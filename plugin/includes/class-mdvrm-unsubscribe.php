<?php
/**
 * Email opt-out handling for Mudrava RUM.
 *
 * @package MudravaRUM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a report recipient unsubscribe with a single signed link.
 *
 * The link works without any login. It carries a 160-bit HMAC token derived
 * from the recipient address and a stored site secret, so no address appears in
 * the URL, nothing has to be looked up in the database, and a forged token is
 * not computable. Requests are served through admin-post.php, which is always
 * dynamic, so page caches cannot swallow an opt-out.
 */
class MDVRM_Unsubscribe {

	/**
	 * Option holding opted-out addresses as address => timestamp map.
	 */
	const OPTOUT_OPTION = 'mdvrm_optouts';

	/**
	 * Option holding the HMAC secret used to sign tokens.
	 */
	const SECRET_OPTION = 'mdvrm_unsub_secret';

	/**
	 * Admin-post action used by the link inside emails.
	 */
	const ACTION = 'mdvrm_unsub';

	/**
	 * Admin-post action used by the admin "resume reports" control.
	 */
	const RESUME_ACTION = 'mdvrm_resubscribe';

	/**
	 * Token length in hex characters (160 bits).
	 */
	const TOKEN_LENGTH = 40;

	/**
	 * Tolerated forged-token attempts per client address per minute.
	 *
	 * A full allowance is far above what a real recipient can trigger by
	 * clicking, so a throttled address only means someone next to it tried
	 * forged links. The lockout is short and never removes an opt-out.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Lifetime of the attempt counter in seconds.
	 */
	const ATTEMPT_WINDOW = 120;

	/**
	 * Largest opt-out map kept in the option.
	 */
	const MAX_OPTOUTS = 50;

	/**
	 * Plugin reference.
	 *
	 * @var MDVRM_Plugin
	 */
	protected $plugin;

	/**
	 * Per-request cache of the opt-out map.
	 *
	 * @var array|null
	 */
	protected $optout_cache = null;

