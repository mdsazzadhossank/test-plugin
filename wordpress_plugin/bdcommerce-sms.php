<?php
/**
 * Plugin Name: BdCommerce SMS Manager
 * Plugin URI:  https://bdcommerce.com
 * Description: A complete SMS & Customer Management solution. Sync customers and send Bulk SMS by relaying requests through your Main Dashboard. Includes Live Capture & Fraud Check.
 * Version:     1.5.0
 * Author:      BdCommerce
 * License:     GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class BDC_SMS_Manager {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'bdc_customers';

        // Hooks
        register_activation_hook( __FILE__, array( $this, 'create_tables' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        
        // AJAX Handlers
        add_action( 'wp_ajax_bdc_sync_customers', array( $this, 'ajax_sync_customers' ) );
        add_action( 'wp_ajax_bdc_send_sms', array( $this, 'ajax_send_sms' ) );

        // Live Capture Injection
        add_action( 'wp_footer', array( $this, 'inject_live_capture_script' ) );

        // WooCommerce Order Column Hook (Fraud Check)
        add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_fraud_check_column' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_fraud_check_column' ), 10, 2 );
    }

    /**
     * Create Database Table for Customers
     */
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            phone varchar(20) NOT NULL,
            email varchar(100) DEFAULT '',
            total_spent decimal(10,2) DEFAULT 0,
            order_count int DEFAULT 0,
            last_order_date datetime DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY phone (phone)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    /**
     * Add Menu Page
     */
    public function add_admin_menu() {
        add_menu_page(
            'SMS Manager',
            'SMS Manager',
            'manage_options',
            'bdc-sms-manager',
            array( $this, 'render_dashboard' ),
            'dashicons-smartphone',
            56
        );
    }

    /**
     * Register Settings
     */
    public function register_settings() {
        register_setting( 'bdc_sms_group', 'bdc_dashboard_url' );
    }

    /**
     * Enqueue Scripts and Styles
     */
    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_bdc-sms-manager' === $hook || 'edit.php' === $hook ) {
            wp_enqueue_script( 'tailwindcss', 'https://cdn.tailwindcss.com', array(), '3.0', false );
            
            // Script for Fraud Check Column (Only on order list page)
            if( 'edit.php' === $hook && isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order' ) {
                $api_base = $this->get_api_base_url();
                if($api_base) {
                    wp_add_inline_script('jquery', '
                        jQuery(document).ready(function($) {
                            $(".bdc-fraud-check-btn").on("click", function(e) {
                                e.preventDefault();
                                var btn = $(this);
                                var phone = btn.data("phone");
                                var target = btn.closest("div");
                                
                                btn.text("Checking...");
                                
                                $.get("' . esc_url($api_base . '/check_fraud.php') . '?phone=" + phone, function(data) {
                                    if(data.error) {
                                        target.html("<span style=\"color:red\">Error</span>");
                                        return;
                                    }
                                    var color = data.success_rate > 80 ? "green" : (data.success_rate > 50 ? "orange" : "red");
                                    var html = "<div style=\"font-weight:bold; color:" + color + "\">" + data.success_rate + "% Success</div>";
                                    html += "<div style=\"font-size:10px; color:#666\">" + data.delivered + "/" + data.total_orders + " Delivered</div>";
                                    target.html(html);
                                }).fail(function() {
                                    target.html("<span style=\"color:red\">Failed</span>");
                                });
                            });
                        });
                    ');
                }
            }
        }
    }

    /**
     * Add Custom Column to Orders
     */
    public function add_fraud_check_column( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $column ) {
            $new_columns[$key] = $column;
            if ( 'order_total' === $key ) {
                $new_columns['bdc_fraud_check'] = __( 'Fraud Check', 'bdc-sms' );
            }
        }
        return $new_columns;
    }

    /**
     * Render Custom Column Data
     */
    public function render_fraud_check_column( $column, $post_id ) {
        if ( 'bdc_fraud_check' === $column ) {
            $order = wc_get_order( $post_id );
            if ( $order ) {
                $phone = $order->get_billing_phone();
                if($phone) {
                    echo '<div><button class="button small bdc-fraud-check-btn" data-phone="'.esc_attr($phone).'">Check</button></div>';
                } else {
                    echo '<span class="description">No Phone</span>';
                }
            }
        }
    }

    /**
     * Helper: Normalize Dashboard URL
     */
    private function get_api_base_url() {
        $dashboard_url = get_option( 'bdc_dashboard_url' );
        if ( empty( $dashboard_url ) ) return null;

        $clean_url = preg_replace('/\/[a-zA-Z0-9_-]+\.php$/', '', $dashboard_url);
        $base_url = rtrim( $clean_url, '/' );
        
        if ( substr( $base_url, -3 ) === 'api' ) {
             return $base_url;
        } else {
             return $base_url . '/api';
        }
    }

    /**
     * Fetch Feature Flags from Dashboard API
     */
    private function get_remote_features() {
        $api_base = $this->get_api_base_url();
        if ( ! $api_base ) return [];

        $cached_features = get_transient('bdc_remote_features');
        if ( false !== $cached_features ) {
            return $cached_features;
        }

        $response = wp_remote_get( $api_base . '/features.php', array( 'timeout' => 5, 'sslverify' => false ) );
        
        if ( is_wp_error( $response ) ) return [];

        $body = wp_remote_retrieve_body( $response );
        $features = json_decode( $body, true );

        if ( is_array( $features ) ) {
            set_transient( 'bdc_remote_features', $features, 5 * MINUTE_IN_SECONDS );
            return $features;
        }

        return [];
    }

    /**
     * Inject Live Capture Script on Checkout
     */
    public function inject_live_capture_script() {
        if ( ! is_checkout() || is_order_received_page() ) return;

        $api_base = $this->get_api_base_url();
        if ( ! $api_base ) return;

        $features = $this->get_remote_features();
        if ( empty($features['live_capture']) || $features['live_capture'] !== true ) {
            return;
        }

        $cart_items = [];
        if ( WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $cart_item ) {
                $product = $cart_item['data'];
                $cart_items[] = [
                    'product_id' => $cart_item['product_id'],
                    'name' => $product->get_name(),
                    'quantity' => $cart_item['quantity'],
                    'total' => $cart_item['line_total']
                ];
            }
        }
        $cart_total = WC()->cart ? WC()->cart->total : 0;
        $session_id = WC()->session ? WC()->session->get_customer_id() : uniqid('guest_', true);

        ?>
        <script type="text/javascript">
        (function($) {
            $(document).ready(function() {
                const apiEndpoint = "<?php echo esc_url($api_base . '/live_capture.php'); ?>";
                const sessionId = "<?php echo esc_js($session_id); ?>";
                const cartItems = <?php echo json_encode($cart_items); ?>;
                const cartTotal = <?php echo esc_js($cart_total); ?>;
                
                let debounceTimer;

                function captureData() {
                    const phone = $('#billing_phone').val();
                    const name = $('#billing_first_name').val() + ' ' + $('#billing_last_name').val();
                    const email = $('#billing_email').val();
                    const address = $('#billing_address_1').val() + ', ' + $('#billing_city').val();

                    if(phone && phone.length > 5) {
                        $.ajax({
                            url: apiEndpoint,
                            type: 'POST',
                            contentType: 'application/json',
                            data: JSON.stringify({
                                session_id: sessionId,
                                phone: phone,
                                name: name,
                                email: email,
                                address: address,
                                cart_items: cartItems,
                                cart_total: cartTotal
                            }),
                            success: function(res) {
                                // console.log('Lead captured', res);
                            }
                        });
                    }
                }

                $('form.checkout').on('input', 'input', function() {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(captureData, 1000); // 1s debounce
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    private function check_connection() {
        $api_base = $this->get_api_base_url();
        if (!$api_base) return false;
        $url = $api_base . '/settings.php?key=check_connection';
        $response = wp_remote_get( $url, array( 'timeout' => 5, 'sslverify' => false ) );
        if ( is_wp_error( $response ) ) return false;
        return wp_remote_retrieve_response_code($response) === 200;
    }

    public function ajax_sync_customers() {
        check_ajax_referer( 'bdc_sms_nonce', 'nonce' );
        if ( ! class_exists( 'WooCommerce' ) ) wp_send_json_error( 'WooCommerce is not installed.' );
        global $wpdb;
        $orders = wc_get_orders( array('limit' => -1, 'status' => array('wc-completed', 'wc-processing', 'wc-on-hold')) );
        $count = 0;
        foreach ( $orders as $order ) {
            $phone = preg_replace( '/[^0-9]/', '', $order->get_billing_phone() );
            if ( empty( $phone ) ) continue;
            
            $exists = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $this->table_name WHERE phone = %s", $phone ) );
            if ( $exists ) {
                $wpdb->update($this->table_name, array('name' => $order->get_billing_first_name().' '.$order->get_billing_last_name(), 'email' => $order->get_billing_email(), 'last_order_date' => $order->get_date_created()->date('Y-m-d H:i:s')), array('id' => $exists->id));
            } else {
                $wpdb->insert($this->table_name, array('name' => $order->get_billing_first_name().' '.$order->get_billing_last_name(), 'phone' => $phone, 'email' => $order->get_billing_email(), 'total_spent' => $order->get_total(), 'order_count' => 1, 'last_order_date' => $order->get_date_created()->date('Y-m-d H:i:s')));
                $count++;
            }
        }
        
        wp_send_json_success( "$count new customers imported." );
    }

    public function ajax_send_sms() {
        check_ajax_referer( 'bdc_sms_nonce', 'nonce' );
        $numbers = $_POST['numbers'] ?? [];
        $message = $_POST['message'] ?? '';
        $api_base = $this->get_api_base_url();
        if ( !$api_base ) wp_send_json_error( 'Dashboard URL missing.' );
        if ( empty( $numbers ) || empty( $message ) ) wp_send_json_error( 'Inputs empty.' );

        $formatted_numbers = [];
        foreach ( $numbers as $phone ) {
            $p = $phone; 
            if ( strlen( $p ) == 11 && substr( $p, 0, 2 ) == '01' ) $p = '88' . $p;
            $formatted_numbers[] = $p;
        }
        $contacts_csv = implode(',', $formatted_numbers);
        $type = (mb_strlen($message) != strlen($message)) ? 'unicode' : 'text';

        $response = wp_remote_post( $api_base . '/send_sms.php', array(
            'body' => json_encode(array("contacts" => $contacts_csv, "msg" => $message, "type" => $type)),
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 25, 'sslverify' => false
        ));

        if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );
        wp_send_json_success( "Sent successfully." );
    }

    public function render_dashboard() {
        global $wpdb;
        $customers = $wpdb->get_results( "SELECT * FROM $this->table_name ORDER BY id DESC" );
        $is_connected = $this->check_connection();
        
        $features = $this->get_remote_features();
        
        include(plugin_dir_path(__FILE__) . 'admin-view.php');
    }
}

new BDC_SMS_Manager();
?>