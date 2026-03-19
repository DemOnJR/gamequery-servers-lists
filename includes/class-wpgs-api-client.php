<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPGS_API_Client {
    const CACHE_INDEX_OPTION = 'wpgs_cache_index';

    /**
     * @param int $list_id
     * @return string
     */
    public function get_cache_key($list_id) {
        $list_id = absint($list_id);
        $settings = WPGS_Settings::get_settings();
        $groups = WPGS_Lists::get_groups($list_id);

        $hash_source = wp_json_encode(
            array(
                'list_id' => $list_id,
                'api_base_url' => isset($settings['api_base_url']) ? (string) $settings['api_base_url'] : '',
                'email' => isset($settings['email']) ? (string) $settings['email'] : '',
                'token' => isset($settings['token']) ? (string) $settings['token'] : '',
                'plan' => isset($settings['plan']) ? (string) $settings['plan'] : 'FREE',
                'groups' => $groups,
            )
        );

        return sprintf('wpgs_list_%d_hash_%s', $list_id, md5((string) $hash_source));
    }

    /**
     * @param int $list_id
     * @return array<string, mixed>|false
     */
    public function get_cached_payload($list_id) {
        $cache_key = $this->get_cache_key($list_id);
        $cached = get_transient($cache_key);
        return is_array($cached) ? $cached : false;
    }

    /**
     * @param int $list_id
     * @return array<string, mixed>
     */
    public function get_or_refresh_list_cache($list_id) {
        $cached = $this->get_cached_payload($list_id);
        if (false !== $cached) {
            return array(
                'success' => true,
                'source' => 'cache',
                'status_code' => 200,
                'data' => $cached,
            );
        }

        $result = $this->refresh_list_cache($list_id);
        if (!empty($result['success'])) {
            $result['source'] = 'live';
        }

        return $result;
    }

    /**
     * @param int $list_id
     * @return array<string, mixed>
     */
    public function refresh_list_cache($list_id) {
        $list_id = absint($list_id);
        $result = $this->request_list_payload($list_id);

        if (empty($result['success'])) {
            $this->log_error($list_id, $result);
            return $result;
        }

        $cache_key = $this->get_cache_key($list_id);
        $ttl = WPGS_Settings::get_effective_cache_ttl();

        set_transient($cache_key, $result['data'], $ttl);
        self::register_cache_key($list_id, $cache_key);
        WPGS_Settings::clear_last_api_error();

        return array(
            'success' => true,
            'status_code' => isset($result['status_code']) ? (int) $result['status_code'] : 200,
            'data' => $result['data'],
            'cache_key' => $cache_key,
            'cache_ttl' => $ttl,
        );
    }

    /**
     * @param int $list_id
     * @return array<string, mixed>
     */
    private function request_list_payload($list_id) {
        $list_id = absint($list_id);
        $settings = WPGS_Settings::get_settings();

        $email = isset($settings['email']) ? trim((string) $settings['email']) : '';
        $token = isset($settings['token']) ? trim((string) $settings['token']) : '';
        $plan = isset($settings['plan']) ? WPGS_Settings::normalize_plan((string) $settings['plan']) : 'FREE';
        $api_base_url = isset($settings['api_base_url']) ? untrailingslashit((string) $settings['api_base_url']) : '';

        if (empty($email) || empty($token)) {
            return $this->build_error_result(
                'missing_credentials',
                'WPGS settings are missing API credentials.',
                0,
                array('list_id' => $list_id)
            );
        }

        if (empty($api_base_url)) {
            return $this->build_error_result(
                'missing_api_base_url',
                'WPGS settings are missing API base URL.',
                0,
                array('list_id' => $list_id)
            );
        }

        $groups = WPGS_Lists::get_groups($list_id);
        $payload_groups = $this->normalize_groups_for_payload($groups);
        $server_count = WPGS_Lists::count_servers($payload_groups);

        if (empty($payload_groups)) {
            return $this->build_error_result(
                'empty_groups',
                'The list has no valid game groups or servers.',
                0,
                array('list_id' => $list_id)
            );
        }

        if ($server_count > 1000) {
            return $this->build_error_result(
                'server_limit_exceeded',
                'This list exceeds the 1000 servers per request limit.',
                0,
                array(
                    'list_id' => $list_id,
                    'server_count' => $server_count,
                )
            );
        }

        $response = wp_remote_post(
            $api_base_url . '/post/fetch',
            array(
                'timeout' => 20,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-API-Token' => $token,
                    'X-API-Token-Type' => $plan,
                    'X-API-Token-Email' => $email,
                ),
                'body' => wp_json_encode(
                    array(
                        'servers' => $payload_groups,
                    )
                ),
            )
        );

        if (is_wp_error($response)) {
            return $this->build_error_result(
                'request_failed',
                $response->get_error_message(),
                0,
                array('list_id' => $list_id)
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (429 === $status_code) {
            $message = 'Daily API quota exceeded.';
            if (is_array($decoded) && !empty($decoded['message'])) {
                $message = sanitize_text_field((string) $decoded['message']);
            }

            return $this->build_error_result(
                'quota_exceeded',
                $message,
                $status_code,
                array('list_id' => $list_id)
            );
        }

        if ($status_code < 200 || $status_code >= 300) {
            $message = 'Unexpected GameQuery API response.';
            if (is_array($decoded) && !empty($decoded['message'])) {
                $message = sanitize_text_field((string) $decoded['message']);
            }

            return $this->build_error_result(
                'api_http_error',
                $message,
                $status_code,
                array('list_id' => $list_id)
            );
        }

        if (!is_array($decoded)) {
            return $this->build_error_result(
                'invalid_response',
                'GameQuery API returned invalid JSON.',
                $status_code,
                array('list_id' => $list_id)
            );
        }

        return array(
            'success' => true,
            'status_code' => $status_code,
            'data' => $decoded,
        );
    }

    /**
     * @param array<int, mixed> $groups
     * @return array<int, array<string, mixed>>
     */
    private function normalize_groups_for_payload($groups) {
        $normalized = array();

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $game_id = isset($group['game_id']) ? sanitize_text_field((string) $group['game_id']) : '';
            $game_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $game_id);

            if (empty($game_id)) {
                continue;
            }

            $raw_servers = array();
            if (isset($group['servers']) && is_array($group['servers'])) {
                $raw_servers = $group['servers'];
            } elseif (isset($group['servers'])) {
                $raw_servers = preg_split('/[\r\n,]+/', (string) $group['servers']);
            }

            $servers = array();
            foreach ($raw_servers as $raw_server) {
                $server = trim((string) $raw_server);
                if (empty($server) || !WPGS_Lists::is_valid_server_address($server)) {
                    continue;
                }

                if (!in_array($server, $servers, true)) {
                    $servers[] = $server;
                }
            }

            if (empty($servers)) {
                continue;
            }

            $normalized[] = array(
                'game_id' => $game_id,
                'servers' => $servers,
            );
        }

        return $normalized;
    }

    /**
     * @param string $error_code
     * @param string $error_message
     * @param int $status_code
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function build_error_result($error_code, $error_message, $status_code = 0, $extra = array()) {
        return array_merge(
            array(
                'success' => false,
                'error_code' => sanitize_text_field((string) $error_code),
                'error_message' => sanitize_text_field((string) $error_message),
                'status_code' => (int) $status_code,
            ),
            $extra
        );
    }

    /**
     * @param int $list_id
     * @param array<string, mixed> $result
     */
    private function log_error($list_id, $result) {
        $error_code = isset($result['error_code']) ? (string) $result['error_code'] : 'unknown_error';
        $error_message = isset($result['error_message']) ? (string) $result['error_message'] : 'Unknown API error';
        $status_code = isset($result['status_code']) ? (int) $result['status_code'] : 0;

        WPGS_Settings::set_last_api_error(
            $error_code,
            $error_message,
            array(
                'list_id' => absint($list_id),
                'status_code' => $status_code,
            )
        );
    }

    /**
     * @param int $list_id
     */
    public static function clear_list_cache($list_id) {
        $list_id = absint($list_id);
        if ($list_id <= 0) {
            return;
        }

        $index = self::get_cache_index();
        $list_key = (string) $list_id;

        if (isset($index[$list_key]) && is_array($index[$list_key])) {
            foreach ($index[$list_key] as $cache_key) {
                delete_transient((string) $cache_key);
            }
        }

        unset($index[$list_key]);
        self::set_cache_index($index);
    }

    public static function clear_all_list_caches() {
        $index = self::get_cache_index();

        foreach ($index as $list_keys) {
            if (!is_array($list_keys)) {
                continue;
            }

            foreach ($list_keys as $cache_key) {
                delete_transient((string) $cache_key);
            }
        }

        delete_option(self::CACHE_INDEX_OPTION);
    }

    /**
     * @param int $list_id
     * @param string $cache_key
     */
    private static function register_cache_key($list_id, $cache_key) {
        $list_id = absint($list_id);
        $cache_key = self::sanitize_cache_key($cache_key);

        if ($list_id <= 0 || '' === $cache_key) {
            return;
        }

        $index = self::get_cache_index();
        $list_key = (string) $list_id;

        if (!isset($index[$list_key]) || !is_array($index[$list_key])) {
            $index[$list_key] = array();
        }

        if (!in_array($cache_key, $index[$list_key], true)) {
            $index[$list_key][] = $cache_key;
        }

        if (count($index[$list_key]) > 20) {
            $index[$list_key] = array_slice($index[$list_key], -20);
        }

        self::set_cache_index($index);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function get_cache_index() {
        $raw = get_option(self::CACHE_INDEX_OPTION, array());
        if (!is_array($raw)) {
            return array();
        }

        $index = array();
        foreach ($raw as $list_id => $cache_keys) {
            $list_key = (string) absint($list_id);
            if ('0' === $list_key || !is_array($cache_keys)) {
                continue;
            }

            $sanitized_keys = array();
            foreach ($cache_keys as $cache_key) {
                $sanitized_key = self::sanitize_cache_key($cache_key);
                if ('' === $sanitized_key || in_array($sanitized_key, $sanitized_keys, true)) {
                    continue;
                }

                $sanitized_keys[] = $sanitized_key;
            }

            if (!empty($sanitized_keys)) {
                $index[$list_key] = $sanitized_keys;
            }
        }

        return $index;
    }

    /**
     * @param array<string, array<int, string>> $index
     */
    private static function set_cache_index($index) {
        if (empty($index)) {
            delete_option(self::CACHE_INDEX_OPTION);
            return;
        }

        update_option(self::CACHE_INDEX_OPTION, $index, false);
    }

    /**
     * @param mixed $cache_key
     * @return string
     */
    private static function sanitize_cache_key($cache_key) {
        $cache_key = sanitize_key((string) $cache_key);
        if ('' === $cache_key || 0 !== strpos($cache_key, 'wpgs_list_')) {
            return '';
        }

        return $cache_key;
    }
}
