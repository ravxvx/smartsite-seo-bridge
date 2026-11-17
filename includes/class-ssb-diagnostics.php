<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Read-only check of contact-form e-mail: Fluent Forms notifications, FluentSMTP settings and recent mail logs. */
final class SSB_Diagnostics {
    private static function table($name) {
        global $wpdb;
        $t = $wpdb->prefix . $name;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t ? $t : '';
    }

    private static function pick($arr, $keys) {
        foreach ($keys as $k) {
            if (is_array($arr) && isset($arr[$k]) && $arr[$k] !== '') {
                return $arr[$k];
            }
        }
        return '';
    }

    private static function flat($v) {
        return is_array($v) ? wp_json_encode($v) : (string) $v;
    }

    public static function mail() {
        global $wpdb;
        $out = array(
            'site' => home_url(),
            'admin_email' => get_option('admin_email'),
            'fluent_forms_active' => defined('FLUENTFORM'),
            'fluent_smtp_active' => defined('FLUENTMAIL'),
            'forms' => array(),
            'smtp' => array(),
            'recent_mail' => array(),
            'findings' => array(),
        );

        $forms = self::table('fluentform_forms');
        $meta = self::table('fluentform_form_meta');
        $subs = self::table('fluentform_submissions');
        if (!$forms) {
            $out['findings'][] = 'Fluent Forms tables not found: the plugin is missing or inactive.';
        } else {
            foreach ((array) $wpdb->get_results("SELECT id, title, status FROM {$forms} ORDER BY id LIMIT 50") as $f) {
                $row = array('id' => (int) $f->id, 'title' => $f->title, 'status' => $f->status, 'entries' => $subs ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$subs} WHERE form_id = %d", $f->id)) : null, 'notifications' => array());
                if ($meta) {
                    $rows = $wpdb->get_results($wpdb->prepare("SELECT value FROM {$meta} WHERE form_id = %d AND meta_key = 'notifications'", $f->id));
                    foreach ((array) $rows as $m) {
                        $n = json_decode((string) $m->value, true);
                        if (!is_array($n)) {
                            continue;
                        }
                        $st = isset($n['status']) ? $n['status'] : (isset($n['enabled']) ? $n['enabled'] : null);
                        $send = isset($n['sendTo']) ? $n['sendTo'] : (isset($n['to']) ? $n['to'] : '');
                        $row['notifications'][] = array(
                            'name' => self::pick($n, array('name')),
                            'enabled' => $st === null ? 'unknown' : (in_array($st, array(true, 1, '1', 'true', 'yes', 'enabled', 'active'), true) ? 'yes' : 'NO'),
                            'send_to' => is_array($send) ? self::flat($send) : (string) $send,
                            'from_email' => self::flat(self::pick($n, array('fromEmail', 'from_email'))),
                            'reply_to' => self::flat(self::pick($n, array('replyTo', 'reply_to'))),
                            'subject' => self::flat(self::pick($n, array('subject'))),
                            'conditions' => !empty($n['conditionals']['status']) ? 'ON' : 'off',
                        );
                    }
                }
                if (!$row['notifications']) {
                    $out['findings'][] = 'Form #' . $f->id . ' "' . $f->title . '" has NO email notification, so it sends no mail when submitted. Add one in Fluent Forms > Settings & Integrations > Email Notifications.';
                } else {
                    foreach ($row['notifications'] as $n) {
                        if ($n['enabled'] === 'NO') {
                            $out['findings'][] = 'Form #' . $f->id . ': notification "' . $n['name'] . '" is switched off.';
                        }
                        if ($n['send_to'] === '' || $n['send_to'] === '[]') {
                            $out['findings'][] = 'Form #' . $f->id . ': notification "' . $n['name'] . '" has no recipient.';
                        }
                        if ($n['conditions'] === 'ON') {
                            $out['findings'][] = 'Form #' . $f->id . ': notification "' . $n['name'] . '" has conditional logic; it only sends when the condition matches.';
                        }
                    }
                }
                $out['forms'][] = $row;
            }
        }

        $s = get_option('fluentmail-settings');
        if (is_array($s)) {
            $conns = isset($s['connections']) && is_array($s['connections']) ? $s['connections'] : array();
            foreach ($conns as $key => $c) {
                $ps = isset($c['provider_settings']) ? $c['provider_settings'] : array();
                $out['smtp'][] = array(
                    'provider' => self::pick($c, array('provider')) ?: self::pick($ps, array('provider')),
                    'sender_email' => self::pick($ps, array('sender_email')),
                    'sender_name' => self::pick($ps, array('sender_name')),
                    'force_from_email' => self::pick($ps, array('force_from_email')),
                );
            }
            $misc = isset($s['misc']) && is_array($s['misc']) ? $s['misc'] : array();
            $out['smtp_default'] = self::pick($misc, array('default_connection'));
            if (!$conns) {
                $out['findings'][] = 'FluentSMTP has no saved connection.';
            }
        } elseif ($out['fluent_smtp_active']) {
            $out['findings'][] = 'FluentSMTP is active but has no saved settings.';
        }

        $logs = self::table('fluentmail_logs');
        if ($logs) {
            foreach ((array) $wpdb->get_results("SELECT id, subject, `to`, status, created_at FROM {$logs} ORDER BY id DESC LIMIT 10") as $l) {
                $out['recent_mail'][] = array('id' => (int) $l->id, 'subject' => $l->subject, 'to' => self::flat(maybe_unserialize($l->to)), 'status' => $l->status, 'time' => $l->created_at);
            }
        }
        if (!$out['findings']) {
            $out['findings'][] = 'No obvious problem found in the stored settings. Submit the form once and check FluentSMTP > Email Logs and the spam folder.';
        }
        return $out;
    }

