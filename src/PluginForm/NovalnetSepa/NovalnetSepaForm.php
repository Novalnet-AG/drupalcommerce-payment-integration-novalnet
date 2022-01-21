<?php
/**
 * Contains the Novalnet form description.
 *
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.1.0
 */
namespace Drupal\commerce_novalnet\PluginForm\NovalnetSepa;

use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_novalnet\Novalnet;

/**
 * This is NovalnetSepaForm.
 */
class NovalnetSepaForm extends BasePaymentMethodAddForm {

  /**
   * Build the credit card payment configuration field.
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
    $configuration = $this->entity->getPaymentGateway()->get('configuration');
    $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
    $order = Order::load($order_id);
    $form['payment_details']['display_title'] =Novalnet::displayPaymentLogo($configuration['display_label'], 'novalnet_sepa');
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
      '#value' => '<br />' .Novalnet::getDescription('novalnet_sepa'),
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
    $form['payment_details'] = $this->buildSepaForm($form['payment_details'], $form_state);
    return $form;
  }

  /**
   * Build the sepa payment configuration field.
   *
   * @param array $element
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The form structure.
   */
  public function buildSepaForm(array $element, FormStateInterface $form_state) {
    $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
    $order = Order::load($order_id);
    $profile = $order->getBillingProfile();
    $address = '';
    if (!empty($profile)) {
      $address = $profile->get('address')->first()->getValue();
    }
    $element['#attributes']['class'] = 'novalnet-sepa-form';
    $element['novalnet_sepa_iban'] = [
      '#type'          => 'textfield',
      '#id'            => 'novalnet_sepa_iban',
      '#title'         => t('IBAN'),
      '#attributes'    => [
        'onkeypress'   => 'return NovalnetUtility.formatIban(event)',
        'onchange'     => 'return NovalnetUtility.formatIban(event)',
        'autocomplete' => 'off',
        'style'        => 'text-transform: uppercase;',
      ],
      '#required' => true,
    ];
   $allow_b2b = $this->entity->getPaymentGateway()->get('configuration')['guarantee_configuration']['novalnet_sepa_allow_b2b_customer'];
   $company = !empty($address['organization']) ? $address['organization'] : '';
   if ($this->entity->getPaymentGateway()->get('configuration')['guarantee_configuration']['novalnet_sepa_guarantee_payment'] == 1) {
     if ((empty($company) && $allow_b2b == 1) || $allow_b2b == 0) {
       
        $element['novalnet_sepa_dob'] = [
              '#type'        => 'textfield',
              '#id'          => 'novalnet_sepa_dob',
              '#title'       => t('Your date of birth'),
              '#placeholder' => 'DD.MM.YYYY',
              '#attributes'  => [
               'onkeypress'   => 'return NovalnetUtility.isNumericBirthdate( this,event )',
			   'onchange'     => 'return NovalnetUtility.isNumericBirthdate( this,event )',			
               'onkeydown'  => 'return NovalnetUtility.isNumericBirthdate( this,event )',
              ],                
              '#required'    => true,
           ];
     }
   }
     $element['nnsepa_ibanconf_bool'] = [
      '#type'           => 'hidden',
      '#default_value'  => '1',
      '#attributes'     => ['id' => 'nnsepa_ibanconf_bool'],
    ];
    $element['novalnet_sepa_overlay_link'] = [
      '#type' => 'markup',
      '#markup' => '<a id="nnsepa_ibanconf"><strong>' .t('I hereby grant the mandate for the SEPA direct debit (electronic transmission)
       and confirm that the given bank details are correct!.'). '</strong></a>',
    ];
    $element['novalnet_sepa_overlay_info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="panel panel-default collapse in" id="nnsepa_ibanconf_desc" style="padding: 5px;" aria-expanded="true">' .
      t('I authorise (A) Novalnet AG to send instructions to my bank to debit my account and (B) my bank to debit
        my account in accordance with the instructions from Novalnet AG.
        <br><br><strong>Creditor identifier: DE53ZZZ00000004253</strong><br><br><strong>
        Note:</strong> You are entitled to a refund from your bank under the terms and conditions of your agreement with bank.
        A refund must be claimed within 8 weeks starting from the date on which your account was debited.') .
        '</div>',

    ];
    Novalnet::includeFiles($element, 'novalnet_sepa', []);
    return $element;
  }
}
