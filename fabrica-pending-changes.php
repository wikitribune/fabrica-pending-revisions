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

	const EDITING_MODE_OPEN = '';
	const EDITING_MODE_PENDING = 'pending';
	const EDITING_MODE_LOCKED = 'locked';

	public function __construct() {

		if (!is_admin()) {
			// [TODO] move frontend hooks and functions to their own class?
			add_filter('the_content', array($this, 'filterAcceptedRevisionContent'), -1);
			add_filter('the_title', array($this, 'filterAcceptedRevisionTitle'), -1, 2);
			add_filter('the_excerpt', array($this, 'filterAcceptedRevisionExcerpt'), -1, 2);
			add_filter('acf/format_value_for_api', array($this, 'filterAcceptedRevisionField'), -1, 3); // ACF v4
			add_filter('acf/format_value', array($this, 'filterAcceptedRevisionField'), -1, 3); // ACF v5+

			return;
		}

		add_action('admin_head', array($this, 'hideUpdateButton'));
		add_action('wp_insert_post_empty_content', array($this, 'checkSaveAllowed'), 99, 2);
		add_action('save_post', array($this, 'saveAcceptedRevision'), 10, 3);
		add_action('add_meta_boxes', array($this, 'addPermissionsMetaBox'));
		// [TODO] show only for edit post
		add_action('post_submitbox_start', array($this, 'addPublishButton'));
		add_filter('gettext', array($this, 'alterText'), 10, 2);
		add_action('wp_prepare_revision_for_js', array($this, 'prepareRevisionForJS'), 10, 3);
		add_action('admin_enqueue_scripts', array($this, 'enqueueScripts'));
	}

	// Replace content with post's accepted revision content
	public function filterAcceptedRevisionContent($content) {
		$postID = get_the_ID();
		if (empty($postID) || get_post_type($postID) != 'post') { return $content; }
		$acceptedID = get_post_meta($postID, '_fpc_accepted_revision_id', true);
		if (!$acceptedID) { return $content; }

		return get_post_field('post_content', $acceptedID);
	}

	// Replace title with post's accepted revision title
	public function filterAcceptedRevisionTitle($title, $postID) {
		if (get_post_type($postID) != 'post') { return $title; }
		$acceptedID = get_post_meta($postID, '_fpc_accepted_revision_id', true);
		if (!$acceptedID) { return $title; }

		return get_post_field('post_title', $acceptedID);
	}

	// Replace excerpt with post's accepted revision excerpt
	public function filterAcceptedRevisionExcerpt($excerpt, $postID) {
		if (get_post_type($postID) != 'post') { return $excerpt; }
		$acceptedID = get_post_meta($postID, '_fpc_accepted_revision_id', true);
		if (!$acceptedID) { return $excerpt; }

		return get_post_field('post_excerpt', $acceptedID);
	}

	// Replace custom fields' data with post's accepted revision custom fields' data
	public function filterAcceptedRevisionField($value, $postID, $field) {
		if (!function_exists('get_field')) { return $value; }
		if (get_post_type($postID) != 'post' || $field['name'] == 'accepted_revision_id') { return $value; }
		$acceptedID = get_post_meta($postID, '_fpc_accepted_revision_id', true);
		if (!$acceptedID) { return $value; }

		return get_field($field['name'], $acceptedID);
	}

	public function hideUpdateButton() {
		$screen = get_current_screen();
		if ($screen->id !== 'post') { return; }
		$postID = get_the_ID();
		if (empty($postID)) { return; }

		// Hide the update/publish button completly if JS is not available to disable it
		$editingMode = get_post_meta($postID, '_fpc_editing_mode', true) ?: '';
		if ($editingMode === self::EDITING_MODE_LOCKED && !current_user_can('publish_posts', $postID)) {
			echo '<style>#major-publishing-actions { display: none; }</style>';
		}
	}

	public function addPermissionsMetaBox() {
		$postID = get_the_ID();
		if (empty($postID) || !current_user_can('publish_posts', $postID)) { return; }

		add_meta_box('fpc_editing_mode_box', 'Permissions', array($this, 'showEditingModeMetaBox'), 'post', 'side', 'high', array());
	}

	public function showEditingModeMetaBox() {

		// Dropdown to select post's editing mode
		$postID = get_the_ID();
		if (empty($postID) || !current_user_can('publish_posts', $postID)) { return; }

		$editingMode = get_post_meta($postID, '_fpc_editing_mode', true) ?: '';
		echo '<p><label for="fpc-editing-mode" class="fpc-editing-mode__label">Editing Mode</label></p>';
		echo '<select name="fpc-editing-mode" id="fpc-editing-mode" class="fpc-editing-mode__select">';
		echo '<option value=""' . ($editingMode === self::EDITING_MODE_OPEN ? ' selected="selected"' : '') . '>Open</option>';
		echo '<option value="' . self::EDITING_MODE_PENDING . '"' . ($editingMode === self::EDITING_MODE_PENDING ? ' selected="selected"' : '') . '>Edits require approval</option>';
		echo '<option value="' . self::EDITING_MODE_LOCKED . '"' . ($editingMode === self::EDITING_MODE_LOCKED ? ' selected="selected"' : '') . '>Locked</option>';
		echo '</select>';
	}

	public function checkSaveAllowed($maybeEmpty, $postArray) {
		$editingMode = get_post_meta($postArray['ID'], '_fpc_editing_mode', true) ?: '';

		// Check if post is locked (editing not allowed)
		if ($editingMode === self::EDITING_MODE_LOCKED && !current_user_can('publish_posts', $postArray['ID'])) {
			return false;
		}

		return $maybeEmpty;
	}

	public function saveAcceptedRevision($postID, $post, $update) {

		// Check if user is authorised to publish changes
		$editingMode = get_post_meta($postArray['ID'], '_fpc_editing_mode', true) ?: '';
		if ($editingMode !== self::EDITING_MODE_OPEN && !current_user_can('publish_posts', $postID)) { return; }

		// Save editing mode changes
		if (isset($_POST['fpc-editing-mode'])) {
			if (!add_post_meta($postID, '_fpc_editing_mode', $_POST['fpc-editing-mode'], true)) {
				update_post_meta($postID, '_fpc_editing_mode', $_POST['fpc-editing-mode']);
			}
		}

		// Publish only if save is set publish
		if ($editingMode === self::EDITING_MODE_PENDING && !isset($_POST['fpc-publish-update'])) { return; }

		// Get accepted revision
		$args = array(
			'post_author' => $post->post_author,
			'posts_per_page' => 1
		);
		$revisions = wp_get_post_revisions($postID, $args);
		if (count($revisions) < 1) { return; } // No accepted revision

		// Set pointer to accepted revision
		$acceptedID = current($revisions)->ID;
		if (!add_post_meta($postID, '_fpc_accepted_revision_id', $acceptedID, true)) {
			update_post_meta($postID, '_fpc_accepted_revision_id', $acceptedID);
		}
	}

	public function addPublishButton() {
		$screen = get_current_screen();
		if ($screen->id !== 'post') { return; }

		// Show button to explicitly save as pending changes if user has sufficient permissions
		global $post;
		if (empty($post) || !current_user_can('publish_posts', $post->ID) || $post->post_status !== 'publish') { return; }

		$html = '<div class="fpc-publish-update-action">';
		$html .= '<input type="submit" name="fpc-publish-update" id="fpc-publish-update-submit" value="Publish update" class="button-primary fpc-publish-update-action__button">';
		$html .= '</div>';
		echo $html;
	}

	public function alterText($translation, $text) {
		if ($text == 'Update') {

			// Replace Update button text
			global $post;
			if (!$post) {
				return $translation;
			}
			return 'Save as pending changes';
		}
		return $translation;
	}

	public function prepareRevisionForJS($revisionsData, $revision, $post) {

		// Set accepted flag in the revision pointed by the post
		$acceptedID = get_post_meta($post->ID, '_fpc_accepted_revision_id', true) ?: $post->ID;
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

	public function preparePostForJS() {
		global $post;
		if (!$post) {
			return array();
		}

		// Data to pass to Post's Javascript
		$editingMode = get_post_meta($post->ID, '_fpc_editing_mode', true) ?: '';
		return array(
			'post' => $post,
			'editingMode' => $editingMode,
			'canUserPublishPosts' => current_user_can('publish_posts', $post->ID)
		);
	}

	public function enqueueScripts($hook_suffix) {
		if ($hook_suffix == 'post.php') {
			wp_enqueue_style('fpc-pending-changes-styles', plugin_dir_url(__FILE__) . 'css/pending-changes.css');
			wp_enqueue_script('fpc-pending-changes-post', plugin_dir_url(__FILE__) . 'js/pending-changes-post.js', array('jquery', 'revisions'));
			wp_localize_script('fpc-pending-changes-post', 'fpcData', $this->preparePostForJS());
		} else if ($hook_suffix == 'revision.php') {
			wp_enqueue_script('fpc-pending-changes-revisions', plugin_dir_url(__FILE__) . 'js/pending-changes-revisions.js', array('jquery', 'revisions'));
		}
	}
}

new Plugin();
