<?php
/**
 * Plugin Name: SmartSite Mobile Fixes
 * Description: Small front-end CSS fixes for single blog posts on phones (title size, featured image fit).
 * Version: 1.0.0
 * Author: SmartSite.ie
 * License: GPLv2 or later
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_head', function () {
    if (!is_singular('post')) {
        return;
    }
    ?>
<style id="smartsite-mobile-fixes">
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
</style>
    <?php
}, 99);
