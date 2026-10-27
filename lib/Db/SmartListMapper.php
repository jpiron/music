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

use OCA\Music\AppFramework\Db\Mapper;
use OCP\IDBConnection;

/**
 * @extends Mapper<SmartList>
 */
class SmartListMapper extends Mapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'music_smartlists', SmartList::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if not found
	 */
	public function findByPlaylistId(int $playlistId, string $userId) : SmartList {
		$sql = 'SELECT * FROM `*PREFIX*music_smartlists` WHERE `playlist_id` = ? AND `user_id` = ?';
		return $this->findEntity($sql, [$playlistId, $userId]);
	}

	/**
	 * @return SmartList[]
	 */
	public function findAllByUser(string $userId) : array {
		$sql = 'SELECT * FROM `*PREFIX*music_smartlists` WHERE `user_id` = ?';
		return $this->findEntities($sql, [$userId]);
	}

	/**
	 * Find the smart lists of all users which have not been refreshed after the given time, or never at all.
	 * Whether a list is actually due for refreshing depends on its own refresh interval which is checked by the caller.
	 * @return SmartList[]
	 */
	public function findAllRefreshedBefore(string $timestamp) : array {
		$sql = 'SELECT * FROM `*PREFIX*music_smartlists` WHERE `refreshed` IS NULL OR `refreshed` < ?';
		return $this->findEntities($sql, [$timestamp]);
	}

	public function existsForPlaylist(int $playlistId) : bool {
		$sql = 'SELECT 1 FROM `*PREFIX*music_smartlists` WHERE `playlist_id` = ?';
		$result = $this->execute($sql, [$playlistId]);
		$exists = ($result->fetch() !== false);
		$result->closeCursor();
		return $exists;
	}

	public function deleteByPlaylistId(int $playlistId, string $userId) : void {
		$sql = 'DELETE FROM `*PREFIX*music_smartlists` WHERE `playlist_id` = ? AND `user_id` = ?';
		$this->execute($sql, [$playlistId, $userId])->closeCursor();
	}
}
