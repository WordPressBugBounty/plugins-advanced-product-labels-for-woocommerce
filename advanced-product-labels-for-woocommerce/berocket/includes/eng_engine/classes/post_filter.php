<?php
namespace BeRocket\EngagementEngine;

use function BeRocket\utm;

class PostFilter extends PostBase {
    public string $post_name = 'PostFilter';
    public string $plugin_sku = 'filters';
	public string $plugin_name = 'ajax_filters';

	public function __construct( $o_locked_features ) {
		parent::__construct( $o_locked_features );
	}

	public function output_locked_features_widget_type_search_field( $widget_types ) {
		$locked_features = $this->get_locked_features_for_post();
		$i               = 1;
		foreach ( $locked_features as $locked_feature ) {
			if ( $this->is_locked( $locked_feature ) ) {
				if ( ! empty( $locked_feature['function'] ) and
                     $locked_feature['function'] == 'output_locked_features_widget_type_search_field' ) {
					$widget_types[ $locked_feature['license'] . '_' . $i ] = array(
						'value' => '',
						'name'  => sanitize_text_field( $locked_feature['label'] ?? '' ),
						'image' => esc_url_raw( $locked_feature['image'] ?? '' ),
					);
					$i ++;
				}
			}
		}

		return $widget_types;
	}

	public function output_locked_features_filter_by_field() {
		$locked_features = $this->get_locked_features_for_post();
		foreach ( $locked_features as $locked_feature ) {
			if ( ! empty( $locked_feature['function'] ) and
                 $locked_feature['function'] == 'output_locked_features_filter_by_field' and
                 $this->is_locked( $locked_feature )
            ) {
				echo "<option disabled='disabled'>" . esc_html( $locked_feature['label'] ?? '' ) . " (" .
				     esc_html( $locked_feature['license'] ?? '' ) . ")</option>";
			}
		}
	}

	public function general_feature() {
		$locked_features = $this->get_locked_features_for_post();
		if ( ! empty( $locked_features ) ) {
			foreach ( $locked_features as $feature ) {
				if ( ! empty( $feature['function'] ) and ! empty( $feature['hook'] ) and
                     $feature['function'] == 'general_feature' and
                     current_filter() == $feature['hook'] and
				     $this->is_locked( $feature )
                ) {
					$feature_name = esc_attr( $feature['name'] ?? '' );
					$license      = esc_attr( $feature['license'] ?? '' );
					$this->add_tooltip( $feature );
					echo '
					<div class="braapf_attribute_setup_flex locked_feature_tr">
						<div class="braapf_' . $feature_name . ' braapf_full_select_full">
							<a id="locked_feature_' . $feature_name . '" class="locked_feature_value" aria-expanded="false">
					            <label>' . esc_html( $feature['label'] ?? '' ) . '</label>
								<input type="checkbox" disabled="disabled" value="" />
					            <span class="dashicons dashicons-lock di_badge_' . $license . '"></span>
					            ' . esc_html( $feature['text'] ?? '' ) . '
					        </a>
						</div>
					</div>';
                    if ( ! empty( $feature['condition'] ) ) {
						$feature_name = esc_js( $feature['name'] ?? '' );
						$feature_condition = esc_js( $feature['condition'] );
                        ?>
                        <script>
                            //jQuery(document).on("brsbs_style", function() {
                                berocket_show_element('.braapf_<?=$feature_name?>', '<?=$feature_condition?>', true);
                            //});
                        </script>
                        <?php
                    }
				}
			}
		}
	}

