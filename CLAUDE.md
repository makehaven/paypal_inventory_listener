# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Module Overview

Drupal module that handles PayPal Instant Payment Notification (IPN) webhooks to automatically update material inventory after sales. Part of the MakeHaven makerspace platform.

## Development Commands

```bash
# Run from parent Drupal project root (not this module directory)
lando drush cr                    # Clear cache after changes
lando drush watchdog:show --type=paypal_inventory_listener  # View module logs

# Code standards check
lando sh -c "vendor/bin/phpcs --standard=Drupal web/modules/custom/paypal_inventory_listener"
```

## Architecture

### IPN Endpoint
- **Route**: `/store/purchase-listener/paypal/ipn` (public access, no auth required)
- **Controller**: `PaypalIpnController::ipnListener()`

### Processing Flow
1. Receives raw POST data from PayPal IPN
2. Validates IPN with PayPal (skipped in local dev)
3. Filters: only processes `Completed` payments, `cart`/`web_accept` types, USD currency
4. Validates business ID matches configured PayPal account
5. Deduplicates using key-value store (`txn_id`, `ipn_track_id`)
6. Normalizes single-item "Buy Now" transactions to cart format
7. Creates `material_inventory` ECK entities for each line item

### Key Dependencies
- **ECK Module**: Provides the `material_inventory` entity type
- **material_inventory_totals**: Recalculates totals when adjustments are created

### Entity Structure
Creates `inventory_adjustment` bundle entities with:
- `field_inventory_ref_material` - Reference to Material node
- `field_inventory_quantity_change` - Negative value (or 0 for tab checkouts)
- `field_inventory_change_reason` - Always `sale`
- `field_inventory_change_memo` - Buyer info and item name

### Tab Checkout Handling
When `custom` field contains `{"type": "tab_checkout", "uid": ...}`, quantity change is 0 because inventory was already deducted when item was added to member's tab.

### Local Development
IPN validation is bypassed when:
- Client IP is `127.0.0.1` or `::1`
- Host contains `lndo.site`

To test locally, POST form-encoded data to the endpoint simulating PayPal IPN format.

## Configuration
Business ID validation uses: `makerspace_material_store.settings` → `paypal_business_id`
