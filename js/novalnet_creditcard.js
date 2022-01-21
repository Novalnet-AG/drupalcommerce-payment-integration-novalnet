/**
 * This file process the novalnet cc related process
 *
 * @category   PHP
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.1.0
 */
(function ($, Drupal) {
  'use strict';
    Drupal.behaviors.commerceNovalnetCreditCard = {
	loadCcIframe: function(e) {
		 let linkToAdd
        = document.createElement('script');
		linkToAdd.src = 'https://cdn.novalnet.de/js/v2/NovalnetUtility.js';
		 document.head.appendChild(linkToAdd);
		setTimeout(function(){
		NovalnetUtility.setClientKey(drupalSettings.commerce_novalnet.novalnet_creditcard.client_key);
	    var configurationObject = {
		callback: {
			// Called once the pan_hash created successfully
			on_success: function (data) {				
				if (jQuery("input[name='payment_information[add_payment_method][payment_details][pan_hash]']").html() != undefined) {					
					jQuery("input[name='payment_information[add_payment_method][payment_details][pan_hash]']").val(data['hash']);
					jQuery("input[name='payment_information[add_payment_method][payment_details][unique_id]']").val(data['unique_id']);
					jQuery("input[name='payment_information[add_payment_method][payment_details][do_redirect]']").val(data['do_redirect']);
					jQuery("#commerce-checkout-flow-multistep-default").submit();	
				} else {					
					jQuery("input[name='add_payment_method[payment_details][pan_hash]']").val(data['hash']);
					jQuery("input[name='add_payment_method[payment_details][unique_id]']").val(data['unique_id']);
					jQuery("input[name='add_payment_method[payment_details][do_redirect]']").val(data['do_redirect']);			
					jQuery("input[data-drupal-selector='edit-actions-submit']").addClass('submit-add-payment');
					jQuery(".submit-add-payment").click();					
				}				
				return true;
			},
			// Called in case of an invalid payment data or incomplete input
			on_error:  function (data) {            
				if ( undefined !== data['error_message'] ) {
					alert(data['error_message']);
					return false;
				}
			},
			// Called in case the Challenge window Overlay (for 3ds2.0) displays
			on_show_overlay:  function (data) {
				jQuery('#novalnet-cc-iframe').addClass("overlay");
				jQuery('.overlay').css({
						'position': 'fixed',
						'width': '100%',
						'height': '100%',
						'top': '0',
						'left': '0',
						'right': '0',
						'bottom': '0',
						'background-color': 'rgba(0,0,0,0.5)',
						'z-index': '2',
						'cursor': 'pointer'
					});
			},
			// Called in case the Challenge window Overlay (for 3ds2.0) hided
			on_hide_overlay:  function (data) {
				jQuery('#novalnet-cc-iframe').removeClass("overlay");
				jQuery('#novalnet-cc-iframe').removeAttr("style");
			}
		},
		// Customize Iframe container styel, text etc.,
		iframe: {
			id:'novalnet-cc-iframe', // Iframe ID
			inline:drupalSettings.commerce_novalnet.novalnet_creditcard.inline_form, // Default inline form
			skip_auth: 1,
			style: {
				container: drupalSettings.commerce_novalnet.novalnet_creditcard.css_text, // CSS for the Iframe
				input: drupalSettings.commerce_novalnet.novalnet_creditcard.css_input,
				label: drupalSettings.commerce_novalnet.novalnet_creditcard.css_label, // CSS for the label
			},
			// Customize the text of the Iframe
			text: {
				lang : drupalSettings.commerce_novalnet.novalnet_creditcard.lang, // End-customers selected language. The Iframe will be rendered in this Language
				error: drupalSettings.commerce_novalnet.novalnet_creditcard.invalid_error, // Basic error message
				// Customize the text for the card holder
				card_holder : {
					label:drupalSettings.commerce_novalnet.novalnet_creditcard.card_holder,
					place_holder: drupalSettings.commerce_novalnet.novalnet_creditcard.holder_place_holder, // Customized place holder text for the card holder
					error: drupalSettings.commerce_novalnet.novalnet_creditcard.holder_error, // Customized error text for the card holder
				},
				// Customize the text for the card number
				card_number : {
                    label: drupalSettings.commerce_novalnet.novalnet_creditcard.card_number,
					place_holder: drupalSettings.commerce_novalnet.novalnet_creditcard.number_place_holder, // Customized place holder text for the card number
					error: drupalSettings.commerce_novalnet.novalnet_creditcard.number_error, // Customized error text for the card number
				},
				// Customize the text for the expiry date
				expiry_date : {
                    label:drupalSettings.commerce_novalnet.novalnet_creditcard.expiry_date,
                    place_holder: drupalSettings.commerce_novalnet.novalnet_creditcard.expiry_date_place_holder, // Customized place holder text for the card number
					error: drupalSettings.commerce_novalnet.novalnet_creditcard.expiry_error, // Customized error text for the expiry date
				},
				// Customize the text for the CVC
				cvc : {
					label: drupalSettings.commerce_novalnet.novalnet_creditcard.cvc,
					place_holder: drupalSettings.commerce_novalnet.novalnet_creditcard.cvc_place_holder, // Customized place holder text for the CVC/CVV/CID
					error: drupalSettings.commerce_novalnet.novalnet_creditcard.cvc_error, // Customized error text for the CVC/CVV/CID
				}
			}
		},
		// Customer data
		customer: {
			first_name: drupalSettings.commerce_novalnet.novalnet_creditcard.first_name, // End-customer's First name which will be prefilled in the Card Holder field
			last_name:  drupalSettings.commerce_novalnet.novalnet_creditcard.last_name, // End-customer's Last name which will be prefilled in the Card Holder field
			email: drupalSettings.commerce_novalnet.novalnet_creditcard.email, // End-customer's Email ID
			tel:  drupalSettings.commerce_novalnet.novalnet_creditcard.tel, // End-customer's Telephone number
			mobile:  drupalSettings.commerce_novalnet.novalnet_creditcard.mobile, // End-customer's Mobile number
			fax: drupalSettings.commerce_novalnet.novalnet_creditcard.fax, // End-customer's Fax number
			tax_id: drupalSettings.commerce_novalnet.novalnet_creditcard.tax_first_nameid, // End-customer's Tax ID
			// End-customer's billing address
			billing: {
				street:  drupalSettings.commerce_novalnet.novalnet_creditcard.street, // End-customer's billing street
				city:  drupalSettings.commerce_novalnet.novalnet_creditcard.city, // End-customer's billing city
				zip: drupalSettings.commerce_novalnet.novalnet_creditcard.zip, // End-customer's billing zip
				country_code:  drupalSettings.commerce_novalnet.novalnet_creditcard.country_code, // End-customer's billing country ISO code
				company: drupalSettings.commerce_novalnet.novalnet_creditcard.company, // End-customer's billing company
			},
			shipping: {
				same_as_billing : drupalSettings.commerce_novalnet.novalnet_creditcard.same_as_billing,
			},
		},
		// Transaction data
		transaction: {
			amount:drupalSettings.commerce_novalnet.novalnet_creditcard.amount, // The payable amount that can be charged for the transaction (in minor units)
			currency:drupalSettings.commerce_novalnet.novalnet_creditcard.currency, // The three-character currency code
			test_mode:drupalSettings.commerce_novalnet.novalnet_creditcard.test_mode,
			enforce_3d:drupalSettings.commerce_novalnet.novalnet_creditcard.enforce_3d ,

		},
	   // Custom data
		custom: {
			lang: drupalSettings.commerce_novalnet.novalnet_creditcard.lang,
		}
	};
	// Create the form
	NovalnetUtility.createCreditCardForm(configurationObject);
	}, 2000);
},
	getNovalnetHash: function(e){
		// It will control the getPanHash call, if the pan hash value already assigned to pan_hash
		if(jQuery("input[name='payment_information[add_payment_method][payment_details][pan_hash]']").val() != undefined && jQuery("input[name='payment_information[add_payment_method][payment_details][pan_hash]']").val() == '' || jQuery("input[name='add_payment_method[payment_details][pan_hash]']").val() != undefined && jQuery("input[name='add_payment_method[payment_details][pan_hash]']").val() == '')
		{
			
			event.preventDefault();
			event.stopImmediatePropagation();
			NovalnetUtility.getPanHash();
		}
	},
    };
     $( document ).ready(function () {
      
        $("#commerce-checkout-flow-multistep-default").submit(function(){			
			 Drupal.behaviors.commerceNovalnetCreditCard.getNovalnetHash();
		});
        $(".commerce-payment-add-form").submit(function(){				
			 Drupal.behaviors.commerceNovalnetCreditCard.getNovalnetHash();
		});
		

    });
})(jQuery, Drupal);
