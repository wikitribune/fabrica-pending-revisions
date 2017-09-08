<?php
/*
Plugin Name: Fabrica Pending Changes
Plugin URI:
Description:
Version: 0.0.1
Author: Fabrica
Author URI: https://fabri.ca/
Text Domain: fabrica-pending-changes
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
*/

namespace Fabrica\PendingChanges;

if (!defined('WPINC')) { die(); }

class Plugin {

	public function __construct() {

		if (!is_admin()) {
			// [TODO] move frontend hooks and functions to their own class?
			add_filter('the_content', array($this, 'acceptedRevisionContent'), -1);
			add_filter('the_title', array($this, 'acceptedRevisionTitle'), -1, 2);
			add_filter('the_excerpt', array($this, 'acceptedRevisionExcerpt'), -1, 2);
			add_filter('acf/format_value_for_api', array($this, 'acceptedRevisionField'), -1, 3); // ACF v4
			add_filter('acf/format_value', array($this, 'acceptedRevisionField'), -1, 3); // ACF v5+

			return;
		}

		add_action('save_post', array($this, 'saveAcceptedRevision'), 10, 3);
		// [TODO] show only for edit post
		add_action('post_submitbox_start', array($this, 'addButton'));
		add_action('wp_prepare_revision_for_js', array($this, 'prepareRevisionForJS'), 10, 3);
		add_action('admin_enqueue_scripts', array($this, 'loadScript'));
	}

	public function acceptedRevisionContent($content) {
		$postID = get_the_ID();
		if (get_post_type($postID) != 'post') { return $content; }
		$acceptedID = get_post_meta($postID, '_fcp_accepted_revision_id', true);
		if (!$acceptedID) { return $content; }

		return get_post_field('post_content', $acceptedID);
	}

	public function acceptedRevisionTitle($title, $postID) {
		if (get_post_type($postID) != 'post') { return $title; }
		$acceptedID = get_post_meta($postID, '_fcp_accepted_revision_id', true);
		if (!$acceptedID) { return $title; }

		return get_post_field('post_title', $acceptedID);
	}

	public function acceptedRevisionExcerpt($excerpt, $postID) {
		if (get_post_type($postID) != 'post') { return $excerpt; }
		$acceptedID = get_post_meta($postID, '_fcp_accepted_revision_id', true);
		if (!$acceptedID) { return $excerpt; }

		return get_post_field('post_excerpt', $acceptedID);
	}

	public function acceptedRevisionField($value, $postID, $field) {
		if (!function_exists('get_field')) { return $value; }
		if (get_post_type($postID) != 'post' || $field['name'] == 'accepted_revision_id') { return $value; }
		$acceptedID = get_post_meta($postID, '_fcp_accepted_revision_id', true);
		if (!$acceptedID) { return $value; }

		return get_field($field['name'], $acceptedID);
	}

	public function saveAcceptedRevision($postID, $post, $update) {
		if (isset($_POST['pending-changes'])) { return; }

		// Get accepted revision
		$args = array(
			'post_author' => $post->post_author,
			'posts_per_page' => 1
		);
		$revisions = wp_get_post_revisions($postID, $args);
		if (count($revisions) < 1) { return $data; } // No accepted revision

		// Set pointer to accepted revision
		$acceptedID = current($revisions)->ID;
		if (!add_post_meta($postID, '_fcp_accepted_revision_id', $acceptedID, true)) {
			update_post_meta($postID, '_fcp_accepted_revision_id', $acceptedID, '');
		}
	}

	public function addButton() {
		// [TODO] show only for editors (and possibly original author)
		// [FIXME] fix: move styling to CSS file
		$html = '<div class="pending-changes-action" style="text-align: right; line-height: 23px; margin-bottom: 12px;">';
		$html .= '<input type="submit" name="pending-changes" id="pending-changes-submit" value="Save draft" class="button-primary">';
		$html .= '</div>';
		echo $html;
	}

	public function prepareRevisionForJS($revisionsData, $revision, $post) {

		// Set accepted flag in the revision pointed by the post
		$acceptedID = get_post_meta($post->ID, '_fcp_accepted_revision_id', true) ?: $post->ID;
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

	public function loadScript($hook_suffix) {
		if ($hook_suffix == 'revision.php') {
			wp_enqueue_script('fc-pending-changes', plugin_dir_url( __FILE__ ) . 'js/pending-changes.js', array('jquery', 'revisions'));
		}
	}
}

new Plugin();
