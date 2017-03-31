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
