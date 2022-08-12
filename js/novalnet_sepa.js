/**
 * This file will handle mandate information popup
 *
 * @category   JS
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.2.0
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
		    if ($('#nnsepa_ibanconf_bool').val() == 1) {
              $('#nnsepa_ibanconf_desc').slideDown();
              $('#nnsepa_ibanconf_bool').val(0);

            }
            else {
               $('#nnsepa_ibanconf_desc').slideUp();
               $('#nnsepa_ibanconf_bool').val(1);


            }
        });
    },

	};

})(jQuery, Drupal);
