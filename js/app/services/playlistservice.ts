/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2026
 */

import * as ng from 'angular';
import { gettextCatalog } from 'angular-gettext';
import { IService } from 'restangular';
import { MusicRootScope } from 'app/config/musicrootscope';
import { LibraryService, Playlist, SmartListCriteria } from './libraryservice';
import { PlayQueue } from 'shared/playqueue';

ng.module('Music').service('playlistService', [
'$rootScope', 'libraryService', 'playQueueService', 'gettextCatalog', 'Restangular', '$q',
function($rootScope : MusicRootScope, libraryService : LibraryService, playQueueService : PlayQueue, gettextCatalog : gettextCatalog, Restangular : IService, $q : ng.IQService) {

	function errorDetails(error : any) : string {
		const message = error?.data?.message;
		return message ? `: ${message}` : '';
	}

	/**
	 * Handle the response of a request modifying a smart playlist. The response contains the whole updated playlist
	 * which replaces the old instance in the library service.
	 */
	function modifySmartPlaylist(playlist : Playlist, request : ng.IPromise<any>) : ng.IPromise<Playlist> {
		playlist.busy = true;
		return request.then((result) => {
			playlist.busy = false;
			// If the list is currently playing, the play queue keeps playing the previous content
			return libraryService.replacePlaylist(result);
		}, (error) => {
			playlist.busy = false;
			OCA.Music.Dialogs.showNotification(gettextCatalog.getString('Failed to update the playlist') + errorDetails(error));
			return $q.reject();
		});
	}

	return {

		createPlaylist(name : string, trackIds : number[], comment? : string) : ng.IPromise<Playlist> {
			const args = {
				name: name,
				trackIds: trackIds.join(','),
				comment: comment
			};
			return Restangular.all('playlists').post(args).then(
				(playlist : any) => libraryService.addPlaylist(playlist),
				(_error) => {
					OCA.Music.Dialogs.showNotification(gettextCatalog.getString('Failed to create the playlist'));
					return $q.reject();
				}
			);
		},

		updatePlaylistName(playlist : Playlist) : ng.IPromise<void> {
			playlist.busy = true;
			return Restangular.one('playlists', playlist.id).customPUT({name: playlist.name}).then((result) => {
				playlist.updated = result.updated;
				playlist.busy = false;
			}, (_error) => {
				playlist.busy = false;
				OCA.Music.Dialogs.showNotification(gettextCatalog.getString('Failed to update the playlist'));
				return $q.reject();
			});
		},

		updatePlaylistTracks(playlist : Playlist) : ng.IPromise<void> {
			playlist.busy = true;
			const trackIds = playlist.tracks.map((entry) => entry.track.id);
			return Restangular.one('playlists', playlist.id).customPUT({trackIds: trackIds.join(',')}).then((result) => {
				playlist.updated = result.updated;
				playlist.busy = false;
			}, (_error) => {
				playlist.busy = false;
				OCA.Music.Dialogs.showNotification(gettextCatalog.getString('Failed to update the playlist'));
				return $q.reject();
			});
		},

		addTracksToPlaylist(playlist : Playlist, trackIds : number[]) : ng.IPromise<void> {
			playlist.busy = true;
			return Restangular.one('playlists', playlist.id).all('add').post({track: trackIds.join(',')}).then((result) => {
				playlist.busy = false;
				playlist.updated = result.updated;

				trackIds.forEach((trackId) => {
					libraryService.addToPlaylist(playlist.id, trackId);
				});

				// Update the currently playing list if necessary
				if ($rootScope.playingView == '#/playlist/' + playlist.id) {
					let newTracks = trackIds.map((trackId) => {
						return { track: libraryService.getTrack(trackId) };
					});
					playQueueService.onTracksAdded(newTracks);
				}
			}, (_error) => {
				playlist.busy = false;
				OCA.Music.Dialogs.showNotification(gettextCatalog.getString('Failed to add tracks to the playlist'));
				return $q.reject();
			});
		},

		createSmartPlaylist(name : string, criteria : SmartListCriteria) : ng.IPromise<Playlist> {
			const args = {
				name: name,
				criteria: ng.toJson(criteria)
			};
			return Restangular.all('playlists/smart').post(args).then(
				(playlist : any) => libraryService.addPlaylist(playlist),
				(error) => {
					OCA.Music.Dialogs.showNotification(
						gettextCatalog.getString('Failed to create the smart playlist') + errorDetails(error));
					return $q.reject();
				}
			);
		},

		/**
		 * Change the criteria and/or the refresh interval of a smart playlist. The refresh interval may be given
		 * in minutes, as 0 for manual refresh only, or as 'default'.
		 */
		updateSmartPlaylist(playlist : Playlist, criteria : SmartListCriteria|null, refreshInterval : number|'default'|null = null) : ng.IPromise<Playlist> {
			const args : Record<string, string|number> = {};
			if (criteria !== null) {
				args.criteria = ng.toJson(criteria);
			}
			if (refreshInterval !== null) {
				args.refreshInterval = refreshInterval;
			}
			return modifySmartPlaylist(playlist, Restangular.one('playlists', playlist.id).one('smart').customPUT(args));
		},

		refreshSmartPlaylist(playlist : Playlist) : ng.IPromise<Playlist> {
			return modifySmartPlaylist(playlist, Restangular.one('playlists', playlist.id).all('refresh').post({}));
		},

		convertToStaticPlaylist(playlist : Playlist) : ng.IPromise<Playlist> {
			return modifySmartPlaylist(playlist, Restangular.one('playlists', playlist.id).one('smart').remove());
		},

		deletePlaylist(playlist : Playlist) : ng.IPromise<void> {
			playlist.busy = true;
			return Restangular.one('playlists', playlist.id).remove().then(() => {
				libraryService.removePlaylist(playlist);
				playlist.busy = false;
			}, (_error) => {
				playlist.busy = false;
				OCA.Music.Dialogs.showNotification(gettextCatalog.getString('Failed to delete the playlist'));
				return $q.reject();
			});
		}
	};

}]);
