<?php
/**
 * Plugin Name: SmartSite Mobile Fixes
 * Description: One-time installer: appends the SmartSite mobile blog-post CSS to Appearance > Customize > Additional CSS (keeps existing CSS). After it ran, the plugin can be deleted.
 * Version: 1.1.0
 * Author: SmartSite.ie
 * License: GPLv2 or later
 */
if (!defined('ABSPATH')) {
    exit;
}

const SSMF_START = '/* ssmf:start - SmartSite mobile fixes */';
const SSMF_END = '/* ssmf:end */';
const SSMF_CSS = <<<'CSS'
body.single-post :is(main, article, .site-content, #content) img,
body.single-post :is(main, article, .site-content, #content) video{max-width:100% !important}
body.single-post :is(main, article, .site-content, #content) :is(img.wp-post-image, .post-thumbnail img, .featured-image img, figure img){
  width:100% !important;height:auto !important;object-fit:contain !important;aspect-ratio:auto !important;
}
body.single-post :is(.post-thumbnail, .featured-image, .entry-thumbnail, .post-image, figure):has(> img),
body.single-post :is(.post-thumbnail, .featured-image, .entry-thumbnail, .post-image):has(img){
  height:auto !important;max-height:none !important;max-width:100% !important;overflow:visible !important;
}
@media (max-width:768px){
  body.single-post :is(h1, .entry-title, .post-title){
    font-size:clamp(1.55rem,6.4vw,2.15rem) !important;line-height:1.22 !important;letter-spacing:0 !important;margin-bottom:16px !important;
  }
  body.single-post :is(.entry-meta, .post-meta, .posted-on, time){line-height:1.4 !important}
  body.single-post :is(main, article){padding-left:16px;padding-right:16px;box-sizing:border-box;max-width:100%;overflow-x:hidden}
  body.single-post :is(.entry-content, .post-content) :is(p, li){line-height:1.7 !important}
  body.single-post :is(.entry-content, .post-content) table{display:block;overflow-x:auto;max-width:100%}
  body.single-post :is(.entry-content, .post-content) pre{overflow-x:auto;max-width:100%}
}
CSS;

function ssmf_install() {
    if (get_option('ssmf_installed') === '1' || !function_exists('wp_get_custom_css')) {
        return;
    }
    $current = (string) wp_get_custom_css();
    if (strpos($current, SSMF_START) === false) {
        $new = rtrim($current) . "\n\n" . SSMF_START . "\n" . trim(SSMF_CSS) . "\n" . SSMF_END . "\n";
        $res = wp_update_custom_css_post($new);
        if (is_wp_error($res)) {
            return;
        }
    }
    update_option('ssmf_installed', '1', false);
}
add_action('init', 'ssmf_install', 20);
