<?php

/**
 * Register default payment gateways
 *
 * @access      private
 * @return      array
*/

function rcp_get_payment_gateways() {

	$gateways = array(
		'paypal' => array(
			'admin_label'    => __( 'PayPal Standard', 'rcp' ),
			'checkout_label' => __( 'PayPal', 'rcp' )
		),
		'stripe' => array(
			'admin_label'    => __( 'Stripe', 'rcp' ),
			'checkout_label' => __( 'Credit Card', 'rcp' )
		),
	);

	return apply_filters( 'rcp_payment_gateways', $gateways );
}


/**
 * Return list of active gateways
 *
 * @access      private
 * @return      array
*/

function rcp_get_enabled_payment_gateways() {
	global $rcp_options;
	$gateways = rcp_get_payment_gateways();
	$enabled_gateways = isset( $rcp_options['gateways'] ) ? $rcp_options['gateways'] : false;
	$gateway_list = array();
	if( $enabled_gateways ) {
		foreach( $gateways as $key => $gateway ) :
			if( isset( $enabled_gateways[ $key ] ) && $enabled_gateways[ $key ] == 1 ) :
				$gateway_list[ $key ] = $gateway;
			endif;
		endforeach;
	} else {
		$gateway_list['paypal'] = 'PayPal';
	}
	return $gateway_list;
}


/**
 * Send payment / subscription data to gateway
 *
 * @access      private
 * @return      array
*/

function rcp_send_to_gateway( $gateway, $subscription_data ) {
	do_action( 'rcp_gateway_' . $gateway, $subscription_data );
}