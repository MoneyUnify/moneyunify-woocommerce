<?php
/**
 * Plugin Name: MoneyUnify WooCommerce Gateway
 * Description: WooCommerce payment gateway for MoneyUnify Mobile Money.
 * Version: 2.0.2
 * Author: MoneyUnify
 */

defined('ABSPATH') || exit;

// Define the gateway class
if (!class_exists('WC_Gateway_MoneyUnify') && class_exists('WC_Payment_Gateway')) {

    class WC_Gateway_MoneyUnify extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'moneyunify';
            $this->icon = '';
            $this->has_fields = true;
            $this->method_title = 'MoneyUnify';
            $this->method_description = 'Pay using MoneyUnify Mobile Money. Customer approves payment on their phone.';
            $this->supports = array('products');

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title', 'Mobile Money (MoneyUnify)');
            $this->description = $this->get_option('description', 'Enter your mobile money number and approve on your phone');
            $this->auth_id = $this->get_option('auth_id');
            $this->currency = $this->get_option('currency', 'ZMW');
            $this->sandbox = $this->get_option('sandbox') === 'yes';

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable',
                    'type' => 'checkbox',
                    'label' => 'Enable MoneyUnify payment method',
                    'default' => 'yes',
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'Payment method title shown at checkout',
                    'default' => 'Mobile Money (MoneyUnify)',
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'Description shown at checkout',
                    'default' => 'Enter your mobile money number and approve on your phone',
                ),
                'auth_id' => array(
                    'title' => 'MoneyUnify Auth ID',
                    'type' => 'text',
                    'description' => 'Your MoneyUnify API Auth ID',
                ),
                'currency' => array(
                    'title' => 'Currency',
                    'type' => 'select',
                    'description' => 'Currency to accept for payments',
                    'default' => 'ZMW',
                    'options' => array(
                        'ZMW' => 'ZMW - Zambian Kwacha',
                        'USD' => 'USD - US Dollar',
                        'NGN' => 'NGN - Nigerian Naira',
                        'KES' => 'KES - Kenyan Shilling',
                        'GHS' => 'GHS - Ghana Cedi',
                        'TZS' => 'TZS - Tanzanian Shilling',
                        'UGX' => 'UGX - Ugandan Shilling',
                        'XOF' => 'XOF - West African CFA',
                        'EUR' => 'EUR - Euro',
                        'GBP' => 'GBP - British Pound',
                    ),
                ),
                'sandbox' => array(
                    'title' => 'Sandbox Mode',
                    'type' => 'checkbox',
                    'label' => 'Enable sandbox mode for testing',
                    'default' => 'yes',
                ),
            );
        }

        public function is_available() {
            return $this->enabled === 'yes' && !empty($this->auth_id);
        }

        public function payment_fields() {
            echo '<div id="moneyunify-payment-fields">';
            
            if ($this->description) {
                echo '<p class="form-row form-row-wide">' . wp_kses_post(wpautop(wptexturize($this->description))) . '</p>';
            }
            
            echo '<p class="form-row form-row-wide">';
            echo '<label for="moneyunify_phone">Mobile Money Number <span class="required">*</span></label>';
            echo '<input type="tel" class="input-text" name="moneyunify_phone" id="moneyunify_phone" ';
            echo 'placeholder="097XXXXXXX" pattern="[0-9]{9,12}" required />';
            echo '</p>';
            echo '<p class="form-row form-row-wide">';
            echo '<small>You will receive a payment request on your phone. Approve it to complete payment.</small>';
            echo '</p>';
            echo '</div>';
        }

        public function validate_fields() {
            $phone = sanitize_text_field($_POST['moneyunify_phone'] ?? '');
            if (empty($phone) || !preg_match('/^[0-9]{9,12}$/', $phone)) {
                wc_add_notice('Please enter a valid mobile money number (9-12 digits)', 'error');
                return false;
            }
            return true;
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                wc_add_notice('Order not found', 'error');
                return array('result' => 'fail', 'redirect' => '');
            }

            if (get_woocommerce_currency() !== $this->currency) {
                wc_add_notice('MoneyUnify only supports ' . $this->currency . '. Please change your store currency.', 'error');
                return array('result' => 'fail', 'redirect' => '');
            }

            $phone = sanitize_text_field($_POST['moneyunify_phone'] ?? '');
            if (!preg_match('/^[0-9]{9,12}$/', $phone)) {
                wc_add_notice('Invalid mobile money number.', 'error');
                return array('result' => 'fail', 'redirect' => '');
            }

            $response = $this->api_call('/payments/request', array(
                'auth_id' => $this->auth_id,
                'from_payer' => $phone,
                'amount' => $order->get_total(),
                'currency' => $this->currency,
                'reference' => 'WC-' . $order_id . '-' . time(),
            ));

            if (empty($response['data']['transaction_id'])) {
                $error_msg = !empty($response['message']) ? $response['message'] : 'Payment request failed. Try again.';
                wc_add_notice($error_msg, 'error');
                return array('result' => 'fail', 'redirect' => '');
            }

            update_post_meta($order_id, '_moneyunify_txn', $response['data']['transaction_id']);
            update_post_meta($order_id, '_moneyunify_phone', $phone);
            update_post_meta($order_id, '_moneyunify_status', 'pending');

            $order->update_status('on-hold', 'Awaiting customer approval on phone (MoneyUnify)');
            wc_reduce_stock_levels($order_id);
            WC()->cart->empty_cart();

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }

        public function thankyou_page($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) return;
            
            $status = get_post_meta($order_id, '_moneyunify_status', true);
            
            echo '<div class="moneyunify-thankyou">';
            echo '<h3>Payment Status</h3>';
            
            if ($status === 'approved') {
                echo '<p class="success">Payment approved! Thank you for your order.</p>';
            } else {
                echo '<p>Please check your phone for a payment approval request.</p>';
                echo '<p class="status-pending"><small>Status: Awaiting approval</small></p>';
            }
            echo '</div>';
        }

        private function api_call($path, $body) {
            $base = $this->sandbox 
                ? 'https://sandbox.moneyunify.one' 
                : 'https://api.moneyunify.one';
            
            $args = array(
                'timeout' => 30,
                'headers' => array(
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ),
                'body' => $body,
                'sslverify' => true,
            );
            
            $response = wp_remote_post($base . $path, $args);
            
            if (is_wp_error($response)) {
                error_log('MoneyUnify API Error: ' . $response->get_error_message());
                return array('success' => false, 'message' => $response->get_error_message());
            }
            
            $body = wp_remote_retrieve_body($response);
            return json_decode($body, true);
        }
    }
}

