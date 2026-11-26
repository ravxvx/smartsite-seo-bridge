<?php
/**
 * Plugin Name: SmartSite SEO Bridge
 * Description: Full control of Rank Math SEO for administrators and connected tools: REST meta fields, SEO audit with estimated score, schema, redirects, change history with undo, backed-up settings changes and an admin dashboard.
 * Version: 1.10.3
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: SmartSite
 * License: GPL-2.0-or-later
 * Text Domain: smartsite-seo-bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SSB_VERSION', '1.10.3');
define('SSB_FILE', __FILE__);
define('SSB_DIR', plugin_dir_path(__FILE__));

require_once SSB_DIR . 'includes/class-ssb-fields.php';
require_once SSB_DIR . 'includes/class-ssb-schema.php';
require_once SSB_DIR . 'includes/class-ssb-audit.php';
require_once SSB_DIR . 'includes/class-ssb-settings.php';
require_once SSB_DIR . 'includes/class-ssb-redirects.php';
require_once SSB_DIR . 'includes/class-ssb-snippets.php';
require_once SSB_DIR . 'includes/class-ssb-diagnostics.php';
require_once SSB_DIR . 'includes/class-ssb-images.php';
require_once SSB_DIR . 'includes/class-ssb-sitetools.php';
require_once SSB_DIR . 'includes/class-ssb-rest.php';
require_once SSB_DIR . 'includes/class-ssb-abilities.php';
require_once SSB_DIR . 'includes/class-ssb-admin.php';

final class SmartSite_SEO_Bridge {
    const DB_OPTION = 'smartsite_seo_bridge_version';

    public static function init() {
        add_action('init', array('SSB_Fields', 'register_meta'), 20);
        add_action('rest_api_init', array('SSB_REST', 'register_routes'));
        add_action('wp_abilities_api_categories_init', array('SSB_Abilities', 'register_category'));
        add_action('wp_abilities_api_init', array('SSB_Abilities', 'register'));
        add_action('admin_menu', array('SSB_Admin', 'menu'));
        add_action('admin_enqueue_scripts', array('SSB_Admin', 'assets'));
        add_action('admin_notices', array('SSB_Admin', 'notices'));
        add_action('admin_init', array(__CLASS__, 'maybe_migrate'));
        register_activation_hook(SSB_FILE, array(__CLASS__, 'activate'));
    }

    public static function activate() {
        self::migrate();
    }

    public static function maybe_migrate() {
        if (current_user_can('manage_options') && get_option(self::DB_OPTION) !== SSB_VERSION) {
            self::migrate();
        }
    }

    /** Copies values saved by version 1.1.0 under the wrong keys (_rank_math_*) to the real Rank Math keys. */
    private static function migrate() {
        global $wpdb;
        $moved = 0;
        foreach (SSB_Fields::defs() as $def) {
            $old = '_' . $def['key'];
            $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", $old));
            foreach ((array) $rows as $r) {
                if ($r->meta_value !== '' && get_post_meta($r->post_id, $def['key'], true) === '') {
                    update_post_meta($r->post_id, $def['key'], wp_slash($r->meta_value));
                    $moved++;
                }
            }
        }
        update_option(self::DB_OPTION, SSB_VERSION);
        if ($moved) {
            update_option('smartsite_seo_bridge_migrated', (int) get_option('smartsite_seo_bridge_migrated', 0) + $moved, false);
        }
    }
}

SmartSite_SEO_Bridge::init();
