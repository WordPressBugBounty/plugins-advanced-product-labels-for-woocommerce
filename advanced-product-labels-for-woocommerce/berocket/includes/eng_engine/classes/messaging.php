<?php
namespace BeRocket\EngagementEngine;

class Messaging {
	protected function is_settings() {
		return apply_filters('is_berocket_settings_page', false);
	}

	protected function is_post_page( $post_name ) {
		global $pagenow;
		$post_type = $_GET['post_type'] ?? '';
		$post_id   = $_GET['post'] ?? 0;
		if ( $pagenow === 'edit.php' && $post_type == $post_name ) {
			return true;
		}

		if ( $pagenow === 'post-new.php' && $post_type == $post_name ) {
			return true;
		}

		if ( $pagenow === 'post.php' && $post_id ) {
			if ( get_post_type( $post_id ) == $post_name ) {
				return true;
			}
		}

		return false;
	}

	private function ab_test() {
	}

	private function enable() {
	}

	private function disable() {
	}
}
