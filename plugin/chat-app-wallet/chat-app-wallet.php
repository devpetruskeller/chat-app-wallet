<?php
/**
 * Plugin Name: PosTooChat Chat App Wallet
 * Description: Shared commercial wallet and one-use billing authorization service for PosTooChat apps.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: PosTooChat
 * Text Domain: chat-app-wallet
 */

defined( 'ABSPATH' ) || exit;

final class PTC_Chat_App_Wallet {
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
	}

	/**
	 * Registered in-server service for chat apps. It reserves one wallet unit and
	 * returns a short-lived HMAC ticket for SB Coms; it never makes local HTTP calls.
	 */
	public function authorize_message( $request ) {
		if ( ! is_array( $request ) ) return new WP_Error( 'wallet_invalid_request' );
		$app_id = sanitize_key( $request['app_id'] ?? '' );
		$entity_id = sanitize_text_field( $request['entity_id'] ?? '' );
		$channel = sanitize_key( $request['channel'] ?? '' );
		$meter = sanitize_key( $request['meter'] ?? '' );
		$idempotency_key = sanitize_text_field( $request['idempotency_key'] ?? '' );
		if ( ! $app_id || ! preg_match( '/^[0-9a-f-]{36}$/i', $entity_id ) || ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || ! $meter || ! $idempotency_key ) {
			return new WP_Error( 'wallet_invalid_authorization_context' );
		}
		$secret = defined( 'PTC_WALLET_AUTHORIZATION_SIGNING_SECRET' ) ? trim( PTC_WALLET_AUTHORIZATION_SIGNING_SECRET ) : '';
		if ( strlen( $secret ) < 32 ) return new WP_Error( 'wallet_not_configured' );

		/** Apps may supply their own entity and sponsorship/rate policy through this filter. */
		$decision = apply_filters( 'postoochat_wallet_authorization_decision', null, $request, $this );
		if ( ! is_array( $decision ) || empty( $decision['wallet_id'] ) || ! isset( $decision['reserved_credits'] ) ) {
			return new WP_Error( 'wallet_policy_not_configured' );
		}
		$wallet_id = sanitize_text_field( $decision['wallet_id'] );
		$credits = (float) $decision['reserved_credits'];
		if ( $credits < 0 ) return new WP_Error( 'wallet_invalid_reservation' );

		global $wpdb;
		$authorizations = $wpdb->prefix . 'ptc_wallet_authorizations';
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT authorization_id, expires_at, signature FROM $authorizations WHERE app_id = %s AND idempotency_key = %s", $app_id, $idempotency_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing && strtotime( $existing['expires_at'] ) > time() ) return array( 'ok' => true, 'authorization_id' => $existing['authorization_id'], 'expires_at' => $existing['expires_at'], 'signature' => $existing['signature'], 'reused' => true );

		$authorization_id = wp_generate_uuid4();
		$expires_at = gmdate( 'c', time() + 300 );
		$claims = implode( '.', array( $authorization_id, $app_id, $entity_id, $channel, $meter, $idempotency_key, $expires_at ) );
		$signature = hash_hmac( 'sha256', $claims, $secret );
		$inserted = $wpdb->insert( $authorizations, array(
			'authorization_id' => $authorization_id, 'wallet_id' => $wallet_id, 'entity_id' => $entity_id, 'app_id' => $app_id,
			'channel' => $channel, 'meter' => $meter, 'idempotency_key' => $idempotency_key, 'reserved_credits' => $credits,
			'state' => 'reserved', 'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( $expires_at ) ), 'signature' => $signature, 'created_at' => current_time( 'mysql', true ),
		) );
		if ( ! $inserted ) return new WP_Error( 'wallet_authorization_store_failed' );
		return array( 'ok' => true, 'authorization_id' => $authorization_id, 'entity_id' => $entity_id, 'channel' => $channel, 'meter' => $meter, 'expires_at' => $expires_at, 'signature' => $signature );
	}

	public function add_admin_page() {
		add_management_page( __( 'Chat App Wallet', 'chat-app-wallet' ), __( 'Chat App Wallet', 'chat-app-wallet' ), 'manage_options', 'chat-app-wallet', array( $this, 'render_admin_page' ) );
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You are not allowed to manage wallets.', 'chat-app-wallet' ) );
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Chat App Wallet', 'chat-app-wallet' ); ?></h1>
		<p><?php esc_html_e( 'Wallet payment providers and rate policies will be configured here. Chat apps obtain one-use billing authorizations through the registered PHP service.', 'chat-app-wallet' ); ?></p></div>
		<?php
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
	dbDelta( "CREATE TABLE $entities ( entity_id char(36) NOT NULL, entity_type varchar(32) NOT NULL, display_name varchar(191) NOT NULL, created_at datetime NOT NULL, PRIMARY KEY (entity_id) ) $charset;" );
	dbDelta( "CREATE TABLE $wallets ( wallet_id char(36) NOT NULL, entity_id char(36) NOT NULL, channel varchar(16) NOT NULL, currency char(3) NOT NULL, status varchar(16) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, PRIMARY KEY (wallet_id), UNIQUE KEY entity_channel_currency (entity_id, channel, currency) ) $charset;" );
	dbDelta( "CREATE TABLE $ledger ( ledger_id char(36) NOT NULL, wallet_id char(36) NOT NULL, event_type varchar(32) NOT NULL, credits decimal(18,6) NOT NULL, reference_id varchar(191) NULL, reason varchar(191) NULL, created_at datetime NOT NULL, PRIMARY KEY (ledger_id), KEY wallet_created (wallet_id, created_at) ) $charset;" );
	dbDelta( "CREATE TABLE $authorizations ( authorization_id char(36) NOT NULL, wallet_id char(36) NOT NULL, entity_id char(36) NOT NULL, app_id varchar(64) NOT NULL, channel varchar(16) NOT NULL, meter varchar(64) NOT NULL, idempotency_key varchar(191) NOT NULL, reserved_credits decimal(18,6) NOT NULL, state varchar(16) NOT NULL, expires_at datetime NOT NULL, signature char(64) NOT NULL, created_at datetime NOT NULL, PRIMARY KEY (authorization_id), UNIQUE KEY app_idempotency (app_id, idempotency_key), KEY wallet_state (wallet_id, state) ) $charset;" );
}
register_activation_hook( __FILE__, 'ptc_chat_app_wallet_install_schema' );
add_action( 'plugins_loaded', 'postoochat_wallet', 5 );
