<?php
/**
 * Contains the form description.
 *
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.2.0
 */
namespace Drupal\commerce_novalnet\PluginForm\NovalnetCreditCard;

use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_novalnet\Novalnet;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\Core\Locale\CountryManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\Core\Url;
use Drupal\Core\Messenger\MessengerInterface;

class NovalnetCreditCardForm extends BasePaymentMethodAddForm {

  /**
   * Form the configuration field.
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
    // Get the Payment Gateway configuration.
    $configuration = $this->entity->getPaymentGateway()->get('configuration');
    $form['payment_details']['display_title'] = Novalnet::displayPaymentLogo($configuration['display_label'], 'novalnet_cc');
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
      '#value' => '<br />' .Novalnet::getDescription('novalnet_cc'),
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
    $form['payment_details']['key'] = [
      '#type' => 'value',
      '#value' => 'no-value',
    ];

    $form['payment_details'] = $this->creditCardForm($form['payment_details'], $form_state, $configuration);
    return $form;
  }

  /**
   * Build the creditcard payment configuration field.
   *
   * @param array $element
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $configuration
   *   The configuration field.
   *
   * @return array
   *   The form structure.
   */
  public function creditCardForm($element, $form_state, $configuration) {

    $config = \Drupal::config('commerce_novalnet.application_settings');
    $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
    $order = Order::load($order_id);
    $profile = $order->getBillingProfile();
    $lang = \Drupal::currentUser()->getPreferredLangcode();
    if (!empty($profile)) {
      $address = $profile->get('address')->first()->getValue();
    }
    $element['#attributes']['class'] = 'novalnet-cc-form';
    $element['pan_hash'] = [
      '#type' => 'hidden',
      '#value' => '',
    ];
    $element['unique_id'] = [
      '#type' => 'hidden',
      '#value' => '',
    ];
    $element['do_redirect'] = [
      '#type' => 'hidden',
      '#value' => '',
    ];
    $element['cc_iframe_error'] = [
     'inside' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => [
            'style' => ['color:red'],
            'id' => 'nncc_error',
          ],
        ],
  ];
    $element['cc_iframe'] = [
      'inside' => [
        '#type' => 'html_tag',
        '#tag' => 'iframe',
        '#attributes' => [
          'id' => 'novalnet-cc-iframe',
          'frameborder' => 0,
          'scrolling' => false,
          'width' => '100%',
          'onload' => 'Drupal.behaviors.commerceNovalnetCreditCard.loadCcIframe();',
        ],
      ],
    ];
    Novalnet::includeFiles($element, 'novalnet_creditcard', [
      'client_key' => $config->get('client_key'),
      'css_label'        => $configuration['css_settings']['novalnet_creditcard_css_label'],
      'css_input'        => $configuration['css_settings']['novalnet_creditcard_css_input'],
      'css_text'         => $configuration['css_settings']['novalnet_creditcard_css_text'],
      'enforce_3d'       => $configuration['novalnet_cc3d_secure'],
      'inline_form'      => $configuration['inline_iframe'],
      'test_mode'        => $configuration['mode'],
      'lang'             => $lang,
      'invalid_error' => t('Your credit card details are invalid'),
      'holder_place_holder' => t('Name on the card'),
      'holder_error' => t('Please enter the valid card holder name'),
      'number_place_holder' => t('XXXX XXXX XXXX XXXX'),
      'expiry_date_place_holder' => t('xx/xx'),
      'number_error' => t('Please enter the valid card number'),
      'expiry_error' => t('Please enter the valid expiry month / year in the given format'),
      'cvc_place_holder' => t('XXX'),
      'cvc_error' => t('Please enter the valid CVC/CVV/CID'),
      'first_name' => $address['given_name'],
      'last_name' => $address['family_name'],
      'email' => $order->get('mail')->first()->value != null ? $order->get('mail')->first()->value : '',
      'tel' => $address['tel'] != null ? $address['tel'] : '',
      'mobile' => $address['mobile'] != null ? $address['mobile'] : '',
      'fax' => $address['fax'] != null ? $address['fax'] : '',
      'tax_id' => $address['tax_id'] != null ? $address['tax_id'] : '',
      'billing_street' => $address['address_line1']. ' ' .$address['address_line2'] ,
      'billing_city' => $address['locality'],
      'billing_zip' => $address['postal_code'],
      'billing_country_code' => $address['country_code'],
      'billling_company' => $address['organization'],
      'same_as_billing' => 1,
      'card_holder' =>t('Card holder name'),
      'card_number' => t('Card number'),
      'expiry_date' => t('Expiry date'),
      'cvc' => t('CVC/CVV/CID'),
      'amount' => Novalnet::formatAmount($order->getTotalPrice()->getNumber()),
      'currency' => $order->getTotalPrice()->getCurrencyCode()
    ]);
    return $element;
  }
}
