# Carts Module

## Overview

REST surface for cart-related settings, refactored from a monolithic file into a
modular, PSR-4-autoloaded structure. Cart tracking, webhooks, and the public
recovery landing route live in `inc/services/cart/` — this module only holds the
authenticated `storedash/v1` cart routes.

## Architecture

### Directory Structure

```
Carts/
├── Abstract_Carts_Controller.php       # Base controller (permissions, table name)
├── Carts_Manager.php                   # Main initialization and coordination
├── Route_Registry.php                  # REST API route registration
├── Contracts/
│   └── Carts_Controller_Interface.php  # Interface for all controllers
└── Controllers/
    └── Carts_Settings_Controller.php   # GET/POST /carts/settings
```

### Key Components

1. **Abstract_Carts_Controller**: Shared functionality —
   - `check_permission()` - `manage_woocommerce` capability check
   - `get_table_name()` - Database table name helper

2. **Route_Registry**: Centralizes all route registration under the `storedash/v1` namespace

3. **Carts_Settings_Controller**: Reads/writes the cart-tracking settings synced from the dashboard

## API Endpoints

- `GET /storedash/v1/carts/settings` - Read cart tracking settings
- `POST /storedash/v1/carts/settings` - Update cart tracking settings (synced from dashboard)

> The former `POST /carts/{token}/recover` route was removed (2026-07-31): it was
> an unused `wp_mail` stub with no production callers. Real recovery flows
> through storedash-worker (Inngest) → storedash-mail → the public
> `GET /storedash/v1/recover-cart` route in `inc/services/cart/Cart_Recovery.php`.
> The list/single/delete/stats cart endpoints once documented here were never
> registered by `Route_Registry`.

## Important Notes

1. **Database-Driven**: Cart data lives in the custom `{prefix}woodash_carts` table,
   written by `inc/services/cart/Cart_Tracking.php` — not by this module.
2. **Recovery System**: Owned by `inc/services/cart/Cart_Recovery.php` (public
   tokenized GET route) plus the worker/mail pipeline; nothing in this module
   sends email.

## Testing

1. Verify both `/carts/settings` methods return identical responses to the dashboard's expectations
2. Confirm permission checks reject non-`manage_woocommerce` users

## Future Enhancements

The modular structure allows for:
- Easy addition of new cart routes
- Unit testing of individual components
- Better separation of database and business logic
