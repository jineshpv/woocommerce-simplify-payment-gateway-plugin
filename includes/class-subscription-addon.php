<?php
/*
 * Copyright (c) 2013 - 2017 Mastercard International Incorporated
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification, are
 * permitted provided that the following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this list of
 * conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of
 * conditions and the following disclaimer in the documentation and/or other materials
 * provided with the distribution.
 * Neither the name of the Mastercard International Incorporated nor the names of its
 * contributors may be used to endorse or promote products derived from this software
 * without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY
 * EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES
 * OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT
 * SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED
 * TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS;
 * OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER
 * IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING
 * IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF
 * SUCH DAMAGE.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Simplify Commerce Gateway for subscriptions.
 *
 * @class 		WC_Addons_Gateway_Simplify_Commerce
 * @extends		WC_Gateway_Simplify_Commerce
 * @version		1.3.0
 * @author 		SimplifyCommerce
 */
class WC_Addons_Gateway_Simplify_Commerce extends WC_Gateway_Simplify_Commerce {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();

		if ( class_exists( 'WC_Subscriptions_Order' ) ) {
			add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
			add_action( 'woocommerce_subscription_failing_payment_method_updated_' . $this->id, array( $this, 'update_failing_payment_method' ), 10, 2 );

			add_action( 'wcs_resubscribe_order_created', array( $this, 'delete_resubscribe_meta' ), 10 );

			// Allow store managers to manually set Simplify as the payment method on a subscription
			add_filter( 'woocommerce_subscription_payment_meta', array( $this, 'add_subscription_payment_meta' ), 10, 2 );
			add_filter( 'woocommerce_subscription_validate_payment_meta', array( $this, 'validate_subscription_payment_meta' ), 10, 2 );
		}

		if ( class_exists( 'WC_Pre_Orders_Order' ) ) {
			add_action( 'wc_pre_orders_process_pre_order_completion_payment_' . $this->id, array( $this, 'process_pre_order_release_payment' ) );
		}

