<?php
/**
 * Plugin Name: MoneyUnify WooCommerce Gateway (ZMW – Approval Required)
 * Description: WooCommerce payment gateway for MoneyUnify. Only completes order after customer approves payment on phone. Shows properly in WooCommerce payment methods.
 * Version: 1.5.0
 * Author: Kazashim kuzasuwat
 */

if (!defined('ABSPATH')) exit;

/*--------------------------------------------------------------
 REGISTER GATEWAY
--------------------------------------------------------------*/
add_filter('woocommerce_payment_gateways', function ($gateways) {
    $gateways[] = 'WC_Gateway_MoneyUnify';
    return $gateways;
});

/*--------------------------------------------------------------
 LOAD GATEWAY AFTER WOOCOMMERCE IS READY
--------------------------------------------------------------*/
add_action('plugins_loaded', function () {

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_MoneyUnify extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'moneyunify';
            $this->method_title = 'MoneyUnify';
            $this->method_description = 'Pay using MoneyUnify Mobile Money. Order completes ONLY after customer approval.';
            $this->has_fields = true;

            $this->init_form_fields();
            $this->init_settings();

            $this->title   = $this->get_option('title');
            $this->auth_id = $this->get_option('auth_id');
            $this->sandbox = $this->get_option('sandbox') === 'yes';

            add_action(
                'woocommerce_update_options_payment_gateways_' . $this->id,
                [$this, 'process_admin_options']
            );
        }

        /*--------------------------------------------------------------
        SETTINGS
        --------------------------------------------------------------*/
        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title' => 'Enable',
                    'type' => 'checkbox',
                    'default' => 'yes'
                ],
                'title' => [
                    'title' => 'Title',
                    'type' => 'text',
                    'default' => 'Mobile Money (MoneyUnify)'
                ],
                'auth_id' => [
                    'title' => 'MoneyUnify Auth ID',
                    'type' => 'text'
                ],
                'sandbox' => [
                    'title' => 'Sandbox Mode',
                    'type' => 'checkbox',
                    'default' => 'yes'
                ]
            ];
        }

        /*--------------------------------------------------------------
        CHECK IF GATEWAY IS AVAILABLE
        --------------------------------------------------------------*/
        public function is_available() {

            if ($this->enabled !== 'yes') return false;
            if (get_woocommerce_currency() !== 'ZMW') return false;
            if (empty($this->auth_id)) return false;

            return true;
        }

        /*--------------------------------------------------------------
        INLINE CHECKOUT UI
        --------------------------------------------------------------*/
        public function payment_fields() {
            ?>
            <div id="moneyunify-inline">
                <p><strong>Enter Mobile Money Number</strong></p>
                <input type="text" name="moneyunify_phone" placeholder="097XXXXXXX" required />
                <small>You will receive a prompt on your phone to approve payment.</small>
            </div>
            <?php
        }

        /*--------------------------------------------------------------
        PROCESS PAYMENT
        --------------------------------------------------------------*/
        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            if (get_woocommerce_currency() !== 'ZMW') {
                wc_add_notice('MoneyUnify only supports ZMW.', 'error');
                return;
            }

            $phone = sanitize_text_field($_POST['moneyunify_phone'] ?? '');
            if (!preg_match('/^[0-9]{9,12}$/', $phone)) {
                wc_add_notice('Invalid mobile money number.', 'error');
                return;
            }

            // Request payment
            $response = $this->api_call('/payments/request', [
                'auth_id'    => $this->auth_id,
                'from_payer' => $phone,
                'amount'     => $order->get_total(),
                'currency'   => 'ZMW'
            ]);

            if (empty($response['data']['transaction_id'])) {
                wc_add_notice('Payment request failed. Try again.', 'error');
                return;
            }

            update_post_meta($order_id, '_moneyunify_txn', $response['data']['transaction_id']);
            update_post_meta($order_id, '_moneyunify_phone', $phone);

            $order->update_status('on-hold', 'Awaiting customer approval on phone (MoneyUnify)');
            wc_reduce_stock_levels($order_id);
            WC()->cart->empty_cart();

            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            ];
        }

        /*--------------------------------------------------------------
        VERIFY PAYMENT
        --------------------------------------------------------------*/
        public function verify_payment($txn) {
            return $this->api_call('/payments/verify', [
                'transaction_id' => $txn
            ]);
        }

        /*--------------------------------------------------------------
        API CALL
        --------------------------------------------------------------*/
        private function api_call($path, $body) {
            $base = $this->sandbox
                ? 'https://sandbox.moneyunify.one'
                : 'https://api.moneyunify.one';

            $response = wp_remote_post($base . $path, [
                'timeout' => 30,
                'headers' => ['Accept' => 'application/json'],
                'body' => $body
            ]);

            if (is_wp_error($response)) return null;
            return json_decode(wp_remote_retrieve_body($response), true);
        }
    }
});

