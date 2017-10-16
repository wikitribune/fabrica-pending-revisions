<?php

namespace Fabrica\PendingRevisions;

if (!defined('WPINC')) { die(); }

require_once('singleton.php');
require_once('base.php');

class Front extends Singleton {
	public function init() {
		add_filter('the_content', array($this, 'filterAcceptedRevisionContent'), -1);
		add_filter('the_excerpt', array($this, 'filterAcceptedRevisionExcerpt'), -1, 2);
		add_filter('the_title', array($this, 'filterAcceptedRevisionTitle'), -1, 2);
		add_filter('single_post_title', array($this, 'filterAcceptedRevisionTitle'), -1, 2);
		add_filter('acf/format_value_for_api', array($this, 'filterAcceptedRevisionField'), -1, 3); // ACF v4
		add_filter('acf/format_value', array($this, 'filterAcceptedRevisionField'), -1, 3); // ACF v5+
	}

	// Replace content with post's accepted revision content
	public function filterAcceptedRevisionContent($content) {
		if (is_preview()) { return $content; }
		$postID = get_the_ID();
		if (empty($postID) || !in_array(get_post_type($postID), Base::instance()->getEnabledPostTypes())) { return $content; }
		$acceptedID = get_post_meta($postID, '_fpr_accepted_revision_id', true);
		if (!$acceptedID) { return $content; }

		return get_post_field('post_content', $acceptedID);
	}

	// Replace excerpt with post's accepted revision excerpt
	public function filterAcceptedRevisionExcerpt($excerpt, $postID) {
		if (is_preview()) { return $excerpt; }
		if (!in_array(get_post_type($postID), Base::instance()->getEnabledPostTypes())) { return $excerpt; }
		$acceptedID = get_post_meta($postID, '_fpr_accepted_revision_id', true);
		if (!$acceptedID) { return $excerpt; }

		return get_post_field('post_excerpt', $acceptedID);
	}

	// Replace title with post's accepted revision title
	public function filterAcceptedRevisionTitle($title, $post) {
		if (is_preview()) { return $title; }
		$postID = is_object($post) ? $post->ID : $post;
		if (!in_array(get_post_type($postID), Base::instance()->getEnabledPostTypes())) { return $title; }
		$acceptedID = get_post_meta($postID, '_fpr_accepted_revision_id', true);
		if (!$acceptedID) { return $title; }

		return get_post_field('post_title', $acceptedID);
	}

	// Replace custom fields' data with post's accepted revision custom fields' data
	public function filterAcceptedRevisionField($value, $postID, $field) {
		if (is_preview()) { return $value; }
		if (!function_exists('get_field')) { return $value; }
		if (!in_array(get_post_type($postID), Base::instance()->getEnabledPostTypes()) || $field['name'] == 'accepted_revision_id') { return $value; }
		$acceptedID = get_post_meta($postID, '_fpr_accepted_revision_id', true);
		if (!$acceptedID) { return $value; }

		return get_field($field['name'], $acceptedID);
	}
}

Front::instance()->init();
