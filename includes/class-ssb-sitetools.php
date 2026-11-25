<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Narrow, administrator-only site tools: read the active theme's template files (read-only), read and
 * change Additional CSS with a history that can be undone, and read the tail of the PHP/WordPress error log.
 * There is deliberately no way to run code or to write files.
 */
final class SSB_SiteTools {
    const CSS_HISTORY = 'smartsite_seo_bridge_css_history';
    const MAX_FILE = 153600;
    const MAX_CSS = 102400;
    const EXT = array('php', 'css', 'js', 'json', 'html', 'txt', 'md');

    private static function roots() {
        $roots = array(realpath(get_stylesheet_directory()));
        $parent = realpath(get_template_directory());
        if ($parent && !in_array($parent, $roots, true)) {
            $roots[] = $parent;
        }
        return array_values(array_filter($roots));
    }

    public static function theme_files($limit = 400) {
        $out = array();
        foreach (self::roots() as $root) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
            foreach ($it as $f) {
                if (count($out) >= $limit) {
                    break 2;
                }
                if (!$f->isFile() || $f->isLink() || !in_array(strtolower($f->getExtension()), self::EXT, true)) {
                    continue;
                }
                $rel = ltrim(str_replace($root, '', $f->getPathname()), '/\\');
                if (preg_match('#(^|/)(node_modules|vendor|\.git)(/|$)#', $rel)) {
                    continue;
                }
                $out[] = array('theme' => basename($root), 'path' => $rel, 'size' => $f->getSize());
            }
        }
        return array('stylesheet' => get_stylesheet(), 'template' => get_template(), 'files' => $out);
    }

    public static function theme_file($theme, $path) {
        $path = ltrim(str_replace('\\', '/', (string) $path), '/');
        if ($path === '' || strpos($path, '..') !== false || !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXT, true)) {
            return new WP_Error('smartsite_seo_bad_path', 'File type or path not allowed.', array('status' => 400));
        }
        foreach (self::roots() as $root) {
            if ($theme !== '' && basename($root) !== $theme) {
                continue;
            }
            $full = realpath($root . '/' . $path);
            if ($full && strpos($full, $root . DIRECTORY_SEPARATOR) === 0 && is_file($full) && !is_link($root . '/' . $path)) {
                if (filesize($full) > self::MAX_FILE) {
                    return new WP_Error('smartsite_seo_too_big', 'File is larger than 150 KB.', array('status' => 413));
                }
                return array('theme' => basename($root), 'path' => $path, 'content' => (string) file_get_contents($full));
            }
        }
        return new WP_Error('smartsite_seo_not_found', 'File not found in the active theme.', array('status' => 404));
    }

    private static function history() {
        $h = get_option(self::CSS_HISTORY, array());
        return is_array($h) ? $h : array();
    }

    public static function css_get() {
        $hist = array_map(function ($e) {
            return array('id' => $e['id'], 'time' => $e['time'], 'bytes' => strlen($e['css']), 'note' => $e['note']);
        }, self::history());
        return array('css' => (string) wp_get_custom_css(), 'history' => array_values($hist));
    }

    public static function css_set($css, $note = '', $dry_run = false) {
        $css = (string) $css;
        if (strlen($css) > self::MAX_CSS) {
            return new WP_Error('smartsite_seo_too_big', 'CSS is larger than 100 KB.', array('status' => 413));
        }
        if (stripos($css, '</style') !== false || stripos($css, '<script') !== false) {
            return new WP_Error('smartsite_seo_bad_css', 'CSS must not contain HTML tags.', array('status' => 400));
        }
        $before = (string) wp_get_custom_css();
        if ($dry_run) {
            return array('dry_run' => true, 'changed' => $before !== $css, 'bytes_before' => strlen($before), 'bytes_after' => strlen($css));
        }
        if ($before !== $css) {
            $h = self::history();
            array_unshift($h, array('id' => wp_generate_uuid4(), 'time' => gmdate('c'), 'note' => sanitize_text_field($note), 'css' => $before));
            update_option(self::CSS_HISTORY, array_slice($h, 0, 20), false);
            $res = wp_update_custom_css_post($css);
            if (is_wp_error($res)) {
                return $res;
            }
        }
        return array('saved' => true, 'bytes' => strlen($css));
    }

    public static function css_undo($id) {
        foreach (self::history() as $e) {
            if ($e['id'] === $id) {
                return self::css_set($e['css'], 'undo to ' . $e['time']);
            }
        }
        return new WP_Error('smartsite_seo_not_found', 'History entry not found.', array('status' => 404));
    }

    public static function php_log($lines = 80) {
        $lines = max(1, min(300, (int) $lines));
        $candidates = array();
        if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '' && WP_DEBUG_LOG !== '1') {
            $candidates[] = WP_DEBUG_LOG;
        }
        $candidates[] = WP_CONTENT_DIR . '/debug.log';
        $ini = ini_get('error_log');
        if ($ini) {
            $candidates[] = $ini;
        }
        foreach ($candidates as $file) {
            if ($file && is_file($file) && is_readable($file)) {
                $size = filesize($file);
                $fh = fopen($file, 'rb');
                if (!$fh) {
                    continue;
                }
                fseek($fh, max(0, $size - 120000));
                $tail = (string) stream_get_contents($fh);
                fclose($fh);
                $rows = preg_split('/\r?\n/', rtrim($tail));
                return array('file' => basename($file), 'size' => $size, 'debug_log_enabled' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG, 'lines' => array_slice($rows, -$lines));
            }
        }
        return array('file' => null, 'debug_log_enabled' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG, 'lines' => array(), 'note' => 'No readable error log found. Enable WP_DEBUG_LOG to create wp-content/debug.log.');
    }
}
