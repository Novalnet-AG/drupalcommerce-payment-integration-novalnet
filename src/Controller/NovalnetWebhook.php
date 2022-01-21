<?php
/**
 * Contains the Novalnet webhook related process
 *
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.com/payment-plugins/free/license
 * @version    1.0.0
 */

namespace Drupal\commerce_novalnet\Controller;

use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\Entity\Payment;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_novalnet\Novalnet;
use Drupal\commerce_price\Price;

/**
 * Defines the Novalnet Webhook.
 *
 * @class Novalnet
 */
class NovalnetWebhook extends ControllerBase {
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
   * Mandatory Parameters.
   *
   * @var array
   */
  protected $mandatoryParams = array(
                            'event'       => array(
                                'type',
                                'checksum',
                                'tid',
                            ),
                            'merchant'    => array(
                                'vendor',
                                'project',
                            ),
                            'result'      => array(
                                'status',
                            ),
                            'transaction' => array(
                                'tid',
                                'payment_type',
                                'status',
                            ),
                        );
  /**
   * Request parameters
   *
   * @var array
   */
  protected $eventData = [];
  /**
   * Order reference values.
   *
   * @var array
   */
  protected $orderReference = [];
   /**
   * Order reference values.
   *
   * @var array
   */
  protected $order_details = [];
    /**
   * Parent tid.
   *
   * @var integer
   */
  protected $parent_tid;
  /**
   * Callback test mode.
   *
   * @var int
   */
  protected $allow_webhook_test;
  /**
   * Novalnet library reference.
   *
   * @var bool
   */
  protected $novalnetLibrary;
   /**
   * Global configuration
   *
   * @var Object
   */
  protected $global_configuration;
   /**
   * Currency Formatter.
   *
   * @var Object
   */
  protected $currency_formatter;
  /**
   * Callback api process.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Loads the server request and initiates the callback process.
   */
   public function process(Request $request) {

     // Get global configuration value
     $this->global_configuration = \Drupal::config('commerce_novalnet.application_settings');
     $this->currency_formatter = \Drupal::service('commerce_price.currency_formatter');
     // Get request data
     $request = json_decode(file_get_contents('php://input'), true);
     $this->eventData = $request;
     $get_host_name  = gethostbyname('pay-nn.de');
     $client_ip_address = Novalnet::getIpAddress();
     if (empty($get_host_name)) {
       $this->displayMessage('Novalnet HOST IP missing');
     }
    if ($client_ip_address != $get_host_name && !$this->global_configuration->get('callback_test_mode')) {
      $this->displayMessage('Unauthorised access from the IP ' . $client_ip_address);
    }
    $this->validateRequestParameter($this->eventData, $this->global_configuration->get('access_key'));
    // Get order reference
    $this->orderReference = $this->getOrderReference($this->eventData);
    $this->parent_tid = !empty($this->eventData['event']['parent_tid'])
    ?$this->eventData['event']['parent_tid']:$this->eventData['event']['tid'];
    $order_id = !empty($this->orderReference['order_id'])?$this->orderReference['order_id']
    :(!empty($this->eventData['transaction']['order_no'])?$this->eventData['transaction']['order_no']:'');
    $this->order = empty($order_id) ? '' : Order::load($order_id);
    $this->payment_gateway = $this->order->get('payment_gateway')->entity->getPlugin()->getConfiguration();
    $this->order_details = $this->getOrderDetails($order_id);
    $this->configuration = \Drupal::config('commerce_payment.commerce_payment_gateway.' . $this->order_details['payment_gateway']);
    $this->currency_formatter = \Drupal::service('commerce_price.currency_formatter');
    $this->commercePaymentEventHandler($parent_tid);
   }
   /**
   * Handle the payment event.
   * @param int $parent_tid
   *   Get the parent tid
   */
   public function commercePaymentEventHandler($parent_tid) {

     switch ($this->eventData['event']['type']) {
      case 'PAYMENT':
        $this->displayMessage(t('The webhook notification received ') . '(' .
                                $this->eventData['event']['type'] . ')' . t('for the TID: '). $this->eventData['event']['tid']);
        break;
      case 'TRANSACTION_CAPTURE':
        $this->handleTransactionCapture();
        break;
      case 'TRANSACTION_CANCEL':
        $this->handleTransactionCancellation();
        break;
      case 'TRANSACTION_REFUND':
        $this->handleTransactionRefund();
        break;
      case 'TRANSACTION_UPDATE':
        $this->handleTransactionUpdate();
        break;
      case 'CREDIT':
        $this->handleTransactionCredit();
        break;
      case 'CHARGEBACK':
        $this->handleTransactionChargeBack();
        break;
      default:
        $this-> displayMessage('The webhook notification has been received for the unhandled EVENT type'.
        $this->eventData['event']['type']);
     }
   }
   /**
   * Handle transaction credit
   *
   * @return void
   *
   */
   public function handleTransactionCredit() {
      $transaction_array = array(
                '@otid' => $this->eventData['event']['parent_tid'],
                '@tid' => $this->eventData['event']['tid'],
                '@amt'  => $this->currency_formatter->format($this->eventData['transaction']['amount'] / 100,
                                                             $this->eventData['transaction']['currency']),
              );
      if (in_array($this->eventData['transaction']['payment_type'], array('INVOICE_CREDIT', 'CASHPAYMENT_CREDIT', 'MULTIBANCO_CREDIT', 'PREPAYMENT'), true)) {
        $order_credit = $this->order_details ['total_paid__number'] * 100;
        $order_total = $this->orderReference ['amount__number'] * 100;
        $order_refund = $this->orderReference ['refunded_amount__number'] * 100;
        if ($order_credit <  $order_total) {
            $callback_information = t('Credit has been successfully received for the TID : @otid with amount @amt.
            Please refer PAID order details in our Novalnet Admin Portal for the TID @tid', $transaction_array);
            // Calculate total amount.
            $paid_amount = $order_credit + $this->eventData['transaction']['amount'];
            $amount = (float) sprintf("%.2f", $paid_amount/ 100);
            $this->order->setTotalPaid(new Price($amount, $this->eventData['transaction']['currency']));
            $this->order->save();
            // Calculate including refunded amount.
            $remaining_amount = $order_total - $order_refund;
            // update the amount and status
            $order_state = $this->orderReference['state'];
           if ($paid_amount >=  $remaining_amount) {

                // Update callback
                $order_state = 'completed';
            }
            $field_value = array('state' => $order_state);

            $this->updatedetails('commerce_payment', $field_value, 'remote_id', $this->parent_tid);
            $transaction_details = $this->order->getData('transaction_details')['message'];
            $this->order->setData('transaction_details', ['message' => $transaction_details .'<br />'.'<br />'. $callback_information]);
            $this->order->save();
        }
        else {
             $callback_information = 'Order Already Paid';
        }
      }
      else {
         $order_credit = $this->order_details ['total_paid__number'] *100;
         $paid_amount = $order_credit + $this->eventData['transaction']['amount'];
         $callback_information = t('Credit has been successfully received for the TID : @otid with amount @amt.
         Please refer PAID order details in our Novalnet Admin Portal for the TID @tid', $transaction_array);
         $amount = (float) sprintf("%.2f", $paid_amount/ 100);
         $this->order->setTotalPaid(new Price($amount, $this->eventData['transaction']['currency']));
         $this->order->save();
         $order_state = 'completed';
         $field_value = array('state' => $order_state);
         $this->updatedetails('commerce_payment', $field_value, 'remote_id', $this->parent_tid);
         $transaction_details = $this->order->getData('transaction_details')['message'];
         $this->order->setData('transaction_details', ['message' => $transaction_details .'<br />'.'<br />'. $callback_information]);
         $this->order->save();
      }
      // Send notification mail to the configured E-mail.

      $this->sendMailNotification($callback_information, $this->orderReference['order_id']);
      $this->displayMessage($callback_information);
   }
   /**
   * Handle transaction update
   *
   * @return void
   *
   */
   public function handleTransactionUpdate() {
       if ($this->eventData['transaction']['update_type'] == 'STATUS') {
           if (in_array($this->eventData['transaction']['status'], array('PENDING', 'ON_HOLD', 'CONFIRMED', 'DEACTIVATED'), true)) {
               if ('DEACTIVATED' === $this->eventData['transaction']['status']) {
                   $order_status = 'cancelled';
                   $callback_information = t('The transaction has been cancelled on @date',['@date' => date('d.m.Y')]);
                   $field_value = array('state' => $order_status);
                   $this->updatedetails('commerce_payment', $field_value, 'remote_id', $this->parent_tid);
                   // Send notification mail to the configured E-mail.
                   $this->sendMailNotification($callback_information, $this->orderReference['order_id']);
               }
               else {
                    if ('ON_HOLD' == $this->eventData['transaction']['status']) {
                        if (in_array($this->eventData ['transaction']['payment_type'], array('INVOICE','GUARANTEED_INVOICE'))
                            && empty($this->eventData ['transaction']['bank_details'])
                            && !empty($this->orderReference ['transaction_details'])) {
                            $this->eventData ['transaction']['bank_details'] = $this->orderReference ['transaction_details'];
                        }
                        $order_status = 'authorization';
                        $transaction_parameter = array(
                                '@tid'  => $this->eventData['transaction']['tid'],
                                '@date' => date('d.m.Y'),
                                '@time' => date('H:i:s'),
                          );
                        $callback_information = t('The transaction status has been changed from pending
                        to on hold for the TID: @tid on @date @time'
                                                   ,$transaction_parameter);
                        if (in_array($this->eventData['transaction']['payment_type'], array('INVOICE', 'GUARANTEED_INVOICE'))) {
                          $callback_information .= Novalnet::formBankDetails($this->eventData,true);
                        }
                        $field_value = array('state' => $order_status);
                        $this->updatedetails('commerce_payment', $field_value, 'remote_id', $this->parent_tid);
                    }
                    elseif ('CONFIRMED' == $this->eventData['transaction']['status']) {
                        $order_status = 'completed';
                        $transaction_parameter = array(
                                '@tid' => $this->eventData['transaction']['tid'],
                                '@date' => date('d.m.Y'),
                                '@time' => date('H:i:s'),
                          );
                        $callback_information  = t('The transaction has been updated succesfully for the tid @tid on @date @time', $transaction_parameter);
                        if (in_array($this->eventData ['transaction']['payment_type'], array('INVOICE', 'GUARANTEED_INVOICE'))
                            && empty($this->eventData['transaction']['bank_details']) && !empty($this->orderReference ['transaction_details'])) {
                            $this->eventData ['transaction']['bank_details'] = $this->orderReference ['transaction_details'] ;
                        }
                        elseif ($this->orderReference ['tid_status'] == 75  && 'CONFIRMED' == $this->eventData['transaction']['status']) {
                            $callback_information .= Novalnet::formTransactionDetails(['tid' => $this->eventData['event']['parent_tid'],
                            'test_mode' => $this->eventData['transaction']['test_mode']],$this->payment_gateway, $payment_gateway);
                            if ($this->eventData ['transaction']['payment_type'] == 'GUARANTEED_INVOICE') {
                              $callback_information .= Novalnet::formBankDetails($this->eventData, true);
                            }
                        }
                        $field_value = array('state' => $order_status);
                        $this->updatedetails('commerce_payment', $field_value, 'remote_id', $this->parent_tid);
                    }
                    elseif ('PENDING' == $this->eventData['transaction']['status']) {
                        $order_status = 'pending';
                        $field_value = array('state' => $order_status);
                        $this->updatedetails('commerce_payment', $field_value, 'remote_id', $this->parent_tid);
                    }
                    if (!empty($callback_information)) {
                      $this->sendMailNotification($callback_information, $this->orderReference['order_id']);
                    }
                    // Reform the transaction comments.
                    if (in_array($this->eventData['transaction']['payment_type'], array('INVOICE', 'PREPAYMENT', 'GUARANTEED_INVOICE'))) {
                        if (empty($this->eventData['transaction']['bank_details'])) {
                                $this->eventData['transaction']['bank_details'] =  $this->orderReference ['transaction_details'] ;
                                $this->order->setData('transaction_details', ['message' => $transaction_details .'<br />'.'<br />'. $this->eventData['transaction']['bank_details']]);
                                $this->order->save();
                        }
                    }
                    elseif ('CASHPAYMENT' === $this->eventData['transaction']['payment_type']) {
                        if (empty($this->eventData['transaction']['nearest_stores'])) {
                            $this->eventData['transaction']['nearest_stores'] = $this->orderReference ['transaction_details'];
                            $this->order->setData('transaction_details', ['message' => $transaction_details .'<br />'.'<br />'. $this->eventData['transaction']['nearest_stores']]);
                            $this->order->save();
                        }
                    }
                }
            }
       }
       elseif (in_array($this->eventData['transaction']['update_type'] , array('DUE_DATE','AMOUNT_DUE_DATE'))) {
           $transaction_array = array(
                '@tid'  => $this->eventData['event']['tid'],
                '@amt'  => $this->currency_formatter->format($this->eventData['transaction']['amount']/100, $this->eventData['transaction']['currency']),
                '@date' =>  $this->eventData['transaction']['due_date'],
             );
           $callback_information = t('Transaction updated successfully for the TID @tid  with amount @amt
           and due date @date', $transaction_array);
           if (in_array($this->eventData['transaction']['payment_type'], array('INVOICE', 'PREPAYMENT', 'GUARANTEED_INVOICE'))) {
               $callback_information .= Novalnet::formBankDetails($this->eventData, true);
           }
           // Send notification mail to the configured E-mail.
           $this->sendMailNotification($callback_information, $this->orderReference['order_id']);
       }
       elseif ($this->eventData['transaction']['update_type'] == 'AMOUNT') {
           $transaction_array = array(
                '@tid'  => $this->eventData['event']['tid'],
                '@amt'  => $this->currency_formatter->format($this->eventData['transaction']['amount']/100,
                           $this->eventData['transaction']['currency']),
             );
           $callback_information = t('Transaction updated successfully for the TID @tid  with amount @amt', $transaction_array);
           $field_value = array('amount__number' => sprintf('%.2f',$this->eventData['transaction']['amount']/100));
           $this->updatedetails('commerce_payment', $field_value, 'remote_id', $this->parent_tid);

           // Send notification mail to the configured E-mail.
           $this->sendMailNotification($callback_information, $this->orderReference['order_id']);
           $field_value = array('total_price__number' => sprintf('%.2f',$this->eventData['transaction']['amount']/100));
           $this->updatedetails('commerce_order', $field_value, 'order_id', $this->orderReference['order_id']);
       }
      $transaction_details = $this->order->getData('transaction_details')['message'];
      $this->order->setData('transaction_details', ['message' => $transaction_details .'<br />'.'<br />'. $callback_information]);
      $this->order->save();
       $this->displayMessage($callback_information);
   }

  /**
   * Handle transaction charge back
   *
   * @return void
   *
  */
   public function handleTransactionChargeBack() {
       if (!empty($this->eventData['transaction']['amount'])) {
         $transaction_array = array(
                '@otid' => $this->parent_tid,
                '@amt'  => $this->currency_formatter->format($this->eventData['transaction']['amount']/100,
                           $this->eventData['transaction']['currency']),
                '@date' => date('d.m.Y H:i:s'),
                '@tid'  => $this->eventData['event']['tid'],
              );
         $callback_information = t('Chargeback executed successfully for the TID : @otid amount: @amt on @date.
                                     The subsequent TID: @tid', $transaction_array);
         $transaction_details = $this->order->getData('transaction_details')['message'];
         $this->order->setData('transaction_details', ['message' => $transaction_details .'<br />'.'<br />'. $callback_information]);
         $this->order->save();
         // Send notification mail to the configured E-mail.
         $this->sendMailNotification($callback_information, $this->orderReference['order_id']);
         $this->displayMessage($callback_information);
        }
   }
   /**
   * Handle transaction refund
   *
   * @return void
   *
   */
   public function handleTransactionRefund() {
       if (!empty($this->eventData['transaction']['refund']['amount'])) {
          $transaction_parameter = array(
            '@orgtid' => $this->eventData['event']['parent_tid'],
            '@amt'  => $this->currency_formatter->format($this->eventData['transaction']['refund']['amount']/100,
                       $this->eventData['transaction']['currency']),
            '@date' => date('d.m.Y H:i:s'),
            '@tid'  => $this->eventData['event']['tid'],
          );
          if(isset($this->eventData['transaction']['refund']['tid']) && !empty($this->eventData['transaction']['refund']['tid'])){
            $callback_information = t('Refund has been initiated for the TID: @orgtid with the amount @amt . New tid:@tid', $transaction_parameter);
	      }
	      else{
			$callback_information = t('Refund has been initiated for the TID: @orgtid with the amount @amt ', $transaction_parameter);
	      }
          $old_refund_amount = $this->getTransactionDetails($this->parent_tid);
          $new_refund_amount = $old_refund_amount['refunded_amount__number']*100 + $this->eventData['transaction']['refund']['amount'];
          $amount = (float) sprintf("%.2f", $new_refund_amount/ 100);
          $transaction_details = $this->order->getData('transaction_details')['message'];
          $this->order->setData('transaction_details', ['message' => $transaction_details .'<br />'.'<br />'. $callback_information]);
          $this->order->save();
          \Drupal::database()->update('commerce_payment')
          ->fields(array('refunded_amount__number' => $amount))
          ->condition('remote_id', $this->eventData['event']['parent_tid'])
          ->execute();

          if($this->eventData['transaction']['amount'] > $new_refund_amount){
             $field_value = array('state' => 'partialy_refunded');
             $this->updatedetails('commerce_payment', $field_value, 'remote_id', $this->parent_tid);
           }
          else{
			 if(in_array($this->eventData['transaction']['payment_type'],array('INVOICE','GUARANTEED_INVOICE','DIRECT_DEBIT_SEPA','GUARANTEED_DIRECT_DEBIT_SEPA','PREPAYMENT'))){
			    if($response['transaction']['status'] == 'DEACTIVATED'){
			      $order_state = 'voided';
			     }
			     else{
				    $order_state = 'refunded';
				 }
		     }
		     else{
			   $order_state = 'refunded';
		     }
			 $field_value = array('state' => $order_state);
             $this->updatedetails('commerce_payment', $field_value, 'remote_id', $this->parent_tid);
	  	  }
          // Send notification mail to the configured E-mail.
          $this->sendMailNotification($callback_information, $this->orderReference['order_id']);
          $this->displayMessage($callback_information);
        }
   }
   /**
   * Handle transaction cancellation
   *
   * @return void
   *
   */
   public function handleTransactionCancellation() {
        $callback_information = '<br/>' . t('The transaction has been canceled on @date @time', ['@date' => date('Y-m-d'), '@time' => date('H:i:s')]);
        $order_status = 'cancelled';
        $field_value = array('state' => $order_status);
        $this->updatedetails('commerce_payment', $field_value, 'remote_id', $this->parent_tid);
        $transaction_details = $this->order->getData('transaction_details')['message'];
        $this->order->setData('transaction_details', ['message' => $transaction_details .'<br />'.'<br />'. $callback_information]);
        $this->order->save();
        $this->sendMailNotification($callback_information, $this->orderReference['order_id']);
        $this->displayMessage($callback_information);
   }
   /**
   * Handle transaction capture
   *
   * @return void
   *
   */
   public function handleTransactionCapture() {
      $callback_information = t('The transaction has been confirmed on @date, @time', ['@date' => date('Y-m-d'), '@time' => date('H:i:s')]);
      $order_status = (in_array($this->eventData['transaction']['payment_type'], array('INVOICE')))?'pending':'completed';
      if (in_array($this->eventData ['transaction']['payment_type'], array('INVOICE', 'GUARANTEED_INVOICE'))) {
        $callback_information .= '<br/><br/>'.str_replace(t('Please transfer the amount to the below mentioned account details of our payment processor Novalnet'),
                                 t('Please transfer the amount to the below mentioned account details of our payment processor Novalnet').'<br/>'.
                                 t('Due date: @due_date', ['@due_date' => $this->eventData['transaction']['due_date']]).
                                 '<br/>', unserialize($this->order_details['data'])['transaction_details']['message']);
      }
      $field_value = array('state' => $order_status);
      $this->updatedetails('commerce_payment', $field_value, 'remote_id', $this->parent_tid);
      $transaction_details = $this->order->getData('transaction_details')['message'];
      $this->order->setData('transaction_details', ['message' => $transaction_details .'<br />'.'<br />'. $callback_information]);
      $this->order->save();
      $this->sendMailNotification($callback_information, $this->orderReference['order_id']);
      $this->displayMessage($callback_information);
   }
   /**
   * Get the order reference.
   *
   * @param $request
   *
   * @return array
   *   The order refernce.
   */
  public function getOrderReference($request) {
   $transaction_details = [];
	$parent_tid = !empty($request['event']['parent_tid']) ? $request['event']['parent_tid'] : $request['event']['tid'];
	$transaction_details = $this->getTransactionDetails($parent_tid);
	if(empty($transaction_details)){
		$order_id = !empty($request['transaction']['order_no']) ? $request['transaction']['order_no'] : '';
		if($request['transaction']['payment_type'] == 'ONLINE_TRANSFER_CREDIT'){
		  $this->updateInitialPayment($order_id, $request);
		  $transaction_details = $this->getTransactionDetails($parent_tid);
		}
		else{
		  if (!empty($order_id)) {
		    if ($request['event']['type'] == 'PAYMENT') {
		     $this->updateInitialPayment($order_id, $request);
		   }
		    else {
		      $this->displayMessage("Event type mismatched for TID ". $request['event']['tid']);
		    }
		  }
		  else {
		  $this->displayMessage('Order number is not valid');
		  }
		}
	}
return $transaction_details;
  }
   /**
   * To get the order related details.
   *
   * @param int $order_id
   *   The order id.
   */
  public function getOrderDetails($order_id) {
    return \Drupal::database()->select('commerce_order', 'order_id')
      ->fields('order_id', [
        'mail',
        'payment_gateway',
        'total_paid__number',
        'data',
      ])
      ->condition('order_id', $order_id)
      ->execute()
      ->fetchAssoc();
  }
  public function updatedetails($table, $field_value, $condition, $condition_value){
    return \Drupal::database()->update($table)
          ->fields($field_value)
          ->condition($condition, $condition_value)
          ->execute();
  }
   /**
   * Get transaction details.
   *
   * @param $parent_tid
   *
   * @return object
   *   The callback details.
   */
  public function getTransactionDetails($parent_tid) {
    return \Drupal::database()->select('commerce_payment', 'order_id')
      ->fields('order_id', [
        'order_id',
        'state',
        'payment_gateway',
        'remote_state',
        'amount__number',
        'refunded_amount__number',
      ])
      ->condition('remote_id', $parent_tid)
      ->execute()
      ->fetchAssoc();
  }
  /**
   * Update / initialize the payment.
   *
   * @param int $order_id
   *   The order id of the processing order.
   * @param array $request
   *   Get the request params.
   */
  public function updateInitialPayment($order_id, $request) {

    if ($order = Order::load($order_id)) {
		$this->order_details = $this->getOrderDetails($order_id);
		$this->order = Order::load($order_id);
		$this->payment_gateway = $this->order->get('payment_gateway')->entity->getPlugin()->getConfiguration();
		$payment_gateway = $this->order->get('payment_gateway')->entity->getPluginId();
		$data = '<br />'.Novalnet::formTransactionDetails($request, $this->payment_gateway, $payment_gateway);
		$order_status = 'completed';
		if ($request['transaction']['payment_type'] == 'CASHPAYMENT') {
		$data .= Novalnet::formStoreDetails($request);
		$order_status = 'pending';
		}
		if (in_array($request['transaction']['payment_type'], array('INVOICE', 'PREPAYMENT', 'GUARANTEED_INVOICE'))) {
		$order_status = 'pending';
		$data .= Novalnet::formBankDetails($request, true);
		}
		if ($request['result']['status'] != 'SUCCESS' && $request['result']['status_code'] != 100) {
		$data .= '<br/>' . Novalnet::responseMessage($request);
		$order_status = 'canceled';
		}
		$redirectPayments = ['novalnet_paypal', 'novalnet_ideal', 'novalnet_giropay',
		'novalnet_eps', 'novalnet_przelewy24', 'novalnet_sofort','novalnet_bancontact','novalnet_postfinance','novalnet_postfinancecard'
		];

		$this->order->setData('transaction_details', ['message' => '<br />'.$data]);

		$this->order->save();

		$parent_tid = !empty($request['event']['parent_tid']) ? $request['event']['parent_tid'] : $request['event']['tid'];
		// Complete the payment process in the shop system.
		$this->commerceNovalnetPaymentSave($order_status, $order_id, $data, $request);
		// Send notification mail to the configured E-mail.
		$this->sendMailNotification($data, $this->orderReference['order_id']);
		}
  }

   /**
   * Store the order and payment details.
   *
   * @param string $callback_state
   *   The callback order state.
   * @param int $order_id
   *   The order id.
   * @param string $callback_information
   *   The callback comments.
   * @param string $request
   *   The callback request parameter.
   */
  public function commerceNovalnetPaymentSave($callback_state, $order_id, $callback_information = '', $request_data, $credit = false) {
    $order = Order::load($order_id);
    $order->getState()->value = $callback_state;
    $order->state = $callback_state;
    $transaction_details = $order->getData('transaction_details')['message'];
    $order->setData('transaction_details', ['message' => $callback_information]);
    $order->save();
    $amount = ($request_data['transaction']['amount']/100);
    $string = sprintf("%.2f", $amount);
    $currency =  !empty($request_data['transaction']['currency']) ? $request_data['transaction']['currency'] : 'EUR';
    $this->price = new Price($string, $currency);
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => $callback_state,
      'amount' => $this->price,
      'payment_gateway' => $this->order_details['payment_gateway'],
      'order_id' => $order->id(),
      'remote_id' => !empty($request_data['event']['parent_tid'])?$request_data['event']['parent_tid']:$request_data['event']['tid'],
      'remote_state' => $request_data['transaction']['status'],
    ]);
    $payment->save();
  }
   /**
   * Send notification mail.
   *
   * @param string $information
   *   Formed comments.
   * @param string $additional_note
   *   Additional note.
   */
  public function sendMailNotification($information, $additional_note = '') {
    $toAddress  = '';
    $toAddress  = \Drupal::config('commerce_novalnet.application_settings')->get('callback_mail_to_address');
    $send_mail = new \Drupal\Core\Mail\Plugin\Mail\PhpMail();
    $from = \Drupal::config('system.site')->get('mail');
    $message['headers'] = array(
    'content-type' => 'text/html;charset=UTF-8',
    'MIME-Version' => '1.0',
    'reply-to' => $from,
    'from' => 'sender name <'.$from.'>'
    );
    $message['subject'] = t('Novalnet Callback script notification - @sitename', ['@sitename' => \Drupal::config('system.site')->get('name')]);
    if ($toAddress) {
        $params['to_mail'] = explode(",", $toAddress);
        $abcc = '';
        foreach ($params['to_mail'] as $abcc => $svalBcc) {
            $svalBcc = trim($svalBcc);
            $valid_bcc = \Drupal::service('email.validator')->isValid($svalBcc);
            if ($valid_bcc == true) {
              $message['to'] = $svalBcc;
              $message['body'] = $information;
              $send_mail->mail($message);
            }
        }
    }
  }

   /**
   * Validate Request Parameter.
   *
   * @param array $request
   *   Get the server request.
   * @param string $access_key
   *   Get the access key.
   *
   * @return void
   */
   public function validateRequestParameter($request, $access_key) {
      // Validate required parameter
      foreach ($this->mandatoryParams as $category => $parameters) {
        if (empty($request [$category])) {
          // Could be a possible manipulation in the notification data
          $this->displayMessage("Required parameter category($category) not received");
        }
        foreach ($parameters as $parameter) {
          if (empty($request [$category] [$parameter])) {
          // Could be a possible manipulation in the notification data
            $this->displayMessage(t("Required parameter($parameter) in the category($category) not received"));
          } elseif (in_array($parameter, array('tid'), true) && !preg_match('/^\d{17}$/', $request[$category][$parameter])) {
            $this->displayMessage("Invalid TID received in the category($category) not received $parameter");
          }
        }
      }
      // Validate the received checksum.
      $this->validateChecksum($access_key, $request);
      // Validate TID's from the event data
      if (!preg_match('/^\d{17}$/', $request['event']['tid'])) {
        $this->displayMessage("Invalid event TID: ".$request ['event'] ['tid']." received for the event(".$request['event']['type'].")");
      }
      elseif ($request['event']['parent_tid'] && !preg_match('/^\d{17}$/', $request ['event']['parent_tid'])) {
        $this->displayMessage("Invalid event TID: ".$request ['event']['parent_tid'] ." received for the event(". $request['event']['type'] .")");
      }
   }
   /**
   * Validate checksum
   *
   * @param string $access_key
   *   Global accesskey
   * @param array $event_data
   *   event array.
   */
  public function validateChecksum($access_key, $event_data) {
    $token_string  = $event_data ['event']['tid'] . $event_data ['event']['type'] . $event_data ['result']['status'];
    if (isset($event_data ['transaction']['amount'])) {
      $token_string .= $event_data ['transaction'] ['amount'];
    }
    if (isset($event_data ['transaction']['currency'])) {
      $token_string .= $event_data ['transaction']['currency'];
    }
    if (!empty($access_key)) {
      $token_string .= strrev($access_key);
    }
    $generated_checksum = hash('sha256', $token_string);
    if ($generated_checksum !== $event_data ['event']['checksum']) {
      $this->displayMessage("While notifying some data has been changed. The hash check failed");
    }
  }
   /**
    * Display message
    *
    * @return void
    *
    */
  public function displayMessage($message) {
    echo $message;
    exit;
  }


}
?>
