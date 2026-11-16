<?php
if (!defined('ABSPATH')) {
    exit;
}

/** REST routes under /wp-json/smartsite-seo/v1/ (administrators only). */
final class SSB_REST {
    const NS = 'smartsite-seo/v1';

    public static function admin_only() {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return new WP_Error('smartsite_seo_forbidden', 'Administrator access is required.', array('status' => 403));
        }
        return true;
    }

    public static function item_permissions($request) {
        $post = SSB_Fields::allowed_post(absint($request['id']));
        return is_wp_error($post) ? $post : true;
    }

    private static function out($res) {
        return is_wp_error($res) ? $res : rest_ensure_response($res);
    }

    public static function register_routes() {
        $admin = array(__CLASS__, 'admin_only');
        $item = array(__CLASS__, 'item_permissions');

        register_rest_route(self::NS, '/pages/(?P<id>\d+)', array(
            array('methods' => WP_REST_Server::READABLE, 'permission_callback' => $item, 'callback' => function ($r) {
                return self::out(SSB_Fields::item(absint($r['id']), !empty($r['audit'])));
            }),
            array('methods' => WP_REST_Server::EDITABLE, 'permission_callback' => $item, 'callback' => function ($r) {
                return self::out(SSB_Fields::update_item(absint($r['id']), $r->get_json_params()));
            }),
        ));
        register_rest_route(self::NS, '/pages/(?P<id>\d+)/audit', array(
            'methods' => WP_REST_Server::READABLE, 'permission_callback' => $item, 'callback' => function ($r) {
                return self::out(SSB_Audit::run(absint($r['id'])));
            },
        ));
        register_rest_route(self::NS, '/items', array(
            'methods' => WP_REST_Server::READABLE, 'permission_callback' => $admin, 'callback' => function ($r) {
                return self::out(SSB_Fields::list_items($r->get_params()));
            },
        ));
        register_rest_route(self::NS, '/terms/(?P<taxonomy>[a-z0-9_\-]+)/(?P<id>\d+)', array(
            array('methods' => WP_REST_Server::READABLE, 'permission_callback' => $admin, 'callback' => function ($r) {
                return self::out(SSB_Fields::term_item(sanitize_key($r['taxonomy']), absint($r['id'])));
            }),
            array('methods' => WP_REST_Server::EDITABLE, 'permission_callback' => $admin, 'callback' => function ($r) {
                return self::out(SSB_Fields::update_term(sanitize_key($r['taxonomy']), absint($r['id']), $r->get_json_params()));
            }),
        ));
        register_rest_route(self::NS, '/snippets', array(
            array('methods' => WP_REST_Server::READABLE, 'permission_callback' => $admin, 'callback' => function () {
                return self::out(array('site' => home_url(), 'items' => SSB_Snippets::describe(SSB_Snippets::library())));
            }),
            // Apply or remove: {"dry_run": true} uses the saved library; "items" applies a one-off list instead.
            array('methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $admin, 'callback' => function ($r) {
                $p = $r->get_json_params();
                $p = is_array($p) ? $p : array();
                $items = array_key_exists('items', $p) ? $p['items'] : null;
                if (!empty($p['remove'])) {
                    return self::out(SSB_Snippets::remove($items));
                }
                return self::out(SSB_Snippets::apply($items, !empty($p['dry_run'])));
            }),
        ));
        // Build SEO intros from this site's own Rank Math focus keywords and descriptions. {"dry_run": true} only reports.
        register_rest_route(self::NS, '/snippets/generate', array(
            'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $admin, 'callback' => function ($r) {
                $p = $r->get_json_params();
                return self::out(SSB_Snippets::generate(is_array($p) && !empty($p['dry_run'])));
            },
        ));
        // Save this site's snippet library: a list of items, or {"site": "example.com", "items": [...]}. [] clears it.
        register_rest_route(self::NS, '/snippets/library', array(
            'methods' => WP_REST_Server::EDITABLE, 'permission_callback' => $admin, 'callback' => function ($r) {
                $p = $r->get_json_params();
                if (is_array($p) && isset($p['library'])) {
                    $p = $p['library'];
                }
                return self::out(SSB_Snippets::save($p === null ? array() : $p));
            },
        ));
        register_rest_route(self::NS, '/history', array(
            'methods' => WP_REST_Server::READABLE, 'permission_callback' => $admin, 'callback' => function ($r) {
                return self::out(array('entries' => SSB_History::summarise($r['limit'] ? (int) $r['limit'] : 50)));
            },
        ));
        register_rest_route(self::NS, '/history/(?P<entry>[A-Za-z0-9\-]+)/undo', array(
            'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $admin, 'callback' => function ($r) {
                return self::out(SSB_History::undo((string) $r['entry']));
            },
        ));
        register_rest_route(self::NS, '/settings/(?P<group>[a-z\-]+)', array(
            array('methods' => WP_REST_Server::READABLE, 'permission_callback' => $admin, 'callback' => function ($r) {
                return self::out(SSB_Settings::get((string) $r['group']));
            }),
            array('methods' => WP_REST_Server::EDITABLE, 'permission_callback' => $admin, 'callback' => function ($r) {
                $b = $r->get_json_params();
                $b = is_array($b) ? $b : array();
                return self::out(SSB_Settings::update((string) $r['group'], isset($b['values']) ? $b['values'] : null, !empty($b['dry_run'])));
            }),
        ));
        register_rest_route(self::NS, '/settings-backups', array(
            'methods' => WP_REST_Server::READABLE, 'permission_callback' => $admin, 'callback' => function () {
                return self::out(array('backups' => SSB_Settings::backups()));
            },
        ));
        register_rest_route(self::NS, '/settings-backups/(?P<backup>[A-Za-z0-9\-]+)/restore', array(
            'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $admin, 'callback' => function ($r) {
                return self::out(SSB_Settings::restore((string) $r['backup']));
            },
        ));
        register_rest_route(self::NS, '/redirects', array(
            array('methods' => WP_REST_Server::READABLE, 'permission_callback' => $admin, 'callback' => function () {
                return self::out(SSB_Redirects::list_all());
            }),
            array('methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $admin, 'callback' => function ($r) {
                $b = $r->get_json_params();
                $b = is_array($b) ? $b : array();
                return self::out(SSB_Redirects::create(isset($b['from']) ? $b['from'] : '', isset($b['to']) ? $b['to'] : '', isset($b['code']) ? $b['code'] : 301));
            }),
        ));
        register_rest_route(self::NS, '/status', array(
            'methods' => WP_REST_Server::READABLE, 'permission_callback' => $admin, 'callback' => function () {
                return self::out(array(
                    'version' => SSB_VERSION,
                    'rank_math_installed' => defined('RANK_MATH_VERSION'),
                    'rank_math_version' => defined('RANK_MATH_VERSION') ? RANK_MATH_VERSION : null,
                    'redirections_module' => class_exists('\\RankMath\\Redirections\\Redirection'),
                    'abilities_api' => function_exists('wp_register_ability'),
                    'schema_types' => SSB_Schema::types(),
                    'settings_groups' => array_keys(SSB_Settings::groups()),
                    'score_target' => SSB_Audit::TARGET,
                    'legacy_values_migrated' => (int) get_option('smartsite_seo_bridge_migrated', 0),
                ));
            },
        ));
    }
}
