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

/**
 * Provides the Prepayment payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "novalnet_multibanco",
 *   label = "Multibanco",
 *   display_label = "Multibanco",
 *    forms = {
 *     "add-payment-method" = "Drupal\commerce_novalnet\PluginForm\NovalnetMultibanco\NovalnetMultibancoForm",
 *     "receive-payment" = "Drupal\commerce_payment\PluginForm\PaymentReceiveForm",
 *     "refund-payment" = "Drupal\commerce_payment\PluginForm\PaymentRefundForm",
 *   },
 *   payment_method_types = {"novalnet_multibanco"},
 * )
 */
class NovalnetMultibanco extends OnsitePaymentGatewayBase {

  protected $code = 'novalnet_multibanco';

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'mode' => 'live',
      'display_label' => t('Multibanco'),
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
    Novalnet::getCommonFields($form, $this->configuration);
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
    }
  }
  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = true) {
    $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
    $order = Order::load($order_id);
    // v2 structure based form parameter
    $request_parameters = [];
    $request_parameters['merchant'] = Novalnet::getMerchantData();
    $request_parameters['customer'] = Novalnet::getCustomerData($order, 'novalnet_multibanco');
    $request_parameters['transaction'] = Novalnet::getTransactionData($order, 'novalnet_multibanco', $payment, $this->configuration);
    $request_parameters['transaction']['payment_type'] = Novalnet::getPaymentType($this->code);
    $request_parameters['custom'] = ['lang' => strtoupper(\Drupal::languageManager()->getCurrentLanguage()->getId()),];
    $json_data = json_encode($request_parameters);
    $result = Novalnet::sendRequest($json_data, Novalnet::getPaygateURL('payment'));
    $response = Json::decode($result);
    if (isset($response['result']['status']) && $response['result']['status_code'] == 100) {
      $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      $payment = $payment_storage->create([
        'state'           => 'pending',
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
    $operations['refund'] = [
      'title'      => $this->t('Refund'),
      'page_title' => $this->t('Refund payment'),
      'plugin_form'=> 'refund-payment',
      'access'     => in_array($payment_state, ['completed', 'partially_refunded']),
    ];
    return $operations;
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
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
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
