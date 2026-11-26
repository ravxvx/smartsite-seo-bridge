<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Field definitions, sanitising, REST meta registration and reading/writing of one item's SEO data. */
final class SSB_Fields {
    const ROBOTS_KEY = 'rank_math_robots';
    const ADV_ROBOTS_KEY = 'rank_math_advanced_robots';
    const SCORE_KEY = 'rank_math_seo_score';

    /** Public field name => definition. Keys have no leading underscore: that is what Rank Math reads. */
    public static function defs() {
        return array(
            'title'                => array('key' => 'rank_math_title', 'max' => 255),
            'description'          => array('key' => 'rank_math_description', 'max' => 320),
            'focus_keyword'        => array('key' => 'rank_math_focus_keyword', 'max' => 255),
            'canonical'            => array('key' => 'rank_math_canonical_url', 'max' => 2048, 'url' => true),
            'breadcrumb_title'     => array('key' => 'rank_math_breadcrumb_title', 'max' => 255),
            'facebook_title'       => array('key' => 'rank_math_facebook_title', 'max' => 255),
            'facebook_description' => array('key' => 'rank_math_facebook_description', 'max' => 320),
            'facebook_image'       => array('key' => 'rank_math_facebook_image', 'max' => 2048, 'url' => true),
            'twitter_use_facebook' => array('key' => 'rank_math_twitter_use_facebook', 'max' => 3, 'enum' => array('on', 'off')),
            'twitter_card_type'    => array('key' => 'rank_math_twitter_card_type', 'max' => 40, 'enum' => array('summary_large_image', 'summary_card')),
            'twitter_title'        => array('key' => 'rank_math_twitter_title', 'max' => 255),
            'twitter_description'  => array('key' => 'rank_math_twitter_description', 'max' => 320),
            'twitter_image'        => array('key' => 'rank_math_twitter_image', 'max' => 2048, 'url' => true),
            'primary_category'     => array('key' => 'rank_math_primary_category', 'max' => 20, 'int' => true),
            'pillar_content'       => array('key' => 'rank_math_pillar_content', 'max' => 3, 'enum' => array('on', 'off')),
        );
    }

