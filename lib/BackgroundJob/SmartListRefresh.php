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

namespace OCA\Music\BackgroundJob;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\AppInfo\Application;
use OCA\Music\Service\SmartListService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class SmartListRefresh extends TimedJob {

	public function __construct(ITimeFactory $time) {
		parent::__construct($time);
		// The refresh intervals of the individual lists are checked on each run
		$this->setInterval(5 * 60);
	}

	/**
	 * Refresh the smart playlists which are due for refreshing
	 * @param mixed $arguments
	 * @return void
	 */
	public function run($arguments) {
		$app = \OC::$server->query(Application::class);

		$logger = $app->get(Logger::class);
		$logger->debug('Run ' . static::class);

		$count = $app->get(SmartListService::class)->refreshAllStale();
		$logger->debug("$count smart lists were refreshed");
	}
}
