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
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_novalnet\NovalnetLibrary;

/**
 * Provides the Novalnet CreditCard payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "novalnet_cc",
 *   label = "CreditCard",
 *   display_label = "CreditCard",
 *    forms = {
 *     "add-payment-method" = "Drupal\commerce_novalnet\PluginForm\NovalnetCreditCard\NovalnetCreditCardForm",
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
      'display_label' => t('CreditCard'),
      'transaction_type' => 'capture',
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
    $form                     = parent::buildConfigurationForm($form, $form_state);
    $novalnet_library         = new NovalnetLibrary();
    $novalnet_library->commerceNovalnetGetCommonFields($form, $this->configuration, $this->code);
    $novalnet_library->commerceNovalnetGetManualChecklimit($form, $this->configuration);
    $novalnet_library->commerceNovalnetGetOrderStatus($form, $this->configuration);

    // Build Custom css settings option.
    $form['css_settings'] = [
      '#type' => 'details',
      '#title' => t('Custom CSS settings'),
      '#description' => t('CSS settings for Credit Card iframe'),
      '#open' => TRUE,
    ];
    $form['css_settings']['novalnet_creditcard_css_label'] = [
      '#type' => 'textarea',
      '#title' => t('Label'),
      '#default_value' => $this->configuration['css_settings']['novalnet_creditcard_css_label'],
    ];
    $form['css_settings']['novalnet_creditcard_css_input'] = [
      '#type' => 'textarea',
      '#title' => t('Input'),
      '#default_value' => $this->configuration['css_settings']['novalnet_creditcard_css_input'],
    ];
    $form['css_settings']['novalnet_creditcard_css_text'] = [
      '#type' => 'textarea',
      '#title' => t('CSS Text'),
      '#default_value' => $this->configuration['css_settings']['novalnet_creditcard_css_text'],
    ];
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
      $this->configuration['transaction_type'] = $values['transaction_type'];
      $this->configuration['manual_amount_limit'] = $values['manual_amount_limit'];
      $this->configuration['order_completion_status'] = $values['order_completion_status'];
      $this->configuration['css_settings']['novalnet_creditcard_css_label'] = $values['css_settings']['novalnet_creditcard_css_label'];
      $this->configuration['css_settings']['novalnet_creditcard_css_input'] = $values['css_settings']['novalnet_creditcard_css_input'];
      $this->configuration['css_settings']['novalnet_creditcard_css_text'] = $values['css_settings']['novalnet_creditcard_css_text'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $novalnet_library = new NovalnetLibrary();
    $payment_details = \Drupal::service('session')->get('payment_details');
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
    $order = Order::load($order_id);
    $request_parameters = [];
    $novalnet_library->commerceNovalnetMerchantParameters($request_parameters);
    $novalnet_library->commerceNovalnetCommonParameters($order, $this->configuration, $payment, $request_parameters);
    $novalnet_library->commerceNovalnetSystemParameters($request_parameters);
    $novalnet_library->commerceNovalnetAdditionalParameters($payment, $this->configuration, $request_parameters);
    $request_parameters['pan_hash']     = $payment_details['pan_hash'];
    $request_parameters['unique_id']    = $payment_details['unique_id'];
    $request_parameters['key']          = 6;
    $request_parameters['payment_type'] = 'CREDITCARD';
    $request_parameters['nn_it']        = 'iframe';

		$request_parameters['cc_3d'] = '1';
    $paramlist = $novalnet_library->commerceNovalnetRedirectParameters($request_parameters, $novalnet_library->access_key, $order);
    $paramlist['return_url'] = $novalnet_library->build3dCheckReturnUrl($order_id, 'commerce_novalnet.3ds.return');
    $paramlist['error_return_url'] = $novalnet_library->build3dCheckCancelUrl($order_id, 'commerce_novalnet.3ds.cancel');
    return self::commerceNovalnetFormHiddenValues($paramlist);
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    \Drupal::service('session')->set('payment_details', $_POST['payment_information']['add_payment_method']['payment_details']);
    $payment_method->setReusable(FALSE);
    $remote_id = $payment_method->getOwnerId();
    $payment_method->setRemoteId($remote_id);
    $payment_method->save();

  }

  /**
   * Add hidden elements to the form.
   *
   * @param array $data
   *   The elements to be formed in hidden.
   */
  public function commerceNovalnetFormHiddenValues(array $data) {
    $form_data   = '<form name="novalnet_cc" method="post" action="https://payport.novalnet.de/pci_payport">';
    $form_submit = '<script>document.forms.novalnet_cc.submit();</script>';
    echo  t('Please wait while you are redirected to the payment server. If nothing happens within 10 seconds, please click on the button below.').'<br><input data-drupal-selector="edit-actions-next" type="submit" id="edit-actions-next" name="op" value="Proceed" class="button button--primary js-form-submit form-submit">';
    foreach ($data as $k => $v) {
      $form_data .= '<input type="hidden" name="' . $k . '" value="' . $v . '" />' . "\n";
    }
    echo $form_data . '</form>' . $form_submit;
    exit();
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
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $novalnet_library = new NovalnetLibrary();
    if ((isset($response['status']) && $response['status'] == 100)) {
	 $global_configuration = \Drupal::config('commerce_novalnet.application_settings');
	$order_state = $this->configuration['order_completion_status'];
	if($response['tid_status'] == 98)
	$order_state = $global_configuration->get('commerce_novalnet_onhold_completion_status');
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
      $novalnet_library->commerceNovalnetOrderComplete($response, $this->code, $order, $this->configuration, $order_state, $this->entityId);
    }
    else {
      $novalnet_library->commerceNovalnetCancellation($response, $order, $this->code, $this->configuration);
      return FALSE;
    }
  }

}
