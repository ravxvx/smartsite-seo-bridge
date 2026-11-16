<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Admin dashboard: Tools > SmartSite SEO Bridge. */
final class SSB_Admin {
    const SLUG = 'smartsite-seo-bridge';

    public static function menu() {
        add_management_page('SmartSite SEO Bridge', 'SmartSite SEO Bridge', 'manage_options', self::SLUG, array(__CLASS__, 'render'));
    }

    public static function notices() {
        if (!current_user_can('manage_options') || defined('RANK_MATH_VERSION')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === 'tools_page_' . self::SLUG) {
            echo '<div class="notice notice-warning"><p><strong>SmartSite SEO Bridge:</strong> Rank Math is not active. Fields are stored but have no effect until Rank Math is installed and activated.</p></div>';
        }
    }

    public static function assets($hook) {
        if ($hook !== 'tools_page_' . self::SLUG) {
            return;
        }
        wp_enqueue_style('smartsite-seo-bridge-admin', plugins_url('assets/admin.css', SSB_FILE), array(), SSB_VERSION);
        wp_enqueue_script('smartsite-seo-bridge-admin', plugins_url('assets/admin.js', SSB_FILE), array(), SSB_VERSION, true);
        $types = array();
        foreach (get_post_types(array('public' => true), 'objects') as $slug => $obj) {
            if ($slug !== 'attachment') {
                $types[] = array('slug' => $slug, 'label' => $obj->labels->name);
            }
        }
        wp_localize_script('smartsite-seo-bridge-admin', 'SSBConfig', array(
            'restUrl' => esc_url_raw(rest_url(SSB_REST::NS . '/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'types' => $types,
            'groups' => array_keys(SSB_Settings::groups()),
            'target' => SSB_Audit::TARGET,
        ));
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap" id="ssb-wrap">
            <h1>SmartSite SEO Bridge <small>v<?php echo esc_html(SSB_VERSION); ?></small></h1>
            <p class="description">Rank Math control centre: audit, edit, history, global settings and redirects. The score shown here is an <strong>estimate</strong> of the Rank Math content tests; Rank Math itself recalculates its own score when a page is opened and saved in the editor.</p>
            <div id="ssb-app"><noscript>JavaScript is required for this page.</noscript></div>
        </div>
        <?php
    }
}