		add_filter( 'woocommerce_simplify_commerce_hosted_args', array( $this, 'hosted_payment_args' ), 10, 2 );
		add_action( 'woocommerce_api_wc_addons_gateway_simplify_commerce', array( $this, 'return_handler' ) );
		add_action( 'woocommerce_api_wc_gateway_simplify_commerce', array( $this, 'return_handler' ) );
	}

	/**
	 * Hosted payment args.
	 *
	 * @param  array $args
	 * @param  int   $order_id
	 * @return array
	 */
	public function hosted_payment_args( $args, $order_id ) {
		if ( ( $this->order_contains_subscription( $order_id ) ) || ( $this->order_contains_pre_order( $order_id ) && WC_Pre_Orders_Order::order_requires_payment_tokenization( $order_id ) ) ) {
			$args['operation'] = 'create.token';
		}

		$args['redirect-url'] = WC()->api_request_url( 'WC_Addons_Gateway_Simplify_Commerce' );

		return $args;
	}

	/**
	 * Check if order contains subscriptions.
	 *
	 * @param  int $order_id
	 * @return bool
	 */
	protected function order_contains_subscription( $order_id ) {
		return function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) );
	}

	/**
	 * Check if order contains pre-orders.
	 *
	 * @param  int $order_id
	 * @return bool
	 */
	protected function order_contains_pre_order( $order_id ) {
		return class_exists( 'WC_Pre_Orders_Order' ) && WC_Pre_Orders_Order::order_contains_pre_order( $order_id );
	}

	/**
	 * Process the subscription.
	 *
	 * @param  WC_Order $order
	 * @param  string   $cart_token
	 * @uses   Simplify_ApiException
	 * @uses   Simplify_BadRequestException
	 * @return array
	 * @throws Exception
	 */
	protected function process_subscription( $order, $customer_token_value = '' ) {
		try {
			if ( empty( $customer_token_value ) ) {
				$error_msg = __( 'Please make sure your card details have been entered correctly and that your browser supports JavaScript.', 'woocommerce' );

				if ( 'yes' == $this->sandbox ) {
					$error_msg .= ' ' . __( 'Developers: Please make sure that you\'re including jQuery and there are no JavaScript errors on the page.', 'woocommerce' );
				}

				throw new Simplify_ApiException( $error_msg );
			}

			$this->save_subscription_meta( $order->id, $customer_token_value );

			$payment_response = $this->process_subscription_payment( $order, $order->get_total() );

			if ( is_wp_error( $payment_response ) ) {
				throw new Exception( $payment_response->get_error_message() );
			} else {
				// Remove cart
				WC()->cart->empty_cart();

				// Return thank you page redirect
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order )
				);
			}

		} catch ( Simplify_ApiException $e ) {
			if ( $e instanceof Simplify_BadRequestException && $e->hasFieldErrors() && $e->getFieldErrors() ) {
				foreach ( $e->getFieldErrors() as $error ) {
					wc_add_notice( $error->getFieldName() . ': "' . $error->getMessage() . '" (' . $error->getErrorCode() . ')', 'error' );
				}
			} else {
				wc_add_notice( $e->getMessage(), 'error' );
			}

			return array(
				'result'   => 'fail',
				'redirect' => ''
			);
		}
	}

	/**
	 * Store the customer and card IDs on the order and subscriptions in the order.
	 *
	 * @param int $order_id
	 * @param string $customer_id
	 */
	protected function save_subscription_meta( $order_id, $customer_id ) {

		$customer_id = wc_clean( $customer_id );

		update_post_meta( $order_id, '_simplify_customer_id', $customer_id );

		// Also store it on the subscriptions being purchased in the order
		foreach( wcs_get_subscriptions_for_order( $order_id ) as $subscription ) {
			update_post_meta( $subscription->id, '_simplify_customer_id', $customer_id );
		}
	}

	/**
	 * Process the pre-order.
	 *
	 * @param WC_Order $order
	 * @param string   $cart_token
	 * @uses  Simplify_ApiException
	 * @uses  Simplify_BadRequestException
	 * @return array
	 */
	protected function process_pre_order( $order, $customer_token_value = '' ) {
		if ( WC_Pre_Orders_Order::order_requires_payment_tokenization( $order->id ) ) {

			try {
				if ( $order->order_total * 100 < 50 ) {
					$error_msg = __( 'Sorry, the minimum allowed order total is 0.50 to use this payment method.', 'woocommerce' );

					throw new Simplify_ApiException( $error_msg );
				}

				if ( empty( $customer_token_value ) ) {
					$error_msg = __( 'Please make sure your card details have been entered correctly and that your browser supports JavaScript.', 'woocommerce' );

					if ( 'yes' == $this->sandbox ) {
						$error_msg .= ' ' . __( 'Developers: Please make sure that you\'re including jQuery and there are no JavaScript errors on the page.', 'woocommerce' );
					}

					throw new Simplify_ApiException( $error_msg );
				}

				// Store the customer ID in the order
				update_post_meta( $order->id, '_simplify_customer_id', $customer_token_value );

				// Reduce stock levels
				$order->reduce_order_stock();

				// Remove cart
				WC()->cart->empty_cart();

				// Is pre ordered!
				WC_Pre_Orders_Order::mark_order_as_pre_ordered( $order );

				// Return thank you page redirect
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order )
				);

			} catch ( Simplify_ApiException $e ) {
				if ( $e instanceof Simplify_BadRequestException && $e->hasFieldErrors() && $e->getFieldErrors() ) {
					foreach ( $e->getFieldErrors() as $error ) {
						wc_add_notice( $error->getFieldName() . ': "' . $error->getMessage() . '" (' . $error->getErrorCode() . ')', 'error' );
					}
				} else {
					wc_add_notice( $e->getMessage(), 'error' );
				}

				return array(
					'result'   => 'fail',
					'redirect' => ''
				);
			}

		} else {
			return parent::process_standard_payments( $order, '', $customer_token_value );
		}
	}

	/**
	 * Process the payment.
	 *
	 * @param  int $order_id
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order      = wc_get_order( $order_id );

		$customer_token_value = $this->get_customer_token($order);

		// Processing subscription
		if ( 'standard' == $this->mode && ( $this->order_contains_subscription( $order->id ) || ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order_id ) ) ) ) {
			return $this->process_subscription( $order, $customer_token_value );

		// Processing pre-order
		} elseif ( 'standard' == $this->mode && $this->order_contains_pre_order( $order->id ) ) {
			return $this->process_pre_order( $order, $customer_token_value );

		// Processing regular product
		} else {
			// Payment/CC form is hosted on Simplify
			if ( 'hosted' === $this->mode ) {
				return $this->process_hosted_payments( $order );
			}

			return $this->process_standard_payments( $order, '', $customer_token_value );
		}
	}

	/**
	 * process_subscription_payment function.
	 *
	 * @param WC_order $order
	 * @param int $amount (default: 0)
	 * @uses  Simplify_BadRequestException
	 * @return bool|WP_Error
	 */
	public function process_subscription_payment( $order, $amount = 0 ) {
		if ( 0 == $amount ) {
			// Payment complete
			$order->payment_complete();

			return true;
		}

		if ( $amount * 100 < 50 ) {
			return new WP_Error( 'simplify_error', __( 'Sorry, the minimum allowed order total is 0.50 to use this payment method.', 'woocommerce' ) );
		}

		$customer_id = get_post_meta( $order->id, '_simplify_customer_id', true );

		if ( ! $customer_id ) {
			return new WP_Error( 'simplify_error', __( 'Customer not found', 'woocommerce' ) );
		}

		try {
			// Charge the customer
			$payment = Simplify_Payment::createPayment( array(
				'amount'              => $amount * 100, // In cents.
				'customer'            => $customer_id,
				'description'         => sprintf( __( '%s - Order #%s', 'woocommerce' ), esc_html( get_bloginfo( 'name', 'display' ) ), $order->get_order_number() ),
				'currency'            => strtoupper( get_woocommerce_currency() ),
				'reference'           => $order->id
			) );

		} catch ( Exception $e ) {

			$error_message = $e->getMessage();

			if ( $e instanceof Simplify_BadRequestException && $e->hasFieldErrors() && $e->getFieldErrors() ) {
				$error_message = '';
				foreach ( $e->getFieldErrors() as $error ) {
					$error_message .= ' ' . $error->getFieldName() . ': "' . $error->getMessage() . '" (' . $error->getErrorCode() . ')';
				}
			}

			$order->add_order_note( sprintf( __( 'Simplify payment error: %s', 'woocommerce' ), $error_message ) );

			return new WP_Error( 'simplify_payment_declined', $e->getMessage(), array( 'status' => $e->getCode() ) );
		}

		if ( 'APPROVED' == $payment->paymentStatus ) {
			// Payment complete
			$order->payment_complete( $payment->id );

			// Add order note
			$order->add_order_note( sprintf( __( 'Simplify payment approved (ID: %s, Auth Code: %s)', 'woocommerce' ), $payment->id, $payment->authCode ) );

			return true;
		} else {
			$order->add_order_note( __( 'Simplify payment declined', 'woocommerce' ) );

			return new WP_Error( 'simplify_payment_declined', __( 'Payment was declined - please try another card.', 'woocommerce' ) );
		}
	}

	/**
	 * scheduled_subscription_payment function.
	 *
	 * @param float $amount_to_charge The amount to charge.
	 * @param WC_Order $renewal_order A WC_Order object created to record the renewal payment.
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {
		$result = $this->process_subscription_payment( $renewal_order, $amount_to_charge );

		if ( is_wp_error( $result ) ) {
			$renewal_order->update_status( 'failed', sprintf( __( 'Simplify Transaction Failed (%s)', 'woocommerce' ), $result->get_error_message() ) );
		}
	}

	/**
	 * Update the customer_id for a subscription after using Simplify to complete a payment to make up for.
	 * an automatic renewal payment which previously failed.
	 *
	 * @param WC_Subscription $subscription The subscription for which the failing payment method relates.
	 * @param WC_Order $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
	 */
	public function update_failing_payment_method( $subscription, $renewal_order ) {
		update_post_meta( $subscription->id, '_simplify_customer_id', get_post_meta( $renewal_order->id, '_simplify_customer_id', true ) );
	}

	/**
	 * Include the payment meta data required to process automatic recurring payments so that store managers can.
	 * manually set up automatic recurring payments for a customer via the Edit Subscription screen in Subscriptions v2.0+.
	 *
	 * @param array $payment_meta associative array of meta data required for automatic payments
	 * @param WC_Subscription $subscription An instance of a subscription object
	 * @return array
	 */
	public function add_subscription_payment_meta( $payment_meta, $subscription ) {

		$payment_meta[ $this->id ] = array(
			'post_meta' => array(
				'_simplify_customer_id' => array(
					'value' => get_post_meta( $subscription->id, '_simplify_customer_id', true ),
					'label' => 'Simplify Customer ID',
				),
			),
		);

		return $payment_meta;
	}

	/**
	 * Validate the payment meta data required to process automatic recurring payments so that store managers can.
	 * manually set up automatic recurring payments for a customer via the Edit Subscription screen in Subscriptions 2.0+.
	 *
	 * @param  string $payment_method_id The ID of the payment method to validate
	 * @param  array $payment_meta associative array of meta data required for automatic payments
	 * @return array
	 * @throws Exception
	 */
	public function validate_subscription_payment_meta( $payment_method_id, $payment_meta ) {
		if ( $this->id === $payment_method_id ) {
			if ( ! isset( $payment_meta['post_meta']['_simplify_customer_id']['value'] ) || empty( $payment_meta['post_meta']['_simplify_customer_id']['value'] ) ) {
				throw new Exception( 'A "_simplify_customer_id" value is required.' );
			}
		}
	}

	/**
	 * Don't transfer customer meta to resubscribe orders.
	 *
	 * @access public
	 * @param int $resubscribe_order The order created for the customer to resubscribe to the old expired/cancelled subscription
	 * @return void
	 */
	public function delete_resubscribe_meta( $resubscribe_order ) {
		delete_post_meta( $resubscribe_order->id, '_simplify_customer_id' );
	}

	/**
	 * Process a pre-order payment when the pre-order is released.
	 *
	 * @param WC_Order $order
	 * @return WP_Error|null
	 */
	public function process_pre_order_release_payment( $order ) {

		try {
			$order_items    = $order->get_items();
			$order_item     = array_shift( $order_items );
			$pre_order_name = sprintf( __( '%s - Pre-order for "%s"', 'woocommerce' ), esc_html( get_bloginfo( 'name', 'display' ) ), $order_item['name'] ) . ' ' . sprintf( __( '(Order #%s)', 'woocommerce' ), $order->get_order_number() );

			$customer_id = get_post_meta( $order->id, '_simplify_customer_id', true );

			if ( ! $customer_id ) {
				return new WP_Error( 'simplify_error', __( 'Customer not found', 'woocommerce' ) );
			}

			// Charge the customer
			$payment = Simplify_Payment::createPayment( array(
				'amount'              => $order->order_total * 100, // In cents.
				'customer'            => $customer_id,
				'description'         => trim( substr( $pre_order_name, 0, 1024 ) ),
				'currency'            => strtoupper( get_woocommerce_currency() ),
				'reference'           => $order->id
			) );

			if ( 'APPROVED' == $payment->paymentStatus ) {
				// Payment complete
				$order->payment_complete( $payment->id );

				// Add order note
				$order->add_order_note( sprintf( __( 'Simplify payment approved (ID: %s, Auth Code: %s)', 'woocommerce' ), $payment->id, $payment->authCode ) );
			} else {
				return new WP_Error( 'simplify_payment_declined', __( 'Payment was declined - the customer need to try another card.', 'woocommerce' ) );
			}
		} catch ( Exception $e ) {
			$order_note = sprintf( __( 'Simplify Transaction Failed (%s)', 'woocommerce' ), $e->getMessage() );

			// Mark order as failed if not already set,
			// otherwise, make sure we add the order note so we can detect when someone fails to check out multiple times
			if ( 'failed' != $order->get_status() ) {
				$order->update_status( 'failed', $order_note );
			} else {
				$order->add_order_note( $order_note );
			}
		}
	}

	/**
	 * Process customer: updating or creating a new customer/saved CC
	 */
	protected function process_customer( $order, $customer_token = null, $cart_token = '' ) {
		$customer_info = array(
			'email' => $order->billing_email,
			'name'  => trim( $order->get_formatted_billing_full_name() ),
		);
		$token = $this->save_token( $customer_token, $cart_token, $customer_info );

		if ( ! is_null( $token ) ) {
			$order->add_payment_token( $token );
		}
		return $token->get_token();
	}

	/**
	 * Save token for subscription
	 */
	protected function get_customer_token($order) {
		// New CC info was entered
		if ( isset( $_REQUEST['cardToken'] ) ) {
			$cart_token           = wc_clean( $_REQUEST['cardToken'] );
			return $this->process_customer( $order, null, $cart_token );
		}
		else {
			$customer_token = $this->get_users_token();
			return !is_null($customer_token) ? $customer_token->get_token() : '';
		}
	}

	/**
	 * Return handler for Hosted Payments.
	 */
	public function return_handler() {
		if ( ! isset( $_REQUEST['cardToken'] ) ) {
			parent::return_handler();
		}

		@ob_clean();
		header( 'HTTP/1.1 200 OK' );

		$redirect_url = wc_get_page_permalink( 'cart' );

		if ( isset( $_REQUEST['reference'] ) && isset( $_REQUEST['amount'] ) ) {
			$cart_token  = $_REQUEST['cardToken'];
			$amount      = absint( $_REQUEST['amount'] );
			$order_id    = absint( $_REQUEST['reference'] );
			$order       = wc_get_order( $order_id );
			$order_total = absint( $order->get_total() * 100 );

			if ( $amount === $order_total ) {
				if ( $this->order_contains_subscription( $order->id ) ) {
					$customer_token_value = $this->get_customer_token($order);
					$response = $this->process_subscription( $order, $customer_token_value );
				} elseif ( $this->order_contains_pre_order( $order->id ) ) {
					$customer_token_value = $this->get_customer_token($order);
					$response = $this->process_pre_order( $order, $customer_token_value );
				} else {
					return parent::return_handler();
				}

				if ( 'success' == $response['result'] ) {
					$redirect_url = $response['redirect'];
				} else {
					$order->update_status( 'failed', __( 'Payment was declined by Simplify Commerce.', 'woocommerce' ) );
				}

				wp_redirect( $redirect_url );
				exit();
			}
		}

		wp_redirect( $redirect_url );
		exit();
	}
}
