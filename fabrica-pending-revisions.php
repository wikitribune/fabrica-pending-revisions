<?php
/*
Plugin Name: Fabrica Pending Revisions
Plugin URI:
Description:
Version: 0.0.3
Author: Fabrica
Author URI: https://fabri.ca/
Text Domain: fabrica-pending-revisions
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
*/

namespace Fabrica\PendingRevisions;

if (!defined('WPINC')) { die(); }

class Plugin {

	const EDITING_MODE_OFF = 'off';
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

	private $settings = array();

	public function __construct() {
		if (!is_admin()) {
			// [TODO] move frontend hooks and functions to their own class?
			add_filter('the_content', array($this, 'filterAcceptedRevisionContent'), -1);
			add_filter('the_excerpt', array($this, 'filterAcceptedRevisionExcerpt'), -1, 2);
			add_filter('the_title', array($this, 'filterAcceptedRevisionTitle'), -1, 2);
			add_filter('single_post_title', array($this, 'filterAcceptedRevisionTitle'), -1, 2);
			add_filter('acf/format_value_for_api', array($this, 'filterAcceptedRevisionField'), -1, 3); // ACF v4
			add_filter('acf/format_value', array($this, 'filterAcceptedRevisionField'), -1, 3); // ACF v5+

			return;
		}

		add_action('wp_ajax_fpr-editing-mode-save', array($this, 'savePermissions'));

		// Exit now if AJAX request, to hook admin-only requests after
		if (wp_doing_ajax()) { return; }

		// Saving hooks
		add_action('wp_insert_post_empty_content', array($this, 'checkSaveAllowed'), 99, 2);
		add_action('save_post', array($this, 'saveAcceptedRevision'), 10, 3);

		// Settings page
		add_action('admin_menu', array($this, 'addSettingsPage'));
		add_action('admin_init', array($this, 'registerSettings'), 20);

		// Layout, buttons and metaboxes
		add_action('admin_head', array($this, 'initPostEdit'));
		add_action('post_submitbox_start', array($this, 'addPendingRevisionsButton'));
		add_filter('gettext', array($this, 'alterText'), 10, 2);
		add_action('add_meta_boxes', array($this, 'addPermissionsMetaBox'));

		// Post list columns
		add_action('manage_posts_columns', array($this, 'addPendingColumn'));
		add_action('manage_posts_custom_column', array($this, 'getPendingColumnContent'), 10, 2);

		// Browse revisions
		add_action('pre_get_posts', array($this, 'enablePostsFilters'));
		add_filter('posts_where', array($this, 'filterBrowseRevisions'));

		// Scripts
		add_action('wp_prepare_revision_for_js', array($this, 'prepareRevisionForJS'), 10, 3);
		add_action('admin_enqueue_scripts', array($this, 'enqueueScripts'));
	}

	// Return plugin settings
	public function getSettings() {
		$this->settings = $this->settings ?: get_option('fpr-settings');
		return $this->settings;
	}

