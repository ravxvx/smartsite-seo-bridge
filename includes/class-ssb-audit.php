<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server-side SEO audit that mirrors the main Rank Math content tests.
 * The score is an ESTIMATE: Rank Math itself only calculates its score in the block/classic editor.
 */
final class SSB_Audit {
    const TARGET = 81; // Rank Math shows green above 80.

    private static function lc($s) {
        return function_exists('mb_strtolower') ? mb_strtolower((string) $s, 'UTF-8') : strtolower((string) $s);
    }

    private static function len($s) {
        return function_exists('mb_strlen') ? mb_strlen((string) $s, 'UTF-8') : strlen((string) $s);
    }

    private static function pos($hay, $needle) {
        $p = function_exists('mb_strpos') ? mb_strpos($hay, $needle, 0, 'UTF-8') : strpos($hay, $needle);
        return $p;
    }

    /** Rendered HTML of an item as visitors would see it (Elementor aware). */
    private static function html($post) {
        $html = '';
        if (class_exists('\\Elementor\\Plugin') && get_post_meta($post->ID, '_elementor_edit_mode', true) === 'builder') {
            try {
                $html = (string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($post->ID, true);
            } catch (\Throwable $e) {
                $html = '';
            }
        }
        if (trim($html) === '') {
            $GLOBALS['post'] = $post;
            setup_postdata($post);
            try {
                $html = (string) apply_filters('the_content', $post->post_content);
            } catch (\Throwable $e) {
                $html = (string) $post->post_content;
            }
            wp_reset_postdata();
        }
        return $html;
    }

    private static function other_uses_keyword($post_id, $kw) {
        $q = new WP_Query(array(
            'post_type' => 'any',
            'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
            'post__not_in' => array($post_id),
            'posts_per_page' => 30,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => array(array('key' => 'rank_math_focus_keyword', 'value' => $kw, 'compare' => 'LIKE')),
        ));
        foreach ($q->posts as $other) {
            $primary = trim(explode(',', (string) get_post_meta($other, 'rank_math_focus_keyword', true))[0]);
            if (self::lc($primary) === self::lc($kw)) {
                return true;
            }
        }
        return false;
    }

    public static function run($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return array('score' => 0, 'grade' => 'red', 'target' => self::TARGET, 'estimated' => true, 'checks' => array());
        }
        $all = (string) get_post_meta($post_id, 'rank_math_focus_keyword', true);
        $kw = trim(explode(',', $all)[0]);
        $seo_title = (string) get_post_meta($post_id, 'rank_math_title', true);
        $permalink = (string) get_permalink($post_id);
        $path = (string) wp_parse_url($permalink, PHP_URL_PATH);
        return self::analyze(array(
            'keyword' => $kw,
            'keyword_slug' => $kw === '' ? '' : sanitize_title($kw),
            'title' => $seo_title !== '' ? $seo_title : get_the_title($post_id),
            'title_is_custom' => $seo_title !== '',
            'description' => (string) get_post_meta($post_id, 'rank_math_description', true),
            'slug' => (string) $post->post_name,
            'path_length' => strlen($path),
            'html' => self::html($post),
            'site_host' => (string) wp_parse_url(home_url(), PHP_URL_HOST),
            'keyword_unique' => $kw === '' ? true : !self::other_uses_keyword($post_id, $kw),
            'rank_math_score' => (int) get_post_meta($post_id, 'rank_math_seo_score', true),
        ));
    }

