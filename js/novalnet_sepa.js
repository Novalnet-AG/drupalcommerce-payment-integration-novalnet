/**
 * This file process the novalnet sepa related process
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
	Drupal.behaviors.commerceNovalnetDirectDebitSepa = {
		attach: function (context, settings) {
		$(document).ready(function(){
			if($('#nnsepa_ibanconf_bool').val() == 1){
				$('#nnsepa_ibanconf_desc').slideUp();
	  		}
			

      });

		$("#nnsepa_ibanconf").unbind().click(function() {
		    //Stuff
		    if ($('#nnsepa_ibanconf_bool').val() == 1) {
              $('#nnsepa_ibanconf_desc').slideDown();
              $('#nnsepa_ibanconf_bool').val(0);
              // event.stopPropagation();
              // event.preventDefault();
              
            }
            else {
               $('#nnsepa_ibanconf_desc').slideUp();
               $('#nnsepa_ibanconf_bool').val(1);
               // event.stopPropagation();
               // event.preventDefault();
              
            }
		});

		/*$('#nnsepa_ibanconf').click(function (event) {
            if ($('#nnsepa_ibanconf_bool').val() == 1) {
              $('#nnsepa_ibanconf_desc').slideDown();
              $('#nnsepa_ibanconf_bool').val(0);
              event.stopPropagation();
              event.preventDefault();
              
            }
            else {
               $('#nnsepa_ibanconf_desc').slideUp();
               $('#nnsepa_ibanconf_bool').val(1);
               event.stopPropagation();
               event.preventDefault();
              
            }
              
        });*/
    },

		/* Check for alphanumeric keys */
		allowAlphanumeric : function ( event ) {
			var keycode = ( 'which' in event ) ? event.which : event.keyCode,
				reg     = /^(?:[0-9a-zA-Z]+$)/;
			return ( reg.test( String.fromCharCode( keycode ) ) || keycode == 0 || keycode == 8 );
		},

		/* Check for holder keys */
		allowNameKey : function ( event ) {
			var keycode = ( 'which' in event ) ? event.which : event.keyCode,
				reg     = /[^0-9\[\]\/\\#,+@!^()$~%'"=:;<>{}\_\|*?`]/g;
			return ( reg.test( String.fromCharCode( keycode ) ) || keycode == 0 || keycode == 8 );
		},
	};
   
})(jQuery, Drupal);