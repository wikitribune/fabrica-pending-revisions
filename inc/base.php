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

	public function init() {
		if (!is_admin()) { return; }

		add_action('wp_ajax_fpr-editing-mode-save', array($this, 'savePermissions'));

		// Exit now if AJAX request, to hook admin-only requests after
		if (wp_doing_ajax()) { return; }

		// Saving hooks
		add_action('wp_insert_post_empty_content', array($this, 'checkSaveAllowed'), 99, 2);
		add_action('save_post', array($this, 'saveAcceptedRevision'), 10, 3);
		add_filter('post_updated_messages', array($this, 'changePostUpdatedMessages'));

		// Layout, buttons and metaboxes
		add_action('admin_head', array($this, 'initPostEdit'));
		add_action('post_submitbox_start', array($this, 'addPendingRevisionsButton'));
		add_filter('gettext', array($this, 'alterText'), 10, 2);
		add_action('add_meta_boxes', array($this, 'addPermissionsMetaBox'));

		// Disable locked posts
		add_action('user_has_cap', array($this, 'disableLockedPosts'), 10, 3);

		// Post list columns
		add_action('manage_posts_columns', array($this, 'addPendingColumn'));
		add_action('manage_posts_custom_column', array($this, 'getPendingColumnContent'), 10, 2);

		// Browse revisions
		add_action('pre_get_posts', array($this, 'enablePostsFilters'));
		add_filter('posts_where', array($this, 'filterBrowseRevisions'));
		add_filter('admin_body_class', array($this, 'addAutosaveBodyClass'));

		// Scripts
		add_action('wp_prepare_revision_for_js', array($this, 'prepareRevisionForJS'), 10, 3);
		add_action('admin_enqueue_scripts', array($this, 'enqueueScripts'));
	}

	// Get post types for which plugin is enabled
	public function getEnabledPostTypes() {
		$settings = Settings::instance()->getSettings();
		$args = array('public' => true);
		$postTypes = get_post_types($args);
		$enabledPostTypes = array();
		foreach ($postTypes as $postType) {
			$settingName = $postType . '_default_editing_mode';
			$defaultEditingMode = isset($settings[$settingName]) ? $settings[$settingName] : '';
			if ($defaultEditingMode != self::EDITING_MODE_OFF && in_array($defaultEditingMode, array_keys(self::EDITING_MODES))) {
				$enabledPostTypes []= $postType;
			}
		}

		return $enabledPostTypes;
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
		if (!isset($_POST['data']['postID']) || !isset($_POST['data']['editingMode'])) { return; }
		$postID = $_POST['data']['postID'];
		$editingMode = $_POST['data']['editingMode'];
		update_post_meta($postID, '_fpr_editing_mode', $editingMode);
		exit();
	}

	// Check and update missing accepted revision — most likely plugin was not installed when post was created
	private function saveMissingAcceptedRevision($postArray) {
		if ($postArray['post_status'] != 'publish') { return; }
		$acceptedID = get_post_meta($postArray['ID'], '_fpr_accepted_revision_id');
		if ($acceptedID) { return; }

		// Post's accepted revision ID is not set: set it to the lastest revision no matter which since it's already assumed as published
		$revision = $this->getLatestRevision($postArray['ID']);
		if (!$revision) { return; } // No revision to accept (new post)

		// Set pointer to accepted revision
		$acceptedID = $revision->ID;
		update_post_meta($postArray['ID'], '_fpr_accepted_revision_id', $acceptedID);
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
		$where .= " AND " . $wpdb->prefix . "posts.post_name NOT LIKE '%-autosave-v1'";
		return $where;
	}

	// Update accepted revision if allowed
	public function saveAcceptedRevision($postID, $post, $update) {
		if (!in_array(get_post_type($postID), $this->getEnabledPostTypes())) { return; }

		// Check if user is authorised to publish changes
		$editingMode = $this->getEditingMode($postID);
		if ($editingMode !== self::EDITING_MODE_OPEN && !current_user_can('accept_revisions', $postID)) { return; }

		// Publish only if not set to save as pending revisions
		if (isset($_POST['fpr-pending-revisions'])) { return; }

		// Get accepted revision
		$args = array('post_author' => get_current_user_id());
		$revision = $this->getLatestRevision($postID, $args);
		if (!$revision) { return; } // No revision to accept

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
		echo '<div class="notice notice-warning">' . $this->notificationMessages . '</div>';
	}

	// Initialise page
	public function initPostEdit() {
		$postID = get_the_ID();
		if (empty($postID) || !in_array(get_post_type($postID), $this->getEnabledPostTypes()) || get_current_screen()->base != 'post') { return; }

		// Get and show notification messages
		$this->notificationMessages = $this->getEditingModeNotificationMessage($postID);
		$this->notificationMessages .= $this->getRevisionNotAcceptedNotificationMessage($postID);

		if (!empty($this->notificationMessages)) {
			add_action('admin_notices', array($this, 'showNotificationMessages'));
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
		if ($column === 'pending_revisions') {

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
	}

	// Enable filters getting posts in Browse revisions page, so that Autosaves can be removed
	public function enablePostsFilters($query) {
		$screen = get_current_screen();
		if (!$screen || $screen->base != 'revision') { return; }
		$query->set('suppress_filters', false);
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

	// Update revisions data to show in Browse Revisions page, to reflect current accepted post
	public function prepareRevisionForJS($revisionsData, $revision, $post) {

		// Set accepted flag in the revision pointed by the post
		$acceptedID = get_post_meta($post->ID, '_fpr_accepted_revision_id', true) ?: $this->getLatestRevision($post->ID)->ID;
		$revisionsData['pending'] = false;
		if ($revision->ID == $acceptedID) {
			$revisionsData['current'] = true;
		} else {
			$revisionsData['current'] = false;
			$accepted = get_post($acceptedID);
			if (strtotime($revision->post_date) > strtotime($accepted->post_date)) {
				$revisionsData['pending'] = true;
			}
		}

		return $revisionsData;
	}

	// Get data to send to Edit post page
	private function preparePostForJS() {
		global $post;
		if (!$post) {
			return array();
		}

		// Data to pass to Post's Javascript
		$editingMode = $this->getEditingMode($post->ID);
		$latestRevisionID = $this->getLatestRevision($post->ID)->ID;
		return array(
			'post' => $post,
			'editingMode' => $editingMode,
			'latestRevisionID' => $latestRevisionID,
			'canUserPublishPosts' => current_user_can('accept_revisions', $post->ID),
			'url' => admin_url('admin-ajax.php')
		);
	}

	// Set JSs and CSSs for Edit post and Browse revisions pages
	public function enqueueScripts($hookSuffix) {
		if (in_array($hookSuffix, array('post.php', 'post-new.php'))) {
			wp_enqueue_style('fpr-styles', plugin_dir_url(Plugin::MAIN_FILE) . 'css/main.css');
			wp_enqueue_script('fpr-post', plugin_dir_url(Plugin::MAIN_FILE) . 'js/post.js', array('jquery', 'revisions'));
			wp_localize_script('fpr-post', 'fprData', $this->preparePostForJS());
		} else if ($hookSuffix == 'revision.php') {
			wp_enqueue_style('fpr-styles', plugin_dir_url(Plugin::MAIN_FILE) . 'css/main.css');
			wp_enqueue_script('fpr-revisions', plugin_dir_url(Plugin::MAIN_FILE) . 'js/revisions.js', array('jquery', 'revisions'));
		} else if ($hookSuffix == 'settings_page_fpr-settings') {
			wp_enqueue_style('fpr-styles', plugin_dir_url(Plugin::MAIN_FILE) . 'css/settings.css');
		}
	}
}

Base::instance()->init();
