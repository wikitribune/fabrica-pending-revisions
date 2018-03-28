<?php

namespace Fabrica\PendingRevisions;

if (!defined('WPINC')) { die(); }

require_once('singleton.php');
require_once('settings.php');

class Base extends Singleton {

	const DOMAIN = 'fabrica-pending-revisions';

	const EDITING_MODE_OFF = '';
	const EDITING_MODE_OPEN = 'open';
	const EDITING_MODE_PENDING = 'pending';
	const EDITING_MODE_LOCKED = 'locked';
	const EDITING_MODES = array(
		self::EDITING_MODE_OFF => array(
			'name' => 'Off',
			'description' => 'editing mode cannot be changed'
		),
		self::EDITING_MODE_OPEN => array(
			'name' => 'Open',
			'description' => 'all revisions accepted'
		),
		self::EDITING_MODE_PENDING => array(
			'name' => 'Edits require approval',
			'description' => 'suggestions must be approved'
		),
		self::EDITING_MODE_LOCKED => array(
			'name' => 'Locked',
			'description' => 'only authorised users can edit'
		),
	);

	private $postTypesSupported;

	public function init() {
		if (!is_admin()) { return; }

		add_action('wp_ajax_fpr-editing-mode-save', array($this, 'savePermissions'));
		add_action('wp_ajax_fpr-revision-publish', array($this, 'publishRevision'));
		add_filter('_wp_post_revision_fields', array($this, 'showExtraRevisionFields'), 10, 2 );

		// Exit now if AJAX request, to hook admin-only requests after
		if (wp_doing_ajax()) { return; }

		// Saving hooks
		add_action('edit_form_top', array($this, 'cacheLastRevisionData'));
		add_action('wp_insert_post_empty_content', array($this, 'checkSaveAllowed'), 99, 2);
		add_filter('wp_save_post_revision_post_has_changed', array($this, 'checkExtraFieldChanges'), 10, 3);
		add_filter('_wp_put_post_revision', array($this, 'saveAdditionalRevisionFields'), 10, 1);
		add_action('save_post', array($this, 'saveAcceptedRevision'), 10, 3);
		add_filter('post_updated_messages', array($this, 'changePostUpdatedMessages'));

		// Layout, buttons and metaboxes
		add_action('admin_head', array($this, 'initPostEdit'));
		add_action('post_submitbox_start', array($this, 'addPendingRevisionsButton'));
		add_filter('gettext', array($this, 'alterText'), 10, 2);
		add_action('add_meta_boxes', array($this, 'addPermissionsMetaBox'));

		// Disable locked posts and enable posts filters
		add_action('user_has_cap', array($this, 'disableLockedPosts'), 10, 3);
		add_action('pre_get_posts', array($this, 'enablePostsFilters'));

		// Sort by pending revisions in posts lists
		add_filter('posts_orderby', array($this, 'sortByPendingColumn'), 10, 2);

		// Browse revisions
		add_filter('posts_where', array($this, 'filterBrowseRevisions'));
		add_filter('admin_body_class', array($this, 'addAutosaveBodyClass'));

		// Scripts
		add_action('wp_prepare_revision_for_js', array($this, 'prepareRevisionForJS'), 20, 3);
		add_action('admin_enqueue_scripts', array($this, 'enqueueScripts'));
	}

	// Get post types for which plugin is enabled
	public function getEnabledPostTypes() {
		if ($this->postTypesSupported) { return $this->postTypesSupported; }

		$settings = Settings::instance()->getSettings();
		$args = array('public' => true);
		$postTypes = get_post_types($args);
		$this->postTypesSupported = array();
		foreach ($postTypes as $postType) {
			$settingName = $postType . '_default_editing_mode';
			$defaultEditingMode = isset($settings[$settingName]) ? $settings[$settingName] : '';
			if ($defaultEditingMode != self::EDITING_MODE_OFF && in_array($defaultEditingMode, array_keys(self::EDITING_MODES))) {
				$this->postTypesSupported []= $postType;
			}
		}

		return $this->postTypesSupported;
	}