    /** Pure analysis (no WordPress calls) so it can be unit-tested. */
    public static function analyze($in) {
        $kw = trim((string) $in['keyword']);
        $kwl = self::lc($kw);
        $has_kw = $kw !== '';

        $html = (string) preg_replace('#<(script|style|svg|noscript)\b[^>]*>.*?</\1>#is', '', (string) $in['html']);
        $spaced = (string) preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr|/td)\b[^>]*>#i', ' ', $html);
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($spaced), ENT_QUOTES, 'UTF-8')));
        $textl = self::lc($text);
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $text, $wm);
        $words = count($wm[0]);

        $occ = $has_kw ? substr_count($textl, $kwl) : 0;
        $kw_words = $has_kw ? max(1, count(preg_split('/\s+/u', $kw))) : 1;
        $density = ($words > 0 && $has_kw) ? round($occ * $kw_words / $words * 100, 2) : 0;

        $first = $has_kw ? self::pos($textl, $kwl) : false;
        $first_ok = $first !== false && $first <= max(0, self::len($text) * 0.1);

        $in_headings = false;
        if ($has_kw && preg_match_all('#<h[2-6]\b[^>]*>(.*?)</h[2-6]>#is', $html, $hm)) {
            foreach ($hm[1] as $h) {
                if (strpos(self::lc(strip_tags($h)), $kwl) !== false) {
                    $in_headings = true;
                    break;
                }
            }
        }

        $imgs = 0;
        $img_alt = false;
        if (preg_match_all('#<img\b[^>]*>#i', $html, $im)) {
            $imgs = count($im[0]);
            foreach ($im[0] as $tag) {
                if ($has_kw && preg_match('#\balt\s*=\s*(["\'])(.*?)\1#is', $tag, $am) && strpos(self::lc($am[2]), $kwl) !== false) {
                    $img_alt = true;
                }
            }
        }

        $internal = 0;
        $external = 0;
        $external_follow = 0;
        $host = preg_replace('#^www\.#', '', self::lc($in['site_host']));
        if (preg_match_all('#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>#is', $html, $lm, PREG_SET_ORDER)) {
            foreach ($lm as $m) {
                $href = trim($m[2]);
                if ($href === '' || $href[0] === '#' || stripos($href, 'mailto:') === 0 || stripos($href, 'tel:') === 0) {
                    continue;
                }
                $link_host = '';
                if (preg_match('#^(?:https?:)?//([^/:?\#]+)#i', $href, $hh)) {
                    $link_host = preg_replace('#^www\.#', '', self::lc($hh[1]));
                }
                if ($link_host === '' || $link_host === $host) {
                    $internal++;
                } else {
                    $external++;
                    if (stripos($m[0], 'nofollow') === false) {
                        $external_follow++;
                    }
                }
            }
        }

        $title = trim((string) $in['title']);
        $title_l = self::lc($title);
        $desc = trim((string) $in['description']);
        $slug = self::lc($in['slug']);
        $kslug = self::lc($in['keyword_slug']);
        $kw_in_slug = false;
        if ($has_kw && $kslug !== '') {
            $kw_in_slug = strpos($slug, $kslug) !== false;
            if (!$kw_in_slug) {
                $parts = array_filter(explode('-', $kslug));
                $kw_in_slug = $parts && count(array_filter($parts, function ($p) use ($slug) { return strpos($slug, $p) !== false; })) === count($parts);
            }
        }
        $tlen = self::len($title);
        $dlen = self::len($desc);

        $checks = array();
        $add = function ($id, $label, $status, $weight, $tip) use (&$checks) {
            $checks[] = array('id' => $id, 'label' => $label, 'status' => $status, 'weight' => $weight, 'tip' => $status === 'pass' ? '' : $tip);
        };
        $b = function ($ok) { return $ok ? 'pass' : 'fail'; };

        $add('kw_set', 'Focus keyword is set', $b($has_kw), 3, 'Set a focus keyword in Rank Math.');
        $add('kw_title', 'Focus keyword in SEO title', $b($has_kw && strpos($title_l, $kwl) !== false), 10, 'Put the focus keyword in the SEO title.');
        $add('kw_title_start', 'Focus keyword near the beginning of the title', $b($has_kw && ($p = self::pos($title_l, $kwl)) !== false && $p <= 10), 3, 'Move the keyword to the start of the SEO title.');
        $add('title_len', 'SEO title length (30-60 characters)', $b($tlen >= 30 && $tlen <= 60), 4, 'SEO title is ' . $tlen . ' characters; aim for 30-60.');
        $add('kw_desc', 'Focus keyword in meta description', $b($has_kw && $desc !== '' && strpos(self::lc($desc), $kwl) !== false), 8, 'Mention the focus keyword in the meta description.');
        $add('desc_len', 'Meta description length (100-160 characters)', $b($dlen >= 100 && $dlen <= 160), 4, 'Meta description is ' . $dlen . ' characters; aim for 100-160.');
        $add('kw_url', 'Focus keyword in URL', $b($kw_in_slug), 6, 'Include the focus keyword in the page slug.');
        $add('url_len', 'Short URL (under 75 characters)', $b((int) $in['path_length'] < 75), 3, 'Shorten the URL.');
        $add('kw_first', 'Focus keyword in the first 10% of the content', $b($first_ok), 8, 'Use the focus keyword in the opening paragraph.');
        $add('kw_content', 'Focus keyword appears in the content', $b($occ > 0), 8, 'Use the focus keyword in the text.');
        $len_status = $words >= 600 ? 'pass' : ($words >= 300 ? 'partial' : 'fail');
        $add('length', 'Content length (600+ words)', $len_status, 12, 'The page has ' . $words . ' words; aim for 600 or more.');
        $dens_status = ($density >= 0.5 && $density <= 2.5) ? 'pass' : ($density > 0 ? 'partial' : 'fail');
        $add('density', 'Keyword density 0.5-2.5%', $dens_status, 6, 'Keyword density is ' . $density . '%; use the keyword naturally about once per 100 words.');
        $add('kw_headings', 'Focus keyword in a subheading (H2-H6)', $b($in_headings), 6, 'Use the keyword in at least one H2/H3 heading.');
        $add('img', 'Page has at least one image (<img>)', $b($imgs > 0), 3, 'Add a real image. Inline SVG is not counted as an image by Rank Math.');
        $add('kw_alt', 'Focus keyword in an image alt text', $b($img_alt), 5, 'Add the keyword to an image alt text.');
        $add('internal', 'Internal link present', $b($internal > 0), 6, 'Link to another page of your site.');
        $add('external', 'External link present', $b($external > 0), 5, 'Link to a relevant external source.');
        $add('external_follow', 'At least one dofollow external link', $b($external_follow > 0), 2, 'Do not mark every external link as nofollow.');
        $add('unique_kw', 'Focus keyword not used on another page', $b(!empty($in['keyword_unique'])), 4, 'Another page already uses this focus keyword; pick a unique one.');

        $total = 0;
        $got = 0;
        foreach ($checks as $c) {
            $total += $c['weight'];
            $got += $c['status'] === 'pass' ? $c['weight'] : ($c['status'] === 'partial' ? $c['weight'] / 2 : 0);
        }
        $score = $has_kw && $total > 0 ? (int) round($got / $total * 100) : 0;

        return array(
            'estimated' => true,
            'score' => $score,
            'grade' => $score > 80 ? 'green' : ($score > 50 ? 'orange' : 'red'),
            'target' => self::TARGET,
            'keyword' => $kw,
            'words' => $words,
            'density' => $density,
            'title_is_custom' => !empty($in['title_is_custom']),
            'rank_math_score' => isset($in['rank_math_score']) ? (int) $in['rank_math_score'] : 0,
            'checks' => $checks,
        );
    }
}
