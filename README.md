# PayPal Inventory Listener

This Drupal module, **PayPal Inventory Listener**, handles PayPal Instant Payment Notification (IPN) messages to automatically update product inventory after a sale. When a customer completes a purchase through PayPal, this module listens for the notification and adjusts the inventory levels accordingly.

## Getting Started

These instructions will get you a copy of the project up and running on your local machine for development and testing purposes.

### Prerequisites

This module requires **Drupal ^10** and the **ECK (Entity Construction Kit)** module.

### Installing

1.  Download the module and place it in your Drupal project's `/modules/custom` directory.
2.  Enable the module through the Drupal administration interface.

## Usage

Once enabled, the module provides a listener endpoint at `/store/purchase-listener/paypal/ipn`. You will need to configure your PayPal account to send IPN messages to this URL.

### Functionality

When a PayPal payment with the status "Completed" is received, the module will:
1.  Parse the incoming IPN data.
2.  For each item in the transaction, it will create an "inventory_adjustment" entity.
3.  The quantity of the purchased item will be deducted from the inventory.
4.  A log entry will be created for each inventory adjustment, including the payer's name and email, and the item purchased.

### Local Testing

For local development and testing, the module bypasses the IPN validation with PayPal. This allows you to simulate IPN messages without a live PayPal connection. The module considers an environment as "local" if the remote address is `127.0.0.1` or `::1`, or if the host contains `lndo.site`.
