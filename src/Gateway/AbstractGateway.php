<?php

declare( strict_types=1 );

namespace Coinsnap\WC\Gateway;

use Coinsnap\Client\Invoice;
use Coinsnap\Client\InvoiceCheckoutOptions;
use Coinsnap\Client\PullPayment;
use Coinsnap\Util\PreciseNumber;
use Coinsnap\WC\Helper\CoinsnapApiHelper;
use Coinsnap\WC\Helper\CoinsnapApiWebhook;
use Coinsnap\WC\Helper\Logger;
use Coinsnap\WC\Helper\OrderStates;

abstract class AbstractGateway extends \WC_Payment_Gateway {
	const ICON_MEDIA_OPTION = 'icon_media_id';
	public $tokenType;
	public $primaryPaymentMethod;
	protected $apiHelper;

	public function __construct() {
		// General gateway setup.
		$this->icon              = $this->getIcon();
		$this->has_fields        = false;
		$this->order_button_text = __( 'Proceed to payment gateway', 'coinsnap-for-woocommerce' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user facing set variables.
		$this->title        = $this->getTitle();
		$this->description  = $this->getDescription();

		$this->apiHelper = new CoinsnapApiHelper();
		// Debugging & informational settings.
		$this->debug_php_version    = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$this->debug_plugin_version = COINSNAP_VERSION;

		// Actions.
		add_action('admin_enqueue_scripts', [$this, 'addAdminScripts']);
		add_action('wp_enqueue_scripts', [$this, 'addPublicScripts']);
		add_action('woocommerce_update_options_payment_gateways_' . $this->getId(), [$this, 'process_admin_options']);

		// Supported features.
		$this->supports = ['products','refunds'];
	}

	//  Initialise Gateway Settings Form Fields
	public function init_form_fields() {
		$this->form_fields = [
			'enabled' => [
				'title'       => __( 'Enabled/Disabled', 'coinsnap-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable this payment gateway.', 'coinsnap-for-woocommerce' ),
				'default'     => 'no',
				'value'       => 'yes',
				'desc_tip'    => false,
			],
			'title'       => [
				'title'       => __( 'Title', 'coinsnap-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Controls the name of this payment method as displayed to the customer during checkout.', 'coinsnap-for-woocommerce' ),
				'default'     => $this->getTitle(),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Customer Message', 'coinsnap-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Message to explain how the customer will be paying for the purchase.', 'coinsnap-for-woocommerce' ),
				'default'     => $this->getDescription(),
				'desc_tip'    => true,
			],
			'icon_upload' => [
				'type'        => 'icon_upload',
			],
		];
	}

//  @inheritDoc
    public function process_payment( $orderId ) {
        if ( ! $this->apiHelper->configured ) {
            Logger::debug( 'Coinsnap API connection not configured, aborting. Please go to Coinsnap Server settings and set it up.' );
            throw new \Exception( __( "Can't process order. Please contact us if the problem persists.", 'coinsnap-for-woocommerce' ) );
	}

	// Load the order and check it.
	$order = new \WC_Order( $orderId );
		if ( $order->get_id() === 0 ) {
			$message = 'Could not load order id ' . $orderId . ', aborting.';
			Logger::debug( $message, true );
			throw new \Exception( $message );
	}

	// Check if the order is a modal payment.
	if (isset($_POST['action'])) {
            $action = wc_clean( wp_unslash( $_POST['action'] ) );
			if ( $action === 'coinsnap_modal_checkout' ) {
				Logger::debug( 'process_payment called via modal checkout.' );
            }
	}

		// Check for existing invoice and redirect instead.
		if ( $this->validInvoiceExists( $orderId ) ) {
			$existingInvoiceId = get_post_meta( $orderId, 'Coinsnap_id', true );
			Logger::debug( 'Found existing Coinsnap invoice and redirecting to it. Invoice id: ' . $existingInvoiceId );

			return [
				'result' => 'success',
				'redirect' => $this->apiHelper->getInvoiceRedirectUrl( $existingInvoiceId ),
				'invoiceId' => $existingInvoiceId,
				'orderCompleteLink' => $order->get_checkout_order_received_url(),
			];
		}

		// Create an invoice.
		Logger::debug( 'Creating invoice on Coinsnap Server' );
		if ( $invoice = $this->createInvoice( $order ) ) {

			// Todo: update order status and Coinsnap meta data.

			Logger::debug( 'Invoice creation successful, redirecting user.' );

			$url = $invoice->getData()['checkoutLink'];
			
			return [
				'result' => 'success',
				'redirect' => $url,
				'invoiceId' => $invoice->getData()['id'],
				'orderCompleteLink' => $order->get_checkout_order_received_url(),
			];
		}
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		// Check if the Coinsnap Server version used supports refunds.
		if (!$this->apiHelper->serverSupportsRefunds()) {
			$errServer = 'Your Coinsnap Server does not support refunds. Make sure to run a Coinsnap Server v1.7.6 or newer.';
			Logger::debug($errServer);
			return new \WP_Error('1', $errServer);
		}

		// Check if the api key has support for refunds, abort if not.
		if (!$this->apiHelper->apiKeyHasRefundPermission()) {
			$errKeyInfo = 'Your current API key does not support refunds. You will need to create a new one with the required permission.';
			Logger::debug(__METHOD__ . ' : The current api key does not support refunds.' );
			return new \WP_Error('1', $errKeyInfo);
		}

		// Abort if no amount.
		if (is_null($amount)) {
			$errAmount = __METHOD__ . ': refund amount is empty, aborting.';
			Logger::debug($errAmount);
			return new \WP_Error('1', $errAmount);
		}

		$order = wc_get_order($order_id);
		$refundAmount = PreciseNumber::parseString($amount);
		$currency = $order->get_currency();

		// Check if order has invoice id.
		if (!$invoiceId = $order->get_meta('Coinsnap_id')) {
			$errNoCoinsnapId = __METHOD__ . ': no Coinsnap invoice id found, aborting.';
			Logger::debug($errNoCoinsnapId);
			return new \WP_Error('1', $errNoCoinsnapId);
		}

		// Make sure the refund amount is not greater than the invoice amount.
		if ($amount > $order->get_remaining_refund_amount()) {
			$errAmount = __METHOD__ . ': the refund amount can not exceed the order amount, aborting.';
			Logger::debug($errAmount);
			return new \WP_Error('1', $errAmount);
		}

		// Create the payout on Coinsnap Server.
		// Handle Sats-mode.
		if ($currency === 'SAT') {
			$currency = 'BTC';
			$amountBTC = bcdiv($refundAmount->__toString(), '100000000', 8);
			$refundAmount = PreciseNumber::parseString($amountBTC);
		}

		// Get payment methods.
		$paymentMethods = $this->getPaymentMethods();
		// Remove LNURL
		if (in_array('BTC_LNURLPAY', $paymentMethods)) {
			$paymentMethods = array_diff($paymentMethods, ['BTC_LNURLPAY']);
		}

		// Create the payout.
		try {
			$client = new PullPayment( $this->apiHelper->url, $this->apiHelper->apiKey);
			// todo: add reason to description with upcoming php lib v3
			$pullPayment = $client->createPullPayment(
				$this->apiHelper->storeId,
				__('Refund for order no.: ', 'coinsnap-for-woocommerce') . $order->get_order_number() . ' reason: ' . $reason,
				$refundAmount,
				$currency,
				null,
				null,
				false, // use setting
				null,
				null,
				array_values($paymentMethods)
			);

			if (!empty($pullPayment)) {
				$refundMsg = "PullPayment ID: " . $pullPayment->getId() . "\n";
				$refundMsg .= "Link: " . $pullPayment->getViewLink() . "\n";
				$refundMsg .= "Amount: " . $amount . " " . $currency . "\n";
				$refundMsg .= "Reason: " . $reason;
				$successMsg = 'Successfully created refund: ' . $refundMsg;

				Logger::debug($successMsg);

				$order->add_order_note($successMsg);
				// Use add_meta_data to allow for partial refunds.
				$order->add_meta_data('Coinsnap_refund', $refundMsg, false);
				$order->save();
				return true;
			} else {
				$errEmptyPullPayment = 'Error creating pull payment. Make sure you have the correct api key permissions.';
				Logger::debug($errEmptyPullPayment, true);
				return new \WP_Error('1', $errEmptyPullPayment);
			}
		} catch (\Throwable $e) {
			$errException = 'Exception creating pull payment: ' . $e->getMessage();
			Logger::debug($errException,true);
			return new \WP_Error('1', $errException);
		}

		return new \WP_Error('1', 'Error processing the refund, please check logs.');
	}

	public function process_admin_options() {
		// Store media id.
		$iconFieldName = 'woocommerce_' . $this->getId() . '_' . self::ICON_MEDIA_OPTION;
		if ($mediaId = sanitize_key($_POST[$iconFieldName])) {
			if ($mediaId !== $this->get_option(self::ICON_MEDIA_OPTION)) {
				$this->update_option(self::ICON_MEDIA_OPTION, $mediaId);
			}
		} else {
			// Reset to empty otherwise.
			$this->update_option(self::ICON_MEDIA_OPTION, '');
		}
		return parent::process_admin_options();
	}

	/**
	 * Generate html for handling icon uploads with media manager.
	 *
	 * Note: `generate_$type_html()` is a pattern you can use from WooCommerce Settings API to render custom fields.
	 */
	public function generate_icon_upload_html() {
		$mediaId = $this->get_option(self::ICON_MEDIA_OPTION);
		$mediaSrc = '';
		if ($mediaId) {
			$mediaSrc = wp_get_attachment_image_src($mediaId)[0];
		}
		$iconFieldName = 'woocommerce_' . $this->getId() . '_' . self::ICON_MEDIA_OPTION;

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo __('Gateway Icon:', 'coinsnap-for-woocommerce'); ?></th>
			<td class="forminp" id="coinsnap_icon">
				<div id="coinsnap_icon_container">
					<input class="coinsnap-icon-button" type="button"
						   name="woocommerce_coinsnap_icon_upload_button"
						   value="<?php echo __('Upload or select icon', 'coinsnap-for-woocommerce'); ?>"
						   style="<?php echo $mediaId ? 'display:none;' : ''; ?>"
					/>
					<img class="coinsnap-icon-image" src="<?php echo esc_url($mediaSrc); ?>" style="<?php echo esc_attr($mediaId) ? '' : 'display:none;'; ?>" />
					<input class="coinsnap-icon-remove" type="button"
						   name="woocommerce_coinsnap_icon_button_remove"
						   value="<?php echo __('Remove image', 'coinsnap-for-woocommerce'); ?>"
						   style="<?php echo $mediaId ? '' : 'display:none;'; ?>"
					/>
					<input class="input-text regular-input coinsnap-icon-value" type="hidden"
						   name="<?php echo esc_attr($iconFieldName); ?>"
						   id="<?php echo esc_attr($iconFieldName); ?>"
						   value="<?php echo esc_attr($mediaId); ?>"
					/>
				</div>
			</td>
		</tr>
        <?php
		return ob_get_clean();
	}

	public function getId(): string {
		return $this->id;
	}

	/**
	 * Get custom gateway icon, if any.
	 */
	public function getIcon(): string {
		$icon = null;
		if ($mediaId = $this->get_option(self::ICON_MEDIA_OPTION)) {
			if ($customIcon = wp_get_attachment_image_src($mediaId)[0]) {
				$icon = $customIcon;
			}
		}

		return $icon ?? COINSNAP_PLUGIN_URL . 'assets/images/bitcoin-lightning-logos.png';
	}

	/**
	 * Add scripts.
	 */
	public function addAdminScripts($hook_suffix) {
		if ($hook_suffix === 'woocommerce_page_wc-settings') {
			wp_enqueue_media();
			wp_register_script(
				'coinsnap_abstract_gateway',
				COINSNAP_PLUGIN_URL . 'assets/js/gatewayIconMedia.js',
				['jquery'],
				COINSNAP_VERSION
			);
			wp_enqueue_script('coinsnap_abstract_gateway');
			wp_localize_script(
				'coinsnap_abstract_gateway',
				'coinsnapGatewayData',
				[
					'buttonText' => __('Use this image', 'coinsnap-for-woocommerce'),
					'titleText' => __('Insert image', 'coinsnap-for-woocommerce'),
				]
			);
		}
	}

	public function addPublicScripts() {
		// We only load the modal checkout scripts when enabled.
		if (get_option('coinsnap_modal_checkout') !== 'yes') {
			return;
		}

		if ($this->apiHelper->configured === false) {
			return;
		}

		
	}

	/**
	 * Process webhooks from Coinsnap.
	 */
            public function processWebhook() {
		if ($rawPostData = file_get_contents("php://input")) {
			//  Validate webhook request.
			//  X-Coinsnap-Sig type: string
                        //  HMAC signature of the body using the webhook's secret
			$headers = getallheaders();                        
                        
			foreach ($headers as $key => $value) {
				if (strtolower($key) === 'x-coinsnap-sig') {
					$signature = $value;
				}
			}
                        
                        if (!isset($signature) || !$this->apiHelper->validWebhookRequest($signature, $rawPostData)) {
				Logger::debug('Failed to validate signature of webhook request.');
				wp_die('Webhook request validation failed.');
			}

			try {
				$postData = json_decode($rawPostData, false, 512, JSON_THROW_ON_ERROR);

				if (!isset($postData->invoiceId)) {
					Logger::debug('No Coinsnap invoiceId provided, aborting.');
					wp_die('No Coinsnap invoiceId provided, aborting.');
				}

				// Load the order by metadata field Coinsnap_id
				$orders = wc_get_orders([
					'meta_key' => 'Coinsnap_id',
					'meta_value' => $postData->invoiceId
				]);

				// Abort if no orders found.
				if (count($orders) === 0) {
					Logger::debug('Could not load order by Coinsnap invoiceId: ' . $postData->invoiceId);
					// Note: we return status 200 here for wp_die() which seems counter intuative but needs to be done
					// to not clog up the Coinsnap servers webhook processing queue until it is fixed there.
					wp_die('No order found for this invoiceId.', '', ['response' => 200]);
				}

				// Abort on multiple orders found.
				if (count($orders) > 1) {
					Logger::debug('Found multiple orders for invoiceId: ' . $postData->invoiceId);
					Logger::debug(print_r($orders, true));
					wp_die('Multiple orders found for this invoiceId, aborting.');
				}

				$this->processOrderStatus($orders[0], $postData);

			} catch (\Throwable $e) {
				Logger::debug('Error decoding webook payload: ' . $e->getMessage());
				Logger::debug($rawPostData);
			}
		}
	}

	protected function processOrderStatus(\WC_Order $order, \stdClass $webhookData) {
		if (!in_array($webhookData->type, CoinsnapApiWebhook::WEBHOOK_EVENTS)) {
			Logger::debug('Webhook event received but ignored: ' . $webhookData->type);
			return;
		}

		Logger::debug('Updating order status with webhook event received for processing: ' . $webhookData->type);
		// Get configured order states or fall back to defaults.
		if (!$configuredOrderStates = get_option('coinsnap_order_states')) {
			$configuredOrderStates = (new OrderStates())->getDefaultOrderStateMappings();
		}

		switch ($webhookData->type) {
			case 'New':
				if ($webhookData->afterExpiration) {
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::EXPIRED_PAID_PARTIAL]);
					$order->add_order_note(__('Invoice (partial) payment incoming (unconfirmed) after invoice was already expired.', 'coinsnap-for-woocommerce'));
				} else {
					// No need to change order status here, only leave a note.
					$order->add_order_note(__('Invoice (partial) payment incoming (unconfirmed). Waiting for settlement.', 'coinsnap-for-woocommerce'));
				}

				// Store payment data (exchange rate, address).
				//$this->updateWCOrderPayments($order);
				break;
			
			case 'Settled':
				$order->payment_complete();
				if ($webhookData->overPaid) {
					$order->add_order_note(__('Invoice payment settled but was overpaid.', 'coinsnap-for-woocommerce'));
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::SETTLED_PAID_OVER]);
				} else {
					$order->add_order_note(__('Invoice payment settled.', 'coinsnap-for-woocommerce'));
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::SETTLED]);
				}

				// Store payment data (exchange rate, address).
				//$this->updateWCOrderPayments($order);

				break;
			case 'Processing': // The invoice is paid in full.
				$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::PROCESSING]);
				if ($webhookData->overPaid) {
					$order->add_order_note(__('Invoice payment received fully with overpayment, waiting for settlement.', 'coinsnap-for-woocommerce'));
				} else {
					$order->add_order_note(__('Invoice payment received fully, waiting for settlement.', 'coinsnap-for-woocommerce'));
				}
				break;
			case 'Expired':
				if ($webhookData->partiallyPaid) {
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::EXPIRED_PAID_PARTIAL]);
					$order->add_order_note(__('Invoice expired but was paid partially, please check.', 'coinsnap-for-woocommerce'));
				} else {
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::EXPIRED]);
					$order->add_order_note(__('Invoice expired.', 'coinsnap-for-woocommerce'));
				}
				break;
			case 'Invalid':
				$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::INVALID]);
				if ($webhookData->manuallyMarked) {
					$order->add_order_note(__('Invoice manually marked invalid.', 'coinsnap-for-woocommerce'));
				} else {
					$order->add_order_note(__('Invoice became invalid.', 'coinsnap-for-woocommerce'));
				}
				break;
		}
	}

	/**
	 * Checks if the order has already a Coinsnap invoice set and checks if it is still
	 * valid to avoid creating multiple invoices for the same order on Coinsnap Server end.
	 *
	 * @param int $orderId
	 *
	 * @return mixed Returns false if no valid invoice found or the invoice id.
	 */
	protected function validInvoiceExists( int $orderId ): bool {
		// Check order metadata for Coinsnap_id.
		if ( $invoiceId = get_post_meta( $orderId, 'Coinsnap_id', true ) ) {
			// Validate the order status on Coinsnap server.
			$client = new Invoice( $this->apiHelper->url, $this->apiHelper->apiKey );
			try {
				Logger::debug( 'Trying to fetch existing invoice from Coinsnap Server.' );
				$invoice       = $client->getInvoice( $this->apiHelper->storeId, $invoiceId );
				$invalidStates = [ 'Expired', 'Invalid' ];
				if ( in_array( $invoice->getData()['status'], $invalidStates ) ) {
					return false;
				} else {
					// Check also if the payment methods match, only needed if separate payment methods enabled.
					if (get_option('coinsnap_separate_gateways') === 'yes') {
						$pmInvoice = $invoice->getData()['checkout']['paymentMethods'];
						$pmInvoice = str_replace('-', '_', $pmInvoice);
						sort($pmInvoice);
                                                
                                                /*
						$pm = $this->getPaymentMethods();
						sort($pm);
						if ($pm === $pmInvoice) return true;
                                                */
						
                                                // Mark existing invoice as invalid.
						$order = wc_get_order($orderId);
						$order->add_order_note(__('Coinsnap invoice manually set to invalid because customer went back to checkout and changed payment gateway.', 'coinsnap-for-woocommerce'));
						$this->markInvoiceInvalid($invoiceId);
						return false;
					}
					return true;
				}
			} catch ( \Throwable $e ) {
				Logger::debug( $e->getMessage() );
			}
		}

		return false;
	}

	public function markInvoiceInvalid($invoiceId): void {
		Logger::debug( 'Marking invoice as invalid: ' . $invoiceId);
		try {
			$client = new Invoice( $this->apiHelper->url, $this->apiHelper->apiKey );
			$client->markInvoiceStatus($this->apiHelper->storeId, $invoiceId, 'Invalid');
		} catch (\Throwable $e) {
			Logger::debug('Error marking invoice invalid: ' . $e->getMessage());
		}
	}

	/**
	 * Update WC order status (if a valid mapping is set).
	 */
	public function updateWCOrderStatus(\WC_Order $order, string $status): void {
		if ($status !== OrderStates::IGNORE) {
			Logger::debug('Updating order status from ' . $order->get_status() . ' to ' . $status);
			$order->update_status($status);
		}
	}

	public function updateWCOrderPayments(\WC_Order $order): void {
		// Load payment data from API.
		try {
			$client = new Invoice( $this->apiHelper->url, $this->apiHelper->apiKey );
			$allPaymentData = $client->getPaymentMethods($this->apiHelper->storeId, $order->get_meta('Coinsnap_id'));

			foreach ($allPaymentData as $payment) {
				// Only continue if the payment method has payments made.
				if ((float) $payment->getPaymentMethodPaid() > 0.0) {
					$paymentMethodName = $payment->getPaymentMethod();
					// Update order meta data with payment methods and transactions.
					update_post_meta( $order->get_id(), "Coinsnap_{$paymentMethodName}_total_paid", $payment->getTotalPaid() ?? '' );
					update_post_meta( $order->get_id(), "Coinsnap_{$paymentMethodName}_total_amount", $payment->getAmount() ?? '' );
					update_post_meta( $order->get_id(), "Coinsnap_{$paymentMethodName}_total_due", $payment->getDue() ?? '' );
					update_post_meta( $order->get_id(), "Coinsnap_{$paymentMethodName}_total_fee", $payment->getNetworkFee() ?? '' );
					update_post_meta( $order->get_id(), "Coinsnap_{$paymentMethodName}_rate", $payment->getRate() ?? '' );
					if ((float) $payment->getRate() > 0.0) {
						$formattedRate = number_format((float) $payment->getRate(), wc_get_price_decimals(), wc_get_price_decimal_separator(), wc_get_price_thousand_separator());
						update_post_meta( $order->get_id(), "Coinsnap_{$paymentMethodName}_rateFormatted", $formattedRate );
					}

					// For each actual payment make a separate entry to make sense of it.
					foreach ($payment->getPayments() as $index => $trx) {
						update_post_meta( $order->get_id(), "Coinsnap_{$paymentMethodName}_{$index}_id", $trx->getTransactionId() ?? '' );
						update_post_meta( $order->get_id(), "Coinsnap_{$paymentMethodName}_{$index}_timestamp", $trx->getReceivedTimestamp() ?? '' );
						update_post_meta( $order->get_id(), "Coinsnap_{$paymentMethodName}_{$index}_destination", $trx->getDestination() ?? '' );
						update_post_meta( $order->get_id(), "Coinsnap_{$paymentMethodName}_{$index}_amount", $trx->getValue() ?? '' );
						update_post_meta( $order->get_id(), "Coinsnap_{$paymentMethodName}_{$index}_status", $trx->getStatus() ?? '' );
						update_post_meta( $order->get_id(), "Coinsnap_{$paymentMethodName}_{$index}_networkFee", $trx->getFee() ?? '' );
					}
				}
			}
		} catch (\Throwable $e) {
			Logger::debug( 'Error processing payment data for invoice: ' . $order->get_meta('Coinsnap_id') . ' and order ID: ' . $order->get_id() );
			Logger::debug($e->getMessage());
		}
	}

	/**
	 * Create an invoice on Coinsnap Server.
	 */
	public function createInvoice( \WC_Order $order ): ?\Coinsnap\Result\Invoice {
		// In case some plugins customizing the order number we need to pass that along, defaults to internal ID.
		$orderNumber = $order->get_order_number();
		Logger::debug( 'Got order number: ' . $orderNumber . ' and order ID: ' . $order->get_id() );

		$metadata = [];
                

		// Send customer data only if option is set.
		if ( get_option( 'coinsnap_send_customer_data' ) === 'yes' ) {
			$metadata = $this->prepareCustomerMetadata( $order );
		}
                
                
                $buyerEmail = $this->prepareCustomerMetadata( $order )['buyerEmail'];
                $buyerName = $this->prepareCustomerMetadata( $order )['buyerName'];

		// Set included tax amount.
		$metadata['taxIncluded'] = $order->get_cart_tax();

		// POS metadata.
		$metadata['posData'] = $this->preparePosMetadata( $order );

		// Checkout options.
		$checkoutOptions = new InvoiceCheckoutOptions();
		$redirectUrl     = $this->get_return_url( $order );
		$checkoutOptions->setRedirectURL( $redirectUrl );
		Logger::debug( 'Setting redirect url to: ' . $redirectUrl );

		         

		// Payment methods.
		if ($paymentMethods = $this->getPaymentMethods()) {
			$checkoutOptions->setPaymentMethods($paymentMethods);
		}

		// Handle payment methods of type "promotion".
		// Promotion type set 1 token per each quantity.
		if ($this->getTokenType() === 'promotion') {
			$currency = $this->primaryPaymentMethod ?? null;
			$amount = PreciseNumber::parseInt( $this->getOrderTotalItemsQuantity($order));
		} else { // Defaults.
			$currency = $order->get_currency();
			$amount = PreciseNumber::parseString( $order->get_total() ); // unlike method signature suggests, it returns string.
		}

		// Handle Sats-mode.
		// Because Coinsnap does not understand SAT as a currency we need to change to BTC and adjust the amount.
		if ($currency === 'SAT') {
			$currency = 'BTC';
			$amountBTC = bcdiv($amount->__toString(), '100000000', 8);
			$amount = PreciseNumber::parseString($amountBTC);
		}

		// Create the invoice on Coinsnap Server.
		$client = new Invoice( $this->apiHelper->url, $this->apiHelper->apiKey );
		try {
			$invoice = $client->createInvoice(
				$this->apiHelper->storeId,  //$storeId
				$currency,                  //$currency
				$amount,                    //$amount
				$orderNumber,               //$orderId
                                $buyerEmail,                //$buyerEmail
                                $buyerName,                 //$customerName
                                $redirectUrl,               //$redirectUrl
                                COINSNAP_REFERRAL_CODE,     //$referralCode
				$metadata,
				$checkoutOptions
			);

			$this->updateOrderMetadata( $order->get_id(), $invoice );

			return $invoice;

		} catch ( \Throwable $e ) {
			Logger::debug( $e->getMessage(), true );
			// todo: should we throw exception here to make sure there is an visible error on the page and not silently failing?
		}

		return null;
	}

	/**
	 * Maps customer billing metadata.
	 */
	protected function prepareCustomerMetadata( \WC_Order $order ): array {
		return [
			'buyerEmail'    => $order->get_billing_email(),
			'buyerName'     => $order->get_formatted_billing_full_name(),
			'buyerAddress1' => $order->get_billing_address_1(),
			'buyerAddress2' => $order->get_billing_address_2(),
			'buyerCity'     => $order->get_billing_city(),
			'buyerState'    => $order->get_billing_state(),
			'buyerZip'      => $order->get_billing_postcode(),
			'buyerCountry'  => $order->get_billing_country()
		];
	}

	/**
	 * Maps POS metadata.
	 */
	protected function preparePosMetadata( $order ): string {
		$posData = [
			'WooCommerce' => [
				'Order ID'       => $order->get_id(),
				'Order Number'   => $order->get_order_number(),
				'Order URL'      => $order->get_edit_order_url(),
				'Plugin Version' => constant( 'COINSNAP_VERSION' )
			]
		];

		return json_encode( $posData, JSON_THROW_ON_ERROR );
	}

	/**
	 * References WC order metadata with Coinsnap invoice data.
	 */
	protected function updateOrderMetadata( int $orderId, \Coinsnap\Result\Invoice $invoice ) {
		// Store relevant Coinsnap invoice data.
		update_post_meta( $orderId, 'Coinsnap_redirect', $invoice->getData()['checkoutLink'] );
		update_post_meta( $orderId, 'Coinsnap_id', $invoice->getData()['id'] );
	}

	/**
	 * Return the total quantity of the whole order for all line items.
	 */
	public function getOrderTotalItemsQuantity(\WC_Order $order): int {
		$total = 0;
		foreach ($order->get_items() as $item ) {
			$total += $item->get_quantity();
		}

		return $total;
	}

	/**
	 * Get customer visible gateway title.
	 */
	public function getTitle(): string {
		return $this->get_option('title', 'Bitcoin, Lightning Network');
	}

	/**
	 * Get customer facing gateway description.
	 */
	public function getDescription(): string {
		return $this->get_option('description', 'You will be redirected to the Bitcoin Payment Page to complete your purchase');
	}

	/**
	 * Get type of Coinsnap payment method/token as configured. Can be payment or promotion.
	 */
	public function getTokenType(): string {
		return $this->get_option('token_type', 'payment');
	}

	/**
	 * Get allowed Coinsnap payment methods (needed for limiting invoices to specific payment methods).
	 */
	abstract public function getPaymentMethods(): array;
}
