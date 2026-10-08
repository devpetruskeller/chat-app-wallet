<?php
/**
 * Plugin Name: PosTooChat Chat App Wallet
 * Description: Shared commercial wallet and one-use billing authorization service for PosTooChat apps.
 * Version: 0.2.1
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: PosTooChat
 * Text Domain: chat-app-wallet
 */

defined( 'ABSPATH' ) || exit;

final class PTC_Chat_App_Wallet {
	const DEFAULT_TIMEZONE = 'Africa/Johannesburg';
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_post_ptc_wallet_revoke_access', array( $this, 'handle_revoke_access' ) );
	}

	/** Public provider endpoint; PayPal authenticity is verified inside the callback. */
	public function register_rest_routes() {
		register_rest_route( 'chat-app-wallet/v1', '/identity-provision', array( 'methods' => 'POST', 'callback' => array( $this, 'provision_identity_endpoint' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'chat-app-wallet/v1', '/overview', array( 'methods' => 'POST', 'callback' => array( $this, 'overview_endpoint' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'chat-app-wallet/v1', '/authorization-outcome', array( 'methods' => 'POST', 'callback' => array( $this, 'authorization_outcome_endpoint' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'chat-app-wallet/v1', '/paypal/webhook', array(
			'methods' => 'POST',
			'callback' => array( $this, 'handle_paypal_webhook' ),
			'permission_callback' => '__return_true',
		) );
	}
	/** Restricted SB Coms boundary. It returns presentation variables only. */
	public function overview_endpoint( WP_REST_Request $request ) {
		$expected = defined( 'PTC_WALLET_OVERVIEW_TOKEN' ) ? PTC_WALLET_OVERVIEW_TOKEN : '';
		$actual = preg_replace( '/^Bearer\s+/i', '', (string) $request->get_header( 'authorization' ) );
		if ( ! $expected || ! hash_equals( $expected, $actual ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'unauthorized' ), 401 );
		$body = $request->get_json_params(); $channel = sanitize_key( $body['channel'] ?? '' ); $identity = sanitize_text_field( $body['identity'] ?? '' );
		if ( ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || 0 !== strpos( $identity, $channel . ':' ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_identity' ), 422 );
		$provisioned = $this->provision_owned_wallet( $channel, $identity );
		if ( is_wp_error( $provisioned ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $provisioned->get_error_code() ), 503 );
		global $wpdb; $wallets = $wpdb->prefix . 'ptc_wallet_wallets'; $entities = $wpdb->prefix . 'ptc_wallet_entities'; $ledger = $wpdb->prefix . 'ptc_wallet_ledger'; $grants = $wpdb->prefix . 'ptc_wallet_access_grants';
		$wallet = $wpdb->get_row( $wpdb->prepare( "SELECT w.wallet_id, w.timezone FROM $wallets w JOIN $entities e ON e.entity_id=w.entity_id WHERE e.identity_key=%s AND w.channel=%s AND w.status='active'", $identity, $channel ), ARRAY_A );
		$wallet_id = $wallet['wallet_id'] ?? ''; $timezone = $wallet['timezone'] ?? self::DEFAULT_TIMEZONE;
		try { $as_of = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( $timezone ) )->format( 'Y-m-d H:i T' ); } catch ( Exception $error ) { $as_of = gmdate( 'Y-m-d H:i' ) . ' UTC'; }
		$balance = $wallet_id ? (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(credits),0) FROM $ledger WHERE wallet_id=%s", $wallet_id ) ) : 0.0;
		$shared = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $grants WHERE identity_key=%s AND status='active'", $identity ) );
		return new WP_REST_Response( array( 'ok' => true, 'variables' => array( 'as_of_display' => $as_of, 'owned_credit_display' => number_format_i18n( $balance, 3 ) . ' CRD', 'owned_wallet_label' => 'Your Wallet', 'owned_wallet_status' => 'Active', 'return_app_label' => 'Return to app', 'shared_access_count' => (string) (int) $shared, 'shared_access_summary' => (int) $shared ? 'Shared access available.' : 'No shared wallet access.' ) ), 200 );
	}
	public function authorization_outcome_endpoint( WP_REST_Request $request ) {
		$secret = defined( 'PTC_WALLET_AUTHORIZATION_SIGNING_SECRET' ) ? PTC_WALLET_AUTHORIZATION_SIGNING_SECRET : '';
		$raw = $request->get_body(); $provided = (string) $request->get_header( 'x-postoo-wallet-outcome-signature' );
		if ( ! $secret || ! $provided || ! hash_equals( hash_hmac( 'sha256', $raw, $secret ), $provided ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_outcome_signature' ), 401 );
		$body = json_decode( $raw, true ); $authorization_id = sanitize_text_field( $body['authorizationId'] ?? '' ); $outcome = sanitize_key( $body['outcome'] ?? '' );
		if ( ! preg_match( '/^[0-9a-f-]{36}$/i', $authorization_id ) || ! in_array( $outcome, array( 'accepted', 'release' ), true ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_outcome' ), 422 );
		global $wpdb; $authorizations = $wpdb->prefix . 'ptc_wallet_authorizations'; $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $authorizations WHERE authorization_id=%s", $authorization_id ), ARRAY_A );
		if ( ! $row ) return new WP_REST_Response( array( 'ok' => true, 'unknown' => true ), 200 );
		if ( 'reserved' !== $row['state'] ) return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
		$event = 'accepted' === $outcome ? 'consume' : 'release'; $credits = 'release' === $outcome ? (float) $row['reserved_credits'] : 0;
		$wpdb->insert( $wpdb->prefix . 'ptc_wallet_ledger', array( 'ledger_id' => wp_generate_uuid4(), 'wallet_id' => $row['wallet_id'], 'event_type' => $event, 'credits' => $credits, 'reference_id' => $authorization_id, 'reason' => 'sb_coms_' . $outcome, 'created_at' => current_time( 'mysql', true ) ) );
		$wpdb->update( $authorizations, array( 'state' => $event ), array( 'authorization_id' => $authorization_id, 'state' => 'reserved' ) );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/** Minimal SB Coms boundary: channel identity only, no Suite profile data. */
	public function provision_identity_endpoint( WP_REST_Request $request ) {
		$expected = defined( 'PTC_WALLET_IDENTITY_PROVISION_TOKEN' ) ? PTC_WALLET_IDENTITY_PROVISION_TOKEN : '';
		$actual = preg_replace( '/^Bearer\\s+/i', '', (string) $request->get_header( 'authorization' ) );
		if ( ! $expected || ! hash_equals( $expected, $actual ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'unauthorized' ), 401 );
		$body = $request->get_json_params();
		$result = $this->provision_owned_wallet( sanitize_key( $body['channel'] ?? '' ), sanitize_text_field( $body['identity'] ?? '' ) );
		return is_wp_error( $result ) ? new WP_REST_Response( array( 'ok' => false, 'error' => $result->get_error_code() ), 422 ) : new WP_REST_Response( array( 'ok' => true, 'ownedWalletProfile' => $result ), 200 );
	}

	public function provision_owned_wallet( $channel, $identity ) {
		if ( ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || 0 !== strpos( $identity, $channel . ':' ) ) return new WP_Error( 'wallet_invalid_identity' );
		global $wpdb; $entities = $wpdb->prefix . 'ptc_wallet_entities'; $wallets = $wpdb->prefix . 'ptc_wallet_wallets';
		$entity = $wpdb->get_var( $wpdb->prepare( "SELECT entity_id FROM $entities WHERE identity_key = %s", $identity ) );
		if ( ! $entity ) { $entity = wp_generate_uuid4(); if ( ! $wpdb->insert( $entities, array( 'entity_id' => $entity, 'entity_type' => 'owned_identity', 'display_name' => $identity, 'identity_key' => $identity, 'created_at' => current_time( 'mysql', true ) ) ) ) return new WP_Error( 'wallet_profile_store_failed' ); }
		$wallet = $wpdb->get_var( $wpdb->prepare( "SELECT wallet_id FROM $wallets WHERE entity_id = %s AND channel = %s", $entity, $channel ) );
		if ( ! $wallet ) { $wallet = wp_generate_uuid4(); if ( ! $wpdb->insert( $wallets, array( 'wallet_id' => $wallet, 'entity_id' => $entity, 'channel' => $channel, 'currency' => 'CRD', 'timezone' => self::DEFAULT_TIMEZONE, 'status' => 'active', 'created_at' => current_time( 'mysql', true ) ) ) ) return new WP_Error( 'wallet_profile_store_failed' ); }
		return array( 'identity' => $identity, 'wallet_reference' => hash( 'sha256', $wallet ) );
	}

	public function handle_paypal_webhook( WP_REST_Request $request ) {
		$client_id = defined( 'PTC_WALLET_PAYPAL_CLIENT_ID' ) ? trim( PTC_WALLET_PAYPAL_CLIENT_ID ) : '';
		$client_secret = defined( 'PTC_WALLET_PAYPAL_CLIENT_SECRET' ) ? trim( PTC_WALLET_PAYPAL_CLIENT_SECRET ) : '';
		$webhook_id = defined( 'PTC_WALLET_PAYPAL_WEBHOOK_ID' ) ? trim( PTC_WALLET_PAYPAL_WEBHOOK_ID ) : '';
		if ( ! $client_id || ! $client_secret || ! $webhook_id ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'paypal_not_configured' ), 503 );
		$raw = $request->get_body();
		$event = json_decode( $raw, true );
		$event_id = sanitize_text_field( (string) ( $event['id'] ?? '' ) );
		if ( ! is_array( $event ) || ! $event_id ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_paypal_event' ), 422 );
		$verified = $this->verify_paypal_webhook( $request, $event, $client_id, $client_secret, $webhook_id );
		if ( is_wp_error( $verified ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $verified->get_error_code() ), 401 );

		global $wpdb;
		$receipts = $wpdb->prefix . 'ptc_wallet_provider_receipts';
		$already_received = $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM $receipts WHERE provider = %s AND provider_event_id = %s",
			'paypal',
			$event_id
		) );
		if ( $already_received ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
		}
		$inserted = $wpdb->insert( $receipts, array( 'provider' => 'paypal', 'provider_event_id' => $event_id, 'event_type' => sanitize_text_field( (string) ( $event['event_type'] ?? '' ) ), 'payload' => wp_json_encode( $event ), 'received_at' => current_time( 'mysql', true ) ) );
		if ( ! $inserted ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'paypal_receipt_store_failed' ), 503 );
		// Credit is deliberately not granted here. A later checkout implementation
		// must first bind a verified PayPal order/capture to a Wallet purchase.
		return new WP_REST_Response( array( 'ok' => true, 'duplicate' => false ), 200 );
	}

	private function verify_paypal_webhook( $request, $event, $client_id, $client_secret, $webhook_id ) {
		$token_response = wp_remote_post( 'https://api-m.sandbox.paypal.com/v1/oauth2/token', array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ), 'Accept' => 'application/json' ),
			'body' => array( 'grant_type' => 'client_credentials' ),
		) );
		if ( is_wp_error( $token_response ) || 200 !== wp_remote_retrieve_response_code( $token_response ) ) return new WP_Error( 'paypal_token_verification_failed' );
		$token = json_decode( wp_remote_retrieve_body( $token_response ), true );
		$access_token = is_array( $token ) ? (string) ( $token['access_token'] ?? '' ) : '';
		if ( ! $access_token ) return new WP_Error( 'paypal_token_verification_failed' );
		$verification = wp_remote_post( 'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature', array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $access_token, 'Content-Type' => 'application/json' ),
			'body' => wp_json_encode( array(
				'auth_algo' => (string) $request->get_header( 'paypal-auth-algo' ), 'cert_url' => esc_url_raw( (string) $request->get_header( 'paypal-cert-url' ) ),
				'transmission_id' => sanitize_text_field( (string) $request->get_header( 'paypal-transmission-id' ) ), 'transmission_sig' => sanitize_text_field( (string) $request->get_header( 'paypal-transmission-sig' ) ),
				'transmission_time' => sanitize_text_field( (string) $request->get_header( 'paypal-transmission-time' ) ), 'webhook_id' => $webhook_id, 'webhook_event' => $event,
			) ),
		) );
		if ( is_wp_error( $verification ) || 200 !== wp_remote_retrieve_response_code( $verification ) ) return new WP_Error( 'paypal_signature_verification_failed' );
		$result = json_decode( wp_remote_retrieve_body( $verification ), true );
		return is_array( $result ) && 'SUCCESS' === ( $result['verification_status'] ?? '' ) ? true : new WP_Error( 'paypal_signature_invalid' );
	}

	/**
	 * Registered in-server service for chat apps. It reserves one wallet unit and
	 * returns a short-lived HMAC ticket for SB Coms; it never makes local HTTP calls.
	 */
	public function authorize_message( $request ) {
		if ( ! is_array( $request ) ) return new WP_Error( 'wallet_invalid_request' );
		$forbidden = array_intersect( array_keys( $request ), array( 'wallet_id', 'payer', 'price', 'balance', 'credit_limit', 'shared_wallet_preference', 'entity_id', 'meter' ) );
		if ( $forbidden ) return new WP_Error( 'wallet_commercial_input_forbidden' );
		$app_id = sanitize_key( $request['app_id'] ?? '' );
		$channel = sanitize_key( $request['channel'] ?? '' );
		$meter = sanitize_key( $request['billing_meter'] ?? '' );
		$idempotency_key = sanitize_text_field( $request['idempotency_key'] ?? '' );
		$subject = sanitize_text_field( $request['subject_identity'] ?? '' ); $recipient = sanitize_text_field( $request['recipient_identity'] ?? '' ); $reference = sanitize_text_field( $request['business_reference'] ?? '' );
		if ( ! $app_id || ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || ! $meter || ! $idempotency_key || ! $reference || 0 !== strpos( $subject, $channel . ':' ) || 0 !== strpos( $recipient, $channel . ':' ) ) {
			return new WP_Error( 'wallet_invalid_authorization_context' );
		}
		$secret = defined( 'PTC_WALLET_AUTHORIZATION_SIGNING_SECRET' ) ? trim( PTC_WALLET_AUTHORIZATION_SIGNING_SECRET ) : '';
		if ( strlen( $secret ) < 32 ) return new WP_Error( 'wallet_not_configured' );

		global $wpdb;
		$authorizations = $wpdb->prefix . 'ptc_wallet_authorizations'; $grants = $wpdb->prefix . 'ptc_wallet_access_grants';
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $authorizations WHERE app_id = %s AND idempotency_key = %s", $app_id, $idempotency_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing && strtotime( $existing['expires_at'] ) > time() ) return array( 'ok' => true, 'claims' => json_decode( $existing['claims'], true ), 'reused' => true );
		$this->provision_owned_wallet( $channel, $subject );
		$wallet_id = $wpdb->get_var( $wpdb->prepare( "SELECT w.wallet_id FROM {$wpdb->prefix}ptc_wallet_wallets w JOIN {$wpdb->prefix}ptc_wallet_entities e ON e.entity_id=w.entity_id WHERE e.identity_key=%s AND w.channel=%s AND w.status='active'", $subject, $channel ) );
		$grant = $wpdb->get_row( $wpdb->prepare( "SELECT wallet_id FROM $grants WHERE identity_key=%s AND status='active' AND (channel=%s OR channel='*') AND (app_id=%s OR app_id='*') ORDER BY priority ASC, created_at ASC LIMIT 1", $subject, $channel, $app_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $grant ) $wallet_id = $grant['wallet_id'];
		$credits = 1.0; $balance = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(credits),0) FROM {$wpdb->prefix}ptc_wallet_ledger WHERE wallet_id=%s", $wallet_id ) );
		if ( ! $wallet_id || $balance < $credits ) return new WP_Error( 'credit_balance_exhausted' );

		$authorization_id = wp_generate_uuid4();
		$expires_at = gmdate( 'c', time() + 300 );
		$payer_reference = hash( 'sha256', $wallet_id ); $claims = array( 'authorizationId' => $authorization_id, 'appId' => $app_id, 'channel' => $channel, 'billingMeter' => $meter, 'idempotencyKey' => $idempotency_key, 'businessReference' => $reference, 'payerReference' => $payer_reference, 'reservedAmount' => '1.000000', 'unit' => 'CRD', 'expiresAt' => $expires_at );
		$claims['signature'] = hash_hmac( 'sha256', implode( '.', $claims ), $secret );
		$wpdb->insert( $wpdb->prefix . 'ptc_wallet_ledger', array( 'ledger_id' => wp_generate_uuid4(), 'wallet_id' => $wallet_id, 'event_type' => 'reserve', 'credits' => -$credits, 'reference_id' => $authorization_id, 'reason' => $reference, 'created_at' => current_time( 'mysql', true ) ) );
		$inserted = $wpdb->insert( $authorizations, array(
			'authorization_id' => $authorization_id, 'wallet_id' => $wallet_id, 'entity_id' => '', 'app_id' => $app_id,
			'channel' => $channel, 'meter' => $meter, 'idempotency_key' => $idempotency_key, 'reserved_credits' => $credits,
			'state' => 'reserved', 'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( $expires_at ) ), 'signature' => $claims['signature'], 'claims' => wp_json_encode( $claims ), 'created_at' => current_time( 'mysql', true ),
		) );
		if ( ! $inserted ) return new WP_Error( 'wallet_authorization_store_failed' );
		return array( 'ok' => true, 'claims' => $claims );
	}

	public function add_admin_page() {
		add_management_page( __( 'Chat App Wallet', 'chat-app-wallet' ), __( 'Chat App Wallet', 'chat-app-wallet' ), 'manage_options', 'chat-app-wallet', array( $this, 'render_admin_page' ) );
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You are not allowed to manage wallets.', 'chat-app-wallet' ) );
		$search = sanitize_text_field( wp_unslash( $_GET['wallet_search'] ?? '' ) );
		$identity_result = $this->suite_identities();
		$operations_error = is_wp_error( $identity_result ) ? $identity_result : null;
		$identities = $operations_error ? array() : $identity_result;
		$wallets = $this->wallets_by_identity();
		$visible = array_filter( $identities, function( $identity ) use ( $search ) {
			$haystack = strtolower( implode( ' ', array( $identity['channel'] ?? '', $identity['sender_address'] ?? '', $identity['selected_app'] ?? '', $identity['stage'] ?? '', $identity['updated_at'] ?? '' ) ) );
			return '' === $search || false !== strpos( $haystack, strtolower( $search ) );
		} );
		$linked = 0;
		foreach ( $identities as $identity ) if ( isset( $wallets[ $this->identity_key( $identity ) ] ) ) $linked++;
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Chat App Wallet', 'chat-app-wallet' ); ?></h1>
		<p><?php esc_html_e( 'Wallet support, identity access, usage and refunds. A revoke stops future Suite access; it does not erase ledger history.', 'chat-app-wallet' ); ?></p>
		<?php if ( $operations_error ) : ?><div class="notice notice-error"><p><?php echo esc_html( 'Identity data is unavailable: ' . $operations_error->get_error_code() . '. Check PTC_WALLET_OPERATIONS_URL and PTC_WALLET_OPERATIONS_TOKEN in wp-config.php, then confirm the matching Supabase Edge Function secret.' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['wallet_notice'] ) ) : ?><div class="notice notice-<?php echo 'revoked' === $_GET['wallet_notice'] ? 'success' : 'error'; ?>"><p><?php echo esc_html( 'revoked' === $_GET['wallet_notice'] ? 'Consent and access were revoked.' : 'The requested wallet operation could not be completed.' ); ?></p></div><?php endif; ?>
		<div style="display:flex;gap:24px;margin:16px 0"><div><strong><?php echo esc_html( count( $identities ) ); ?></strong><br>known identities</div><div><strong><?php echo esc_html( $linked ); ?></strong><br>with owned Wallet profile</div><div><strong><?php echo esc_html( max( 0, count( $identities ) - $linked ) ); ?></strong><br>without available funding</div></div>
		<form method="get" style="margin:16px 0"><input type="hidden" name="page" value="chat-app-wallet"><label for="wallet_search">Search wallets / identities </label><input id="wallet_search" name="wallet_search" value="<?php echo esc_attr( $search ); ?>" placeholder="number, channel, app, status, activity date"><button class="button">Search</button></form>
		<table class="widefat striped"><thead><tr><th>Identity</th><th>App</th><th>Status</th><th>Wallet balance</th><th>Last activity</th><th>Actions</th></tr></thead><tbody>
		<?php foreach ( $visible as $identity ) : $key = $this->identity_key( $identity ); $wallet = $wallets[ $key ] ?? null; ?>
		<tr><td><?php echo esc_html( $key ); ?></td><td><?php echo esc_html( $identity['selected_app'] ?? '—' ); ?></td><td><?php echo esc_html( $identity['stage'] ?? '—' ); ?></td><td><?php echo esc_html( $wallet ? $wallet['balance'] . ' ' . $wallet['currency'] : 'No available funding' ); ?></td><td><?php echo esc_html( $identity['updated_at'] ?? '—' ); ?></td><td><?php if ( 'revoked' !== ( $identity['stage'] ?? '' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Revoke consent and access for this identity?');"><input type="hidden" name="action" value="ptc_wallet_revoke_access"><input type="hidden" name="channel" value="<?php echo esc_attr( $identity['channel'] ); ?>"><input type="hidden" name="sender_address" value="<?php echo esc_attr( $identity['sender_address'] ); ?>"><?php wp_nonce_field( 'ptc_wallet_revoke_access' ); ?><input required name="reason" maxlength="500" placeholder="Reason for revocation"><button class="button button-link-delete">Revoke consent &amp; access</button></form><?php else : ?>Revoked<?php endif; ?></td></tr>
		<?php endforeach; if ( ! $visible ) : ?><tr><td colspan="6">No identities found.</td></tr><?php endif; ?></tbody></table></div>
		<?php
	}

	private function identity_key( $identity ) {
		$channel = sanitize_key( $identity['channel'] ?? '' );
		$address = sanitize_text_field( $identity['sender_address'] ?? '' );
		return 0 === strpos( $address, $channel . ':' ) ? $address : $channel . ':' . $address;
	}

	private function operations_request( $body ) {
		$url = defined( 'PTC_WALLET_OPERATIONS_URL' ) ? esc_url_raw( PTC_WALLET_OPERATIONS_URL ) : '';
		$token = defined( 'PTC_WALLET_OPERATIONS_TOKEN' ) ? trim( PTC_WALLET_OPERATIONS_TOKEN ) : '';
		if ( ! $url || ! $token ) return new WP_Error( 'wallet_operations_not_configured' );
		$response = wp_remote_post( $url, array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
		if ( is_wp_error( $response ) ) return $response;
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return 200 === wp_remote_retrieve_response_code( $response ) && is_array( $data ) && ! empty( $data['ok'] ) ? $data : new WP_Error( 'wallet_operations_request_failed' );
	}

	private function suite_identities() {
		$result = $this->operations_request( array( 'action' => 'identities.list' ) );
		return is_wp_error( $result ) ? $result : (array) ( $result['identities'] ?? array() );
	}

	private function wallets_by_identity() {
		global $wpdb;
		$entities = $wpdb->prefix . 'ptc_wallet_entities'; $wallets = $wpdb->prefix . 'ptc_wallet_wallets'; $ledger = $wpdb->prefix . 'ptc_wallet_ledger';
		$rows = $wpdb->get_results( "SELECT e.display_name, w.currency, COALESCE(SUM(l.credits), 0) AS balance FROM $entities e INNER JOIN $wallets w ON w.entity_id = e.entity_id LEFT JOIN $ledger l ON l.wallet_id = w.wallet_id GROUP BY e.entity_id, e.display_name, w.currency", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = array(); foreach ( $rows as $row ) $result[ $row['display_name'] ] = $row; return $result;
	}

	public function handle_revoke_access() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You are not allowed to revoke access.', 'chat-app-wallet' ) );
		check_admin_referer( 'ptc_wallet_revoke_access' );
		$user = wp_get_current_user();
		$result = $this->operations_request( array( 'action' => 'identity.revoke', 'channel' => sanitize_key( $_POST['channel'] ?? '' ), 'senderAddress' => sanitize_text_field( wp_unslash( $_POST['sender_address'] ?? '' ) ), 'reason' => sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ), 'actor' => $user->user_login ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'chat-app-wallet', 'wallet_notice' => is_wp_error( $result ) ? 'failed' : 'revoked' ), admin_url( 'tools.php' ) ) ); exit;
	}
}

function postoochat_wallet() {
	return PTC_Chat_App_Wallet::instance();
}

function ptc_chat_app_wallet_install_schema() {
	global $wpdb;
	$charset = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$entities = $wpdb->prefix . 'ptc_wallet_entities';
	$wallets = $wpdb->prefix . 'ptc_wallet_wallets';
	$ledger = $wpdb->prefix . 'ptc_wallet_ledger';
	$authorizations = $wpdb->prefix . 'ptc_wallet_authorizations';
	$receipts = $wpdb->prefix . 'ptc_wallet_provider_receipts';
	dbDelta( "CREATE TABLE $entities ( entity_id char(36) NOT NULL, entity_type varchar(32) NOT NULL, display_name varchar(191) NOT NULL, identity_key varchar(191) NULL, created_at datetime NOT NULL, PRIMARY KEY (entity_id), UNIQUE KEY identity_key (identity_key) ) $charset;" );
	// dbDelta does not reliably add a newly indexed column to an existing
	// plugin table. Upgrade pre-Wallet identity tables explicitly and safely.
	$identity_column = $wpdb->get_var( "SHOW COLUMNS FROM $entities LIKE 'identity_key'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $identity_column ) $wpdb->query( "ALTER TABLE $entities ADD COLUMN identity_key varchar(191) NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$identity_index = $wpdb->get_var( "SHOW INDEX FROM $entities WHERE Key_name = 'identity_key'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $identity_index ) $wpdb->query( "ALTER TABLE $entities ADD UNIQUE KEY identity_key (identity_key)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	dbDelta( "CREATE TABLE $wallets ( wallet_id char(36) NOT NULL, entity_id char(36) NOT NULL, channel varchar(16) NOT NULL, currency char(3) NOT NULL, timezone varchar(64) NOT NULL DEFAULT 'Africa/Johannesburg', status varchar(16) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, PRIMARY KEY (wallet_id), UNIQUE KEY entity_channel_currency (entity_id, channel, currency) ) $charset;" );
	$timezone_column = $wpdb->get_var( "SHOW COLUMNS FROM $wallets LIKE 'timezone'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $timezone_column ) $wpdb->query( "ALTER TABLE $wallets ADD COLUMN timezone varchar(64) NOT NULL DEFAULT 'Africa/Johannesburg'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	dbDelta( "CREATE TABLE $ledger ( ledger_id char(36) NOT NULL, wallet_id char(36) NOT NULL, event_type varchar(32) NOT NULL, credits decimal(18,6) NOT NULL, reference_id varchar(191) NULL, reason varchar(191) NULL, created_at datetime NOT NULL, PRIMARY KEY (ledger_id), KEY wallet_created (wallet_id, created_at) ) $charset;" );
	dbDelta( "CREATE TABLE $authorizations ( authorization_id char(36) NOT NULL, wallet_id char(36) NOT NULL, entity_id char(36) NOT NULL, app_id varchar(64) NOT NULL, channel varchar(16) NOT NULL, meter varchar(64) NOT NULL, idempotency_key varchar(191) NOT NULL, reserved_credits decimal(18,6) NOT NULL, state varchar(16) NOT NULL, expires_at datetime NOT NULL, signature char(64) NOT NULL, claims longtext NULL, created_at datetime NOT NULL, PRIMARY KEY (authorization_id), UNIQUE KEY app_idempotency (app_id, idempotency_key), KEY wallet_state (wallet_id, state) ) $charset;" );
	$grants = $wpdb->prefix . 'ptc_wallet_access_grants';
	dbDelta( "CREATE TABLE $grants ( grant_id char(36) NOT NULL, wallet_id char(36) NOT NULL, payer_owner_entity_id char(36) NOT NULL, identity_key varchar(191) NOT NULL, app_id varchar(64) NOT NULL DEFAULT '*', channel varchar(16) NOT NULL DEFAULT '*', spending_limit decimal(18,6) NULL, priority int NOT NULL DEFAULT 100, status varchar(16) NOT NULL DEFAULT 'active', audit_actor varchar(191) NOT NULL, created_at datetime NOT NULL, revoked_at datetime NULL, PRIMARY KEY (grant_id), KEY eligible_identity (identity_key,status,priority) ) $charset;" );
	dbDelta( "CREATE TABLE $receipts ( provider varchar(32) NOT NULL, provider_event_id varchar(191) NOT NULL, event_type varchar(96) NOT NULL, payload longtext NOT NULL, received_at datetime NOT NULL, PRIMARY KEY (provider, provider_event_id) ) $charset;" );
}
register_activation_hook( __FILE__, 'ptc_chat_app_wallet_install_schema' );
function ptc_chat_app_wallet_maybe_upgrade_schema() {
	if ( get_option( 'ptc_chat_app_wallet_schema_version' ) !== '7' ) {
		ptc_chat_app_wallet_install_schema();
		update_option( 'ptc_chat_app_wallet_schema_version', '7', false );
	}
}
add_action( 'plugins_loaded', 'ptc_chat_app_wallet_maybe_upgrade_schema', 4 );
add_action( 'plugins_loaded', 'postoochat_wallet', 5 );
