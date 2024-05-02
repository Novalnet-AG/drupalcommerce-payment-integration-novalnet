<?php
/**
 * @file
 * This file provide the global form settings.
 * 
 * @category   PHP
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.0.1
 */
namespace Drupal\commerce_novalnet\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Url;
use Drupal\commerce_novalnet\NovalnetLibrary;

/**
 * The Novalnet configuration.
 */
class NovalnetGlobalConfiguration extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['commerce_novalnet.application_settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_novalnet_settings_form';
  }

  /**
   * Auto fill configuration.
   *
   * @param array $form
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   form values
   */
  public function fillAutoConfig(array &$form, FormStateInterface $form_state) {
    $novalnet_library = new NovalnetLibrary();
    // Get process key.
    $process_key = $form_state->hasValue('commerce_novalnet_product_activation_key') ? $form_state->getValue('commerce_novalnet_product_activation_key') : $form['commerce_novalnet_product_activation_key']['#default_value'];
    if (!empty($process_key)) {
      $langcode = \Drupal::currentUser()->getPreferredLangcode();
      $response = $novalnet_library->commerceNovalnetSendServerRequest([
        'hash' => $process_key,
        'lang' => strtoupper($langcode),
      ], 'https://payport.novalnet.de/autoconfig', TRUE);
      $configuration = Json::decode($response);
      if (!json_last_error()) {
        $form['commerce_novalnet_vendor_id']['#value']          = $configuration['vendor'];
        $form['commerce_novalnet_auth_code']['#value']          = $configuration['auth_code'];
        $form['commerce_novalnet_project_id']['#value']         = $configuration['product'];
        $form['commerce_novalnet_project_id']['#default_value'] = $configuration['product'];
        $form['commerce_novalnet_access_key']['#value']         = $configuration['access_key'];
        $tariff_name                                            = array_column($configuration['tariff'], 'name');
        $tariff_id                                              = array_keys($configuration['tariff']);
        $available_tariff                                       = array_combine($tariff_id, $tariff_name);
        $form['commerce_novalnet_tariff_id']['#options']        = $available_tariff;
        return $form;
      }
    }
    else {
      $form['commerce_novalnet_vendor_id']['#value'] = '';
      $form['commerce_novalnet_auth_code']['#value'] = '';
      $form['commerce_novalnet_project_id']['#value'] = '';
      $form['commerce_novalnet_access_key']['#value'] = '';
      $form['commerce_novalnet_tariff_id']['#options'] = [];
      return $form;
    }
  }

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
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $novalnet_library = new NovalnetLibrary();
    $config = $this->config('commerce_novalnet.application_settings');
    $wrapper_id = Html::getUniqueId('ajax-wrapper');
    $form['#prefix'] = '<div id="' . $wrapper_id . '">';
    $form['#suffix'] = '</div>';
    $form['commerce_novalnet_admin_description']    = array(
    '#type'       => 'markup',
    '#markup'      => t("For additional configurations login to <a href='https://admin.novalnet.de' target='_blank'>Novalnet Merchant Administration portal</a>. To login to the Portal you need to have an account at Novalnet. If you don't have one yet, please contact <a href='mailto:sales@novalnet.de'>sales@novalnet.de</a> / tel. +49 (089) 923068320") . '<br>' . t("To use the PayPal payment method please enter your PayPal API details in <a href='https://admin.novalnet.de' target='_blank'>Novalnet Merchant Administration portal</a>"),
  );
    $form['commerce_novalnet_product_activation_key'] = [
      '#type' => 'textfield',
      '#title' => t('Product activation key'),
      '#required'         => TRUE,
      '#validated'        => TRUE,
      '#default_value' => $config->get('product_activation_key'),
       '#description' => t("Enter Novalnet Product activation key. To get the Product Activation Key, go to <a href='https://admin.novalnet.de/' target='_blank'>Novalnet Merchant Administration portal</a> - PROJECTS: Project Information - Shop Parameters: API Signature (Product activation key)."),
      '#ajax'             => [
        'event' => 'change',
        'wrapper' => $wrapper_id,
        'callback' => [$this, 'fillAutoConfig'],
        'effect' => 'fade',
      ],
    ];
    $form['commerce_novalnet_vendor_id'] = [
      '#type' => 'hidden',
      '#validated'   => TRUE,
      '#default_value' => $config->get('vendor_id'),
    ];
    $form['commerce_novalnet_auth_code'] = [
      '#type' => 'hidden',
      '#validated'        => TRUE,
      '#default_value' => $config->get('auth_code'),
    ];
    $form['commerce_novalnet_project_id'] = [
      '#type' => 'hidden',
      '#validated'        => TRUE,
      '#default_value' => $config->get('project_id'),
    ];
    $form['commerce_novalnet_access_key'] = [
      '#type' => 'hidden',
      '#validated'        => TRUE,
      '#default_value' => $config->get('access_key'),
    ];
    $form['commerce_novalnet_tariff_id'] = [
      '#type' => 'select',
      '#title' => t('Tariff ID'),
      '#default_value' => $config->get('tariff_id'),
      '#required'         => TRUE,
      '#validated'        => TRUE,
      '#description' => t('Select Novalnet tariff ID'),

      '#options' => [],
    ];
    $form['commerce_novalnet_referrer_id'] = [
      '#type' => 'textfield',
      '#title' => t('Referrer ID'),
      '#default_value' => $config->get('referrer_id'),
      '#description' => t('Enter the referrer ID of the person/company who recommended you Novalnet'),
    ];
   $form['commerce_novalnet_onhold_completion_status'] = [
      '#type'          => 'select',
      '#title'         => t('Onhold order status'),
      '#options'       => $novalnet_library->commerceOrderStatusOptionsList(),
      '#default_value' => !empty($config->get('commerce_novalnet_onhold_completion_status')) ? $config->get('commerce_novalnet_onhold_completion_status') : 'pending',
    ];
    $form['commerce_novalnet_onhold_cancelled_status'] = [
      '#type'          => 'select',
      '#title'         => t('Cancellation order status'),
      '#options'       => $novalnet_library->commerceOrderStatusOptionsList(),
      '#default_value' => !empty($config->get('commerce_novalnet_onhold_cancelled_status')) ? $config->get('commerce_novalnet_onhold_cancelled_status') : 'canceled',
    ];
    $form['callback_configuration'] = [
      '#type' => 'details',
      '#title' => t('Merchant script management'),
      '#open' => TRUE,
    ];
    $form['callback_configuration']['commerce_novalnet_callback_test_mode'] = [
      '#type' => 'checkbox',
      '#title' => t('Deactivate IP address control (for test purpose only)'),
      '#default_value' => $config->get('callback_test_mode'),
      '#description' => t('This option will allow performing a manual execution. Please disable this option before setting your shop to LIVE mode, to avoid unauthorized calls from external parties (excl. Novalnet).'),
    ];
    $form['callback_configuration']['commerce_novalnet_enable_callback_mail'] = [
      '#type' => 'checkbox',
      '#title' => t('Enable E-mail notification for callback'),
      '#default_value' => $config->get('enable_callback_mail'),
    ];
    $form['callback_configuration']['commerce_novalnet_callback_mail_to_address'] = [
      '#type' => 'email',
      '#title' => t('E-mail address (To)'),
      '#default_value' => $config->get('callback_mail_to_address'),
      '#description' => t('E-Mail address of the recipient'),
    ];
    $form['callback_configuration']['commerce_novalnet_callback_mail_bcc_address'] = [
      '#type' => 'textfield',
      '#title' => t('E-mail address (Bcc)'),
      '#default_value' => !empty($config->get('callback_mail_bcc_address')) ? $config->get('callback_mail_bcc_address') : '',
      '#description' => t('E-mail address of the recipient for BCC.'),
    ];
    $form['callback_configuration']['commerce_novalnet_callback_notify_url'] = [
      '#type' => 'url',
      '#title' => t('Notification URL'),
      '#default_value' => !empty($config->get('callback_notify_url')) ? $config->get('callback_notify_url') : Url::fromRoute('commerce_novalnet.callback', [], ['absolute' => TRUE])->toString(),
      '#description' => t('The notification URL is used to keep your database/system actual and synchronizes with the Novalnet transaction status.'),
    ];
    $this->fields = array_keys(array_merge($form, $form['callback_configuration']));
    if ($config->get('product_activation_key')) {
      $this->fillAutoConfig($form, $form_state);
    }
    return $form;
  }

  /**
   * Validates the element form.
   *
   * @param array $form
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $novalnet_library = new NovalnetLibrary();
    if (!$form_state->getValue('commerce_novalnet_product_activation_key')) {
      $form_state->setErrorByName('commerce_novalnet_product_activation_key', t('Please fill all the mandatory fields.'));
      return;
    }
    if ($form_state->getValue('commerce_novalnet_callback_mail_bcc_address')) {
		 $mailarr = explode(",", $form_state->getValue('commerce_novalnet_callback_mail_bcc_address'));
    $invalidarr = [];
    foreach ($mailarr as $email) {
      if (!valid_email_address(trim($email))) {
        $invalidarr[] = trim($email);
      }
    }
    if (!empty($invalidarr)) {
     $form_state->setErrorByName('commerce_novalnet_callback_mail_bcc_address', t('Invalid @title', array('@title' => $form['callback_configuration']['commerce_novalnet_callback_mail_bcc_address']['#title'])));

    }
	}
    if (!$novalnet_library->commerceNovalnetDigitsCheck($form_state->getValue('commerce_novalnet_referrer_id'))) {
      $form_state->setValueForElement($form['commerce_novalnet_referrer_id'], '');
    }
  }

  /**
   * Submits the element form.
   *
   * @param array $form
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $config = $this->config('commerce_novalnet.application_settings');
    $config
      ->set('commerce_novalnet_product_activation_key', $form_state->getValue('commerce_novalnet_product_activation_key'))
      ->set('commerce_novalnet_vendor_id', $form_state->getValue('commerce_novalnet_vendor_id'))
      ->set('commerce_novalnet_auth_code', $form_state->getValue('commerce_novalnet_auth_code'))
      ->set('commerce_novalnet_project_id', $form_state->getValue('commerce_novalnet_project_id'))
      ->set('commerce_novalnet_tariff_id', $form_state->getValue('commerce_novalnet_tariff_id'))
      ->set('commerce_novalnet_referrer_id', $form_state->getValue('commerce_novalnet_referrer_id'))
      ->set('commerce_novalnet_onhold_completion_status', $form_state->getValue('commerce_novalnet_onhold_completion_status'))
      ->set('commerce_novalnet_onhold_cancelled_status', $form_state->getValue('commerce_novalnet_onhold_cancelled_status'))
      ->set('commerce_novalnet_access_key', $form_state->getValue('commerce_novalnet_access_key'))
      ->set('commerce_novalnet_callback_test_mode', $form_state->getValue('commerce_novalnet_callback_test_mode'))
      ->set('commerce_novalnet_enable_callback_mail', $form_state->getValue('commerce_novalnet_enable_callback_mail'))
      ->set('commerce_novalnet_callback_mail_to_address', $form_state->getValue('commerce_novalnet_callback_mail_to_address'));
    foreach ($this->fields as $field) {
      if (strpos($field, 'commerce_novalnet') !== FALSE) {
        $value = $form_state->getValue($field);
        $field = str_replace('commerce_novalnet_', '', $field);
        $config->set($field, $value)->save();
      }
    }
    $config->save();
  }

}
