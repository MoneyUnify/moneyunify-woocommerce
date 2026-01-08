<?php
/**
 * Plugin Name: MoneyUnify WooCommerce Gateway
 * Description: WooCommerce payment gateway for MoneyUnify Mobile Money. Works with all themes including custom checkout builders.
 * Version: 2.0.0
 * Author: MoneyUnify
 * Text Domain: moneyunify
 */

if (!defined('ABSPATH')) exit;

// Define the gateway class early to ensure it's always available
if (!class_exists('WC_Gateway_MoneyUnify')) {

    class WC_Gateway_MoneyUnify extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'moneyunify';
            $this->icon = '';
            $this->has_fields = true;
            $this->method_title = __('MoneyUnify', 'moneyunify');
            $this->method_description = __('Pay using MoneyUnify Mobile Money. Customer approves payment on their phone.', 'moneyunify');
            $this->supports = ['products'];

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title', __('Mobile Money (MoneyUnify)', 'moneyunify'));
            $this->description = $this->get_option('description', __('You will receive a payment prompt on your phone', 'moneyunify'));
            $this->auth_id = $this->get_option('auth_id');
            $this->currency = $this->get_option('currency', 'ZMW');
            $this->sandbox = $this->get_option('sandbox') === 'yes';

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title' => __('Enable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable MoneyUnify payment method', 'moneyunify'),
                    'default' => 'yes',
                    'desc_tip' => true,
                ],
                'title' => [
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Payment method title shown at checkout', 'moneyunify'),
                    'default' => __('Mobile Money (MoneyUnify)', 'moneyunify'),
                    'desc_tip' => true,
                ],
                'description' => [
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Description shown at checkout', 'moneyunify'),
                    'default' => __('Enter your mobile money number and approve the payment on your phone', 'moneyunify'),
                    'desc_tip' => true,
                ],
                'auth_id' => [
                    'title' => __('MoneyUnify Auth ID', 'moneyunify'),
                    'type' => 'text',
                    'description' => __('Your MoneyUnify API Auth ID', 'moneyunify'),
                    'desc_tip' => true,
                    'placeholder' => 'Enter your Auth ID',
                ],
                'currency' => [
                    'title' => __('Currency', 'woocommerce'),
                    'type' => 'select',
                    'description' => __('Currency to accept for payments', 'moneyunify'),
                    'default' => 'ZMW',
                    'options' => [
                        'ZMW' => __('ZMW - Zambian Kwacha', 'moneyunify'),
                        'USD' => __('USD - US Dollar', 'moneyunify'),
                        'NGN' => __('NGN - Nigerian Naira', 'moneyunify'),
                        'KES' => __('KES - Kenyan Shilling', 'moneyunify'),
                        'GHS' => __('GHS - Ghana Cedi', 'moneyunify'),
                        'TZS' => __('TZS - Tanzanian Shilling', 'moneyunify'),
                        'UGX' => __('UGX - Ugandan Shilling', 'moneyunify'),
                        'XOF' => __('XOF - West African CFA', 'moneyunify'),
                        'EUR' => __('EUR - Euro', 'moneyunify'),
                        'GBP' => __('GBP - British Pound', 'moneyunify'),
                    ],
                    'desc_tip' => true,
                ],
                'sandbox' => [
                    'title' => __('Sandbox Mode', 'moneyunify'),
                    'type' => 'checkbox',
                    'label' => __('Enable sandbox mode for testing', 'moneyunify'),
                    'default' => 'yes',
                    'description' => __('Use sandbox.moneyunify.one for testing', 'moneyunify'),
                    'desc_tip' => true,
                ],
            ];
        }

        public function is_available() {
            // Always return true if enabled - don't restrict by currency for availability
            // Currency validation happens during payment processing
            return $this->enabled === 'yes' && !empty($this->auth_id);
        }

        public function payment_fields() {
            echo '<div id="moneyunify-payment-fields">';
            
            if ($this->description) {
                echo '<p class="form-row form-row-wide">' . wp_kses_post(wpautop(wptexturize($this->description))) . '</p>';
            }
            
            echo '<p class="form-row form-row-wide">';
            echo '<label for="moneyunify_phone">' . __('Mobile Money Number', 'moneyunify') . ' <span class="required">*</span></label>';
            echo '<input type="tel" class="input-text" name="moneyunify_phone" id="moneyunify_phone" ';
            echo 'placeholder="' . __('097XXXXXXX', 'moneyunify') . '" pattern="[0-9]{9,12}" required />';
            echo '</p>';
            echo '<p class="form-row form-row-wide">';
            echo '<small>' . __('You will receive a payment request on your phone. Approve it to complete payment.', 'moneyunify') . '</small>';
            echo '</p>';
            
            echo '</div>';
            
            // Add inline JavaScript for validation
            echo '<script>
            jQuery(document).ready(function($) {
                $("form.checkout").on("checkout_place_order_moneyunify", function() {
                    var phone = $("input[name=\"moneyunify_phone\"]").val();
                    if (!phone || !/^[0-9]{9,12}$/.test(phone)) {
                        alert("' . __('Please enter a valid mobile money number (9-12 digits)', 'moneyunify') . '");
                        return false;
                    }
                    return true;
                });
            });
            </script>';
        }

        public function validate_fields() {
            $phone = sanitize_text_field($_POST['moneyunify_phone'] ?? '');
            if (empty($phone) || !preg_match('/^[0-9]{9,12}$/', $phone)) {
                wc_add_notice(__('Please enter a valid mobile money number (9-12 digits)', 'moneyunify'), 'error');
                return false;
            }
            return true;
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                wc_add_notice(__('Order not found', 'woocommerce'), 'error');
                return ['result' => 'fail', 'redirect' => ''];
            }

            // Currency validation
            if (get_woocommerce_currency() !== $this->currency) {
                wc_add_notice(
                    sprintf(__('MoneyUnify only supports %s. Please change your store currency.', 'moneyunify'), $this->currency), 
                    'error'
                );
                return ['result' => 'fail', 'redirect' => ''];
            }

            $phone = sanitize_text_field($_POST['moneyunify_phone'] ?? '');
            if (!preg_match('/^[0-9]{9,12}$/', $phone)) {
                wc_add_notice(__('Invalid mobile money number.', 'moneyunify'), 'error');
                return ['result' => 'fail', 'redirect' => ''];
            }

            // Request payment
            $response = $this->api_call('/payments/request', [
                'auth_id' => $this->auth_id,
                'from_payer' => $phone,
                'amount' => $order->get_total(),
                'currency' => $this->currency,
                'reference' => 'WC-' . $order_id . '-' . time(),
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            ]);

            if (empty($response['data']['transaction_id'])) {
                $error_msg = !empty($response['message']) ? $response['message'] : 'Payment request failed. Try again.';
                wc_add_notice(__($error_msg, 'moneyunify'), 'error');
                return ['result' => 'fail', 'redirect' => ''];
            }

            update_post_meta($order_id, '_moneyunify_txn', $response['data']['transaction_id']);
            update_post_meta($order_id, '_moneyunify_phone', $phone);
            update_post_meta($order_id, '_moneyunify_status', 'pending');

            $order->update_status('on-hold', __('Awaiting customer approval on phone (MoneyUnify)', 'moneyunify'));
            wc_reduce_stock_levels($order_id);
            
            // Clear cart
            WC()->cart->empty_cart();

            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        }

        public function thankyou_page($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) return;
            
            $txn = get_post_meta($order_id, '_moneyunify_txn', true);
            $status = get_post_meta($order_id, '_moneyunify_status', true);
            
            echo '<div class="moneyunify-thankyou">';
            echo '<h3>' . __('Payment Status', 'moneyunify') . '</h3>';
            
            if ($status === 'approved') {
                echo '<p class="success">' . __('✓ Payment approved! Thank you for your order.', 'moneyunify') . '</p>';
            } else {
                echo '<p>' . __('Please check your phone for a payment approval request.', 'moneyunify') . '</p>';
                echo '<p class="status-pending"><small>' . __('Status: Awaiting approval', 'moneyunify') . '</small></p>';
                
                // Auto-refresh script
                echo '<script>
                (function() {
                    var checkCount = 0;
                    var maxChecks = 60; // 10 minutes
                    var checkInterval = setInterval(function() {
                        checkCount++;
                        if (checkCount > maxChecks) {
                            clearInterval(checkInterval);
                            return;
                        }
                        fetch("' . admin_url('admin-ajax.php') . '", {
                            method: "POST",
                            headers: {"Content-Type": "application/x-www-form-urlencoded"},
                            body: "action=moneyunify_poll&order_id=' . $order_id . '"
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            if (d.success && d.data && d.data.status === "approved") {
                                clearInterval(checkInterval);
                                location.reload();
                            } else if (d.success && d.data && d.data.status === "failed") {
                                clearInterval(checkInterval);
                                alert("Payment was not approved. Please try again or contact support.");
                            }
                        })
                        .catch(function() {});
                    }, 10000);
                })();
                </script>';
            }
            echo '</div>';
        }

        private function api_call($path, $body) {
            $base = $this->sandbox 
                ? 'https://sandbox.moneyunify.one' 
                : 'https://api.moneyunify.one';
            
            $args = [
                'timeout' => 30,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => $body,
                'sslverify' => true,
            ];
            
            $response = wp_remote_post($base . $path, $args);
            
            if (is_wp_error($response)) {
                error_log('MoneyUnify API Error: ' . $response->get_error_message());
                return ['success' => false, 'message' => $response->get_error_message()];
            }
            
            $body = wp_remote_retrieve_body($response);
            return json_decode($body, true);
        }
    }
}

