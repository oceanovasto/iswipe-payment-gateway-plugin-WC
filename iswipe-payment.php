<?php
/*
Plugin Name: WooCommerce iSwipe Gateway
Plugin URI: https://iswipe.net
Description: Extends WooCommerce with an iSwipe gateway.
Version: 1.01
Author: Clic Technology Corp.
Author URI: https://iswipe.net
*/

if (!defined( 'ABSPATH' )) exit; // Exit if accessed directly

add_action('plugins_loaded', 'init_iswipe_Payment_Gateway', 0);
function init_iswipe_Payment_Gateway() {

    if(!class_exists('WC_Payment_Gateway')) return;

    class WC_iSwipe_Payment_Gateway extends WC_Payment_Gateway{

        public function __construct(){

            /**
             * add bandgee.js and iswipe-style.css
             */
            wp_register_style('iswipe-style', plugins_url( 'iswipe-style.css', __FILE__ ), array(), null);
            wp_enqueue_style('iswipe-style');
            wp_enqueue_script('bandge', 'https://widget.iswipe.net/checkout/widget.js', array('jquery'), null);

            $this->id                 = 'iswipe';
            $this->has_fields         = false;
            $this->method_title       = 'iSwipe Gataway';
            $this->method_description = 'iSwipe Gataway';
            $this->icon               = apply_filters('woocommerce_iswipe_icon', plugins_url( 'iswipe.svg', __FILE__ ));
            $this->init_form_fields();
            $this->init_settings();

            // Load settings
            $this->enabled         = $this->get_option( 'enabled' );
            $this->title           = $this->get_option( 'title' );
            $this->description     = $this->get_option( 'description' );
            $this->public_api_key  = $this->get_option( 'public_api_key' );
            $this->private_api_key = $this->get_option( 'private_api_key' );

            // Actions
            add_action('woocommerce_receipt_'. $this->id, array( $this, 'receipt_page' ) );
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_wc_iswipe_payment_gateway', array($this, 'check_ipn_response'));
        }

        public function init_form_fields(){

            $this->form_fields = array(
                'enabled' => array(
                    'title'       => __( 'On/Off', 'iswipe_payment' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'On/Off plugin', 'iswipe_payment' ),
                    'default'     => 'yes'
                ),
                'title' => array(
                    'title'       => __( 'Title', 'iswipe_payment' ),
                    'type'        => 'text',
                    'description' => __( 'The title that appears on the checkout page', 'iswipe_payment' ),
                    'default'     => 'iSwipe Gataway',
                    'desc_tip'    => true,
                ),
                'description'     => array(
                    'title'       => __( 'Description', 'iswipe_payment' ),
                    'type'        => 'textarea',
                    'description' => __( 'The description that appears during the payment method selection process', 'iswipe_payment' ),
                    'default'     => __( 'Pay through the payment gateway iSwipe', 'iswipe_payment' ),
                ),
                'public_api_key'  => array(
                    'title'       => __( 'Public API Key', 'iswipe_payment' ),
                    'type'        => 'text',
                    'description' => __( 'Public API Key in iSwipe system.', 'iswipe_payment' ),
                    'default'     => '',
                ),
                'private_api_key' => array(
                    'title'       => __( 'Private API Key', 'iswipe_payment' ),
                    'type'        => 'text',
                    'description' => __( 'Private API Key in iSwipe system.', 'iswipe_payment' ),
                    'default'     => '',
                )
            );

            return true;
        }

        function process_payment($order_id) {
            $order = new WC_Order($order_id);
            return array(
                'result'    => 'success',
                'redirect'  => add_query_arg('order', $order_id, add_query_arg('key', $order->order_key, get_permalink(wc_get_page_id('pay'))))
            );
        }

        public function receipt_page($order) {
            echo '<p>' . __('Thank you for your order, please click the button below to pay.', 'woocommerce') . '</p>';
            echo $this->generate_form($order);
        }

        public function generate_form($order_id) {
            $order = new WC_Order( $order_id );
            global $woocommerce;

            // Mark as on-hold (we're awaiting the payment)
            $order->update_status('on-hold', __( 'Awaiting payment response', 'woocommerce' ));

            // Remove cart
            $woocommerce->cart->empty_cart();

            // Prepare payment request`s data
            $args = array(
                'amount'           => $order->order_total,
                'currency'         => get_woocommerce_currency(),
                'public_api_key'   => $this->public_api_key,
                'order'            => $order_id,
                'pay_way'          => 'iSwipe'
            );

            return '<div id="modal">' .
                '<div class="uswipe-badge" id="uswipe-badge">' .
                '</div>' .
                '</div>' .
                '<a href="#" class="btn btn-md btn-primary btn-buy" id="btn-buy">buy</a>' .
                '<script>' .
                'jQuery(document).ready(function() {' .
                'var btn = document.getElementById("btn-buy");' .
                'var modal = document.getElementById("modal");' .
                'var modal_widget = document.getElementById("uswipe-badge");' .
                'btn.onclick = function() {' .
                'modal.style.position = "fixed";' .
                'modal.style.background = "#0000007a";' .
                'modal.style.display = "block";' .
                '};' .
                'window.onclick = function(event) {' .
                'if (event.target == modal_widget) {' .
                'modal.style.display = "none";' .
                '}' .
                '}' .
                '});' .
                'document.querySelector(".btn-buy").addEventListener("click", buttonClick);' .
                'function buttonClick() {' .
                'var uswipe = UswipeWidget({' .
                'amount:' . $args["amount"] .',' .
                'currency:"' . $args["currency"] . '"' .
                //'orderId:' . $args["order"] .
                '}, "uswipe-badge")("' . $args["public_api_key"] . '");' .
                'uswipe.addListener("success", function () {' .
                'document.querySelector(".info").innerHTML = "Thank you for choosing our shop! Check your order status!"' .
                '});' .
                'uswipe.addListener("failed", function () {' .
                'document.querySelector(".info").innerHTML = "Please try again in order to finalize your order!";' .
                '});' .
                '}' .
                '</script>';
        }


        /**
         * When we have a payment`s response
         */
        function check_ipn_response(){

            // Get the Private api key from response
            $private_api_key_request = $_SERVER['HTTP_ISWIPE_AUTHORIZATION'] ? $_SERVER['HTTP_ISWIPE_AUTHORIZATION'] : '';

            // Get the Private api key from db
            $private_api_key_client = $this->get_option('private_api_key');

            // Get orderId and order status
            $request = json_decode(file_get_contents('php://input'), true);
            $response_order_id = +$request['orderId'];
            $response_order_status = $request['status'] === 'success' ? 'processing' : 'cancelled';

            if (isset($request['orderId']) && $request['orderId'] !== '' && isset($request['status']) // check response data
                && $request['status'] !== '' && $private_api_key_request && $private_api_key_client
                && $private_api_key_request === $private_api_key_client) {

                $order = new WC_Order($response_order_id);
                $order->update_status($response_order_status);
                $order->add_order_note( __('Order status: ', 'iswipe_payment') . $response_order_status );

            } else {
                wp_die('IPN request failed!');
            }
        }

    }

}

add_action('plugins_loaded', 'iswipe_payment_load_textdomain');
function iswipe_payment_load_textdomain() {
    load_plugin_textdomain( 'iswipe-payment', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

add_filter( 'woocommerce_payment_gateways', 'add_WC_iswipe_Payment_Gateway' );
function add_WC_iswipe_Payment_Gateway( $methods ){
    $methods[] = 'WC_iswipe_Payment_Gateway';
    return $methods;
}
?>