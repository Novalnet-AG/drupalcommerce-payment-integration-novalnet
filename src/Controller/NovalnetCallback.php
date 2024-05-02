<?php
/**
 * @file
 * Contains the Novalnet callback related process.
 * 
 * @category   PHP
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.0.1
 */
namespace Drupal\commerce_novalnet\Controller;

use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\Order;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\commerce_novalnet\NovalnetLibrary;
use Drupal\commerce_price\Price;

/**
 * Defines the Novalnet Callback.
 *
 * @class Novalnet
 */
class NovalnetCallback extends ControllerBase {

  protected $entityTypeManager;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
    $container->get('entity_type.manager')
    );
  }

  /**
   * Level - 0 Payment types.
   *
   * @var array
   */
  protected $payments = [
    'CREDITCARD',
    'INVOICE_START',
    'CASHPAYMENT',
    'DIRECT_DEBIT_SEPA',
    'GUARANTEED_INVOICE',
    'GUARANTEED_DIRECT_DEBIT_SEPA',
    'PAYPAL',
    'PRZELEWY24',
    'ONLINE_TRANSFER',
    'IDEAL',
    'GIROPAY',
    'EPS',
  ];

  /**
   * Level - 1 Payment types.
   *
   * @var array
   */
  protected $chargebacks = [
    'RETURN_DEBIT_SEPA',
    'REVERSAL',
    'CREDITCARD_BOOKBACK',
    'CASHPAYMENT_REFUND',
    'CREDITCARD_CHARGEBACK',
    'PAYPAL_BOOKBACK',
    'REFUND_BY_BANK_TRANSFER_EU',
    'PRZELEWY24_REFUND',
    'GUARANTEED_SEPA_BOOKBACK',
    'GUARANTEED_INVOICE_BOOKBACK',
  ];

  /**
   * Level - 2 Payment types.
   *
   * @var array
   */
  protected $collections = [
    'INVOICE_CREDIT',
    'CASHPAYMENT_CREDIT',
    'CREDIT_ENTRY_CREDITCARD',
    'CREDIT_ENTRY_SEPA',
    'CREDIT_ENTRY_DE',
    'DEBT_COLLECTION_DE',
    'DEBT_COLLECTION_SEPA',
    'DEBT_COLLECTION_CREDITCARD',
    'ONLINE_TRANSFER_CREDIT',
  ];

  /**
   * Level - 2 Payment types.
   *
   * @var array
   */
  protected $transactionCancel = [
    'TRANSACTION_CANCELLATION',
  ];

  /**
   * Novalnet payments catagory.
   *
   * @var array
   */
  protected $paymentGroups = [
    'novalnet_cc'         => [
      'CREDITCARD',
      'CREDITCARD_BOOKBACK',
      'CREDITCARD_CHARGEBACK',
      'CREDIT_ENTRY_CREDITCARD',
      'DEBT_COLLECTION_CREDITCARD',
      'SUBSCRIPTION_STOP',
      'SUBSCRIPTION_REACTIVATE',
      'TRANSACTION_CANCELLATION',
    ],
    'novalnet_sepa'        => [
      'DIRECT_DEBIT_SEPA',
      'RETURN_DEBIT_SEPA',
      'GUARANTEED_DIRECT_DEBIT_SEPA',
      'REFUND_BY_BANK_TRANSFER_EU',
      'SUBSCRIPTION_STOP',
      'CREDIT_ENTRY_SEPA',
      'DEBT_COLLECTION_SEPA',
      'SUBSCRIPTION_REACTIVATE',
      'GUARANTEED_SEPA_BOOKBACK',
      'TRANSACTION_CANCELLATION',
    ],
    'novalnet_ideal'       => [
      'IDEAL',
      'REFUND_BY_BANK_TRANSFER_EU',
      'ONLINE_TRANSFER_CREDIT',
      'REVERSAL',
      'CREDIT_ENTRY_DE',
      'DEBT_COLLECTION_DE',
    ],
    'novalnet_eps'         => [
      'EPS',
      'CREDIT_ENTRY_DE',
      'DEBT_COLLECTION_DE',
      'REVERSAL',
      'REFUND_BY_BANK_TRANSFER_EU',
    ],
    'novalnet_giropay'     => [
      'GIROPAY',
      'CREDIT_ENTRY_DE',
      'DEBT_COLLECTION_DE',
      'REVERSAL',
      'REFUND_BY_BANK_TRANSFER_EU',
    ],
    'novalnet_sofort' => [
      'ONLINE_TRANSFER',
      'REFUND_BY_BANK_TRANSFER_EU',
      'ONLINE_TRANSFER_CREDIT',
      'CREDIT_ENTRY_DE',
      'REVERSAL',
      'CREDIT_ENTRY_DE',
      'DEBT_COLLECTION_DE',
    ],
    'novalnet_paypal'      => [
      'PAYPAL',
      'PAYPAL_BOOKBACK',
      'SUBSCRIPTION_STOP',
      'TRANSACTION_CANCELLATION',
    ],
    'novalnet_prepayment'  => [
      'INVOICE_START',
      'INVOICE_CREDIT',
      'SUBSCRIPTION_STOP',
      'REFUND_BY_BANK_TRANSFER_EU',
    ],
    'novalnet_invoice'     => [
      'INVOICE_START',
      'INVOICE_CREDIT',
      'GUARANTEED_INVOICE',
      'SUBSCRIPTION_STOP',
      'SUBSCRIPTION_REACTIVATE',
      'GUARANTEED_INVOICE_BOOKBACK',
      'TRANSACTION_CANCELLATION',
      'CREDIT_ENTRY_DE',
      'DEBT_COLLECTION_DE',
      'REFUND_BY_BANK_TRANSFER_EU',
    ],
    'novalnet_przelewy24' => [
      'PRZELEWY24',
      'PRZELEWY24_REFUND',
    ],
    'novalnet_cashpayment' => [
      'CASHPAYMENT',
      'CASHPAYMENT_CREDIT',
      'CASHPAYMENT_REFUND',
    ],
  ];

  /**
   * Mandatory Parameters.
   *
   * @var array
   */
  protected $requiredParams = [
    'vendor_id',
    'status',
    'payment_type',
    'tid_status',
    'tid',
  ];

  /**
   * Affiliate Parameters.
   *
   * @var array
   */
  protected $affiliateParams = [
    'vendor_id',
    'vendor_authcode',
    'product_id',
    'aff_id',
    'aff_authcode',
    'aff_accesskey',
  ];

  /**
   * Novalnet success codes.
   *
   * @var array
   */
  protected $successCode = [
    'PAYPAL' => [
      '100',
      '90',
      '85',
    ],
    'INVOICE_START' => [
      '100',
      '91',
    ],
    'CREDITCARD' => [
      '100',
      '98',
    ],
    'DIRECT_DEBIT_SEPA' => [
      '100',
      '99',
    ],
    'GUARANTEED_INVOICE' => [
      '100',
      '91',
      '75',
    ],
    'GUARANTEED_DIRECT_DEBIT_SEPA' => [
      '100',
      '99',
      '75',
    ],
    'ONLINE_TRANSFER' => [
      '100',
    ],
    'ONLINE_TRANSFER_CREDIT' => [
      '100',
    ],
    'GIROPAY' => [
      '100',
    ],
    'IDEAL' => [
      '100',
    ],
    'EPS' => [
      '100',
    ],
    'PRZELEWY24' => [
      '100',
      '86',
    ],
  ];

  /**
   * Callback test mode.
   *
   * @var int
   */
  protected $testMode;

  /**
   * Request parameters.
   *
   * @var array
   */
  protected $serverRequest = [];

  /**
   * Order reference values.
   *
   * @var array
   */
  protected $orderReference = [];

  /**
   * Success status values.
   *
   * @var bool
   */
  protected $successStatus;

  /**
   * Novalnet library reference.
   *
   * @var bool
   */
  protected $novalnetLibrary;

  /**
   * Critical error email.
   *
   * @var bool
   */
  protected $toAddress = 'technic@novalnet.de';

  /**
   * Callback api process.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Loads the server request and initiates the callback process.
   */
  public function callback(Request $request) {
    $this->novalnetLibrary = new NovalnetLibrary();

    $request = !empty($request->request->all()) ? array_map('trim', $request->request->all()) : array_map('trim', $request->query->all());
    
    $global_configuration = \Drupal::config('commerce_novalnet.application_settings');
    // Backend callback option.
    $this->testMode = $global_configuration->get('callback_test_mode');
    // Authenticating the server request based on IP.
    $client_ip = $this->novalnetLibrary->commerceNovalnetGetIpAddress();
    $get_host_name = gethostbyname('pay-nn.de');
    if (empty($get_host_name)) {
      $this->displayMessage('Novalnet HOST IP missing');
    }
    if ($client_ip != $get_host_name && !$global_configuration->get('callback_test_mode')) {

      $this->displayMessage('Novalnet callback received. Unauthorised access from the IP ' . $client_ip);
    }

    // Affiliate activation process.
    if (!empty($request['vendor_activation'])) {
      // Validate the callback mandatory affiliate parameters.
      $this->validateRequiredFields($this->affiliateParams, $request);
      $this->serverRequest = $request;
      db_insert('commerce_novalnet_affiliate_detail')
        ->fields(
      [
        'vendor_id'       => $this->serverRequest['vendor_id'],
        'vendor_authcode' => $this->serverRequest['vendor_authcode'],
        'product_id'      => $this->serverRequest['product_id'],
        'product_url'     => $this->serverRequest['product_url'],
        'activation_date' => date('Y-m-d H:i:s', strtotime($this->serverRequest['activation_date'])),
        'aff_id'          => $this->serverRequest['aff_id'],
        'aff_authcode'    => $this->serverRequest['aff_authcode'],
        'aff_accesskey'   => $this->serverRequest['aff_accesskey'],
      ]
          )
        ->execute();

      // Send notification mail to the configured E-mail.
      $this->sendMailNotification('Novalnet callback script executed successfully with Novalnet account activation information.');
    }

    // Get request parameters.
    $this->serverRequest = $this->validateServerRequest($request);
    // Check for success status.
    $this->successStatus = ($this->statusCheck($this->serverRequest) && $this->statusCheck($this->serverRequest, 'tid_status'));
    // Get order reference.
    $this->orderReference = $this->getOrderReference();
    $order_id = !empty($this->orderReference['order_id']) ? $this->orderReference['order_id'] : $this->serverRequest['order_no'];
    $this->order = Order::load($order_id);
    if (empty($this->order)) {
      $this->commerceNovalnetSendCriticalOrderNotFoundMail();
    }
    $this->payment_gateway = $this->order->get('payment_gateway')->entity->getPlugin()->getConfiguration();

    $this->order_details = $this->getOrderDetails($order_id);

    $this->configuration = \Drupal::config('commerce_payment.commerce_payment_gateway.' . $this->order_details['payment_gateway']);
    $this->currency_formatter = \Drupal::service('commerce_price.currency_formatter');

    $this->commerceNovalnetProcessTransactionCancel($order_id);

    // Level 0 payments - Initial payments.
    $this->initialLevelProcess();

    // Level 1 payments - Type of charge backs.
    $this->firstLevelProcess();

    // Level 2 payments - Type of credit entry.
    $this->secondLevelProcess();

    if (!$this->successStatus) {
      $this->displayMessage('Novalnet callback received. Status is not valid: Only 100 is allowed');
    }
     // After execution.
        $this->displayMessage('Novalnet Callbackscript received. Payment type ( ' . $this->serverRequest['payment_type'] . ' ) is not applicable for this process!');
  }

  /**
   * Validate required fields.
   *
   * @param array $required_params
   *   Required params.
   * @param array $request
   *   Get the server request.
   */
  public function validateRequiredFields(array $required_params, array $request) {
    foreach ($required_params as $params) {
      if (empty($request[$params])) {
        $this->displayMessage("Required param ( $params ) missing!");
      }
      elseif (in_array($params, ['tid', 'tid_payment', 'signup_tid']) && !preg_match('/^\d{17}$/', $request[$params])) {
        $this->displayMessage('Novalnet callback received. Invalid TID [ ' . $request[$params] . ' ] for Order.');
      }
    }
  }

  /**
   * Get the required TID parameter.
   *
   * @param array $request
   *   Get the server request.
   *
   * @return string
   *   The TID key to be validated.
   */
  public function getRequiredTid(array $request) {
    $shop_tid = 'tid';
    if (!empty($request['payment_type']) && in_array($request['payment_type'], array_merge($this->chargebacks, $this->collections))) {
      $shop_tid = 'tid_payment';
    }
    return $shop_tid;
  }

  /**
   * Validate and return the server request.
   *
   * @param array $request
   *   Get the server request.
   *
   * @return array
   *   The validated server request.
   */
  public function validateServerRequest(array $request) {

    $this->requiredParams[] = $shop_tid_key = $this->getRequiredTid($request);

    // Validate the callback mandatory request parameters.
    $this->validateRequiredFields($this->requiredParams, $request);

    if (!empty($request['payment_type']) && !in_array($request['payment_type'], array_merge($this->payments, $this->chargebacks, $this->collections, $this->transactionCancel))) {

      $this->displayMessage('Novalnet callback received. Payment type ( ' . $request['payment_type'] . ' ) is mismatched!');
    }
    $request['shop_tid'] = $request[$shop_tid_key];
    return $request;
  }

  /**
   * Get transaction details.
   *
   * @return object
   *   The callback details.
   */
  public function getTransactionDetails() {
    return db_select('commerce_novalnet_transaction_detail', 'order_id')
      ->fields('order_id', [
        'order_id',
        'payment_type',
        'paid_amount',
        'total_amount',
        'tid_status',
      ])
      ->condition('tid', $this->serverRequest['shop_tid'])
      ->execute()
      ->fetchAssoc();
  }

  /**
   * Get the order reference.
   *
   * @return array
   *   The order refernce.
   */
  public function getOrderReference() {
    $transaction_details = [];
    $transaction_details = $this->getTransactionDetails();
    if (empty($transaction_details)) {
      $order_id = !empty($this->serverRequest['order_no']) ? $this->serverRequest['order_no'] : '';
      if (!empty($order_id)) {
        if ($this->serverRequest['payment_type'] == 'ONLINE_TRANSFER_CREDIT') {
          $this->updateInitialPayment($order_id, FALSE);
          $transaction_details = $this->getTransactionDetails();
        }
        else {
          $this->updateInitialPayment($order_id, TRUE);
        }
      }
      else {
        $this->displayMessage('Novalnet callback script order number not valid');
      }
    }
    // Check for payment_type.
    if (empty($transaction_details) || (!empty($this->serverRequest['payment_type']) && !in_array($this->serverRequest['payment_type'], $this->paymentGroups[$transaction_details['payment_type']]))) {
      $this->displayMessage('Novalnet callback received. Payment type [ ' . $this->serverRequest['payment_type'] . '] is not valid.');
    }
    return $transaction_details;
  }

  /**
   * Callback API Level zero process.
   */
  public function initialLevelProcess() {
    if (in_array($this->serverRequest['payment_type'], $this->payments) && $this->statusCheck($this->serverRequest) && in_array($this->serverRequest['tid_status'], $this->successCode[$this->serverRequest['payment_type']])) {
      if (in_array($this->serverRequest['payment_type'], ['PAYPAL', 'PRZELEWY24']) && $this->successStatus && ((int) $this->orderReference['paid_amount'] < (int) $this->orderReference['total_amount'])) {
        $callback_comments = '<br/><br/>'. t('Novalnet Callback Script executed successfully for the TID: @tid with amount @amount on @date.', ['@tid' => $this->serverRequest['tid'],'@amount' => $this->currency_formatter->format($this->serverRequest['amount'] / 100,$this->serverRequest['currency']), '@date' => date('Y-m-d H:i:s'),]);
        $this->commerceNovalnetPaymentSave($this->payment_gateway['order_completion_status'], $this->orderReference['order_id'], $callback_comments);
        db_update('commerce_novalnet_transaction_detail')
          ->fields(['paid_amount' => $this->orderReference['paid_amount'] + $this->serverRequest['amount']])
          ->condition('tid', $this->serverRequest['shop_tid'])
          ->condition('order_id', $this->orderReference['order_id'])
          ->execute();
        // Send notification mail to the configured E-mail.
        $this->sendMailNotification($callback_comments);
      }
      elseif (in_array($this->serverRequest['payment_type'], ['GUARANTEED_INVOICE','GUARANTEED_DIRECT_DEBIT_SEPA', 'INVOICE_START', 'CREDITCARD', 'DIRECT_DEBIT_SEPA', 'PAYPAL']) &&in_array($this->serverRequest['tid_status'], [91, 99, 100])&& in_array($this->orderReference['tid_status'], [75, 91, 99, 98, 85])) {
		  $global_configuration = \Drupal::config('commerce_novalnet.application_settings');

		 $order_status = $this->payment_gateway['order_completion_status'];
         if (in_array($this->serverRequest['tid_status'], [99, 91]) && $this->orderReference['tid_status'] == 75) {
           $order_status = $global_configuration->get('commerce_novalnet_onhold_completion_status');
           $callback_comments .= '<br/>' . t('Novalnet callback received. The transaction status has been changed from pending to on hold for the TID: @tid on @date @time.',['@tid' => $this->serverRequest['shop_tid'],'@date' => date('Y-m-d H:i:s'),'@time' => date('H:i:s')]) . '<br/>';
        }
        if ($this->serverRequest['tid_status'] == 100 && in_array($this->orderReference['tid_status'],
        [75, 91, 99,98])) {
          if (in_array($this->serverRequest['payment_type'], ['GUARANTEED_INVOICE',
            'GUARANTEED_DIRECT_DEBIT_SEPA', 'INVOICE_START']) &&  $this->orderReference['tid_status'] == 75) {
            $order_status = ($this->serverRequest['payment_type'] == 'GUARANTEED_DIRECT_DEBIT_SEPA')
            ? $order_status : $this->payment_gateway['callback_order_status'];
          }          
		  $callback_comments .= '<br/><br/>' .t('Novalnet callback received. The transaction has been confirmed on @date, @time', ['@date' => date('Y-m-d'), '@time' => date('H:i:s')]);
	    }

		if($this->serverRequest['payment_type'] == 'GUARANTEED_INVOICE' && $this->serverRequest['tid_status'] == 100)  {
			$order_status = $this->payment_gateway['callback_order_status'];
		}
		$payment_config = \Drupal::config('commerce_payment.commerce_payment_gateway.'.$this->order_details['payment_gateway']);
		$novalnet_comments = '';
		$novalnet_comments .= $payment_config->get('label').'<br/>';
		$novalnet_comments = $this->novalnetLibrary->commerceNovalnetTransactionComments([
			'tid'       => $this->serverRequest['shop_tid'],
			'test_mode' => $this->serverRequest['test_mode'],
		], $this->payment_gateway, $this->orderReference['payment_gateway']);
		if($this->serverRequest['payment_type'] == 'GUARANTEED_INVOICE')  {
			$novalnet_comments .= t('This is processed as a guarantee payment') . '<br />';
		}
		$novalnet_comments = '';
		if (in_array($this->serverRequest['payment_type'], ['GUARANTEED_INVOICE', 'INVOICE_START', 'PREPAYMENT'])) {
			$novalnet_comments .= '<br/>';
		    $novalnet_comments .= $this->novalnetLibrary->commerceNovalnetInvoiceComments($this->serverRequest, TRUE);
		    $novalnet_comments .= '<br/>';
		}
		$comments = $novalnet_comments . $callback_comments;
		if($this->serverRequest['payment_type'] == 'GUARANTEED_INVOICE' && $this->orderReference['tid_status'] == 75 && in_array($this->serverRequest['tid_status'], [91, 100])) {
		 $this->guaranteeOrderConfirmationMail($comments);
		}
		$this->commerceNovalnetPaymentSave($order_status, $this->orderReference['order_id'], $comments);
		// Send notification mail to the configured E-mail.
		$this->sendMailNotification($callback_comments);
		db_update('commerce_novalnet_transaction_detail')
		 ->fields(['paid_amount' => $paid_amount, 'tid_status' => $this->serverRequest['tid_status']])
		 ->condition('tid', $this->serverRequest['shop_tid'])
		 ->condition('order_id', $this->orderReference['order_id'])
		 ->execute();
		// Send notification mail to the configured E-mail.
		$this->sendMailNotification($callback_comments);
      }
      // Handle Przelewy failure.
      elseif ($this->serverRequest['payment_type'] == 'PRZELEWY24' && !$this->successStatus && $this->serverRequest['tid_status'] != '86') {
        // Form transaction comments.
        $novalnet_comments = $this->novalnetLibrary->commerceNovalnetTransactionComments([
          'tid'       => $this->serverRequest['shop_tid'],
          'test_mode' => $this->serverRequest['test_mode'],
        ], $this->payment_gateway, $this->orderReference['payment_gateway']);
        $novalnet_comments .= PHP_EOL . t('The transaction has been canceled due to:@tid.', ['@tid' => $this->novalnetLibrary->commerceNovalnetResponseMessage($this->serverRequest)]);
        // Send notification mail to the configured E-mail.
        $this->sendMailNotification($novalnet_comments);
      }
      else {
        // After execution.
        $this->displayMessage('Novalnet Callbackscript received. Payment type ( ' . $this->serverRequest['payment_type'] . ' ) is not applicable for this process!');
      }
    }// End if().

  }

  /**
   * To form guarantee payment order confirmation mail.
   *
   * @param string $comments
   *   The order related information.
   */
  public function guaranteeOrderConfirmationMail($comments) {
    $commerce_info = system_get_info('module', 'commerce');
    $profile = $this->order->getBillingProfile();
    $address = $profile->get('address')->first()->getValue();
    $customer_name = $address['given_name'] . ' ' . $address['family_name'];
    $subject = 'Order Confirmation - Your Order '.$this->orderReference['order_id'] .' with ' .\Drupal::config('system.site')->get('name'). ' has been confirmed!';
    $body = '<body style="background:#F6F6F6; font-family:Verdana, Arial, Helvetica, sans-serif; font-size:14px; margin:0; padding:0;"><div style="width:55%;height:auto;margin: 0 auto;background:rgb(247, 247, 247);border: 2px solid rgb(223, 216, 216);border-radius: 5px;box-shadow: 1px 7px 10px -2px #ccc;"><div style="min-height: 300px;padding:20px;"><b>Dear Mr./Ms./Mrs.</b>' . $customer_name . '<br><br>';
    $body .= t('We are pleased to inform you that your order has been confirmed.');
    $body .= '<br><br><b>Payment Information:</b><br>' . $comments . '</div><div style="width:100%;height:20px;background:#00669D;"></div></div></body>';
    $send_mail = new \Drupal\Core\Mail\Plugin\Mail\PhpMail();
	$from = \Drupal::config('system.site')->get('mail');
	$to = $this->order->get('mail')->first()->value;
	$message['headers'] = array(
	'content-type' => 'text/html;charset=UTF-8',
	'MIME-Version' => '1.0',
	'reply-to' => $from,
	'from' => 'sender name <'.$from.'>'
	);
	$message['to'] = $to;
	$message['subject'] =  $subject;
	$message['body'] = $body;
	$send_mail->mail($message);
  }

  /**
   * Callback API Level 1 process.
   */
  public function firstLevelProcess() {
    if (in_array($this->serverRequest['payment_type'], $this->chargebacks) && $this->successStatus) {
      // Prepare callback comments.
      $callback_comments ='<br/><br/>'.t('Novalnet callback received. Chargeback executed successfully for the TID: @stid amount: @amount on @date The subsequent TID: @tid.', ['@stid' => $this->serverRequest['shop_tid'],'@amount' =>$this->currency_formatter->format($this->serverRequest['amount'] / 100,$this->serverRequest['currency']), '@date' => date('Y-m-d'),'@tid' => $this->serverRequest['tid']]);
      if (in_array($this->serverRequest['payment_type'], [
        'PAYPAL_BOOKBACK',
        'CREDITCARD_BOOKBACK',
        'CASHPAYMENT_REFUND',
        'REFUND_BY_BANK_TRANSFER_EU',
        'PRZELEWY24_REFUND',
        'GUARANTEED_SEPA_BOOKBACK',
        'GUARANTEED_INVOICE_BOOKBACK',
      ], TRUE)) {
        $callback_comments = t('<br/><br/>Novalnet callback received. Refund/Bookback executed successfully for the TID:@stid amount: @amount on @date. The subsequent TID: @tid.', ['@stid' => $this->serverRequest['shop_tid'],'@amount' => $this->currency_formatter->format($this->serverRequest['amount'] / 100,$this->serverRequest['currency']), '@date' => date('Y-m-d'),'@tid' => $this->serverRequest['tid']]);
        
      }
      $this->commerceNovalnetPaymentSave($this->order_details['state'],$this->orderReference['order_id'], $callback_comments);
        // Send notification mail to the configured E-mail.
        $this->sendMailNotification($callback_comments);
    }
  }

  /**
   * Callback API Level 2 process.
   */
  public function secondLevelProcess() {
    if (in_array($this->serverRequest['payment_type'], $this->collections) && $this->successStatus) {
      if (in_array($this->serverRequest['payment_type'], [
        'INVOICE_CREDIT',
        'CASHPAYMENT_CREDIT',
        'ONLINE_TRANSFER_CREDIT',
      ], TRUE)) {
        if ((int) $this->orderReference['paid_amount'] < (int) $this->orderReference['total_amount']) {
          // Prepare callback comments.
          $callback_comments = '<br/><br/>'.t('Novalnet Callback Script executed successfully for the TID: @stid with amount @amount on @date. Please refer PAID transaction in our Novalnet Merchant Administration with the TID: @tid', ['@stid' =>$this->serverRequest['shop_tid'],'@amount' => $this->currency_formatter->format($this->serverRequest['amount'] / 100,$this->serverRequest['currency']),'@date' => date('Y-m-d H:i:s'),'@tid' => $this->serverRequest['tid'],
          ]);
          // Calculate total amount.
          $paid_amount = $this->orderReference['paid_amount'] + $this->serverRequest['amount'];
          $additional_note = '';
          $order_status = $this->payment_gateway['order_completion_status'];
          // Check for full payment.
          if ((int) $paid_amount >= $this->orderReference['total_amount']) {
			  $order_status = $this->payment_gateway['callback_order_status'];
            if ($this->serverRequest['payment_type'] == 'ONLINE_TRANSFER_CREDIT') {
              $additional_note .= t('The amount of @amount for the order @order has been paid. Please verify received amount and TID details, and update the order status accordingly.',['@amount' => $this->currency_formatter->format($this->serverRequest['amount'] / 100,$this->serverRequest['currency']), '@order' => $this->order->id()]);
            }
          }
          $this->commerceNovalnetPaymentSave($order_status , $this->orderReference['order_id'], $callback_comments);
          // Update the transaction detail.
          db_update('commerce_novalnet_transaction_detail')
            ->fields(['paid_amount' => $paid_amount, 'tid_status' => $this->serverRequest['tid_status']])
            ->condition('tid', $this->serverRequest['shop_tid'])
            ->condition('order_id', $this->orderReference['order_id'])
            ->execute();
          $this->sendMailNotification($callback_comments, $additional_note);

        }
        $this->displayMessage('Novalnet callback received. Callback Script executed already. Refer Order :' . $this->orderReference['order_id']);
      }
      elseif (in_array($this->serverRequest['payment_type'], [
        'CREDIT_ENTRY_CREDITCARD',
        'DEBT_COLLECTION_CREDITCARD',
        'CREDIT_ENTRY_DE',
        'DEBT_COLLECTION_DE',
        'DEBT_COLLECTION_SEPA',
        'CREDIT_ENTRY_SEPA',
      ], TRUE)) {

        $callback_comments = '<br/><br/>'.t('Novalnet Callback Script executed successfully for the TID: @stid with amount @amount on @date. Please refer PAID transaction in our Novalnet Merchant Administration with the TID: @tid', ['@stid' => $this->serverRequest['shop_tid'],'@amount' => $this->currency_formatter->format($this->serverRequest['amount'] / 100,$this->serverRequest['currency']),'@date' => date('Y-m-d H:i:s'),'@tid' => $this->serverRequest['tid']]);

         $this->commerceNovalnetPaymentSave($this->order_details['state'], $this->orderReference['order_id'], $callback_comments);
        // Send notification mail to the configured E-mail.
        $this->sendMailNotification($callback_comments, $additional_note);
      }
      $this->displayMessage('Novalnet Callbackscript received. Payment type ( ' . $this->serverRequest['payment_type'] . ' ) is not applicable for this process!');
    }// End if().
  }

  /**
   * Function for update canceled transaction details.
   */
  public function commerceNovalnetProcessTransactionCancel() {
    if (in_array($this->serverRequest['payment_type'], $this->transactionCancel)
    && in_array($this->orderReference['tid_status'], [75, 91, 99, 85, 98])) {
      $callback_comments = '<br/><br/>' . t('Novalnet callback received. The transaction has been canceled on @date @time', ['@date' => date('Y-m-d'), '@time' => date('H:i:s')]);
	 $global_configuration = \Drupal::config('commerce_novalnet.application_settings');
     $order_status = $global_configuration->get('commerce_novalnet_onhold_cancelled_status');
     $this->commerceNovalnetPaymentSave($order_status, $this->orderReference['order_id'], $callback_comments);
      db_update('commerce_novalnet_transaction_detail')
        ->fields(['tid_status' => $this->serverRequest['tid_status']])
        ->condition('order_id', $this->orderReference['order_id'])
        ->execute();
      $this->sendMailNotification($callback_comments);
    }
  }

  /**
   * Update / initialize the payment.
   *
   * @param int $order_id
   *   The order id of the processing order.
   * @param bool $communication_failure
   *   Check for communication failure payment.
   */
  public function updateInitialPayment($order_id, $communication_failure) {
    if ($order = Order::load($order_id)) {
      $this->order_details = $this->getOrderDetails($order_id);
      $this->order = Order::load($order_id);
      $this->payment_gateway = $this->order->get('payment_gateway')->entity->getPlugin()->getConfiguration();
      $payment_gateway = $this->order->get('payment_gateway')->entity->getPluginId();
      // Check for payment_type.
      if (!empty($this->serverRequest['payment_type']) && !in_array($this->serverRequest['payment_type'], $this->paymentGroups[$payment_gateway])) {
        $this->displayMessage('Novalnet callback received. Payment type [ ' . $this->serverRequest['payment_type'] . '] is not valid.');
      }
      $novalnet_comments = 'Novalnet callback received.<br />' . $this->novalnetLibrary->commerceNovalnetTransactionComments([
        'tid'       => $this->serverRequest['shop_tid'],
        'test_mode' => $this->serverRequest['test_mode'],
      ], $this->payment_gateway, $payment_gateway);
      $order_status = $this->payment_gateway['order_completion_status'];
      if (in_array($payment_gateway, ['novalnet_invoice', 'novalnet_prepayment'])) {
        // Form bank details comment.
        $novalnet_comments .= $this->novalnetLibrary->commerceNovalnetInvoiceComments($this->serverRequest, TRUE);
      }
      if (!in_array($this->serverRequest['tid_status'], $this->successCode[$this->serverRequest['payment_type']])) {
        $novalnet_comments .= '<br/>' . $this->novalnetLibrary->commerceNovalnetResponseMessage($this->serverRequest);
        $order_status = 'canceled';

      }

      db_insert('commerce_novalnet_transaction_detail')
        ->fields(
         [
           'order_id'     => $order_id,
           'payment_type' => $payment_gateway,
           'paid_amount'  => $this->serverRequest['amount'],
           'total_amount' => $this->serverRequest['amount'],
           'tid_status'   => $this->serverRequest['tid_status'],
           'tid' => $this->serverRequest['tid'],
         ]
          )
        ->execute();
      // Complete the payment process in the shop system.
      $this->commerceNovalnetPaymentSave($order_status, $order_id, $novalnet_comments);
      if ($communication_failure) {
        // Send notification mail to the configured E-mail.
        $this->sendMailNotification($novalnet_comments);
      }
    }
  }

  /**
   * Checks the status.
   *
   * @param array $data
   *   The data to be validated.
   * @param string $key
   *   The value to be validated.
   * @param int $status
   *   The status to be validated.
   */
  public function statusCheck(array $data, $key = 'status', $status = '100') {
    return (!empty($data[$key]) && $status == $data[$key]);
  }

  /**
   * Store the order and payment details.
   *
   * @param string $callback_state
   *   The callback order state.
   * @param int $order_id
   *   The order id.
   * @param string $callback_comments
   *   The callback comments.
   */
  public function commerceNovalnetPaymentSave( $callback_state, $order_id,  $callback_comments) {
    $order = Order::load($order_id);
    $order->getState()->value = $callback_state;
    $order->state = $callback_state;

    $comments = $order->getData('transaction_details')['message'];
    $order->setData('transaction_details', ['message' => $comments . $callback_comments]);
    $order->save();

    $amount = ($this->serverRequest['amount'] / 100); 
    $string = sprintf("%.2f", $amount);

    $this->price = new Price($string, $this->serverRequest['currency']);
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => $callback_state,
      'amount' => $this->price,
      'payment_gateway' => $this->order_details['payment_gateway'],
      'order_id' => $order->id(),
      'remote_id' => $this->serverRequest['tid'],
      'remote_state' => $this->serverRequest['tid_status'] ,
    ]);
    $payment->save();
  }

  /**
   * To get the order related details.
   *
   * @param int $order_id
   *   The order id.
   */
  public function getOrderDetails($order_id) {
    return db_select('commerce_order', 'order_id')
      ->fields('order_id', [
        'mail',
        'state',
        'payment_gateway',
      ])
      ->condition('order_id', $order_id)
      ->execute()
      ->fetchAssoc();
  }

  /**
   * Display the callback messages.
   *
   * @param string $message
   *   The callback message for the executed process.
   * @param int $order_number
   *   The order number.
   */
  public function displayMessage($message, $order_number = FALSE) {
    echo ($order_number) ? 'message=' . $message . '&order_no=' . $order_number : $message;
    exit;
  }

  /**
   * Send notification mail.
   *
   * @param string $comments
   *   Formed comments.
   * @param string $additional_note
   *   Additional note.
   */
  public function sendMailNotification($comments, $additional_note = '') {
    $commerce_info        = system_get_info('module', 'commerce');
    $toAddress  = '';
    $bccAddress  = '';
    $enableCallbackMail   = \Drupal::config('commerce_novalnet.application_settings')->get('enable_callback_mail');
    if($enableCallbackMail) {
		$toAddress            = \Drupal::config('commerce_novalnet.application_settings')->get('callback_mail_to_address');
	    $bccAddress           = \Drupal::config('commerce_novalnet.application_settings')->get('callback_mail_bcc_address');
    }
    $send_mail = new \Drupal\Core\Mail\Plugin\Mail\PhpMail();
	$from = \Drupal::config('system.site')->get('mail');
    $message['headers'] = array(
	'content-type' => 'text/html;charset=UTF-8',
	'MIME-Version' => '1.0',
	'reply-to' => $from,
	'from' => 'sender name <'.$from.'>'
	);
	$message['to'] = $toAddress;
	$message['subject'] =  t('Novalnet Callback script notification - @sitename', ['@sitename' => \Drupal::config('system.site')->get('name')]);
	if($bccAddress) {
		$params['bcc_mail'] = explode(",", $bccAddress);
		$abcc = '';
		foreach ($params['bcc_mail'] as $abcc => $svalBcc) {
			$svalBcc = trim($svalBcc);
			$valid_bcc = \Drupal::service('email.validator')->isValid($svalBcc);
			if ($valid_bcc == TRUE) {
			$message['headers']['Bcc'] = $svalBcc;
			}
		}
	}
	$message['body'] = $comments;
	$send_mail->mail($message);
    $this->displayMessage($comments . $additional_note, $this->serverRequest['order_no']);
  }

  /**
   * Function for send critical error notification mail.
   */
  public function commerceNovalnetSendCriticalOrderNotFoundMail() {
    $params[] = '';
    $subject = t('Critical error on shop system @shop_name: order not found for TID: @tid', ['@tid' => $this->serverRequest['tid'], '@shop_name' => \Drupal::config('system.site')->get('name')]);
    $message = t('Dear Technic team,<br/> Please evaluate this transaction and contact our payment module team at Novalnet.<br/>Merchant ID : @vendor_id <br/>Project ID : @product<br/>TID : @tid <br/> TID status : @tid_status <br/> Order no : @order_no <br/> Payment type : @payment <br/> E-mail : @admin_email <br/><br/>Regards,<br/>Novalnet Team', [
      '@vendor_id' => $this->serverRequest['vendor_id'],
      '@product' => $this->serverRequest['product_id'] ,
      '@tid' => $this->serverRequest['shop_tid'],
      '@tid_status' => $this->serverRequest['tid_status'],
      '@order_no' => $this->serverRequest['order_no'],
      '@payment' => $this->serverRequest['payment_type'],
      '@admin_email' => \Drupal::config('system.site')->get('mail'),
    ]);
    $params['message'] = $message;
    $params['node_title'] = $subject;
    $params['headers'] = [];
    \Drupal::service('plugin.manager.mail')->mail('commerce_novalnet', 'novalnet_callback', $this->toAddress, \Drupal::currentUser()->getPreferredLangcode(), $params, NULL, TRUE);
    $this->displayMessage('Novalnet callback received. Transaction Mapping Failed');
  }

}
