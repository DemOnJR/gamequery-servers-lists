<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPGS_Admin {
    const MENU_SLUG = 'wpgs';
    const LISTS_PAGE_SLUG = 'edit.php?post_type=wpgs_list';
    const SETTINGS_SLUG = 'wpgs-settings';
    const STATS_SLUG = 'wpgs-stats';

    public function register() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_post_wpgs_save_settings', array($this, 'handle_save_settings'));
        add_action('admin_notices', array($this, 'render_admin_notices'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    public function register_menu() {
        add_menu_page(
            __('WPGS', 'gamequery-server-lists'),
            __('WPGS', 'gamequery-server-lists'),
            'manage_options',
            self::LISTS_PAGE_SLUG,
            '',
            'dashicons-list-view',
            25
        );

        add_submenu_page(
            self::LISTS_PAGE_SLUG,
            __('Lists', 'gamequery-server-lists'),
            __('Lists', 'gamequery-server-lists'),
            'manage_options',
            self::LISTS_PAGE_SLUG,
            ''
        );

        add_submenu_page(
            self::LISTS_PAGE_SLUG,
            __('Settings', 'gamequery-server-lists'),
            __('Settings', 'gamequery-server-lists'),
            'manage_options',
            self::SETTINGS_SLUG,
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            self::LISTS_PAGE_SLUG,
            __('Stats', 'gamequery-server-lists'),
            __('Stats', 'gamequery-server-lists'),
            'manage_options',
            self::STATS_SLUG,
            array($this, 'render_stats_page')
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
        $frontend_css_path = WPGS_PLUGIN_DIR . 'assets/frontend.css';
        $admin_css_version = file_exists($admin_css_path) ? (string) filemtime($admin_css_path) : WPGS_VERSION;
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
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'gamequery-server-lists'));
        }

        check_admin_referer('wpgs_save_settings', 'wpgs_settings_nonce');

        $raw_settings = array();
        if (isset($_POST['wpgs_settings']) && is_array($_POST['wpgs_settings'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in WPGS_Settings::sanitize_settings().
            $raw_settings = wp_unslash($_POST['wpgs_settings']);
        }

        WPGS_Settings::update_settings($raw_settings);

        set_transient(
            'wpgs_admin_notice_' . get_current_user_id(),
            array(
                'type' => 'success',
                'message' => __('WPGS settings saved.', 'gamequery-server-lists'),
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
        $list_count = WPGS_Settings::get_published_list_count();
        $daily_calls = WPGS_Settings::estimate_daily_calls();
        $effective_ttl = WPGS_Settings::get_effective_cache_ttl();
        $last_error = WPGS_Settings::get_last_api_error();

        ?>
        <div class="wrap wpgs-admin-page">
            <h1><?php echo esc_html__('WPGS', 'gamequery-server-lists'); ?></h1>

            <div class="wpgs-settings-summary">
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Published Lists', 'gamequery-server-lists'); ?></strong>
                    <span><?php echo esc_html((string) $list_count); ?></span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Configured TTL', 'gamequery-server-lists'); ?></strong>
                    <span><?php echo esc_html((string) WPGS_Settings::get_cache_ttl_setting()); ?>s</span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Effective Cron Interval', 'gamequery-server-lists'); ?></strong>
                    <span><?php echo esc_html((string) $effective_ttl); ?>s</span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Estimated Calls / Day', 'gamequery-server-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n((int) round($daily_calls))); ?></span>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wpgs-settings-form">
                <input type="hidden" name="action" value="wpgs_save_settings" />
                <?php wp_nonce_field('wpgs_save_settings', 'wpgs_settings_nonce'); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="wpgs_email"><?php echo esc_html__('API Email', 'gamequery-server-lists'); ?></label></th>
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
                            <th scope="row"><label for="wpgs_token"><?php echo esc_html__('API Token', 'gamequery-server-lists'); ?></label></th>
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
                            <th scope="row"><label for="wpgs_plan"><?php echo esc_html__('Plan Type', 'gamequery-server-lists'); ?></label></th>
                            <td>
                                <select id="wpgs_plan" name="wpgs_settings[plan]">
                                    <option value="FREE" <?php selected('FREE', (string) $settings['plan']); ?>>FREE</option>
                                    <option value="PRO" <?php selected('PRO', (string) $settings['plan']); ?>>PRO</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpgs_api_base_url"><?php echo esc_html__('API Base URL', 'gamequery-server-lists'); ?></label></th>
                            <td>
                                <input
                                    type="url"
                                    id="wpgs_api_base_url"
                                    name="wpgs_settings[api_base_url]"
                                    value="<?php echo esc_attr((string) $settings['api_base_url']); ?>"
                                    class="regular-text code"
                                    placeholder="https://api.gamequery.dev/v1"
                                />
                                <p class="description"><?php echo esc_html__('The plugin calls POST /post/fetch against this base URL.', 'gamequery-server-lists'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpgs_cache_ttl"><?php echo esc_html__('Cache TTL (seconds)', 'gamequery-server-lists'); ?></label></th>
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
                                <p class="description"><?php echo esc_html__('Used for transient expiry and cron refresh interval. FREE plan uses a minimum effective interval of 60 seconds.', 'gamequery-server-lists'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Save Settings', 'gamequery-server-lists')); ?>
            </form>

            <?php if (!empty($last_error)) : ?>
                <div class="wpgs-last-error">
                    <h2><?php echo esc_html__('Last API Error', 'gamequery-server-lists'); ?></h2>
                    <p><strong><?php echo esc_html__('Code:', 'gamequery-server-lists'); ?></strong> <code><?php echo esc_html((string) $last_error['error_code']); ?></code></p>
                    <p><strong><?php echo esc_html__('Message:', 'gamequery-server-lists'); ?></strong> <?php echo esc_html((string) $last_error['error_message']); ?></p>
                    <?php if (!empty($last_error['status_code'])) : ?>
                        <p><strong><?php echo esc_html__('HTTP Status:', 'gamequery-server-lists'); ?></strong> <?php echo esc_html((string) $last_error['status_code']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($last_error['list_id'])) : ?>
                        <p><strong><?php echo esc_html__('List ID:', 'gamequery-server-lists'); ?></strong> <?php echo esc_html((string) $last_error['list_id']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($last_error['occurred_at'])) : ?>
                        <p><strong><?php echo esc_html__('Occurred At (UTC):', 'gamequery-server-lists'); ?></strong> <?php echo esc_html((string) $last_error['occurred_at']); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_stats_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $lists = get_posts(
            array(
                'post_type' => WPGS_Lists::POST_TYPE,
                'post_status' => array('publish', 'draft', 'pending', 'private'),
                'numberposts' => -1,
                'orderby' => 'date',
                'order' => 'DESC',
            )
        );

        $rows = array();
        $totals = array(
            'views_total' => 0,
            'views_unique' => 0,
            'clicks_total' => 0,
            'clicks_unique' => 0,
        );

        if (is_array($lists)) {
            foreach ($lists as $list_post) {
                if (!$list_post instanceof WP_Post) {
                    continue;
                }

                $stats = WPGS_Stats::get_list_stats($list_post->ID);
                $views_total = isset($stats['views_total']) ? (int) $stats['views_total'] : 0;
                $views_unique = isset($stats['views_unique']) ? (int) $stats['views_unique'] : 0;
                $clicks_total = isset($stats['clicks_total']) ? (int) $stats['clicks_total'] : 0;
                $clicks_unique = isset($stats['clicks_unique']) ? (int) $stats['clicks_unique'] : 0;

                $totals['views_total'] += $views_total;
                $totals['views_unique'] += $views_unique;
                $totals['clicks_total'] += $clicks_total;
                $totals['clicks_unique'] += $clicks_unique;

                $rows[] = array(
                    'id' => (int) $list_post->ID,
                    'title' => get_the_title($list_post->ID),
                    'edit_url' => get_edit_post_link($list_post->ID, ''),
                    'views_total' => $views_total,
                    'views_unique' => $views_unique,
                    'clicks_total' => $clicks_total,
                    'clicks_unique' => $clicks_unique,
                    'last_event' => isset($stats['last_event']) ? (string) $stats['last_event'] : '',
                );
            }
        }

        $ctr_total = 0.0;
        if ($totals['views_total'] > 0) {
            $ctr_total = ($totals['clicks_total'] / $totals['views_total']) * 100;
        }
        ?>
        <div class="wrap wpgs-admin-page">
            <h1><?php echo esc_html__('WPGS Stats', 'gamequery-server-lists'); ?></h1>

            <div class="wpgs-settings-summary">
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Total Views', 'gamequery-server-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($totals['views_total'])); ?></span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Unique Views', 'gamequery-server-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($totals['views_unique'])); ?></span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('Total Clicks', 'gamequery-server-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($totals['clicks_total'])); ?></span>
                </div>
                <div class="wpgs-summary-item">
                    <strong><?php echo esc_html__('CTR', 'gamequery-server-lists'); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($ctr_total, 2)); ?>%</span>
                </div>
            </div>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('List', 'gamequery-server-lists'); ?></th>
                        <th><?php echo esc_html__('Shortcode', 'gamequery-server-lists'); ?></th>
                        <th><?php echo esc_html__('Views', 'gamequery-server-lists'); ?></th>
                        <th><?php echo esc_html__('Unique Views', 'gamequery-server-lists'); ?></th>
                        <th><?php echo esc_html__('Clicks', 'gamequery-server-lists'); ?></th>
                        <th><?php echo esc_html__('Unique Clicks', 'gamequery-server-lists'); ?></th>
                        <th><?php echo esc_html__('CTR', 'gamequery-server-lists'); ?></th>
                        <th><?php echo esc_html__('Last Event (UTC)', 'gamequery-server-lists'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr>
                            <td colspan="8"><?php echo esc_html__('No lists found yet.', 'gamequery-server-lists'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($rows as $row) : ?>
                            <?php
                            $row_ctr = 0.0;
                            if ($row['views_total'] > 0) {
                                $row_ctr = ($row['clicks_total'] / $row['views_total']) * 100;
                            }
                            ?>
                            <tr>
                                <td>
                                    <?php $title = '' !== trim((string) $row['title']) ? (string) $row['title'] : __('(no title)', 'gamequery-server-lists'); ?>
                                    <?php if (!empty($row['edit_url'])) : ?>
                                        <a href="<?php echo esc_url((string) $row['edit_url']); ?>"><?php echo esc_html($title); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($title); ?>
                                    <?php endif; ?>
                                </td>
                                <td><code>[gamequery_<?php echo esc_html((string) $row['id']); ?>]</code></td>
                                <td><?php echo esc_html(number_format_i18n((int) $row['views_total'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int) $row['views_unique'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int) $row['clicks_total'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int) $row['clicks_unique'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n($row_ctr, 2)); ?>%</td>
                                <td><?php echo '' !== $row['last_event'] ? esc_html((string) $row['last_event']) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
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
            echo esc_html__('WPGS warning: FREE plan with cache TTL under 60 seconds can exhaust your daily quota quickly.', 'gamequery-server-lists');
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