	// Get post types for which plugin is enabled
	public function getEnabledPostTypes() {
		$settings = $this->getSettings();
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

	// Replace content with post's accepted revision content
	public function filterAcceptedRevisionContent($content) {
		$postID = get_the_ID();
		if (empty($postID) || !in_array(get_post_type($postID), $this->getEnabledPostTypes())) { return $content; }
		$acceptedID = get_post_meta($postID, '_fpr_accepted_revision_id', true);
		if (!$acceptedID) { return $content; }

		return get_post_field('post_content', $acceptedID);
	}

	// Replace excerpt with post's accepted revision excerpt
	public function filterAcceptedRevisionExcerpt($excerpt, $postID) {
		if (!in_array(get_post_type($postID), $this->getEnabledPostTypes())) { return $excerpt; }
		$acceptedID = get_post_meta($postID, '_fpr_accepted_revision_id', true);
		if (!$acceptedID) { return $excerpt; }

		return get_post_field('post_excerpt', $acceptedID);
	}

	// Replace title with post's accepted revision title
	public function filterAcceptedRevisionTitle($title, $post) {
		$postID = is_object($post) ? $post->ID : $post;
		if (!in_array(get_post_type($postID), $this->getEnabledPostTypes())) { return $title; }
		$acceptedID = get_post_meta($postID, '_fpr_accepted_revision_id', true);
		if (!$acceptedID) { return $title; }

		return get_post_field('post_title', $acceptedID);
	}

	// Replace custom fields' data with post's accepted revision custom fields' data
	public function filterAcceptedRevisionField($value, $postID, $field) {
		if (!function_exists('get_field')) { return $value; }
		if (!in_array(get_post_type($postID), $this->getEnabledPostTypes()) || $field['name'] == 'accepted_revision_id') { return $value; }
		$acceptedID = get_post_meta($postID, '_fpr_accepted_revision_id', true);
		if (!$acceptedID) { return $value; }

		return get_field($field['name'], $acceptedID);
	}

	// Return the editing mode for a given post. If no editing mode is defined the post type's default editing mode is returned
	public function getEditingMode($postID) {
		// Get post type's default editing mode
		$settings = $this->getSettings();
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

	// Get user and posts permissions to determine whether or not post can be saved by current user
	public function checkSaveAllowed($maybeEmpty, $postArray) {
		$editingMode = $this->getEditingMode($postArray['ID']);

		// Check if post is locked (editing not allowed)
		if ($editingMode === self::EDITING_MODE_LOCKED && !current_user_can('accept_revisions', $postArray['ID'])) {
			return false;
		}

		return $maybeEmpty;
	}

	// Returns the latest published revision, excluding autosaves
	public function getNonAutosaveRevisions($postID, $extraArgs=array()) {
		$args = array_merge(array('suppress_filters' => false), $extraArgs);
		add_filter('posts_where', array($this, 'filterOutAutosaves'), 10, 1);
		$revisions = wp_get_post_revisions($postID, $args);
		remove_filter('posts_where', array($this, 'filterOutAutosaves'));
		return $revisions;
	}

	// Returns all revisions that are not autosaves
	public function getLatestPublishedRevision($postID, $extraArgs=array()) {
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

		// Check if user is authorised to publish changes
		$editingMode = $this->getEditingMode($postID);
		if ($editingMode !== self::EDITING_MODE_OPEN && !current_user_can('accept_revisions', $postID)) { return; }

		// Publish only if not set to save as pending revisions
		if (isset($_POST['fpr-pending-revisions'])) { return; }

		// Get accepted revision
		$args = array('post_author' => get_current_user_id());
		$revision = $this->getLatestPublishedRevision($postID, $args);
		if (!$revision) { return; } // No accepted revision

		// Set pointer to accepted revision
		$acceptedID = $revision->ID;
		update_post_meta($postID, '_fpr_accepted_revision_id', $acceptedID);
	}

	// Add settings page to admin menu
	function addSettingsPage() {
		add_options_page(
			'Fabrica Pending Revisions',
			'Pending Revisions',
			'manage_options',
			'fpr-settings',
			array($this, 'renderSettingsPage')
		);
	}

	// Build and show settings page
	function renderSettingsPage() {
		?><div class="wrap">
			<h1><?php _e('Fabrica Pending Revisions Settings', 'fabrica-pending-revisions'); ?></h1>
			<form method="post" action="options.php" class="fpr-default-editing-mode-settings"><?php
				settings_fields('fpr-settings');
				do_settings_sections('fpr-settings');
				submit_button();
			?></form>
		</div><?php
	}

	// Register custom settings
	public function registerSettings() {

		// Load settings to object property
		$this->getSettings();

		register_setting(
			'fpr-settings', // Option group
			'fpr-settings', // Option name
			array($this, 'sanitizeSettings') // Sanitize
		);

		add_settings_section(
			'default_editing_mode', // ID
			__('Default editing mode', 'fabrica-pending-revisions'), // Title
			array($this, 'renderDefaultEditingModeHeader'), // Callback
			'fpr-settings' // Page
		);

		// Register setting for each post type
		$args = array('public' => true);
		$postTypes = get_post_types($args, 'objects');
		foreach ($postTypes as $postType) {
			add_settings_field(
				$postType->name . '_default_editing_mode', // ID
				__($postType->label, 'fabrica-pending-revisions'), // Title
				array($this, 'renderDefaultEditingModeSetting'), // Callback
				'fpr-settings', // Page
				'default_editing_mode', // Section
				array('postType' => $postType) // Callback arguments
			);
		}
	}

	// Header for default settings section
	function renderDefaultEditingModeHeader() {
		echo '<p>' . __('Enable Pending Revisions for individual post types by choosing the default editing mode (authorised users can change the mode for individual posts). If left disabled the editing mode cannot be changed and all revisions are published automatically.', 'fabrica-pending-revisions') . '</p>';
		echo '<div class="fpr-default-editing-mode-settings__header">';
		foreach (self::EDITING_MODES as $choice => $choiceData) {
			echo '<span class="fpr-default-editing-mode-settings__header-title"><div class="fpr-default-editing-mode-settings__choice-caption">' . __($choiceData['name'], 'fabrica-pending-revisions') . '</div><div class="fpr-default-editing-mode-settings__choice-description">' . __($choiceData['description'], 'fabrica-pending-revisions') . '</div></span>';
		}
		echo '</div>';
	}

	// Build and show default editing mode custom setting
	function renderDefaultEditingModeSetting($data) {
		$settings = $this->getSettings();
		$fieldName = $data['postType']->name . '_default_editing_mode';
		$savedValue = isset($settings[$fieldName]) ? $settings[$fieldName] : 'off';
		$choiceDescriptions = array(
			self::EDITING_MODE_OFF => 'editing mode cannot be changed',
			self::EDITING_MODE_OPEN => 'all revisions accepted',
			self::EDITING_MODE_PENDING => 'suggestions must be approved',
			self::EDITING_MODE_LOCKED => 'only authorised users can edit'
		);
		foreach (self::EDITING_MODES as $choice => $choiceData) {
			?><span class="fpr-default-editing-mode-settings__radio">
				<input type="radio" id="<?php echo $fieldName . '-' . $choice; ?>" name="fpr-settings[<?php echo $fieldName; ?>]" <?php checked($savedValue, $choice); ?> value="<?php echo $choice; ?>">
				<label for="<?php echo $fieldName . '-' . $choice; ?>" class="fpr-default-editing-mode-settings__radio-label">
					<span class="fpr-default-editing-mode-settings__choice-caption"><?php _e($choiceData['name'], 'fabrica-pending-revisions'); ?></span>
					<span class="fpr-default-editing-mode-settings__choice-description"><?php _e($choiceData['description'], 'fabrica-pending-revisions'); ?></span>
				</label>
			</span><?php
		}
	}

	// Sanitize saved fields
	public function sanitizeSettings($input) {
		$sanitizedInput = array();
		$args = array('public' => true);
		$postTypes = get_post_types($args);
		$editingModesChoices = array_keys(self::EDITING_MODES);
		foreach ($postTypes as $postType) {
			$fieldName = $postType . '_default_editing_mode';
			if (isset($input[$fieldName]) && in_array($input[$fieldName], $editingModesChoices)) {
				$sanitizedInput[$fieldName] = $input[$fieldName];
			}
		}
		return $sanitizedInput;
	}

	// Show notification to warn user that the current post revision is not the accepted one
	function showRevisionNotTheAcceptedNotification() {
		if (empty($this->acceptedID) || empty($this->latestRevision)) { return; }
		$diffLink = admin_url('revision.php?from=' . $this->acceptedID . '&to=' . $this->latestRevision->ID);
		echo '<div class="notice notice-warning">' . $this->notificationMessages . '<p>' . sprintf(__('You are seeing suggested changes to this Story which are pending approval by an Editor. You\'ll be adding your own suggested changes to theirs below (if you need help spotting their suggestions, check the <a href="%s">compare the published and pending versions</a>).', 'fabrica-pending-revisions'), $diffLink) . '</p></div>';
	}

	// Initialise page according to post's editing mode
	private function initEditingMode($postID) {
		if (current_user_can('accept_revisions', $postID)) { return; }

		// Show notifications depending on editing mode
		$editingMode = $this->getEditingMode($postID);
		if ($editingMode === self::EDITING_MODE_PENDING) {
			$postType = get_post_type_object(get_post_type($postID));
			return '<p>' . sprintf(__('Changes to this %s require the approval of an editor before they will be made public.', 'fabrica-pending-revisions'), esc_html($postType->labels->singular_name)) . '</p>';
		} else if ($editingMode === self::EDITING_MODE_LOCKED) {

			// Hide the update/publish button completly if JS is not available to disable it
			echo '<style>#major-publishing-actions { display: none; }</style>';
			$postType = get_post_type_object(get_post_type($postID));
			return '<p><span class="dashicons dashicons-lock"></span> ' . sprintf(__('This %s is currently locked and cannot be edited; please try again later. In the meantime you can use the Talk page to discuss its contents.', 'fabrica-pending-revisions'), esc_html($postType->labels->singular_name)) . '</p>';
		}

		return '';
	}

	// Initialise page
	public function initPostEdit() {
		$screen = get_current_screen();
		if (!in_array($screen->post_type, $this->getEnabledPostTypes()) || $screen->base != 'post') { return; }
		$postID = get_the_ID();
		if (empty($postID)) { return; }

		// Get editing mode notification messages
		$this->notificationMessages = $this->initEditingMode($postID);

		// Show notification if post's current accepted revision is not this
		$acceptedID = get_post_meta($postID, '_fpr_accepted_revision_id', true) ?: $postID;
		$latestRevision = $this->getLatestPublishedRevision($postID);
		if ($acceptedID && $latestRevision && $acceptedID != $latestRevision->ID) {
			$this->acceptedID = $acceptedID;
			$this->latestRevision = $latestRevision;
			add_action('admin_notices', array($this, 'showRevisionNotTheAcceptedNotification'));
		}
	}

	// Add button for allowed users to be able to save a pending revision rather than "publish"
	public function addPendingRevisionsButton() {

		// Show button to explicitly save as pending changes
		$screen = get_current_screen();
		if (!in_array($screen->post_type, $this->getEnabledPostTypes()) || $screen->base != 'post') { return; }

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
		if ($text == 'Update') {

			// Replace Update button text
			global $post;
			if (empty($post) || $post->post_status !== 'publish') { return $translation; }
			$editingMode = $this->getEditingMode($post->ID);
			if ($editingMode !== self::EDITING_MODE_PENDING || current_user_can('accept_revisions', $post->ID)) { return $translation; }

			return 'Suggest edit';
		}
		return $translation;
	}

	// Add metabox to change post's editing mode
	public function addPermissionsMetaBox() {
		$postID = get_the_ID();
		if (empty($postID) || !current_user_can('accept_revisions', $postID)) { return; }

		// Check if editing mode is enabled for post type
		$settings = $this->getSettings();
		$settingName = get_post_type($postID) . '_default_editing_mode';
		$defaultEditingMode = isset($settings[$settingName]) ? $settings[$settingName] : '';
		if ($defaultEditingMode == self::EDITING_MODE_OFF || !in_array($defaultEditingMode, array_keys(self::EDITING_MODES))) {
			return;
		}

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
			echo '<label class="fpr-editing-mode__input-label"><input type="radio" name="fpr-editing-mode" value="' . $choice . '" ' . checked($editingMode, $choice, false) . '>' . __($choiceData['name'], 'fabrica-pending-revisions') . '</label>';
		}
		echo '<p class="fpr-editing-mode__button"><button class="button">Change editing mode</button></p>';
	}

	// Add Pending Revisions column
	function addPendingColumn($columns) {
		$columns['pending_revisions'] = __('Pending Revisions');
		return $columns;
	}

	// Filter for Pending Revisions column content
	function getPendingColumnContent($column, $postID) {
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
		if ($screen->base != 'revision') { return; }
		$query->set('suppress_filters', false);
	}

	// Remove Autosave revisions from Browse revisions page
	public function filterBrowseRevisions($where) {
		$screen = get_current_screen();
		if ($screen->base != 'revision') { return $where; }
		return $this->filterOutAutosaves($where);
	}

	// Update revisions data to show in Browse Revisions page, to reflect current accepted post
	public function prepareRevisionForJS($revisionsData, $revision, $post) {

		// Set accepted flag in the revision pointed by the post
		$acceptedID = get_post_meta($post->ID, '_fpr_accepted_revision_id', true) ?: $post->ID;
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
	public function preparePostForJS() {
		global $post;
		if (!$post) {
			return array();
		}

		// Data to pass to Post's Javascript
		$editingMode = $this->getEditingMode($post->ID);
		return array(
			'post' => $post,
			'editingMode' => $editingMode,
			'canUserPublishPosts' => current_user_can('accept_revisions', $post->ID),
			'url' => admin_url('admin-ajax.php')
		);
	}

	// Set JSs and CSSs for Edit post and Browse revisions pages
	public function enqueueScripts($hook_suffix) {
		if ($hook_suffix == 'post.php') {
			wp_enqueue_style('fpr-styles', plugin_dir_url(__FILE__) . 'css/main.css');
			wp_enqueue_script('fpr-post', plugin_dir_url(__FILE__) . 'js/post.js', array('jquery', 'revisions'));
			wp_localize_script('fpr-post', 'fprData', $this->preparePostForJS());
		} else if ($hook_suffix == 'revision.php') {
			wp_enqueue_style('fpr-styles', plugin_dir_url(__FILE__) . 'css/main.css');
			wp_enqueue_script('fpr-revisions', plugin_dir_url(__FILE__) . 'js/revisions.js', array('jquery', 'revisions'));
		} else if ($hook_suffix == 'settings_page_fpr-settings') {
			wp_enqueue_style('fpr-styles', plugin_dir_url(__FILE__) . 'css/settings.css');
		}
	}
}

new Plugin();
