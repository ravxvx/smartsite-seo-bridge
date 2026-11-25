<?php
if (!defined('ABSPATH')) {
    exit;
}

/** WordPress Abilities API registration (optional; skipped when the API is missing). */
final class SSB_Abilities {
    public static function register_category() {
        if (function_exists('wp_register_ability_category')) {
            wp_register_ability_category('smartsite-seo', array('label' => 'SmartSite SEO', 'description' => 'Read and edit Rank Math SEO data.'));
        }
    }

    public static function register() {
        if (!function_exists('wp_register_ability')) {
            return;
        }
        $admin = function () {
            return current_user_can('manage_options');
        };
        $meta = function ($readonly) {
            return array('show_in_rest' => true, 'annotations' => array('readonly' => $readonly, 'destructive' => false, 'idempotent' => true));
        };
        $seo_props = array();
        foreach (SSB_Fields::defs() as $field => $def) {
            $seo_props[$field] = array('type' => 'string', 'maxLength' => $def['max']);
        }
        $seo_props['robots'] = array('type' => 'array', 'items' => array('type' => 'string', 'enum' => SSB_Fields::robots_values()));
        $seo_props['advanced_robots'] = array('type' => 'object');
        $seo_props['schema'] = array('type' => 'object', 'description' => 'Keys: ' . implode(', ', SSB_Schema::types()) . '. A null value removes that schema.');
        $groups = array_keys(SSB_Settings::groups());

        $get_post = function ($input) {
            return SSB_Fields::allowed_post(absint(isset($input['id']) ? $input['id'] : 0));
        };

        try {
            wp_register_ability('smartsite-seo/list-items', array(
                'label' => 'List content with SEO data and estimated score',
                'description' => 'Lists pages, posts or other public content with Rank Math fields; set audit=true to include the estimated SEO score and failed checks.',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => array(
                    'post_type' => array('type' => 'string', 'default' => 'page'),
                    'status' => array('type' => 'string', 'default' => 'any'),
                    'search' => array('type' => 'string'),
                    'audit' => array('type' => 'boolean', 'default' => false),
                    'per_page' => array('type' => 'integer', 'default' => 20),
                    'page' => array('type' => 'integer', 'default' => 1),
                )),
                'execute_callback' => function ($input) { return SSB_Fields::list_items(is_array($input) ? $input : array()); },
                'permission_callback' => $admin,
                'meta' => $meta(true),
            ));
            wp_register_ability('smartsite-seo/get-item', array(
                'label' => 'Get SEO data of one item',
                'description' => 'Returns Rank Math fields, robots, schema and the estimated audit of a page or post.',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => array('id' => array('type' => 'integer')), 'required' => array('id')),
                'execute_callback' => function ($input) use ($get_post) {
                    $post = $get_post($input);
                    return is_wp_error($post) ? $post : SSB_Fields::item($post->ID, true);
                },
                'permission_callback' => $admin,
                'meta' => $meta(true),
            ));
            wp_register_ability('smartsite-seo/audit-item', array(
                'label' => 'Audit the SEO of one item',
                'description' => 'Estimated Rank Math style score (target 81+) with every check and a tip for each failed one.',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => array('id' => array('type' => 'integer')), 'required' => array('id')),
                'execute_callback' => function ($input) use ($get_post) {
                    $post = $get_post($input);
                    return is_wp_error($post) ? $post : SSB_Audit::run($post->ID);
                },
                'permission_callback' => $admin,
                'meta' => $meta(true),
            ));
            wp_register_ability('smartsite-seo/update-item', array(
                'label' => 'Update SEO data of one item',
                'description' => 'Sets Rank Math fields, robots, advanced robots and schema. An empty string removes a field. Every change is recorded in history and can be undone. Does not publish or change content.',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => array('id' => array('type' => 'integer'), 'seo' => array('type' => 'object', 'properties' => $seo_props)), 'required' => array('id', 'seo')),
                'execute_callback' => function ($input) use ($get_post) {
                    $post = $get_post($input);
                    return is_wp_error($post) ? $post : SSB_Fields::update_item($post->ID, isset($input['seo']) ? $input['seo'] : null, 'ability');
                },
                'permission_callback' => $admin,
                'meta' => $meta(false),
            ));
            wp_register_ability('smartsite-seo/list-history', array(
                'label' => 'List SEO change history',
                'description' => 'Recent per-item SEO changes with old and new values.',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => array('limit' => array('type' => 'integer', 'default' => 50))),
                'execute_callback' => function ($input) { return array('entries' => SSB_History::summarise(isset($input['limit']) ? (int) $input['limit'] : 50)); },
                'permission_callback' => $admin,
                'meta' => $meta(true),
            ));
            wp_register_ability('smartsite-seo/undo-change', array(
                'label' => 'Undo an SEO change',
                'description' => 'Restores the values from before a history entry.',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => array('entry' => array('type' => 'string')), 'required' => array('entry')),
                'execute_callback' => function ($input) { return SSB_History::undo((string) (isset($input['entry']) ? $input['entry'] : '')); },
                'permission_callback' => $admin,
                'meta' => $meta(false),
            ));
            wp_register_ability('smartsite-seo/get-settings', array(
                'label' => 'Get Rank Math global settings',
                'description' => 'Returns one settings group: ' . implode(', ', $groups) . '.',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => array('group' => array('type' => 'string', 'enum' => $groups)), 'required' => array('group')),
                'execute_callback' => function ($input) { return SSB_Settings::get((string) (isset($input['group']) ? $input['group'] : '')); },
                'permission_callback' => $admin,
                'meta' => $meta(true),
            ));
            wp_register_ability('smartsite-seo/update-settings', array(
                'label' => 'Update Rank Math global settings',
                'description' => 'Merges values into one settings group. Use dry_run=true first to preview. Each real change is backed up and can be restored.',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => array(
                    'group' => array('type' => 'string', 'enum' => array_values(array_diff($groups, array('modules')))),
                    'values' => array('type' => 'object'),
                    'dry_run' => array('type' => 'boolean', 'default' => false),
                ), 'required' => array('group', 'values')),
                'execute_callback' => function ($input) {
                    return SSB_Settings::update((string) (isset($input['group']) ? $input['group'] : ''), isset($input['values']) ? $input['values'] : null, !empty($input['dry_run']));
                },
                'permission_callback' => $admin,
                'meta' => $meta(false),
            ));
            wp_register_ability('smartsite-seo/create-redirect', array(
                'label' => 'Create a redirect',
                'description' => 'Creates a redirect in the Rank Math Redirections module (default 301).',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => array('from' => array('type' => 'string'), 'to' => array('type' => 'string'), 'code' => array('type' => 'integer', 'default' => 301)), 'required' => array('from', 'to')),
                'execute_callback' => function ($input) {
                    return SSB_Redirects::create(isset($input['from']) ? $input['from'] : '', isset($input['to']) ? $input['to'] : '', isset($input['code']) ? $input['code'] : 301);
                },
                'permission_callback' => $admin,
                'meta' => $meta(false),
            ));
            wp_register_ability('smartsite-seo/list-theme-files', array(
                'label' => 'List files of the active theme',
                'description' => 'Read-only list of template, style and script files in the active (child and parent) theme.',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => new stdClass()),
                'execute_callback' => function () { return SSB_SiteTools::theme_files(); },
                'permission_callback' => $admin,
                'meta' => $meta(true),
            ));
            wp_register_ability('smartsite-seo/read-theme-file', array(
                'label' => 'Read one file of the active theme',
                'description' => 'Read-only. Returns the text of a theme file (php, css, js, json, html, txt, md, up to 150 KB). Cannot read outside the theme folder.',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => array('theme' => array('type' => 'string'), 'path' => array('type' => 'string')), 'required' => array('path')),
                'execute_callback' => function ($input) { return SSB_SiteTools::theme_file(isset($input['theme']) ? $input['theme'] : '', isset($input['path']) ? $input['path'] : ''); },
                'permission_callback' => $admin,
                'meta' => $meta(true),
            ));
            wp_register_ability('smartsite-seo/get-additional-css', array(
                'label' => 'Read Additional CSS and its history',
                'description' => 'Returns the Customizer Additional CSS and a list of earlier versions saved by this plugin.',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => new stdClass()),
                'execute_callback' => function () { return SSB_SiteTools::css_get(); },
                'permission_callback' => $admin,
                'meta' => $meta(true),
            ));
            wp_register_ability('smartsite-seo/set-additional-css', array(
                'label' => 'Replace Additional CSS',
                'description' => 'Replaces the whole Additional CSS with the given text (read it first and send the complete result). The previous version is saved to history and can be restored with undo. dry_run=true only reports sizes. HTML tags are refused.',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => array('css' => array('type' => 'string'), 'note' => array('type' => 'string'), 'dry_run' => array('type' => 'boolean', 'default' => false)), 'required' => array('css')),
                'execute_callback' => function ($input) { return SSB_SiteTools::css_set(isset($input['css']) ? $input['css'] : '', isset($input['note']) ? $input['note'] : '', !empty($input['dry_run'])); },
                'permission_callback' => $admin,
                'meta' => $meta(false),
            ));
            wp_register_ability('smartsite-seo/undo-additional-css', array(
                'label' => 'Restore an earlier Additional CSS version',
                'description' => 'Restores a version from the history returned by get-additional-css.',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => array('id' => array('type' => 'string')), 'required' => array('id')),
                'execute_callback' => function ($input) { return SSB_SiteTools::css_undo(isset($input['id']) ? $input['id'] : ''); },
                'permission_callback' => $admin,
                'meta' => $meta(false),
            ));
            wp_register_ability('smartsite-seo/read-php-log', array(
                'label' => 'Read the tail of the PHP error log',
                'description' => 'Read-only. Returns the last lines (default 80, max 300) of wp-content/debug.log or the PHP error_log when readable.',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => array('lines' => array('type' => 'integer', 'default' => 80))),
                'execute_callback' => function ($input) { return SSB_SiteTools::php_log(isset($input['lines']) ? $input['lines'] : 80); },
                'permission_callback' => $admin,
                'meta' => $meta(true),
            ));
            wp_register_ability('smartsite-seo/plan-content-images', array(
                'label' => 'Plan: featured image into content',
                'description' => 'Read-only preview of published, indexable items whose content has no image: the featured image would be inserted with alt text containing the focus keyword.',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => new stdClass()),
                'execute_callback' => function () { return SSB_Images::plan(); },
                'permission_callback' => $admin,
                'meta' => $meta(true),
            ));
            wp_register_ability('smartsite-seo/apply-content-images', array(
                'label' => 'Insert featured images into content',
                'description' => 'Inserts the featured image (with keyword alt text) into the content of the given item ids, or of all planned items when ids is empty. Each change is a normal WordPress revision.',
                'category' => 'smartsite-seo',
                'input_schema' => array('type' => 'object', 'properties' => array('ids' => array('type' => 'array', 'items' => array('type' => 'integer')))),
                'execute_callback' => function ($input) { return SSB_Images::apply(isset($input['ids']) ? (array) $input['ids'] : array()); },
                'permission_callback' => $admin,
                'meta' => $meta(false),
            ));
        } catch (\Throwable $e) {
            // The Abilities API changed shape: the REST routes keep working.
        }
    }
}
