<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Thin wrapper around the Rank Math Redirections module (must be enabled in Rank Math). */
final class SSB_Redirects {
    private static function available() {
        if (!class_exists('\\RankMath\\Redirections\\Redirection') || !class_exists('\\RankMath\\Redirections\\DB')) {
            return new WP_Error('smartsite_seo_redirects_unavailable', 'The Rank Math Redirections module is not active. Enable it in Rank Math > Dashboard.', array('status' => 409));
        }
        return true;
    }

    public static function list_all() {
        $ok = self::available();
        if (is_wp_error($ok)) {
            return $ok;
        }
        try {
            $res = \RankMath\Redirections\DB::get_redirections(array('limit' => 200));
            return array('count' => isset($res['count']) ? (int) $res['count'] : 0, 'redirections' => isset($res['redirections']) ? $res['redirections'] : array());
        } catch (\Throwable $e) {
            return new WP_Error('smartsite_seo_redirects_failed', 'Could not read redirects: ' . $e->getMessage(), array('status' => 500));
        }
    }

    /** $from is a path like /old-page/, $to a path or full URL. */
    public static function create($from, $to, $code = 301) {
        $ok = self::available();
        if (is_wp_error($ok)) {
            return $ok;
        }
        $from = '/' . ltrim(trim((string) wp_parse_url((string) $from, PHP_URL_PATH)), '/');
        $to = trim((string) $to);
        $code = (int) $code;
        if ($from === '/' || $to === '') {
            return new WP_Error('smartsite_seo_invalid_value', 'Provide a source path other than "/" and a destination.', array('status' => 400));
        }
        if (!in_array($code, array(301, 302, 307, 308, 410, 451), true)) {
            return new WP_Error('smartsite_seo_invalid_value', 'Unsupported redirect code.', array('status' => 400));
        }
        if ($to[0] === '/') {
            $to = home_url($to);
        }
        if (!wp_http_validate_url($to)) {
            return new WP_Error('smartsite_seo_invalid_url', 'Destination must be a valid URL or a path starting with /.', array('status' => 400));
        }
        try {
            $redirection = \RankMath\Redirections\Redirection::from(array(
                'sources' => array(array('pattern' => ltrim($from, '/'), 'comparison' => 'exact')),
                'url_to' => $to,
                'header_code' => $code,
                'status' => 'active',
            ));
            $id = $redirection->save();
            return array('created' => true, 'id' => (int) $id, 'from' => $from, 'to' => $to, 'code' => $code);
        } catch (\Throwable $e) {
            return new WP_Error('smartsite_seo_redirects_failed', 'Could not create redirect: ' . $e->getMessage(), array('status' => 500));
        }
    }
}
