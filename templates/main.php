<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div id="app-content" class="ttc-app">
	<div id="timetrackercards"
		data-api="<?php p($_['api']); ?>"
		data-users-url="<?php p($_['usersUrl']); ?>"
		data-avatar-url="<?php p($_['avatarUrl']); ?>"
		data-uid="<?php p($_['uid']); ?>">

		<div class="ttc-header">
			<h2>Time Tracker Cards</h2>
			<button type="button" class="primary" data-action="new"><?php p($l->t('New timer')); ?></button>
		</div>

		<p class="ttc-error" id="ttc-error" role="alert" hidden></p>

		<div class="ttc-empty" id="ttc-empty" hidden>
			<p class="ttc-empty-title"><?php p($l->t('No timers yet')); ?></p>
			<p><?php p($l->t('Create a timer for screen time, cooking or anything else, and share it with the people you choose.')); ?></p>
		</div>

		<div class="ttc-grid" id="ttc-grid"></div>

		<div id="ttc-i18n" hidden
			data-title-create="<?php p($l->t('New timer')); ?>"
			data-title-edit="<?php p($l->t('Edit timer')); ?>"
			data-create="<?php p($l->t('Create')); ?>"
			data-save="<?php p($l->t('Save')); ?>"
			data-name-required="<?php p($l->t('Please enter a name.')); ?>"
			data-confirm-delete="<?php p($l->t('Delete this timer for everyone?')); ?>"
			data-confirm-leave="<?php p($l->t('Leave this timer?')); ?>"
			data-owner="<?php p($l->t('Owner')); ?>"
			data-remove="<?php p($l->t('Remove')); ?>"
			data-invite="<?php p($l->t('Invite')); ?>"
			data-error-generic="<?php p($l->t('Something went wrong.')); ?>"
			data-error-network="<?php p($l->t('Could not reach the server.')); ?>"></div>

		<template id="ttc-card-tpl">
			<article class="ttc-card" data-status="idle" data-owner="false" data-resettable="true">
				<header class="ttc-card-head">
					<button type="button" class="ttc-icon-btn ttc-grip" data-action="grip"
						title="<?php p($l->t('Drag to move')); ?>" aria-label="<?php p($l->t('Drag to move')); ?>">
						<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
							<circle cx="8" cy="5" r="1.7"/><circle cx="8" cy="12" r="1.7"/><circle cx="8" cy="19" r="1.7"/>
							<circle cx="16" cy="5" r="1.7"/><circle cx="16" cy="12" r="1.7"/><circle cx="16" cy="19" r="1.7"/>
						</svg>
					</button>
					<h3 class="ttc-name"></h3>
					<div class="ttc-menu-wrap">
						<button type="button" class="ttc-icon-btn" data-action="menu" aria-haspopup="true" aria-expanded="false"
							title="<?php p($l->t('Menu')); ?>" aria-label="<?php p($l->t('Menu')); ?>">
							<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
								<circle cx="5" cy="12" r="1.9"/><circle cx="12" cy="12" r="1.9"/><circle cx="19" cy="12" r="1.9"/>
							</svg>
						</button>
						<div class="ttc-menu" role="menu" hidden>
							<button type="button" role="menuitem" data-action="share" data-owner-only><?php p($l->t('Share…')); ?></button>
							<button type="button" role="menuitem" data-action="edit" data-owner-only><?php p($l->t('Edit…')); ?></button>
							<button type="button" role="menuitem" data-action="move-left"><?php p($l->t('Move left')); ?></button>
							<button type="button" role="menuitem" data-action="move-right"><?php p($l->t('Move right')); ?></button>
							<button type="button" role="menuitem" data-action="leave" data-member-only><?php p($l->t('Leave timer')); ?></button>
							<button type="button" role="menuitem" data-action="delete" data-owner-only class="ttc-danger"><?php p($l->t('Delete timer')); ?></button>
						</div>
					</div>
				</header>

				<p class="ttc-desc"></p>

				<div class="ttc-ring">
					<div class="ttc-pulse" aria-hidden="true"></div>
					<svg class="ttc-svg" viewBox="0 0 200 200" aria-hidden="true" focusable="false">
						<circle class="ttc-track" cx="100" cy="100" r="88"/>
						<circle class="ttc-arc" cx="100" cy="100" r="88" pathLength="100" transform="rotate(-90 100 100)"/>
						<g class="ttc-head" transform="rotate(0 100 100)"><circle cx="100" cy="12" r="6.5"/></g>
					</svg>
					<div class="ttc-center">
						<div class="ttc-clock" role="timer">00:00</div>
					</div>
				</div>

				<p class="ttc-status">
					<span data-show="idle"><?php p($l->t('Ready')); ?></span>
					<span data-show="running"><?php p($l->t('Running')); ?></span>
					<span data-show="paused"><?php p($l->t('Paused')); ?></span>
					<span data-show="finished"><?php p($l->t('Time is up')); ?></span>
					<span data-show="running paused">
						· <?php p($l->t('Started by')); ?> <strong class="ttc-by"></strong>
					</span>
				</p>

				<div class="ttc-buttons">
					<button type="button" class="primary" data-action="start" data-show="idle"><?php p($l->t('Start')); ?></button>
					<button type="button" class="primary" data-action="start" data-show="finished" data-needs-reset><?php p($l->t('Start')); ?></button>
					<button type="button" class="primary" data-action="start" data-show="paused"><?php p($l->t('Resume')); ?></button>
					<button type="button" data-action="pause" data-show="running"><?php p($l->t('Pause')); ?></button>
					<button type="button" data-action="reset" data-show="running paused finished" data-needs-reset><?php p($l->t('Reset')); ?></button>
				</div>

				<footer class="ttc-foot">
					<span class="ttc-chip" data-daily-only><?php p($l->t('Resets daily')); ?></span>
					<div class="ttc-avatars"></div>
				</footer>
			</article>
		</template>

		<dialog id="ttc-dlg-edit" class="ttc-dialog">
			<form id="ttc-form-edit" class="ttc-dialog-body" novalidate>
				<h3 id="ttc-edit-title"></h3>

				<label for="ttc-f-name"><?php p($l->t('Name')); ?></label>
				<input id="ttc-f-name" type="text" maxlength="128" autocomplete="off"
					placeholder="<?php p($l->t('For example: Pizza in the oven')); ?>">

				<label for="ttc-f-desc"><?php p($l->t('Description')); ?></label>
				<textarea id="ttc-f-desc" rows="3" maxlength="150"
					placeholder="<?php p($l->t('Optional note, shown under the title')); ?>"></textarea>
				<p class="ttc-hint ttc-count"><span id="ttc-desc-count">0</span>/150</p>

				<label for="ttc-f-minutes"><?php p($l->t('Length (minutes)')); ?></label>
				<input id="ttc-f-minutes" type="number" min="1" max="1440" value="45">

				<label class="ttc-switch">
					<input type="checkbox" id="ttc-f-resettable" checked>
					<span class="ttc-switch-track" aria-hidden="true"></span>
					<span><?php p($l->t('Allow resetting the time')); ?></span>
				</label>
				<p class="ttc-hint"><?php p($l->t('When off, the time cannot be reset by hand. It starts over with the full time every day instead.')); ?></p>

				<p class="ttc-error" id="ttc-edit-error" role="alert" hidden></p>

				<div class="ttc-dialog-actions">
					<button type="button" data-action="cancel-edit"><?php p($l->t('Cancel')); ?></button>
					<button type="submit" class="primary" id="ttc-edit-save"></button>
				</div>
			</form>
		</dialog>

		<dialog id="ttc-dlg-share" class="ttc-dialog">
			<div class="ttc-dialog-body">
				<h3><?php p($l->t('Share timer')); ?>: <span id="ttc-share-name"></span></h3>

				<h4><?php p($l->t('Members')); ?></h4>
				<ul class="ttc-people" id="ttc-members"></ul>

				<label for="ttc-search"><?php p($l->t('Invite someone')); ?></label>
				<input id="ttc-search" type="search" autocomplete="off" placeholder="<?php p($l->t('Search users')); ?>">
				<ul class="ttc-people" id="ttc-results"></ul>
				<p class="ttc-hint" id="ttc-noresults" hidden><?php p($l->t('No users found.')); ?></p>

				<div class="ttc-dialog-actions">
					<button type="button" class="primary" data-action="close-share"><?php p($l->t('Done')); ?></button>
				</div>
			</div>
		</dialog>
	</div>
</div>
