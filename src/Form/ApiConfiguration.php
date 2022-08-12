<?php
/**
 * This file provides the global configuration form.
 *
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.2.0
 */
namespace Drupal\commerce_novalnet\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\commerce_novalnet\Novalnet;
use Drupal\Core\Url;


class ApiConfiguration extends ConfigFormBase {
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
    $config = $this->config('commerce_novalnet.application_settings');
    $wrapper_id = Html::getUniqueId('ajax-wrapper');
    $form['#prefix'] = '<div id="' . $wrapper_id . '">';
    $form['#suffix'] = '</div>';
    $form['commerce_novalnet_admin_description']    = array(
    '#type'       => 'markup',
    '#markup'      => t("Please read the Installation Guide before you start and login to the <a href='https://admin.novalnet.de/' target='_blank'>Novalnet Admin Portal</a> using your merchant account. To get a merchant account, mail to <a href='mailto:sales@novalnet.de'>sales@novalnet.de</a> or call +49 (089) 923068320."),
    );
    $form['commerce_novalnet_product_activation_key'] = [
      '#type' => 'textfield',
      '#title' => t('Product activation key'),
      '#validated'        => true,
      '#default_value' => $config->get('product_activation_key'),
       '#description' => t("Your product activation key is a unique token for merchant authentication and payment processing.Get your Product activation key from the <a href=\"https://admin.novalnet.de\" target=\"_blank\">Novalnet Admin Portal</a> PROJECT > Choose your project > Shop Parameters > API Signature (Product activation key)"),
    ];
    $form['commerce_novalnet_access_key'] = [
      '#type' => 'textfield',
      '#title' => t('Payment access key'),
      '#validated'        => true,
      '#default_value' => $config->get('access_key'),
       '#description' =>t("Your secret key used to encrypt the data to avoid user manipulation and fraud.Get your Payment access key from the <a href=\"https://admin.novalnet.de\" target=\"_blank\">Novalnet Admin Portal</a> PROJECT > Choose your project > Shop Parameters > Payment access key"),
     ];
     $form['commerce_novalnet_client_key'] = [
      '#type' => 'hidden',
      '#validated'   => true,
      '#default_value' => $config->get('client_key'),
    ];
     $form['commerce_novalnet_project_id'] = [
      '#type' => 'hidden',
      '#validated'        => true,
      '#default_value' => $config->get('project_id'),
    ];
    $form['commerce_novalnet_tariff_id'] = [
      '#type' => 'select',
      '#title' => t('Select Tariff ID'),
      '#default_value' => $config->get('tariff_id'),
      '#validated'        => true,
      '#description' => t('Select a Tariff ID to match the preferred tariff plan you created at the Novalnet Admin Portal for this project'),
      '#options' => [],
    ];
    $form['callback_configuration'] = [
      '#type' => 'details',
      '#title' => t('Notification / Webhook URL Setup'),
      '#open' => true,
    ];
    $form['callback_configuration']['commerce_novalnet_callback_test_mode'] = [
      '#type' => 'checkbox',
      '#title' => t('Allow manual testing of the Notification / Webhook URL'),
      '#default_value' => $config->get('callback_test_mode'),
      '#description' => t('Enable this to test the Novalnet Notification / Webhook URL manually.
       Disable this before setting your shop live to block unauthorized calls from external parties'),
    ];
    $form['callback_configuration']['commerce_novalnet_callback_mail_to_address'] = [
      '#type' => 'textfield',
      '#title' => t('Send e-mail to'),
      '#default_value' => $config->get('callback_mail_to_address'),
      '#description' => t('Notification / Webhook URL execution messages will be sent to this e-mail'),
    ];
   $form['callback_configuration']['commerce_novalnet_callback_notify_url'] = [
      '#type' => 'url',
      '#title' => t('Notification / Webhook URL'),
      '#default_value' => !empty($config->get('callback_notify_url'))
       ? $config->get('callback_notify_url'):Url::fromRoute('commerce_novalnet.webhook', [], ['absolute' => true])->toString(),
      '#description' => t('You must configure the webhook endpoint in your <a href=\"https://admin.novalnet.de\" target=\"_blank\">Novalnet Admin portal</a>. This will allow you to receive notifications about the transaction'),
    ];
    $form['callback_configuration']['commerce_novalnet_configure_callback_url'] = [
      '#type' => 'submit',
      '#value' => t('Configure'),
      '#button_type' => 'primary',
      '#submit' => ['::configureWebhookUrl'],
    ];
    $this->fields = array_keys(array_merge($form, $form['callback_configuration']));
    if ($config->get('product_activation_key') && $config->get('access_key')) {
      $this->fillConfigurationFields($form, $form_state);
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
    if (!$form_state->getValue('commerce_novalnet_product_activation_key')) {
      $form_state->setErrorByName('commerce_novalnet_product_activation_key', t('Please fill all the mandatory fields.'));
    }
    if (!$form_state->getValue('commerce_novalnet_access_key')) {
	   $form_state->setErrorByName('commerce_novalnet_access_key', t('Please fill all the mandatory fields.'));
    }
    if ($form_state->getValue('commerce_novalnet_callback_mail_to_address')) {
        $mailarr = explode(",", $form_state->getValue('commerce_novalnet_callback_mail_to_address'));
        $invalidarr = [];
        foreach ($mailarr as $email) {
          if (!$this->validEmailAddress($email)) {
            $invalidarr[] = trim($email);
          }
        }
        if (!empty($invalidarr)) {
         $form_state->setErrorByName('commerce_novalnet_callback_mail_to_address',
         t('Invalid @title', array('@title' => $form['callback_configuration']['commerce_novalnet_callback_mail_to_address']['#title'])));
        }
    }
    return;
  }
  /**
   * Validate email address.
   *
   * @param array $form
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function validEmailAddress($mail) {
    return \Drupal::service('email.validator')->isValid($mail);
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
    foreach ($this->fields as $field) {
      if (strpos($field, 'commerce_novalnet') !== FALSE) {
        $value = trim($form_state->getValue($field));
        $field = str_replace('commerce_novalnet_', '', $field);
        $config->set($field, $value)->save();
      }
    }
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
   public function fillConfigurationFields(array &$form, FormStateInterface $form_state) {
    // Get process key.
    $process_key = $form_state->hasValue('commerce_novalnet_product_activation_key')
    ?$form_state->getValue('commerce_novalnet_product_activation_key'):$form['commerce_novalnet_product_activation_key']['#default_value'];
    $access_key = $form_state->hasValue('commerce_novalnet_access_key')
    ?$form_state->getValue('commerce_novalnet_access_key'):$form['commerce_novalnet_access_key']['#default_value'];
    if (!empty($process_key) && !empty($access_key)) {
      $langcode = \Drupal::currentUser()->getPreferredLangcode();
      $request = array('public_key' => $process_key,
                       'access_key' => $access_key,
                       'lang'       => strtoupper($langcode));
      $response = Novalnet::getMerchantDetails($request);
      $configuration = Json::decode($response);
      if (!json_last_error() && $configuration['result']['status_code'] == '100') {
        $form['commerce_novalnet_client_key']['#value']         = $configuration['merchant']['client_key'];
        $form['commerce_novalnet_project_id']['#value']         = $configuration['merchant']['project'];
        $tariff_name                                            = array_column($configuration['merchant']['tariff'], 'name');
        $tariff_id                                              = array_keys($configuration['merchant']['tariff']);
        $available_tariff                                       = array_combine($tariff_id, $tariff_name);
        $form['commerce_novalnet_tariff_id']['#options']        = $available_tariff;
        return $form;
      }
      else {
		$this->messenger()->addMessage($configuration['result']['status_text'], 'error');
        $form['commerce_novalnet_client_key']['#value'] = '';
        $form['commerce_novalnet_project_id']['#value'] = '';
        $form['commerce_novalnet_tariff_id']['#options'] = [];
        return $form;
      }
    }
    else {
	  $form['commerce_novalnet_product_activation_key']['#value'] = '';
      $form['commerce_novalnet_access_key']['#value'] = '';
      $form['commerce_novalnet_client_key']['#value'] = '';
      $form['commerce_novalnet_project_id']['#value'] = '';
      $form['commerce_novalnet_tariff_id']['#options'] = [];
      return $form;
    }
  }
  /**
   * Submit Configure Callback Url
   *
   * @param array $form
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   form values
   */
  public function configureWebhookUrl(array &$form, FormStateInterface $form_state) {
       $process_key = $form_state->hasValue('commerce_novalnet_product_activation_key')
       ? $form_state->getValue('commerce_novalnet_product_activation_key'):$form['commerce_novalnet_product_activation_key']['#default_value'];
       $access_key = $form_state->hasValue('commerce_novalnet_access_key')
       ? $form_state->getValue('commerce_novalnet_access_key'):$form['commerce_novalnet_access_key']['#default_value'];
       $webhook_url = $form_state->hasvalue('commerce_novalnet_callback_notify_url')
       ? $form_state->getValue('commerce_novalnet_callback_notify_url'):$form['commerce_novalnet_callback_notify_url']['#default_value'];
       if (!empty($process_key) && !empty($access_key)) {
         if (!empty($webhook_url)) {
           $langcode = \Drupal::currentUser()->getPreferredLangcode();
           $endpoint = Novalnet::getPaygateURL('apiconfigure');
            // Build the headers
            $data = [
                'merchant'      =>  [
                'signature' => $process_key
                ],
                'webhook'       =>[
                    'url'       => $webhook_url
                ],
                'custom'        => [
                    'lang'      => $langcode
                ]
            ];
            $json_data = json_encode($data);
            $response = Novalnet::sendRequest($json_data, $endpoint);
            $configuration = Json::decode($response);
            if ($configuration['result']['status'] == 'SUCCESS') {
               $this->messenger()->addMessage($this->t('Notification / Webhook URL is configured successfully in Novalnet Admin Portal'));
            }
            else {
                $this->messenger()->addMessage($configuration['result']['status_text'], 'error');
            }
          }
          else {
             $this->messenger()->addMessage($this->t('Please enter the valid webhook URL'), 'error');
          }
       }
       else {
           $this->messenger()->addMessage($this->t('please enter valid details'), 'error');
       }
       return $form;
    }
}
