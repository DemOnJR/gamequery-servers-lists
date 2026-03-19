<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('wpgs_settings');
delete_option('wpgs_last_api_error');

$wpgs_timestamp = wp_next_scheduled('wpgs_refresh_all_lists');
while ($wpgs_timestamp) {
    wp_unschedule_event($wpgs_timestamp, 'wpgs_refresh_all_lists');
    $wpgs_timestamp = wp_next_scheduled('wpgs_refresh_all_lists');
}

$wpgs_cache_index = get_option('wpgs_cache_index', array());
if (is_array($wpgs_cache_index)) {
    foreach ($wpgs_cache_index as $wpgs_list_keys) {
        if (!is_array($wpgs_list_keys)) {
            continue;
        }

        foreach ($wpgs_list_keys as $wpgs_cache_key) {
            if (!is_string($wpgs_cache_key) || '' === $wpgs_cache_key) {
                continue;
            }

            delete_transient($wpgs_cache_key);
        }
    }
}

delete_option('wpgs_cache_index');
