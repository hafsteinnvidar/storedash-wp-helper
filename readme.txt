=== Storedash Helper ===
Contributors: TODO_WPORG_USERNAME
Tags: woocommerce, webhooks, abandoned-cart, waitlist, rest-api
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 6.0.0
WC tested up to: 10.9
Stable tag: 1.19.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Companion plugin for the Storedash app: enhanced WooCommerce REST endpoints and outbound webhooks for carts, waitlists, opt-ins and orders.

== Description ==

Storedash Helper is a companion plugin that connects your WooCommerce store to
the Storedash app. It provides:

* Enhanced REST API endpoints (registered under the `storedash/v1` namespace) for
  products, orders, coupons, media, discounts, brands and shipment tracking.
* Real-time outbound webhooks for cart, waitlist/back-in-stock, marketing opt-in,
  product enquiry and order events.
* Cart tracking with token-based abandoned-cart recovery.
* Product waitlist and back-in-stock notifications.
* Product enquiry forms.
* Brand taxonomy management.
* A discount engine with BOGO, quantity-based and dynamic pricing rules that
  integrates with the WooCommerce Store API for block-based cart and checkout.
* An integration framework for shipping providers.
* WooCommerce High-Performance Order Storage (HPOS) and cart/checkout blocks
  compatibility.

Privileged REST endpoints authenticate with a real WooCommerce REST API consumer
key/secret requiring the `manage_woocommerce` capability, and outbound webhooks
are signed with a constant-time HMAC signature. Only two routes are public: the
version-free `storedash/v1/ping` onboarding check and the token- and
rate-limited cart-recovery endpoint.

