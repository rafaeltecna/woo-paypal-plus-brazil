<?php
/**
 * PayPal Plus Brazil API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_PayPal_Plus_Brazil_API {

	/**
	 * Gateway class.
	 *
	 * @var WC_PayPal_Plus_Brazil_Gateway
	 */
	protected $gateway;

	/**
	 * WC_PayPal_Plus_Brazil_API constructor.
	 *
	 * @param $gateway WC_PayPal_Plus_Brazil_Gateway
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Get the API environment.
	 *
	 * @return string
	 */
	protected function get_environment() {
		return ( 'yes' == $this->gateway->sandbox ) ? 'sandbox.' : '';
	}

	/**
	 * Get the payment URL.
	 *
	 * @return string.
	 */
	protected function get_payment_url() {
		return 'https://api.' . $this->get_environment() . 'paypal.com/v1/payments';
	}

	/**
	 * Get the token URL.
	 *
	 * @return string
	 */
	protected function get_token_url() {
		return 'https://api.' . $this->get_environment() . 'paypal.com/v1/oauth2/token';
	}

	/**
	 * Get the payment experience URL.
	 *
	 * @return string
	 */
	protected function get_payment_experience_url() {
		return 'https://api.' . $this->get_environment() . 'paypal.com/v1/payment-experience';
	}

	/**
	 * Make a request to API
	 *
	 * @param $url
	 * @param string $method
	 * @param array $data
	 * @param array $headers
	 *
	 * @return array|WP_Error
	 */
	protected function do_request( $url, $method = 'POST', $data = array(), $headers = array() ) {
		$params = array(
			'method'      => $method,
			'timeout'     => 60,
			'httpversion' => '1.1',
		);

		if ( 'POST' == $method && ! empty( $data ) ) {
			$params['body'] = $data;
		}

		if ( ! empty( $headers ) ) {
			$params['headers'] = $headers;
		}

		return wp_safe_remote_post( $url, $params );
	}

	/**
	 * Make a request to API with automatic access token.
	 *
	 * @param $url
	 * @param string $method
	 * @param array $data
	 * @param array $headers
	 * @param bool $bearer
	 *
	 * @return array|WP_Error
	 */
	protected function do_request_bearer( $url, $method = 'POST', $data = array(), $headers = array() ) {
		// Default headers.
		$default_headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $this->get_access_token(),
		);
		$headers         = wp_parse_args( $headers, $default_headers );

		// Check if data is serialized
		if ( is_array( $data ) ) {
			$data = json_encode( $data );
		}

		return $this->do_request( $url, $method, $data, $headers );
	}

	/**
	 * Get basic auth base64 encoded.
	 *
	 * @return string
	 */
	protected function get_basic_auth() {
		$auth = base64_encode( $this->gateway->client_id . ':' . $this->gateway->client_secret );

		return $auth;
	}

	/**
	 * Get access token to make requests.
	 *
	 * @return null|string
	 */
	public function get_access_token() {
		$access_token = get_transient( 'woo_paypal_plus_brazil_access_token' );

		// Return the saved access token if available.
		if ( false !== $access_token ) {
			return $access_token;
		}

		$headers  = array( 'Authorization' => 'Basic ' . $this->get_basic_auth() );
		$data     = array( 'grant_type' => 'client_credentials' );
		$response = $this->do_request( $this->get_token_url(), 'POST', $data, $headers );

		$this->gateway->log( 'Requesting to ' . $this->get_token_url() . ': ' . print_r( $data, true ) );

		if ( is_wp_error( $response ) ) {
			$this->gateway->log( 'WP_Error trying to get access token: ' . $response->get_error_message() );
		} else {
			$response_body = json_decode( $response['body'], true );
			$this->gateway->log( 'Response: ' . print_r( $response_body, true ) );

			if ( 200 == $response['response']['code'] ) {
				$this->gateway->log( 'Success getting access token.' );
				$access_token = $response_body['access_token'];
				$expires_in   = $response_body['expires_in'] - 50; // -50s to make sure that will be always fresh.

				// Save transient.
				set_transient( 'woo_paypal_plus_brazil_access_token', $access_token, $expires_in );

				return $access_token;
			} else if ( 401 === $response['response']['code'] ) {
				$this->gateway->log( 'Failed to authenticate with the cretentials.' );
			} else {
				$this->gateway->log( 'Error trying to get access token.' );
			}
		}

		return false;
	}

	/**
	 * Do payment request.
	 *
	 * @return bool|array
	 */
	public function do_payment_request( $user_info ) {
		$order_id = get_query_var( 'order-pay' );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		$cart     = WC()->cart;
		$customer = WC()->customer;
		$url      = $this->get_payment_url() . '/payment';
		$data     = array(
			'intent'                => 'sale',
			'payer'                 => array(
				'payment_method' => 'paypal',
			),
			'experience_profile_id' => $this->get_experience_profile_id(),
			'transactions'          => array(
				array(
					'amount'          => array(
						'currency' => 'BRL',
						'total'    => $this->money_format( $order ? $order->get_total() : $cart->total ),
						'details'  => array(
							'subtotal' => $this->money_format( $order ? $order->get_subtotal() - $order->get_total_discount() : $cart->subtotal_ex_tax - $cart->discount_cart ),
							'shipping' => $this->money_format( $order ? $order->order_shipping : $cart->shipping_total ),
							'tax'      => $this->money_format( $order ? $order->order_shipping_tax + $order->order_tax : $cart->shipping_tax_total + $cart->tax_total ),
						),
					),
					'payment_options' => array(
						'allowed_payment_method' => 'IMMEDIATE_PAY',
					),
					'item_list'       => array(
						'shipping_address' => array(
							'recipient_name' => $user_info['first_name'] . ' ' . $user_info['last_name'],
							'line1'          => $user_info['shipping_address'],
							'line2'          => $user_info['shipping_address_2'],
							'city'           => $order ? $order->shipping_city : $customer->get_shipping_city(),
							'country_code'   => $order ? $order->shipping_country : $customer->get_shipping_country(),
							'postal_code'    => $order ? $order->shipping_postcode : $customer->get_shipping_postcode(),
							'state'          => $order ? $order->shipping_state : $customer->get_shipping_state(),
						),
						'items'            => array(),
					),
				),
			),
			'redirect_urls'         => array(
				'return_url' => home_url(),
				'cancel_url' => home_url(),
			),
		);

		// Add cart items to request data
		$items = $order ? $order->get_items() : $cart->get_cart();
		foreach ( $items as $item ) {
			$product   = new WC_Product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
			$item_data = array(
				'sku'      => $product->get_id(),
				'name'     => $product->get_title(),
				'quantity' => $order ? $item['qty'] : $item['quantity'],
				'price'    => $this->money_format( $order ? $item['line_subtotal'] / $item['qty'] : $item['line_subtotal'] / $item['quantity'] ),
				'currency' => 'BRL',
				'url'      => $product->get_permalink(),
				'tax'      => $this->money_format( $order ? $item['line_tax'] / $item['qty'] : $item['line_tax'] / $item['quantity'] ),
			);

			$data['transactions'][0]['item_list']['items'][] = $item_data;
		}

		// If order has discount, add this as a item
		$has_discount = $order ? $order->get_total_discount() : $cart->has_discount();
		if ( $has_discount ) {
			$discount = $order ? $order->get_total_discount() : $cart->discount_cart;

			$data['transactions'][0]['item_list']['items'][] = array(
				'sku'      => 'discount',
				'name'     => __( 'Discount', 'woo-paypal-plus-brazil' ),
				'quantity' => 1,
				'price'    => $this->money_format( $discount * - 1 ),
				'currency' => 'BRL',
			);
		}

		$response = $this->do_request_bearer( $url, 'POST', $data );

		$this->gateway->log( 'Requesting to ' . $url . ': ' . print_r( $data, true ) );

		if ( is_wp_error( $response ) ) {
			$this->gateway->log( 'WP_Error trying to create order: ' . $response->get_error_message() );
		} else {
			$response_body = json_decode( $response['body'], true );
			$this->gateway->log( 'Response: ' . print_r( $response_body, true ) );

			if ( 201 == $response['response']['code'] ) {
				$this->gateway->log( 'Success creating order.' );

				return array(
					'id'           => $response_body['id'],
					'approval_url' => $response_body['links'][1]['href'],
				);
			} else if ( 401 === $response['response']['code'] ) {
				$this->gateway->log( 'Failed to authenticate with the cretentials.' );
			} else {
				$this->gateway->log( 'Error trying to create order.' );
			}
		}

		return false;
	}

	/**
	 * Process payment.
	 *
	 * @param $order WC_Order
	 * @param $payment_id
	 * @param $payer_id
	 * @param string $remembercards
	 *
	 * @return bool|array
	 */
	public function process_payment( $order, $payment_id, $payer_id, $remembercards ) {
		$url      = $this->get_payment_url() . '/payment/' . $payment_id . '/execute/';
		$data     = array( 'payer_id' => $payer_id );
		$response = $this->do_request_bearer( $url, 'POST', $data );

		$this->gateway->log( 'Requesting to ' . $url . ': ' . print_r( $data, true ) );

		if ( is_wp_error( $response ) ) {
			$this->gateway->log( 'WP_Error trying to execute payment: ' . $response->get_error_message() );
		} else {
			$response_body = json_decode( $response['body'], true );
			$this->gateway->log( 'Response: ' . print_r( $response_body, true ) );

			if ( 200 == $response['response']['code'] ) {
				$this->gateway->log( 'Success executing payment.' );
				$payment_state = $response_body['transactions'][0]['related_resources'][0]['sale']['state'];
				$payment_data  = array(
					'id'          => $response_body['id'],
					'intent'      => $response_body['intent'],
					'state'       => $response_body['state'],
					'cart'        => $response_body['cart'],
					'payer'       => array(
						'payment_method' => $response_body['payer']['payment_method'],
						'status'         => $response_body['payer']['status'],
					),
					'sale'        => array(
						'id'                          => $response_body['transactions'][0]['related_resources'][0]['sale']['id'],
						'state'                       => $response_body['transactions'][0]['related_resources'][0]['sale']['state'],
						'payment_mode'                => $response_body['transactions'][0]['related_resources'][0]['sale']['payment_mode'],
						'protection_eligibility'      => $response_body['transactions'][0]['related_resources'][0]['sale']['protection_eligibility'],
						'protection_eligibility_type' => $response_body['transactions'][0]['related_resources'][0]['sale']['protection_eligibility_type'],
						'transaction_fee'             => $response_body['transactions'][0]['related_resources'][0]['sale']['transaction_fee']['value'],
					),
					'create_time' => $response_body['create_time'],
				);
				update_post_meta( $order->id, '_wc_paypal_plus_payment_data', $payment_data );
				update_post_meta( $order->id, '_wc_paypal_plus_payment_id', $payment_data['id'] );
				update_post_meta( $order->id, '_wc_paypal_plus_payment_sale_id', $payment_data['sale']['id'] );
				update_post_meta( $order->id, '_wc_paypal_plus_payment_sale_fee', $payment_data['sale']['transaction_fee'] );
				if ( 'yes' == $this->gateway->sandbox ) {
					update_post_meta( $order->id, '_wc_paypal_plus_payment_sandbox', 'yes' );
				}
				if ( $user_id = $order->get_user_id() ) {
					update_user_meta( $user_id, 'paypal_plus_remembered_cards', $remembercards );
				}
				if ( $payment_state === 'completed' ) {
					$this->gateway->log( 'Payment completed.' );

					return array(
						'status' => 'completed',
						'data'   => $response_body,
					);
				} else if ( $payment_state === 'pending' ) {
					$this->gateway->log( 'Payment is pending.' );

					return array(
						'status' => 'pending',
						'data'   => '',
					);
				} else if ( $payment_state === 'denied' ) {
					$this->gateway->log( 'Payment was denied.' );

					return array(
						'status' => 'denied',
						'data'   => '',
					);
				} else {
					$this->gateway->log( 'The payment could not be processed. Status: ' . $response_body['transactions'][0]['related_resources'][0]['sale']['state'] );

					return array(
						'status' => 'error',
						'data'   => '',
					);
				}
			} else if ( 401 === $response['response']['code'] ) {
				$this->gateway->log( 'Failed to authenticate with the cretentials.' );
			} else {
				$this->gateway->log( 'Error trying to process order.' );
			}
		}

		WC()->cart->empty_cart();

		return array(
			'status' => 'error',
			'data'   => '',
		);
	}

	/**
	 * Get Experience Profile ID or create one if don't have.
	 *
	 * @return mixed
	 */
	public function get_experience_profile_id() {
		return $this->gateway->experience_profile_id;
	}

	/**
	 * Create Web Profile Experience
	 *
	 * @param array $args Arguments to create Web Experience Profile.
	 *
	 * @return array|bool
	 */
	public function create_web_experience( $args = array() ) {
		$default_args = array(
			'name'         => get_bloginfo( 'name' ),
			'presentation' => array(
				'brand_name'  => get_bloginfo( 'name' ),
				'locale_code' => 'BR',
			),
			'input_fields' => array(
				'no_shipping'      => 0,
				'address_override' => 1,
			),
		);
		$data         = wp_parse_args( $args, $default_args );
		$url          = $this->get_payment_experience_url() . '/web-profiles/';
		$response     = $this->do_request_bearer( $url, 'POST', $data );

		$this->gateway->log( 'Requesting to ' . $url . ': ' . print_r( $data, true ) );

		if ( is_wp_error( $response ) ) {
			$this->gateway->log( 'WP_Error trying to create web experience profile: ' . $response->get_error_message() );
		} else {
			$response_body = json_decode( $response['body'], true );
			$this->gateway->log( 'Response: ' . print_r( $response_body, true ) );

			if ( 201 == $response['response']['code'] ) {
				$this->gateway->log( 'Success creating web experience profile.' );

				return $response_body;
			} else if ( 401 === $response['response']['code'] ) {
				$this->gateway->log( 'Failed to authenticate with the cretentials.' );
			} else {
				$this->gateway->log( 'Error trying to create web experience profile.' );
			}
		}

		return false;
	}

	/**
	 * Delete web experience profile.
	 *
	 * @param string $profile_id Web Experience Profile ID
	 *
	 * @return bool|array
	 */
	public function delete_web_experience_profile( $profile_id ) {
		$url      = $this->get_payment_experience_url() . '/' . $profile_id;
		$response = $this->do_request_bearer( $url, 'DELETE' );

		$this->gateway->log( 'Requesting to ' . $url );

		if ( is_wp_error( $response ) ) {
			$this->gateway->log( 'WP_Error trying to delete web experience profile: ' . $response->get_error_message() );
		} else {
			$response_body = json_decode( $response['body'], true );
			$this->gateway->log( 'Response: ' . print_r( $response_body, true ) );

			if ( 204 == $response['response']['code'] ) {
				$this->gateway->log( 'Success deleting web experience profile.' );

				return $response_body;
			} else if ( 401 === $response['response']['code'] ) {
				$this->gateway->log( 'Failed to authenticate with the cretentials.' );
			} else {
				$this->gateway->log( 'Error trying to delete web experience profile.' );
			}
		}

		return false;
	}

	/**
	 * Money format.
	 *
	 * @param  int /float $value Value to fix.
	 *
	 * @return float            Fixed value.
	 */
	protected function money_format( $value ) {
		return number_format( $value, 2, '.', '' );
	}

}
