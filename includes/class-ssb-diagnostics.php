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
}
