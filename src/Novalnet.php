<?php
/**
 * Contains the helper functions.
 *
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.2.0
 */

namespace Drupal\commerce_novalnet;

use Drupal\Core\Locale\CountryManager;
use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\Core\Url;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Messenger\MessengerInterface;

class Novalnet {

  private static $redirectPayments = ['novalnet_paypal', 'novalnet_ideal', 'novalnet_giropay',
    'novalnet_eps', 'novalnet_przelewy24', 'novalnet_sofort', 'novalnet_onlinebank_transfer', 'novalnet_bancontact', 'novalnet_postfinance', 'novalnet_postfinancecard'
  ];


   /**
    * Form header values
    *
    * @param string $access_key
    *
    * @return string $headers
    */
   public  static function formheaders($access_key) {
       $global_configuration = \Drupal::config('commerce_novalnet.application_settings');
       $encoded_key = (!empty($access_key)?$access_key:$global_configuration->get('access_key'));
       $headers = [
            'Content-Type:application/json',
            'Charset:utf-8',
            'Accept:application/json',
            'X-NN-Access-Key:' . base64_encode($encoded_key)
        ];
        return $headers;

   }

   /**
    * Get payment type
    *
    * @param string $payment
    *
    * @return string $payment_types[$payment]
    */
   public static function getPaymentType($payment) {
       $payment_types = array(
            'novalnet_invoice'              => 'INVOICE',
            'novalnet_guaranteed_invoice'   => 'GUARANTEED_INVOICE',
            'novalnet_prepayment'           => 'PREPAYMENT',
            'novalnet_sepa'                 => 'DIRECT_DEBIT_SEPA',
            'novalnet_guaranteed_sepa'      => 'GUARANTEED_DIRECT_DEBIT_SEPA',
            'novalnet_cashpayment'          => 'CASHPAYMENT',
            'novalnet_cc'                   => 'CREDITCARD',
            'novalnet_sofort'               => 'ONLINE_TRANSFER',
            'novalnet_onlinebank_transfer'  => 'ONLINE_BANK_TRANSFER',
            'novalnet_ideal'                => 'IDEAL',
            'novalnet_eps'                  => 'EPS',
            'novalnet_giropay'              => 'GIROPAY',
            'novalnet_paypal'               => 'PAYPAL',
            'novalnet_przelewy24'           => 'PRZELEWY24',
            'novalnet_postfinance'          => 'POSTFINANCE',
            'novalnet_postfinancecard'      => 'POSTFINANCE_CARD',
            'novalnet_multibanco'           => 'MULTIBANCO',
            'novalnet_bancontact'           => 'BANCONTACT',
        );
        return $payment_types[$payment];
   }

   /**
    * Get merchant details
    *
    * @param array $request
    *
    * @return array $response
    */
   public static function getMerchantDetails($request) {
        $endpoint = self::getPaygateURL('merchant');
        $data = [
            'merchant'      =>  [
            'signature' => $request['public_key']
            ],
            'custom'        => [
                'lang'      => $request['lang']
            ]
        ];
        $json_data = json_encode($data);
        $response = self::sendRequest($json_data, $endpoint, $request['access_key']);
        return $response;
    }

	/**
	 * Process refund
     *
     * @param integer $tid
     * @param integer $amount
     *
     * @return array $response
     */
    public static function refund($tid, $amount) {
      $data = [];
      $data ['transaction'] = array (
                            'tid' => $tid,
                            'amount' => $amount*100);
      $data ['cust'] = array('lang' => strtoupper(\Drupal::languageManager()->getCurrentLanguage()->getId()),);
      $json_data = json_encode($data);
      $result = Novalnet::sendRequest($json_data, Novalnet::getPaygateURL('refund'));
      $response = Json::decode($result);
      return $response;
    }

    /**
     * Update Transaction
     *
     * @param integer $tid
     * @param string $url
     *
     * @return array $response
     */
    public static function updateTransaction($tid, $url) {
      $data = [];
      $data ['transaction'] = array('tid' => $tid);
      $data ['cust'] = array('lang' => strtoupper(\Drupal::languageManager()->getCurrentLanguage()->getId()));
      $json_data = json_encode($data);
      $result = Novalnet::sendRequest($json_data, Novalnet::getPaygateURL($url));
      $response = Json::decode($result);
      return $response;
    }

    /**
     * Get merchant data
     *
     * @return array
     */
    public static function getMerchantData() {
        $config = \Drupal::config('commerce_novalnet.application_settings');
        return array(
        'signature' => $config->get('product_activation_key'),
        'tariff' => $config->get('tariff_id')
        );

    }

