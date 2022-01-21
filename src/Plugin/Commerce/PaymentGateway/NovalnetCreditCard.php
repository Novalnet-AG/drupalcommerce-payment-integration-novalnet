<?php
/**
 * Novalnet payment method module
 *
 * This module is used for real time processing of
 * Novalnet transaction of customers.
 *
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.1.0
 */
namespace Drupal\commerce_novalnet\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Serialization\Json;
use Drupal\commerce_novalnet\Novalnet;

/**
 * Provides the Novalnet CreditCard payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "novalnet_cc",
 *   label = "Credit/Debit Cards",
 *   display_label = "Credit/Debit Cards",
 *    forms = {
 *     "add-payment-method" = "Drupal\commerce_novalnet\PluginForm\NovalnetCreditCard\NovalnetCreditCardForm",
 *     "receive-payment" = "Drupal\commerce_payment\PluginForm\PaymentReceiveForm",
 *     "refund-payment" = "Drupal\commerce_payment\PluginForm\PaymentRefundForm",
 *     "void-payment" = "Drupal\commerce_payment\PluginForm\PaymentVoidForm",
 *     "capture-payment" = "Drupal\commerce_payment\PluginForm\PaymentCaptureForm",
 *   },
 *   payment_method_types = {"novalnet_cc"},
 * )
 */
class NovalnetCreditCard extends OnsitePaymentGatewayBase {

