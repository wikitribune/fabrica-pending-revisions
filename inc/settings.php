<?php

namespace Fabrica\PendingRevisions;

if (!defined('WPINC')) { die(); }

require_once('singleton.php');
require_once('base.php');

class Settings extends Singleton {
	private $settings;

	public function init() {
		add_action('admin_menu', array($this, 'addSettingsPage'));
		add_action('admin_init', array($this, 'registerSettings'), 20);
	}

	// Return plugin settings
	public function getSettings() {
		$this->settings = $this->settings ?: get_option('fpr-settings');

		// Default settings
		$this->settings['revision_submitted_pending_approval_notification_message'] = isset($this->settings['revision_submitted_pending_approval_notification_message']) ? $this->settings['revision_submitted_pending_approval_notification_message'] : 'Revision submitted and pending approval by an Editor and the community. <a href="%s">View the original article</a>';
		$this->settings['revision_not_accepted_notification_message'] = isset($this->settings['revision_not_accepted_notification_message']) ? $this->settings['revision_not_accepted_notification_message'] : 'You are seeing suggested changes to this Story which are pending approval by an Editor. You\'ll be adding your own suggested changes to theirs below (if you need help spotting their suggestions, check the <a href="%s">compare the published and pending versions</a>).';
		$this->settings['revision_not_accepted_editors_notification_message'] = isset($this->settings['revision_not_accepted_editors_notification_message']) ? $this->settings['revision_not_accepted_editors_notification_message'] : 'You are seeing suggested changes to this Story which are pending approval by an Editor. <a href="%s">Compare the published and pending versions</a>';
		$this->settings['edits_require_approval_notification_message'] = isset($this->settings['edits_require_approval_notification_message']) ? $this->settings['edits_require_approval_notification_message'] : 'Changes to this %s require the approval of an editor before they will be made public.';
		$this->settings['post_locked_notification_message'] = isset($this->settings['post_locked_notification_message']) ? $this->settings['post_locked_notification_message'] : 'This %1$s is currently locked and cannot be edited; please try again later. In the meantime you can use the <a href="%2$s#talk">Talk page</a> to discuss its contents.';

		return $this->settings;
	}

	// Add settings page to admin menu
	public function addSettingsPage() {
		add_options_page(
			'Fabrica Pending Revisions',
			'Pending Revisions',
			'manage_options',
			'fpr-settings',
			array($this, 'renderSettingsPage')
		);
	}

	// Build and show settings page
	public function renderSettingsPage() {
		?><div class="wrap">
			<h1><?php _e('Fabrica Pending Revisions Settings', Base::DOMAIN); ?></h1>
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
			__('Default editing mode', Base::DOMAIN), // Title
			array($this, 'renderDefaultEditingModeHeader'), // Callback
			'fpr-settings' // Page
		);

		add_settings_section(
			'notifications_messages', // ID
			__('Notifications messages', Base::DOMAIN), // Title
			array($this, 'renderNotificationsMessagesHeader'), // Callback
			'fpr-settings' // Page
		);

		// Register default editing mode setting for each post type
		$args = array('public' => true);
		$postTypes = get_post_types($args, 'objects');
		foreach ($postTypes as $postType) {
			if ($postType->name == 'attachment') { continue; }
			add_settings_field(
				$postType->name . '_default_editing_mode', // ID
				__($postType->label, Base::DOMAIN), // Title
				array($this, 'renderDefaultEditingModeSetting'), // Callback
				'fpr-settings', // Page
				'default_editing_mode', // Section
				array('postType' => $postType) // Callback arguments
			);
		}

		// Register notifications messages settings
		add_settings_field(
			'revision_submitted_pending_approval_notification_message', // ID
			__('Revision submitted and pending approval', Base::DOMAIN), // Title
			array($this, 'renderNotificationMessageSetting'), // Callback
			'fpr-settings', // Page
			'notifications_messages', // Section
			array(
				'notificationMessage' => 'revision_submitted_pending_approval_notification_message',
				'note' => __('Use <code>%s</code> for post (accepted revision) permalink.'),
			) // Callback arguments
		);

		add_settings_field(
			'revision_not_accepted_notification_message', // ID
			__('Revision being edited not the accepted revision (contributors message)', Base::DOMAIN), // Title
			array($this, 'renderNotificationMessageSetting'), // Callback
			'fpr-settings', // Page
			'notifications_messages', // Section
			array(
				'notificationMessage' => 'revision_not_accepted_notification_message',
				'note' => __('Use <code>%s</code> for accepted and pending revisions comparison URL.'),
			) // Callback arguments
		);

		add_settings_field(
			'revision_not_accepted_editors_notification_message', // ID
			__('Revision being edited not the accepted revision (editors message)', Base::DOMAIN), // Title
			array($this, 'renderNotificationMessageSetting'), // Callback
			'fpr-settings', // Page
			'notifications_messages', // Section
			array(
				'notificationMessage' => 'revision_not_accepted_editors_notification_message',
				'note' => __('Use <code>%s</code> for post type name.'),
			) // Callback arguments
		);