    /**
     * Get customer data
     *
     * @param object $order
     * @param boolean $allow_b2b
     *
     * @return array $customer_data
     */
    public static function getCustomerData($order, $payment, $allow_b2b = null) {
        $config                                 = \Drupal::config('commerce_novalnet.application_settings');
        $profile                                = $order->getBillingProfile();
        $address                                = $profile->get('address')->first()->getValue();
        $shipping_address = '';
        if (\Drupal::moduleHandler()->moduleExists('commerce_shipping')
           &&$order->hasField('shipments') && !($order->get('shipments')->isEmpty())) {
          /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
          $shipments = $payment->getOrder()->get('shipments')->referencedEntities();
          $first_shipment = reset($shipments);
          /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $shipping_address */
          $shipping_address = $first_shipment->getShippingProfile()->address->first();
        }
        $customer_data = [
                'customer_no'      => \Drupal::currentUser()->id(),
                'first_name'       => $address['given_name'],
                'last_name'        => $address['family_name'],
                'customer_ip' => self::getIpAddress(),
                'email'            => $order->get('mail')->first()->value ];
        $customer_data['billing'] = [
                'street'           => $address['address_line1'] . ' ' . $address['address_line2'],
                'city'             => $address['locality'],
                'zip'              => $address['postal_code'],
                'country_code'     => $address['country_code']];
        if (!empty($shipping_address)) {
          $shipping_address = [
                'street'           => $shipping_address->getAddressLine1() . ' ' . $shipping_address->getAddressLine2(),
                'city'             => $shipping_address->getLocality(),
                'zip'              => $shipping_address->getPostalCode(),
                'country_code'     => $shipping_address->getCountryCode()];
        }
        if (!empty($shipping_address) && $customer_data['billing'] != $shipping_address) {
		  $customer_data['shipping'] = $shipping_address;
		}
		else {
		   $customer_data['shipping']['same_as_billing'] = 1;
		}

        if (in_array($payment, array('novalnet_invoice', 'novalnet_sepa'))
           && \Drupal::service('session')->get($payment. '_guarantee_payment')) {
           if (!empty($address['organization']) && $allow_b2b == 1) {
             $customer_data['billing']['company'] = $address['organization'];
             \Drupal::service('session')->set('company', $address['organization']);
            }
         }
         elseif (!empty($address['organization'])) {
            $customer_data['billing']['company'] = $address['organization'];
         }
        return $customer_data;
    }

    /**
     * Get transaction data
     *
     * @param object $order
     * @param object $payment
     * @param object $configuration
     *
     * @return array $transaction_data
     */
    public static function getTransactionData($order, $code, $payment, $configuration) {

        $commerce_info    = $commerce_info = \Drupal::service('extension.list.module')->getExtensionInfo('commerce');
        $transaction_data = [
                'currency'     => $payment->getAmount()->getCurrencyCode(),
                'test_mode'    => ($configuration['mode'] == 'test') ? 1 : 0,
                'amount'       => self::formatAmount($payment->getAmount()->getNumber()),
                'order_no'     => $order->id(),
                'system_name'  => 'drupal-' . \Drupal::VERSION . '-commerce',
                'system_ip'    =>  self::getIpAddress(),
                'system_version' => \Drupal::VERSION . '-' . $commerce_info['version'] . '-NN1.2.0',
		];

        if (in_array($code, self::$redirectPayments)) {
            $transaction_data['error_return_url'] = self::getReturnUrl($order, 'commerce_payment.checkout.cancel');
            $transaction_data['return_url']       = self::getReturnUrl($order, 'commerce_payment.checkout.return');
        }
        return $transaction_data;

    }

   /**
    * Getting the transaction details from server
    *
    * @param array $request
    *
    * @return array $transaction_response
    */
   public static function getTransactionDetails($request) {
        $global_configuration = \Drupal::config('commerce_novalnet.application_settings');
        // Check checksum value correct or not
        $txn_secret = !empty(\Drupal::service('session')->get('novalnet_txn_secret'))? \Drupal::service('session')->get('novalnet_txn_secret') : $request['txn_secret'];
        // Handle Response
        if (!empty($request['checksum']) && !empty($request['tid']) && $txn_secret && !empty($request['status'])) {
            $token_string = $request['tid'] . $txn_secret . $request['status'] . strrev($global_configuration->get('access_key'));
            $generated_checksum = hash('sha256', $token_string);
            if ($generated_checksum !== $request['checksum']) {
                \Drupal::messenger()->addMessage(t('While redirecting some data has been changed. The hash check failed.'), 'error');
                exit;
            }
            $data = [];
            // Transaction data
            $data['transaction'] = ['tid'=>$request['tid'], ]; // The TID for which you need to get the details
            // Custom data
            $data['custom'] = [ 'lang'      => strtoupper(\Drupal::languageManager()->getCurrentLanguage()->getId())]; // Merchant's selected language
            // Convert the array to JSON string
            $json_data = json_encode($data);
            $url = self::getPaygateURL('transaction');
            // Sending transaction call to server
            $transaction_response = self::sendRequest($json_data,$url);
            return $transaction_response;
        }

    }

