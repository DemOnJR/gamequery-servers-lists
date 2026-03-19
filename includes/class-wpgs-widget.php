<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPGS_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'wpgs_server_list',
            __('GameQuery Servers', 'gamequery-server-lists'),
            array(
                'classname' => 'wpgs-server-list-widget',
                'description' => __('Display a GameQuery server list by selecting a list name or entering a shortcode.', 'gamequery-server-lists'),
            )
        );
    }

    public function register() {
        add_action('widgets_init', array($this, 'register_widget'));
    }

    public function register_widget() {
        register_widget(__CLASS__);
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $instance
     */
    public function widget($args, $instance) {
        $title = isset($instance['title']) ? (string) $instance['title'] : '';
        $shortcode = $this->resolve_shortcode($instance);

        if ('' === $shortcode) {
            return;
        }

        $output = do_shortcode($shortcode);
        if ('' === trim((string) $output)) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Provided by theme wrappers.
        echo isset($args['before_widget']) ? $args['before_widget'] : '';

        if ('' !== $title) {
            $filtered_title = apply_filters('widget_title', $title, $instance, $this->id_base);
            if ('' !== trim((string) $filtered_title)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Provided by theme wrappers.
                echo isset($args['before_title']) ? $args['before_title'] : '';
                echo wp_kses_post((string) $filtered_title);
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Provided by theme wrappers.
                echo isset($args['after_title']) ? $args['after_title'] : '';
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is escaped by renderer.
        echo $output;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Provided by theme wrappers.
        echo isset($args['after_widget']) ? $args['after_widget'] : '';
    }

    /**
     * @param array<string, mixed> $instance
     */
    public function form($instance) {
        $title = isset($instance['title']) ? (string) $instance['title'] : '';
        $list_id = isset($instance['list_id']) ? absint($instance['list_id']) : 0;
        $shortcode = isset($instance['shortcode']) ? (string) $instance['shortcode'] : '';
        $lists = $this->get_published_lists();

        $title_field_id = $this->get_field_id('title');
        $title_field_name = $this->get_field_name('title');
        $list_field_id = $this->get_field_id('list_id');
        $list_field_name = $this->get_field_name('list_id');
        $shortcode_field_id = $this->get_field_id('shortcode');
        $shortcode_field_name = $this->get_field_name('shortcode');

        echo '<p>';
        echo '<label for="' . esc_attr($title_field_id) . '">' . esc_html__('Title', 'gamequery-server-lists') . '</label>';
        echo '<input class="widefat" id="' . esc_attr($title_field_id) . '" name="' . esc_attr($title_field_name) . '" type="text" value="' . esc_attr($title) . '" />';
        echo '</p>';

        echo '<p>';
        echo '<label for="' . esc_attr($list_field_id) . '">' . esc_html__('Server List', 'gamequery-server-lists') . '</label>';
        echo '<select class="widefat" id="' . esc_attr($list_field_id) . '" name="' . esc_attr($list_field_name) . '">';
        echo '<option value="0">' . esc_html__('Select a list...', 'gamequery-server-lists') . '</option>';
        foreach ($lists as $list_post) {
            $name = '' !== trim((string) $list_post->post_title) ? (string) $list_post->post_title : sprintf(__('List #%d', 'gamequery-server-lists'), absint($list_post->ID));
            echo '<option value="' . esc_attr((string) absint($list_post->ID)) . '"' . selected($list_id, absint($list_post->ID), false) . '>' . esc_html($name) . '</option>';
        }
        echo '</select>';
        echo '</p>';

        echo '<p class="description">' . esc_html__('Select a published list by name or use a shortcode override below.', 'gamequery-server-lists') . '</p>';

        echo '<p>';
        echo '<label for="' . esc_attr($shortcode_field_id) . '">' . esc_html__('Shortcode Override (optional)', 'gamequery-server-lists') . '</label>';
        echo '<input class="widefat" id="' . esc_attr($shortcode_field_id) . '" name="' . esc_attr($shortcode_field_name) . '" type="text" value="' . esc_attr($shortcode) . '" placeholder="[gamequery_8]" />';
        echo '</p>';

        echo '<p class="description">' . esc_html__('Examples: [gamequery_8], gamequery_8, [gamequery id="8"], or just 8. Shortcode override takes priority.', 'gamequery-server-lists') . '</p>';
    }

    /**
     * @param array<string, mixed> $new_instance
     * @param array<string, mixed> $old_instance
     * @return array<string, mixed>
     */
    public function update($new_instance, $old_instance) {
        return array(
            'title' => isset($new_instance['title']) ? sanitize_text_field((string) $new_instance['title']) : '',
            'list_id' => isset($new_instance['list_id']) ? absint($new_instance['list_id']) : 0,
            'shortcode' => isset($new_instance['shortcode']) ? sanitize_text_field((string) $new_instance['shortcode']) : '',
        );
    }

    /**
     * @return array<int, WP_Post>
     */
    private function get_published_lists() {
        $lists = get_posts(
            array(
                'post_type' => WPGS_Lists::POST_TYPE,
                'post_status' => 'publish',
                'numberposts' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
                'no_found_rows' => true,
            )
        );

        if (!is_array($lists)) {
            return array();
        }

        return $lists;
    }

    /**
     * @param array<string, mixed> $instance
     * @return string
     */
    private function resolve_shortcode($instance) {
        $shortcode = isset($instance['shortcode']) ? trim((string) $instance['shortcode']) : '';
        if ('' !== $shortcode) {
            return $this->normalize_shortcode($shortcode);
        }

        $list_id = isset($instance['list_id']) ? absint($instance['list_id']) : 0;
        if ($list_id <= 0) {
            return '';
        }

        return '[gamequery id="' . $list_id . '"]';
    }

    /**
     * @param string $shortcode
     * @return string
     */
    private function normalize_shortcode($shortcode) {
        if (preg_match('/^\[gamequery_(\d+)\]$/i', $shortcode, $matches)) {
            return '[gamequery id="' . absint($matches[1]) . '"]';
        }

        if (preg_match('/^gamequery_(\d+)$/i', $shortcode, $matches)) {
            return '[gamequery id="' . absint($matches[1]) . '"]';
        }

        if (preg_match('/^\[gamequery\s+id\s*=\s*["\']?(\d+)["\']?\s*\]$/i', $shortcode, $matches)) {
            return '[gamequery id="' . absint($matches[1]) . '"]';
        }

        if (preg_match('/^(\d+)$/', $shortcode, $matches)) {
            return '[gamequery id="' . absint($matches[1]) . '"]';
        }

        return $shortcode;
    }
}
