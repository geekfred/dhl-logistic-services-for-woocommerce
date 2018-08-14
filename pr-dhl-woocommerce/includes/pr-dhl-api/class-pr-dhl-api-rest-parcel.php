<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly


class PR_DHL_API_REST_Parcel extends PR_DHL_API_REST {

	// const PR_DHL_AUTO_CLOSE = '1';

	private $args = array();

	public function __construct() {}

	public function get_dhl_parcel_services( $args ) {
		// curl -X GET --header 'Accept: application/json' --header 'X-EKP: 2222222222' 'https://cig.dhl.de/services/sandbox/rest/checkout/28757/availableServices?startDate=2018-08-17'

		$this->set_arguments( $args );
		$this->set_endpoint( '/checkout/' . $args['postcode'] . '/availableServices' );
		$this->set_query_string();

		$response_body = $this->get_request();

		// This will work on one order but NOT on bulk!
		$label_response = $response_body->shipments[0]->packages[0]->responseDetails;
		$package_id = $label_response->labelDetails[0]->packageId;

		$label_url = $this->save_label_file( $package_id , $label_response->labelDetails[0]->format, $label_response->labelDetails[0]->labelData );

		$label_tracking_info = array( 'label_url' => $label_url,
										'tracking_number' => $package_id,
										'tracking_status' => isset( $label_response->trackingNumberStatus ) ? $label_response->trackingNumberStatus : ''
										);

		return $label_tracking_info;
	}

	protected function set_arguments( $args ) {
		// Validate set args
		
		if ( empty( $args['account_num'] ) ) {
			throw new Exception( __('Please, provide an account in the DHL shipping settings', 'pr-shipping-dhl' ) );
		}

		if ( empty( $args['postcode'] ) ) {
			throw new Exception( __('Please, provide the receiver postnumber.', 'pr-shipping-dhl' ) );
		}

		if ( empty( $args['start_date'] ) ) {
			throw new Exception( __('Please, provide the shipment start date.', 'pr-shipping-dhl' ) );
		}

		$this->args = $args;
	}

	protected function set_query_string() {
		// 2018-08-17
		$dhl_label_query_string = array( 'startDate' => $this->args['start_date'] );
		
		$this->query_string = http_build_query($dhl_label_query_string);
	}

	protected function set_header( $authorization = '' ) {
		$dhl_header['Accept'] = 'application/json';
		$dhl_header['X-EKP'] = $this->args['account_num'];
		
		if ( !empty( $authorization ) ) {
			$dhl_header['Authorization'] = $authorization;
		}
		
		$this->remote_header = $dhl_header;
		error_log(print_r($this->remote_header,true));
	}
}
