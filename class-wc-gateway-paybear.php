<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Plugin Name: Crypto Payment Gateway for WooCommerce by PayBear.io
 * Plugin URI: https://www.paybear.io/
 * Description: Allows to accept crypto payments such as Bitcoin (BTC) and Ethereum (ETH)
 * Version: 0.9
 */

add_action( 'plugins_loaded', 'paybear_gateway_load', 0 );
function paybear_gateway_load() {

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        // oops!
        return;
    }

    /**
     * Add the gateway to WooCommerce.
     */
    add_filter( 'woocommerce_payment_gateways', 'woocommerce_paybear_add_gateway' );

    function woocommerce_paybear_add_gateway( $methods ) {
    	if (!in_array('WC_Gateway_Paybear', $methods)) {
				$methods[] = WC_Gateway_Paybear::get_instance();
			}
			return $methods;
    }

    /**
     * Crypto Payment Gateway for WooCommerce by PayBear.io
     *
     * @class 		WC_Gateway_Paybear
     * @extends		WC_Gateway_Paybear
     * @version		0.9
     * @package		WooCommerce/Classes/Payment
     */
class WC_Gateway_Paybear extends WC_Payment_Gateway {

	const API_DOMAIN = 'https://api.paybear.io';
	const API_DOMAIN_TEST = 'http://test.paybear.io';

	/**
	 * @var Singleton The reference the *Singleton* instance of this class
	 */
	private static $instance;

	public static $log;

	public static $currencies = null;

	/**
	 * Returns the *Singleton* instance of this class.
	 *
	 * @return Singleton The *Singleton* instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private clone method to prevent cloning of the instance of the
	 * *Singleton* instance.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Private unserialize method to prevent unserializing of the *Singleton*
	 * instance.
	 *
	 * @return void
	 */
	private function __wakeup() {}



	protected function __construct() {
		$plugin_url = plugin_dir_url( __FILE__ );

		$this->id           = 'paybear';
		$this->icon         = apply_filters( 'woocommerce_paybear_icon', $plugin_url.'assets/images/icons/crypto.png' );
		$this->assetDir     = $plugin_url.'assets/form/';
		$this->enabled      = $this->get_option( 'enabled' );
		$this->has_fields   = false;
		$this->method_title = __( 'Crypto Payments via PayBear.io', 'woocommerce' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		add_action( 'admin_notices', array( $this, 'process_admin_notices' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function init() {

		// Define user set variables
		$this->title 			= $this->get_option( 'title' );
		$this->description 		= $this->get_option( 'description' );
		$this->merchant_id 			= $this->get_option( 'merchant_id' );
		$this->ipn_secret   = $this->get_option( 'ipn_secret' );
		$this->debug_email			= $this->get_option( 'debug_email' );


		$this->form_submission_method = $this->get_option( 'form_submission_method' ) == 'yes' ? true : false;

		// Actions
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'thankyou_page' ) );
		add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'order_received_text' ), 10, 2 );


		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
		add_filter( 'plugin_action_links_paybear-crypto-payment-gateway-for-woocommerce/class-wc-gateway-paybear.php', array( $this, 'plugin_action_links' ) );


		// Payment listener/API hook
		add_action( 'woocommerce_api_wc_gateway_paybear', array( $this, 'check_ipn_response' ) );

		//Disable PayBear if all Cryptocurrencies are disabled
		add_filter( 'woocommerce_available_payment_gateways', array($this, 'available_gateways') );

    }

    function is_available()
    {
	    $is_available = ( 'yes' === $this->enabled );

	    if ( WC()->cart && 0 < $this->get_order_total() ) {

		    $cart_total = $this->get_order_total();

		    $is_available = false;
		    foreach($this->get_currencies() as $token => $currency) {
			    if ($this->token_enabled($token)) {
				    $rate = $this->get_exchange_rate($token);
				    if ($rate>0) {
					    $amount = round($cart_total/$rate, 8);
					    if ($amount >= $currency['minimum']) {
						    $is_available = true;
						    break;
					    }
				    } else {
					    self::log('No exchange rate for ' . $token);
				    }
			    }
		    }
	    }

	    return $is_available;
    }

    function get_currencies()
    {
        if (self::$currencies===null) $this->init_currencies();
        return self::$currencies;
    }

	function available_gateways( $available_gateways )
    {
		if ( isset( $available_gateways['paybear'] ) ) {

			if (!$this->is_available()) {
				unset( $available_gateways['paybear'] );
				self::log('No currencies');
			}

		}

		return $available_gateways;
	}