		add_settings_field(
			'edits_require_approval_notification_message', // ID
			__('Post edits require approval', Base::DOMAIN), // Title
			array($this, 'renderNotificationMessageSetting'), // Callback
			'fpr-settings', // Page
			'notifications_messages', // Section
			array(
				'notificationMessage' => 'edits_require_approval_notification_message',
				'note' => __('Use <code>%s</code> for post type name.'),
			) // Callback arguments
		);

		add_settings_field(
			'post_locked_notification_message', // ID
			__('Post locked for changes', Base::DOMAIN), // Title
			array($this, 'renderNotificationMessageSetting'), // Callback
			'fpr-settings', // Page
			'notifications_messages', // Section
			array(
				'notificationMessage' => 'post_locked_notification_message',
				'note' => __('Use <code>%1$s</code> for post type name and <code>%2$s</code> for post permalink.'),
			) // Callback arguments
		);
	}

	// Header for default settings section
	public function renderDefaultEditingModeHeader() {
		echo '<p>' . __('Enable Pending Revisions for individual post types by choosing the default editing mode (authorised users can change the mode for individual posts). If left disabled the editing mode cannot be changed and all revisions are published automatically.', Base::DOMAIN) . '</p>';
		echo '<div class="fpr-default-editing-mode-settings__header">';
		foreach (Base::EDITING_MODES as $choice => $choiceData) {
			echo '<span class="fpr-default-editing-mode-settings__header-title"><div class="fpr-default-editing-mode-settings__choice-caption">' . __($choiceData['name'], Base::DOMAIN) . '</div><div class="fpr-default-editing-mode-settings__choice-description">' . __($choiceData['description'], Base::DOMAIN) . '</div></span>';
		}
		echo '</div>';
	}

	// Header for default settings section
	public function renderNotificationsMessagesHeader() {
		// Empty on purpose
	}

	// Build and show default editing mode custom setting
	public function renderDefaultEditingModeSetting($data) {
		$settings = $this->getSettings();
		$fieldName = $data['postType']->name . '_default_editing_mode';
		$savedValue = isset($settings[$fieldName]) ? $settings[$fieldName] : '';
		$savedValue = in_array($savedValue, array_keys(Base::EDITING_MODES)) ? $savedValue : '';
		foreach (Base::EDITING_MODES as $choice => $choiceData) {
			?><span class="fpr-default-editing-mode-settings__radio">
				<input type="radio" id="<?php echo $fieldName . '-' . $choice; ?>" name="fpr-settings[<?php echo $fieldName; ?>]" <?php checked($savedValue, $choice); ?> value="<?php echo $choice; ?>">
				<label for="<?php echo $fieldName . '-' . $choice; ?>" class="fpr-default-editing-mode-settings__radio-label">
					<span class="fpr-default-editing-mode-settings__choice-caption"><?php _e($choiceData['name'], Base::DOMAIN); ?></span>
					<span class="fpr-default-editing-mode-settings__choice-description"><?php _e($choiceData['description'], Base::DOMAIN); ?></span>
				</label>
			</span><?php
		}
	}

	// Build and show a notification message custom setting
	public function renderNotificationMessageSetting($data) {
		if (empty($data['notificationMessage'])) { return; }
		$settings = $this->getSettings();
		$savedValue = isset($settings[$data['notificationMessage']]) ? $settings[$data['notificationMessage']] : '';
		echo '<textarea name="fpr-settings[' . $data['notificationMessage'] . ']" rows="6" class="fpr-notification-message-settings">' . $savedValue . '</textarea>';
		if (!empty($data['note'])) {
			echo '<div class="fpr-notification-message-settings__note"><em>' . $data['note'] . '</em></div>';
		}
	}

	// Sanitize saved fields
	public function sanitizeSettings($input) {
		$sanitizedInput = array();
		$args = array('public' => true);
		$postTypes = get_post_types($args);
		$editingModesChoices = array_keys(Base::EDITING_MODES);
		foreach ($postTypes as $postType) {
			if ($postType == 'attachment') { continue; }
			$fieldName = $postType . '_default_editing_mode';
			if (isset($input[$fieldName]) && $input[$fieldName] != Base::EDITING_MODE_OFF && in_array($input[$fieldName], $editingModesChoices)) {
				$sanitizedInput[$fieldName] = $input[$fieldName];
			}
		}

		$fieldNames = array(
			'revision_submitted_pending_approval_notification_message',
			'revision_not_accepted_notification_message',
			'revision_not_accepted_editors_notification_message',
			'edits_require_approval_notification_message',
			'post_locked_notification_message',
		);
		foreach ($fieldNames as $fieldName) {
			if (isset($input[$fieldName])) {
				$sanitizedInput[$fieldName] = $input[$fieldName];
			}
		}

		return $sanitizedInput;
	}
}

Settings::instance()->init();
