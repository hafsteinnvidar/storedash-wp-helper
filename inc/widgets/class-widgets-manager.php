<?php
/**
 * Widgets Manager
 *
 * @package StoreDash\Widgets
 * @since   1.0.0
 */

namespace StoreDash\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Widgets Manager Class
 */
class Widgets_Manager {

	/**
	 * Instance
	 *
	 * @var Widgets_Manager
	 */
	private static $instance = null;

	/**
	 * Get instance
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_widget_assets' ) );
	}

	/**
	 * Register widgets
	 */
	public function register_widgets( $widgets_manager ) {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		require_once STOREDASH_PATH . 'inc/widgets/class-back-in-stock-widget.php';
		require_once STOREDASH_PATH . 'inc/widgets/class-product-enquiry-widget.php';
		require_once STOREDASH_PATH . 'inc/widgets/class-signup-widget.php';

		$widgets_manager->register( new Back_In_Stock_Widget() );
		$widgets_manager->register( new Product_Enquiry_Widget() );
		$widgets_manager->register( new Signup_Widget() );
	}

	/**
	 * Register widget assets (enqueued conditionally via get_style_depends / get_script_depends)
	 */
	public function register_widget_assets() {
		wp_register_style(
			'storedash-waitlist-widget',
			STOREDASH_URL . 'assets/css/waitlist-widget.css',
			array(),
			STOREDASH_VERSION
		);

		wp_register_script(
			'storedash-waitlist-widget',
			STOREDASH_URL . 'assets/js/waitlist-widget.js',
			array(),
			STOREDASH_VERSION,
			true
		);

		wp_localize_script(
			'storedash-waitlist-widget',
			'storedashWaitlist',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'storedash_waitlist_submit' ),
				'strings'  => array(
					'error_general'   => __( 'An error occurred. Please try again.', 'storedash' ),
					'error_duplicate' => __( 'You\'re already on the waitlist for this product.', 'storedash' ),
					'error_email'     => __( 'Please enter a valid email address.', 'storedash' ),
					'error_name'      => __( 'Please enter your name.', 'storedash' ),
					'error_variation' => __( 'Please select a variation.', 'storedash' ),
				),
			)
		);

		wp_register_style(
			'storedash-enquiry-widget',
			STOREDASH_URL . 'assets/css/enquiry-widget.css',
			array(),
			STOREDASH_VERSION
		);

		wp_register_script(
			'storedash-enquiry-widget',
			STOREDASH_URL . 'assets/js/enquiry-widget.js',
			array(),
			STOREDASH_VERSION,
			true
		);

		wp_localize_script(
			'storedash-enquiry-widget',
			'storedashEnquiry',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'storedash_enquiry_submit' ),
				'strings'  => array(
					'error_general' => __( 'An error occurred. Please try again.', 'storedash' ),
					'error_email'   => __( 'Please enter a valid email address.', 'storedash' ),
					'error_message' => __( 'Please enter a message (at least 10 characters).', 'storedash' ),
					'submitting'    => __( 'Sending...', 'storedash' ),
					'success'       => __( 'Thank you! Your enquiry has been submitted.', 'storedash' ),
				),
			)
		);

		wp_register_style(
			'storedash-signup-widget',
			STOREDASH_URL . 'assets/css/signup-widget.css',
			array(),
			STOREDASH_VERSION
		);

		wp_register_script(
			'storedash-signup-widget',
			STOREDASH_URL . 'assets/js/signup-widget.js',
			array(),
			STOREDASH_VERSION,
			true
		);

		wp_localize_script(
			'storedash-signup-widget',
			'storedashSignup',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'storedash_optin_submit' ),
				'strings'  => array(
					'error_general' => __( 'An error occurred. Please try again.', 'storedash' ),
					'error_email'   => __( 'Please enter a valid email address.', 'storedash' ),
					'submitting'    => __( 'Subscribing...', 'storedash' ),
					'success'       => __( 'Thanks for subscribing!', 'storedash' ),
				),
			)
		);
	}
}

// Initialize
Widgets_Manager::instance();
