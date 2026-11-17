<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Puts each page's/post's featured image into its content (with alt text that contains the Rank Math
 * focus keyword) when the content has no <img> at all. Rank Math counts only images in the content.
 * Works on any site, only touches published, indexable items built with the normal editor, and the
 * change is a normal WordPress edit, so it appears in the item's revisions. Nothing happens until Apply.
 */
final class SSB_Images {
    const MARK = '<!-- ssb-image -->';
    const END = '<!-- /ssb-image -->';
    const MAX_ITEMS = 300;

    private static function candidates() {
        $types = get_post_types(array('public' => true), 'names');
        unset($types['attachment']);
        return get_posts(array(
            'post_type' => array_values($types),
            'post_status' => 'publish',
            'posts_per_page' => self::MAX_ITEMS,
            'fields' => 'ids',
            'suppress_filters' => true,
            'meta_query' => array(array('key' => '_thumbnail_id', 'compare' => 'EXISTS')),
        ));
    }

    private static function alt_for($post, $thumb) {
        $kw = trim((string) strtok((string) get_post_meta($post->ID, 'rank_math_focus_keyword', true), ','));
        $base = trim((string) get_post_meta($thumb, '_wp_attachment_image_alt', true));
        $base = trim((string) strtok($base, ','));
        if ($kw === '') {
            return $base !== '' ? $base : get_the_title($post);
        }
        if ($base === '' || stripos($base, $kw) !== false) {
            return $kw;
        }
        $alt = $kw . ' - ' . $base;
        return function_exists('mb_substr') ? mb_substr($alt, 0, 125) : substr($alt, 0, 125);
    }

    public static function plan() {
        $rows = array();
        foreach (self::candidates() as $id) {
            $post = get_post($id);
            $thumb = (int) get_post_thumbnail_id($id);
            $row = array('id' => (int) $id, 'title' => get_the_title($post), 'type' => $post->post_type, 'keyword' => '', 'alt' => '');
            $robots = (array) get_post_meta($id, 'rank_math_robots', true);
            if (!$thumb || !wp_get_attachment_image_url($thumb, 'large')) {
                $row['state'] = 'skipped: no usable featured image';
            } elseif (in_array('noindex', $robots, true)) {
                $row['state'] = 'skipped: noindex';
            } elseif (get_post_meta($id, '_elementor_data', true)) {
                $row['state'] = 'skipped: built with Elementor (add the image in the editor)';
            } elseif (strpos($post->post_content, self::MARK) !== false || stripos($post->post_content, '<img') !== false) {
                $row['state'] = 'skipped: content already has an image';
            } else {
                $row['keyword'] = trim((string) strtok((string) get_post_meta($id, 'rank_math_focus_keyword', true), ','));
                $row['alt'] = self::alt_for($post, $thumb);
                $row['state'] = 'ready';
            }
            $rows[] = $row;
        }
        return array('items' => $rows, 'ready' => count(array_filter($rows, function ($r) { return $r['state'] === 'ready'; })));
    }

    public static function apply($ids = array()) {
        $ids = array_map('absint', (array) $ids);
        $done = array();
        foreach (self::plan()['items'] as $row) {
            if ($row['state'] !== 'ready' || ($ids && !in_array($row['id'], $ids, true))) {
                continue;
            }
            $post = get_post($row['id']);
            if (!$post || !current_user_can('edit_post', $post->ID)) {
                continue;
            }
            $thumb = (int) get_post_thumbnail_id($post->ID);
            $src = wp_get_attachment_image_src($thumb, 'large');
            if (!$src) {
                continue;
            }
            $img = '<img src="' . esc_url($src[0]) . '" alt="' . esc_attr($row['alt']) . '" width="' . (int) $src[1] . '" height="' . (int) $src[2] . '" class="wp-image-' . $thumb . '" loading="lazy" />';
            $html = self::MARK . "\n" . '<figure class="wp-block-image size-large">' . $img . '</figure>' . "\n" . self::END . "\n";
            $content = (string) $post->post_content;
            $end = '<!-- /ssb-snippet:seo-intro -->';
            $pos = strpos($content, $end);
            if ($pos !== false) {
                $pos += strlen($end);
                $content = substr($content, 0, $pos) . "\n" . $html . ltrim(substr($content, $pos));
            } else {
                $content = $html . $content;
            }
            $res = wp_update_post(array('ID' => $post->ID, 'post_content' => wp_slash($content)), true);
            if (!is_wp_error($res)) {
                $done[] = array('id' => $post->ID, 'title' => $row['title'], 'alt' => $row['alt']);
            }
        }
        return array('applied' => $done);
    }
}
