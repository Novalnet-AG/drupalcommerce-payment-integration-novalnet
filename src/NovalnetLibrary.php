<?php
/**
 * @file
 * Contains the Novalnet helper functions.
 * 
 * @category   PHP
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.0.1
 */
 
namespace Drupal\commerce_novalnet;

use Drupal\Core\Locale\CountryManager;
use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\Core\Url;
use Drupal\Core\Messenger\MessengerInterface;


// Get the Novalnet image path.
define('NOVALNET_IMAGES_PATH', base_path() . drupal_get_path('module', 'commerce_novalnet') . '/images/');

/**
 * NovalnetLibrary class.
 */
class NovalnetLibrary {

  private $redirectPayments = ['novalnet_paypal', 'novalnet_ideal', 'novalnet_giropay',
    'novalnet_eps', 'novalnet_przelewy24', 'novalnet_sofort', 'novalnet_cc',
  ];
  private $dataParams = ['auth_code', 'product', 'tariff', 'amount',
    'test_mode',
  ];

  /**
   * Form vendor parameters and store in reference variable.
   *
   * @param object $request_parameters
   *   The request parameter.
   */
  public function commerceNovalnetMerchantParameters(&$request_parameters) {
    $global_configuration = \Drupal::config('commerce_novalnet.application_settings');
    $request_parameters['vendor'] = $global_configuration->get('vendor_id');
    $request_parameters['auth_code'] = $global_configuration->get('auth_code');
    $request_parameters['product'] = $global_configuration->get('project_id');
    $request_parameters['tariff'] = $global_configuration->get('tariff_id');
    $this->commerceNovalnetCheckAffiliateOrder($request_parameters);
    $this->access_key = !empty($request_parameters['payment_access_key']) ? $request_parameters['payment_access_key'] : $global_configuration->get('access_key');
    if ($referrer_id = $this->commerceNovalnetDigitsCheck($global_configuration->get('referrer_id'))) {
      $request_parameters['referrer_id'] = $referrer_id;
    }

  }

  /**
   * Validate the payment params.
   *
   * @param object $form_state
   *   The form state configuration.
   */
  public function commerceNovalnetValidateParams($form_state) {
    // Validate payment based on global configuration.
    $global_configuration = \Drupal::config('commerce_novalnet.application_settings');
    if (!$global_configuration->get('product_activation_key') || !$global_configuration->get('tariff_id')) {
      $form_state->setErrorByName('', t('Please fill in all the mandatory fields'));
      return;
    }
  }

  /**
   * Display common configuartion fields.
   *
   * @param array $form
   *   An array of the form field.
   * @param array $configuration
   *   An array of the configuration field.
   * @param string $payment
   *   The payment name.
   */
  public function commerceNovalnetGetCommonFields(array &$form, array $configuration, $payment) {
    \Drupal::service('session')->set('payment_name', $payment);
    $form['upload_logo'] = [
      '#type' => 'managed_file',
      '#name' => 'upload_logo',  
      '#description' => t('Browse logo to be displayed with the payment name'),
      '#title' => t('Payment logo'),
      '#preview' => TRUE,
      '#upload_location' => 'public://',
      '#upload_validators' => [
        'file_validate_name' => [],
        'file_validate_extensions' => ['jpg jpeg gif png'],
		'file_validate_image_resolution' => ['42x42'],

      ],
      '#default_value' => isset($configuration['upload_logo']) ? $configuration['upload_logo'] : '',
    ];
    $form['notification'] = [
      '#type' => 'textfield',
      '#title' => t('Notification for the buyer'),
      '#default_value' => $configuration['notification'],
      '#description' => t('The entered text will be displayed on the checkout page'),
    ];
   
  }

  /**
   * Display manual check limit configuartion fields.
   *
   * @param array $form
   *   An array of form field.
   * @param array $configuration
   *   An array of the configuration field.
   */
  public function commerceNovalnetGetManualChecklimit(array &$form, array $configuration, $payment = false) {
		$billing_agreement_des = ' ';
		
	if($payment == 'novalnet_paypal') {
			$billing_agreement_des = t('(In order to use this option you must have billing agreement option enabled in your PayPal account. Please contact your account manager at PayPal.)');
	}
    $form['transaction_type'] = [
      '#type'          => 'select',
      '#title'         => t('On-hold payment action'),
      '#options'       => [
        'capture'   => t('Capture'),
        'authorize' => t('Authorize'),
      ],
      '#default_value' => isset($configuration['transaction_type']) ? $configuration['transaction_type'] : 'capture',
      '#attributes'    => ['id' => 'transaction_type'],
    ];
    $form['manual_amount_limit'] = [
      '#type'             => 'number',
      '#size'             => 20,

      '#title'            => t('Minimum transaction limit for authorization (in minimum unit of currency. E.g. enter 100 which is equal to 1.00)'),
      '#description'      => t('In case the order amount exceeds the mentioned limit, the transaction will be set on-hold till your confirmation of the transaction. You can leave the field empty if you wish to process all the transactions as on-hold.').' '.$billing_agreement_des,
      '#default_value'    => isset($configuration['manual_amount_limit']) ? $configuration['manual_amount_limit'] : '',
      '#states'        => [
        'invisible' => ['select[id="transaction_type"]' => [['value' => 'capture']]],
      ],
    ];
  }

