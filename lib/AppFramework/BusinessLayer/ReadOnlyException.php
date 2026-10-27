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

namespace OCA\Music\AppFramework\BusinessLayer;

/**
 * Thrown when trying to modify an entity which exists but can't be modified by the user (e.g. content of a smart playlist)
 */
class ReadOnlyException extends BusinessLayerException {
}
