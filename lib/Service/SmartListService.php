<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Jonathan Piron <jonathan@piron.at>
 * @copyright Jonathan Piron 2026
 */

namespace OCA\Music\Service;

use OCA\Music\AppFramework\BusinessLayer\BusinessLayerException;
use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\BusinessLayer\PlaylistBusinessLayer;
use OCA\Music\BusinessLayer\TrackBusinessLayer;
use OCA\Music\Db\BaseMapper;
use OCA\Music\Db\Playlist;
use OCA\Music\Db\SmartList;
use OCA\Music\Db\SmartListMapper;
use OCA\Music\Db\SortBy;
use OCA\Music\Utility\ArrayUtil;
use OCA\Music\Utility\Random;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;

/**
 * Smart playlists are playlists whose content is defined by advanced search criteria on tracks. The result of the
 * search is stored as the content of a normal playlist, so that the smart playlists are available as-is through all
 * the APIs. The content is refreshed when it gets stale, either on access or by a background job, or on user request.
 */
class SmartListService {

	/** Default refresh interval in minutes, used when not configured in config.php */
	public const DEFAULT_REFRESH_INTERVAL = 60;

	private const ORDERS = ['name', 'parent', 'newest', 'play_count', 'last_played', 'rating', 'random'];

	public function __construct(
		private SmartListMapper $smartListMapper,
		private PlaylistBusinessLayer $playlistBusinessLayer,
		private TrackBusinessLayer $trackBusinessLayer,
		private IConfig $config,
		private Logger $logger,
	) {
	}

	/**
	 * Create a new smart playlist and populate it
	 * @param array $criteria Search criteria like ['conjunction' => 'and', 'order' => 'name', 'limit' => 100, 'rules' => [...]]
	 * @param ?int $refreshInterval Refresh interval in minutes, 0 for manual refresh only, null to use the default
	 * @throws \InvalidArgumentException if the criteria are not valid
	 */
	public function create(string $name, array $criteria, ?int $refreshInterval, string $userId) : Playlist {
		$criteria = $this->validateCriteria($criteria, $userId);
		self::validateRefreshInterval($refreshInterval);

		$playlist = $this->playlistBusinessLayer->create($name, $userId);

		$smartList = new SmartList();
		$smartList->setUserId($userId);
		$smartList->setPlaylistId($playlist->getId());
		$smartList->setCriteriaArray($criteria);
		$smartList->setRefreshInterval($refreshInterval);
		$smartList = $this->smartListMapper->insert($smartList);

		$this->refresh($smartList, $playlist);
		return $playlist;
	}

	/**
	 * Change the criteria and/or the refresh interval of an existing smart playlist. The content is refreshed
	 * if the criteria are changed.
	 * @param ?array $criteria New criteria, or null to keep the current criteria
	 * @param bool $setRefreshInterval Whether to update the refresh interval
	 * @param ?int $refreshInterval New refresh interval in minutes, 0 for manual refresh only, null to use the default
	 * @throws BusinessLayerException if the smart playlist is not found
	 * @throws \InvalidArgumentException if the criteria are not valid
	 */
	public function update(int $playlistId, string $userId, ?array $criteria, bool $setRefreshInterval, ?int $refreshInterval) : Playlist {
		$playlist = $this->playlistBusinessLayer->find($playlistId, $userId);
		$smartList = $this->findSmartList($playlistId, $userId);

		if ($setRefreshInterval) {
			self::validateRefreshInterval($refreshInterval);
			$smartList->setRefreshInterval($refreshInterval);
		}

		if ($criteria !== null) {
			$smartList->setCriteriaArray($this->validateCriteria($criteria, $userId));
			$this->refresh($smartList, $playlist);
		} else {
			$this->smartListMapper->update($smartList);
			$this->injectSmartList($playlist, $smartList);
		}

		return $playlist;
	}

	/**
	 * Convert a smart playlist to a normal playlist, keeping its current content
	 * @throws BusinessLayerException if the smart playlist is not found
	 */
	public function convertToStatic(int $playlistId, string $userId) : Playlist {
		$playlist = $this->playlistBusinessLayer->find($playlistId, $userId);
		$this->findSmartList($playlistId, $userId); // ensure that this is a smart list
		$this->smartListMapper->deleteByPlaylistId($playlistId, $userId);
		return $playlist;
	}