    /**
     * Get payport URL
     *
     * @param string $data
     *
     * @return string
     */
    public static function getPaygateURL($data) {
        switch ($data) {
            case 'payment':
              return  'https://payport.novalnet.de/v2/payment';
            case 'authorize':
              return 'https://payport.novalnet.de/v2/authorize';
            case 'merchant':
              return 'https://payport.novalnet.de/v2/merchant/details';
            case 'transaction':
              return 'https://payport.novalnet.de/v2/transaction/details';
            case 'apiconfigure':
              return 'https://payport.novalnet.de/v2/webhook/configure';
            case 'refund':
              return 'https://payport.novalnet.de/v2/transaction/refund';
            case 'capture':
              return 'https://payport.novalnet.de/v2/transaction/capture';
            case 'cancel':
              return 'https://payport.novalnet.de/v2/transaction/cancel';

        }
    }

    /**
     * Sending request to server
     *
     * @param string $data
     * @param string $url
     * @param string $access_key
     *
     * @return string $result
     */
    public static function sendRequest($data, $url, $access_key='') {
        $headers = self::formheaders($access_key);
        // Initiate cURL
        $curl = curl_init();
        // Set the url
        curl_setopt($curl, CURLOPT_URL, $url);
        // Set the result output to be a string
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        // Set the POST value to true (mandatory)
        curl_setopt($curl, CURLOPT_POST, true);
        // Set the post fields
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        // Set the headers
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        // Execute cURL
        $result = curl_exec($curl);
        // Handle cURL error
        if (curl_errno($curl)) {
            echo 'Request Error:' . curl_error($curl);
            return $result;
        }
        // Close cURL
        curl_close($curl);
        return $result;
	}

	/**
	 * Validate merchant data
	 */
	public static function validateParams() {
		// Validate payment based on global configuration.
		$global_configuration = \Drupal::config('commerce_novalnet.application_settings');
		if (!$global_configuration->get('product_activation_key') || !$global_configuration->get('tariff_id')) {
		  return true;
		}
		return false;
	}

	/**
	* Display common configuartion fields
	*
	* @param array $form
	* @param array $configuration
	*
	* @return array $form
	*/
	public static function getCommonFields(array &$form, array $configuration) {
		$form['notification'] = [
		  '#type' => 'textfield',
		  '#title' => t('Notification for the buyer'),
		  '#default_value' => $configuration['notification'],
		  '#description' => t('The entered text will be displayed on the checkout page'),
		];
	}

	/**
	 * Display onhold configuartion fields
	 *
	 * @param array $form
	 * @param array $configuration
	 * @param boolean $payment
	 *
	 * @return array $form
	 */
	public static function getManualChecklimit(array &$form, array $configuration, $payment = false) {
		$form['transaction_type'] = [
		  '#type'          => 'select',
		  '#title'         => t('Payment Action'),
		  '#options'       => [
			'capture'   => t('Capture'),
			'authorize' => t('Authorize'),
		  ],
		  '#default_value' => isset($configuration['transaction_type']) ? $configuration['transaction_type'] : 'capture',
		  '#description' => t('Choose whether or not the payment should be charged immediately. Capture completes the transaction by transferring the funds from buyer account to merchant account. Authorize verifies payment details and reserves funds to capture it later, giving time for the merchant to decide on the order.'),
		  '#attributes'    => ['id' => 'transaction_type'],
		];
		$form['manual_amount_limit'] = [
		  '#type'             => 'number',
		  '#size'             => 10,
		  '#title'            => t('Minimum transaction amount for authorization (in minimum unit of currency. E.g. enter 100 which is equal to 1.00)'),
		  '#description'      => t('In case the order amount exceeds the mentioned limit, the transaction will be set on-hold till your confirmation of the transaction. You can leave the field empty if you wish to process all the transactions as on-hold.'),
		  '#default_value'    => isset($configuration['manual_amount_limit']) ? $configuration['manual_amount_limit'] : '',
		  '#states'        => [
			'invisible' => ['select[id="transaction_type"]' => [['value' => 'capture']]],
		  ],
		];
	}

