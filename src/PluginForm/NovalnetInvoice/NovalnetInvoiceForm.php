<?php
/**
 * Contains the Novalnet form description.
 *
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.0.0
 */
namespace Drupal\commerce_novalnet\PluginForm\NovalnetInvoice;

use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_novalnet\Novalnet;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;

class NovalnetInvoiceForm extends BasePaymentMethodAddForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $configuration = $this->entity->getPaymentGateway()->get('configuration');
    $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
    $order = Order::load($order_id);
    $profile = $order->getBillingProfile();
    $address = '';
    if (!empty($profile)) {
      $address = $profile->get('address')->first()->getValue();
    }
    $form['payment_details']['display_title'] = Novalnet::displayPaymentLogo($configuration['display_label'], 'novalnet_invoice');
    if ($configuration['mode'] == 'test') {
      $form['payment_details']['test_mode'] = [
        'inside' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => '<br />' . t('Test Mode'),
        '#attributes' => [
          'style' => 'position: relative;
                        background-color: #0080c9;
                        color: #fff;
                        padding: 10px 20px;
                        margin-bottom: 8px;
                        font-size: 10px;
                        text-align: center;
                        text-transform: uppercase;
                        letter-spacing: 1px;
                        line-height: 0.8px;
                        border-radius: 0px 0px 5px 5px;
                        transition: transform 0.5s ease 0.5s;
                        animation: novalnet-test-mode-blinker 2s linear infinite;
                        font-weight: bold;
                        float: right;
                        top: -0px;'
          ],
        ],
      ];
    }
    $form['payment_details']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => '<br />' .Novalnet::getDescription('novalnet_invoice'),
      '#attributes' => [
        'style' => 'position: relative;
                         width: 95%;
                        height: auto;
                        background: content-box;
                        font-size: 14px;
                        color: #333;
                        margin: 20px 0px;
                        padding: 1em 1em;
                        border-left: 5px solid #0080c9;
                        box-shadow:0 0 8px 0px rgba(0,0,0,.4);
                        clear: both;
                        word-break:break-word;'
                    ],

  ];

  if (!empty($configuration['notification'])) {
    $form['payment_details']['buyer_notification'] = [
        '#prefix' => '<p>',
        '#markup' => $configuration['notification'],
        '#suffix' => '</p>',
    ];
  }

  $allow_b2b = $this->entity->getPaymentGateway()->get('configuration')['guarantee_configuration']['novalnet_invoice_allow_b2b_customer'];
  $company = !empty($address['organization']) ? $address['organization'] : '';
  if ($this->entity->getPaymentGateway()->get('configuration')['guarantee_configuration']['novalnet_invoice_guarantee_payment'] == 1) {
      if ((empty($company) && $allow_b2b == 1) || $allow_b2b == 0) {
            $form['novalnet_invoice_dob'] = [
              '#type'     => 'date',
              '#id'       => 'novalnet_invoice_dob',
              '#title'    => t('Your date of birth'),
              '#required'         => true,
           ];
      }
  }
  $form['payment_details']['key'] = [
      '#type' => 'value',
      '#value' => 'no-value',
    ];
  return $form;
  }
}
