<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPGS_Settings {
    const OPTION_KEY = 'wpgs_settings';
    const LAST_API_ERROR_OPTION = 'wpgs_last_api_error';

    /**
     * @return array<string, mixed>
     */
    public static function get_defaults() {
        return array(
            'email' => '',
            'token' => '',
            'plan' => 'FREE',
            'account_base_url' => 'https://gamequery.dev',
            'api_base_url' => 'https://api.gamequery.dev/v1',
            'cache_ttl' => 60,
        );
    }

    public static function ensure_defaults() {
        $settings = get_option(self::OPTION_KEY, null);
        $defaults = self::get_defaults();

        if (!is_array($settings)) {
            add_option(self::OPTION_KEY, $defaults);
            return;
        }

        $normalized = wp_parse_args($settings, $defaults);
        if ($normalized !== $settings) {
            update_option(self::OPTION_KEY, $normalized);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_settings() {
        $settings = get_option(self::OPTION_KEY, array());
        if (!is_array($settings)) {
            $settings = array();
        }

        return wp_parse_args($settings, self::get_defaults());
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function sanitize_settings($raw) {
        $defaults = self::get_defaults();

        $email = isset($raw['email']) ? sanitize_email((string) $raw['email']) : '';
        $token = isset($raw['token']) ? sanitize_text_field((string) $raw['token']) : '';
        $plan = isset($raw['plan']) ? self::normalize_plan((string) $raw['plan']) : 'FREE';

        $account_base_url = isset($raw['account_base_url']) ? esc_url_raw((string) $raw['account_base_url']) : $defaults['account_base_url'];
        $account_base_url = untrailingslashit((string) $account_base_url);
        if (empty($account_base_url)) {
            $account_base_url = (string) $defaults['account_base_url'];
        }

        $api_base_url = isset($raw['api_base_url']) ? esc_url_raw((string) $raw['api_base_url']) : $defaults['api_base_url'];
        $api_base_url = untrailingslashit((string) $api_base_url);
        if (empty($api_base_url)) {
            $api_base_url = (string) $defaults['api_base_url'];
        }

        $cache_ttl = isset($raw['cache_ttl']) ? absint($raw['cache_ttl']) : 60;
        if ($cache_ttl < 10) {
            $cache_ttl = 10;
        }

        return array(
            'email' => $email,
            'token' => $token,
            'plan' => $plan,
            'account_base_url' => $account_base_url,
            'api_base_url' => $api_base_url,
            'cache_ttl' => $cache_ttl,
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function update_settings($raw) {
        $current = self::get_settings();
        $sanitized = self::sanitize_settings($raw);

        update_option(self::OPTION_KEY, $sanitized);

        $cache_affecting_keys = array('email', 'token', 'plan', 'api_base_url');
        $cache_affecting_changed = false;
        foreach ($cache_affecting_keys as $key) {
            $current_value = isset($current[$key]) ? (string) $current[$key] : '';
            $new_value = isset($sanitized[$key]) ? (string) $sanitized[$key] : '';
            if ($current_value !== $new_value) {
                $cache_affecting_changed = true;
                break;
            }
        }

        if ($cache_affecting_changed) {
            WPGS_API_Client::clear_all_list_caches();
            self::clear_last_api_error();
        }

        $previous_ttl = isset($current['cache_ttl']) ? absint($current['cache_ttl']) : 60;
        $next_ttl = isset($sanitized['cache_ttl']) ? absint($sanitized['cache_ttl']) : 60;
        $previous_plan = isset($current['plan']) ? self::normalize_plan((string) $current['plan']) : 'FREE';
        $next_plan = isset($sanitized['plan']) ? self::normalize_plan((string) $sanitized['plan']) : 'FREE';

        if ($previous_ttl !== $next_ttl || $previous_plan !== $next_plan) {
            WPGS_Cron::reschedule();
        }

        return $sanitized;
    }

    /**
     * @param string $plan
     * @return string
     */
    public static function normalize_plan($plan) {
        $normalized = strtoupper(trim($plan));
        return in_array($normalized, array('FREE', 'PRO'), true) ? $normalized : 'FREE';
    }

    /**
     * @return int
     */
    public static function get_cache_ttl_setting() {
        $settings = self::get_settings();
        $ttl = isset($settings['cache_ttl']) ? absint($settings['cache_ttl']) : 60;
        return max(10, $ttl);
    }

    /**
     * @return int
     */
    public static function get_effective_cache_ttl() {
        $settings = self::get_settings();
        $ttl = self::get_cache_ttl_setting();
        $plan = isset($settings['plan']) ? self::normalize_plan((string) $settings['plan']) : 'FREE';

        if ('FREE' === $plan && $ttl < 60) {
            return 60;
        }

        return $ttl;
    }

    /**
     * @return string
     */
    public static function get_account_base_url() {
        $settings = self::get_settings();
        $base_url = isset($settings['account_base_url']) ? untrailingslashit((string) $settings['account_base_url']) : 'https://gamequery.dev';
        if (empty($base_url)) {
            $base_url = 'https://gamequery.dev';
        }

        $filtered = apply_filters('wpgs_account_base_url', $base_url, $settings);
        $filtered = esc_url_raw((string) $filtered);
        $filtered = untrailingslashit((string) $filtered);

        return !empty($filtered) ? $filtered : $base_url;
    }

    /**
     * @return int
     */
    public static function get_published_list_count() {
        $counts = wp_count_posts('wpgs_list');
        if (!$counts || !isset($counts->publish)) {
            return 0;
        }

        return (int) $counts->publish;
    }

    /**
     * @return int
     */
    public static function get_published_server_count() {
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
            return 0;
        }

        $unique_servers = array();
        foreach ($list_ids as $list_id) {
            $groups = WPGS_Lists::get_groups((int) $list_id);
            foreach ($groups as $group) {
                if (!is_array($group) || empty($group['game_id']) || empty($group['servers']) || !is_array($group['servers'])) {
                    continue;
                }

                $game_id = preg_replace('/[^a-zA-Z0-9_-]/', '', sanitize_text_field((string) $group['game_id']));
                if ('' === $game_id) {
                    continue;
                }

                foreach ($group['servers'] as $raw_server) {
                    $server = trim((string) $raw_server);
                    if ('' === $server || !WPGS_Lists::is_valid_server_address($server)) {
                        continue;
                    }

                    $unique_servers[$game_id . '|' . $server] = true;
                }
            }
        }

        return count($unique_servers);
    }

    /**
     * @return int
     */
    public static function estimate_calls_per_refresh_cycle() {
        $server_count = self::get_published_server_count();
        if ($server_count <= 0) {
            return 0;
        }

        $max_servers = class_exists('WPGS_API_Client') ? (int) WPGS_API_Client::MAX_SERVERS_PER_REQUEST : 1000;
        $max_servers = max(1, $max_servers);

        return (int) ceil($server_count / $max_servers);
    }

    /**
     * @return float
     */
    public static function estimate_daily_calls() {
        $ttl = self::get_effective_cache_ttl();
        $calls_per_cycle = self::estimate_calls_per_refresh_cycle();

        if ($ttl <= 0 || $calls_per_cycle <= 0) {
            return 0;
        }

        return (86400 / $ttl) * $calls_per_cycle;
    }

    /**
     * @return bool
     */
    public static function should_warn_free_ttl() {
        $settings = self::get_settings();
        $plan = isset($settings['plan']) ? self::normalize_plan((string) $settings['plan']) : 'FREE';
        if ('FREE' !== $plan) {
            return false;
        }

        return self::get_cache_ttl_setting() < 60;
    }

    /**
     * @return bool
     */
    public static function should_warn_free_quota() {
        $settings = self::get_settings();
        $plan = isset($settings['plan']) ? self::normalize_plan((string) $settings['plan']) : 'FREE';
        if ('FREE' !== $plan) {
            return false;
        }

        return self::estimate_daily_calls() > 1440;
    }

    /**
     * @param string $error_code
     * @param string $error_message
     * @param array<string, mixed> $extra
     */
    public static function set_last_api_error($error_code, $error_message, $extra = array()) {
        $payload = array_merge(
            array(
                'error_code' => sanitize_text_field((string) $error_code),
                'error_message' => sanitize_text_field((string) $error_message),
                'occurred_at' => current_time('mysql', true),
            ),
            $extra
        );

        update_option(self::LAST_API_ERROR_OPTION, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_last_api_error() {
        $value = get_option(self::LAST_API_ERROR_OPTION, array());
        return is_array($value) ? $value : array();
    }

    public static function clear_last_api_error() {
        delete_option(self::LAST_API_ERROR_OPTION);
    }
}
