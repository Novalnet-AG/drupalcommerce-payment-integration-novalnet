<?php
/**
 * @file
 * Novalnet payment method module
 * This module is used for real time processing of
 * Novalnet transaction of customers.
 *
 * @category   PHP
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.0.1
 */
namespace Drupal\commerce_novalnet\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_novalnet\NovalnetLibrary;

/**
 * Provides the Prepayment payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "novalnet_prepayment",
 *   label = "Prepayment",
 *   display_label = "Prepayment",
 *    forms = {
 *     "add-payment-method" = "Drupal\commerce_novalnet\PluginForm\NovalnetPrepayment\NovalnetPrepaymentForm",
 *   },
 *   payment_method_types = {"novalnet_prepayment"},
 * )
 */
class NovalnetPrepayment extends OnsitePaymentGatewayBase {

  protected $code = 'novalnet_prepayment';

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'mode' => 'live',
      'display_label' => t('Prepayment'),
      'order_completion_status' => 'pending',
      'callback_order_status' => 'completed',
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
    global $base_url;
    $form                             = parent::buildConfigurationForm($form, $form_state);
    $novalnet_library                 = new NovalnetLibrary();
    $novalnet_library->commerceNovalnetGetCommonFields($form, $this->configuration, $this->code);
    $novalnet_library->commerceNovalnetGetOrderStatus($form, $this->configuration, 'callback_order_status');
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
    $novalnet_library = new NovalnetLibrary();
    $novalnet_library->commerceNovalnetValidateParams($form_state);
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
      $this->configuration['upload_logo'] = $values['upload_logo'];
      $this->configuration['order_completion_status'] = $values['order_completion_status'];
      $this->configuration['callback_order_status'] = $values['callback_order_status'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $novalnet_library = new NovalnetLibrary();
    $payment_method = $payment->getPaymentMethod();
    $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
    $order = Order::load($order_id);
    $request_parameters = [];
    $novalnet_library->commerceNovalnetMerchantParameters($request_parameters);
    $novalnet_library->commerceNovalnetCommonParameters($order, $this->configuration, $payment, $request_parameters);
    $novalnet_library->commerceNovalnetSystemParameters($request_parameters);
    $novalnet_library->commerceNovalnetAdditionalParameters($payment, $this->configuration, $request_parameters);
    $request_parameters['invoice_type'] = 'PREPAYMENT';
    $request_parameters['payment_type'] = 'INVOICE_START';
    $request_parameters['key']          = 27;
    $request_parameters['invoice_ref']  = 'BNR-' . $request_parameters['product'] . '-' . $order_id;
    $response                           = $novalnet_library->commerceNovalnetSendServerRequest($request_parameters);
    if (isset($response['status']) && $response['status'] == 100) {
      $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      $payment = $payment_storage->create([
        'state' => $this->configuration['order_completion_status'],
        'amount' => $order->getTotalPrice(),
        'payment_gateway' => $this->entityId,
        'order_id' => $order_id,
        'remote_id' => $response['tid'],
        'remote_state' => $response['tid_status'],
      ]);
      $novalnet_library->commerceNovalnetOrderComplete($response, $this->code, $order, $this->configuration, $this->configuration['order_completion_status'], $payment_method->label());
      $order->save();
      $payment->save();
    }
    else {
      $novalnet_library->commerceNovalnetCancellation($response, $order, $this->code, $this->configuration);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $payment_method->setReusable(TRUE);
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