	/**
	* Get success return url for redirect payments
	*
	* @param \Drupal\commerce_order\Entity\OrderInterface $order
	* @param string $type
	*
	* @return string
	*/
	public static function getReturnUrl(OrderInterface $order, $type) {
		$arguments = [
		  'commerce_order' => $order->id(),
		  'step' => 'payment',
		];
		return (new Url($type, $arguments, ['absolute' => true]))
		  ->toString();
	}

	/**
	 * Display guarantee payment configuration fields
	 *
	 * @param array $form
	 * @param string $payment
	 * @param array $configuration
	 *
	 * @return array $form
	 */
	public static function getGuaranteeConfiguration(array &$form, $payment, $configuration) {
		$form['guarantee_configuration'] = [
		'#type' => 'details',
		'#title' => t('Payment guarantee configuration'),
		'#description' => sprintf('<div><p><strong>%1$s</strong><br/>
				<ul>
					<li>%2$s</li>
					<li>%3$s</li>
					<li>%4$s</li>
					<li>%5$s</li>
					<li>%6$s</li>
					<li>%7$s</li>
				</ul></p></div>', t('Payment guarantee requirements:'), t('Allowed B2C countries: Germany, Austria, Switzerland'),t('Allowed B2B countries: European Union'),
		t('Allowed currency: €'),t('Minimum order amount: €9,99 or more'),t('Age limit: 18 years or more'),
		t('The billing address must be the same as the shipping address')),
		'#open' => true,
		];
		$form['guarantee_configuration'][$payment . '_guarantee_payment'] = [
		  '#type' => 'checkbox',
		  '#title' => '<b>'.t('Enable payment guarantee').'</b>',
		  '#default_value' => $configuration['guarantee_configuration'][$payment . '_guarantee_payment'],
		  '#attributes' => ($configuration['guarantee_configuration'][$payment . '_guarantee_payment'] == 1 ) ? ['checked'] : (( $configuration['guarantee_configuration'][$payment . '_guarantee_payment'] == '0' ) ? [''] : [ 'checked' => 'checked'] ),
		 ];
		$form['guarantee_configuration'][$payment . '_guarantee_payment_minimum_order_amount'] = [
		  '#type' => 'number',
		  '#title' => t('Minimum order amount for payment guarantee (in minimum unit of currency. E.g. enter 100 which is equal to 1.00)'),
		  '#description' => t('Enter the minimum amount (in cents) for the transaction to be processed with payment guarantee. For example, enter 100 which is equal to 1,00. By default, the amount will be 9,99 EUR'),
		  '#default_value' => $configuration['guarantee_configuration'][$payment . '_guarantee_payment_minimum_order_amount'],
		];

		$form['guarantee_configuration'][$payment . '_force_normal_payment'] = [
		  '#type' => 'checkbox',
		  '#title' => '<b>'.t('Force Non-Guarantee payment').'</b>',
		  '#description' => t('Even if payment guarantee is enabled, payments will still be processed as non-guarantee payments if the payment guarantee requirements are not met. Review the requirements under "Enable Payment Guarantee" in the Installation Guide.'),
		  '#default_value' => $configuration['guarantee_configuration'][$payment . '_force_normal_payment'],
		];
		 $form['guarantee_configuration'][$payment . '_allow_b2b_customer'] = [
		  '#type' => 'checkbox',
		  '#title' => '<b>'.t('Allow B2B Customer').'</b>',
		  '#description' => t('Allow b2b customers to place order'),
		  '#default_value' => $configuration['guarantee_configuration'][$payment . '_allow_b2b_customer'],
		  '#attributes' => ($configuration['guarantee_configuration'][$payment . '_allow_b2b_customer'] == 1 ) ? ['checked'] : (( $configuration['guarantee_configuration'][$payment . '_allow_b2b_customer'] == '0' ) ? [''] : [ 'checked' => 'checked'] ),
		];
	}