/**
 * Register the gateway with very early priority to ensure it's always loaded
 */
add_filter('woocommerce_payment_gateways', 'moneyunify_add_gateway_class', 1);
function moneyunify_add_gateway_class($gateways) {
    $gateways[] = 'WC_Gateway_MoneyUnify';
    return $gateways;
}

/**
 * Also try loading via init with high priority as backup
 */
add_action('init', 'moneyunify_ensure_gateway_loaded', 1);
function moneyunify_ensure_gateway_loaded() {
    if (!has_action('woocommerce_payment_gateways', 'moneyunify_add_gateway_class')) {
        add_filter('woocommerce_payment_gateways', 'moneyunify_add_gateway_class', 1);
    }
}

/**
 * Force payment gateways to reload on checkout page
 * This fixes caching issues and ensures gateways are always available
 */
add_action('wp_enqueue_scripts', 'moneyunify_enqueue_scripts');
function moneyunify_enqueue_scripts() {
    if (!is_checkout()) return;
    
    wp_enqueue_script(
        'moneyunify-checkout',
        plugins_url('moneyunify-checkout.js', __FILE__),
        ['jquery', 'wc-checkout'],
        '2.0.0',
        true
    );
    
    wp_localize_script('moneyunify-checkout', 'moneyunify_params', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('moneyunify_nonce'),
    ]);
}

