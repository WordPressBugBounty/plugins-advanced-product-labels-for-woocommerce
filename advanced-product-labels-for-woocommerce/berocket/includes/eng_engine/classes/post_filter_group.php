<?php
namespace BeRocket\EngagementEngine;

class PostFilterGroup extends PostBase {
	public string $post_name = 'PostFilterGroup';
	public string $plugin_sku = 'filters';
	public string $plugin_name = 'ajax_filters';

	public function __construct( $o_locked_features ) {
		parent::__construct( $o_locked_features );
	}
}