<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPGS_Admin {
    const MENU_SLUG = 'wpgs';
    const LISTS_PAGE_SLUG = 'edit.php?post_type=wpgs_list';
    const SETTINGS_SLUG = 'wpgs-settings';
    const STATS_SLUG = 'wpgs-stats';
    const CONNECT_NONCE_ACTION = 'wpgs_connect_account';
    const CONNECT_TRANSIENT_PREFIX = 'wpgs_connect_session_';

    public function register() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'handle_stats_export'));
        add_action('admin_post_wpgs_save_settings', array($this, 'handle_save_settings'));
        add_action('admin_notices', array($this, 'render_admin_notices'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_wpgs_connect_init', array($this, 'handle_connect_init'));
        add_action('wp_ajax_wpgs_connect_poll', array($this, 'handle_connect_poll'));
    }

    public function register_menu() {
        add_menu_page(
            __('WPGS', 'gamequery-servers-lists'),
            __('WPGS', 'gamequery-servers-lists'),
            'manage_options',
            self::LISTS_PAGE_SLUG,
            '',
            'dashicons-list-view',
            25
        );

        add_submenu_page(
            self::LISTS_PAGE_SLUG,
            __('Lists', 'gamequery-servers-lists'),
            __('Lists', 'gamequery-servers-lists'),
            'manage_options',
            self::LISTS_PAGE_SLUG,
            ''
        );

        add_submenu_page(
            self::LISTS_PAGE_SLUG,
            __('Stats', 'gamequery-servers-lists'),
            __('Stats', 'gamequery-servers-lists'),
            'manage_options',
            self::STATS_SLUG,
            array($this, 'render_stats_page')
        );

        add_submenu_page(
            self::LISTS_PAGE_SLUG,
            __('Settings', 'gamequery-servers-lists'),
            __('Settings', 'gamequery-servers-lists'),
            'manage_options',
            self::SETTINGS_SLUG,
            array($this, 'render_settings_page')
        );
    }

    public function enqueue_admin_assets() {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $wpgs_screens = array(
            'edit-' . WPGS_Lists::POST_TYPE,
            WPGS_Lists::POST_TYPE,
            WPGS_Lists::POST_TYPE . '_page_' . self::SETTINGS_SLUG,
            WPGS_Lists::POST_TYPE . '_page_' . self::STATS_SLUG,
            self::MENU_SLUG . '_page_' . self::SETTINGS_SLUG,
            self::MENU_SLUG . '_page_' . self::STATS_SLUG,
        );

        if (!in_array($screen->id, $wpgs_screens, true)) {
            return;
        }

        $admin_css_path = WPGS_PLUGIN_DIR . 'assets/admin.css';
        $admin_js_path = WPGS_PLUGIN_DIR . 'assets/admin.js';
        $frontend_css_path = WPGS_PLUGIN_DIR . 'assets/frontend.css';
        $admin_css_version = file_exists($admin_css_path) ? (string) filemtime($admin_css_path) : WPGS_VERSION;
        $admin_js_version = file_exists($admin_js_path) ? (string) filemtime($admin_js_path) : WPGS_VERSION;
        $frontend_css_version = file_exists($frontend_css_path) ? (string) filemtime($frontend_css_path) : WPGS_VERSION;

        wp_enqueue_style(
            'wpgs-admin',
            WPGS_PLUGIN_URL . 'assets/admin.css',
            array(),
            $admin_css_version
        );

        wp_enqueue_style(
            'wpgs-frontend-preview',
            WPGS_PLUGIN_URL . 'assets/frontend.css',
            array(),
            $frontend_css_version
        );

        $settings_screens = array(
            WPGS_Lists::POST_TYPE . '_page_' . self::SETTINGS_SLUG,
            self::MENU_SLUG . '_page_' . self::SETTINGS_SLUG,
        );

        if (!in_array($screen->id, $settings_screens, true)) {
            return;
        }

        wp_enqueue_script(
            'wpgs-admin-settings',
            WPGS_PLUGIN_URL . 'assets/admin.js',
            array(),
            $admin_js_version,
            true
        );

        $account_base_url = WPGS_Settings::get_account_base_url();
        $account_origin = '';
        $account_scheme = wp_parse_url($account_base_url, PHP_URL_SCHEME);
        $account_host = wp_parse_url($account_base_url, PHP_URL_HOST);
        $account_port = wp_parse_url($account_base_url, PHP_URL_PORT);
        if (is_string($account_scheme) && is_string($account_host) && '' !== $account_scheme && '' !== $account_host) {
            $account_origin = strtolower($account_scheme . '://' . $account_host);
            if ((is_int($account_port) && $account_port > 0) || (is_string($account_port) && ctype_digit($account_port))) {
                $account_origin .= ':' . (string) $account_port;
            }
        }

        wp_add_inline_script(
            'wpgs-admin-settings',
            'window.WPGSConnect = ' . wp_json_encode(
                array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce(self::CONNECT_NONCE_ACTION),
                    'accountOrigin' => $account_origin,
                    'pollIntervalMs' => 2000,
                    'reloadDelayMs' => 1200,
                    'messages' => array(
                        'opening' => __('Opening GameQuery account connection...', 'gamequery-servers-lists'),
                        'waiting' => __('Waiting for your approval in the GameQuery popup...', 'gamequery-servers-lists'),
                        'connected' => __('Connected successfully. Reloading settings...', 'gamequery-servers-lists'),
                        'popupBlocked' => __('Popup blocked by your browser. Please allow popups and try again.', 'gamequery-servers-lists'),
                        'closed' => __('Connection window was closed before completion.', 'gamequery-servers-lists'),
                        'failed' => __('Connection failed. Please try again.', 'gamequery-servers-lists'),
                    ),
                )
            ) . ';',
            'before'
        );
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'gamequery-servers-lists'));
        }

        check_admin_referer('wpgs_save_settings', 'wpgs_settings_nonce');

        $raw_settings = array();
        if (isset($_POST['wpgs_settings']) && is_array($_POST['wpgs_settings'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in WPGS_Settings::sanitize_settings().
            $raw_settings = wp_unslash($_POST['wpgs_settings']);
        }

        $current_settings = WPGS_Settings::get_settings();
        $raw_settings['plan'] = isset($current_settings['plan']) ? (string) $current_settings['plan'] : 'FREE';

        WPGS_Settings::update_settings($raw_settings);

        set_transient(
            'wpgs_admin_notice_' . get_current_user_id(),
            array(
                'type' => 'success',
                'message' => __('WPGS settings saved.', 'gamequery-servers-lists'),
            ),
            60
        );

        wp_safe_redirect(admin_url('admin.php?page=' . self::SETTINGS_SLUG));
        exit;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = WPGS_Settings::get_settings();
        $active_tab = $this->get_settings_active_tab();
        $list_count = WPGS_Settings::get_published_list_count();
        $server_count = WPGS_Settings::get_published_server_count();
        $calls_per_cycle = WPGS_Settings::estimate_calls_per_refresh_cycle();
        $daily_calls = WPGS_Settings::estimate_daily_calls();
        $effective_ttl = WPGS_Settings::get_effective_cache_ttl();
        $last_error = WPGS_Settings::get_last_api_error();
        $has_connected_account = !empty($settings['email']) && !empty($settings['token']);
        $connected_summary = $has_connected_account
            ? sprintf(
                /* translators: 1: API email, 2: API plan */
                __('Currently connected as %1$s (%2$s).', 'gamequery-servers-lists'),
                (string) $settings['email'],
                (string) $settings['plan']
            )
            : __('No GameQuery account connected yet.', 'gamequery-servers-lists');

        ?>
        <div class="wrap wpgs-admin-page">
            <h1><?php echo esc_html__('WPGS Settings', 'gamequery-servers-lists'); ?></h1>
            <?php $this->render_settings_tabs_nav($active_tab); ?>

            <?php if ('logs' === $active_tab) : ?>
                <?php $this->render_settings_logs_tab(); ?>
                </div>
                <?php
                return;
                ?>
            <?php endif; ?>

            <div class="wpgs-settings-summary">
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Published Lists', 'gamequery-servers-lists'); ?></strong>
                    <span><?php echo esc_html((string) $list_count); ?></span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Configured Servers', 'gamequery-servers-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($server_count)); ?></span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Configured TTL', 'gamequery-servers-lists'); ?></strong>
                    <span><?php echo esc_html((string) WPGS_Settings::get_cache_ttl_setting()); ?>s</span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Effective Cron Interval', 'gamequery-servers-lists'); ?></strong>
                    <span><?php echo esc_html((string) $effective_ttl); ?>s</span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Calls / Refresh', 'gamequery-servers-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($calls_per_cycle)); ?></span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Estimated Calls / Day', 'gamequery-servers-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n((int) round($daily_calls))); ?></span>
                </div>
            </div>

            <div class="wpgs-connect-panel" id="wpgs-connect-panel">
                <h2><?php echo esc_html__('Connect GameQuery Account', 'gamequery-servers-lists'); ?></h2>
                <p class="description"><?php echo esc_html__('Use the secure popup flow to select an existing API key without copy/pasting credentials.', 'gamequery-servers-lists'); ?></p>
                <p class="wpgs-connect-current<?php echo $has_connected_account ? ' is-connected' : ''; ?>"><?php echo esc_html($connected_summary); ?></p>
                <div class="wpgs-connect-actions">
                    <?php if ($has_connected_account) : ?>
                        <span class="wpgs-connect-badge" aria-label="<?php echo esc_attr__('Connected to a GameQuery key', 'gamequery-servers-lists'); ?>">
                            <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                            <?php echo esc_html__('Connected', 'gamequery-servers-lists'); ?>
                        </span>
                    <?php endif; ?>
                    <button type="button" class="button button-primary" id="wpgs-connect-button"><?php echo esc_html__('Connect with GameQuery', 'gamequery-servers-lists'); ?></button>
                    <span class="spinner" id="wpgs-connect-spinner" aria-hidden="true"></span>
                </div>
                <p id="wpgs-connect-status" class="description" role="status" aria-live="polite"></p>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wpgs-settings-form">
                <input type="hidden" name="action" value="wpgs_save_settings" />
                <?php wp_nonce_field('wpgs_save_settings', 'wpgs_settings_nonce'); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="wpgs_email"><?php echo esc_html__('API Email', 'gamequery-servers-lists'); ?></label></th>
                            <td>
                                <input
                                    type="email"
                                    id="wpgs_email"
                                    name="wpgs_settings[email]"
                                    value="<?php echo esc_attr((string) $settings['email']); ?>"
                                    class="regular-text"
                                    autocomplete="off"
                                />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpgs_token"><?php echo esc_html__('API Token', 'gamequery-servers-lists'); ?></label></th>
                            <td>
                                <input
                                    type="password"
                                    id="wpgs_token"
                                    name="wpgs_settings[token]"
                                    value="<?php echo esc_attr((string) $settings['token']); ?>"
                                    class="regular-text"
                                    autocomplete="off"
                                />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpgs_plan"><?php echo esc_html__('Plan Type', 'gamequery-servers-lists'); ?></label></th>
                            <td>
                                <strong id="wpgs_plan"><?php echo esc_html((string) $settings['plan']); ?></strong>
                                <p class="description"><?php echo esc_html__('Plan is auto-detected from your connected API key and cannot be changed manually. Reconnect if you switch keys.', 'gamequery-servers-lists'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpgs_account_base_url"><?php echo esc_html__('Account Base URL', 'gamequery-servers-lists'); ?></label></th>
                            <td>
                                <input
                                    type="url"
                                    id="wpgs_account_base_url"
                                    name="wpgs_settings[account_base_url]"
                                    value="<?php echo esc_attr((string) $settings['account_base_url']); ?>"
                                    class="regular-text code"
                                    placeholder="https://gamequery.dev"
                                />
                                <p class="description"><?php echo esc_html__('Used by the one-click account connect flow. Leave as default unless support asks you to change it.', 'gamequery-servers-lists'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpgs_api_base_url"><?php echo esc_html__('API Base URL', 'gamequery-servers-lists'); ?></label></th>
                            <td>
                                <input
                                    type="url"
                                    id="wpgs_api_base_url"
                                    name="wpgs_settings[api_base_url]"
                                    value="<?php echo esc_attr((string) $settings['api_base_url']); ?>"
                                    class="regular-text code"
                                    placeholder="https://api.gamequery.dev/v1"
                                />
                                <p class="description"><?php echo esc_html__('The plugin calls POST /post/fetch against this base URL.', 'gamequery-servers-lists'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpgs_cache_ttl"><?php echo esc_html__('Cache TTL (seconds)', 'gamequery-servers-lists'); ?></label></th>
                            <td>
                                <input
                                    type="number"
                                    min="10"
                                    step="1"
                                    id="wpgs_cache_ttl"
                                    name="wpgs_settings[cache_ttl]"
                                    value="<?php echo esc_attr((string) $settings['cache_ttl']); ?>"
                                    class="small-text"
                                />
                                <p class="description"><?php echo esc_html__('Used for transient expiry and cron refresh interval. FREE plan uses a minimum effective interval of 60 seconds.', 'gamequery-servers-lists'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Save Settings', 'gamequery-servers-lists')); ?>
            </form>

            <?php if (!empty($last_error)) : ?>
                <div class="wpgs-last-error">
                    <h2><?php echo esc_html__('Last API Error', 'gamequery-servers-lists'); ?></h2>
                    <p><strong><?php echo esc_html__('Code:', 'gamequery-servers-lists'); ?></strong> <code><?php echo esc_html((string) $last_error['error_code']); ?></code></p>
                    <p><strong><?php echo esc_html__('Message:', 'gamequery-servers-lists'); ?></strong> <?php echo esc_html((string) $last_error['error_message']); ?></p>
                    <?php if (!empty($last_error['status_code'])) : ?>
                        <p><strong><?php echo esc_html__('HTTP Status:', 'gamequery-servers-lists'); ?></strong> <?php echo esc_html((string) $last_error['status_code']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($last_error['list_id'])) : ?>
                        <p><strong><?php echo esc_html__('List ID:', 'gamequery-servers-lists'); ?></strong> <?php echo esc_html((string) $last_error['list_id']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($last_error['occurred_at'])) : ?>
                        <p><strong><?php echo esc_html__('Occurred At (UTC):', 'gamequery-servers-lists'); ?></strong> <?php echo esc_html((string) $last_error['occurred_at']); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @return string
     */
    private function get_settings_active_tab() {
        $tab = filter_input(INPUT_GET, 'tab', FILTER_UNSAFE_RAW);
        $tab = is_string($tab) ? sanitize_key($tab) : '';

        return 'logs' === $tab ? 'logs' : 'general';
    }

    /**
     * @param string $active_tab
     */
    private function render_settings_tabs_nav($active_tab) {
        $active_tab = 'logs' === $active_tab ? 'logs' : 'general';

        $tabs = array(
            'general' => __('Settings', 'gamequery-servers-lists'),
            'logs' => __('Logs', 'gamequery-servers-lists'),
        );

        echo '<nav class="nav-tab-wrapper wpgs-nav-tabs">';
        foreach ($tabs as $tab_key => $tab_label) {
            $tab_url = add_query_arg(
                array(
                    'page' => self::SETTINGS_SLUG,
                    'tab' => $tab_key,
                ),
                admin_url('admin.php')
            );

            $classes = 'nav-tab';
            if ($active_tab === $tab_key) {
                $classes .= ' nav-tab-active';
            }

            echo '<a class="' . esc_attr($classes) . '" href="' . esc_url($tab_url) . '">' . esc_html($tab_label) . '</a>';
        }
        echo '</nav>';
    }

    private function render_settings_logs_tab() {
        $logs = WPGS_Cron::get_recent_logs(300);

        $summary = array(
            'total' => count($logs),
            'success' => 0,
            'partial' => 0,
            'error' => 0,
            'noop' => 0,
            'api_calls' => 0,
            'last_run' => '',
        );

        foreach ($logs as $index => $log) {
            if (!is_array($log)) {
                continue;
            }

            $status = isset($log['status']) ? sanitize_key((string) $log['status']) : 'noop';
            if (isset($summary[$status])) {
                $summary[$status]++;
            }

            $summary['api_calls'] += isset($log['api_calls']) ? max(0, (int) $log['api_calls']) : 0;

            if (0 === $index && !empty($log['recorded_at'])) {
                $summary['last_run'] = (string) $log['recorded_at'];
            }
        }

        ?>
        <p class="description wpgs-log-note"><?php echo esc_html__('This tab shows only the last 24 hours of cron refresh activity. Older log entries are automatically removed.', 'gamequery-servers-lists'); ?></p>

        <div class="wpgs-settings-summary">
            <div class="wpgs-summary-item">
                <strong><?php echo esc_html__('Runs (24h)', 'gamequery-servers-lists'); ?></strong>
                <span><?php echo esc_html(number_format_i18n((int) $summary['total'])); ?></span>
            </div>
            <div class="wpgs-summary-item">
                <strong><?php echo esc_html__('Successful', 'gamequery-servers-lists'); ?></strong>
                <span><?php echo esc_html(number_format_i18n((int) $summary['success'])); ?></span>
            </div>
            <div class="wpgs-summary-item">
                <strong><?php echo esc_html__('With Errors', 'gamequery-servers-lists'); ?></strong>
                <span><?php echo esc_html(number_format_i18n((int) $summary['error'] + (int) $summary['partial'])); ?></span>
            </div>
            <div class="wpgs-summary-item">
                <strong><?php echo esc_html__('API Calls (24h)', 'gamequery-servers-lists'); ?></strong>
                <span><?php echo esc_html(number_format_i18n((int) $summary['api_calls'])); ?></span>
            </div>
            <div class="wpgs-summary-item">
                <strong><?php echo esc_html__('Last Run (UTC)', 'gamequery-servers-lists'); ?></strong>
                <span class="wpgs-summary-text"><?php echo '' !== $summary['last_run'] ? esc_html($summary['last_run']) : '—'; ?></span>
            </div>
        </div>

        <table class="widefat striped wpgs-log-table">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Time (UTC)', 'gamequery-servers-lists'); ?></th>
                    <th><?php echo esc_html__('Status', 'gamequery-servers-lists'); ?></th>
                    <th><?php echo esc_html__('API Calls', 'gamequery-servers-lists'); ?></th>
                    <th><?php echo esc_html__('Lists', 'gamequery-servers-lists'); ?></th>
                    <th><?php echo esc_html__('Servers', 'gamequery-servers-lists'); ?></th>
                    <th><?php echo esc_html__('Chunks', 'gamequery-servers-lists'); ?></th>
                    <th><?php echo esc_html__('Duration', 'gamequery-servers-lists'); ?></th>
                    <th><?php echo esc_html__('Message', 'gamequery-servers-lists'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)) : ?>
                    <tr>
                        <td colspan="8"><?php echo esc_html__('No cron logs in the last 24 hours yet.', 'gamequery-servers-lists'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($logs as $log) : ?>
                        <?php
                        if (!is_array($log)) {
                            continue;
                        }

                        $status = isset($log['status']) ? sanitize_key((string) $log['status']) : 'noop';
                        $status_class = 'wpgs-log-status wpgs-log-status-' . sanitize_html_class($status);
                        $status_label = $this->cron_log_status_label($status);
                        $message = isset($log['message']) ? (string) $log['message'] : '';
                        $error_code = isset($log['error_code']) ? (string) $log['error_code'] : '';
                        if ('' !== $error_code) {
                            $message .= ' [' . $error_code . ']';
                        }

                        $recorded_at = isset($log['recorded_at']) ? (string) $log['recorded_at'] : '';
                        $api_calls = isset($log['api_calls']) ? max(0, (int) $log['api_calls']) : 0;
                        $list_refreshed = isset($log['list_refreshed']) ? max(0, (int) $log['list_refreshed']) : 0;
                        $server_total = isset($log['server_total']) ? max(0, (int) $log['server_total']) : 0;
                        $chunk_count = isset($log['chunk_count']) ? max(0, (int) $log['chunk_count']) : 0;
                        $duration_ms = isset($log['duration_ms']) ? max(0, (int) $log['duration_ms']) : 0;
                        ?>
                        <tr>
                            <td><?php echo '' !== $recorded_at ? esc_html($recorded_at) : '—'; ?></td>
                            <td><span class="<?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span></td>
                            <td><?php echo esc_html(number_format_i18n($api_calls)); ?></td>
                            <td><?php echo esc_html(number_format_i18n($list_refreshed)); ?></td>
                            <td><?php echo esc_html(number_format_i18n($server_total)); ?></td>
                            <td><?php echo esc_html(number_format_i18n($chunk_count)); ?></td>
                            <td><?php echo esc_html(number_format_i18n($duration_ms)); ?>ms</td>
                            <td><?php echo '' !== trim($message) ? esc_html($message) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * @param string $status
     * @return string
     */
    private function cron_log_status_label($status) {
        if ('success' === $status) {
            return __('Success', 'gamequery-servers-lists');
        }

        if ('partial' === $status) {
            return __('Partial', 'gamequery-servers-lists');
        }

        if ('error' === $status) {
            return __('Error', 'gamequery-servers-lists');
        }

        return __('No-op', 'gamequery-servers-lists');
    }

    public function handle_stats_export() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $page = filter_input(INPUT_GET, 'page', FILTER_UNSAFE_RAW);
        $post_type = filter_input(INPUT_GET, 'post_type', FILTER_UNSAFE_RAW);
        $format = filter_input(INPUT_GET, 'wpgs_export', FILTER_UNSAFE_RAW);

        $page = is_string($page) ? sanitize_key($page) : '';
        $post_type = is_string($post_type) ? sanitize_key($post_type) : '';
        $format = is_string($format) ? sanitize_key($format) : '';

        if (self::STATS_SLUG !== $page || WPGS_Lists::POST_TYPE !== $post_type || '' === $format) {
            return;
        }

        $raw_list_id = filter_input(INPUT_GET, 'list_id', FILTER_SANITIZE_NUMBER_INT);
        $list_id = (is_string($raw_list_id) || is_int($raw_list_id)) ? absint($raw_list_id) : 0;
        $nonce = filter_input(INPUT_GET, '_wpnonce', FILTER_UNSAFE_RAW);
        $nonce = is_string($nonce) ? sanitize_text_field($nonce) : '';

        $rows = $this->build_stats_rows();
        $this->maybe_handle_stats_export($list_id, $rows, $format, $nonce);
    }

    public function render_stats_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $status_filter = $this->get_stats_status_filter();
        $search_query = $this->get_stats_search_query();

        if ($this->maybe_handle_stats_actions($status_filter, $search_query)) {
            return;
        }

        $rows = $this->build_stats_rows(
            array(
                'status' => $status_filter,
                'search' => $search_query,
            )
        );

        $raw_selected_list_id = filter_input(INPUT_GET, 'list_id', FILTER_SANITIZE_NUMBER_INT);
        $selected_list_id = (is_string($raw_selected_list_id) || is_int($raw_selected_list_id)) ? absint($raw_selected_list_id) : 0;

        if ($selected_list_id > 0) {
            $this->render_single_stats_page($selected_list_id, $rows, $status_filter, $search_query);
            return;
        }

        $totals = array(
            'views_total' => 0,
            'views_unique' => 0,
            'clicks_total' => 0,
            'clicks_unique' => 0,
        );

        foreach ($rows as $row) {
            $totals['views_total'] += (int) $row['views_total'];
            $totals['views_unique'] += (int) $row['views_unique'];
            $totals['clicks_total'] += (int) $row['clicks_total'];
            $totals['clicks_unique'] += (int) $row['clicks_unique'];
        }

        $ctr_total = $this->calculate_ctr($totals['clicks_total'], $totals['views_total']);
        $has_filters = ('all' !== $status_filter) || ('' !== $search_query);
        $reset_url = $this->get_stats_page_url();
        ?>
        <div class="wrap wpgs-admin-page">
            <h1><?php echo esc_html__('WPGS Stats', 'gamequery-servers-lists'); ?></h1>

            <form method="get" action="<?php echo esc_url(admin_url('edit.php')); ?>" class="wpgs-stats-filter-toolbar">
                <input type="hidden" name="post_type" value="<?php echo esc_attr(WPGS_Lists::POST_TYPE); ?>" />
                <input type="hidden" name="page" value="<?php echo esc_attr(self::STATS_SLUG); ?>" />

                <label for="wpgs_stats_status_filter" class="screen-reader-text"><?php echo esc_html__('Filter by status', 'gamequery-servers-lists'); ?></label>
                <select id="wpgs_stats_status_filter" name="wpgs_status">
                    <option value="all" <?php selected('all', $status_filter); ?>><?php echo esc_html__('All statuses', 'gamequery-servers-lists'); ?></option>
                    <option value="publish" <?php selected('publish', $status_filter); ?>><?php echo esc_html__('Published', 'gamequery-servers-lists'); ?></option>
                    <option value="draft" <?php selected('draft', $status_filter); ?>><?php echo esc_html__('Draft', 'gamequery-servers-lists'); ?></option>
                    <option value="pending" <?php selected('pending', $status_filter); ?>><?php echo esc_html__('Pending', 'gamequery-servers-lists'); ?></option>
                    <option value="private" <?php selected('private', $status_filter); ?>><?php echo esc_html__('Private', 'gamequery-servers-lists'); ?></option>
                </select>

                <label for="wpgs_stats_search" class="screen-reader-text"><?php echo esc_html__('Search lists', 'gamequery-servers-lists'); ?></label>
                <input
                    type="search"
                    id="wpgs_stats_search"
                    name="s"
                    value="<?php echo esc_attr($search_query); ?>"
                    placeholder="<?php echo esc_attr__('Search lists...', 'gamequery-servers-lists'); ?>"
                />

                <button type="submit" class="button"><?php echo esc_html__('Filter', 'gamequery-servers-lists'); ?></button>
                <?php if ($has_filters) : ?>
                    <a class="button button-secondary" href="<?php echo esc_url($reset_url); ?>"><?php echo esc_html__('Reset', 'gamequery-servers-lists'); ?></a>
                <?php endif; ?>
            </form>

            <div class="wpgs-settings-summary">
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Total Views', 'gamequery-servers-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($totals['views_total'])); ?></span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Unique Views', 'gamequery-servers-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($totals['views_unique'])); ?></span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Total Clicks', 'gamequery-servers-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($totals['clicks_total'])); ?></span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('CTR', 'gamequery-servers-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($ctr_total, 2)); ?>%</span>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url($this->get_stats_page_url()); ?>" class="wpgs-stats-bulk-form">
                <?php wp_nonce_field('wpgs_stats_bulk', 'wpgs_stats_bulk_nonce'); ?>
                <input type="hidden" name="wpgs_stats_action" value="bulk_apply" />
                <input type="hidden" name="wpgs_status" value="<?php echo esc_attr($status_filter); ?>" />
                <input type="hidden" name="s" value="<?php echo esc_attr($search_query); ?>" />

                <div class="wpgs-stats-bulk-actions">
                    <label class="screen-reader-text" for="wpgs_bulk_action"><?php echo esc_html__('Select bulk action', 'gamequery-servers-lists'); ?></label>
                    <select id="wpgs_bulk_action" name="wpgs_bulk_action">
                        <option value=""><?php echo esc_html__('Bulk actions', 'gamequery-servers-lists'); ?></option>
                        <option value="trash"><?php echo esc_html__('Move to Trash', 'gamequery-servers-lists'); ?></option>
                    </select>
                    <button type="submit" class="button action"><?php echo esc_html__('Apply', 'gamequery-servers-lists'); ?></button>
                </div>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <td class="manage-column check-column"><input type="checkbox" id="wpgs-stats-select-all" /></td>
                            <th><?php echo esc_html__('List', 'gamequery-servers-lists'); ?></th>
                            <th><?php echo esc_html__('Shortcode', 'gamequery-servers-lists'); ?></th>
                            <th><?php echo esc_html__('Views', 'gamequery-servers-lists'); ?></th>
                            <th><?php echo esc_html__('Unique Views', 'gamequery-servers-lists'); ?></th>
                            <th><?php echo esc_html__('Clicks', 'gamequery-servers-lists'); ?></th>
                            <th><?php echo esc_html__('Unique Clicks', 'gamequery-servers-lists'); ?></th>
                            <th><?php echo esc_html__('CTR', 'gamequery-servers-lists'); ?></th>
                            <th><?php echo esc_html__('Last Event (UTC)', 'gamequery-servers-lists'); ?></th>
                            <th><?php echo esc_html__('Actions', 'gamequery-servers-lists'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)) : ?>
                            <tr>
                                <td colspan="10">
                                    <?php if ($has_filters) : ?>
                                        <?php echo esc_html__('No lists matched your current filters.', 'gamequery-servers-lists'); ?>
                                    <?php else : ?>
                                        <?php echo esc_html__('No lists found yet.', 'gamequery-servers-lists'); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($rows as $row) : ?>
                                <?php $row_ctr = $this->calculate_ctr((int) $row['clicks_total'], (int) $row['views_total']); ?>
                                <tr>
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" class="wpgs-stats-row-checkbox" name="wpgs_list_ids[]" value="<?php echo esc_attr((string) $row['id']); ?>" />
                                    </th>
                                    <td>
                                        <?php $title = '' !== trim((string) $row['title']) ? (string) $row['title'] : __('(no title)', 'gamequery-servers-lists'); ?>
                                        <a href="<?php echo esc_url((string) $row['stats_url']); ?>"><?php echo esc_html($title); ?></a>
                                        <div class="wpgs-stats-secondary">
                                            <?php
                                            /* translators: %s: list post status label. */
                                            $status_label = sprintf(__('Status: %s', 'gamequery-servers-lists'), strtoupper((string) $row['post_status']));
                                            echo esc_html($status_label);
                                            ?>
                                        </div>
                                    </td>
                                    <td><code>[gamequery_<?php echo esc_html((string) $row['id']); ?>]</code></td>
                                    <td><a class="wpgs-stats-cell-link" href="<?php echo esc_url((string) $row['stats_url']); ?>"><?php echo esc_html(number_format_i18n((int) $row['views_total'])); ?></a></td>
                                    <td><a class="wpgs-stats-cell-link" href="<?php echo esc_url((string) $row['stats_url']); ?>"><?php echo esc_html(number_format_i18n((int) $row['views_unique'])); ?></a></td>
                                    <td><a class="wpgs-stats-cell-link" href="<?php echo esc_url((string) $row['stats_url']); ?>"><?php echo esc_html(number_format_i18n((int) $row['clicks_total'])); ?></a></td>
                                    <td><a class="wpgs-stats-cell-link" href="<?php echo esc_url((string) $row['stats_url']); ?>"><?php echo esc_html(number_format_i18n((int) $row['clicks_unique'])); ?></a></td>
                                    <td><?php echo esc_html(number_format_i18n($row_ctr, 2)); ?>%</td>
                                    <td><?php echo '' !== (string) $row['last_event'] ? esc_html((string) $row['last_event']) : '—'; ?></td>
                                    <td>
                                        <div class="wpgs-stats-row-actions">
                                            <a class="button button-small" href="<?php echo esc_url((string) $row['stats_url']); ?>"><?php echo esc_html__('Open report', 'gamequery-servers-lists'); ?></a>
                                            <?php if (!empty($row['edit_url'])) : ?>
                                                <a class="button button-small" href="<?php echo esc_url((string) $row['edit_url']); ?>"><?php echo esc_html__('Edit', 'gamequery-servers-lists'); ?></a>
                                            <?php endif; ?>
                                            <?php if (!empty($row['trash_url'])) : ?>
                                                <a class="button button-small wpgs-button-danger" href="<?php echo esc_url((string) $row['trash_url']); ?>"><?php echo esc_html__('Trash', 'gamequery-servers-lists'); ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>

            <script>
            (function () {
                var selectAll = document.getElementById('wpgs-stats-select-all');
                if (!selectAll) {
                    return;
                }

                selectAll.addEventListener('change', function () {
                    var checkboxes = document.querySelectorAll('.wpgs-stats-row-checkbox');
                    for (var i = 0; i < checkboxes.length; i += 1) {
                        checkboxes[i].checked = selectAll.checked;
                    }
                });
            }());
            </script>
        </div>
        <?php
    }

    /**
     * @return string
     */
    private function get_stats_status_filter() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only table filter input.
        $raw = isset($_REQUEST['wpgs_status']) ? sanitize_key((string) wp_unslash($_REQUEST['wpgs_status'])) : 'all';
        $allowed = array('all', 'publish', 'draft', 'pending', 'private');

        return in_array($raw, $allowed, true) ? $raw : 'all';
    }

    /**
     * @return string
     */
    private function get_stats_search_query() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only table filter input.
        $raw = isset($_REQUEST['s']) ? sanitize_text_field((string) wp_unslash($_REQUEST['s'])) : '';
        return trim((string) $raw);
    }

    /**
     * @param string $status_filter
     * @param string $search_query
     * @return array<string, string>
     */
    private function build_stats_filter_query_args($status_filter, $search_query) {
        $args = array();

        $status_filter = sanitize_key((string) $status_filter);
        if ('all' !== $status_filter && '' !== $status_filter) {
            $args['wpgs_status'] = $status_filter;
        }

        $search_query = trim(sanitize_text_field((string) $search_query));
        if ('' !== $search_query) {
            $args['s'] = $search_query;
        }

        return $args;
    }

    /**
     * @param string $status_filter
     * @return array<int, string>
     */
    private function get_stats_post_statuses($status_filter) {
        $status_filter = sanitize_key((string) $status_filter);
        if (in_array($status_filter, array('publish', 'draft', 'pending', 'private'), true)) {
            return array($status_filter);
        }

        return array('publish', 'draft', 'pending', 'private');
    }

    /**
     * @param string $status_filter
     * @param string $search_query
     * @return bool
     */
    private function maybe_handle_stats_actions($status_filter, $search_query) {
        if (!is_admin() || !current_user_can('manage_options')) {
            return false;
        }

        $action = isset($_REQUEST['wpgs_stats_action']) ? sanitize_key((string) wp_unslash($_REQUEST['wpgs_stats_action'])) : '';
        if ('' === $action) {
            return false;
        }

        $redirect_args = $this->build_stats_filter_query_args((string) $status_filter, (string) $search_query);

        if ('trash' === $action) {
            $list_id = isset($_REQUEST['list_id']) ? absint((string) wp_unslash($_REQUEST['list_id'])) : 0;
            $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field((string) wp_unslash($_REQUEST['_wpnonce'])) : '';

            if ($list_id <= 0 || !wp_verify_nonce($nonce, 'wpgs_stats_trash_' . $list_id)) {
                $this->set_admin_notice('error', __('Invalid trash request.', 'gamequery-servers-lists'));
                wp_safe_redirect($this->get_stats_page_url(0, $redirect_args));
                exit;
            }

            if ($this->trash_stats_list($list_id)) {
                $this->set_admin_notice('success', __('List moved to Trash.', 'gamequery-servers-lists'));
            } else {
                $this->set_admin_notice('error', __('List could not be moved to Trash.', 'gamequery-servers-lists'));
            }

            wp_safe_redirect($this->get_stats_page_url(0, $redirect_args));
            exit;
        }

        if ('bulk_apply' === $action) {
            $bulk_nonce = isset($_POST['wpgs_stats_bulk_nonce']) ? sanitize_text_field((string) wp_unslash($_POST['wpgs_stats_bulk_nonce'])) : '';
            if (!wp_verify_nonce($bulk_nonce, 'wpgs_stats_bulk')) {
                $this->set_admin_notice('error', __('Invalid bulk action request.', 'gamequery-servers-lists'));
                wp_safe_redirect($this->get_stats_page_url(0, $redirect_args));
                exit;
            }

            $bulk_action = isset($_POST['wpgs_bulk_action']) ? sanitize_key((string) wp_unslash($_POST['wpgs_bulk_action'])) : '';
            if ('trash' !== $bulk_action) {
                $this->set_admin_notice('warning', __('No bulk action was selected.', 'gamequery-servers-lists'));
                wp_safe_redirect($this->get_stats_page_url(0, $redirect_args));
                exit;
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized in the loop below.
            $raw_list_ids = isset($_POST['wpgs_list_ids']) ? (array) wp_unslash($_POST['wpgs_list_ids']) : array();
            $list_ids = array();
            foreach ($raw_list_ids as $raw_list_id) {
                $list_id = absint((int) $raw_list_id);
                if ($list_id > 0) {
                    $list_ids[$list_id] = $list_id;
                }
            }

            if (empty($list_ids)) {
                $this->set_admin_notice('warning', __('No lists were selected.', 'gamequery-servers-lists'));
                wp_safe_redirect($this->get_stats_page_url(0, $redirect_args));
                exit;
            }

            $trashed_count = 0;
            foreach ($list_ids as $list_id) {
                if ($this->trash_stats_list($list_id)) {
                    $trashed_count++;
                }
            }

            if ($trashed_count > 0) {
                $message = sprintf(
                    /* translators: %d: trashed list count */
                    _n('Moved %d list to Trash.', 'Moved %d lists to Trash.', $trashed_count, 'gamequery-servers-lists'),
                    $trashed_count
                );
                $this->set_admin_notice('success', $message);
            } else {
                $this->set_admin_notice('error', __('Selected lists could not be moved to Trash.', 'gamequery-servers-lists'));
            }

            wp_safe_redirect($this->get_stats_page_url(0, $redirect_args));
            exit;
        }

        return false;
    }

    /**
     * @param int $list_id
     * @return bool
     */
    private function trash_stats_list($list_id) {
        $list_id = absint($list_id);
        if ($list_id <= 0 || !current_user_can('delete_post', $list_id)) {
            return false;
        }

        $post = get_post($list_id);
        if (!$post instanceof WP_Post || WPGS_Lists::POST_TYPE !== $post->post_type || 'trash' === $post->post_status) {
            return false;
        }

        $trashed = wp_trash_post($list_id);
        return $trashed instanceof WP_Post;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function build_stats_rows($filters = array()) {
        $status_filter = isset($filters['status']) ? sanitize_key((string) $filters['status']) : 'all';
        $search_query = isset($filters['search']) ? sanitize_text_field((string) $filters['search']) : '';
        $search_query = trim($search_query);

        $query_args = array(
            'post_type' => WPGS_Lists::POST_TYPE,
            'post_status' => $this->get_stats_post_statuses($status_filter),
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        );

        if ('' !== $search_query) {
            $query_args['s'] = $search_query;
        }

        $lists = get_posts(
            $query_args
        );

        $rows = array();
        if (!is_array($lists)) {
            return $rows;
        }

        $filter_args = $this->build_stats_filter_query_args($status_filter, $search_query);

        foreach ($lists as $list_post) {
            if (!$list_post instanceof WP_Post) {
                continue;
            }

            $list_id = (int) $list_post->ID;
            $stats = WPGS_Stats::get_list_stats($list_id);
            $groups = WPGS_Lists::get_groups($list_id);
            $trash_url = '';

            if (current_user_can('delete_post', $list_id)) {
                $trash_url = wp_nonce_url(
                    $this->get_stats_page_url(
                        0,
                        array_merge(
                            $filter_args,
                            array(
                                'wpgs_stats_action' => 'trash',
                                'list_id' => $list_id,
                            )
                        )
                    ),
                    'wpgs_stats_trash_' . $list_id
                );
            }

            $rows[] = array(
                'id' => $list_id,
                'title' => get_the_title($list_id),
                'edit_url' => get_edit_post_link($list_id, ''),
                'stats_url' => $this->get_stats_page_url($list_id, $filter_args),
                'trash_url' => $trash_url,
                'post_status' => (string) $list_post->post_status,
                'views_total' => isset($stats['views_total']) ? (int) $stats['views_total'] : 0,
                'views_unique' => isset($stats['views_unique']) ? (int) $stats['views_unique'] : 0,
                'clicks_total' => isset($stats['clicks_total']) ? (int) $stats['clicks_total'] : 0,
                'clicks_unique' => isset($stats['clicks_unique']) ? (int) $stats['clicks_unique'] : 0,
                'last_event' => isset($stats['last_event']) ? (string) $stats['last_event'] : '',
                'group_count' => count($groups),
                'server_count' => WPGS_Lists::count_servers($groups),
            );
        }

        return $rows;
    }

    /**
     * @param int $list_id
     * @param array<int, array<string, mixed>> $rows
     * @param string $status_filter
     * @param string $search_query
     */
    private function render_single_stats_page($list_id, $rows, $status_filter = 'all', $search_query = '') {
        $row = $this->find_stats_row($rows, $list_id);
        $back_url = $this->get_stats_page_url(0, $this->build_stats_filter_query_args((string) $status_filter, (string) $search_query));

        if (empty($row)) {
            ?>
            <div class="wrap wpgs-admin-page">
                <h1><?php echo esc_html__('WPGS Stats', 'gamequery-servers-lists'); ?></h1>
                <p><?php echo esc_html__('That list report could not be found.', 'gamequery-servers-lists'); ?></p>
                <p><a class="button" href="<?php echo esc_url($back_url); ?>"><?php echo esc_html__('Back to all stats', 'gamequery-servers-lists'); ?></a></p>
            </div>
            <?php
            return;
        }

        $list_id = (int) $row['id'];
        $title = '' !== trim((string) $row['title']) ? (string) $row['title'] : __('(no title)', 'gamequery-servers-lists');
        $views_total = (int) $row['views_total'];
        $views_unique = (int) $row['views_unique'];
        $clicks_total = (int) $row['clicks_total'];
        $clicks_unique = (int) $row['clicks_unique'];

        $ctr_total = $this->calculate_ctr($clicks_total, $views_total);
        $ctr_unique = $this->calculate_ctr($clicks_unique, $views_unique);

        $csv_url = wp_nonce_url(
            $this->get_stats_page_url($list_id, array('wpgs_export' => 'csv')),
            'wpgs_stats_export_' . $list_id
        );
        $pdf_url = wp_nonce_url(
            $this->get_stats_page_url($list_id, array('wpgs_export' => 'pdf')),
            'wpgs_stats_export_' . $list_id
        );

        ?>
        <div class="wrap wpgs-admin-page">
            <div class="wpgs-stats-toolbar">
                <div>
                    <h1><?php echo esc_html($title); ?></h1>
                    <p class="description"><code>[gamequery_<?php echo esc_html((string) $list_id); ?>]</code></p>
                </div>
                <div class="wpgs-stats-toolbar-actions">
                    <a class="button" href="<?php echo esc_url($back_url); ?>"><?php echo esc_html__('Back to all stats', 'gamequery-servers-lists'); ?></a>
                    <?php if (!empty($row['edit_url'])) : ?>
                        <a class="button" href="<?php echo esc_url((string) $row['edit_url']); ?>"><?php echo esc_html__('Edit list', 'gamequery-servers-lists'); ?></a>
                    <?php endif; ?>
                    <a class="button button-secondary" href="<?php echo esc_url($csv_url); ?>"><?php echo esc_html__('Download CSV', 'gamequery-servers-lists'); ?></a>
                    <a class="button button-secondary" href="<?php echo esc_url($pdf_url); ?>"><?php echo esc_html__('Download PDF', 'gamequery-servers-lists'); ?></a>
                </div>
            </div>

            <div class="wpgs-settings-summary">
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Total Views', 'gamequery-servers-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($views_total)); ?></span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Unique Views', 'gamequery-servers-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($views_unique)); ?></span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Total Clicks', 'gamequery-servers-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($clicks_total)); ?></span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Unique Clicks', 'gamequery-servers-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($clicks_unique)); ?></span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('CTR (Total)', 'gamequery-servers-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($ctr_total, 2)); ?>%</span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('CTR (Unique)', 'gamequery-servers-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($ctr_unique, 2)); ?>%</span>
                </div>
            </div>

            <div class="wpgs-stats-graphs">
                <div class="wpgs-stats-graph-card">
                    <h2><?php echo esc_html__('Audience Quality', 'gamequery-servers-lists'); ?></h2>
                    <?php
                    $views_ratio_hint = number_format_i18n($views_unique) . ' / ' . number_format_i18n($views_total);
                    $unique_clicks_hint = number_format_i18n($clicks_unique) . ' / ' . number_format_i18n($clicks_total);

                    $views_ratio_bar = $this->render_stats_progress_bar(
                        __('Unique Views Ratio', 'gamequery-servers-lists'),
                        $views_unique,
                        $views_total,
                        '#0f4b81',
                        $views_ratio_hint
                    );

                    $unique_clicks_bar = $this->render_stats_progress_bar(
                        __('Unique Clicks Ratio', 'gamequery-servers-lists'),
                        $clicks_unique,
                        $clicks_total,
                        '#1f7c41',
                        $unique_clicks_hint
                    );

                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped admin HTML.
                    echo $views_ratio_bar;
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped admin HTML.
                    echo $unique_clicks_bar;
                    ?>
                </div>
                <div class="wpgs-stats-graph-card">
                    <h2><?php echo esc_html__('Conversion Snapshot', 'gamequery-servers-lists'); ?></h2>
                    <?php
                    $total_ctr_hint = number_format_i18n($clicks_total) . ' / ' . number_format_i18n($views_total);
                    $unique_ctr_hint = number_format_i18n($clicks_unique) . ' / ' . number_format_i18n($views_unique);

                    $total_ctr_bar = $this->render_stats_progress_bar(
                        __('Click-through Rate (Total)', 'gamequery-servers-lists'),
                        $clicks_total,
                        $views_total,
                        '#8a4b00',
                        $total_ctr_hint
                    );

                    $unique_ctr_bar = $this->render_stats_progress_bar(
                        __('Click-through Rate (Unique)', 'gamequery-servers-lists'),
                        $clicks_unique,
                        $views_unique,
                        '#6d28d9',
                        $unique_ctr_hint
                    );

                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped admin HTML.
                    echo $total_ctr_bar;
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped admin HTML.
                    echo $unique_ctr_bar;
                    ?>
                </div>
            </div>

            <table class="widefat striped wpgs-stats-detail-table">
                <tbody>
                    <tr>
                        <th><?php echo esc_html__('List ID', 'gamequery-servers-lists'); ?></th>
                        <td><?php echo esc_html((string) $list_id); ?></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Groups', 'gamequery-servers-lists'); ?></th>
                        <td><?php echo esc_html(number_format_i18n((int) $row['group_count'])); ?></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Servers', 'gamequery-servers-lists'); ?></th>
                        <td><?php echo esc_html(number_format_i18n((int) $row['server_count'])); ?></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Last Event (UTC)', 'gamequery-servers-lists'); ?></th>
                        <td><?php echo '' !== (string) $row['last_event'] ? esc_html((string) $row['last_event']) : '—'; ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * @param int $list_id
     * @param array<int, array<string, mixed>> $rows
     * @param string $format
     * @param string $nonce
     */
    private function maybe_handle_stats_export($list_id, $rows, $format, $nonce) {
        $format = sanitize_key((string) $format);
        $nonce = sanitize_text_field((string) $nonce);

        if ('' === $format) {
            return;
        }

        $list_id = absint($list_id);
        if ($list_id <= 0) {
            wp_die(esc_html__('The selected report could not be found.', 'gamequery-servers-lists'));
        }

        $row = $this->find_stats_row($rows, $list_id);
        if (empty($row)) {
            wp_die(esc_html__('The selected report could not be found.', 'gamequery-servers-lists'));
        }

        if (!wp_verify_nonce($nonce, 'wpgs_stats_export_' . $list_id)) {
            wp_die(esc_html__('Invalid export request.', 'gamequery-servers-lists'));
        }

        if ('csv' === $format) {
            $this->export_stats_csv($row);
            return;
        }

        if ('pdf' === $format) {
            $this->export_stats_pdf($row);
            return;
        }

        wp_die(esc_html__('Unsupported export format.', 'gamequery-servers-lists'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function export_stats_csv($row) {
        $list_id = isset($row['id']) ? absint($row['id']) : 0;
        $filename = 'wpgs-stats-list-' . $list_id . '-' . gmdate('Ymd-His') . '.csv';
        $lines = $this->build_stats_export_lines($row);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        if (false !== $output) {
            fputcsv($output, array(__('Metric', 'gamequery-servers-lists'), __('Value', 'gamequery-servers-lists')));
            foreach ($lines as $line) {
                if (!is_array($line)) {
                    continue;
                }

                $label = isset($line['label']) ? (string) $line['label'] : '';
                $value = isset($line['value']) ? (string) $line['value'] : '';
                fputcsv($output, array($label, $value));
            }
        }

        exit;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function export_stats_pdf($row) {
        $list_id = isset($row['id']) ? absint($row['id']) : 0;
        $filename = 'wpgs-stats-list-' . $list_id . '-' . gmdate('Ymd-His') . '.pdf';
        $lines = $this->build_stats_export_lines($row);

        $pdf_lines = array();
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $label = isset($line['label']) ? (string) $line['label'] : '';
            $value = isset($line['value']) ? (string) $line['value'] : '';
            $pdf_lines[] = $label . ': ' . $value;
        }

        $pdf = $this->build_simple_pdf_document(__('WPGS Stats Report', 'gamequery-servers-lists'), $pdf_lines);

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF stream download.
        echo $pdf;
        exit;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, array<string, string>>
     */
    private function build_stats_export_lines($row) {
        $list_id = isset($row['id']) ? absint($row['id']) : 0;
        $title = isset($row['title']) && '' !== trim((string) $row['title'])
            ? (string) $row['title']
            : __('(no title)', 'gamequery-servers-lists');

        $views_total = isset($row['views_total']) ? (int) $row['views_total'] : 0;
        $views_unique = isset($row['views_unique']) ? (int) $row['views_unique'] : 0;
        $clicks_total = isset($row['clicks_total']) ? (int) $row['clicks_total'] : 0;
        $clicks_unique = isset($row['clicks_unique']) ? (int) $row['clicks_unique'] : 0;

        return array(
            array(
                'label' => __('Generated At (UTC)', 'gamequery-servers-lists'),
                'value' => current_time('mysql', true),
            ),
            array(
                'label' => __('List ID', 'gamequery-servers-lists'),
                'value' => (string) $list_id,
            ),
            array(
                'label' => __('List Title', 'gamequery-servers-lists'),
                'value' => $title,
            ),
            array(
                'label' => __('Shortcode', 'gamequery-servers-lists'),
                'value' => '[gamequery_' . $list_id . ']',
            ),
            array(
                'label' => __('Groups', 'gamequery-servers-lists'),
                'value' => number_format_i18n(isset($row['group_count']) ? (int) $row['group_count'] : 0),
            ),
            array(
                'label' => __('Servers', 'gamequery-servers-lists'),
                'value' => number_format_i18n(isset($row['server_count']) ? (int) $row['server_count'] : 0),
            ),
            array(
                'label' => __('Total Views', 'gamequery-servers-lists'),
                'value' => number_format_i18n($views_total),
            ),
            array(
                'label' => __('Unique Views', 'gamequery-servers-lists'),
                'value' => number_format_i18n($views_unique),
            ),
            array(
                'label' => __('Total Clicks', 'gamequery-servers-lists'),
                'value' => number_format_i18n($clicks_total),
            ),
            array(
                'label' => __('Unique Clicks', 'gamequery-servers-lists'),
                'value' => number_format_i18n($clicks_unique),
            ),
            array(
                'label' => __('CTR (Total)', 'gamequery-servers-lists'),
                'value' => number_format_i18n($this->calculate_ctr($clicks_total, $views_total), 2) . '%',
            ),
            array(
                'label' => __('CTR (Unique)', 'gamequery-servers-lists'),
                'value' => number_format_i18n($this->calculate_ctr($clicks_unique, $views_unique), 2) . '%',
            ),
            array(
                'label' => __('Last Event (UTC)', 'gamequery-servers-lists'),
                'value' => isset($row['last_event']) && '' !== (string) $row['last_event'] ? (string) $row['last_event'] : '—',
            ),
        );
    }

    /**
     * @param string $title
     * @param array<int, string> $lines
     * @return string
     */
    private function build_simple_pdf_document($title, $lines) {
        $stream = "BT\n/F1 12 Tf\n";
        $y = 810;

        $pdf_lines = array_merge(array((string) $title, ''), $lines);
        foreach ($pdf_lines as $line) {
            $escaped_line = $this->escape_pdf_text((string) $line);
            $stream .= '1 0 0 1 42 ' . (int) $y . " Tm\n(" . $escaped_line . ") Tj\n";
            $y -= 16;
            if ($y < 36) {
                break;
            }
        }

        $stream .= "ET";

        $objects = array(
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj',
            '4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj',
            '5 0 obj << /Length ' . strlen($stream) . " >> stream\n" . $stream . "\nendstream endobj",
        );

        $pdf = "%PDF-1.4\n";
        $offsets = array(0);

        foreach ($objects as $index => $object) {
            $offsets[$index + 1] = strlen($pdf);
            $pdf .= $object . "\n";
        }

        $xref_offset = strlen($pdf);
        $pdf .= 'xref' . "\n";
        $pdf .= '0 ' . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf('%010d 00000 n ' . "\n", (int) $offsets[$i]);
        }

        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . ' /Root 1 0 R >>' . "\n";
        $pdf .= 'startxref' . "\n";
        $pdf .= (string) $xref_offset . "\n";
        $pdf .= "%%EOF";

        return $pdf;
    }

    /**
     * @param string $value
     * @return string
     */
    private function escape_pdf_text($value) {
        $value = preg_replace('/[^\x20-\x7E]/', ' ', (string) $value);
        if (!is_string($value)) {
            return '';
        }

        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('(', '\\(', $value);
        $value = str_replace(')', '\\)', $value);

        return trim($value);
    }

    /**
     * @param string $label
     * @param int $value
     * @param int $max
     * @param string $color
     * @param string $hint
     * @return string
     */
    private function render_stats_progress_bar($label, $value, $max, $color, $hint = '') {
        $value = max(0, (int) $value);
        $max = max(0, (int) $max);
        $percent = 0.0;

        if ($max > 0) {
            $percent = ($value / $max) * 100;
        }

        $percent = max(0.0, min(100.0, $percent));
        $width = number_format($percent, 2, '.', '');

        $sanitized_color = sanitize_hex_color($color);
        if (!is_string($sanitized_color) || '' === $sanitized_color) {
            $sanitized_color = '#0f4b81';
        }

        $html = '';
        $html .= '<div class="wpgs-stats-progress">';
        $html .= '<div class="wpgs-stats-progress-head">';
        $html .= '<span>' . esc_html($label) . '</span>';
        $html .= '<strong>' . esc_html(number_format_i18n($percent, 2)) . '%</strong>';
        $html .= '</div>';
        $html .= '<div class="wpgs-stats-progress-track">';
        $html .= '<span style="width:' . esc_attr($width) . '%; background:' . esc_attr($sanitized_color) . ';"></span>';
        $html .= '</div>';

        if ('' !== trim((string) $hint)) {
            $html .= '<p class="description">' . esc_html($hint) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param int $list_id
     * @return array<string, mixed>
     */
    private function find_stats_row($rows, $list_id) {
        $list_id = absint($list_id);
        if ($list_id <= 0) {
            return array();
        }

        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }

            if ($list_id === (int) $row['id']) {
                return $row;
            }
        }

        return array();
    }

    /**
     * @param int $clicks
     * @param int $views
     * @return float
     */
    private function calculate_ctr($clicks, $views) {
        $clicks = max(0, (int) $clicks);
        $views = max(0, (int) $views);
        if ($views <= 0) {
            return 0.0;
        }

        return ($clicks / $views) * 100;
    }

    /**
     * @param int $list_id
     * @param array<string, mixed> $extra_args
     * @return string
     */
    private function get_stats_page_url($list_id = 0, $extra_args = array()) {
        $args = array(
            'post_type' => WPGS_Lists::POST_TYPE,
            'page' => self::STATS_SLUG,
        );

        $list_id = absint($list_id);
        if ($list_id > 0) {
            $args['list_id'] = $list_id;
        }

        if (is_array($extra_args) && !empty($extra_args)) {
            $args = array_merge($args, $extra_args);
        }

        return add_query_arg($args, admin_url('edit.php'));
    }

    public function handle_connect_init() {
        if (!$this->verify_connect_ajax_request()) {
            return;
        }

        $account_base_url = WPGS_Settings::get_account_base_url();
        if (!wp_http_validate_url($account_base_url)) {
            wp_send_json_error(
                array(
                    'message' => __('Account base URL is invalid.', 'gamequery-servers-lists'),
                ),
                400
            );
        }

        $code_verifier = $this->build_pkce_verifier();
        $code_challenge = $this->build_pkce_challenge($code_verifier);

        $response = $this->request_remote_json(
            untrailingslashit($account_base_url) . '/theme-api/plugin-connect/sessions',
            array(
                'codeChallenge' => $code_challenge,
                'codeChallengeMethod' => 'S256',
                'siteUrl' => home_url('/'),
                'adminUrl' => admin_url(),
                'pluginVersion' => WPGS_VERSION,
            )
        );

        if (empty($response['success'])) {
            $message = !empty($response['message'])
                ? (string) $response['message']
                : __('Unable to start account connection right now.', 'gamequery-servers-lists');

            wp_send_json_error(
                array(
                    'message' => $message,
                ),
                502
            );
        }

        $response_body = isset($response['body']) && is_array($response['body']) ? $response['body'] : array();

        $session_id = $this->sanitize_connect_session_id(isset($response_body['sessionId']) ? (string) $response_body['sessionId'] : '');
        $poll_token = isset($response_body['pollToken']) ? sanitize_text_field((string) $response_body['pollToken']) : '';
        $authorize_url = isset($response_body['authorizeUrl']) ? esc_url_raw((string) $response_body['authorizeUrl']) : '';
        $expires_in = isset($response_body['expiresIn']) ? absint($response_body['expiresIn']) : 900;

        if (
            empty($session_id)
            || empty($poll_token)
            || empty($authorize_url)
            || !wp_http_validate_url($authorize_url)
            || !preg_match('/^pct_[a-f0-9]{32,128}$/', $poll_token)
        ) {
            wp_send_json_error(
                array(
                    'message' => __('GameQuery returned an invalid connection payload.', 'gamequery-servers-lists'),
                ),
                502
            );
        }

        $base_host = wp_parse_url($account_base_url, PHP_URL_HOST);
        $authorize_host = wp_parse_url($authorize_url, PHP_URL_HOST);
        if (!is_string($base_host) || !is_string($authorize_host) || strtolower($base_host) !== strtolower($authorize_host)) {
            wp_send_json_error(
                array(
                    'message' => __('GameQuery returned an unexpected authorize host.', 'gamequery-servers-lists'),
                ),
                502
            );
        }

        $expires_in = max(60, min(1800, $expires_in));

        set_transient(
            $this->get_connect_transient_key($session_id),
            array(
                'session_id' => $session_id,
                'poll_token' => $poll_token,
                'code_verifier' => $code_verifier,
                'account_base_url' => untrailingslashit($account_base_url),
                'expires_at' => time() + $expires_in,
                'created_by' => get_current_user_id(),
            ),
            $expires_in + 300
        );

        wp_send_json_success(
            array(
                'session_id' => $session_id,
                'authorize_url' => $authorize_url,
                'expires_in' => $expires_in,
            )
        );
    }

    public function handle_connect_poll() {
        if (!$this->verify_connect_ajax_request()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verify_connect_ajax_request() validates nonce and capability before reading session_id.
        $session_id = isset($_POST['session_id']) ? $this->sanitize_connect_session_id((string) wp_unslash($_POST['session_id'])) : '';
        if (empty($session_id)) {
            wp_send_json_error(
                array(
                    'message' => __('Connection session is invalid or missing.', 'gamequery-servers-lists'),
                ),
                400
            );
        }

        $session_data = get_transient($this->get_connect_transient_key($session_id));
        if (!is_array($session_data)) {
            wp_send_json_success(
                array(
                    'status' => 'expired',
                    'message' => __('Connection session has expired. Please start again.', 'gamequery-servers-lists'),
                )
            );
        }

        $created_by = isset($session_data['created_by']) ? absint($session_data['created_by']) : 0;
        if ($created_by !== get_current_user_id()) {
            wp_send_json_error(
                array(
                    'message' => __('This connection session belongs to another admin user.', 'gamequery-servers-lists'),
                ),
                403
            );
        }

        $stored_session_id = isset($session_data['session_id']) ? $this->sanitize_connect_session_id((string) $session_data['session_id']) : '';
        $poll_token = isset($session_data['poll_token']) ? sanitize_text_field((string) $session_data['poll_token']) : '';
        $code_verifier = isset($session_data['code_verifier']) ? sanitize_text_field((string) $session_data['code_verifier']) : '';
        $account_base_url = isset($session_data['account_base_url']) ? esc_url_raw((string) $session_data['account_base_url']) : '';
        $expires_at = isset($session_data['expires_at']) ? absint($session_data['expires_at']) : 0;

        if (
            empty($stored_session_id)
            || $stored_session_id !== $session_id
            || empty($poll_token)
            || empty($code_verifier)
            || empty($account_base_url)
            || !preg_match('/^pct_[a-f0-9]{32,128}$/', $poll_token)
        ) {
            delete_transient($this->get_connect_transient_key($session_id));
            wp_send_json_error(
                array(
                    'message' => __('Stored connection session is invalid. Please start again.', 'gamequery-servers-lists'),
                ),
                400
            );
        }

        if ($expires_at > 0 && time() > $expires_at) {
            delete_transient($this->get_connect_transient_key($session_id));
            wp_send_json_success(
                array(
                    'status' => 'expired',
                    'message' => __('Connection session has expired. Please start again.', 'gamequery-servers-lists'),
                )
            );
        }

        $poll_response = $this->request_remote_json(
            untrailingslashit($account_base_url) . '/theme-api/plugin-connect/sessions/' . rawurlencode($session_id) . '/poll',
            array(
                'pollToken' => $poll_token,
            )
        );

        if (empty($poll_response['success'])) {
            $status_code = isset($poll_response['status_code']) ? absint($poll_response['status_code']) : 0;
            if (in_array($status_code, array(404, 410), true)) {
                delete_transient($this->get_connect_transient_key($session_id));
                wp_send_json_success(
                    array(
                        'status' => 'expired',
                        'message' => __('Connection session is no longer available. Please start again.', 'gamequery-servers-lists'),
                    )
                );
            }

            wp_send_json_error(
                array(
                    'message' => !empty($poll_response['message'])
                        ? (string) $poll_response['message']
                        : __('Failed to poll GameQuery connection status.', 'gamequery-servers-lists'),
                ),
                502
            );
        }

        $poll_body = isset($poll_response['body']) && is_array($poll_response['body']) ? $poll_response['body'] : array();
        $status = isset($poll_body['status']) ? sanitize_key((string) $poll_body['status']) : '';

        if ('pending' === $status) {
            wp_send_json_success(
                array(
                    'status' => 'pending',
                )
            );
        }

        if ('cancelled' === $status || 'canceled' === $status) {
            delete_transient($this->get_connect_transient_key($session_id));
            wp_send_json_success(
                array(
                    'status' => 'cancelled',
                    'message' => __('Connection was cancelled from GameQuery.', 'gamequery-servers-lists'),
                )
            );
        }

        if ('expired' === $status || 'consumed' === $status) {
            delete_transient($this->get_connect_transient_key($session_id));
            wp_send_json_success(
                array(
                    'status' => 'expired',
                    'message' => __('Connection session has expired. Please start again.', 'gamequery-servers-lists'),
                )
            );
        }

        if ('approved' !== $status) {
            wp_send_json_error(
                array(
                    'message' => __('Unexpected connection status received from GameQuery.', 'gamequery-servers-lists'),
                ),
                502
            );
        }

        $authorization_code = isset($poll_body['authorizationCode']) ? sanitize_text_field((string) $poll_body['authorizationCode']) : '';
        if (!preg_match('/^pca_[a-f0-9]{32,128}$/', $authorization_code)) {
            wp_send_json_error(
                array(
                    'message' => __('Invalid authorization code received from GameQuery.', 'gamequery-servers-lists'),
                ),
                502
            );
        }

        $exchange_response = $this->request_remote_json(
            untrailingslashit($account_base_url) . '/theme-api/plugin-connect/exchange',
            array(
                'sessionId' => $session_id,
                'authorizationCode' => $authorization_code,
                'codeVerifier' => $code_verifier,
            )
        );

        if (empty($exchange_response['success'])) {
            $exchange_body = isset($exchange_response['body']) && is_array($exchange_response['body']) ? $exchange_response['body'] : array();
            $exchange_code = isset($exchange_body['code']) ? sanitize_key((string) $exchange_body['code']) : '';

            if ('invalid_grant' === $exchange_code) {
                wp_send_json_success(
                    array(
                        'status' => 'pending',
                    )
                );
            }

            wp_send_json_error(
                array(
                    'message' => !empty($exchange_response['message'])
                        ? (string) $exchange_response['message']
                        : __('Failed to finalize account connection.', 'gamequery-servers-lists'),
                ),
                502
            );
        }

        $exchange_body = isset($exchange_response['body']) && is_array($exchange_response['body']) ? $exchange_response['body'] : array();
        $credentials = isset($exchange_body['credentials']) && is_array($exchange_body['credentials']) ? $exchange_body['credentials'] : array();

        $email = isset($credentials['email']) ? sanitize_email((string) $credentials['email']) : '';
        $api_token = isset($credentials['apiToken']) ? sanitize_text_field((string) $credentials['apiToken']) : '';
        $plan = isset($credentials['plan']) ? WPGS_Settings::normalize_plan((string) $credentials['plan']) : 'FREE';
        $key_name = isset($credentials['keyName']) ? sanitize_text_field((string) $credentials['keyName']) : '';

        if (empty($email) || empty($api_token)) {
            wp_send_json_error(
                array(
                    'message' => __('GameQuery did not return valid credentials.', 'gamequery-servers-lists'),
                ),
                502
            );
        }

        $settings = WPGS_Settings::get_settings();
        $settings['email'] = $email;
        $settings['token'] = $api_token;
        $settings['plan'] = $plan;
        WPGS_Settings::update_settings($settings);

        $notice_message = '';
        if (!empty($key_name)) {
            $notice_message = sprintf(
                /* translators: 1: API email, 2: API key name */
                __('Connected GameQuery account %1$s using key "%2$s".', 'gamequery-servers-lists'),
                $email,
                $key_name
            );
        } else {
            $notice_message = sprintf(
                /* translators: %s: API email */
                __('Connected GameQuery account %s.', 'gamequery-servers-lists'),
                $email
            );
        }

        $this->set_admin_notice('success', $notice_message);

        delete_transient($this->get_connect_transient_key($session_id));

        wp_send_json_success(
            array(
                'status' => 'completed',
                'email' => $email,
                'plan' => $plan,
                'key_name' => $key_name,
            )
        );
    }

    /**
     * @return bool
     */
    private function verify_connect_ajax_request() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(
                array(
                    'message' => __('You do not have permission to perform this action.', 'gamequery-servers-lists'),
                ),
                403
            );
        }

        if (!check_ajax_referer(self::CONNECT_NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(
                array(
                    'message' => __('Security check failed. Please refresh and try again.', 'gamequery-servers-lists'),
                ),
                403
            );
        }

        return true;
    }

    /**
     * @param string $session_id
     * @return string
     */
    private function get_connect_transient_key($session_id) {
        return self::CONNECT_TRANSIENT_PREFIX . md5((string) $session_id);
    }

    /**
     * @param string $value
     * @return string
     */
    private function sanitize_connect_session_id($value) {
        $value = strtolower(trim($value));
        if (!preg_match('/^pcs_[a-f0-9]{32,96}$/', $value)) {
            return '';
        }

        return $value;
    }

    /**
     * @return string
     */
    private function build_pkce_verifier() {
        $random = random_bytes(32);
        return $this->base64url_encode($random);
    }

    /**
     * @param string $verifier
     * @return string
     */
    private function build_pkce_challenge($verifier) {
        return $this->base64url_encode(hash('sha256', (string) $verifier, true));
    }

    /**
     * @param string $value
     * @return string
     */
    private function base64url_encode($value) {
        $encoded = base64_encode((string) $value);
        $encoded = str_replace('+', '-', $encoded);
        $encoded = str_replace('/', '_', $encoded);
        return rtrim($encoded, '=');
    }

    /**
     * @param string $url
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request_remote_json($url, $payload) {
        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 20,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ),
                'body' => wp_json_encode($payload),
            )
        );

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'status_code' => 0,
                'message' => sanitize_text_field((string) $response->get_error_message()),
                'body' => array(),
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw_body, true);
        if (!is_array($decoded)) {
            $decoded = array();
        }

        $http_success = $status_code >= 200 && $status_code < 300;
        $app_success = !array_key_exists('success', $decoded) || !empty($decoded['success']);
        $success = $http_success && $app_success;

        $fallback_message = $http_success
            ? __('Unexpected response from GameQuery.', 'gamequery-servers-lists')
            : sprintf(
                /* translators: %d: HTTP status code */
                __('GameQuery responded with HTTP %d.', 'gamequery-servers-lists'),
                $status_code
            );

        return array(
            'success' => $success,
            'status_code' => $status_code,
            'message' => $this->extract_remote_message($decoded, $fallback_message),
            'body' => $decoded,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param string $fallback
     * @return string
     */
    private function extract_remote_message($body, $fallback) {
        if (isset($body['message']) && is_string($body['message']) && '' !== trim($body['message'])) {
            return sanitize_text_field((string) $body['message']);
        }

        if (isset($body['error_message']) && is_string($body['error_message']) && '' !== trim($body['error_message'])) {
            return sanitize_text_field((string) $body['error_message']);
        }

        return sanitize_text_field((string) $fallback);
    }

    /**
     * @param string $type
     * @param string $message
     */
    private function set_admin_notice($type, $message) {
        set_transient(
            'wpgs_admin_notice_' . get_current_user_id(),
            array(
                'type' => sanitize_key((string) $type),
                'message' => sanitize_text_field((string) $message),
            ),
            60
        );
    }

    public function render_admin_notices() {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $wpgs_screens = array(
            'edit-' . WPGS_Lists::POST_TYPE,
            WPGS_Lists::POST_TYPE,
            WPGS_Lists::POST_TYPE . '_page_' . self::SETTINGS_SLUG,
            WPGS_Lists::POST_TYPE . '_page_' . self::STATS_SLUG,
            self::MENU_SLUG . '_page_' . self::SETTINGS_SLUG,
            self::MENU_SLUG . '_page_' . self::STATS_SLUG,
        );

        if (!in_array($screen->id, $wpgs_screens, true)) {
            return;
        }

        $notice = get_transient('wpgs_admin_notice_' . get_current_user_id());
        if (is_array($notice) && !empty($notice['message'])) {
            $type = isset($notice['type']) ? (string) $notice['type'] : 'info';
            echo '<div class="notice ' . esc_attr($this->notice_class($type)) . '"><p>' . esc_html((string) $notice['message']) . '</p></div>';
            delete_transient('wpgs_admin_notice_' . get_current_user_id());
        }

        if (WPGS_Settings::should_warn_free_ttl()) {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('WPGS warning: FREE plan with cache TTL under 60 seconds can exhaust your daily quota quickly.', 'gamequery-servers-lists');
            echo '</p></div>';
        }

        if (WPGS_Settings::should_warn_free_quota()) {
            $daily_calls = (int) round(WPGS_Settings::estimate_daily_calls());
            echo '<div class="notice notice-warning"><p>';
            echo esc_html(sprintf('WPGS warning: current configuration estimates %d API calls/day on FREE plan, which exceeds the 1,440 daily limit.', $daily_calls));
            echo '</p></div>';
        }

        if (WPGS_Lists::POST_TYPE === $screen->id) {
            global $post;
            if ($post instanceof WP_Post && WPGS_Lists::POST_TYPE === $post->post_type) {
                $server_count = (int) get_post_meta($post->ID, WPGS_Lists::META_SERVER_TOTAL, true);
                if ($server_count > 1000) {
                    echo '<div class="notice notice-warning"><p>';
                    echo esc_html(sprintf('WPGS warning: this list has %d servers, but the API supports at most 1000 per request.', $server_count));
                    echo '</p></div>';
                }
            }
        }
    }

    /**
     * @param string $type
     * @return string
     */
    private function notice_class($type) {
        if ('error' === $type) {
            return 'notice-error';
        }

        if ('warning' === $type) {
            return 'notice-warning';
        }

        if ('success' === $type) {
            return 'notice-success is-dismissible';
        }

        return 'notice-info';
    }
}