/*--------------------------------------------------------------
 AJAX POLLING (THANK YOU PAGE)
--------------------------------------------------------------*/
add_action('wp_ajax_moneyunify_poll', 'moneyunify_poll');
add_action('wp_ajax_nopriv_moneyunify_poll', 'moneyunify_poll');

function moneyunify_poll() {
    $order = wc_get_order(absint($_POST['order_id']));
    if (!$order) wp_send_json_error();

    $txn = get_post_meta($order->get_id(), '_moneyunify_txn', true);
    if (!$txn) wp_send_json_error();

    $gateway = new WC_Gateway_MoneyUnify();
    $result = $gateway->verify_payment($txn);

    if (!empty($result['data']['status'])) {
        $status = strtoupper($result['data']['status']);

        if ($status === 'SUCCESS') {
            $order->payment_complete();
            $order->add_order_note('Payment approved by customer (MoneyUnify).');
            wp_send_json_success(['status' => 'approved']);
        }

        if (in_array($status, ['FAILED', 'REJECTED', 'CANCELLED'])) {
            $order->update_status('failed', 'Customer did not approve or payment failed.');
            wp_send_json_success(['status' => 'failed']);
        }
    }

    wp_send_json_success(['status' => 'waiting']);
}

/*--------------------------------------------------------------
 THANK YOU PAGE POLLING SCRIPT
--------------------------------------------------------------*/
add_action('wp_footer', function () {
    if (!is_order_received_page()) return;
    ?>
    <script>
    setInterval(function () {
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=moneyunify_poll&order_id=<?php echo get_query_var("order-received"); ?>'
        })
        .then(r => r.json())
        .then(d => {
            if (d.success && d.data.status === 'approved') {
                location.reload();
            }
        });
    }, 10000);
    </script>
    <?php
});

/*--------------------------------------------------------------
 CRON FAILSAFE (EVERY 5 MINUTES)
--------------------------------------------------------------*/
add_action('moneyunify_cron_verify', function () {

    $orders = wc_get_orders([
        'status' => 'on-hold',
        'limit' => 10
    ]);

    $gateway = new WC_Gateway_MoneyUnify();

    foreach ($orders as $order) {
        $txn = get_post_meta($order->get_id(), '_moneyunify_txn', true);
        if (!$txn) continue;

        $result = $gateway->verify_payment($txn);

        if (!empty($result['data']['status'])) {
            $status = strtoupper($result['data']['status']);

            if ($status === 'SUCCESS') {
                $order->payment_complete();
                $order->add_order_note('Payment approved by customer (CRON check).');
            }

            if (in_array($status, ['FAILED', 'REJECTED', 'CANCELLED'])) {
                $order->update_status('failed', 'Customer did not approve payment.');
            }
        }
    }
});

if (!wp_next_scheduled('moneyunify_cron_verify')) {
    wp_schedule_event(time(), 'five_minutes', 'moneyunify_cron_verify');
}

add_filter('cron_schedules', function ($schedules) {
    $schedules['five_minutes'] = [
        'interval' => 300,
        'display' => 'Every 5 Minutes'
    ];
    return $schedules;
});

/*--------------------------------------------------------------
 ADMIN META BOX
--------------------------------------------------------------*/
add_action('add_meta_boxes', function () {
    add_meta_box('moneyunify_meta', 'MoneyUnify Transaction', function ($post) {
        echo '<p><strong>Transaction ID:</strong><br>' .
            esc_html(get_post_meta($post->ID, '_moneyunify_txn', true)) . '</p>';
        echo '<p><strong>Phone:</strong><br>' .
            esc_html(get_post_meta($post->ID, '_moneyunify_phone', true)) . '</p>';
    }, 'shop_order', 'side');
});
