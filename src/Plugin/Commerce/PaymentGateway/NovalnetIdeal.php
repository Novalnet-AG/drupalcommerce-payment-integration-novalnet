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

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Serialization\Json;
use Drupal\commerce_novalnet\Novalnet;

/**
 * Provides the Novalnet Ideal payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "novalnet_ideal",
 *   label = "iDEAL",
 *   display_label = "iDEAL",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_novalnet\PluginForm\NovalnetIdeal\NovalnetIdealForm",
 *   }
 * )
 */
class NovalnetIdeal extends OffsitePaymentGatewayBase {

  private $code = 'novalnet_ideal';
  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'mode' => 'live',
      'display_label' => t('iDEAL'),
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
  public function onReturn(OrderInterface $order, Request $request) {
    if (!empty($request->request->all())) {
       $result = $request->request->all();
       $response =Novalnet::getTransactionDetails($result);
    }
    else {
      $result = $request->query->all();
      $response = Novalnet::getTransactionDetails($result);
    }
    $response = Json::decode($response);
    Novalnet::completeOrder($response, $this->code, $order, $this->configuration['mode']);
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state'           => 'completed',
      'amount'          => $order->getTotalPrice(),
      'payment_gateway' => $this->entityId,
      'order_id'        => $order->id(),
      'remote_id'       => $response['transaction']['tid'],
      'remote_state'    => $response['transaction']['status'],
    ]);
    $order->save();
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    if (!empty($request->request->all())) {
      $result = $request->request->all();
    }
    else {
      $result = $request->query->all();
    }
   Novalnet::cancellation($result, $order->id(), $this->code);
  }
}
