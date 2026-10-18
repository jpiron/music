/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2020, 2021
 */


angular.module('Music').controller('PlaylistDetailsController', [
	'$scope', '$timeout', 'Restangular', 'libraryService', 'playlistService', 'gettextCatalog',
	function ($scope, $timeout, Restangular, libraryService, playlistService, gettextCatalog) {

		// refresh intervals in minutes, 'default' meaning the server-wide default interval
		$scope.refreshIntervalOptions = [
			{ value: 'default',	text: gettextCatalog.getString('Default') },
			{ value: 0,			text: gettextCatalog.getString('Never (manual only)') },
			{ value: 15,		text: gettextCatalog.getString('Every 15 minutes') },
			{ value: 60,		text: gettextCatalog.getString('Every hour') },
			{ value: 360,		text: gettextCatalog.getString('Every 6 hours') },
			{ value: 1440,		text: gettextCatalog.getString('Every day') },
			{ value: 10080,		text: gettextCatalog.getString('Every week') },
		];

		function resetContents() {
			$scope.playlist = null;
			$scope.totalLength = null;
			$scope.createdDate = null;
			$scope.updatedDate = null;
			$scope.smartRefreshedDate = null;
			$scope.smartRefreshInterval = null;
			$scope.editing = false;
			$('#app-sidebar .albumart').css('background-image', '');
		}
		resetContents();

		$scope.$watch('contentId', function(playlistId) {
			if (!$scope.playlist || playlistId != $scope.playlist.id) {
				resetContents();
				$scope.playlist = libraryService.getPlaylist(playlistId);

				$scope.createdDate = OCA.Music.Utils.formatDateTime($scope.playlist.created);
				$scope.updatedDate = OCA.Music.Utils.formatDateTime($scope.playlist.updated);

				let art = $('#app-sidebar .albumart');
				let url = OC.generateUrl('apps/music/api/playlists/') + playlistId + '/cover';
				art.css('background-image', 'url("' + url + '")');
			}
		});

		$scope.$watch('playlist.updated', function(updated) {
			$scope.updatedDate = OCA.Music.Utils.formatDateTime(updated);
		});

		// The playlist object gets replaced in the library when a smart playlist is refreshed or modified
		$scope.$watch(() => libraryService.getPlaylist($scope.contentId), function(playlist) {
			if (playlist && $scope.playlist && playlist !== $scope.playlist && playlist.id == $scope.playlist.id) {
				$scope.playlist = playlist;
			}
		});

		$scope.$watch('playlist.smart', function(smart) {
			$scope.smartRefreshedDate = smart ? OCA.Music.Utils.formatDateTime(smart.refreshed) : null;
			$scope.smartRefreshInterval = smart ? (smart.refreshInterval ?? 'default') : null;
			// show also a custom interval possibly set via the API
			if (smart && !_.find($scope.refreshIntervalOptions, { value: $scope.smartRefreshInterval })) {
				$scope.refreshIntervalOptions.push({
					value: $scope.smartRefreshInterval,
					text: gettextCatalog.getPlural($scope.smartRefreshInterval,
						'Every {{ count }} minute', 'Every {{ count }} minutes', { count: $scope.smartRefreshInterval })
				});
			}
		});

		$scope.smartCriteriaSummary = function() {
			const criteria = $scope.playlist.smart.criteria;
			const parts = [
				(criteria.conjunction == 'or') ? gettextCatalog.getString('Matching any rule') : gettextCatalog.getString('Matching all rules'),
				gettextCatalog.getString('order: {{ order }}', { order: criteria.order }),
				criteria.limit ? gettextCatalog.getString('limit: {{ limit }}', { limit: criteria.limit }) : gettextCatalog.getString('unlimited')
			];
			return parts.join(', ');
		};

		$scope.onRefreshIntervalChanged = function(interval) {
			playlistService.updateSmartPlaylist($scope.playlist, null, interval);
		};

		$scope.$watchCollection('playlist.tracks', function() {
			$scope.totalLength = _.reduce($scope.playlist.tracks, function(sum, item) {
				return sum + (item.track?.length ?? 0); // be prepared for invalid playist entries
			}, 0);
		});

		let initialComment = null;

		// Start editing the comment
		$scope.startEdit = function() {
			if (!$scope.editing) {
				$scope.editing = true;
				initialComment = $scope.playlist.comment;
				// Move the focus to the input field
				$timeout(function() {
					$('#app-sidebar dd textarea').focus();
				});
			}
		};

		// Commit editing the comment
		$scope.commitEdit = function() {
			// push the change to the server only if the comment has actually changed
			if (initialComment !== $scope.playlist.comment) {
				Restangular.one('playlists', $scope.playlist.id).customPUT({comment: $scope.playlist.comment}).then(function (result) {
					$scope.playlist.updated = result.updated;
				});
			}
			$scope.editing = false;
		};

		// Commit editing when user clicks outside the textarea
		$('#app-sidebar dd textarea').blur(function() {
			$timeout($scope.commitEdit);
		});
	}
]);