  private $code = 'novalnet_cc';

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'mode' => 'live',
      'display_label'    => t('Credit/Debit Cards'),
      'transaction_type' => 'capture',
    ] + parent::defaultConfiguration();
  }

  /**
   * Form the payment configuration field.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The form structure.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['inline_iframe'] = [
    '#type'            => 'checkbox',
    '#title'           => t('Enable Inline form'),
    '#description'     => t('Inline form: The following fields will be shown in the checkout in two lines: card holder & credit card number / expiry date / CVC'),
    '#default_value' => $this->configuration['inline_iframe'],
    '#attributes' => ($this->configuration['inline_iframe'] == 1 ) ? ['checked'] : (($this->configuration['inline_iframe'] == '0' ) ? [''] : [ 'checked' => 'checked'] ),
  ];
    $form['novalnet_cc3d_secure'] = [
      '#type'          => 'checkbox',
      '#title'         => t('Enforce 3D secure payment outside EU'),
      '#default_value' => $this->configuration['novalnet_cc3d_secure'],
      '#description'   => t('By enabling this option, all payments from cards issued outside the EU will be authenticated via 3DS 2.0 SCA'),
    ];

    Novalnet::getManualChecklimit($form, $this->configuration);
    Novalnet::getCommonFields($form, $this->configuration);

    // Build Custom css settings option.
    $form['css_settings'] = [
      '#type'        => 'details',
      '#title'       => t('Custom CSS settings'),
      '#description' => t('CSS settings for iframe form'),
      '#open' => true,
    ];
    $form['css_settings']['novalnet_creditcard_css_label'] = [
      '#type'          => 'textarea',
      '#title'         => t('Label'),
      '#default_value' => $this->configuration['css_settings']['novalnet_creditcard_css_label'],
    ];
    $form['css_settings']['novalnet_creditcard_css_input'] = [
      '#type'          => 'textarea',
      '#title'         => t('Input'),
      '#default_value' => $this->configuration['css_settings']['novalnet_creditcard_css_input'],
    ];
    $form['css_settings']['novalnet_creditcard_css_text'] = [
      '#type'          => 'textarea',
      '#title'         => t('CSS Text'),
      '#default_value' => $this->configuration['css_settings']['novalnet_creditcard_css_text'],
    ];
    return $form;
  }

  /**BC_17
   * Validates the element form.
   *
   * @param array $form
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $result = Novalnet::validateParams();
    if ($result) {
      $form_state->setErrorByName('', t('Please fill in all the mandatory fields'));
    }
  }

  /**
   * Submits the element form.
   *
   * @param array $form
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['notification']         = $values['notification'];
      $this->configuration['transaction_type']     = $values['transaction_type'];
      $this->configuration['novalnet_cc3d_secure'] = $values['novalnet_cc3d_secure'];
      $this->configuration['inline_iframe']        = $values['inline_iframe'];
      $this->configuration['manual_amount_limit']  = $values['manual_amount_limit'];
      $this->configuration['css_settings']['novalnet_creditcard_css_label'] = $values['css_settings']['novalnet_creditcard_css_label'];
      $this->configuration['css_settings']['novalnet_creditcard_css_input'] = $values['css_settings']['novalnet_creditcard_css_input'];
      $this->configuration['css_settings']['novalnet_creditcard_css_text']  = $values['css_settings']['novalnet_creditcard_css_text'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = true) {
	
    $payment_details = \Drupal::service('session')->get('payment_details');
    if($payment_details['error']){
		$this->messenger()->addError($payment_details['error']);		
    }
    else{			
		$this->assertPaymentState($payment, ['new']);
		$order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
		$order = Order::load($order_id);
		$request_parameters = [];
		$request_parameters['merchant'] = Novalnet::getMerchantData();
		$request_parameters['customer'] = Novalnet::getCustomerData($order, 'novalnet_cc');
		$request_parameters['transaction'] =Novalnet::getTransactionData($order, 'novalnet_cc', $payment, $this->configuration);
		if ($payment_details['do_redirect'] == '1') {
			if ($this->configuration['novalnet_cc3d_secure']) {
				 $request_parameters['transaction']['enforce_3d'] = 1;
			}
			$request_parameters['transaction']['return_url'] = Novalnet::build3dCheckReturnUrl($order_id, 'commerce_novalnet.3ds.return');
			$request_parameters['transaction']['error_return_url'] = Novalnet::build3dCheckCancelUrl($order_id, 'commerce_novalnet.3ds.cancel');
		}
		$request_parameters['transaction']['payment_type'] = Novalnet::getPaymentType($this->code);
		$request_parameters['transaction']['payment_data']['pan_hash']  = $payment_details['pan_hash'];
		$request_parameters['transaction']['payment_data']['unique_id'] = $payment_details['unique_id'];
		$request_parameters['custom'] = ['lang' => strtoupper(\Drupal::languageManager()->getCurrentLanguage()->getId()),];
		$url = 'payment';
		if ($this->configuration['transaction_type'] == 'authorize'
		   && (Novalnet::formatAmount($payment->getAmount()->getNumber()) >= $this->configuration['manual_amount_limit'])) {
			$url = 'authorize';
		}		
		$json_data = json_encode($request_parameters);
		$result = Novalnet::sendRequest($json_data, Novalnet::getPaygateURL($url));    
		$response = Json::decode($result);   		
		if (isset($response['result']['status']) && $response['result']['status'] == 'SUCCESS') {
		  if ($payment_details['do_redirect'] == '1') {
			\Drupal::service('session')->set('novalnet_txn_secret', $response['transaction']['txn_secret']);
			return $this->formHiddenValues($request_parameters, $response['result']['redirect_url']);
		  }
		  else {
			  
			$order_state = $response['transaction']['status'] == 'ON_HOLD' ? 'authorization' : 'completed';
			$payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
			$payment = $payment_storage->create([
			  'state'           => $order_state,
			  'amount'          => $order->getTotalPrice(),
			  'payment_gateway' => $this->entityId,
			  'order_id'        => $order_id,
			  'remote_id'       => $response['transaction']['tid'],
			  'remote_state'    => $response['transaction']['status'],
			 ]);
			 Novalnet::completeOrder($response, $this->code, $order, $this->configuration['mode']);
			 $payment->save();
		 }
	  }
	  else {
	Novalnet::cancellation($response, $order->id(), $this->code);
		return false;
	  }
	}
  }
     /**
   * {@inheritdoc}
   */
 public function buildPaymentOperations(PaymentInterface $payment) {
    $payment_state = $payment->getState()->getId();
    $operations = [];
    $operations['capture'] = [
        'title' => $this->t('Capture'),
        'page_title' => $this->t('Capture payment'),
        'plugin_form' => 'capture-payment',
        'access' => $payment_state == 'authorization',
      ];
    $operations['void'] = [
      'title' => $this->t('Void'),
      'page_title' => $this->t('Void payment'),
      'plugin_form' => 'void-payment',
      'access' => $payment_state == 'authorization',
    ];
    $operations['refund'] = [
      'title' => $this->t('Refund'),
      'page_title' => $this->t('Refund payment'),
      'plugin_form' => 'refund-payment',
      'access' => in_array($payment_state, ['completed', 'partially_refunded']),
    ];
    return $operations;
  }
  /**
  * {@inheritdoc}
  */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, ['pending','authorization']);
    $response = Novalnet::updateTransaction($payment->getRemoteId(), 'cancel');
    if ($response['transaction']['status_code'] == '103') {
      $payment->state = 'voided';
      $payment->save();
    }
  }
  /**
  * {@inheritdoc}
  */
  public function capturePayment(PaymentInterface $payment) {

    $this->assertPaymentState($payment, ['authorization','pending'], 'capture');
    $response = Novalnet::updateTransaction($payment->getRemoteId(), 'capture');
    if ($response['transaction']['status_code'] == '100') {
      $payment->state = 'completed';
      $payment->save();
    }
    else {
       $this->messenger()->addError($response['result']['status_text']);
    }
  }
  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {

    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    $response = Novalnet::refund($payment->getRemoteId(), $amount->getNumber());
    if ($response['transaction']['status_code'] == '100') {
      $this->assertRefundAmount($payment, $amount);
      $old_refunded_amount = $payment->getRefundedAmount();
      $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->state = 'partially_refunded';
    }
    else {
      $payment->state = 'refunded';
    }
    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
    }
    else {
      $this->messenger()->addError($response['result']['status_text']);
    }
  }
 /**
   *  Form hidden value used rdirect process
   *
   *  @param array $data
   *  @param string $url
   *
   *
  */
  public function formHiddenValues(array $data, $url) {

    $form_data   = '<form name="novalnet_cc" method="post" action="'.$url.'">';
    $form_submit = '<script>document.forms.novalnet_cc.submit();</script>';
    echo  t('Please wait while you are redirected to the payment server.
    If nothing happens within 10 seconds, please click on the button below.')
   .'<br><input data-drupal-selector="edit-actions-next" type="submit" id="edit-actions-next" name="op" value="Proceed" class="button button--primary js-form-submit form-submit">';
    foreach ($data as $k => $v) {
	  foreach ($v as $key => $value) {
         $form_data .= '<input type="hidden" name="' . $key . '" value="' . $value . '" />' . "\n";
      }
    }
    echo $form_data . '</form>' . $form_submit;
    exit();
  }
  /**
  * {@inheritdoc}
  */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
	$payment_details = isset($_POST['payment_information']['add_payment_method']['payment_details']) ? $_POST['payment_information']['add_payment_method']['payment_details'] : $_POST['add_payment_method']['payment_details']; 
	
	if(isset($_POST['add_payment_method']['payment_details']) && $_POST['add_payment_method']['payment_details']['do_redirect'] == 1 ){
		$payment_details['error'] = t('Card holder authentication required, please choose a different payment type.');
		\Drupal::service('session')->set('payment_details', $payment_details);
	}
	else{
		\Drupal::service('session')->set('payment_details', $payment_details);
		$payment_method->setReusable(false);
		$remote_id = $payment_method->getOwnerId();
		$payment_method->setRemoteId($remote_id);
		$payment_method->save();
	}
  }
  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // Delete the local entity.
    $payment_method->delete();
  }
  /**
   * Processes the "return" request for 3-D Secure check.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param array $response
   *   The transaction response.
   */
  public function onSecurityCheckReturn(OrderInterface $order, array $response) {	   
    if (isset($response['status']) && $response['status_code'] == 100) {
      $result = Novalnet::getTransactionDetails($response);
      $result = Json::decode($result); 
      if (isset($result['result']['status']) && $result['result']['status_code'] == 100) {
        $global_configuration = \Drupal::config('commerce_novalnet.application_settings');
        $order_state = $result['transaction']['status'] == 'ON_HOLD' ? 'authorization' : 'completed';
        $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
        $payment = $payment_storage->create([
                'state'           => $order_state,
                'amount'          => $order->getTotalPrice(),
                'payment_gateway' => $this->entityId,
                'order_id'        => $order->id(),
               'remote_id'        => $result['transaction']['tid'],
                'remote_state'    => $result['transaction']['status'],
              ]);
              $order->save();
              $payment->save();
              Novalnet::completeOrder($result, $this->code, $order, $this->configuration['mode']);
        }
        else {
          Novalnet::cancellation($result, $order->id(), $this->code,true);
          return false;
        }
    }
    else
				{
				Novalnet::cancellation($response, $order->id(), $this->code);
					return false;
				}
  }
}
