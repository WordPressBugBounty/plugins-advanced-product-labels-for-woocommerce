<?php
class BeRocket_import_export {
    public function __construct() {
        add_action('BeRocket_framework_updater_account_form_after', array($this, 'account_form'), 10, 1);
        add_action('wp_ajax_brfr_get_export_settings', array($this, 'get_export') );
        add_action('wp_ajax_brfr_set_import_settings', array($this, 'set_import') );
        add_action('wp_ajax_brfr_get_import_backups', array($this, 'get_backups') );
        add_action('wp_ajax_brfr_restore_import_backups', array($this, 'restore_backups') );
    }
    public static function get_export() {
        $nonce = $_GET['nonce'];
        $result = wp_verify_nonce( $nonce, 'brfr_import_export' );
        if( ! $result || ! current_user_can( 'manage_options' ) ) {
            wp_die( -1, 403 );
        }
        $plugin_slug = sanitize_text_field($_GET['plugin']);
        $plugin_instance = apply_filters('brfr_plugin_get_instance_' . $plugin_slug, FALSE);
        $export = array();
        if( $plugin_instance !== FALSE && $plugin_instance->import_export !== FALSE ) {
            $export = self::export_generate($plugin_slug);
        }
        echo json_encode($export);
        wp_die();
    }
    public static function set_import() {
        $nonce = $_POST['nonce'];
        $result = wp_verify_nonce( $nonce, 'brfr_import_export' );
        if( ! $result || ! current_user_can( 'manage_options' ) ) {
            wp_die( -1, 403 );
        }
        $plugin_slug = sanitize_text_field($_POST['plugin']);
        $data = stripslashes($_POST['data']);
        if( empty($plugin_slug) || empty($data) ) {
            _e('Empty data', 'BeRocket_domain');
            wp_die();
        }
        $data = json_decode($data, true);
        if( empty($data) ) {
            _e('Incorrect data', 'BeRocket_domain');
            wp_die();
        }
        $plugin_instance = apply_filters('brfr_plugin_get_instance_' . $plugin_slug, FALSE);
        $import = array();
        if( $plugin_instance !== FALSE && $plugin_instance->import_export !== FALSE ) {
            $import = self::import_generate($plugin_slug, $data);
            if( ! empty($import) ) {
                $old_option = $plugin_instance->get_option();
                $save_id_use = '1';
                $save_id_time = false;
                foreach(array('1', '2', '3') as $save_id) {
                    $transient_option = get_transient('brfr_bckp_' . $plugin_slug . '_' . $save_id);
                    if( $transient_option == false ) {
                        $save_id_use = $save_id;
                        break;
                    } elseif( $save_id_time === false || $transient_option['import_export_date'] < $save_id_time ) {
                        $save_id_use = $save_id;
                        $save_id_time = $transient_option['import_export_date'];
                    }
                }
                $old_option['import_export_date'] = time();
                set_transient( 'brfr_bckp_' . $plugin_slug . '_' . $save_id_use, $old_option, DAY_IN_SECONDS );
                $import = $plugin_instance->save_settings_callback( $import );
                update_option($plugin_instance->values['settings_name'], $import);
                _e('Imported', 'BeRocket_domain');
            } else {
                _e('This data cannot be used for import', 'BeRocket_domain');
            }
        } else {
            _e('Import for this plugin not allowed', 'BeRocket_domain');
        }
        wp_die();
    }
    public static function get_backups() {
        $nonce = $_GET['nonce'];
        $result = wp_verify_nonce( $nonce, 'brfr_import_export' );
        if( ! $result || ! current_user_can( 'manage_options' ) ) {
            wp_die( -1, 403 );
        }
        $plugin_slug = sanitize_text_field($_GET['plugin']);
        $exist_ids = array();
        foreach(array('1', '2', '3') as $save_id) {
            $transient_option = get_transient('brfr_bckp_' . $plugin_slug . '_' . $save_id);
            if( $transient_option != false && is_array($transient_option) && ! empty($transient_option['import_export_date']) ) {
                $exist_ids[] = array(
                    'id' => $save_id,
                    'time' => $transient_option['import_export_date']
                );
            }
        }
        if( count($exist_ids) > 0 ) {
            echo '<select name="backup">';
            echo '<option value="0">' . __('-= Select backup to restore =-', 'BeRocket_domain') . '</option>';
            foreach($exist_ids as $exist_id) {
                echo '<option value="'.$exist_id['id'].'">' . date('Y-m-d H:i:s', $exist_id['time']) . '</option>';
            }
            echo '</select>';
        }
        wp_die();
    }
    public static function restore_backups() {
        $nonce = $_GET['nonce'];
        $result = wp_verify_nonce( $nonce, 'brfr_import_export' );
        if( ! $result || ! current_user_can( 'manage_options' ) ) {
            wp_die( -1, 403 );
        }
        $plugin_slug = sanitize_text_field($_GET['plugin']);
        $backup_id = intval($_GET['backup']);
        if( empty($backup_id) || empty($plugin_slug) ) {
            echo 'Incorect data';
            wp_die();
        }
        $exist_ids = array();
        $transient_option = get_transient('brfr_bckp_' . $plugin_slug . '_' . $backup_id);
        $plugin_instance = apply_filters('brfr_plugin_get_instance_' . $plugin_slug, FALSE);
        if( $plugin_instance !== FALSE && $plugin_instance->import_export !== FALSE
            && $transient_option != false && is_array($transient_option) && ! empty($transient_option['import_export_date']) ) {
            $import = $plugin_instance->save_settings_callback( $transient_option );
            update_option($plugin_instance->values['settings_name'], $import);
            echo 'OK';
        } else {
            echo 'Backup cannot be used for plugin: ' . $plugin_slug . ' ID: ' . $backup_id;
        }
        wp_die();
    }
    public static function account_form($plugin_info) {
        $nonce = wp_create_nonce('brfr_import_export');
        ?><div><span class="brfr_import_export_open button"><?php _e('Import/Export', 'BeRocket_domain') ?></span></div>
    <div class="brfr_import_export_block" style="display: none;">
        <form class="brfr_import_export_form">
            <h3><?php _e('Import/Export', 'BeRocket_domain') ?></h3>
            <input name="action" type="hidden" value="brfr_set_import_settings">
            <input name="nonce" type="hidden" value="<?php echo $nonce; ?>">
            <select name="plugin">
                <?php
                if( is_array($plugin_info) ) {
                    foreach($plugin_info as $plugin_info_single) {
                        $plugin_instance = apply_filters('brfr_plugin_get_instance_' . $plugin_info_single['plugin_name'], FALSE);
                        if( $plugin_instance !== FALSE && $plugin_instance->import_export !== FALSE ) {
                            echo "<option value='{$plugin_info_single['plugin_name']}'>{$plugin_info_single['norm_name']}</option>";
                        }
                    }
                }
                ?>
            </select>
            <h3><?php _e('EXPORT', 'BeRocket_domain') ?></h3>
            <div class="brfr_export_wrap">
                <div class="brapf_export_loading" style="display:none;"><i class="fa fa-spinner fa-spin"></i></div>
                <textarea class="brfr_export" readonly></textarea>
            </div>
            <h3><?php _e('IMPORT', 'BeRocket_domain') ?></h3>
            <div class="brfr_import_wrap">
                <div class="brapf_import_loading" style="display:none;"><i class="fa fa-spinner fa-spin"></i></div>
                <textarea name="data" class="brfr_import"></textarea>
            </div>
            <button class="button brfr_import_send"><?php _e('Import', 'BeRocket_domain') ?></button>
        </form>
        <form class="brfr_backup_form" style="display: none;">
            <input name="action" type="hidden" value="brfr_restore_import_backups">
            <input name="nonce" type="hidden" value="<?php echo $nonce; ?>">
            <h3></h3>
            <div class="brfr_backup_form_select"></div>
            <button class="button brfr_backup_form_send"><?php _e('Restore', 'BeRocket_domain') ?></button>
            <i class="fa fa-spinner fa-spin" style="display:none;"></i>
            <i class="fa fa-check" style="display:none;"></i>
        </form>
    </div>
<script>
jQuery(document).on('click', '.brfr_import_export_form .brfr_export', function() {
    const textarea = jQuery(this)[0];
    textarea.focus();
    textarea.select();
    textarea.setSelectionRange(0, textarea.value.length);
});
jQuery(document).on('change', '.brfr_import_export_form select', function() {
    brfr_get_export_data();
    get_import_backup();
});
jQuery(document).on('click', '.brfr_import_export_open', function() {
    jQuery('.brfr_import_export_block').show();
    jQuery(this).parent().hide();
    brfr_get_export_data();
    get_import_backup();
});
function brfr_get_export_data() {
    var pluginSlug = jQuery('.brfr_import_export_block select').val();
    jQuery('.brapf_export_loading').show();
    jQuery.get(ajaxurl, {action:'brfr_get_export_settings', plugin:pluginSlug, nonce:'<?php echo $nonce; ?>'}, function(data) {
        jQuery('.brfr_import_export_block .brfr_export').text(data);
        jQuery('.brapf_export_loading').hide();
    });
}
function get_import_backup() {
    var pluginSlug = jQuery('.brfr_import_export_block select').val();
    jQuery('.brfr_backup_form').hide();
    jQuery('.brfr_backup_form .brfr_backup_form_select select').remove();
    jQuery.get(ajaxurl, {action:'brfr_get_import_backups', plugin:pluginSlug, nonce:'<?php echo $nonce; ?>'}, function(data) {
        if( data ) {
            jQuery('.brfr_backup_form .brfr_backup_form_select').append(jQuery(data));
            jQuery('.brfr_backup_form').show();
        }
    });
}
function brfr_get_import_data() {
    var data = jQuery('.brfr_import_export_form').serialize();
    jQuery('.brapf_import_loading').show();
    jQuery('.brfr_import_export_form .brfr_import').prop('disabled', true);
    jQuery('.brfr_import_export_form .brfr_import_send').prop('disabled', true);
    jQuery.post(ajaxurl, data, function(data) {
        jQuery('.brfr_import_export_form .brfr_import').text(data);
        jQuery('.brfr_import_export_form .brfr_import').val(data);
        jQuery('.brapf_import_loading').hide();
        brfr_get_export_data();
        get_import_backup();
        setTimeout(brfr_get_import_data_end, 5000);
    });
}
function brfr_get_import_data_end() {
    jQuery('.brfr_import_export_form .brfr_import').text('');
    jQuery('.brfr_import_export_form .brfr_import').val('');
    jQuery('.brfr_import_export_form .brfr_import').prop('disabled', false);
    jQuery('.brfr_import_export_form .brfr_import_send').prop('disabled', false);
}
function brfr_restore_backup() {
    var pluginSlug = jQuery('.brfr_import_export_block select').val();
    var data = 'plugin='+pluginSlug+'&'+jQuery('.brfr_backup_form').serialize();
    jQuery('.brfr_backup_form .fa-spin').show();
    jQuery('.brfr_backup_form .brfr_backup_form_send').prop('disabled', true);
    jQuery('.brfr_backup_form .brfr_backup_form_select select').prop('disabled', true);
    jQuery.get(ajaxurl, data, function(data) {
        if( data == 'OK' ) {
            jQuery('.brfr_backup_form .fa-check').show();
        } else {
            var html = '<span class="brfr_backup_form_error">' + data + '</span>';
            jQuery('.brfr_backup_form_send').after(jQuery(html));
        }
        jQuery('.brfr_backup_form .fa-spin').hide();
        brfr_get_export_data();
        setTimeout(brfr_restore_backup_end, 5000);
    });
}
function brfr_restore_backup_end() {
    jQuery('.brfr_backup_form_send .brfr_backup_form_error').remove();
    jQuery('.brfr_backup_form .fa-check').hide();
    jQuery('.brfr_backup_form .brfr_backup_form_send').prop('disabled', false);
    jQuery('.brfr_backup_form .brfr_backup_form_select select').prop('disabled', false);
}
jQuery(document).on('submit', '.brfr_import_export_form', function(event) {
    event.preventDefault();
    if( ! jQuery('.brfr_import_export_form .brfr_import').is('disabled') ) {
        brfr_get_import_data();
    }
});
jQuery(document).on('submit', '.brfr_backup_form', function(event) {
    event.preventDefault();
    if( ! jQuery('.brfr_backup_form .brfr_backup_form_send').is('disabled') ) {
        brfr_restore_backup();
    }
});
</script>
<style>
.brfr_import_export_form textarea {
    width: 100%;
    min-height: 60px;
}
.brfr_import_wrap,
.brfr_export_wrap {
    position: relative;
}
.brapf_import_loading,
.brapf_export_loading {
    position: absolute;
    background: rgba(240,240,241,0.5);
    top: 0;
    bottom: 0;
    left: 0;
    right: 0;
}
.brapf_import_loading .fa-spin,
.brapf_export_loading .fa-spin {
    position: absolute;
    top: 10px;
    left: 10px;
    font-size: 40px;
}
</style><?php
    }
    public static function export_generate($plugin_slug) {
        $plugin_slug = sanitize_text_field($plugin_slug);
        $plugin_instance = apply_filters('brfr_plugin_get_instance_' . $plugin_slug, FALSE);
        if( $plugin_instance != FALSE && $plugin_instance->import_export !== FALSE ) {
            $current_option = $plugin_instance->get_option();
            $default_option = $plugin_instance->defaults;
            $export = self::check_settings_remove($current_option, $default_option);
            $export = self::type_check_export($export, $plugin_instance->import_export);
            return $export;
        }
        return array();
    }
    public static function import_generate($plugin_slug, $options = array()) {
        $plugin_slug = sanitize_text_field($plugin_slug);
        $plugin_instance = apply_filters('brfr_plugin_get_instance_' . $plugin_slug, FALSE);
        if( $plugin_instance != FALSE && $plugin_instance->import_export !== FALSE ) {
            $default_option = $plugin_instance->defaults;
            $import = self::check_settings_create($options, $default_option);
            $import = self::type_check_import($import, $plugin_instance->import_export);
            return $import;
        }
        return array();
    }
    public static function check_settings_create($option, $default) {
        if( is_array($option) && is_array($default) ) {
            foreach( $option as $key => $value ) {
                $key = sanitize_text_field($key);
                if( isset( $default[$key] ) && is_array($value) && is_array($default[$key]) ) {
                    $default[$key] = self::check_settings_create($value, $default[$key]);
                } elseif( is_array($value) ) {
                    $default[$key] = self::check_settings_create($value, array());
                } else {
                    $default[$key] = sanitize_textarea_field($value);
                }
            }
        } else {
            $default = $option;
        }
        return $default;
    }
    public static function check_settings_remove($option, $default) {
        $new_option = array();
        if( is_array($option) && is_array($default) ) {
            foreach( $option as $key => $value ) {
                if( isset( $default[$key] ) ) {
                    if( is_array($value) && is_array($default[$key]) ) {
                        $new_value = self::check_settings_remove($value, $default[$key]);
                        if( is_array($new_value) && count($new_value) > 0 ) {
                            $new_option[$key] = $new_value;
                        }
                    } elseif( $value !== $default[$key] ) {
                        $new_option[$key] = $value;
                    }
                } else {
                    $new_option[$key] = $value;
                }
            }
        } else {
            $new_option = $option;
        }
        return $new_option;
    }
    
