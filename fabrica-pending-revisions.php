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
	public static $mainFile = __FILE__;
}

if (is_admin()) {
	require_once('inc/base.php');
	require_once('inc/settings.php');
} else {
	require_once('inc/front.php');
}