	// Return the editing mode for a given post. If no editing mode is defined the post type's default editing mode is returned
	public function getEditingMode($postID) {

		// Get post type's default editing mode
		$settings = Settings::instance()->getSettings();
		$settingName = get_post_type($postID) . '_default_editing_mode';
		$defaultEditingMode = isset($settings[$settingName]) ? $settings[$settingName] : '';
		$editingModes = array_keys(self::EDITING_MODES);
		if ($defaultEditingMode == self::EDITING_MODE_OFF || !in_array($defaultEditingMode, $editingModes)) {

			// Default setting when post type's editing mode is not enabled
			return self::EDITING_MODE_OPEN;
		}

		$editingMode = get_post_meta($postID, '_fpr_editing_mode', true);
		if (empty($editingMode) || !in_array($editingMode, $editingModes)) {

			// Editing mode not set: return default setting for post type
			$editingMode = $defaultEditingMode;
		}

		return $editingMode;
	}

	// Called asynchronously to save post's editing mode permissions
	public function savePermissions() {
		if (!isset($_POST['data']['postID']) || !isset($_POST['data']['editingMode'])) {
			wp_send_json_error(array('message' => 'No post ID or editing mode'));
		}
		$postID = $_POST['data']['postID'];
		if (!check_ajax_referer("fpr-editing-mode-{$postID}", 'security', false)) {
			wp_send_json_error(array('message' => 'Nonce check failed'));
		}
		$editingMode = $_POST['data']['editingMode'];
		update_post_meta($postID, '_fpr_editing_mode', $editingMode);
		wp_send_json_success();
	}

	// Called asynchronously to set a revision as accepted (publish)
	public function publishRevision() {
		if (empty($_POST['data']['revision']) || !is_numeric($_POST['data']['revision']) || !current_user_can('accept_revisions')) {
			wp_send_json_error(array('message' => 'No revision ID or user not allowed'));
		}
		$revisionID = $_POST['data']['revision'];
		$revision = wp_get_post_revision($revisionID);
		if (empty($revision) || !check_ajax_referer("fpr-publish-post_{$revisionID}", 'security', false)) {
			wp_send_json_error(array('message' => 'Invalid revision ID or nonce check failed'));
		}

		// Set the accepted ID to point to the revision
		update_post_meta($revision->post_parent, '_fpr_accepted_revision_id', $revisionID);
		wp_send_json_success();
	}

	// Check and update missing accepted revision — most likely plugin was not installed when post was created
	private function saveMissingAcceptedRevision($postArray) {
		if ($postArray['post_status'] != 'publish') { return; }
		$acceptedID = get_post_meta($postArray['ID'], '_fpr_accepted_revision_id', true);
		if ($acceptedID) { return; }

		// Post's accepted revision ID is not set: set it to the lastest revision no matter which since it's already assumed as published
		$revision = $this->getLatestRevision($postArray['ID']);
		if (!$revision) { return; } // No revision to accept (new post)

		// Set pointer to accepted revision
		$acceptedID = $revision->ID;
		update_post_meta($postArray['ID'], '_fpr_accepted_revision_id', $acceptedID);
	}

	// Cache last revision data in the post form, to save the 'based on revision X' meta value
	public function cacheLastRevisionData($post) {

		// Exit if some problem with the post
		if (empty($post)) { return; }

		// Exit for unsupported post types
		if (!in_array($post->post_type, $this->getEnabledPostTypes())) { return; }

		// If a specific revision was requested for editing, use that as the last revision
		$sourceRevisionID = false;
		if (isset($_GET['fpr-edit']) && is_numeric($_GET['fpr-edit'])) {
			$revision = wp_get_post_revision($_GET['fpr-edit']);
			if (!empty($revision) && $revision->post_parent == $post->ID) {
				$sourceRevisionID = $revision->ID;
			}
		}

		// Otherwise use the last revision
		if (!$sourceRevisionID) {
			$revision = $this->getLatestRevision($post->ID);
			if (!empty($revision)) {
				$sourceRevisionID = $revision->ID;
			}
		}

		// Escape if still no revision found
		if (!$sourceRevisionID) { return; }

		// Cache latest revision ID
		echo '<input type="hidden" id="fpr_source_revision_id" name="_fpr_source_revision_id" value="' . $sourceRevisionID . '">';
	}