    /* ------------------------------------------------- site-wide reports */

    private static function sec_list($title, $items) {
        return array('title' => $title, 'type' => 'list', 'items' => array_values($items));
    }

    private static function sec_table($title, $head, $rows) {
        return array('title' => $title, 'type' => 'table', 'head' => $head, 'rows' => array_values($rows));
    }

    private static function yn($v) {
        return $v ? 'yes' : 'no';
    }

    public static function report($kind) {
        switch ($kind) {
            case 'health':
                return self::health();
            case 'security':
                return self::security();
            case 'shop':
                return self::shop();
            case 'indexing':
                return self::indexing();
        }
        return new WP_Error('smartsite_seo_bad_report', 'Unknown report.', array('status' => 400));
    }

    private static function health() {
        $find = array();
        $info = array(
            array('PHP', PHP_VERSION), array('WordPress', get_bloginfo('version')), array('Site URL', home_url()),
            array('HTTPS', self::yn(is_ssl() || strpos(home_url(), 'https://') === 0)), array('Memory limit', ini_get('memory_limit')),
            array('Theme', wp_get_theme()->get('Name') . ' ' . wp_get_theme()->get('Version')), array('Timezone', wp_timezone_string()),
            array('Permalinks', get_option('permalink_structure') ?: 'plain (not recommended)'),
            array('WP_DEBUG', self::yn(defined('WP_DEBUG') && WP_DEBUG)), array('WP-Cron disabled', self::yn(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)),
        );
        if (version_compare(PHP_VERSION, '8.1', '<')) {
            $find[] = 'PHP ' . PHP_VERSION . ' is old; ask the host for 8.2 or newer.';
        }
        if (!get_option('permalink_structure')) {
            $find[] = 'Permalinks are plain; use "Post name".';
        }
        $overdue = 0;
        $cron = _get_cron_array();
        foreach ((array) $cron as $ts => $hooks) {
            if (is_numeric($ts) && $ts < time() - 3600) {
                $overdue += is_array($hooks) ? count($hooks) : 0;
            }
        }
        if ($overdue) {
            $find[] = $overdue . ' scheduled task(s) are more than an hour overdue: WP-Cron may not be running.';
        }
        $log = array();
        $file = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content') . '/debug.log';
        if (is_readable($file) && filesize($file) > 0) {
            $fp = fopen($file, 'rb');
            fseek($fp, max(0, filesize($file) - 6000));
            $tail = (string) fread($fp, 6000);
            fclose($fp);
            $log = array_slice(array_filter(array_map('trim', explode("\n", str_replace(ABSPATH, '/', $tail)))), -25);
        }
        return array('title' => 'Site health', 'sections' => array(
            self::sec_list('Findings', $find ?: array('Nothing obvious found.')),
            self::sec_table('Environment', array('Item', 'Value'), $info),
            self::sec_table('Scheduled tasks', array('Item', 'Value'), array(array('Overdue (> 1 h)', $overdue), array('Total events', is_array($cron) ? count($cron) : 0))),
            self::sec_list('End of debug.log (if any)', $log ?: array('No debug.log or it is empty.')),
        ));
    }

