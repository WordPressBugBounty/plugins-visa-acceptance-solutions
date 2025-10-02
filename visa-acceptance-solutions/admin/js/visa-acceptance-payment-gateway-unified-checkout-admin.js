jQuery(function($){
	'use strict';

	/**
	 * All of the code for your admin-facing JavaScript source
	 * should reside in this file.
	 *
	 * Note: It has been assumed you will write jQuery code here, so the
	 * $ function reference has been prepared for usage within the scope
	 * of this function.
	 *
	 * This enables you to define handlers, for when the DOM is ready:
	 *
	 * $(function() {
	 *
	 * });
	 *
	 * When the window is loaded:
	 *
	 * $( window ).load(function() {
	 *
	 * });
	 *
	 * ...and/or other possibilities.
	 *
	 * Ideally, it is not considered best practise to attach more than a
	 * single DOM-ready or window-load handler for a particular page.
	 * Although scripts in the WordPress core, Plugins and Themes may be
	 * practising this, we should strive to set a better example in our own work.
	 */
	if(wc_payment_gateway_admin_order.gateway_id == wc_payment_gateway_admin_order.visa_acceptance_solutions_uc_id){
		var coupon_button = document.querySelector('.remove-coupon');
		if(coupon_button){
			coupon_button.style.display = 'none';
		}
		var add_items = document.querySelector('.button.add-line-item');
		if (add_items) {
			add_items.style.display = 'none';
		}

		var add_coupons = document.querySelector('.button.add-coupon');
		if (add_coupons) {
			add_coupons.style.display = 'none';
		}

		var manual_refund_button = document.querySelector('.button.button-primary.do-manual-refund');
		if(manual_refund_button){
			manual_refund_button.style.display = 'none';
		}
		var refund_button = document.querySelector('button.refund-items');
		if('no' === wc_payment_gateway_admin_order.refund_button_visibility)
		{
			refund_button.style.display = 'none';
		}

		//Added to remove edit option
		jQuery('td.wc-order-edit-line-item').hide();

		//Added to remove recalculate button
		jQuery('.button.button-primary.calculate-action').hide();

		var button = document.querySelector('button.button-primary.do-api-refund');
		if(button)
		{
			button.disabled = true;
		}

		if(  'no' === wc_payment_gateway_admin_order.order_fully_capture )
		{
			jQuery('input[name=refund_amount]').on('change',function(){
				var total_amount = parseFloat(wc_payment_gateway_admin_order.total_refund_amount);

				var refund_amount = jQuery('#refund_amount').val();
				refund_amount = Number(refund_amount);
				if(refund_amount==total_amount ){
					if(button)
					{
						button.disabled = false;
					}

				}else{
					if(button)
					{
						button.disabled = true;
					}

				}
			});
			jQuery('input[name=refund_amount]').on('input',function(){

				var total_amount = parseFloat(wc_payment_gateway_admin_order.total_refund_amount);

				var refund_amount = jQuery('#refund_amount').val();
				refund_amount = Number(refund_amount);
				if(refund_amount==total_amount){
					button.disabled = false;
				}else{
					button.disabled = true;
				}
			});
		}

		   if ('yes' === wc_payment_gateway_admin_order.order_fully_capture && 'yes' === wc_payment_gateway_admin_order.refund_button_visibility)
		   {

			jQuery('input[name=refund_amount]').on('change',function(){
				var total_amount = parseFloat(wc_payment_gateway_admin_order.total_refund_amount);

				var refund_amount = jQuery('#refund_amount').val();
				refund_amount = Number(refund_amount);
				if(refund_amount<=total_amount && refund_amount > 0){
					if(button)
					{
						button.disabled = false;
					}

				}else{
					if(button)
					{
						button.disabled = true;
					}

				}
			});
			jQuery('input[name=refund_amount]').on('input',function(){
				var total_amount = parseFloat(wc_payment_gateway_admin_order.total_refund_amount);

				var refund_amount = jQuery('#refund_amount').val();
				refund_amount = Number(refund_amount);
				if(refund_amount<=total_amount && refund_amount > 0){
					button.disabled = false;
				}else{
					button.disabled = true;
				}
			});
		   }

	}

	jQuery( "#capture-button" ).on( "click", function(e) {

		if (jQuery('#capture-button').hasClass('disabled')) {
			jQuery('#capture-button').prop('disabled', true);
			return false;
		}

			var capture_data = wc_payment_gateway_admin_order;

			// get the order_id from the button tag
			var order_id = capture_data['order_id'];

			// get the product_id from the button tag
			var gateway_id = capture_data['gateway_id'];
			if( confirm(capture_data['capture_message']) ) {
				try{
					$.ajax({
						type: 'POST',
						url: wc_payment_gateway_admin_order.ajax_url,
						dataType: 'json',
						data: {
							action: wc_payment_gateway_admin_order.capture_action,
							nonce: wc_payment_gateway_admin_order.capture_nonce,
							order_id: order_id,
							gateway_id: gateway_id,
						},
						beforeSend: function() {
							jQuery("#woocommerce-order-items").block({
								message: null,
								overlayCSS: {
									background: "#fff",
									opacity: .6
								}
							});
						},
						success: function (data, textStatus, XMLHttpRequest) {

							if(data.success == 1)
							{
								alert(data.message);
								jQuery("#woocommerce-order-items").unblock()
								location.reload();
							}
							else {
								alert(data.message);
								jQuery("#woocommerce-order-items").unblock()
							}
						},
						error: function (XMLHttpRequest, textStatus, errorThrown) {
							console.log(errorThrown);
							alert(wc_payment_gateway_admin_order.error_failure)
						}
				});
			}catch (exception) {
				console.log(exception);
				alert(wc_payment_gateway_admin_order.error_failure)
			}
		  }else{
				e.preventDefault();
		  }

		});

})( jQuery );
