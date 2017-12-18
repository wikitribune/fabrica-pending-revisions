<?php
/*
Plugin Name: Fabrica Pending Revisions
Plugin URI:
Description:
Version: 0.0.14
Author: Fabrica
Author URI: https://fabri.ca/
Text Domain: fabrica-pending-revisions
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
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
