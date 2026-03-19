<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPGS_Stats {
    const META_VIEWS_TOTAL = '_wpgs_stats_views_total';
    const META_VIEWS_UNIQUE = '_wpgs_stats_views_unique';
    const META_CLICKS_TOTAL = '_wpgs_stats_clicks_total';
    const META_CLICKS_UNIQUE = '_wpgs_stats_clicks_unique';
    const META_LAST_EVENT_AT = '_wpgs_stats_last_event_at';

    const COOKIE_NAME = 'wpgs_vid';

    public function register() {
        add_action('wp_ajax_wpgs_track_event', array($this, 'handle_track_event'));
        add_action('wp_ajax_nopriv_wpgs_track_event', array($this, 'handle_track_event'));
    }

    public function handle_track_event() {
        $nonce_valid = check_ajax_referer('wpgs_track_event', 'nonce', false);
        if (false === $nonce_valid) {
            wp_send_json_error(array('tracked' => false, 'reason' => 'invalid_nonce'), 403);
        }

        $list_id = isset($_POST['list_id']) ? absint(wp_unslash($_POST['list_id'])) : 0;
        $event = isset($_POST['event']) ? sanitize_key((string) wp_unslash($_POST['event'])) : '';

        $result = self::track_event($list_id, $event);
        wp_send_json_success($result);
    }

    /**
     * @param int $list_id
     * @param string $event
     * @return array<string, mixed>
     */
    public static function track_event($list_id, $event) {
        $list_id = absint($list_id);
        $event = sanitize_key($event);

        if ($list_id <= 0 || !in_array($event, array('view', 'click'), true)) {
            return array('tracked' => false);
        }

        $post = get_post($list_id);
        if (!$post || WPGS_Lists::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status) {
            return array('tracked' => false);
        }

        $stats_keys = self::event_meta_keys($event);
        $total_key = $stats_keys['total'];
        $unique_key = $stats_keys['unique'];

        $total = self::increment_meta_counter($list_id, $total_key);

        $is_unique = false;
        $visitor_id = self::get_or_create_visitor_id();
        if ('' !== $visitor_id) {
            $unique_transient_key = self::build_unique_transient_key($list_id, $event, $visitor_id);
            if (false === get_transient($unique_transient_key)) {
                set_transient($unique_transient_key, 1, DAY_IN_SECONDS);
                self::increment_meta_counter($list_id, $unique_key);
                $is_unique = true;
            }
        }

        update_post_meta($list_id, self::META_LAST_EVENT_AT, current_time('mysql', true));

        $stats = self::get_list_stats($list_id);
        $unique = 'click' === $event
            ? (int) $stats['clicks_unique']
            : (int) $stats['views_unique'];

        $campaign_state = array(
            'enabled' => false,
            'goal_reached' => false,
            'ended' => false,
            'ended_at' => '',
        );

        if (class_exists('WPGS_Lists') && method_exists('WPGS_Lists', 'evaluate_campaign_state')) {
            $campaign_state = WPGS_Lists::evaluate_campaign_state($list_id, $stats);
        }

        return array(
            'tracked' => true,
            'event' => $event,
            'list_id' => $list_id,
            'total' => $total,
            'unique' => $unique,
            'is_unique' => $is_unique,
            'campaign_ended' => !empty($campaign_state['ended']),
            'campaign_goal_reached' => !empty($campaign_state['goal_reached']),
        );
    }

    /**
     * @param int $list_id
     * @return array<string, int|string>
     */
    public static function get_list_stats($list_id) {
        $list_id = absint($list_id);

        $views_total = (int) get_post_meta($list_id, self::META_VIEWS_TOTAL, true);
        $views_unique = (int) get_post_meta($list_id, self::META_VIEWS_UNIQUE, true);
        $clicks_total = (int) get_post_meta($list_id, self::META_CLICKS_TOTAL, true);
        $clicks_unique = (int) get_post_meta($list_id, self::META_CLICKS_UNIQUE, true);
        $last_event = (string) get_post_meta($list_id, self::META_LAST_EVENT_AT, true);

        return array(
            'views_total' => max(0, $views_total),
            'views_unique' => max(0, $views_unique),
            'clicks_total' => max(0, $clicks_total),
            'clicks_unique' => max(0, $clicks_unique),
            'last_event' => $last_event,
        );
    }

    /**
     * @param string $event
     * @return array<string, string>
     */
    private static function event_meta_keys($event) {
        if ('click' === $event) {
            return array(
                'total' => self::META_CLICKS_TOTAL,
                'unique' => self::META_CLICKS_UNIQUE,
            );
        }

        return array(
            'total' => self::META_VIEWS_TOTAL,
            'unique' => self::META_VIEWS_UNIQUE,
        );
    }

    /**
     * @param int $list_id
     * @param string $meta_key
     * @return int
     */
    private static function increment_meta_counter($list_id, $meta_key) {
        $current = (int) get_post_meta($list_id, $meta_key, true);
        $next = max(0, $current) + 1;
        update_post_meta($list_id, $meta_key, $next);

        return $next;
    }

    /**
     * @return string
     */
    private static function get_or_create_visitor_id() {
        $cookie_value = filter_input(INPUT_COOKIE, self::COOKIE_NAME, FILTER_SANITIZE_SPECIAL_CHARS);
        $existing = self::sanitize_visitor_id(is_string($cookie_value) ? $cookie_value : '');

        if ('' !== $existing) {
            return $existing;
        }

        $visitor_id = self::sanitize_visitor_id((string) wp_generate_password(24, false, false));
        if ('' === $visitor_id) {
            return self::fallback_visitor_fingerprint();
        }

        if (!headers_sent()) {
            $path = '/';
            if (defined('COOKIEPATH') && COOKIEPATH) {
                $path = COOKIEPATH;
            }

            $domain = '';
            if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) {
                $domain = COOKIE_DOMAIN;
            }

            setcookie(
                self::COOKIE_NAME,
                $visitor_id,
                time() + YEAR_IN_SECONDS,
                $path,
                $domain,
                is_ssl(),
                true
            );
            $_COOKIE[self::COOKIE_NAME] = $visitor_id;
        }

        return $visitor_id;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function sanitize_visitor_id($value) {
        $value = preg_replace('/[^a-zA-Z0-9]/', '', trim($value));
        if (!is_string($value)) {
            return '';
        }

        $length = strlen($value);
        if ($length < 12 || $length > 64) {
            return '';
        }

        return $value;
    }

    /**
     * @return string
     */
    private static function fallback_visitor_fingerprint() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        if ('' === $ip && '' === $ua) {
            return '';
        }

        return md5($ip . '|' . $ua);
    }

    /**
     * @param int $list_id
     * @param string $event
     * @param string $visitor_id
     * @return string
     */
    private static function build_unique_transient_key($list_id, $event, $visitor_id) {
        return sprintf('wpgs_stats_u_%s_%d_%s', $event, absint($list_id), md5($visitor_id));
    }
}
