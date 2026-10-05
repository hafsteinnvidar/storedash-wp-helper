/**
 * Gift cards — Cart / Checkout blocks.
 *
 * No build step: plain wp.element calls. Renders a code field in the
 * DiscountsMeta slot (under the coupon form) and talks to the Store API via
 * extensionCartUpdate({ namespace: 'storedash_gift_card', data }), which
 * refreshes the cart store, so totals update live.
 */
;(function () {
  'use strict'

  var wc = window.wc || {}
  var wp = window.wp || {}
  var checkout = wc.blocksCheckout
  var params = window.storedash_gift_card_blocks || {}

  if (!checkout || !wp.plugins || !wp.element) {
    return
  }

  var Fill = checkout.ExperimentalDiscountsMeta || checkout.ExperimentalOrderMeta
  var update = checkout.extensionCartUpdate
  if (!Fill || !update) {
    return
  }

  var el = wp.element.createElement
  var useState = wp.element.useState

  function money(minor, totals) {
    var unit = totals && typeof totals.currency_minor_unit === 'number' ? totals.currency_minor_unit : 0
    var value = (parseInt(minor, 10) || 0) / Math.pow(10, unit)
    var parts = value.toFixed(unit).split('.')
    var thousand = (totals && totals.currency_thousand_separator) || ''
    var decimal = (totals && totals.currency_decimal_separator) || '.'
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousand)
    var number = parts.length > 1 ? parts[0] + decimal + parts[1] : parts[0]
    return ((totals && totals.currency_prefix) || '') + number + ((totals && totals.currency_suffix) || '')
  }

  function GiftCardForm(props) {
    var data = (props.extensions && props.extensions.storedash_gift_card) || null
    var totals = props.cart && props.cart.cartTotals
    var codeState = useState('')
    var errorState = useState('')
    var busyState = useState(false)
    var code = codeState[0]
    var error = errorState[0]
    var busy = busyState[0]

    if (!data || !data.enabled) {
      return null
    }

    function send(payload) {
      busyState[1](true)
      errorState[1]('')
      return update({ namespace: 'storedash_gift_card', data: payload })
        .then(function () {
          codeState[1]('')
        })
        .catch(function (err) {
          errorState[1]((err && err.message) || params.invalid)
        })
        .finally(function () {
          busyState[1](false)
        })
    }

    var cards = (data.cards || []).map(function (card) {
      return el(
        'li',
        { key: card.id, className: 'storedash-gift-card__card' },
        params.cardLabel.replace('%1$s', card.last4).replace('%2$s', money(card.applied, totals)) + ' ',
        el(
          'button',
          {
            type: 'button',
            className: 'storedash-gift-card__remove',
            disabled: busy,
            onClick: function () {
              send({ remove: card.id })
            },
          },
          params.remove
        )
      )
    })

    var canAdd = !data.cart_has_gift_card && (data.cards || []).length < data.max_cards

    return el(
      'div',
      { className: 'storedash-gift-card wc-block-components-totals-item' },
      el('strong', { className: 'storedash-gift-card__title' }, params.title),
      cards.length ? el('ul', { className: 'storedash-gift-card__cards' }, cards) : null,
      data.cart_has_gift_card ? el('p', { className: 'storedash-gift-card__note' }, params.notAllowed) : null,
      canAdd
        ? el(
            'form',
            {
              className: 'storedash-gift-card__form',
              onSubmit: function (event) {
                event.preventDefault()
                if (code.trim() && !busy) {
                  send({ apply: code.trim() })
                }
              },
            },
            el('input', {
              type: 'text',
              className: 'storedash-gift-card__input',
              value: code,
              placeholder: params.placeholder,
              'aria-label': params.placeholder,
              autoComplete: 'off',
              spellCheck: false,
              onChange: function (event) {
                codeState[1](event.target.value)
              },
            }),
            el(
              'button',
              { type: 'submit', className: 'wc-block-components-button storedash-gift-card__apply', disabled: busy || !code.trim() },
              params.apply
            )
          )
        : null,
      error ? el('p', { className: 'storedash-gift-card__error', role: 'alert' }, error) : null
    )
  }

  // The fill clones its children with { cart, extensions, context }.
  function render() {
    return el(Fill, null, el(GiftCardForm, null))
  }

  wp.plugins.registerPlugin('storedash-gift-card', {
    render: render,
    scope: 'woocommerce-checkout',
  })
})()
