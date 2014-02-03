<?php


function rcp_process_stripe_signup( $subscription_data ) {

	global $rcp_options;

	// just shorter and easier
	$data = $subscription_data;

	if ( isset( $rcp_options['stripe_test_mode'] ) ) {
		$secret_key = trim( $rcp_options['stripe_test_secret'] );
	} else {
		$secret_key = trim( $rcp_options['stripe_live_secret'] );
	}

	Stripe::setApiKey( $secret_key );

	$paid = false;

	if ( $data['auto_renew'] ) {

		// process a subscription sign up

		$plan_id = strtolower( str_replace( ' ', '', $data['subscription_name'] ) );

		$plan_name = $data['subscription_name'];

		if ( !rcp_check_stripe_plan_exists( $plan_id ) ) {
			// create the plan if it doesn't exist
			rcp_create_stripe_plan( $plan_name );
		}

		try {
			if ( isset( $data['post_data']['rcp_discount'] ) && $data['post_data']['rcp_discount'] != '' ) {

				$customer = Stripe_Customer::create( array(
						'card' 			=> $data['post_data']['stripeToken'],
						'plan' 			=> $plan_id,
						'email' 		=> $data['user_email'],
						'description' 	=> 'User ID: ' . $data['user_id'] . ' - User Email: ' . $data['user_email'],
						'coupon' 		=> $data['post_data']['rcp_discount']
					)
				);

			} else {

				$customer = Stripe_Customer::create( array(
						'card' 			=> $data['post_data']['stripeToken'],
						'plan' 			=> $plan_id,
						'email' 		=> $data['user_email'],
						'description' 	=> 'User ID: ' . $data['user_id'] . ' - User Email: ' . $data['user_email']
					)
				);

			}

			if ( ! empty( $subscription_data['fee'] ) ) {

				if( $subscription_data['fee'] > 0 ) {
					$description = sprintf( __( 'Signup Fee for %s', 'rcp_stripe' ), $plan_name );
				} else {
					$description = sprintf( __( 'Signup Discount for %s', 'rcp_stripe' ), $plan_name );
				}

				Stripe_InvoiceItem::create( array(
						'customer'    => $customer->id,
						'amount'      => $subscription_data['fee'] * 100,
						'currency'    => strtolower( $data['currency'] ),
						'description' => $description
					)
				);

				// Create the invoice containing taxes / discounts / fees
				$invoice = Stripe_Invoice::create( array(
						'customer' => $customer->id, // the customer to apply the fee to
					) );
				$invoice->pay();

			}

			rcp_stripe_set_as_customer( $data['user_id'], $customer );

			// subscription payments are recorded via webhook

			$paid = true;

		} catch ( Stripe_CardError $e ) {

			$body = $e->getJsonBody();
			$err  = $body['error'];

			$error = "<h4>An error occurred</h4>";
			if( isset( $err['code'] ) ) {
				$error .= "<p>Error code: " . $err['code'] ."</p>";
			}
			$error .= "<p>Status: " . $e->getHttpStatus() ."</p>";
			$error .= "<p>Message: " . $err['message'] . "</p>";

			wp_die( $error );

			exit;

		} catch (Stripe_InvalidRequestError $e) {

			// Invalid parameters were supplied to Stripe's API
			$body = $e->getJsonBody();
			$err  = $body['error'];

			$error = "<h4>An error occurred</h4>";
			if( isset( $err['code'] ) ) {
				$error .= "<p>Error code: " . $err['code'] ."</p>";
			}
			$error .= "<p>Status: " . $e->getHttpStatus() ."</p>";
			$error .= "<p>Message: " . $err['message'] . "</p>";

			wp_die( $error );

		} catch (Stripe_AuthenticationError $e) {

			// Authentication with Stripe's API failed
			// (maybe you changed API keys recently)

			$body = $e->getJsonBody();
			$err  = $body['error'];

			$error = "<h4>An error occurred</h4>";
			if( isset( $err['code'] ) ) {
				$error .= "<p>Error code: " . $err['code'] ."</p>";
			}
			$error .= "<p>Status: " . $e->getHttpStatus() ."</p>";
			$error .= "<p>Message: " . $err['message'] . "</p>";

			wp_die( $error );

		} catch (Stripe_ApiConnectionError $e) {

			// Network communication with Stripe failed

			$body = $e->getJsonBody();
			$err  = $body['error'];

			$error = "<h4>An error occurred</h4>";
			if( isset( $err['code'] ) ) {
				$error .= "<p>Error code: " . $err['code'] ."</p>";
			}
			$error .= "<p>Status: " . $e->getHttpStatus() ."</p>";
			$error .= "<p>Message: " . $err['message'] . "</p>";

			wp_die( $error );

		} catch (Stripe_Error $e) {

			// Display a very generic error to the user

			$body = $e->getJsonBody();
			$err  = $body['error'];

			$error = "<h4>An error occurred</h4>";
			if( isset( $err['code'] ) ) {
				$error .= "<p>Error code: " . $err['code'] ."</p>";
			}
			$error .= "<p>Status: " . $e->getHttpStatus() ."</p>";
			$error .= "<p>Message: " . $err['message'] . "</p>";

			wp_die( $error );

		} catch (Exception $e) {

			// Something else happened, completely unrelated to Stripe

			$error = "<p>An unidentified error occurred.</p>";
			$error .= print_r( $e, true );

			wp_die( $error );

		}

	} else {

		// process a one time payment signup

		try {

			$charge = Stripe_Charge::create( array(
				'amount' 		=> $data['price'] * 100, // amount in cents
				'currency' 		=> strtolower( $data['currency'] ),
				'card' 			=> $data['post_data']['stripeToken'],
				'description' 	=> 'User ID: ' . $data['user_id'] . ' - User Email: ' . $data['user_email'] )
			);

			$payment_data = array(
				'date'              => date( 'Y-m-d g:i:s', time() ),
				'subscription'      => $data['subscription_name'],
				'payment_type' 		=> 'Credit Card One Time',
				'subscription_key' 	=> $data['key'],
				'amount' 			=> $data['price'],
				'user_id' 			=> $data['user_id'],
				'transaction_id'    => $charge->id
			);

			$rcp_payments = new RCP_Payments();
			$rcp_payments->insert( $payment_data );

			$paid = true;

		} catch ( Stripe_CardError $e ) {

			$body = $e->getJsonBody();
			$err  = $body['error'];

			$error = "<h4>An error occurred</h4>";
			if( isset( $err['code'] ) ) {
				$error .= "<p>Error code: " . $err['code'] ."</p>";
			}
			$error .= "<p>Status: " . $e->getHttpStatus() ."</p>";
			$error .= "<p>Message: " . $err['message'] . "</p>";

			wp_die( $error );

			exit;

		} catch (Stripe_InvalidRequestError $e) {

			// Invalid parameters were supplied to Stripe's API
			$body = $e->getJsonBody();
			$err  = $body['error'];

			$error = "<h4>An error occurred</h4>";
			if( isset( $err['code'] ) ) {
				$error .= "<p>Error code: " . $err['code'] ."</p>";
			}
			$error .= "<p>Status: " . $e->getHttpStatus() ."</p>";
			$error .= "<p>Message: " . $err['message'] . "</p>";

			wp_die( $error );

		} catch (Stripe_AuthenticationError $e) {

			// Authentication with Stripe's API failed
			// (maybe you changed API keys recently)

			$body = $e->getJsonBody();
			$err  = $body['error'];

			$error = "<h4>An error occurred</h4>";
			if( isset( $err['code'] ) ) {
				$error .= "<p>Error code: " . $err['code'] ."</p>";
			}
			$error .= "<p>Status: " . $e->getHttpStatus() ."</p>";
			$error .= "<p>Message: " . $err['message'] . "</p>";

			wp_die( $error );

		} catch (Stripe_ApiConnectionError $e) {

			// Network communication with Stripe failed

			$body = $e->getJsonBody();
			$err  = $body['error'];

			$error = "<h4>An error occurred</h4>";
			if( isset( $err['code'] ) ) {
				$error .= "<p>Error code: " . $err['code'] ."</p>";
			}
			$error .= "<p>Status: " . $e->getHttpStatus() ."</p>";
			$error .= "<p>Message: " . $err['message'] . "</p>";

			wp_die( $error );

		} catch (Stripe_Error $e) {

			// Display a very generic error to the user

			$body = $e->getJsonBody();
			$err  = $body['error'];

			$error = "<h4>An error occurred</h4>";
			if( isset( $err['code'] ) ) {
				$error .= "<p>Error code: " . $err['code'] ."</p>";
			}
			$error .= "<p>Status: " . $e->getHttpStatus() ."</p>";
			$error .= "<p>Message: " . $err['message'] . "</p>";

			wp_die( $error );

		} catch (Exception $e) {

			// Something else happened, completely unrelated to Stripe

			$error = "<p>An unidentified error occurred.</p>";
			$error .= print_r( $e, true );

			wp_die( $error );

		}
	}

	if ( $paid ) {

		// set this user to active
		rcp_set_status( $data['user_id'], 'active' );

		// send out the notification email
		rcp_email_subscription_status( $data['user_id'], 'active' );

		if ( $data['new_user'] ) {

			// send an email to the admin alerting them of the registration
			wp_new_user_notification( $data['user_id'] );
			// log the new user in
			rcp_login_user_in( $data['user_id'], $data['user_name'], $data['post_data']['rcp_user_pass'] );
		} else {
			delete_user_meta( $data['user_id'], '_rcp_stripe_sub_cancelled' );
		}

		do_action( 'rcp_stripe_signup', $data['user_id'], $data );

	} else {
		wp_die( __( 'An error occurred, please contact the site administrator: ', 'rcp_stripe' ) . get_bloginfo( 'admin_email' ) );
	}

	$redirect = get_permalink( $rcp_options['redirect'] );
	// redirect to the success page, or error page if something went wrong
	wp_redirect( $redirect ); exit;
}
add_action( 'rcp_gateway_stripe', 'rcp_process_stripe_signup', 9 ); // Priority set to 9 to ensure this takes precedence over add-on plugin, if active