    function token_enabled($token)
    {
        $key = strtolower($token.'_enable');
	    return ($this->get_option( $key )=='yes') ? true : false;
    }

    function token_minimum($token)
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

    function api_domain()
    {
	    if (false) { //testnet is not yet available
	        return self::API_DOMAIN_TEST;
        } else {
		    return self::API_DOMAIN;
        }
    }

    /**
     * Adds plugin action links
     *
     */
    public function plugin_action_links( $links ) {
        $setting_link = $this->get_setting_link();

        $plugin_links = array(
            '<a href="' . $setting_link . '">' . __( 'Settings', 'woocommerce-gateway-paybear' ) . '</a>',
            '<a href="https://github.com/Paybear/paybear-samples">' . __( 'Docs', 'woocommerce-gateway-paybear' ) . '</a>',
            '<a href="https://www.paybear.io/">' . __( 'Support', 'woocommerce-gateway-paybear' ) . '</a>',
        );
        return array_merge( $plugin_links, $links );
    }


    /**
     * Get setting link.
     *
     * @return string Setting link
     */
    public function get_setting_link() {
        $use_id_as_section = function_exists( 'WC' ) ? version_compare( WC()->version, '2.6', '>=' ) : false;

        $section_slug = $use_id_as_section ? 'paybear' : strtolower( 'WC_Gateway_Paybear' );

        return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $section_slug );
    }

    public function get_address_link($order_id, $token = 'all')
    {
	    return add_query_arg( array('wc-api' => 'WC_Gateway_Paybear', 'address' => $token, 'order_id' => $order_id), home_url( '/' ) );
    }

	public function get_ipn_link($order_id)
	{
		return add_query_arg( array('wc-api' => 'WC_Gateway_Paybear', 'order_id' => $order_id), home_url( '/' ) );
	}

	public function get_status_link($id)
    {
        return add_query_arg( array('wc-api' => 'WC_Gateway_Paybear', 'status' => $id), home_url( '/' ) );
    }




	public function process_admin_notices() {
	    static $checked = false;

	    if ($checked) return; else $checked = true;

	    //check currency
        if (!$this->get_exchange_rate('eth')) {
	        echo '<div class="error"><p>' . sprintf( __( 'PayBear plugin could not load the conversion rates, please check if your currency (%1$s) is supported.', 'woocommerce' ), get_woocommerce_currency() ) . '</p></div>';
        }

        //check API keys
		if (!$this->get_option( 'api_secret' ) || substr($this->get_option( 'api_secret' ), 0, 3)!='sec') {
			echo '<div class="error"><p>' . sprintf( __( 'Please set your API keys in <a href="%1$s">PayBear Settings</a>', 'woocommerce' ), $this->get_setting_link() ) . '</p></div>';
        }
	}

    public function init_currencies()
    {
	    $secret = $this->get_option( 'api_secret' );
	    if (!$secret) return false;

	    self::$currencies = array();

	    $url = $this->api_domain() . sprintf("/v2/currencies?token=%s", $secret);
	    if ( $response = @file_get_contents( $url ) ) {
		    $response = json_decode( $response, true);
		    if ( isset($response) && $response['success'] ) {
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
							'title' => __( 'Enable/Disable', 'woocommerce' ),
							'type' => 'checkbox',
							'label' => __( 'Enable Crypto Payments', 'woocommerce' ),
							'default' => 'yes'
						),
			'api_secret' => array(
                            'title' => __( 'API Key (Secret)', 'woocommerce' ),
                            'type' => 'text',
                            'description' => __( 'Starts with "sec". Sign up at https://www.paybear.io/ and get your key', 'woocommerce' ),
                        ),
			'api_public' => array(
                            'title' => __( 'API Key (Public)', 'woocommerce' ),
                            'type' => 'text',
                            'description' => __( 'Starts with "pub". Sign up at https://www.paybear.io/ and get your key', 'woocommerce' ),
                        ),
			'title' => array(
							'title' => __( 'Title', 'woocommerce' ),
							'type' => 'text',
							'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
							'default' => __( 'Crypto Payments', 'woocommerce' ),
							'desc_tip'      => true,
						),
			'description' => array(
							'title' => __( 'Description', 'woocommerce' ),
							'type' => 'textarea',
							'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
							'default' => __( 'Bitcoin (BTC), Ethereum (ETH) and other crypto currencies', 'woocommerce' )
						),

			'debug_email' => array(
							'title' => __( 'Debug Email', 'woocommerce' ),
							'type' => 'email',
							'default' => '',
							'description' => __( 'Send copies of invalid IPNs to this email address.', 'woocommerce' ),
						),
			'rate_lock_time' => array(
                        'title' => __( 'Exchange Rate Lock Time', 'woocommerce' ),
                        'type' => 'text',
                        'description' => __( "Lock Fiat to Crypto exchange rate for this long (in minutes, 15 is the recommended minimum)", 'woocommerce' ),
                        'default' => '15'
            )

			);

    }


    /**
     * Process the payment and return the result
     *
     * @access public
     * @param int $order_id
     * @return array
     */
	function process_payment( $order_id ) {

		$order          = wc_get_order( $order_id );

		return array(
			    //'result' 	=> 'failure',
				'result' 	=> 'success',
				'redirect'	=> $this->get_return_url( $order ),
		);

	}

    /**
     * Order received page.
     *
     * @access public
     * @return void
     */
    function thankyou_page( $order_id ) {
        $order = wc_get_order($order_id);
	    $status = $order->get_status();

	    if ($status=='on-hold' ) {
		    echo '<p>' . __( 'Waiting for payment confirmation.', 'woocommerce' ) . '</p>';
		    echo '<p>' . __( 'Once your payment is confirmed, your order will be processed automatically.', 'woocommerce' ) . '</p>';
        }

	    if ($status=='pending' || $status=='failed') {
		    echo '<p>' . __( 'Once your payment is confirmed, your order will be processed automatically.', 'woocommerce' ) . '</p>';

		    $js  = $this->assetDir . 'paybear.js';
		    $css = $this->assetDir . 'paybear.css';
		    echo '<script src="' . $js . '"></script><link rel="stylesheet" href="' . $css . '">';
		    readfile( dirname( __FILE__ ) . '/assets/form/index.html' );
		    //readfile(dirname(__FILE__).'/assets/form/inline.html');

		    //$url = $this->get_address_link( $order_id );
		    //$this->payment_button( 'Pay Now', 'paybear-all', $url );

		    $json = json_encode($this->get_json($order_id));
		    ?>
            <!--<button id="paybear-all">Pay with Crypto</button>-->
            <script>
                (function () {
                    var options = <?php echo $json ?>;
                    window.paybear = new Paybear(options);
                })();
            </script>
		    <?php

	    }
    }

	function order_received_text( $str, $order_id ) {
		$order = wc_get_order($order_id);
		$status = $order->get_status();

		if ($status=='pending') {
			$str = $str . ' ' . __( 'Please select the option below to pay.' );
		}

		return $str;
	}


	function process_ipn( $order_id, $params ) {
		$invoice = $params->invoice;

		$order_id = intval($order_id);
		$order = wc_get_order($order_id);

		self::log('Order status: ' . $order->get_status());

		if (!$order || (!in_array($order->get_status(), array('pending', 'on-hold', 'failed', 'cancelled'))) ) wp_send_json($invoice); //stop processing this order

		self::log(print_r($params, true));

		//$token = get_post_meta( $order_id, 'Token Selected', true);
        $token = $params->blockchain;
		update_post_meta( $order_id, 'Token Selected', $token);
        $confirmations = $this->get_confirmations($token);
        if (!is_numeric($confirmations)) $confirmations = 3;



		if ($invoice == get_post_meta($order_id, $token . ' invoice', true)) {
		    if ($order->get_status()!='on-hold') {
			    // Mark as on-hold
			    $order->update_status( 'on-hold', __( 'Awaiting payment confirmation', 'woocommerce' ) );
		    }

			update_post_meta($order_id, $token . ' confirmations', $params->confirmations);
			update_post_meta($order_id, $token . ' max_confirmations', $confirmations);


			$timestamp = get_post_meta($order_id, $token . ' payment timestamp', true);
			if (!$timestamp) {
				update_post_meta($order_id, $token . ' payment timestamp', time());
            }

            if ($params->confirmations >= $confirmations) { //enough confirmations
                $toPay = get_post_meta($order_id, $token . ' total', true);
                $exp = $params->inTransaction->exp;
                $amountPaid = $params->inTransaction->amount / pow(10, $exp); //amount in Crypto
                $maxDifference = 0.00000001;

	            self::log("PayBear IPN: toPay [" . $toPay . "]");
                self::log("PayBear IPN: paid [" . $amountPaid . "]");
	            self::log("PayBear IPN: maxDifference [" . $maxDifference . "]");

                if ($toPay>0 && ($toPay-$amountPaid)<$maxDifference) { //allow loss caused by rounding
	                $orderTimestamp = get_post_meta($order_id, $token . ' order timestamp', true);
	                $paymentTimestamp = get_post_meta($order_id, $token . ' payment timestamp', true);
	                $deadline = $orderTimestamp + $this->get_option('rate_lock_time', 15 )*60;
	                $process = true;
	                if ($paymentTimestamp>$deadline) {
		                self::log( "PayBear IPN: late payment [" . $order_id . "]" );

		                //recalculate using current exchange rate: did we lose?
                        $fiatPaid = $amountPaid * $this->get_exchange_rate($token);
                        if ($order->get_total()<$fiatPaid) {
	                        self::log( "PayBear IPN: rate changed [" . $order_id . "]" );
	                        if ( ! empty( $this->debug_email ) ) {
		                        mail( $this->debug_email, "PayBear IPN: late payment [" . $order_id . "]", print_r( $params, true ) );
	                        }
	                        $process = false;

	                        $currency = get_woocommerce_currency();

	                        $order->update_status('on-hold');
	                        $note = sprintf(__( 'Late Payment / Rate changed (%s %s paid, %s %s expected)', 'woocommerce' ), $fiatPaid, $currency, $order->get_total(), $currency);
	                        $order->add_order_note( $note, 1, false );
                        }
                    }

                    if ($process) {
	                    self::log( 'Payment complete' );
	                    $order->payment_complete( $params->inTransaction->hash );
                    }
                } else {
	                self::log("PayBear IPN: wrong amount [" . $order_id . "]");
                    if (!empty($this->debug_email)) { mail($this->debug_email, "PayBear IPN: wrong amount [" . $order_id . "]", print_r($params, true)); }

	                $order->update_status('on-hold' );
	                $underpaid = round(($toPay-$amountPaid)*$this->get_exchange_rate($token), 2);
	                $note = sprintf(__( 'Wrong Amount Paid (%s %s received, %s %s expected) - %s %s underpaid', 'woocommerce' ), $amountPaid, strtoupper($token), $toPay, strtoupper($token), get_woocommerce_currency_symbol(), $underpaid);
	                $order->add_order_note( $note, 1, false );
                }

                wp_send_json($invoice); //stop processing callbacks
            }

            self::log(sprintf('Callback processed: %s/%s', $params->confirmations, $confirmations));
		} else {
			self::log("PayBear IPN: wrong invoice [" . $order_id . ' / ' . get_post_meta($order_id, $token . ' invoice', true) . "]");
			if (!empty($this->debug_email)) { mail($this->debug_email, "PayBear IPN: wrong invoice [" . $invoice . "]", print_r($params, true)); }
        }

        wp_send_json('WAITING');
	}

	/**
	 * Check for IPN Response
	 *
	 * @access public
	 * @return void
	 */
	function check_ipn_response() {

		@ob_clean();

		if (isset($_GET['address']) && isset($_GET['order_id'])) { //address request
		    $json = $this->get_currency_json($_GET['order_id'], $_GET['address']);
			wp_send_json($json);
        }

		if (isset($_GET['status'])) { //address request
			return $this->get_status($_GET['status']);
		}

		self::log("PayBear IPN:" . print_r($_GET, true) . ' / ' . print_r($_POST, true));

		if ( isset($_GET['order_id']) ) {
			$order_id = intval($_GET['order_id']);
			$data = file_get_contents('php://input');
			self::log("PayBear IPN data:" . $data);
			if ($data) {
				$params = json_decode($data);
				if ($params) {
					$this->process_ipn( $order_id, $params );
				}
			}

		} else {
			wp_die( "PayBear IPN Request Failure" );
 		}
	}

	function get_json($order_id)
    {
	    $order_id = intval($order_id);
	    $order = wc_get_order($order_id);
	    $value = $order->get_total();

	    $currencies = $this->get_currency_json($order_id);
	    $response = array(
            //'button' => '#paybear-all',
            'modal' => false,
            'currencies' => $currencies,
		    //'currenciesUrl' => $this->get_address_link($order_id),
            'fiatValue' => doubleval($value),
            'fiatCurrency' => strtoupper(get_woocommerce_currency()),
            'fiatSign' => get_woocommerce_currency_symbol(),
            'enableFiatTotal' => false,
            'enablePoweredBy' => false,
            'statusUrl' => $this->get_status_link($order_id),
            'redirectTo' => $this->get_return_url( $order ),
            'redirectTimeout' => 0,
            'timer' => $this->get_option('rate_lock_time', 15 )*60
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
			if ( $this->token_enabled($code) ) {
				$rate = $this->get_exchange_rate($code);
				if ( $rate ) {
					$amount = round( $value / $rate, 8 );
					if ( $amount >= $currency['minimum'] ) {
						if ($token=='all') {
							$currency['currencyUrl'] = $this->get_address_link( $order_id, $code );
							$currency['coinsValue']  = $amount;
							$currency['rate'] = round( $rate, 2 );

							$currencies[] = $currency;
						} elseif ( $token == $code ) {
							$address      = $this->get_address( $code, $order_id, $amount );
							$currency['coinsValue'] = $amount;
							$currency['rate'] = round( $rate, 2 );
							$currency['maxConfirmations'] = $this->get_confirmations( $code );
							$currency['address'] = $address;

							$currencies[] = $currency;
						}
					}
				}
			}
		}


		if ($token!='all') {
			update_post_meta( $order_id, 'Token Selected', $token);
			return count($currencies)>0 ? current($currencies) : array();
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

	    if ($order && ($status=='on-hold' || $status=='processing' || $status=='failed')) {

		    $token = get_post_meta( $order_id, 'Token Selected', true);
		    //$response['success'] = false;
		    //$response['confirmations'] = 0;
		    if ($token) {
			    $confirmations = get_post_meta( $order_id, $token . ' confirmations', true);
			    $maxConfirmations = get_post_meta( $order_id, $token . ' max_confirmations', true);

			    $response['success'] = $confirmations >= $maxConfirmations;

			    if (is_numeric($confirmations)) $response['confirmations'] = $confirmations;
		    }
	    }

        wp_send_json($response);
    }



    public static function log( $message ) {
        if ( empty( self::$log ) ) {
            self::$log = wc_get_logger();
        }

        self::$log->add( 'woocommerce-gateway-paybear', $message );
    }

    function get_exchange_rate( $token ) {
	    static $cache;

        $token = strtolower($token);

        if (!$cache || !isset($cache->$token)) {
            $currency = strtolower(get_woocommerce_currency());
	        $url = $this->api_domain() . sprintf("/v2/exchange/%s/rate", $currency);


	        if ( $response = @file_get_contents( $url ) ) {
		        $response = json_decode( $response );
		        if ( $response->success ) {
			        $cache = $response->data;
		        }
	        }
        }

        return isset($cache->$token->mid) ? $cache->$token->mid : null;
    }

    public function get_address( $token, $order_id, $total ) {
	    $token = $this->sanitize_token($token);

	    if (!$this->token_enabled($token)) return false;

	    //return '0xTESTJKADHFJKDHFJKSDFSDF';

	    if ($address = get_post_meta($order_id, $token . ' address', true)) {
		    update_post_meta($order_id, $token . ' total', $total);
		    update_post_meta($order_id, $token . ' order timestamp', time());
		    return $address;
	    }

	    $secret = $this->get_option( 'api_secret' );

        $callbackUrl = $this->get_ipn_link($order_id);
	    //$callbackUrl = 'http://demo.paybear.io/ojosidfjsdf';

	    $url = sprintf($this->api_domain() . '/v2/%s/payment/%s?token=%s', $token, urlencode($callbackUrl), $secret);
	    self::log("PayBear address request: " . $url);
        if ($contents = @file_get_contents($url)) {
            $response = json_decode($contents);
	        self::log("PayBear address response: " . print_r($response, true));
            if (isset($response->data->address)) {
	            $address = $response->data->address;

	            update_post_meta($order_id, $token . ' address', $address);
                update_post_meta($order_id, $token . ' invoice', $response->data->invoice);
	            update_post_meta($order_id, $token . ' total', $total);
	            update_post_meta($order_id, $token . ' order timestamp', time());

                return $address;
            } else {
	            self::log("PayBear API responded: " . $contents);
            }
        }
    }

    public function sanitize_token( $token ) {
	    $token = strtolower($token);
	    $token = preg_replace('/[^a-z]/', '', $token);
	    return $token;
    }

}

class WC_Paybear extends WC_Gateway_Paybear {
	public function __construct() {
		_deprecated_function( 'WC_Paybear', '1.4', 'WC_Gateway_Paybear' );
		parent::__construct();
	}
}

	$GLOBALS['wc_paybear'] = WC_Gateway_Paybear::get_instance();
}
