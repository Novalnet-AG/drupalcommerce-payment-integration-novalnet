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
namespace Drupal\commerce_novalnet\PluginForm\NovalnetInvoice;

use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_novalnet\NovalnetLibrary;

/**
 * This is NovalnetInvoiceForm.
 */
class NovalnetInvoiceForm extends BasePaymentMethodAddForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $configuration = $this->entity->getPaymentGateway()->get('configuration');
    $novalnet_library = new NovalnetLibrary();
    $novalnet_library->commerceNovalnetDisplayPaymentLogo($configuration['display_label'], 'novalnet_invoice', $configuration);
    $form['payment_details']['display_title'] = $novalnet_library->commerceNovalnetDisplayPaymentLogo($configuration['display_label'], 'novalnet_invoice', $configuration);
    $description = '<br>'.$novalnet_library->commerceNovalnetGetDescription('novalnet_invoice');

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
    if ($this->entity->getPaymentGateway()->get('configuration')['guarantee_configuration']['novalnet_invoice_guarantee_payment']) {
      $form['novalnet_invoice_dob'] = [
        '#type'     => 'date',
        '#id'       => 'novalnet_invoice_dob',
        '#title'    => t('Your date of birth'),
        '#required'         => TRUE,
      ];
    }
    $form['payment_details']['key'] = [
      '#type' => 'value',
      '#value' => 'no-value',
    ];

    return $form;
  }

}
