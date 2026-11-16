<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rank Math schema (JSON-LD) stored as post meta "rank_math_schema_<Type>".
 * Supported types: FAQPage, Service, ProfessionalService, SoftwareApplication.
 */
final class SSB_Schema {
    const PREFIX = 'rank_math_schema_';

    public static function types() {
        return array('FAQPage', 'Service', 'ProfessionalService', 'SoftwareApplication');
    }

    public static function existing_keys($post_id) {
        $keys = array();
        foreach ((array) get_post_meta($post_id) as $k => $v) {
            if (strpos($k, self::PREFIX) === 0) {
                $keys[] = $k;
            }
        }
        return $keys;
    }

    /** Schema currently stored for an item: type => schema array. */
    public static function read($post_id) {
        $out = array();
        foreach (self::existing_keys($post_id) as $key) {
            $v = get_post_meta($post_id, $key, true);
            if (is_array($v)) {
                $out[substr($key, strlen(self::PREFIX))] = $v;
            }
        }
        return $out;
    }

    private static function text($v, $max = 500) {
        $v = SSB_Fields::clean_text($v);
        return SSB_Fields::strlen($v) > $max ? '' : $v;
    }

    private static function html($v) {
        return trim(wp_kses((string) $v, array('a' => array('href' => true), 'p' => array(), 'br' => array(), 'strong' => array(), 'em' => array(), 'ul' => array(), 'ol' => array(), 'li' => array())));
    }

    private static function metadata($post_id, $type) {
        return array('title' => $type, 'type' => 'custom', 'shortcode' => 's-' . substr(md5($post_id . '|' . $type), 0, 13), 'isPrimary' => false);
    }

    /**
     * Turns {"FAQPage": {...}, "Service": null} into meta-key => value|null (null deletes).
     * @return array|WP_Error
     */
    public static function build_changes($post_id, $input) {
        if (!is_array($input)) {
            return new WP_Error('smartsite_seo_invalid_value', 'schema must be an object keyed by type.', array('status' => 400));
        }
        $changes = array();
        foreach ($input as $type => $data) {
            if (!in_array($type, self::types(), true)) {
                return new WP_Error('smartsite_seo_invalid_value', 'Unsupported schema type: ' . sanitize_key((string) $type) . '. Allowed: ' . implode(', ', self::types()) . '.', array('status' => 400));
            }
            if ($data === null || $data === '' || $data === array()) {
                $changes[self::PREFIX . $type] = null;
                continue;
            }
            if (!is_array($data)) {
                return new WP_Error('smartsite_seo_invalid_value', $type . ' must be an object.', array('status' => 400));
            }
            $built = self::build($post_id, $type, $data);
            if (is_wp_error($built)) {
                return $built;
            }
            $changes[self::PREFIX . $type] = $built;
        }
        return $changes;
    }

    private static function build($post_id, $type, $d) {
        $schema = array('@type' => $type, 'metadata' => self::metadata($post_id, $type));
        $provider = array('@type' => 'Organization', 'name' => get_bloginfo('name'), 'url' => home_url('/'));

        if ($type === 'FAQPage') {
            $items = isset($d['questions']) && is_array($d['questions']) ? $d['questions'] : array();
            $main = array();
            foreach (array_slice($items, 0, 30) as $qa) {
                $q = is_array($qa) && isset($qa['question']) ? self::text($qa['question'], 300) : '';
                $a = is_array($qa) && isset($qa['answer']) ? self::html($qa['answer']) : '';
                if ($q === '' || $a === '') {
                    return new WP_Error('smartsite_seo_invalid_value', 'FAQPage needs questions: [{"question": "...", "answer": "..."}].', array('status' => 400));
                }
                $main[] = array('@type' => 'Question', 'name' => $q, 'acceptedAnswer' => array('@type' => 'Answer', 'text' => $a));
            }
            if (!$main) {
                return new WP_Error('smartsite_seo_invalid_value', 'FAQPage needs at least one question.', array('status' => 400));
            }
            $schema['mainEntity'] = $main;
            return $schema;
        }

        $name = isset($d['name']) ? self::text($d['name'], 200) : '';
        if ($name === '') {
            return new WP_Error('smartsite_seo_invalid_value', $type . ' needs a name.', array('status' => 400));
        }
        $schema['name'] = $name;
        if (!empty($d['description'])) {
            $schema['description'] = self::text($d['description'], 600);
        }

        if ($type === 'Service') {
            foreach (array('serviceType', 'areaServed') as $k) {
                if (!empty($d[$k])) {
                    $schema[$k] = self::text($d[$k], 200);
                }
            }
            $schema['provider'] = $provider;
        } elseif ($type === 'ProfessionalService') {
            $schema['url'] = !empty($d['url']) ? esc_url_raw($d['url']) : home_url('/');
            foreach (array('telephone', 'email', 'priceRange', 'areaServed') as $k) {
                if (!empty($d[$k])) {
                    $schema[$k] = self::text($d[$k], 120);
                }
            }
            if (!empty($d['image'])) {
                $schema['image'] = esc_url_raw($d['image']);
            }
            if (!empty($d['address']) && is_array($d['address'])) {
                $addr = array('@type' => 'PostalAddress');
                foreach (array('streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry') as $k) {
                    if (!empty($d['address'][$k])) {
                        $addr[$k] = self::text($d['address'][$k], 120);
                    }
                }
                $schema['address'] = $addr;
            }
        } elseif ($type === 'SoftwareApplication') {
            foreach (array('applicationCategory', 'operatingSystem') as $k) {
                if (!empty($d[$k])) {
                    $schema[$k] = self::text($d[$k], 120);
                }
            }
            if (!empty($d['url'])) {
                $schema['url'] = esc_url_raw($d['url']);
            }
            if (!empty($d['free'])) {
                $schema['offers'] = array('@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR');
            }
        }
        return $schema;
    }
}
