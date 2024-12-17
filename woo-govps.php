<?php
/*
Plugin Name: GoVPS Provisioning Plugin
Description: This plugin sends API Requests to GoVPS to create VPS accounts from Completed WooComerce Orders and Sends the VPS Credentials to the Customer.
Version: 1.1
Author: Jorion Tech
Author URI: https://jorionng.com
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

        // Hook for subscription orders
        add_action('woocommerce_subscription_payment_complete', [$this, 'provision_vps_for_subscription'], 10, 1);

        // Optional: Hook for renewal orders
        add_action('woocommerce_subscription_renewal_payment_complete', [$this, 'provision_vps_for_subscription'], 10, 1);

        // Activation hook for creating database table
        register_activation_hook(__FILE__, [$this, 'create_vps_table']);

        // Admin menu and page
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Settings initialization
        add_action('admin_init', [$this, 'initialize_settings']);

        // Admin notices for missing API keys
        add_action('admin_notices', [$this, 'display_api_key_notice']);
    }

    // ... [Previous methods remain the same] ...

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
        $servers = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY paid_at DESC");
?>
        <div class="wrap">
            <h1>VPS Servers</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Product Type</th>
                        <th>VPS ID</th>
                        <th>IP Address</th>
                        <th>Username</th>
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
                // 'Secret' => 'Testsurgevps:t8E649y59f95mi95cFVm',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('VPS API Request Error: ' . $response->get_error_message());
            $this->log_webhook_request($response->get_error_message(), false);
            return false;
        }
        
        $this->log_webhook_request($response);
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    public function provision_vps_for_subscription($subscription)
    {
        // $this->log_webhook_request($subscription);
        $order_id = $subscription->get_last_order();
        $this->provision_vps_for_order($order_id);
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

    public function provision_vps_for_order($order_id)
    {
        $order = wc_get_order($order_id);

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $product_id = $product->get_id();

            // Get product name
            $product_name = $product->get_name();

            // Map product to VPS tariff
            $tariff_map = [
                'basic_product' => 'Start',
                'standard_product' => 'Expert',
                'classic_product' => 'Classic',
                'professional_product' => 'Greate'
            ];

            $product_slug = $product->get_slug();
            $tariff = $tariff_map[$product_slug] ?? null;

            if (!$tariff) continue;

            // Get order duration (months)
            $order_duration = $this->get_order_duration($order);

            // Send API request
            $api_response = $this->send_vps_creation_request($tariff, $order_duration);

            if ($api_response && $api_response['status']) {
                // Save VPS details to database
                $this->save_vps_details($order_id, $tariff, $api_response['data']);

                // Send credentials email
                $this->send_vps_credentials_email($order, $api_response['data']);
            }
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
        $to = $order->get_billing_email();
        $subject = '🎉 Your Order is Confirmed! Let the Trading Begin!';
        $message = '
        <p>Hey ' . $order->get_billing_first_name() . ',</p>

        <p>Awesome news! Your order with SurgeVps has been confirmed, and we\'re ready to help you take your Forex trading to the next level! 🚀.</p>

        <p><strong>Your new VPS login credentials:</strong></p>

        <ul>
            <li><strong>IP Address:</strong> ' . $vps_data['ip'] . ':' . $vps_data['port'] .'</li>
            <li><strong>Username:</strong> ' . $vps_data['username'] . '</li>
            <li><strong>Password:</strong> ' . $vps_data['password'] . '</li>
            <li><strong>Start Date:</strong> ' . $vps_data['paid_to'] . '</li>
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

        wp_mail($to, $subject, $message, array('Content-Type: text/html'));
    }

    private function get_order_duration($order)
    {
        // This is a placeholder. You'll need to implement logic to get the order duration
        // This could be from a custom field, product variation, or other order metadata
        return min(max(1, $order->get_meta('_order_duration', true)), 12);
    }

    private function log_webhook_request($request)
    {
        // Get the plugin's log file path
        $log_file = plugin_dir_path(__FILE__) . 'go-vps--' . date('Y-m-d') . '.log';

        // $request_data = json_decode($request, true);
        // Write the request to the log file
        $log_message = "Govps API Response: " . print_r($request, true) . "\n";
        file_put_contents($log_file, $log_message, FILE_APPEND);
    }
}

// Initialize the plugin
new GoVPSProvisioningPlugin();