	/**
	 * Validate guarantee conditions
	 *
	 * @param object $order
	 * @param string $payment
	 * @param array $guarantee_configuartion
	 *
	 * @return string
	 */
	public static function checkGuaranteeProcess($order, $payment, array $guarantee_configuartion,$message) {
		if ($guarantee_configuartion['guarantee_configuration'][$payment . '_guarantee_payment']) {
		   $minimum_amount = !empty($guarantee_configuartion['guarantee_configuration'][$payment . '_guarantee_payment_minimum_order_amount'])
		  ? $guarantee_configuartion['guarantee_configuration'][$payment . '_guarantee_payment_minimum_order_amount'] : 999;

		  if ($order->getTotalPrice()->getCurrencyCode() != 'EUR') {
			$message .= t('Only EUR currency allowed<br>');
		  }
		  if (self::formatAmount($order->getTotalPrice()->getNumber()) < (int) $minimum_amount) {
			$message .= t('Minimum order amount must be ' . $minimum_amount/100 . ' ' .'Є'. '<br>');
		  }

		  if (!$message) {
			\Drupal::service('session')->set($payment . '_guarantee_payment', true);
		  }
		  if (!empty($message) && $guarantee_configuartion['guarantee_configuration'][$payment . '_force_normal_payment']) {
			\Drupal::service('session')->remove($payment . '_guarantee_payment');
		  }
		  if (!empty($message) && $guarantee_configuartion['guarantee_configuration'][$payment . '_force_normal_payment']) {
			$message = '';
			// Process as normal payment.
			\Drupal::service('session')->remove($payment . '_guarantee_payment');
		  }
		  if ($message) {

			$guarantee_message = t("The payment cannot be processed, because the basic requirements for the payment guarantee haven't been met<br>");
			\Drupal::messenger()->addError(t($guarantee_message.$message));
			throw new InvalidRequestException($guarantee_message);
		  }
		  return true;
		}
		else {
		  // Process as normal payment
		  \Drupal::service('session')->remove($payment . '_guarantee_payment');
		}
	}

	/**
	 * Validate guarantee address
	 *
	 * @param object $order
	 * @param object $configuartion
	 * @param string $payment
	 *
	 * @return string
	 */
	public static function checkGuaranteeAddress($order, $configuartion, $payment) {
	  $error = '';
	  $billing_address = $order->getBillingProfile()->address->first();
	  $shipping_address = '';
  $minimum_amount = !empty($configuartion['guarantee_configuration'][$payment . '_guarantee_payment_minimum_order_amount'])
		  ? $configuartion['guarantee_configuration'][$payment . '_guarantee_payment_minimum_order_amount'] : 999;
	  if (\Drupal::moduleHandler()->moduleExists('commerce_shipping') && $order->hasField('shipments') && !($order->get('shipments')->isEmpty())) {
	  /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
	  $shipments = $payment->getOrder()->get('shipments')->referencedEntities();
	  $first_shipment = reset($shipments);
	  /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $shipping_address */
	  $shipping_address = $first_shipment->getShippingProfile()->address->first();
	  }
 if (self::formatAmount($order->getTotalPrice()->getNumber()) < (int) $minimum_amount) {
			$error = '<br>'.t('Minimum order amount must be ' . $minimum_amount/100 . ' ' .'Є'. '<br>');
	  }
	  if (!empty($shipping_address)) {
		$shipping_data = [
		  'address' => $shipping_address->getAddressLine1() . ' ' . $shipping_address->getAddressLine2(),
		  'country' => $shipping_address->getCountryCode(),
		  'city' => $shipping_address->getLocality(),
		  'zip' => $shipping_address->getPostalCode(),
		];
		$billing_data = [
		  'address' => $billing_address->getAddressLine1() . ' ' . $billing_address->getAddressLine2(),
		  'country' => $billing_address->getCountryCode(),
		  'city' => $billing_address->getLocality(),
		  'zip' => $billing_address->getPostalCode(),
		];

		if ($billing_data == $shipping_data) {
		  $error .= t('The shipping address must be the same as the billing address<br>');
		}
	  }
	  if (!in_array($billing_address->getCountryCode(), ['AT', 'DE', 'CH'])) {
		$error .= t('Only Germany, Austria or Switzerland are allowed<br>');
	  }
	  if (!empty($error) && $configuartion['guarantee_configuration'][$payment . '_force_normal_payment']) {
		$error = '';
		// Process as normal payment
		\Drupal::service('session')->remove($payment . '_guarantee_payment');
	  }
	  elseif (!empty($error)) {
		$guarantee_message = t("The payment cannot be processed, because the basic requirements for the payment guarantee haven't been met<br>");
		\Drupal::messenger()->addError(t($guarantee_message.$error));
	throw new InvalidRequestException($guarantee_message.$error);
	  }
	}