	/**
	 * Constructor.
	 *
	 * @param MDVRM_Plugin $plugin Plugin instance.
	 */
	public function __construct( MDVRM_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook the public opt-out endpoint and the admin resume endpoint.
	 */
	public function hook(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'serve_unsubscribe' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'serve_unsubscribe' ) );
		add_action( 'admin_post_' . self::RESUME_ACTION, array( $this, 'serve_resume' ) );
	}

	/**
	 * Build the signed opt-out URL for one address.
	 *
	 * @param string $email Recipient address.
	 * @return string Absolute URL, empty for an invalid address.
	 */
	public function link( string $email ): string {
		$normalized = sanitize_email( trim( $email ) );
		if ( ! is_email( $normalized ) ) {
			return '';
		}

		return add_query_arg(
			array(
				'action' => self::ACTION,
				't'      => self::token( $normalized ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Derive the opt-out token for an address.
	 *
	 * Stateless on purpose: the token is reproducible from the address and the
	 * site secret, so no token store and no garbage collection is needed.
	 *
	 * @param string $email Recipient address.
	 * @return string Lowercase hex token.
	 */
	public static function token( string $email ): string {
		$normalized = strtolower( trim( $email ) );

		return substr( hash_hmac( 'sha256', self::ACTION . '|' . $normalized, self::secret() ), 0, self::TOKEN_LENGTH );
	}

	/**
	 * Get or create the site secret used to sign opt-out tokens.
	 *
	 * Stored in its own non-autoloaded option instead of reusing wp_salt(), so
	 * rotating core salts does not break links that are already in inboxes.
	 *
	 * @return string Hex secret.
	 */
	public static function secret(): string {
		$secret = get_option( self::SECRET_OPTION, '' );
		if ( is_string( $secret ) && preg_match( '/^[a-f0-9]{64}$/', $secret ) ) {
			return $secret;
		}

		$secret = bin2hex( random_bytes( 32 ) );
		if ( ! add_option( self::SECRET_OPTION, $secret, '', 'no' ) ) {
			update_option( self::SECRET_OPTION, $secret );
		}

		return $secret;
	}

	/**
	 * Read the opt-out map.
	 *
	 * @return array Address => timestamp.
	 */
	public function optouts(): array {
		if ( is_array( $this->optout_cache ) ) {
			return $this->optout_cache;
		}

		$stored = get_option( self::OPTOUT_OPTION, array() );
		$map    = array();

		if ( is_array( $stored ) ) {
			foreach ( $stored as $email => $when ) {
				$email = is_string( $email ) ? strtolower( sanitize_email( $email ) ) : '';
				if ( is_email( $email ) ) {
					$map[ $email ] = absint( $when );
				}
			}
		}

		$this->optout_cache = $map;

		return $map;
	}

	/**
	 * Persist the opt-out map.
	 *
	 * @param array $map Address => timestamp.
	 */
	protected function save_optouts( array $map ): void {
		$map = array_slice( $map, -1 * self::MAX_OPTOUTS, null, true );
		ksort( $map, SORT_STRING );

		$this->optout_cache = $map;

		if ( ! add_option( self::OPTOUT_OPTION, $map, '', 'no' ) ) {
			update_option( self::OPTOUT_OPTION, $map );
		}
	}

	/**
	 * Whether an address stopped receiving plugin emails.
	 *
	 * @param string $email Address to test.
	 * @return bool
	 */
	public function is_opted_out( string $email ): bool {
		$normalized = strtolower( trim( $email ) );
		if ( ! is_email( $normalized ) ) {
			return false;
		}

		return array_key_exists( $normalized, $this->optouts() );
	}

	/**
	 * Stop sending emails to an address.
	 *
	 * @param string $email Address to opt out.
	 * @return bool True when the address was newly opted out.
	 */
	public function opt_out( string $email ): bool {
		$normalized = strtolower( trim( $email ) );
		if ( ! is_email( $normalized ) ) {
			return false;
		}

		$map = $this->optouts();
		if ( array_key_exists( $normalized, $map ) ) {
			return false;
		}

		$map[ $normalized ] = time();
		$this->save_optouts( $map );

		/**
		 * Fires after an address stops receiving Mudrava RUM emails.
		 *
		 * @param string $email Address that opted out.
		 */
		do_action( 'mdvrm_unsubscribed', $normalized );

		return true;
	}

	/**
	 * Resume sending emails to an address.
	 *
	 * @param string $email Address to opt back in.
	 * @return bool True when an opt-out was removed.
	 */
	public function opt_in( string $email ): bool {
		$normalized = strtolower( trim( $email ) );
		if ( ! is_email( $normalized ) ) {
			return false;
		}

		$map = $this->optouts();
		if ( ! array_key_exists( $normalized, $map ) ) {
			return false;
		}

		unset( $map[ $normalized ] );
		$this->save_optouts( $map );

		/**
		 * Fires after an address starts receiving Mudrava RUM emails again.
		 *
		 * @param string $email Address that was re-subscribed.
		 */
		do_action( 'mdvrm_resubscribed', $normalized );

		return true;
	}

	/**
	 * Find which address a token belongs to.
	 *
	 * Only a short closed list is tested: the configured recipient, the site
	 * admin address as fallback, and addresses that already opted out so an old
	 * email still leads to a truthful page. Each candidate is compared in
	 * constant time; the list never holds more than a handful of entries.
	 *
	 * @param string $token Candidate token.
	 * @return string Matched address, empty when none matches.
	 */
	public function resolve( string $token ): string {
		if ( ! preg_match( '/^[a-f0-9]{' . self::TOKEN_LENGTH . '}$/', $token ) ) {
			return '';
		}

		$candidates = array();

		$recipient = $this->plugin->settings()->get( 'alert_recipient' );
		if ( is_string( $recipient ) && is_email( $recipient ) ) {
			$candidates[] = strtolower( $recipient );
		}

		$admin = get_option( 'admin_email' );
		if ( is_string( $admin ) && is_email( $admin ) ) {
			$candidates[] = strtolower( $admin );
		}

		foreach ( array_keys( $this->optouts() ) as $email ) {
			$candidates[] = $email;
		}

		foreach ( array_unique( $candidates ) as $email ) {
			if ( hash_equals( self::token( $email ), $token ) ) {
				return $email;
			}
		}

		return '';
	}

	/**
	 * Handle a public opt-out request and render the result.
	 */
	public function serve_unsubscribe(): void {
		$result = $this->process_unsubscribe( $this->request_token(), $this->is_human_navigation(), $this->remote_ip() );
		$this->render( $result['status'], $result['title'], $result['message'] );
	}

	/**
	 * Process an opt-out request without producing output.
	 *
	 * Inputs are passed in so the logic can be exercised directly by tests,
	 * where filter_input() has no real request to read.
	 *
	 * @param string $token Candidate token.
	 * @param bool   $human Whether the request looks like a real click.
	 * @param string $ip    Connecting address for attempt throttling.
	 * @return array{status:int,title:string,message:string,email:string,changed:bool}
	 */
	public function process_unsubscribe( string $token = '', bool $human = true, string $ip = '' ): array {
		if ( ! preg_match( '/^[a-f0-9]{' . self::TOKEN_LENGTH . '}$/', $token ) ) {
			return $this->outcome( '', 400, false, __( 'This unsubscribe link is not valid.', 'mudrava-rum' ), __( 'No changes were made. Open the link exactly as it appears in the email, or ask a site administrator to change the report recipient in the plugin settings.', 'mudrava-rum' ) );
		}

		if ( $this->attempt_limit_reached( $ip ) ) {
			return $this->outcome( $ip, 429, false, __( 'Too many attempts.', 'mudrava-rum' ), __( 'This unsubscribe link is not valid. No changes were made.', 'mudrava-rum' ) );
		}

		$email = $this->resolve( $token );
		if ( '' === $email ) {
			$this->record_attempt( $ip );

			return $this->outcome( '', 400, false, __( 'This unsubscribe link is not valid.', 'mudrava-rum' ), __( 'The link does not match this site, or the plugin was reinstalled since the email was sent. No changes were made.', 'mudrava-rum' ) );
		}

		if ( ! $human ) {
			return $this->outcome( $email, 200, false, __( 'Almost there.', 'mudrava-rum' ), __( 'A mail client or a scanner opened this link automatically, so nothing was changed yet. Click the unsubscribe link once more to stop these emails for good.', 'mudrava-rum' ) );
		}

		$already = $this->is_opted_out( $email );
		if ( ! $already ) {
			$this->opt_out( $email );
		}

		return $this->outcome(
			$email,
			200,
			! $already,
			$already
				? __( 'You are already unsubscribed.', 'mudrava-rum' )
				: __( 'You have been unsubscribed.', 'mudrava-rum' ),
			$already
				? __( 'This address is not receiving Mudrava RUM reports or alerts. A site administrator can turn them back on in Mudrava RUM settings.', 'mudrava-rum' )
				: /* translators: %s: site name. */
				sprintf( __( 'This address will no longer receive Mudrava RUM reports or alerts from %s. No further emails will be sent to it.', 'mudrava-rum' ), get_bloginfo( 'name' ) )
		);
	}

	/**
	 * Connecting address, validated.
	 *
	 * Peer address only: forwarded headers are never trusted here, a spoofed
	 * header must not be able to reset the attempt counter.
	 *
	 * @return string Validated IP, empty when unavailable.
	 */
	public function remote_ip(): string {
		$ip = filter_input( INPUT_SERVER, 'REMOTE_ADDR' );
		if ( ! is_string( $ip ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Peer address, validated with filter_var() below.
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Read and normalize the token from the query string, then the POST body.
	 *
	 * The token is an HMAC signature, not a form submission, so no nonce applies
	 * here; an unsupported method override is rejected outright.
	 *
	 * @return string
	 */
	protected function request_token(): string {
		$override = filter_input( INPUT_SERVER, 'HTTP_X_HTTP_METHOD_OVERRIDE' );
		$tunnel   = filter_input( INPUT_SERVER, 'HTTP_X_HTTP_METHOD' );
		if ( is_string( $override ) && '' !== $override && 'GET' !== strtoupper( $override ) ) {
			return '';
		}
		if ( is_string( $tunnel ) && '' !== $tunnel && 'GET' !== strtoupper( $tunnel ) ) {
			return '';
		}

		$token = filter_input( INPUT_GET, 't' );
		if ( ! is_string( $token ) || '' === $token ) {
			$token = filter_input( INPUT_POST, 't' );
		}

		return is_string( $token ) ? substr( strtolower( sanitize_text_field( $token ) ), 0, 64 ) : '';
	}

	/**
	 * Whether the request looks like an automated link preview rather than a click.
	 *
	 * @return bool
	 */
	protected function is_human_navigation(): bool {
		$server = filter_input_array( INPUT_SERVER );
		if ( ! is_array( $server ) ) {
			return true;
		}

		foreach ( array( 'HTTP_X_PURPOSE', 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_MOZ' ) as $key ) {
			$value = isset( $server[ $key ] ) ? strtolower( (string) $server[ $key ] ) : '';
			if ( '' === $value ) {
				continue;
			}
			if ( false !== strpos( $value, 'preview' ) || ( false !== strpos( $value, 'prefetch' ) && false === strpos( $value, 'navigate' ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Rate key for forged-token attempts from one address.
	 *
	 * @param string $ip Connecting address.
	 * @return string Empty when the address is unusable.
	 */
	public function attempt_key( string $ip ): string {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return MDVRM_REST::rate_limit_key( 'unsub:' . $ip );
	}

	/**
	 * Whether an address already exceeded the forged-token allowance.
	 *
	 * @param string $ip Connecting address.
	 * @return bool
	 */
	public function attempt_limit_reached( string $ip ): bool {
		$key = $this->attempt_key( $ip );
		if ( '' === $key ) {
			return false;
		}

		return absint( get_transient( $key ) ) >= self::MAX_ATTEMPTS;
	}

	/**
	 * Count one forged-token attempt.
	 *
	 * @param string $ip Connecting address.
	 */
	public function record_attempt( string $ip ): void {
		$key = $this->attempt_key( $ip );
		if ( '' === $key ) {
			return;
		}

		$count = absint( get_transient( $key ) ) + 1;
		set_transient( $key, $count, self::ATTEMPT_WINDOW );
	}

	/**
	 * Shape a result array.
	 *
	 * @param string $email   Matched address, empty when none.
	 * @param int    $status  HTTP status.
	 * @param bool   $changed Whether the opt-out state moved.
	 * @param string $title   Page heading.
	 * @param string $message Page body.
	 * @return array{status:int,title:string,message:string,email:string,changed:bool}
	 */
	protected function outcome( string $email, int $status, bool $changed, string $title, string $message ): array {
		return array(
			'status'  => $status,
			'changed' => $changed,
			'title'   => $title,
			'message' => $message,
			'email'   => $email,
		);
	}

	/**
	 * Handle the admin "resume reports" control.
	 */
	public function serve_resume(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to change report recipients.', 'mudrava-rum' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::RESUME_ACTION );

		$recipient = $this->plugin->settings()->get( 'alert_recipient' );
		$recipient = is_string( $recipient ) ? $recipient : '';
		$this->opt_in( $recipient );

		wp_safe_redirect(
			add_query_arg( 'mdvrm_resubscribed', '1', admin_url( 'admin.php?page=mudrava-rum-settings' ) )
		);
		exit;
	}

	/**
	 * Render a standalone response page and stop.
	 *
	 * @param int    $status  HTTP status code.
	 * @param string $title   Heading.
	 * @param string $message Paragraph.
	 */
	protected function render( int $status, string $title, string $message ): void {
		if ( ! headers_sent() ) {
			status_header( $status );
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		}

		$site = get_bloginfo( 'name' );
		$lang = get_bloginfo( 'language' );

		echo '<!DOCTYPE html>' . "\n";
		echo '<html lang="' . esc_attr( $lang ? $lang : 'en-US' ) . '">' . "\n";
		echo '<head>' . "\n";
		echo '<meta charset="' . esc_attr( get_option( 'blog_charset' ) ) . '">' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		echo '<meta name="robots" content="noindex, nofollow">' . "\n";
		echo '<title>' . esc_html( $title ) . '</title>' . "\n";
		echo '<style>body{margin:0;padding:40px 20px;background:#f6f7f8;color:#1d2327;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
			. '.box{max-width:560px;margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:32px}'
			. 'h1{margin:0 0 12px;font-size:22px}p{margin:0 0 16px}a{color:#2271b1}</style>' . "\n";
		echo '</head>' . "\n";
		echo '<body><main class="box">' . "\n";
		echo '<h1>' . esc_html( $title ) . '</h1>' . "\n";
		echo '<p>' . esc_html( $message ) . '</p>' . "\n";
		if ( $site ) {
			echo '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( $site ) . '</a></p>' . "\n";
		}
		echo '</main></body>' . "\n";
		echo '</html>' . "\n";

		exit;
	}

	/**
	 * Drop the per-request opt-out cache. Used by integration tests.
	 */
	public function reset_cache(): void {
		$this->optout_cache = null;
	}
}
