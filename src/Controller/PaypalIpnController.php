<?php

namespace Drupal\paypal_inventory_listener\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for handling PayPal IPN notifications.
 */
class PaypalIpnController extends ControllerBase {

  /**
   * Inventory adjustment storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $inventoryStorage;

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs the controller.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger) {
    $this->inventoryStorage = $entity_type_manager->getStorage('material_inventory');
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('logger.channel.paypal_inventory_listener')
    );
  }

  /**
   * IPN listener to handle inventory updates after PayPal checkout.
   */
  public function ipnListener(Request $request) {
    // Read the raw POST data from PayPal.
    $raw_post_data = $request->getContent();
    $post_data = [];
    parse_str($raw_post_data, $post_data);

    // For local testing, skip IPN validation.
    $client_ip = $request->getClientIp();
    $is_local = in_array($client_ip, ['127.0.0.1', '::1'], TRUE) || str_contains($request->getHost(), 'lndo.site');

    if ($is_local || $this->validateIpn($raw_post_data)) {
      // Process the IPN if the payment status is 'Completed'.
      if (isset($post_data['payment_status']) && $post_data['payment_status'] === 'Completed') {
        $payer_email = $post_data['payer_email'] ?? '';
        $payer_name = trim(($post_data['first_name'] ?? '') . ' ' . ($post_data['last_name'] ?? ''));

        // Process each item in the cart.
        $item_count = 1;  // PayPal's cart items start with 'item_number1', 'item_number2', etc.
        while (isset($post_data['item_number' . $item_count])) {
          // Extract item data.
          $item_number_key = 'item_number' . $item_count;
          $quantity_key = 'quantity' . $item_count;
          $item_name_key = 'item_name' . $item_count;

          $item_number_raw = $post_data[$item_number_key];
          $quantity_raw = $post_data[$quantity_key] ?? 1;
          $item_name = $post_data[$item_name_key] ?? '';

          if (!is_numeric($item_number_raw)) {
            $this->logger->warning('Skipping PayPal line item with invalid material reference: @item.', ['@item' => $item_number_raw]);
            $item_count++;
            continue;
          }

          $material_id = (int) $item_number_raw;
          $quantity = (int) $quantity_raw;
          if ($quantity <= 0) {
            $this->logger->warning('Skipping PayPal line item for material @nid with non-positive quantity @qty.', [
              '@nid' => $material_id,
              '@qty' => $quantity,
            ]);
            $item_count++;
            continue;
          }

          $this->logger->info('Recording PayPal sale for material @nid, quantity @qty.', [
            '@nid' => $material_id,
            '@qty' => $quantity,
          ]);

          // Create an inventory adjustment entity for each item.
          try {
            // Create the entity.
            $inventory_adjustment = $this->inventoryStorage->create([
              'type' => 'inventory_adjustment',
              'field_inventory_ref_material' => [
                'target_id' => $material_id,
              ],
              // Deduct the quantity from inventory.
              'field_inventory_quantity_change' => [
                'value' => -$quantity,
              ],
              'field_inventory_change_reason' => 'sale',
              'field_inventory_change_memo' => sprintf(
                'Sold to %s (%s) - Item: %s',
                $payer_name ?: 'Unknown buyer',
                $payer_email ?: 'no-email',
                $item_name ?: 'Unknown item'
              ),
            ]);

            // Save the inventory adjustment.
            $inventory_adjustment->save();

            $this->logger->info('Inventory adjustment saved for material @nid.', ['@nid' => $material_id]);
          } catch (EntityStorageException $e) {
            $this->logger->error('Failed to create inventory adjustment for material @nid: @message', [
              '@nid' => $material_id,
              '@message' => $e->getMessage(),
            ]);
          } catch (\Exception $e) {
            $this->logger->error('Unexpected error creating inventory adjustment for material @nid: @message', [
              '@nid' => $material_id,
              '@message' => $e->getMessage(),
            ]);
          }

          // Move to the next item in the cart.
          $item_count++;
        }
      } else {
        $this->logger->error('Payment status is not "Completed".');
      }
    } else {
      // Log invalid IPN or other statuses for debugging purposes.
      $this->logger->error('Invalid IPN or payment not completed.');
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
