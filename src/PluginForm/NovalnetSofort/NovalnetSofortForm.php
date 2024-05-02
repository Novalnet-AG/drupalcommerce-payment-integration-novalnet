<?php
/**
 * @file
 * Contains the Novalnet reirect payment form process.
 * 
 * @category   PHP
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.0.1
 */
namespace Drupal\commerce_novalnet\PluginForm\NovalnetSofort;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_novalnet\NovalnetLibrary;

/**
 * This is NovalnetSofortForm .
 */
class NovalnetSofortForm extends BasePaymentOffsiteForm {

  const NOVALNET_SOFORT_URL = 'https://payport.novalnet.de/online_transfer_payport';

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $novalnet_library = new NovalnetLibrary();
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
    $order = Order::load($order_id);
    $request_parameters = [];
    $configuration = $this->entity->getPaymentGateway()->get('configuration');
    $novalnet_library->commerceNovalnetMerchantParameters($request_parameters);
    $novalnet_library->commerceNovalnetCommonParameters($order, $configuration, $payment, $request_parameters);
    $novalnet_library->commerceNovalnetSystemParameters($request_parameters);
    $novalnet_library->commerceNovalnetAdditionalParameters($payment, $configuration, $request_parameters);
    $request_parameters['key'] = 33;
    $request_parameters['payment_type'] = 'ONLINE_TRANSFER';
    $paramlist = $novalnet_library->commerceNovalnetRedirectParameters($request_parameters, $novalnet_library->access_key, $order);
    return $this->buildRedirectForm($form, $form_state, self::NOVALNET_SOFORT_URL, $paramlist, 'post');
  }

}
