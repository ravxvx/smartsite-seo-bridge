<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds or removes small, marked HTML snippets at the start or end of post content (for example an SEO intro).
 *
 * Snippets are kept per site in the SSB_Snippets::OPTION option (the "library"), so the plugin ships no
 * site-specific content and works on any site. A library is imported as JSON (an array of items, or
 * {"site": "example.com", "items": [...]}). Each item targets a post by "slug" (portable between sites)
 * or by "id". When the JSON names a "site", it is only accepted on that host.
 *
 * Each snippet is wrapped in comment markers so it is applied once and can be removed again.
 * WordPress also keeps a revision.
 */
final class SSB_Snippets {
    const OPTION = 'smartsite_seo_bridge_snippets';
    const MAX_ITEMS = 500;
    const MAX_HTML = 20000;

    /** The library saved for this site. */
    public static function library() {
        $data = get_option(self::OPTION, array());
        return is_array($data) ? array_values($data) : array();
    }

    private static function host($url) {
        $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
        return preg_replace('/^www\./', '', $host);
    }

    /**
     * Validates and cleans a library sent as JSON (decoded). Returns the clean items or a WP_Error.
     * @param mixed $data array of items, or array('site' => host, 'items' => array(...))
     */
    public static function validate($data) {
        if (is_array($data) && isset($data['items'])) {
            if (!empty($data['site'])) {
                $want = self::host(strpos((string) $data['site'], '//') === false ? 'https://' . $data['site'] : $data['site']);
                $have = self::host(home_url());
                if ($want !== '' && $want !== $have) {
                    return new WP_Error('smartsite_seo_wrong_site', sprintf('This snippet file is for %s, not for %s. Nothing was saved.', $want, $have), array('status' => 400));
                }
            }
            $data = $data['items'];
        }
        if (!is_array($data)) {
            return new WP_Error('smartsite_seo_invalid_body', 'Send a list of snippets: [{"slug":"about","key":"seo-intro","position":"start","html":"<p>…</p>"}].', array('status' => 400));
        }
        if (count($data) > self::MAX_ITEMS) {
            return new WP_Error('smartsite_seo_invalid_body', 'Too many snippets (maximum ' . self::MAX_ITEMS . ').', array('status' => 400));
        }
        $out = array();
        foreach (array_values($data) as $i => $it) {
            if (!is_array($it)) {
                return new WP_Error('smartsite_seo_invalid_value', 'Snippet ' . ($i + 1) . ' is not an object.', array('status' => 400));
            }
            $id = absint(isset($it['id']) ? $it['id'] : 0);
            $slug = sanitize_title(isset($it['slug']) ? (string) $it['slug'] : '');
            if (!$id && $slug === '') {
                return new WP_Error('smartsite_seo_invalid_value', 'Snippet ' . ($i + 1) . ' needs a "slug" or an "id".', array('status' => 400));
            }
            $html = isset($it['html']) ? (string) $it['html'] : '';
            if (trim($html) === '' || strlen($html) > self::MAX_HTML) {
                return new WP_Error('smartsite_seo_invalid_value', 'Snippet ' . ($i + 1) . ' has empty or too long "html".', array('status' => 400));
            }
            $row = array(
                'key' => self::clean_key(isset($it['key']) ? $it['key'] : 'snippet'),
                'position' => (isset($it['position']) && $it['position'] === 'end') ? 'end' : 'start',
                'html' => wp_kses_post($html),
            );
            if ($slug !== '') {
                $row['slug'] = $slug;
            }
            if ($id) {
                $row['id'] = $id;
            }
            if (isset($it['keyword']) && $it['keyword'] !== '') {
                $row['keyword'] = SSB_Fields::clean_text($it['keyword']);
            }
            $out[] = $row;
        }
        return $out;
    }

    /** Saves a library for this site (an empty list clears it). */
    public static function save($data) {
        $items = self::validate($data);
        if (is_wp_error($items)) {
            return $items;
        }
        update_option(self::OPTION, $items, false);
        return array('saved' => count($items), 'items' => self::describe($items));
    }

    /** Library items with the post each one resolves to on this site, so the admin can check before applying. */
    public static function describe($items) {
        $out = array();
        foreach ((array) $items as $it) {
            $post = self::target($it);
            $out[] = $it + array(
                'target_id' => $post ? (int) $post->ID : 0,
                'target_title' => $post ? get_the_title($post) : '',
                'target_url' => $post ? get_permalink($post) : '',
            );
        }
        return $out;
    }

