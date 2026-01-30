<?php

namespace Drupal\paypal_inventory_listener\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
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
   * Key/value factory.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface
   */
  protected KeyValueFactoryInterface $keyValueFactory;

  /**
   * Time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * Constructs the controller.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger, KeyValueFactoryInterface $key_value_factory, TimeInterface $time) {
    $this->inventoryStorage = $entity_type_manager->getStorage('material_inventory');
    $this->logger = $logger;
    $this->keyValueFactory = $key_value_factory;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('logger.channel.paypal_inventory_listener'),
      $container->get('keyvalue'),
      $container->get('datetime.time')
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
        $transaction_id = $post_data['txn_id'] ?? '';
        if ($transaction_id === '') {
          $this->logger->warning('Skipping PayPal IPN with missing txn_id.');
          return new Response('', 200);
        }

        $accepted_types = ['cart', 'web_accept'];
        $txn_type = $post_data['txn_type'] ?? '';
        if ($txn_type !== '' && !in_array($txn_type, $accepted_types, TRUE)) {
          $this->logger->warning('Skipping PayPal IPN with unsupported txn_type @type (txn_id @txn).', [
            '@type' => $txn_type,
            '@txn' => $transaction_id,
          ]);
          return new Response('', 200);
        }

        $currency = $post_data['mc_currency'] ?? '';
        if ($currency !== '' && $currency !== 'USD') {
          $this->logger->warning('Skipping PayPal IPN with unexpected currency @currency (txn_id @txn).', [
            '@currency' => $currency,
            '@txn' => $transaction_id,
          ]);
          return new Response('', 200);
        }

        $configured_business = $this->config('makerspace_material_store.settings')->get('paypal_business_id');
        if (!empty($configured_business)) {
          $receiver_email = $post_data['receiver_email'] ?? '';
          $business_email = $post_data['business'] ?? '';
          $receiver_id = $post_data['receiver_id'] ?? '';
          $matches = in_array($configured_business, [$receiver_email, $business_email, $receiver_id], TRUE);
          if (!$matches) {
            $this->logger->warning('Skipping PayPal IPN with mismatched business receiver (txn_id @txn).', [
              '@txn' => $transaction_id,
            ]);
            return new Response('', 200);
          }
        }

        $store = $this->keyValueFactory->get('paypal_inventory_listener');
        $ipn_track_id = $post_data['ipn_track_id'] ?? '';
        if ($store->has('txn_id:' . $transaction_id) || ($ipn_track_id !== '' && $store->has('ipn_track_id:' . $ipn_track_id))) {
          $this->logger->notice('Skipping duplicate PayPal IPN (txn_id @txn).', ['@txn' => $transaction_id]);
          return new Response('', 200);
        }

        $payer_email = $post_data['payer_email'] ?? '';
        $payer_name = trim(($post_data['first_name'] ?? '') . ' ' . ($post_data['last_name'] ?? ''));
        
        // Parse custom field.
        $custom_raw = $post_data['custom'] ?? '';
        $custom_data = [];
        if ($custom_raw !== '') {
          $decoded = json_decode($custom_raw, TRUE);
          if (is_array($decoded)) {
            $custom_data = $decoded;
          }
        }
        $custom_uid = $custom_data['uid'] ?? $custom_raw; // Fallback to raw if not JSON
        $transaction_type = $custom_data['type'] ?? 'unknown';

        // Handle single item transactions (e.g. "Buy Now" buttons) by normalizing to cart format.
        if (!isset($post_data['item_number1']) && isset($post_data['item_number'])) {
          $post_data['item_number1'] = $post_data['item_number'];
          $post_data['quantity1'] = $post_data['quantity'] ?? 1;
          $post_data['item_name1'] = $post_data['item_name'] ?? '';
        }

        // Process each item in the cart.
        $item_count = 1;  // PayPal's cart items start with 'item_number1', 'item_number2', etc.
        $created_adjustment = FALSE;
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

          // Determine quantity change.
          // If this is a tab checkout, the inventory was already deducted when added to tab.
          // So we record a 0 change here, just to log the sale and trigger reconciliation.
          $qty_change = ($transaction_type === 'tab_checkout') ? 0 : -$quantity;

          // Create an inventory adjustment entity for each item.
          try {
            // Construct memo with UID if present.
            $memo = sprintf(
              'Sold to %s (%s) - Item: %s',
              $payer_name ?: 'Unknown buyer',
              $payer_email ?: 'no-email',
              $item_name ?: 'Unknown item'
            );
            if ($custom_uid) {
              $memo = "[UID:$custom_uid] " . $memo;
            }

            // Create the entity.
            $inventory_adjustment = $this->inventoryStorage->create([
              'type' => 'inventory_adjustment',
              'field_inventory_ref_material' => [
                'target_id' => $material_id,
              ],
              // Deduct the quantity from inventory (or 0 if tab).
              'field_inventory_quantity_change' => [
                'value' => $qty_change,
              ],
              'field_inventory_change_reason' => 'sale',
              'field_inventory_change_memo' => $memo,
            ]);

            // Save the inventory adjustment.
            $inventory_adjustment->save();
            $created_adjustment = TRUE;

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

        if ($created_adjustment) {
          $store->set('txn_id:' . $transaction_id, $this->time->getRequestTime());
          if ($ipn_track_id !== '') {
            $store->set('ipn_track_id:' . $ipn_track_id, $this->time->getRequestTime());
          }
        } else {
          $this->logger->warning('PayPal IPN did not create inventory adjustments (txn_id @txn).', [
            '@txn' => $transaction_id,
          ]);
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
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    if ($response === false) {
      $error = curl_error($ch);
      curl_close($ch);
      $this->logger->error('PayPal IPN validation request failed: @error', ['@error' => $error]);
      return FALSE;
    }
    curl_close($ch);

    $response = trim($response);
    if ($response !== 'VERIFIED') {
      $this->logger->warning('PayPal IPN validation returned @response.', ['@response' => $response]);
    }
    return strcmp($response, 'VERIFIED') === 0;
  }

}