The plugin requires an active Storedash account at [storedash.app](https://storedash.app).

The admin interface bundles the [Inter](https://rsms.me/inter/) typeface, licensed
under the SIL Open Font License 1.1 (see `assets/fonts/OFL.txt`).

== Installation ==

1. Ensure WooCommerce 6.0 or later is installed and active.
2. Upload the `storedash` folder to the `/wp-content/plugins/` directory, or
   install the plugin through the WordPress plugins screen.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Complete the connection setup from your Storedash account. Storedash
   provisions a WooCommerce REST API consumer key and configures the outbound
   webhook URLs.
5. Verify connectivity by requesting `GET /wp-json/storedash/v1/ping`.

Requires PHP 7.4 or later, WordPress 5.8 or later, and WooCommerce 6.0 or later.

== Frequently Asked Questions ==

= How do I get updates? =

Like any other plugin: WordPress shows "Update available" on the Plugins screen when a new release is published, and you update with one click. You can also enable auto-updates for it. New releases are published at https://github.com/hafsteinnvidar/storedash-wp-helper/releases.

= Does this plugin require WooCommerce? =

Yes. WooCommerce must be installed and activated for Storedash Helper to work.

= Do I need a Storedash account? =

Yes. This is a companion plugin. It exposes endpoints and webhooks consumed by
the Storedash app and does not send any data until you connect it to your
Storedash account during setup.

= How are the REST endpoints secured? =

Privileged endpoints require a WooCommerce REST API consumer key/secret belonging
to a user with the `manage_woocommerce` capability, verified with a constant-time
comparison. The only unauthenticated routes are the `storedash/v1/ping`
onboarding check (which exposes no version information) and the token- and
rate-limited cart-recovery link endpoint. Webhooks are signed and verified with a
constant-time HMAC signature.

= Is HPOS (High-Performance Order Storage) supported? =

Yes. Storedash Helper fully supports both the legacy post-based storage and the
new HPOS custom order tables.

= Does this plugin work with WooCommerce block-based checkout? =

Yes. The discount engine integrates with the WooCommerce Store API for
block-based cart and checkout.

= Can I change or disable the webhook endpoints? =

Yes. No outbound data is sent until you connect the plugin to your Storedash
account (both a store ID and a webhook signing secret must be present). Once
connected, each event type posts to a default Storedash endpoint, and every
outbound webhook URL is a WordPress option you can change to point at your own
endpoint, or clear (set empty) to disable that outbound call entirely. The
options are listed in the "External services" section below.

== External services ==

Storedash Helper is a companion plugin for the Storedash platform. To provide its
cart-recovery, waitlist, enquiry and live-chat features it transmits store and
customer data to Storedash-operated services. You must have a Storedash account,
and the plugin does not send any data until you connect it to that account during
setup.

Services operated by Storedash:

1. Storedash Webhook Intake — https://webhooks.storedash.io
   - Data sent: when cart tracking is enabled, abandoned/converted cart data
     including the shopper's email, name, phone, cart line items and totals; when
     a shopper joins a product waitlist or submits a product enquiry or marketing
     opt-in, their name, email, phone and message; the related product and store
     identifiers.
   - When: cart data is sent automatically as shoppers interact with the
     cart/checkout (only when cart tracking is enabled); waitlist, opt-in and
     enquiry data are sent when a shopper submits the respective form.
   - Configurable: each event type posts to a user-configurable option URL,
     defaulting to a `https://webhooks.storedash.io/` endpoint —
     `woodash_cart_webhook_url` (cart), `woodash_optin_webhook_url` (opt-in),
     `storedash_waitlist_webhook_url` (waitlist), `storedash_enquiry_webhook_url`
     (enquiry). You may point these at any endpoint you control, or clear an option
     (set it empty) to disable that call. No data is sent until the store is
     connected (store ID + webhook secret present).

2. Storedash App / Authentication — https://app.storedash.io
   - Data sent: OAuth connection parameters and store identifiers during initial
     setup, and health/diagnostic checks you trigger from the admin screen.
   - When: during onboarding and when you run diagnostics.

3. Storedash Live Chat — https://storedash-chat-service-5nte2.ondigitalocean.app
   - Data sent: the plugin loads the chat widget script and passes your store id;
     chat content is then handled by the Storedash chat service.
   - When: on storefront pages, only when a store id is configured. The service
     URL is overridable via the `storedash_live_chat_url` option.

Your use of these services is subject to the Storedash Terms of Service
(https://storedash.app/terms) and the Storedash Privacy Policy
(https://storedash.app/privacy).

== Changelog ==

= 1.19.0 =
* The plugin now updates itself from the WordPress Plugins screen. When a new release is published, every site running the plugin shows "Update available" within about 12 hours (or immediately after Dashboard → Updates → "Check again") and installs it with the standard one-click "Update now" link; the WordPress auto-update toggle also works. Updates are fetched from the public release feed at github.com/hafsteinnvidar/storedash-wp-helper, and each downloaded package is checked against the SHA-256 published with the release before it is installed. Sites on 1.18.0 or earlier need one last manual upload to receive this.

= 1.18.0 =
* YouTube and Vimeo videos added to a product description or short description in Storedash now show on the product page. WooCommerce removed every embedded video frame when a product was saved through its REST API, so the video arrived in WordPress as an empty box. The plugin now keeps video frames whose address is an https YouTube (youtube.com, youtube-nocookie.com) or Vimeo (player.vimeo.com) player link, and still removes every other frame. Also applies to variation descriptions. Nothing else in the description is changed.

= 1.17.0 =
* New Rewards credit module. Customers earn store credit on completed (or processing) orders according to rules pushed from Storedash (`POST /wp-json/storedash/v1/credit/sync`), and spend it at checkout as a non-taxable "Rewards credit" line on the classic checkout, the Cart/Checkout Blocks (a "Use my rewards credit" checkbox) and headless storefronts (Store API `extensions.storedash_credit` plus a `storedash_credit` cart update callback). Balances live in a new ledger table in WordPress, are consumed oldest-expiry-first inside a locked transaction, are released when an order is cancelled or fails, and are clawed back or returned as credit on refunds. New authenticated endpoints `GET /credit/balance`, `GET /credit/ledger`, `POST /credit/grant`, `POST /credit/adjust`, and a customer-token `GET /credit/me`. Every ledger movement is sent to Storedash as a signed `credit.*` webhook. A daily `storedash_credit_expire` cron records expired credit.
* Marketing consent given through the back-in-stock waitlist form and the product enquiry form is now reported to Storedash with its own source (`waitlist_optin` / `enquiry_optin`) instead of being labelled as the newsletter signup widget. Merchants can therefore target a welcome automation or a list at real newsletter signups only. Existing subscribers keep their recorded source; no data is changed.

= 1.16.0 =
* Bulk product edits made in Storedash are now written to the store through a new authenticated `POST /wp-json/storedash/v1/products/bulk` endpoint. Each product or variation is saved through WooCommerce's own REST update logic, so validation, stock and price lookup tables, and plugin hooks behave exactly as with the standard API. The endpoint works within a time budget and reports which items it did not reach, so large edits on slower hosting complete in several short requests instead of failing with a timeout. Stores on an older plugin version keep using the standard WooCommerce batch endpoint.

= 1.15.0 =
* Shipment tracking written by Storedash can now carry a carrier tracking link. The `POST /wp-json/storedash/v1/orders/{id}/shipment-tracking` endpoint accepts an optional `custom_tracking_link`; when present, the WooCommerce Shipment Tracking extension records the carrier as a custom provider with that link (so Icelandic carriers such as Dropp and Pósturinn get a clickable link in order emails and the account page). Without the link, behaviour is unchanged. Stores without the extension additionally get a `_tracking_link` order meta.

= 1.14.0 =
* Headless storefronts can now add shoppers to the back-in-stock waitlist through a new authenticated `POST /wp-json/storedash/v1/waitlist/entries` endpoint. Entries land in the same table as the on-site widget, so restock notifications, the dashboard waitlist, and conversion tracking all work unchanged. Rate-limited per shopper and per email.

= 1.10.0 =
* Storedash can now discover the store's own order and product statuses, including custom ones added by other plugins, through a new read-only `/wp-json/storedash/v1/statuses` endpoint. Previously the dashboard assumed the WooCommerce core status list, so orders and products parked in a custom status were unlabelled or missing.
* Listing products by a custom product status through the WooCommerce REST API now works. WooCommerce validates `status`, `include_status` and `exclude_status` against a fixed list and rejected custom statuses with a 400 error; those lists now also include the statuses registered on your store. Requests that use only the standard statuses behave exactly as before.

= 1.9.2 =
* Marketing consent given on waitlist and enquiry forms now reaches the marketing list. A wrong internal class name meant the consent checkbox on those forms was silently ignored; checkout and signup-widget consent were unaffected.
* Cart recovery works again after a shopper clicks a recovery link and then abandons a second time. Previously that cart was permanently excluded from further recovery emails.
* WooCommerce Blocks carts and headless storefronts now see discount names, badges, and savings for store-wide, product, category, tag, and brand discounts. Prices were already correct; the discount metadata was missing.
* The bulk-pricing table on product pages now respects the "skip products already on sale" option, matching what the cart actually charges.
* Repeated shipment-tracking writes with the same tracking number and carrier no longer create duplicate tracking entries.
* Clean uninstall now also removes the customer-tokens table, and deactivation clears its purge task.
* Removed an unused internal recovery endpoint and assorted dead code.

= 1.9.1 =
* Category discounts now also apply to products filed only under a subcategory of the targeted category, matching what a category archive shows. Previously a discount on a parent category reached only products explicitly assigned to that parent, so items sitting in a child category alone appeared on the category page at full price. Tag targeting is unaffected (tags are not hierarchical), and product exclusions and the "skip products already on sale" option continue to take precedence.
* Headless storefronts now see discounted catalog prices. The Store API (`wc/store/v1/products`) previously reported stored prices, so a store built on it showed every discounted product at full price even though the discount was applied correctly at checkout. Product responses now carry the discounted `prices.price` and report `on_sale`, so storefronts render the reduced price with the original struck through. Cart and checkout pricing is unchanged, and the WooCommerce admin REST API (`wc/v3`) is deliberately untouched so exports, integrations and syncs keep seeing the merchant's own prices.
* Discounted variable products now show the discount badge. A variable product has no price of its own — its variations carry the prices — so the badge check came back empty and only the struck-through price range appeared, leaving variable and simple products looking inconsistent on the same shelf. The badge now reflects the discount on the cheapest variation, which is the price the listing leads with.

= 1.7.2 =
* Headless stores: customer-facing account links in WordPress and WooCommerce emails (password reset and similar) now point at the headless storefront instead of wp-login.php and the WooCommerce My Account page, so a shopper who requests a reset from the storefront is no longer sent to a dead end on the WordPress origin. Inactive unless the storedash_storefront_url option is set, so non-headless stores are unaffected.

= 1.7.1 =
* Email Signup widget: added a "Preview in Editor" switch under Privacy & Messages that renders the success or error message inside the Elementor preview. The message only appears on the frontend after a submission, so its colours, typography, padding and radius previously had to be styled blind. The setting affects the editor only and never renders on the live page.

= 1.7.0 =
* Email Signup Elementor widget: rebuilt the Style tab. The single section of six colour pickers is replaced by Layout, Form Box, Heading, Description, Input Field, Button, and Privacy & Messages sections, adding typography (font family, size, weight, spacing) for every text element, border and border-radius controls for the form, input and button, padding, box shadows, normal/hover button states, hover animation and transition duration.
* Email Signup widget: the button can now be positioned to the right of, left of, below or above the email field, with controls for the gap, field alignment and the width at which the button wraps onto its own line.
* Email Signup widget: per-element text alignment for the heading, description, input, button, privacy notice and messages, plus a widget-wide alignment default. All layout, spacing and alignment controls are responsive per device.
* Email Signup widget: fixed the button dropping onto its own line below the email field instead of sitting beside it. Themes that style form fields with `width: 100%` (most of them) made the field claim the whole row; the field's width is now reset so "Right of Field" and "Left of Field" keep the button inline until the column is genuinely too narrow for it.
* No changes to saved settings: existing Email Signup widgets keep their configured colours and appearance.

= 1.6.1 =
* Fixed a fatal memory-exhaustion error (HTTP 500 on every storedash/v1 endpoint) on sites where a third-party capability filter calls wp_get_current_user() — e.g. Yoast SEO. The REST key authentication no longer performs capability checks inside the determine_current_user filter (capabilities are enforced by each route's permission callback, matching WooCommerce core), and the filter is now guarded against re-entrant calls.

= 1.6.0 =
* Removed the custom Taxonomies module (storedash/v1/brands and product-brands endpoints). Storedash and its sync now use WooCommerce's native brands support (WC 9.6+). On stores running WC below 9.6 the product_brand taxonomy is unregistered until WooCommerce is updated.

= 1.4.0 =
* Added GET /storedash/v1/payment-gateways returning every configured payment gateway (enabled and disabled) with its refund-support capability, so Storedash can tell which providers allow automatic refunds.
* Added GET /storedash/v1/orders/{id}/payment-meta resolving an order's gateway, refund support, transaction id and provider transaction URL, including orders whose gateway is no longer installed.

= 1.3.1 =
* Throttle cart.updated webhooks (leading+trailing, default 120s window) to prevent webhook bursts during checkout.

= 1.3.0 =
* Discount engine overhaul: display discounts (store-wide/product/category/tag/brand) now apply to actual cart and checkout prices, not just displayed prices.
* Deterministic discount resolution: priority, then biggest customer saving, then oldest rule. One discount per product/cart line.
* New per-rule "apply to sale price" setting controlling whether a discount stacks on merchant sale prices.
* Discounts with cart conditions no longer alter catalog price display (advertised prices are always honored).
* UTC-consistent discount scheduling; unused tracking table removed.
* Privacy: shopper carts are no longer captured at all until the store is connected to a Storedash account, and no cart, waitlist, enquiry or opt-in data is sent off-site before then; clearing a webhook URL option now disables that call.
* Settings: cart tracking, marketing opt-in, abandonment time and retention days are now managed centrally from the Storedash dashboard; the plugin's Settings screen keeps the local Clean Uninstall switch.
* Waitlist: back-in-stock notifications mark entries as notified, so shoppers are no longer re-notified on every restock.
* Security: hardened the media upload-from-url endpoint against SSRF, enforced the API key read/write scope, stopped an unauthenticated header from suppressing outbound webhooks, and stopped exposing the webhook secret in the admin page source.
* Admin: refreshed the plugin admin interface with collapsible diagnostics sections and the bundled OFL-licensed Inter font.
* Fixes: Posturinn auto-shipments only skipped for Storedash-managed orders, schema upgrades now apply on update, complete data removal on uninstall, and the live-chat widget is now properly enqueued.
* Housekeeping: removed dead code, corrected PSR-4 autoloading for case-sensitive hosts, regenerated the translation template, and cleaned the code to pass phpcs with zero errors and warnings.

= 1.2.6 =
* Marketing opt-in consent sources. Customers can now grant marketing consent from the store, feeding the Storedash opt-in subscriber list:
    * Classic checkout: an optional "receive promotional emails" checkbox on the order-review step.
    * Block (Store API) checkout: the same opt-in via the WooCommerce Additional Checkout Fields API (requires WooCommerce 8.9+).
    * Elementor "Email Signup" widget: a standalone single-opt-in email-capture form placeable on any page.
* On consent, the plugin posts a signed `subscription.optin` webhook to Storedash (reusing the existing X-WooDash-Signature envelope). Checkbox rendering is controlled remotely from the Storedash app (Carts settings), and each source is idempotent.

= 1.2.4 =
* Added POST /storedash/v1/coupons/{id}/status endpoint to set a coupon's publish/draft status — the one coupon operation the WooCommerce REST API cannot perform. All other coupon CRUD continues to use the standard WooCommerce REST API.

= 1.2.3 =
* Added the required "External services" disclosure to readme.txt for WordPress.org compliance.
* Corrected the readme Stable tag to match the plugin version.
* Output-escaping and input-sanitization hardening across admin, webhook-notice and discount-display output.

= 1.1.0 =
* Improved security: escaped all translatable output strings
* Fixed text domain consistency across all translation calls
* Added WooCommerce Blocks compatibility declaration
* Improved nonce verification in AJAX handlers
* Migrated all logging to use centralized debug logger
* Added readme.txt and LICENSE file
* Added translation template (.pot) support

= 1.0.1 =
* Initial public release

== Upgrade Notice ==

= 1.19.0 =
Adds one-click updates from the Plugins screen. This is the last version that has to be uploaded by hand.

= 1.4.0 =
Adds payment-gateway capability endpoints so Storedash can detect which providers support automatic refunds and link out to provider transactions. Recommended update for all users.

= 1.3.1 =
Throttles cart update webhooks during checkout to prevent bursts of 10-15 webhooks per session. Recommended update for all users.

= 1.3.0 =
Important fix: catalog discounts now apply at checkout (not just on display), and shopper data is neither captured nor sent off-site until the store is connected. Recommended update for all users.

= 1.2.6 =
Adds marketing opt-in consent capture (checkout checkbox for classic and block checkout, plus an Elementor email-signup widget). Recommended update for all users.

= 1.2.4 =
Adds a coupon publish/draft status endpoint. Recommended update for all users.

= 1.2.3 =
Adds the required external-services disclosure and additional output/input hardening. Recommended update for all users.

= 1.1.0 =
Security and compatibility improvements. Recommended update for all users.