  /**
   * Display the order status configuration.
   *
   * @param array $form
   *   An array of form field.
   * @param array $configuration
   *   An array of the configuration field.
   * @param string $additional_status_field
   *   Get the additional field.
   */
  public function commerceNovalnetGetOrderStatus(array &$form, array $configuration, $additional_status_field = FALSE) {
    $form['order_completion_status'] = [
      '#type'          => 'select',
      '#title'         => t('Order completion status'),
      '#options'       => $this->commerceOrderStatusOptionsList(),
      '#default_value' => isset($configuration['order_completion_status']) ? $configuration['order_completion_status'] : 'pending',
    ];
    if ($additional_status_field) {
      $status_text = ($additional_status_field == 'callback_order_status') ? t('Callback order status') : t('Order status for the pending payment');
      $form[$additional_status_field] = [
        '#type'          => 'select',
        '#title'         => $status_text,
        '#options'       => $this->commerceOrderStatusOptionsList(),
        '#default_value' => isset($configuration[$additional_status_field]) ? $configuration[$additional_status_field] : 'pending',
      ];
    }
  }
  
  /**
   * Get redirect payment parameters.
   *
   * @param array $request_parameters
   *   The request parameters.
   * @param string $access_key
   *   The payment access key.
   * @param object $order
   *   The order object $order.
   *
   * @return array
   *   The redirect params
   */
  public function commerceNovalnetRedirectParameters(array &$request_parameters, $access_key, $order) {
    global $base_url;
    $request_parameters['uniqid']           = $this->getUniqueid();
    $request_parameters['return_method']    = $request_parameters['error_return_method'] = 'POST';
    $request_parameters['implementation']   = 'ENC';
    $request_parameters['user_variable_0']  = $base_url;
    $request_parameters['error_return_url'] = $this->getReturnUrl($order, 'commerce_payment.checkout.cancel');
    $request_parameters['return_url']       = $this->getReturnUrl($order, 'commerce_payment.checkout.return');
    return $this->commerceNovalnetEncodeParams($request_parameters, $access_key);
  }

  /**
   * Get success return url for redirect payments.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order object.
   * @param string $type
   *   The form type.
   * @param string $step
   *   The payment step.
   *
   * @return string
   *   Return the url
   */
  public function getReturnUrl(OrderInterface $order, $type, $step = 'payment') {
    $arguments = [
      'commerce_order' => $order->id(),
      'step' => $step,
    ];
    return (new Url($type, $arguments, ['absolute' => TRUE]))
      ->toString();
  }

  /**
   * Get unique id.
   *
   * @return string
   *   Return the array value
   */
  public function getUniqueid() {
    $randomwordarray = ['8', '7', '6', '5', '4', '3', '2', '1', '9', '0', '9', '7',
      '6', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0',
    ];
    shuffle($randomwordarray);
    return substr(implode($randomwordarray, ''), 0, 16);
  }

  /**
   * Transfer request data via httpClient method.
   *
   * @param array $data
   *   An array to be transmitted.
   * @param string $url
   *   An string value.
   * @param bool $json
   *   The Json for which the data to be get.
   *
   * @return string
   *   The response for the transmission
   */
  public function commerceNovalnetSendServerRequest(array $data, $url = 'https://payport.novalnet.de/paygate.jsp', $json = FALSE) {
    try {
      $response = \Drupal::httpClient()->post(
        $url,
        [
          'form_params' => $data,
        ]
         );
      if ($response->getStatusCode() == '200') {
        $body = $response->getBody();

        if ($json) {
          return (string) $body;
        }
        else {
          parse_str((string) $body, $output);
          return $output;
        }
      }
    }
    catch (\Exception $exception) {
      throw new InvalidRequestException($exception->getMessage(), $exception->getCode(), $exception);
    }
  }

  /**
   * Form common novalnet parameters.
   *
   * @param object $order
   *   The order object.
   * @param array $configuration
   *   Formed parameters.
   * @param object $payment
   *   Current payment method.
   * @param array $request_parameters
   *   The request parameter
   *
   *   return array.
   */
  public function commerceNovalnetCommonParameters($order, array $configuration, $payment, array &$request_parameters) {
    $config                                 = \Drupal::config('commerce_novalnet.application_settings');
    $profile                                = $order->getBillingProfile();
    $address                                = $profile->get('address')->first()->getValue();
    $request_parameters['customer_no']      = \Drupal::currentUser()->id();
    $request_parameters['gender']           = 'u';
    $request_parameters['first_name']       = $address['given_name'];
    $request_parameters['last_name']        = $address['family_name'];
    $request_parameters['email']            = $order->get('mail')->first()->value;
    $request_parameters['street']           = $address['address_line1'] . ' ' . $address['address_line2'];
    $request_parameters['search_in_street'] = 1;
    $request_parameters['city']             = $address['locality'];
    $request_parameters['zip']              = $address['postal_code'];
    $request_parameters['country_code']     = $address['country_code'];
    $request_parameters['currency']  = $payment->getAmount()->getCurrencyCode();
    $request_parameters['test_mode'] = ($configuration['mode'] == 'test') ? 1 : 0;
    $request_parameters['lang']      = strtoupper(\Drupal::languageManager()->getCurrentLanguage()->getId());
    $request_parameters['amount']    = $this->commerceNovalnetFormatAmount($payment->getAmount()->getNumber());
    $request_parameters['order_no']  = $order->id();
    if ($address['organization']) {
      $request_parameters['company'] = $address['organization'];
    }
    if ($config->get('callback_notify_url')) {
      $request_parameters['notify_url'] = $config->get('callback_notify_url');
    }
    return $request_parameters;
  }

