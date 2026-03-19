<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPGS_Lists {
    const POST_TYPE = 'wpgs_list';
    const META_GROUPS = '_wpgs_groups';
    const META_DISPLAY = '_wpgs_display';
    const GAMES_CATALOG_OPTION = 'wpgs_games_catalog';
    const GAMES_CATALOG_REFRESH_SECONDS = 7 * DAY_IN_SECONDS;
    const META_CAMPAIGN = '_wpgs_campaign';
    const META_CAMPAIGN_ENDED = '_wpgs_campaign_ended';
    const META_CAMPAIGN_ENDED_AT = '_wpgs_campaign_ended_at';
    const META_TEMPLATE = '_wpgs_template';
    const META_CUSTOM_CSS = '_wpgs_custom_css';
    const META_SERVER_TOTAL = '_wpgs_server_total';
    const META_LIMIT_EXCEEDED = '_wpgs_server_limit_exceeded';

    public function register() {
        add_action('init', array($this, 'register_post_type'));
        add_filter('manage_edit-' . self::POST_TYPE . '_columns', array($this, 'filter_columns'));
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array($this, 'render_column'), 10, 2);
        add_action('add_meta_boxes_' . self::POST_TYPE, array($this, 'add_meta_boxes'));
        add_action('save_post_' . self::POST_TYPE, array($this, 'save_meta_boxes'));
        add_action('before_delete_post', array($this, 'clear_cache_on_post_delete'));
        add_action('trashed_post', array($this, 'clear_cache_on_post_delete'));
    }

    public function register_post_type() {
        self::register_post_type_static();
    }

    public static function register_post_type_static() {
        $labels = array(
            'name' => __('WPGS Lists', 'gamequery-servers-lists'),
            'singular_name' => __('WPGS List', 'gamequery-servers-lists'),
            'add_new' => __('Add New List', 'gamequery-servers-lists'),
            'add_new_item' => __('Add New WPGS List', 'gamequery-servers-lists'),
            'edit_item' => __('Edit WPGS List', 'gamequery-servers-lists'),
            'new_item' => __('New WPGS List', 'gamequery-servers-lists'),
            'view_item' => __('View WPGS List', 'gamequery-servers-lists'),
            'search_items' => __('Search WPGS Lists', 'gamequery-servers-lists'),
            'not_found' => __('No lists found', 'gamequery-servers-lists'),
            'not_found_in_trash' => __('No lists found in Trash', 'gamequery-servers-lists'),
            'menu_name' => __('WPGS Lists', 'gamequery-servers-lists'),
        );

        register_post_type(
            self::POST_TYPE,
            array(
                'labels' => $labels,
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => false,
                'supports' => array('title'),
                'map_meta_cap' => true,
                'capability_type' => 'post',
                'menu_icon' => 'dashicons-list-view',
            )
        );
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function filter_columns($columns) {
        $result = array();

        if (isset($columns['cb'])) {
            $result['cb'] = $columns['cb'];
        }

        $result['title'] = __('Title', 'gamequery-servers-lists');
        $result['wpgs_shortcode'] = __('Shortcode', 'gamequery-servers-lists');
        $result['wpgs_groups'] = __('Groups', 'gamequery-servers-lists');
        $result['wpgs_servers'] = __('Servers', 'gamequery-servers-lists');
        $result['wpgs_views'] = __('Views', 'gamequery-servers-lists');
        $result['wpgs_clicks'] = __('Clicks', 'gamequery-servers-lists');
        $result['date'] = __('Date', 'gamequery-servers-lists');

        return $result;
    }

    /**
     * @param string $column
     * @param int $post_id
     */
    public function render_column($column, $post_id) {
        $post_id = absint($post_id);

        if ('wpgs_shortcode' === $column) {
            echo '<code>[gamequery_' . esc_html((string) $post_id) . ']</code><br />';
            echo '<code>[gamequery id=&quot;' . esc_html((string) $post_id) . '&quot;]</code>';
            return;
        }

        if ('wpgs_groups' === $column) {
            $groups = self::get_groups($post_id);
            echo esc_html((string) count($groups));
            return;
        }

        if ('wpgs_servers' === $column) {
            $groups = self::get_groups($post_id);
            $server_count = self::count_servers($groups);
            $is_limit_exceeded = $server_count > 1000;

            echo esc_html((string) $server_count);
            if ($is_limit_exceeded) {
                echo ' <span class="wpgs-pill wpgs-pill-warning">' . esc_html__('Limit exceeded', 'gamequery-servers-lists') . '</span>';
            }

            return;
        }

        if ('wpgs_views' === $column) {
            $total = (int) get_post_meta($post_id, WPGS_Stats::META_VIEWS_TOTAL, true);
            $unique = (int) get_post_meta($post_id, WPGS_Stats::META_VIEWS_UNIQUE, true);
            $this->render_stats_cell($post_id, $total, $unique);
            return;
        }

        if ('wpgs_clicks' === $column) {
            $total = (int) get_post_meta($post_id, WPGS_Stats::META_CLICKS_TOTAL, true);
            $unique = (int) get_post_meta($post_id, WPGS_Stats::META_CLICKS_UNIQUE, true);
            $this->render_stats_cell($post_id, $total, $unique);
        }
    }

    /**
     * @param int $post_id
     * @param int $total
     * @param int $unique
     */
    private function render_stats_cell($post_id, $total, $unique) {
        $post_id = absint($post_id);
        $total = max(0, (int) $total);
        $unique = max(0, (int) $unique);

        $stats_url = add_query_arg(
            array(
                'post_type' => self::POST_TYPE,
                'page' => 'wpgs-stats',
                'list_id' => $post_id,
            ),
            admin_url('edit.php')
        );

        echo '<a href="' . esc_url($stats_url) . '" class="wpgs-stats-cell-link">';
        echo '<strong>' . esc_html((string) $total) . '</strong>';
        echo '<br />';
        echo '<span class="description">' . esc_html__('Unique:', 'gamequery-servers-lists') . ' ' . esc_html((string) $unique) . '</span>';
        echo '</a>';
    }

    /**
     * @param WP_Post $post
     */
    public function add_meta_boxes($post) {
        add_meta_box(
            'wpgs_template_metabox',
            __('Choose Template', 'gamequery-servers-lists'),
            array($this, 'render_template_metabox'),
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'wpgs_groups_metabox',
            __('Server Groups', 'gamequery-servers-lists'),
            array($this, 'render_groups_metabox'),
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'wpgs_campaign_metabox',
            __('Campaign Goal', 'gamequery-servers-lists'),
            array($this, 'render_campaign_metabox'),
            self::POST_TYPE,
            'side',
            'default'
        );

        add_meta_box(
            'wpgs_display_metabox',
            __('Display Fields', 'gamequery-servers-lists'),
            array($this, 'render_display_metabox'),
            self::POST_TYPE,
            'side',
            'default'
        );

        add_meta_box(
            'wpgs_css_metabox',
            __('Custom CSS', 'gamequery-servers-lists'),
            array($this, 'render_css_metabox'),
            self::POST_TYPE,
            'normal',
            'default'
        );
    }

    /**
     * @param WP_Post $post
     */
    public function render_template_metabox($post) {
        $current_template = self::get_template($post->ID);
        $templates = self::get_templates();
        $template_categories = self::get_template_categories();

        echo '<input type="hidden" id="wpgs_template" name="wpgs_template" value="' . esc_attr($current_template) . '" />';
        echo '<div class="wpgs-template-browser">';

        echo '<div class="wpgs-template-controls">';
        echo '<div class="wpgs-template-control wpgs-template-control-category">';
        echo '<select id="wpgs-template-category" aria-label="' . esc_attr__('Filter templates by category', 'gamequery-servers-lists') . '">';
        echo '<option value="">' . esc_html__('All categories', 'gamequery-servers-lists') . '</option>';
        foreach ($template_categories as $category_key => $category_label) {
            echo '<option value="' . esc_attr($category_key) . '">' . esc_html($category_label) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="wpgs-template-control wpgs-template-control-search">';
        echo '<input type="search" id="wpgs-template-search" class="regular-text" placeholder="' . esc_attr__('Search templates...', 'gamequery-servers-lists') . '" aria-label="' . esc_attr__('Search templates', 'gamequery-servers-lists') . '" />';
        echo '</div>';
        echo '</div>';

        echo '<div class="wpgs-template-grid" id="wpgs-template-grid">';
        foreach ($templates as $template_key => $template) {
            $this->render_template_card($template_key, $template, $current_template);
        }
        echo '</div>';
        echo '<p id="wpgs-template-empty" class="description wpgs-template-empty" style="display:none;">' . esc_html__('No templates match your current filters.', 'gamequery-servers-lists') . '</p>';

        echo '</div>';

        $this->render_template_live_preview($current_template, $templates);

        $this->render_template_metabox_script();
    }

    /**
     * @param WP_Post $post
     */
    public function render_groups_metabox($post) {
        wp_nonce_field('wpgs_save_list_meta', 'wpgs_list_nonce');

        $groups = self::get_groups($post->ID);
        $games_catalog = self::get_games_catalog();
        if (empty($groups)) {
            $groups = array(
                array(
                    'game_id' => '',
                    'servers' => array(''),
                ),
            );
        }

        if (!empty($games_catalog)) {
            echo '<p>' . esc_html__('Add one or more game groups. Search by game name and select it from the list, or use manual Game ID override.', 'gamequery-servers-lists') . '</p>';
            echo '<p class="description">' . esc_html__('Game catalog is stored in WordPress database and refreshed every 7 days.', 'gamequery-servers-lists') . '</p>';
        } else {
            echo '<p>' . esc_html__('Add one or more game groups. Each group has one game ID and one or more server addresses.', 'gamequery-servers-lists') . '</p>';
        }

        echo '<div id="wpgs-groups" class="wpgs-groups">';

        foreach ($groups as $index => $group) {
            $this->render_group_row((int) $index, $group, $games_catalog);
        }

        echo '</div>';

        echo '<p><button type="button" class="button" id="wpgs-add-group">' . esc_html__('Add Group', 'gamequery-servers-lists') . '</button></p>';
        if (!empty($games_catalog)) {
            echo '<p class="description">' . esc_html__('Tip: Use the search box to filter games quickly. Manual Game ID takes priority if both are filled.', 'gamequery-servers-lists') . '</p>';
        }
        echo '<p class="description">' . esc_html__('Server format: IP:PORT (example: 127.0.0.1:27015). One server per line or comma-separated.', 'gamequery-servers-lists') . '</p>';

        $this->render_groups_metabox_script();
    }

    /**
     * @param string $template_key
     * @param array<string, mixed> $template
     * @param string $current_template
     */
    private function render_template_card($template_key, $template, $current_template) {
        $template_key = sanitize_key($template_key);
        $label = isset($template['label']) ? (string) $template['label'] : ucfirst(str_replace('-', ' ', $template_key));
        $description = isset($template['description']) ? (string) $template['description'] : '';
        $category = isset($template['category']) ? sanitize_key((string) $template['category']) : 'general';
        $category_label = isset($template['category_label']) ? (string) $template['category_label'] : self::format_filter_label($category);

        $is_selected = $template_key === $current_template;
        $search_blob = strtolower($label . ' ' . $description . ' ' . $category_label);
        $preview_theme = self::get_card_theme_for_template($template_key);
        $preview_wrap = self::get_card_theme_wrap_for_template($template_key);
        $preview_mode = self::get_preview_mode_for_template($template_key);

        echo '<button type="button" class="wpgs-template-card wpgs-template-card--' . esc_attr($template_key) . ($is_selected ? ' is-selected' : '') . '"';
        echo ' data-template-key="' . esc_attr($template_key) . '"';
        echo ' data-category="' . esc_attr($category) . '"';
        echo ' data-label="' . esc_attr($label) . '"';
        echo ' data-preview-mode="' . esc_attr($preview_mode) . '"';
        echo ' data-preview-theme="' . esc_attr($preview_theme) . '"';
        echo ' data-preview-wrap="' . esc_attr($preview_wrap) . '"';
        echo ' data-search="' . esc_attr($search_blob) . '">';
        echo '<span class="wpgs-template-preview">';
        echo '<span class="wpgs-template-preview-head"></span>';
        echo '<span class="wpgs-template-preview-row"></span>';
        echo '<span class="wpgs-template-preview-row"></span>';
        echo '<span class="wpgs-template-preview-row"></span>';
        echo '</span>';
        echo '<span class="wpgs-template-card-body">';
        echo '<span class="wpgs-template-card-title">' . esc_html($label) . '</span>';
        echo '<span class="wpgs-template-card-category">' . esc_html($category_label) . '</span>';
        if ('' !== $description) {
            echo '<span class="wpgs-template-card-description">' . esc_html($description) . '</span>';
        }
        echo '</span>';
        echo '</button>';
    }

    /**
     * @param string $current_template
     * @param array<string, array<string, mixed>> $templates
     */
    private function render_template_live_preview($current_template, $templates) {
        $current_template = self::sanitize_template($current_template);
        $current_label = isset($templates[$current_template]['label']) ? (string) $templates[$current_template]['label'] : self::format_filter_label($current_template);
        $active_preview_mode = self::get_preview_mode_for_template($current_template);

        echo '<div class="wpgs-template-live-preview" id="wpgs-template-live-preview">';
        echo '<div class="wpgs-template-live-head">';
        echo '<strong>' . esc_html__('Live Preview', 'gamequery-servers-lists') . '</strong>';
        echo '<span id="wpgs-template-live-label">' . esc_html($current_label) . '</span>';
        echo '</div>';

        $is_card_theme_pane = 'card-theme' === $active_preview_mode;
        echo '<div class="wpgs-template-preview-pane' . ($is_card_theme_pane ? ' is-active' : '') . '" data-preview-pane="card-theme"' . ($is_card_theme_pane ? '' : ' hidden') . '>';
        echo '<div class="wpgs-template-live-card-shell" id="wpgs-template-live-card-shell">';
        echo '<div class="wpgs-list wpgs-card-theme-layout" id="wpgs-template-live-list">';
        echo '<div class="wpgs-card">';
        echo '<div class="wpgs-card-main">';
        echo '<span class="wpgs-server-name">Dust2 Classic #1</span>';
        echo '<span class="wpgs-server-address">127.0.0.1:27015</span>';
        echo '</div>';
        echo '<div class="wpgs-card-status">';
        echo '<span class="wpgs-status wpgs-status-online"><span class="wpgs-status-dot"></span>' . esc_html__('Online', 'gamequery-servers-lists') . '</span>';
        echo '</div>';
        echo '<div class="wpgs-card-meta">';
        echo '<span class="wpgs-meta-pill"><span class="wpgs-meta-label">' . esc_html__('Map', 'gamequery-servers-lists') . '</span>de_dust2</span>';
        echo '<span class="wpgs-meta-pill"><span class="wpgs-meta-label">' . esc_html__('Players', 'gamequery-servers-lists') . '</span>12</span>';
        echo '<span class="wpgs-meta-pill"><span class="wpgs-meta-label">' . esc_html__('Max', 'gamequery-servers-lists') . '</span>32</span>';
        echo '</div>';
        echo '</div>';
        echo '<div class="wpgs-card">';
        echo '<div class="wpgs-card-main">';
        echo '<span class="wpgs-server-name">Mirage Ranked</span>';
        echo '<span class="wpgs-server-address">127.0.0.1:27016</span>';
        echo '</div>';
        echo '<div class="wpgs-card-status">';
        echo '<span class="wpgs-status wpgs-status-offline"><span class="wpgs-status-dot"></span>' . esc_html__('Offline', 'gamequery-servers-lists') . '</span>';
        echo '</div>';
        echo '<div class="wpgs-card-meta">';
        echo '<span class="wpgs-meta-pill"><span class="wpgs-meta-label">' . esc_html__('Map', 'gamequery-servers-lists') . '</span>de_mirage</span>';
        echo '<span class="wpgs-meta-pill"><span class="wpgs-meta-label">' . esc_html__('Players', 'gamequery-servers-lists') . '</span>21</span>';
        echo '<span class="wpgs-meta-pill"><span class="wpgs-meta-label">' . esc_html__('Max', 'gamequery-servers-lists') . '</span>32</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        $is_table_pane = 'table' === $active_preview_mode;
        echo '<div class="wpgs-template-preview-pane' . ($is_table_pane ? ' is-active' : '') . '" data-preview-pane="table"' . ($is_table_pane ? '' : ' hidden') . '>';
        echo '<div class="wpgs-table-wrapper wpgs-template-live-table-wrap" id="wpgs-template-live-table-wrap">';
        echo '<table class="wpgs-table">';
        echo '<thead><tr><th>' . esc_html__('Name', 'gamequery-servers-lists') . '</th><th>' . esc_html__('Address', 'gamequery-servers-lists') . '</th><th>' . esc_html__('Players', 'gamequery-servers-lists') . '</th><th>' . esc_html__('Status', 'gamequery-servers-lists') . '</th></tr></thead>';
        echo '<tbody>';
        echo '<tr><td>Dust2 Classic #1</td><td>127.0.0.1:27015</td><td>12</td><td><span class="wpgs-status wpgs-status-online">online</span></td></tr>';
        echo '<tr><td>Mirage Ranked</td><td>127.0.0.1:27016</td><td>21</td><td><span class="wpgs-status wpgs-status-offline">offline</span></td></tr>';
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';

        $is_strip_pane = 'strip' === $active_preview_mode;
        echo '<div class="wpgs-template-preview-pane' . ($is_strip_pane ? ' is-active' : '') . '" data-preview-pane="strip"' . ($is_strip_pane ? '' : ' hidden') . '>';
        echo '<div class="wpgs-list wpgs-layout-strip">';
        echo '<div class="wpgs-card"><span class="wpgs-dot-col wpgs-dot-online"></span><div class="wpgs-name-col"><span class="wpgs-server-name">Dust2 Classic #1</span><span class="wpgs-server-address">127.0.0.1:27015</span></div><div class="wpgs-meta-row"><span class="wpgs-meta-item"><strong>12</strong> / 32</span><span class="wpgs-meta-item">de_dust2</span></div><span class="wpgs-status-text online">Online</span></div>';
        echo '<div class="wpgs-card"><span class="wpgs-dot-col wpgs-dot-offline"></span><div class="wpgs-name-col"><span class="wpgs-server-name">Mirage Ranked</span><span class="wpgs-server-address">127.0.0.1:27016</span></div><div class="wpgs-meta-row"><span class="wpgs-meta-item"><strong>21</strong> / 32</span><span class="wpgs-meta-item">de_mirage</span></div><span class="wpgs-status-text offline">Offline</span></div>';
        echo '</div>';
        echo '</div>';

        $is_sidebar_compact_pane = 'sidebar-compact' === $active_preview_mode;
        echo '<div class="wpgs-template-preview-pane' . ($is_sidebar_compact_pane ? ' is-active' : '') . '" data-preview-pane="sidebar-compact"' . ($is_sidebar_compact_pane ? '' : ' hidden') . '>';
        echo '<div class="wpgs-list wpgs-layout-sidebar-compact">';
        echo '<div class="wpgs-card"><span class="wpgs-dot wpgs-dot-online"></span><div class="wpgs-card-body"><span class="wpgs-server-name">Dust2 Classic #1</span><span class="wpgs-server-address">127.0.0.1:27015</span></div><span class="wpgs-players">12/32</span></div>';
        echo '<div class="wpgs-card"><span class="wpgs-dot wpgs-dot-offline"></span><div class="wpgs-card-body"><span class="wpgs-server-name">Mirage Ranked</span><span class="wpgs-server-address">127.0.0.1:27016</span></div><span class="wpgs-players">21/32</span></div>';
        echo '</div>';
        echo '</div>';

        $is_sidebar_dark_pane = 'sidebar-dark' === $active_preview_mode;
        echo '<div class="wpgs-template-preview-pane' . ($is_sidebar_dark_pane ? ' is-active' : '') . '" data-preview-pane="sidebar-dark"' . ($is_sidebar_dark_pane ? '' : ' hidden') . '>';
        echo '<div class="wpgs-layout-sidebar-dark-wrap">';
        echo '<div class="wpgs-list wpgs-layout-sidebar-dark">';
        echo '<div class="wpgs-card"><span class="wpgs-dot wpgs-dot-online"></span><div class="wpgs-card-body"><span class="wpgs-server-name">Dust2 Classic #1</span><span class="wpgs-server-address">127.0.0.1:27015</span></div><span class="wpgs-players">12/32</span></div>';
        echo '<div class="wpgs-card"><span class="wpgs-dot wpgs-dot-offline"></span><div class="wpgs-card-body"><span class="wpgs-server-name">Mirage Ranked</span><span class="wpgs-server-address">127.0.0.1:27016</span></div><span class="wpgs-players">21/32</span></div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        $is_grid_cards_pane = 'grid-cards' === $active_preview_mode;
        echo '<div class="wpgs-template-preview-pane' . ($is_grid_cards_pane ? ' is-active' : '') . '" data-preview-pane="grid-cards"' . ($is_grid_cards_pane ? '' : ' hidden') . '>';
        echo '<div class="wpgs-list wpgs-layout-grid-cards">';
        echo '<div class="wpgs-card"><div class="wpgs-card-top"><span class="wpgs-server-name">Dust2 Classic #1</span><span class="wpgs-status wpgs-status-online"><span class="wpgs-status-dot"></span>Online</span></div><span class="wpgs-server-address">127.0.0.1:27015</span><div class="wpgs-card-footer"><span class="wpgs-meta-pill"><span class="wpgs-meta-label">Players</span>12</span><span class="wpgs-meta-pill"><span class="wpgs-meta-label">Max</span>32</span></div></div>';
        echo '<div class="wpgs-card"><div class="wpgs-card-top"><span class="wpgs-server-name">Mirage Ranked</span><span class="wpgs-status wpgs-status-offline"><span class="wpgs-status-dot"></span>Offline</span></div><span class="wpgs-server-address">127.0.0.1:27016</span><div class="wpgs-card-footer"><span class="wpgs-meta-pill"><span class="wpgs-meta-label">Players</span>21</span><span class="wpgs-meta-pill"><span class="wpgs-meta-label">Map</span>de_mirage</span></div></div>';
        echo '</div>';
        echo '</div>';

        $is_minimal_list_pane = 'minimal-list' === $active_preview_mode;
        echo '<div class="wpgs-template-preview-pane' . ($is_minimal_list_pane ? ' is-active' : '') . '" data-preview-pane="minimal-list"' . ($is_minimal_list_pane ? '' : ' hidden') . '>';
        echo '<div class="wpgs-list wpgs-layout-minimal-list">';
        echo '<div class="wpgs-card"><span class="wpgs-indicator wpgs-indicator-online"></span><div class="wpgs-card-main"><span class="wpgs-server-name">Dust2 Classic #1</span><div class="wpgs-server-meta"><span class="wpgs-server-address">127.0.0.1:27015</span><span class="wpgs-sep">&middot;</span><span class="wpgs-map-val">de_dust2</span></div></div><span class="wpgs-players-val">12 / 32</span></div>';
        echo '<div class="wpgs-card"><span class="wpgs-indicator wpgs-indicator-offline"></span><div class="wpgs-card-main"><span class="wpgs-server-name">Mirage Ranked</span><div class="wpgs-server-meta"><span class="wpgs-server-address">127.0.0.1:27016</span><span class="wpgs-sep">&middot;</span><span class="wpgs-map-val">de_mirage</span></div></div><span class="wpgs-players-val">21 / 32</span></div>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * @param int $index
     * @param array<string, mixed> $group
     * @param array<string, string> $games_catalog
     */
    private function render_group_row($index, $group, $games_catalog = array()) {
        $game_id = isset($group['game_id']) ? (string) $group['game_id'] : '';
        $servers = isset($group['servers']) && is_array($group['servers']) ? $group['servers'] : array();
        $servers_value = implode("\n", array_map('strval', $servers));
        $selected_game_id = isset($games_catalog[$game_id]) ? $game_id : '';
        $manual_game_id = '' === $selected_game_id ? $game_id : '';

        echo '<div class="wpgs-group-row" data-index="' . esc_attr((string) $index) . '">';
        echo '<div class="wpgs-group-row-head">';
        echo '<strong class="wpgs-group-title">' . esc_html__('Group', 'gamequery-servers-lists') . ' #' . esc_html((string) ($index + 1)) . '</strong>';
        echo '<button type="button" class="button-link-delete wpgs-remove-group">' . esc_html__('Remove', 'gamequery-servers-lists') . '</button>';
        echo '</div>';

        if (!empty($games_catalog)) {
            echo '<p>';
            echo '<label><strong>' . esc_html__('Game', 'gamequery-servers-lists') . '</strong></label>';
            echo '<input type="search" class="widefat wpgs-game-search" placeholder="' . esc_attr__('Search games...', 'gamequery-servers-lists') . '" />';
            echo '</p>';

            echo '<p>';
            echo '<select class="widefat wpgs-game-select" name="wpgs_groups[' . esc_attr((string) $index) . '][game_id]">';
            echo '<option value="">' . esc_html__('Select a game...', 'gamequery-servers-lists') . '</option>';
            foreach ($games_catalog as $catalog_game_id => $catalog_game_name) {
                $option_label = $catalog_game_name . ' (' . $catalog_game_id . ')';
                echo '<option value="' . esc_attr($catalog_game_id) . '"' . selected($selected_game_id, $catalog_game_id, false) . '>' . esc_html($option_label) . '</option>';
            }
            echo '</select>';
            echo '</p>';

            echo '<p>';
            echo '<label><strong>' . esc_html__('Game ID (manual override)', 'gamequery-servers-lists') . '</strong></label>';
            echo '<input type="text" class="widefat wpgs-game-id-manual" name="wpgs_groups[' . esc_attr((string) $index) . '][game_id_manual]" value="' . esc_attr($manual_game_id) . '" placeholder="minecraft" />';
            echo '</p>';
        } else {
            echo '<p>';
            echo '<label><strong>' . esc_html__('Game ID', 'gamequery-servers-lists') . '</strong></label>';
            echo '<input type="text" class="widefat" name="wpgs_groups[' . esc_attr((string) $index) . '][game_id]" value="' . esc_attr($game_id) . '" placeholder="counterstrike16" />';
            echo '</p>';
        }

        echo '<p>';
        echo '<label><strong>' . esc_html__('Servers', 'gamequery-servers-lists') . '</strong></label>';
        echo '<textarea class="widefat code" rows="6" name="wpgs_groups[' . esc_attr((string) $index) . '][servers]" placeholder="127.0.0.1:27015">' . esc_textarea($servers_value) . '</textarea>';
        echo '</p>';
        echo '</div>';
    }

    private function render_template_metabox_script() {
        ?>
        <script>
            (function () {
                const templateInput = document.getElementById('wpgs_template');
                const templateSearch = document.getElementById('wpgs-template-search');
                const templateCategory = document.getElementById('wpgs-template-category');
                const templateEmpty = document.getElementById('wpgs-template-empty');
                const templateLiveWrap = document.getElementById('wpgs-template-live-card-shell');
                const templateLiveList = document.getElementById('wpgs-template-live-list');
                const templateLiveTableWrap = document.getElementById('wpgs-template-live-table-wrap');
                const templateLiveLabel = document.getElementById('wpgs-template-live-label');
                const templatePreviewPanes = Array.prototype.slice.call(document.querySelectorAll('.wpgs-template-preview-pane'));
                const templateCards = Array.prototype.slice.call(document.querySelectorAll('.wpgs-template-card'));

                function cardByTemplateKey(templateKey) {
                    for (let i = 0; i < templateCards.length; i += 1) {
                        if (String(templateCards[i].dataset.templateKey || '') === templateKey) {
                            return templateCards[i];
                        }
                    }

                    return null;
                }

                function clearThemeClasses(element, prefix) {
                    if (!element) {
                        return;
                    }

                    Array.prototype.slice.call(element.classList).forEach(function (className) {
                        if (className.indexOf(prefix) === 0) {
                            element.classList.remove(className);
                        }
                    });
                }

                function hasPreviewPane(mode) {
                    for (let i = 0; i < templatePreviewPanes.length; i += 1) {
                        if (String(templatePreviewPanes[i].dataset.previewPane || '') === String(mode || '')) {
                            return true;
                        }
                    }

                    return false;
                }

                function showPreviewPane(mode) {
                    templatePreviewPanes.forEach(function (pane) {
                        const isActive = String(pane.dataset.previewPane || '') === mode;
                        pane.classList.toggle('is-active', isActive);
                        pane.hidden = !isActive;
                    });
                }

                function updateLivePreview(templateKey) {
                    if (!templateCards.length) {
                        return;
                    }

                    const card = cardByTemplateKey(templateKey);
                    const label = card ? String(card.dataset.label || '') : '';
                    const previewMode = card ? String(card.dataset.previewMode || '') : '';
                    const previewTheme = card ? String(card.dataset.previewTheme || '') : '';
                    const previewWrap = card ? String(card.dataset.previewWrap || '') : '';
                    const resolvedMode = previewMode || (previewTheme ? 'card-theme' : 'table');
                    const resolvedTheme = previewTheme || 'clean';
                    const activeMode = hasPreviewPane(resolvedMode) ? resolvedMode : 'table';

                    if (templateLiveLabel) {
                        templateLiveLabel.textContent = label;
                    }

                    showPreviewPane(activeMode);

                    if (templateLiveWrap) {
                        templateLiveWrap.classList.remove('wpgs-glass-wrap', 'wpgs-frosted-wrap');
                        if ('card-theme' === activeMode && previewWrap) {
                            templateLiveWrap.classList.add(previewWrap);
                        }
                    }

                    if (templateLiveList) {
                        clearThemeClasses(templateLiveList, 'wpgs-theme-');
                        if ('card-theme' === activeMode) {
                            templateLiveList.classList.add('wpgs-theme-' + resolvedTheme);
                        }
                    }

                    if (templateLiveTableWrap) {
                        clearThemeClasses(templateLiveTableWrap, 'wpgs-template-');
                        if ('table' === activeMode) {
                            templateLiveTableWrap.classList.add('wpgs-template-' + String(templateKey || 'classic'));
                        }
                    }
                }

                function selectTemplate(templateKey) {
                    if (!templateInput || !templateKey) {
                        return;
                    }

                    templateInput.value = templateKey;
                    templateCards.forEach(function (card) {
                        card.classList.toggle('is-selected', card.dataset.templateKey === templateKey);
                    });

                    updateLivePreview(templateKey);
                }

                function filterTemplateCards() {
                    if (!templateCards.length) {
                        return;
                    }

                    const query = templateSearch ? templateSearch.value.trim().toLowerCase() : '';
                    const category = templateCategory ? templateCategory.value : '';
                    let visibleCount = 0;

                    templateCards.forEach(function (card) {
                        const searchBlob = String(card.dataset.search || '').toLowerCase();
                        const cardCategory = String(card.dataset.category || '');

                        const matchesQuery = !query || searchBlob.indexOf(query) !== -1;
                        const matchesCategory = !category || cardCategory === category;
                        const isVisible = matchesQuery && matchesCategory;
                        card.style.display = isVisible ? '' : 'none';
                        if (isVisible) {
                            visibleCount += 1;
                        }
                    });

                    if (templateEmpty) {
                        templateEmpty.style.display = visibleCount > 0 ? 'none' : '';
                    }
                }

                templateCards.forEach(function (card) {
                    card.addEventListener('click', function () {
                        selectTemplate(String(card.dataset.templateKey || ''));
                    });
                });

                if (templateSearch) {
                    templateSearch.addEventListener('input', filterTemplateCards);
                }

                if (templateCategory) {
                    templateCategory.addEventListener('change', filterTemplateCards);
                }

                filterTemplateCards();

                if (templateInput && templateInput.value) {
                    updateLivePreview(String(templateInput.value));
                } else if (templateCards.length) {
                    updateLivePreview(String(templateCards[0].dataset.templateKey || ''));
                }
            }());
        </script>
        <?php
    }

    private function render_groups_metabox_script() {
        ?>
        <script>
            (function () {
                const container = document.getElementById('wpgs-groups');
                const addButton = document.getElementById('wpgs-add-group');
                if (!container || !addButton) {
                    return;
                }

                function updateRemoveButtons() {
                    const rows = container.querySelectorAll('.wpgs-group-row');
                    rows.forEach(function (row) {
                        const button = row.querySelector('.wpgs-remove-group');
                        if (button) {
                            button.disabled = rows.length === 1;
                        }
                    });
                }

                function reindexRows() {
                    const rows = container.querySelectorAll('.wpgs-group-row');
                    rows.forEach(function (row, index) {
                        row.dataset.index = String(index);
                        const title = row.querySelector('.wpgs-group-title');
                        if (title) {
                            title.textContent = 'Group #' + String(index + 1);
                        }

                        row.querySelectorAll('input, textarea, select').forEach(function (field) {
                            if (!field.name) {
                                return;
                            }

                            field.name = field.name.replace(/wpgs_groups\[\d+\]/, 'wpgs_groups[' + String(index) + ']');
                        });
                    });

                    updateRemoveButtons();
                }

                function attachRemoveHandlers() {
                    container.querySelectorAll('.wpgs-remove-group').forEach(function (button) {
                        if (button.dataset.bound === '1') {
                            return;
                        }

                        button.dataset.bound = '1';
                        button.addEventListener('click', function () {
                            const rows = container.querySelectorAll('.wpgs-group-row');
                            if (rows.length <= 1) {
                                return;
                            }

                            const row = this.closest('.wpgs-group-row');
                            if (row) {
                                row.remove();
                                reindexRows();
                            }
                        });
                    });
                }

                function bindGameSelectors() {
                    container.querySelectorAll('.wpgs-group-row').forEach(function (row) {
                        const searchField = row.querySelector('.wpgs-game-search');
                        const selectField = row.querySelector('.wpgs-game-select');
                        const manualField = row.querySelector('.wpgs-game-id-manual');

                        if (!searchField || !selectField) {
                            return;
                        }

                        if (selectField.dataset.optionSource !== '1') {
                            const options = Array.prototype.slice.call(selectField.options).map(function (option) {
                                return {
                                    value: option.value,
                                    text: option.textContent || option.innerText || '',
                                };
                            });

                            try {
                                selectField.dataset.options = JSON.stringify(options);
                                selectField.dataset.optionSource = '1';
                            } catch (error) {
                                return;
                            }
                        }

                        function getOriginalOptions() {
                            if (!selectField.dataset.options) {
                                return [];
                            }

                            try {
                                const parsed = JSON.parse(selectField.dataset.options);
                                return Array.isArray(parsed) ? parsed : [];
                            } catch (error) {
                                return [];
                            }
                        }

                        function restoreSelectOptions(filterText) {
                            const currentValue = selectField.value;
                            const normalizedFilter = String(filterText || '').trim().toLowerCase();
                            const options = getOriginalOptions();

                            if (!options.length) {
                                return;
                            }

                            selectField.innerHTML = '';

                            options.forEach(function (option, index) {
                                const optionValue = String(option.value || '');
                                const optionText = String(option.text || '');

                                if (index > 0 && '' !== normalizedFilter) {
                                    const optionSearch = (optionText + ' ' + optionValue).toLowerCase();
                                    if (-1 === optionSearch.indexOf(normalizedFilter)) {
                                        return;
                                    }
                                }

                                const optionNode = document.createElement('option');
                                optionNode.value = optionValue;
                                optionNode.textContent = optionText;
                                if (optionValue === currentValue) {
                                    optionNode.selected = true;
                                }

                                selectField.appendChild(optionNode);
                            });

                            if (currentValue && !Array.prototype.slice.call(selectField.options).some(function (option) {
                                return option.value === currentValue;
                            })) {
                                const fallback = options.find(function (option) {
                                    return String(option.value || '') === currentValue;
                                });

                                if (fallback) {
                                    const optionNode = document.createElement('option');
                                    optionNode.value = String(fallback.value || '');
                                    optionNode.textContent = String(fallback.text || '');
                                    optionNode.selected = true;
                                    selectField.appendChild(optionNode);
                                }
                            }
                        }

                        if (searchField.dataset.boundGameSearch !== '1') {
                            searchField.dataset.boundGameSearch = '1';
                            searchField.addEventListener('input', function () {
                                restoreSelectOptions(searchField.value || '');
                            });
                        }

                        if (selectField.dataset.boundGameSelect !== '1') {
                            selectField.dataset.boundGameSelect = '1';
                            selectField.addEventListener('change', function () {
                                if (!manualField || !selectField.value) {
                                    return;
                                }

                                manualField.value = '';
                            });
                        }

                        if (manualField && manualField.dataset.boundManualInput !== '1') {
                            manualField.dataset.boundManualInput = '1';
                            manualField.addEventListener('input', function () {
                                if ('' !== String(manualField.value || '').trim()) {
                                    selectField.value = '';
                                }
                            });
                        }

                        restoreSelectOptions(searchField.value || '');
                    });
                }

                function clearCloneState(clone) {
                    clone.querySelectorAll('[data-bound], [data-bound-game-search], [data-bound-game-select], [data-bound-manual-input]').forEach(function (node) {
                        delete node.dataset.bound;
                        delete node.dataset.boundGameSearch;
                        delete node.dataset.boundGameSelect;
                        delete node.dataset.boundManualInput;
                    });

                    clone.querySelectorAll('.wpgs-game-select').forEach(function (selectField) {
                        delete selectField.dataset.optionSource;
                        delete selectField.dataset.options;
                    });
                }

                addButton.addEventListener('click', function () {
                    const rows = container.querySelectorAll('.wpgs-group-row');
                    if (!rows.length) {
                        return;
                    }

                    const clone = rows[rows.length - 1].cloneNode(true);
                    clearCloneState(clone);

                    clone.querySelectorAll('input, textarea, select').forEach(function (field) {
                        if ('SELECT' === field.tagName) {
                            field.selectedIndex = 0;
                            return;
                        }

                        field.value = '';
                    });

                    container.appendChild(clone);
                    attachRemoveHandlers();
                    bindGameSelectors();
                    reindexRows();
                });

                attachRemoveHandlers();
                bindGameSelectors();
                reindexRows();
            }());
        </script>
        <?php
    }

    /**
     * @param WP_Post $post
     */
    public function render_campaign_metabox($post) {
        $campaign = self::get_campaign_settings($post->ID);
        $goal_modes = self::get_campaign_goal_modes();
        $stats = WPGS_Stats::get_list_stats($post->ID);

        $views_unique = isset($stats['views_unique']) ? max(0, (int) $stats['views_unique']) : 0;
        $clicks_unique = isset($stats['clicks_unique']) ? max(0, (int) $stats['clicks_unique']) : 0;
        $views_target = max(1, (int) $campaign['views_unique_target']);
        $clicks_target = max(1, (int) $campaign['clicks_unique_target']);
        $campaign_state = self::evaluate_campaign_state($post->ID, $stats);
        $goal_reached = !empty($campaign_state['goal_reached']);
        $is_ended = !empty($campaign_state['ended']);
        $ended_at = isset($campaign_state['ended_at']) ? (string) $campaign_state['ended_at'] : '';

        $status_label = __('Disabled', 'gamequery-servers-lists');
        if (!empty($campaign['enabled'])) {
            if ($is_ended) {
                $status_label = __('Ended', 'gamequery-servers-lists');
            } elseif ($goal_reached && empty($campaign['auto_end'])) {
                $status_label = __('Goal reached (auto-end off)', 'gamequery-servers-lists');
            } else {
                $status_label = __('Active', 'gamequery-servers-lists');
            }
        }

        $goal_mode = isset($campaign['goal_mode']) ? (string) $campaign['goal_mode'] : 'views_or_clicks';
        $target_summary = '';
        if ('views' === $goal_mode) {
            $target_summary = sprintf(
                /* translators: 1: current unique views, 2: target unique views. */
                __('Target: %1$s / %2$s unique views', 'gamequery-servers-lists'),
                number_format_i18n($views_unique),
                number_format_i18n($views_target)
            );
        } elseif ('clicks' === $goal_mode) {
            $target_summary = sprintf(
                /* translators: 1: current unique clicks, 2: target unique clicks. */
                __('Target: %1$s / %2$s unique clicks', 'gamequery-servers-lists'),
                number_format_i18n($clicks_unique),
                number_format_i18n($clicks_target)
            );
        } elseif ('views_and_clicks' === $goal_mode) {
            $target_summary = sprintf(
                /* translators: 1: current unique views, 2: target unique views, 3: current unique clicks, 4: target unique clicks. */
                __('Target: views %1$s / %2$s and clicks %3$s / %4$s', 'gamequery-servers-lists'),
                number_format_i18n($views_unique),
                number_format_i18n($views_target),
                number_format_i18n($clicks_unique),
                number_format_i18n($clicks_target)
            );
        } else {
            $target_summary = sprintf(
                /* translators: 1: current unique views, 2: target unique views, 3: current unique clicks, 4: target unique clicks. */
                __('Target: views %1$s / %2$s or clicks %3$s / %4$s', 'gamequery-servers-lists'),
                number_format_i18n($views_unique),
                number_format_i18n($views_target),
                number_format_i18n($clicks_unique),
                number_format_i18n($clicks_target)
            );
        }

        echo '<div class="wpgs-campaign-options">';
        echo '<label class="wpgs-checkbox-row">';
        echo '<input type="checkbox" name="wpgs_campaign[enabled]" value="1" ' . checked(!empty($campaign['enabled']), true, false) . ' />';
        echo '<span>' . esc_html__('Enable campaign goal', 'gamequery-servers-lists') . '</span>';
        echo '</label>';

        echo '<p>';
        echo '<label for="wpgs-campaign-goal-mode"><strong>' . esc_html__('Completion rule', 'gamequery-servers-lists') . '</strong></label>';
        echo '<select id="wpgs-campaign-goal-mode" class="widefat" name="wpgs_campaign[goal_mode]">';
        foreach ($goal_modes as $mode_key => $mode_label) {
            echo '<option value="' . esc_attr($mode_key) . '" ' . selected($goal_mode, $mode_key, false) . '>' . esc_html($mode_label) . '</option>';
        }
        echo '</select>';
        echo '</p>';

        echo '<p>';
        echo '<label for="wpgs-campaign-views-target"><strong>' . esc_html__('Unique views target', 'gamequery-servers-lists') . '</strong></label>';
        echo '<input type="number" min="1" step="1" class="widefat" id="wpgs-campaign-views-target" name="wpgs_campaign[views_unique_target]" value="' . esc_attr((string) $views_target) . '" />';
        echo '</p>';

        echo '<p>';
        echo '<label for="wpgs-campaign-clicks-target"><strong>' . esc_html__('Unique clicks target', 'gamequery-servers-lists') . '</strong></label>';
        echo '<input type="number" min="1" step="1" class="widefat" id="wpgs-campaign-clicks-target" name="wpgs_campaign[clicks_unique_target]" value="' . esc_attr((string) $clicks_target) . '" />';
        echo '</p>';

        echo '<label class="wpgs-checkbox-row">';
        echo '<input type="checkbox" name="wpgs_campaign[auto_end]" value="1" ' . checked(!empty($campaign['auto_end']), true, false) . ' />';
        echo '<span>' . esc_html__('Auto-end campaign when goal is reached', 'gamequery-servers-lists') . '</span>';
        echo '</label>';

        echo '<p class="wpgs-campaign-state"><strong>' . esc_html__('Status:', 'gamequery-servers-lists') . '</strong> ' . esc_html($status_label) . '</p>';
        echo '<p class="description">' . esc_html($target_summary) . '</p>';
        if ($is_ended && '' !== $ended_at) {
            /* translators: %s: campaign end date and time in UTC. */
            $ended_at_message = sprintf(__('Ended at (UTC): %s', 'gamequery-servers-lists'), $ended_at);
            echo '<p class="description">' . esc_html($ended_at_message) . '</p>';
        }
        echo '<p class="description">' . esc_html__('Campaign goals use unique stats from WPGS tracking.', 'gamequery-servers-lists') . '</p>';
        echo '</div>';
    }

    /**
     * @param WP_Post $post
     */
    public function render_display_metabox($post) {
        $display = self::get_display_settings($post->ID);

        $fields = array(
            'show_name' => __('Server name', 'gamequery-servers-lists'),
            'show_address' => __('Server address', 'gamequery-servers-lists'),
            'show_copy_address' => __('Copy IP button', 'gamequery-servers-lists'),
            'show_map' => __('Map', 'gamequery-servers-lists'),
            'show_players' => __('Players', 'gamequery-servers-lists'),
            'show_maxplayers' => __('Max players', 'gamequery-servers-lists'),
            'show_status' => __('Updater status', 'gamequery-servers-lists'),
        );

        echo '<div class="wpgs-display-options">';
        foreach ($fields as $field_key => $label) {
            $checked = !empty($display[$field_key]);
            echo '<label class="wpgs-checkbox-row">';
            echo '<input type="checkbox" name="wpgs_display[' . esc_attr($field_key) . ']" value="1" ' . checked($checked, true, false) . ' />';
            echo '<span>' . esc_html($label) . '</span>';
            echo '</label>';
        }
        echo '</div>';
    }

    /**
     * @param WP_Post $post
     */
    public function render_css_metabox($post) {
        $custom_css = self::get_custom_css($post->ID);

        echo '<p>' . esc_html__('Custom CSS will be printed with this list output. Scope it to the wrapper class to avoid affecting other elements.', 'gamequery-servers-lists') . '</p>';
        echo '<p><code>.wpgs-list-' . esc_html((string) $post->ID) . ' .wpgs-table { /* your rules */ }</code></p>';
        echo '<textarea name="wpgs_custom_css" rows="10" class="widefat code" placeholder=".wpgs-list-' . esc_attr((string) $post->ID) . ' .wpgs-table { border-radius: 8px; }">' . esc_textarea($custom_css) . '</textarea>';
    }

    /**
     * @param int $post_id
     */
    public function save_meta_boxes($post_id) {
        if (!isset($_POST['wpgs_list_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wpgs_list_nonce'])), 'wpgs_save_list_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $raw_groups = array();
        if (isset($_POST['wpgs_groups']) && is_array($_POST['wpgs_groups'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in sanitize_groups().
            $raw_groups = wp_unslash($_POST['wpgs_groups']);
        }

        $groups = $this->sanitize_groups($raw_groups);
        update_post_meta($post_id, self::META_GROUPS, $groups);

        $raw_display = array();
        if (isset($_POST['wpgs_display']) && is_array($_POST['wpgs_display'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in sanitize_display().
            $raw_display = wp_unslash($_POST['wpgs_display']);
        }

        $display = $this->sanitize_display($raw_display);
        update_post_meta($post_id, self::META_DISPLAY, $display);

        $raw_campaign = array();
        if (isset($_POST['wpgs_campaign']) && is_array($_POST['wpgs_campaign'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in sanitize_campaign().
            $raw_campaign = wp_unslash($_POST['wpgs_campaign']);
        }

        $campaign = $this->sanitize_campaign($raw_campaign);
        update_post_meta($post_id, self::META_CAMPAIGN, $campaign);
        self::evaluate_campaign_state($post_id);

        $raw_template = '';
        if (isset($_POST['wpgs_template'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in sanitize_template().
            $raw_template = (string) wp_unslash($_POST['wpgs_template']);
        }

        $template = self::sanitize_template($raw_template);
        update_post_meta($post_id, self::META_TEMPLATE, $template);

        $custom_css = '';
        if (isset($_POST['wpgs_custom_css'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in sanitize_custom_css().
            $custom_css = $this->sanitize_custom_css((string) wp_unslash($_POST['wpgs_custom_css']));
        }
        update_post_meta($post_id, self::META_CUSTOM_CSS, $custom_css);

        $server_count = self::count_servers($groups);
        update_post_meta($post_id, self::META_SERVER_TOTAL, $server_count);

        $is_limit_exceeded = $server_count > 1000;
        update_post_meta($post_id, self::META_LIMIT_EXCEEDED, $is_limit_exceeded ? '1' : '0');

        if ($is_limit_exceeded) {
            $limit_exceeded_message = sprintf(
                /* translators: %d is the number of servers configured in the list. */
                __('This list currently has %d servers. The API accepts a maximum of 1000 servers per request.', 'gamequery-servers-lists'),
                $server_count
            );

            set_transient(
                'wpgs_admin_notice_' . get_current_user_id(),
                array(
                    'type' => 'warning',
                    'message' => $limit_exceeded_message,
                ),
                90
            );
        }

        WPGS_API_Client::clear_list_cache($post_id);
    }

    /**
     * @param array<int|string, mixed> $raw_groups
     * @return array<int, array<string, mixed>>
     */
    private function sanitize_groups($raw_groups) {
        $groups = array();
        $games_catalog = self::get_games_catalog();
        $games_lookup = self::build_games_catalog_lookup($games_catalog);

        foreach ($raw_groups as $raw_group) {
            if (!is_array($raw_group)) {
                continue;
            }

            $selected_game_input = isset($raw_group['game_id']) ? sanitize_text_field((string) $raw_group['game_id']) : '';
            $manual_game_input = isset($raw_group['game_id_manual']) ? sanitize_text_field((string) $raw_group['game_id_manual']) : '';
            $game_input = '' !== trim($manual_game_input) ? $manual_game_input : $selected_game_input;
            $game_id = self::resolve_game_id_from_input($game_input, $games_catalog, $games_lookup);
            $game_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $game_id);

            if (empty($game_id)) {
                continue;
            }

            $servers_input = isset($raw_group['servers']) ? (string) $raw_group['servers'] : '';
            $servers_parts = preg_split('/[\r\n,]+/', $servers_input);
            $servers = array();

            if (is_array($servers_parts)) {
                foreach ($servers_parts as $server_part) {
                    $server = trim((string) $server_part);
                    if (empty($server) || !self::is_valid_server_address($server)) {
                        continue;
                    }

                    if (!in_array($server, $servers, true)) {
                        $servers[] = $server;
                    }
                }
            }

            if (empty($servers)) {
                continue;
            }

            $groups[] = array(
                'game_id' => $game_id,
                'servers' => $servers,
            );
        }

        return $groups;
    }

    /**
     * @param array<string, mixed> $raw_display
     * @return array<string, int>
     */
    private function sanitize_display($raw_display) {
        $defaults = self::get_default_display_settings();
        $display = array();

        foreach (array_keys($defaults) as $key) {
            $display[$key] = isset($raw_display[$key]) ? 1 : 0;
        }

        return $display;
    }

    /**
     * @param array<string, mixed> $raw_campaign
     * @return array<string, int|string>
     */
    private function sanitize_campaign($raw_campaign) {
        return self::normalize_campaign_settings($raw_campaign);
    }

    /**
     * @param string $css
     * @return string
     */
    private function sanitize_custom_css($css) {
        $css = trim($css);
        $css = str_replace(array('<', '>'), '', $css);

        return $css;
    }

    /**
     * @param int $post_id
     */
    public function clear_cache_on_post_delete($post_id) {
        $post_id = absint($post_id);
        if ($post_id <= 0) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || self::POST_TYPE !== $post->post_type) {
            return;
        }

        WPGS_API_Client::clear_list_cache($post_id);
    }

    /**
     * @return array<string, string>
     */
    public static function get_campaign_goal_modes() {
        return array(
            'views_or_clicks' => __('Unique views OR unique clicks', 'gamequery-servers-lists'),
            'views_and_clicks' => __('Unique views AND unique clicks', 'gamequery-servers-lists'),
            'views' => __('Unique views only', 'gamequery-servers-lists'),
            'clicks' => __('Unique clicks only', 'gamequery-servers-lists'),
        );
    }

    /**
     * @return array<string, int|string>
     */
    public static function get_default_campaign_settings() {
        return array(
            'enabled' => 0,
            'goal_mode' => 'views_or_clicks',
            'views_unique_target' => 100,
            'clicks_unique_target' => 25,
            'auto_end' => 1,
        );
    }

    /**
     * @param int $post_id
     * @return array<string, int|string>
     */
    public static function get_campaign_settings($post_id) {
        $value = get_post_meta($post_id, self::META_CAMPAIGN, true);
        return self::normalize_campaign_settings($value);
    }

    /**
     * @param int $post_id
     * @param array<string, int|string> $stats
     * @return array<string, int|string|bool>
     */
    public static function evaluate_campaign_state($post_id, $stats = array()) {
        $post_id = absint($post_id);
        if ($post_id <= 0) {
            return array(
                'enabled' => false,
                'goal_reached' => false,
                'ended' => false,
                'ended_at' => '',
            );
        }

        $campaign = self::get_campaign_settings($post_id);
        $is_enabled = !empty($campaign['enabled']);

        $views_unique = isset($stats['views_unique']) ? max(0, (int) $stats['views_unique']) : 0;
        $clicks_unique = isset($stats['clicks_unique']) ? max(0, (int) $stats['clicks_unique']) : 0;

        if (empty($stats)) {
            $current_stats = WPGS_Stats::get_list_stats($post_id);
            $views_unique = isset($current_stats['views_unique']) ? max(0, (int) $current_stats['views_unique']) : 0;
            $clicks_unique = isset($current_stats['clicks_unique']) ? max(0, (int) $current_stats['clicks_unique']) : 0;
        }

        $goal_reached = $is_enabled && self::is_campaign_goal_reached($campaign, $views_unique, $clicks_unique);
        $should_end = $goal_reached && !empty($campaign['auto_end']);

        $was_ended = '1' === (string) get_post_meta($post_id, self::META_CAMPAIGN_ENDED, true);
        $ended_at = (string) get_post_meta($post_id, self::META_CAMPAIGN_ENDED_AT, true);

        if (!$is_enabled || !$should_end) {
            if ($was_ended) {
                update_post_meta($post_id, self::META_CAMPAIGN_ENDED, '0');
            }

            if ('' !== $ended_at) {
                delete_post_meta($post_id, self::META_CAMPAIGN_ENDED_AT);
                $ended_at = '';
            }

            return array(
                'enabled' => $is_enabled,
                'goal_reached' => $goal_reached,
                'ended' => false,
                'ended_at' => '',
            );
        }

        if (!$was_ended) {
            update_post_meta($post_id, self::META_CAMPAIGN_ENDED, '1');
        }

        if ('' === $ended_at) {
            $ended_at = current_time('mysql', true);
            update_post_meta($post_id, self::META_CAMPAIGN_ENDED_AT, $ended_at);
        }

        return array(
            'enabled' => true,
            'goal_reached' => true,
            'ended' => true,
            'ended_at' => $ended_at,
        );
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, int|string>
     */
    private static function normalize_campaign_settings($value) {
        $defaults = self::get_default_campaign_settings();
        if (!is_array($value)) {
            return $defaults;
        }

        $goal_mode = isset($value['goal_mode']) ? sanitize_key((string) $value['goal_mode']) : (string) $defaults['goal_mode'];
        $goal_mode = self::sanitize_campaign_goal_mode($goal_mode);

        return array(
            'enabled' => !empty($value['enabled']) ? 1 : 0,
            'goal_mode' => $goal_mode,
            'views_unique_target' => max(1, isset($value['views_unique_target']) ? (int) $value['views_unique_target'] : (int) $defaults['views_unique_target']),
            'clicks_unique_target' => max(1, isset($value['clicks_unique_target']) ? (int) $value['clicks_unique_target'] : (int) $defaults['clicks_unique_target']),
            'auto_end' => !empty($value['auto_end']) ? 1 : 0,
        );
    }

    /**
     * @param string $goal_mode
     * @return string
     */
    private static function sanitize_campaign_goal_mode($goal_mode) {
        $allowed = array('views_or_clicks', 'views_and_clicks', 'views', 'clicks');
        if (!in_array($goal_mode, $allowed, true)) {
            return 'views_or_clicks';
        }

        return $goal_mode;
    }

    /**
     * @param array<string, int|string> $campaign
     * @param int $views_unique
     * @param int $clicks_unique
     * @return bool
     */
    private static function is_campaign_goal_reached($campaign, $views_unique, $clicks_unique) {
        $goal_mode = isset($campaign['goal_mode']) ? self::sanitize_campaign_goal_mode((string) $campaign['goal_mode']) : 'views_or_clicks';
        $views_target = max(1, isset($campaign['views_unique_target']) ? (int) $campaign['views_unique_target'] : 1);
        $clicks_target = max(1, isset($campaign['clicks_unique_target']) ? (int) $campaign['clicks_unique_target'] : 1);

        if ('views' === $goal_mode) {
            return $views_unique >= $views_target;
        }

        if ('clicks' === $goal_mode) {
            return $clicks_unique >= $clicks_target;
        }

        if ('views_and_clicks' === $goal_mode) {
            return $views_unique >= $views_target && $clicks_unique >= $clicks_target;
        }

        return $views_unique >= $views_target || $clicks_unique >= $clicks_target;
    }

    /**
     * @return array<string, int>
     */
    public static function get_default_display_settings() {
        return array(
            'show_name' => 1,
            'show_address' => 1,
            'show_copy_address' => 0,
            'show_map' => 1,
            'show_players' => 1,
            'show_maxplayers' => 1,
            'show_status' => 1,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_templates() {
        return array(
            'classic' => array(
                'label' => __('Classic Table', 'gamequery-servers-lists'),
                'description' => __('Balanced default table with clear spacing.', 'gamequery-servers-lists'),
                'category' => 'general',
                'category_label' => __('General', 'gamequery-servers-lists'),
                'tags' => array('default', 'balanced', 'table'),
            ),
            'compact' => array(
                'label' => __('Compact Table', 'gamequery-servers-lists'),
                'description' => __('Dense rows for long server lists.', 'gamequery-servers-lists'),
                'category' => 'utility',
                'category_label' => __('Utility', 'gamequery-servers-lists'),
                'tags' => array('compact', 'dense', 'list-heavy'),
            ),
            'minimal' => array(
                'label' => __('Minimal Table', 'gamequery-servers-lists'),
                'description' => __('Soft borders and reduced visual weight.', 'gamequery-servers-lists'),
                'category' => 'clean',
                'category_label' => __('Clean', 'gamequery-servers-lists'),
                'tags' => array('minimal', 'light', 'simple'),
            ),
            'esports' => array(
                'label' => __('Esports Table', 'gamequery-servers-lists'),
                'description' => __('High-contrast table for gaming pages.', 'gamequery-servers-lists'),
                'category' => 'gaming',
                'category_label' => __('Gaming', 'gamequery-servers-lists'),
                'tags' => array('bold', 'esports', 'competitive', 'table'),
            ),
            'slate' => array(
                'label' => __('Slate Table', 'gamequery-servers-lists'),
                'description' => __('Muted panel table with calm typography.', 'gamequery-servers-lists'),
                'category' => 'gaming',
                'category_label' => __('Gaming', 'gamequery-servers-lists'),
                'tags' => array('modern', 'slate', 'panel', 'table'),
            ),
            'terminal' => array(
                'label' => __('Terminal Table', 'gamequery-servers-lists'),
                'description' => __('Retro command-line inspired table.', 'gamequery-servers-lists'),
                'category' => 'retro',
                'category_label' => __('Retro', 'gamequery-servers-lists'),
                'tags' => array('retro', 'terminal', 'monospace', 'table'),
            ),
            'strip' => array(
                'label' => __('Format: Horizontal Strip', 'gamequery-servers-lists'),
                'description' => __('Full-width rows with compact inline metadata.', 'gamequery-servers-lists'),
                'category' => 'formats',
                'category_label' => __('Formats', 'gamequery-servers-lists'),
                'tags' => array('layout', 'rows', 'strip', 'wide'),
            ),
            'sidebar-compact' => array(
                'label' => __('Format: Sidebar Compact', 'gamequery-servers-lists'),
                'description' => __('Narrow stacked rows for light sidebars.', 'gamequery-servers-lists'),
                'category' => 'formats',
                'category_label' => __('Formats', 'gamequery-servers-lists'),
                'tags' => array('layout', 'sidebar', 'compact', 'light'),
            ),
            'sidebar-dark' => array(
                'label' => __('Format: Sidebar Dark', 'gamequery-servers-lists'),
                'description' => __('Dark compact sidebar with subtle separators.', 'gamequery-servers-lists'),
                'category' => 'formats',
                'category_label' => __('Formats', 'gamequery-servers-lists'),
                'tags' => array('layout', 'sidebar', 'dark', 'compact'),
            ),
            'grid-cards' => array(
                'label' => __('Format: Grid Cards', 'gamequery-servers-lists'),
                'description' => __('Two-column visual cards for wide sections.', 'gamequery-servers-lists'),
                'category' => 'formats',
                'category_label' => __('Formats', 'gamequery-servers-lists'),
                'tags' => array('layout', 'grid', 'cards', 'visual'),
            ),
            'minimal-list' => array(
                'label' => __('Format: Minimal List', 'gamequery-servers-lists'),
                'description' => __('Ultra-clean list with typography and dividers.', 'gamequery-servers-lists'),
                'category' => 'formats',
                'category_label' => __('Formats', 'gamequery-servers-lists'),
                'tags' => array('layout', 'minimal', 'list', 'clean'),
            ),
            'clean' => array(
                'label' => __('Theme: Clean', 'gamequery-servers-lists'),
                'description' => __('Default light card style.', 'gamequery-servers-lists'),
                'category' => 'themes',
                'category_label' => __('Card Themes', 'gamequery-servers-lists'),
                'tags' => array('cards', 'light', 'clean', 'campaign'),
            ),
            'dark' => array(
                'label' => __('Theme: Dark', 'gamequery-servers-lists'),
                'description' => __('Dark gaming card style with subtle glow.', 'gamequery-servers-lists'),
                'category' => 'themes',
                'category_label' => __('Card Themes', 'gamequery-servers-lists'),
                'tags' => array('cards', 'dark', 'gaming'),
            ),
            'accent' => array(
                'label' => __('Theme: Accent', 'gamequery-servers-lists'),
                'description' => __('Cards with accent border and vibrant pills.', 'gamequery-servers-lists'),
                'category' => 'themes',
                'category_label' => __('Card Themes', 'gamequery-servers-lists'),
                'tags' => array('cards', 'accent', 'vibrant'),
            ),
            'glass' => array(
                'label' => __('Theme: Glass', 'gamequery-servers-lists'),
                'description' => __('Glassmorphism cards on a gradient wrapper.', 'gamequery-servers-lists'),
                'category' => 'themes',
                'category_label' => __('Card Themes', 'gamequery-servers-lists'),
                'tags' => array('cards', 'glass', 'gradient'),
            ),
            'cyber' => array(
                'label' => __('Theme: Cyber', 'gamequery-servers-lists'),
                'description' => __('Neon cyber card style with high contrast.', 'gamequery-servers-lists'),
                'category' => 'themes',
                'category_label' => __('Card Themes', 'gamequery-servers-lists'),
                'tags' => array('cards', 'neon', 'cyber'),
            ),
            'warm' => array(
                'label' => __('Theme: Warm', 'gamequery-servers-lists'),
                'description' => __('Warm minimal cards for editorial layouts.', 'gamequery-servers-lists'),
                'category' => 'themes',
                'category_label' => __('Card Themes', 'gamequery-servers-lists'),
                'tags' => array('cards', 'warm', 'minimal'),
            ),
            'outlined' => array(
                'label' => __('Theme: Outlined', 'gamequery-servers-lists'),
                'description' => __('Flat outlined card style.', 'gamequery-servers-lists'),
                'category' => 'themes',
                'category_label' => __('Card Themes', 'gamequery-servers-lists'),
                'tags' => array('cards', 'outlined', 'flat'),
            ),
            'frosted' => array(
                'label' => __('Theme: Frosted', 'gamequery-servers-lists'),
                'description' => __('Dark frosted rows with divider style.', 'gamequery-servers-lists'),
                'category' => 'themes',
                'category_label' => __('Card Themes', 'gamequery-servers-lists'),
                'tags' => array('cards', 'frosted', 'dark'),
            ),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function get_template_categories() {
        $categories = array();
        $templates = self::get_templates();

        foreach ($templates as $template) {
            if (!is_array($template)) {
                continue;
            }

            $category_key = isset($template['category']) ? sanitize_key((string) $template['category']) : '';
            if ('' === $category_key) {
                continue;
            }

            $category_label = isset($template['category_label'])
                ? (string) $template['category_label']
                : self::format_filter_label($category_key);

            $categories[$category_key] = $category_label;
        }

        asort($categories);
        return $categories;
    }

    /**
     * @return array<string, string>
     */
    public static function get_template_tags() {
        $tags = array();
        $templates = self::get_templates();

        foreach ($templates as $template) {
            if (!is_array($template) || empty($template['tags']) || !is_array($template['tags'])) {
                continue;
            }

            foreach ($template['tags'] as $tag) {
                $tag_key = sanitize_key((string) $tag);
                if ('' === $tag_key) {
                    continue;
                }

                $tags[$tag_key] = self::format_filter_label($tag_key);
            }
        }

        asort($tags);
        return $tags;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function format_filter_label($value) {
        $value = str_replace('-', ' ', $value);
        return ucwords($value);
    }

    /**
     * @return array<int, string>
     */
    public static function get_table_template_keys() {
        return array('classic', 'compact', 'minimal', 'esports', 'slate', 'terminal');
    }

    /**
     * @return array<int, string>
     */
    public static function get_format_template_keys() {
        return array('strip', 'sidebar-compact', 'sidebar-dark', 'grid-cards', 'minimal-list');
    }

    /**
     * @param string $template
     * @return bool
     */
    public static function is_table_template($template) {
        $template = sanitize_key((string) $template);
        return in_array($template, self::get_table_template_keys(), true);
    }

    /**
     * @param string $template
     * @return bool
     */
    public static function is_format_template($template) {
        $template = sanitize_key((string) $template);
        return in_array($template, self::get_format_template_keys(), true);
    }

    /**
     * @param string $template
     * @return string
     */
    public static function get_preview_mode_for_template($template) {
        $template = sanitize_key((string) $template);

        if (self::is_table_template($template)) {
            return 'table';
        }

        if (self::is_format_template($template)) {
            return $template;
        }

        if ('' !== self::get_card_theme_for_template($template)) {
            return 'card-theme';
        }

        return 'table';
    }

    /**
     * @param string $template
     * @return string
     */
    public static function get_card_theme_for_template($template) {
        $template = sanitize_key((string) $template);

        $map = array(
            'cards' => 'clean',
            'clean' => 'clean',
            'dark' => 'dark',
            'accent' => 'accent',
            'glass' => 'glass',
            'cyber' => 'cyber',
            'warm' => 'warm',
            'outlined' => 'outlined',
            'frosted' => 'frosted',
        );

        return isset($map[$template]) ? $map[$template] : '';
    }

    /**
     * @param string $template
     * @return string
     */
    public static function get_card_theme_wrap_for_template($template) {
        $theme = self::get_card_theme_for_template($template);
        if ('glass' === $theme) {
            return 'wpgs-glass-wrap';
        }

        if ('frosted' === $theme) {
            return 'wpgs-frosted-wrap';
        }

        return '';
    }

    /**
     * @param string $template
     * @return bool
     */
    public static function is_card_theme_template($template) {
        return '' !== self::get_card_theme_for_template($template);
    }

    /**
     * @param string $template
     * @return string
     */
    public static function sanitize_template($template) {
        $template = sanitize_key($template);

        if ('cards' === $template) {
            return 'clean';
        }

        $templates = self::get_templates();

        if (isset($templates[$template])) {
            return $template;
        }

        return 'classic';
    }

    /**
     * @param int $post_id
     * @return string
     */
    public static function get_template($post_id) {
        $value = get_post_meta($post_id, self::META_TEMPLATE, true);
        if (!is_string($value)) {
            return 'classic';
        }

        return self::sanitize_template($value);
    }

    /**
     * @param int $post_id
     * @return array<int, array<string, mixed>>
     */
    public static function get_groups($post_id) {
        $value = get_post_meta($post_id, self::META_GROUPS, true);
        if (!is_array($value)) {
            return array();
        }

        $groups = array();
        foreach ($value as $group) {
            if (!is_array($group) || empty($group['game_id']) || empty($group['servers']) || !is_array($group['servers'])) {
                continue;
            }

            $servers = array();
            foreach ($group['servers'] as $server) {
                $server = trim((string) $server);
                if (empty($server) || !self::is_valid_server_address($server)) {
                    continue;
                }

                if (!in_array($server, $servers, true)) {
                    $servers[] = $server;
                }
            }

            if (empty($servers)) {
                continue;
            }

            $groups[] = array(
                'game_id' => sanitize_text_field((string) $group['game_id']),
                'servers' => $servers,
            );
        }

        return $groups;
    }

    /**
     * @param int $post_id
     * @return array<string, int>
     */
    public static function get_display_settings($post_id) {
        $defaults = self::get_default_display_settings();
        $value = get_post_meta($post_id, self::META_DISPLAY, true);

        if (!is_array($value)) {
            return $defaults;
        }

        $sanitized = array();
        foreach (array_keys($defaults) as $key) {
            $sanitized[$key] = !empty($value[$key]) ? 1 : 0;
        }

        return $sanitized;
    }

    /**
     * @param int $post_id
     * @return string
     */
    public static function get_custom_css($post_id) {
        $value = get_post_meta($post_id, self::META_CUSTOM_CSS, true);
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    /**
     * @return array<string, string>
     */
    private static function get_games_catalog() {
        $stored = get_option(self::GAMES_CATALOG_OPTION, array());

        $stored_catalog = array();
        $stored_updated_at = 0;
        if (is_array($stored) && isset($stored['games']) && is_array($stored['games'])) {
            $stored_catalog = self::normalize_games_catalog($stored['games']);
            $stored_updated_at = isset($stored['updated_at']) ? absint($stored['updated_at']) : 0;
        } elseif (is_array($stored)) {
            $stored_catalog = self::normalize_games_catalog($stored);
        }

        $now = time();

        if (!empty($stored_catalog) && $stored_updated_at <= 0) {
            $stored_updated_at = $now;
            update_option(
                self::GAMES_CATALOG_OPTION,
                array(
                    'updated_at' => $stored_updated_at,
                    'games' => $stored_catalog,
                ),
                false
            );
        }

        $is_fresh = !empty($stored_catalog)
            && $stored_updated_at > 0
            && ($now - $stored_updated_at) < self::GAMES_CATALOG_REFRESH_SECONDS;

        if ($is_fresh) {
            return $stored_catalog;
        }

        $fetched_catalog = self::fetch_games_catalog_from_api();
        if (!empty($fetched_catalog)) {
            update_option(
                self::GAMES_CATALOG_OPTION,
                array(
                    'updated_at' => $now,
                    'games' => $fetched_catalog,
                ),
                false
            );

            return $fetched_catalog;
        }

        return $stored_catalog;
    }

    /**
     * @return array<string, string>
     */
    private static function fetch_games_catalog_from_api() {
        $settings = WPGS_Settings::get_settings();
        $api_base_url = isset($settings['api_base_url']) ? untrailingslashit((string) $settings['api_base_url']) : '';

        $urls = array();
        if ('' !== $api_base_url) {
            $urls[] = $api_base_url . '/get/games';
        }

        $urls[] = 'https://api.gamequery.dev/v1/get/games';
        $urls = array_values(array_unique($urls));

        $games_catalog = array();
        foreach ($urls as $url) {
            $response = wp_remote_get(
                $url,
                array(
                    'timeout' => 15,
                    'headers' => array(
                        'Accept' => 'application/json',
                    ),
                )
            );

            if (is_wp_error($response)) {
                continue;
            }

            $status_code = (int) wp_remote_retrieve_response_code($response);
            if ($status_code < 200 || $status_code >= 300) {
                continue;
            }

            $body = (string) wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);
            $games_catalog = self::normalize_games_catalog($decoded);
            if (!empty($games_catalog)) {
                break;
            }
        }

        return $games_catalog;
    }

    /**
     * @param mixed $source
     * @return array<string, string>
     */
    private static function normalize_games_catalog($source) {
        if (!is_array($source)) {
            return array();
        }

        $catalog = array();

        foreach ($source as $raw_key => $raw_value) {
            $game_id = '';
            $game_name = '';

            if (is_string($raw_key) && is_string($raw_value)) {
                $game_id = $raw_key;
                $game_name = $raw_value;
            } elseif (is_array($raw_value)) {
                $game_id = isset($raw_value['id']) ? (string) $raw_value['id'] : '';
                $game_name = isset($raw_value['name']) ? (string) $raw_value['name'] : '';
            }

            $game_id = preg_replace('/[^a-zA-Z0-9_-]/', '', sanitize_text_field($game_id));
            $game_name = sanitize_text_field($game_name);

            if (!is_string($game_id) || '' === $game_id || '' === $game_name) {
                continue;
            }

            $catalog[$game_id] = $game_name;
        }

        if (!empty($catalog)) {
            asort($catalog, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $catalog;
    }

    /**
     * @param array<string, string> $games_catalog
     * @return array<string, string>
     */
    private static function build_games_catalog_lookup($games_catalog) {
        $lookup = array();

        foreach ($games_catalog as $game_id => $game_name) {
            $normalized_id = self::normalize_game_catalog_key($game_id);
            if ('' !== $normalized_id) {
                $lookup[$normalized_id] = (string) $game_id;
            }

            $normalized_name = self::normalize_game_catalog_key($game_name);
            if ('' !== $normalized_name && !isset($lookup[$normalized_name])) {
                $lookup[$normalized_name] = (string) $game_id;
            }

            $normalized_combo = self::normalize_game_catalog_key($game_name . ' (' . $game_id . ')');
            if ('' !== $normalized_combo && !isset($lookup[$normalized_combo])) {
                $lookup[$normalized_combo] = (string) $game_id;
            }
        }

        return $lookup;
    }

    /**
     * @param string $game_input
     * @param array<string, string> $games_catalog
     * @param array<string, string> $games_lookup
     * @return string
     */
    private static function resolve_game_id_from_input($game_input, $games_catalog, $games_lookup) {
        $game_input = trim(sanitize_text_field((string) $game_input));
        if ('' === $game_input) {
            return '';
        }

        if (isset($games_catalog[$game_input])) {
            return (string) $game_input;
        }

        $normalized_key = self::normalize_game_catalog_key($game_input);
        if ('' !== $normalized_key && isset($games_lookup[$normalized_key])) {
            return (string) $games_lookup[$normalized_key];
        }

        return $game_input;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function normalize_game_catalog_key($value) {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/\s+/', ' ', $value);

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @return int
     */
    public static function count_servers($groups) {
        $count = 0;
        foreach ($groups as $group) {
            if (!is_array($group) || empty($group['servers']) || !is_array($group['servers'])) {
                continue;
            }

            $count += count($group['servers']);
        }

        return $count;
    }

    /**
     * @param string $address
     * @return bool
     */
    public static function is_valid_server_address($address) {
        if (!preg_match('/^(\d{1,3}\.){3}\d{1,3}:\d{1,5}$/', $address)) {
            return false;
        }

        $parts = explode(':', $address);
        if (2 !== count($parts)) {
            return false;
        }

        $ip = $parts[0];
        $port = (int) $parts[1];

        if ($port <= 0 || $port > 65535) {
            return false;
        }

        $octets = explode('.', $ip);
        if (4 !== count($octets)) {
            return false;
        }

        foreach ($octets as $octet) {
            $value = (int) $octet;
            if ($value < 0 || $value > 255) {
                return false;
            }
        }

        return true;
    }
}
