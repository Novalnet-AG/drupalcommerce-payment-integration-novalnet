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
 * @version    1.2.0
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

/**
 * Provides the Sepa payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "novalnet_sepa",
 *   label = "Direct Debit SEPA",
 *   display_label = "Direct Debit SEPA",
 *    forms = {
 *    "add-payment-method" ="Drupal\commerce_novalnet\PluginForm\NovalnetSepa\NovalnetSepaForm",
 *    "receive-payment" = "Drupal\commerce_payment\PluginForm\PaymentReceiveForm",
 *    "refund-payment" = "Drupal\commerce_payment\PluginForm\PaymentRefundForm",
 *    "void-payment" = "Drupal\commerce_payment\PluginForm\PaymentVoidForm",
 *    "capture-payment" = "Drupal\commerce_payment\PluginForm\PaymentCaptureForm",
 *   },
 *   payment_method_types = {"novalnet_sepa"},
 * )
 */
class NovalnetSepa extends OnsitePaymentGatewayBase {

  private $code = 'novalnet_sepa';

  /**
  * {@inheritdoc}
  */
  public function defaultConfiguration() {
    return [
      'mode' => 'live',
      'display_label' => t('Direct Debit SEPA'),
      'transaction_type' => 'Capture',
      ['guarantee_configuration'][$this->code . '_guarantee_payment'] => true,
      ['guarantee_configuration'][$this->code . '_force_normal_payment'] => false,
      ['guarantee_configuration'][$this->code . '_allow_b2b_customer'] => false,
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
    $form['novalnet_sepa_due_date'] = [
      '#type' => 'number',
      '#min' => 2,
      '#max' => 14,
      '#title' => $this->t('Payment due date (in days)'),
      '#default_value' => $this->configuration['novalnet_sepa_due_date'],
      '#description' => $this->t('Number of days after which the payment is debited (must be between 2 and 14 days)'),
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
    if (!empty($values[$this->code . '_due_date']) && ($values[$this->code . '_due_date'] < 2 || $values[$this->code . '_due_date'] > 14)) {
      $form_state->setErrorByName('', t('SEPA Due date is not valid'));
    }
    if ($error = Novalnet::validateGuaranteeConfiguration($values['guarantee_configuration'], $this->code)) {
      $form_state->setErrorByName('', $error);
      return;
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
      $this->configuration['transaction_type'] = $values['transaction_type'];
      $this->configuration['manual_amount_limit'] = $values['manual_amount_limit'];
      $this->configuration['novalnet_sepa_due_date'] = $values['novalnet_sepa_due_date'];
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
    $this->assertPaymentState($payment, ['new']);
    $payment_details = \Drupal::service('session')->get('payment_details');
    $payment_method = $payment->getPaymentMethod();
    $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
    $order = Order::load($order_id);
    if (\Drupal::service('session')->get($this->code . '_guarantee_payment')) {
        Novalnet::checkGuaranteeAddress($order, $this->configuration, $this->code);
    }
    $allow_b2b = $this->configuration['guarantee_configuration'][$this->code.'_allow_b2b_customer'];
    // v2 structure based form parameter
    $request_parameters = [];
    $request_parameters['merchant'] = Novalnet::getMerchantData();
    $request_parameters['customer'] = Novalnet::getCustomerData($order,'novalnet_sepa', $allow_b2b);
    $request_parameters['transaction'] = Novalnet::getTransactionData($order, 'novalnet_sepa', $payment, $this->configuration);
    $request_parameters['transaction']['payment_type'] = ($this->configuration['guarantee_configuration'][$this->code .'_force_normal_payment'] == 1 || $this->configuration['guarantee_configuration'][$this->code .'_guarantee_payment'] == 0)
    ? Novalnet::getPaymentType($this->code):Novalnet::getPaymentType('novalnet_guaranteed_sepa');
    $request_parameters['custom'] = ['lang' => strtoupper(\Drupal::languageManager()->getCurrentLanguage()->getId()),];
    if (\Drupal::service('session')->get($this->code . '_guarantee_payment')) {
      $payment_details  = \Drupal::service('session')->get('payment_details');
      $request_parameters['transaction']['payment_type'] = Novalnet::getPaymentType('novalnet_guaranteed_sepa');
      $company = '';
      if (\Drupal::service('session')->get('company')) {
        $company = \Drupal::service('session')->get('company');
        \Drupal::service('session')->remove('company');
      }
      if (!$company && !empty($payment_details['novalnet_sepa_dob'])) {
        $request_parameters['customer']['birth_date']   = date('Y-m-d', strtotime($payment_details['novalnet_sepa_dob']));
      }
    }
    if(!empty($this->configuration['novalnet_sepa_due_date']) ){
      $request_parameters['transaction']['due_date'] = date('Y-m-d', strtotime('+ ' .$this->configuration['novalnet_sepa_due_date']. 'day'));

    }
    $request_parameters['transaction']['payment_data']['iban'] = $payment_details['novalnet_sepa_iban'];
    if (!empty($payment_details['novalnet_sepa_bic_container']['novalnet_sepa_bic'])) {
		$request_parameters['transaction']['payment_data']['bic'] = $payment_details['novalnet_sepa_bic_container']['novalnet_sepa_bic'];
	}
    $url = 'payment';
    if ($this->configuration['transaction_type'] == 'authorize'
        && (Novalnet::formatAmount($payment->getAmount()->getNumber()) >= $this->configuration['manual_amount_limit'])) {
        $url = 'authorize';
    }
    $json_data = json_encode($request_parameters);
    $result = Novalnet::sendRequest($json_data, Novalnet::getPaygateURL($url));
    $response = Json::decode($result);
    if (isset($response['result']['status']) && $response['result']['status_code'] == 100) {
      $order_state = ($response['transaction']['status_code'] == '100') ? 'completed'
      : ($response['transaction']['status_code'] == '99' ? 'authorization' : 'pending');
      $global_configuration = \Drupal::config('commerce_novalnet.application_settings');
      $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      $payment = $payment_storage->create([
        'state' => $order_state,
        'amount' => $order->getTotalPrice(),
        'payment_gateway' => $this->entityId,
        'order_id' => $order_id,
        'remote_id' => $response['transaction']['tid'],
        'remote_state' => $response['transaction']['status'],
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
	  $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
	  $order = Order::load($order_id);
	  $message = '<br/>' . t('The transaction has been canceled on @date @time', ['@date' => date('Y-m-d'), '@time' => date('H:i:s')]);
	  $transaction_details = $order->getData('transaction_details')['message'];
      $order->setData('transaction_details', ['message' => $transaction_details .'<br />'.'<br />'. $message]);
      $order->save();
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
	  $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
	  $order = Order::load($order_id);
	  $message = t('The transaction has been confirmed on @date, @time', ['@date' => date('Y-m-d'), '@time' => date('H:i:s')]);
	  $transaction_details = $order->getData('transaction_details')['message'];
      $order->setData('transaction_details', ['message' => $transaction_details .'<br />'.'<br />'. $message]);
      $order->save();
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
		if($response['transaction']['status_code'] == '100') { // Success
			$this->assertRefundAmount($payment, $amount);
			$old_refunded_amount = $payment->getRefundedAmount();
			$new_refunded_amount = $old_refunded_amount->add($amount);
			$payment->state = 'refunded';
			$order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
			$order = Order::load($order_id);
			$transaction_details = $order->getData('transaction_details')['message'];
			$currency_formatter = \Drupal::service('commerce_price.currency_formatter');
			if ($new_refunded_amount->lessThan($payment->getAmount())) { // If partial refund
				$payment->state = 'partially_refunded';
			}
			if ($response['transaction']['status'] == 'DEACTIVATED') { // If transaction deactivated
				$payment->state = 'voided';
			}
			$message = t('Refund has been initiated for the TID: @otid with the amount @amount', ['@otid' => $response['transaction']['tid'], '@amount' => $currency_formatter->format($response['transaction']['refund']['amount']/100, $response['transaction']['refund']['currency'])]);
			if(!empty($response['transaction']['refund']['tid'])) {
				$message = t('Refund has been initiated for the TID: @otid with the amount @amount. New TID:@tid for the refunded amount', ['@otid' => $response['transaction']['tid'], '@amount' => $currency_formatter->format($response['transaction']['refund']['amount']/100, $response['transaction']['refund']['currency']), '@tid' => $response['transaction']['refund']['tid']]);
			}
			$this->messenger()->addMessage($message, 'status');
			$order->setData('transaction_details', ['message' => $transaction_details .'<br />'.'<br />'. $message]);
			$order->save();
			$payment->setRefundedAmount($new_refunded_amount);
			$payment->save();
		}
		else { // Failure
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
    ?$_POST['payment_information']['add_payment_method']['payment_details']:$_POST['add_payment_method']['payment_details'];
    $message = '';
    \Drupal::service('session')->set('payment_details', $birth_date);
      if (!empty($birth_date['novalnet_sepa_dob'])) {
	   if (time() < strtotime('+18 years', strtotime($birth_date['novalnet_sepa_dob']))) {
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