	// Get user and posts permissions to determine whether or not post can be saved by current user
	public function checkSaveAllowed($maybeEmpty, $postArray) {
		$editingMode = $this->getEditingMode($postArray['ID']);

		// Check if post is locked (editing not allowed)
		if ($editingMode === self::EDITING_MODE_LOCKED && !current_user_can('accept_revisions', $postArray['ID'])) {
			return false;
		}

		// Check if accepted ID will be updated (ie., post not being saved as pending)
		$editingMode = $this->getEditingMode($postArray['ID']);
		if ($editingMode == self::EDITING_MODE_OPEN && !current_user_can('accept_revisions', $postArray['ID'])) { return $maybeEmpty; }
		if (current_user_can('accept_revisions', $postArray['ID']) && !isset($_POST['fpr-pending-revisions'])) { return $maybeEmpty; }

		// Accepted ID won't be updated: check if it's missing and force update
		$this->saveMissingAcceptedRevision($postArray);

		return $maybeEmpty;
	}

	// Returns all revisions that are not autosaves
	protected function getNonAutosaveRevisions($postID, $extraArgs=array()) {
		$args = array_merge(array('suppress_filters' => false), $extraArgs);
		add_filter('posts_where', array($this, 'filterOutAutosaves'), 10, 1);
		$revisions = wp_get_post_revisions($postID, $args);
		remove_filter('posts_where', array($this, 'filterOutAutosaves'));
		return $revisions;
	}

	// Returns the latest revision, excluding autosaves
	protected function getLatestRevision($postID, $extraArgs=array()) {
		$args = array_merge(array('posts_per_page' => 1), $extraArgs);
		$revisions = $this->getNonAutosaveRevisions($postID, $args);
		if (count($revisions) == 0) { return false; }
		return current($revisions);
	}

	// Adds the temporary WHERE clause needed to exclude autosave from the revisions list
	public function filterOutAutosaves($where) {
		global $wpdb;
		$where .= " AND {$wpdb->posts}.post_name NOT LIKE '%-autosave-v1'";
		return $where;
	}

	// Check taxonomy / featured image changes in order to force a revision
	public function checkExtraFieldChanges($hasChanged, $lastRevision, $post) {

		// Taxonomies
		$taxonomies = get_object_taxonomies($post->post_type);
		foreach ($taxonomies as $taxonomy) {
			$currentTerms = get_the_terms($post->ID, $taxonomy) ?: array();
			$revisionTerms = get_the_terms($lastRevision->ID, $taxonomy) ?: array();
			if ($currentTerms != $revisionTerms) {
				return true;
			}
		}

		// Featured image
		$currentThumbnail = get_post_meta($post->ID, '_thumbnail_id', true);
		$revisionThumbnail = get_post_meta($lastRevision->ID, '_thumbnail_id', true);
		if ($currentThumbnail != $revisionThumbnail) {
			return true;
		}

		// Return unchanged value otherwise
		return $hasChanged;
	}

	// Save taxonomy / featured image changes to revision
	public function saveAdditionalRevisionFields($revisionID) {
		$revision = get_post($revisionID);
		$post = get_post($revision->post_parent);

		// Save taxonomies to the revision
		$taxonomies = get_object_taxonomies($post->post_type);
		foreach ($taxonomies as $taxonomy) {
			$terms = get_the_terms($post->ID, $taxonomy) ?: array();
			if (empty($terms)) { continue; }
			$termIDs = wp_list_pluck($terms, 'term_id');
			wp_set_post_terms($revision->ID, $termIDs, $taxonomy);
		}

		// Save featured image to the revision
		$thumbnail = get_post_meta($post->ID, '_thumbnail_id', true);
		if (!empty($thumbnail)) {
			update_metadata('post', $revision->ID, '_thumbnail_id', $thumbnail, '');
		}
	}

	// Update accepted revision if allowed and revision ID from which this has originated
	public function saveAcceptedRevision($postID, $post, $update) {
		if (!isset($post->post_status) || $post->post_status != 'publish') { return; }
		if (!in_array(get_post_type($postID), $this->getEnabledPostTypes())) { return; }

		// Get the latest revision corresponding to the post, in order to save extra information per revision
		$args = array('post_author' => get_current_user_id());
		$revision = $this->getLatestRevision($postID, $args);

		// Save reference to revision on which this one is based
		if (!$revision) { return; } // No revision to accept or set source revision on
		if (isset($_POST['_fpr_source_revision_id'])) {

			// Using `update_metadata()` because `update_post_meta()` doesn't save metafields on revisions
			update_metadata('post', $revision->ID, '_fpr_source_revision_id', $_POST['_fpr_source_revision_id'], '');
		}

		// Check if user is authorised to publish changes
		$editingMode = $this->getEditingMode($postID);
		if ($editingMode !== self::EDITING_MODE_OPEN && !current_user_can('accept_revisions', $postID)) { return; }

		// Publish only if not set to save as pending revisions
		if (isset($_POST['fpr-pending-revisions'])) { return; }

		// Set pointer to accepted revision
		$acceptedID = $revision->ID;
		update_post_meta($postID, '_fpr_accepted_revision_id', $acceptedID);
	}

