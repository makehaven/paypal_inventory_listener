<?php

namespace Drupal\paypal_inventory_listener\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Entity\EntityStorageException;

/**
 * Controller for handling PayPal IPN notifications.
 */
class PaypalIpnController extends ControllerBase {

  /**
   * IPN listener to handle inventory updates after PayPal checkout.
   */
  public function ipnListener(Request $request) {
    // Read the raw POST data from PayPal.
    $raw_post_data = file_get_contents('php://input');
    $post_data = [];
    parse_str($raw_post_data, $post_data);

    // For local testing, skip IPN validation.
    $is_local = in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']) || strpos($_SERVER['HTTP_HOST'], 'lndo.site') !== false;

    if ($is_local || $this->validateIpn($raw_post_data)) {
      // Process the IPN if the payment status is 'Completed'.
      if (isset($post_data['payment_status']) && $post_data['payment_status'] == 'Completed') {
        $payer_email = $post_data['payer_email'];
        $payer_name = $post_data['first_name'] . ' ' . $post_data['last_name'];

        // Process each item in the cart.
        $item_count = 1;  // PayPal's cart items start with 'item_number1', 'item_number2', etc.
        while (isset($post_data['item_number' . $item_count])) {
          // Extract item data.
          $item_number_key = 'item_number' . $item_count;
          $quantity_key = 'quantity' . $item_count;
          $item_name_key = 'item_name' . $item_count;

          $item_number = $post_data[$item_number_key];  // Material node ID.
          $quantity = $post_data[$quantity_key] ?? 1;
          $item_name = $post_data[$item_name_key];

          // Debugging: Log the values before creating the entity.
          \Drupal::logger('paypal_inventory_listener')->info('Attempting to create an entity with bundle: inventory_adjustment. Item Number: ' . $item_number . ', Quantity: ' . $quantity);

          // Create an inventory adjustment entity for each item.
          try {
            // Get the storage handler for the 'material_inventory' entity type.
            $storage = \Drupal::entityTypeManager()->getStorage('material_inventory');

            // Create the entity.
            $inventory_adjustment = $storage->create([
              'type' => 'inventory_adjustment',   // Bundle name.
              'field_inventory_ref_material' => $item_number,
              'field_inventory_quantity_change' => -$quantity, // Deduct the quantity from inventory.
              'field_inventory_change_reason' => 'sale',
              'field_inventory_change_memo' => 'Sold to ' . $payer_name . ' (' . $payer_email . ') - Item: ' . $item_name,
            ]);

            // Save the inventory adjustment.
            $inventory_adjustment->save();

            \Drupal::logger('paypal_inventory_listener')->info('Inventory adjustment created successfully for item: ' . $item_number);
          } catch (EntityStorageException $e) {
            \Drupal::logger('paypal_inventory_listener')->error('Failed to create inventory adjustment: ' . $e->getMessage());
          } catch (\Exception $e) {
            \Drupal::logger('paypal_inventory_listener')->error('An unexpected error occurred: ' . $e->getMessage());
          }

          // Move to the next item in the cart.
          $item_count++;
        }
      } else {
        \Drupal::logger('paypal_inventory_listener')->error('Payment status is not "Completed".');
      }
    } else {
      // Log invalid IPN or other statuses for debugging purposes.
      \Drupal::logger('paypal_inventory_listener')->error('Invalid IPN or Payment not completed.');
    }

    // Return a 200 response to acknowledge receipt of the IPN.
    return new Response('', 200);
  }

  /**
   * Validates the IPN with PayPal (Production use only).
   */
  private function validateIpn($raw_post_data) {
    $request_body = 'cmd=_notify-validate&' . $raw_post_data;
    $ch = curl_init('https://ipnpb.paypal.com/cgi-bin/webscr');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Connection: Close']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body);
    $response = curl_exec($ch);
    curl_close($ch);

    return strcmp($response, "VERIFIED") == 0;
  }
}
