<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly


class PR_DHL_API_SOAP_Label extends PR_DHL_API_SOAP implements PR_DHL_API_Label {

	const DHL_MAX_ITEMS = '6';
	const DHL_RETURN_PARTICIPATION = '07';

	private $args = array();

	// 'LI', 'CH', 'NO'
	protected $eu_iso2 = array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'RO', 'SI', 'SK', 'ES', 'SE', 'GB');

	public function __construct( ) {
		try {

			parent::__construct( );

		} catch (Exception $e) {
			throw $e;
		}
	}

	public function dhl_test_connection( $client_id, $client_secret ) {
		return $this->get_access_token( $client_id, $client_secret );
	}

	public function dhl_validate_field( $key, $value ) {
		$this->validate_field( $key, $value );
	}

	protected function validate_field( $key, $value ) {

		try {

			switch ( $key ) {
				case 'weight':
					$this->validate( $value );
					break;
				case 'hs_code':
					$this->validate( $value, 'string', 4, 11 );
					break;
				default:
					parent::validate_field( $key, $value );
					break;
			}
			
		} catch (Exception $e) {
			throw $e;
		}
	}

	public function get_dhl_label( $args ) {
		$this->set_arguments( $args );
		$soap_request = $this->set_message();

		try {
			$soap_client = $this->get_access_token( $args['dhl_settings']['api_user'], $args['dhl_settings']['api_pwd'] );
			PR_DHL()->log_msg( '"createShipmentOrder" called with: ' . print_r( $soap_request, true ) );

			$response_body = $soap_client->createShipmentOrder($soap_request);
			error_log(print_r($soap_client->__getLastRequest(),true));
			error_log(print_r($soap_client->__getLastResponse(),true));
			error_log(print_r($response_body,true));

			PR_DHL()->log_msg( 'Response Body: ' . print_r( $response_body, true ) );
		
		} catch (Exception $e) {
			throw $e;
		}

		if( $response_body->Status->statusCode != 0 ) {
			throw new Exception( sprintf( __('Could not create label - %s', 'pr-shipping-dhl'), $response_body->Status->statusMessage ) );
		} else {
			// Give the server 1 second to create the PDF before downloading it
			sleep(1);

			$tracking_number = isset( $response_body->CreationState->LabelData->shipmentNumber ) ? $response_body->CreationState->LabelData->shipmentNumber : '';
			
			// $label_url = $this->save_label_file( $response_body->CreationState->sequenceNumber, 'pdf', $response_body->CreationState->LabelData->labelUrl );
			$label_url = $this->save_label_file( $response_body->CreationState->sequenceNumber, 'pdf', $response_body->CreationState->LabelData->labelData );

			$label_tracking_info = array( 'label_url' => $label_url, 'tracking_number' => $tracking_number );

			return $label_tracking_info;
		}
	}

	public function delete_dhl_label_call( $args ) {
		$soap_request =	array(
					'Version' =>
						array(
								'majorRelease' => '2',
								'minorRelease' => '2'
						),
					'shipmentNumber' => $args['tracking_number']
				);

		try {

			$soap_client = $this->get_access_token( $args['api_user'], $args['api_pwd'] );
			$response_body = $soap_client->deleteShipmentOrder( $soap_request );

		} catch (Exception $e) {
			throw $e;
		}

		if( $response_body->Status->statusCode != 0 ) {
			throw new Exception( sprintf( __('Could not delete label - %s', 'pr-shipping-dhl'), $response_body->Status->statusMessage ) );
		} 
	}

	public function delete_dhl_label( $args ) {
		// Delete the label remotely first
		try {
			$this->delete_dhl_label_call( $args );
		} catch (Exception $e) {
			throw $e;			
		}

		// Then delete file
		$upload_path = wp_upload_dir();
		$label_path = str_replace( $upload_path['url'], $upload_path['path'], $args['label_url'] );
		
		if( file_exists( $label_path ) ) {
			$res = unlink( $label_path );
			
			if( ! $res ) {
				throw new Exception( __('DHL Label could not be deleted!', 'pr-shipping-dhl' ) );
			}
		}

	}

	protected function save_label_file( $order_id, $format, $label_data ) {
		$label_name = 'dhl-label-' . $order_id . '.' . $format;
		$upload_path = wp_upload_dir();
		$label_path = $upload_path['path'] . '/'. $label_name;
		$label_url = $upload_path['url'] . '/'. $label_name;

		if( validate_file($label_path) > 0 ) {
			throw new Exception( __('Invalid file path!', 'pr-shipping-dhl' ) );
		}

		$label_data_decoded = $label_data;
		// $label_data_decoded = base64_decode($label_data);
		// $label_data_decoded = file_get_contents( $label_data );

		// SOAP client decodes (base64) on its own so no need to do it here
		$file_ret = file_put_contents( $label_path, $label_data_decoded );
		
		if( empty( $file_ret ) ) {
			throw new Exception( __('DHL Label file cannot be saved!', 'pr-shipping-dhl' ) );
		}

		return $label_url;
	}

	protected function set_arguments( $args ) {
		// Validate set args
		
		if ( empty( $args['dhl_settings']['api_user'] ) ) {
			throw new Exception( __('Please, provide the username in the DHL shipping settings', 'pr-shipping-dhl' ) );
		}

		if ( empty( $args['dhl_settings']['api_pwd'] )) {
			throw new Exception( __('Please, provide the password for the username in the DHL shipping settings', 'pr-shipping-dhl') );
		}

		// Validate order details
		if ( empty( $args['dhl_settings']['account_num'] ) ) {
			throw new Exception( __('Please, provide an account in the DHL shipping settings', 'pr-shipping-dhl' ) );
		}

		if ( empty( $args['dhl_settings']['participation'] )) {
			throw new Exception( __('Please, provide a participation number for the shipping method in the DHL shipping settings', 'pr-shipping-dhl') );
		}

		if ( empty( $args['dhl_settings']['shipper_name'] )) {
			throw new Exception( __('Please, provide a shipper name in the DHL shipping settings', 'pr-shipping-dhl') );
		}

		if ( empty( $args['dhl_settings']['shipper_address'] )) {
			throw new Exception( __('Please, provide a shipper address in the DHL shipping settings', 'pr-shipping-dhl') );
		}

		if ( empty( $args['dhl_settings']['shipper_address_no'] )) {
			throw new Exception( __('Please, provide a shipper address number in the DHL shipping settings', 'pr-shipping-dhl') );
		}

		if ( empty( $args['dhl_settings']['shipper_address_city'] )) {
			throw new Exception( __('Please, provide a shipper city in the DHL shipping settings', 'pr-shipping-dhl') );
		}

		if ( empty( $args['dhl_settings']['shipper_address_zip'] )) {
			throw new Exception( __('Please, provide a shipper postcode in the DHL shipping settings', 'pr-shipping-dhl') );
		}

		// Order details
		if ( empty( $args['order_details']['dhl_product'] )) {
			throw new Exception( __('DHL "Product" is empty!', 'pr-shipping-dhl') );
		}

		// return receiver
		if ( isset( $args['order_details']['return_address'] ) && ( $args['order_details']['return_address'] == 'yes' ) ) {

			if ( ( $args['order_details']['dhl_product'] != 'V01PAK' ) && ( $args['order_details']['dhl_product'] != 'V01PRIO' ) && ( $args['order_details']['dhl_product'] != 'V86PARCEL' ) && ( $args['order_details']['dhl_product'] != 'V55PAK' ) ){
				
				throw new Exception( __('Returns are not supported by this DHL Service.', 'pr-shipping-dhl') );
			}
			
			if ( empty( $args['dhl_settings']['return_name'] )) {
				throw new Exception( __('Please, provide a return name in the DHL shipping settings', 'pr-shipping-dhl') );
			}

			if ( empty( $args['dhl_settings']['return_address'] )) {
				throw new Exception( __('Please, provide a return address in the DHL shipping settings', 'pr-shipping-dhl') );
			}

			if ( empty( $args['dhl_settings']['return_address_no'] )) {
				throw new Exception( __('Please, provide a return address number in the DHL shipping settings', 'pr-shipping-dhl') );
			}

			if ( empty( $args['dhl_settings']['return_address_city'] )) {
				throw new Exception( __('Please, provide a return city in the DHL shipping settings', 'pr-shipping-dhl') );
			}

			if ( empty( $args['dhl_settings']['return_address_zip'] )) {
				throw new Exception( __('Please, provide a return postcode in the DHL shipping settings', 'pr-shipping-dhl') );
			}	
		}

		if ( empty( $args['order_details']['order_id'] )) {
			throw new Exception( __('Shop "Order ID" is empty!', 'pr-shipping-dhl') );
		}

		if ( empty( $args['order_details']['weightUom'] )) {
			throw new Exception( __('Shop "Weight Units of Measure" is empty!', 'pr-shipping-dhl') );
		}

		if ( empty( $args['order_details']['weight'] )) {
			throw new Exception( __('Order "Weight" is empty!', 'pr-shipping-dhl') );
		}

		if ( isset( $args['order_details']['identcheck'] ) && ( $args['order_details']['identcheck'] == 'yes' ) ) {
			if ( empty( $args['order_details']['identcheck_fname'] ) || empty( $args['order_details']['identcheck_lname'] ) || empty( $args['order_details']['identcheck_dob'] ) || empty( $args['order_details']['identcheck_age'] ) ) {
				throw new Exception( __('All "Identity Check" values must be entered.', 'pr-shipping-dhl') );
			}
		}

		// Validate weight
		try {
			$this->validate_field( 'weight', $args['order_details']['weight'] );
		} catch (Exception $e) {
			throw new Exception( 'Weight - ' . $e->getMessage() );
		}

		// if ( empty( $args['order_details']['duties'] )) {
		// 	throw new Exception( __('DHL "Duties" is empty!', 'pr-shipping-dhl') );
		// }

		if ( empty( $args['order_details']['currency'] )) {
			throw new Exception( __('Shop "Currency" is empty!', 'pr-shipping-dhl') );
		}

		// Validate shipping address
		if ( empty( $args['shipping_address']['address_1'] )) {
			throw new Exception( __('Shipping "Address 1" is empty!', 'pr-shipping-dhl') );
		}

		if ( empty( $args['shipping_address']['city'] )) {
			throw new Exception( __('Shipping "City" is empty!', 'pr-shipping-dhl') );
		}

		if ( empty( $args['shipping_address']['country'] )) {
			throw new Exception( __('Shipping "Country" is empty!', 'pr-shipping-dhl') );
		}

		// If address 2 missing, set last piece of an address to be address 2
		if ( empty( $args['shipping_address']['address_2'] )) {
			// Break address into pieces			
			$address_exploded = explode(' ', $args['shipping_address']['address_1']);
			// Get last piece, assuming it is street number 
			$last_index = sizeof($address_exploded);

			// Set last index as street number
			$args['shipping_address']['address_2'] = $address_exploded[ $last_index - 1 ];

			// Unset it in address 1
			unset( $address_exploded[ $last_index - 1 ] );

			// Set address 1 without street number
			$args['shipping_address']['address_1'] = implode(' ', $address_exploded );
		}

		// Add default values for required fields that might not be passed e.g. phone
		$default_args = array( 'shipping_address' => 
									array( 'name' => '',
											'company' => '',
											'address_2' => '',
											'email' => '',
											'postcode' => '',
											'state' => '',
											'phone' => ' '
											),
						);

		$args['shipping_address'] = wp_parse_args( $args['shipping_address'], $default_args['shipping_address'] );

		$default_args_item = array( 
									'item_description' => '',
									'sku' => '',
									'line_total' => 0,
									'country_origin' => '',
									'hs_code' => '',
									'qty' => 1
									);

		foreach ($args['items'] as $key => $item) {
			
			if ( ! empty( $item['hs_code'] ) ) {
				try {
					$this->validate_field( 'hs_code', $item['hs_code'] );
				} catch (Exception $e) {
					throw new Exception( 'HS Code - ' . $e->getMessage() );
				}
			}

			$args['items'][$key] = wp_parse_args( $item, $default_args_item );			
		}

		$this->args = $args;
	}

	protected function set_query_string() {
		return '';
	}

	protected function is_european_shipment() {
		
		// if ( ! empty( $this->args['dhl_settings'][ 'shipper_country' ] ) && ! empty( $this->args['shipping_address']['country'] ) && ( $this->args['dhl_settings'][ 'shipper_country' ] == $this->args['shipping_address']['country'] ) ) {
		if ( ! empty( $this->args['shipping_address']['country'] ) && in_array( $this->args['shipping_address']['country'], $this->eu_iso2 ) ) {
			return true;
		} else {
			return false;
		}
	}
	

	protected function set_message() {
		if( ! empty( $this->args ) ) {
			// Set date related functions to German time
			// date_default_timezone_set('Europe/Berlin');

			// SERVICES DATA
			$services_map = array(
								'preferred_time' => array(
													'name' => 'PreferredTime',
													'type' => 'type'),
								'age_visual' => array(
													'name' => 'VisualCheckOfAge',
													'type' => 'type'),
								'preferred_location' => array(
													'name' => 'PreferredLocation' ,
													'type' => 'details'),
								'preferred_neighbor' => array(
													'name' => 'PreferredNeighbour' ,
													'type' => 'details'),
								'preferred_day' => array(
													'name' => 'PreferredDay' ,
													'type' => 'details'),
								'personally' => array(
													'name' => 'Personally'
													),
								'no_neighbor' => array(
													'name' => 'NoNeighbourDelivery'
													),
								'named_person' => array(
													'name' => 'NamedPersonOnly' ,
													),
								'premium' => array(
													'name' => 'Premium'
													),
								'additional_insurance' => array(
													'name' => 'AdditionalInsurance' 
													),
								'bulky_goods' => array(
													'name' => 'BulkyGoods'
													),
								'identcheck' => array(
													'name' => 'IdentCheck'
													),
								);

			$services = array();
			foreach ($services_map as $key => $value) {

				if ( ! empty( $this->args['order_details'][ $key ] ) ) {

					if ( $this->args['order_details'][ $key ] == 'no' ) {
						continue;
					}
					
					if ( $this->args['order_details'][ $key ] == 'yes' ) {

						$services[ $value['name'] ] = array(
							'active' => 1
						);

						switch ( $key ) {
							case 'additional_insurance':
								$services[ $value['name'] ]['insuranceAmount'] = $this->args['order_details']['total_value'];
								break;	
							case 'identcheck':
								$services[ $value['name'] ]['Ident']['surname'] = $this->args['order_details']['identcheck_fname'];
								$services[ $value['name'] ]['Ident']['givenName'] = $this->args['order_details']['identcheck_lname'];
								$services[ $value['name'] ]['Ident']['dateOfBirth'] = $this->args['order_details']['identcheck_dob'];
								$services[ $value['name'] ]['Ident']['minimumAge'] = $this->args['order_details']['identcheck_age'];
								break;							
						}

					} else {
						$services[ $value['name'] ] = array(
							'active' => 1,
							$value['type'] => $this->args['order_details'][ $key ]
						);
					}
				}				
			}

			// EMAIL NOTIFCATION
			$notification_email = array();
			if ( isset( $this->args['order_details'][ 'email_notification' ] ) && $this->args['order_details'][ 'email_notification' ] == 'yes' ) {
				$notification_email['recipientEmailAddress'] = $this->args['shipping_address']['email'];
			}

			// COD DATA
			$bank_data = array();
			if( isset( $this->args['order_details']['cod_value'] ) ) {

				$services[ 'CashOnDelivery' ] = array(
							'active' => 1
						);

				// If the fee was added to the customer i.e. 'cod_fee' == 'yes', then do not add to merchange i.e. 'addFee' = 0
				if( isset( $this->args['dhl_settings']['cod_fee'] ) && $this->args['dhl_settings']['cod_fee'] == 'yes' ) {
					$services['CashOnDelivery']['addFee'] = 0;
				} else {
					$services['CashOnDelivery']['addFee'] = 1;
				}

				$services[ 'CashOnDelivery']['codAmount'] = $this->args['order_details']['cod_value']; 	

				$bank_data_map = array(
									'bank_holder' => 'accountOwner',
									'bank_name' => 'bankName',
									'bank_iban' => 'iban',
									'bank_ref' => 'note1',
									'bank_ref_2' => 'note2',
									'bank_bic' => 'bic'
									);

				foreach ($bank_data_map as $key => $value) {
					
					if( isset( $this->args['dhl_settings'][ $key ] ) ) {
						$bank_data[ $value ] = $this->args['dhl_settings'][ $key ];
					}
				}
			}

			// create account number
			$product_number = preg_match('!\d+!', $this->args['order_details']['dhl_product'], $matches );

			if( $product_number ) {
				$account_number = $this->args['dhl_settings']['account_num'] . $matches[0] . $this->args['dhl_settings']['participation'];
				
				if ( isset( $this->args['order_details']['return_address'] ) && ( $this->args['order_details']['return_address'] == 'yes' ) ) {
			
					$return_account_number = $this->args['dhl_settings']['account_num'] . $matches[0] . self::DHL_RETURN_PARTICIPATION;
				}

			} else {
				throw new Exception( __('Could not create account number - no product number.', 'pr-shipping-dhl') );				
			}

			$this->args['order_details']['weight'] = $this->maybe_convert_weight( $this->args['order_details']['weight'], $this->args['order_details']['weightUom'] );

			$dhl_label_body = 
				array(
					'Version' =>
						array(
								'majorRelease' => '2',
								'minorRelease' => '2'
						),
					'ShipmentOrder' => 
						array (
								'sequenceNumber' => $this->args['order_details']['order_id'],
								'Shipment' => 
									array( 
										'ShipmentDetails' => 
											array( 
												'product' => $this->args['order_details']['dhl_product'],
												'accountNumber' => $account_number,
												'shipmentDate' => date('Y-m-d'),
												'ShipmentItem' => 
													array( 
														'weightInKG' => $this->args['order_details']['weight']
														),
												'Service' => $services,
												'Notification' => $notification_email,
												'BankData' => $bank_data,
											),
										'Shipper' =>
											array(
												'Name' =>
													array(
														'name1' => $this->args['dhl_settings']['shipper_name'],
														'name2' => $this->args['dhl_settings']['shipper_company']
														),
												'Address' =>
													array(
														'streetName' => $this->args['dhl_settings']['shipper_address'],
														'streetNumber' => intval($this->args['dhl_settings']['shipper_address_no']),
														'zip' => $this->args['dhl_settings']['shipper_address_zip'],
														'city' => $this->args['dhl_settings']['shipper_address_city'],
														'Origin' =>
															array(
																'countryISOCode' => $this->args['dhl_settings'][ 'shipper_country' ],
																'state' => $this->args['dhl_settings']['shipper_address_state'],
															)
														),
												'Communication' =>
													array(
														'phone' => $this->args['dhl_settings']['shipper_phone'],
														'email' => $this->args['dhl_settings']['shipper_email']
														)
											),
										'Receiver' =>
											array(
												'name1' => $this->args['shipping_address']['name'],
												'Address' =>
													array(
														'name2' => $this->args['shipping_address']['company'],
														'streetName' => $this->args['shipping_address']['address_1'],
														'streetNumber' => $this->args['shipping_address']['address_2'],
														// 'addressAddition' => $this->args['shipping_address']['address_2'],
														'zip' => $this->args['shipping_address']['postcode'],
														'city' => $this->args['shipping_address']['city'],
														'Origin' =>
															array(
																'countryISOCode' => $this->args['shipping_address']['country'],
																'state' => $this->args['shipping_address']['state']
															)
														),
												// 'Packstation' => array(),
												'Communication' =>
													array(
														'phone' => $this->args['shipping_address']['phone'],
														'email' => $this->args['shipping_address']['email']
														)
											)											
									),
								'labelResponseType' => 'B64'

						)
				);
			// error_log($this->args['order_details']['return_address']);
			if ( isset( $this->args['order_details']['return_address'] ) && ( $this->args['order_details']['return_address'] == 'yes' ) ) {

				$dhl_label_body['ShipmentOrder']['Shipment']['ShipmentDetails']['returnShipmentAccountNumber'] = $return_account_number;

				$dhl_label_body['ShipmentOrder']['Shipment']['ReturnReceiver'] = array(
												'Name' =>
													array(
														'name1' => $this->args['dhl_settings']['return_name'],
														'name2' => $this->args['dhl_settings']['return_company']
														),
												'Address' =>
													array(
														'streetName' => $this->args['dhl_settings']['return_address'],
														'streetNumber' => intval($this->args['dhl_settings']['return_address_no']),
														'zip' => $this->args['dhl_settings']['return_address_zip'],
														'city' => $this->args['dhl_settings']['return_address_city'],
														'Origin' =>
															array(
																'countryISOCode' => $this->args['dhl_settings'][ 'return_country' ],
																'state' => $this->args['dhl_settings']['return_address_state'],
															)
														),
												'Communication' =>
													array(
														'phone' => $this->args['dhl_settings']['return_phone'],
														'email' => $this->args['dhl_settings']['return_email']
														)
											);
				// error_log(print_r($this->dhl_label_body['ShipmentOrder']['Shipment']['ReturnReceiver'],true));
			}

			// Is codeable set here since it's at a high level in the message
			if ( isset($this->args['order_details']['is_codeable']) && ($this->args['order_details']['is_codeable'] == 'yes') ) {
				$dhl_label_body['ShipmentOrder']['PrintOnlyIfCodeable'] = array( 'active' => 1 );
			}

			// If international shipment add export information
			if( ! $this->is_european_shipment() ) {

				// TEST THIS
				if ( sizeof($this->args['items']) > self::DHL_MAX_ITEMS ) {
					throw new Exception( sprintf( __('Only %s ordered items can be processed, your order has %s', 'pr-shipping-dhl'), self::DHL_MAX_ITEMS, sizeof($this->args['items']) ) );
				}
				
				$customsDetails = array();

				$item_description = '';
				foreach ($this->args['items'] as $key => $item) {
					// weightInKG is in KG needs to be changed if 'g' or 'lbs' etc.
					$item['item_weight'] = $this->maybe_convert_weight( $item['item_weight'], $this->args['order_details']['weightUom'] );

					$item_description .= ! empty( $item_description ) ? ', ' : '';
					$item_description .= $item['item_description'];

					$json_item = array(
									'description' => substr( $item['item_description'], 0, 255 ),
									'countryCodeOrigin' => $item['country_origin'],
									'customsTariffNumber' => $item['hs_code'],
									'amount' => intval( $item['qty'] ),
									'netWeightInKG' => round( floatval( $item['item_weight'] ), 2 ),
									'customsValue' => round( floatval( $item['item_value'] ), 2 ),
								);
					// $customsDetails = $json_item;
					array_push($customsDetails, $json_item);
				}

				$item_description = substr( $item_description, 0, 255 );

				$dhl_label_body['ShipmentOrder']['Shipment']['ExportDocument'] = 
					array(
						'invoiceNumber' => $this->args['order_details']['order_id'],
						'exportType' => 'OTHER',
						'exportTypeDescription' => $item_description,
						'termsOfTrade' => $this->args['order_details']['duties'],
						'placeOfCommital' => $this->args['shipping_address']['country'],
						'ExportDocPosition' => $customsDetails
					);
			}

			// Unset/remove any items that are empty strings or 0, even if required!
			$this->body_request = $this->walk_recursive_remove( $dhl_label_body );
			// Ensure Export Document is set before adding additional fee
			if( isset( $this->body_request['ShipmentOrder']['Shipment']['ExportDocument'] ) ) {
				// Additional fees, required and 0 so place after check
				$this->body_request['ShipmentOrder']['Shipment']['ExportDocument']['additionalFee'] = 0;
			}

			return $this->body_request;
			// $this->body_request = json_encode($dhl_label_body, JSON_PRETTY_PRINT);
		}
		
	}
	
	private function maybe_convert_weight( $weight, $UoM ) {
		switch ( $UoM ) {
			case 'g':
				$weight = $weight / 1000;
				break;
			case 'lb':
				$weight = $weight / 2.2;
				break;
			case 'oz':
				$weight = $weight / 35.274;
				break;
			default:
				break;
		}
		return $weight;
	}

	// Unset/remove any items that are empty strings or 0
	private function walk_recursive_remove( array $array ) { 
	    foreach ($array as $k => $v) { 
	        if (is_array($v)) { 
	            $array[$k] = $this->walk_recursive_remove($v); 
	        } 
            
            if ( empty( $v ) ) { 
                unset($array[$k]); 
            } 
	        
	    }
	    return $array; 
	} 
}
