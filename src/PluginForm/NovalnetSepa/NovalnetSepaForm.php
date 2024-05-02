<?php
/**
 * @file
 * Contains the Novalnet form description.
 * 
 * @category   PHP
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.0.1
 */
namespace Drupal\commerce_novalnet\PluginForm\NovalnetSepa;

use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_novalnet\NovalnetLibrary;

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
    $novalnet_library = new NovalnetLibrary();
    $configuration = $this->entity->getPaymentGateway()->get('configuration');
    $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
    $order = Order::load($order_id);
    $form['payment_details']['display_title'] = $novalnet_library->commerceNovalnetDisplayPaymentLogo($configuration['display_label'], 'novalnet_sepa', $configuration);
    $description = '<br>'.$novalnet_library->commerceNovalnetGetDescription('novalnet_sepa');
    $form['payment_details']['description'] = [
      '#markup' => $description,
    ];
    if ($configuration['mode'] == 'test') {
      $form['payment_details']['test_mode'] = [
        'inside' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => t('The payment will be processed in the test mode therefore amount for this transaction will not be charged'),
          '#attributes' => [
            'style' => ['color:red'],
          ],
        ],
      ];
    }
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
    $novalnet_library = new NovalnetLibrary();
    $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
    $order = Order::load($order_id);
    $element['#attributes']['class'] = 'novalnet-sepa-form';
    $element['novalnet_sepa_account_holder'] = [
      '#type' => 'textfield',
      '#id' => 'novalnet_sepa_account_holder',
      '#title' => t('Account holder'),
      '#attributes' => ['onkeypress' => 'return Drupal.behaviors.commerceNovalnetDirectDebitSepa.allowNameKey(event)', 'autocomplete' => 'off'],
      '#required' => TRUE,
    ];
    $element['novalnet_sepa_iban'] = [
      '#type'   => 'textfield',
      '#id'     => 'novalnet_sepa_iban',
      '#title'  => t('IBAN'),
      '#attributes' => [
        'onkeypress' => 'return Drupal.behaviors.commerceNovalnetDirectDebitSepa.allowAlphanumeric(event)',
        'autocomplete' => 'off',
        'style' => 'text-transform: uppercase;',
      ],
      '#required' => TRUE,
    ];

    if ($this->entity->getPaymentGateway()->get('configuration')['guarantee_configuration']['novalnet_sepa_guarantee_payment']) {
      $element['novalnet_sepa_dob'] = [
        '#type'     => 'date',
        '#id'       => 'novalnet_sepa_dob',
        '#title'    => t('Your date of birth'),
        '#required'         => TRUE,
      ];
    }

    $element['nnsepa_ibanconf_bool'] = [
      '#type'           => 'hidden',
      '#default_value'  => '1',
      '#attributes'     => ['id' => 'nnsepa_ibanconf_bool'],
    ];
    
    $element['novalnet_sepa_overlay_link'] = [
      '#type' => 'markup',
      '#markup' => '<a id="nnsepa_ibanconf"><strong>' . t('I hereby grant the mandate for the SEPA direct debit (electronic transmission) and confirm that the given bank details are correct!.') . '</strong></a>',

    ];
    $element['novalnet_sepa_overlay_info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="panel panel-default collapse in" id="nnsepa_ibanconf_desc" style="padding: 5px;" aria-expanded="true">' .
      t('I authorise (A) Novalnet AG to send instructions to my bank to debit my account and (B) my bank to debit my account in accordance with the instructions from Novalnet AG.<br><br><strong>Creditor identifier: DE53ZZZ00000004253</strong><br><br><strong>Note:</strong> You are entitled to a refund from your bank under the terms and conditions of your agreement with bank. A refund must be claimed within 8 weeks starting from the date on which your account was debited.') .
      ' </div>',

    ];

    $novalnet_library->commerceNovalnetIncludeJs($element, 'novalnet_sepa', []);
    return $element;
  }

}