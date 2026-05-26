<?php
namespace BeRocket;

class utm {
	private array $options;

	public function __construct( $options ) {
		$default = [
			'source'   => 'plugin',
			'medium'   => 'banner',
			'campaign' => 'upgrade',
			'content'  => 'settings_top_banner',
			'term'     => 'filters',
		];

		$this->options = array_merge( $default, array_intersect_key( $options, $default ) );
	}

	public function build( $link ): string {
		$utm = [
			'utm_source'   => $this->options['source'],
			'utm_medium'   => $this->options['medium'],
			'utm_campaign' => $this->options['campaign'],
			'utm_content'  => $this->options['content'],
			'utm_term'     => $this->options['term'],
		];

		return add_query_arg( $utm, $link );
	}
}

/**
 * @return string
 *
 * utm_source	де користувач знаходиться   plugin, wp_admin, wordpress, email, youtube, blog, docs, etc
 * utm_medium	тип місця/як саме показано	banner, popup, email, organic, cpc, admin_notice, sidebar, etc
 * utm_campaign	Кампанія	                upgrade_prompt, spring_sale, black_friday, upgrade, locked_feature, etc
 * utm_content	Конкретний елемент	        top_banner, sidebar_cta, locked_feature_popup, header_cta_2, etc
 * utm_term	    Додатково (опц.)	        filters, labels, minmax, business_filters
 *
 * Example:
 * $new_link = utm('https://berocket.com/woocommerce-ajax-products-filter/', [
 * 'source'   => 'plugin',
 * 'medium'   => 'banner',
 * 'campaign' => 'upgrade',
 * 'content'  => 'settings_top_banner',
 * 'term'     => 'filters',
 * ]);
 */
function utm( $link, $options = [] ): string {
	if ( ! $link )
		return '';

	$utm = new utm( $options );
	return $utm->build( $link );
}
