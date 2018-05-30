<?php
/*
Plugin Name: Fabrica Pending Revisions
Plugin URI: https://github.com/wikitribune/fabrica-pending-revisions
Description: Enables updates to published content to be held in a draft state, or to be submitted for moderation and approval before they go live. Also makes WP’s native Revisions more accountable by extending the system’s tracking of changes to taxonomy items and featured images, and improves the ‘Compare Revisions’ interface.
Version: 0.1.0
Author: Fabrica
Author URI: https://fabri.ca/
Text Domain: fabrica-pending-revisions
License: MIT
License URI: https://opensource.org/licenses/MIT
*/

namespace Fabrica\PendingRevisions;

if (!defined('WPINC')) { die(); }

require_once('inc/singleton.php');

class Plugin extends Singleton {
	const MAIN_FILE = __FILE__;

	public function init() {
		if (!is_admin()) { return; }

		register_activation_hook(self::MAIN_FILE, array($this, 'activate'));
	}

	// Create `accept_revisions` capability and add to Admin role
	public function activate() {
		if (!current_user_can('activate_plugins')) { return; }
		$plugin = isset($_REQUEST['plugin']) ? $_REQUEST['plugin'] : '';
		check_admin_referer('activate-plugin_' . $plugin);

		// Check if Admin already has capability and if not add it
		$role = get_role('administrator');
		if (!$role || isset($role->capabilities['accept_revisions'])) { return; }
		$role->add_cap('accept_revisions');
	}
}

Plugin::instance()->init();

if (is_admin()) {
	require_once('inc/base.php');
	require_once('inc/settings.php');
} else {
	require_once('inc/front.php');
}
