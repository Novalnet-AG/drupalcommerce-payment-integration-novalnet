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

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\commerce_novalnet\NovalnetLibrary;

/**
 * Provides the Novalnet Przelewy payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "novalnet_przelewy24",
 *   label = "Przelewy24",
 *   display_label = "Przelewy24",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_novalnet\PluginForm\NovalnetPrzelewy24\NovalnetPrzelewy24Form",
 *   }
 * )
 */
class NovalnetPrzelewy24 extends OffsitePaymentGatewayBase {
  private $code = 'novalnet_przelewy24';

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'mode' => 'live',
      'display_label' => t('Przelewy24'),
      'order_pending_status' => 'pending',
      'order_completion_status' => 'completed',
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
    $novalnet_library->commerceNovalnetGetOrderStatus($form, $this->configuration, 'order_pending_status');
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
      $this->configuration['order_pending_status'] = $values['order_pending_status'];
      $this->configuration['order_completion_status'] = $values['order_completion_status'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $novalnet_library = new NovalnetLibrary();
    if(!empty($request->request->all())) {
      $response = $request->request->all();
    } else {
      $response = $request->query->all();      
    }
    $order_state = ($response['tid_status'] == 86) ? $this->configuration['order_pending_status'] : $this->configuration['order_completion_status'];
	$novalnet_library->commerceNovalnetOrderComplete($response, $this->code, $order, $this->configuration, $this->configuration['order_completion_status'], false);
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => $order_state,
      'amount' => $order->getTotalPrice(),
      'payment_gateway' => $this->entityId,
      'order_id' => $order->id(),
      'remote_id' => $response['tid'],
      'remote_state' => $response['tid_status'],
    ]);
    $order->save();
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    $novalnet_library = new NovalnetLibrary();
    if(!empty($request->request->all())) {
      $response = $request->request->all();
    } else {
      $response = $request->query->all();      
    }
    $novalnet_library->commerceNovalnetCancellation($response, $order, $this->code, $this->configuration);
  }

}
