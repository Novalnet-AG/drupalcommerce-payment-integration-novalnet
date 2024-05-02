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
namespace Drupal\commerce_novalnet\Plugin\Commerce\PaymentMethodType;

use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentMethodType\PaymentMethodTypeBase;

/**
 * Provides the Sepa payment method type.
 *
 * @CommercePaymentMethodType(
 *   id = "novalnet_sepa",
 *   label = @Translation("Sepa"),
 *   create_label = @Translation("Sepa"),
 * )
 */
class NovalnetSepaType extends PaymentMethodTypeBase {

  /**
   * {@inheritdoc}
   */
  public function buildLabel(PaymentMethodInterface $payment_method) {
    // ToDo.
    $payment_gateway = $payment_method->getPaymentGateway();
    $configuration = $payment_gateway->get('configuration');

    $args = [
      '@gateway_title' => $payment_gateway->label(),
    ];
    $label = t('@gateway_title', $args);

    $label .= '<br />' . $configuration['description'];

    return $label;
  }

}