	/**
	 * Re-evaluate the criteria of a smart playlist and update its content
	 * @throws BusinessLayerException if the smart playlist is not found
	 */
	public function refreshById(int $playlistId, string $userId) : Playlist {
		$playlist = $this->playlistBusinessLayer->find($playlistId, $userId);
		$smartList = $this->findSmartList($playlistId, $userId);
		$this->refresh($smartList, $playlist);
		return $playlist;
	}

	/**
	 * Mark the smart playlists among the given playlists as such and optionally refresh those which are stale.
	 * This should be called before passing the playlists to any API so that the smart lists are rendered properly.
	 * @param Playlist[] $playlists
	 * @return Playlist[] the same playlists which were passed in
	 */
	public function injectSmartLists(array $playlists, string $userId, bool $refreshStale = true) : array {
		if (!empty($playlists)) {
			$smartLists = [];
			foreach ($this->smartListMapper->findAllByUser($userId) as $smartList) {
				$smartLists[$smartList->getPlaylistId()] = $smartList;
			}

			foreach ($playlists as $playlist) {
				$smartList = $smartLists[$playlist->getId()] ?? null;
				if ($smartList === null) {
					continue; // not a smart list, leave as-is
				} elseif ($refreshStale && $this->isStale($smartList)) {
					$this->refresh($smartList, $playlist);
				} else {
					$this->injectSmartList($playlist, $smartList);
				}
			}
		}
		return $playlists;
	}

	/**
	 * Like injectSmartLists but for a single playlist
	 */
	public function injectSmartListToPlaylist(Playlist $playlist, string $userId, bool $refreshStale = true) : Playlist {
		return $this->injectSmartLists([$playlist], $userId, $refreshStale)[0];
	}

	/**
	 * Refresh all the smart playlists of all the users which are due for refreshing
	 * @return int The number of refreshed playlists
	 */
	public function refreshAllStale() : int {
		$now = new \DateTime();
		$candidates = $this->smartListMapper->findAllRefreshedBefore($now->format(BaseMapper::SQL_DATE_FORMAT));
		$count = 0;

		foreach ($candidates as $smartList) {
			if ($this->isStale($smartList)) {
				try {
					$playlist = $this->playlistBusinessLayer->find($smartList->getPlaylistId(), $smartList->getUserId());
					$this->refresh($smartList, $playlist);
					$count++;
				} catch (BusinessLayerException $e) {
					// the playlist no longer exists, drop the orphan definition
					$this->logger->debug("Removing smart list definition of non-existing playlist {$smartList->getPlaylistId()}");
					$this->smartListMapper->delete($smartList);
				}
			}
		}

		return $count;
	}

	/**
	 * Get the refresh interval used by the smart lists which have no interval of their own
	 * @return int Interval in minutes, 0 or negative if the lists are not refreshed automatically by default
	 */
	public function getDefaultRefreshInterval() : int {
		return (int)$this->config->getSystemValue('music.smartlist_refresh_interval', self::DEFAULT_REFRESH_INTERVAL);
	}

	/**
	 * @return ?int Effective refresh interval in minutes, null if the list is not refreshed automatically
	 */
	private function getEffectiveRefreshInterval(SmartList $smartList) : ?int {
		$interval = $smartList->getRefreshInterval() ?? $this->getDefaultRefreshInterval();
		return ($interval > 0) ? $interval : null;
	}

	/**
	 * @return ?\DateTime The time when the content becomes stale, null if never
	 */
	private function getValidUntil(SmartList $smartList) : ?\DateTime {
		$interval = $this->getEffectiveRefreshInterval($smartList);
		$refreshed = $smartList->getRefreshed();

		if ($interval === null || $refreshed === null) {
			return null;
		} else {
			$validUntil = new \DateTime($refreshed);
			$validUntil->modify("+$interval minutes");
			return $validUntil;
		}
	}

	private function isStale(SmartList $smartList) : bool {
		if ($smartList->getRefreshed() === null) {
			return true;
		} else {
			$validUntil = $this->getValidUntil($smartList);
			return ($validUntil !== null && $validUntil <= new \DateTime());
		}
	}

