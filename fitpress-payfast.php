<?php
/**
 * @package FitPress
 */
/*
Plugin Name: FitPress Payfast
Plugin URI: http://fitpress.co.za
Description: Integrates FitPress in PayFast payments.
Version: 1.0
Author: Flicker Leap
Author URI: https://flickerleap.com
License: GPLv2 or later
Text Domain: fitpress-payfast
*/

if ( ! defined( 'ABSPATH' ) ) :
	exit; // Exit if accessed directly
endif;

function is_fitpress_active_for_payfast(){

	/**
	 * Check if WooCommerce is active, and if it isn't, disable Subscriptions.
	 *
	 * @since 1.0
	 */
	if ( !is_plugin_active( 'fitpress/fitpress.php' ) ) {
		add_action( 'admin_notices', 'FP_Payfast::fitpress_inactive_notice' );
		deactivate_plugins( plugin_basename( __FILE__ ) );
	}

}

add_action( 'admin_init', 'is_fitpress_active_for_payfast' );

class FP_Payfast {
	
	/**
	 * @var FitPress The single instance of the class
	 * @since 1.0
	 */
	protected static $_instance = null;

	/**
	 * Main FitPress Instance
	 *
	 * Ensures only one instance of FitPress is loaded or can be loaded.
	 *
	 * @since 1.0
	 * @static
	 * @see WC()
	 * @return FitPress - Main instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct(){

		add_filter( 'fitpress_signup_redirect', 'fp_checkout_url' );

		add_filter( 'fitpress_payment_methods', array( $this, 'add_payfast_method' ) );

		add_filter( 'fitpress_payment_method_payfast', array( $this, 'process_payment' ), 10, 2 );

		add_action( 'fitpress_payment_notify_payfast', array( $this, 'process_notify' ), 10, 2 );

		add_action( 'fitpress_before_membership', array( $this, 'account_payment' ), 10, 1 );

		add_action( 'admin_init', array( $this, 'init_settings' ) );

		add_filter( 'fitpress_payment_token', array( $this, 'has_token' ) );

	}

	public function has_token( $has_token ) {

		$membership = FP_Membership::get_user_membership( get_current_user_id() );

		if ( ! $this->get_token( $membership['membership_id'] ) ) :
			return false;
		endif;

		return true;

	}

	public function account_payment( $member_id ){

		if ( $token = $this->get_token( $member_id ) ) :
			echo '<p>PayFast is set up as your payment method.</p>';
		else :
			echo '<p>Set up PayFast as your payment method <a class="btn button" href="' . fp_checkout_url() . '">Set up PayFast</a></p>';
		endif;

	}

	public function add_payfast_method( $payment_methods ){

		$payment_methods = array( 'payfast' => 'PayFast' );

		return $payment_methods;

	}

	public function process_payment( $membership, $member ){

		$settings = get_option( 'fitpress_settings' );

		$payfast = $this->set_payfast_settings( $settings );
		$fields = $this->get_fields( $membership, $member, $payfast );

		$output = '<form action="' . $payfast['url'] . '" method="post">';
		
		foreach( $fields as $name => $value ):
			$output .= '<input type="hidden" name="' . $name . '" value="' . $value . '">';
		endforeach;

		$membership_status = new FP_Membership_Status( $membership['membership_id'] );
		if ( 'active' == $membership_status->get_status() && 'Once Off' != $membership['term'] ) :
			$output .= '<p class="form-row form-row-submit"><input type="submit" class="button" value="Connect to PayFast" /></p>';
		else :
			$output .= '<p class="form-row form-row-submit"><input type="submit" class="button" value="Pay on PayFast" /></p>';
		endif;
		$output .= '</form>';

		return $output;

	}

	public function save_token( $membership_id, $token ){
		update_post_meta( $membership_id, 'fp_payfast_token', $token );
	}

	public function get_token( $membership_id ){
		return get_post_meta( $membership_id, 'fp_payfast_token', false );
	}

	public function process_notify( $post_data ) {

		$this->verify_payment( $post_data );

		$this->save_token( $post_data['m_payment_id'], $post_data['token'] );

		$payment = new FP_Payment();

		if ( ! $payment->has_payment( $post_data['pf_payment_id'] ) ) :

			$payment_data = array(
				'amount' => 'amount_gross',
				'membership_id' => $post_data['m_payment_id'],
				'reference' => $post_data['pf_payment_id'],
			);

			switch ( $post_data['payment_status'] ) :
				case 'COMPLETE':
					$payment->process_payment( 'complete', $payment_data );
				break;
				case 'FAILED':
					$payment->process_payment( 'failed', $payment_data );
				break;
				case 'PENDING':
					$payment->process_payment( 'pending', $payment_data );
				break;
				case 'CANCELLED':
					$payment->process_payment( 'cancelled', $payment_data );
				break;
				default:
				break;
			endswitch;

			die('Payment recorded.');

		else:

			die('Already has payment.');

		endif;

	}

	public function verify_payment( $post_data ) {

		$settings = get_option( 'fitpress_settings' );

		$data = array();

		foreach ( $post_data as $key => $val ) :
			$data[ $key ] = stripslashes( $val );
		endforeach;

		if( isset( $settings['payfast_passphrase'] ) ) :
			$data['passphrase'] = $settings['payfast_passphrase'];
		endif;

		$payfast_string = '';

		foreach ( $data as $key => $val ) :
			if ( $key != 'signature' ) :
				$payfast_string .= $key . '=' . urlencode( $val ) . '&';
			endif;
		endforeach;

		$payfast_string = substr( $payfast_string, 0, -1 );

		$signature = md5( $payfast_string );

		if ( $signature != $post_data['signature'] ) :
		   die('Invalid Signature');
		endif;

		$valid_hosts = array(
			'www.payfast.co.za',
			'sandbox.payfast.co.za',
			'w1w.payfast.co.za',
			'w2w.payfast.co.za',
			'192.168.33.1'
		);

		$valid_ips = array();

		foreach ( $valid_hosts as $payfast_host_name ) :
			$ips = gethostbynamel( $payfast_host_name );

			if ( $ips !== false ) :
				$valid_ips = array_merge( $valid_ips, $ips );
			endif;
		endforeach;

		$valid_ips = array_unique( $valid_ips );

		if ( ! in_array( $_SERVER['REMOTE_ADDR'], $valid_ips ) ) :
			die('Source IP not Valid');
		endif;

	}

	public function get_fields( $membership, $member, $payfast ){
		
		$first_name = get_user_meta( $member->ID, 'first_name', true );
		$last_name = get_user_meta( $member->ID, 'last_name', true );
		$email_address = $member->data->user_email;

		switch( $membership['term'] ):

			case '+12 month':
				$frequency = 6;
			case '+6 month':
				$frequency = 5;
			case '+3 month':
				$frequency = 4;
			case '+1 month':
			default: 
				$frequency = 3;
				break;

		endswitch;

		$fields = array(
			'merchant_id' => $payfast['merchant_id'],
			'merchant_key' => $payfast['merchant_key'],
			'return_url' => $payfast['return_url'],
			'cancel_url' => $payfast['cancel_url'],
			'notify_url' => $payfast['notify_url'],
			'name_first' => $first_name,
			'name_last'  => $last_name,
			'email_address' => $email_address,
			'm_payment_id' => $membership['membership_id'],
			'amount' => number_format( sprintf( '%.2f', $membership['price'] ), 2, '.', '' ),
			'item_name' => get_bloginfo( 'name' ),
			'item_description' => $membership['name'] . ' membership',
		);

		if ( 'Once Off' != $membership['term'] ) :
			$membership_status = new FP_Membership_Status( $membership['membership_id'] );
			if ( 'active' == $membership_status->get_status() ) :
				$fields = array_merge( $fields, array(
					'subscription_type' => 1,
					'billing_date' => date( 'Y-m-d', get_user_meta( $member->ID, 'fitpress_next_invoice_date', true ) ),
					'recurring_amount' => number_format( sprintf( '%.2f', $membership['price'] ), 2, '.', '' ),
					'frequency' => $frequency,
				) );
				$fields['amount'] = number_format( sprintf( '%.2f', 0 ), 2, '.', '' );
			else :
				$fields = array_merge( $fields, array(
					'subscription_type' => 1,
					'frequency' => $frequency,
				) );
			endif;
		endif;

		$string_output = '';

		foreach ( $fields as $key => $val ) :
			if ( ! empty( $val ) ) :
				$string_output .= $key . '=' . urlencode( trim( $val ) ) . '&';
			endif;
		endforeach;

		$string_output = substr( $string_output, 0, -1 );

		if ( isset( $payfast['passphrase'] ) ) :
			$string_output .= '&passphrase=' . urlencode( trim( $payfast['passphrase'] ) );
		endif;

		$fields['signature'] = md5( $string_output );

		return $fields;

	}

	public function set_payfast_settings( $payfast_settings ){

		$settings = array();

		if ( isset( $payfast_settings['payfast_mode'] ) && $payfast_settings['payfast_mode'] == 'production' ) :
			$settings['url'] = 'https://www.payfast.co.za/eng/process';
		else :
			$settings['url'] = 'https://sandbox.payfast.co.za/eng/process';
		endif;

		if ( isset( $payfast_settings['payfast_merchant_id'] ) && ! empty ( $payfast_settings['payfast_merchant_id'] )  ) :
			$settings['merchant_id'] = $payfast_settings['payfast_merchant_id'];
		else :
			$settings['merchant_id'] = '10001822';
		endif;

		if ( isset( $payfast_settings['payfast_merchant_key'] ) && ! empty ( $payfast_settings['payfast_merchant_key'] ) ) :
			$settings['merchant_key'] = $payfast_settings['payfast_merchant_key'];
		else :
			$settings['merchant_key'] = 'gcy7w8gmun4pc';
		endif;

		if ( isset( $payfast_settings['payfast_passphrase'] ) ) :
			$settings['passphrase'] = $payfast_settings['payfast_passphrase'];
		endif;

		$settings['return_url'] = fp_confirm_url();
		$settings['cancel_url'] = fp_checkout_url();
		$settings['notify_url'] = fp_notify_url() . '?method=payfast';

		return $settings;

	}

	public function init_settings() {

		$this->settings = get_option( 'fitpress_settings' );

		add_settings_section(
			'payfast_settings',
			'PayFast Settings',
			array( $this, 'payfast_settings_callback_function' ),
			'fp_settings'
		);

		add_settings_field(
			'payfast_merchant_id',
			'Merchant ID',
			array( $this, 'merchant_id_callback_function' ),
			'fp_settings',
			'payfast_settings'
		);
		add_settings_field(
			'payfast_merchant_key',
			'Merchant Key',
			array( $this, 'merchant_key_callback_function' ),
			'fp_settings',
			'payfast_settings'
		);
		add_settings_field(
			'payfast_passphrase',
			'PayFast Passphrase',
			array( $this, 'passphrase_callback_function' ),
			'fp_settings',
			'payfast_settings'
		);
		add_settings_field(
			'payfast_mode',
			'Merchant Key',
			array( $this, 'mode_callback_function' ),
			'fp_settings',
			'payfast_settings'
		);

		register_setting( 'fp_settings', 'fitpress_settings' );

	}

	public function payfast_settings_callback_function() {
	}

	public function merchant_id_callback_function() {
		$value = (! empty( $this->settings['payfast_merchant_id'] ) ) ? $this->settings['payfast_merchant_id'] : '';
		echo '<input name="fitpress_settings[payfast_merchant_id]" id="payfast_merchant_id" type="text" value="' . $value . '" />';
	}

	public function merchant_key_callback_function() {
		$value = (! empty( $this->settings['payfast_merchant_key'] ) ) ? $this->settings['payfast_merchant_key'] : '';
		echo '<input name="fitpress_settings[payfast_merchant_key]" id="payfast_merchant_key" type="text" value="' . $value . '" />';
	}

	public function passphrase_callback_function() {
		$value = (! empty( $this->settings['payfast_passphrase'] ) ) ? $this->settings['payfast_passphrase'] : '';
		echo '<input name="fitpress_settings[payfast_passphrase]" id="payfast_passphrase" type="text" value="' . $value . '" />';
	}

	public function mode_callback_function() {
		$value = (! empty( $this->settings['payfast_mode'] ) ) ? $this->settings['payfast_mode'] : 'testing';
		echo '<input name="fitpress_settings[payfast_mode]" id="payfast_mode" type="radio" value="testing"  ' . checked( 'testing', $value, false ) . ' /> Testing';
		echo '<br />';
		echo '<input name="fitpress_settings[payfast_mode]" id="payfast_mode" type="radio" ' . checked( 'production', $value, false ) . ' value="production" /> Production';
	}

	public static function fitpress_inactive_notice() {
		if ( current_user_can( 'activate_plugins' ) ) :?>
			<div id="message" class="error">
				<p><?php printf( __( '%sFitPress is inactive%s. The FitPress plugin must be active for FitPress PayFast to work. Please install & activate FitPress.', 'fitpress-payfast' ), '<strong>', '</strong>' ); ?></p>
			</div>
		<?php endif;
	}

}



/**
 * Extension main function
 */
function __fp_payfast_main() {
	FP_PayFast::instance();
}

// Initialize plugin when plugins are loaded
add_action( 'plugins_loaded', '__fp_payfast_main' );
