<?php
/**
 * Plugin Name: GameQuery Server Lists
 * Plugin URI: https://gamequery.dev
 * Description: Build reusable GameQuery-powered server lists and embed them with shortcodes.
 * Version: 0.1.4
 * Author: pbdaemon
 * Author URI: https://profiles.wordpress.org/pbdaemon/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gamequery-servers-lists
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPGS_PLUGIN_FILE', __FILE__);
define('WPGS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPGS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPGS_VERSION', '0.1.4');

require_once WPGS_PLUGIN_DIR . 'includes/class-wpgs-settings.php';
require_once WPGS_PLUGIN_DIR . 'includes/class-wpgs-api-client.php';
require_once WPGS_PLUGIN_DIR . 'includes/class-wpgs-stats.php';
require_once WPGS_PLUGIN_DIR . 'includes/class-wpgs-lists.php';
require_once WPGS_PLUGIN_DIR . 'includes/class-wpgs-cron.php';
require_once WPGS_PLUGIN_DIR . 'includes/class-wpgs-renderer.php';
require_once WPGS_PLUGIN_DIR . 'includes/class-wpgs-shortcodes.php';
require_once WPGS_PLUGIN_DIR . 'includes/class-wpgs-admin.php';
require_once WPGS_PLUGIN_DIR . 'includes/class-wpgs-widget.php';

final class WPGS_Plugin {
    /**
     * @var WPGS_Plugin|null
     */
    private static $instance = null;

    /**
     * @var WPGS_API_Client
     */
    private $api_client;

    /**
     * @var WPGS_Renderer
     */
    private $renderer;

    /**
     * @var WPGS_Stats
     */
    private $stats;

    /**
     * @var WPGS_Lists
     */
    private $lists;

    /**
     * @var WPGS_Cron
     */
    private $cron;

    /**
     * @var WPGS_Shortcodes
     */
    private $shortcodes;

    /**
     * @var WPGS_Admin
     */
    private $admin;

    /**
     * @var WPGS_Widget
     */
    private $widget;

    private function __construct() {
        $this->api_client = new WPGS_API_Client();
        $this->renderer = new WPGS_Renderer($this->api_client);
        $this->stats = new WPGS_Stats();
        $this->lists = new WPGS_Lists();
        $this->cron = new WPGS_Cron($this->api_client);
        $this->shortcodes = new WPGS_Shortcodes($this->renderer);
        $this->admin = new WPGS_Admin();
        $this->widget = new WPGS_Widget();

        $this->register_hooks();
    }

    /**
     * @return WPGS_Plugin
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function register_hooks() {
        $this->stats->register();
        $this->lists->register();
        $this->cron->register();
        $this->shortcodes->register();
        $this->admin->register();
        $this->widget->register();

        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
    }

    public function enqueue_frontend_assets() {
        $frontend_css_path = WPGS_PLUGIN_DIR . 'assets/frontend.css';
        $frontend_js_path = WPGS_PLUGIN_DIR . 'assets/frontend.js';
        $frontend_css_version = file_exists($frontend_css_path) ? (string) filemtime($frontend_css_path) : WPGS_VERSION;
        $frontend_js_version = file_exists($frontend_js_path) ? (string) filemtime($frontend_js_path) : WPGS_VERSION;

        wp_enqueue_style(
            'wpgs-frontend',
            WPGS_PLUGIN_URL . 'assets/frontend.css',
            array(),
            $frontend_css_version
        );

        wp_enqueue_script(
            'wpgs-frontend',
            WPGS_PLUGIN_URL . 'assets/frontend.js',
            array(),
            $frontend_js_version,
            true
        );

        $tracking_config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => 'wpgs_track_event',
            'nonce' => wp_create_nonce('wpgs_track_event'),
        );

        wp_add_inline_script(
            'wpgs-frontend',
            'window.WPGSStats = ' . wp_json_encode($tracking_config) . ';',
            'before'
        );
    }

    public static function activate() {
        WPGS_Settings::ensure_defaults();
        WPGS_Lists::register_post_type_static();
        WPGS_Cron::activate();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        WPGS_Cron::deactivate();
        flush_rewrite_rules();
    }
}

register_activation_hook(__FILE__, array('WPGS_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('WPGS_Plugin', 'deactivate'));

WPGS_Settings::ensure_defaults();
WPGS_Plugin::instance();