/**
 * AJAX handler for payment verification
 */
add_action('wp_ajax_moneyunify_poll', 'moneyunify_poll_handler');
add_action('wp_ajax_nopriv_moneyunify_poll', 'moneyunify_poll_handler');
function moneyunify_poll_handler() {
    check_ajax_referer('moneyunify_nonce', 'nonce');
    
    $order_id = absint($_POST['order_id'] ?? 0);
    if (!$order_id) {
        wp_send_json_error(['message' => 'Invalid order']);
    }
    
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => 'Order not found']);
    }
    
    $txn = get_post_meta($order_id, '_moneyunify_txn', true);
    if (!$txn) {
        wp_send_json_error(['message' => 'No transaction ID']);
    }
    
    $gateway = new WC_Gateway_MoneyUnify();
    $result = $gateway->api_call('/payments/verify', ['transaction_id' => $txn]);
    
    if (!empty($result['data']['status'])) {
        $status = strtoupper($result['data']['status']);
        
        if ($status === 'SUCCESS' && $order->get_status() !== 'processing') {
            update_post_meta($order_id, '_moneyunify_status', 'approved');
            $order->payment_complete($txn);
            $order->add_order_note(__('Payment approved by customer (MoneyUnify).', 'moneyunify'));
            wp_send_json_success(['status' => 'approved']);
        }
        
        if (in_array($status, ['FAILED', 'REJECTED', 'CANCELLED']) && $order->get_status() !== 'failed') {
            update_post_meta($order_id, '_moneyunify_status', 'failed');
            $order->update_status('failed', __('Customer did not approve or payment failed.', 'moneyunify'));
            wp_send_json_success(['status' => 'failed']);
        }
    }
    
    wp_send_json_success(['status' => 'waiting']);
}

