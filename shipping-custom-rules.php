<?php
/*
* Plugin Name: Shipping Custom Rules for WooCommerce
* Plugin URI: https://github.com/Webpixelia
* Description: Add specific rules for shipping with WooCommerce according flat weight, fixed price and kilo price
* Version: 1.0.6
* Author: Webpixelia
* Author URI: https://webpixelia.com/
* Requires PHP: 7.3
* Requires at least: 5.0
* Tested up to: 6.4
* WC requires at least: 5.0
* WC tested up to: 8.3
* License: GPLv2 or later
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain: shipping-custom-rules
* Domain Path: /languages
*/

if (!defined('ABSPATH')) exit;

define( 'WSCR_SHIPPING_CUSTOM_VERSION', '1.0.6' );

// Check if WooCommerce is activated
if (!in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    add_action('admin_notices', 'wcscr_woocommerce_not_active_notice');

    function wcscr_woocommerce_not_active_notice() {
        deactivate_plugins(plugin_basename(__FILE__));
        echo '<div class="error"><p>';
        echo __('WooCommerce is not active. Please install and/or activate WooCommerce to use the plugin Shipping custom rules.', 'shipping-custom-rules');
        echo '</p></div>';
    }
}

// Use the 'woocommerce_shipping_init' action to ensure WooCommerce is initialized
add_action('woocommerce_shipping_init', 'custom_rules_shipping_init');

function custom_rules_shipping_init() {
    class WCSCR_Shipping_Custom_Method extends WC_Shipping_Method {
        
        /**
         * User set variables
         * @var float $price_kilo
         * @var float $flat_weight
         * @var float $fixed_price
         * @since 1.0.5
         */
        public $price_kilo;
        public $flat_weight;
        public $fixed_price;


        /**
         * Construct.
         *
         * Initialize the class and plugin.
         *
         * @since 1.0.0
         */
        public function __construct($instance_id = 0) {
            $this->id = 'wcscr_custom_rules_shipping';
            $this->instance_id = absint($instance_id);
            $this->method_title = __('Shipping custom rules', 'shipping-custom-rules');
            $this->method_description = __('Shipping method to be used with custom weight and price: fixed price plus price per kilo', 'shipping-custom-rules');
            $this->title = __('Shipping custom rules', 'shipping-custom-rules');
            $this->supports = array(
                'shipping-zones',
                'instance-settings',
                'instance-settings-modal',
            );
            
            $this->init();
        }

        /**
         * Init.
         *
         * Initialize plugin parts.
         *
         * @since 1.0.0
         */
        public function init() {
            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables.
            $this->title  = $this->get_option( 'title' );
            $this->tax_status = $this->get_option( 'tax_status' );
            $this->price_kilo = $this->get_option( 'price_kilo', 0 );
            $this->flat_weight = $this->get_option( 'flat_weight' );
            $this->fixed_price = $this->get_option( 'fixed_price', 0 );

            // Actions.
            add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        /**
         * Initializes the form fields for the shipping method settings.
         *
         * This method defines the fields that will be displayed in the shipping method settings page in the WordPress admin area.
         * It includes fields for the method title, tax status, price per additional kilo, flat weight, and fixed price.
         */
        public function init_form_fields() {
            $this->instance_form_fields = array(
                'title' => array(
                    'title' => __( 'Method Title', 'shipping-custom-rules' ),
                    'type' => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'shipping-custom-rules' ),
                    'default' => $this->method_title,
                    'desc_tip' => true
                ),
                'tax_status' => array(
                    'title'   => __( 'Tax status', 'shipping-custom-rules' ),
                    'type'    => 'select',
                    'class'   => 'wc-enhanced-select',
                    'default' => 'taxable',
                    'options' => array(
                        'taxable' => __( 'Taxable', 'shipping-custom-rules' ),
                        'none'    => _x( 'None', 'Tax status', 'shipping-custom-rules' ),
                    ),
                ),
                'price_kilo'       => array(
                    'title'       => __( 'Price of extra kilo', 'shipping-custom-rules' ),
                    'type'        => 'price',
                    'placeholder' => wc_format_localized_price( 0 ),
                    'description' => __( 'Enter a price for all extra kilo (kg)', 'shipping-custom-rules' ),
                    'default'     => '2',
                    'desc_tip'    => true,
                ),
                'flat_weight' => array(
                    'title' => __( 'Flat weight', 'shipping-custom-rules' ),
                    'type' => 'number',
                    'description' => __( 'Enter a flat weight', 'shipping-custom-rules' ),
                    'default' => '10',
                    'desc_tip' => true,
                ),
                'fixed_price'       => array(
                    'title'       => __( 'Price for flat weight', 'shipping-custom-rules' ),
                    'type'        => 'price',
                    'placeholder' => wc_format_localized_price( 0 ),
                    'description' => __( 'Enter a fixed price for flat weight', 'shipping-custom-rules' ),
                    'default'     => '10',
                    'desc_tip'    => true,
                )
                
            );
        }

        /**
         * Get setting form fields for instances of this shipping method within zones.
         *
         * @return array
         */
        public function get_instance_form_fields() {
            return parent::get_instance_form_fields();
        }

        /**
         * Calculates the custom shipping cost for the given package.
         *
         * @param array $package The package containing the cart items and shipping details.
         * @return float The calculated shipping cost.
         */
        private function wcscr_calculate_custom_shipping_cost( $package ) {
            $fees = 0;
        
            // Collect the total weight of the package
            $total_weight = 0;
            foreach ( $package['contents'] as $item_id => $values ) {
                $product = $values['data'];
                $weight = floatval($product->get_weight()) * $values['quantity'];
                $total_weight += $weight;
            }
        
            // Set personalized rates
            $base_rate = $this->fixed_price;
            $base_weight = $this->flat_weight;
            $rate_per_additional_kilo = $this->price_kilo;
        
            // Calculating shipping costs based on weight
            if ( $total_weight <= $base_weight ) {
                $fees = $base_rate;
            } else {
                $weight_add = $total_weight - $base_weight;
                $fees = $base_rate + ( $weight_add * $rate_per_additional_kilo );
            }

            return $fees;
        }

        /**
         * Called to calculate shipping rates for this method. Rates can be added using the add_rate() method.
         *
         * @uses WC_Shipping_Method::add_rate()
         *
         * @param array $package Shipping package.
         */
        public function calculate_shipping( $package = array() ) {
            $fees = $this->wcscr_calculate_custom_shipping_cost($package);

            $this->add_rate(
                array(
                    'title'      => $this->title,
                    'label'   => $this->title,
                    'cost'    => $fees,
                )
            );
        }
    }
    add_filter('woocommerce_shipping_methods', 'wcscr_add_custom_rules_shipping');
}

function wcscr_add_custom_rules_shipping($methods) {
    $methods['wcscr_custom_rules_shipping'] = 'WCSCR_Shipping_Custom_Method';
    return $methods;
}


/**
 * Setup WooCommerce HPOS compatibility.
 */
add_action(
	'before_woocommerce_init',
	function() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);