<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
// Removes only this plugin's own bookkeeping. Rank Math data (post meta, settings) is left untouched.
delete_option('smartsite_seo_bridge_version');
delete_option('smartsite_seo_bridge_migrated');
delete_option('smartsite_seo_bridge_history');
delete_option('smartsite_seo_bridge_settings_backups');
