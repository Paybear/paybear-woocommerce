<?php
if (!defined('ABSPATH'))
    exit; // Exit if accessed directly

/**
 * Plugin Name: Crypto Payments for WooCommerce by PayBear.io
 * Plugin URI: https://www.paybear.io/
 * Description: Allows to accept crypto payments such as Bitcoin (BTC) and Ethereum (ETH)
 * Version: 1.1
 */

add_action('plugins_loaded', 'paybear_gateway_load', 0);
add_action('init', 'paybear_shortcodes_init');
add_action('init', 'create_paybear_post_type');

add_filter('template_include', 'paybear_payment_page_template');

function paybear_payment_page_template($page_template)
{
    if (get_post_type() && get_post_type() === 'paybear_payment') {
        $dir = pathinfo($page_template, PATHINFO_DIRNAME);
        $page_template = $dir . DIRECTORY_SEPARATOR . 'page.php';
    }

    return $page_template;
}

function paybear_shortcodes_init()
{
    add_shortcode('paybear_payment_widget', 'paybear_payment_widget_shortcode');
}

function create_paybear_post_type()
{
    register_post_type('paybear_payment',
        array(
            'labels' => array(
                'name' => __('Paybear Payment'),
                'singular_name' => __('Paybear Payment')
            ),
            'public' => true,
            'has_archive' => false,
            // 'capability_type' => 'product',
            'publicly_queryable' => true,
            'exclude_from_search' => true,
            'show_in_menu' => false,
            'show_in_nav_menus' => false,
            'show_in_admin_bar' => false,
            'show_in_rest' => false,
            'hierarchical' => false,
            'supports' => array('title'),
            // 'rewrite' => array('slug' => 'checkout')
        )
    );
    flush_rewrite_rules();
}