    public static function type_check_export($options, $types = array()) {
        foreach($types as $key => $type) {
            if( is_array($type) && isset($options[$key]) ) {
                if( isset($type['export_type']) ) {
                    switch($type['export_type']) {
                        case 'remove':
                            unset($options[$key]);
                            break;
                        case 'post':
                        case 'taxonomy':
                            $options[$key] = self::id_to_slug($options[$key], $type['export_type'], $type);
                            break;
                    }
                } else {
                    $options[$key] = self::type_check_export($options[$key], $type);
                }
            }
        }
        return $options;
    }
    public static function type_check_import($options, $types = array()) {
        foreach($types as $key => $type) {
            if( is_array($type) && isset($options[$key]) ) {
                if( isset($type['export_type']) ) {
                    switch($type['export_type']) {
                        case 'post':
                        case 'taxonomy':
                            $options[$key] = self::slug_to_id($options[$key], $type['export_type'], $type);
                            break;
                    }
                } else {
                    $options[$key] = self::type_check_import($options[$key], $type);
                }
            }
        }
        return $options;
    }
    public static function id_to_slug($id, $type, $additional = false) {
        $result = false;
        switch($type) {
            case 'post':
                $post = get_post($id);
                if( ! empty($post) ) {
                    $result = array('s' => $post->post_name);
                }
                break;
            case 'taxonomy':
                $field = 'term_id';
                if( isset($additional['field']) ) {
                    $field = $additional['field'];
                }
                $taxonomy = 'product_cat';
                if( isset($additional['taxonomy']) ) {
                    $taxonomy = $additional['taxonomy'];
                }
                $term = get_term_by($field, $id, $taxonomy);
                if( ! empty($term) ) {
                    $result = array('s' => $term->slug, 'tx' => $taxonomy);
                }
                break;
            default:
                break;
        }
        return $result;
    }
    public static function slug_to_id($data, $type, $additional = false) {
        $result = false;
        if( ! is_array($data) ) {
            return $result;
        }
        switch($type) {
            case 'post':
                if( ! empty($data['s']) ) {
                    $post_id = self::get_post_by_slug($data['s']);
                    if( ! empty($post_id) ) {
                        $result = $post_id;
                    }
                }
                break;
            case 'taxonomy':
                if( ! empty($data['s']) && ! empty($data['tx']) ) {
                    $taxonomy = 'product_cat';
                    if( isset($additional['taxonomy']) ) {
                        $taxonomy = $additional['taxonomy'];
                    }
                    $term = get_term_by('slug', $data['s'], $taxonomy);
                    if( ! empty($post_id) ) {
                        $field = 'term_id';
                        if( isset($additional['field']) ) {
                            $field = $additional['field'];
                        }
                        $result = $term->$field;
                    }
                }
                break;
        }
        return $result;
    }
    public static function get_post_by_slug($slug) {
        $args = array(
            'name'           => $slug,
            'posts_per_page' => 1,
        );
        $posts = get_posts($args);

        if ($posts) {
            return $posts[0];
        } else {
            return false;
        }
    }
}
new BeRocket_import_export();