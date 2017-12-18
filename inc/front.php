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
		add_filter('get_object_terms', array($this, 'filterAcceptedRevisionTaxonomies'), -1, 4);
		add_filter('get_post_metadata', array($this, 'filterAcceptedRevisionThumbnail'), -1, 4);
		add_filter('acf/format_value_for_api', array($this, 'filterAcceptedRevisionField'), -1, 3); // ACF v4
		add_filter('acf/format_value', array($this, 'filterAcceptedRevisionField'), -1, 3); // ACF v5+
	}

	// Replace content with post's accepted revision content
	public function filterAcceptedRevisionContent($content) {
		if (is_preview()) { return $content; }
		$postID = get_the_ID();
		if (empty($postID) || !in_array(get_post_type($postID), Base::instance()->getEnabledPostTypes())) { return $content; }

		// Preview specific revision
		if (isset($_GET['fpr-preview']) && $_GET['fpr-preview'] != $postID && is_numeric($_GET['fpr-preview']) && current_user_can('edit_posts', $postID)) {
			return get_post_field('post_content', $_GET['fpr-preview']);
		}

		// Accepted revision
		$acceptedID = get_post_meta($postID, '_fpr_accepted_revision_id', true);
		if (!$acceptedID || $acceptedID == $postID) { return $content; }
		return get_post_field('post_content', $acceptedID);
	}

	// Replace excerpt with post's accepted revision excerpt
	public function filterAcceptedRevisionExcerpt($excerpt, $postID) {
		if (is_preview()) { return $excerpt; }
		if (!in_array(get_post_type($postID), Base::instance()->getEnabledPostTypes())) { return $excerpt; }

		// Preview specific revision
		if (isset($_GET['fpr-preview']) && $_GET['fpr-preview'] != $postID && is_numeric($_GET['fpr-preview']) && current_user_can('edit_posts', $postID)) {
			return get_post_field('post_excerpt', $_GET['fpr-preview']);
		}

		// Accepted revision
		$acceptedID = get_post_meta($postID, '_fpr_accepted_revision_id', true);
		if (!$acceptedID || $acceptedID == $postID) { return $excerpt; }
		return get_post_field('post_excerpt', $acceptedID);
	}

	// Replace title with post's accepted revision title
	public function filterAcceptedRevisionTitle($title, $post) {
		if (is_preview()) { return $title; }
		$postID = is_object($post) ? $post->ID : $post;
		if (!in_array(get_post_type($postID), Base::instance()->getEnabledPostTypes())) { return $title; }

		// Preview specific revision
		if (isset($_GET['fpr-preview']) && $_GET['fpr-preview'] != $postID && is_numeric($_GET['fpr-preview']) && current_user_can('edit_posts', $postID)) {
			return get_post_field('post_title', $_GET['fpr-preview']);
		}

		// Accepted revision
		$acceptedID = get_post_meta($postID, '_fpr_accepted_revision_id', true);
		if (!$acceptedID || $acceptedID == $postID) { return $title; }
		return get_post_field('post_title', $acceptedID);
	}

	// Replace custom fields' data with post's accepted revision custom fields' data
	public function filterAcceptedRevisionField($value, $postID, $field) {
		if (is_preview()) { return $value; }
		if (!function_exists('get_field')) { return $value; }
		if (!in_array(get_post_type($postID), Base::instance()->getEnabledPostTypes()) || $field['name'] == 'accepted_revision_id') { return $value; }

		// Preview specific revision
		if (isset($_GET['fpr-preview']) && $_GET['fpr-preview'] != $postID && is_numeric($_GET['fpr-preview']) && current_user_can('edit_posts', $postID)) {
			return get_field($field['name'], $_GET['fpr-preview']);
		}

		// Accepted revision
		$acceptedID = get_post_meta($postID, '_fpr_accepted_revision_id', true);
		if (!$acceptedID || $acceptedID == $postID) { return $value; }
		return get_field($field['name'], $acceptedID);
	}

	// Replace post thumbnail with accepted revision's
	public function filterAcceptedRevisionThumbnail($value, $postID, $key, $single) {
		if (is_preview()) { return $value; }
		if (!is_numeric($postID) || !in_array(get_post_type($postID), Base::instance()->getEnabledPostTypes()) || $key != '_thumbnail_id') { return $value; }

		// Preview specific revision
		if (isset($_GET['fpr-preview']) && $_GET['fpr-preview'] != $postID && is_numeric($_GET['fpr-preview']) && current_user_can('edit_posts', $postID)) {
			return get_post_meta($_GET['fpr-preview'], '_thumbnail_id', $single);
		}

		// Accepted revision
		$acceptedID = get_post_meta($postID, '_fpr_accepted_revision_id', true);
		if (!$acceptedID || $acceptedID == $postID) { return $value; }
		return get_post_meta($acceptedID, '_thumbnail_id', $single);
	}

	// Replace taxonomy term data with post's accepted revision terms
	public function filterAcceptedRevisionTaxonomies($terms, $objectIDs, $taxonomies, $args) {
		if (is_preview()) { return $terms; }
		if (!is_array($objectIDs)) { $objectIDs = array($objectIDs); }

		// Replace posts' terms with those of their accepted revisions
		$acceptedRevisions = array();
		$previewTerms = array();
		$previewPost = false;
		if (isset($_GET['fpr-preview']) && is_numeric($_GET['fpr-preview'])) {
			$previewPost = wp_get_post_parent_id($_GET['fpr-preview']);
		}
		foreach ($objectIDs as $objectID) {
			if (empty($objectID)) { continue; }
			if (!in_array(get_post_type($objectID), Base::instance()->getEnabledPostTypes())) { continue; }

			// Preview specific revision
			if ($previewPost && $_GET['fpr-preview'] != $objectID && $previewPost == $objectID && current_user_can('edit_posts', $objectID)) {
				$previewTerms = wp_get_object_terms($_GET['fpr-preview'], $taxonomies, $args);
				continue;
			}

			// Accepted revision
			$acceptedID = get_post_meta($objectID, '_fpr_accepted_revision_id', true);
			if (!$acceptedID || $acceptedID == $objectID) { continue; }
			$acceptedRevisions[$acceptedID] = $objectID;
		}
		if (empty($acceptedRevisions) && empty($previewTerms)) { return $terms; } // No revisions to fetch terms from

		// Remove posts' own terms from results
		$resultTerms = array();
		$acceptedPosts = array_values($acceptedRevisions);
		$termsHaveObjectId = (isset($args['fields']) && $args['fields'] == 'all_with_object_id');
		if ($termsHaveObjectId) {

			// Use the terms' object ID to identify which need to be replaced
			foreach ($terms as $term) {
				if ($term->object_id == $previewPost) { continue; }
				if (in_array($term->object_id, $acceptedPosts)) { continue; }
				$resultTerms []= $term;
			}
		}

		// Get terms for the accepted revisions and update their `object_id` reference
		$revisionsTerms = wp_get_object_terms(array_keys($acceptedRevisions), $taxonomies, $args);
		$revisionsTerms = array_merge($previewTerms, $revisionsTerms); // Merge with terms from preview
		if ($termsHaveObjectId) {
			foreach ($revisionsTerms as $term) {

				// Replace fetched term's object ID from preview/accepted revision's to post's
				if ($previewPost && $term->object_id == $_GET['fpr-preview']) {
					$term->object_id = $previewPost;
				} else if (isset($acceptedRevisions[$term->object_id])) {
					$term->object_id = $acceptedRevisions[$term->object_id];
				}
			}
		} else {

			// Since terms for objects with no accepted revision couldn't be told apart get them separately
			$resultIDs = array_diff($objectIDs, array($previewPost), $acceptedPosts);
			if (!empty($resultIDs)) {
				$resultTerms = wp_get_object_terms($resultIDs, $taxonomies, $args);
			}
		}

		// Merge untouched and updated terms
		return array_merge($resultTerms, $revisionsTerms);
	}
}

Front::instance()->init();
