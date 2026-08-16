<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Remove runtime state for one site. Cloud objects, attachment metadata and
 * image-backend mappings stay intact so uninstall never destroys user media.
 */
function wpstow_uninstall_site()
{
    global $wpdb;

    foreach ([
        'wpstow_setting',
        'wpstow_rewrite_rules_version',
        'wpstow_legacy_migration_version',
        'wpstow_r2_migration_version',
        'wpstow_media_queue_db_version',
        'wpstow_delete_queue_db_version',
    ] as $option) {
        delete_option($option);
    }

    foreach ([
        'wpstow_run_media_queue',
        'wpstow_media_queue_watchdog',
        'wpstow_cloud_delete_watchdog',
        'wpstow_abort_direct_upload',
    ] as $hook) {
        wp_clear_scheduled_hook($hook);
    }

    $mediaJobs = $wpdb->prefix . 'wpstow_media_jobs';
    $deleteJobs = $wpdb->prefix . 'wpstow_delete_jobs';
    $wpdb->query("DROP TABLE IF EXISTS `{$mediaJobs}`");
    $wpdb->query("DROP TABLE IF EXISTS `{$deleteJobs}`");

    $transientPrefix = $wpdb->esc_like('_transient_wpstow_du_') . '%';
    $timeoutPrefix = $wpdb->esc_like('_transient_timeout_wpstow_du_') . '%';
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $transientPrefix,
        $timeoutPrefix
    ));
}

if (is_multisite()) {
    $siteIds = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ($siteIds as $siteId) {
        switch_to_blog((int) $siteId);
        wpstow_uninstall_site();
        restore_current_blog();
    }
} else {
    wpstow_uninstall_site();
}