	// Change notification message when post is updated if pending approval
	public function changePostUpdatedMessages($messages) {
		global $post;
		if (empty($post)) { return; }
		$args = array('post_author' => get_current_user_id());
		$latestRevision = $this->getLatestRevision($post->ID, $args);
		$acceptedID = get_post_meta($post->ID, '_fpr_accepted_revision_id', true);
		if (!$acceptedID || !$latestRevision || $acceptedID == $latestRevision->ID) { return $messages; }

		// Revision saved by user is not the accepted: show pending revision submitted notice
		$settings = Settings::instance()->getSettings();
		$messages[$post->post_type][1] = sprintf(__($settings['revision_submitted_pending_approval_notification_message'] ?: '', self::DOMAIN), get_permalink($post));
		$messages[$post->post_type][4] = $messages[$post->post_type][1];
		if (isset($_GET['revision'])) {
			$postType = get_post_type_object($post->post_type);
			$messages[$post->post_type][5] =  sprintf(__($settings['revision_restored_pending_approval_notification_message'] ?: '', self::DOMAIN), esc_html($postType->labels->singular_name), wp_post_revision_title((int) $_GET['revision'], false));
		}

		return $messages;
	}

	// Get notification message to warn user if the current post revision is not the accepted one
	public function getRevisionNotAcceptedNotificationMessage($postID) {

		// Check if revision is the accepted
		$acceptedID = get_post_meta($postID, '_fpr_accepted_revision_id', true);
		$latestRevision = $this->getLatestRevision($postID);
		if (!$acceptedID || !$latestRevision || $acceptedID == $latestRevision->ID) { return ''; }

		// Add message according to user capabilities
		$settings = Settings::instance()->getSettings();
		$diffLink = admin_url('revision.php?from=' . $acceptedID . '&to=' . $latestRevision->ID);
		$message = $settings['revision_not_accepted_notification_message'] ?: '';
		if (current_user_can('accept_revisions', $postID)) {
			$message = $settings['revision_not_accepted_editors_notification_message'] ?: '';
		}

		return '<p>' . sprintf($message, $diffLink) . '</p>';
	}

	// Get notification messages according to post's editing mode
	private function getEditingModeNotificationMessage($postID) {
		if (current_user_can('accept_revisions', $postID)) { return; }

		// Show notifications depending on editing mode
		$settings = Settings::instance()->getSettings();
		$editingMode = $this->getEditingMode($postID);
		if ($editingMode !== self::EDITING_MODE_PENDING) { return ''; }

		$postType = get_post_type_object(get_post_type($postID));
		return '<p>' . sprintf(__($settings['edits_require_approval_notification_message'], self::DOMAIN), esc_html($postType->labels->singular_name)) . '</p>';
	}

	// Show collected notification messages
	public function showNotificationMessages() {

		// Don't show plugin notifications if there's a saved notification from WP
		if (!empty($_GET['message'])) { return; }
		if (empty($this->notificationMessages)) { return; }
		echo '<div class="fpr-notice notice notice-warning">' . $this->notificationMessages . '</div>';
	}

