<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPGS_Renderer {
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

    /**
     * @param int $list_id
     * @return string
     */
    public function render_list($list_id) {
        $list_id = absint($list_id);
        if ($list_id <= 0) {
            return $this->render_notice(__('Invalid list ID.', 'gamequery-servers-lists'), 'warning');
        }

        $post = get_post($list_id);
        if (!$post || WPGS_Lists::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status) {
            return $this->render_notice(__('WPGS list not found or not published.', 'gamequery-servers-lists'), 'warning');
        }

        $groups = WPGS_Lists::get_groups($list_id);
        if (empty($groups)) {
            return $this->render_notice(__('This server list is empty.', 'gamequery-servers-lists'), 'info');
        }

        $result = $this->api_client->get_or_refresh_list_cache($list_id);
        if (empty($result['success'])) {
            $error_code = isset($result['error_code']) ? (string) $result['error_code'] : '';
            $message = __('Server list temporarily unavailable.', 'gamequery-servers-lists');

            if ('quota_exceeded' === $error_code) {
                $message = __('Server list temporarily unavailable (API quota exceeded).', 'gamequery-servers-lists');
            }

            return $this->render_notice($message, 'warning');
        }

        $payload = isset($result['data']) && is_array($result['data']) ? $result['data'] : array();
        $display = WPGS_Lists::get_display_settings($list_id);
        $template = WPGS_Lists::get_template($list_id);
        $rows = $this->build_rows($groups, $payload);

        if (empty($rows)) {
            return $this->render_notice(__('No server data available yet.', 'gamequery-servers-lists'), 'info');
        }

        $custom_css = WPGS_Lists::get_custom_css($list_id);

        $card_theme = WPGS_Lists::get_card_theme_for_template($template);
        if ('' !== $card_theme) {
            $card_theme_wrap = WPGS_Lists::get_card_theme_wrap_for_template($template);
            return $this->render_cards_layout($list_id, $template, $rows, $display, $custom_css, $card_theme, $card_theme_wrap);
        }

        if (WPGS_Lists::is_format_template($template)) {
            return $this->render_format_layout($list_id, $template, $rows, $display, $custom_css);
        }

        $columns = $this->resolve_columns($display);
        return $this->render_table_layout($list_id, $template, $rows, $columns, $custom_css, $display);
    }

    /**
     * @param int $list_id
     * @param string $template
     * @param array<int, array<string, string>> $rows
     * @param array<string, int> $display
     * @param string $custom_css
     * @return string
     */
    private function render_format_layout($list_id, $template, $rows, $display, $custom_css) {
        if ('strip' === $template) {
            return $this->render_strip_layout($list_id, $template, $rows, $display, $custom_css);
        }

        if ('sidebar-compact' === $template) {
            return $this->render_sidebar_compact_layout($list_id, $template, $rows, $display, $custom_css);
        }

        if ('sidebar-dark' === $template) {
            return $this->render_sidebar_dark_layout($list_id, $template, $rows, $display, $custom_css);
        }

        if ('grid-cards' === $template) {
            return $this->render_grid_cards_layout($list_id, $template, $rows, $display, $custom_css);
        }

        return $this->render_minimal_list_layout($list_id, $template, $rows, $display, $custom_css);
    }

    /**
     * @param int $list_id
     * @param string $template
     * @param array<int, array<string, string>> $rows
     * @param array<string, int> $display
     * @param string $custom_css
     * @return string
     */
    private function render_strip_layout($list_id, $template, $rows, $display, $custom_css) {
        $show_name = !empty($display['show_name']);
        $show_address = !empty($display['show_address']);
        $show_map = !empty($display['show_map']);
        $show_players = !empty($display['show_players']);
        $show_maxplayers = !empty($display['show_maxplayers']);
        $show_status = !empty($display['show_status']);
        $show_copy_address = !empty($display['show_copy_address']) && $show_address;

        if (!$show_name && !$show_address) {
            $show_name = true;
        }

        $html = '';
        $html .= '<div class="wpgs-list wpgs-list-' . esc_attr((string) $list_id) . ' wpgs-template-' . esc_attr($template) . ' wpgs-layout-strip" data-list-id="' . esc_attr((string) $list_id) . '">';
        if (!empty($custom_css)) {
            $html .= '<style>' . esc_html($custom_css) . '</style>';
        }

        foreach ($rows as $row) {
            $status_token = $this->status_token(isset($row['status']) ? (string) $row['status'] : '');
            $name = isset($row['name']) ? (string) $row['name'] : '';
            $address = isset($row['address']) ? (string) $row['address'] : '';
            $map = isset($row['map']) ? (string) $row['map'] : '';
            $players = isset($row['players']) ? (string) $row['players'] : '';
            $maxplayers = isset($row['maxplayers']) ? (string) $row['maxplayers'] : '';

            $players_text = $this->format_players_max($players, $maxplayers, $show_players, $show_maxplayers);
            $map_text = ($show_map && $this->has_meaningful_value($map)) ? $map : '-';

            $html .= '<div class="wpgs-card">';
            if ($show_status) {
                $html .= '<span class="wpgs-dot-col wpgs-dot-' . esc_attr($status_token) . '"></span>';
            }

            $html .= '<div class="wpgs-name-col">';
            if ($show_name) {
                $name_output = '' !== trim($name) ? $name : __('Unavailable', 'gamequery-servers-lists');
                $html .= '<span class="wpgs-server-name">' . esc_html($name_output) . '</span>';
            }
            if ($show_address) {
                $html .= $this->render_server_address($address, $show_copy_address);
            }
            $html .= '</div>';

            if ('-' !== $players_text || '-' !== $map_text) {
                $html .= '<div class="wpgs-meta-row">';
                $html .= '<span class="wpgs-meta-item">' . wp_kses($players_text, array('strong' => array())) . '</span>';
                $html .= '<span class="wpgs-meta-item">' . esc_html($map_text) . '</span>';
                $html .= '</div>';
            }

            if ($show_status) {
                $status_label = $this->status_label($status_token);
                $html .= '<span class="wpgs-status-text ' . esc_attr($status_token) . '">' . esc_html($status_label) . '</span>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * @param int $list_id
     * @param string $template
     * @param array<int, array<string, string>> $rows
     * @param array<string, int> $display
     * @param string $custom_css
     * @return string
     */
    private function render_sidebar_compact_layout($list_id, $template, $rows, $display, $custom_css) {
        $show_name = !empty($display['show_name']);
        $show_address = !empty($display['show_address']);
        $show_players = !empty($display['show_players']);
        $show_maxplayers = !empty($display['show_maxplayers']);
        $show_status = !empty($display['show_status']);
        $show_copy_address = !empty($display['show_copy_address']) && $show_address;

        if (!$show_name && !$show_address) {
            $show_name = true;
        }

        $html = '';
        $html .= '<div class="wpgs-list wpgs-list-' . esc_attr((string) $list_id) . ' wpgs-template-' . esc_attr($template) . ' wpgs-layout-sidebar-compact" data-list-id="' . esc_attr((string) $list_id) . '">';
        if (!empty($custom_css)) {
            $html .= '<style>' . esc_html($custom_css) . '</style>';
        }

        foreach ($rows as $row) {
            $status_token = $this->status_token(isset($row['status']) ? (string) $row['status'] : '');
            $name = isset($row['name']) ? (string) $row['name'] : '';
            $address = isset($row['address']) ? (string) $row['address'] : '';
            $players = isset($row['players']) ? (string) $row['players'] : '';
            $maxplayers = isset($row['maxplayers']) ? (string) $row['maxplayers'] : '';
            $players_text = $this->format_players_compact($players, $maxplayers, $show_players, $show_maxplayers);

            $html .= '<div class="wpgs-card">';
            if ($show_status) {
                $html .= '<span class="wpgs-dot wpgs-dot-' . esc_attr($status_token) . '"></span>';
            }

            $html .= '<div class="wpgs-card-body">';
            if ($show_name) {
                $name_output = '' !== trim($name) ? $name : __('Unavailable', 'gamequery-servers-lists');
                $html .= '<span class="wpgs-server-name">' . esc_html($name_output) . '</span>';
            }
            if ($show_address) {
                $html .= $this->render_server_address($address, $show_copy_address);
            }
            $html .= '</div>';

            if ('-' !== $players_text) {
                $html .= '<span class="wpgs-players">' . esc_html($players_text) . '</span>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * @param int $list_id
     * @param string $template
     * @param array<int, array<string, string>> $rows
     * @param array<string, int> $display
     * @param string $custom_css
     * @return string
     */
    private function render_sidebar_dark_layout($list_id, $template, $rows, $display, $custom_css) {
        $show_name = !empty($display['show_name']);
        $show_address = !empty($display['show_address']);
        $show_players = !empty($display['show_players']);
        $show_maxplayers = !empty($display['show_maxplayers']);
        $show_status = !empty($display['show_status']);
        $show_copy_address = !empty($display['show_copy_address']) && $show_address;

        if (!$show_name && !$show_address) {
            $show_name = true;
        }

        $html = '';
        $html .= '<div class="wpgs-layout-sidebar-dark-wrap">';
        if (!empty($custom_css)) {
            $html .= '<style>' . esc_html($custom_css) . '</style>';
        }

        $html .= '<div class="wpgs-list wpgs-list-' . esc_attr((string) $list_id) . ' wpgs-template-' . esc_attr($template) . ' wpgs-layout-sidebar-dark" data-list-id="' . esc_attr((string) $list_id) . '">';
        foreach ($rows as $row) {
            $status_token = $this->status_token(isset($row['status']) ? (string) $row['status'] : '');
            $name = isset($row['name']) ? (string) $row['name'] : '';
            $address = isset($row['address']) ? (string) $row['address'] : '';
            $players = isset($row['players']) ? (string) $row['players'] : '';
            $maxplayers = isset($row['maxplayers']) ? (string) $row['maxplayers'] : '';
            $players_text = $this->format_players_compact($players, $maxplayers, $show_players, $show_maxplayers);

            $html .= '<div class="wpgs-card">';
            if ($show_status) {
                $html .= '<span class="wpgs-dot wpgs-dot-' . esc_attr($status_token) . '"></span>';
            }

            $html .= '<div class="wpgs-card-body">';
            if ($show_name) {
                $name_output = '' !== trim($name) ? $name : __('Unavailable', 'gamequery-servers-lists');
                $html .= '<span class="wpgs-server-name">' . esc_html($name_output) . '</span>';
            }
            if ($show_address) {
                $html .= $this->render_server_address($address, $show_copy_address);
            }
            $html .= '</div>';

            if ('-' !== $players_text) {
                $html .= '<span class="wpgs-players">' . esc_html($players_text) . '</span>';
            }

            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * @param int $list_id
     * @param string $template
     * @param array<int, array<string, string>> $rows
     * @param array<string, int> $display
     * @param string $custom_css
     * @return string
     */
    private function render_grid_cards_layout($list_id, $template, $rows, $display, $custom_css) {
        $show_name = !empty($display['show_name']);
        $show_address = !empty($display['show_address']);
        $show_map = !empty($display['show_map']);
        $show_players = !empty($display['show_players']);
        $show_maxplayers = !empty($display['show_maxplayers']);
        $show_status = !empty($display['show_status']);
        $show_copy_address = !empty($display['show_copy_address']) && $show_address;

        if (!$show_name && !$show_address) {
            $show_name = true;
        }

        $html = '';
        $html .= '<div class="wpgs-list wpgs-list-' . esc_attr((string) $list_id) . ' wpgs-template-' . esc_attr($template) . ' wpgs-layout-grid-cards" data-list-id="' . esc_attr((string) $list_id) . '">';
        if (!empty($custom_css)) {
            $html .= '<style>' . esc_html($custom_css) . '</style>';
        }

        foreach ($rows as $row) {
            $status_token = $this->status_token(isset($row['status']) ? (string) $row['status'] : '');
            $name = isset($row['name']) ? (string) $row['name'] : '';
            $address = isset($row['address']) ? (string) $row['address'] : '';
            $map = isset($row['map']) ? (string) $row['map'] : '';
            $players = isset($row['players']) ? (string) $row['players'] : '';
            $maxplayers = isset($row['maxplayers']) ? (string) $row['maxplayers'] : '';

            $html .= '<div class="wpgs-card">';
            $html .= '<div class="wpgs-card-top">';
            if ($show_name) {
                $name_output = '' !== trim($name) ? $name : __('Unavailable', 'gamequery-servers-lists');
                $html .= '<span class="wpgs-server-name">' . esc_html($name_output) . '</span>';
            }

            if ($show_status) {
                $html .= '<span class="wpgs-status wpgs-status-' . esc_attr($status_token) . '"><span class="wpgs-status-dot"></span>' . esc_html($this->status_label($status_token)) . '</span>';
            }
            $html .= '</div>';

            if ($show_address) {
                $html .= $this->render_server_address($address, $show_copy_address);
            }

            $pills = '';
            if ($show_players && $this->has_meaningful_value($players)) {
                $pills .= '<span class="wpgs-meta-pill"><span class="wpgs-meta-label">' . esc_html__('Players', 'gamequery-servers-lists') . '</span>' . esc_html($players) . '</span>';
            }
            if ($show_maxplayers && $this->has_meaningful_value($maxplayers)) {
                $pills .= '<span class="wpgs-meta-pill"><span class="wpgs-meta-label">' . esc_html__('Max', 'gamequery-servers-lists') . '</span>' . esc_html($maxplayers) . '</span>';
            }
            if ($show_map && $this->has_meaningful_value($map)) {
                $pills .= '<span class="wpgs-meta-pill"><span class="wpgs-meta-label">' . esc_html__('Map', 'gamequery-servers-lists') . '</span>' . esc_html($map) . '</span>';
            }

            if ('' !== $pills) {
                $html .= '<div class="wpgs-card-footer">' . $pills . '</div>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * @param int $list_id
     * @param string $template
     * @param array<int, array<string, string>> $rows
     * @param array<string, int> $display
     * @param string $custom_css
     * @return string
     */
    private function render_minimal_list_layout($list_id, $template, $rows, $display, $custom_css) {
        $show_name = !empty($display['show_name']);
        $show_address = !empty($display['show_address']);
        $show_map = !empty($display['show_map']);
        $show_players = !empty($display['show_players']);
        $show_maxplayers = !empty($display['show_maxplayers']);
        $show_status = !empty($display['show_status']);
        $show_copy_address = !empty($display['show_copy_address']) && $show_address;

        if (!$show_name && !$show_address) {
            $show_name = true;
        }

        $html = '';
        $html .= '<div class="wpgs-list wpgs-list-' . esc_attr((string) $list_id) . ' wpgs-template-' . esc_attr($template) . ' wpgs-layout-minimal-list" data-list-id="' . esc_attr((string) $list_id) . '">';
        if (!empty($custom_css)) {
            $html .= '<style>' . esc_html($custom_css) . '</style>';
        }

        foreach ($rows as $row) {
            $status_token = $this->status_token(isset($row['status']) ? (string) $row['status'] : '');
            $name = isset($row['name']) ? (string) $row['name'] : '';
            $address = isset($row['address']) ? (string) $row['address'] : '';
            $map = isset($row['map']) ? (string) $row['map'] : '';
            $players = isset($row['players']) ? (string) $row['players'] : '';
            $maxplayers = isset($row['maxplayers']) ? (string) $row['maxplayers'] : '';
            $players_text = $this->format_players_max_plain($players, $maxplayers, $show_players, $show_maxplayers);

            $html .= '<div class="wpgs-card">';
            if ($show_status) {
                $html .= '<span class="wpgs-indicator wpgs-indicator-' . esc_attr($status_token) . '"></span>';
            }

            $html .= '<div class="wpgs-card-main">';
            if ($show_name) {
                $name_output = '' !== trim($name) ? $name : __('Unavailable', 'gamequery-servers-lists');
                $html .= '<span class="wpgs-server-name">' . esc_html($name_output) . '</span>';
            }

            if ($show_address || ($show_map && $this->has_meaningful_value($map))) {
                $html .= '<div class="wpgs-server-meta">';
                if ($show_address) {
                    $html .= $this->render_server_address($address, $show_copy_address);
                }
                if ($show_map && $this->has_meaningful_value($map)) {
                    if ($show_address) {
                        $html .= '<span class="wpgs-sep">&middot;</span>';
                    }
                    $html .= '<span class="wpgs-map-val">' . esc_html($map) . '</span>';
                }
                $html .= '</div>';
            }

            $html .= '</div>';

            if ('-' !== $players_text) {
                $html .= '<span class="wpgs-players-val">' . esc_html($players_text) . '</span>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * @param int $list_id
     * @param string $template
     * @param array<int, array<string, string>> $rows
     * @param array<int, array<string, string>> $columns
     * @param string $custom_css
     * @param array<string, int> $display
     * @return string
     */
    private function render_table_layout($list_id, $template, $rows, $columns, $custom_css, $display) {
        $html = '';
        $show_copy_address = !empty($display['show_copy_address']);

        $html .= '<div class="wpgs-list wpgs-list-' . esc_attr((string) $list_id) . ' wpgs-template-' . esc_attr($template) . '" data-list-id="' . esc_attr((string) $list_id) . '">';
        if (!empty($custom_css)) {
            $html .= '<style>' . esc_html($custom_css) . '</style>';
        }

        $html .= '<div class="wpgs-table-wrapper">';
        $html .= '<table class="wpgs-table">';
        $html .= '<thead><tr>';

        foreach ($columns as $column) {
            $html .= '<th>' . esc_html($column['label']) . '</th>';
        }

        $html .= '</tr></thead>';
        $html .= '<tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($columns as $column) {
                $key = $column['key'];
                $value = isset($row[$key]) ? $row[$key] : '';

                if ('status' === $key) {
                    $status_class = $this->status_css_class((string) $value);
                    $html .= '<td><span class="wpgs-status ' . esc_attr($status_class) . '">' . esc_html((string) $value) . '</span></td>';
                } elseif ('address' === $key) {
                    $html .= '<td>' . $this->render_server_address((string) $value, $show_copy_address) . '</td>';
                } else {
                    $html .= '<td>' . esc_html((string) $value) . '</td>';
                }
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div></div>';

        return $html;
    }

    /**
     * @param int $list_id
     * @param string $template
     * @param array<int, array<string, string>> $rows
     * @param array<string, int> $display
     * @param string $custom_css
     * @param string $card_theme
     * @param string $card_theme_wrap
     * @return string
     */
    private function render_cards_layout($list_id, $template, $rows, $display, $custom_css, $card_theme, $card_theme_wrap = '') {
        $show_name = !empty($display['show_name']);
        $show_address = !empty($display['show_address']);
        $show_map = !empty($display['show_map']);
        $show_players = !empty($display['show_players']);
        $show_maxplayers = !empty($display['show_maxplayers']);
        $show_status = !empty($display['show_status']);
        $show_copy_address = !empty($display['show_copy_address']) && $show_address;

        if (!$show_name && !$show_address && !$show_map && !$show_players && !$show_maxplayers && !$show_status) {
            $show_address = true;
            $show_copy_address = !empty($display['show_copy_address']);
        }

        $html = '';

        $card_theme = sanitize_key((string) $card_theme);
        $card_theme_wrap = sanitize_html_class((string) $card_theme_wrap);
        $wrapper_classes = array(
            'wpgs-list',
            'wpgs-list-' . $list_id,
            'wpgs-template-' . $template,
            'wpgs-card-theme-layout',
            'wpgs-theme-' . $card_theme,
        );
        $wrapper_class_string = implode(
            ' ',
            array_filter(
                array_map('sanitize_html_class', $wrapper_classes)
            )
        );

        if ('' !== $card_theme_wrap) {
            $html .= '<div class="' . esc_attr($card_theme_wrap) . '">';
        }

        $html .= '<div class="' . esc_attr($wrapper_class_string) . '" data-list-id="' . esc_attr((string) $list_id) . '">';
        if (!empty($custom_css)) {
            $html .= '<style>' . esc_html($custom_css) . '</style>';
        }

        $html .= '<div class="wpgs-cards">';
        foreach ($rows as $row) {
            $name = isset($row['name']) ? (string) $row['name'] : '';
            $address = isset($row['address']) ? (string) $row['address'] : '';
            $map = isset($row['map']) ? (string) $row['map'] : '';
            $players = isset($row['players']) ? (string) $row['players'] : '';
            $maxplayers = isset($row['maxplayers']) ? (string) $row['maxplayers'] : '';
            $status = isset($row['status']) ? strtolower(trim((string) $row['status'])) : 'unknown';

            $status_text = $status;
            if ('' === $status_text) {
                $status_text = 'unknown';
            }

            $html .= '<div class="wpgs-card">';
            $html .= '<div class="wpgs-card-main">';

            if ($show_name) {
                $name_output = '' !== trim($name) ? $name : __('Unavailable', 'gamequery-servers-lists');
                $html .= '<span class="wpgs-server-name" title="' . esc_attr($name_output) . '">' . esc_html($name_output) . '</span>';
            }

            if ($show_address) {
                $html .= $this->render_server_address($address, $show_copy_address);
            }

            $html .= '</div>';

            if ($show_status) {
                $status_class = $this->status_css_class($status_text);
                $html .= '<div class="wpgs-card-status">';
                $html .= '<span class="wpgs-status ' . esc_attr($status_class) . '">';
                $html .= '<span class="wpgs-status-dot"></span>';
                $html .= esc_html(ucfirst($status_text));
                $html .= '</span>';
                $html .= '</div>';
            }

            $meta_html = '';
            if ($show_map && $this->has_meaningful_value($map)) {
                $meta_html .= '<span class="wpgs-meta-pill"><span class="wpgs-meta-label">' . esc_html__('Map', 'gamequery-servers-lists') . '</span>' . esc_html($map) . '</span>';
            }

            if ($show_players && $this->has_meaningful_value($players)) {
                $meta_html .= '<span class="wpgs-meta-pill"><span class="wpgs-meta-label">' . esc_html__('Players', 'gamequery-servers-lists') . '</span>' . esc_html($players) . '</span>';
            }

            if ($show_maxplayers && $this->has_meaningful_value($maxplayers)) {
                $meta_html .= '<span class="wpgs-meta-pill"><span class="wpgs-meta-label">' . esc_html__('Max', 'gamequery-servers-lists') . '</span>' . esc_html($maxplayers) . '</span>';
            }

            if ('' !== $meta_html) {
                $html .= '<div class="wpgs-card-meta">' . $meta_html . '</div>';
            }

            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '</div>';

        if ('' !== $card_theme_wrap) {
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * @param string $address
     * @param bool $show_copy_button
     * @return string
     */
    private function render_server_address($address, $show_copy_button = false) {
        $address = (string) $address;
        $html = '<span class="wpgs-server-address">' . esc_html($address);

        if ($show_copy_button && $this->has_meaningful_value($address)) {
            $copy_label = __('Copy', 'gamequery-servers-lists');
            $copied_label = __('Copied', 'gamequery-servers-lists');
            $failed_label = __('Failed', 'gamequery-servers-lists');
            $copy_aria_label = __('Copy server IP address', 'gamequery-servers-lists');

            $html .= ' <button type="button" class="wpgs-copy-address" data-copy-address="' . esc_attr($address) . '" data-copy-label="' . esc_attr($copy_label) . '" data-copied-label="' . esc_attr($copied_label) . '" data-failed-label="' . esc_attr($failed_label) . '" aria-label="' . esc_attr($copy_aria_label) . '" title="' . esc_attr($copy_label) . '">' . esc_html($copy_label) . '</button>';
        }

        $html .= '</span>';

        return $html;
    }

    /**
     * @param string $value
     * @return bool
     */
    private function has_meaningful_value($value) {
        $value = trim((string) $value);
        return '' !== $value && '-' !== $value;
    }

    /**
     * @param string $status
     * @return string
     */
    private function status_token($status) {
        $status = strtolower(trim((string) $status));
        if ('online' === $status) {
            return 'online';
        }

        if ('offline' === $status) {
            return 'offline';
        }

        return 'unknown';
    }

    /**
     * @param string $status_token
     * @return string
     */
    private function status_label($status_token) {
        if ('online' === $status_token) {
            return __('Online', 'gamequery-servers-lists');
        }

        if ('offline' === $status_token) {
            return __('Offline', 'gamequery-servers-lists');
        }

        return __('Unknown', 'gamequery-servers-lists');
    }

    /**
     * @param string $players
     * @param string $maxplayers
     * @param bool $show_players
     * @param bool $show_maxplayers
     * @return string
     */
    private function format_players_max($players, $maxplayers, $show_players, $show_maxplayers) {
        $players_value = ($show_players && $this->has_meaningful_value($players)) ? $players : '-';
        $max_value = ($show_maxplayers && $this->has_meaningful_value($maxplayers)) ? $maxplayers : '-';

        if ('-' === $players_value && '-' === $max_value) {
            return '-';
        }

        if ('-' !== $players_value && '-' !== $max_value) {
            return '<strong>' . esc_html($players_value) . '</strong> / ' . esc_html($max_value);
        }

        if ('-' !== $players_value) {
            return '<strong>' . esc_html($players_value) . '</strong>';
        }

        return esc_html($max_value);
    }

    /**
     * @param string $players
     * @param string $maxplayers
     * @param bool $show_players
     * @param bool $show_maxplayers
     * @return string
     */
    private function format_players_compact($players, $maxplayers, $show_players, $show_maxplayers) {
        $players_value = ($show_players && $this->has_meaningful_value($players)) ? $players : '-';
        $max_value = ($show_maxplayers && $this->has_meaningful_value($maxplayers)) ? $maxplayers : '-';

        if ('-' === $players_value && '-' === $max_value) {
            return '-';
        }

        if ('-' !== $players_value && '-' !== $max_value) {
            return $players_value . '/' . $max_value;
        }

        return '-' !== $players_value ? $players_value : $max_value;
    }

    /**
     * @param string $players
     * @param string $maxplayers
     * @param bool $show_players
     * @param bool $show_maxplayers
     * @return string
     */
    private function format_players_max_plain($players, $maxplayers, $show_players, $show_maxplayers) {
        $players_value = ($show_players && $this->has_meaningful_value($players)) ? $players : '-';
        $max_value = ($show_maxplayers && $this->has_meaningful_value($maxplayers)) ? $maxplayers : '-';

        if ('-' === $players_value && '-' === $max_value) {
            return '-';
        }

        if ('-' !== $players_value && '-' !== $max_value) {
            return $players_value . ' / ' . $max_value;
        }

        return '-' !== $players_value ? $players_value : $max_value;
    }

    /**
     * @param array<string, int> $display
     * @return array<int, array<string, string>>
     */
    private function resolve_columns($display) {
        $available = array(
            'name' => array(
                'key' => 'name',
                'label' => __('Name', 'gamequery-servers-lists'),
                'enabled' => !empty($display['show_name']),
            ),
            'address' => array(
                'key' => 'address',
                'label' => __('Address', 'gamequery-servers-lists'),
                'enabled' => !empty($display['show_address']),
            ),
            'map' => array(
                'key' => 'map',
                'label' => __('Map', 'gamequery-servers-lists'),
                'enabled' => !empty($display['show_map']),
            ),
            'players' => array(
                'key' => 'players',
                'label' => __('Players', 'gamequery-servers-lists'),
                'enabled' => !empty($display['show_players']),
            ),
            'maxplayers' => array(
                'key' => 'maxplayers',
                'label' => __('Max Players', 'gamequery-servers-lists'),
                'enabled' => !empty($display['show_maxplayers']),
            ),
            'status' => array(
                'key' => 'status',
                'label' => __('Status', 'gamequery-servers-lists'),
                'enabled' => !empty($display['show_status']),
            ),
        );

        $columns = array();
        foreach ($available as $column) {
            if (!empty($column['enabled'])) {
                $columns[] = array(
                    'key' => (string) $column['key'],
                    'label' => (string) $column['label'],
                );
            }
        }

        if (empty($columns)) {
            $columns[] = array(
                'key' => 'address',
                'label' => __('Address', 'gamequery-servers-lists'),
            );
        }

        return $columns;
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @param array<string, mixed> $payload
     * @return array<int, array<string, string>>
     */
    private function build_rows($groups, $payload) {
        $rows = array();

        foreach ($groups as $group) {
            if (empty($group['servers']) || !is_array($group['servers'])) {
                continue;
            }

            foreach ($group['servers'] as $server_address) {
                $server_address = trim((string) $server_address);
                if (empty($server_address)) {
                    continue;
                }

                $server_data = array();
                if (isset($payload[$server_address]) && is_array($payload[$server_address])) {
                    $server_data = $payload[$server_address];
                }

                $updater = isset($server_data['_updater']) && is_array($server_data['_updater']) ? $server_data['_updater'] : array();

                $status = 'unknown';
                if (isset($updater['status']) && '' !== trim((string) $updater['status'])) {
                    $status = strtolower(trim((string) $updater['status']));
                }

                $rows[] = array(
                    'name' => $this->string_value($server_data, 'name', __('Unavailable', 'gamequery-servers-lists')),
                    'address' => $server_address,
                    'map' => $this->string_value($server_data, 'map', '-'),
                    'players' => $this->number_value($server_data, 'players'),
                    'maxplayers' => $this->number_value($server_data, 'maxplayers'),
                    'status' => $status,
                );
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $source
     * @param string $key
     * @param string $fallback
     * @return string
     */
    private function string_value($source, $key, $fallback = '') {
        if (!isset($source[$key])) {
            return $fallback;
        }

        $value = trim((string) $source[$key]);
        return '' !== $value ? $value : $fallback;
    }

    /**
     * @param array<string, mixed> $source
     * @param string $key
     * @return string
     */
    private function number_value($source, $key) {
        if (!isset($source[$key]) || !is_numeric($source[$key])) {
            return '-';
        }

        return (string) ((int) $source[$key]);
    }

    /**
     * @param string $status
     * @return string
     */
    private function status_css_class($status) {
        $status = $this->status_token((string) $status);
        if ('online' === $status) {
            return 'wpgs-status-online';
        }

        if ('offline' === $status) {
            return 'wpgs-status-offline';
        }

        return 'wpgs-status-unknown';
    }

    /**
     * @param string $message
     * @param string $type
     * @return string
     */
    private function render_notice($message, $type = 'info') {
        $type = sanitize_html_class($type);
        return '<div class="wpgs-notice wpgs-notice-' . esc_attr($type) . '">' . esc_html($message) . '</div>';
    }
}