/**
 * Register the gateway with WooCommerce - use 'plugins_loaded' hook
 */
add_action('plugins_loaded', 'moneyunify_init_gateway', 20);
function moneyunify_init_gateway() {
    if (class_exists('WC_Payment_Gateway') && !class_exists('WC_Gateway_MoneyUnify')) {
        // Class should already be defined from above, but just in case
        require_once dirname(__FILE__);
    }
    
    add_filter('woocommerce_payment_gateways', 'moneyunify_add_gateway');
}

/**
 * Add gateway to available gateways
 */
function moneyunify_add_gateway($gateways) {
    if (class_exists('WC_Gateway_MoneyUnify')) {
        $gateways[] = 'WC_Gateway_MoneyUnify';
    }
    return $gateways;
}

/**
 * Force refresh of payment gateways on admin page load
 */
add_action('woocommerce_settings_pages', 'moneyunify_force_refresh_gateways');
function moneyunify_force_refresh_gateways() {
    delete_transient('wc_gateways');
}

/**
 * AJAX handler for payment verification
 */
add_action('wp_ajax_moneyunify_poll', 'moneyunify_poll_handler');
add_action('wp_ajax_nopriv_moneyunify_poll', 'moneyunify_poll_handler');
function moneyunify_poll_handler() {
    check_ajax_referer('woocommerce-checkout', 'security');
    
    $order_id = absint($_POST['order_id'] ?? 0);
    if (!$order_id) {
        wp_send_json_error(array('message' => 'Invalid order'));
    }
    
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => 'Order not found'));
    }
    
    $txn = get_post_meta($order_id, '_moneyunify_txn', true);
    if (!$txn) {
        wp_send_json_error(array('message' => 'No transaction ID'));
    }
    
    $gateway = new WC_Gateway_MoneyUnify();
    $result = $gateway->api_call('/payments/verify', array('transaction_id' => $txn));
    
    if (!empty($result['data']['status'])) {
        $status = strtoupper($result['data']['status']);
        
        if ($status === 'SUCCESS' && $order->get_status() !== 'processing') {
            update_post_meta($order_id, '_moneyunify_status', 'approved');
            $order->payment_complete($txn);
            $order->add_order_note('Payment approved by customer (MoneyUnify).');
            wp_send_json_success(array('status' => 'approved'));
        }
        
        if (in_array($status, array('FAILED', 'REJECTED', 'CANCELLED')) && $order->get_status() !== 'failed') {
            update_post_meta($order_id, '_moneyunify_status', 'failed');
            $order->update_status('failed', 'Customer did not approve or payment failed.');
            wp_send_json_success(array('status' => 'failed'));
        }
    }
    
    wp_send_json_success(array('status' => 'waiting'));
}