	// If `fpr-edit` GET variable is set, preload a given revision's fields
	private function initEditRevision($postID) {
		if (empty($_GET['fpr-edit']) || !is_numeric($_GET['fpr-edit'])) { return; }
		$revisionID = $_GET['fpr-edit'];
		$revision = wp_get_post_revision($revisionID);
		if (empty($revision) || $revision->post_parent != $postID) { return; }

		// User can only edit if they have sufficient permissions or revision's their own autosave
		if (!current_user_can('accept_revisions') && (!wp_is_post_autosave($revisionID) || $revision->post_author != get_current_user())) { return; }

		// Set WP default values
		global $post;
		$postID = $post->ID;
		$post->post_title = $revision->post_title;
		$post->post_content = $revision->post_content;
		$post->post_excerpt = $revision->post_excerpt;

		// Preload taxonomies
		add_filter('get_object_terms', function($terms, $objectIDs, $taxonomies, $args) use ($postID, $revisionID) {
			if (empty($postID) || empty($revisionID) || !is_array($objectIDs) || current($objectIDs) != $postID) {
				return $terms;
			}
			return wp_get_object_terms($revisionID, $taxonomies, $args);
		}, 100, 4);

		// Preload featured image
		add_filter('get_post_metadata', function($value, $objectID, $key, $single) use ($postID, $revisionID) {
			if (empty($postID) || empty($revisionID) || !is_numeric($objectID) || $objectID != $postID) {
				return $value;
			}
			if (strpos($key, '_fpr_') === 0) { return $value; }
			return get_metadata('post', $revisionID, $key, $single);
		}, 100, 4);
	}


	// Initialise page
	public function initPostEdit() {
		$screen = get_current_screen();
		$enabledPostTypes = $this->getEnabledPostTypes();
		if (!in_array($screen->post_type, $enabledPostTypes)) { return; }
		if ($screen->base == 'post') { // Edit post page
			$postID = get_the_ID();
			if (empty($postID)) { return; }

			// Get and show notification messages
			$this->notificationMessages = $this->getEditingModeNotificationMessage($postID);
			$this->notificationMessages .= $this->getRevisionNotAcceptedNotificationMessage($postID);
			if (!empty($this->notificationMessages)) {
				add_action('admin_notices', array($this, 'showNotificationMessages'));
			}

			// Edit a given revision if `fpr-edit` GET variable is set
			if (!empty($_GET['fpr-edit'])) {
				$this->initEditRevision($postID);
			}
		} else if ($screen->base == 'edit') { // Post list page

			// Add hooks to add pending revisions column to post list for all enabled post types
			$postTypeString = "{$screen->post_type}_posts";
			if ($postTypeString == 'post') {
				$postTypeString = 'posts';
			} else if ($postTypeString == 'page') {
				$postTypeString = 'pages';
			}
			add_action("manage_{$postTypeString}_columns", array($this, 'addPendingColumn'));
			add_action("manage_{$postTypeString}_custom_column", array($this, 'getPendingColumnContent'), 10, 2);
			add_action("manage_{$screen->id}_sortable_columns", array($this, 'sortablePendingColumn'));
		}
	}

	// Add button for allowed users to be able to save a pending revision rather than "publish"
	public function addPendingRevisionsButton() {

		// Show button to explicitly save as pending changes
		$screen = get_current_screen();
		if (!in_array($screen->post_type, $this->getEnabledPostTypes())) { return; }

		// Check if post is published and unlocked or user has publishing permissions
		global $post;
		if (empty($post) || $post->post_status !== 'publish') { return; }
		if (!current_user_can('accept_revisions', $post->ID)) { return; }

		$html = '<div class="fpr-pending-revisions-action">';
		$html .= '<input type="submit" name="fpr-pending-revisions" id="fpr-pending-revisions-submit" value="Save pending" class="button fpr-pending-revisions-action__button">';
		$html .= '</div>';
		echo $html;
	}

	// Change WP's default Update button text when appropriate
	public function alterText($translation, $text) {
		if ($text == 'Sorry, you are not allowed to edit this item.') {

			// Replace not allowed notice for locked posts
			global $post;
			if (empty($post)) { return $translation; }
			$editingMode = $this->getEditingMode($post->ID);
			if ($editingMode !== self::EDITING_MODE_LOCKED) { return $translation; }

			$settings = Settings::instance()->getSettings();
			$postType = get_post_type_object($post->post_type);
			return sprintf(__($settings['post_locked_notification_message'], self::DOMAIN), esc_html($postType->labels->singular_name), get_permalink($post));
		} else if ($text == 'Update') {

			// Replace Update button text
			global $post;
			if (empty($post) || $post->post_status !== 'publish') { return $translation; }
			$editingMode = $this->getEditingMode($post->ID);
			if ($editingMode !== self::EDITING_MODE_PENDING || current_user_can('accept_revisions', $post->ID)) { return $translation; }

			return __('Suggest edit', self::DOMAIN);
		}
		return $translation;
	}