/**
 * Debug shortcode - add [moneyunify_debug] to any page to see gateway status
 */
add_shortcode('moneyunify_debug', 'moneyunify_debug_shortcode');
function moneyunify_debug_shortcode() {
    if (!current_user_can('manage_options')) return '';
    
    ob_start();
    
    echo '<div style="background:#f0f0f1; padding:20px; margin:20px 0; border:1px solid #ccc;">';
    echo '<h3>MoneyUnify Gateway Debug</h3>';
    
    // Check if gateway class exists
    echo '<p><strong>Gateway Class Exists:</strong> ' . (class_exists('WC_Gateway_MoneyUnify') ? '✓ Yes' : '✗ No') . '</p>';
    
    // Check if gateway is registered
    $gateways = WC()->payment_gateways()->get_available_payment_gateways();
    echo '<p><strong>MoneyUnify in Available Gateways:</strong> ' . (isset($gateways['moneyunify']) ? '✓ Yes' : '✗ No') . '</p>';
    
    // Check settings
    if (class_exists('WC_Gateway_MoneyUnify')) {
        $gateway = new WC_Gateway_MoneyUnify();
        echo '<p><strong>Gateway Enabled:</strong> ' . ($gateway->enabled === 'yes' ? '✓ Yes' : '✗ No') . '</p>';
        echo '<p><strong>Auth ID Set:</strong> ' . (!empty($gateway->auth_id) ? '✓ Yes' : '✗ No') . '</p>';
        echo '<p><strong>Gateway Currency:</strong> ' . esc_html($gateway->currency) . '</p>';
        echo '<p><strong>Store Currency:</strong> ' . esc_html(get_woocommerce_currency()) . '</p>';
        echo '<p><strong>is_available():</strong> ' . ($gateway->is_available() ? '✓ Yes' : '✗ No') . '</p>';
    }
    
    echo '</div>';
    
    return ob_get_clean();
}

/**
 * Force refresh of payment gateways on checkout page
 * This helps with theme conflicts and caching
 */
add_action('woocommerce_before_checkout_form', 'moneyunify_force_gateway_refresh', 5);
function moneyunify_force_gateway_refresh() {
    // Clear any cached gateway data
    delete_transient('wc_gateways');
    
    // Force reload of available gateways
    WC()->payment_gateways()->init();
}

/**
 * Admin notice for troubleshooting
 */
