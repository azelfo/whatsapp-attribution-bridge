<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = (array) get_option('wab_settings', array());
if (empty($settings['delete_on_uninstall'])) {
    return;
}

global $wpdb;
wp_clear_scheduled_hook('wab_daily_cleanup');
wp_clear_scheduled_hook('wab_cleanup_batch');
wp_clear_scheduled_hook('wab_retry_pending');
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wab_attributions");
delete_option('wab_settings');
delete_option('wab_messages');
delete_option('wab_db_version');