  /**
   * Guarantee payment configuration form.
   *
   * @param array $form
   *   The form reference.
   * @param string $payment
   *   The payment type.
   * @param string $configuration
   *   The payment configuration.
   */
  public function commerceNovalnetGuaranteeConfiguration(array &$form, $payment, $configuration) {
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
            </ul></p></div>', t('Basic requirements for payment guarantee'), t('Allowed countries: AT, DE, CH'), t('Allowed currency: EUR'), t('Minimum amount of order >= 9,99 EUR'),t('Minimum age of end customer >= 18 Years'), t('The billing address must be the same as the shipping address'), t('Gift certificates/vouchers are not allowed')),
    '#open' => TRUE,
  ];
    $form['guarantee_configuration'][$payment . '_guarantee_payment'] = [
      '#type' => 'checkbox',
      '#title' => '<b>'.t('Enable payment guarantee').'</b>',
      '#default_value' => $configuration['guarantee_configuration'][$payment . '_guarantee_payment'],
    ];
    $form['guarantee_configuration'][$payment . '_guarantee_payment_minimum_order_amount'] = [
      '#type' => 'number',
      '#title' => t('Minimum order amount (in minimum unit of currency. E.g. enter 100 which is equal to 1.00)'),
      '#description' => t('This setting will override the default setting made in the minimum order amount. Note: Minimum amount should be greater than or equal to 9,99 EUR.'),
      '#default_value' => $configuration['guarantee_configuration'][$payment . '_guarantee_payment_minimum_order_amount'],
    ];
    $form['guarantee_configuration'][$payment . '_guarantee_payment_pending_status'] = [
      '#type' => 'select',
      '#title' => t('Order status for the pending payment'),
      '#options' => $this->commerceOrderStatusOptionsList(),
      '#default_value' => isset($configuration['guarantee_configuration'][$payment . '_guarantee_payment_pending_status']) ? $configuration['guarantee_configuration'][$payment . '_guarantee_payment_pending_status'] : 'pending',
    ];
    $form['guarantee_configuration'][$payment . '_force_normal_payment'] = [
      '#type' => 'checkbox',
      '#title' => '<b>'.t('Force Non-Guarantee payment').'</b>',
      '#description' => t('If the payment guarantee is activated (True), but the above mentioned requirements are not met, the payment should be processed as non-guarantee payment.'),
      '#default_value' => $configuration['guarantee_configuration'][$payment . '_force_normal_payment'],
    ];
  }

  /**
   * Process and show guarantee payment fields.
   *
   * @param object $order
   *   The order object.
   * @param object $payment
   *   The order payment.
   * @param array $guarantee_configuartion
   *   The payment guarantee_configuartion.
   * @param object $shipping_address
   *   Get the shipping address details.
   *
   * @return string
   *   Return the Guarantee message
   */
  public function commerceNovalnetCheckGuaranteeProcess($order, $payment, array $guarantee_configuartion, $shipping_address) {
    if ($guarantee_configuartion['guarantee_configuration'][$payment . '_guarantee_payment']) {
      $billing_address = $order->getBillingProfile()->address->first();
      if ($shipping_address) {
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

        if ($billing_data !== $shipping_data) {
          $message .= t('The shipping address must be the same as the billing address<br>');
        }
      }
      $minimum_amount = !empty($guarantee_configuartion['guarantee_configuration'][$payment . '_guarantee_payment_minimum_order_amount']) ? $guarantee_configuartion['guarantee_configuration'][$payment . '_guarantee_payment_minimum_order_amount'] : 999;
      if (!in_array($billing_address->getCountryCode(), ['AT', 'DE', 'CH'])) {
        $message .= t('Only Germany, Austria or Switzerland are allowed<br>');
      }
      if ($order->getTotalPrice()->getCurrencyCode() != 'EUR') {
        $message .= t('Only EUR currency allowed<br>');
      }
      if ($this->commerceNovalnetFormatAmount($order->getTotalPrice()->getNumber()) < (int) $minimum_amount) {
        $message .= t('Minimum order amount must be ' . $minimum_amount . ' ' . $order->getTotalPrice()->getCurrencyCode() . '<br>');
      }
      if (!$message) {
        \Drupal::service('session')->set($payment . '_guarantee_payment', TRUE);
        \Drupal::service('session')->remove($payment . '_guarantee_payment_error');
      }
      if (!empty($message) && $guarantee_configuartion['guarantee_configuration'][$payment . '_force_normal_payment']) {
        \Drupal::service('session')->remove($payment . '_guarantee_payment');
        \Drupal::service('session')->set($payment . '_guarantee_payment_error', TRUE);
      }
      if (!empty($message) && $guarantee_configuartion['guarantee_configuration'][$payment . '_force_normal_payment']) {
        $message = '';
        // Process as normal payment.
        \Drupal::service('session')->remove($payment . '_guarantee_payment');
        \Drupal::service('session')->remove($payment . '_guarantee_payment_error');
      }
      if ($message) {
        $guarantee_message = t("The payment cannot be processed, because the basic requirements for the payment guarantee haven't been met<br>");
        \Drupal::messenger()->addError(t($guarantee_message.$message));
        throw new InvalidRequestException($guarantee_message);
      }
      return TRUE;
    }
    else {
      // Process as normal payment.
      \Drupal::service('session')->remove($payment . '_guarantee_payment_error');
      \Drupal::service('session')->remove($payment . '_guarantee_payment');
    }
  }

  /**
   * Checks guarantee payment.
   *
   * @param object $guarantee_dob
   *   The payment session data.
   * @param string $payment
   *   The payment type.
   * @param array $guarantee_configuartion
   *   The guarantee configuration.
   *
   * @return string
   *   Return the error message.
   */
  public function commerceNovalnetValidateDob($guarantee_dob, $payment, array $guarantee_configuartion) {
    $message = '';
    if ($guarantee_configuartion['guarantee_configuration'][$payment . '_guarantee_payment']) {
      if (empty($guarantee_dob[$payment . '_dob'])) {
        $message = t('Please enter your date of birth');
      }
      elseif (time() < strtotime('+18 years', strtotime($guarantee_dob[$payment . '_dob']))) {
        $message = t('You need to be at least 18 years old');
      }
    }
    if ($guarantee_configuartion['guarantee_configuration'][$payment . '_force_normal_payment'] && $message != '') {
      \Drupal::service('session')->remove($payment . '_guarantee_payment');
      $message = '';
    }
    if ($message) {
      drupal_set_message($message, 'error');
      return FALSE;
    }
  }

  /**
   * Validates the Guarantee settings with the inputs given.
   *
   * @param array $form_value
   *   The posted values from the form.
   * @param string $payment
   *   The current payment method.
   *
   *   return string.
   */
  public function commerceNovalnetValidateGuaranteeConfiguration(array $form_value, $payment) {
    if ($form_value[$payment . '_guarantee_payment']) {
      $minimum_amount = trim($form_value[$payment . '_guarantee_payment_minimum_order_amount']) ? trim($form_value[$payment . '_guarantee_payment_minimum_order_amount']) : 999;

      if (!$this->commerceNovalnetDigitsCheck($minimum_amount)) {
        return t('The amount is invalid');
      }
      elseif ($minimum_amount < 999) {
        return t('The minimum amount should be at least 9,99 EUR');
      }
    }
    return FALSE;
  }
  
  public function validateSepaDueDate(array $form_value, $payment, $form_state) {
	if(!empty($form_value[$payment . '_due_date']) && ($form_value[$payment . '_due_date'] < 2 || $form_value[$payment . '_due_date'] > 14)) {
      $form_state->setErrorByName('', t('SEPA Due date is not valid'));
      return;
    }
  }
  /**
   * Form system parameters and store in reference variable.
   *
   * @param array $request_parameters
   *   The system parameters.
   */
  public function commerceNovalnetSystemParameters(array &$request_parameters) {
    global $base_url;
    $commerce_info                        = system_get_info('module', 'commerce');
    $request_parameters['remote_ip']      = $this->commerceNovalnetGetIpAddress();
    $request_parameters['system_ip']      = $this->commerceNovalnetGetIpAddress('SERVER_ADDR');
    $request_parameters['system_name']    = 'drupal-' . \Drupal::VERSION . '-commerce';
    $request_parameters['system_version'] = \Drupal::VERSION . '-' . $commerce_info['version'] . '-NN1.0.1';
    $request_parameters['system_url']     = $base_url;
  }

  /**
   * Form reference transaction parameters and store in reference variable.
   *
   * @param object $payment
   *   The payment object.
   * @param array $configuration
   *   The Payment configuration.
   * @param array $request_parameters
   *   The request params
   *
   *   return array.
   */
  public function commerceNovalnetAdditionalParameters($payment, array $configuration, array &$request_parameters) {
    if (($configuration['transaction_type'] == 'authorize' && ($this->commerceNovalnetFormatAmount($payment->getAmount()->getNumber()) >= $configuration['manual_amount_limit']))) {
      $request_parameters['on_hold'] = '1';
    }
  }

  /**
   * Encode the parameter request using ENC implementation.
   *
   * @param array $data
   *   The request data.
   * @param string $accesskey
   *   The encoding key.
   *
   * @return array
   *   Return the decoded data.
   */
  public function commerceNovalnetEncodeParams(array $data, $accesskey) {
    foreach ($this->dataParams as $value) {
      if (isset($data[$value])) {
        $data[$value] = $this->encrypt($data[$value], $data['uniqid'], $accesskey);
      }
    }
    // Generates the hash value only for the form methods.
    $data['hash'] = $this->commerceNovalnetGetHash($data, $accesskey);
    return $data;
  }

  /**
   * Decodes the parameter request using ENC implementation.
   *
   * @param array $data
   *   The request data.
   * @param string $access_key
   *   The payment accesskey.
   *
   * @return array
   *   Return the decoded value
   */
  public function decodePaymentData(array &$data, $access_key) {
    foreach ($this->dataParams as $value) {
      if (isset($data[$value])) {
        $data[$value] = $this->decrypt($data[$value], $data['uniqid'], $access_key);
      }
    }
    return $data;
  }

  /**
   * Generates the unique hash string using ENC implementation.
   *
   * @param string $data
   *   The request params.
   * @param string $accesskey
   *   The payment access key encoding the data.
   *
   * @return string
   *   Return the hash value
   */
  public function commerceNovalnetGetHash($data, $accesskey) {
    $string = '';
    $this->dataParams[] = 'uniqid';
    foreach ($this->dataParams as $param) {
      $string .= $data[$param];
    }
    $string .= strrev($accesskey);
    return hash('sha256', $string);
  }

  /**
   * Encrypts the input data on the openssl encrypt method.
   *
   * @param string $input
   *   The input params.
   * @param int $salt
   *   The unique id.
   * @param string $accesskey
   *   The access key.
   *
   * @return string
   *   Return the encrypted value.
   */
  public function encrypt($input, $salt, $accesskey) {
    // Return Encrypted Data.
    return htmlentities(
    base64_encode(
     openssl_encrypt($input, "aes-256-cbc", $accesskey, TRUE, $salt)
    )
     );
  }

  /**
   * Decrypts the input data based on the openssl decrypt method.
   *
   * @param string $input
   *   The input params.
   * @param int $salt
   *   The unique id.
   * @param string $accesskey
   *   The access key.
   *
   * @return string
   *   Return the encrypted value
   */
  protected function decrypt($input, $salt, $accesskey) {
    // Return decrypted Data.
    return openssl_decrypt(
    base64_decode($input),
    "aes-256-cbc",
    $accesskey,
    TRUE,
    $salt
     );
  }

  /**
   * Get server / remote address.
   *
   * @param string $type
   *   The type of IP address required.
   *
   * @return string
   *   The IP address value.
   */
  public function commerceNovalnetGetIpAddress($type = 'REMOTE_ADDR') {
    if ($type == 'SERVER_ADDR') {
      if (empty($_SERVER['SERVER_ADDR'])) {
        // Handled for IIS server.
        return gethostbyname($_SERVER['SERVER_NAME']);
      }
      else {
        return $_SERVER['SERVER_ADDR'];
      }
    }
    // For remote address.
    else {
      return \Drupal::request()->getClientIp();
    }
  }

  /**
   * Add js file/ library.
   *
   * @param array $form
   *   The payment form.
   * @param string $payment
   *   The payment type.
   * @param string $library
   *   The js library.
   */
  public function commerceNovalnetIncludeJs(array &$form, $payment, $library) {
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
   *   Days to be calculated from currect date.
   *
   * @return string|false
   *   A string containing the only the numeric value,
   *   returns FALSE if input is not numeric.
   */
  public function commerceNovalnetDigitsCheck($input) {
    return (preg_match('/^[0-9]+$/', $input)) ? $input : FALSE;
  }

  /**
   * Payment complete process.
   *
   * @param array $response
   *   The server response.
   * @param string $payment_method
   *   The payment type.
   * @param object $order
   *   The order object.
   * @param array $configuration
   *   The payment configuration value.
   * @param string $order_state
   *   The order state.
   */
  public function commerceNovalnetOrderComplete(array $response, $payment_method, $order, array $configuration, $order_state, $payment_name) { 
    $global_configuration = \Drupal::config('commerce_novalnet.application_settings');
    if (in_array($payment_method, $this->redirectPayments)) {
      if (isset($response['hash2']) && $response['hash2'] != $this->commerceNovalnetGetHash($response, $global_configuration->get('access_key'))) {
        $this->commerceNovalnetCancellation($response, $order, $payment_method, $configuration);
        drupal_set_message(t('While redirecting some data has been changed. The hash check failed.'), 'error');
      }
      $this->decodePaymentData($response, $global_configuration->get('access_key'));
    }
    $paid_amount = $response['amount'] * 100;
    if (in_array($response['tid_status'], ['85', '86', '90', '75']) ||
    in_array($payment_method, ['novalnet_invoice', 'novalnet_prepayment',
      'novalnet_cashpayment',
    ])) {
      $paid_amount = 0;
    }
    $comments .= $this->commerceNovalnetTransactionComments($response, $configuration, $payment_method);
    if ($payment_method == 'novalnet_prepayment' || ($payment_method == 'novalnet_invoice' && $response['tid_status'] != 75)) {
      $comments .= $this->commerceNovalnetInvoiceComments($response);
    }
    if ($payment_method == 'novalnet_cashpayment') {
      $comments .= $this->commerceNovalnetCashPaymentComments($response);
    }
    $aff_id = !empty(\Drupal::service('session')->get('nn_aff_id')) ? \Drupal::service('session')->get('nn_aff_id') : ' ';
    if (is_int($aff_id)) {
      $this->storeAffiliateDetails($order, $aff_id);
    }
    
    $this->storeTransactionDetails($order, $response, $payment_method, $response['amount'] * 100, $paid_amount);
     if (in_array($payment_method, $this->redirectPayments)) {
			  \Drupal::service('session')->set('redirect', $payment_name);
		    $order->setData('transaction_details', ['message' => '<br />'.$comments]);
		    $order->setData('transaction_details_redirect', ['message' =>'<br />'.$comments]);
    }
    else {
		\Drupal::service('session')->remove('redirect');
	   $order->setData('transaction_details', ['message' => $comments]);
	   $order->setData('transaction_details_direct', ['message' =>'<br />'.$comments]);
	}
	
	if(in_array($payment_method, $this->redirectPayments))		    
			$payment_name = $configuration['display_label'].'<br>';

	$order->setData('transaction_details_comments', ['message' => $payment_name.$comments]);
	$order->save();
	$this->commerceNovalnetUnsetSession($payment_method);

  }
	
  /**
   * Store the transaction details.
   *
   * @param object $order
   *   An object containing the order data.
   * @param array $response
   *   An array containing the transaction response.
   * @param string $payment_method
   *   The current payment method.
   * @param int $order_amount
   *   The order amount.
   * @param int $paid_amount
   *   The payment amount.
   */
  public function storeTransactionDetails($order, array $response, $payment_method, $order_amount, $paid_amount = FALSE) {
    db_insert('commerce_novalnet_transaction_detail')
      ->fields(
     [
       'order_id'      => $order->id(),
       'tid'           => $response['tid'],
       'tid_status'    => $response['tid_status'],
       'payment_type'  => $payment_method,
       'total_amount'  => $order_amount,
       'paid_amount'   => ($paid_amount) ? $paid_amount : 0 ,
       'customer_id'   => \Drupal::currentUser()->id(),
     ]
       )
      ->execute();
  }

  /**
   * Store the affiliate details.
   *
   * @param object $order
   *   An object containing the order data.
   * @param object $aff_id
   *   The affiliate id.
   */
  public function storeAffiliateDetails($order, $aff_id) {
    db_insert('commerce_novalnet_aff_user_detail')
      ->fields(
     [
       'aff_id'      => $aff_id,
       'customer_id'  => \Drupal::currentUser()->id(),
       'aff_order_no'    => $order->id(),
     ]
       )
      ->execute();
  }

  /**
   * Form basic transaction comments.
   *
   * @param array $response
   *   The server response.
   * @param array $configuration
   *   The payment configuration.
   * @param string $payment
   *   The payment type.
   *
   * @return string
   *   The transaction comments.
   */
  public function commerceNovalnetTransactionComments(array $response, array $configuration, $payment = '') {

    $transaction_comments = '';

    if (\Drupal::service('session')->get($payment . '_guarantee_payment')) {
      $transaction_comments .= t('This is processed as a guarantee payment') . '<br />';
    }
    $transaction_comments .= strip_tags(t('Novalnet transaction ID: @tid', ['@tid' => $response['tid']]));

    if (!empty($response['test_mode']) || !empty($configuration['mode'])) {
      $transaction_comments .= '<br />' . t('Test order') . '<br />';
    }
    if (\Drupal::service('session')->get($payment . '_guarantee_payment') && $response['tid_status'] == '75') {
		$transaction_comments .= ($response['payment_id'] == '41') ? t('Your order is under verification and once confirmed, we will send you our bank details to where the order amount should be transferred. Please note that this may take upto 24 hours.') . '<br />' :  t('Your order is under verification and we will soon update you with the order status. Please note that this may take upto 24 hours.') . '<br />';
    }

    return $transaction_comments;
  }

  /**
   * Get the Cashpayment comments.
   *
   * @param array $response
   *   An array containing the transaction response.
   *
   * @return string
   *   Return the string value
   */
  public function commerceNovalnetCashPaymentComments(array $response) {
    if (isset($response['cashpayment_slip_id'])) {
      $comment = strip_tags(t('Slip expiry date: @date', ['@date' => date_format(date_create($response['cashpayment_due_date']), "m/d/Y")])) . '<br /><br />';
      if (isset($response['nearest_store_title_1'])) {
        $comment .= t('Store(s) near you') . '<br /><br />';
      }
      $store_values = preg_filter('/^nearest_store_(.*)_(.*)$/', '$2', array_keys($response));
      if ($store_values) {
        $countries = CountryManager::getStandardList();
        for ($i = 1; $i <= max($store_values); $i++) {
          $store_details .= $response['nearest_store_title_' . $i] . '<br />';
          $store_details .= $response['nearest_store_street_' . $i] . '<br />';
          $store_details .= $response['nearest_store_city_' . $i] . '<br />';
          $store_details .= $response['nearest_store_zipcode_' . $i] . '<br />';
          $store_details .= $countries[$response['nearest_store_zipcode_' . $i]] . '<br />';
        }
      }
    }
    return $comment . $store_details;
  }

  /**
   * Get payment description.
   *
   * @param string $payment
   *   The payment type.
   *
   * @return string
   *   The payment description
   */
  public function commerceNovalnetGetDescription($payment) {
    switch ($payment) {
      case 'novalnet_cc':
        $description = t('The amount will be debited from your credit card once the order is submitted');
        break;

      case 'novalnet_sepa':
        $description = t('Your account will be debited upon the order submission');
        break;

      case 'novalnet_invoice':

      case 'novalnet_prepayment':
        $description = t('Once you`ve submitted the order, you will receive an e-mail with account details to make payment');
        break;

      case 'novalnet_cashpayment':
        $description = t('On successful checkout, you will receive a payment slip/SMS to pay your online purchase at one of our retail partners (e.g. supermarket)');
        break;

      default:
        $description = t('After the successful verification, you will be redirected to Novalnet secure order page to proceed with the payment');
        $description .= '<br/>' . t('Please don’t close the browser after successful payment, until you have been redirected back to the Shop');
        break;
    }
    return $description;
  }

  /**
   * Function for build the payment name with logo.
   *
   * @param string $title
   *   The payment logo title.
   * @param string $payment_method
   *   The payment method.
   * @param array $settings
   *   The payment settings
   *
   *   return string.
   */
  public function commerceNovalnetDisplayPaymentLogo($title, $payment_method, array $settings) {
    global $base_url;
    if (!empty($settings['upload_logo'])) {
      $image_path = "/sites/default/files/novalnet_custom_logo/";
      $novalnet_images = glob(DRUPAL_ROOT . $image_path . "*.*");
      for ($i = 0; $i < count($novalnet_images); $i++) {
        $image = $novalnet_images[$i];
        $info = pathinfo($image);
        $image_name = basename($image, '.' . $info['extension']);
        if ($image_name == $payment_method) {
          $ext = strtolower(pathinfo($image, PATHINFO_EXTENSION));
          $payment_url = $base_url . $image_path . $image_name . '.' . $ext;
        }
      }
    }
    else {
      $payment_url = NOVALNET_IMAGES_PATH . $payment_method . '.png';
    }
    $link_title = [
      '#theme' => 'image',
      '#uri' => $payment_url,
      '#alt' => $title,
    ];
    return $link_title;
  }

  /**
   * Builds the URL to the "return" page.
   *
   * @param int $order_id
   *   The order id.
   *
   * @return string
   *   The "return" page url.
   */
  public function build3dCheckReturnUrl($order_id) {
    return Url::fromRoute('commerce_novalnet.3ds.return', [
      'commerce_order' => $order_id,
      'step' => 'payment',
    ], ['absolute' => TRUE])->toString();
  }

  /**
   * Builds the URL to the "cancel" page.
   *
   * @param int $order_id
   *   The order id.
   *
   * @return string
   *   The "cancel" page url.
   */
  public function build3dCheckCancelUrl($order_id) {
    return Url::fromRoute('commerce_novalnet.3ds.cancel', [
      'commerce_order' => $order_id,
      'step' => 'payment',
    ], ['absolute' => TRUE])->toString();
  }

  /**
   * Retrieves messages from server response.
   *
   * @param array $data
   *   The response data.
   *
   * @return string
   *   Response message
   */
  public function commerceNovalnetResponseMessage(array $data) {
    if (!empty($data['status_text'])) {
      return $data['status_text'];
    }
    elseif (!empty($data['status_desc'])) {
      return $data['status_desc'];
    }
    elseif (!empty($data['status_message'])) {
      return $data['status_message'];
    }
    else {
      return t('Payment was not successful. An error occurred');
    }
  }

  /**
   * Form Bank details comments.
   *
   * @param array $response
   *   The server response.
   *
   * @return string
   *   Return the invoice comments
   */
  public function commerceNovalnetInvoiceComments(array $response, $callback = FALSE) {
	$this->currency_formatter = \Drupal::service('commerce_price.currency_formatter');
	$amount = $callback ? sprintf("%.2f", $response['amount']/100) : $response['amount'];
	$amount = $this->currency_formatter->format($amount, $response['currency']);
    $comment .= '<br/><div id=nn_bank_details>' . t('Please transfer the amount to the below mentioned account details of our payment processor Novalnet') . '<br/>';
    
  if($response['tid_status'] == 100) {
		$comment .= t('Due date: @due_date', ['@due_date' => $response['due_date']]) . '<br/>';
	}
	$comment .= t('Account holder: @holder', ['@holder' => $response['invoice_account_holder']]) . '<br/>' .
            t('IBAN: @invoice_iban', ['@invoice_iban' => $response['invoice_iban']]) . '<br/>' .
            t('BIC: @invoice_bic', ['@invoice_bic' => $response['invoice_bic']]) . '<br/>' .
            t('Bank: @bank', ['@bank' => $response['invoice_bankname'] . ' ' . $response['invoice_bankplace']]) . '<br/>' .
            t('Amount: @amount', ['@amount' => $amount]) . '<br/>';
    $comment .= '<br/>' . t('Please use any one of the following references as the payment reference, as only through this way your payment is matched and assigned to the order:').'<br/>';
    $comment .= t('Payment Reference @reference : TID @tid', ['@reference' => 1, '@tid' => $response['tid']]) . '<br/>';
    $comment .= t('Payment Reference @reference : @invoice_ref', ['@reference' => 2, '@invoice_ref' => $response['invoice_ref']]) . '<br/>';
    $comment .= '</div>';
    return $comment;
  }

  /**
   * Handle payment error and redirects to checkout.
   *
   * @param array $response
   *   The payment response received from the server.
   * @param object $order
   *   The order object.
   * @param string $payment_method
   *   The payment method name.
   *
   *   return string.
   */
  public function commerceNovalnetCancellation(array $response, $order, $payment_method, array $configuration) {
    global $base_url;
    $status_text = $this->commerceNovalnetResponseMessage($response);
    if (in_array($payment_method, $this->redirectPayments)) {
      $global_configuration = \Drupal::config('commerce_novalnet.application_settings');
      $this->decodePaymentData($response, $global_configuration->get('access_key'));
    }
    $this->storeTransactionDetails($order, $response, $payment_method, $response['amount'], $paid_amount);
    \Drupal::logger('commerce_novalnet')->error($status_text);
    \Drupal::logger('commerce_novalnet')->warning('Payment failed for order @order_id: @message', ['@order_id' => $order->id(), '@message' => $status_text]);
    drupal_set_message($status_text, 'error');
    if (in_array($payment_method, $this->redirectPayments)) {
      $response = new RedirectResponse($base_url . '/checkout/' . $order->id() . '/order_information');
      $response->send();
    }
    else {
      throw new InvalidRequestException($status_text);
    }
  }

  /**
   * Unsets the Novalnet session storage values.
   *
   * @param string $payment
   *   The current payment method.
   */
  public function commerceNovalnetUnsetSession($payment) {
    // Remove existing session values.
    \Drupal::service('session')->remove($payment . '_guarantee_payment');
    \Drupal::service('session')->remove($payment . '_guarantee_payment_error');
  }

  /**
   * Convert bigger amount to smaller unit.
   *
   * @param float|string $amount
   *   The amount to be converted.
   *
   * @return string
   *   Converted amount
   */
  public function commerceNovalnetFormatAmount($amount) {
    return round($amount, 2) * 100;
  }

  /**
   * Handle the order status.
   *
   * Return string.
   */
  public function commerceOrderStatusOptionsList() {
    return [
      'new'   => t('New'),
      'pending' => t('Pending'),
      'completed' => t('Completed'),
      'partially_refunded' => t('Partially refunded'),
      'refunded' => t('Refunded'),
      'canceled' => t('Canceled'),
    ];
  }

  /**
   * Function for check affiliate order.
   *
   * @param array $data
   *   The request data.
   */
  public function commerceNovalnetCheckAffiliateOrder(array &$data) {
    // Call this function for get Affiliate Id from the affiliate table.
    $aff_id = !empty(\Drupal::service('session')->get('nn_aff_id')) ? \Drupal::service('session')->get('nn_aff_id') : $this->commerceNovalnetGetAffiliateDetails();
    // Call this function for get Affiliate details from the affiliate table.
    $aff_details = $this->commerceNovalnetGetAffiliateDetails($aff_id);
    if ($aff_details) {
      $data['vendor']             = \Drupal::service('session')->get('nn_aff_id');
      $data['auth_code']          = $aff_details['aff_authcode'];
      $data['payment_access_key'] = $aff_details['aff_accesskey'];
    }
  }

 /**
   * Function for get affiliate id from transaction table.
   *
   * @param int $aff_id
   *   The response data.
   *
   * @return array
   *   Return the affiliateparams
   */
  public function commerceNovalnetGetAffiliateDetails($aff_id = FALSE) {
    if (!$aff_id) {
      if (\Drupal::currentUser()->id() > 0) {
        $db_result = db_select('commerce_novalnet_aff_user_detail', 'aff_id')
          ->fields('aff_id', ['aff_id'])
          ->condition('customer_id', \Drupal::currentUser()->id())
          ->orderBy('id', 'DESC')
          ->execute()
          ->fetchAssoc();
        if (empty($db_result)) {
          return FALSE;
        }
        \Drupal::service('session')->set('nn_aff_id', $db_result['aff_id']);

        return $db_result['aff_id'];
      }
      return FALSE;
    }
    $aff_details = db_select('commerce_novalnet_affiliate_detail', 'aff_id')
      ->fields('aff_id', [
        'aff_authcode',
        'aff_accesskey',
      ])
      ->condition('aff_id', $aff_id)
      ->execute()
      ->fetchAssoc();
    if (empty($aff_details)) {
      return FALSE;
    }
    return $aff_details;
  }
}
