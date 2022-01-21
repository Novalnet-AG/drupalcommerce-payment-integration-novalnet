<?php
/**
 * Contains the Novalnet reirect payment form process.
 *
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.1.0
 */
namespace Drupal\commerce_novalnet\PluginForm\NovalnetEps;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_novalnet\Novalnet;

class NovalnetEpsForm extends BasePaymentOffsiteForm {
  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
    $order = Order::load($order_id);
    $request_parameters = [];
    $configuration = $this->entity->getPaymentGateway()->get('configuration');
    $request_parameters['merchant'] = Novalnet::getMerchantData();
    $request_parameters['customer'] = Novalnet::getCustomerData($order,'novalnet_eps');
    $request_parameters['transaction'] =Novalnet::getTransactionData($order,'novalnet_eps', $payment, $configuration);
    $request_parameters['transaction']['payment_type'] = Novalnet::getPaymentType('novalnet_eps');
    $request_parameters['custom'] = ['lang' => strtoupper(\Drupal::languageManager()->getCurrentLanguage()->getId()),];
    $json_data = json_encode($request_parameters);
    $result = Novalnet::sendRequest($json_data, Novalnet::getPaygateURL('payment'));
    $response = json_decode($result);
    \Drupal::service('session')->set('novalnet_txn_secret', $response->transaction->txn_secret);
    if (!empty($response->result->redirect_url)) {
       return $this->buildRedirectForm($form, $form_state, $response->result->redirect_url, [], 'post');
    }
  }
}
