<?php
/**
 * Plugin Name: SIMON Integration
 * Plugin URI: https://github.com/your-org/simon-wordpress
 * Description: Integrates WordPress sites with SIMON monitoring system
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * Text Domain: simon
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SIMON_VERSION', '1.0.0');
define('SIMON_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SIMON_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main SIMON class
 */
class Simon_Integration {

    /**
     * Instance of this class
     */
    private static $instance = null;

    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Settings
        add_action('admin_init', [$this, 'register_settings']);
        
        // Cron hook
        add_action('simon_submit_data', [$this, 'submit_data_cron']);
        
        // Schedule cron if enabled
        add_action('init', [$this, 'schedule_cron']);
        
        // Add rewrite rules for endpoints
        add_action('init', [$this, 'add_rewrite_rules']);
        add_action('template_redirect', [$this, 'handle_endpoints']);
        
        // WP-CLI command
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('simon submit', [$this, 'wpcli_submit']);
        }
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            'SIMON Settings',
            'SIMON',
            'manage_options',
            'simon-settings',
            [$this, 'settings_page']
        );
        
        add_management_page(
            'SIMON Client',
            'SIMON Client',
            'manage_options',
            'simon-client',
            [$this, 'client_page']
        );
        
        add_management_page(
            'SIMON Site',
            'SIMON Site',
            'manage_options',
            'simon-site',
            [$this, 'site_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('simon_settings', 'simon_auth_key');
        register_setting('simon_settings', 'simon_api_url');
        register_setting('simon_settings', 'simon_enable_cron');
        register_setting('simon_settings', 'simon_cron_interval');
        register_setting('simon_settings', 'simon_client_id');
        register_setting('simon_settings', 'simon_client_name');
        register_setting('simon_settings', 'simon_contact_name');
        register_setting('simon_settings', 'simon_contact_email');
        register_setting('simon_settings', 'simon_site_id');
        register_setting('simon_settings', 'simon_site_name');
        register_setting('simon_settings', 'simon_site_url');
        register_setting('simon_settings', 'simon_external_id');
        register_setting('simon_settings', 'simon_auth_token');
        register_setting('simon_settings', 'simon_client_auth_key');
    }

    /**
     * Settings page
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['submit'])) {
            check_admin_referer('simon_settings');
            update_option('simon_auth_key', sanitize_text_field($_POST['simon_auth_key']));
            update_option('simon_api_url', sanitize_text_field($_POST['simon_api_url']));
            update_option('simon_enable_cron', isset($_POST['simon_enable_cron']));
            update_option('simon_cron_interval', intval($_POST['simon_cron_interval']));
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }

        $auth_key = get_option('simon_auth_key', '');
        $api_url = get_option('simon_api_url', '');
        $enable_cron = get_option('simon_enable_cron', false);
        $cron_interval = get_option('simon_cron_interval', 86400);
        ?>
        <div class="wrap">
            <h1>SIMON Settings</h1>
            <form method="post">
                <?php wp_nonce_field('simon_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="simon_auth_key">Auth Key</label>
                        </th>
                        <td>
                            <input type="text" id="simon_auth_key" name="simon_auth_key" 
                                   value="<?php echo esc_attr($auth_key); ?>" 
                                   class="regular-text" required>
                            <p class="description"><strong>Required:</strong> Authentication key for SIMON API. This must be set before any other functions will work. Get this from the SIMON Settings page.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="simon_api_url">SIMON API URL</label>
                        </th>
                        <td>
                            <input type="url" id="simon_api_url" name="simon_api_url" 
                                   value="<?php echo esc_attr($api_url); ?>" 
                                   class="regular-text" required>
                            <p class="description">Base URL of the SIMON API server (e.g., http://localhost:3000)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="simon_enable_cron">Enable Cron</label>
                        </th>
                        <td>
                            <input type="checkbox" id="simon_enable_cron" name="simon_enable_cron" 
                                   value="1" <?php checked($enable_cron); ?>>
                            <label for="simon_enable_cron">Enable automatic submission via WordPress cron</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="simon_cron_interval">Cron Interval</label>
                        </th>
                        <td>
                            <select id="simon_cron_interval" name="simon_cron_interval">
                                <option value="3600" <?php selected($cron_interval, 3600); ?>>Every hour</option>
                                <option value="21600" <?php selected($cron_interval, 21600); ?>>Every 6 hours</option>
                                <option value="86400" <?php selected($cron_interval, 86400); ?>>Daily</option>
                                <option value="604800" <?php selected($cron_interval, 604800); ?>>Weekly</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Client configuration page
     */
    public function client_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $auth_key = get_option('simon_auth_key', '');
        $api_url = get_option('simon_api_url', '');
        $client_id = get_option('simon_client_id', '');

        if (empty($auth_key)) {
            echo '<div class="wrap"><div class="notice notice-error"><p><strong>Auth Key Required</strong></p><p>You must configure the Auth Key in <a href="' . admin_url('options-general.php?page=simon-settings') . '">SIMON Settings</a> before you can use any other features.</p></div></div>';
            return;
        }

        if (empty($api_url)) {
            echo '<div class="wrap"><div class="notice notice-error"><p>Please configure the API URL in <a href="' . admin_url('options-general.php?page=simon-settings') . '">SIMON Settings</a> first.</p></div></div>';
            return;
        }

        if (isset($_POST['create_client'])) {
            check_admin_referer('simon_client');
            $this->create_client();
        }

        if (isset($_POST['clear_client'])) {
            check_admin_referer('simon_client');
            delete_option('simon_client_id');
            delete_option('simon_client_name');
            delete_option('simon_contact_name');
            delete_option('simon_contact_email');
            echo '<div class="notice notice-success"><p>Client ID cleared!</p></div>';
        }

        $client_name = get_option('simon_client_name', '');
        $contact_name = get_option('simon_contact_name', '');
        $contact_email = get_option('simon_contact_email', '');
        ?>
        <div class="wrap">
            <h1>SIMON Client Configuration</h1>
            <?php if ($client_id): ?>
                <div class="notice notice-info">
                    <p><strong>Current Client ID: <?php echo esc_html($client_id); ?></strong></p>
                    <?php 
                    $client_auth_key = get_option('simon_client_auth_key', '');
                    if ($client_auth_key): ?>
                        <p><strong>Client Auth Key: <?php echo esc_html($client_auth_key); ?></strong></p>
                        <p class="description">Keep this client auth key secure. It is required for submitting data to the SIMON API for all sites belonging to this client.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('simon_client'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="client_name">Client Name</label>
                        </th>
                        <td>
                            <input type="text" id="client_name" name="client_name" 
                                   value="<?php echo esc_attr($client_name); ?>" 
                                   class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="contact_name">Contact Name</label>
                        </th>
                        <td>
                            <input type="text" id="contact_name" name="contact_name" 
                                   value="<?php echo esc_attr($contact_name); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="contact_email">Contact Email</label>
                        </th>
                        <td>
                            <input type="email" id="contact_email" name="contact_email" 
                                   value="<?php echo esc_attr($contact_email); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                </table>
                <?php submit_button('Create/Update Client', 'primary', 'create_client'); ?>
                <?php if ($client_id): ?>
                    <?php submit_button('Clear Client ID', 'secondary', 'clear_client'); ?>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    /**
     * Site configuration page
     */
    public function site_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $auth_key = get_option('simon_auth_key', '');
        $api_url = get_option('simon_api_url', '');
        $client_id = get_option('simon_client_id', '');
        $site_id = get_option('simon_site_id', '');

        if (empty($auth_key)) {
            echo '<div class="wrap"><div class="notice notice-error"><p><strong>Auth Key Required</strong></p><p>You must configure the Auth Key in <a href="' . admin_url('options-general.php?page=simon-settings') . '">SIMON Settings</a> before you can use any other features.</p></div></div>';
            return;
        }

        if (empty($api_url)) {
            echo '<div class="wrap"><div class="notice notice-error"><p>Please configure the API URL in <a href="' . admin_url('options-general.php?page=simon-settings') . '">SIMON Settings</a> first.</p></div></div>';
            return;
        }

        if (empty($client_id)) {
            echo '<div class="wrap"><div class="notice notice-error"><p>Please create a client in <a href="' . admin_url('tools.php?page=simon-client') . '">SIMON Client Configuration</a> first.</p></div></div>';
            return;
        }

        if (isset($_POST['create_site'])) {
            check_admin_referer('simon_site');
            $this->create_site();
        }

        if (isset($_POST['clear_site'])) {
            check_admin_referer('simon_site');
            delete_option('simon_site_id');
            echo '<div class="notice notice-success"><p>Site ID cleared!</p></div>';
        }

        if (isset($_POST['submit_now'])) {
            check_admin_referer('simon_site');
            $result = $this->submit_data();
            if ($result) {
                echo '<div class="notice notice-success"><p>Site data submitted successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to submit site data. Check the logs for details.</p></div>';
            }
        }

        $site_name = get_option('simon_site_name', get_bloginfo('name'));
        $site_url = get_option('simon_site_url', home_url());
        $external_id = get_option('simon_external_id', '');
        $auth_token = get_option('simon_auth_token', '');
        ?>
        <div class="wrap">
            <h1>SIMON Site Configuration</h1>
            <?php if ($site_id): ?>
                <div class="notice notice-info">
                    <p><strong>Current Site ID: <?php echo esc_html($site_id); ?></strong></p>
                    <?php 
                    $client_auth_key = get_option('simon_client_auth_key', '');
                    if ($client_auth_key): ?>
                        <p class="description">This site uses the client auth key for API submissions. The client auth key is configured in the Client Configuration page.</p>
                    <?php else: ?>
                        <p class="description">Warning: Client auth key not configured. Please create/update the client to get a client auth key.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('simon_site'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="site_name">Site Name</label>
                        </th>
                        <td>
                            <input type="text" id="site_name" name="site_name" 
                                   value="<?php echo esc_attr($site_name); ?>" 
                                   class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="site_url">Site URL</label>
                        </th>
                        <td>
                            <input type="url" id="site_url" name="site_url" 
                                   value="<?php echo esc_attr($site_url); ?>" 
                                   class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="external_id">External ID</label>
                        </th>
                        <td>
                            <input type="text" id="external_id" name="external_id" 
                                   value="<?php echo esc_attr($external_id); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="auth_token">Auth Token</label>
                        </th>
                        <td>
                            <input type="text" id="auth_token" name="auth_token" 
                                   value="<?php echo esc_attr($auth_token); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                </table>
                <?php submit_button('Create/Update Site', 'primary', 'create_site'); ?>
                <?php if ($site_id): ?>
                    <?php submit_button('Clear Site ID', 'secondary', 'clear_site'); ?>
                    <?php submit_button('Submit Data Now', 'primary', 'submit_now'); ?>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    /**
     * Create client
     */
    private function create_client() {
        $api_url = get_option('simon_api_url', '');
        $client_data = [
            'name' => sanitize_text_field($_POST['client_name']),
            'contact_name' => sanitize_text_field($_POST['contact_name']),
            'contact_email' => sanitize_email($_POST['contact_email']),
        ];

        $response = wp_remote_post(rtrim($api_url, '/') . '/api/clients', [
            'body' => json_encode($client_data),
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Auth-Key' => $auth_key,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            echo '<div class="notice notice-error"><p>Error: ' . esc_html($response->get_error_message()) . '</p></div>';
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 201 || $code === 409) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $client_id = $body['client_id'] ?? null;
            $client_auth_key = $body['auth_key'] ?? null;

            if ($client_id) {
                update_option('simon_client_id', $client_id);
                update_option('simon_client_name', $client_data['name']);
                update_option('simon_contact_name', $client_data['contact_name']);
                update_option('simon_contact_email', $client_data['contact_email']);
                if ($client_auth_key) {
                    update_option('simon_client_auth_key', $client_auth_key);
                }
                echo '<div class="notice notice-success"><p>Client created/updated successfully! Client ID: ' . esc_html($client_id) . '</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>Failed to create client. Status: ' . esc_html($code) . '</p></div>';
        }
    }

    /**
     * Create site
     */
    private function create_site() {
        $auth_key = get_option('simon_auth_key', '');
        $api_url = get_option('simon_api_url', '');
        $client_id = get_option('simon_client_id', '');
        $site_data = [
            'client_id' => (int) $client_id,
            'cms' => 'wordpress',
            'name' => sanitize_text_field($_POST['site_name']),
            'url' => esc_url_raw($_POST['site_url']),
            'external_id' => sanitize_text_field($_POST['external_id']),
            'auth_token' => sanitize_text_field($_POST['auth_token']),
        ];

        $response = wp_remote_post(rtrim($api_url, '/') . '/api/sites', [
            'body' => json_encode($site_data),
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Auth-Key' => $auth_key,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            echo '<div class="notice notice-error"><p>Error: ' . esc_html($response->get_error_message()) . '</p></div>';
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 201 || $code === 409) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $site_id = $body['site_id'] ?? null;
            $auth_key = $body['auth_key'] ?? null;

            if ($site_id) {
                update_option('simon_site_id', $site_id);
                update_option('simon_site_name', $site_data['name']);
                update_option('simon_site_url', $site_data['url']);
                update_option('simon_external_id', $site_data['external_id']);
                update_option('simon_auth_token', $site_data['auth_token']);
                echo '<div class="notice notice-success"><p>Site created/updated successfully! Site ID: ' . esc_html($site_id) . '</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>Failed to create site. Status: ' . esc_html($code) . '</p></div>';
        }
    }

    /**
     * Collect site data
     */
    private function collect_site_data() {
        global $wpdb;

        $data = [];

        // Core version and status
        $core_version = get_bloginfo('version');
        $data['core'] = [
            'version' => $core_version,
            'status' => $this->get_core_status($core_version),
        ];

        // Log summary
        $data['log_summary'] = $this->get_log_summary();

        // Environment
        $data['environment'] = $this->get_environment();

        // Extensions (plugins)
        $data['extensions'] = $this->get_extensions();

        // Themes
        $data['themes'] = $this->get_themes();

        return $data;
    }

    /**
     * Get core status
     */
    private function get_core_status($version) {
        $update_core = get_site_transient('update_core');
        if (isset($update_core->updates) && !empty($update_core->updates)) {
            return 'outdated';
        }
        return 'up-to-date';
    }

    /**
     * Get log summary
     */
    private function get_log_summary() {
        global $wpdb;

        $summary = [
            'total' => 0,
            'error' => 0,
            'warning' => 0,
            'by_level' => [],
        ];

        // Count errors from error_log if available
        // WordPress doesn't have a centralized log like Drupal's watchdog
        // This is a simplified version
        $summary['by_level'][] = ['level' => 'notice', 'count' => 0];

        return $summary;
    }

    /**
     * Get environment
     */
    private function get_environment() {
        global $wpdb;

        $env = [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'max_input_vars' => (int) ini_get('max_input_vars'),
            'web_server' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'database_type' => 'mysql',
            'database_version' => $wpdb->db_version(),
            'php_modules' => get_loaded_extensions(),
        ];

        return $env;
    }

    /**
     * Get extensions (plugins)
     */
    private function get_extensions() {
        $extensions = [];
        
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        foreach ($plugins as $plugin_file => $plugin_data) {
            $extensions[] = [
                'type' => 'plugin',
                'machine_name' => dirname($plugin_file),
                'human_name' => $plugin_data['Name'],
                'version' => $plugin_data['Version'],
                'status' => is_plugin_active($plugin_file) ? 'enabled' : 'disabled',
                'is_custom' => $this->is_custom_plugin($plugin_file),
            ];
        }

        return $extensions;
    }

    /**
     * Check if plugin is custom
     */
    private function is_custom_plugin($plugin_file) {
        // Custom plugins are typically in wp-content/plugins/custom or mu-plugins
        return strpos($plugin_file, 'custom/') !== false || 
               strpos($plugin_file, 'mu-plugins/') !== false;
    }

    /**
     * Get themes
     */
    private function get_themes() {
        $themes = [];
        $wp_themes = wp_get_themes();

        foreach ($wp_themes as $theme_slug => $theme) {
            $themes[] = [
                'machine_name' => $theme_slug,
                'human_name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'status' => ($theme_slug === get_stylesheet()) ? 'default' : 'enabled',
                'is_custom' => $this->is_custom_theme($theme_slug),
            ];
        }

        return $themes;
    }

    /**
     * Check if theme is custom
     */
    private function is_custom_theme($theme_slug) {
        $theme = wp_get_theme($theme_slug);
        $theme_root = $theme->get_theme_root();
        return strpos($theme_root, 'custom') !== false;
    }

    /**
     * Submit data to SIMON
     */
    public function submit_data() {
        $api_url = get_option('simon_api_url', '');
        $client_id = get_option('simon_client_id', '');
        $site_id = get_option('simon_site_id', '');

        if (empty($api_url) || empty($client_id) || empty($site_id)) {
            return false;
        }

        $data = $this->collect_site_data();
        $payload = [
            'client_id' => (int) $client_id,
            'site_id' => (int) $site_id,
            'core' => $data['core'],
            'log_summary' => $data['log_summary'],
            'environment' => $data['environment'],
            'extensions' => $data['extensions'],
            'themes' => $data['themes'],
        ];

        // Get client auth_key from options (auth_key is now associated with client, not site)
        $client_auth_key = get_option('simon_client_auth_key', '');
        
        if (empty($client_auth_key)) {
            error_log('SIMON API error: client auth_key not configured. Please create/update the client to get a client auth_key.');
            return false;
        }
        
        $response = wp_remote_post(rtrim($api_url, '/') . '/api/intake', [
            'body' => json_encode($payload),
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Auth-Key' => $client_auth_key,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('SIMON API error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200 || $code === 201) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            error_log('SIMON submission successful. Snapshot ID: ' . ($body['snapshot_id'] ?? 'N/A'));
            return true;
        }

        return false;
    }

    /**
     * Cron submission
     */
    public function submit_data_cron() {
        if (!get_option('simon_enable_cron', false)) {
            return;
        }
        $this->submit_data();
    }

    /**
     * Schedule cron
     */
    public function schedule_cron() {
        if (!get_option('simon_enable_cron', false)) {
            wp_clear_scheduled_hook('simon_submit_data');
            return;
        }

        // Add custom cron schedule
        add_filter('cron_schedules', [$this, 'add_cron_schedule']);

        $interval = get_option('simon_cron_interval', 86400);
        $schedule_name = $this->get_schedule_name($interval);
        
        if (!wp_next_scheduled('simon_submit_data')) {
            wp_schedule_event(time(), $schedule_name, 'simon_submit_data');
        }
    }

    /**
     * Add custom cron schedule
     */
    public function add_cron_schedule($schedules) {
        $interval = get_option('simon_cron_interval', 86400);
        $schedule_name = $this->get_schedule_name($interval);
        
        if (!isset($schedules[$schedule_name])) {
            $schedules[$schedule_name] = [
                'interval' => $interval,
                'display' => $this->get_schedule_display($interval),
            ];
        }
        
        return $schedules;
    }

    /**
     * Get schedule name from interval
     */
    private function get_schedule_name($interval) {
        $map = [
            3600 => 'simon_hourly',
            21600 => 'simon_six_hours',
            86400 => 'simon_daily',
            604800 => 'simon_weekly',
        ];
        return $map[$interval] ?? 'simon_daily';
    }

    /**
     * Get schedule display name
     */
    private function get_schedule_display($interval) {
        $map = [
            3600 => 'Every Hour',
            21600 => 'Every 6 Hours',
            86400 => 'Daily',
            604800 => 'Weekly',
        ];
        return $map[$interval] ?? 'Daily';
    }

    /**
     * WP-CLI command
     */
    public function wpcli_submit($args, $assoc_args) {
        $api_url = get_option('simon_api_url', '');
        $client_id = get_option('simon_client_id', '');
        $site_id = get_option('simon_site_id', '');

        if (empty($api_url)) {
            WP_CLI::error('SIMON API URL not configured. Please configure it in Settings → SIMON');
            return;
        }

        if (empty($client_id)) {
            WP_CLI::error('SIMON Client ID not configured. Please create a client in Tools → SIMON Client');
            return;
        }

        if (empty($site_id)) {
            WP_CLI::error('SIMON Site ID not configured. Please create a site in Tools → SIMON Site');
            return;
        }

        WP_CLI::line('Submitting site data to SIMON...');
        $result = $this->submit_data();

        if ($result) {
            WP_CLI::success('Site data submitted successfully!');
        } else {
            WP_CLI::error('Failed to submit site data. Check the logs for details.');
        }
    }
}

/**
 * Register query vars for endpoints
 */
add_filter('query_vars', function($vars) {
    $vars[] = 'simon_endpoint';
    return $vars;
});

/**
 * Flush rewrite rules on activation
 */
register_activation_hook(__FILE__, function() {
    $instance = Simon_Integration::get_instance();
    $instance->add_rewrite_rules();
    flush_rewrite_rules();
});

/**
 * Register query vars for endpoints
 */
add_filter('query_vars', function($vars) {
    $vars[] = 'simon_endpoint';
    return $vars;
});

/**
 * Flush rewrite rules on activation
 */
register_activation_hook(__FILE__, function() {
    $instance = Simon_Integration::get_instance();
    $instance->add_rewrite_rules();
    flush_rewrite_rules();
});

// Initialize plugin
Simon_Integration::get_instance();