	public function turn_on_filter_customization( $options ) {
		if ( ! $this->is_paid() or $this->is_premium() ) {
			$options['style']['has_advanced'] = true;
			add_action( 'braapf_advanced_single_filter_style', function ( $settings_name, $braapf_filter_setings ) {
				$data    = DataStore::get_current();
				if ( ! empty( $data['locked_features']['filters']['posts'][ $this->post_name ] ) ) {
					$locked_features = $data['locked_features']['filters']['posts'][ $this->post_name ];
					foreach ( $locked_features as $feature ) {
						if ( ! empty( $feature['function'] ) and $feature['function'] == 'turn_on_filter_customization' and
                             $this->is_locked( $feature )
                        ) {
							echo '<div class="style_customization" style="text-align: center; width: 100%;">
									<a href="' . esc_url( utm( ( $feature['link'] ?? '' ),[
										'medium' => 'custom_post',
										'campaign' => 'locked_feature',
										'content'  => ( $feature['name'] ?? '' ) . '_image'] ) ) . '" target="_blank" rel="noopener noreferrer">
										<img alt="Customization in Business version" style="max-width: 100%"
							     			 src="https://apicdn.berocket.com/plugin_assets/filters/post_filter/filter_post_business_styling_framed.png" />
							     	</a>';
							if ( ! empty( $feature['link'] ) ) {
								echo '<div class="br_framework_settings style_customization_buttons">';
								if ( $feature['demo'] ) {
									echo '<a href="' . esc_url( $feature['demo'] ) . '" class="get_premium_version" target="_blank" rel="noopener noreferrer" style="max-width: 300px;margin-right: 20px;">
											DEMO
										  </a>';
								}
								echo '<a href="' . esc_url( utm( $feature['link'],[
										 'medium' => 'custom_post',
										 'campaign' => 'locked_feature',
										 'content'  => ( $feature['name'] ?? '' ) . '_button'] ) ) . '"
										 target="_blank" rel="noopener noreferrer" class="buy_premium_version" style="max-width: 300px;">
										UPGRADE
									  </a>';
								echo '</div>';
							}
							echo '
								</div>
								<style>
									html #settings .berocket_sbs_step.brsbs_style a.turn_on_advanced:before {
										color: #b500f7;
									}
									html #settings .berocket_sbs_step.brsbs_style a.turn_on_advanced:after {
										background: linear-gradient(45deg, #b500f7 , #f22932);
										background: -webkit-linear-gradient(45deg, #b500f7 , #f22932);
										background-clip: border-box;
										-webkit-background-clip: text;
										-webkit-text-fill-color: transparent;
										font-weight: 600;
									}
								</style>';
						}
					}
				}
			}, 10, 2 );
		}

		return $options;
	}

	public function filter_settings_style_templates( $templates ) {
		$locked_features = $this->get_locked_features_for_post();
		if ( ! empty( $locked_features ) ) {
			foreach ( $locked_features as $feature ) {
				if ( ! empty( $feature['function'] ) and
                     $feature['function'] == 'filter_settings_style_templates'
                     and $this->is_locked( $feature )
                ) {
					$feature_name = esc_attr( $feature['name'] ?? '' );
					$template     = esc_attr( $feature['template'] ?? '' );
					$label        = esc_attr( $feature['label'] ?? '' );
					$image        = esc_url( $feature['image'] ?? '' );
					$license      = esc_html( $feature['license'] ?? '' );
					$link         = esc_url( utm( $feature['link'] ?? '', [
						'medium'   => 'custom_post',
						'campaign' => 'locked_feature',
						'content'  => $feature['name'] ?? '',
					] ) );

					$templates[ $feature['type'] ]['html'][ $feature['name'] ] = '
					<div class="braapf_style_' . $feature_name . '" data-slug="' . $feature_name . '"
						 data-template="' . $template . '" data-name="' . $label . '"
						 data-image="' . $image . '"
						 data-version="1.0" data-specific="elements" data-sort_pos="1" style="order: 210001;">
						<input id="braapf_style_' . $feature_name . '" type="radio" data-name="' . $label . '"
							   data-slug="' . $feature_name . '" data-template="' . $template . '" data-sort_pos="1"
							   data-image="' . $image . '" data-version="1.0" data-specific="elements">
						<label for="braapf_style_' . $feature_name . '">
							<img alt="' . $label . '" src="' . $image . '">
						    <h3>' . esc_html( $feature['label'] ?? '' ) . '</h3>
						</label>
						<section class="premium-only">
							<a target="_blank" rel="noopener noreferrer" href="' . $link . '">
								<span>
									<i class="fa fa-star" aria-hidden="true"></i>
									Go ' . $license .'
									<i class="fa fa-star" aria-hidden="true"></i>
								</span>
							</a>
						</section>
					</div>
					';
				}
			}
		}

		return $templates;
	}

	public function br_filter_settings_style_template_after( $template_slug ) {
		$locked_features = $this->get_locked_features_for_post();
		if ( ! empty( $locked_features ) ) {
			foreach ( $locked_features as $feature ) {
				if ( ! empty( $feature['function'] ) and
                     $feature['function'] == 'br_filter_settings_style_template_after' and
                     $this->is_locked( $feature )
                ) {
                    if ( ! empty( $feature['after'] ) and $template_slug == $feature['after'] ) {
                        $show_conditions = $feature['condition'] ?? '!braapf_current_template_styles! == "' . $feature['template'] . '"';
                        $feature_name = esc_attr( $feature['name'] ?? '' );
                        $template = esc_attr( $feature['template'] ?? '' );
                        $label = esc_attr( $feature['label'] ?? '' );
                        $image = esc_url( $feature['image'] ?? '' );
                        $link = esc_url( utm( $feature['link'] ?? '', [
                            'medium'   => 'custom_post',
                            'campaign' => 'locked_feature',
                            'content'  => $feature['name'] ?? '',
                        ] ) );
                        ?>
                        <div class="braapf_template_<?=$feature_name?>" style="order: 1009001;">
                            <h4><?=esc_html( $feature['label'] ?? '' )?></h4>
                            <div class="braapf_style">
                                <div class="braapf_style_<?=$feature_name?>" data-slug="<?=$feature_name?>" data-template="<?=$feature_name?>"
                                     data-name="<?=$label?>" data-image="<?=$image?>"
                                     data-version="1.0" data-specific="" data-sort_pos="1" style="order: 2009001;">
                                    <input id="braapf_style_<?=$feature_name?>" type="radio" name="br_product_filter[<?=$feature_name?>]"
                                           value="" data-slug="<?=$feature_name?>" data-template="<?=$feature_name?>" data-name="<?=$label?>"
                                           data-image="<?=$image?>" data-version="1.0" data-specific="" data-sort_pos="1">
                                    <label for="braapf_style_<?=$feature_name?>">
                                        <img alt="Slider" src="<?=$image?>">
                                        <h3><?=esc_html( $feature['label'] ?? '' )?></h3>
                                    </label>
                                    <section class="premium-only">
                                        <a target="_blank" rel="noopener noreferrer" href="<?=$link?>">
                                            <span>
                                                <i class="fa fa-star" aria-hidden="true"></i>
                                                Go <?=esc_html( $feature['license'] ?? '' )?>
                                                <i class="fa fa-star" aria-hidden="true"></i>
                                            </span>
                                        </a>
                                    </section>
                                </div>
                            </div>
                            <script>
                                /*jQuery(document).on("brsbs_style", function() {
                                    berocket_show_element('.braapf_<?=$feature_name?>', '<?=esc_js( $feature['condition'] ?? '' )?>', true, braapf_sort_styles);
                                });*/
                                berocket_show_element('.braapf_template_<?=$feature_name?>', '<?=esc_js( $show_conditions )?>', true);
                            </script>
                        </div>
                        <?php
                    }
				}
			}
		}
	}

	public function get_locked_features_for_post(): array {
		$data = DataStore::get_current();

		return $data['locked_features'][ $this->plugin_sku ]['posts'][ $this->post_name ] ?? [];
	}
}
