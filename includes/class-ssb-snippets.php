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
    const BUNDLED_OPTION = 'smartsite_seo_bridge_snippets_bundled';
    const MAX_ITEMS = 500;
    const MAX_HTML = 20000;

    /** The library saved for this site. */
    public static function library() {
        $data = self::maybe_import_bundled(get_option(self::OPTION, null));
        return is_array($data) ? array_values($data) : array();
    }

    /**
     * Loads bundled/<this host>.json when the plugin ships a library for this site and that exact file
     * has not been loaded before. A library the admin already has is never overwritten, and a library
     * cleared on purpose stays empty (the file's hash is remembered in BUNDLED_OPTION).
     */
    private static function maybe_import_bundled($data) {
        $file = SSB_DIR . 'bundled/' . self::host(home_url()) . '.json';
        if (!is_readable($file)) {
            if ($data === null) {
                update_option(self::OPTION, array(), false);
                return array();
            }
            return $data;
        }
        $hash = (string) md5_file($file);
        if (get_option(self::BUNDLED_OPTION) === $hash) {
            return $data === null ? array() : $data;
        }
        update_option(self::BUNDLED_OPTION, $hash, false);
        if (is_array($data) && !empty($data)) {
            return $data;
        }
        $json = json_decode((string) file_get_contents($file), true);
        $clean = is_array($json) ? self::validate($json) : array();
        $items = is_wp_error($clean) ? array() : $clean;
        update_option(self::OPTION, $items, false);
        return $items;
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
            if (!empty($it['generated'])) {
                $row['generated'] = 1;
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

    /**
     * Builds an SEO intro for every published page and post that has a Rank Math focus keyword and
     * description but does not mention the keyword near the top of its content (the Rank Math
     * "keyword in introduction" test). Works on any site: the text comes from that site's own Rank Math
     * data. The result is saved to the library (replacing earlier generated items, keeping items loaded
     * from a file for other pages); nothing is added to pages until Apply.
     */
    public static function generate($dry_run = false) {
        $types = get_post_types(array('public' => true), 'names');
        unset($types['attachment']);
        $ids = get_posts(array(
            'post_type' => array_values($types),
            'post_status' => 'publish',
            'posts_per_page' => self::MAX_ITEMS,
            'fields' => 'ids',
            'suppress_filters' => true,
            'meta_query' => array(array('key' => 'rank_math_focus_keyword', 'value' => '', 'compare' => '!=')),
        ));
        $made = array();
        $report = array();
        foreach ($ids as $id) {
            $post = get_post($id);
            $kw = trim((string) strtok((string) get_post_meta($id, 'rank_math_focus_keyword', true), ','));
            $desc = trim((string) get_post_meta($id, 'rank_math_description', true));
            $robots = (array) get_post_meta($id, 'rank_math_robots', true);
            $row = array('id' => (int) $id, 'slug' => $post->post_name, 'title' => get_the_title($post), 'keyword' => $kw);
            if (in_array('noindex', $robots, true)) {
                $row['state'] = 'skipped: noindex';
            } elseif ($desc === '' || strpos($desc, '%') !== false) {
                $row['state'] = 'skipped: no plain Rank Math description';
            } elseif (strpos($post->post_content, '<!-- ssb-snippet:seo-intro -->') !== false) {
                $row['state'] = 'skipped: intro already on the page';
            } elseif (self::keyword_in_intro($post->post_content, $kw)) {
                $row['state'] = 'skipped: keyword already in the introduction';
            } else {
                $label = function_exists('mb_convert_case') ? mb_convert_case($kw, MB_CASE_TITLE, 'UTF-8') : ucwords($kw);
                $made[] = array(
                    'id' => (int) $id,
                    'key' => 'seo-intro',
                    'position' => 'start',
                    'keyword' => $kw,
                    'html' => '<p class="ssb-intro"><strong>' . esc_html($label) . ':</strong> ' . esc_html($desc) . '</p>',
                );
                $row['state'] = 'intro generated';
            }
            $report[] = $row;
        }
        if (!$dry_run) {
            $keep = array();
            foreach (self::library() as $it) {
                if (!empty($it['generated'])) {
                    continue;
                }
                $keep[] = $it;
            }
            $taken = array();
            foreach (self::describe($keep) as $it) {
                if ($it['target_id'] && $it['key'] === 'seo-intro') {
                    $taken[$it['target_id']] = true;
                }
            }
            foreach ($made as $it) {
                if (empty($taken[$it['id']])) {
                    $it['generated'] = 1;
                    $keep[] = $it;
                }
            }
            update_option(self::OPTION, array_slice($keep, 0, self::MAX_ITEMS), false);
        }
        return array('dry_run' => (bool) $dry_run, 'generated' => count($made), 'results' => $report, 'items' => self::describe(self::library()));
    }

    /** True when the keyword appears in the first 10% of the visible text (at least the first 60 words). */
    private static function keyword_in_intro($content, $kw) {
        if ($kw === '') {
            return true;
        }
        $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes((string) $content))));
        $words = preg_split('/\s+/u', $text);
        $n = max(60, (int) ceil(count($words) * 0.1));
        $intro = implode(' ', array_slice($words, 0, $n));
        return stripos($intro, $kw) !== false;
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
