<?php
/**
 * Plugin Name: Ethereum Payments for WooCommerce
 * Plugin URI: https://www.ethereumpos.com
 * Description: Accept Ethereum Payments in your WooCommerce shopping cart
 * Author: Ethereum POS
 * Author URI: https://ethereumpos.com
 * Version: 0.0.1
 * Text Domain: ethereum-for-woocommerce
 * Domain Path: /i18n/languages/
 *
 * Copyright: (c) 2017 Ethereum POS
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   ethereum-for-woocommerce
 * @author    Ethereum POS
 * @category  Admin
 * @copyright Copyright (c) 2017, Ethereum POS
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 *
 * This offline gateway forks the WooCommerce core "Cheque" payment gateway to create another Ethereum Payment method.
 */

defined('ABSPATH') or exit;


// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}


/**
 * Add the gateway to WC Available Gateways
 *
 * @since 1.0.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + offline gateway
 */
function wc_ethereum_add_to_gateways($gateways)
{
    $gateways[] = 'WC_Gateway_Ethereum';
    return $gateways;
}
add_filter('woocommerce_payment_gateways', 'wc_ethereum_add_to_gateways');


/**
 * Adds plugin page links
 *
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function wc_ethereum_gateway_plugin_links($links)
{

    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=ethereum_gateway') . '">' . __('Configure', 'ethereum-for-woocommerce') . '</a>'
    );

    return array_merge($plugin_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_ethereum_gateway_plugin_links');



add_action('init', 'ethereum_callback_page');

function ethereum_callback_page()
{
    add_feed('ethereum_callback', 'ethereum_callback');
}

function ethereum_callback()
{
    $data   = json_decode(file_get_contents('php://input'), true);
    $secret = $data['secret'];
    $refid  = $data['ref_id'];
    $txid   = $data['transaction_id'];
    $status = $data['status'];

    $order = wc_get_order($refid);

    if ($status == "paid") {
        $order->reduce_order_stock();
				$order->add_order_note( __('Ethereum Payment Paid. Transaction ID: ' . $txid, 'ethereum-for-woocommerce'),1);
				$order->update_status('processing', 'ethereum-for-woocommerce');
    } else if ($status == "complete") {
				$order->add_order_note( __('Ethereum Payment Complete', 'ethereum-for-woocommerce'),1);
    }


    echo "<p>It works! $refid and $txid </p>";
}


/**
 * Ethereum Payment Gateway
 *
 * Provides an Ethereum Payment Gateway; mainly for testing purposes.
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class         WC_Gateway_Ethereum
 * @extends        WC_Payment_Gateway
 * @version        1.0.0
 * @package        WooCommerce/Classes/Payment
 * @author         Ethereum POS
 */
add_action('plugins_loaded', 'wc_ethereum_gateway_init', 11);

function wc_ethereum_gateway_init()
{

    class WC_Gateway_Ethereum extends WC_Payment_Gateway
    {

        /**
         * Constructor for the gateway.
         */
        public function __construct()
        {

            $this->id                 = 'ethereum_gateway';
            $this->icon               = apply_filters('woocommerce_offline_icon', '');
            $this->has_fields         = false;
            $this->method_title       = __('Ethereum', 'ethereum-for-woocommerce');
            $this->method_description = __('Allows Ethereum Payments. Very handy if you use your cheque gateway for another payment method, and can help with testing. Orders are marked as "on-hold" when received.', 'ethereum-for-woocommerce');

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title        = $this->get_option('title');
            $this->description  = $this->get_option('description');
            $this->testmode     = $this->get_option('testmode');
            $this->public_key   = $this->get_option('public_key');
            $this->private_key  = $this->get_option('private_key');
						$this->address  = $this->get_option('address');

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options'
            ));
            add_action('woocommerce_thankyou_order_received_text', array(
                $this,
                'thankyou_page'
            ));

            // Customer Emails
            add_action('woocommerce_email_before_order_table', array(
                $this,
                'email_instructions'
            ), 10, 3);
        }




        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields()
        {

            $this->form_fields = apply_filters('wc_offline_form_fields', array(

                'enabled' => array(
                    'title' => __('Enable/Disable', 'ethereum-for-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable Ethereum Payment', 'ethereum-for-woocommerce'),
                    'default' => 'yes'
                ),

                'testmode' => array(
                    'title' => __('Use Testnet', 'ethereum-for-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable Testmode using Ropsten Ethereum Testnet', 'ethereum-for-woocommerce'),
                    'default' => 'yes'
                ),

                'public_key' => array(
                    'title' => __('Public API Key', 'ethereum-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title for the payment method the customer sees during checkout.', 'ethereum-for-woocommerce'),
                    'default' => __('', 'ethereum-for-woocommerce'),
                    'desc_tip' => true
                ),

                'private_key' => array(
                    'title' => __('Secret API Key', 'ethereum-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('Payment method description that the customer will see on your checkout.', 'ethereum-for-woocommerce'),
                    'default' => __('', 'ethereum-for-woocommerce'),
                    'desc_tip' => true
                ),

                'address' => array(
                    'title' => __('Ethereum Address', 'ethereum-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('Instructions that will be added to the thank you page and emails.', 'ethereum-for-woocommerce'),
                    'default' => '',
                    'desc_tip' => true
                )
            ));
        }


        /**
         * Output for the order received page.
         */
        public function thankyou_page()
        {
            $eth_id = $_GET['eth_id'];
						$added_text = "<iframe src=\"https://ethereumpos.com/payment/$eth_id\" style=\"width: 100%;height: 310px;border: 0;\"></iframe>";
						return $added_text;
        }


        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         */
        public function email_instructions($order, $sent_to_admin, $plain_text = false)
        {

            if ($this->instructions && !$sent_to_admin && $this->id === $order->payment_method && $order->has_status('on-hold')) {
                echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
            }
        }


        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id)
        {

            $order = wc_get_order($order_id);

            $post_url = 'https://testapi.ethereumpos.com/order';

            $oid      = $order->get_order_number();
            $amount   = $order->get_total();
            $callback = get_site_url() . '/ethereum_callback';
						$myaddress = $this->address;

            $arg_data = array(
                'ref_id' => $oid,
                'amount' => $amount,
                'address' => $myaddress,
                'callback' => $callback
            );
            $data     = json_encode($arg_data);

            $auth = $this->private_key;

            $args = array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => $auth
                ),
                'body' => $data
            );

            $response = wp_remote_post(esc_url_raw($post_url), $args);

            $response_body = wp_remote_retrieve_body($response);

            $result = json_decode($response_body);

            // Mark as on-hold (we're awaiting the payment)
            $order->update_status('pending', __('Awaiting Ethereum Payment', 'ethereum-for-woocommerce'));

            // Remove cart
            WC()->cart->empty_cart();

						$ethoid = $result->id;
						$ethaddr = $result->address;
						$ethamount = $result->expected_amount;

            $payload = array(
                "eth_id" => $ethoid,
								"eth_amount" => $ethamount,
                "eth_address" => $ethaddr
            );

						$order->add_order_note( __('EthereumPOS.com Order ID: ' . $ethoid . ' Pay Exactly ' . $ethamount . ' ETH to Address: ' . $ethaddr, 'ethereum-for-woocommerce'),1);

            $querystring = http_build_query($payload);

            // Return thankyou redirect
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order) . '&' . $querystring
            );
        }

    } // end \WC_Gateway_Ethereum class
}

?>
