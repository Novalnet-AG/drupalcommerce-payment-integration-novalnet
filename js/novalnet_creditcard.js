/**
 * This file process the novalnet cc related process
 *  
 * @category   PHP
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.0.1
 */
(function ($, Drupal) {
  'use strict';
    Drupal.behaviors.commerceNovalnetCreditCard = {
        target_orgin : 'https://secure.novalnet.de',
        error_message : '',
        attach: function (context, settings) {
            Drupal.behaviors.commerceNovalnetCreditCard.process();
        },
        process: function () {
            jQuery('#edit-actions-next').click(function (e) {
                var iframe = Drupal.behaviors.commerceNovalnetCreditCard.getIframeContent();
                var pan_hash = jQuery("input[name='payment_information[add_payment_method][payment_details][pan_hash]']");
                if(pan_hash.val() != undefined && pan_hash.val() == '' && Drupal.behaviors.commerceNovalnetCreditCard.error_message == '') {
                    e.preventDefault();
                    // Call the postMessage event for getting the hash.
                    iframe.postMessage( JSON.stringify( {
                        callBack : 'getHash'
                    } ), Drupal.behaviors.commerceNovalnetCreditCard.target_orgin );
                }
                else {
                    if(Drupal.behaviors.commerceNovalnetCreditCard.error_message != '') {
                        e.stopImmediatePropagation();
                        e.preventDefault();
                        iframe.postMessage( JSON.stringify( { 
                            callBack : 'getHash'
                        } ), Drupal.behaviors.commerceNovalnetCreditCard.target_orgin );
                    }
                }
            });

            if ( window.addEventListener ) {
                // addEventListener works for all major browsers.
                window.addEventListener('message', function(event) {
                    Drupal.behaviors.commerceNovalnetCreditCard.addEvent( event );
                });
            } else {
                // attachEvent works for IE8.
                window.attachEvent('onmessage', function (event) {
                    Drupal.behaviors.commerceNovalnetCreditCard.addEvent( event );
                });
            }
        },
        getIframeContent: function () {
            var iframe = $('#novalnet-cc-iframe')[0];
            return iframe.contentWindow ? iframe.contentWindow : iframe.contentDocument.defaultView;
        },        
        loadCcIframe: function(e) {
            var iframe = Drupal.behaviors.commerceNovalnetCreditCard.getIframeContent();
            var create_element = {
                callBack : 'createElements',
                customStyle : {
                    labelStyle : drupalSettings.commerce_novalnet.novalnet_creditcard.css_label,
                    inputStyle : drupalSettings.commerce_novalnet.novalnet_creditcard.css_input,
                    styleText  : drupalSettings.commerce_novalnet.novalnet_creditcard.css_text,
                },
                customText: {
                    card_holder : {
                        labelText : Drupal.t('Card holder name'),
                        inputText : Drupal.t('Name on card'),
                    },
                    card_number : {
                        labelText : Drupal.t('Card number'),
                        inputText : Drupal.t('XXXX XXXX XXXX XXXX'),
                    },
                    expiry_date : {
                        labelText : Drupal.t('Expiry date'),
                    },
                    cvc : {
                        labelText : Drupal.t('CVC/CVV/CID'),
                        inputText : Drupal.t('XXX'),
                    },
                    cvcHintText : Drupal.t('what is this?'),
                    errorText   : Drupal.t('Your credit card details are invalid')
                },       
            }
            iframe.postMessage(create_element, Drupal.behaviors.commerceNovalnetCreditCard.target_orgin );
            
            var get_height = {
                callBack : 'getHeight'
            }
            iframe.postMessage( get_height, Drupal.behaviors.commerceNovalnetCreditCard.target_orgin );
        },

        addEvent: function(e) {
            if ( e.origin === Drupal.behaviors.commerceNovalnetCreditCard.target_orgin) {
                var data = eval('(' + e.data + ')');
                if (data['callBack'] == 'getHeight') {
                    $( '#novalnet-cc-iframe' ).css( 'height', data ['contentHeight'] );
                } else if ( undefined !== data['error_message']) {
                    Drupal.behaviors.commerceNovalnetCreditCard.error_message = data['error_message'];
					$('#nncc_error').html(Drupal.t('Your credit card details are invalid')).show();
                    e.preventDefault();
                }else if (data['callBack'] == 'getHash' && data['hash'] != undefined && data['unique_id'] != undefined) {
                    if (data['result'] == 'success') {
                        Drupal.behaviors.commerceNovalnetCreditCard.error_message = ''
                        jQuery("input[name='payment_information[add_payment_method][payment_details][pan_hash]']").val(data['hash']);
                        jQuery("input[name='payment_information[add_payment_method][payment_details][unique_id]']").val(data['unique_id']);
                        jQuery("#edit-actions-next").click();
                    }
                }
            }
        }
    };
    $( document ).ready(function () {
        Drupal.behaviors.commerceNovalnetCreditCard.process();
        $("#novalnet-cc-iframe").on('load', function(){
            Drupal.behaviors.commerceNovalnetCreditCard.loadCcIframe();
        });
    });
})(jQuery, Drupal);
