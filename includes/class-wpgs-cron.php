<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPGS_Cron {
    const EVENT_HOOK = 'wpgs_refresh_all_lists';
    const SCHEDULE_KEY = 'wpgs_interval';
    const LOG_OPTION_KEY = 'wpgs_cron_logs';
    const LOG_RETENTION_SECONDS = DAY_IN_SECONDS;
    const LOG_MAX_ITEMS = 500;

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
            'display' => sprintf(__('WPGS every %d seconds', 'gamequery-servers-lists'), $interval),
        );

        return $schedules;
    }

    public function refresh_all_lists() {
        $started_at = microtime(true);
        $api_calls = 0;
        $chunk_count = 0;

        $list_ids = get_posts(
            array(
                'post_type' => WPGS_Lists::POST_TYPE,
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
            )
        );

        $list_total = is_array($list_ids) ? count($list_ids) : 0;

        if (!is_array($list_ids) || empty($list_ids)) {
            $this->log_refresh_result(
                'noop',
                'No published lists to refresh.',
                $started_at,
                array(
                    'list_total' => $list_total,
                    'api_calls' => $api_calls,
                    'chunk_count' => $chunk_count,
                    'list_refreshed' => 0,
                    'server_total' => 0,
                )
            );
            return;
        }

        $list_servers = array();
        $combined_servers = array();

        foreach ($list_ids as $list_id) {
            $list_id = absint($list_id);
            if ($list_id <= 0) {
                continue;
            }

            $groups = WPGS_Lists::get_groups($list_id);
            if (empty($groups)) {
                continue;
            }

            foreach ($groups as $group) {
                if (!is_array($group) || empty($group['game_id']) || empty($group['servers']) || !is_array($group['servers'])) {
                    continue;
                }

                $game_id = preg_replace('/[^a-zA-Z0-9_-]/', '', sanitize_text_field((string) $group['game_id']));
                if ('' === $game_id) {
                    continue;
                }

                if (!isset($combined_servers[$game_id]) || !is_array($combined_servers[$game_id])) {
                    $combined_servers[$game_id] = array();
                }

                foreach ($group['servers'] as $raw_server) {
                    $server = trim((string) $raw_server);
                    if ('' === $server || !WPGS_Lists::is_valid_server_address($server)) {
                        continue;
                    }

                    if (!isset($list_servers[$list_id]) || !is_array($list_servers[$list_id])) {
                        $list_servers[$list_id] = array();
                    }

                    $list_servers[$list_id][$server] = true;
                    $combined_servers[$game_id][$server] = true;
                }
            }
        }

        $list_with_servers = count($list_servers);
        $server_total = 0;
        foreach ($combined_servers as $servers_map) {
            if (!is_array($servers_map)) {
                continue;
            }

            $server_total += count($servers_map);
        }

        if (empty($list_servers) || empty($combined_servers)) {
            $this->log_refresh_result(
                'noop',
                'No valid servers found in published lists.',
                $started_at,
                array(
                    'list_total' => $list_total,
                    'list_with_servers' => $list_with_servers,
                    'api_calls' => $api_calls,
                    'chunk_count' => $chunk_count,
                    'list_refreshed' => 0,
                    'server_total' => $server_total,
                )
            );
            return;
        }

        $payload_groups = array();
        foreach ($combined_servers as $game_id => $servers_map) {
            if (!is_array($servers_map) || empty($servers_map)) {
                continue;
            }

            $servers = array_values(array_keys($servers_map));
            if (empty($servers)) {
                continue;
            }

            $payload_groups[] = array(
                'game_id' => (string) $game_id,
                'servers' => $servers,
            );
        }

        if (empty($payload_groups)) {
            $this->log_refresh_result(
                'noop',
                'No valid payload groups found for refresh.',
                $started_at,
                array(
                    'list_total' => $list_total,
                    'list_with_servers' => $list_with_servers,
                    'api_calls' => $api_calls,
                    'chunk_count' => $chunk_count,
                    'list_refreshed' => 0,
                    'server_total' => $server_total,
                )
            );
            return;
        }

        $chunks = $this->chunk_payload_groups($payload_groups, WPGS_API_Client::MAX_SERVERS_PER_REQUEST);
        $chunk_count = count($chunks);

        if (empty($chunks)) {
            $this->log_refresh_result(
                'noop',
                'No valid payload chunks were generated.',
                $started_at,
                array(
                    'list_total' => $list_total,
                    'list_with_servers' => $list_with_servers,
                    'api_calls' => $api_calls,
                    'chunk_count' => $chunk_count,
                    'list_refreshed' => 0,
                    'server_total' => $server_total,
                )
            );
            return;
        }

        $merged_payload = array();
        foreach ($chunks as $chunk_index => $chunk_groups) {
            $api_calls++;
            $result = $this->api_client->fetch_payload_for_groups(
                $chunk_groups,
                array(
                    'chunk_index' => (int) $chunk_index,
                    'chunk_count' => count($chunks),
                )
            );

            if (empty($result['success']) || !isset($result['data']) || !is_array($result['data'])) {
                $error_code = isset($result['error_code']) ? (string) $result['error_code'] : 'unknown_error';
                $error_message = isset($result['error_message']) ? (string) $result['error_message'] : 'Unknown API error';
                $status_code = isset($result['status_code']) ? (int) $result['status_code'] : 0;

                WPGS_Settings::set_last_api_error(
                    $error_code,
                    $error_message,
                    array(
                        'list_id' => 0,
                        'status_code' => $status_code,
                    )
                );

                $this->log_refresh_result(
                    'error',
                    'API refresh chunk failed.',
                    $started_at,
                    array(
                        'list_total' => $list_total,
                        'list_with_servers' => $list_with_servers,
                        'api_calls' => $api_calls,
                        'chunk_count' => $chunk_count,
                        'list_refreshed' => 0,
                        'server_total' => $server_total,
                        'error_code' => $error_code,
                        'error_message' => $error_message,
                        'status_code' => $status_code,
                    )
                );
                return;
            }

            foreach ($result['data'] as $server_address => $server_data) {
                $server_address = trim((string) $server_address);
                if ('' === $server_address || !is_array($server_data)) {
                    continue;
                }

                $merged_payload[$server_address] = $server_data;
            }
        }

        $list_refreshed = 0;
        $list_store_errors = 0;

        foreach ($list_servers as $list_id => $server_map) {
            if (!is_array($server_map) || empty($server_map)) {
                continue;
            }

            $list_payload = array();
            foreach (array_keys($server_map) as $server_address) {
                $server_address = (string) $server_address;
                if (isset($merged_payload[$server_address]) && is_array($merged_payload[$server_address])) {
                    $list_payload[$server_address] = $merged_payload[$server_address];
                }
            }

            $store_result = $this->api_client->store_list_cache_payload((int) $list_id, $list_payload, 200);
            if (!empty($store_result['success'])) {
                $list_refreshed++;
                continue;
            }

            $list_store_errors++;
        }

        if ($list_store_errors > 0) {
            $this->log_refresh_result(
                'partial',
                'Cron refresh completed with cache storage errors.',
                $started_at,
                array(
                    'list_total' => $list_total,
                    'list_with_servers' => $list_with_servers,
                    'api_calls' => $api_calls,
                    'chunk_count' => $chunk_count,
                    'list_refreshed' => $list_refreshed,
                    'list_errors' => $list_store_errors,
                    'server_total' => $server_total,
                )
            );
            return;
        }

        $this->log_refresh_result(
            'success',
            'Cron refresh completed successfully.',
            $started_at,
            array(
                'list_total' => $list_total,
                'list_with_servers' => $list_with_servers,
                'api_calls' => $api_calls,
                'chunk_count' => $chunk_count,
                'list_refreshed' => $list_refreshed,
                'server_total' => $server_total,
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $payload_groups
     * @param int $max_servers_per_chunk
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function chunk_payload_groups($payload_groups, $max_servers_per_chunk) {
        if (!is_array($payload_groups) || empty($payload_groups)) {
            return array();
        }

        $max_servers_per_chunk = max(1, (int) $max_servers_per_chunk);
        $chunks = array();
        $current_chunk = array();
        $current_count = 0;

        foreach ($payload_groups as $group) {
            if (!is_array($group) || empty($group['game_id']) || empty($group['servers']) || !is_array($group['servers'])) {
                continue;
            }

            $game_id = (string) $group['game_id'];
            $servers = array_values(array_filter($group['servers'], 'is_string'));
            $offset = 0;
            $server_total = count($servers);

            while ($offset < $server_total) {
                if ($current_count >= $max_servers_per_chunk) {
                    if (!empty($current_chunk)) {
                        $chunks[] = $current_chunk;
                    }

                    $current_chunk = array();
                    $current_count = 0;
                }

                $remaining = $max_servers_per_chunk - $current_count;
                if ($remaining <= 0) {
                    $remaining = $max_servers_per_chunk;
                }

                $slice = array_slice($servers, $offset, $remaining);
                if (empty($slice)) {
                    break;
                }

                $current_chunk[] = array(
                    'game_id' => $game_id,
                    'servers' => $slice,
                );

                $slice_count = count($slice);
                $current_count += $slice_count;
                $offset += $slice_count;
            }
        }

        if (!empty($current_chunk)) {
            $chunks[] = $current_chunk;
        }

        return $chunks;
    }

    /**
     * @param string $status
     * @param string $message
     * @param float $started_at
     * @param array<string, mixed> $context
     */
    private function log_refresh_result($status, $message, $started_at, $context = array()) {
        $duration_ms = (int) round((microtime(true) - (float) $started_at) * 1000);
        $context = is_array($context) ? $context : array();
        $context['duration_ms'] = max(0, $duration_ms);

        self::add_log($status, $message, $context);
    }

    /**
     * @param string $status
     * @param string $message
     * @param array<string, mixed> $context
     */
    public static function add_log($status, $message, $context = array()) {
        $context = is_array($context) ? $context : array();

        $entry = self::normalize_log_entry(
            array_merge(
                array(
                    'timestamp' => time(),
                    'recorded_at' => current_time('mysql', true),
                    'status' => $status,
                    'message' => $message,
                ),
                $context
            )
        );

        $logs = self::load_log_entries();
        $logs = self::prune_log_entries($logs, time());
        $logs[] = $entry;

        if (count($logs) > self::LOG_MAX_ITEMS) {
            $logs = array_slice($logs, -self::LOG_MAX_ITEMS);
        }

        self::save_log_entries($logs);
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public static function get_recent_logs($limit = 200) {
        $limit = max(1, absint($limit));
        $logs = self::load_log_entries();
        $pruned_logs = self::prune_log_entries($logs, time());

        if (count($pruned_logs) !== count($logs)) {
            self::save_log_entries($pruned_logs);
        }

        usort(
            $pruned_logs,
            static function ($left, $right) {
                $left_timestamp = isset($left['timestamp']) ? (int) $left['timestamp'] : 0;
                $right_timestamp = isset($right['timestamp']) ? (int) $right['timestamp'] : 0;

                return $right_timestamp <=> $left_timestamp;
            }
        );

        return array_slice($pruned_logs, 0, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function load_log_entries() {
        $raw = get_option(self::LOG_OPTION_KEY, array());
        if (!is_array($raw)) {
            return array();
        }

        $logs = array();
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $logs[] = self::normalize_log_entry($entry);
        }

        return $logs;
    }

    /**
     * @param array<int, array<string, mixed>> $logs
     */
    private static function save_log_entries($logs) {
        if (!is_array($logs)) {
            $logs = array();
        }

        update_option(self::LOG_OPTION_KEY, array_values($logs), false);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function normalize_log_entry($entry) {
        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
        $recorded_at = isset($entry['recorded_at']) ? sanitize_text_field((string) $entry['recorded_at']) : '';

        if ($timestamp <= 0 && '' !== $recorded_at) {
            $parsed_time = strtotime($recorded_at);
            $timestamp = false !== $parsed_time ? (int) $parsed_time : 0;
        }

        if ($timestamp <= 0) {
            $timestamp = time();
        }

        if ('' === $recorded_at) {
            $recorded_at = gmdate('Y-m-d H:i:s', $timestamp);
        }

        return array(
            'timestamp' => $timestamp,
            'recorded_at' => $recorded_at,
            'status' => self::normalize_log_status(isset($entry['status']) ? (string) $entry['status'] : ''),
            'message' => sanitize_text_field(isset($entry['message']) ? (string) $entry['message'] : ''),
            'api_calls' => max(0, isset($entry['api_calls']) ? (int) $entry['api_calls'] : 0),
            'chunk_count' => max(0, isset($entry['chunk_count']) ? (int) $entry['chunk_count'] : 0),
            'list_total' => max(0, isset($entry['list_total']) ? (int) $entry['list_total'] : 0),
            'list_with_servers' => max(0, isset($entry['list_with_servers']) ? (int) $entry['list_with_servers'] : 0),
            'list_refreshed' => max(0, isset($entry['list_refreshed']) ? (int) $entry['list_refreshed'] : 0),
            'list_errors' => max(0, isset($entry['list_errors']) ? (int) $entry['list_errors'] : 0),
            'server_total' => max(0, isset($entry['server_total']) ? (int) $entry['server_total'] : 0),
            'duration_ms' => max(0, isset($entry['duration_ms']) ? (int) $entry['duration_ms'] : 0),
            'error_code' => sanitize_key(isset($entry['error_code']) ? (string) $entry['error_code'] : ''),
            'error_message' => sanitize_text_field(isset($entry['error_message']) ? (string) $entry['error_message'] : ''),
            'status_code' => max(0, isset($entry['status_code']) ? (int) $entry['status_code'] : 0),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $logs
     * @param int $current_timestamp
     * @return array<int, array<string, mixed>>
     */
    private static function prune_log_entries($logs, $current_timestamp) {
        if (!is_array($logs) || empty($logs)) {
            return array();
        }

        $min_timestamp = max(0, (int) $current_timestamp - self::LOG_RETENTION_SECONDS);
        $filtered = array();

        foreach ($logs as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            if ($timestamp < $min_timestamp) {
                continue;
            }

            $filtered[] = self::normalize_log_entry($entry);
        }

        return $filtered;
    }

    /**
     * @param string $status
     * @return string
     */
    private static function normalize_log_status($status) {
        $status = sanitize_key((string) $status);
        $allowed = array('success', 'partial', 'error', 'noop');

        if (!in_array($status, $allowed, true)) {
            return 'noop';
        }

        return $status;
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