    public static function robots_values() {
        return array('index', 'noindex', 'follow', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet');
    }

    public static function strlen($value) {
        return function_exists('mb_strlen') ? mb_strlen((string) $value, 'UTF-8') : strlen((string) $value);
    }

    /**
     * Plain-text clean-up that keeps Rank Math variables such as %date% or %category%.
     * (sanitize_text_field() would strip "%da" / "%ca" because they look like URL octets.)
     */
    public static function clean_text($value) {
        $value = wp_strip_all_tags((string) $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim((string) $value);
    }

    public static function sanitize_value($field, $value) {
        $defs = self::defs();
        $def = $defs[$field];
        $value = trim((string) $value);
        if (!empty($def['url'])) {
            return $value === '' ? '' : esc_url_raw($value);
        }
        if (!empty($def['int'])) {
            return absint($value) > 0 ? (string) absint($value) : '';
        }
        return self::clean_text($value);
    }

    public static function clean_robots($value) {
        if (!is_array($value)) {
            return array();
        }
        return array_values(array_intersect(self::robots_values(), array_map('strval', $value)));
    }

    /** @return array|WP_Error */
    public static function clean_advanced_robots($value) {
        if (!is_array($value)) {
            return new WP_Error('smartsite_seo_invalid_value', 'advanced_robots must be an object.', array('status' => 400));
        }
        $out = array();
        foreach ($value as $k => $v) {
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            if (($k === 'max-snippet' || $k === 'max-video-preview') && preg_match('/^-?\d{1,6}$/', $v)) {
                $out[$k] = $v;
            } elseif ($k === 'max-image-preview' && in_array($v, array('none', 'standard', 'large'), true)) {
                $out[$k] = $v;
            } else {
                return new WP_Error('smartsite_seo_invalid_value', 'Unsupported advanced_robots entry: ' . sanitize_key((string) $k), array('status' => 400));
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------- REST meta */

    public static function post_auth($allowed, $meta_key, $object_id) {
        return current_user_can('edit_post', $object_id);
    }

    public static function term_auth($allowed, $meta_key, $object_id) {
        return current_user_can('edit_term', $object_id);
    }

    public static function register_meta() {
        $post_types = get_post_types(array('public' => true), 'names');
        unset($post_types['attachment']);
        $taxonomies = get_taxonomies(array('public' => true), 'names');

        foreach (self::defs() as $field => $def) {
            $sanitize = function ($value) use ($field) {
                return SSB_Fields::sanitize_value($field, $value);
            };
            $base = array('type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => $sanitize);
            foreach ($post_types as $pt) {
                register_post_meta($pt, $def['key'], $base + array('auth_callback' => array(__CLASS__, 'post_auth')));
            }
            foreach ($taxonomies as $tx) {
                register_term_meta($tx, $def['key'], $base + array('auth_callback' => array(__CLASS__, 'term_auth')));
            }
        }

        $robots = array(
            'type' => 'array',
            'single' => true,
            'show_in_rest' => array('schema' => array('type' => 'array', 'items' => array('type' => 'string', 'enum' => self::robots_values()))),
            'sanitize_callback' => array(__CLASS__, 'clean_robots'),
        );
        foreach ($post_types as $pt) {
            register_post_meta($pt, self::ROBOTS_KEY, $robots + array('auth_callback' => array(__CLASS__, 'post_auth')));
        }
        foreach ($taxonomies as $tx) {
            register_term_meta($tx, self::ROBOTS_KEY, $robots + array('auth_callback' => array(__CLASS__, 'term_auth')));
        }
    }

    /* ------------------------------------------------------------ access */

    /** @return WP_Post|WP_Error */
    public static function allowed_post($post_id) {
        $post = get_post($post_id);
        $type = $post ? get_post_type_object($post->post_type) : null;
        if (!$post || $post->post_type === 'attachment' || !$type || !$type->public) {
            return new WP_Error('smartsite_seo_not_found', 'Content not found.', array('status' => 404));
        }
        if (!is_user_logged_in() || !current_user_can('edit_post', $post_id) || !current_user_can('manage_options')) {
            return new WP_Error('smartsite_seo_forbidden', 'Administrator access is required.', array('status' => 403));
        }
        return $post;
    }

    /* ------------------------------------------------------- read / write */

    /** All Rank Math meta keys SmartSite SEO Bridge manages for an item, including present schema keys. */
    public static function managed_keys($post_id) {
        $keys = array(self::ROBOTS_KEY, self::ADV_ROBOTS_KEY);
        foreach (self::defs() as $def) {
            $keys[] = $def['key'];
        }
        return array_values(array_unique(array_merge($keys, SSB_Schema::existing_keys($post_id))));
    }

    /** Raw managed meta of an item (only non-empty values). */
    public static function snapshot($post_id) {
        $out = array();
        foreach (self::managed_keys($post_id) as $key) {
            $v = get_post_meta($post_id, $key, true);
            if ($v !== '' && $v !== array() && $v !== null && $v !== false) {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    /** Makes the managed meta of an item equal to $target (keys missing from it are deleted). */
    public static function apply_snapshot($post_id, $target) {
        $keys = array_unique(array_merge(array_keys($target), self::managed_keys($post_id)));
        foreach ($keys as $key) {
            if (isset($target[$key])) {
                update_post_meta($post_id, $key, wp_slash($target[$key]));
            } else {
                delete_post_meta($post_id, $key);
            }
        }
    }

    public static function item($post_id, $with_audit = false) {
        $post = get_post($post_id);
        $seo = array();
        foreach (self::defs() as $field => $def) {
            $seo[$field] = (string) get_post_meta($post_id, $def['key'], true);
        }
        $robots = get_post_meta($post_id, self::ROBOTS_KEY, true);
        $adv = get_post_meta($post_id, self::ADV_ROBOTS_KEY, true);
        $data = array(
            'post_id' => (int) $post_id,
            'post_type' => $post ? $post->post_type : '',
            'status' => $post ? $post->post_status : '',
            'post_title' => get_the_title($post_id),
            'url' => get_permalink($post_id),
            'rank_math_installed' => defined('RANK_MATH_VERSION'),
            'seo' => $seo,
            'robots' => is_array($robots) ? array_values($robots) : array(),
            'advanced_robots' => is_array($adv) ? $adv : array(),
            'schema' => SSB_Schema::read($post_id),
            'rank_math_score' => (int) get_post_meta($post_id, self::SCORE_KEY, true),
        );
        if ($with_audit) {
            $data['audit'] = SSB_Audit::run($post_id);
        }
        return $data;
    }

    /** Validates and saves SEO fields. Empty value removes a field. Returns item data (with audit) or WP_Error. */
    public static function update_item($post_id, $params, $source = 'api') {
        if (!is_array($params)) {
            return new WP_Error('smartsite_seo_invalid_body', 'Send a JSON object containing SEO fields.', array('status' => 400));
        }
        $defs = self::defs();
        $unknown = array_diff(array_keys($params), array_merge(array_keys($defs), array('robots', 'advanced_robots', 'schema')));
        if ($unknown) {
            return new WP_Error('smartsite_seo_unknown_fields', 'Unsupported SEO fields: ' . implode(', ', array_map('sanitize_key', $unknown)), array('status' => 400));
        }

        $changes = array(); // meta key => value, or null to delete
        foreach ($params as $field => $value) {
            if ($field === 'robots') {
                if (!is_array($value) || array_diff(array_map('strval', $value), self::robots_values())) {
                    return new WP_Error('smartsite_seo_invalid_value', 'robots must be a list of: ' . implode(', ', self::robots_values()) . '.', array('status' => 400));
                }
                $clean = self::clean_robots($value);
                $changes[self::ROBOTS_KEY] = $clean ? $clean : null;
            } elseif ($field === 'advanced_robots') {
                $clean = self::clean_advanced_robots($value);
                if (is_wp_error($clean)) {
                    return $clean;
                }
                $changes[self::ADV_ROBOTS_KEY] = $clean ? $clean : null;
            } elseif ($field === 'schema') {
                $built = SSB_Schema::build_changes($post_id, $value);
                if (is_wp_error($built)) {
                    return $built;
                }
                $changes = $changes + $built;
            } else {
                if (!is_string($value) && !is_numeric($value)) {
                    return new WP_Error('smartsite_seo_invalid_value', $field . ' must be a string.', array('status' => 400));
                }
                $value = trim((string) $value);
                $def = $defs[$field];
                if (self::strlen($value) > $def['max']) {
                    return new WP_Error('smartsite_seo_too_long', sprintf('%s exceeds %d characters.', $field, $def['max']), array('status' => 400));
                }
                if (!empty($def['url']) && $value !== '' && !wp_http_validate_url($value)) {
                    return new WP_Error('smartsite_seo_invalid_url', $field . ' must be a valid HTTP or HTTPS URL.', array('status' => 400));
                }
                if (!empty($def['enum']) && $value !== '' && !in_array($value, $def['enum'], true)) {
                    return new WP_Error('smartsite_seo_invalid_value', sprintf('%s must be one of: %s.', $field, implode(', ', $def['enum'])), array('status' => 400));
                }
                $clean = self::sanitize_value($field, $value);
                $changes[$def['key']] = $clean === '' ? null : $clean;
            }
        }

        $before = self::snapshot($post_id);
        foreach ($changes as $key => $value) {
            if ($value === null) {
                delete_post_meta($post_id, $key);
            } else {
                update_post_meta($post_id, $key, wp_slash($value));
            }
        }
        $after = self::snapshot($post_id);
        if ($before != $after) {
            SSB_History::add($post_id, $before, $after, $source);
        }
        return self::item($post_id, true);
    }

    /* ----------------------------------------------------------- listing */

    public static function list_items($args) {
        $types = get_post_types(array('public' => true), 'names');
        unset($types['attachment']);
        $pt = isset($args['post_type']) ? sanitize_key($args['post_type']) : 'page';
        if ($pt !== 'any' && !isset($types[$pt])) {
            return new WP_Error('smartsite_seo_bad_type', 'Unknown post type.', array('status' => 400));
        }
        $status = isset($args['status']) ? sanitize_key($args['status']) : 'any';
        if (!in_array($status, array('any', 'publish', 'draft', 'pending', 'private', 'future'), true)) {
            $status = 'any';
        }
        $per = isset($args['per_page']) ? max(1, min(100, (int) $args['per_page'])) : 20;
        $paged = isset($args['page']) ? max(1, (int) $args['page']) : 1;
        $with_audit = !empty($args['audit']) && $args['audit'] !== 'false' && $args['audit'] !== '0';
        $q = new WP_Query(array(
            'post_type' => $pt === 'any' ? array_values($types) : $pt,
            'post_status' => $status,
            's' => isset($args['search']) ? sanitize_text_field((string) $args['search']) : '',
            'posts_per_page' => $per,
            'paged' => $paged,
            'orderby' => 'menu_order title',
            'order' => 'ASC',
        ));
        $items = array();
        foreach ($q->posts as $p) {
            // Stray output (PHP notices from other plugins) would break the JSON reply; one bad item must not stop the list.
            ob_start();
            try {
                $items[] = self::item($p->ID, $with_audit);
            } catch (\Throwable $e) {
                $items[] = array('post_id' => (int) $p->ID, 'post_type' => $p->post_type, 'status' => $p->post_status, 'post_title' => get_the_title($p), 'url' => get_permalink($p), 'seo' => array(), 'robots' => array(), 'schema' => array(), 'rank_math_score' => 0, 'error' => $e->getMessage(),
                    'audit' => array('score' => 0, 'grade' => 'red', 'keyword' => '', 'words' => 0, 'density' => 0, 'checks' => array(array('id' => 'error', 'label' => 'Audit failed: ' . $e->getMessage(), 'status' => 'fail', 'tip' => ''))));
            }
            ob_end_clean();
        }
        return array('total' => (int) $q->found_posts, 'pages' => (int) $q->max_num_pages, 'page' => $paged, 'items' => $items);
    }

    /* -------------------------------------------------------------- terms */

    public static function term_item($taxonomy, $term_id) {
        $term = get_term($term_id, $taxonomy);
        if (!$term || is_wp_error($term)) {
            return new WP_Error('smartsite_seo_not_found', 'Term not found.', array('status' => 404));
        }
        $seo = array();
        foreach (self::defs() as $field => $def) {
            $seo[$field] = (string) get_term_meta($term_id, $def['key'], true);
        }
        $robots = get_term_meta($term_id, self::ROBOTS_KEY, true);
        return array('term_id' => (int) $term_id, 'taxonomy' => $taxonomy, 'name' => $term->name, 'url' => get_term_link($term), 'seo' => $seo, 'robots' => is_array($robots) ? array_values($robots) : array());
    }

    public static function update_term($taxonomy, $term_id, $params) {
        $item = self::term_item($taxonomy, $term_id);
        if (is_wp_error($item)) {
            return $item;
        }
        if (!is_array($params)) {
            return new WP_Error('smartsite_seo_invalid_body', 'Send a JSON object containing SEO fields.', array('status' => 400));
        }
        $defs = self::defs();
        foreach ($params as $field => $value) {
            if ($field === 'robots') {
                if (!is_array($value) || array_diff(array_map('strval', $value), self::robots_values())) {
                    return new WP_Error('smartsite_seo_invalid_value', 'Unsupported robots values.', array('status' => 400));
                }
                $clean = self::clean_robots($value);
                $clean ? update_term_meta($term_id, self::ROBOTS_KEY, $clean) : delete_term_meta($term_id, self::ROBOTS_KEY);
                continue;
            }
            if (!isset($defs[$field]) || (!is_string($value) && !is_numeric($value))) {
                return new WP_Error('smartsite_seo_unknown_fields', 'Unsupported field: ' . sanitize_key((string) $field), array('status' => 400));
            }
            $def = $defs[$field];
            $value = trim((string) $value);
            if (self::strlen($value) > $def['max'] || (!empty($def['enum']) && $value !== '' && !in_array($value, $def['enum'], true))) {
                return new WP_Error('smartsite_seo_invalid_value', 'Invalid value for ' . $field . '.', array('status' => 400));
            }
            $clean = self::sanitize_value($field, $value);
            $clean === '' ? delete_term_meta($term_id, $def['key']) : update_term_meta($term_id, $def['key'], wp_slash($clean));
        }
        return self::term_item($taxonomy, $term_id);
    }
}

/** Change history of per-item SEO edits, with undo. */
final class SSB_History {
    const OPT = 'smartsite_seo_bridge_history';
    const MAX = 200;

    public static function all() {
        $list = get_option(self::OPT, array());
        return is_array($list) ? $list : array();
    }

    public static function add($post_id, $before, $after, $source) {
        $list = self::all();
        array_unshift($list, array(
            'id' => wp_generate_uuid4(),
            'time' => time(),
            'user' => get_current_user_id(),
            'post_id' => (int) $post_id,
            'source' => (string) $source,
            'before' => $before,
            'after' => $after,
        ));
        update_option(self::OPT, array_slice($list, 0, self::MAX), false);
    }

    /** Compact view: which keys changed and their old/new values. */
    public static function summarise($limit = 50) {
        $out = array();
        foreach (array_slice(self::all(), 0, max(1, (int) $limit)) as $e) {
            $changed = array();
            $keys = array_unique(array_merge(array_keys($e['before']), array_keys($e['after'])));
            foreach ($keys as $k) {
                $b = isset($e['before'][$k]) ? $e['before'][$k] : null;
                $a = isset($e['after'][$k]) ? $e['after'][$k] : null;
                if ($a != $b) {
                    $changed[$k] = array('from' => $b, 'to' => $a);
                }
            }
            $post = get_post($e['post_id']);
            $user = get_userdata($e['user']);
            $out[] = array(
                'id' => $e['id'],
                'time' => gmdate('c', (int) $e['time']),
                'user' => $user ? $user->user_login : '',
                'post_id' => (int) $e['post_id'],
                'post_title' => $post ? get_the_title($post) : '(deleted)',
                'source' => $e['source'],
                'changed' => $changed,
            );
        }
        return $out;
    }

    public static function undo($entry_id) {
        foreach (self::all() as $e) {
            if ($e['id'] === $entry_id) {
                $post = SSB_Fields::allowed_post((int) $e['post_id']);
                if (is_wp_error($post)) {
                    return $post;
                }
                $current = SSB_Fields::snapshot($post->ID);
                SSB_Fields::apply_snapshot($post->ID, $e['before']);
                $after = SSB_Fields::snapshot($post->ID);
                if ($current != $after) {
                    self::add($post->ID, $current, $after, 'undo');
                }
                return SSB_Fields::item($post->ID, true);
            }
        }
        return new WP_Error('smartsite_seo_not_found', 'History entry not found.', array('status' => 404));
    }
}
