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
 * @version    1.0.0
 */
namespace Drupal\commerce_novalnet\Plugin\Commerce\PaymentMethodType;

use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentMethodType\PaymentMethodTypeBase;

/**
 * Provides the CreditCard payment method type.
 *
 * @CommercePaymentMethodType(
 *   id = "novalnet_cc",
 *   label = @Translation("Credit/Debit Cards"),
 *   create_label = @Translation("Credit/Debit Cards"),
 * )
 */
class NovalnetCreditCardType extends PaymentMethodTypeBase {
  /**
   * {@inheritdoc}
   */
  public function buildLabel(PaymentMethodInterface $payment_method) {
    $payment_gateway = $payment_method->getPaymentGateway();
    $args = [
      '@gateway_title' => $payment_gateway->label(),
    ];
    $label = t('@gateway_title', $args);
    return $label;
  }
}
