/**
 * Gift cards — classic checkout.
 *
 * Posts the code to wc-ajax, then asks WooCommerce to recalculate totals so
 * the gift card fee shows up in the order review. The box is re-rendered by
 * WooCommerce on every update_checkout, so all handlers are delegated.
 */
;(function ($) {
  'use strict'

  if (typeof storedash_gift_card_params === 'undefined') {
    return
  }

  var params = storedash_gift_card_params
  var busy = false

  function showError(message) {
    $('.storedash-gift-card-box__error')
      .text(message || params.error)
      .show()
  }

  function apply() {
    var $input = $('#storedash_gift_card_code')
    var code = String($input.val() || '').trim()
    if (!code || busy) {
      return
    }
    busy = true
    $('.storedash-gift-card-box__error').hide()

    $.ajax({
      type: 'POST',
      url: params.apply_url,
      data: { nonce: params.nonce, code: code },
      success: function () {
        $input.val('')
        $(document.body).trigger('update_checkout')
      },
      error: function (xhr) {
        var message =
          xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
        showError(message)
      },
      complete: function () {
        busy = false
      },
    })
  }

  $(document).on('click', '.storedash-gift-card-box__button', function (event) {
    event.preventDefault()
    apply()
  })

  // Enter in the code field applies the code instead of placing the order.
  $(document).on('keydown', '#storedash_gift_card_code', function (event) {
    if (event.key === 'Enter' || event.keyCode === 13) {
      event.preventDefault()
      apply()
    }
  })

  $(document).on('click', '.storedash-gift-card-box__remove', function (event) {
    event.preventDefault()
    $.ajax({
      type: 'POST',
      url: params.remove_url,
      data: { nonce: params.nonce, card: $(this).data('card') },
      complete: function () {
        $(document.body).trigger('update_checkout')
      },
    })
  })
})(jQuery)
