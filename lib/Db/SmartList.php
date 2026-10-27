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

namespace OCA\Music\Db;

/**
 * Definition of a smart playlist. The smart playlist itself is a normal playlist (see Playlist) which holds the result
 * of the latest evaluation of the criteria. This entity stores the search criteria and the refresh state.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getPlaylistId()
 * @method void setPlaylistId(int $playlistId)
 * @method ?string getCriteria()
 * @method void setCriteria(?string $criteria)
 * @method ?int getRefreshInterval()
 * @method void setRefreshInterval(?int $minutes)
 * @method ?string getRefreshed()
 * @method void setRefreshed(?string $timestamp)
 */
class SmartList extends \OCP\AppFramework\Db\Entity {
	public string $userId = '';
	public int $playlistId = 0;
	public ?string $criteria = null;
	public ?int $refreshInterval = null;
	public ?string $refreshed = null;

	public function __construct() {
		$this->addType('playlistId', 'int');
		$this->addType('refreshInterval', 'int');
	}

	/**
	 * @return array{conjunction: string, order: string, limit: ?int, rules: array<array{rule: string, operator: string, input: string}>}
	 */
	public function getCriteriaArray() : array {
		$criteria = \json_decode($this->criteria ?? '', true);
		return [
			'conjunction' => $criteria['conjunction'] ?? 'and',
			'order'       => $criteria['order'] ?? 'name',
			'limit'       => isset($criteria['limit']) ? (int)$criteria['limit'] : null,
			'rules'       => $criteria['rules'] ?? []
		];
	}

	public function setCriteriaArray(array $criteria) : void {
		$this->setCriteria(\json_encode($criteria));
	}
}
