(function($){
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
	if(typeof wc_payment_gateway_admin_order !== 'undefined' && wc_payment_gateway_admin_order.gateway_id == wc_payment_gateway_admin_order.visa_acceptance_solutions_uc_id){
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

		//Added to remove edit option.
		jQuery('td.wc-order-edit-line-item').hide();

		//Added to remove recalculate button.
		jQuery('.button.button-primary.calculate-action').hide();

		var button = document.querySelector('button.button-primary.do-api-refund');
		if(button)
		{
			button.disabled = true;
		}

		if(  'yes' === wc_payment_gateway_admin_order.has_partial_capture_history && 'no' === wc_payment_gateway_admin_order.order_fully_capture )
		{
			
			var remainingCaptured = parseFloat(wc_payment_gateway_admin_order.remaining_captured_amount) || 0;
			var capturedItemQuantities = wc_payment_gateway_admin_order.captured_item_quantities || {};
			var hasUntrackedCapture = 'yes' === wc_payment_gateway_admin_order.has_untracked_partial_capture;

			var validatePartialHistoryRefundAmount = function(){
				var refund_amount = Number(jQuery('#refund_amount').val());
				var matchesCaptured = remainingCaptured > 0 && refund_amount > 0 && refund_amount <= remainingCaptured + 0.01;

				// A "Refund" can't cover more units of a line item than were actually captured.
				var exceedsCapturedQty = false;
				if (matchesCaptured && !hasUntrackedCapture) {
					jQuery('input.refund_order_item_qty').each(function(){
						var match = /\[(\d+)\]/.exec(jQuery(this).attr('name') || '');
						if (!match) {
							return;
						}
						var selectedQty = Number(jQuery(this).val()) || 0;
						var capturedQty = Number(capturedItemQuantities[match[1]] || 0);
						if (selectedQty > capturedQty) {
							exceedsCapturedQty = true;
						}
					});
				}

				if(button)
				{
					button.disabled = ! (matchesCaptured && ! exceedsCapturedQty);
				}
			};

			jQuery('input[name=refund_amount]').on('change', validatePartialHistoryRefundAmount);
			jQuery('input[name=refund_amount]').on('input', validatePartialHistoryRefundAmount);
			jQuery(document).on('change input', 'input.refund_order_item_qty', validatePartialHistoryRefundAmount);
		}
		else if(  'no' === wc_payment_gateway_admin_order.order_fully_capture )
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

			// get the order_id from the button tag.
			var order_id = capture_data['order_id'];

			// get the product_id from the button tag.
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
		// Partial Capture Functionality (Inline UI like WooCommerce refunds).
	jQuery(document).on('click', '#partial-capture-button', function(e) {
		e.preventDefault();

		if (jQuery(this).hasClass('disabled')) {
			return false;
		}

		// Show the partial capture row (like refund UI).
		jQuery('tr.partial-capture').show();
		
		// Hide the capture buttons while partial capture UI is open.
		jQuery('#capture-button, #partial-capture-button').hide();
		
		// Initial validation check
		validatePartialCaptureButton();
	});

	// Cancel partial capture.
	jQuery(document).on('click', '.cancel-partial-capture', function(e) {
		e.preventDefault();
		
		// Hide the partial capture row.
		jQuery('tr.partial-capture').hide();
		
		// Show the capture buttons again.
		jQuery('#capture-button, #partial-capture-button').show();
		
		// Reset all quantities to 0.
		jQuery('.capture-qty').val(0);
		jQuery('#capture-shipping').prop('checked', false);
		jQuery('#capture_amount').val('0');
		updatePartialCaptureAmount();
		
		// Re-enable capture button.
		jQuery('.do-api-capture').prop('disabled', false).removeClass('disabled');
	});

	// Update amounts when quantities change.
	jQuery(document).on('input', '.capture-qty', function() {
		var maxQty = parseInt(jQuery(this).attr('max')) || 0;
		var currentQty = parseInt(jQuery(this).val()) || 0;
		
		// Enforce max quantity in real-time
		if (currentQty > maxQty) {
			jQuery(this).val(maxQty);
		}
		
		updatePartialCaptureAmount();
		validatePartialCaptureButton();
	});

	// Update amounts when shipping checkbox changes.
	jQuery(document).on('change', '#capture-shipping', function() {
		updatePartialCaptureAmount();
		validatePartialCaptureButton();
	});

	// Validate the partial capture button state.
	function validatePartialCaptureButton() {
		var isValid = true;
		var hasInvalidQuantity = false;

		// Check if any quantity exceeds max.
		jQuery('.capture-qty').each(function() {
			var quantity = parseInt(jQuery(this).val()) || 0;
			var maxQty = parseInt(jQuery(this).attr('max')) || 0;
			
			if (quantity > maxQty) {
				hasInvalidQuantity = true;
				isValid = false;
			}
		});

		// Check if capture amount is greater than 0.
		var captureAmount = parseFloat(jQuery('#capture_amount').val()) || 0;
		if (captureAmount <= 0) {
			isValid = false;
		}

		// Enable or disable the capture button based on validation.
		var captureButton = jQuery('.do-api-capture');
		if (!isValid) {
			captureButton.prop('disabled', true).addClass('disabled');
		} else {
			captureButton.prop('disabled', false).removeClass('disabled');
		}
	}

	function updatePartialCaptureAmount() {
		var total = 0;
		var currencySymbol = wc_payment_gateway_admin_order.currency_symbol || '$';

		// Calculate line items.
		jQuery('.capture-qty').each(function() {
			var quantity = parseInt(jQuery(this).val()) || 0;
			var unitPrice = parseFloat(jQuery(this).data('unit-price')) || 0;
			var itemTotal = quantity * unitPrice;
			var itemId = jQuery(this).data('item-id');
			
			// Update individual item line total.
			jQuery('.capture-line-total[data-item-id="' + itemId + '"]').html(currencySymbol + itemTotal.toFixed(2));
			
			total += itemTotal;
		});

		// Add shipping if checked.
		if (jQuery('#capture-shipping').is(':checked')) {
			var shippingAmount = parseFloat(jQuery('#capture-shipping').data('shipping-amount')) || 0;
			total += shippingAmount;
		}

		// Update capture amount field.
		jQuery('#capture_amount').val(total.toFixed(2));
		jQuery('.capture-amount-display').html(currencySymbol + total.toFixed(2));
	}

	// Process partial capture.
	jQuery(document).on('click', '.do-api-capture', function(e) {
		e.preventDefault();

		var quantities = {};
		var hasQuantity = false;
		var captureAmount = parseFloat(jQuery('#capture_amount').val()) || 0;
		var availableAmount = parseFloat(jQuery('tr.partial-capture').data('available-amount')) || 0;
		var validationErrors = [];

		if (captureAmount <= 0) {
			alert('Please select items to capture or enter a capture amount.');
			return;
		}

		// Validate capture amount doesn't exceed available amount.
		if (captureAmount > availableAmount) {
			alert('Capture amount $' + captureAmount.toFixed(2) + ' exceeds available amount $' + availableAmount.toFixed(2) + '.');
			return;
		}

		// Validate quantities don't exceed remaining quantities.
		jQuery('.capture-qty').each(function() {
			var quantity = parseInt(jQuery(this).val()) || 0;
			var maxQty = parseInt(jQuery(this).attr('max')) || 0;
			var itemId = jQuery(this).data('item-id');
			
			if (quantity > maxQty) {
				var nameText = jQuery(this).closest('tr').find('.name').text();
				var productName = String.prototype.trim.call(nameText).split('×')[0];
				productName = String.prototype.trim.call(productName);
				validationErrors.push(productName + ': Cannot capture ' + quantity + ' (only ' + maxQty + ' remaining)');
			}
		});

		if (validationErrors.length > 0) {
			alert('Validation Error:\n\n' + validationErrors.join('\n'));
			return;
		}

		jQuery('.capture-qty').each(function() {
			var quantity = parseInt(jQuery(this).val()) || 0;
			if (quantity > 0) {
				var itemId = jQuery(this).data('item-id');
				quantities[itemId] = quantity;
				hasQuantity = true;
			}
		});

		var includeShipping = jQuery('#capture-shipping').is(':checked') ? 'yes' : 'no';
		var captureData = wc_payment_gateway_admin_order;

		if (confirm('Are you sure you want to capture ' + wc_payment_gateway_admin_order.currency_symbol + captureAmount.toFixed(2) + '? This action cannot be undone.')) {
			try {
				jQuery.ajax({
					type: 'POST',
					url: wc_payment_gateway_admin_order.ajax_url,
					dataType: 'json',
					data: {
						action: 'visa_acceptance_partial_capture',
						nonce: wc_payment_gateway_admin_order.partial_capture_nonce,
						order_id: captureData['order_id'],
						quantities: quantities,
						include_shipping: includeShipping,
						capture_amount: captureAmount
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
					success: function(data, textStatus, XMLHttpRequest) {
						if (data.success) {
							alert(data.data.message || 'Partial capture completed successfully.');
							jQuery("#woocommerce-order-items").unblock();
							location.reload();
						} else {
							alert(data.data.message || 'Partial capture failed.');
							jQuery("#woocommerce-order-items").unblock();
						}
					},
					error: function(XMLHttpRequest, textStatus, errorThrown) {
						console.log(errorThrown);
						alert('An error occurred while processing the partial capture.');
						jQuery("#woocommerce-order-items").unblock();
					}
				});
			} catch (exception) {
				console.log(exception);
				alert('An error occurred while processing the partial capture.');
				jQuery("#woocommerce-order-items").unblock();
			}
		}
	});

	// Refresh Payment Status Button Handler.
	/* jQuery(document).on('click', '#refresh-status-button', function(e) {
		e.preventDefault();
		
		var button = jQuery(this);
		var orderId = wc_payment_gateway_admin_order.order_id;

		if (!orderId) {
			alert('Missing order ID.');
			return;
		}
		
		if (!confirm('Refresh payment status? This will check the current status of the transaction.')) {
			return;
		}
		
		// Disable button and show loading state.
		var originalText = button.text();
		button.prop('disabled', true).text('Refreshing...');
		
		jQuery.ajax({
			type: 'POST',
			url: wc_payment_gateway_admin_order.ajax_url,
			dataType: 'json',
			data: {
				action: 'vas_refresh_payment_status',
                nonce: wc_payment_gateway_admin_order.refresh_payment_status_nonce,
                order_id: orderId
			},
			success: function(response) {
				if (response.success) {
					alert(response.data.message || 'Payment status refreshed successfully.');
					// Reload page to show updated order status.
					location.reload();
				} else {
					button.prop('disabled', false).text(originalText);
					button.prop('disabled', false).text('Refresh Payment Status');
				}
			},
			error: function(xhr, status, error) {
				console.error('Refresh status error:', error);
				alert('An error occurred while refreshing payment status. Please try again.');
				button.prop('disabled', false).text(originalText);
			}
		});
	}); */

})( jQuery );
