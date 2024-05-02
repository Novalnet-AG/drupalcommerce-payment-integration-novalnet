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
 * Provides the Novalnet Invoice payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "novalnet_invoice",
 *   label = "Invoice",
 *   display_label = "Invoice",
 *    forms = {
 *     "add-payment-method" = "Drupal\commerce_novalnet\PluginForm\NovalnetInvoice\NovalnetInvoiceForm",
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
      'transaction_type' => 'capture',
      'order_completion_status' => 'pending',
      'callback_order_status' => 'completed',
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
    $novalnet_library              = new NovalnetLibrary();
    $form                          = parent::buildConfigurationForm($form, $form_state);
     $form['novalnet_invoice_due_date'] = [
      '#type' => 'number',
      '#min' => 0,
      '#title' => t('Payment due date (in days)'),
      '#default_value' => $this->configuration['novalnet_invoice_due_date'],
      '#description' => t('Enter the number of days to transfer the payment amount to Novalnet (must be greater than 7 days). In case if the field is empty, 14 days will be set as due date by default'),
    ];
    $novalnet_library->commerceNovalnetGetCommonFields($form, $this->configuration, $this->code);
   
    $novalnet_library->commerceNovalnetGetManualChecklimit($form, $this->configuration);
    $novalnet_library->commerceNovalnetGetOrderStatus($form, $this->configuration, 'callback_order_status');
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
    if ($error = $novalnet_library->commerceNovalnetValidateGuaranteeConfiguration($values['guarantee_configuration'], $this->code)) {
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
      $this->configuration['order_completion_status'] = $values['order_completion_status'];
      $this->configuration['callback_order_status'] = $values['callback_order_status'];
      $this->configuration['guarantee_configuration'][$this->code . '_guarantee_payment'] = $values['guarantee_configuration'][$this->code . '_guarantee_payment'];
      $this->configuration['guarantee_configuration'][$this->code . '_guarantee_payment_minimum_order_amount'] = $values['guarantee_configuration'][$this->code . '_guarantee_payment_minimum_order_amount'];
      $this->configuration['guarantee_configuration'][$this->code . '_guarantee_payment_pending_status'] = $values['guarantee_configuration'][$this->code . '_guarantee_payment_pending_status'];
      $this->configuration['guarantee_configuration'][$this->code . '_force_normal_payment'] = $values['guarantee_configuration'][$this->code . '_force_normal_payment'];
      $this->configuration['upload_logo'] = $values['upload_logo'];
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
    $request_parameters['payment_type'] = 'INVOICE_START';
    $request_parameters['key']          = 27;
    $request_parameters['invoice_type'] = 'INVOICE';
    $request_parameters['invoice_ref']  = 'BNR-' . $request_parameters['product'] . '-' . $order_id;
    if (\Drupal::service('session')->get($this->code . '_guarantee_payment')) {
      $payment_details                    = \Drupal::service('session')->get('payment_details');
      $request_parameters['key']          = '41';
      $request_parameters['payment_type'] = 'GUARANTEED_INVOICE';
      $request_parameters['birth_date']   = date('Y-m-d', strtotime($payment_details['novalnet_invoice_dob']));
    }
    if ($this->configuration['novalnet_invoice_due_date']) {
      $request_parameters['due_date'] = date('Y-m-d', strtotime('+ ' . $this->configuration['novalnet_invoice_due_date'] . ' day'));
    }
    $response = $novalnet_library->commerceNovalnetSendServerRequest($request_parameters);
    if (isset($response['status']) && $response['status'] == 100) {
      $order_state = ($response['tid_status'] == '100') ? $this->configuration['order_completion_status'] : $this->configuration['callback_order_status'];
      if ($response['payment_id'] == 41 && $response['tid_status'] == '100') {
        $order_state = $this->configuration['callback_order_status'];
      }
      if ($response['payment_id'] == 41 && $response['tid_status'] == '75') {
        $order_state = $this->configuration['guarantee_configuration'][$this->code . '_guarantee_payment_pending_status'];
      }
      $global_configuration = \Drupal::config('commerce_novalnet.application_settings');
		if($response['tid_status'] == 91)
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
    $birth_date = isset($_POST['payment_information']['add_payment_method']['payment_details']) ? $_POST['payment_information']['add_payment_method']['payment_details'] : $_POST['payment_information']['add_payment_method'];
    \Drupal::service('session')->set('payment_details', $birth_date);
    $novalnet_library = new NovalnetLibrary();
    $novalnet_library->commerceNovalnetValidateDob($birth_date, $this->code, $this->configuration);
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
