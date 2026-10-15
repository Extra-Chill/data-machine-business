<?php
/**
 * Smoke coverage for the single canonical Sendy configuration.
 *
 * Subscribe and metrics resolve datamachine_sendy_config when the caller omits
 * the legacy `config` override, and update_api_config() writes the API
 * connection without dropping database credentials.
 *
 * Run with: php tests/sendy-canonical-config-smoke.php
 */

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static function can_manage(): bool {
			return true;
		}
	}

	class AbilityRegistration {
		public static function on_abilities_api_init( callable $callback ): void {
			$callback();
		}
	}
}

namespace {
	$root         = dirname( __DIR__ );
	$failures     = array();
	$passes       = 0;
	$registered   = array();
	$site_options = array();
	$http_calls   = array();

	define( 'ABSPATH', $root . '/' );

	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code, string $message, $data = null ) {
			unset( $data );
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	function assert_config( bool $condition, string $name ): void {
		global $failures, $passes;
		if ( $condition ) {
			++$passes;
			return;
		}
		$failures[] = $name;
	}

	function __( string $text, string $domain = '' ): string {
		return $text;
	}

	function wp_register_ability( string $name, array $definition ): void {
		global $registered;
		$registered[ $name ] = $definition;
	}

	function get_site_option( string $option, $default = array() ) {
		global $site_options;
		return array_key_exists( $option, $site_options ) ? $site_options[ $option ] : $default;
	}

	function update_site_option( string $option, $value ): bool {
		global $site_options;
		$site_options[ $option ] = $value;
		return true;
	}

	function apply_filters( string $hook, $value ) {
		return $value;
	}

	function wp_parse_args( array $args, array $defaults ): array {
		return array_merge( $defaults, $args );
	}

	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/' );
	}

	function wp_http_validate_url( string $url ) {
		return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : false;
	}

	function is_email( string $email ) {
		return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}

	function absint( $value ): int {
		return abs( (int) $value );
	}

	function sanitize_text_field( $value ): string {
		return trim( (string) $value );
	}

	function sanitize_email( $value ): string {
		return trim( (string) $value );
	}

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function wp_remote_post( string $url, array $args ) {
		global $http_calls;
		$http_calls[] = array( 'url' => $url, 'body' => $args['body'] ?? array() );
		if ( false !== strpos( $url, 'get-brands' ) ) {
			return array( 'code' => 200, 'body' => '{"brand1":{"id":"1","name":"Brand"}}' );
		}
		if ( false !== strpos( $url, 'get-lists' ) ) {
			return array( 'code' => 200, 'body' => '{"list1":{"id":"ListHash","name":"Main"}}' );
		}
		if ( false !== strpos( $url, 'active-subscriber-count' ) ) {
			return array( 'code' => 200, 'body' => '42' );
		}
		return array( 'code' => 200, 'body' => '1' );
	}

	function wp_remote_retrieve_body( array $response ): string {
		return $response['body'];
	}

	function wp_remote_retrieve_response_code( array $response ): int {
		return $response['code'];
	}

	require_once $root . '/inc/Sendy/SendyClient.php';
	require_once $root . '/inc/Abilities/Sendy/SendyAbilities.php';

	use DataMachineBusiness\Abilities\Sendy\SendyAbilities;

	$abilities = new SendyAbilities();

	// ── Schemas no longer require caller credentials ──
	assert_config( ! in_array( 'config', $registered['datamachine/sendy-subscribe']['input_schema']['required'], true ), 'subscribe does not require config' );
	assert_config( ! in_array( 'config', $registered['datamachine/sendy-metrics']['input_schema']['required'] ?? array(), true ), 'metrics does not require config' );

	// ── No canonical config: explicit errors ──
	$absent = $abilities->execute_subscribe( array( 'list_id' => 'L1', 'email' => 'fan@example.com' ) );
	assert_config( is_wp_error( $absent ) && 'sendy_provider_unavailable' === $absent->get_error_code(), 'subscribe without any config is explicit' );
	assert_config( false !== strpos( $absent->get_error_message(), 'datamachine_sendy_config' ), 'error names the option to set' );
	assert_config( ! SendyAbilities::has_api_config(), 'has_api_config is false when empty' );

	// ── DB-only canonical config (the production state that broke push) ──
	$site_options[ SendyAbilities::CONFIG_OPTION ] = array(
		'db' => array( 'host' => 'localhost', 'user' => 'sendy', 'pass' => 'db-secret', 'name' => 'sendy' ),
	);
	$db_only = $abilities->execute_subscribe( array( 'list_id' => 'L1', 'email' => 'fan@example.com' ) );
	assert_config( is_wp_error( $db_only ) && 'sendy_config_incomplete' === $db_only->get_error_code(), 'subscribe with db-only config reports missing API fields' );
	assert_config( false !== strpos( $db_only->get_error_message(), 'datamachine_sendy_config' ), 'incomplete error names the option' );
	$api_metrics = $abilities->execute_metrics( array( 'metric' => 'subscribers', 'source' => 'api' ) );
	assert_config( is_wp_error( $api_metrics ) && 'sendy_config_incomplete' === $api_metrics->get_error_code(), 'api-sourced metrics need API fields' );
	assert_config( array() === $http_calls, 'incomplete config makes no HTTP calls' );

	// ── update_api_config ──
	$bad_url = SendyAbilities::update_api_config( 'key', 'not a url' );
	assert_config( is_wp_error( $bad_url ) && 'sendy_config_invalid' === $bad_url->get_error_code(), 'invalid URL is rejected' );
	$empty = SendyAbilities::update_api_config( '', 'https://mail.example.test' );
	assert_config( is_wp_error( $empty ) && 'sendy_config_incomplete' === $empty->get_error_code(), 'empty key is rejected' );
	assert_config( ! isset( $site_options[ SendyAbilities::CONFIG_OPTION ]['api_key'] ), 'rejected updates write nothing' );

	$saved  = SendyAbilities::update_api_config( ' canonical-secret ', 'https://mail.example.test/ ' );
	$stored = $site_options[ SendyAbilities::CONFIG_OPTION ];
	assert_config( true === $saved, 'valid API config saves' );
	assert_config( 'canonical-secret' === $stored['api_key'] && 'https://mail.example.test' === $stored['sendy_url'], 'API config is trimmed and unslashed' );
	assert_config( 'db-secret' === $stored['db']['pass'], 'database credentials are preserved' );
	assert_config( SendyAbilities::has_api_config(), 'has_api_config is true after saving' );

	// ── Canonical config drives subscribe and metrics ──
	$subscribed = $abilities->execute_subscribe( array( 'list_id' => 'L1', 'email' => 'fan@example.com' ) );
	$call       = end( $http_calls );
	assert_config( ! is_wp_error( $subscribed ), 'subscribe succeeds with canonical config' );
	assert_config( 'https://mail.example.test/subscribe' === $call['url'] && 'canonical-secret' === $call['body']['api_key'], 'subscribe uses the canonical URL and key' );

	$http_calls = array();
	$stats      = $abilities->execute_metrics( array( 'metric' => 'subscribers', 'source' => 'api' ) );
	assert_config( ! is_wp_error( $stats ) && 42 === ( $stats['total_active'] ?? null ), 'metrics resolve canonical config' );
	assert_config( 0 === strpos( $http_calls[0]['url'], 'https://mail.example.test/' ), 'metrics hit the canonical URL' );

	// ── Legacy override still works for unmigrated callers ──
	$abilities->execute_subscribe(
		array(
			'config'  => array( 'api_key' => 'legacy-secret', 'sendy_url' => 'https://legacy.example.test' ),
			'list_id' => 'L1',
			'email'   => 'fan@example.com',
		)
	);
	$call = end( $http_calls );
	assert_config( 'https://legacy.example.test/subscribe' === $call['url'] && 'legacy-secret' === $call['body']['api_key'], 'an explicit config override is honored' );

	$partial = $abilities->execute_subscribe( array( 'config' => array( 'api_key' => 'x' ), 'list_id' => 'L1', 'email' => 'fan@example.com' ) );
	assert_config( is_wp_error( $partial ) && 'sendy_config_incomplete' === $partial->get_error_code(), 'an incomplete override is still rejected' );

	foreach ( array( $absent, $db_only, $api_metrics, $stats ) as $result ) {
		if ( is_wp_error( $result ) ) {
			$result = array( $result->get_error_code(), $result->get_error_message() );
		}
		$serialized = serialize( $result );
		assert_config( false === strpos( $serialized, 'secret' ), 'credentials never appear in results' );
	}

	if ( ! empty( $failures ) ) {
		fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
		exit( 1 );
	}

	echo "Sendy canonical config smoke checks passed ({$passes} assertions).\n";
}