	/**
	* Validates the guarantee confgiuration
	*
	* @param array $form_value
	* @param string $payment
	*
	* @return mixed
	*/
	public static function validateGuaranteeConfiguration(array $form_value, $payment) {
		if ($form_value[$payment . '_guarantee_payment']) {
		  $minimum_amount = trim($form_value[$payment . '_guarantee_payment_minimum_order_amount'])
								? trim($form_value[$payment . '_guarantee_payment_minimum_order_amount']) : 999;
		  if (!self::checkNumeric($minimum_amount)) {
			return t('The amount is invalid');
		  }
		  elseif ($minimum_amount < 999) {
			return t('The minimum amount should be at least 9,99 EUR');
		  }
		}
		return false;
	}

	/**
	 * Get remote address.
	 *
	 * @return string $ip
	 */
	public static function getIpAddress() {
		$customerIp = \Drupal::request()->getClientIp();
		$ip = self::getRemoteAddress();
		$ip = ($customerIp != $ip) ? $ip : $customerIp;
		return $ip;

	}
	/**
	 * Get remote address
	 *
	 * @return string $ip
	 */
	public static function getRemoteAddress() {
	  $IpKeys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR');
	  foreach ($IpKeys as $key) {
		  if (array_key_exists($key, $_SERVER) === true) {
			  foreach (explode(',', $_SERVER[$key]) as $ip) {
				  return trim($ip);
			  }
		  }
	  }
	}

  /**
   * Add js file/ library.
   *
   * @param array $form
   * @param string $payment
   * @param string $library
   *
   * @return array $form
   */
  public static function includeFiles(array &$form, $payment, $library) {
    $form['#attached'] = [
      'library' => [
        'commerce_novalnet/' . $payment,
      ],
      'drupalSettings' => [
        'commerce_novalnet' => [
          $payment => $library,
        ],
      ],
    ];
  }

	/**
	* Validates the given input data is numeric or not.
	*
	* @param string $input
	*
	* @return mixed
	*/
	public static function checkNumeric($input) {
		return (preg_match('/^[0-9]+$/', $input)) ? $input : false;
	}
	/**
	 * Form transaction details including bank details and complete the order
	 *
	 * @param array $response
	 * @param string $payment_method
	 * @param object $order
	 * @param string $test_mode
	 */
	public static function completeOrder(array $response, $payment_method, $order, $test_mode) {
		$global_configuration = \Drupal::config('commerce_novalnet.application_settings');
		$information = self::formTransactionDetails($response, $test_mode, $payment_method);
		if ($payment_method == 'novalnet_prepayment' || ($payment_method == 'novalnet_invoice' && $response['transaction']['status_code'] != 75)) {
		  $information .= self::formBankDetails($response);
		}
		if ($payment_method == 'novalnet_cashpayment') {
		  $information .= self::formStoreDetails($response);
		}
		if ($payment_method == 'novalnet_multibanco') {
		  $information .= self::formPaymentReference($response);
		}
		$order->setData('transaction_details', ['message' =>'<br />'.$information]);
		$order->save();
	}

	/**
	 * Form transaction details
	 *
	 * @param array $response
	 * @param string $test_mode
	 * @param string $payment
	 *
	 * @return string $transaction_details
	 */
	public static function formTransactionDetails(array $response, $test_mode, $payment = '') {
		$transaction_details = '';
		if (\Drupal::service('session')->get($payment . '_guarantee_payment')) {
		  $transaction_details .= t('This is processed as a guarantee payment') . '<br />';
		}
		$transaction_details .= strip_tags(t('Novalnet transaction ID: @tid', ['@tid' => $response['transaction']['tid']]));
		if (!empty($response['transaction']['test_mode']) || !empty($test_mode)) {
		  $transaction_details .= '<br />' . t('Test order') . '<br />';
		}
		if (\Drupal::service('session')->get($payment . '_guarantee_payment') && $response['transaction']['status_code'] == '75') {
			$transaction_details .= ($response['transaction']['payment_type'] == 'GUARANTEED_INVOICE') ? t('Your order is under verification and once confirmed, we will send you our bank details to where the order amount should be transferred. Please note that this may take upto 24 hours.') . '<br />' :  t('Your order is under verification and we will soon update you with the order status. Please note that this may take upto 24 hours.') . '<br />';
		}
		return $transaction_details;
	}
     /**
	* Form Payment Reference comments for Multibanco
	*
	* @param array $response
	*
	* @return string
	*/
	public static function formPaymentReference (array $response) {
		$currency_formatter = \Drupal::service('commerce_price.currency_formatter');
		$amount = sprintf("%.2f", $response['transaction']['amount']/100);
		$amount = $currency_formatter->format($amount, $response['transaction']['currency']);
		$reference_details = t('Please use the following payment reference details to pay the amount of @amount at a Multibanco ATM or through your internet banking.' , ['@amount' => $amount]) .'<br />' .
		t('Payment Reference: @reference',['@reference' => $response['transaction']['partner_payment_reference']]);
		return $reference_details;
	}
	/**
	 * Form cash payment store details
	 *
	 * @param array $response
	 *
	 * @return string $store_details
	 */
	public static function formStoreDetails(array $response) {
		if (!empty($response['transaction']['due_date'])) {
			$store_details = strip_tags(t('Slip expiry date: @date', ['@date' => date_format(date_create($response['transaction']['due_date']), "m/d/Y")])) . '<br /><br />';
		}
		if (isset($response['transaction']['nearest_stores'])) {
			$store_details .= t('Store(s) near you') . '<br /><br />';
			$countries = CountryManager::getStandardList();
			foreach ($response['transaction']['nearest_stores'] as $nearestStore) {
				foreach (array('store_name', 'street', 'city', 'zip', 'country_code') as $value) {
					$store_details .= ($value == 'country_code') ? $countries[$nearestStore[$value]] . "<br/> <br/>" : $nearestStore[$value] . "<br/>";
				}
			}
		}
		return $store_details;
	}

