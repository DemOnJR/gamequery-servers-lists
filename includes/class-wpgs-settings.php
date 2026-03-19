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
     * @return float
     */
    public static function estimate_daily_calls() {
        $ttl = self::get_cache_ttl_setting();
        $list_count = self::get_published_list_count();

        if ($ttl <= 0 || $list_count <= 0) {
            return 0;
        }

        return (86400 / $ttl) * $list_count;
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
