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
		add_action( 'ptc_wallet_release_expired_reservations', array( $this, 'release_expired_reservations' ) );
	}

	/** Public provider endpoint; PayPal authenticity is verified inside the callback. */
	public function register_rest_routes() {
		register_rest_route( 'chat-app-wallet/v1', '/identity-provision', array( 'methods' => 'POST', 'callback' => array( $this, 'provision_identity_endpoint' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'chat-app-wallet/v1', '/identity-lifecycle', array( 'methods' => 'POST', 'callback' => array( $this, 'identity_lifecycle_endpoint' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'chat-app-wallet/v1', '/overview', array( 'methods' => 'POST', 'callback' => array( $this, 'overview_endpoint' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'chat-app-wallet/v1', '/activity', array( 'methods' => 'POST', 'callback' => array( $this, 'activity_endpoint' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'chat-app-wallet/v1', '/invite', array( 'methods' => 'POST', 'callback' => array( $this, 'invite_endpoint' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'chat-app-wallet/v1', '/referral-bind', array( 'methods' => 'POST', 'callback' => array( $this, 'referral_bind_endpoint' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'chat-app-wallet/v1', '/topup', array( 'methods' => 'POST', 'callback' => array( $this, 'topup_endpoint' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'chat-app-wallet/v1', '/authorization-outcome', array( 'methods' => 'POST', 'callback' => array( $this, 'authorization_outcome_endpoint' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'chat-app-wallet/v1', '/paypal/webhook', array(
			'methods' => 'POST',
			'callback' => array( $this, 'handle_paypal_webhook' ),
			'permission_callback' => '__return_true',
		) );
	}

	/** One restricted identity boundary is used by SB Coms presentation routes. */
	private function authenticate_presentation_request( WP_REST_Request $request ) {
		$expected = defined( 'PTC_WALLET_OVERVIEW_TOKEN' ) ? PTC_WALLET_OVERVIEW_TOKEN : '';
		$actual = preg_replace( '/^Bearer\s+/i', '', (string) $request->get_header( 'authorization' ) );
		return $expected && hash_equals( $expected, $actual );
	}

	private function validated_identity_request( WP_REST_Request $request ) {
		if ( ! $this->authenticate_presentation_request( $request ) ) return new WP_Error( 'unauthorized' );
		$body = $request->get_json_params(); $channel = sanitize_key( $body['channel'] ?? '' ); $identity = sanitize_text_field( $body['identity'] ?? '' );
		if ( ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || 0 !== strpos( $identity, $channel . ':' ) ) return new WP_Error( 'invalid_identity' );
		$identity = $this->canonical_identity( $channel, $identity );
		$profile = $this->provision_owned_wallet( $channel, $identity );
		return is_wp_error( $profile ) ? $profile : array( $channel, $identity, $body );
	}
	/** Restricted SB Coms boundary. It returns presentation variables only. */
	public function overview_endpoint( WP_REST_Request $request ) {
		$identity_request = $this->validated_identity_request( $request );
		if ( is_wp_error( $identity_request ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $identity_request->get_error_code() ), 'unauthorized' === $identity_request->get_error_code() ? 401 : 422 );
		list( $channel, $identity ) = $identity_request;
		global $wpdb; $wallets = $wpdb->prefix . 'ptc_wallet_wallets'; $entities = $wpdb->prefix . 'ptc_wallet_entities'; $ledger = $wpdb->prefix . 'ptc_wallet_ledger'; $grants = $wpdb->prefix . 'ptc_wallet_access_grants';
		$wallet = $wpdb->get_row( $wpdb->prepare( "SELECT w.wallet_id, w.timezone FROM $wallets w JOIN $entities e ON e.entity_id=w.entity_id WHERE e.identity_key=%s AND w.channel=%s AND w.status='active'", $identity, $channel ), ARRAY_A );
		$wallet_id = $wallet['wallet_id'] ?? ''; $timezone = $wallet['timezone'] ?? self::DEFAULT_TIMEZONE;
		try { $as_of = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( $timezone ) )->format( 'Y-m-d H:i T' ); } catch ( Exception $error ) { $as_of = gmdate( 'Y-m-d H:i' ) . ' UTC'; }
		$balance = $wallet_id ? (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(credits),0) FROM $ledger WHERE wallet_id=%s", $wallet_id ) ) : 0.0;
		$shared = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $grants WHERE identity_key=%s AND status='active'", $identity ) );
		return new WP_REST_Response( array( 'ok' => true, 'variables' => array( 'as_of_display' => $as_of, 'owned_credit_display' => number_format_i18n( $balance, 3 ) . ' CRD', 'owned_wallet_label' => 'Your Wallet', 'owned_wallet_status' => 'Active', 'return_app_label' => 'Return to app', 'shared_access_count' => (string) (int) $shared, 'shared_access_summary' => (int) $shared ? 'Shared access available.' : 'No shared wallet access.' ) ), 200 );
	}

	/** Statement data stays Wallet-owned; SB Coms only receives renderable facts. */
	public function activity_endpoint( WP_REST_Request $request ) {
		$identity_request = $this->validated_identity_request( $request );
		if ( is_wp_error( $identity_request ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $identity_request->get_error_code() ), 401 );
		list( $channel, $identity ) = $identity_request;
		global $wpdb; $wallets = $wpdb->prefix . 'ptc_wallet_wallets'; $entities = $wpdb->prefix . 'ptc_wallet_entities'; $ledger = $wpdb->prefix . 'ptc_wallet_ledger';
		$wallet_id = $wpdb->get_var( $wpdb->prepare( "SELECT w.wallet_id FROM $wallets w INNER JOIN $entities e ON e.entity_id=w.entity_id WHERE e.identity_key=%s AND w.channel=%s", $identity, $channel ) );
		$rows = $wallet_id ? $wpdb->get_results( $wpdb->prepare( "SELECT event_type, credits, created_at FROM $ledger WHERE wallet_id=%s ORDER BY created_at DESC, ledger_id DESC LIMIT 5", $wallet_id ), ARRAY_A ) : array();
		$summary = array(); foreach ( $rows as $row ) $summary[] = sprintf( '%s %s CRD', sanitize_key( $row['event_type'] ), number_format_i18n( (float) $row['credits'], 3 ) );
		return new WP_REST_Response( array( 'ok' => true, 'variables' => array( 'activity_summary' => $summary ? implode( "\n", $summary ) : 'No wallet activity yet.', 'activity_count' => (string) count( $rows ) ) ), 200 );
	}

	/** Wallet creates the opaque referral code; SB Coms only turns it into a channel link. */
	public function invite_endpoint( WP_REST_Request $request ) {
		$identity_request = $this->validated_identity_request( $request );
		if ( is_wp_error( $identity_request ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $identity_request->get_error_code() ), 401 );
		list( $channel, $identity ) = $identity_request;
		$campaign = $this->referral_campaign( $channel, $identity );
		if ( is_wp_error( $campaign ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $campaign->get_error_code() ), 503 );
		$token = bin2hex( random_bytes( 16 ) );
		global $wpdb; $offers = $wpdb->prefix . 'ptc_wallet_referral_offers';
		if ( ! $wpdb->insert( $offers, array( 'offer_id' => wp_generate_uuid4(), 'campaign_id' => $campaign['campaign_id'], 'token_hash' => hash( 'sha256', $token ), 'status' => 'active', 'created_at' => current_time( 'mysql', true ) ) ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'referral_offer_store_failed' ), 503 );
		return new WP_REST_Response( array( 'ok' => true, 'variables' => array_merge( $this->referral_progress_variables( $campaign ), array( 'referralToken' => $token ) ) ), 200 );
	}

	/** A new channel identity can bind one valid offer before it begins onboarding. */
	public function referral_bind_endpoint( WP_REST_Request $request ) {
		$expected = defined( 'PTC_WALLET_REFERRAL_TOKEN' ) ? trim( PTC_WALLET_REFERRAL_TOKEN ) : '';
		$actual = preg_replace( '/^Bearer\s+/i', '', (string) $request->get_header( 'authorization' ) );
		if ( strlen( $expected ) < 32 || ! hash_equals( $expected, $actual ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'unauthorized' ), 401 );
		$body = $request->get_json_params(); $channel = sanitize_key( $body['channel'] ?? '' ); $token = sanitize_text_field( $body['token'] ?? '' ); $identity = sanitize_text_field( $body['identity'] ?? '' );
		if ( ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) || 0 !== strpos( $identity, $channel . ':' ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_referral_bind' ), 422 );
		$identity = $this->canonical_identity( $channel, $identity ); global $wpdb; $offers = $wpdb->prefix . 'ptc_wallet_referral_offers'; $campaigns = $wpdb->prefix . 'ptc_wallet_referral_campaigns'; $referrals = $wpdb->prefix . 'ptc_wallet_referrals';
		$offer = $wpdb->get_row( $wpdb->prepare( "SELECT o.offer_id,o.campaign_id,c.inviter_identity_key,c.status,c.target_count FROM $offers o INNER JOIN $campaigns c ON c.campaign_id=o.campaign_id WHERE o.token_hash=%s AND o.status='active'", hash( 'sha256', $token ) ), ARRAY_A );
		if ( ! $offer || 'active' !== $offer['status'] || $offer['inviter_identity_key'] === $identity ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'referral_offer_unavailable' ), 422 );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT campaign_id FROM $referrals WHERE referred_identity_key=%s", $identity ), ARRAY_A );
		if ( $existing ) return new WP_REST_Response( array( 'ok' => true, 'alreadyBound' => true, 'sameCampaign' => $existing['campaign_id'] === $offer['campaign_id'] ), 200 );
		$created = $wpdb->insert( $referrals, array( 'referral_id' => wp_generate_uuid4(), 'campaign_id' => $offer['campaign_id'], 'referred_identity_key' => $identity, 'status' => 'pending', 'created_at' => current_time( 'mysql', true ) ) );
		return $created ? new WP_REST_Response( array( 'ok' => true, 'bound' => true ), 200 ) : new WP_REST_Response( array( 'ok' => false, 'error' => 'referral_bind_store_failed' ), 503 );
	}

	private function referral_campaign( $channel, $identity ) {
		global $wpdb; $campaigns = $wpdb->prefix . 'ptc_wallet_referral_campaigns';
		$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $campaigns WHERE inviter_identity_key=%s AND channel=%s AND status='active' ORDER BY created_at DESC LIMIT 1", $identity, $channel ), ARRAY_A );
		if ( $campaign ) return $campaign;
		$total = defined( 'PTC_WALLET_REFERRAL_CAMPAIGN_CREDITS' ) ? (float) PTC_WALLET_REFERRAL_CAMPAIGN_CREDITS : 0.001;
		$micro = (int) round( $total * 1000000 ); if ( $micro < 5 ) return new WP_Error( 'referral_campaign_not_configured' );
		$campaign = array( 'campaign_id' => wp_generate_uuid4(), 'inviter_identity_key' => $identity, 'channel' => $channel, 'target_count' => 5, 'grant_microcredits' => $micro, 'status' => 'active', 'created_at' => current_time( 'mysql', true ) );
		return $wpdb->insert( $campaigns, $campaign ) ? $campaign : new WP_Error( 'referral_campaign_store_failed' );
	}

	private function referral_progress_variables( $campaign ) {
		global $wpdb; $referrals = $wpdb->prefix . 'ptc_wallet_referrals'; $wallets = $wpdb->prefix . 'ptc_wallet_wallets'; $entities = $wpdb->prefix . 'ptc_wallet_entities'; $ledger = $wpdb->prefix . 'ptc_wallet_ledger';
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $referrals WHERE campaign_id=%s AND status='granted'", $campaign['campaign_id'] ) );
		$wallet_id = $wpdb->get_var( $wpdb->prepare( "SELECT w.wallet_id FROM $wallets w INNER JOIN $entities e ON e.entity_id=w.entity_id WHERE e.identity_key=%s AND w.channel=%s", $campaign['inviter_identity_key'], $campaign['channel'] ) );
		$balance = $wallet_id ? (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(credits),0) FROM $ledger WHERE wallet_id=%s", $wallet_id ) ) : 0;
		return array( 'completedInviteCount' => (string) $count, 'completedInviteTarget' => (string) $campaign['target_count'], 'creditBalance' => number_format( $balance, 6, '.', '' ), 'creditUnit' => 'CRD' );
	}

	/** Creates a Sandbox order only for an operator-configured package. */
	public function topup_endpoint( WP_REST_Request $request ) {
		$identity_request = $this->validated_identity_request( $request );
		if ( is_wp_error( $identity_request ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $identity_request->get_error_code() ), 401 );
		$packages = json_decode( defined( 'PTC_WALLET_PAYPAL_TOPUP_PACKAGES' ) ? PTC_WALLET_PAYPAL_TOPUP_PACKAGES : '[]', true );
		$body = $request->get_json_params(); $sku = sanitize_key( $body['package'] ?? '' );
		$package = is_array( $packages ) ? array_values( array_filter( $packages, function( $item ) use ( $sku ) { return is_array( $item ) && $sku && $sku === sanitize_key( $item['sku'] ?? '' ); } ) ) : array();
		if ( ! $package ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'topup_package_not_configured' ), 422 );
		// Checkout capture binding is intentionally separate: a return URL is not payment proof.
		return new WP_REST_Response( array( 'ok' => false, 'error' => 'paypal_checkout_not_enabled' ), 503 );
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
		if ( (float) $row['reserved_credits'] > 0 ) $wpdb->insert( $wpdb->prefix . 'ptc_wallet_ledger', array( 'ledger_id' => wp_generate_uuid4(), 'wallet_id' => $row['wallet_id'], 'event_type' => $event, 'credits' => $credits, 'reference_id' => $authorization_id, 'reason' => 'sb_coms_' . $outcome, 'created_at' => current_time( 'mysql', true ) ) );
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

	/**
	 * Signed Suite lifecycle boundary. Events are deliberately minimal: Wallet
	 * neither receives nor reconstructs Suite profile, email, mobile or TOTP data.
	 */
	public function identity_lifecycle_endpoint( WP_REST_Request $request ) {
		$secret = defined( 'PTC_WALLET_IDENTITY_LIFECYCLE_SIGNING_SECRET' ) ? trim( PTC_WALLET_IDENTITY_LIFECYCLE_SIGNING_SECRET ) : '';
		$timestamp = (string) $request->get_header( 'x-postoo-lifecycle-timestamp' );
		$provided = (string) $request->get_header( 'x-postoo-lifecycle-signature' );
		$raw = $request->get_body(); $when = strtotime( $timestamp );
		$expected = $secret && $timestamp ? hash_hmac( 'sha256', $timestamp . '.' . $raw, $secret ) : '';
		if ( strlen( $secret ) < 32 || ! $when || abs( time() - $when ) > 300 || ! $provided || ! hash_equals( $expected, $provided ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_lifecycle_signature' ), 401 );
		}
		$body = json_decode( $raw, true );
		$event_id = sanitize_text_field( $body['eventId'] ?? '' );
		$event_type = sanitize_key( $body['eventType'] ?? '' );
		$identity = sanitize_text_field( $body['identity'] ?? '' );
		$occurred_at = sanitize_text_field( $body['occurredAt'] ?? '' );
		if ( ! preg_match( '/^[0-9a-f-]{36}$/i', $event_id ) || ! in_array( $event_type, array( 'verified', 'withdrawn', 'revoked' ), true ) || ! $occurred_at || ! preg_match( '/^(telegram|whatsapp):.+$/', $identity, $matches ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_lifecycle_event' ), 422 );
		}
		$channel = $matches[1]; $identity = $this->canonical_identity( $channel, $identity );
		global $wpdb; $events = $wpdb->prefix . 'ptc_wallet_identity_events';
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $events WHERE event_id=%s", $event_id ) ) ) return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( 'verified' === $event_type ) {
				$result = $this->provision_owned_wallet( $channel, $identity );
				if ( is_wp_error( $result ) ) throw new Exception( $result->get_error_code() );
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}ptc_wallet_wallets w INNER JOIN {$wpdb->prefix}ptc_wallet_entities e ON e.entity_id=w.entity_id SET w.status='active' WHERE e.identity_key=%s AND w.channel=%s", $identity, $channel ) );
				$notification = $this->grant_completed_referral( $identity );
			} else {
				$this->hold_identity_wallets( $identity, $event_type );
			}
			$stored = $wpdb->insert( $events, array( 'event_id' => $event_id, 'identity_key' => $identity, 'event_type' => $event_type, 'occurred_at' => gmdate( 'Y-m-d H:i:s', strtotime( $occurred_at ) ), 'received_at' => current_time( 'mysql', true ) ) );
			if ( ! $stored ) throw new Exception( 'lifecycle_event_store_failed' );
			$wpdb->query( 'COMMIT' );
		} catch ( Exception $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'lifecycle_apply_failed' ), 503 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'duplicate' => false, 'notification' => $notification ?? null ), 200 );
	}

	/** Credits are integer microcredits; the five grants always sum exactly to the campaign amount. */
	private function grant_completed_referral( $referred_identity ) {
		global $wpdb; $referrals = $wpdb->prefix . 'ptc_wallet_referrals'; $campaigns = $wpdb->prefix . 'ptc_wallet_referral_campaigns'; $wallets = $wpdb->prefix . 'ptc_wallet_wallets'; $entities = $wpdb->prefix . 'ptc_wallet_entities'; $ledger = $wpdb->prefix . 'ptc_wallet_ledger';
		$referral = $wpdb->get_row( $wpdb->prepare( "SELECT r.*,c.inviter_identity_key,c.channel,c.target_count,c.grant_microcredits,c.status AS campaign_status FROM $referrals r INNER JOIN $campaigns c ON c.campaign_id=r.campaign_id WHERE r.referred_identity_key=%s FOR UPDATE", $referred_identity ), ARRAY_A );
		if ( ! $referral || 'pending' !== $referral['status'] || 'active' !== $referral['campaign_status'] ) return null;
		$completed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $referrals WHERE campaign_id=%s AND status='granted'", $referral['campaign_id'] ) );
		$position = $completed + 1; if ( $position > (int) $referral['target_count'] ) return null;
		$target = (int) $referral['target_count']; $total = (int) $referral['grant_microcredits'];
		$micro = (int) floor( $position * $total / $target ) - (int) floor( ( $position - 1 ) * $total / $target );
		$wallet_id = $wpdb->get_var( $wpdb->prepare( "SELECT w.wallet_id FROM $wallets w INNER JOIN $entities e ON e.entity_id=w.entity_id WHERE e.identity_key=%s AND w.channel=%s AND w.status='active'", $referral['inviter_identity_key'], $referral['channel'] ) );
		if ( ! $wallet_id || $micro <= 0 ) return null;
		$grant_id = wp_generate_uuid4();
		if ( ! $wpdb->insert( $ledger, array( 'ledger_id' => $grant_id, 'wallet_id' => $wallet_id, 'event_type' => 'referral_grant', 'credits' => number_format( $micro / 1000000, 6, '.', '' ), 'reference_id' => $referral['referral_id'], 'reason' => 'verified_referral', 'actor' => 'wallet_referral', 'created_at' => current_time( 'mysql', true ) ) ) ) throw new Exception( 'referral_grant_store_failed' );
		if ( 1 !== $wpdb->update( $referrals, array( 'status' => 'granted', 'grant_ledger_id' => $grant_id, 'granted_at' => current_time( 'mysql', true ) ), array( 'referral_id' => $referral['referral_id'], 'status' => 'pending' ) ) ) throw new Exception( 'referral_grant_state_failed' );
		if ( $position === $target ) $wpdb->update( $campaigns, array( 'status' => 'completed', 'completed_at' => current_time( 'mysql', true ) ), array( 'campaign_id' => $referral['campaign_id'], 'status' => 'active' ) );
		$balance = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(credits),0) FROM $ledger WHERE wallet_id=%s", $wallet_id ) );
		return array( 'recipientIdentity' => $referral['inviter_identity_key'], 'messageName' => 'WALLET_INVITE_SUCCESS', 'variables' => array( 'referralDisplayName' => 'A new referral', 'creditGrant' => number_format( $micro / 1000000, 6, '.', '' ), 'creditBalance' => number_format( $balance, 6, '.', '' ), 'creditUnit' => 'CRD', 'completedInviteCount' => (string) $position, 'completedInviteTarget' => (string) $target ) );
	}

	/** Hold owned balances and release only reservations that never reached SB Coms acceptance. */
	private function hold_identity_wallets( $identity, $reason ) {
		global $wpdb; $wallets = $wpdb->prefix . 'ptc_wallet_wallets'; $entities = $wpdb->prefix . 'ptc_wallet_entities'; $authorizations = $wpdb->prefix . 'ptc_wallet_authorizations'; $ledger = $wpdb->prefix . 'ptc_wallet_ledger'; $grants = $wpdb->prefix . 'ptc_wallet_access_grants';
		$owned = $wpdb->get_col( $wpdb->prepare( "SELECT w.wallet_id FROM $wallets w INNER JOIN $entities e ON e.entity_id=w.entity_id WHERE e.identity_key=%s", $identity ) );
		foreach ( $owned as $wallet_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT authorization_id,reserved_credits FROM $authorizations WHERE wallet_id=%s AND state='reserved'", $wallet_id ), ARRAY_A );
			foreach ( $rows as $row ) {
				if ( 1 === $wpdb->update( $authorizations, array( 'state' => 'release' ), array( 'authorization_id' => $row['authorization_id'], 'state' => 'reserved' ) ) && (float) $row['reserved_credits'] > 0 ) {
					$wpdb->insert( $ledger, array( 'ledger_id' => wp_generate_uuid4(), 'wallet_id' => $wallet_id, 'event_type' => 'release', 'credits' => $row['reserved_credits'], 'reference_id' => $row['authorization_id'], 'reason' => 'identity_' . $reason, 'created_at' => current_time( 'mysql', true ) ) );
				}
			}
		}
		$wpdb->query( $wpdb->prepare( "UPDATE $wallets w INNER JOIN $entities e ON e.entity_id=w.entity_id SET w.status='held' WHERE e.identity_key=%s", $identity ) );
		$wpdb->query( $wpdb->prepare( "UPDATE $grants SET status='revoked', revoked_at=UTC_TIMESTAMP() WHERE identity_key=%s AND status IN ('pending','active')", $identity ) );
		$campaigns = $wpdb->prefix . 'ptc_wallet_referral_campaigns'; $offers = $wpdb->prefix . 'ptc_wallet_referral_offers'; $referrals = $wpdb->prefix . 'ptc_wallet_referrals';
		$wpdb->query( $wpdb->prepare( "UPDATE $campaigns SET status='cancelled', cancelled_at=UTC_TIMESTAMP() WHERE inviter_identity_key=%s AND status='active'", $identity ) );
		$wpdb->query( $wpdb->prepare( "UPDATE $offers o INNER JOIN $campaigns c ON c.campaign_id=o.campaign_id SET o.status='cancelled', cancelled_at=UTC_TIMESTAMP() WHERE c.inviter_identity_key=%s AND o.status='active'", $identity ) );
		$wpdb->query( $wpdb->prepare( "UPDATE $referrals SET status='cancelled', cancelled_at=UTC_TIMESTAMP() WHERE referred_identity_key=%s AND status='pending'", $identity ) );
	}

	public function provision_owned_wallet( $channel, $identity ) {
		if ( ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || 0 !== strpos( $identity, $channel . ':' ) ) return new WP_Error( 'wallet_invalid_identity' );
		$identity = $this->canonical_identity( $channel, $identity );
		global $wpdb; $entities = $wpdb->prefix . 'ptc_wallet_entities'; $wallets = $wpdb->prefix . 'ptc_wallet_wallets';
		$entity = $wpdb->get_var( $wpdb->prepare( "SELECT entity_id FROM $entities WHERE identity_key = %s", $identity ) );
		if ( ! $entity ) { $entity = wp_generate_uuid4(); if ( ! $wpdb->insert( $entities, array( 'entity_id' => $entity, 'entity_type' => 'owned_identity', 'display_name' => $identity, 'identity_key' => $identity, 'created_at' => current_time( 'mysql', true ) ) ) ) return new WP_Error( 'wallet_profile_store_failed' ); }
		$wallet = $wpdb->get_var( $wpdb->prepare( "SELECT wallet_id FROM $wallets WHERE entity_id = %s AND channel = %s", $entity, $channel ) );
		if ( ! $wallet ) { $wallet = wp_generate_uuid4(); if ( ! $wpdb->insert( $wallets, array( 'wallet_id' => $wallet, 'entity_id' => $entity, 'channel' => $channel, 'currency' => 'CRD', 'timezone' => self::DEFAULT_TIMEZONE, 'status' => 'active', 'created_at' => current_time( 'mysql', true ) ) ) ) return new WP_Error( 'wallet_profile_store_failed' ); }
		return array( 'identity' => $identity, 'wallet_reference' => hash( 'sha256', $wallet ) );
	}

	private function canonical_identity( $channel, $identity ) {
		$prefix = $channel . ':'; $value = sanitize_text_field( $identity );
		while ( 0 === strpos( $value, $prefix ) ) $value = substr( $value, strlen( $prefix ) );
		return $prefix . $value;
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
		$message_group = sanitize_text_field( $request['message_group'] ?? '' ); $message_name = sanitize_text_field( $request['message_name'] ?? '' );
		if ( ! $app_id || ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || ! $meter || ! preg_match( '/^[A-Za-z0-9_-]+$/', $message_group ) || ! preg_match( '/^[A-Za-z0-9_-]+$/', $message_name ) || ! $idempotency_key || ! $reference || 0 !== strpos( $subject, $channel . ':' ) || 0 !== strpos( $recipient, $channel . ':' ) ) {
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
		$grant = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $grants WHERE identity_key=%s AND status='active' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) AND (channel=%s OR channel='*') ORDER BY priority ASC, created_at ASC LIMIT 1", $subject, $channel ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$credits = $this->credit_requirement( $channel, $message_group, $message_name, 1.0 );
		if ( $grant ) {
			$wallet_id = $grant['wallet_id'];
			if ( null !== $grant['spending_limit'] ) {
				$used = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(reserved_credits),0) FROM $authorizations WHERE wallet_id=%s AND state IN ('reserved','consume') AND created_at >= %s", $wallet_id, $grant['created_at'] ) );
				if ( $used + $credits > (float) $grant['spending_limit'] ) return new WP_Error( 'shared_wallet_limit_exhausted' );
			}
		}
		$balance = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(credits),0) FROM {$wpdb->prefix}ptc_wallet_ledger WHERE wallet_id=%s", $wallet_id ) );
		if ( ! $wallet_id || ( $credits > 0 && $balance < $credits ) ) return new WP_Error( 'credit_balance_exhausted' );

		$authorization_id = wp_generate_uuid4();
		$expires_at = gmdate( 'c', time() + 300 );
		$payer_reference = hash( 'sha256', $wallet_id ); $claims = array( 'authorizationId' => $authorization_id, 'appId' => $app_id, 'channel' => $channel, 'messageGroup' => $message_group, 'messageName' => $message_name, 'billingMeter' => $meter, 'idempotencyKey' => $idempotency_key, 'businessReference' => $reference, 'payerReference' => $payer_reference, 'reservedAmount' => number_format( $credits, 6, '.', '' ), 'unit' => 'CRD', 'expiresAt' => $expires_at );
		$claims['signature'] = hash_hmac( 'sha256', implode( '.', $claims ), $secret );
		$wpdb->query( 'START TRANSACTION' );
		$inserted = $wpdb->insert( $authorizations, array(
			'authorization_id' => $authorization_id, 'wallet_id' => $wallet_id, 'entity_id' => '', 'app_id' => $app_id,
			'channel' => $channel, 'meter' => $meter, 'idempotency_key' => $idempotency_key, 'reserved_credits' => $credits,
			'state' => 'reserved', 'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( $expires_at ) ), 'signature' => $claims['signature'], 'claims' => wp_json_encode( $claims ), 'created_at' => current_time( 'mysql', true ),
		) );
		if ( ! $inserted ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'wallet_authorization_store_failed' ); }
		if ( $credits > 0 ) { $reserved = $wpdb->insert( $wpdb->prefix . 'ptc_wallet_ledger', array( 'ledger_id' => wp_generate_uuid4(), 'wallet_id' => $wallet_id, 'event_type' => 'reserve', 'credits' => -$credits, 'reference_id' => $authorization_id, 'reason' => $reference, 'created_at' => current_time( 'mysql', true ) ) ); if ( ! $reserved ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'wallet_reservation_store_failed' ); } }
		$wpdb->query( 'COMMIT' );
		return array( 'ok' => true, 'claims' => $claims );
	}

	private function credit_requirement( $channel, $group, $name, $fallback ) {
		$default = $this->channel_default_rate( $channel, $fallback ); $base = $default['credits'];
		if ( ! $group || ! $name ) return $base;
		global $wpdb; $rates = $wpdb->prefix . 'ptc_wallet_rate_cards';
		$ignored = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $rates WHERE channel=%s AND scope='message_ignore' AND message_group=%s AND message_name=%s AND status='active' ORDER BY created_at DESC LIMIT 1", $channel, $group, $name ) );
		if ( $ignored ) return 0.0;
		$override = $wpdb->get_var( $wpdb->prepare( "SELECT credits_required FROM $rates WHERE channel=%s AND scope='message_override' AND message_group=%s AND message_name=%s AND status='active' ORDER BY created_at DESC LIMIT 1", $channel, $group, $name ) );
		return null !== $override && (float) $override > 0 ? (float) $override : $base;
	}

	/** The active signed channel price is the source for default-message USD calculations. */
	private function channel_default_rate( $channel, $fallback_credits = 1.0 ) {
		global $wpdb; $rates = $wpdb->prefix . 'ptc_wallet_rate_cards';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT credits_required, usd_per_credit FROM $rates WHERE channel=%s AND scope='channel_default' AND status='active' ORDER BY created_at DESC LIMIT 1", $channel ), ARRAY_A );
		return array( 'credits' => $row ? (float) $row['credits_required'] : (float) $fallback_credits, 'usd_per_credit' => $row && null !== $row['usd_per_credit'] ? (float) $row['usd_per_credit'] : null );
	}

	/** Idempotent recovery for expired/orphaned reservations. UTC is canonical. */
	public function release_expired_reservations() {
		global $wpdb; $authorizations = $wpdb->prefix . 'ptc_wallet_authorizations'; $ledger = $wpdb->prefix . 'ptc_wallet_ledger';
		$rows = $wpdb->get_results( "SELECT authorization_id, wallet_id, reserved_credits FROM $authorizations WHERE state='reserved' AND expires_at <= UTC_TIMESTAMP()", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $row ) {
			$wpdb->query( 'START TRANSACTION' );
			$updated = $wpdb->update( $authorizations, array( 'state' => 'release' ), array( 'authorization_id' => $row['authorization_id'], 'state' => 'reserved' ) );
			if ( 1 === $updated && (float) $row['reserved_credits'] > 0 ) $wpdb->insert( $ledger, array( 'ledger_id' => wp_generate_uuid4(), 'wallet_id' => $row['wallet_id'], 'event_type' => 'release', 'credits' => $row['reserved_credits'], 'reference_id' => $row['authorization_id'], 'reason' => 'authorization_expired', 'created_at' => current_time( 'mysql', true ) ) );
			$wpdb->query( 'COMMIT' );
		}
	}

	public function add_admin_page() {
		add_management_page( __( 'Chat App Wallet', 'chat-app-wallet' ), __( 'Chat App Wallet', 'chat-app-wallet' ), 'manage_options', 'chat-app-wallet', array( $this, 'render_admin_page' ) );
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You are not allowed to manage wallets.', 'chat-app-wallet' ) );
		list( $adjustment_notice, $adjustment_error ) = $this->handle_adjustment_forms();
		list( $rate_notice, $rate_error ) = $this->handle_rate_forms(); $catalog_result = $this->billable_catalog(); $catalog = is_wp_error( $catalog_result ) ? array() : $catalog_result;
		$catalog_error = is_wp_error( $catalog_result ) ? $catalog_result->get_error_code() : null;
		$active_rate_cards = $this->rate_cards(); $configured_messages = array(); foreach ( $active_rate_cards as $rate ) if ( in_array( $rate['scope'], array( 'message_override', 'message_ignore' ), true ) ) $configured_messages[ $rate['channel'] . '|' . $rate['message_group'] . '|' . $rate['message_name'] ] = true;
		$rate_catalog = array_values( array_filter( $catalog, function( $entry ) use ( $configured_messages ) { return empty( $configured_messages[ $entry['channel'] . '|' . $entry['group'] . '|' . $entry['name'] ] ); } ) );
		$search = sanitize_text_field( wp_unslash( $_GET['wallet_search'] ?? '' ) );
		$identity_result = $this->suite_identities();
		$operations_error = is_wp_error( $identity_result ) ? $identity_result : null;
		$identities = $operations_error ? array() : $identity_result;
		$wallets = $this->wallets_by_identity();
		$visible = array_filter( $identities, function( $identity ) use ( $search ) {
			$haystack = strtolower( implode( ' ', array( $identity['channel'] ?? '', $identity['sender_address'] ?? '', $identity['stage'] ?? '', $identity['updated_at'] ?? '' ) ) );
			return '' === $search || false !== strpos( $haystack, strtolower( $search ) );
		} );
		$linked = 0;
		foreach ( $identities as $identity ) if ( isset( $wallets[ $this->identity_key( $identity ) ] ) ) $linked++;
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Chat App Wallet', 'chat-app-wallet' ); ?></h1>
		<p><?php esc_html_e( 'Wallet support, identity access, usage and refunds. A revoke stops future Suite access; it does not erase ledger history.', 'chat-app-wallet' ); ?></p>
		<?php if ( $adjustment_notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $adjustment_notice ); ?></p></div><?php endif; ?>
		<?php if ( $adjustment_error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $adjustment_error ); ?></p></div><?php endif; ?>
		<?php if ( $rate_notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $rate_notice ); ?></p></div><?php endif; ?><?php if ( $rate_error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $rate_error ); ?></p></div><?php endif; ?>
		<?php if ( $operations_error ) : ?><div class="notice notice-error"><p><?php echo esc_html( 'Identity data is unavailable: ' . $operations_error->get_error_code() . '. Check PTC_WALLET_OPERATIONS_URL and PTC_WALLET_OPERATIONS_TOKEN in wp-config.php, then confirm the matching Supabase Edge Function secret.' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['wallet_notice'] ) ) : ?><div class="notice notice-<?php echo 'revoked' === $_GET['wallet_notice'] ? 'success' : 'error'; ?>"><p><?php echo esc_html( 'revoked' === $_GET['wallet_notice'] ? 'Consent and access were revoked.' : 'The requested wallet operation could not be completed.' ); ?></p></div><?php endif; ?>
		<div style="display:flex;gap:24px;margin:16px 0"><div><strong><?php echo esc_html( count( $identities ) ); ?></strong><br>known identities</div><div><strong><?php echo esc_html( $linked ); ?></strong><br>with owned Wallet profile</div><div><strong><?php echo esc_html( max( 0, count( $identities ) - $linked ) ); ?></strong><br>without available funding</div></div>
		<h2 class="nav-tab-wrapper"><a href="#adjustment" class="nav-tab nav-tab-active" data-wallet-tab="adjustment">Credit adjustment</a><a href="#defaults" class="nav-tab" data-wallet-tab="defaults">Channel defaults</a><a href="#rates" class="nav-tab" data-wallet-tab="rates">Rate card</a><a href="#identities" class="nav-tab" data-wallet-tab="identities">Wallet identities</a></h2><div class="wallet-tab-panel" data-wallet-panel="adjustment">
		<h2><?php esc_html_e( 'Operator credit adjustment', 'chat-app-wallet' ); ?></h2>
		<?php $pending_adjustment = get_transient( 'ptc_wallet_adjustment_' . get_current_user_id() ); if ( is_array( $pending_adjustment ) ) : ?>
		<form method="post"><p><?php wp_nonce_field( 'ptc_wallet_adjustment_verify' ); ?><label for="totp_code">Authenticator code</label> <input id="totp_code" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required> <button class="button button-primary" name="ptc_wallet_adjustment_verify" value="1">Approve and record adjustment</button></p></form>
		<?php else : ?>
		<form method="post"><table class="form-table"><tr><th><label for="wallet_id">Wallet</label></th><td><select id="wallet_id" name="wallet_id" required><option value="">Select a wallet</option><?php foreach ( $wallets as $row ) : if ( ! empty( $row['wallet_id'] ) ) : ?><option value="<?php echo esc_attr( $row['wallet_id'] ); ?>"><?php echo esc_html( $row['display_name'] . ' — ' . $row['balance'] . ' ' . $row['currency'] ); ?></option><?php endif; endforeach; ?></select></td></tr><tr><th><label for="adjustment_kind">Operation</label></th><td><select id="adjustment_kind" name="adjustment_kind"><option value="grant">Add credits</option><option value="adjustment">Remove allocated credits</option></select></td></tr><tr><th><label for="amount">Credits</label></th><td><input id="amount" name="amount" inputmode="decimal" required></td></tr><tr><th><label for="reason">Reason</label></th><td><input id="reason" name="reason" maxlength="191" class="regular-text" required></td></tr></table><?php wp_nonce_field( 'ptc_wallet_adjustment_request' ); ?><button class="button button-primary" name="ptc_wallet_adjustment_request" value="1">Request 2FA approval</button></form>
		<?php endif; ?>
		</div><div class="wallet-tab-panel" data-wallet-panel="defaults" style="display:none"><h2><?php esc_html_e( 'Default message credit by channel', 'chat-app-wallet' ); ?></h2><?php $current_defaults = array( 'telegram' => $this->channel_default_rate( 'telegram' ), 'whatsapp' => $this->channel_default_rate( 'whatsapp' ) ); ?><table class="widefat striped" style="max-width:760px"><thead><tr><th>Channel</th><th>USD per credit</th><th>Default CRD per message</th><th>USD per default message</th></tr></thead><tbody><?php foreach ( $current_defaults as $default_channel => $default_rate ) : $usd_per_credit = null === $default_rate['usd_per_credit'] ? 'Not configured' : '$' . number_format( $default_rate['usd_per_credit'], 6, '.', '' ); $usd_per_message = null === $default_rate['usd_per_credit'] ? 'Not configured' : '$' . number_format( $default_rate['credits'] * $default_rate['usd_per_credit'], 6, '.', '' ); ?><tr><td><?php echo esc_html( ucfirst( $default_channel ) ); ?></td><td><?php echo esc_html( $usd_per_credit ); ?></td><td><?php echo esc_html( number_format( $default_rate['credits'], 6, '.', '' ) ); ?> CRD</td><td><?php echo esc_html( $usd_per_message ); ?></td></tr><?php endforeach; ?></tbody></table><p class="description">USD per default message = USD per credit × default CRD per message.</p><p>Set the credit amount used by every billable message in a channel unless that message has a Rate card rule.</p><?php $pending_default = get_transient( 'ptc_wallet_rate_' . get_current_user_id() ); if ( is_array( $pending_default ) && 'channel_default' === $pending_default['scope'] ) : ?><form method="post"><p><?php wp_nonce_field( 'ptc_wallet_rate_verify' ); ?><label for="default_rate_totp">Authenticator code</label> <input id="default_rate_totp" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required> <button class="button button-primary" name="ptc_wallet_rate_verify" value="1">Approve default change</button></p></form><?php else : ?><form method="post"><input type="hidden" name="rate_scope" value="channel_default"><table class="form-table"><tr><th>Channel</th><td><select name="rate_channel"><option value="telegram">Telegram</option><option value="whatsapp">WhatsApp</option></select></td></tr><tr><th>USD per credit</th><td><input name="usd_per_credit" inputmode="decimal" required></td></tr><tr><th>Default credits per message</th><td><input name="credits_required" inputmode="decimal" required></td></tr><tr><th>Reason</th><td><input name="reason" maxlength="191" class="regular-text" required></td></tr></table><?php wp_nonce_field( 'ptc_wallet_rate_request' ); ?><button class="button button-primary" name="ptc_wallet_rate_request" value="1">Request 2FA approval</button></form><?php endif; ?></div><div class="wallet-tab-panel" data-wallet-panel="rates" style="display:none"><h2><?php esc_html_e( 'Rate card', 'chat-app-wallet' ); ?></h2><p>Set a higher per-message cost or make a selected billable message free. Channel defaults are managed on the Channel defaults tab.</p><?php if ( $catalog_error ) : ?><div class="notice notice-error"><p><?php echo esc_html( 'Active catalog unavailable: ' . $catalog_error . '. Confirm the deployed wallet-operations function and PTC_WALLET_OPERATIONS configuration.' ); ?></p></div><?php endif; ?>
		<?php $pending_rate = get_transient( 'ptc_wallet_rate_' . get_current_user_id() ); if ( is_array( $pending_rate ) && 'channel_default' !== $pending_rate['scope'] ) : ?><form method="post"><p><?php wp_nonce_field( 'ptc_wallet_rate_verify' ); ?><label for="rate_totp">Authenticator code</label> <input id="rate_totp" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required> <button class="button button-primary" name="ptc_wallet_rate_verify" value="1">Approve rate change</button></p></form><?php else : ?>
		<?php $channel_defaults = array( 'telegram' => number_format( $this->credit_requirement( 'telegram', '', '', 1.0 ), 6, '.', '' ), 'whatsapp' => number_format( $this->credit_requirement( 'whatsapp', '', '', 1.0 ), 6, '.', '' ) ); ?>
		<form method="post"><table class="form-table"><tr><th>Channel</th><td><select id="rate_channel" name="rate_channel"><option value="telegram">Telegram</option><option value="whatsapp">WhatsApp</option></select></td></tr><tr><th>Scope</th><td><select id="rate_scope" name="rate_scope"><option value="message_override">Message override</option><option value="message_ignore">Message ignore (no credit cost)</option></select></td></tr><tr><th>Current default credits per message</th><td><output id="rate_default_credits" data-telegram="<?php echo esc_attr( $channel_defaults['telegram'] ); ?>" data-whatsapp="<?php echo esc_attr( $channel_defaults['whatsapp'] ); ?>"></output> CRD</td></tr><tr id="rate_catalog_row"><th>Catalog message</th><td><select id="message_group" name="message_group"><option value="">Select group</option><?php foreach ( array_unique( array_column( $rate_catalog, 'group' ) ) as $group ) : ?><option value="<?php echo esc_attr( $group ); ?>"><?php echo esc_html( $group ); ?></option><?php endforeach; ?></select> <select id="message_name" name="message_name"><option value="">Select message</option><?php foreach ( $rate_catalog as $entry ) : ?><option data-channel="<?php echo esc_attr( $entry['channel'] ); ?>" data-group="<?php echo esc_attr( $entry['group'] ); ?>" value="<?php echo esc_attr( $entry['name'] ); ?>"><?php echo esc_html( $entry['name'] ); ?></option><?php endforeach; ?></select></td></tr><tr id="rate_usd_row"><th>USD per credit</th><td><input id="usd_per_credit" name="usd_per_credit" inputmode="decimal" required></td></tr><tr id="rate_credits_row"><th>Credits required</th><td><input id="credits_required" name="credits_required" inputmode="decimal" required></td></tr><tr><th>Reason</th><td><input name="reason" maxlength="191" class="regular-text" required></td></tr></table><?php wp_nonce_field( 'ptc_wallet_rate_request' ); ?><button class="button button-primary" name="ptc_wallet_rate_request" value="1">Request 2FA approval</button></form><script>document.addEventListener('DOMContentLoaded',function(){var c=document.getElementById('rate_channel'),s=document.getElementById('rate_scope'),g=document.getElementById('message_group'),m=document.getElementById('message_name'),d=document.getElementById('rate_default_credits'),u=document.getElementById('usd_per_credit'),r=document.getElementById('credits_required'),f=function(){var ignore=s.value==='message_ignore';d.textContent=d.dataset[c.value]||'1.000000';document.getElementById('rate_usd_row').style.display=ignore?'none':'';document.getElementById('rate_credits_row').style.display=ignore?'none':'';u.required=!ignore;r.required=!ignore;Array.prototype.forEach.call(m.options,function(o){o.hidden=!!o.dataset.channel&&(o.dataset.channel!==c.value||o.dataset.group!==g.value);});if(ignore){u.value='';r.value='0';}m.value='';};c.onchange=f;s.onchange=f;g.onchange=f;f();});</script><?php endif; ?>
		<h3><?php esc_html_e( 'Approved message costs', 'chat-app-wallet' ); ?></h3><table class="widefat striped"><thead><tr><th>Channel</th><th>Scope</th><th>Message</th><th>USD/CRD</th><th>CRD required</th><th>Reason</th><th>Action</th></tr></thead><tbody><?php foreach ( $active_rate_cards as $rate ) : if ( 'channel_default' === $rate['scope'] ) continue; $scope_label = array( 'message_override' => 'Message override', 'message_ignore' => 'Message ignore' )[ $rate['scope'] ] ?? $rate['scope']; ?><tr><td><?php echo esc_html( $rate['channel'] ); ?></td><td><?php echo esc_html( $scope_label ); ?></td><td><?php echo esc_html( $rate['message_group'] . ' / ' . $rate['message_name'] ); ?></td><td><?php echo esc_html( null === $rate['usd_per_credit'] ? '—' : $rate['usd_per_credit'] ); ?></td><td><?php echo esc_html( $rate['credits_required'] ); ?></td><td><?php echo esc_html( $rate['reason'] ); ?></td><td><form method="post"><input type="hidden" name="rate_channel" value="<?php echo esc_attr( $rate['channel'] ); ?>"><input type="hidden" name="rate_scope" value="<?php echo esc_attr( $rate['scope'] ); ?>"><input type="hidden" name="message_group" value="<?php echo esc_attr( $rate['message_group'] ); ?>"><input type="hidden" name="message_name" value="<?php echo esc_attr( $rate['message_name'] ); ?>"><?php wp_nonce_field( 'ptc_wallet_rate_remove_request' ); ?><button class="button" name="ptc_wallet_rate_remove_request" value="1">Remove / change cost</button></form></td></tr><?php endforeach; ?></tbody></table>
		</div><div class="wallet-tab-panel" data-wallet-panel="identities" style="display:none"><h2><?php esc_html_e( 'Wallet identities', 'chat-app-wallet' ); ?></h2><form method="get" style="margin:16px 0"><input type="hidden" name="page" value="chat-app-wallet"><label for="wallet_search">Search wallets / identities </label><input id="wallet_search" name="wallet_search" value="<?php echo esc_attr( $search ); ?>" placeholder="number, channel, status, activity date"><button class="button">Search</button></form>
		<table class="widefat striped"><thead><tr><th>Identity</th><th>Status</th><th>Wallet balance</th><th>Last activity</th><th>Actions</th></tr></thead><tbody>
		<?php foreach ( $visible as $identity ) : $key = $this->identity_key( $identity ); $wallet = $wallets[ $key ] ?? null; ?>
		<tr><td><?php echo esc_html( $key ); ?></td><td><?php echo esc_html( $identity['stage'] ?? '—' ); ?></td><td><?php echo esc_html( $wallet ? $wallet['balance'] . ' ' . $wallet['currency'] : 'No available funding' ); ?></td><td><?php echo esc_html( $identity['updated_at'] ?? '—' ); ?></td><td><?php if ( 'revoked' !== ( $identity['stage'] ?? '' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Revoke consent and access for this identity?');"><input type="hidden" name="action" value="ptc_wallet_revoke_access"><input type="hidden" name="channel" value="<?php echo esc_attr( $identity['channel'] ); ?>"><input type="hidden" name="sender_address" value="<?php echo esc_attr( $identity['sender_address'] ); ?>"><?php wp_nonce_field( 'ptc_wallet_revoke_access' ); ?><input required name="reason" maxlength="500" placeholder="Reason for revocation"><button class="button button-link-delete">Revoke consent &amp; access</button></form><?php else : ?>Revoked<?php endif; ?></td></tr>
		<?php endforeach; if ( ! $visible ) : ?><tr><td colspan="5">No identities found.</td></tr><?php endif; ?></tbody></table></div><script>document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('[data-wallet-tab]').forEach(function(t){t.onclick=function(e){e.preventDefault();document.querySelectorAll('[data-wallet-tab]').forEach(function(x){x.classList.remove('nav-tab-active');});document.querySelectorAll('[data-wallet-panel]').forEach(function(x){x.style.display='none';});t.classList.add('nav-tab-active');document.querySelector('[data-wallet-panel="'+t.dataset.walletTab+'"]').style.display='block';};});});</script></div>
		<?php
	}

	private function identity_key( $identity ) {
		$channel = sanitize_key( $identity['channel'] ?? '' );
		$address = sanitize_text_field( $identity['sender_address'] ?? '' );
		return $this->canonical_identity( $channel, $address );
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

	private function two_factor_request( $body ) {
		$url = defined( 'PTC_WALLET_2FA_URL' ) ? esc_url_raw( PTC_WALLET_2FA_URL ) : '';
		$token = defined( 'PTC_WALLET_2FA_TOKEN' ) ? trim( PTC_WALLET_2FA_TOKEN ) : '';
		if ( ! $url || ! $token ) return new WP_Error( 'wallet_2fa_not_configured' );
		$response = wp_remote_post( $url, array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
		if ( is_wp_error( $response ) ) return new WP_Error( 'wallet_2fa_unavailable' );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return wp_remote_retrieve_response_code( $response ) >= 200 && wp_remote_retrieve_response_code( $response ) < 300 && is_array( $data ) && ! empty( $data['ok'] ) ? $data : new WP_Error( 'wallet_2fa_rejected', sanitize_text_field( $data['error'] ?? 'wallet_2fa_rejected' ) );
	}

	private function apply_operator_adjustment( $pending ) {
		global $wpdb; $wallets = $wpdb->prefix . 'ptc_wallet_wallets'; $ledger = $wpdb->prefix . 'ptc_wallet_ledger';
		$wpdb->query( 'START TRANSACTION' );
		$wallet = $wpdb->get_row( $wpdb->prepare( "SELECT wallet_id FROM $wallets WHERE wallet_id=%s FOR UPDATE", $pending['walletId'] ), ARRAY_A );
		if ( ! $wallet ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'wallet_not_found' ); }
		$balance = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(credits),0) FROM $ledger WHERE wallet_id=%s", $wallet['wallet_id'] ) );
		if ( $balance + (float) $pending['credits'] < 0 ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'wallet_adjustment_exceeds_available_credit' ); }
		$inserted = $wpdb->insert( $ledger, array( 'ledger_id' => wp_generate_uuid4(), 'wallet_id' => $wallet['wallet_id'], 'event_type' => (float) $pending['credits'] > 0 ? 'grant' : 'adjustment', 'credits' => $pending['credits'], 'reference_id' => $pending['changeId'], 'reason' => $pending['reason'], 'actor' => $pending['actor'], 'created_at' => current_time( 'mysql', true ) ) );
		if ( ! $inserted ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'wallet_adjustment_store_failed' ); }
		$wpdb->query( 'COMMIT' ); return true;
	}

	private function handle_adjustment_forms() {
		if ( ! current_user_can( 'manage_options' ) ) return array( null, null );
		$user = wp_get_current_user(); $key = 'ptc_wallet_adjustment_' . $user->ID;
		if ( isset( $_POST['ptc_wallet_adjustment_request'] ) ) {
			check_admin_referer( 'ptc_wallet_adjustment_request' );
			$wallet_id = sanitize_text_field( wp_unslash( $_POST['wallet_id'] ?? '' ) ); $amount = (string) wp_unslash( $_POST['amount'] ?? '' ); $kind = sanitize_key( $_POST['adjustment_kind'] ?? '' ); $reason = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );
			if ( ! preg_match( '/^\d+(?:\.\d{1,6})?$/', $amount ) || (float) $amount <= 0 || ! in_array( $kind, array( 'grant', 'adjustment' ), true ) || ! $reason || ! preg_match( '/^[0-9a-f-]{36}$/i', $wallet_id ) ) return array( null, __( 'Enter a positive amount, a reason, and a valid wallet.', 'chat-app-wallet' ) );
			$credits = 'adjustment' === $kind ? -(float) $amount : (float) $amount; $change_id = wp_generate_uuid4();
			$pending = array( 'changeId' => $change_id, 'walletId' => $wallet_id, 'credits' => number_format( $credits, 6, '.', '' ), 'reason' => $reason, 'actor' => $user->user_login ); $json = wp_json_encode( $pending ); $digest = hash( 'sha256', $json );
			$challenge = $this->two_factor_request( array( 'event' => 'registry_change.challenge.create', 'wordpressUserId' => (string) $user->ID, 'changeId' => $change_id, 'action' => 'wallet.adjustment', 'changeDigest' => $digest, 'target' => array( 'walletId' => $wallet_id ) ) );
			if ( is_wp_error( $challenge ) ) return array( null, $challenge->get_error_message() );
			$pending['changeDigest'] = $digest; $pending['challengeId'] = $challenge['challengeId']; set_transient( $key, $pending, 2 * MINUTE_IN_SECONDS ); return array( __( 'Enter your authenticator code to approve this exact credit adjustment.', 'chat-app-wallet' ), null );
		}
		if ( isset( $_POST['ptc_wallet_adjustment_verify'] ) ) {
			check_admin_referer( 'ptc_wallet_adjustment_verify' ); $pending = get_transient( $key ); $code = sanitize_text_field( wp_unslash( $_POST['totp_code'] ?? '' ) );
			if ( ! is_array( $pending ) || ! preg_match( '/^\d{6}$/', $code ) ) return array( null, __( 'This adjustment approval expired. Start again.', 'chat-app-wallet' ) );
			$approval = $this->two_factor_request( array( 'event' => 'registry_change.challenge.verify', 'challengeId' => $pending['challengeId'], 'code' => $code ) );
			if ( is_wp_error( $approval ) ) return array( null, $approval->get_error_message() );
			$consumed = $this->two_factor_request( array( 'event' => 'wallet_adjustment.consume', 'changeId' => $pending['changeId'], 'action' => 'wallet.adjustment', 'changeDigest' => $pending['changeDigest'], 'target' => array( 'walletId' => $pending['walletId'] ), 'approvalToken' => $approval['approvalToken'] ) );
			if ( is_wp_error( $consumed ) ) return array( null, $consumed->get_error_message() ); $result = $this->apply_operator_adjustment( $pending ); if ( is_wp_error( $result ) ) return array( null, $result->get_error_message() ); delete_transient( $key ); return array( __( 'Wallet credit adjustment recorded.', 'chat-app-wallet' ), null );
		}
		return array( null, null );
	}

	private function rate_cards() {
		global $wpdb; return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ptc_wallet_rate_cards WHERE status='active' ORDER BY channel, scope, message_group, message_name", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function handle_rate_forms() {
		if ( ! current_user_can( 'manage_options' ) ) return array( null, null ); $user = wp_get_current_user(); $transient = 'ptc_wallet_rate_' . $user->ID;
		if ( isset( $_POST['ptc_wallet_rate_verify'] ) ) {
			check_admin_referer( 'ptc_wallet_rate_verify' ); $pending = get_transient( $transient ); $code = sanitize_text_field( wp_unslash( $_POST['totp_code'] ?? '' ) );
			if ( ! is_array( $pending ) || ! preg_match( '/^\d{6}$/', $code ) ) return array( null, __( 'This rate approval expired. Start again.', 'chat-app-wallet' ) );
			$approval = $this->two_factor_request( array( 'event' => 'registry_change.challenge.verify', 'challengeId' => $pending['challengeId'], 'code' => $code ) ); if ( is_wp_error( $approval ) ) return array( null, $approval->get_error_message() );
			$consumed = $this->two_factor_request( array( 'event' => 'wallet_rate_card.consume', 'changeId' => $pending['changeId'], 'action' => 'wallet.rate_card', 'changeDigest' => $pending['changeDigest'], 'target' => array( 'channel' => $pending['channel'], 'scope' => $pending['scope'], 'messageGroup' => $pending['group'], 'messageName' => $pending['name'] ), 'approvalToken' => $approval['approvalToken'] ) ); if ( is_wp_error( $consumed ) ) return array( null, $consumed->get_error_message() );
			global $wpdb; $rates = $wpdb->prefix . 'ptc_wallet_rate_cards'; $wpdb->query( 'START TRANSACTION' );
			$where = array( 'channel' => $pending['channel'], 'scope' => $pending['scope'], 'status' => 'active' ); if ( 'channel_default' !== $pending['scope'] ) { $where['message_group'] = $pending['group']; $where['message_name'] = $pending['name']; } $wpdb->update( $rates, array( 'status' => 'superseded', 'superseded_at' => current_time( 'mysql', true ) ), $where );
			if ( 'remove' === ( $pending['operation'] ?? '' ) ) { $wpdb->query( 'COMMIT' ); delete_transient( $transient ); return array( __( 'Message rate rule removed. The message is available in the Rate card again.', 'chat-app-wallet' ), null ); }
			$ok = $wpdb->insert( $rates, array( 'rate_card_id' => wp_generate_uuid4(), 'channel' => $pending['channel'], 'scope' => $pending['scope'], 'message_group' => $pending['group'] ?: null, 'message_name' => $pending['name'] ?: null, 'billing_meter' => $pending['meter'] ?: null, 'usd_per_credit' => $pending['usd'], 'credits_required' => $pending['credits'], 'status' => 'active', 'reason' => $pending['reason'], 'actor' => $pending['actor'], 'change_id' => $pending['changeId'], 'created_at' => current_time( 'mysql', true ) ) );
			if ( $ok && 'channel_default' === $pending['scope'] ) $wpdb->query( $wpdb->prepare( "UPDATE $rates SET status='superseded', superseded_at=%s WHERE channel=%s AND scope='message_override' AND status='active' AND credits_required <= %f", current_time( 'mysql', true ), $pending['channel'], $pending['credits'] ) );
			if ( ! $ok ) { $wpdb->query( 'ROLLBACK' ); return array( null, __( 'Could not store the rate change.', 'chat-app-wallet' ) ); } $wpdb->query( 'COMMIT' ); delete_transient( $transient ); return array( __( 'Rate card updated.', 'chat-app-wallet' ), null );
		}
		if ( isset( $_POST['ptc_wallet_rate_remove_request'] ) ) {
			check_admin_referer( 'ptc_wallet_rate_remove_request' ); $channel = sanitize_key( $_POST['rate_channel'] ?? '' ); $scope = sanitize_key( $_POST['rate_scope'] ?? '' ); $group = sanitize_text_field( wp_unslash( $_POST['message_group'] ?? '' ) ); $name = sanitize_text_field( wp_unslash( $_POST['message_name'] ?? '' ) );
			if ( ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || ! in_array( $scope, array( 'message_override', 'message_ignore' ), true ) || ! preg_match( '/^[A-Za-z0-9_-]+$/', $group ) || ! preg_match( '/^[A-Za-z0-9_-]+$/', $name ) ) return array( null, __( 'Select a valid approved message rule.', 'chat-app-wallet' ) );
			$pending = array( 'operation' => 'remove', 'changeId' => wp_generate_uuid4(), 'channel' => $channel, 'scope' => $scope, 'group' => $group, 'name' => $name, 'actor' => $user->user_login ); $digest = hash( 'sha256', wp_json_encode( $pending ) );
			$challenge = $this->two_factor_request( array( 'event' => 'registry_change.challenge.create', 'wordpressUserId' => (string) $user->ID, 'changeId' => $pending['changeId'], 'action' => 'wallet.rate_card', 'changeDigest' => $digest, 'target' => array( 'channel' => $channel, 'scope' => $scope, 'messageGroup' => $group, 'messageName' => $name ) ) ); if ( is_wp_error( $challenge ) ) return array( null, $challenge->get_error_message() );
			$pending['changeDigest'] = $digest; $pending['challengeId'] = $challenge['challengeId']; set_transient( $transient, $pending, 2 * MINUTE_IN_SECONDS ); return array( __( 'Enter an authenticator code to approve removal of this message rate rule.', 'chat-app-wallet' ), null );
		}
		if ( ! isset( $_POST['ptc_wallet_rate_request'] ) ) return array( null, null ); check_admin_referer( 'ptc_wallet_rate_request' );
		$channel = sanitize_key( $_POST['rate_channel'] ?? '' ); $scope = sanitize_key( $_POST['rate_scope'] ?? '' ); $group = sanitize_text_field( wp_unslash( $_POST['message_group'] ?? '' ) ); $name = sanitize_text_field( wp_unslash( $_POST['message_name'] ?? '' ) );
		$credits = (string) wp_unslash( $_POST['credits_required'] ?? '' ); $usd = (string) wp_unslash( $_POST['usd_per_credit'] ?? '' ); $reason = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );
		if ( ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) || ! in_array( $scope, array( 'channel_default', 'message_override', 'message_ignore' ), true ) || ! $reason || ( 'channel_default' !== $scope && ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $group ) || ! preg_match( '/^[A-Za-z0-9_-]+$/', $name ) ) ) ) return array( null, __( 'Select a valid channel, scope, and reason.', 'chat-app-wallet' ) );
		$catalog = $this->billable_catalog(); if ( is_wp_error( $catalog ) ) return array( null, $catalog->get_error_message() );
		$meter = ''; if ( 'channel_default' === $scope ) { $group = ''; $name = ''; if ( ! preg_match( '/^\d+(?:\.\d{1,6})?$/', $credits ) || (float) $credits <= 0 || ! preg_match( '/^\d+(?:\.\d{1,6})?$/', $usd ) || (float) $usd <= 0 ) return array( null, __( 'Enter valid positive rate values and a reason.', 'chat-app-wallet' ) ); } else { $catalog_match = false; foreach ( $catalog as $entry ) if ( $entry['channel'] === $channel && $entry['group'] === $group && $entry['name'] === $name ) $catalog_match = true; if ( ! $catalog_match ) return array( null, __( 'Select an active catalog message.', 'chat-app-wallet' ) ); if ( 'message_ignore' === $scope ) { $credits = '0'; $usd = null; } elseif ( ! preg_match( '/^\d+(?:\.\d{1,6})?$/', $credits ) || (float) $credits <= 0 || ! preg_match( '/^\d+(?:\.\d{1,6})?$/', $usd ) || (float) $usd <= 0 ) return array( null, __( 'Enter valid positive rate values and a reason.', 'chat-app-wallet' ) ); }
		$change_id = wp_generate_uuid4(); $pending = array( 'changeId' => $change_id, 'channel' => $channel, 'scope' => $scope, 'group' => $group, 'name' => $name, 'meter' => $meter, 'credits' => number_format( (float) $credits, 6, '.', '' ), 'usd' => null === $usd ? null : number_format( (float) $usd, 6, '.', '' ), 'reason' => $reason, 'actor' => $user->user_login ); $digest = hash( 'sha256', wp_json_encode( $pending ) );
		$challenge = $this->two_factor_request( array( 'event' => 'registry_change.challenge.create', 'wordpressUserId' => (string) $user->ID, 'changeId' => $change_id, 'action' => 'wallet.rate_card', 'changeDigest' => $digest, 'target' => array( 'channel' => $channel, 'scope' => $scope, 'messageGroup' => $group, 'messageName' => $name ) ) ); if ( is_wp_error( $challenge ) ) return array( null, $challenge->get_error_message() );
		$pending['changeDigest'] = $digest; $pending['challengeId'] = $challenge['challengeId']; set_transient( $transient, $pending, 2 * MINUTE_IN_SECONDS ); return array( __( 'Enter an authenticator code to approve this exact rate-card change.', 'chat-app-wallet' ), null );
	}

	private function suite_identities() {
		$result = $this->operations_request( array( 'action' => 'identities.list' ) );
		return is_wp_error( $result ) ? $result : (array) ( $result['identities'] ?? array() );
	}

	private function billable_catalog() {
		$result = $this->operations_request( array( 'action' => 'catalog.rate_card' ) );
		return is_wp_error( $result ) ? $result : (array) ( $result['definitions'] ?? array() );
	}

	private function wallets_by_identity() {
		global $wpdb;
		$entities = $wpdb->prefix . 'ptc_wallet_entities'; $wallets = $wpdb->prefix . 'ptc_wallet_wallets'; $ledger = $wpdb->prefix . 'ptc_wallet_ledger';
		$rows = $wpdb->get_results( "SELECT w.wallet_id, e.display_name, w.currency, COALESCE(SUM(l.credits), 0) AS balance FROM $entities e INNER JOIN $wallets w ON w.entity_id = e.entity_id LEFT JOIN $ledger l ON l.wallet_id = w.wallet_id GROUP BY w.wallet_id, e.entity_id, e.display_name, w.currency", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	$rates = $wpdb->prefix . 'ptc_wallet_rate_cards';
	$campaigns = $wpdb->prefix . 'ptc_wallet_referral_campaigns';
	$offers = $wpdb->prefix . 'ptc_wallet_referral_offers';
	$referrals = $wpdb->prefix . 'ptc_wallet_referrals';
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
		dbDelta( "CREATE TABLE $ledger ( ledger_id char(36) NOT NULL, wallet_id char(36) NOT NULL, event_type varchar(32) NOT NULL, credits decimal(18,6) NOT NULL, reference_id varchar(191) NULL, reason varchar(191) NULL, actor varchar(191) NULL, created_at datetime NOT NULL, PRIMARY KEY (ledger_id), KEY wallet_created (wallet_id, created_at) ) $charset;" );
		if ( ! $wpdb->get_var( "SHOW COLUMNS FROM $ledger LIKE 'actor'" ) ) $wpdb->query( "ALTER TABLE $ledger ADD COLUMN actor varchar(191) NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	dbDelta( "CREATE TABLE $authorizations ( authorization_id char(36) NOT NULL, wallet_id char(36) NOT NULL, entity_id char(36) NOT NULL, app_id varchar(64) NOT NULL, channel varchar(16) NOT NULL, meter varchar(64) NOT NULL, idempotency_key varchar(191) NOT NULL, reserved_credits decimal(18,6) NOT NULL, state varchar(16) NOT NULL, expires_at datetime NOT NULL, signature char(64) NOT NULL, claims longtext NULL, created_at datetime NOT NULL, PRIMARY KEY (authorization_id), UNIQUE KEY app_idempotency (app_id, idempotency_key), KEY wallet_state (wallet_id, state) ) $charset;" );
	$grants = $wpdb->prefix . 'ptc_wallet_access_grants';
	dbDelta( "CREATE TABLE $grants ( grant_id char(36) NOT NULL, wallet_id char(36) NOT NULL, payer_owner_entity_id char(36) NOT NULL, identity_key varchar(191) NOT NULL, channel varchar(16) NOT NULL DEFAULT '*', spending_limit decimal(18,6) NULL, priority int NOT NULL DEFAULT 100, status varchar(16) NOT NULL DEFAULT 'pending', audit_actor varchar(191) NOT NULL, created_at datetime NOT NULL, expires_at datetime NULL, accepted_at datetime NULL, revoked_at datetime NULL, PRIMARY KEY (grant_id), KEY eligible_identity (identity_key,status,priority) ) $charset;" );
	if ( $wpdb->get_var( "SHOW COLUMNS FROM $grants LIKE 'app_id'" ) ) $wpdb->query( "ALTER TABLE $grants DROP COLUMN app_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( array( 'expires_at datetime NULL', 'accepted_at datetime NULL' ) as $definition ) {
		$column = strtok( $definition, ' ' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $grants LIKE %s", $column ) ) ) $wpdb->query( "ALTER TABLE $grants ADD COLUMN $definition" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	dbDelta( "CREATE TABLE $receipts ( provider varchar(32) NOT NULL, provider_event_id varchar(191) NOT NULL, event_type varchar(96) NOT NULL, payload longtext NOT NULL, received_at datetime NOT NULL, PRIMARY KEY (provider, provider_event_id) ) $charset;" );
	$identity_events = $wpdb->prefix . 'ptc_wallet_identity_events';
	dbDelta( "CREATE TABLE $identity_events ( event_id char(36) NOT NULL, identity_key varchar(191) NOT NULL, event_type varchar(16) NOT NULL, occurred_at datetime NOT NULL, received_at datetime NOT NULL, PRIMARY KEY (event_id), KEY identity_received (identity_key, received_at) ) $charset;" );
	dbDelta( "CREATE TABLE $campaigns ( campaign_id char(36) NOT NULL, inviter_identity_key varchar(191) NOT NULL, channel varchar(16) NOT NULL, target_count int NOT NULL, grant_microcredits bigint NOT NULL, status varchar(16) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, completed_at datetime NULL, cancelled_at datetime NULL, PRIMARY KEY (campaign_id), KEY inviter_active (inviter_identity_key,channel,status) ) $charset;" );
	dbDelta( "CREATE TABLE $offers ( offer_id char(36) NOT NULL, campaign_id char(36) NOT NULL, token_hash char(64) NOT NULL, status varchar(16) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, cancelled_at datetime NULL, PRIMARY KEY (offer_id), UNIQUE KEY token_hash (token_hash), KEY campaign_status (campaign_id,status) ) $charset;" );
	dbDelta( "CREATE TABLE $referrals ( referral_id char(36) NOT NULL, campaign_id char(36) NOT NULL, referred_identity_key varchar(191) NOT NULL, status varchar(16) NOT NULL DEFAULT 'pending', grant_ledger_id char(36) NULL, created_at datetime NOT NULL, granted_at datetime NULL, cancelled_at datetime NULL, PRIMARY KEY (referral_id), UNIQUE KEY referred_identity (referred_identity_key), KEY campaign_status (campaign_id,status) ) $charset;" );
	dbDelta( "CREATE TABLE $rates ( rate_card_id char(36) NOT NULL, channel varchar(16) NOT NULL, scope varchar(32) NOT NULL, message_group varchar(96) NULL, message_name varchar(128) NULL, billing_meter varchar(96) NULL, usd_per_credit decimal(18,6) NULL, credits_required decimal(18,6) NOT NULL, status varchar(16) NOT NULL DEFAULT 'active', reason varchar(191) NOT NULL, actor varchar(191) NOT NULL, change_id char(36) NOT NULL, created_at datetime NOT NULL, superseded_at datetime NULL, PRIMARY KEY (rate_card_id), KEY active_channel (channel,scope,status), KEY active_message (channel,message_group,message_name,status) ) $charset;" );
	// Earlier SB Coms payloads could contain an already-prefixed sender address.
	// Repair only unambiguous duplicate prefixes; never merge a second entity.
	foreach ( array( 'telegram', 'whatsapp' ) as $channel ) {
		$duplicate = $channel . ':' . $channel . ':%';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT entity_id, identity_key FROM $entities WHERE identity_key LIKE %s", $duplicate ), ARRAY_A );
		foreach ( $rows as $row ) {
			$canonical = $channel . ':' . substr( $row['identity_key'], strlen( $channel . ':' . $channel . ':' ) );
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT entity_id FROM $entities WHERE identity_key=%s", $canonical ) );
			if ( ! $exists ) $wpdb->update( $entities, array( 'identity_key' => $canonical, 'display_name' => $canonical ), array( 'entity_id' => $row['entity_id'] ) );
		}
	}
}
register_activation_hook( __FILE__, 'ptc_chat_app_wallet_install_schema' );
function ptc_chat_app_wallet_maybe_upgrade_schema() {
	if ( get_option( 'ptc_chat_app_wallet_schema_version' ) !== '14' ) {
		ptc_chat_app_wallet_install_schema();
		update_option( 'ptc_chat_app_wallet_schema_version', '14', false );
	}
	if ( ! wp_next_scheduled( 'ptc_wallet_release_expired_reservations' ) ) wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', 'ptc_wallet_release_expired_reservations' );
}
add_action( 'plugins_loaded', 'ptc_chat_app_wallet_maybe_upgrade_schema', 4 );
add_action( 'plugins_loaded', 'postoochat_wallet', 5 );