	// Add metabox to change post's editing mode
	public function addPermissionsMetaBox() {
		$postID = get_the_ID();
		if (empty($postID) || !current_user_can('accept_revisions', $postID)) { return; }

		// Check if editing mode is enabled for post type
		if (!in_array(get_post_type($postID), $this->getEnabledPostTypes())) { return; }

		add_meta_box('fpr_editing_mode_box', 'Permissions', array($this, 'showEditingModeMetaBox'), null, 'side', 'high', array());
	}

	// Build and show metabox to change post's editing mode
	public function showEditingModeMetaBox() {

		// Dropdown to select post's editing mode
		$postID = get_the_ID();
		if (empty($postID) || !current_user_can('accept_revisions', $postID)) { return; }

		$editingMode = $this->getEditingMode($postID);
		echo '<p><label for="fpr-editing-mode" class="fpr-editing-mode__label">Editing Mode</label></p>';
		foreach (self::EDITING_MODES as $choice => $choiceData) {
			if ($choice == self::EDITING_MODE_OFF) { continue; }
			echo '<label class="fpr-editing-mode__input-label"><input type="radio" name="fpr-editing-mode" value="' . $choice . '" ' . checked($editingMode, $choice, false) . '>' . __($choiceData['name'], self::DOMAIN) . '</label>';
		}
		echo '<p class="fpr-editing-mode__button"><button class="button">Change editing mode</button></p>';
	}

	// Change get posts query to exclude locked posts
	public function disableLockedPosts($allcaps, $cap, $args) {
		if (!in_array($args[0], array('edit_post')) || empty($args[2])) { return $allcaps; }
		if (isset($allcaps['accept_revisions']) && $allcaps['accept_revisions']) { return $allcaps; }

		$editingMode = $this->getEditingMode($args[2]);
		if ($editingMode != self::EDITING_MODE_LOCKED) { return $allcaps; }

		// Post is locked and user does not have `accept_revisions` capability
		return false;
	}

	// Add Pending Revisions column
	public function addPendingColumn($columns) {
		$columns['pending_revisions'] = __('Pending Revisions');
		return $columns;
	}

	// Filter for Pending Revisions column content
	public function getPendingColumnContent($column, $postID) {
		if ($column !== 'pending_revisions') { return; }

		$acceptedID = get_post_meta($postID, '_fpr_accepted_revision_id', true);
		if (empty($acceptedID)) {
			echo '—';
			return;
		}
		$accepted = get_post($acceptedID);

		// Get all revisions created after the accepted revision (ie., are pending)
		$args = array(
			'date_query' => array(
				array(
					'after'     => $accepted->post_date,
					'inclusive' => false,
				),
			),
			'posts_per_page' => -1,
		);
		$revisions = $this->getNonAutosaveRevisions($postID, $args);
		$revisionsCount = count($revisions);
		if ($revisionsCount === 0) {
			echo '—';
			return;
		}

		// Link to diff between accepted and latest pending revision
		$diffLink = admin_url('revision.php?from=' . $acceptedID . '&to=' . current($revisions)->ID);
		echo '<a href="' . $diffLink . '">' . $revisionsCount . '</a>';
	}

	// Set Pending Revisions column as sortable
	public function sortablePendingColumn($columns) {
		$columns['pending_revisions'] = 'pending_revisions';
		return $columns;
	}

	// Sort Pending Revisions column
	public function sortByPendingColumn($orderby, $query) {
		if (!$query->is_main_query() || $query->get('orderby') != 'pending_revisions') { return $orderby; }

		$order = strtoupper($query->get('order'));
		if (!in_array($order, array('ASC', 'DESC'))) { $order = 'ASC'; }

		// Count post's revisions posterior to post's accepted revision
		global $wpdb;
		$orderby = "(SELECT COUNT(*)
			FROM {$wpdb->posts} AS revisions, {$wpdb->postmeta} AS postmeta
			WHERE postmeta.meta_key = '_fpr_accepted_revision_id'
				AND postmeta.post_id = {$wpdb->posts}.id
				AND revisions.post_type = 'revision'
				AND revisions.post_name not like '%-autosave-v1'
				AND revisions.id > postmeta.meta_value
				AND revisions.post_parent = {$wpdb->posts}.id) {$order}";

