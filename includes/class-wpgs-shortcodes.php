<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPGS_Shortcodes {
    const SHORTCODE_ALIAS = 'wpgs_list';
    const SHORTCODE_DYNAMIC_PREFIX = 'wpgs_list_';

    /**
     * @var WPGS_Renderer
     */
    private $renderer;

    /**
     * @param WPGS_Renderer $renderer
     */
    public function __construct($renderer) {
        $this->renderer = $renderer;
    }

    public function register() {
        add_shortcode(self::SHORTCODE_ALIAS, array($this, 'render_alias_shortcode'));
        add_action('init', array($this, 'register_dynamic_shortcodes'), 20);
        add_filter('widget_custom_html_content', array($this, 'render_widget_shortcodes'), 11);
    }

    public function register_dynamic_shortcodes() {
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
            return;
        }

        foreach ($list_ids as $list_id) {
            $shortcode_tag = self::SHORTCODE_DYNAMIC_PREFIX . absint($list_id);
            add_shortcode($shortcode_tag, array($this, 'render_dynamic_shortcode'));
        }
    }

    /**
     * @param array<string, mixed> $atts
     * @return string
     */
    public function render_alias_shortcode($atts) {
        $atts = shortcode_atts(
            array(
                'id' => 0,
            ),
            $atts,
            self::SHORTCODE_ALIAS
        );

        $list_id = absint($atts['id']);
        if ($list_id <= 0) {
            return '';
        }

        return $this->renderer->render_list($list_id);
    }

    /**
     * @param array<string, mixed> $atts
     * @param string|null $content
     * @param string $tag
     * @return string
     */
    public function render_dynamic_shortcode($atts, $content = null, $tag = '') {
        if (!preg_match('/^wpgs_list_(\d+)$/', (string) $tag, $matches)) {
            return '';
        }

        $list_id = isset($matches[1]) ? absint($matches[1]) : 0;
        if ($list_id <= 0) {
            return '';
        }

        return $this->renderer->render_list($list_id);
    }

    /**
     * @param string $content
     * @return string
     */
    public function render_widget_shortcodes($content) {
        if (!is_string($content) || '' === $content) {
            return $content;
        }

        if (false === strpos($content, '[' . self::SHORTCODE_ALIAS)) {
            return $content;
        }

        return do_shortcode($content);
    }
}
