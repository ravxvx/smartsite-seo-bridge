<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Rank Math global settings with dry-run preview, automatic backups and restore. */
final class SSB_Settings {
    const BACKUPS = 'smartsite_seo_bridge_settings_backups';
    const MAX_BACKUPS = 30;

    /** group => option name. "modules" is read-only. */
    public static function groups() {
        return array(
            'titles' => 'rank-math-options-titles',
            'general' => 'rank-math-options-general',
            'sitemap' => 'rank-math-options-sitemap',
            'instant-indexing' => 'rank-math-options-instant-indexing',
            'modules' => 'rank_math_modules',
        );
    }

    private static function valid_value($value, $depth = 0) {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return true;
        }
        if (is_string($value)) {
            return strlen($value) <= 8000;
        }
        if (is_array($value) && $depth < 4 && count($value) <= 500) {
            foreach ($value as $k => $v) {
                if (!is_int($k) && !preg_match('/^[A-Za-z0-9_\-\.]{1,120}$/', (string) $k)) {
                    return false;
                }
                if (!self::valid_value($v, $depth + 1)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    private static function clean_value($value) {
        if (is_string($value)) {
            // Keep Rank Math variables (%date%, %category%) and basic markup; drop scripts.
            return wp_kses_post($value);
        }
        if (is_array($value)) {
            return array_map(array(__CLASS__, 'clean_value'), $value);
        }
        return $value;
    }

    public static function get($group) {
        $groups = self::groups();
        if (!isset($groups[$group])) {
            return new WP_Error('smartsite_seo_bad_group', 'Unknown settings group. Use: ' . implode(', ', array_keys($groups)) . '.', array('status' => 400));
        }
        $opt = get_option($groups[$group], array());
        return array('group' => $group, 'option' => $groups[$group], 'read_only' => $group === 'modules', 'values' => is_array($opt) ? $opt : array());
    }

    /**
     * Merges $values into a group. With $dry_run nothing is saved and the planned changes are returned.
     * Every real change first stores a backup of the previous option value.
     */
    public static function update($group, $values, $dry_run = false) {
        $groups = self::groups();
        if (!isset($groups[$group])) {
            return new WP_Error('smartsite_seo_bad_group', 'Unknown settings group.', array('status' => 400));
        }
        if ($group === 'modules') {
            return new WP_Error('smartsite_seo_read_only', 'Rank Math modules are read-only here: enable or disable them in Rank Math > Dashboard.', array('status' => 400));
        }
        if (!is_array($values) || !$values) {
            return new WP_Error('smartsite_seo_invalid_body', 'Send {"values": {...}} with the settings to change.', array('status' => 400));
        }
        foreach ($values as $k => $v) {
            if (!preg_match('/^[A-Za-z0-9_\-\.]{1,120}$/', (string) $k) || !self::valid_value($v)) {
                return new WP_Error('smartsite_seo_invalid_value', 'Invalid setting name or value: ' . sanitize_text_field((string) $k), array('status' => 400));
            }
        }
        $current = get_option($groups[$group], array());
        if (!is_array($current)) {
            $current = array();
        }
        $new = $current;
        $changes = array();
        foreach ($values as $k => $v) {
            $v = self::clean_value($v);
            $old = array_key_exists($k, $current) ? $current[$k] : null;
            if ($old !== $v) {
                $changes[$k] = array('from' => $old, 'to' => $v);
            }
            $new[$k] = $v;
        }
        if ($dry_run) {
            return array('dry_run' => true, 'group' => $group, 'changes' => $changes);
        }
        if ($changes) {
            self::backup($group, $current);
            update_option($groups[$group], $new);
        }
        return array('dry_run' => false, 'group' => $group, 'changes' => $changes, 'values' => $new);
    }

    private static function backup($group, $before) {
        $list = self::backups_raw();
        array_unshift($list, array('id' => wp_generate_uuid4(), 'time' => time(), 'user' => get_current_user_id(), 'group' => $group, 'before' => $before));
        update_option(self::BACKUPS, array_slice($list, 0, self::MAX_BACKUPS), false);
    }

    private static function backups_raw() {
        $list = get_option(self::BACKUPS, array());
        return is_array($list) ? $list : array();
    }

    public static function backups() {
        $out = array();
        foreach (self::backups_raw() as $b) {
            $user = get_userdata($b['user']);
            $out[] = array('id' => $b['id'], 'time' => gmdate('c', (int) $b['time']), 'user' => $user ? $user->user_login : '', 'group' => $b['group'], 'keys' => count($b['before']));
        }
        return $out;
    }

    public static function restore($id) {
        $groups = self::groups();
        foreach (self::backups_raw() as $b) {
            if ($b['id'] === $id && isset($groups[$b['group']]) && $b['group'] !== 'modules') {
                $current = get_option($groups[$b['group']], array());
                self::backup($b['group'], is_array($current) ? $current : array());
                update_option($groups[$b['group']], $b['before']);
                return self::get($b['group']);
            }
        }
        return new WP_Error('smartsite_seo_not_found', 'Backup not found.', array('status' => 404));
    }
}
