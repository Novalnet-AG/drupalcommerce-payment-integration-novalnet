<?php
/**
 * @file
 * Contains the 3D secure related process.
 *
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.com/payment-plugins/free/license
 * @version    1.2.0
 */
namespace Drupal\commerce_novalnet\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayInterface;
use Drupal\Core\Access\AccessException;
use Symfony\Component\HttpFoundation\Request;

/**
 * The NovalnetSecureCheckController .
 */
class NovalnetSecureCheckController {
  /**
   * Provides the "return" checkout payment page for Creditcard 3-D Secure.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *   The order object.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request params.
   */
  public function onReturn(OrderInterface $commerce_order, Request $request) {
    if (!empty($request->request->all())) {
      $response = $request->request->all();
    }
    else {
      $response = $request->query->all();
    }
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $commerce_order->payment_gateway->entity;
    $payment_gateway_plugin = $payment_gateway->getPlugin();
    if (!$payment_gateway_plugin instanceof OnsitePaymentGatewayInterface) {
      throw new AccessException('The payment gateway for the order does not implement ' . OnsitePaymentGatewayInterface::class);
    }
    /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
    $checkout_flow = $commerce_order->checkout_flow->entity;
    $checkout_flow_plugin = $checkout_flow->getPlugin();
    try {
      $payment_gateway_plugin->onSecurityCheckReturn($commerce_order, $response);
      $redirect_step = $checkout_flow_plugin->getNextStepId('payment');
    }
    catch (PaymentGatewayException $e) {
      \Drupal::logger('commerce_novalnet')->error($e->getMessage());
      drupal_set_message(t('Payment failed at the payment server. Please review your information and try again.'), 'error');
      $redirect_step = $checkout_flow_plugin->getPreviousStepId();
    }
    $checkout_flow_plugin->redirectToStep($redirect_step);
  }

  /**
   * Provides the "cancel" checkout payment page for 3-D Secure check.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *   The order.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   */
  public function onCancel(OrderInterface $commerce_order, Request $request) {
    if (!empty($request->request->all())) {
      $response = $request->request->all();
    }
    else {
      $response = $request->query->all();
    }
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $commerce_order->payment_gateway->entity;
    $payment_gateway_plugin = $payment_gateway->getPlugin();
    if (!$payment_gateway_plugin instanceof OnsitePaymentGatewayInterface) {
      throw new AccessException('The payment gateway for the order does not implement ' . OnsitePaymentGatewayInterface::class);
    }
    $payment_gateway_plugin->onSecurityCheckReturn($commerce_order, $response);
    /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
    $checkout_flow = $commerce_order->checkout_flow->entity;
    /** @var \Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface $checkout_flow_plugin */
    $checkout_flow_plugin = $checkout_flow->getPlugin();
    $previous_step_id = $checkout_flow_plugin->getPreviousStepId('payment');
    foreach ($checkout_flow_plugin->getPanes() as $pane) {
      if ($pane->getId() == 'payment_information') {
        $previous_step_id = $pane->getStepId();
      }
    }
    $checkout_flow_plugin->redirectToStep($previous_step_id);
  }

}