    private static function security() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $find = array();
        $all = get_plugins();
        $upd = get_site_transient('update_plugins');
        $upd = is_object($upd) && !empty($upd->response) ? $upd->response : array();
        $rows = array();
        foreach ($all as $file => $p) {
            $active = is_plugin_active($file);
            $new = isset($upd[$file]) ? $upd[$file]->new_version : '';
            $rows[] = array($p['Name'], $p['Version'], $active ? 'active' : 'inactive', $new ? 'update to ' . $new : '');
            if ($new && $active) {
                $find[] = $p['Name'] . ' ' . $p['Version'] . ' has an update (' . $new . ').';
            }
        }
        if (get_option('users_can_register')) {
            $find[] = 'Open user registration is ON (default role: ' . get_option('default_role') . '). Switch it off unless you need it.';
        }
        $admins = array();
        foreach (get_users(array('role' => 'administrator', 'fields' => array('ID', 'user_login', 'user_email', 'user_registered'))) as $u) {
            $admins[] = array($u->ID, $u->user_login, $u->user_email, $u->user_registered);
        }
        if (count($admins) > 2) {
            $find[] = count($admins) . ' administrator accounts; keep only those that are needed.';
        }
        foreach ($admins as $a) {
            if (in_array(strtolower($a[1]), array('admin', 'administrator'), true)) {
                $find[] = 'An administrator is called "' . $a[1] . '", a common guessing target; use two-factor sign-in.';
            }
        }
        if (!has_filter('xmlrpc_enabled', '__return_false') && apply_filters('xmlrpc_enabled', true)) {
            $find[] = 'XML-RPC is enabled; disable it if nothing uses it.';
        }
        return array('title' => 'Plugins and security', 'sections' => array(
            self::sec_list('Findings', $find ?: array('Nothing obvious found.')),
            self::sec_table('Plugins', array('Plugin', 'Version', 'State', 'Update'), $rows),
            self::sec_table('Administrators', array('ID', 'Login', 'E-mail', 'Registered'), $admins),
        ));
    }

    private static function shop() {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_page_id')) {
            return array('title' => 'Shop', 'sections' => array(self::sec_list('Findings', array('WooCommerce is not active.'))));
        }
        $find = array();
        $pages = array();
        foreach (array('shop', 'cart', 'checkout', 'myaccount', 'terms') as $k) {
            $id = (int) wc_get_page_id($k);
            $post = $id > 0 ? get_post($id) : null;
            $robots = $id > 0 ? get_post_meta($id, 'rank_math_robots', true) : '';
            $pages[] = array($k, $id > 0 ? $id : 'not set', $post ? $post->post_status : '', $post ? get_permalink($id) : '', is_array($robots) && in_array('noindex', $robots, true) ? 'noindex' : 'indexable');
            if ($id <= 0 && $k !== 'terms') {
                $find[] = 'WooCommerce "' . $k . '" page is not set.';
            }
        }
        $gw = array();
        $enabled = 0;
        foreach (WC()->payment_gateways()->payment_gateways() as $g) {
            $on = $g->enabled === 'yes';
            $enabled += $on ? 1 : 0;
            $gw[] = array($g->id, $g->get_title(), self::yn($on));
        }
        if (!$enabled) {
            $find[] = 'No payment gateway is enabled; customers cannot pay.';
        }
        $counts = (array) wp_count_posts('product');
        $prod = array();
        foreach ($counts as $st => $n) {
            if ((int) $n) {
                $prod[] = array($st, (int) $n);
            }
        }
        $mail = array(
            array('From address', get_option('woocommerce_email_from_address')), array('From name', get_option('woocommerce_email_from_name')),
            array('Currency', get_option('woocommerce_currency')), array('Store country', get_option('woocommerce_default_country')),
            array('Guest checkout', get_option('woocommerce_enable_guest_checkout')), array('Account registration at checkout', get_option('woocommerce_enable_signup_and_login_from_checkout')),
        );
        return array('title' => 'Shop', 'sections' => array(
            self::sec_list('Findings', $find ?: array('Nothing obvious found.')),
            self::sec_table('WooCommerce pages', array('Page', 'ID', 'Status', 'URL', 'Search'), $pages),
            self::sec_table('Payment gateways', array('ID', 'Title', 'Enabled'), $gw),
            self::sec_table('Products', array('Status', 'Count'), $prod),
            self::sec_table('Store settings', array('Setting', 'Value'), $mail),
        ));
    }

    private static function indexing() {
        global $wpdb;
        $find = array();
        if (!get_option('blog_public')) {
            $find[] = 'Search engines are discouraged (Settings > Reading). The site will not be indexed.';
        }
        $robots = apply_filters('robots_txt', "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n", (int) get_option('blog_public'));
        $sm = wp_remote_head(home_url('/sitemap_index.xml'), array('timeout' => 6, 'redirection' => 3));
        $code = is_wp_error($sm) ? $sm->get_error_message() : wp_remote_retrieve_response_code($sm);
        if ($code !== 200) {
            $find[] = 'The sitemap /sitemap_index.xml did not answer 200 (' . $code . ').';
        }
        $noindex = $wpdb->get_results("SELECT p.ID, p.post_title, p.post_type, p.post_status FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'rank_math_robots' WHERE m.meta_value LIKE '%noindex%' AND p.post_status IN ('publish','draft','private') LIMIT 100");
        $nrows = array();
        foreach ((array) $noindex as $n) {
            $nrows[] = array($n->ID, $n->post_title, $n->post_type, $n->post_status);
        }
        $redir = array();
        foreach (array('redirection_items' => 'Redirection plugin', 'rank_math_redirections' => 'Rank Math redirections') as $t => $label) {
            $tbl = self::table($t);
            $redir[] = array($label, $tbl ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tbl}") : 'not installed');
        }
        $mixed = 0;
        $lines = array_filter(array_map('trim', explode("\n", (string) $robots)));
        return array('title' => 'Indexing', 'sections' => array(
            self::sec_list('Findings', $find ?: array('Nothing obvious found.')),
            self::sec_list('robots.txt (as WordPress builds it)', $lines),
            self::sec_table('Sitemap and settings', array('Item', 'Value'), array(array('Sitemap /sitemap_index.xml', $code), array('Search engines allowed', self::yn(get_option('blog_public'))), array('Site language', get_locale()))),
            self::sec_table('Items marked noindex', array('ID', 'Title', 'Type', 'Status'), $nrows),
            self::sec_table('Redirects', array('Source', 'Rules'), $redir),
        ));
    }
}