	private function refresh(SmartList $smartList, Playlist $playlist) : void {
		$criteria = $smartList->getCriteriaArray();
		try {
			$trackIds = ArrayUtil::extractIds($this->evaluate($criteria, $smartList->getUserId()));
			$changed = $this->playlistBusinessLayer->setSmartListTracks($trackIds, $playlist);
			$this->logger->debug("Refreshed smart list {$playlist->getId()}, content " . ($changed ? 'changed' : 'unchanged'));
		} catch (BusinessLayerException $e) {
			// The criteria may have become invalid e.g. because of a missing DB extension. Keep the previous content.
			$this->logger->warning("Failed to refresh the smart list {$playlist->getId()}: {$e->getMessage()}");
		}

		$smartList->setRefreshed((new \DateTime())->format(BaseMapper::SQL_DATE_FORMAT));
		$this->smartListMapper->update($smartList);
		$this->injectSmartList($playlist, $smartList);
	}

	private function injectSmartList(Playlist $playlist, SmartList $smartList) : void {
		$playlist->setSmartList($smartList, $this->getValidUntil($smartList)?->format(BaseMapper::SQL_DATE_FORMAT));
	}

	/**
	 * @throws BusinessLayerException if the search fails
	 * @return \OCA\Music\Db\Track[]
	 */
	private function evaluate(array $criteria, string $userId) : array {
		$order = $criteria['order'];
		$limit = $criteria['limit'];

		if ($order === 'random') {
			// pick the random subset from all the matching tracks, a new subset is picked on each refresh
			$tracks = $this->trackBusinessLayer->findAllAdvanced($criteria['conjunction'], $criteria['rules'], $userId, SortBy::Name);
			return Random::pickItems($tracks, $limit ?? \count($tracks));
		} else {
			return $this->trackBusinessLayer->findAllAdvanced(
					$criteria['conjunction'], $criteria['rules'], $userId, SortBy::fromAdvSearchOrder($order), null, $limit);
		}
	}

	/**
	 * Check and normalize the given criteria
	 * @throws \InvalidArgumentException if the criteria are not valid
	 */
	private function validateCriteria(array $criteria, string $userId) : array {
		$conjunction = $criteria['conjunction'] ?? 'and';
		if ($conjunction !== 'and' && $conjunction !== 'or') {
			throw new \InvalidArgumentException("Invalid conjunction '$conjunction'");
		}

		$order = $criteria['order'] ?? 'name';
		if (!\in_array($order, self::ORDERS)) {
			throw new \InvalidArgumentException("Invalid order '$order'");
		}

		$limit = $criteria['limit'] ?? null;
		if ($limit === '') {
			$limit = null;
		}
		if ($limit !== null) {
			if (!\is_numeric($limit) || (int)$limit <= 0) {
				throw new \InvalidArgumentException("Invalid limit '$limit'");
			}
			$limit = (int)$limit;
		}

		$rules = $criteria['rules'] ?? [];
		if (!\is_array($rules)) {
			throw new \InvalidArgumentException('Invalid rules');
		}
		$rules = \array_map(function ($rule) {
			if (!\is_array($rule) || empty($rule['rule']) || empty($rule['operator']) || !isset($rule['input'])) {
				throw new \InvalidArgumentException('Invalid search rule');
			}
			if ($rule['rule'] === 'playlist' && $this->smartListMapper->existsForPlaylist((int)$rule['input'])) {
				throw new \InvalidArgumentException('A smart playlist cannot refer to another smart playlist');
			}
			return [
				'rule'     => (string)$rule['rule'],
				'operator' => (string)$rule['operator'],
				'input'    => (string)$rule['input']
			];
		}, \array_values($rules));

		$criteria = \compact('conjunction', 'order', 'limit', 'rules');

		// ensure that the criteria can actually be evaluated on this DB
		try {
			$this->evaluate($criteria, $userId);
		} catch (BusinessLayerException $e) {
			throw new \InvalidArgumentException($e->getMessage());
		}

		return $criteria;
	}

	private static function validateRefreshInterval(?int $refreshInterval) : void {
		if ($refreshInterval !== null && $refreshInterval < 0) {
			throw new \InvalidArgumentException("Invalid refresh interval '$refreshInterval'");
		}
	}

	/**
	 * @throws BusinessLayerException if not found
	 */
	private function findSmartList(int $playlistId, string $userId) : SmartList {
		try {
			return $this->smartListMapper->findByPlaylistId($playlistId, $userId);
		} catch (DoesNotExistException $e) {
			throw new BusinessLayerException("Playlist $playlistId is not a smart playlist");
		}
	}
}