add_action('admin_notices', 'moneyunify_admin_notices');
function moneyunify_admin_notices() {
    if (!current_user_can('manage_options')) return;
    
    // Check if MoneyUnify is properly configured
    if (!class_exists('WC_Gateway_MoneyUnify')) {
        echo '<div class="notice notice-error"><p>';
        echo __('MoneyUnify Gateway: Gateway class not loaded. Please check plugin file.', 'moneyunify');
        echo '</p></div>';
        return;
    }
    
    $gateway = new WC_Gateway_MoneyUnify();
    
    if ($gateway->enabled !== 'yes') {
        echo '<div class="notice notice-warning"><p>';
        echo __('MoneyUnify Gateway: Gateway is disabled. Enable it in WooCommerce → Settings → Payments.', 'moneyunify');
        echo '</p></div>';
        return;
    }
    
    if (empty($gateway->auth_id)) {
        echo '<div class="notice notice-warning"><p>';
        echo __('MoneyUnify Gateway: Auth ID is not configured. Add it in WooCommerce → Settings → Payments → MoneyUnify.', 'moneyunify');
        echo '</p></div>';
        return;
    }
    
    if (get_woocommerce_currency() !== $gateway->currency) {
        echo '<div class="notice notice-info"><p>';
        echo sprintf(
            __('MoneyUnify Gateway: Store currency (%s) differs from gateway currency (%s). Change one to match.', 'moneyunify'),
            get_woocommerce_currency(),
            $gateway->currency
        );
        echo '</p></div>';
    }
}

/**
 * Scheduled task for verifying pending payments
 */
if (!wp_next_scheduled('moneyunify_cron_verify')) {
    wp_schedule_event(time(), 'five_minutes', 'moneyunify_cron_verify');
}

add_action('moneyunify_cron_verify', 'moneyunify_cron_verify_handler');
function moneyunify_cron_verify_handler() {
    $orders = wc_get_orders([
        'status' => 'on-hold',
        'limit' => 20,
        'meta_query' => [
            [
                'key' => '_payment_method',
                'value' => 'moneyunify',
                'compare' => '=',
            ],
        ],
    ]);
    
    if (empty($orders)) return;
    
    $gateway = new WC_Gateway_MoneyUnify();
    
    foreach ($orders as $order) {
        $txn = get_post_meta($order->get_id(), '_moneyunify_txn', true);
        if (!$txn) continue;
        
        $result = $gateway->api_call('/payments/verify', ['transaction_id' => $txn]);
        
        if (!empty($result['data']['status'])) {
            $status = strtoupper($result['data']['status']);
            
            if ($status === 'SUCCESS') {
                $order->payment_complete($txn);
                $order->add_order_note(__('Payment approved (CRON check) - MoneyUnify.', 'moneyunify'));
            } elseif (in_array($status, ['FAILED', 'REJECTED', 'CANCELLED'])) {
                $order->update_status('failed', __('Customer did not approve payment - MoneyUnify.', 'moneyunify'));
            }
        }
    }
}

// Add custom cron schedule
add_filter('cron_schedules', 'moneyunify_cron_schedules');
function moneyunify_cron_schedules($schedules) {
    $schedules['five_minutes'] = [
        'interval' => 300,
        'display' => __('Every 5 Minutes', 'moneyunify'),
    ];
    return $schedules;
}

/**
 * Admin meta box for order details
 */
add_action('add_meta_boxes', 'moneyunify_add_meta_boxes');
function moneyunify_add_meta_boxes() {
    add_meta_box(
        'moneyunify_meta',
        __('MoneyUnify Transaction', 'moneyunify'),
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
        echo '<tr><td><strong>' . __('Transaction ID:', 'moneyunify') . '</strong></td><td>' . esc_html($txn) . '</td></tr>';
    }
    if ($phone) {
        echo '<tr><td><strong>' . __('Phone:', 'moneyunify') . '</strong></td><td>' . esc_html($phone) . '</td></tr>';
    }
    if ($status) {
        echo '<tr><td><strong>' . __('Status:', 'moneyunify') . '</strong></td><td>' . esc_html(ucfirst($status)) . '</td></tr>';
    }
    echo '</table>';
}