		return $orderby;
	}

	// Enable filters for getting posts in Browse revisions page, so that Autosaves can be removed, and when sorting 'pending_revisions' column
	public function enablePostsFilters($query) {
		if ($query->is_main_query() && $query->get('orderby') == 'pending_revisions') {
			$query->set('suppress_filters', false);
			return;
		}
		$screen = get_current_screen();
		if (!empty($screen) && $screen->base == 'revision') {
			$query->set('suppress_filters', false);
		}
	}

	// Remove Autosave revisions from Browse revisions page
	public function filterBrowseRevisions($where) {
		$screen = get_current_screen();
		if (!$screen || $screen->base != 'revision') { return $where; }
		if (empty($_GET['revision'])) { return $this->filterOutAutosaves($where); }

		// Check if revision is an autosave
		$postID = wp_is_post_autosave($_GET['revision']);
		if (!$postID) { return $this->filterOutAutosaves($where); }

		// Exclude every autosave but the requested one
		global $wpdb;
		$where .= " AND (" . $wpdb->prefix . "posts.post_name NOT LIKE '%-autosave-v1'";
		$where .= " OR " . $wpdb->prefix . "posts.ID = " . $_GET['revision'] . ")";

		return $where;
	}

	// Add a body class to Browse revisions when showing an autosave
	public function addAutosaveBodyClass($classes) {
		$screen = get_current_screen();
		if (!$screen || $screen->base != 'revision') { return $classes; }
		if (empty($_GET['revision'])) { return $classes; }

		// Check if revision is an autosave
		$postID = wp_is_post_autosave($_GET['revision']);
		if (!$postID) { return $classes; }

		$classes .= ' fpr-autosave-revision ';
		return $classes;
	}

	// Register extra revision fields for revision timeline
	public function showExtraRevisionFields($fields, $post) {
		if (empty($post)) { return $fields; }

		// Taxonomies
		$taxonomies = get_object_taxonomies($post['post_type'], 'objects');
		foreach ($taxonomies as $taxonomy) {
			$slug = '_tax_' . $taxonomy->name;
			$fields[$slug] = $taxonomy->label;
			add_filter("_wp_post_revision_field_{$slug}", array($this, 'displayExtraRevisionField'), 10, 4);
		}

		// Featured image
		$fields['featured-image'] = 'Featured image';
		add_filter("_wp_post_revision_field_featured-image", array($this, 'displayExtraRevisionField'), 10, 4);

		return $fields;
	}

	// Show extra revision fields on revision timeline
	public function displayExtraRevisionField($value, $field, $post, $direction) {
		if (substr($field, 0, 5) == '_tax_') {
			$terms = wp_get_post_terms($post->ID, substr($field, 5), array('fields' => 'names'));
			return join($terms, ', ');
		} else if ($field == 'featured-image') {
			return get_the_post_thumbnail_url($post->ID);
		}
	}

	// Update revisions data to show in Browse Revisions page, to reflect current accepted post
	public function prepareRevisionForJS($revisionData, $revision, $post) {

		// Set accepted flag in the revision pointed by the post
		$acceptedID = get_post_meta($post->ID, '_fpr_accepted_revision_id', true) ?: $this->getLatestRevision($post->ID)->ID;
		$revisionData['pending'] = false;
		if ($revision->ID == $acceptedID) {
			$revisionData['current'] = true;
		} else {
			$revisionData['current'] = false;
			$accepted = get_post($acceptedID);
			if (strtotime($revision->post_date) > strtotime($accepted->post_date)) {
				$revisionData['pending'] = true;
			}
		}
		$revisionData['postStatus'] = get_post_status($post);

		// Author role
		global $wp_roles;
		$author = get_userdata($revision->post_author);
		if (!empty($author)) {
			$authorRole = $author->roles[0];
			$revisionData['author']['role'] = translate_user_role($wp_roles->roles[$authorRole]['name']);
			$revisionData['author']['current'] = $revision->post_author == get_current_user_id();
		}

		// Revision note (if available)
		$revisionData['note'] = '';
		$notes = explode(' - ', $revisionData['timeAgo']);
		if (count($notes)) {
			$timeAgo = array_pop($notes);
			$notes = implode(' - ', $notes);
			$revisionData['timeAgo'] = $timeAgo;
			$revisionData['note'] = str_replace('Note: ', '', $notes);
		}

		// Revision on which this one is based
		$sourceRevisionID = get_post_meta($revision->ID, '_fpr_source_revision_id', true);
		if ($sourceRevisionID) {
			$revisionData['sourceRevisionID'] = $sourceRevisionID;
		}

		// Buttons URLs
		$revisionData['urls'] = array(
			'edit' => admin_url("post.php?post={$post->ID}&action=edit&fpr-edit={$revision->ID}"),
			'preview' => add_query_arg(array('fpr-preview' => $revision->ID), get_permalink($post->ID)),
			'view' => get_permalink($post->ID),
			'ajax' => admin_url('admin-ajax.php')
		);
		$revisionData['nonce'] = wp_create_nonce("fpr-publish-post_{$revision->ID}");
		$revisionData['userCanAccept'] = current_user_can('accept_revisions', $revision->ID);

		return $revisionData;
	}

	// Get data to send to Edit post page
	private function preparePostForJS() {
		global $post;
		if (!$post) {
			return array();
		}

		// Get non-autosave revisions count for Publish metabox info
		$args = array(
			'posts_per_page' => -1,
			'order' => 'DESC',
		);
		$revisions = $this->getNonAutosaveRevisions($post->ID, $args);
		$revisionsCount = count($revisions);
		$latestRevision = current($revisions);
		$latestRevisionID = $latestRevision ? $latestRevision->ID : false;
		$revisionEditing = isset($_GET['fpr-edit']) ? $_GET['fpr-edit'] : $latestRevisionID;
		$pendingCount = false;
		$revisionsUrl = admin_url('revision.php?revision=' . $revisionEditing);
		$acceptedID = get_post_meta($post->ID, '_fpr_accepted_revision_id', true);
		if ($acceptedID) {
			if ($acceptedID != $latestRevisionID) {
				$revisionsUrl = admin_url('revision.php?from=' . $acceptedID . '&to=' . $revisionEditing);
			}
			$pendingCount = 0;
			foreach ($revisions as $revision) {
				if ($revision->ID == $acceptedID) { break; }
				$pendingCount++;
			}
		}

		// Data to pass to Post's Javascript
		$editingMode = $this->getEditingMode($post->ID);
		return array(
			'post' => $post,
			'editingMode' => $editingMode,
			'revisionsCount' => $revisionsCount,
			'pendingCount' => $pendingCount,
			'acceptedID' => $acceptedID,
			'latestRevisionID' => $latestRevisionID,
			'isAutosave' => wp_is_post_autosave($revisionEditing),
			'canUserPublishPosts' => current_user_can('accept_revisions', $post->ID),
			'nonce' => wp_create_nonce("fpr-editing-mode-{$post->ID}"),
			'urls' => array(
				'revisions' => $revisionsUrl,
				'ajax' => admin_url('admin-ajax.php')
			)
		);
	}

	// Set JSs and CSSs for Edit post and Browse revisions pages
	public function enqueueScripts($hookSuffix) {
		wp_enqueue_style('fpr-style', plugin_dir_url(Plugin::MAIN_FILE) . 'css/main.css');
		if (in_array($hookSuffix, array('post.php', 'post-new.php'))) {
			wp_enqueue_style('fpr-post-style', plugin_dir_url(Plugin::MAIN_FILE) . 'css/post.css');
			wp_enqueue_script('fpr-post', plugin_dir_url(Plugin::MAIN_FILE) . 'js/post.js', array('jquery', 'revisions'));
			wp_localize_script('fpr-post', 'fprData', $this->preparePostForJS());
		} else if ($hookSuffix == 'revision.php') {
			wp_enqueue_style('fpr-revisions-style', plugin_dir_url(Plugin::MAIN_FILE) . 'css/revisions.css');
			wp_enqueue_script('fpr-revisions', plugin_dir_url(Plugin::MAIN_FILE) . 'js/revisions.js', array('jquery', 'revisions'));
		} else if ($hookSuffix == 'settings_page_fpr-settings') {
			wp_enqueue_style('fpr-settings-style', plugin_dir_url(Plugin::MAIN_FILE) . 'css/settings.css');
		}
	}
}

Base::instance()->init();