	/**
	 * Get payment description
	 *
	 * @param string $payment
	 *
	 * @return string $description
	 */
	public static function getDescription($payment) {
		switch ($payment) {
		  case 'novalnet_cc':
			$description = t('Your credit/debit card will be charged immediately after the order is completed');
			break;

		  case 'novalnet_sepa':
			$description = t('The amount will be debited from your account by Novalnet');
			break;

		  case 'novalnet_invoice':

		  case 'novalnet_prepayment':
			$description = t('You will receive an e-mail with the Novalnet account details to complete the payment');
			break;

		  case 'novalnet_cashpayment':
			$description = t('On successful checkout, you will receive a payment slip/SMS to pay your online purchase at one of our retail partners (e.g. supermarket)');
			break;
		  case 'novalnet_multibanco':
			$description = t('On successful checkout, you will receive a payment reference. Using this payment reference, you can either pay in the Multibanco ATM or through your online bank account ');
			break;
		  case 'novalnet_sofort':
			$description = t('You will be redirected to Sofort. Please don’t close or refresh the browser until the payment is completed.');
			break;
		  case 'novalnet_onlinebank_transfer':
			$description = t('You will be redirected to banking page. Please don’t close or refresh the browser until the payment is completed.');
			break;
		  case 'novalnet_ideal':
			$description = t('You will be redirected to iDEAL. Please don’t close or refresh the browser until the payment is completed.');
			break;
		  case 'novalnet_eps':
			$description = t('You will be redirected to eps. Please don’t close or refresh the browser until the payment is completed.');
			break;
		  case 'novalnet_giropay':
			$description = t('You will be redirected to giropay. Please don’t close or refresh the browser until the payment is completed.');
			break;
		  case 'novalnet_paypal':
			$description = t('You will be redirected to PayPal. Please don’t close or refresh the browser until the payment is completed.');
			break;
		  case 'novalnet_przelewy24':
			$description = t('You will be redirected to Przelewy24. Please don’t close or refresh the browser until the payment is completed.');
			break;
		  case 'novalnet_bancontact':
			$description = t('You will be redirected to Bancontact. Please don’t close or refresh the browser until the payment is completed.');
			break;

		  case 'novalnet_postfinance':

		  case 'novalnet_postfinancecard':
			$description = t('You will be redirected to Postfinance. Please don’t close or refresh the browser until the payment is completed.');
			break;
		}
		return $description;
	}

	/**
	 * Function for build the payment name with logo.
	 *
	 * @param string $title
	 * @param string $payment_method
	 * @return string $link_title
	 */
	public static function displayPaymentLogo($title, $payment_method) {
		$payment_url = base_path() . drupal_get_path('module', 'commerce_novalnet') . '/images/'. $payment_method . '.png';
		$link_title = [
		  '#theme' => 'image',
		  '#uri' => $payment_url,
		  '#alt' => $title,
		];
		return $link_title;
	}

