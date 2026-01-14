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

// Flush rewrite rules on activation
register_activation_hook(__FILE__, function() {
    $simon = Simon_Integration::get_instance();
    $simon->register_rewrite_rules();
    flush_rewrite_rules();
});

// Flush rewrite rules on deactivation
register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});

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
        
        // Register URL endpoints (check very early before WordPress tries to find pages)
        add_action('plugins_loaded', [$this, 'check_url_endpoints'], 1);
        add_action('init', [$this, 'register_rewrite_rules']);
        add_action('init', [$this, 'flush_rewrite_rules_once'], 999);
        add_action('template_redirect', [$this, 'handle_url_endpoints']);
        
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
        register_setting('simon_settings', 'simon_client_slack_webhook');
        register_setting('simon_settings', 'simon_site_id');
        register_setting('simon_settings', 'simon_site_name');
        register_setting('simon_settings', 'simon_site_url');
        register_setting('simon_settings', 'simon_external_id');
        register_setting('simon_settings', 'simon_auth_token');
        register_setting('simon_settings', 'simon_client_auth_key');
        register_setting('simon_settings', 'simon_slack_webhook');
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

        if (isset($_POST['save_client'])) {
            check_admin_referer('simon_client');
            $this->save_client_data();
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
            delete_option('simon_client_slack_webhook');
            echo '<div class="notice notice-success"><p>Client ID cleared!</p></div>';
        }

        $client_name = get_option('simon_client_name', '');
        $contact_name = get_option('simon_contact_name', '');
        $contact_email = get_option('simon_contact_email', '');
        $slack_webhook = get_option('simon_client_slack_webhook', '');
        
        // Get API response from last submission (stored in transient)
        $last_response = get_transient('simon_client_last_response');
        if ($last_response !== false) {
            delete_transient('simon_client_last_response'); // Delete after displaying
        }
        ?>
        <div class="wrap">
            <h1>SIMON Client Configuration</h1>
            <?php if ($client_id): ?>
                <div class="notice notice-info">
                    <p><strong>Current Client ID: <?php echo esc_html($client_id); ?></strong></p>
                    <p class="description">The Client Auth Key is displayed in the form below once received from SIMON.</p>
                </div>
            <?php endif; ?>
            
            <?php if ($last_response): ?>
                <!-- API Response Display -->
                <div class="card" style="max-width: 800px; margin-bottom: 20px;">
                    <h2>API Response</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Status Code</th>
                            <td>
                                <?php 
                                $status_code = $last_response['status_code'] ?? 'N/A';
                                $status_class = ($status_code >= 200 && $status_code < 300) ? 'notice-success' : 'notice-error';
                                ?>
                                <span class="notice <?php echo esc_attr($status_class); ?>" style="display: inline-block; padding: 5px 10px;">
                                    <strong><?php echo esc_html($status_code); ?></strong>
                                </span>
                            </td>
                        </tr>
                        <?php if (!empty($last_response['headers'])): ?>
                        <tr>
                            <th scope="row">Response Headers</th>
                            <td>
                                <details style="margin-top: 10px;">
                                    <summary style="cursor: pointer; font-weight: 600; color: #0073aa; margin-bottom: 10px;">Click to view headers</summary>
                                    <pre style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; border-radius: 3px; overflow-x: auto; max-height: 300px; overflow-y: auto;"><code><?php echo esc_html(json_encode($last_response['headers'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></code></pre>
                                </details>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th scope="row">Response Body</th>
                            <td>
                                <details style="margin-top: 10px;" open>
                                    <summary style="cursor: pointer; font-weight: 600; color: #0073aa; margin-bottom: 10px;">Click to view response</summary>
                                    <pre style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; border-radius: 3px; overflow-x: auto; max-height: 400px; overflow-y: auto;"><code><?php echo esc_html($last_response['body_formatted']); ?></code></pre>
                                </details>
                            </td>
                        </tr>
                        <?php if (!empty($last_response['message'])): ?>
                        <tr>
                            <th scope="row">Message</th>
                            <td>
                                <div class="notice <?php echo esc_attr($last_response['message_type'] ?? 'notice-info'); ?>">
                                    <p><?php echo esc_html($last_response['message']); ?></p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
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
                    <tr>
                        <th scope="row">
                            <label for="slack_webhook">Slack Webhook URL</label>
                        </th>
                        <td>
                            <input type="url" id="slack_webhook" name="slack_webhook" 
                                   value="<?php echo esc_attr($slack_webhook); ?>" 
                                   class="regular-text">
                            <p class="description">Optional: Slack webhook URL to receive all notifications for this client. All notifications for this client's sites will be sent to this webhook.</p>
                        </td>
                    </tr>
                    <?php if ($client_id): ?>
                    <tr>
                        <th scope="row">
                            <label for="client_id">Client ID</label>
                        </th>
                        <td>
                            <input type="text" id="client_id" name="client_id" 
                                   value="<?php echo esc_attr($client_id); ?>" 
                                   class="regular-text" readonly style="background-color: #f5f5f5; cursor: not-allowed;">
                            <p class="description">This ID is received from SIMON after client creation/update and cannot be edited. It is automatically included in all subsequent API calls.</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
                <?php submit_button('Save Data', 'secondary', 'save_client', false); ?>
                <?php submit_button('Create/Update Client', 'primary', 'create_client', false); ?>
                <?php if ($client_id): ?>
                    <?php submit_button('Clear Client ID', 'delete', 'clear_client', false); ?>
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
        $slack_webhook = get_option('simon_slack_webhook', '');
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
                            <label for="slack_webhook">Slack Webhook URL</label>
                        </th>
                        <td>
                            <input type="url" id="slack_webhook" name="slack_webhook" 
                                   value="<?php echo esc_attr($slack_webhook); ?>" 
                                   class="regular-text">
                            <p class="description">Optional: Slack webhook URL to receive site-specific notifications</p>
                        </td>
                    </tr>
                    <?php if ($site_id): ?>
                    <tr>
                        <th scope="row">
                            <label for="site_id">Site ID</label>
                        </th>
                        <td>
                            <input type="text" id="site_id" name="site_id" 
                                   value="<?php echo esc_attr($site_id); ?>" 
                                   class="regular-text" readonly style="background-color: #f5f5f5; cursor: not-allowed;">
                            <p class="description">This ID is received from SIMON after site creation/update and cannot be edited. It is automatically included in all API submissions.</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
                <p class="submit">
                    <?php submit_button('Create/Update Site', 'primary', 'create_site', false); ?>
                    <?php if ($site_id): ?>
                        <?php submit_button('Clear Site ID', 'delete', 'clear_site', false); ?>
                        <?php submit_button('Submit Data Now', 'primary', 'submit_now', false); ?>
                    <?php endif; ?>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Save client data locally without submitting to API
     */
    private function save_client_data() {
        if (!isset($_POST['client_name']) || empty($_POST['client_name'])) {
            echo '<div class="notice notice-error"><p>Client Name is required.</p></div>';
            return;
        }

        update_option('simon_client_name', sanitize_text_field($_POST['client_name']));
        
        if (isset($_POST['contact_name'])) {
            update_option('simon_contact_name', sanitize_text_field($_POST['contact_name']));
        }
        
        if (isset($_POST['contact_email'])) {
            update_option('simon_contact_email', sanitize_email($_POST['contact_email']));
        }
        
        if (isset($_POST['slack_webhook'])) {
            update_option('simon_client_slack_webhook', esc_url_raw($_POST['slack_webhook']));
        }

        echo '<div class="notice notice-success"><p>Client data saved successfully! You can now submit it to the API when ready.</p></div>';
    }

    /**
     * Create client
     */
    private function create_client() {
        $auth_key = get_option('simon_auth_key', '');
        $api_url = get_option('simon_api_url', '');
        
        // Use POST data if available, otherwise fall back to saved options
        $client_data = [
            'name' => !empty($_POST['client_name']) 
                ? sanitize_text_field($_POST['client_name']) 
                : get_option('simon_client_name', ''),
            'contact_name' => !empty($_POST['contact_name']) 
                ? sanitize_text_field($_POST['contact_name']) 
                : get_option('simon_contact_name', ''),
            'contact_email' => !empty($_POST['contact_email']) 
                ? sanitize_email($_POST['contact_email']) 
                : get_option('simon_contact_email', ''),
            'slack_webhook' => !empty($_POST['slack_webhook']) 
                ? esc_url_raw($_POST['slack_webhook']) 
                : get_option('simon_client_slack_webhook', ''),
            'auth_key' => $auth_key, // Add SIMON plugin auth_key to payload
        ];
        
        // Validate required field
        if (empty($client_data['name'])) {
            echo '<div class="notice notice-error"><p>Client Name is required. Please enter it in the form or save it first.</p></div>';
            return;
        }
        
        // Validate auth_key
        if (empty($auth_key)) {
            echo '<div class="notice notice-error"><p>Auth Key is required. Please configure it in <a href="' . admin_url('options-general.php?page=simon-settings') . '">SIMON Settings</a>.</p></div>';
            return;
        }

        $response = wp_remote_post(rtrim($api_url, '/') . '/api/clients', [
            'body' => json_encode($client_data),
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Auth-Key' => $auth_key,
            ],
            'timeout' => 30,
        ]);

        // Prepare response data for display
        $response_data = [
            'status_code' => null,
            'headers' => [],
            'body' => null,
            'body_formatted' => '',
            'message' => '',
            'message_type' => 'notice-info',
        ];

        if (is_wp_error($response)) {
            $response_data['status_code'] = 'Error';
            $response_data['message'] = 'Error: ' . $response->get_error_message();
            $response_data['message_type'] = 'notice-error';
            $response_data['body_formatted'] = json_encode(['error' => $response->get_error_message()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            set_transient('simon_client_last_response', $response_data, 60); // Store for 60 seconds
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $response_headers = wp_remote_retrieve_headers($response);
        $body = wp_remote_retrieve_body($response);
        
        // Parse response body
        $body_decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $response_data['body'] = $body_decoded;
            $response_data['body_formatted'] = json_encode($body_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } else {
            $response_data['body'] = $body;
            $response_data['body_formatted'] = esc_html($body);
        }
        
        $response_data['status_code'] = $code;
        $response_data['headers'] = $response_headers->getAll();

        // Treat 200-299 as success (200 = OK, 201 = Created, 409 = Conflict/Already exists)
        if ($code >= 200 && $code < 300) {
            // Extract client_id from response (check all possible field names)
            $client_id = $body_decoded['client_id'] ?? $body_decoded['id'] ?? $body_decoded['clientId'] ?? null;
            $client_auth_key = $body_decoded['auth_key'] ?? $body_decoded['authKey'] ?? null;

            if ($client_id) {
                // Store client_id - this will be used in all subsequent API calls (site creation, data submission)
                update_option('simon_client_id', $client_id);
                update_option('simon_client_name', $client_data['name']);
                update_option('simon_contact_name', $client_data['contact_name']);
                update_option('simon_contact_email', $client_data['contact_email']);
                if ($client_auth_key) {
                    update_option('simon_client_auth_key', $client_auth_key);
                }
                $response_data['message'] = 'Client created/updated successfully! Client ID: ' . esc_html($client_id) . ' has been saved and will be included in all subsequent API calls.';
                if ($client_auth_key) {
                    $response_data['message'] .= ' Client Auth Key has been saved.';
                }
                $response_data['message_type'] = 'notice-success';
            } else {
                $response_data['message'] = 'Response received but client_id not found in response. Checked fields: client_id, id, clientId. Response body saved for review.';
                $response_data['message_type'] = 'notice-warning';
            }
        } else {
            $error_msg = 'Failed to create/update client. Status: ' . esc_html($code);
            if (isset($body_decoded['message'])) {
                $error_msg .= ' - ' . esc_html($body_decoded['message']);
            } elseif (isset($body_decoded['error'])) {
                $error_msg .= ' - ' . esc_html($body_decoded['error']);
            }
            $response_data['message'] = $error_msg;
            $response_data['message_type'] = 'notice-error';
        }
        
        // Store response for display (will be shown immediately)
        set_transient('simon_client_last_response', $response_data, 60); // Store for 60 seconds
    }

    /**
     * Create site
     */
    private function create_site() {
        $auth_key = get_option('simon_auth_key', '');
        $api_url = get_option('simon_api_url', '');
        
        // Get client_id from stored options (set after client creation/update)
        $client_id = get_option('simon_client_id', '');
        
        if (empty($client_id)) {
            echo '<div class="notice notice-error"><p>Client ID is required. Please create/update a client first in <a href="' . admin_url('tools.php?page=simon-client') . '">SIMON Client</a> configuration.</p></div>';
            return;
        }
        
        $site_data = [
            'client_id' => (int) $client_id,
            'cms' => 'wordpress',
            'name' => sanitize_text_field($_POST['site_name']),
            'url' => esc_url_raw($_POST['site_url']),
            'auth_key' => $auth_key, // Add SIMON plugin auth_key to payload
            'slack_webhook' => !empty($_POST['slack_webhook']) ? esc_url_raw($_POST['slack_webhook']) : '',
        ];
        
        // Validate auth_key
        if (empty($auth_key)) {
            echo '<div class="notice notice-error"><p>Auth Key is required. Please configure it in <a href="' . admin_url('options-general.php?page=simon-settings') . '">SIMON Settings</a>.</p></div>';
            return;
        }

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
                if (!empty($site_data['slack_webhook'])) {
                    update_option('simon_slack_webhook', $site_data['slack_webhook']);
                }
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

        // System metrics (new in v2.0.0.0)
        $data['system_metrics'] = $this->get_system_metrics();

        // Packages (new in v2.0.0.0)
        $data['packages'] = $this->get_packages();

        // Processes (new in v2.0.0.0)
        $data['processes'] = $this->get_processes();

        // Configuration (new in v2.0.0.0)
        $data['configuration'] = $this->get_configuration();

        return $data;
    }

    /**
     * Get core status
     */
    private function get_core_status($version) {
        $update_core = get_site_transient('update_core');
        
        // If no update data available, assume up-to-date
        if (!$update_core || !isset($update_core->updates) || empty($update_core->updates)) {
            return 'up-to-date';
        }
        
        // Check updates to determine if a newer version is available
        foreach ($update_core->updates as $update) {
            if (!isset($update->response)) {
                continue;
            }
            
            // If response is 'latest', the current version is up-to-date
            if ($update->response === 'latest') {
                return 'up-to-date';
            }
            
            // If response is 'upgrade', there's a newer version available
            if ($update->response === 'upgrade') {
                // Double-check by comparing versions if available
                if (isset($update->current) && version_compare($update->current, $version, '>')) {
                    return 'outdated';
                }
                // If no version info but response is 'upgrade', mark as outdated
                return 'outdated';
            }
        }
        
        // Default to up-to-date if we can't determine otherwise
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
     * Get system metrics (new in v2.0.0.0)
     */
    private function get_system_metrics() {
        $metrics = [];

        // Disk metrics
        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['basedir'];
        $disk_total = disk_total_space($upload_path);
        $disk_free = disk_free_space($upload_path);
        
        if ($disk_total !== false && $disk_free !== false) {
            $disk_used = $disk_total - $disk_free;
            $disk_usage_percent = ($disk_total > 0) ? (($disk_used / $disk_total) * 100) : 0;
            
            $metrics['disk'] = [
                'total_gb' => round($disk_total / (1024 * 1024 * 1024), 2),
                'used_gb' => round($disk_used / (1024 * 1024 * 1024), 2),
                'available_gb' => round($disk_free / (1024 * 1024 * 1024), 2),
                'usage_percent' => round($disk_usage_percent, 2),
            ];
        }

        // Memory metrics (PHP process)
        $memory_usage = memory_get_usage(true);
        $memory_peak = memory_get_peak_usage(true);
        $memory_limit = ini_get('memory_limit');
        $memory_limit_bytes = $this->convert_to_bytes($memory_limit);
        
        if ($memory_limit_bytes > 0) {
            $memory_usage_percent = ($memory_usage / $memory_limit_bytes) * 100;
            
            $metrics['memory'] = [
                'used_mb' => round($memory_usage / (1024 * 1024), 2),
                'peak_mb' => round($memory_peak / (1024 * 1024), 2),
                'limit_mb' => round($memory_limit_bytes / (1024 * 1024), 2),
                'usage_percent' => round($memory_usage_percent, 2),
            ];
        }

        // CPU metrics (load average)
        if (function_exists('sys_getloadavg')) {
            $load_avg = sys_getloadavg();
            if ($load_avg !== false) {
                $metrics['cpu'] = [
                    'load_average' => [
                        round($load_avg[0], 2), // 1 minute
                        round($load_avg[1], 2), // 5 minutes
                        round($load_avg[2], 2), // 15 minutes
                    ],
                ];
            }
        }

        // Try to get CPU cores
        if (function_exists('exec')) {
            $cores = @exec('nproc 2>/dev/null');
            if ($cores && is_numeric($cores)) {
                if (!isset($metrics['cpu'])) {
                    $metrics['cpu'] = [];
                }
                $metrics['cpu']['cores'] = (int) $cores;
            }
        }

        return !empty($metrics) ? $metrics : null;
    }

    /**
     * Convert memory limit string to bytes
     */
    private function convert_to_bytes($value) {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }

    /**
     * Get packages (Composer packages if available) (new in v2.0.0.0)
     */
    private function get_packages() {
        $packages = [];
        
        // Check for composer.lock file
        $composer_lock = ABSPATH . 'composer.lock';
        if (file_exists($composer_lock)) {
            $composer_data = json_decode(file_get_contents($composer_lock), true);
            
            if (isset($composer_data['packages']) && is_array($composer_data['packages'])) {
                foreach ($composer_data['packages'] as $package) {
                    if (isset($package['name']) && isset($package['version'])) {
                        $packages[] = [
                            'manager' => 'composer',
                            'name' => $package['name'],
                            'version' => ltrim($package['version'], 'v'), // Remove 'v' prefix if present
                        ];
                    }
                }
            }
        }
        
        // Check for package.json (npm packages) if in WordPress root
        $package_json = ABSPATH . 'package.json';
        if (file_exists($package_json)) {
            $npm_data = json_decode(file_get_contents($package_json), true);
            
            if (isset($npm_data['dependencies']) && is_array($npm_data['dependencies'])) {
                foreach ($npm_data['dependencies'] as $name => $version) {
                    $packages[] = [
                        'manager' => 'npm',
                        'name' => $name,
                        'version' => ltrim($version, '^~'), // Remove version prefixes
                    ];
                }
            }
        }
        
        return !empty($packages) ? $packages : [];
    }

    /**
     * Get processes (PHP-related processes) (new in v2.0.0.0)
     */
    private function get_processes() {
        $processes = [];
        
        // Get current PHP process info
        $processes[] = [
            'name' => 'php',
            'pid' => getmypid(),
            'memory_mb' => round(memory_get_usage(true) / (1024 * 1024), 2),
        ];
        
        // Try to get PHP-FPM processes (if accessible)
        if (function_exists('exec')) {
            $php_processes = @exec('ps aux | grep -E "php-fpm|php-cgi" | grep -v grep 2>/dev/null', $output);
            if (!empty($output) && is_array($output)) {
                foreach ($output as $line) {
                    if (preg_match('/\s+(\d+)\s+/', $line, $matches)) {
                        $pid = (int) $matches[1];
                        if ($pid !== getmypid()) { // Don't duplicate current process
                            $processes[] = [
                                'name' => 'php-fpm',
                                'pid' => $pid,
                                'status' => 'running',
                            ];
                        }
                    }
                }
            }
        }
        
        return !empty($processes) ? $processes : [];
    }

    /**
     * Get configuration (environment variables and config file metadata) (new in v2.0.0.0)
     */
    private function get_configuration() {
        $config = [];
        
        // Environment variables (non-sensitive PHP settings)
        $env_vars = [];
        $safe_vars = ['PHP_VERSION', 'SERVER_SOFTWARE', 'DOCUMENT_ROOT', 'SCRIPT_FILENAME'];
        
        foreach ($safe_vars as $var) {
            $value = getenv($var);
            if ($value !== false) {
                $env_vars[$var] = $value;
            }
        }
        
        // Add some PHP ini settings (non-sensitive)
        $env_vars['PHP_MEMORY_LIMIT'] = ini_get('memory_limit');
        $env_vars['PHP_MAX_EXECUTION_TIME'] = ini_get('max_execution_time');
        $env_vars['PHP_UPLOAD_MAX_FILESIZE'] = ini_get('upload_max_filesize');
        
        if (!empty($env_vars)) {
            $config['environment_variables'] = $env_vars;
        }
        
        // Config file metadata
        $config_files = [];
        
        // wp-config.php metadata
        $wp_config = ABSPATH . 'wp-config.php';
        if (file_exists($wp_config)) {
            $config_files[] = [
                'path' => 'wp-config.php',
                'checksum' => md5_file($wp_config),
                'last_modified' => date('c', filemtime($wp_config)),
                'is_sensitive' => true, // wp-config.php contains sensitive data
            ];
        }
        
        // .htaccess metadata (if exists)
        $htaccess = ABSPATH . '.htaccess';
        if (file_exists($htaccess)) {
            $config_files[] = [
                'path' => '.htaccess',
                'checksum' => md5_file($htaccess),
                'last_modified' => date('c', filemtime($htaccess)),
                'is_sensitive' => false,
            ];
        }
        
        if (!empty($config_files)) {
            $config['config_files'] = $config_files;
        }
        
        return !empty($config) ? $config : null;
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
        
        // Get SIMON plugin auth_key
        $auth_key = get_option('simon_auth_key', '');
        
        // Get client auth_key from options (auth_key is now associated with client, not site)
        $client_auth_key = get_option('simon_client_auth_key', '');
        
        if (empty($client_auth_key)) {
            error_log('SIMON API error: client auth_key not configured. Please create/update the client to get a client auth_key.');
            return false;
        }
        
        $payload = [
            'client_id' => (int) $client_id,
            'site_id' => (int) $site_id,
            'core' => $data['core'],
            'log_summary' => $data['log_summary'],
            'environment' => $data['environment'],
            'extensions' => $data['extensions'],
            'themes' => $data['themes'],
            'auth_key' => $auth_key, // Add SIMON plugin auth_key to payload
        ];
        
        // Add new v2.0.0.0 fields if available
        if (isset($data['system_metrics']) && !empty($data['system_metrics'])) {
            $payload['system_metrics'] = $data['system_metrics'];
        }
        if (isset($data['packages']) && !empty($data['packages'])) {
            $payload['packages'] = $data['packages'];
        }
        if (isset($data['processes']) && !empty($data['processes'])) {
            $payload['processes'] = $data['processes'];
        }
        if (isset($data['configuration']) && !empty($data['configuration'])) {
            $payload['configuration'] = $data['configuration'];
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
     * Register rewrite rules for SIMON endpoints
     */
    public function register_rewrite_rules() {
        add_rewrite_rule('^simon/heartbeat/?$', 'index.php?simon_action=heartbeat', 'top');
        add_rewrite_rule('^simon/cache-clear/?$', 'index.php?simon_action=cache-clear', 'top');
        add_rewrite_rule('^simon/cron/?$', 'index.php?simon_action=cron', 'top');
        add_rewrite_tag('%simon_action%', '([^&]+)');
    }

    /**
     * Flush rewrite rules (call after registering rules)
     */
    public function flush_rewrite_rules_once() {
        if (get_option('simon_rewrite_rules_flushed') !== '1') {
            flush_rewrite_rules(false);
            update_option('simon_rewrite_rules_flushed', '1');
        }
    }

    /**
     * Check URL endpoints very early (before WordPress loads)
     */
    public function check_url_endpoints() {
        $this->check_and_handle_endpoint();
    }

    /**
     * Handle URL endpoints (fallback check)
     */
    public function handle_url_endpoints() {
        $action = get_query_var('simon_action');
        
        if ($action) {
            $this->process_endpoint($action);
            return;
        }
        
        // Fallback: Check REQUEST_URI directly
        $this->check_and_handle_endpoint();
    }

    /**
     * Check REQUEST_URI and handle endpoint if matched
     */
    private function check_and_handle_endpoint() {
        // Check if already processed to avoid double processing
        if (defined('SIMON_ENDPOINT_PROCESSED')) {
            return;
        }
        
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        
        if (empty($request_uri)) {
            return;
        }
        
        // Remove query string and home URL path for matching
        $parsed = parse_url($request_uri);
        $path = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
        
        // Also check raw REQUEST_URI for DDEV subdirectory setups
        if (empty($path)) {
            $path = strtok($request_uri, '?');
            $path = rtrim($path, '/');
        }
        
        // Debug: Log the path being checked (can remove later)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('SIMON Plugin: Checking path: ' . $path);
        }
        
        // Check if request is for SIMON endpoints (be flexible with path matching)
        $action = null;
        if (preg_match('#(^|/)simon/heartbeat/?$#i', $path)) {
            $action = 'heartbeat';
        } elseif (preg_match('#(^|/)simon/cache-clear/?$#i', $path)) {
            $action = 'cache-clear';
        } elseif (preg_match('#(^|/)simon/cron/?$#i', $path)) {
            $action = 'cron';
        }
        
        if ($action) {
            define('SIMON_ENDPOINT_PROCESSED', true);
            $this->process_endpoint($action);
        }
    }

    /**
     * Process the endpoint action
     */
    private function process_endpoint($action) {
        // Heartbeat endpoint doesn't require authentication
        if ($action === 'heartbeat') {
            $this->handle_heartbeat();
            exit;
        }

        // Get and validate parameters for other endpoints
        $client_id = isset($_GET['client_id']) ? sanitize_text_field($_GET['client_id']) : '';
        $site_id = isset($_GET['site_id']) ? sanitize_text_field($_GET['site_id']) : '';

        // Validate client_id and site_id match stored values
        $stored_client_id = get_option('simon_client_id', '');
        $stored_site_id = get_option('simon_site_id', '');

        if (empty($client_id) || empty($site_id)) {
            $this->send_json_response([
                'success' => false,
                'error' => 'Missing required parameters: client_id and site_id are required.',
            ], 400);
            exit;
        }

        if ($client_id !== $stored_client_id || $site_id !== $stored_site_id) {
            $this->send_json_response([
                'success' => false,
                'error' => 'Invalid client_id or site_id. Parameters do not match stored values.',
            ], 403);
            exit;
        }

        // Handle different actions
        switch ($action) {
            case 'cache-clear':
                $this->handle_cache_clear();
                break;
            case 'cron':
                $this->handle_cron_execution();
                break;
            default:
                $this->send_json_response([
                    'success' => false,
                    'error' => 'Unknown action.',
                ], 404);
                exit;
        }
        
        // Exit to prevent WordPress from loading a template
        exit;
    }

    /**
     * Handle heartbeat endpoint
     * Used for availability monitoring and early warning detection
     */
    private function handle_heartbeat() {
        $status = [
            'status' => 'healthy',
            'timestamp' => current_time('mysql'),
            'unix_timestamp' => time(),
            'checks' => [],
        ];
        
        $all_healthy = true;
        
        // Check 1: WordPress core functions
        $status['checks']['wordpress'] = function_exists('get_bloginfo') && function_exists('get_option');
        if (!$status['checks']['wordpress']) {
            $all_healthy = false;
        }
        
        // Check 2: Database connectivity
        global $wpdb;
        $db_check = false;
        if ($wpdb) {
            $db_result = $wpdb->get_var("SELECT 1");
            $db_check = ($db_result === '1');
        }
        $status['checks']['database'] = $db_check;
        if (!$db_check) {
            $all_healthy = false;
        }
        
        // Check 3: Object cache (if available)
        $cache_check = function_exists('wp_cache_get');
        $status['checks']['cache'] = $cache_check;
        // Cache is optional, so we don't fail if it's not available
        
        // Check 4: Filesystem access
        $filesystem_check = false;
        if (function_exists('WP_Filesystem')) {
            global $wp_filesystem;
            if (!$wp_filesystem) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }
            $filesystem_check = is_object($wp_filesystem);
        } else {
            // Fallback: check if uploads directory exists
            $upload_dir = wp_upload_dir();
            $filesystem_check = $upload_dir && !empty($upload_dir['basedir']);
        }
        $status['checks']['filesystem'] = $filesystem_check;
        if (!$filesystem_check) {
            $all_healthy = false;
        }
        
        // Check 5: Memory usage (warning if very high)
        $memory_usage = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
        $memory_limit = ini_get('memory_limit');
        $status['checks']['memory'] = [
            'usage' => $memory_usage,
            'limit' => $memory_limit,
            'healthy' => true, // We'll calculate this
        ];
        
        // Calculate memory percentage if possible
        if ($memory_limit && $memory_usage) {
            $limit_bytes = $this->convert_memory_to_bytes($memory_limit);
            if ($limit_bytes > 0) {
                $memory_percent = ($memory_usage / $limit_bytes) * 100;
                $status['checks']['memory']['percent'] = round($memory_percent, 2);
                // Mark as unhealthy if over 90% memory usage
                if ($memory_percent > 90) {
                    $status['checks']['memory']['healthy'] = false;
                    $all_healthy = false;
                }
            }
        }
        
        // Check 6: PHP version and critical extensions
        $status['checks']['php'] = [
            'version' => PHP_VERSION,
            'healthy' => version_compare(PHP_VERSION, '7.4', '>='),
        ];
        if (!$status['checks']['php']['healthy']) {
            $all_healthy = false;
        }
        
        // Determine overall status
        if (!$all_healthy) {
            $status['status'] = 'degraded';
        }
        
        // Add SIMON-specific info if configured
        $client_id = get_option('simon_client_id', '');
        $site_id = get_option('simon_site_id', '');
        if ($client_id && $site_id) {
            $status['simon'] = [
                'client_id' => $client_id,
                'site_id' => $site_id,
            ];
        }
        
        // Return appropriate HTTP status code
        $http_status = $all_healthy ? 200 : 503; // 503 Service Unavailable if degraded
        
        $this->send_json_response($status, $http_status);
    }
    
    /**
     * Convert memory limit string to bytes
     */
    private function convert_memory_to_bytes($memory) {
        $memory = trim($memory);
        $last = strtolower($memory[strlen($memory) - 1]);
        $value = (int) $memory;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }

    /**
     * Handle cache clear endpoint
     */
    private function handle_cache_clear() {
        // Clear WordPress object cache
        wp_cache_flush();

        // Clear all transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'");

        // Clear SIMON-specific transients if any
        delete_transient('simon_client_last_response');

        $this->send_json_response([
            'success' => true,
            'message' => 'Cache cleared successfully.',
            'timestamp' => current_time('mysql'),
        ]);
        exit;
    }

    /**
     * Handle cron execution endpoint
     */
    private function handle_cron_execution() {
        // Execute the submit_data function
        $result = $this->submit_data();

        if ($result) {
            $this->send_json_response([
                'success' => true,
                'message' => 'Cron executed successfully. Site data submitted to SIMON.',
                'timestamp' => current_time('mysql'),
            ]);
        } else {
            $this->send_json_response([
                'success' => false,
                'error' => 'Failed to execute cron. Check logs for details.',
                'timestamp' => current_time('mysql'),
            ], 500);
        }
        exit;
    }

    /**
     * Send JSON response
     */
    private function send_json_response($data, $status_code = 200) {
        status_header($status_code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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

// Initialize plugin
Simon_Integration::get_instance();

