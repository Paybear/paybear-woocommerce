<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Plugin Name: Crypto Payment Gateway for WooCommerce by PayBear.io
 * Plugin URI: https://www.paybear.io/
 * Description: Allows to accept crypto payments such as Bitcoin (BTC) and Ethereum (ETH)
 * Version: 0.1
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
     * @version		0.1
     * @package		WooCommerce/Classes/Payment
     */
class WC_Gateway_Paybear extends WC_Payment_Gateway {

	const API_DOMAIN = 'https://api.paybear.io';
	const API_DOMAIN_TEST = 'https://test.paybear.io';

	/**
	 * @var Singleton The reference the *Singleton* instance of this class
	 */
	private static $instance;

	public static $log;

	public static $currencies;

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
		$this->id           = 'paybear';
		$this->icon         = apply_filters( 'woocommerce_paybear_icon', plugins_url().'/paybear-crypto-payment-gateway-for-woocommerce/assets/images/icons/crypto.png' );
		$this->assetDir     = plugins_url().'/paybear-crypto-payment-gateway-for-woocommerce/assets/form/';
		$this->enabled      = $this->get_option( 'enabled' );
		$this->has_fields   = false;
		$this->method_title = __( 'Crypto Payments via PayBear.io', 'woocommerce' );

		// Load the settings.
		$this->init_currencies();
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
		    foreach(self::$currencies as $token => $currency) {
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

	function available_gateways( $available_gateways ) {
		$cart_total = WC()->cart->total;

		if ( isset( $available_gateways['paybear'] ) ) {

			if (!$this->is_available()) {
				unset( $available_gateways['paybear'] );
				self::log('No currencies');
			}

		}

		return $available_gateways;
	}


	function get_payout($token)
    {
	    $key = strtolower($token.'_payout');
	    return $this->get_option( $key );
    }

    function token_enabled($token, $checkPayout = true)
    {
        $key = strtolower($token.'_enable');
	    return ($this->get_option( $key )=='yes' && (!$checkPayout || !empty($this->get_payout($token)) )) ? true : false;
    }

    function token_minimum($token)
    {
	    $token = $this->sanitize_token($token);
	    return isset(self::$currencies[$token]) ? self::$currencies[$token]['minimum'] : null;

    }

    function get_confirmations($token)
    {
	    $key = strtolower($token.'_confirmations');
	    $confirmations = $this->get_option( $key );
	    return is_numeric($confirmations) ? $confirmations : 3;
    }

    function api_domain()
    {
	    if ("yes"==$this->get_option('testing', 'no' )) {
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

        //check payout wallets
        foreach (self::$currencies as $token => $currency) {
            if ($this->token_enabled($token, false) && !$this->get_payout($token)) {
	            echo '<div class="error"><p>' . sprintf( __( 'Please set your payout wallet for for %1$s in <a href="%2$s">PayBear Settings</a>', 'woocommerce' ), $currency['code'], $this->get_setting_link() ) . '</p></div>';
            }
        }
	}

    function init_currencies()
    {
        self::$currencies = array(
            'eth' => array(
                'title'         => "Ethereum",
                'code'          => "ETH",
                'icon'          => "data:image/svg+xml;base64,PHN2ZyBoZWlnaHQ9IjQxNyIgcHJlc2VydmVBc3BlY3RSYXRpbz0ieE1pZFlNaWQiIHZpZXdCb3g9IjAgMCAyNTYgNDE3IiB3aWR0aD0iMjU2IiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxwYXRoIGQ9Im0xMjcuOTYxMSAwLTIuNzk1IDkuNXYyNzUuNjY4bDIuNzk1IDIuNzkgMTI3Ljk2Mi03NS42Mzh6IiBmaWxsPSIjMzQzNDM0Ii8+PHBhdGggZD0ibTEyNy45NjIgMC0xMjcuOTYyIDIxMi4zMiAxMjcuOTYyIDc1LjYzOXYtMTMzLjgwMXoiIGZpbGw9IiM4YzhjOGMiLz48cGF0aCBkPSJtMTI3Ljk2MTEgMzEyLjE4NjYtMS41NzUgMS45MnY5OC4xOTlsMS41NzUgNC42MDEgMTI4LjAzOC0xODAuMzJ6IiBmaWxsPSIjM2MzYzNiIi8+PHBhdGggZD0ibTEyNy45NjIgNDE2LjkwNTJ2LTEwNC43MmwtMTI3Ljk2Mi03NS42eiIgZmlsbD0iIzhjOGM4YyIvPjxwYXRoIGQ9Im0xMjcuOTYxMSAyODcuOTU3NyAxMjcuOTYtNzUuNjM3LTEyNy45Ni01OC4xNjJ6IiBmaWxsPSIjMTQxNDE0Ii8+PHBhdGggZD0ibSAuMDAwOSAyMTIuMzIwOCAxMjcuOTYgNzUuNjM3di0xMzMuNzk5eiIgZmlsbD0iIzM5MzkzOSIvPjwvc3ZnPg==",
                'minimum'       => 0.001,
                'min_with_fee'  => 0.006,
                'metamask'      => true,
                'blockExplorer' => "https://etherscan.io/address/%s",
                'walletLink'    => "ethereum:%s?amount=%s"

            ),
            'btc' => array(
	            'title'         => "Bitcoin",
	            'code'          => "BTC",
	            'icon'          => "data:image/svg+xml;base64,PHN2ZyBoZWlnaHQ9IjU0IiB2aWV3Qm94PSIwIDAgMzkgNTQiIHdpZHRoPSIzOSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cGF0aCBkPSJtNDkuNzc1MTEwNiAzOS43MzQyMDE1YzAtOC43MDg2MTEyLTYuMDg0MDk4My0xMC40OTgwNTE4LTYuMDg0MDk4My0xMC40OTgwNTE4czQuNDEzOTUzNi0xLjQzMTU1MjYgNC40MTM5NTM2LTYuOTE5MTcwNi00LjI5NDY1NzUtOC4zNTA3MjMxLTguMzUwNzIzLTguMzUwNzIzMWMtLjExOTI5NjEgMC0uMzU3ODg4MiAwLS40NzcxODQyIDB2LTcuMTU3NzYyNjFoLTQuNzcxODQxOHY3LjE1Nzc2MjYxYy0xLjU1MDg0ODUgMC0zLjEwMTY5NzEgMC00Ljc3MTg0MTcgMHYtNy4xNTc3NjI2MWgtNC43NzE4NDE4djcuMTU3NzYyNjFjLTEuMzEyMjU2NSAwLTEwLjczNjY0NCAwLTEwLjczNjY0NCAwdjUuOTY0ODAyMmg0Ljc3MTg0MTh2MjUuMDUyMTY5M2gtNC43NzE4NDE4djUuOTY0ODAyMmgxMC43MzY2NDR2Ny4xNTc3NjI2aDQuNzcxODQxOHYtNy4xNTc3NjI2aDQuNzcxODQxN3Y3LjE1Nzc2MjZoNC43NzE4NDE4di03LjI3NzA1ODdjMy40NTk1ODUzLS4xMTkyOTYgMTAuNDk4MDUxOS0yLjYyNDUxMjkgMTAuNDk4MDUxOS0xMS4wOTQ1MzIxem0tMjQuOTMyODczMi0xOS44MDMxNDMzaDEyLjUyNjA4NDZjLjgzNTA3MjMgMCAzLjgxNzQ3MzQtLjM1Nzg4ODEgMy44MTc0NzM0IDQuNDEzOTUzNyAwIDQuNDEzOTUzNi0zLjkzNjc2OTUgMy45MzY3Njk0LTMuOTM2NzY5NSAzLjkzNjc2OTRoLTEyLjQwNjc4ODV6bTEzLjI0MTg2MDggMjMuODU5MjA4OGgtMTMuMjQxODYwOHYtOC4zNTA3MjNoMTMuMjQxODYwOGMuOTU0MzY4NCAwIDQuMDU2MDY1NS0uNTk2NDgwMyA0LjA1NjA2NTUgNC40MTM5NTM2LjExOTI5NjEgNC43NzE4NDE3LTQuMDU2MDY1NSAzLjkzNjc2OTQtNC4wNTYwNjU1IDMuOTM2NzY5NHoiIGZpbGw9IiNmN2FjMzEiIHRyYW5zZm9ybT0ibWF0cml4KC45ODE2MjcxOCAuMTkwODA5IC0uMTkwODA5IC45ODE2MjcxOCAtNC4yMTg5NTUgLTEwLjUwOTU1OSkiLz48L3N2Zz4=",
	            'minimum'       => 0.0005,
	            'min_with_fee'  => 0.001,
	            'blockExplorer' => "https://blockchain.info/address/%s",
	            'walletLink'    => "bitcoin:%s?amount=%s"
            ),
            'bch' => array(
	            'title'         => "Bitcoin Cash",
	            'code'          => "BCH",
	            'icon'          => "data:image/svg+xml;base64,PHN2ZyBoZWlnaHQ9IjM2IiB2aWV3Qm94PSIwIDAgMjggMzYiIHdpZHRoPSIyOCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cGF0aCBkPSJtMjcuNDE0OTE2MyAyNC4xMDY4MTk4YzAtNS43MTUwMjYxLTMuOTkyNjg5NS02Ljg4OTM0NjYtMy45OTI2ODk1LTYuODg5MzQ2NnMyLjg5NjY1NzEtLjkzOTQ1NjMgMi44OTY2NTcxLTQuNTQwNzA1N2MwLTMuNjAxMjQ5MjgtMi44MTgzNjktNS40ODAxNjE5OC01LjQ4MDE2Mi01LjQ4MDE2MTk4LS4wNzgyODgxIDAtLjIzNDg2NDEgMC0uMzEzMTUyMSAwdi00LjY5NzI4MTczaC0zLjEzMTUyMTJ2NC42OTcyODE3M2MtMS4wMTc3NDQ0IDAtMi4wMzU0ODg3IDAtMy4xMzE1MjExIDB2LTQuNjk3MjgxNzNoLTMuMTMxNTIxMnY0LjY5NzI4MTczYy0uODYxMTY4MyAwLTcuMDQ1OTIyNiAwLTcuMDQ1OTIyNiAwdjMuOTE0NDAxNDhoMy4xMzE1MjExNXYxNi40NDA0ODZoLTMuMTMxNTIxMTV2My45MTQ0MDE1aDcuMDQ1OTIyNnY0LjY5NzI4MTdoMy4xMzE1MjEydi00LjY5NzI4MTdoMy4xMzE1MjExdjQuNjk3MjgxN2gzLjEzMTUyMTJ2LTQuNzc1NTY5OGMyLjI3MDM1MjgtLjA3ODI4OCA2Ljg4OTM0NjUtMS43MjIzMzY2IDYuODg5MzQ2NS03LjI4MDc4NjZ6bS0xNi4zNjIxOTgtMTIuOTk1ODEyOGg4LjIyMDI0M2MuNTQ4MDE2MiAwIDIuNTA1MjE2OS0uMjM0ODY0MSAyLjUwNTIxNjkgMi44OTY2NTcgMCAyLjg5NjY1NzEtMi41ODM1MDQ5IDIuNTgzNTA1LTIuNTgzNTA0OSAyLjU4MzUwNWgtOC4xNDE5NTV6bTguNjg5OTcxMiAxNS42NTc2MDU3aC04LjY4OTk3MTJ2LTUuNDgwMTYyaDguNjg5OTcxMmMuNjI2MzA0MiAwIDIuNjYxNzkzLS4zOTE0NDAxIDIuNjYxNzkzIDIuODk2NjU3MS4wNzgyODggMy4xMzE1MjExLTIuNjYxNzkzIDIuNTgzNTA0OS0yLjY2MTc5MyAyLjU4MzUwNDl6IiBmaWxsPSIjZGM5ODI5IiB0cmFuc2Zvcm09Im1hdHJpeCguOTcwMjk1NzMgLS4yNDE5MjE5IC4yNDE5MjE5IC45NzAyOTU3MyAtNC45NTg4MSAzLjM1MzI0MSkiLz48L3N2Zz4=",
	            'minimum'       => 0.0005,
	            'min_with_fee'  => 0.003,
	            'blockExplorer' => "https://blockdozer.com/insight/address/%s",
            ),
            'btg' => array(
	            'title'         => "Bitcoin Gold",
	            'code'          => "BTG",
	            'icon'          => "data:image/svg+xml;base64,PHN2ZyBoZWlnaHQ9IjgwMCIgdmlld0JveD0iMCAwIDgwMCA4MDAiIHdpZHRoPSI4MDAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHBhdGggZD0ibTQwMCAwYTQwMCA0MDAgMCAwIDAgLTQwMCA0MDAgNDAwIDQwMCAwIDAgMCA0MDAgNDAwIDQwMCA0MDAgMCAwIDAgNDAwLTQwMCA0MDAgNDAwIDAgMCAwIC00MDAtNDAwem0wIDE0YTM4NiAzODYgMCAwIDEgMzg2IDM4NiAzODYgMzg2IDAgMCAxIC0zODYgMzg2IDM4NiAzODYgMCAwIDEgLTM4Ni0zODYgMzg2IDM4NiAwIDAgMSAzODYtMzg2eiIgZmlsbD0iIzAwMjA2YiIvPjxwYXRoIGQ9Im00MDAgMzVhMzY1IDM2NSAwIDAgMCAtMzY1IDM2NSAzNjUgMzY1IDAgMCAwIDM2NSAzNjUgMzY1IDM2NSAwIDAgMCAzNjUtMzY1IDM2NSAzNjUgMCAwIDAgLTM2NS0zNjV6bTAgMTE1YTI1MCAyNTAgMCAwIDEgMjUwIDI1MCAyNTAgMjUwIDAgMCAxIC0yNTAgMjUwIDI1MCAyNTAgMCAwIDEgLTI1MC0yNTAgMjUwIDI1MCAwIDAgMSAyNTAtMjUweiIgZmlsbD0iI2ViYTgwOSIvPjxwYXRoIGQ9Im00MDAgMTcyYTIyOCAyMjggMCAwIDAgLTIyOCAyMjggMjI4IDIyOCAwIDAgMCAyMjggMjI4IDIyOCAyMjggMCAwIDAgMjI4LTIyOCAyMjggMjI4IDAgMCAwIC0yMjgtMjI4em0wIDE2YTIxMiAyMTIgMCAwIDEgOC45MTc5Ny40NDUzMXY2MC44NDU3MWgtMzIuMTYyMTF2LTU5Ljc1OTc3YTIxMiAyMTIgMCAwIDEgMjMuMjQ0MTQtMS41MzEyNXptNDguOTM5NDUgNS45Njg3NWEyMTIgMjEyIDAgMCAxIDE2My4wNjA1NSAyMDYuMDMxMjUgMjEyIDIxMiAwIDAgMSAtMTYzLjE4MzU5IDIwNi4wNDQ5MnYtNTYuNzI0NjFzMzcuNDQzMjItMS45NjQwNSA1Ni43MTY3OS04LjM0NzY1YzE5LjI3MzU4LTYuMzgzNjEgMzIuMTYyNDQtMTMuMDEzMjcgNDQuMDcwMzItMjcuMTMwODYgMTEuOTA3ODctMTQuMTE3NTggMTguNTM2NTQtNDEuMDAxNTQgMTguMjkxMDEtNjAuMTUyMzUtLjI0NTUyLTE5LjE1MDgxLTYuMTM3MDMtMzQuODY0ODEtMTIuMTUyMzQtNDMuMDg5ODQtNi4wMTUzMi04LjIyNTAyLTE4LjQxNDA0LTE1Ljk1ODk2LTE4LjQxNDA3LTE1Ljk1ODk5IDAgMC03LjQ4ODI5LTMuOTI3MDEtMTMuMzgwODUtNi4xMzY3MS01Ljg5MjU1LTIuMjA5NzEtMTIuNTIzNDQtMy44MDY2NC0xMi41MjM0NC0zLjgwNjY0czEyLjg5MTQ2LTguNDcwNzMgMTguMjkyOTctMTQuMzYzMjkgMTMuOTk1MDMtMTYuNDQ5NjUgMTUuMjIyNjUtMzUuOTY4NzVjMS4yMjc2MS0xOS41MTkwOS0yLjU3ODY1LTM3LjQ0MTc3LTEyLjg5MDYyLTUwLjIwODk4LTEwLjMxMTk3LTEyLjc2NzItMjYuMjcwNjMtMjEuOTc1NDQtNDcuNzUzOTEtMjcuMzc2OTVzLTM1LjM1NTQ3LTYuMTM2NzItMzUuMzU1NDctNi4xMzY3MnptLTExMS4wOTk2MSAzLjU2ODM2djUzLjQ3MjY2bC04MS42MzY3Mi4xODM1OWMtMy4wODk0OS41MTE1MS01LjI1ODMgMi45ODcyNC01Ljg5MjU3IDYuMzIyMjZsLS4yNDYxIDI5Ljk1NTA4Yy0uMDgzOCAzLjY5MjI1IDIuMTc3NTQgNC44MjMxNiA1LjIxODc1IDUuMjE2OGgzNC40MzM2YzYuOTAwODQtLjg0NTQyIDE4LjkyODkyIDYuMjY1MDIgMTkuNjQyNTggMTcuNjE3MTl2MTc5LjU5OTYxYy4xOTgwNSAzLjY5MTAzLTQuODE4MzIgMTIuMzMxMzItMTIuMzk4NDQgMTIuMzk4NDNoLTMzLjg4Mjgycy0xLjcxNzk4LjM2ODM3LTMuMDY4MzUgMS43MTg3NWMtMS4zNTAzOCAxLjM1MDM3LTIuNTc4MTMgNC41NDI5Ny0yLjU3ODEzIDQuNTQyOTdsLTYuMjYxNzIgMjkuNzA3MDNzLS43MzY4NiA1LjE1Njg3LS4xMjMwNCA2LjI2MTcyYy42MTM4MSAxLjEwNDg2IDMuNTYwMDYgNC40MjAyMSA1LjAzMzIgNC41NDI5NyAxLjQ3MzEzLjEyMjc2IDgyLjM3MzA3IDAgODIuMzczMDQgMHY1My43MzYzM2EyMTIgMjEyIDAgMCAxIC0xNTAuNDUzMTItMjAyLjgxMjUgMjEyIDIxMiAwIDAgMSAxNDkuODM5ODQtMjAyLjQ2Mjg5em0zOS44NDM3NSA5OC4yMDcwM2MyNC4wOTQ0MyAxLjI5MjU5IDQ5LjY4ODc4LTIuNDEzNjggNzEuNTMzMjEgNy42Mjg5MSAxOC42Nzc3NyA3LjIwOTI1IDIxLjM1NTQzIDIyLjQxNzcgMjEuNTMzMiAzMi4xMjY5NS0uMjQ5MzggMTAuMzU5MzgtMi42MjA5NCAyMC4xODg0My0xOS4zMTA1NSAzMS4wNzgxMi0xNi42MzA2OCA5LjQxNjU3LTQ4Ljk5MzQ1IDkuMjg3NTktNzMuNzU1ODYgOS4xNTgyMXptLjU5OTYxIDExOS41NzQyMmMyOC44MTgxMiAxLjQwNDUgNTkuNDI5NjItMi42MjQ5IDg1LjU1NjY0IDguMjg3MTEgMjIuMzM5NTIgNy44MzMzOCAyNS41NDMyNCAyNC4zNjAzNSAyNS43NTU4NiAzNC45MTAxNS0uMjk4MjggMTEuMjU2MjItMy4xMzQxMSAyMS45MzcwOC0yMy4wOTU3IDMzLjc2OTU0LTE5Ljg5MTExIDEwLjIzMTc4LTU4LjU5OTc0IDEwLjA4OTgtODguMjE2OCA5Ljk0OTIyem0zMS4yMzA0NyAxMzQuNTQ2ODd2NjEuNzk2ODhhMjEyIDIxMiAwIDAgMSAtOS41MTM2Ny4zMzc4OSAyMTIgMjEyIDAgMCAxIC0yMi4wMTc1OC0xLjE4NzV2LTYwLjc1NTg2aDMwLjgxNDQ2eiIgZmlsbD0iIzAwMjA2YiIvPjwvc3ZnPg==",
	            'minimum'       => 0.0005,
	            'min_with_fee'  => 0.003,
	            'blockExplorer' => "https://btgexp.com/address/%s",
            ),
            'ltc' => array(
	            'title'         => "Litecoin",
	            'code'          => "LTC",
	            'icon'          => "data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyNTAwIiBoZWlnaHQ9IjI1MDAiIHZpZXdCb3g9IjAuODQ3IDAuODc2IDMyOS4yNTQgMzI5LjI1NiI+PHRpdGxlPkxpdGVjb2luPC90aXRsZT48cGF0aCBkPSJNMzMwLjEwMiAxNjUuNTAzYzAgOTAuOTIyLTczLjcwNSAxNjQuNjI5LTE2NC42MjYgMTY0LjYyOUM3NC41NTQgMzMwLjEzMi44NDggMjU2LjQyNS44NDggMTY1LjUwMy44NDggNzQuNTgyIDc0LjU1NC44NzYgMTY1LjQ3Ni44NzZjOTAuOTIgMCAxNjQuNjI2IDczLjcwNiAxNjQuNjI2IDE2NC42MjciIGZpbGw9IiNiZWJlYmUiLz48cGF0aCBkPSJNMjk1LjE1IDE2NS41MDVjMCA3MS42MTMtNTguMDU3IDEyOS42NzUtMTI5LjY3NCAxMjkuNjc1LTcxLjYxNiAwLTEyOS42NzctNTguMDYyLTEyOS42NzctMTI5LjY3NSAwLTcxLjYxOSA1OC4wNjEtMTI5LjY3NyAxMjkuNjc3LTEyOS42NzcgNzEuNjE4IDAgMTI5LjY3NCA1OC4wNTcgMTI5LjY3NCAxMjkuNjc3IiBmaWxsPSIjYmViZWJlIi8+PHBhdGggZD0iTTE1NS44NTQgMjA5LjQ4MmwxMC42OTMtNDAuMjY0IDI1LjMxNi05LjI0OSA2LjI5Ny0yMy42NjMtLjIxNS0uNTg3LTI0LjkyIDkuMTA0IDE3Ljk1NS02Ny42MDhoLTUwLjkyMWwtMjMuNDgxIDg4LjIzLTE5LjYwNSA3LjE2Mi02LjQ3OCAyNC4zOTUgMTkuNTktNy4xNTYtMTMuODM5IDUxLjk5OGgxMzUuNTIxbDguNjg4LTMyLjM2MmgtODQuNjAxIiBmaWxsPSIjZmZmIi8+PC9zdmc+",
	            'minimum'       => 0.005,
	            'min_with_fee'  => 0.03,
	            'blockExplorer' => "https://live.blockcypher.com/ltc/address/%s/",
                'walletLink'    => "litecoin:%s?amount=%s",
            ),
            'dash' => array(
	            'title'         => "DASH",
	            'code'          => "DASH",
	            'icon'          => "data:image/svg+xml;base64,PHN2ZyBoZWlnaHQ9IjM4IiB2aWV3Qm94PSIwIDAgNjQgMzgiIHdpZHRoPSI2NCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cGF0aCBkPSJtNjMuOTEzNjQ2MiAxOC45NjkwMDk1Yy4yMDkzMjQyIDEuMjkwODMyNC4wMzQ4ODczIDIuNjE2NTUyMS0uNDg4NDIzMSAzLjgwMjcyMjRsLTUuNzU2NDE0NiAxOC4xMDY1NDA2Yy0uNTIzMzEwNCAxLjI1NTk0NS0xLjE1MTI4MjkgMi40NDIxMTUzLTEuOTE4ODA0OSAzLjU5MzM5ODItLjkwNzA3MTQgMS4xNTEyODI5LTEuOTUzNjkyMiAyLjE5NzkwMzgtMy4xMDQ5NzUyIDMuMTA0OTc1Mi0xLjE4NjE3MDMuOTc2ODQ2MS0yLjU4MTY2NDcgMS42NzQ1OTMzLTMuODAyNzIyNCAyLjM3MjM0MDYtMS4yMTg4ODA0LjQ1MDg4MDktMi41MDM1NzI1LjY5ODM5MDQtMy44MDI3MjI0LjczMjYzNDZoLTQzLjYwOTIwMTc4bDMuMTA0OTc1MTctOS4zMTQ5MjU1aDM5LjMxODA1NjMxbDYuMjA5OTUwNC0xOS4wNDg0OTk0aC0zOS4zMTgwNTY0bDMuMTA0OTc1Mi05LjMxNDkyNTVoNDMuMzk5ODc3NmMxLjE1MTI4My0uMDM0ODg3NCAyLjMwMjU2NTkuMjA5MzI0MiAzLjM0OTE4NjcuNzMyNjM0NiAxLjAxMTczMzUuMzgzNzYxIDEuODgzOTE3NiAxLjE1MTI4MjkgMi4zNzIzNDA2IDIuMTI4MTI5LjU1ODE5NzguOTQxOTU4OC44NzIxODQxIDIuMDIzNDY3Ljk0MTk1ODggMy4xMDQ5NzUyem0tMzcuNjc4MzUwNCA4LjU0NzQwMzYtMi44NjA3NjM2IDguNjE3MTc4MmgtMjMuMzc0NTMyMmwzLjEwNDk3NTE3LTguNjE3MTc4MnoiIGZpbGw9IiMxZTc1YmIiIHRyYW5zZm9ybT0idHJhbnNsYXRlKDAgLTEzKSIvPjwvc3ZnPg==",
	            'minimum'       => 0.0005,
	            'min_with_fee'  => 0.003,
	            'blockExplorer' => "https://chainz.cryptoid.info/dash/address.dws?%s.htm",
            ),

        );
    }


    /**
     * Initialise Gateway Settings Form Fields
     *
     * @access public
     * @return void
     */
    function init_form_fields()
    {

	    $currencies = array();
	    foreach (self::$currencies as $token => $currency) {
		    $currencies[$token . '_enable'] = array(
			    'title' => __( 'Enable ' . strtoupper($token), 'woocommerce' ),
			    'type' => 'checkbox',
			    'label' => __( sprintf('Uncheck to disable %s payments', $currency['title']), 'woocommerce' ),
			    'default' => 'yes'
		    );
		    $currencies[$token . '_confirmations'] = array(
			    'title' => __( strtoupper($token) . ' Confirmations', 'woocommerce' ),
			    'type' => 'text',
			    'description' => __( 'Process the order after this number of confirmations on blockchain. Recommended = 1 for BTC, 3 for other blockchains', 'woocommerce' ),
			    'default' => ($token=='btc') ? 1 : 3,
			    'desc_tip'      => true,
		    );
		    $currencies[$token . '_payout'] = array(
			    'title' => __( strtoupper($token) . ' Payout Wallet', 'woocommerce' ),
			    'type' => 'text',
			    'description' => __( 'Wallet address that will receive payments.', 'woocommerce' ),
			    'default' => '',
			    'desc_tip'      => true,
		    );
	    }

    	$this->form_fields = array(
			'enabled' => array(
							'title' => __( 'Enable/Disable', 'woocommerce' ),
							'type' => 'checkbox',
							'label' => __( 'Enable Crypto Payments', 'woocommerce' ),
							'default' => 'yes'
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

			'testing' => array(
							'title' => __( 'Sandbox (Testnet) Mode', 'woocommerce' ),
							'type' => 'checkbox',
							'label' => __( 'When enabled, transactions will be processed via testnet', 'woocommerce' ),
							'description' => '',
							'default' => 'no'
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

        $this->form_fields = array_merge($this->form_fields, $currencies);

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

		if (!$order || (!in_array($order->get_status(), array('pending', 'on-hold', 'failed'))) ) wp_send_json($invoice); //stop processing this order

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
	                        $order->update_status('failed', sprintf(__( 'Late Payment / Rate changed (%s %s paid, %s %s expected)', 'woocommerce' ), $fiatPaid, $currency, $order->get_total(), $currency));
                        }
                    }

                    if ($process) {
	                    self::log( 'Payment complete' );
	                    $order->payment_complete( $params->inTransaction->hash );
                    }
                } else {
	                self::log("PayBear IPN: wrong amount [" . $order_id . "]");
                    if (!empty($this->debug_email)) { mail($this->debug_email, "PayBear IPN: wrong amount [" . $order_id . "]", print_r($params, true)); }

	                $order->update_status('failed', sprintf(__( 'Wrong Amount Paid (%s %s received, %s %s expected)', 'woocommerce' ), $amountPaid, strtoupper($token), $toPay, strtoupper($token)) );
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
            'enablePoweredBy' => true,
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

		foreach (self::$currencies as $code => $currency) {
			if ( $this->token_enabled($code) ) {
				$rate = $this->get_exchange_rate($code);
				if ( $rate ) {
					$amount = round( $value / $rate, 8 );
					if ( $amount >= $currency['minimum'] ) {
						if ($token=='all') {
							$currency['currencyUrl'] = $this->get_address_link( $order_id, $code );
							$currency['coinsValue']  = $amount;
							$currency['rate'] = round( $rate, 4 );

							$currencies[] = $currency;
						} elseif ( $token == $code ) {
							$address      = $this->get_address( $code, $order_id, $amount );
							$currency['coinsValue'] = $amount;
							$currency['rate'] = round( $rate, 4 );
							$currency['confirmations'] = $this->get_confirmations( $code );
							$currency['address'] = $address;
							if (isset($currency['blockExplorer'])) $currency['blockExplorer'] = sprintf($currency['blockExplorer'], $address);
							if (isset($currency['walletLink'])) $currency['walletLink'] = sprintf($currency['walletLink'], $address, $amount);

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

			    $response['success'] = $confirmations >= $this->get_confirmations( $token );

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
	        $url = $this->api_domain() . sprintf("/v1/exchange/%s/rate", $currency);


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

	    $payoutAddress = $this->get_payout($token);
        if (!$payoutAddress) return false;

        $callbackUrl = $this->get_ipn_link($order_id);
	    //$callbackUrl = 'http://demo.paybear.io/ojosidfjsdf';

	    $url = sprintf($this->api_domain() . '/v1/%s/payment/%s/%s', $token, $payoutAddress, urlencode($callbackUrl));
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
