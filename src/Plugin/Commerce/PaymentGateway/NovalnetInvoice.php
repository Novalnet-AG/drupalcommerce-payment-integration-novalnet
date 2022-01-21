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
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Serialization\Json;
use Drupal\commerce_novalnet\Novalnet;
use Drupal\commerce_payment\Exception\InvalidRequestException;
/**
 * Provides the Novalnet Invoice payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "novalnet_invoice",
 *   label = "Invoice",
 *   display_label = "Invoice",
 *    forms = {
 *     "add-payment-method" = "Drupal\commerce_novalnet\PluginForm\NovalnetInvoice\NovalnetInvoiceForm",
 *     "receive-payment" = "Drupal\commerce_payment\PluginForm\PaymentReceiveForm",
 *     "refund-payment" = "Drupal\commerce_payment\PluginForm\PaymentRefundForm",
 *     "void-payment" = "Drupal\commerce_payment\PluginForm\PaymentVoidForm",
 *     "capture-payment" = "Drupal\commerce_payment\PluginForm\PaymentCaptureForm",
 *   },
 *   payment_method_types = {"novalnet_invoice"},
 * )
 */
class NovalnetInvoice extends OnsitePaymentGatewayBase {

  private $code = 'novalnet_invoice';

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'mode' => 'live',
      'display_label' => t('Invoice'),
      'transaction_type' => 'Capture',
      ['guarantee_configuration'][$this->code . '_guarantee_payment']    => true,
      ['guarantee_configuration'][$this->code . '_force_normal_payment'] => false,
      ['guarantee_configuration'][$this->code . '_allow_b2b_customer']   => false,
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
      $form['novalnet_invoice_due_date'] = [
      '#type'  => 'number',
      '#min'   => 7,
      '#title' => t('Payment due date (in days)'),
      '#default_value' => $this->configuration['novalnet_invoice_due_date'],
      '#description'   => t('Number of days given to the buyer to transfer the amount to Novalnet (must be greater than 7 days). If this field is left blank, 14 days will be set as due date by default.'),
    ];   
    Novalnet::getCommonFields($form, $this->configuration);   
    Novalnet::getManualChecklimit($form, $this->configuration);
    Novalnet::getGuaranteeConfiguration($form, $this->code, $this->configuration);
    return $form;
  }

  /**
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
    $values = $form_state->getValue($form['#parents']);
    if ($error = Novalnet::validateGuaranteeConfiguration($values['guarantee_configuration'], $this->code)) {
      $values['guarantee_configuration'][$this->code . '_guarantee_payment_minimum_order_amount'] = '';
      $form_state->setErrorByName('', $error);
      return '';
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
      $this->configuration['notification'] = $values['notification'];
      $this->configuration['novalnet_invoice_due_date'] = $values['novalnet_invoice_due_date'];
      $this->configuration['transaction_type'] = $values['transaction_type'];      
      $this->configuration['manual_amount_limit'] = $values['manual_amount_limit'];
      $this->configuration['guarantee_configuration'][$this->code . '_guarantee_payment'] = $values['guarantee_configuration'][$this->code . '_guarantee_payment'];
      $this->configuration['guarantee_configuration'][$this->code . '_guarantee_payment_minimum_order_amount'] = $values['guarantee_configuration'][$this->code . '_guarantee_payment_minimum_order_amount'];
      $this->configuration['guarantee_configuration'][$this->code . '_force_normal_payment'] = $values['guarantee_configuration'][$this->code . '_force_normal_payment'];
      $this->configuration['guarantee_configuration'][$this->code . '_allow_b2b_customer'] = $values['guarantee_configuration'][$this->code . '_allow_b2b_customer'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = true) {	
    $global_configuration = \Drupal::config('commerce_novalnet.application_settings');
    $payment_method = $payment->getPaymentMethod();   
    $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
    $order = Order::load($order_id);     
    if (\Drupal::service('session')->get($this->code . '_guarantee_payment')) {
        Novalnet::checkGuaranteeAddress($order, $this->configuration, $this->code);
    }
    $allow_b2b = $this->configuration['guarantee_configuration'][$this->code . '_allow_b2b_customer'];
    
    // v2 structure based form parameter
    $request_parameters = [];
    $request_parameters['merchant'] = Novalnet::getMerchantData();
    $request_parameters['customer'] = Novalnet::getCustomerData($order, 'novalnet_invoice', $allow_b2b);
    $request_parameters['transaction'] = Novalnet::getTransactionData($order, 'novalnet_invoice',$payment, $this->configuration);
    $request_parameters['transaction']['payment_type'] = ($this->configuration['guarantee_configuration'][$this->code .'_force_normal_payment'] == 1 || $this->configuration['guarantee_configuration'][$this->code .'_guarantee_payment'] == 0)
    ? Novalnet::getPaymentType($this->code):Novalnet::getPaymentType('novalnet_guaranteed_invoice');
    $request_parameters['custom'] = ['lang' => strtoupper(\Drupal::languageManager()->getCurrentLanguage()->getId())];
    if (\Drupal::service('session')->get($this->code . '_guarantee_payment')) {
      $payment_details  = \Drupal::service('session')->get('payment_details');
      $request_parameters['transaction']['payment_type'] = Novalnet::getPaymentType('novalnet_guaranteed_invoice');
      $company = '';
      if (\Drupal::service('session')->get('company')) {
        $company = \Drupal::service('session')->get('company');
        \Drupal::service('session')->remove('company');
      }
      if (!$company && !empty($payment_details['novalnet_invoice_dob']))  {
        $request_parameters['customer']['birth_date']   = date('Y-m-d', strtotime($payment_details['novalnet_invoice_dob']));
      }
    }
    $request_parameters['invoice_ref']  = 'BNR-'.$global_configuration->get('project_id').'-' . $order_id;
    if ($this->configuration['novalnet_invoice_due_date']) {
      $request_parameters['transaction']['due_date'] = date('Y-m-d', strtotime('+ ' .(!empty($this->configuration['novalnet_invoice_due_date'])
      ? $this->configuration['novalnet_invoice_due_date'] :14).' day'));
    }
    $url = 'payment';
    if ($this->configuration['transaction_type'] == 'authorize'
        &&(Novalnet::formatAmount($payment->getAmount()->getNumber()) >= $this->configuration['manual_amount_limit'])) {
        $url = 'authorize';
    }   
    $json_data = json_encode($request_parameters);
    $result = Novalnet::sendRequest($json_data, Novalnet::getPaygateURL($url));
    $response = Json::decode($result);   
    if (isset($response['result']['status']) && $response['result']['status_code'] == 100) {
      $order_state = ($response['transaction']['status_code'] == '100') ?(($response['transaction']['payment_type'] == 'GUARANTEED_INVOICE')?'completed':'pending'):
       ($response['transaction']['status_code'] == '91'?'authorization':'pending');

      $global_configuration = \Drupal::config('commerce_novalnet.application_settings');
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
      $order->save();
      $payment->save();
    }
    else {
      Novalnet::cancellation($response, $order->id(), $this->code);
      return false;
    }
  }
  /**
   * {@inheritdoc}
   */
 public function buildPaymentOperations(PaymentInterface $payment) {
    $payment_state = $payment->getState()->getId();
    $operations = [];
     $operations['capture'] = [
        'title'      => $this->t('Capture'),
        'page_title' => $this->t('Capture payment'),
        'plugin_form'=> 'capture-payment',
        'access'     => $payment_state == 'authorization',
      ];
    $operations['void'] = [
      'title'       => $this->t('Void'),
      'page_title'  => $this->t('Void payment'),
      'plugin_form' => 'void-payment',
      'access'      => $payment_state == 'authorization',
    ];
    $operations['refund'] = [
      'title'        => $this->t('Refund'),
      'page_title'   => $this->t('Refund payment'),
      'plugin_form'  => 'refund-payment',
      'access'       => in_array($payment_state, ['completed', 'partially_refunded']),
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
    $this->assertPaymentState($payment, ['authorization']);
    $response = Novalnet::updateTransaction($payment->getRemoteId(), 'capture');
    if ($response['transaction']['status_code'] == '100') {
      $payment->state = ($response['transaction']['payment_type'] == 'GUARANTEED_INVOICE') ? 'completed':'pending';
      $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
      $order_details = \Drupal::database()->select('commerce_order', 'order_id')
					  ->fields('order_id', [
						'mail',
						'payment_gateway',
						'total_paid__number',
						'data',
					  ])
					  ->condition('order_id', $order_id)
					  ->execute()
					  ->fetchAssoc();($order_id);
      $order = Order::load($order_id);
      $callback_information = '<br/><br/>'.str_replace(t('Please transfer the amount to the below mentioned account details of our payment processor Novalnet'),
                                 t('Please transfer the amount to the below mentioned account details of our payment processor Novalnet').'<br/>'.
                                 t('Due date: @due_date', ['@due_date' => $response['transaction']['due_date']]).
                                 '<br/>', unserialize($order_details['data'])['transaction_details']['message']);
      $transaction_details = $order->getData('transaction_details')['message'];
      $order->setData('transaction_details', ['message' => $transaction_details .'<br />'.'<br />'. $callback_information]);
      $order->save();
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
    $this->assertRefundAmount($payment, $amount);
    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($response['transaction']['status_code'] == '100') {
      if ($new_refunded_amount->lessThan($payment->getAmount())) {
        $payment->state = 'partially_refunded';
      }
     else {
       $payment->state = 'refunded';
       $payment->setRefundedAmount($new_refunded_amount);
       $payment->save();
     }
    }
    elseif ($response['transaction']['status'] == 'DEACTIVATED') {
      $payment->state = 'voided';
      $this->messenger()->addError($response['result']['status_text']);
      $payment->setRefundedAmount($new_refunded_amount);
      $payment->save();
    }
    else {
      $this->messenger()->addError($response['result']['status_text']);
    }

  }

  /**
  * {@inheritdoc}
  */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
	  
	  
    $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
    $order = Order::load($order_id);
    $birth_date = isset($_POST['payment_information']['add_payment_method']['payment_details'])
    ? $_POST['payment_information']['add_payment_method']['payment_details']:(isset($_POST['payment_information']['add_payment_method']) ? $_POST['payment_information']['add_payment_method'] : $_POST['add_payment_method']['payment_details']);
    $message = '';    
    \Drupal::service('session')->set('payment_details', $birth_date);   
      if (!empty($birth_date['novalnet_invoice_dob'])) {			 
	   if (time() < strtotime('+18 years', strtotime($birth_date['novalnet_invoice_dob']))) {		
		$message .= t('You need to be at least 18 years old').'<br>';
	  }  
	}
    Novalnet::checkGuaranteeProcess($order, $this->code, $this->configuration,$message);
    $payment_method->setReusable(true);
    $remote_id = $payment_method->getOwnerId();
    $payment_method->setRemoteId($remote_id);
    $payment_method->setExpiresTime($expires);
    $payment_method->save();
  }
  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // Delete the local entity.
    $payment_method->delete();
  }
}
