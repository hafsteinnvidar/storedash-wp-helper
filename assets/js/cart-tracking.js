;(function ($) {
  'use strict'

  class WooDashCartTracking {
    constructor() {
      this.timers = {
        customer_data: null,
        email_field: null,
        checkout_field: null,
        marketing_optin: null,
      }

      this.init()
    }

    init() {
      // Bail if cart tracking is disabled
      if (!woodash_params.cart_tracking.enabled) {
        return
      }

      this.captureDataListeners()
    }

    captureDataListeners() {
      // Email field capture - use jQuery delegated events for dynamic content
      $(document).on(
        'input',
        '#billing_email, .woodash-capture-email',
        this.captureEmail.bind(this)
      )

      // Checkout fields capture - use jQuery delegated events
      $(document).on(
        'input',
        '#billing_first_name, #billing_last_name, #billing_phone',
        this.captureCheckoutField.bind(this)
      )

      // Listen for email opt-out
      $(document).on('click', '.woodash-email-opt-out', this.optOut.bind(this))

      // Listen for email opt-in
      $(document).on(
        'change',
        '#woodash-opt-in',
        this.toggleOptInOptOut.bind(this)
      )

      // Listen for marketing opt-in checkbox changes
      $(document).on(
        'change',
        '#woodash_marketing_optin',
        this.updateMarketingOptin.bind(this)
      )
    }

    captureEmail(e) {
      const email = e.target.value
      clearTimeout(this.timers.email_field)
      this.timers.email_field = setTimeout(() => {
        // Always capture email, even if invalid (user might be typing)
        this.captureCustomerData(email)
      }, 1000) // Slightly longer delay for email to allow typing
    }

    captureCheckoutField(e) {
      clearTimeout(this.timers.checkout_field)
      this.timers.checkout_field = setTimeout(() => {
        this.captureCustomerData()
      }, 500)
    }

    captureCustomerData(customEmail) {
      clearTimeout(this.timers.customer_data)
      this.timers.customer_data = setTimeout(() => {
        // Get email - use custom email if provided, otherwise get from billing field
        let email = customEmail || $('#billing_email').val() || ''

        const firstName = $('#billing_first_name').val()
        const lastName = $('#billing_last_name').val()
        const phone = $('#billing_phone').val()

        const data = {
          email: email, // Send email even if invalid (captures typos)
          first_name: firstName,
          last_name: lastName,
          phone: phone,
          security: woodash_params.nonce,
        }

        // Only send if we have at least some data
        if (email || firstName || lastName || phone) {
          $.post(
            woodash_params.cart_tracking.wc_ajax_capture_customer_data_url,
            data
          )
        }
      }, 1000)
    }

    toggleOptInOptOut(e) {
      e.preventDefault()
      const optIn = e.target.checked

      if (optIn) {
        this.optIn()
      } else {
        this.optOut()
      }
    }

    optOut(callback = null) {
      $.post(
        woodash_params.cart_tracking.wc_ajax_email_opt_out_url,
        { security: woodash_params.nonce },
        callback
      )
    }

    optIn(callback = null) {
      $.post(
        woodash_params.cart_tracking.wc_ajax_email_opt_in_url,
        { security: woodash_params.nonce },
        callback
      )
    }

    updateMarketingOptin(e) {
      const isChecked = e.target.checked
      const optin = isChecked ? 1 : 0

      // Clear any existing timer
      clearTimeout(this.timers.marketing_optin)

      // Debounce the request
      this.timers.marketing_optin = setTimeout(() => {
        $.post(
          woodash_params.cart_tracking.wc_ajax_update_marketing_optin_url,
          {
            optin: optin,
            security: woodash_params.nonce,
          }
        )
      }, 500)
    }

    isValidEmail(email) {
      return /[^\s@]+@[^\s@]+\.[^\s@]+/.test(email)
    }
  }

  // Initialize on document ready
  $(document).ready(function () {
    if (typeof woodash_params !== 'undefined') {
      new WooDashCartTracking()
    }
  })
})(jQuery)
