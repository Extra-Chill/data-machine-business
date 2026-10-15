<?php
/**
 * Smoke coverage for datamachine/sendy-send-campaign.
 *
 * Asserts the request Sendy receives (create.php with send_campaign=1 and
 * comma-joined targeting), validation that rejects bad input before any HTTP
 * call, schedule conversion, and response mapping.
 *
 * Run with: php tests/sendy-send-campaign-smoke.php
 */

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static bool $allowed = true;

		public static function can_manage(): bool {
			return self::$allowed;
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
	$sendy_config = array(
		'api_key'   => 'api-secret',
		'sendy_url' => 'https://sendy.example.test',
	);
	$http_calls   = array();
	$http_reply   = array( 'code' => 200, 'body' => 'Campaign created and now sending' );

	define( 'ABSPATH', $root . '/' );

	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code, string $message, $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}

	function assert_send( bool $condition, string $name ): void {
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
		global $sendy_config;
		return $sendy_config ?: $default;
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

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function wp_remote_post( string $url, array $args ) {
		global $http_calls, $http_reply;
		$http_calls[] = array( 'url' => $url, 'body' => $args['body'] );
		if ( 'timeout' === $http_reply ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: api-secret timed out' );
		}
		return $http_reply;
	}

	function wp_remote_retrieve_body( array $response ): string {
		return $response['body'];
	}

	function wp_remote_retrieve_response_code( array $response ): int {
		return $response['code'];
	}

	require_once $root . '/inc/Sendy/SendyClient.php';
	require_once $root . '/inc/Abilities/Sendy/SendyAbilities.php';

	$abilities = new \DataMachineBusiness\Abilities\Sendy\SendyAbilities();

	// ── Registration contract ──
	$definition = $registered['datamachine/sendy-send-campaign'] ?? null;
	assert_send( null !== $definition, 'send ability is registered' );
	assert_send( true === ( $definition['meta']['annotations']['destructive'] ?? null ), 'send ability is annotated destructive' );
	assert_send( false === ( $definition['meta']['annotations']['idempotent'] ?? null ), 'send ability is annotated non-idempotent' );
	assert_send( false === ( $definition['meta']['show_in_rest'] ?? null ), 'send ability is hidden from the REST runner' );
	assert_send( ! isset( $definition['input_schema']['properties']['config'] ), 'send ability does not accept credentials' );
	assert_send( false === ( $definition['input_schema']['additionalProperties'] ?? null ), 'send ability rejects unknown input' );
	assert_send( isset( $registered['datamachine/sendy-push-campaign'] ), 'draft push ability is still registered' );
	assert_send( ! isset( $registered['datamachine/sendy-push-campaign']['input_schema']['properties']['list_ids'] ), 'draft push ability gained no send targeting' );

	$base = array(
		'from_name'  => 'Extra Chill',
		'from_email' => 'newsletter@example.com',
		'reply_to'   => 'reply@example.com',
		'subject'    => 'Meetup next Wednesday',
		'html_text'  => '<p>Hi</p>',
		'plain_text' => 'Hi',
		'brand_id'   => '1',
	);

	// ── Validation happens before any HTTP call ──
	$http_calls = array();
	$no_target  = $abilities->execute_send_campaign( $base );
	assert_send( is_wp_error( $no_target ) && 'sendy_validation_error' === $no_target->get_error_code(), 'missing lists and segments is rejected' );

	$empty = $abilities->execute_send_campaign( $base + array( 'list_ids' => array() ) );
	assert_send( is_wp_error( $empty ), 'empty list_ids is rejected' );

	$comma = $abilities->execute_send_campaign( $base + array( 'list_ids' => array( 'abc,def' ) ) );
	assert_send( is_wp_error( $comma ), 'comma-smuggled list ID is rejected' );

	$overlap = $abilities->execute_send_campaign( $base + array( 'list_ids' => array( 'L1' ), 'exclude_list_ids' => array( 'L1' ) ) );
	assert_send( is_wp_error( $overlap ), 'a list both targeted and excluded is rejected' );

	$bad_email = $abilities->execute_send_campaign( array_merge( $base, array( 'from_email' => 'nope', 'list_ids' => array( 'L1' ) ) ) );
	assert_send( is_wp_error( $bad_email ), 'invalid sender email is rejected' );

	$missing_subject = $abilities->execute_send_campaign( array_merge( $base, array( 'subject' => ' ', 'list_ids' => array( 'L1' ) ) ) );
	assert_send( is_wp_error( $missing_subject ), 'blank subject is rejected' );

	$future = ( (int) gmdate( 'Y' ) + 2 ) . '-06-15 18:05';
	$bad_minute = $abilities->execute_send_campaign( $base + array( 'list_ids' => array( 'L1' ), 'schedule_date_time' => substr( $future, 0, 14 ) . '03', 'schedule_timezone' => 'America/New_York' ) );
	assert_send( is_wp_error( $bad_minute ), 'schedule minute not a multiple of 5 is rejected' );

	$no_tz = $abilities->execute_send_campaign( $base + array( 'list_ids' => array( 'L1' ), 'schedule_date_time' => $future ) );
	assert_send( is_wp_error( $no_tz ), 'schedule without timezone is rejected' );

	$bad_tz = $abilities->execute_send_campaign( $base + array( 'list_ids' => array( 'L1' ), 'schedule_date_time' => $future, 'schedule_timezone' => 'Mars/Olympus' ) );
	assert_send( is_wp_error( $bad_tz ), 'invalid timezone is rejected' );

	$past = $abilities->execute_send_campaign( $base + array( 'list_ids' => array( 'L1' ), 'schedule_date_time' => '2001-01-01 10:00', 'schedule_timezone' => 'America/New_York' ) );
	assert_send( is_wp_error( $past ), 'past schedule is rejected' );

	$bad_format = $abilities->execute_send_campaign( $base + array( 'list_ids' => array( 'L1' ), 'schedule_date_time' => 'next tuesday', 'schedule_timezone' => 'America/New_York' ) );
	assert_send( is_wp_error( $bad_format ), 'free-form schedule is rejected' );

	assert_send( array() === $http_calls, 'no HTTP call is made for invalid input' );

	// ── Send now ──
	$http_reply = array( 'code' => 200, 'body' => 'Campaign created and now sending' );
	$sent       = $abilities->execute_send_campaign(
		$base + array(
			'list_ids'         => array( 'L1', 'L2', 'L1' ),
			'exclude_list_ids' => array( 'X9' ),
		)
	);
	$call       = end( $http_calls );
	assert_send( ! is_wp_error( $sent ) && true === $sent['success'] && false === $sent['scheduled'], 'send-now succeeds and is not scheduled' );
	assert_send( ! isset( $sent['raw'] ), 'send response omits raw provider data' );
	assert_send( 'https://sendy.example.test/api/campaigns/create.php' === $call['url'], 'send always targets create.php' );
	assert_send( 1 === $call['body']['send_campaign'], 'send_campaign=1 is posted' );
	assert_send( 'L1,L2' === $call['body']['list_ids'], 'list_ids are deduped and comma-joined' );
	assert_send( 'X9' === $call['body']['exclude_list_ids'], 'exclude_list_ids pass through' );
	assert_send( ! isset( $call['body']['schedule_date_time'] ), 'send-now posts no schedule' );
	assert_send( 1 === count( $http_calls ), 'send-now makes exactly one HTTP call (no status/update lookup)' );

	// ── Segments and segment exclusions ──
	$abilities->execute_send_campaign( $base + array( 'segment_ids' => array( '7' ), 'exclude_segment_ids' => array( '8' ) ) );
	$call = end( $http_calls );
	assert_send( '7' === $call['body']['segment_ids'] && ! isset( $call['body']['list_ids'] ), 'segment-only send posts segment_ids' );
	assert_send( '8' === ( $call['body']['exclude_segments_ids'] ?? null ), 'segment exclusions use Sendy\'s exclude_segments_ids name' );

	// ── Schedule ──
	$http_reply = array( 'code' => 200, 'body' => 'Campaign scheduled' );
	$scheduled  = $abilities->execute_send_campaign( $base + array( 'list_ids' => array( 'L1' ), 'schedule_date_time' => $future, 'schedule_timezone' => 'America/New_York' ) );
	$call       = end( $http_calls );
	$expected   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $future, new DateTimeZone( 'America/New_York' ) )->format( 'F j, Y g:ia' );
	assert_send( ! is_wp_error( $scheduled ) && true === $scheduled['scheduled'], 'scheduled send reports scheduled' );
	assert_send( $expected === $call['body']['schedule_date_time'], 'schedule is converted to Sendy\'s date format' );
	assert_send( 'America/New_York' === $call['body']['schedule_timezone'], 'schedule timezone passes through' );

	// ── Response mapping ──
	$http_reply = array( 'code' => 200, 'body' => 'One or more list IDs are invalid' );
	$rejected   = $abilities->execute_send_campaign( $base + array( 'list_ids' => array( 'L1' ) ) );
	assert_send( is_wp_error( $rejected ) && 'sendy_send_rejected' === $rejected->get_error_code(), 'Sendy error sentences map to failure' );
	assert_send( false !== strpos( $rejected->get_error_message(), 'One or more list IDs are invalid' ), 'known Sendy error text is surfaced' );

	$http_reply = array( 'code' => 200, 'body' => 'Campaign created' );
	$draft_only = $abilities->execute_send_campaign( $base + array( 'list_ids' => array( 'L1' ) ) );
	assert_send( is_wp_error( $draft_only ), 'a created-but-not-sent response is not reported as sent' );

	$http_reply = array( 'code' => 200, 'body' => '<html>Fatal error in /var/www/api-secret</html>' );
	$unknown    = $abilities->execute_send_campaign( $base + array( 'list_ids' => array( 'L1' ) ) );
	assert_send( is_wp_error( $unknown ) && false === strpos( $unknown->get_error_message(), 'api-secret' ), 'unknown responses are not echoed' );

	$http_reply = array( 'code' => 500, 'body' => 'boom api-secret' );
	$remote     = $abilities->execute_send_campaign( $base + array( 'list_ids' => array( 'L1' ) ) );
	assert_send( is_wp_error( $remote ) && 'sendy_remote_error' === $remote->get_error_code(), 'HTTP errors are explicit' );

	$http_reply = 'timeout';
	$timeout    = $abilities->execute_send_campaign( $base + array( 'list_ids' => array( 'L1' ) ) );
	assert_send( is_wp_error( $timeout ) && 'sendy_timeout' === $timeout->get_error_code(), 'timeouts are explicit' );

	// ── Permissions and configuration ──
	\DataMachine\Abilities\PermissionHelper::$allowed = false;
	assert_send( ! call_user_func( $definition['permission_callback'] ), 'send ability rejects non-managers' );
	\DataMachine\Abilities\PermissionHelper::$allowed = true;

	$sendy_config = array( 'api_key' => 'api-secret' );
	$incomplete   = $abilities->execute_send_campaign( $base + array( 'list_ids' => array( 'L1' ) ) );
	assert_send( is_wp_error( $incomplete ) && 'sendy_config_incomplete' === $incomplete->get_error_code(), 'incomplete config is explicit' );

	foreach ( array( $sent, $scheduled, $rejected, $unknown, $remote, $timeout, $incomplete ) as $result ) {
		if ( is_wp_error( $result ) ) {
			$result = array( $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
		}
		assert_send( false === strpos( serialize( $result ), 'api-secret' ), 'credentials are redacted from public results' );
	}

	if ( ! empty( $failures ) ) {
		fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
		exit( 1 );
	}

	echo "Sendy send-campaign smoke checks passed ({$passes} assertions).\n";

}
