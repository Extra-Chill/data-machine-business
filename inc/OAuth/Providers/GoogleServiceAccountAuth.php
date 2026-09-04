<?php
/**
 * Google service account provider.
 *
 * Vendor half of the RFC 7523 JWT-bearer flow. Core's
 * BaseServiceAccountProvider owns claim assembly, RS256 signing, the token
 * exchange, and network-wide caching; this class supplies only Google's token
 * endpoint and the stored credential.
 *
 * Distinct from GoogleAuth, which is the OAuth2 user-consent provider for
 * Sheets and Drive. Both talk to Google, but a service account authenticates
 * as itself with no user in the loop, so it is a different grant with
 * different storage.
 *
 * @package DataMachineBusiness\OAuth\Providers
 */

namespace DataMachineBusiness\OAuth\Providers;

use DataMachine\Core\OAuth\BaseServiceAccountProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoogleServiceAccountAuth extends BaseServiceAccountProvider {

	public const PROVIDER_SLUG = 'google_service_account';

	/**
	 * Default legacy option the credential is read from.
	 */
	public const LEGACY_CONFIG_OPTION = 'datamachine_ga_config';

	/**
	 * Legacy option this instance falls back to.
	 *
	 * Analytics and Search Console store credentials under separate options
	 * today. Preserving each keeps existing installs working without a
	 * migration step; new configuration flows through provider storage, which
	 * encrypts the private key at rest.
	 *
	 * @var string
	 */
	private $legacy_option;

	/**
	 * @param string $legacy_option Legacy option to fall back to.
	 */
	public function __construct( string $legacy_option = self::LEGACY_CONFIG_OPTION ) {
		parent::__construct( self::PROVIDER_SLUG );
		$this->legacy_option = '' !== $legacy_option ? $legacy_option : self::LEGACY_CONFIG_OPTION;
	}

	public function get_token_endpoint(): string {
		return 'https://oauth2.googleapis.com/token';
	}

	/**
	 * Configuration fields for CLI/UI credential entry.
	 */
	public function get_config_fields(): array {
		return array(
			'service_account_json' => array(
				'label'       => __( 'Service account JSON', 'data-machine-business' ),
				'type'        => 'textarea',
				'required'    => true,
				'description' => __( 'The full service account key file contents, including client_email and private_key.', 'data-machine-business' ),
			),
			'subject'              => array(
				'label'       => __( 'Delegated subject', 'data-machine-business' ),
				'type'        => 'text',
				'required'    => false,
				'description' => __( 'Optional user to impersonate for domain-wide delegation.', 'data-machine-business' ),
			),
		);
	}

	/**
	 * Read provider config, falling back to the legacy shared option.
	 *
	 * @param array $context Optional resolution context.
	 * @return array
	 */
	public function get_config( array $context = array() ): array {
		$config = parent::get_config( $context );

		if ( ! empty( $config['service_account_json'] ) || ! empty( $config['private_key'] ) ) {
			return $config;
		}

		$legacy = get_site_option( $this->legacy_option, array() );

		return is_array( $legacy ) ? $legacy : array();
	}
}
