<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPGS_Cron {
    const EVENT_HOOK = 'wpgs_refresh_all_lists';
    const SCHEDULE_KEY = 'wpgs_interval';

    /**
     * @var WPGS_API_Client
     */
    private $api_client;

    /**
     * @param WPGS_API_Client $api_client
     */
    public function __construct($api_client) {
        $this->api_client = $api_client;
    }

    public function register() {
        add_filter('cron_schedules', array($this, 'register_schedule'));
        add_action(self::EVENT_HOOK, array($this, 'refresh_all_lists'));
    }

    /**
     * @param array<string, array<string, mixed>> $schedules
     * @return array<string, array<string, mixed>>
     */
    public function register_schedule($schedules) {
        $interval = WPGS_Settings::get_effective_cache_ttl();

        $schedules[self::SCHEDULE_KEY] = array(
            'interval' => $interval,
            /* translators: %d is refresh interval in seconds. */
            'display' => sprintf(__('WPGS every %d seconds', 'gamequery-server-lists'), $interval),
        );

        return $schedules;
    }

    public function refresh_all_lists() {
        $list_ids = get_posts(
            array(
                'post_type' => WPGS_Lists::POST_TYPE,
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
            )
        );

        if (!is_array($list_ids) || empty($list_ids)) {
            return;
        }

        foreach ($list_ids as $list_id) {
            $this->api_client->refresh_list_cache((int) $list_id);
        }
    }

    public static function activate() {
        self::ensure_scheduled_event();
    }

    public static function deactivate() {
        self::clear_scheduled_event();
    }

    public static function reschedule() {
        self::clear_scheduled_event();
        self::ensure_scheduled_event();
    }

    private static function ensure_scheduled_event() {
        if (!wp_next_scheduled(self::EVENT_HOOK)) {
            wp_schedule_event(time() + 15, self::SCHEDULE_KEY, self::EVENT_HOOK);
        }
    }

    private static function clear_scheduled_event() {
        $timestamp = wp_next_scheduled(self::EVENT_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::EVENT_HOOK);
            $timestamp = wp_next_scheduled(self::EVENT_HOOK);
        }
    }
}