    private static function clean_key($key) {
        $key = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $key));
        return $key === '' ? 'snippet' : $key;
    }

    private static function markers($key) {
        $key = self::clean_key($key);
        return array('<!-- ssb-snippet:' . $key . ' -->', '<!-- /ssb-snippet:' . $key . ' -->');
    }

    /** Finds the post an item points to: slug first (portable), then id. */
    private static function target($it) {
        if (!empty($it['slug'])) {
            $types = get_post_types(array('public' => true), 'names');
            unset($types['attachment']);
            $q = get_posts(array(
                'name' => (string) $it['slug'],
                'post_type' => array_values($types),
                'post_status' => 'any',
                'posts_per_page' => 1,
                'suppress_filters' => true,
            ));
            if ($q) {
                return $q[0];
            }
            return null;
        }
        $id = absint(isset($it['id']) ? $it['id'] : 0);
        return $id ? get_post($id) : null;
    }

    /** @return WP_Post|WP_Error */
    private static function resolve($it) {
        $post = self::target($it);
        if (!$post) {
            return new WP_Error('smartsite_seo_not_found', !empty($it['slug']) ? 'no content with slug "' . $it['slug'] . '" on this site' : 'Content not found.', array('status' => 404));
        }
        return SSB_Fields::allowed_post($post->ID);
    }

    private static function row($it, $post) {
        return array(
            'id' => is_wp_error($post) ? absint(isset($it['id']) ? $it['id'] : 0) : (int) $post->ID,
            'slug' => isset($it['slug']) ? $it['slug'] : '',
            'title' => is_wp_error($post) ? '' : get_the_title($post),
            'state' => '',
        );
    }

    /** @param array|null $items items to apply; null means the saved library. */
    public static function apply($items, $dry_run) {
        if ($items === null) {
            $items = self::library();
        } else {
            $items = self::validate($items);
            if (is_wp_error($items)) {
                return $items;
            }
        }
        $results = array();
        foreach ($items as $it) {
            $post = self::resolve($it);
            $row = self::row($it, $post);
            if (is_wp_error($post)) {
                $row['state'] = 'skipped: ' . $post->get_error_message();
                $results[] = $row;
                continue;
            }
            list($open, $close) = self::markers($it['key']);
            if (strpos($post->post_content, $open) !== false) {
                $row['state'] = 'already applied';
                $results[] = $row;
                continue;
            }
            $html = wp_kses_post($it['html']);
            $block = $open . "\n" . $html . "\n" . $close;
            $new = $it['position'] === 'end' ? $post->post_content . "\n" . $block : $block . "\n" . $post->post_content;
            if ($dry_run) {
                $row['state'] = 'would apply';
            } else {
                $r = wp_update_post(array('ID' => $post->ID, 'post_content' => wp_slash($new)), true);
                $row['state'] = is_wp_error($r) ? 'failed: ' . $r->get_error_message() : 'applied';
            }
            $results[] = $row;
        }
        return array('dry_run' => (bool) $dry_run, 'site' => self::host(home_url()), 'results' => $results);
    }

    /** @param array|null $items items to remove; null means the saved library. */
    public static function remove($items) {
        if ($items === null) {
            $items = self::library();
        } else {
            $items = self::validate($items);
            if (is_wp_error($items)) {
                return $items;
            }
        }
        $results = array();
        foreach ($items as $it) {
            $post = self::resolve($it);
            $row = self::row($it, $post);
            if (is_wp_error($post)) {
                $row['state'] = 'skipped: ' . $post->get_error_message();
                $results[] = $row;
                continue;
            }
            list($open, $close) = self::markers($it['key']);
            $pattern = '/\s*' . preg_quote($open, '/') . '.*?' . preg_quote($close, '/') . '\s*/s';
            $new = preg_replace($pattern, "\n", $post->post_content, 1, $n);
            if (!$n) {
                $row['state'] = 'not present';
            } else {
                $r = wp_update_post(array('ID' => $post->ID, 'post_content' => wp_slash(trim($new))), true);
                $row['state'] = is_wp_error($r) ? 'failed: ' . $r->get_error_message() : 'removed';
            }
            $results[] = $row;
        }
        return array('results' => $results);
    }
}
