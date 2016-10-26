<?php

/**
 * CampTix ZarinPal Payment Method
 *
 * This class handles all ZarinPal integration for CampTix
 *
 * @since		1.0
 * @package		CampTix
 * @category	Class
 * @author 		Masoud Amini
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class CampTix_Payment_Method_ZarinPal extends CampTix_Payment_Method {
	public $id = 'camptix_ZarinPal';
	public $name = 'Zarinak';
	public $description = 'CampTix payment methods for Iranian payment gateway ZarinPal.';
	public $supported_currencies = array( 'IRR' );

	/**
	 * We can have an array to store our options.
	 * Use $this->get_payment_options() to retrieve them.
	 */
	protected $options = array();

	function camptix_init() {
		$this->options = array_merge( array(
			'merchant_id' => ''
		), $this->get_payment_options() );

		// IPN Listener
		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
	}

	function payment_settings_fields() {
		$this->add_settings_field_helper( 'merchant_id', 'Zarinpal Merchant ID', array( $this, 'field_text' ) );
		
	}

	function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['merchant_id'] ) )
			$output['merchant_id'] = $input['merchant_id'];


		return $output;
	}

	function template_redirect() {
		if ( ! isset( $_REQUEST['tix_payment_method'] ) || 'camptix_ZarinPal' != $_REQUEST['tix_payment_method'] )
			return;

		if ( isset( $_GET['tix_action'] ) ) {
			

			if ( 'payment_return' == $_GET['tix_action'] )
				$this->payment_return();

			if ( 'payment_notify' == $_GET['tix_action'] )
				$this->payment_notify();
		}
	}

	function payment_return() {
	
		global $camptix;

		$this->log( sprintf( 'Running payment_return. Request data attached.' ), null, $_REQUEST );
		$this->log( sprintf( 'Running payment_return. Server data attached.' ), null, $_SERVER );

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
		if ( empty( $payment_token ) )
			return;

		$attendees = get_posts(
			array(
				'posts_per_page' => 1,
				'post_type' => 'tix_attendee',
				'post_status' => array( 'draft', 'pending', 'publish', 'cancel', 'refund', 'failed' ),
				'meta_query' => array(
					array(
						'key' => 'tix_payment_token',
						'compare' => '=',
						'value' => $payment_token,
						'type' => 'CHAR',
					),
				),
			)
		);

		if ( empty( $attendees ) )
			return;

		$attendee = reset( $attendees );

		if ( 'draft' == $attendee->post_status ) {
			return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_PENDING );
		} else {
			$access_token = get_post_meta( $attendee->ID, 'tix_access_token', true );
			$url = add_query_arg( array(
				'tix_action' => 'access_tickets',
				'tix_access_token' => $access_token,
			), $camptix->get_tickets_url() );

			wp_safe_redirect( esc_url_raw( $url . '#tix' ) );
			die();
		}
	}



	/**
	 * Runs when ZarinPal sends an ITN signal.
	 * Verify the payload and use $this->payment_result
	 * to signal a transaction result back to CampTix.
	 */
	function payment_notify() {
		global $camptix;

		$this->log( sprintf( 'Running payment_notify. Request data attached.' ), null, $_REQUEST );
		$this->log( sprintf( 'Running payment_notify. Server data attached.' ), null, $_SERVER );

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		$payload = stripslashes_deep( $_REQUEST);

		$data_string = '';
		$data_array = array();

		// Dump the submitted variables and calculate security signature
		foreach ( $payload as $key => $val ) {
			if ( $key != 'signature' ) {
				$data_string .= $key .'='. urlencode( $val ) .'&';
				$data_array[$key] = $val;
			}
		}
		
		$data_string = substr( $data_string, 0, -1 );
		$signature = md5( $data_string );

		$pfError = false;
		if ( 0 != strcmp( $signature, $payload['signature'] ) ) {
			$pfError = true;
			$this->log( sprintf( 'ITN request failed, signature mismatch: %s', $payload ) );
		}
		
		$order = $this->get_order( $payment_token );
		
		if($payload['Status'] == 'OK'){
		// URL also Can be https://ir.zarinpal.com/pg/services/WebGate/wsdl
		$client = new SoapClient('https://de.zarinpal.com/pg/services/WebGate/wsdl', array('encoding' => 'UTF-8')); 

		$result = $client->PaymentVerification(
						  	array(
									'MerchantID'	 => $this->options['merchant_id'],
									'Authority' 	 => $payload['Authority'],
									'Amount'	 => $order['total']/10
								)
		);

		if($result->Status == 100){
			//echo 'Transation success. RefID:'. $result->RefID;
			$this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED );
		} else {
			//echo 'Transation failed. Status:'. $result->Status;
			$this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED );
		}

	} else {
		//echo 'Transaction canceled by user';
		$this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED );
	}


		$access_token = get_post_meta( $attendee->ID, 'tix_access_token', true );
			$url = add_query_arg( array(
				'tix_action' => 'access_tickets',
				'tix_access_token' => $access_token,
			), $camptix->get_tickets_url() );

			wp_safe_redirect( esc_url_raw( $url . '#tix' ) );
			die();

	}

	public function payment_checkout( $payment_token ) {

		if ( ! $payment_token || empty( $payment_token ) )
			return false;

		if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies ) )
			die( __( 'The selected currency is not supported by this payment method.', 'camptix' ) );

		$return_url = add_query_arg( array(
			'tix_action' => 'payment_return',
			'tix_payment_token' => $payment_token,
			'tix_payment_method' => 'camptix_ZarinPal',
		), $this->get_tickets_url() );



		$notify_url = add_query_arg( array(
			'tix_action' => 'payment_notify',
			'tix_payment_token' => $payment_token,
			'tix_payment_method' => 'camptix_ZarinPal',
		), $this->get_tickets_url() );

		$order = $this->get_order( $payment_token );

		$payload = array(
			// Merchant details
			'merchant_id' => $this->options['merchant_id'],
			'return_url' => $return_url,
			'notify_url' => $notify_url,

			// Item details
			'm_payment_id' => $payment_token,
			'amount' => $order['total'],
			'item_name' => get_bloginfo( 'name' ) .' purchase, Order ' . $payment_token,
			'item_description' => sprintf( __( 'سفارش جدید  %s', 'woothemes' ), get_bloginfo( 'name' ) ),

			// Custom strings
			'custom_str1' => $payment_token,
			'source' => 'WordCamp-CampTix-Plugin'
		);


		$ZarinPal_args_array = array();
		foreach ( $payload as $key => $value ) {
			$ZarinPal_args_array[] = '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
		}
		//$url = $this->options['sandbox'] ? 'https://sandbox.ZarinPal.co.za/eng/process?aff=camptix-free' : 'https://www.ZarinPal.co.za/eng/process?aff=camptix-free';
		
		$client = new SoapClient('https://de.zarinpal.com/pg/services/WebGate/wsdl', array('encoding' => 'UTF-8')); 

	$result = $client->PaymentRequest(
						array(
								'MerchantID' 	=> $payload['merchant_id'],
								'Amount' 	=> $payload['amount']/10,
								'Description' 	=> $payload['item_description'],
								'Email' 	=> '',
								'Mobile' 	=> '',
								'CallbackURL' 	=> $payload['notify_url']
							)
	);

	//Redirect to URL You can do it also by creating a form
	if($result->Status == 100)
	{
		//Header('Location: https://www.zarinpal.com/pg/StartPay/'.$result->Authority);
		
		echo  '<div style="border: 1px solid;margin:auto;padding:15px 10px 15px 50px; width:600px;font-size:8pt; line-height:25px;font-family:tahoma; text-align:right; direction:rtl;color: #00529B;background-color: #BDE5F8">
                         درحال اتصال به درگاه پرداخت زرین‌پال ...
                        <br><br>
						توضیحات: '.sprintf( __( 'سفارش جدید  %s', 'woothemes' ), get_bloginfo( 'name' ) ).'<br>
						مبلغ: '.$order['total'].' ریال<br>
						</div>';
						
		echo '<script type="text/javascript" src="https://cdn.zarinpal.com/zarinak/v1/checkout.js"></script>
						<script>
						Zarinak.setAuthority( ' . $result->Authority . ');
						Zarinak.open();
						</script>';
		die('');
	} else {
		echo'ERR: '.$result->Status;
	}

		/*echo '<div id="tix">
					<form action="' . $url . '" method="post" id="ZarinPal_payment_form">
						' . implode( '', $ZarinPal_args_array ) . '
						<script type="text/javascript">
							document.getElementById("ZarinPal_payment_form").submit();
						</script>
					</form>
				</div>';*/
		return;
	}

	/**
	 * Runs when the user cancels their payment during checkout at PayPal.
	 * his will simply tell CampTix to put the created attendee drafts into to Cancelled state.
	 */

}
?>