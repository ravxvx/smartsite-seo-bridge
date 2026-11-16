<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds or removes small, marked HTML snippets at the start or end of post content (for example an SEO intro).
 * Each snippet is wrapped in comment markers so it is applied once and can be removed again. WordPress also keeps a revision.
 */
final class SSB_Snippets {
    public static function bundled() {
        $file = SSB_DIR . 'snippets/seo-intros.json';
        $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : array();
        return is_array($data) ? $data : array();
    }

    private static function markers($key) {
        $key = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $key));
        return array('<!-- ssb-snippet:' . $key . ' -->', '<!-- /ssb-snippet:' . $key . ' -->');
    }

    /** @return array{id:int,title:string,status:string,state:string} */
    public static function apply($items, $dry_run) {
        $results = array();
        foreach ((array) $items as $it) {
            $id = absint(isset($it['id']) ? $it['id'] : 0);
            $post = SSB_Fields::allowed_post($id);
            $row = array('id' => $id, 'title' => '', 'state' => '');
            if (is_wp_error($post)) {
                $row['state'] = 'skipped: ' . $post->get_error_message();
                $results[] = $row;
                continue;
            }
            $row['title'] = get_the_title($id);
            list($open, $close) = self::markers(isset($it['key']) ? $it['key'] : 'snippet');
            if (strpos($post->post_content, $open) !== false) {
                $row['state'] = 'already applied';
                $results[] = $row;
                continue;
            }
            $html = wp_kses_post(isset($it['html']) ? (string) $it['html'] : '');
            if ($html === '') {
                $row['state'] = 'skipped: empty snippet';
                $results[] = $row;
                continue;
            }
            $block = $open . "\n" . $html . "\n" . $close;
            $new = (isset($it['position']) && $it['position'] === 'end') ? $post->post_content . "\n" . $block : $block . "\n" . $post->post_content;
            if ($dry_run) {
                $row['state'] = 'would apply';
            } else {
                $r = wp_update_post(array('ID' => $id, 'post_content' => wp_slash($new)), true);
                $row['state'] = is_wp_error($r) ? 'failed: ' . $r->get_error_message() : 'applied';
            }
            $results[] = $row;
        }
        return array('dry_run' => (bool) $dry_run, 'results' => $results);
    }

    public static function remove($items) {
        $results = array();
        foreach ((array) $items as $it) {
            $id = absint(isset($it['id']) ? $it['id'] : 0);
            $post = SSB_Fields::allowed_post($id);
            $row = array('id' => $id, 'title' => '', 'state' => '');
            if (is_wp_error($post)) {
                $row['state'] = 'skipped: ' . $post->get_error_message();
                $results[] = $row;
                continue;
            }
            $row['title'] = get_the_title($id);
            list($open, $close) = self::markers(isset($it['key']) ? $it['key'] : 'snippet');
            $pattern = '/\s*' . preg_quote($open, '/') . '.*?' . preg_quote($close, '/') . '\s*/s';
            $new = preg_replace($pattern, "\n", $post->post_content, 1, $n);
            if (!$n) {
                $row['state'] = 'not present';
            } else {
                $r = wp_update_post(array('ID' => $id, 'post_content' => wp_slash(trim($new))), true);
                $row['state'] = is_wp_error($r) ? 'failed: ' . $r->get_error_message() : 'removed';
            }
            $results[] = $row;
        }
        return array('results' => $results);
    }
}
