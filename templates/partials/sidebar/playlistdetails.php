<div id="playlist-details" class="sidebar-content" ng-controller="PlaylistDetailsController" ng-if="contentType=='playlist'">

	<div favorite-toggle entity="playlist" rest-prefix="'playlists'"></div>
	<div class="albumart"></div>

	<div class="sidebar-container">
		<h1>{{playlist.name}}</h1>

		<dl class="tags">
			<dt translate>Number of tracks</dt>
			<dd>{{ playlist.tracks.length }}</dd>

			<dt translate>Total length</dt>
			<dd>{{ totalLength | playTime }}</dd>

			<dt ng-if="createdDate" translate>Created</dt>
			<dd ng-if="createdDate">{{ createdDate }}</dd>

			<dt ng-if="updatedDate" translate>Modified</dt>
			<dd ng-if="updatedDate">{{ updatedDate }}</dd>

			<dt ng-if="playlist.smart" translate>Criteria</dt>
			<dd ng-if="playlist.smart" class="smart-criteria">
				<div>{{ smartCriteriaSummary() }}</div>
				<div ng-repeat="rule in playlist.smart.criteria.rules">{{ rule.rule }} {{ rule.operator }} {{ rule.input }}</div>
			</dd>

			<dt ng-if="playlist.smart" translate>Refreshed</dt>
			<dd ng-if="playlist.smart">{{ smartRefreshedDate || '–' }}</dd>

			<dt ng-if="playlist.smart" translate>Auto refresh</dt>
			<dd ng-if="playlist.smart">
				<select ng-model="smartRefreshInterval" ng-change="onRefreshIntervalChanged(smartRefreshInterval)">
					<option ng-repeat="opt in refreshIntervalOptions" ng-value="opt.value">{{ opt.text }}</option>
				</select>
			</dd>

			<dt translate>Comment</dt>
			<dd class="clickable" ng-click="startEdit()"
				><span ng-show="!editing">{{ playlist.comment }}<button class="icon-rename"></button></span
				><textarea ng-show="editing" type="text" on-enter="commitEdit()" ng-model="playlist.comment" maxlength="256"></textarea
			></dd>
		</dl>
	</div>

</div>
