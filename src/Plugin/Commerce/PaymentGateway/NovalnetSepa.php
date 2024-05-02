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
 * Provides the Sepa payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "novalnet_sepa",
 *   label = "Direct Debit SEPA",
 *   display_label = "Direct Debit SEPA",
 *    forms = {
 *    "add-payment-method" ="Drupal\commerce_novalnet\PluginForm\NovalnetSepa\NovalnetSepaForm",
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
      'transaction_type' => 'capture',
      'order_completion_status' => 'completed',
      ['guarantee_configuration'][$this->code . '_guarantee_payment'] => FALSE,
      ['guarantee_configuration'][$this->code . '_force_normal_payment'] => TRUE,
      ['guarantee_configuration'][$this->code . '_guarantee_payment_pending_status'] => 'pending',
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
    $form = parent::buildConfigurationForm($form, $form_state);
    $novalnet_library = new NovalnetLibrary();
    $form['novalnet_sepa_due_date'] = [
      '#type' => 'number',
      '#min' => 0,
      '#title' => $this->t('SEPA payment duration (in days)'),
      '#default_value' => $this->configuration['novalnet_sepa_due_date'],
      '#description' => $this->t('Enter the number of days after which the payment should be processed (must be between 2 and 14 days)'),
      '#size' => 10,
    ];
    $novalnet_library->commerceNovalnetGetCommonFields($form, $this->configuration, $this->code);
    $novalnet_library->commerceNovalnetGetManualChecklimit($form, $this->configuration);
    $novalnet_library->commerceNovalnetGetOrderStatus($form, $this->configuration);
    $novalnet_library->commerceNovalnetGuaranteeConfiguration($form, $this->code, $this->configuration);
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
    $values = $form_state->getValue($form['#parents']);
    $novalnet_library->validateSepaDueDate($values, $this->code, $form_state);
    if ($error = $novalnet_library->commerceNovalnetValidateGuaranteeConfiguration($values['guarantee_configuration'], $this->code)) {
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
      $this->configuration['upload_logo'] = $values['upload_logo'];
      $this->configuration['order_completion_status'] = $values['order_completion_status'];
      $this->configuration['transaction_type'] = $values['transaction_type'];
      $this->configuration['manual_amount_limit'] = $values['manual_amount_limit'];
      $this->configuration['novalnet_sepa_due_date'] = $values['novalnet_sepa_due_date'];
      $this->configuration['guarantee_configuration'][$this->code . '_guarantee_payment'] = $values['guarantee_configuration'][$this->code . '_guarantee_payment'];
      $this->configuration['guarantee_configuration'][$this->code . '_guarantee_payment_minimum_order_amount'] = $values['guarantee_configuration'][$this->code . '_guarantee_payment_minimum_order_amount'];
      $this->configuration['guarantee_configuration'][$this->code . '_guarantee_payment_pending_status'] = $values['guarantee_configuration'][$this->code . '_guarantee_payment_pending_status'];
      $this->configuration['guarantee_configuration'][$this->code . '_force_normal_payment'] = $values['guarantee_configuration'][$this->code . '_force_normal_payment'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $novalnet_library = new NovalnetLibrary();
    $this->assertPaymentState($payment, ['new']);
    $payment_details = \Drupal::service('session')->get('payment_details');
    $payment_method = $payment->getPaymentMethod();
    $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
    $order = Order::load($order_id);
    if (\Drupal::moduleHandler()->moduleExists('commerce_shipping') && $order->hasField('shipments') && !($order->get('shipments')->isEmpty())) {
      /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
      $shipments = $payment->getOrder()->get('shipments')->referencedEntities();
      $first_shipment = reset($shipments);
      /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $shipping_address */
      $shipping_address = $first_shipment->getShippingProfile()->address->first();
    }
    $novalnet_library->commerceNovalnetCheckGuaranteeProcess($order, $this->code, $this->configuration, $shipping_address);
    $request_parameters = [];
    $novalnet_library->commerceNovalnetMerchantParameters($request_parameters);
    $novalnet_library->commerceNovalnetCommonParameters($order, $this->configuration, $payment, $request_parameters);
    $novalnet_library->commerceNovalnetSystemParameters($request_parameters);
    $novalnet_library->commerceNovalnetAdditionalParameters($payment, $this->configuration, $request_parameters);
    $request_parameters['iban']                = $payment_details['novalnet_sepa_iban'];
    $request_parameters['bank_account_holder'] = $payment_details['novalnet_sepa_account_holder'];
    $request_parameters['key']                 = 37;
    $request_parameters['payment_type']        = 'DIRECT_DEBIT_SEPA';
    if($this->configuration['novalnet_sepa_due_date']) {
		$request_parameters['sepa_due_date'] = date('Y-m-d', strtotime('+' . $this->configuration['novalnet_sepa_due_date'] . ' days'));
	}
    if (\Drupal::service('session')->get($this->code . '_guarantee_payment')) {
      $request_parameters['key']          = '40';
      $request_parameters['payment_type'] = 'GUARANTEED_DIRECT_DEBIT_SEPA';
      $request_parameters['birth_date']   = date('Y-m-d', strtotime($payment_details['novalnet_sepa_dob']));
    }
    $response = $novalnet_library->commerceNovalnetSendServerRequest($request_parameters);
    if (isset($response['status']) && $response['status'] == 100) {

        $order_state = ($response['tid_status'] == '75') ? $this->configuration['guarantee_configuration'][$this->code . '_guarantee_payment_pending_status'] : $this->configuration['order_completion_status'];
		$global_configuration = \Drupal::config('commerce_novalnet.application_settings');
		if($response['tid_status'] == 99)
		$order_state = $global_configuration->get('commerce_novalnet_onhold_completion_status');
      $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      $payment = $payment_storage->create([
        'state' => $order_state,
        'amount' => $order->getTotalPrice(),
        'payment_gateway' => $this->entityId,
        'order_id' => $order_id,
        'remote_id' => $response['tid'],
        'remote_state' => $response['tid_status'],
      ]);
      $novalnet_library->commerceNovalnetOrderComplete($response, $this->code, $order, $this->configuration, $order_state, $payment_method->label());
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
    \Drupal::service('session')->set('payment_details', $_POST['payment_information']['add_payment_method']['payment_details']);
    $novalnet_library = new NovalnetLibrary();
    $novalnet_library->commerceNovalnetValidateDob($_POST['payment_information']['add_payment_method']['payment_details'], $this->code, $this->configuration);
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
