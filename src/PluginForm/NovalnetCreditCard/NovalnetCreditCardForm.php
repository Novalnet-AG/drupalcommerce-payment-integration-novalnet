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
namespace Drupal\commerce_novalnet\PluginForm\NovalnetCreditCard;

use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_novalnet\NovalnetLibrary;

/**
 * This is NovalnetCreditCardForm.
 */
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
    $novalnet_library = new NovalnetLibrary();
    // Get the Payment Gateway configuration.
    $configuration = $this->entity->getPaymentGateway()->get('configuration');
    $form['payment_details']['display_title'] = $novalnet_library->commerceNovalnetDisplayPaymentLogo($configuration['display_label'], 'novalnet_cc', $configuration);
    $description = $novalnet_library->commerceNovalnetGetDescription('novalnet_cc_3d');
    $form['payment_details']['description'] = [
      '#markup' => '<br>'.$description,
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

    $form['payment_details'] = $this->buildCreditCardForm($form['payment_details'], $form_state, $configuration);
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
  public function buildCreditCardForm(array $element, FormStateInterface $form_state, array $configuration) {
    $novalnet_library = new NovalnetLibrary();
    $config = \Drupal::config('commerce_novalnet.application_settings');
    $element['#attributes']['class'] = 'novalnet-cc-form';
    $element['pan_hash'] = [
      '#type' => 'hidden',
      '#value' => '',
    ];
    $element['unique_id'] = [
      '#type' => 'hidden',
      '#value' => '',
    ];
    $lang = \Drupal::currentUser()->getPreferredLangcode();
    $iframe_src = base64_encode("vendor=" . $config->get('vendor_id') . "&product=" . $config->get('project_id') . "&server_ip=" . $novalnet_library->commerceNovalnetGetIpAddress('SERVER_ADDR'));
    
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
          'src' => "https://secure.novalnet.de/cc?api='. $iframe_src .'&ln='.$lang",
          'frameborder' => 0,
          'scrolling' => FALSE,
          'allowtransparency' => TRUE,
          'width' => '100%',
          'onload' => 'Drupal.behaviors.commerceNovalnetCreditCard.loadCcIframe()',
        ],
      ],
    ];
    $novalnet_library->commerceNovalnetIncludeJs($element, 'novalnet_creditcard', [
      'css_label'        => $configuration['css_settings']['novalnet_creditcard_css_label'],
      'css_input'        => $configuration['css_settings']['novalnet_creditcard_css_input'],
      'css_text'         => $configuration['css_settings']['novalnet_creditcard_css_text'],
    ]);
    return $element;
  }

}
