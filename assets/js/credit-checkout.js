/**
 * Rewards credit — classic checkout.
 *
 * Posts the "use my credit" choice to wc-ajax, then asks WooCommerce to
 * recalculate totals so the negative fee shows up in the order review.
 * The box is re-rendered by WooCommerce on every update_checkout, so all
 * handlers are delegated.
 */
;(function ($) {
  'use strict'

  if (typeof storedash_credit_params === 'undefined') {
    return
  }

  var params = storedash_credit_params
  var amountTimer = null
  var lastPayload = ''

  function post(apply, amount) {
    var payload = JSON.stringify([apply, amount])
    if (payload === lastPayload) {
      return
    }
    lastPayload = payload

    $.ajax({
      type: 'POST',
      url: params.apply_url,
      data: {
        nonce: params.nonce,
        apply: apply ? 1 : 0,
        amount: amount,
      },
      success: function () {
        $(document.body).trigger('update_checkout')
      },
      error: function () {
        lastPayload = ''
      },
    })
  }

  function currentAmount() {
    var raw = $('#storedash_credit_amount').val()
    return raw ? String(raw).trim() : ''
  }

  $(document).on('change', '#storedash_credit_apply', function () {
    var apply = $(this).is(':checked')
    $('.storedash-credit-box__amount-row').toggle(apply)
    post(apply, apply ? currentAmount() : '')
  })

  $(document).on('input change', '#storedash_credit_amount', function () {
    clearTimeout(amountTimer)
    amountTimer = setTimeout(function () {
      if ($('#storedash_credit_apply').is(':checked')) {
        post(true, currentAmount())
      }
    }, 600)
  })

  // Never submit the amount field with the checkout form; it is session state.
  $(document).on('checkout_place_order', function () {
    $('#storedash_credit_amount').prop('disabled', true)
    return true
  })
  $(document.body).on('checkout_error', function () {
    $('#storedash_credit_amount').prop('disabled', false)
  })
})(jQuery)