	/**
	 * Builds the URL to the return page.
	 *
	 * @param int $order_id
	 *
	 * @return string
	 */
	public static function build3dCheckReturnUrl($order_id) {
		return Url::fromRoute('commerce_novalnet.3ds.return', [
		  'commerce_order' 	=> $order_id,
		  'step' 			=> 'payment',
		], ['absolute'		=> true])->toString();
	}

	/**
	 * Builds the URL to the "cancel" page.
	 *
	 * @param int $order_id
	 *
	 * @return string
	 */
	public static function build3dCheckCancelUrl($order_id) {
		return Url::fromRoute('commerce_novalnet.3ds.cancel', [
		  'commerce_order' 	=> $order_id,
		  'step' 			=> 'payment',
		], ['absolute' 		=> true])->toString();
	}

	/**
	 * Retrieves messages from server response.
	 *
	 * @param array $data
	 *
	 * @return string
	 */
	public static function responseMessage(array $data) {
		if (!empty($data['result']['status_text'])) {
		  return $data['result']['status_text'];
		}
		elseif (!empty($data['result']['status_desc'])) {
		  return $data['result']['status_desc'];
		}
		elseif (!empty($data['result']['status_message'])) {
		  return $data['result']['status_message'];
		}
		else {
		  return t('Payment was not successful. An error occurred');
		}
	}

	/**
	* Form Novalnet bank details
	*
	* @param array $response
	*
	* @return string
	*/
	public static function formBankDetails(array $response) {

		$currency_formatter = \Drupal::service('commerce_price.currency_formatter');
		$amount = sprintf("%.2f", $response['transaction']['amount']/100);
		$amount = $currency_formatter->format($amount, $response['transaction']['currency']);
		$bank_details = '<br/><div id=nn_bank_details>' . t('Please transfer the amount of @amt to the following account' ,['@amt' => $amount]);

		if($response['transaction']['status_code'] == 100 && !empty($response['transaction']['due_date'])) {
		  $bank_details .= t(' on or before : @due_date', ['@due_date' => $response['transaction']['due_date']]);
		}
		$bank_details .= '<br/>' . t('Account holder: @holder', ['@holder' => $response['transaction']['bank_details']['account_holder']]) . '<br/>' .
						 t('IBAN: @invoice_iban', ['@invoice_iban' => $response['transaction']['bank_details']['iban']]) . '<br/>' .
						 t('BIC: @invoice_bic', ['@invoice_bic' => $response['transaction']['bank_details']['bic']]) . '<br/>' .
						 t('Bank: @bank', ['@bank' => $response['transaction']['bank_details']['bank_name'].' '.
										   $response['transaction']['bank_details']['bank_place']]).'<br/>' ;
		$bank_details .= '<br/>'.t('Please use any of the following payment references when transferring the amount. This is necessary to match it with your corresponding order').'<br/>';
		$bank_details .= t('Payment Reference @reference : TID @tid', ['@reference' => 1, '@tid' => $response['transaction']['tid']]).'<br/>';
		$bank_details .= t('Payment Reference @reference : @invoice_ref', ['@reference' => 2, '@invoice_ref' => $response['transaction']['invoice_ref']]).'<br/>';
		$bank_details .= '</div>';
		return $bank_details;
	}

	/**
	 * Handle payment error and redirects to checkout.
	 *
	 * @param array $response
	 * @param int $order_id
	 * @param string $payment_method
	 *
	 * @return string
	 */
	public static function cancellation(array $response, $order_id, $payment_method, $tx_response = false) {
		global $base_url;
		if (in_array($payment_method, self::$redirectPayments) || $payment_method == 'novalnet_cc') {
			if($tx_response == true)
			{
				$status_text = $response['result']['status_text'];
			}
			else
			{
				$status_text = $response['status_text'];
			}
		}
		else {
			$status_text = self::responseMessage($response);
		}
		\Drupal::logger('commerce_novalnet')->error($status_text);
		\Drupal::logger('commerce_novalnet')->warning('Payment failed for order @order_id: @message', ['@order_id' => $order_id, '@message' => $status_text]);
		\Drupal::messenger()->addMessage($status_text, 'error');
	if (in_array($payment_method, self::$redirectPayments) || $payment_method == 'novalnet_cc') {
		  $response = new RedirectResponse($base_url . '/checkout/' . $order_id . '/order_information');
		  $response->send();
		}
		else {
		  throw new InvalidRequestException($status_text);
		}
	}

	/**
	 * Convert bigger amount to smaller unit
	 *
	 * @param float $amount
	 *
	 * @return int
	 */
	public static function formatAmount($amount) {
		return round($amount, 2) * 100;
	}
}