/**
 * Scheduled task for verifying pending payments
 */
if (!wp_next_scheduled('moneyunify_cron_verify')) {
    wp_schedule_event(time(), 'five_minutes', 'moneyunify_cron_verify');
}

add_action('moneyunify_cron_verify', 'moneyunify_cron_verify_handler');
function moneyunify_cron_verify_handler() {
    $orders = wc_get_orders(array(
        'status' => 'on-hold',
        'limit' => 20,
        'meta_query' => array(
            array(
                'key' => '_payment_method',
                'value' => 'moneyunify',
                'compare' => '=',
            ),
        ),
    ));
    
    if (empty($orders)) return;
    
    $gateway = new WC_Gateway_MoneyUnify();
    
    foreach ($orders as $order) {
        $txn = get_post_meta($order->get_id(), '_moneyunify_txn', true);
        if (!$txn) continue;
        
        $result = $gateway->api_call('/payments/verify', array('transaction_id' => $txn));
        
        if (!empty($result['data']['status'])) {
            $status = strtoupper($result['data']['status']);
            
            if ($status === 'SUCCESS') {
                $order->payment_complete($txn);
                $order->add_order_note('Payment approved (CRON check) - MoneyUnify.');
            } elseif (in_array($status, array('FAILED', 'REJECTED', 'CANCELLED'))) {
                $order->update_status('failed', 'Customer did not approve payment - MoneyUnify.');
            }
        }
    }
}

/**
 * Add custom cron schedule
 */
add_filter('cron_schedules', 'moneyunify_cron_schedules');
function moneyunify_cron_schedules($schedules) {
    $schedules['five_minutes'] = array(
        'interval' => 300,
        'display' => 'Every 5 Minutes',
    );
    return $schedules;
}

/**
 * Admin meta box for order details
 */
add_action('add_meta_boxes', 'moneyunify_add_meta_boxes');
function moneyunify_add_meta_boxes() {
    add_meta_box(
        'moneyunify_meta',
        'MoneyUnify Transaction',
        'moneyunify_meta_box_content',
        'shop_order',
        'side',
        'default'
    );
}

function moneyunify_meta_box_content($post) {
    $txn = get_post_meta($post->ID, '_moneyunify_txn', true);
    $phone = get_post_meta($post->ID, '_moneyunify_phone', true);
    $status = get_post_meta($post->ID, '_moneyunify_status', true);
    
    echo '<table style="width:100%;">';
    if ($txn) {
        echo '<tr><td><strong>Transaction ID:</strong></td><td>' . esc_html($txn) . '</td></tr>';
    }
    if ($phone) {
        echo '<tr><td><strong>Phone:</strong></td><td>' . esc_html($phone) . '</td></tr>';
    }
    if ($status) {
        echo '<tr><td><strong>Status:</strong></td><td>' . esc_html(ucfirst($status)) . '</td></tr>';
    }
    echo '</table>';
}

/**
 * Debug shortcode - add [moneyunify_debug] to any page
 */
add_shortcode('moneyunify_debug', 'moneyunify_debug_shortcode');
function moneyunify_debug_shortcode() {
    if (!current_user_can('manage_options')) return '';
    
    ob_start();
    
    echo '<div style="background:#f0f0f1; padding:20px; margin:20px 0; border:1px solid #ccc;">';
    echo '<h3>MoneyUnify Gateway Debug</h3>';
    
    echo '<p><strong>Gateway Class Exists:</strong> ' . (class_exists('WC_Gateway_MoneyUnify') ? 'Yes' : 'No') . '</p>';
    
    if (function_exists('WC')) {
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        echo '<p><strong>MoneyUnify in Available Gateways:</strong> ' . (isset($gateways['moneyunify']) ? 'Yes' : 'No') . '</p>';
        
        if (isset($gateways['moneyunify'])) {
            $gateway = $gateways['moneyunify'];
            echo '<p><strong>Gateway Enabled:</strong> ' . ($gateway->enabled === 'yes' ? 'Yes' : 'No') . '</p>';
            echo '<p><strong>Auth ID Set:</strong> ' . (!empty($gateway->auth_id) ? 'Yes' : 'No') . '</p>';
            echo '<p><strong>Gateway Currency:</strong> ' . esc_html($gateway->currency) . '</p>';
            echo '<p><strong>Store Currency:</strong> ' . esc_html(get_woocommerce_currency()) . '</p>';
            echo '<p><strong>is_available():</strong> ' . ($gateway->is_available() ? 'Yes' : 'No') . '</p>';
        }
    } else {
        echo '<p>WooCommerce not loaded</p>';
    }
    
    echo '</div>';
    
    return ob_get_clean();
}
