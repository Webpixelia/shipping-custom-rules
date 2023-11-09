<?php
if (!defined('ABSPATH')) exit;

/*
* Plugin Name: Shipping Custom Rules for WooCommerce
* Plugin URI: https://github.com/Webpixelia
* Description: Add specific rules for shipping with WooCommerce according flat weight, fixed price and kilo price
* Version: 1.0.5
* Author: Webpixelia
* Author URI: https://webpixelia.com/
* Requires PHP: 7.1
* Requires at least: 4.6
* Tested up to: 6.4
* WC requires at least: 5.0
* WC tested up to: 8.2
* License: GPLv2
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain: shipping-custom-rules
* Domain Path: /languages
*/

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
         * Initialize custom rules shipping.
         */
        public function init() {
            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables.
            $this->title  = $this->get_option( 'title' );
            $this->price_kilo = $this->get_option( 'price_kilo', 0 );
            $this->flat_weight = $this->get_option( 'flat_weight' );
            $this->fixed_price = $this->get_option( 'fixed_price', 0 );

            // Actions.
            add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        public function init_form_fields() {
            $this->instance_form_fields = array(
                'title' => array(
                    'title' => __( 'Method Title', 'shipping-custom-rules' ),
                    'type' => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'shipping-custom-rules' ),
                    'default' => $this->method_title,
                    'desc_tip' => true
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
                    'id' => $this->id,
                    'label'   => $this->title,
                    'cost'    => $fees . 'CC',
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