function paybear_payment_widget_shortcode($atts = array(), $content = null, $tag = '')
{
    wp_enqueue_style('paybear-css', plugin_dir_url(__FILE__) . 'assets/form/paybear.css');
    wp_enqueue_script('paybear-js', plugin_dir_url(__FILE__) . 'assets/form/paybear.js', array('jquery'));
    wp_enqueue_script('paybear-widget-js', plugin_dir_url(__FILE__) . 'assets/form/widget.js', array('paybear-js'));

    $order_id = null;
    $order = null;
    if (isset($_GET['order_id'])) {
        $order_id = (int) $_GET['order_id'];
        $order = wc_get_order($order_id);
    }

    if (!$order_id || !$order) {
        return '';
    }

    if (!isset($_GET['key']) || $_GET['key'] !== $order->get_order_key()) {
        return '';
    }

    $gateway = WC_Gateway_Paybear::get_instance();
    $to_pay = 0;
    $total_paid = 0;
    $total_paid_fiat = 0;
    $fiat_currency = strtoupper(get_woocommerce_currency());
    $fiat_sign = get_woocommerce_currency_symbol();
    $min_overpayment_fiat = $gateway->get_option('min_overpayment');
    $max_underpayment_fiat = $gateway->get_option('max_underpayment');
    $max_underpayment = 0;
    $min_overpayment = 0;
    $overpaid = false;

    $currencies = $gateway->get_currency_json($order_id);
    if ($token = get_post_meta($order_id, 'Token Selected', true)) {
        $payments_unconfirmed = $gateway->get_unconfirmed_payments($order_id, $token);
        $rate = round($order->get_total() / get_post_meta($order_id, $token . ' total', true), 8);
        $orderTimestamp = get_post_meta($order_id, $token . ' order timestamp', true);
        $deadline = $orderTimestamp + $gateway->get_option('rate_lock_time', 15) * 60;
        if (time() > $deadline) {
            $rate = $gateway->get_exchange_rate($token);
        }

        $total_paid = array_sum($payments_unconfirmed);
        $total_paid_fiat = $total_paid * $rate;
        $to_pay = get_post_meta($order_id, $token . ' total', true);
        $max_underpayment = $max_underpayment_fiat / $rate;
        $min_overpayment = $min_overpayment_fiat / $rate;
        if ($total_paid > 0) {
            $currencies = array($gateway->get_currency_json($order_id, $token));
        }

        if ($total_paid - $to_pay > $min_overpayment) {
            $overpaid = true;
        }
    }

    $fiat_value = (float) $order->get_total();
    $status_url = $gateway->get_status_link($order_id);

    $redirect_url = $gateway->get_return_url($order);

    $status = $order->get_status();
    $payment_status = 'pending payment';
    if ($status === 'completed' || $status === 'processing') {
        $payment_status = 'paid';
    }

    if ($status === 'on-hold' && ($fiat_value - $total_paid_fiat) < $max_underpayment_fiat) {
        $payment_status = 'waiting for confirmations';
    } elseif ($status === 'on-hold' && $total_paid_fiat > 0) {
        $payment_status = 'partial payment';
    }

    /** @noinspection SuspiciousAssignmentsInspection */
    $content = '<div class="woocommerce">';
    $content .= '<h2 class="section-title section-title-normal">
                    <span class="section-title-main">Order overview</span>
                    <small>#' . $order->get_id() . '</small>
                </h2>';
    $content .= '<div class="row">';
    $content .= '<div class="col medium-3">&nbsp;</div>';
    $content .= '<div class="col medium-6">';
    $content .= '<table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
                    <tr class="woocommerce-table__line-item order_item">
                        <th>Payment status</th>
                        <td>' . ucwords($payment_status) . '</td>
                    </tr>';
    if ($token && $payment_status !== 'pending payment') {
        $content .= '<tr class="woocommerce-table__line-item order_item">
                        <th>Selected token</th>
                        <td>' . strtoupper($token) . '</td>
                    </tr>';

        $blockExplorer = null;
        $address = get_post_meta($order_id, $token . ' address', true);
        foreach ($currencies as $currency) {
            if ($currency['code'] === strtoupper($token)) {
                $blockExplorer = sprintf($currency['blockExplorer'], $address);
            }
        }

        if ($address && $blockExplorer) {
            $content .= '<tr class="woocommerce-table__line-item order_item">
                            <th>Payment address</th>
                            <td><a href="' . $blockExplorer . '" target="_blank">' . $address . '</a></td>
                        </tr>';
        }
    }

    if (!$overpaid) {
        $content .= '<tr class="woocommerce-table__line-item order_item">
                         <th>Total</th>
                         <td>' . wc_price($fiat_value) . '</td>
                     </tr>';
    }
    if ($total_paid > 0 && !$overpaid) {
        $content .= '<tr class="woocommerce-table__line-item order_item">
                         <th>Paid</th>
                         <td>' . wc_price($total_paid_fiat) . '</td>
                     </tr>';
        if (($to_pay - $total_paid) > $max_underpayment) {
            $content .= '<tr class="woocommerce-table__line-item order_item">
                             <th>To pay</th>
                             <td>' . wc_price($fiat_value - $total_paid_fiat) . '</td>
                         </tr>';
        }
    }
    $content .= '</table>';

    $content .= '<div class="text-right">';
    if (!in_array($payment_status, array('waiting for confirmations', 'paid'))) {
        $content .= '<a href="#" class="button" id="paybear-all">Pay with Crypto</a>';
        $content .= '<div id="paybear" data-autoopen="true" style="display: none" data-fiat-value="' . $fiat_value . '"
                                                         data-currencies="' . esc_html(json_encode($currencies)) . '"
                                                         data-status="' . $status_url . '"
                                                         data-redirect="' . $redirect_url . '"
                                                         data-fiat-currency="' . $fiat_currency . '"
                                                         data-fiat-sign="' . $fiat_sign . '"
                                                         data-min-overpayment-fiat="' . $min_overpayment_fiat . '"
                                                         data-max-underpayment-fiat="' . $max_underpayment_fiat . '">';
        $content .= file_get_contents(plugin_dir_path(__FILE__) . 'assets/form/index.html');
    }
    if ($payment_status === 'waiting for confirmations') {
        $content .= '<a href="" class="button">Refresh</a>';
    }

    if ($payment_status === 'paid') {
        $content .= '<a href="' . $redirect_url . '" class="button">Continue</a>';
    }
    $content .= '</div>';

    $content .= '</div>';
    $content .= '</div>';

    return $content;
}

