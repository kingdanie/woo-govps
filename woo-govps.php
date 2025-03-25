<?php
/*
Plugin Name: GoVPS Provisioning Plugin
Description: This plugin sends API Requests to GoVPS to create VPS accounts from Completed WooComerce Orders and Sends the VPS Credentials to the Customer.
Version: 1.2.2
Author: Jorion Tech
Author URI: https://jorionng.com
Requires at least: 6.4
Requires PHP: 8.0
*/

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class GoVPSProvisioningPlugin
{
    private $table_name;
    private $settings_page_slug = 'govps-settings';
    private $option_group = 'govps_settings_group';
    private $option_name = 'govps_settings';

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'vps_servers';

        add_action('admin_init', [$this, 'check_dependencies']);
        add_action('admin_notices', [$this, 'show_dependency_notices']);

        add_action('woocommerce_subscription_status_active', [$this, 'handle_vps_active_subscriptions'], 10, 1);

        add_action('woocommerce_subscription_renewal_payment_complete', [$this, 'handle_vps_active_subscriptions'], 10, 1);
		

        // Activation hook for creating database table
        register_activation_hook(__FILE__, [$this, 'create_vps_table']);

        // Admin menu and page
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Settings initialization
        add_action('admin_init', [$this, 'initialize_settings']);

        // Admin notices for missing API keys
        add_action('admin_notices', [$this, 'display_api_key_notice']);
    }


    public function add_admin_menu()
    {
        // Add main menu item for VPS Servers
        add_menu_page(
            'VPS Servers',
            'VPS Servers',
            'manage_options',
            'vps-servers',
            [$this, 'render_admin_page'],
            'dashicons-admin-site-alt',
            30
        );

        // Add submenu for Settings
        add_submenu_page(
            'vps-servers',
            'GoVPS Settings',
            'Settings',
            'manage_options',
            $this->settings_page_slug,
            [$this, 'render_settings_page']
        );
    }

    public function initialize_settings()
    {
        // Register settings
        register_setting(
            $this->option_group,
            $this->option_name,
            [$this, 'sanitize_settings']
        );

        // Add settings section
        add_settings_section(
            'govps_api_settings',
            'API Configuration',
            [$this, 'settings_section_callback'],
            $this->settings_page_slug
        );

        // Add settings fields
        add_settings_field(
            'govps_test_api_key',
            'Test API Key',
            [$this, 'render_text_field'],
            $this->settings_page_slug,
            'govps_api_settings',
            [
                'id' => 'test_api_key',
                'type' => 'password'
            ]
        );

        add_settings_field(
            'govps_live_api_key',
            'Live API Key',
            [$this, 'render_text_field'],
            $this->settings_page_slug,
            'govps_api_settings',
            [
                'id' => 'live_api_key',
                'type' => 'password'
            ]
        );

        add_settings_field(
            'govps_test_mode',
            'Test Mode',
            [$this, 'render_checkbox_field'],
            $this->settings_page_slug,
            'govps_api_settings',
            [
                'id' => 'test_mode',
                'label' => 'Enable Test Mode'
            ]
        );
    }

    public function sanitize_settings($input)
    {
        $output = [];

        // Sanitize API keys
        $output['test_api_key'] = sanitize_text_field($input['test_api_key'] ?? '');
        $output['live_api_key'] = sanitize_text_field($input['live_api_key'] ?? '');

        // Sanitize test mode checkbox
        $output['test_mode'] = isset($input['test_mode']) ? 1 : 0;

        return $output;
    }

    public function settings_section_callback()
    {
        echo '<p>Configure your GoVPS API settings here. Make sure to input both test and live API keys.</p>';
    }

    public function render_text_field($args)
    {
        $settings = get_option($this->option_name);
        $value = $settings[$args['id']] ?? '';

        printf(
            '<input type="%s" id="%s" name="%s[%s]" value="%s" class="regular-text" />',
            esc_attr('text'),
            esc_attr($args['id']),
            esc_attr($this->option_name),
            esc_attr($args['id']),
            esc_attr($value)
        );
    }

    public function render_checkbox_field($args)
    {
        $settings = get_option($this->option_name);
        $checked = isset($settings[$args['id']]) && $settings[$args['id']] ? 'checked' : '';

        printf(
            '<input type="checkbox" id="%s" name="%s[%s]" %s /> <label for="%s">%s</label>',
            esc_attr($args['id']),
            esc_attr($this->option_name),
            esc_attr($args['id']),
            $checked,
            esc_attr($args['id']),
            esc_html($args['label'])
        );
    }


    public function render_settings_page()
    {
?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields($this->option_group);
                do_settings_sections($this->settings_page_slug);
                submit_button('Save Settings');
                ?>
            </form>
        </div>
    <?php
    }

    public function render_admin_page()
    {
        global $wpdb;
        $servers = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY paid_at DESC LIMIT 50");
    ?>
        <div class="wrap">
            <h1>VPS Servers</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Subscription ID</th>
                        <th>Product Type</th>
                        <th>VPS ID</th>
                        <th>IP Address</th>
                        <th>Username</th>
                        <th>Password</th>
                        <th>Port</th>
                        <th>Paid At</th>
                        <th>Paid To</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($servers as $server): ?>
                        <tr>
                            <td><?php echo $server->order_id; ?></td>
                            <td><?php echo $server->product_type; ?></td>
                            <td><?php echo $server->vps_id; ?></td>
                            <td><?php echo $server->ip_address; ?></td>
                            <td><?php echo $server->username; ?></td>
                            <td><?php echo $server->password; ?></td>
                            <td><?php echo $server->port; ?></td>
                            <td><?php echo $server->paid_at; ?></td>
                            <td><?php echo $server->paid_to; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function display_api_key_notice()
    {
        $settings = get_option($this->option_name);

        // Check if either API key is missing
        if (empty($settings['test_api_key']) || empty($settings['live_api_key'])) {
        ?>
            <div class="notice notice-warning">
                <p>
                    <strong>GoVPS Provisioning Plugin:</strong>
                    Please configure your API keys in the
                    <a href="<?php echo admin_url('admin.php?page=' . $this->settings_page_slug); ?>">GoVPS Settings</a>
                    to enable VPS provisioning.
                </p>
            </div>
<?php
        }
    }


    public function create_vps_table()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id mediumint(9) NOT NULL,
            product_type VARCHAR(50) NOT NULL,
            vps_id mediumint(9) NOT NULL,
            ip_address VARCHAR(50) NOT NULL,
            port mediumint(9) NOT NULL,
            username VARCHAR(100) NOT NULL,
            password VARCHAR(100) NOT NULL,
            paid_at DATETIME NOT NULL,
            paid_to DATETIME NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    private function send_vps_creation_request($tariff, $months)
    {
        $settings = get_option($this->option_name);

        // Determine API URL and key based on mode
        $api_url = $settings['test_mode']
            ? 'https://api-test.govpsfx.com/api/get'
            : 'https://autodeploy.govpsfx.com/api/get';

        $api_key = $settings['test_mode']
            ? $settings['test_api_key']
            : $settings['live_api_key'];

        // Validate API key
        if (empty($api_key)) {
            error_log('GoVPS API Key is missing');
            return false;
        }

        $body = [
            'tariff' => $tariff,
            'type' => 'Virtual',
            'language' => 'ENG',
            'month' => $months
        ];

        $response = wp_remote_post($api_url, [
            'body' => $body,
            'headers' => [
                'Secret' => $api_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('VPS API Request Error: ' . $response->get_error_message());
            $this->log_request($response->get_error_message(), false);
            return false;
        }

        $this->log_request($response);
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    private function renew_vps_request($vpsId, $months)
    {
        $body = [
            'id' => $vpsId,
            'month' => $months
        ];


        $settings = get_option($this->option_name);

        // Determine API URL and key based on mode
        $api_url = $settings['test_mode']
            ? 'https://api-test.govpsfx.com/api/vm/resume'
            : 'https://autodeploy.govpsfx.com/api/vm/resume';

        $api_key = $settings['test_mode']
            ? $settings['test_api_key']
            : $settings['live_api_key'];

        // Validate API key
        if (empty($api_key)) {
            error_log('GoVPS API Key is missing');
            return false;
        }


        $response = wp_remote_post($api_url, [
            'body' => $body,
            'headers' => [
                'Secret' => $api_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('VPS API Request Error: ' . $response->get_error_message());
            $this->log_request($response->get_error_message(), false);
            return false;
        }

        $this->log_request($response);
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    public function vps_exists($subscription_id)
    {
        global $wpdb;

        $vps_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT vps_id FROM {$this->table_name} WHERE order_id = %d",
                $subscription_id
            )
        );

        return !empty($vps_id);
    }

    public function renew_vps_subscription($subscription)
    {
        global $wpdb;

        // Get the order ID
        $order_id = $subscription->id; //change this back to parent id when you are done testing
        if (!$order_id) {
            error_log('No order found for subscription');
            return;
        }

        // Query the VPS details from custom table
        $vps_details = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT vps_id FROM {$this->table_name} WHERE order_id = %d",
                $order_id
            )
        );

        if (!$vps_details) {
            error_log('No VPS details found for order');
            return;
        }

        // Get order duration (months)
        $order_duration = strtolower($subscription->get_billing_period()) === 'year'
            ? 12
            : $subscription->get_billing_interval();

        // Send renewal request
        $api_response = $this->renew_vps_request($vps_details->vps_id, $order_duration);

        if (!empty($api_response) && !($api_response['status'])) {
            // Update the paid_to date in the database
            $wpdb->update(
                $this->table_name,
                [
                    'paid_to' => date('Y-m-d H:i:s', strtotime($api_response['data']['paid_to']))
                ],
                ['order_id' => $order_id]
            );


            $this->send_vps_renewal_email($subscription, [
                'paid_to' => $api_response['data']['paid_to']
            ]);
        } else {
            // Send failure email to admin
            $vps_id = $vps_details->vps_id ?? 'N/A';
            $reason = isset($api_response['error']) ? $api_response['error'] : (isset($api_response['message']) ? $api_response['message'] : 'Unknown error');
            error_log('Failed to provision VPS for order: ' . $order_id . ' - Reason: ' . $reason);
            $this->send_admin_failure_email($vps_id, $order_id, $reason);
        }
    }





    /**
     * Handle API Requests for new and old subscriptions
     * this is 100% working
     *
     * @param  $subscription
     * @return void
     */
    public function handle_vps_active_subscriptions($subscription)
    {

        $vsp_id = $this->vps_exists($subscription->id);

        if ($vsp_id) {
            $this->renew_vps_subscription($subscription);
        } else {
            $this->provision_vps_for_order($subscription);
        }
    }

    public function provision_vps_for_order($subscription)
    {
        $items = $subscription->get_items();
        $item = reset($items);
        if (!$item) {
            error_log('No items found in subscription');
            return;
        }

        $product = $item->get_product();
        if (!$product) {
            error_log('Could not get product from subscription item');
            return;
        }

        // Get the product slug and extract base plan
        $product_slug = $product->get_slug();

        // Extract the base plan name using regex
        // This will match 'classic' from 'classic_2' or 'professional' from 'professional_'
        if (preg_match('/(basic|standard|classic|professional)/', $product_slug, $matches)) {
            $base_plan = $matches[1];
        } else {
            error_log('Could not extract base plan from slug: ' . $product_slug);
            return;
        }


        // Map product to VPS tariff
        $tariff_map = [
            'basic' => 'Start',
            'standard' => 'Expert',
            'classic' => 'Classic',
            'professional' => 'Greate'
        ];

        $tariff = $tariff_map[$base_plan] ?? null;
        if (!$tariff) {
            error_log('No tariff found for base plan: ' . $base_plan);
            return;
        }

        // Get order duration (months)
        $order_duration = strtolower($subscription->get_billing_period()) === 'year' ? 12 : $subscription->get_billing_interval();

        // Send API request
        $api_response = $this->send_vps_creation_request($tariff, $order_duration);


        $order_id = $subscription->id;

        if (!empty($api_response) && !($api_response['status'])) {
            // Save VPS details to database
            $this->save_vps_details($order_id, $tariff, $api_response['data']);

            // Send credentials email
            $this->send_vps_credentials_email($subscription, $api_response['data']);
        } else {

            // Send failure email to admin
            $vps_id = $api_response['data']['vps_id'] ?? 'N/A';
            $reason = isset($api_response['error']) ? $api_response['error'] : (isset($api_response['message']) ? $api_response['message'] : 'Unknown error');
            error_log('Failed to provision VPS for order: ' . $order_id . ' - Reason: ' . $reason);
            $this->send_admin_failure_email($vps_id, $order_id, $reason);
        }
    }

    private function save_vps_details($order_id, $product_type, $vps_data)
    {
        global $wpdb;

        $wpdb->insert(
            $this->table_name,
            [
                'order_id' => $order_id,
                'product_type' => $product_type,
                'vps_id' => $vps_data['id'],
                'ip_address' => $vps_data['ip'],
                'port' => $vps_data['port'],
                'username' => $vps_data['username'],
                'password' => $vps_data['password'],
                'paid_at' => date('Y-m-d H:i:s', strtotime($vps_data['paid_at'])),
                'paid_to' => date('Y-m-d H:i:s', strtotime($vps_data['paid_to']))
            ]
        );
    }

    private function send_vps_credentials_email($order, $vps_data)
    {

        // Calculate duration from paid_at to paid_to
        $start_date = new DateTime($vps_data['paid_at']);
        $end_date = new DateTime($vps_data['paid_to']);
        $interval = $start_date->diff($end_date);
        $duration = $interval->m + ($interval->y * 12);

        // Add filters for sender email and name
        add_filter('wp_mail_from_name', function ($original_name) {
            return 'SurgeVps';
        });

        $to = $order->get_billing_email();
        $subject = '🎉 Your Order is Confirmed! Let the Trading Begin!';
        $message = '
        <p>Hey ' . $order->get_billing_first_name() . ',</p>

        <p>Awesome news! Your order with SurgeVps has been confirmed, and we\'re ready to help you take your Forex trading to the next level! 🚀.</p>

        <p><strong>Your new VPS login credentials:</strong></p>

        <ul>
            <li><strong>IP Address:</strong> ' . $vps_data['ip'] . ':' . $vps_data['port'] . '</li>
            <li><strong>Username:</strong> ' . $vps_data['username'] . '</li>
            <li><strong>Password:</strong> ' . $vps_data['password'] . '</li>
            <li><strong>Duration:</strong> ' . $duration . ' ' . ($duration == 1 ? 'month' : 'months') . '</li>
            <li><strong>Start Date:</strong> ' . date('Y-m-d', strtotime($vps_data['paid_at'])) . '</li>
            <li><strong>End Date:</strong> ' . date('Y-m-d', strtotime($vps_data['paid_to'])) . '</li>
        </ul>

        <p>Your Forex VPS is now all setup and ready for action! <br /> We\'re excited to see you dive in and start trading like a pro.</p>

        <p><strong>What\'s Next?</strong></p>

        <p>Keep an eye on your inbox—we\'ll send you more exciting deets that you\'ll love. <br /> If you have any questions or need support, our team is just a click away! </p>  

        <p><strong>Contact Us</strong></p>
        <p>https://surgevps.com/contact/</p>

        <p>Thank you for choosing SurgeVps! We\'re thrilled to have you on board and can\'t wait to see what you achieve.</p>  

        <p>Happy trading!</p>
        <p>The SurgeVps Team 🌟</p>

    ';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );

        wp_mail($to, $subject, $message, $headers);

        // Remove the filters after sending the email
        remove_filter('wp_mail_from_name', function ($original_name) {
            return 'SurgeVps';
        });
    }

    private function send_vps_renewal_email($order, $renewal_data)
    {

        // Calculate duration in months
        $duration = strtolower($order->get_billing_period()) === 'year'
            ? 12
            : $order->get_billing_interval();

        add_filter('wp_mail_from_name', function ($original_name) {
            return 'SurgeVps';
        });

        $to = $order->get_billing_email();
        $subject = '🎉 Your VPS Has Been Successfully Renewed!';
        $message = '<p>Hey ' . $order->get_billing_first_name() . ',</p>
        <p>Great news! Your VPS has been successfully renewed.</p>
        <p><strong>Renewal Details:</strong></p>
        <ul>
            <li><strong>Duration:</strong> ' . $duration . ' ' . ($duration == 1 ? 'month' : 'months') . '</li>
            <li><strong>New Expiry Date:</strong> ' . $renewal_data['paid_to'] . '</li>
        </ul>
        <p>Your VPS will continue to operate without any interruption.</p>
        <p>If you have any questions or need support, our team is here to help!</p>
        <p><strong>Contact Us</strong></p>
        <p>https://surgevps.com/contact/</p>
        <p>Thank you for continuing to choose SurgeVps!</p>
        <p>Best regards,<br>The SurgeVps Team 🌟</p>';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );

        wp_mail($to, $subject, $message, $headers);

        // Remove the filterS
        remove_filter('wp_mail_from_name', function ($original_name) {
            return 'SurgeVps';
        });
    }

    private function send_admin_failure_email($vps_id, $order_id, $reason)
    {
        $admin_email = get_option('admin_email');
        $subject = "VPS Provisioning Failed for Subscription #$order_id";
        $message = "
        <p>Hello Admin,</p>
        <p>The VPS provisioning for order <strong>#$order_id</strong> has failed.</p>
        <p><strong>VPS Ip:</strong> {$vps_id}</p>
        <p><strong>Subscription ID:</strong> {$order_id}</p>
        <p><strong>Failure Reason:</strong> {$reason}</p>
        <p>Please check the logs and resolve the issue.</p>
        <p>Regards,<br>Surge VPS Provisioning Plugin</p>
    ";

        // Set headers for HTML email
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . $admin_email . '>'
        ];

        // Send the email
        wp_mail($admin_email, $subject, $message, $headers);
    }


    private function log_request($request)
    {
        // Get the plugin's log file path
        $log_file = plugin_dir_path(__FILE__) . 'go-vps--' . date('Y-m-d') . '.log';

        // $request_data = json_decode($request, true);
        // Write the request to the log file
        $log_message = "Govps API Response: " . print_r($request, true) . "\n";
        file_put_contents($log_file, $log_message, FILE_APPEND);
    }

    public function check_dependencies()
    {
        if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            if (is_plugin_active(plugin_basename(__FILE__))) {
                deactivate_plugins(plugin_basename(__FILE__));
                // Set transient to show notice
                set_transient('surgevps_deactivated', true);
            }
            return false;
        }

        if (!class_exists('WC_Subscriptions')) {
            if (is_plugin_active(plugin_basename(__FILE__))) {
                deactivate_plugins(plugin_basename(__FILE__));
                // Set transient to show notice
                set_transient('surgevps_deactivated', true);
            }
            return false;
        }

        return true;
    }

    public function show_dependency_notices()
    {
        // Check if our plugin was just deactivated
        if (get_transient('surgevps_deactivated')) {
            delete_transient('surgevps_deactivated');

            $message = '<div class="error"><p>';
            $message .= '<strong>SurgeVPS has been deactivated</strong>. ';

            if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
                $message .= 'This plugin requires WooCommerce to be installed and activated. ';
            }

            if (!class_exists('WC_Subscriptions')) {
                $message .= 'This plugin requires WooCommerce Subscriptions to be installed and activated. ';
            }

            $message .= 'Please install and activate the required plugins.</p></div>';

            echo $message;
        }
    }
}

// Initialize the plugin
function surgevps_init()
{
    // Initialize the plugin
    new GoVPSProvisioningPlugin();
}

add_action('plugins_loaded', 'surgevps_init');