function paybear_gateway_load()
{

    if (!class_exists('WC_Payment_Gateway')) {
        // oops!
        return;
    }

    /**
     * Add the gateway to WooCommerce.
     */
    add_filter('woocommerce_payment_gateways', 'woocommerce_paybear_add_gateway');

    function woocommerce_paybear_add_gateway($methods)
    {
        if (!in_array('WC_Gateway_Paybear', $methods, true)) {
            $methods[] = WC_Gateway_Paybear::get_instance();
        }

        return $methods;
    }

    /**
     * Crypto Payments for WooCommerce by PayBear.io
     *
     * @class        WC_Gateway_Paybear
     * @extends        WC_Gateway_Paybear
     * @version        1.1
     * @package        WooCommerce/Classes/Payment
     */
    class WC_Gateway_Paybear extends WC_Payment_Gateway
    {

        const API_DOMAIN = 'https://api.paybear.io';
        const API_DOMAIN_TEST = 'http://test.paybear.io';

        /**
         * @var WC_Gateway_Paybear The reference the *Singleton* instance of this class
         */
        private static $instance;

        public static $log;

        /** @var null|array */
        public static $currencies = null;

        /**
         * Returns the *Singleton* instance of this class.
         *
         * @return WC_Gateway_Paybear The *Singleton* instance.
         */
        public static function get_instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        /**
         * @param $order_id
         * @param $token
         *
         * @return array|mixed|object
         */
        public function get_unconfirmed_payments($order_id, $token)
        {
            $paymentsUnconfirmed = json_decode(get_post_meta($order_id, $token . ' unconfirmed balance', true), true);
            if (!$paymentsUnconfirmed) {
                $paymentsUnconfirmed = array();
            }

            return $paymentsUnconfirmed;
        }

        /**
         * @param $order_id
         * @param $token
         *
         * @return array|mixed|object
         */
        public function get_confirmed_payments($order_id, $token)
        {
            $paymentsConfirmed = json_decode(get_post_meta($order_id, $token . ' confirmed balance', true), true);
            if (!$paymentsConfirmed) {
                $paymentsConfirmed = array();
            }

            return $paymentsConfirmed;
        }

        /**
         * Private clone method to prevent cloning of the instance of the
         * *Singleton* instance.
         *
         * @return void
         */
        private function __clone()
        {
        }

        /**
         * Private unserialize method to prevent unserializing of the *Singleton*
         * instance.
         *
         * @return void
         */
        private function __wakeup()
        {
        }


        protected function __construct()
        {
            $plugin_url = plugin_dir_url(__FILE__);

            $this->id = 'paybear';
            $this->assetDir = $plugin_url . 'assets/form/';
            $this->enabled = $this->get_option('enabled');
            $this->has_fields = false;
            $this->method_title = __('PayBear', 'woocommerce');

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            add_action('admin_notices', array($this, 'process_admin_notices'));
            add_action('plugins_loaded', array($this, 'init'));
        }

        public function get_icon()
        {
            $style = version_compare(WC()->version, '2.6', '>=') ? 'style="margin-left: 0.5em; max-height: 24px; max-width: 24px; float: left"' : '';

            $currencies = $this->get_currencies();
            $icon = '<span style="float: right">';

            foreach (array_slice($currencies, 0, 5) as $currency) {
                $icon .= '<img src="' . $currency['icon'] . '" ' . $style . '>';
            }
            $icon .= '</span>';

            return $icon;
        }

        public function init()
        {
            // Define user set variables
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->merchant_id = $this->get_option('merchant_id');
            $this->ipn_secret = $this->get_option('ipn_secret');
            $this->debug_email = $this->get_option('debug_email');

            $this->form_submission_method = $this->get_option('form_submission_method') === 'yes';

            // Actions
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'thankyou_page'));
            add_filter('woocommerce_thankyou_order_received_text', array($this, 'order_received_text'), 10, 2);


            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));
            add_filter('plugin_action_links_paybear-crypto-payment-gateway-for-woocommerce/class-wc-gateway-paybear.php', array($this, 'plugin_action_links'));


            // Payment listener/API hook
            add_action('woocommerce_api_wc_gateway_paybear', array($this, 'check_ipn_response'));

            //Disable PayBear if all Cryptocurrencies are disabled
            add_filter('woocommerce_available_payment_gateways', array($this, 'available_gateways'));

            add_filter('autoptimize_filter_js_exclude', array($this, 'autoptimize_filter'), 10, 1);
            self::plugin_activation();
        }

        public function autoptimize_filter($exclude)
        {
            return $exclude . ', paybear.js';
        }

        public function is_available()
        {
            $is_available = ('yes' === $this->enabled);

            if (WC()->cart && 0 < $this->get_order_total()) {
                $cart_total = $this->get_order_total();
                $is_available = false;
                foreach ($this->get_currencies() as $token => $currency) {
                    $rate = $this->get_exchange_rate($token);
                    if ($rate > 0) {
                        $amount = round($cart_total / $rate, 8);
                        if ($amount >= $currency['minimum']) {
                            $is_available = true;
                            break;
                        }
                    } else {
                        self::log('No exchange rate for ' . $token);
                    }
                }
            }

            return $is_available;
        }

        public function get_currencies()
        {
            if (self::$currencies === null)
                $this->init_currencies();

            return self::$currencies;
        }

        public function available_gateways($available_gateways)
        {
            if (isset($available_gateways['paybear']) && !$this->is_available()) {
                unset($available_gateways['paybear']);
                self::log('No currencies');
            }

            return $available_gateways;
        }


        public function token_minimum($token)
        {
            $token = $this->sanitize_token($token);
            $currencies = $this->get_currencies();

            return isset($currencies[$token]) ? $currencies[$token]['minimum'] : null;

        }

        function get_confirmations($token)
        {
            $token = $this->sanitize_token($token);
            $currencies = $this->get_currencies();

            return isset($currencies[$token]) ? $currencies[$token]['maxConfirmations'] : 3;
        }

        public function api_domain()
        {
            if (false) {
                return self::API_DOMAIN_TEST;
            }

            return self::API_DOMAIN;
        }

        /**
         * Adds plugin action links
         *
         */
        public function plugin_action_links($links)
        {
            $setting_link = $this->get_setting_link();

            $plugin_links = array(
                '<a href="' . $setting_link . '">' . __('Settings', 'woocommerce-gateway-paybear') . '</a>',
                '<a href="https://github.com/Paybear/paybear-samples">' . __('Docs', 'woocommerce-gateway-paybear') . '</a>',
                '<a href="https://www.paybear.io/">' . __('Support', 'woocommerce-gateway-paybear') . '</a>',
            );

            return array_merge($plugin_links, $links);
        }


        /**
         * Get setting link.
         *
         * @return string Setting link
         */
        public function get_setting_link()
        {
            $use_id_as_section = function_exists('WC') ? version_compare(WC()->version, '2.6', '>=') : false;

            $section_slug = $use_id_as_section ? 'paybear' : strtolower('WC_Gateway_Paybear');

            return admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $section_slug);
        }

        public function get_address_link($order_id, $token = 'all')
        {
            return add_query_arg(array('wc-api' => 'WC_Gateway_Paybear', 'address' => $token, 'order_id' => $order_id), home_url('/'));
        }

        public function get_ipn_link($order_id)
        {
            return add_query_arg(array('wc-api' => 'WC_Gateway_Paybear', 'order_id' => $order_id), home_url('/'));
        }

        public function get_status_link($id)
        {
            return add_query_arg(array('wc-api' => 'WC_Gateway_Paybear', 'status' => $id), home_url('/'));
        }


        public function process_admin_notices()
        {
            static $checked = false;

            if ($checked)
                return; else $checked = true;

            //check currency
            if (!$this->get_exchange_rate('eth')) {
                echo '<div class="error"><p>' . sprintf(__('PayBear plugin could not load the conversion rates, please check if your currency (%1$s) is supported.', 'woocommerce'), get_woocommerce_currency()) . '</p></div>';
            }

            //check API keys
            if (!$this->get_option('api_secret') || substr($this->get_option('api_secret'), 0, 3) != 'sec') {
                echo '<div class="error"><p>' . sprintf(__('Please set your API keys in <a href="%1$s">PayBear Settings</a>', 'woocommerce'), $this->get_setting_link()) . '</p></div>';
            }
        }

        public function init_currencies()
        {
            $secret = $this->get_option('api_secret');
            if (!$secret)
                return false;

            self::$currencies = array();

            $url = $this->api_domain() . sprintf("/v2/currencies?token=%s", $secret);
            if ($response = wp_remote_fopen($url)) {
                $response = json_decode($response, true);
                if (isset($response) && $response['success']) {
                    self::$currencies = $response['data'];
                }
            }

            return true;
        }


        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */
        function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable Crypto Payments', 'woocommerce'),
                    'default' => 'yes'
                ),
                'api_secret' => array(
                    'title' => __('API Key (Secret)', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Starts with "sec". Sign up at https://www.paybear.io/ and get your key', 'woocommerce'),
                ),
                'api_public' => array(
                    'title' => __('API Key (Public)', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Starts with "pub". Sign up at https://www.paybear.io/ and get your key', 'woocommerce'),
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default' => __('PayBear', 'woocommerce'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                    'default' => __('Pay with 7+ cryptocurrencies', 'woocommerce')
                ),

                'min_overpayment' => array(
                    'title' => sprintf(__('Overpayment (%s)', 'woocommerce'), strtoupper(get_woocommerce_currency())),
                    'type' => 'text',
                    'description' => __('The client will be notified about their overpayment if it is greater than this amount. You will then need to issue the overpayment refund.', 'woocommerce'),
                    'default' => '1'
                ),

                'max_underpayment' => array(
                    'title' => sprintf(__('Underpayment (%s)', 'woocommerce'), strtoupper(get_woocommerce_currency())),
                    'type' => 'text',
                    'description' => __('The client will be notified and required to pay the balance owed for underpayments greater than this specified amount.', 'woocommerce'),
                    'default' => '0.01'
                ),

                'debug_email' => array(
                    'title' => __('Debug Email', 'woocommerce'),
                    'type' => 'email',
                    'default' => '',
                    'description' => __('Send copies of invalid IPNs to this email address.', 'woocommerce'),
                ),
                'rate_lock_time' => array(
                    'title' => __('Exchange Rate Lock Time', 'woocommerce'),
                    'type' => 'text',
                    'description' => __("Lock Fiat to Crypto exchange rate for this long (in minutes, 15 is the recommended minimum)", 'woocommerce'),
                    'default' => '15'
                )

            );

        }


        /**
         * Process the payment and return the result
         *
         * @access public
         *
         * @param int $order_id
         *
         * @return array
         */
        function process_payment($order_id)
        {

            $order = wc_get_order($order_id);
            $paybear_page_id = $this->get_option('payment_page_id');
            $redirect = add_query_arg(array('order_id' => $order->get_id(), 'key' => $order->get_order_key()), get_permalink($paybear_page_id));

            return array(
                'result' => 'success',
                'redirect' => $redirect
            );

        }

        function thankyou_page($order_id)
        {
            return '';
        }

        /**
         * Order received page.
         *
         * @access public
         * @return void
         */
        function thankyou_page2($order_id)
        {
            $order = wc_get_order($order_id);
            $status = $order->get_status();
            $underpaid = false;

            $str = '';

            if ($status == 'on-hold') {
                $str .= '<p>' . __('Waiting for payment confirmation.', 'woocommerce') . '</p>';
                $str .= '<p>' . __('Once your payment is confirmed, your order will be processed automatically.', 'woocommerce') . '</p>';

                $underpaid = false;
                if ($token = get_post_meta($order_id, 'Token Selected', true)) {
                    $paymentsUnconfirmed = $this->get_unconfirmed_payments($order_id, $token);
                    $totalPaid = array_sum($paymentsUnconfirmed);
                    $toPay = get_post_meta($order_id, $token . ' total', true);
                    if ($totalPaid < $toPay) {
                        $underpaid = true;
                    }
                }


            }

            if ($status == 'pending' || $status == 'failed') {
                $str .= '<p>' . __('Once your payment is confirmed, your order will be processed automatically.', 'woocommerce') . '</p>';
            }

            return $str;
        }

        function order_received_text($str, $order_id)
        {
            $order = wc_get_order($order_id);
            $status = $order->get_status();
            /** @noinspection SuspiciousAssignmentsInspection */
            $str = '';

            if ($status == 'pending') {
                $str = $str . ' ' . __('Thank you. Please select the option below to pay.');
            }

            return $str . $this->thankyou_page2($order->get_id());
        }


        function process_ipn($order_id, $params)
        {
            $invoice = $params->invoice;

            $order_id = intval($order_id);
            $order = wc_get_order($order_id);

            self::log('Order status: ' . $order->get_status());

            if (!$order || (!in_array($order->get_status(), array('pending', 'on-hold', 'failed', 'cancelled'))))
                wp_send_json($invoice); //stop processing this order

            self::log(print_r($params, true));
            $currencies = $this->get_currencies();
            $token = $this->sanitize_token($params->blockchain);
            $tokenCode = strtoupper($token);
            if (isset($currencies[$params->blockchain])) {
                $tokenCode = $currencies[$params->blockchain]['code'];
            }


            update_post_meta($order_id, 'Token Selected', $token);
            $maxConfirmations = $params->maxConfirmations;
            if (!is_numeric($maxConfirmations))
                $maxConfirmations = $this->get_confirmations($token);
            update_post_meta($order_id, $token . ' max_confirmations', $maxConfirmations);


            if ($invoice == get_post_meta($order_id, $token . ' invoice', true)) {
                if ($order->get_status() != 'on-hold') {
                    // Mark as on-hold
                    $order->update_status('on-hold', __('Awaiting payment confirmation', 'woocommerce'));
                }

                $hash = $params->inTransaction->hash;

                $toPay = get_post_meta($order_id, $token . ' total', true);
                $maxDifference = $this->get_max_underpayment() / $this->get_exchange_rate($token);
                $maxDifference = max($maxDifference, 0.00000001); //always allow rounding errors
                $exp = $params->inTransaction->exp;
                $amountPaid = $params->inTransaction->amount / pow(10, $exp); //amount in Crypto


                $confirmations = json_decode(get_post_meta($order_id, $token . ' confirmation', true), true);
                if (!$confirmations)
                    $confirmations = array();
                $paymentsUnconfirmed = $this->get_unconfirmed_payments($order_id, $token);

                if (isset($paymentsUnconfirmed[$hash])) {
                    $transactionIndex = array_search($hash, array_keys($paymentsUnconfirmed));
                    if ($transactionIndex > 0)
                        usleep($transactionIndex * 500); //avoid race conditions
                }

                $paymentsConfirmed = $this->get_confirmed_payments($order_id, $token);

                $isNewPayment = !isset($paymentsUnconfirmed[$hash]);
                $confirmations[$hash] = $params->confirmations;
                $paymentsUnconfirmed[$hash] = $amountPaid;

                update_post_meta($order_id, $token . ' confirmation', json_encode($confirmations));
                update_post_meta($order_id, $token . ' unconfirmed balance', json_encode($paymentsUnconfirmed));

                $orderTimestamp = get_post_meta($order_id, $token . ' order timestamp', true);
                //$paymentTimestamp = get_post_meta($order_id, $token . ' payment timestamp', true);
                $deadline = $orderTimestamp + $this->get_option('rate_lock_time', 15) * 60;


                //$timestamp = get_post_meta($order_id, $token . ' payment timestamp', true);
                if ($isNewPayment) {
                    if (time() > $deadline) { //rate changed. recalculate crypto total?
                        self::log("PayBear IPN: late payment [" . $order_id . "]");
                        self::log("PayBear IPN: old total [" . $toPay . "]");

                        $rate = $this->get_exchange_rate($token);
                        $fiatTotal = $order->get_total();
                        if ($rate && $fiatTotal > 0) {
                            $newCryptoTotal = round($fiatTotal / $rate, 8);
                            if (true || $newCryptoTotal > $toPay) {
                                $toPay = $newCryptoTotal;
                                $this->update_crypto_total($token, $order_id, $toPay);

                                self::log("PayBear IPN: new total [" . $toPay . "]");
                            }
                        }
                    }

                    update_post_meta($order_id, $token . ' payment timestamp', time());
                }

                if ($params->confirmations >= $maxConfirmations) { //enough confirmations for this payment
                    $paymentsConfirmed[$hash] = $amountPaid;
                    update_post_meta($order_id, $token . ' confirmed balance', json_encode($paymentsConfirmed));

                    $totalPaid = array_sum($paymentsConfirmed);

                    self::log("PayBear IPN: toPay [" . $toPay . "]");
                    self::log("PayBear IPN: paid [" . $amountPaid . "]");
                    self::log("PayBear IPN: total paid [" . $totalPaid . "]");
                    self::log("PayBear IPN: maxDifference [" . round($maxDifference, 9) . "]");


                    if ($toPay > 0 && ($toPay - $totalPaid) < $maxDifference) { //allow loss caused by rounding
                        self::log('Payment complete');
                        $order->payment_complete($params->inTransaction->hash);

                        $overpaidCrypto = $totalPaid - $toPay;
                        $overpaid = round($overpaidCrypto * $this->get_exchange_rate($token), 2);
                        $minOverpaymentFiat = $this->get_min_overpayment();
                        if ($overpaid > $minOverpaymentFiat) { //overpayment
                            $note = sprintf(
                                __('

Whoops, you overpaid %s %s (%s %s)

Don\'t worry, here is what to do next:

To get your overpayment refunded, please contact the merchant directly and share your Order ID %s and %s Address to send your refund to.


Tips for Paying with Crypto:

Tip 1) When paying, ensure you send the correct amount in %s.
Do not manually enter the %s Value.

Tip 2)  If you are sending from an exchange, be sure to correctly factor in their withdrawal fees.

Tip 3) Be sure to successfully send your payment before the countdown timer expires.
This timer is setup to lock in a fixed rate for your payment. Once it expires, rates may change.', 'woocommerce'),
                                $overpaidCrypto, strtoupper($tokenCode), get_woocommerce_currency_symbol(), $overpaid, $order_id, strtoupper($tokenCode), strtoupper($tokenCode), get_woocommerce_currency());
                            $order->add_order_note($note, 1, false);
                        }
                    } else { //underpayment
                        self::log("PayBear IPN: underpayment [" . $order_id . "]");
                        //if (!empty($this->debug_email)) { mail($this->debug_email, "PayBear IPN: wrong amount [" . $order_id . "]", print_r($params, true)); }

                        $order->update_status('on-hold');
                        $underpaidCrypto = $toPay - $totalPaid;
                        $underpaid = round($underpaidCrypto * $this->get_exchange_rate($token), 2);
                        $note = sprintf(__('Looks like you underpaid %s %s (%s %s)
 
Don\'t worry, here is what to do next:
 
Contact the merchant directly and…
-Request details on how you can pay the difference.
-Request a refund and create a new order.
 
 
Tips for Paying with Crypto:
 
Tip 1) When paying, ensure you send the correct amount in %s.
Do not manually enter the %s Value.
 
Tip 2)  If you are sending from an exchange, be sure to correctly factor in their withdrawal fees.
 
Tip 3) Be sure to successfully send your payment before the countdown timer expires.
This timer is setup to lock in a fixed rate for your payment. Once it expires, rates may change.', 'woocommerce'),
                            $underpaidCrypto, strtoupper($tokenCode), get_woocommerce_currency_symbol(), $underpaid, strtoupper($tokenCode), get_woocommerce_currency());
                        $order->add_order_note($note, 1, false);

                        update_post_meta($order_id, $token . ' order timestamp', time()); //extend payment window
                    }

                    wp_send_json($invoice); //stop processing callbacks
                }

                self::log(sprintf('Callback processed: %s/%s', $params->confirmations, $maxConfirmations));
            } else {
                self::log("PayBear IPN: wrong invoice [" . $order_id . ' / ' . get_post_meta($order_id, $token . ' invoice', true) . "]");
                if (!empty($this->debug_email)) {
                    mail($this->debug_email, "PayBear IPN: wrong invoice [" . $invoice . "]", print_r($params, true));
                }
            }

            wp_send_json('WAITING');
        }

        /**
         * Check for IPN Response
         *
         * @access public
         * @return void
         */
        function check_ipn_response()
        {

            @ob_clean();
            if (isset($_GET['address']) && isset($_GET['order_id'])) { //address request
                $json = $this->get_currency_json($_GET['order_id'], $_GET['address']);
                wp_send_json($json);
            }

            if (isset($_GET['status'])) { //address request
                return $this->get_status($_GET['status']);
            }

            self::log("PayBear IPN:" . print_r($_GET, true) . ' / ' . print_r($_POST, true));

            if (isset($_GET['order_id'])) {
                $order_id = intval($_GET['order_id']);
                $data = file_get_contents('php://input');
                self::log("PayBear IPN data:" . $data);
                if ($data) {
                    $params = json_decode($data);
                    if ($params) {
                        $this->process_ipn($order_id, $params);
                    }
                }

            } else {
                wp_die("PayBear IPN Request Failure");
            }
        }

        function get_json($order_id)
        {
            $order_id = intval($order_id);
            $order = wc_get_order($order_id);
            $value = $order->get_total();

            $currencies = $this->get_currency_json($order_id);

            if ($token = get_post_meta($order_id, 'Token Selected', true)) {
                $paymentsUnconfirmed = $this->get_unconfirmed_payments($order_id, $token);
                $totalPaid = array_sum($paymentsUnconfirmed);
                if ($totalPaid > 0) {
                    $currencies = array($this->get_currency_json($order_id, $token));
                }
            }

            $response = array(
                'button' => '#paybear-all',
                'modal' => true,
                'currencies' => $currencies,
                //'currenciesUrl' => $this->get_address_link($order_id),
                'fiatValue' => doubleval($value),
                'fiatCurrency' => strtoupper(get_woocommerce_currency()),
                'fiatSign' => get_woocommerce_currency_symbol(),
                'maxUnderpaymentFiat' => 1,
                'minOverpaymentFiat' => 10,
                'enableFiatTotal' => true,
                'enablePoweredBy' => false,
                'statusUrl' => $this->get_status_link($order_id),
                'redirectTo' => $this->get_return_url($order),
                'redirectTimeout' => 0,
                'timer' => $this->get_option('rate_lock_time', 15) * 60
            );

            return $response;
        }

        function get_currency_json($order_id, $token = 'all')
        {
            $order_id = intval($order_id);
            $order = wc_get_order($order_id);
            $value = $order->get_total();
            $token = $this->sanitize_token($token);

            $currencies = array();

            foreach ($this->get_currencies() as $code => $currency) {
                $rate = $this->get_exchange_rate($code);
                if ($rate) {
                    $amount = round($value / $rate, 8);
                    if ($amount >= $currency['minimum']) {
                        if ($token == 'all') {
                            $currency['currencyUrl'] = $this->get_address_link($order_id, $code);
                            $currency['coinsValue'] = $amount;
                            $currency['rate'] = round($rate, 2);

                            $currencies[] = $currency;
                        } elseif ($token == $code) {
                            $paymentsUnconfirmed = $this->get_unconfirmed_payments($order_id, $token);
                            $unconfirmedTotal = array_sum($paymentsUnconfirmed);

                            $address = $this->get_address($code, $order_id, $amount);
                            $currency['coinsValue'] = $amount;
                            $currency['coinsPaid'] = $unconfirmedTotal;
                            $currency['rate'] = round($rate, 2);
                            $currency['maxConfirmations'] = $this->get_confirmations($code);
                            $currency['address'] = $address;

                            $currencies[] = $currency;
                        }
                    }
                }
            }


            if ($token != 'all') {
                update_post_meta($order_id, 'Token Selected', $token);

                return count($currencies) > 0 ? current($currencies) : array();
            } else {
                return $currencies;
            }

        }

        function get_status($order_id)
        {
            $order_id = intval($order_id);
            $order = wc_get_order($order_id);

            $response = array();

            $status = $order->get_status();

            if ($order && ($status == 'on-hold' || $status == 'processing' || $status == 'failed')) {

                $token = get_post_meta($order_id, 'Token Selected', true);
                if ($token) {
                    $toPay = get_post_meta($order_id, $token . ' total', true);

                    $maxDifference = $this->get_max_underpayment() / $this->get_exchange_rate($token);
                    $maxDifference = max($maxDifference, 0.00000001); //always allow rounding errors

                    $maxConfirmations = get_post_meta($order_id, $token . ' max_confirmations', true);

                    $confirmations = json_decode(get_post_meta($order_id, $token . ' confirmation', true), true);
                    if (!$confirmations)
                        $confirmations = array();
                    $paymentsUnconfirmed = $this->get_unconfirmed_payments($order_id, $token);
                    $paymentsConfirmed = $this->get_confirmed_payments($order_id, $token);

                    $totalConfirmations = min($confirmations);
                    $totalUnconfirmed = array_sum($paymentsUnconfirmed);
                    $totalConfirmed = array_sum($paymentsConfirmed);

                    $response['success'] = ($totalConfirmations >= $maxConfirmations) && ($totalConfirmed > ($toPay - $maxDifference));

                    $response['coinsPaid'] = $totalUnconfirmed;

                    if (!empty($paymentsUnconfirmed))
                        $response['confirmations'] = $totalConfirmations;
                }
            }

            wp_send_json($response);
        }


        public static function log($message)
        {
            if (empty(self::$log)) {
                self::$log = wc_get_logger();
            }

            self::$log->add('woocommerce-gateway-paybear', $message);
        }

        function get_exchange_rate($token)
        {
            static $cache;

            $token = strtolower($token);

            if (!$cache || !isset($cache->$token)) {
                $currency = strtolower(get_woocommerce_currency());
                $url = $this->api_domain() . sprintf("/v2/exchange/%s/rate", $currency);

                if ($response = wp_remote_fopen($url)) {
                    $response = json_decode($response);
                    if ($response->success) {
                        $cache = $response->data;
                    }
                } else {
                    $error = error_get_last();
                    self::log("Cannot get rates: " . print_r($error, true));
                }
            }

            $rate = isset($cache->$token->mid) ? $cache->$token->mid : null;
            $shop_currency = strtolower(get_woocommerce_currency());

            if (isset($cache->$shop_currency)) {
                return $cache->$token->mid / $cache->$shop_currency->mid;
            }

            return $rate;
        }

        public function update_crypto_total($token, $order_id, $total)
        {
            $token = $this->sanitize_token($token);

            /*
            if ($total===false) {
                $rate = $this->get_exchange_rate($token);
                $order = wc_get_order($order_id);
                $value = $order->get_total();
                if ( $rate && $value>0 ) {
                    $total = round( $value / $rate, 8 );
                }
            }
            */

            if ($total > 0) {
                update_post_meta($order_id, $token . ' total', $total);
                update_post_meta($order_id, $token . ' order timestamp', time());
            }

            return $total;

        }

        public function get_address($token, $order_id, $total)
        {
            $token = $this->sanitize_token($token);

            //return '0xTESTJKADHFJKDHFJKSDFSDF';

            if ($address = get_post_meta($order_id, $token . ' address', true)) {
                $this->update_crypto_total($token, $order_id, $total);

                return $address;
            }

            $secret = $this->get_option('api_secret');

            $callbackUrl = $this->get_ipn_link($order_id);
            //$callbackUrl = 'http://demo.paybear.io/ojosidfjsdf';

            $url = sprintf($this->api_domain() . '/v2/%s/payment/%s?token=%s', $token, urlencode($callbackUrl), $secret);
            self::log("PayBear address request: " . $url);
            if ($contents = wp_remote_fopen($url)) {
                $response = json_decode($contents);
                self::log("PayBear address response: " . print_r($response, true));
                if (isset($response->data->address)) {
                    $address = $response->data->address;

                    update_post_meta($order_id, $token . ' address', $address);
                    update_post_meta($order_id, $token . ' invoice', $response->data->invoice);

                    $this->update_crypto_total($token, $order_id, $total);

                    return $address;
                } else {
                    self::log("PayBear API responded: " . $contents);
                }
            }
        }

        public function sanitize_token($token)
        {
            $token = strtolower($token);
            $token = preg_replace('/[^a-z0-9:]/', '', $token);

            return $token;
        }

        private function get_min_overpayment()
        {
            return doubleval($this->get_option('min_overpayment', 1));
        }

        private function get_max_underpayment()
        {
            return doubleval($this->get_option('max_underpayment', 0.01));
        }

        public static function plugin_activation()
        {
            $self = self::get_instance();

            $self->settings['payment_page_id'] = null;
            update_option($self->get_option_key(), apply_filters('woocommerce_settings_api_sanitized_fields_' . $self->id, $self->settings));

            $page_id = $self->get_option('payment_page_id');

            if ($page_id && get_post_type($page_id) != 'paybear_payment') {
                wp_delete_post($page_id);
            }

            if (!$page_id) {
                add_action('init', function () use ($self) {
                    $guid = home_url('/paybear_payment/paybear');

                    $page_id = $self::get_post_id_from_guid($guid);
                    if (!$page_id) {
                        $page_data = array(
                            'post_status' => 'publish',
                            'post_type' => 'paybear_payment',
                            // 'post_author'    => 1,
                            // 'post_name'      => 'paybear',
                            'post_title' => 'Paybear',
                            'post_content' => '[paybear_payment_widget]',
                            // 'post_parent'    => $parent_id,
                            'comment_status' => 'closed',
                            'guid' => $guid,
                        );
                        $page_id = wp_insert_post($page_data);
                    }

                    $self->settings['payment_page_id'] = $page_id;
                    update_option($self->get_option_key(), apply_filters('woocommerce_settings_api_sanitized_fields_' . $self->id, $self->settings));
                });
            }
        }


        public static function plugin_deactivation()
        {
            $post_id = self::get_instance()->get_option('payment_page_id');
            if ($post_id) {
                wp_delete_post($post_id, true);
            }
        }

        public static function get_post_id_from_guid($guid)
        {
            global $wpdb;

            return $wpdb->get_var($wpdb->prepare("SELECT id FROM $wpdb->posts WHERE guid=%s", $guid));

        }

    }

    class WC_Paybear extends WC_Gateway_Paybear
    {
        public function __construct()
        {
            _deprecated_function('WC_Paybear', '1.4', 'WC_Gateway_Paybear');
            parent::__construct();
        }
    }

    $GLOBALS['wc_paybear'] = WC_Gateway_Paybear::get_instance();
}

register_activation_hook(__FILE__, function () {

});
register_deactivation_hook(__FILE__, array('WC_Gateway_Paybear', 'plugin_deactivation'));
