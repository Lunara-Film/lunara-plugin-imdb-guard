<?php
/**
 * Plugin Name: Lunara IMDb Guard
 * Plugin URI: https://lunarafilm.com/
 * Description: Validates review IMDb IDs against title and year, auto-fills clear matches, syncs TMDB poster/backdrop artwork, and provides an editorial audit screen for Lunara.
 * Version: 0.4.2
 * Author: Lunara Film
 * Author URI: https://lunarafilm.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lunara-imdb-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LUNARA_IMDB_GUARD_VERSION', '0.4.2' );
define( 'LUNARA_IMDB_GUARD_FILE', __FILE__ );
define( 'LUNARA_IMDB_GUARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'LUNARA_IMDB_GUARD_DEFAULT_OMDB_API_KEY', '' );

final class Lunara_IMDb_Guard {

	const OPTION_API_KEY             = 'lunara_imdb_guard_omdb_api_key';
	const OPTION_TMDB_API_KEY        = 'lunara_imdb_guard_tmdb_api_key';
	const OPTION_TITLE_MAP           = 'lunara_imdb_guard_title_map_v1';
	const OPTION_TITLE_MAP_MIGRATION = 'lunara_imdb_guard_title_map_migration_v1';
	const OPTION_HEALTH_SNAPSHOT    = 'lunara_imdb_guard_health_snapshot_v1';
	const TITLE_MAP_SCHEMA_VERSION  = 1;
	const HEALTH_SNAPSHOT_SCHEMA_VERSION = 1;
	const LEGACY_MAP_MAX_BYTES      = 1048576;
	const LEGACY_MAP_MAX_ENTRIES    = 5000;
	const TITLE_MAP_CAS_ATTEMPTS    = 5;
	const META_STATUS    = '_lunara_imdb_guard_status';
	const META_MESSAGE   = '_lunara_imdb_guard_message';
	const META_EXPECTED  = '_lunara_imdb_guard_expected_id';
	const META_TITLE     = '_lunara_imdb_guard_expected_title';
	const META_YEAR      = '_lunara_imdb_guard_expected_year';
	const META_CHECKED   = '_lunara_imdb_guard_last_checked';
	const META_LOOKUP    = '_lunara_imdb_guard_lookup_title';
	
	// Custom fields for the TMDB image URLs
	const META_POSTER    = '_lunara_tmdb_poster_url';
	const META_BACKDROP  = '_lunara_tmdb_backdrop_url';
	const META_IMG_CHECKED = '_lunara_imdb_guard_images_checked';

	/**
	 * Singleton instance.
	 *
	 * @var Lunara_IMDb_Guard|null
	 */
	private static $instance = null;

	/**
	 * Bootstrap singleton.
	 *
	 * @return Lunara_IMDb_Guard
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_review', array( $this, 'validate_review_on_save' ), 50, 3 );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		add_action( 'admin_init', array( $this, 'maybe_migrate_title_map' ) );

		add_action( 'admin_post_lunara_imdb_guard_validate', array( $this, 'handle_validate_request' ) );
		add_action( 'admin_post_lunara_imdb_guard_apply', array( $this, 'handle_apply_request' ) );
		add_action( 'admin_post_lunara_imdb_guard_bulk_audit', array( $this, 'handle_bulk_audit_request' ) );
		add_action( 'admin_post_lunara_imdb_guard_fill_images', array( $this, 'handle_fill_images_request' ) );
		add_action( 'admin_post_lunara_imdb_guard_save_settings', array( $this, 'handle_save_settings_request' ) );
		add_action( 'admin_post_lunara_imdb_guard_export_manifest', array( $this, 'handle_export_manifest_request' ) );
	}

	/**
	 * Run versioned storage migrations during activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::instance()->maybe_migrate_title_map();
	}

	/**
	 * Return a sanitized canonical title map.
	 *
	 * NULL means canonical storage is absent or invalid. An empty array is a
	 * valid, intentionally empty map.
	 *
	 * @return array|null
	 */
	public function get_title_map(): ?array {
		$sentinel = self::option_sentinel();
		$stored   = get_option( self::OPTION_TITLE_MAP, $sentinel );

		if ( $sentinel === $stored ) {
			return null;
		}

		$normalized = $this->normalize_title_map( $stored );
		if ( null === $normalized || $stored !== $normalized ) {
			return null;
		}

		return $normalized;
	}

	/**
	 * Look up a canonical IMDb ID by title and year.
	 *
	 * @param mixed $title Film title.
	 * @param mixed $year  Four-digit release year.
	 * @return string
	 */
	public function lookup_title_id( $title, $year = '' ): string {
		if ( ! is_scalar( $title ) || ! is_scalar( $year ) ) {
			return '';
		}

		$key = $this->build_map_key( (string) $title, (string) $year );
		$map = $this->get_title_map();

		return '' !== $key && is_array( $map ) && isset( $map[ $key ] ) ? $map[ $key ] : '';
	}

	/**
	 * Return the canonical plugin-owned admin destination.
	 *
	 * @return string
	 */
	public function admin_url(): string {
		return admin_url( 'edit.php?post_type=review&page=lunara-imdb-guard' );
	}

	/**
	 * Return the capability that owns IMDb Guard operations.
	 *
	 * @return string
	 */
	public function admin_capability(): string {
		return 'edit_posts';
	}

	/**
	 * Import the active theme's bounded legacy title map exactly once.
	 *
	 * This method is hooked only to activation/admin upgrade traffic. Ordinary
	 * map reads and Review mutations never call it or inspect the filesystem.
	 *
	 * @return bool
	 */
	public function maybe_migrate_title_map(): bool {
		$sentinel = self::option_sentinel();
		$stored   = get_option( self::OPTION_TITLE_MAP, $sentinel );

		if ( $sentinel !== $stored ) {
			$normalized = $this->normalize_title_map( $stored );
			if ( null === $normalized || $stored !== $normalized ) {
				return false;
			}

			return $this->mark_title_map_migration_complete();
		}

		$legacy = $this->read_legacy_title_map();
		if ( null === $legacy ) {
			return false;
		}

		$created = add_option( self::OPTION_TITLE_MAP, $legacy, '', false );
		$readback = get_option( self::OPTION_TITLE_MAP, $sentinel );

		if ( $created ) {
			if ( $readback !== $legacy ) {
				return false;
			}
		} else {
			$winner = $this->normalize_title_map( $readback );
			if ( null === $winner || $readback !== $winner ) {
				return false;
			}
		}

		return $this->mark_title_map_migration_complete();
	}

	/**
	 * Return bounded identity/artwork health without scanning Review records.
	 *
	 * @return array
	 */
	public function health_status(): array {
		$snapshot = $this->read_health_snapshot();
		$identity = array(
			'state'           => 'unknown',
			'total'           => null,
			'with_imdb_id'    => null,
			'missing_imdb_id' => null,
			'needs_review'    => null,
		);
		$artwork = array(
			'state'    => 'unknown',
			'eligible' => null,
			'complete' => null,
			'missing'  => null,
		);

		if ( is_array( $snapshot ) ) {
			$identity = $snapshot['identity'];
			$identity['state'] = 0 === $identity['missing_imdb_id'] && 0 === $identity['needs_review'] ? 'healthy' : 'needs_attention';
			$identity = array_merge( array( 'state' => $identity['state'] ), array_diff_key( $identity, array( 'state' => true ) ) );

			$artwork = $snapshot['artwork'];
			$artwork['state'] = 0 === $artwork['missing'] ? 'healthy' : 'needs_attention';
			$artwork = array_merge( array( 'state' => $artwork['state'] ), array_diff_key( $artwork, array( 'state' => true ) ) );
		}

		$sentinel  = self::option_sentinel();
		$stored_map = get_option( self::OPTION_TITLE_MAP, $sentinel );
		if ( $sentinel === $stored_map ) {
			$title_map = array( 'state' => 'missing', 'entries' => null );
		} else {
			$normalized_map = $this->normalize_title_map( $stored_map );
			$title_map = null !== $normalized_map && $stored_map === $normalized_map
				? array( 'state' => 'ready', 'entries' => count( $normalized_map ) )
				: array( 'state' => 'invalid', 'entries' => null );
		}

		$credentials = array(
			'omdb_configured' => '' !== $this->get_api_key(),
			'tmdb_configured' => '' !== $this->get_tmdb_api_key(),
		);
		$healthy = 'healthy' === $identity['state']
			&& 'healthy' === $artwork['state']
			&& 'ready' === $title_map['state']
			&& $credentials['omdb_configured']
			&& $credentials['tmdb_configured'];

		return array(
			'state'        => $healthy ? 'healthy' : 'needs_attention',
			'identity'     => $identity,
			'artwork'      => $artwork,
			'title_map'    => $title_map,
			'credentials'  => $credentials,
		);
	}

	/**
	 * Refresh the compact last-known health projection on an owner action.
	 *
	 * @return bool
	 */
	public function refresh_health_snapshot(): bool {
		$ids = get_posts(
			array(
				'post_type'              => 'review',
				'post_status'            => 'publish',
				'posts_per_page'         => 1000,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$ids = is_array( $ids ) ? array_slice( $ids, 0, 1000 ) : array();
		$identity = array(
			'total'           => 0,
			'with_imdb_id'    => 0,
			'missing_imdb_id' => 0,
			'needs_review'    => 0,
		);
		$artwork = array(
			'eligible' => 0,
			'complete' => 0,
			'missing'  => 0,
		);

		foreach ( $ids as $post_id ) {
			$post_id = (int) $post_id;
			$identity['total']++;
			$imdb_id = $this->normalize_imdb_id( get_post_meta( $post_id, '_lunara_imdb_title_id', true ) );
			$status  = trim( (string) get_post_meta( $post_id, self::META_STATUS, true ) );
			if ( '' === $imdb_id ) {
				$identity['missing_imdb_id']++;
			} else {
				$identity['with_imdb_id']++;
				$artwork['eligible']++;
				$has_poster   = '' !== trim( (string) get_post_meta( $post_id, self::META_POSTER, true ) );
				$has_backdrop = '' !== trim( (string) get_post_meta( $post_id, self::META_BACKDROP, true ) );
				if ( $has_poster && $has_backdrop ) {
					$artwork['complete']++;
				} else {
					$artwork['missing']++;
				}
			}

			if ( ! in_array( $status, array( 'verified', 'autofilled' ), true ) ) {
				$identity['needs_review']++;
			}
		}

		$snapshot = array(
			'schema_version' => self::HEALTH_SNAPSHOT_SCHEMA_VERSION,
			'identity'       => $identity,
			'artwork'        => $artwork,
		);
		$sentinel = self::option_sentinel();
		$stored   = get_option( self::OPTION_HEALTH_SNAPSHOT, $sentinel );
		if ( $sentinel === $stored ) {
			add_option( self::OPTION_HEALTH_SNAPSHOT, $snapshot, '', false );
		} else {
			update_option( self::OPTION_HEALTH_SNAPSHOT, $snapshot, false );
		}

		return get_option( self::OPTION_HEALTH_SNAPSHOT, $sentinel ) === $snapshot;
	}

	/**
	 * Register the review-side meta box.
	 */
	public function register_meta_box() {
		add_meta_box(
			'lunara_imdb_guard',
			__( 'Lunara IMDb Guard', 'lunara-imdb-guard' ),
			array( $this, 'render_meta_box' ),
			'review',
			'side',
			'high'
		);
	}

	/**
	 * Add the audit page under Reviews.
	 */
	public function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=review',
			__( 'IMDb Guard', 'lunara-imdb-guard' ),
			__( 'IMDb Guard', 'lunara-imdb-guard' ),
			'edit_posts',
			'lunara-imdb-guard',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Save-time validator and missing-ID autofill.
	 *
	 * @param int      $post_id Review post id.
	 * @param WP_Post  $post    Current post.
	 * @param bool     $update  Whether this is an update.
	 * @return void
	 */
	public function validate_review_on_save( $post_id, $post, $update ) {
		if ( ! $post instanceof WP_Post || 'review' !== $post->post_type ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$this->save_lookup_override( $post_id );
		$this->validate_review( $post_id, true );
	}

	/**
	 * Render the editor meta box.
	 *
	 * @param WP_Post $post Current review post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$post_id        = (int) $post->ID;
		$year           = trim( (string) get_post_meta( $post_id, '_lunara_year', true ) );
		$current_id     = $this->normalize_imdb_id( get_post_meta( $post_id, '_lunara_imdb_title_id', true ) );
		$state          = $this->get_stored_state( $post_id );
		$title_map      = $this->get_title_map();
		$map_state      = is_array( $title_map ) ? __( 'Ready', 'lunara-imdb-guard' ) : __( 'Migration pending', 'lunara-imdb-guard' );
		$lookup_context = $this->resolve_lookup_context( $post_id );
		$lookup_source  = $this->format_lookup_source_label( $lookup_context['source'] );
		
		$poster_url     = get_post_meta( $post_id, self::META_POSTER, true );
		$backdrop_url   = get_post_meta( $post_id, self::META_BACKDROP, true );

		$validate_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'lunara_imdb_guard_validate',
					'post_id' => $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			'lunara_imdb_guard_validate_' . $post_id
		);

		$apply_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'lunara_imdb_guard_apply',
					'post_id' => $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			'lunara_imdb_guard_apply_' . $post_id
		);
		?>
		<style>
			.lunara-imdb-guard-meta p { margin: 0 0 10px; }
			.lunara-imdb-guard-meta label { display:block; font-weight:600; margin-bottom:4px; }
			.lunara-imdb-guard-meta code { word-break: break-word; }
			.lunara-imdb-guard-status { display:inline-block; padding:4px 8px; border-radius:999px; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
			.lunara-imdb-guard-status.is-verified,
			.lunara-imdb-guard-status.is-autofilled { background:#e8f7ee; color:#17653a; }
			.lunara-imdb-guard-status.is-missing { background:#edf1f5; color:#34495e; }
			.lunara-imdb-guard-status.is-mismatch { background:#fff0d8; color:#8a4d00; }
			.lunara-imdb-guard-status.is-no_match,
			.lunara-imdb-guard-status.is-error { background:#fce8e6; color:#8a1f17; }
			.lunara-imdb-guard-status.is-incomplete { background:#edf1f5; color:#34495e; }
			.lunara-imdb-guard-actions { display:grid; gap:8px; margin-top:12px; }
			.lunara-imdb-guard-actions .button { width:100%; text-align:center; }
			.lunara-imdb-guard-help { font-size:12px; color:#5b6670; line-height:1.5; }
			.lunara-imdb-guard-meta input[type="text"] { width:100%; }
			.lunara-imdb-guard-media-preview { margin-top: 10px; font-size: 11px; color: #646970; }
		</style>
		<div class="lunara-imdb-guard-meta">
			<?php wp_nonce_field( 'lunara_imdb_guard_meta_box', 'lunara_imdb_guard_nonce' ); ?>
			<p><strong><?php esc_html_e( 'Review Title', 'lunara-imdb-guard' ); ?></strong><br><?php echo esc_html( get_the_title( $post_id ) ); ?></p>
			<p><strong><?php esc_html_e( 'Review Year', 'lunara-imdb-guard' ); ?></strong><br><?php echo '' !== $year ? esc_html( $year ) : '<em>' . esc_html__( 'Missing year meta', 'lunara-imdb-guard' ) . '</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			<p><strong><?php esc_html_e( 'Current IMDb ID', 'lunara-imdb-guard' ); ?></strong><br><?php echo '' !== $current_id ? '<code>' . esc_html( $current_id ) . '</code>' : '<em>' . esc_html__( 'Missing', 'lunara-imdb-guard' ) . '</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			<p><strong><?php esc_html_e( 'Lookup Title Used', 'lunara-imdb-guard' ); ?></strong><br><?php echo '' !== $lookup_context['title'] ? esc_html( $lookup_context['title'] ) : '<em>' . esc_html__( 'Unavailable', 'lunara-imdb-guard' ) . '</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><br><span class="lunara-imdb-guard-help"><?php echo esc_html( $lookup_source ); ?></span></p>
			<p>
				<label for="lunara_imdb_guard_lookup_title"><?php esc_html_e( 'Lookup Title Override', 'lunara-imdb-guard' ); ?></label>
				<input type="text" id="lunara_imdb_guard_lookup_title" name="lunara_imdb_guard_lookup_title" value="<?php echo esc_attr( get_post_meta( $post_id, self::META_LOOKUP, true ) ); ?>" placeholder="<?php esc_attr_e( 'Optional manual title for OMDb validation', 'lunara-imdb-guard' ); ?>">
				<span class="lunara-imdb-guard-help"><?php esc_html_e( 'Use this only when the review post title differs from the actual film title you want matched.', 'lunara-imdb-guard' ); ?></span>
			</p>
			<p>
				<strong><?php esc_html_e( 'Status', 'lunara-imdb-guard' ); ?></strong><br>
				<span class="lunara-imdb-guard-status is-<?php echo esc_attr( $state['status'] ); ?>">
					<?php echo esc_html( $this->format_status_label( $state['status'] ) ); ?>
				</span>
			</p>
			<?php if ( '' !== $state['message'] ) : ?>
				<p class="lunara-imdb-guard-help"><?php echo esc_html( $state['message'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $state['expected_id'] ) : ?>
				<p><strong><?php esc_html_e( 'Suggested IMDb ID', 'lunara-imdb-guard' ); ?></strong><br><code><?php echo esc_html( $state['expected_id'] ); ?></code></p>
			<?php endif; ?>
			<?php if ( '' !== $state['expected_title'] ) : ?>
				<p><strong><?php esc_html_e( 'Matched OMDb Title', 'lunara-imdb-guard' ); ?></strong><br><?php echo esc_html( $state['expected_title'] ); ?><?php echo '' !== $state['expected_year'] ? ' (' . esc_html( $state['expected_year'] ) . ')' : ''; ?></p>
			<?php endif; ?>
			
			<?php if ( '' !== $poster_url || '' !== $backdrop_url ) : ?>
				<div class="lunara-imdb-guard-media-preview">
					<strong><?php esc_html_e( 'TMDB Assets Synced:', 'lunara-imdb-guard' ); ?></strong><br>
					<?php if ( '' !== $poster_url ) : ?>
						&#10003; <a href="<?php echo esc_url( $poster_url ); ?>" target="_blank"><?php esc_html_e( 'Poster Image', 'lunara-imdb-guard' ); ?></a><br>
					<?php endif; ?>
					<?php if ( '' !== $backdrop_url ) : ?>
						&#10003; <a href="<?php echo esc_url( $backdrop_url ); ?>" target="_blank"><?php esc_html_e( 'Backdrop Image', 'lunara-imdb-guard' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<p style="margin-top:10px;"><strong><?php esc_html_e( 'Canonical Title Map', 'lunara-imdb-guard' ); ?></strong><br><?php echo esc_html( $map_state ); ?></p>

			<div class="lunara-imdb-guard-actions">
				<a class="button" href="<?php echo esc_url( $validate_url ); ?>"><?php esc_html_e( 'Validate IMDb ID', 'lunara-imdb-guard' ); ?></a>
				<?php if ( '' !== $state['expected_id'] && $state['expected_id'] !== $current_id ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $apply_url ); ?>"><?php esc_html_e( 'Apply Suggested IMDb ID', 'lunara-imdb-guard' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the admin audit page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'lunara-imdb-guard' ) );
		}

		$omdb_configured = '' !== $this->get_api_key();
		$tmdb_configured = '' !== $this->get_tmdb_api_key();
		$reviews       = get_posts(
			array(
				'post_type'              => 'review',
				'post_status'            => 'publish',
				'posts_per_page'         => 150,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$bulk_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'lunara_imdb_guard_bulk_audit',
				),
				admin_url( 'admin-post.php' )
			),
			'lunara_imdb_guard_bulk_audit'
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Lunara IMDb Guard', 'lunara-imdb-guard' ); ?></h1>
			<p><?php esc_html_e( 'This plugin validates review title/year data against OMDb, auto-fills clear missing IMDb IDs, syncs high-resolution assets from TMDB, and keeps an editorial audit trail inside WordPress.', 'lunara-imdb-guard' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:18px 0 28px;">
				<input type="hidden" name="action" value="lunara_imdb_guard_save_settings">
				<?php wp_nonce_field( 'lunara_imdb_guard_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lunara_imdb_guard_omdb_api_key"><?php esc_html_e( 'OMDb API Key', 'lunara-imdb-guard' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="lunara_imdb_guard_omdb_api_key" name="lunara_imdb_guard_omdb_api_key" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $omdb_configured ? __( 'Saved — paste a new key to replace', 'lunara-imdb-guard' ) : __( 'Paste OMDb API key', 'lunara-imdb-guard' ) ); ?>">
							<p class="description"><?php echo esc_html( $omdb_configured ? __( 'Status: Configured. The saved key is write-only and never displayed — leave blank to keep it, or paste a new key to replace it.', 'lunara-imdb-guard' ) : __( 'Not configured. Used for title/year to IMDb ID validation.', 'lunara-imdb-guard' ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lunara_imdb_guard_tmdb_api_key"><?php esc_html_e( 'TMDB API Key', 'lunara-imdb-guard' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="lunara_imdb_guard_tmdb_api_key" name="lunara_imdb_guard_tmdb_api_key" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $tmdb_configured ? __( 'Saved — paste a new key to replace', 'lunara-imdb-guard' ) : __( 'Paste TMDB API key (optional)', 'lunara-imdb-guard' ) ); ?>">
							<p class="description"><?php echo esc_html( $tmdb_configured ? __( 'Status: Configured (write-only). Leave blank to keep it. Syncs poster/backdrop artwork from TMDB.', 'lunara-imdb-guard' ) : __( 'Not configured. Leave blank to reuse the Oscars Ledger TMDB key when that plugin is active.', 'lunara-imdb-guard' ) ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'lunara-imdb-guard' ) ); ?>
			</form>

			<p><a class="button button-primary" href="<?php echo esc_url( $bulk_url ); ?>"><?php esc_html_e( 'Run Bulk Audit', 'lunara-imdb-guard' ); ?></a></p>

			<?php
			$image_stats = $this->count_reviews_missing_images();
			$fill_url    = wp_nonce_url(
				add_query_arg( array( 'action' => 'lunara_imdb_guard_fill_images' ), admin_url( 'admin-post.php' ) ),
				'lunara_imdb_guard_fill_images'
			);
			?>
			<div style="margin:14px 0 26px;padding:16px 18px;border:1px solid #dcdcde;border-left:4px solid #c9a961;border-radius:6px;background:#fff;max-width:780px;">
				<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Poster & backdrop completeness', 'lunara-imdb-guard' ); ?></h2>
				<p style="margin:0 0 8px;">
					<?php if ( $tmdb_configured ) : ?>
						<span style="color:#17653a;font-weight:600;">&#10003; <?php esc_html_e( 'TMDB connected', 'lunara-imdb-guard' ); ?></span>
					<?php else : ?>
						<span style="color:#b32d2e;font-weight:600;">&#10007; <?php esc_html_e( 'TMDB not configured — artwork cannot sync. Add a TMDB key above, or activate the Oscars Ledger plugin to share its key.', 'lunara-imdb-guard' ); ?></span>
					<?php endif; ?>
				</p>
				<p style="margin:0 0 12px;">
					<?php
					printf(
						/* translators: 1: reviews missing art that have an IMDb ID, 2: reviews with no IMDb ID */
						esc_html__( '%1$d reviews with an IMDb ID are missing a poster or backdrop and can be filled from TMDB. %2$d reviews have no IMDb ID yet — validate those first.', 'lunara-imdb-guard' ),
						(int) $image_stats['missing'],
						(int) $image_stats['no_id']
					);
					?>
				</p>
				<?php if ( $tmdb_configured && $image_stats['missing'] > 0 ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $fill_url ); ?>"><?php esc_html_e( 'Fill Missing Posters', 'lunara-imdb-guard' ); ?></a>
					<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Fills up to 20 per click; re-click to continue a large library.', 'lunara-imdb-guard' ); ?></span>
				<?php elseif ( $tmdb_configured ) : ?>
					<p style="margin:0;color:#17653a;font-weight:600;">&#10003; <?php esc_html_e( 'Every review with an IMDb ID already has artwork.', 'lunara-imdb-guard' ); ?></p>
				<?php endif; ?>

				<?php
				$manifest_url = function ( $manifest_source, $manifest_scope ) {
					return wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'lunara_imdb_guard_export_manifest',
								'source' => $manifest_source,
								'scope'  => $manifest_scope,
							),
							admin_url( 'admin-post.php' )
						),
						'lunara_imdb_guard_export_manifest'
					);
				};
				global $wpdb;
				$oscars_entities_table = $wpdb->prefix . 'aat_entities';
				$oscars_available      = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $oscars_entities_table ) ) === $oscars_entities_table );
				?>
				<hr style="margin:16px 0;">
				<p style="margin:0 0 8px;">
					<strong><?php esc_html_e( 'Local batch import (poster manifests)', 'lunara-imdb-guard' ); ?></strong><br>
					<?php esc_html_e( 'Each line carries the film\'s IMDb ID and its title — save as titles.txt beside tools/local/local-poster-preflight.ps1 and run the launcher. Downloaded posters are named "tt-id - Title.jpg" so the importers map them automatically.', 'lunara-imdb-guard' ); ?>
				</p>
				<p style="margin:0;">
					<a class="button" href="<?php echo esc_url( $manifest_url( 'reviews', 'missing' ) ); ?>"><?php esc_html_e( 'Reviews — missing posters', 'lunara-imdb-guard' ); ?></a>
					<a class="button" href="<?php echo esc_url( $manifest_url( 'reviews', 'all' ) ); ?>"><?php esc_html_e( 'Reviews — all', 'lunara-imdb-guard' ); ?></a>
					<?php if ( $oscars_available ) : ?>
						<a class="button" href="<?php echo esc_url( $manifest_url( 'oscars', 'missing' ) ); ?>"><?php esc_html_e( 'Oscars films — missing posters', 'lunara-imdb-guard' ); ?></a>
						<a class="button" href="<?php echo esc_url( $manifest_url( 'oscars', 'all' ) ); ?>"><?php esc_html_e( 'Oscars films — all', 'lunara-imdb-guard' ); ?></a>
					<?php endif; ?>
				</p>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Review', 'lunara-imdb-guard' ); ?></th>
						<th><?php esc_html_e( 'Year', 'lunara-imdb-guard' ); ?></th>
						<th><?php esc_html_e( 'Current IMDb ID', 'lunara-imdb-guard' ); ?></th>
						<th><?php esc_html_e( 'Status', 'lunara-imdb-guard' ); ?></th>
						<th><?php esc_html_e( 'Suggested ID', 'lunara-imdb-guard' ); ?></th>
						<th><?php esc_html_e( 'TMDB Media', 'lunara-imdb-guard' ); ?></th>
						<th><?php esc_html_e( 'Last Checked', 'lunara-imdb-guard' ); ?></th>
						<th><?php esc_html_e( 'Action', 'lunara-imdb-guard' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $reviews ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No published reviews found.', 'lunara-imdb-guard' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $reviews as $review_id ) : ?>
							<?php
							$state        = $this->get_stored_state( $review_id );
							$current_id   = $this->normalize_imdb_id( get_post_meta( $review_id, '_lunara_imdb_title_id', true ) );
							$year         = trim( (string) get_post_meta( $review_id, '_lunara_year', true ) );
							$has_poster   = '' !== get_post_meta( $review_id, self::META_POSTER, true );
							$has_backdrop = '' !== get_post_meta( $review_id, self::META_BACKDROP, true );
							
							$validate_id = wp_nonce_url(
								add_query_arg(
									array(
										'action'  => 'lunara_imdb_guard_validate',
										'post_id' => $review_id,
									),
									admin_url( 'admin-post.php' )
								),
								'lunara_imdb_guard_validate_' . $review_id
							);
							$apply_id = wp_nonce_url(
								add_query_arg(
									array(
										'action'  => 'lunara_imdb_guard_apply',
										'post_id' => $review_id,
									),
									admin_url( 'admin-post.php' )
								),
								'lunara_imdb_guard_apply_' . $review_id
							);
							?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_post_link( $review_id ) ); ?>"><?php echo esc_html( get_the_title( $review_id ) ); ?></a></td>
								<td><?php echo esc_html( $year ); ?></td>
								<td><?php echo '' !== $current_id ? '<code>' . esc_html( $current_id ) . '</code>' : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td><?php echo esc_html( $this->format_status_label( $state['status'] ) ); ?></td>
								<td><?php echo '' !== $state['expected_id'] ? '<code>' . esc_html( $state['expected_id'] ) . '</code>' : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td>
									<?php 
									if ( $has_poster || $has_backdrop ) {
										echo '<span style="color:#17653a;">&#10003; ' . esc_html__( 'Synced', 'lunara-imdb-guard' ) . '</span>';
									} else {
										echo '<span style="color:#646970;">&mdash;</span>';
									}
									?>
								</td>
								<td><?php echo '' !== $state['checked_at'] ? esc_html( $state['checked_at'] ) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td>
									<a class="button" href="<?php echo esc_url( $validate_id ); ?>"><?php esc_html_e( 'Validate', 'lunara-imdb-guard' ); ?></a>
									<?php if ( '' !== $state['expected_id'] && $state['expected_id'] !== $current_id ) : ?>
										<a class="button button-primary" href="<?php echo esc_url( $apply_id ); ?>" style="margin-top:6px;"><?php esc_html_e( 'Apply Match', 'lunara-imdb-guard' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle one-off validation.
	 *
	 * @return void
	 */
	public function handle_validate_request() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		check_admin_referer( 'lunara_imdb_guard_validate_' . $post_id );

		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to validate this review.', 'lunara-imdb-guard' ) );
		}

		$this->validate_review( $post_id, false );

		$this->redirect_back(
			$post_id,
			array(
				'lunara_imdb_guard_notice' => 'validated',
			)
		);
	}

	/**
	 * Apply the suggested IMDb id.
	 *
	 * @return void
	 */
	public function handle_apply_request() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		check_admin_referer( 'lunara_imdb_guard_apply_' . $post_id );

		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this review.', 'lunara-imdb-guard' ) );
		}

		$state = $this->get_stored_state( $post_id );
		if ( '' !== $state['expected_id'] ) {
			update_post_meta( $post_id, '_lunara_imdb_title_id', $state['expected_id'] );
			
			// Dynamic asset lookup following manual alignment application
			$this->sync_lunara_tmdb_images( $post_id, $state['expected_id'] );
		}

		$this->validate_review( $post_id, false );

		$this->redirect_back(
			$post_id,
			array(
				'lunara_imdb_guard_notice' => 'applied',
			)
		);
	}

	/**
	 * Run the audit across published reviews.
	 *
	 * @return void
	 */
	public function handle_bulk_audit_request() {
		check_admin_referer( 'lunara_imdb_guard_bulk_audit' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to run the audit.', 'lunara-imdb-guard' ) );
		}

		$review_ids = get_posts(
			array(
				'post_type'              => 'review',
				'post_status'            => 'publish',
				'posts_per_page'         => 150,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$count = 0;
		foreach ( $review_ids as $review_id ) {
			$this->validate_review( $review_id, true );
			$count++;
		}
		$this->refresh_health_snapshot();

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'                => 'review',
					'page'                     => 'lunara-imdb-guard',
					'lunara_imdb_guard_notice' => 'bulk',
					'audited'                  => $count,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Download a poster manifest for the local TMDB batch tools.
	 *
	 * Every line carries the film's IMDb ID AND its title — the exact format
	 * tools/local/local-poster-preflight.ps1 reads — so the local batch run
	 * can verify each poster against the title, and every downloaded file is
	 * named with both. Sources: the review library, or (when the Oscars
	 * Ledger plugin's tables exist) the full Oscars title catalogue.
	 */
	public function handle_export_manifest_request() {
		check_admin_referer( 'lunara_imdb_guard_export_manifest' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this.', 'lunara-imdb-guard' ) );
		}

		$source = isset( $_GET['source'] ) && 'oscars' === $_GET['source'] ? 'oscars' : 'reviews';
		$scope  = isset( $_GET['scope'] ) && 'all' === $_GET['scope'] ? 'all' : 'missing';
		$lines  = array();

		if ( 'reviews' === $source ) {
			$review_ids = get_posts(
				array(
					'post_type'              => 'review',
					'post_status'            => 'publish',
					'posts_per_page'         => -1,
					'orderby'                => 'title',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $review_ids as $review_id ) {
				$imdb_id = $this->normalize_imdb_id( get_post_meta( $review_id, '_lunara_imdb_title_id', true ) );
				if ( '' === $imdb_id ) {
					continue;
				}
				if ( 'missing' === $scope && '' !== trim( (string) get_post_meta( $review_id, self::META_POSTER, true ) ) ) {
					continue;
				}
				$title = trim( wp_strip_all_tags( (string) get_the_title( $review_id ) ) );
				$year  = trim( (string) get_post_meta( $review_id, '_lunara_year', true ) );
				if ( '' !== $year && false === strpos( $title, '(' . $year . ')' ) ) {
					$title .= ' (' . $year . ')';
				}
				$lines[] = $imdb_id . ' | ' . $title;
			}
		} else {
			global $wpdb;
			$entities_table = $wpdb->prefix . 'aat_entities';
			$posters_table  = $wpdb->prefix . 'aat_posters';

			$has_entities = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $entities_table ) ) === $entities_table );
			if ( ! $has_entities ) {
				wp_die( esc_html__( 'The Oscars Ledger tables are not available on this site.', 'lunara-imdb-guard' ) );
			}
			$has_posters = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $posters_table ) ) === $posters_table );

			if ( 'missing' === $scope && $has_posters ) {
				$rows = $wpdb->get_results(
					"SELECT e.entity_id, e.label FROM $entities_table e
					 LEFT JOIN $posters_table p ON p.imdb_id = e.entity_id AND p.attachment_id > 0
					 WHERE e.entity_type = 'title' AND e.entity_id LIKE 'tt%' AND p.imdb_id IS NULL
					 ORDER BY e.sort_label ASC",
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					"SELECT e.entity_id, e.label FROM $entities_table e
					 WHERE e.entity_type = 'title' AND e.entity_id LIKE 'tt%'
					 ORDER BY e.sort_label ASC",
					ARRAY_A
				);
			}

			foreach ( (array) $rows as $row ) {
				$label   = trim( (string) $row['label'] );
				$lines[] = strtolower( trim( (string) $row['entity_id'] ) ) . ( '' !== $label ? ' | ' . $label : '' );
			}
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="titles-' . $source . '-' . $scope . '-' . gmdate( 'Ymd' ) . '.txt"' );

		echo '# Lunara poster manifest — source: ' . $source . ', scope: ' . $scope . ', ' . count( $lines ) . " films\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '# Feed to tools/local/local-poster-preflight.ps1 (save as titles.txt beside it).' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo implode( "\n", $lines ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Batch-fill missing TMDB posters/backdrops across reviews.
	 *
	 * Non-destructive: only sets a poster/backdrop when it is currently empty,
	 * so it never clobbers art a review already has. Processes a bounded batch
	 * per click and reports how many remain, so a large library is handled by
	 * re-clicking (and already-filled reviews are skipped on subsequent runs).
	 *
	 * @return void
	 */
	public function handle_fill_images_request() {
		check_admin_referer( 'lunara_imdb_guard_fill_images' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this.', 'lunara-imdb-guard' ) );
		}

		if ( '' === $this->get_tmdb_api_key() ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'post_type'                => 'review',
						'page'                     => 'lunara-imdb-guard',
						'lunara_imdb_guard_notice' => 'fill_no_tmdb',
					),
					admin_url( 'edit.php' )
				)
			);
			exit;
		}

		$batch_cap = 20; // reviews synced per click (each = 2 TMDB calls).

		$review_ids = get_posts(
			array(
				'post_type'              => 'review',
				'post_status'            => 'publish',
				'posts_per_page'         => 1000,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$filled_posters   = 0;
		$filled_backdrops = 0;
		$remaining        = 0;
		$processed        = 0;

		foreach ( $review_ids as $review_id ) {
			$has_poster   = '' !== trim( (string) get_post_meta( $review_id, self::META_POSTER, true ) );
			$has_backdrop = '' !== trim( (string) get_post_meta( $review_id, self::META_BACKDROP, true ) );
			if ( $has_poster && $has_backdrop ) {
				continue;
			}

			$imdb_id = $this->normalize_imdb_id( get_post_meta( $review_id, '_lunara_imdb_title_id', true ) );
			if ( '' === $imdb_id ) {
				continue; // No ID to look up — surfaced separately in the audit table.
			}

			if ( '' !== get_post_meta( $review_id, self::META_IMG_CHECKED, true ) ) {
				continue; // Already attempted with a definitive TMDB response.
			}

			if ( $processed >= $batch_cap ) {
				$remaining++;
				continue;
			}

			$did = $this->fill_missing_images( $review_id, $imdb_id );
			$processed++;
			if ( ! empty( $did['poster'] ) ) {
				$filled_posters++;
			}
			if ( ! empty( $did['backdrop'] ) ) {
				$filled_backdrops++;
			}
		}
		$this->refresh_health_snapshot();

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'                => 'review',
					'page'                     => 'lunara-imdb-guard',
					'lunara_imdb_guard_notice' => 'filled',
					'fp'                       => $filled_posters,
					'fb'                       => $filled_backdrops,
					'remaining'                => $remaining,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Persist the API key.
	 *
	 * @return void
	 */
	public function handle_save_settings_request() {
		check_admin_referer( 'lunara_imdb_guard_save_settings' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'lunara-imdb-guard' ) );
		}

		// Write-only key fields: only overwrite a stored key when a non-empty
		// value is submitted, so the premium OMDb key is never echoed back into
		// the page HTML and a blank submit preserves the existing key.
		$submitted_omdb = isset( $_POST['lunara_imdb_guard_omdb_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['lunara_imdb_guard_omdb_api_key'] ) ) : '';
		if ( '' !== $submitted_omdb ) {
			update_option( self::OPTION_API_KEY, $submitted_omdb );
		}

		$submitted_tmdb = isset( $_POST['lunara_imdb_guard_tmdb_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['lunara_imdb_guard_tmdb_api_key'] ) ) : '';
		if ( '' !== $submitted_tmdb ) {
			update_option( self::OPTION_TMDB_API_KEY, $submitted_tmdb );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'                => 'review',
					'page'                     => 'lunara-imdb-guard',
					'lunara_imdb_guard_notice' => 'settings_saved',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Render contextual admin notices after guard actions.
	 *
	 * @return void
	 */
	public function render_admin_notices() {
		if ( ! is_admin() ) {
			return;
		}

		$notice = isset( $_GET['lunara_imdb_guard_notice'] ) ? sanitize_key( wp_unslash( $_GET['lunara_imdb_guard_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'review' !== $screen->post_type ) {
			return;
		}

		$message = '';
		$class   = 'notice notice-success is-dismissible';

		switch ( $notice ) {
			case 'validated':
				$message = __( 'IMDb validation completed for this review.', 'lunara-imdb-guard' );
				break;

			case 'applied':
				$message = __( 'Suggested IMDb ID applied and the review was revalidated.', 'lunara-imdb-guard' );
				break;

			case 'bulk':
				$audited = isset( $_GET['audited'] ) ? absint( $_GET['audited'] ) : 0;
				$message = sprintf(
					/* translators: %d: number of reviews audited */
					_n( 'Bulk audit finished. %d review was checked.', 'Bulk audit finished. %d reviews were checked.', $audited, 'lunara-imdb-guard' ),
					$audited
				);
				break;

			case 'settings_saved':
				$message = __( 'IMDb Guard settings saved.', 'lunara-imdb-guard' );
				break;

			case 'filled':
				$fp  = isset( $_GET['fp'] ) ? absint( $_GET['fp'] ) : 0;
				$fb  = isset( $_GET['fb'] ) ? absint( $_GET['fb'] ) : 0;
				$rem = isset( $_GET['remaining'] ) ? absint( $_GET['remaining'] ) : 0;
				$message = sprintf(
					/* translators: 1: posters filled, 2: backdrops filled */
					__( 'Filled %1$d posters and %2$d backdrops from TMDB.', 'lunara-imdb-guard' ),
					$fp,
					$fb
				);
				if ( $rem > 0 ) {
					$message .= ' ' . sprintf(
						/* translators: %d: reviews still missing artwork */
						__( '%d reviews still need artwork — click "Fill Missing Posters" again to continue.', 'lunara-imdb-guard' ),
						$rem
					);
				}
				break;

			case 'fill_no_tmdb':
				$class   = 'notice notice-error is-dismissible';
				$message = __( 'TMDB is not configured, so no artwork could be synced. Add a TMDB API key first.', 'lunara-imdb-guard' );
				break;
		}

		if ( '' === $message ) {
			return;
		}
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Validate one review and optionally auto-fill a missing id.
	 *
	 * @param int  $post_id           Review post id.
	 * @param bool $auto_fill_missing Whether to fill missing ids automatically.
	 * @return array
	 */
	private function validate_review( $post_id, $auto_fill_missing = true ) {
		$post_id        = absint( $post_id );
		$lookup_context = $this->resolve_lookup_context( $post_id );
		$title          = $lookup_context['title'];
		$year           = trim( (string) get_post_meta( $post_id, '_lunara_year', true ) );
		$current_id     = $this->normalize_imdb_id( get_post_meta( $post_id, '_lunara_imdb_title_id', true ) );

		if ( '' === $year && '' !== $lookup_context['year'] ) {
			$year = $lookup_context['year'];
		}

		$result = array(
			'status'         => 'incomplete',
			'message'        => __( 'Review needs both a lookup title and release year before IMDb validation can run.', 'lunara-imdb-guard' ),
			'expected_id'    => '',
			'expected_title' => '',
			'expected_year'  => '',
			'checked_at'     => current_time( 'mysql' ),
		);

		if ( '' === $title || '' === $year ) {
			$this->store_state( $post_id, $result );
			return $result;
		}

		$lookup = $this->lookup_omdb( $title, $year );
		if ( 'error' === $lookup['status'] ) {
			$result['status']  = 'error';
			$result['message'] = $lookup['message'];
			$this->store_state( $post_id, $result );
			return $result;
		}

		if ( 'not_found' === $lookup['status'] ) {
			$result['status']  = 'no_match';
			$result['message'] = sprintf(
				/* translators: 1: lookup title, 2: year */
				__( 'OMDb did not find a confident movie match for "%1$s" (%2$s).', 'lunara-imdb-guard' ),
				$title,
				$year
			);
			$this->store_state( $post_id, $result );
			return $result;
		}

		$expected_id    = $lookup['imdb_id'];
		$expected_title = $lookup['title'];
		$expected_year  = $lookup['year'];

		$result['expected_id']    = $expected_id;
		$result['expected_title'] = $expected_title;
		$result['expected_year']  = $expected_year;

		if ( '' === $current_id && $auto_fill_missing && '' !== $expected_id ) {
			update_post_meta( $post_id, '_lunara_imdb_title_id', $expected_id );
			$current_id         = $expected_id;
			$result['status']   = 'autofilled';
			$result['message']  = sprintf(
				/* translators: 1: lookup title, 2: year */
				__( 'Missing IMDb ID was auto-filled from OMDb because "%1$s" (%2$s) matched cleanly.', 'lunara-imdb-guard' ),
				$title,
				$year
			);
			if ( ! $this->sync_title_map_entry( $title, $expected_year, $expected_id ) ) {
				$result['status']  = 'error';
				$result['message'] = __( 'IMDb validation succeeded, but canonical title-map sync is temporarily unavailable. Retry validation.', 'lunara-imdb-guard' );
				$this->store_state( $post_id, $result );
				return $result;
			}
			
			// Process TMDB assets matching our newly autofilled ID
			$this->sync_lunara_tmdb_images( $post_id, $expected_id );
			
			$this->store_state( $post_id, $result );
			return $result;
		}

		if ( '' === $current_id ) {
			$result['status']  = 'missing';
			$result['message'] = __( 'Review is missing an IMDb ID, but a suggested match is available below.', 'lunara-imdb-guard' );
			$this->store_state( $post_id, $result );
			return $result;
		}

		if ( $current_id === $expected_id ) {
			$result['status']  = 'verified';
			$result['message'] = sprintf(
				/* translators: 1: lookup title, 2: year */
				__( 'Current IMDb ID matches the OMDb result for "%1$s" (%2$s).', 'lunara-imdb-guard' ),
				$title,
				$year
			);
			if ( ! $this->sync_title_map_entry( $title, $expected_year, $expected_id ) ) {
				$result['status']  = 'error';
				$result['message'] = __( 'IMDb validation succeeded, but canonical title-map sync is temporarily unavailable. Retry validation.', 'lunara-imdb-guard' );
				$this->store_state( $post_id, $result );
				return $result;
			}
			
			// Keep images updated on active verification passes
			$this->sync_lunara_tmdb_images( $post_id, $current_id );
			
			$this->store_state( $post_id, $result );
			return $result;
		}

		$result['status']  = 'mismatch';
		$result['message'] = sprintf(
			/* translators: 1: current id, 2: suggested id */
			__( 'Current IMDb ID %1$s does not match the OMDb suggestion %2$s.', 'lunara-imdb-guard' ),
			$current_id,
			$expected_id
		);
		$this->store_state( $post_id, $result );

		return $result;
	}

	/**
	 * Query OMDb using title and year.
	 *
	 * @param string $title Review title.
	 * @param string $year  Review year.
	 * @return array
	 */
	private function lookup_omdb( $title, $year ) {
		$title = trim( (string) $title );
		$year  = trim( (string) $year );

		$cache_key = 'lunara_imdb_guard_v2_' . md5( strtolower( $title . '|' . $year ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return array(
				'status'  => 'error',
				'message' => __( 'No OMDb API key is configured for Lunara IMDb Guard.', 'lunara-imdb-guard' ),
			);
		}

		$exact_payload = $this->request_omdb(
			array(
				'apikey' => $api_key,
				't'      => $title,
				'y'      => $year,
				'type'   => 'movie',
			)
		);

		if ( is_wp_error( $exact_payload ) ) {
			return array(
				'status'  => 'error',
				'message' => $exact_payload->get_error_message(),
			);
		}

		$result = $this->build_exact_match_result( $exact_payload, $title, $year );
		if ( is_array( $result ) ) {
			set_transient( $cache_key, $result, 7 * DAY_IN_SECONDS );
			return $result;
		}

		$search_payload = $this->request_omdb(
			array(
				'apikey' => $api_key,
				's'      => $title,
				'y'      => $year,
				'type'   => 'movie',
			)
		);

		if ( is_wp_error( $search_payload ) ) {
			return array(
				'status'  => 'error',
				'message' => $search_payload->get_error_message(),
			);
		}

		$result = $this->build_search_match_result( $search_payload, $title, $year );
		if ( is_array( $result ) ) {
			set_transient( $cache_key, $result, 7 * DAY_IN_SECONDS );
			return $result;
		}

		$result = array(
			'status'  => 'not_found',
			'message' => __( 'OMDb returned no confident match.', 'lunara-imdb-guard' ),
		);

		set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Query TMDB to retrieve uncompressed poster and backdrop layouts using an IMDb ID.
	 *
	 * @param string $imdb_id The 'tt' string constant.
	 * @return array|false
	 */
	private function fetch_tmdb_images( $imdb_id ) {
		$api_key = $this->get_tmdb_api_key();
		if ( '' === $api_key ) {
			return false;
		}

		// Step 1: Resolve the internal numeric TMDB ID via External ID endpoint
		$find_url = "https://api.themoviedb.org/3/find/{$imdb_id}?api_key={$api_key}&external_source=imdb_id";
		$find_response = wp_remote_get( $find_url, array( 'timeout' => 10 ) );
		
		if ( is_wp_error( $find_response ) ) {
			return false;
		}
		
		$find_data = json_decode( wp_remote_retrieve_body( $find_response ), true );
		if ( empty( $find_data['movie_results'] ) || ! is_array( $find_data['movie_results'] ) ) {
			return false;
		}
		
		$tmdb_id = $find_data['movie_results'][0]['id'];
		
		// Step 2: Request image collection array
		$images_url = "https://api.themoviedb.org/3/movie/{$tmdb_id}/images?api_key={$api_key}";
		$images_response = wp_remote_get( $images_url, array( 'timeout' => 10 ) );
		
		if ( is_wp_error( $images_response ) ) {
			return false;
		}
		
		$images_data = json_decode( wp_remote_retrieve_body( $images_response ), true );
		
		// Original resolution path config
		$base_image_url = 'https://image.tmdb.org/t/p/original';
		$output = array(
			'poster'   => '',
			'backdrop' => '',
		);
		
		if ( ! empty( $images_data['posters'] ) && is_array( $images_data['posters'] ) ) {
			$output['poster'] = $base_image_url . $images_data['posters'][0]['file_path'];
		}
		
		if ( ! empty( $images_data['backdrops'] ) && is_array( $images_data['backdrops'] ) ) {
			$output['backdrop'] = $base_image_url . $images_data['backdrops'][0]['file_path'];
		}
		
		return $output;
	}

	/**
	 * Orchestrate image population and metadata storage for a specific post.
	 *
	 * @param int    $post_id Post context identifier.
	 * @param string $imdb_id The 'tt' string constant.
	 * @return void
	 */
	private function sync_lunara_tmdb_images( $post_id, $imdb_id ) {
		$images = $this->fetch_tmdb_images( $imdb_id );
		
		if ( is_array( $images ) ) {
			if ( ! empty( $images['poster'] ) ) {
				update_post_meta( $post_id, self::META_POSTER, esc_url_raw( $images['poster'] ) );
			} else {
				delete_post_meta( $post_id, self::META_POSTER );
			}
			
			if ( ! empty( $images['backdrop'] ) ) {
				update_post_meta( $post_id, self::META_BACKDROP, esc_url_raw( $images['backdrop'] ) );
			} else {
				delete_post_meta( $post_id, self::META_BACKDROP );
			}
		}
	}

	/**
	 * Fill only the empty image slots for a post from TMDB. Never deletes or
	 * overwrites an existing poster/backdrop.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $imdb_id The 'tt' id.
	 * @return array Keys 'poster' and 'backdrop' (bool) — what was newly filled.
	 */
	private function fill_missing_images( $post_id, $imdb_id ) {
		$did          = array( 'poster' => false, 'backdrop' => false );
		$has_poster   = '' !== trim( (string) get_post_meta( $post_id, self::META_POSTER, true ) );
		$has_backdrop = '' !== trim( (string) get_post_meta( $post_id, self::META_BACKDROP, true ) );

		if ( $has_poster && $has_backdrop ) {
			return $did;
		}

		$images = $this->fetch_tmdb_images( $imdb_id );
		if ( ! is_array( $images ) ) {
			return $did;
		}

		if ( ! $has_poster && ! empty( $images['poster'] ) ) {
			update_post_meta( $post_id, self::META_POSTER, esc_url_raw( $images['poster'] ) );
			$did['poster'] = true;
		}
		if ( ! $has_backdrop && ! empty( $images['backdrop'] ) ) {
			update_post_meta( $post_id, self::META_BACKDROP, esc_url_raw( $images['backdrop'] ) );
			$did['backdrop'] = true;
		}

		// TMDB gave a definitive answer for this title — mark it attempted so a
		// title it has no (further) art for isn't re-queried on every run.
		update_post_meta( $post_id, self::META_IMG_CHECKED, time() );

		return $did;
	}

	/**
	 * Count published reviews missing a poster or backdrop, split by whether
	 * they have an IMDb ID to look one up with.
	 *
	 * @return array Keys 'missing' and 'no_id' (int).
	 */
	private function count_reviews_missing_images() {
		$ids = get_posts(
			array(
				'post_type'              => 'review',
				'post_status'            => 'publish',
				'posts_per_page'         => 1000,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$missing = 0;
		$no_id   = 0;
		foreach ( $ids as $id ) {
			$has_poster   = '' !== trim( (string) get_post_meta( $id, self::META_POSTER, true ) );
			$has_backdrop = '' !== trim( (string) get_post_meta( $id, self::META_BACKDROP, true ) );
			if ( $has_poster && $has_backdrop ) {
				continue;
			}
			$imdb_id = $this->normalize_imdb_id( get_post_meta( $id, '_lunara_imdb_title_id', true ) );
			if ( '' === $imdb_id ) {
				$no_id++;
				continue;
			}
			if ( '' !== get_post_meta( $id, self::META_IMG_CHECKED, true ) ) {
				continue; // Already attempted — nothing more the puller can do.
			}
			$missing++;
		}

		return array(
			'missing' => $missing,
			'no_id'   => $no_id,
		);
	}

	/**
	 * Persist an optional manual lookup-title override.
	 *
	 * @param int $post_id Review post ID.
	 * @return void
	 */
	private function save_lookup_override( $post_id ) {
		if ( ! isset( $_POST['lunara_imdb_guard_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lunara_imdb_guard_nonce'] ) ), 'lunara_imdb_guard_meta_box' ) ) {
			return;
		}

		$value = isset( $_POST['lunara_imdb_guard_lookup_title'] ) ? sanitize_text_field( wp_unslash( $_POST['lunara_imdb_guard_lookup_title'] ) ) : '';
		$value = $this->clean_lookup_title( $value );

		if ( '' === $value ) {
			delete_post_meta( $post_id, self::META_LOOKUP );
			return;
		}

		update_post_meta( $post_id, self::META_LOOKUP, $value );
	}

	/**
	 * Resolve the lookup title/year context used for validation.
	 *
	 * @param int $post_id Review post ID.
	 * @return array
	 */
	private function resolve_lookup_context( $post_id ) {
		$override = $this->clean_lookup_title( get_post_meta( $post_id, self::META_LOOKUP, true ) );
		if ( '' !== $override ) {
			return array(
				'title'  => $override,
				'year'   => '',
				'source' => 'override',
			);
		}

		$header = $this->extract_review_header_context( $post_id );
		if ( '' !== $header['title'] ) {
			return array(
				'title'  => $header['title'],
				'year'   => $header['year'],
				'source' => 'review_header',
			);
		}

		$post_title = trim( wp_strip_all_tags( get_the_title( $post_id ) ) );
		$stripped   = preg_replace( '/\s*\((?:19|20)\d{2}\)\s*$/', '', $post_title );
		$stripped   = $this->clean_lookup_title( $stripped );

		return array(
			'title'  => '' !== $stripped ? $stripped : $this->clean_lookup_title( $post_title ),
			'year'   => '',
			'source' => $stripped !== $post_title ? 'post_title_trimmed' : 'post_title',
		);
	}

	/**
	 * Extract the canonical title/year comment from the review body when present.
	 *
	 * @param int $post_id Review post ID.
	 * @return array
	 */
	private function extract_review_header_context( $post_id ) {
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( '' === trim( $content ) ) {
			return array(
				'title' => '',
				'year'  => '',
			);
		}

		if ( preg_match( '/<!--\s*["“”]?([^<\r\n]+?)["“”]?\s*\((\d{4})\)(?:[^>]*)-->/u', $content, $matches ) ) {
			return array(
				'title' => $this->clean_lookup_title( $matches[1] ),
				'year'  => trim( (string) $matches[2] ),
			);
		}

		return array(
			'title' => '',
			'year'  => '',
		);
	}

	/**
	 * Normalize a candidate lookup title for OMDb matching.
	 *
	 * @param mixed $title Raw title value.
	 * @return string
	 */
	private function clean_lookup_title( $title ) {
		$title = trim( wp_strip_all_tags( (string) $title ) );
		$title = preg_replace( '/\s+/', ' ', $title );

		return trim( (string) $title, " \t\n\r\0\x0B\"'“”" );
	}

	/**
	 * Render a human-friendly lookup-source label.
	 *
	 * @param string $source Internal lookup source.
	 * @return string
	 */
	private function format_lookup_source_label( $source ) {
		$labels = array(
			'override'           => __( 'Manual override', 'lunara-imdb-guard' ),
			'review_header'      => __( 'Review HTML header comment', 'lunara-imdb-guard' ),
			'post_title_trimmed' => __( 'Post title with the trailing year removed', 'lunara-imdb-guard' ),
			'post_title'         => __( 'Post title', 'lunara-imdb-guard' ),
		);

		return isset( $labels[ $source ] ) ? $labels[ $source ] : __( 'Unknown source', 'lunara-imdb-guard' );
	}

	/**
	 * Request OMDb and return the decoded payload or a WP_Error.
	 *
	 * @param array $query_args OMDb query args.
	 * @return array|WP_Error
	 */
	private function request_omdb( $query_args ) {
		$response = wp_remote_get(
			add_query_arg( $query_args, 'https://www.omdbapi.com/' ),
			array(
				'timeout' => 12,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'lunara_imdb_guard_invalid_payload', __( 'OMDb returned an unreadable response.', 'lunara-imdb-guard' ) );
		}

		return $payload;
	}

	/**
	 * Build a confident exact-title OMDb match result.
	 *
	 * @param array  $payload      OMDb payload.
	 * @param string $lookup_title Title being validated.
	 * @param string $lookup_year  Year being validated.
	 * @return array|null
	 */
	private function build_exact_match_result( $payload, $lookup_title, $lookup_year ) {
		if ( empty( $payload['Response'] ) || 'True' !== $payload['Response'] ) {
			return null;
		}

		$result_title = trim( (string) ( $payload['Title'] ?? '' ) );
		$result_year  = trim( substr( (string) ( $payload['Year'] ?? '' ), 0, 4 ) );
		$result_id    = $this->normalize_imdb_id( $payload['imdbID'] ?? '' );

		if ( '' === $result_title || '' === $result_year || '' === $result_id ) {
			return null;
		}

		if ( $result_year !== trim( (string) $lookup_year ) ) {
			return null;
		}

		if ( $this->normalize_title_key( $result_title ) !== $this->normalize_title_key( $lookup_title ) ) {
			return null;
		}

		return array(
			'status'  => 'ok',
			'title'   => $result_title,
			'year'    => $result_year,
			'imdb_id' => $result_id,
		);
	}

	/**
	 * Build a confident search-result OMDb match result.
	 *
	 * @param array  $payload      OMDb search payload.
	 * @param string $lookup_title Title being validated.
	 * @param string $lookup_year  Year being validated.
	 * @return array|null
	 */
	private function build_search_match_result( $payload, $lookup_title, $lookup_year ) {
		if ( empty( $payload['Response'] ) || 'True' !== $payload['Response'] || empty( $payload['Search'] ) || ! is_array( $payload['Search'] ) ) {
			return null;
		}

		$normalized_lookup = $this->normalize_title_key( $lookup_title );
		$lookup_year       = trim( (string) $lookup_year );

		foreach ( $payload['Search'] as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$candidate_title = trim( (string) ( $candidate['Title'] ?? '' ) );
			$candidate_year  = trim( substr( (string) ( $candidate['Year'] ?? '' ), 0, 4 ) );
			$candidate_type  = sanitize_key( (string) ( $candidate['Type'] ?? '' ) );
			$candidate_id    = $this->normalize_imdb_id( $candidate['imdbID'] ?? '' );

			if ( 'movie' !== $candidate_type || '' === $candidate_title || '' === $candidate_year || '' === $candidate_id ) {
				continue;
			}

			if ( $candidate_year !== $lookup_year ) {
				continue;
			}

			if ( $this->normalize_title_key( $candidate_title ) !== $normalized_lookup ) {
				continue;
			}

			return array(
				'status'  => 'ok',
				'title'   => $candidate_title,
				'year'    => $candidate_year,
				'imdb_id' => $candidate_id,
			);
		}

		return null;
	}

	/**
	 * Return the configured OMDb API key.
	 *
	 * @return string
	 */
	private function get_api_key() {
		$option = trim( (string) get_option( self::OPTION_API_KEY, '' ) );
		if ( '' !== $option ) {
			return $option;
		}

		if ( defined( 'LUNARA_IMDB_GUARD_OMDB_API_KEY' ) ) {
			return trim( (string) constant( 'LUNARA_IMDB_GUARD_OMDB_API_KEY' ) );
		}

		return LUNARA_IMDB_GUARD_DEFAULT_OMDB_API_KEY;
	}

	/**
	 * Return the configured TMDB API key.
	 *
	 * Resolves, in order, from this plugin's own option, the Oscars Ledger
	 * AAT_TMDB_API_KEY constant (so a single configured key serves both plugins),
	 * a wp-config LUNARA_IMDB_GUARD_TMDB_API_KEY constant, or empty. The key is
	 * never hardcoded so it stays out of source control and can be rotated
	 * without a code change; poster/backdrop sync is skipped when it is empty.
	 *
	 * @return string
	 */
	private function get_tmdb_api_key() {
		$option = trim( (string) get_option( self::OPTION_TMDB_API_KEY, '' ) );
		if ( '' !== $option ) {
			return $option;
		}

		if ( defined( 'AAT_TMDB_API_KEY' ) && '' !== (string) constant( 'AAT_TMDB_API_KEY' ) ) {
			return trim( (string) constant( 'AAT_TMDB_API_KEY' ) );
		}

		if ( defined( 'LUNARA_IMDB_GUARD_TMDB_API_KEY' ) ) {
			return trim( (string) constant( 'LUNARA_IMDB_GUARD_TMDB_API_KEY' ) );
		}

		return '';
	}

	/**
	 * Normalize any IMDb string into a tt id when possible.
	 *
	 * @param mixed $raw Raw IMDb value.
	 * @return string
	 */
	private function normalize_imdb_id( $raw ) {
		$raw = trim( (string) $raw );

		if ( preg_match( '/\btt\d{7,10}\b/i', $raw, $matches ) ) {
			return strtolower( $matches[0] );
		}

		if ( preg_match( '#imdb\.com/title/(tt\d{7,10})#i', $raw, $matches ) ) {
			return strtolower( $matches[1] );
		}

		return '';
	}

	/**
	 * Fetch stored audit state.
	 *
	 * @param int $post_id Review post id.
	 * @return array
	 */
	private function get_stored_state( $post_id ) {
		$status = trim( (string) get_post_meta( $post_id, self::META_STATUS, true ) );
		if ( '' === $status ) {
			$status = 'unchecked';
		}

		return array(
			'status'         => $status,
			'message'        => trim( (string) get_post_meta( $post_id, self::META_MESSAGE, true ) ),
			'expected_id'    => trim( (string) get_post_meta( $post_id, self::META_EXPECTED, true ) ),
			'expected_title' => trim( (string) get_post_meta( $post_id, self::META_TITLE, true ) ),
			'expected_year'  => trim( (string) get_post_meta( $post_id, self::META_YEAR, true ) ),
			'checked_at'     => trim( (string) get_post_meta( $post_id, self::META_CHECKED, true ) ),
		);
	}

	/**
	 * Persist audit state.
	 *
	 * @param int   $post_id Review post id.
	 * @param array $result  Validation result.
	 * @return void
	 */
	private function store_state( $post_id, $result ) {
		update_post_meta( $post_id, self::META_STATUS, sanitize_text_field( $result['status'] ) );
		update_post_meta( $post_id, self::META_MESSAGE, sanitize_text_field( $result['message'] ) );
		update_post_meta( $post_id, self::META_EXPECTED, sanitize_text_field( $result['expected_id'] ) );
		update_post_meta( $post_id, self::META_TITLE, sanitize_text_field( $result['expected_title'] ) );
		update_post_meta( $post_id, self::META_YEAR, sanitize_text_field( $result['expected_year'] ) );
		update_post_meta( $post_id, self::META_CHECKED, sanitize_text_field( $result['checked_at'] ) );
	}

	/**
	 * Format human status labels.
	 *
	 * @param string $status Internal status code.
	 * @return string
	 */
	private function format_status_label( $status ) {
		$labels = array(
			'unchecked'  => __( 'Unchecked', 'lunara-imdb-guard' ),
			'incomplete' => __( 'Incomplete', 'lunara-imdb-guard' ),
			'missing'    => __( 'Missing ID', 'lunara-imdb-guard' ),
			'autofilled' => __( 'Auto-Filled', 'lunara-imdb-guard' ),
			'verified'   => __( 'Verified', 'lunara-imdb-guard' ),
			'mismatch'   => __( 'Mismatch', 'lunara-imdb-guard' ),
			'no_match'   => __( 'No Match', 'lunara-imdb-guard' ),
			'error'      => __( 'Error', 'lunara-imdb-guard' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( str_replace( '_', ' ', $status ) );
	}

	/**
	 * Return an object that cannot collide with a stored option value.
	 *
	 * @return stdClass
	 */
	private static function option_sentinel() {
		return new stdClass();
	}

	/**
	 * Read and normalize the active stylesheet's bounded legacy map.
	 *
	 * @return array|null
	 */
	private function read_legacy_title_map() {
		$root       = trailingslashit( get_stylesheet_directory() );
		$candidates = array(
			$root . 'assets/data/imdb-title-map.json',
			$root . 'imdb-title-map.json',
		);
		$legacy = null;
		$found  = false;

		foreach ( $candidates as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}
			$candidate = $this->read_legacy_title_map_file( $path );
			if ( null === $candidate ) {
				return null;
			}
			if ( $found && $legacy !== $candidate ) {
				return null;
			}
			$legacy = $candidate;
			$found  = true;
		}

		return $found ? $legacy : null;
	}

	/**
	 * Read one already-bounded legacy candidate all-or-nothing.
	 *
	 * @param string $path Candidate path within the active stylesheet.
	 * @return array|null
	 */
	private function read_legacy_title_map_file( $path ) {
		$size = filesize( $path );
		if ( false !== $size && $size > self::LEGACY_MAP_MAX_BYTES ) {
			return null;
		}

		$raw = file_get_contents( $path, false, null, 0, self::LEGACY_MAP_MAX_BYTES + 1 );
		if ( false === $raw || strlen( $raw ) > self::LEGACY_MAP_MAX_BYTES ) {
			return null;
		}

		$trimmed = ltrim( $raw );
		if ( '' === $trimmed || '{' !== $trimmed[0] ) {
			return null;
		}

		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return null;
		}

		return $this->normalize_title_map( $decoded );
	}

	/**
	 * Normalize a complete title map or reject the whole candidate.
	 *
	 * @param mixed $candidate Candidate map.
	 * @return array|null
	 */
	private function normalize_title_map( $candidate ) {
		if ( ! is_array( $candidate ) || count( $candidate ) > self::LEGACY_MAP_MAX_ENTRIES ) {
			return null;
		}

		if ( ! empty( $candidate ) && array_keys( $candidate ) === range( 0, count( $candidate ) - 1 ) ) {
			return null;
		}

		$normalized = array();
		foreach ( $candidate as $raw_key => $raw_id ) {
			if ( ! is_string( $raw_key ) || ! is_scalar( $raw_id ) ) {
				return null;
			}

			$separator = strrpos( $raw_key, '|' );
			if ( false === $separator ) {
				return null;
			}
			$title = substr( $raw_key, 0, $separator );
			$year  = trim( substr( $raw_key, $separator + 1 ) );
			$key   = $this->build_map_key( $title, $year );
			$id    = $this->normalize_title_map_id( $raw_id );
			if ( '' === $key || ! preg_match( '/^\d{4}$/', $year ) || '' === $id ) {
				return null;
			}

			if ( isset( $normalized[ $key ] ) && $normalized[ $key ] !== $id ) {
				return null;
			}
			$normalized[ $key ] = $id;
		}

		ksort( $normalized, SORT_NATURAL | SORT_FLAG_CASE );
		return $normalized;
	}

	/**
	 * Accept only a bare IMDb ID or canonical IMDb title URL in stored maps.
	 *
	 * @param mixed $raw_id Candidate stored value.
	 * @return string
	 */
	private function normalize_title_map_id( $raw_id ) {
		$raw_id = trim( (string) $raw_id );
		if ( preg_match( '/^tt\d{7,10}$/i', $raw_id, $matches ) ) {
			return strtolower( $matches[0] );
		}
		if ( preg_match( '~^https?://(?:www\.)?imdb\.com/title/(tt\d{7,10})/?(?:[?#].*)?$~i', $raw_id, $matches ) ) {
			return strtolower( $matches[1] );
		}

		return '';
	}

	/**
	 * Mark migration complete only after the canonical map is valid.
	 *
	 * @return bool
	 */
	private function mark_title_map_migration_complete() {
		$map = $this->get_title_map();
		if ( ! is_array( $map ) ) {
			return false;
		}

		$sentinel = self::option_sentinel();
		$marker   = get_option( self::OPTION_TITLE_MAP_MIGRATION, $sentinel );
		if ( self::TITLE_MAP_SCHEMA_VERSION !== $marker ) {
			if ( $sentinel === $marker ) {
				add_option( self::OPTION_TITLE_MAP_MIGRATION, self::TITLE_MAP_SCHEMA_VERSION, '', false );
			} else {
				update_option( self::OPTION_TITLE_MAP_MIGRATION, self::TITLE_MAP_SCHEMA_VERSION, false );
			}
		}

		return self::TITLE_MAP_SCHEMA_VERSION === get_option( self::OPTION_TITLE_MAP_MIGRATION, $sentinel );
	}

	/**
	 * Update an existing canonical map without seeding partial pre-migration data.
	 *
	 * @param mixed $title   Review title.
	 * @param mixed $year    Review year.
	 * @param mixed $imdb_id IMDb title ID.
	 * @return bool
	 */
	private function sync_title_map_entry( $title, $year, $imdb_id ) {
		if ( ! is_scalar( $title ) || ! is_scalar( $year ) || ! is_scalar( $imdb_id ) ) {
			return false;
		}
		$key     = $this->build_map_key( (string) $title, (string) $year );
		$imdb_id = $this->normalize_imdb_id( $imdb_id );
		if ( '' === $key || '' === $imdb_id ) {
			return false;
		}

		for ( $attempt = 0; $attempt < self::TITLE_MAP_CAS_ATTEMPTS; $attempt++ ) {
			$snapshot = $this->read_title_map_row();
			if ( ! is_array( $snapshot ) ) {
				return false;
			}
			$current = $snapshot['map'];
			if ( isset( $current[ $key ] ) && $imdb_id === $current[ $key ] ) {
				return true;
			}

			$next         = $current;
			$next[ $key ] = $imdb_id;
			ksort( $next, SORT_NATURAL | SORT_FLAG_CASE );
			$swapped = $this->compare_and_swap_title_map( $snapshot, $next, $key, $imdb_id );
			if ( false === $swapped ) {
				return false;
			}
			if ( 1 === $swapped ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read one exact physical canonical option row for a mutation attempt.
	 *
	 * @return array|null
	 */
	private function read_title_map_row() {
		global $wpdb;

		if ( ! isset( $wpdb->options )
			|| ! is_callable( array( $wpdb, 'prepare' ) )
			|| ! is_callable( array( $wpdb, 'get_row' ) ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_id, option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::OPTION_TITLE_MAP
			),
			ARRAY_A
		);
		if ( ! is_array( $row )
			|| ! isset( $row['option_id'], $row['option_value'], $row['autoload'] )
			|| ! is_scalar( $row['option_id'] )
			|| ! ctype_digit( (string) $row['option_id'] )
			|| '0' === (string) $row['option_id']
			|| ! is_string( $row['option_value'] )
			|| ! is_string( $row['autoload'] ) ) {
			return null;
		}

		$decoded    = maybe_unserialize( $row['option_value'] );
		$normalized = $this->normalize_title_map( $decoded );
		if ( null === $normalized || $decoded !== $normalized ) {
			return null;
		}

		return array(
			'option_id'    => (string) $row['option_id'],
			'option_value' => $row['option_value'],
			'autoload'     => $row['autoload'],
			'map'          => $normalized,
		);
	}

	/**
	 * Atomically replace one exact physical option row and verify intent.
	 *
	 * @param array  $snapshot    Exact physical row snapshot.
	 * @param array  $replacement Exact normalized merged map to store.
	 * @param string $intent_key  Canonical entry key that must survive readback.
	 * @param string $intent_id   Canonical IMDb ID that must survive readback.
	 * @return int|false One for a verified win, zero for a conflict, false on error.
	 */
	private function compare_and_swap_title_map( $snapshot, $replacement, $intent_key, $intent_id ) {
		global $wpdb;

		$normalized_replacement = $this->normalize_title_map( $replacement );
		$snapshot_map           = is_array( $snapshot ) ? ( $snapshot['map'] ?? null ) : null;
		$decoded_snapshot       = is_array( $snapshot ) && is_string( $snapshot['option_value'] ?? null )
			? maybe_unserialize( $snapshot['option_value'] )
			: null;
		if ( ! is_array( $snapshot )
			|| array( 'option_id', 'option_value', 'autoload', 'map' ) !== array_keys( $snapshot )
			|| ! is_string( $snapshot['option_id'] )
			|| ! ctype_digit( $snapshot['option_id'] )
			|| '0' === $snapshot['option_id']
			|| ! is_string( $snapshot['option_value'] )
			|| ! is_string( $snapshot['autoload'] )
			|| ! is_array( $snapshot_map )
			|| $decoded_snapshot !== $snapshot_map
			|| $snapshot_map !== $this->normalize_title_map( $snapshot_map )
			|| $replacement !== $normalized_replacement
			|| ! is_string( $intent_key )
			|| ! is_string( $intent_id )
			|| ! isset( $replacement[ $intent_key ] )
			|| $intent_id !== $replacement[ $intent_key ]
			|| ! isset( $wpdb->options )
			|| ! is_callable( array( $wpdb, 'prepare' ) )
			|| ! is_callable( array( $wpdb, 'query' ) ) ) {
			return false;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_id = %d AND option_name = %s AND BINARY option_value = BINARY %s AND BINARY autoload = BINARY %s LIMIT 1",
				maybe_serialize( $replacement ),
				$snapshot['option_id'],
				self::OPTION_TITLE_MAP,
				$snapshot['option_value'],
				$snapshot['autoload']
			)
		);
		if ( false === $updated ) {
			return false;
		}
		if ( 1 !== (int) $updated ) {
			return 0;
		}

		wp_cache_delete( self::OPTION_TITLE_MAP, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$readback = $this->read_title_map_row();
		if ( ! is_array( $readback ) ) {
			return false;
		}

		return isset( $readback['map'][ $intent_key ] ) && $intent_id === $readback['map'][ $intent_key ] ? 1 : 0;
	}

	/**
	 * Read and strictly allowlist a compact health snapshot.
	 *
	 * @return array|null
	 */
	private function read_health_snapshot() {
		$sentinel = self::option_sentinel();
		$stored   = get_option( self::OPTION_HEALTH_SNAPSHOT, $sentinel );
		if ( $sentinel === $stored || ! is_array( $stored ) || self::HEALTH_SNAPSHOT_SCHEMA_VERSION !== ( $stored['schema_version'] ?? null ) ) {
			return null;
		}

		$identity_keys = array( 'total', 'with_imdb_id', 'missing_imdb_id', 'needs_review' );
		$artwork_keys  = array( 'eligible', 'complete', 'missing' );
		$identity      = array();
		$artwork       = array();
		foreach ( $identity_keys as $key ) {
			if ( ! isset( $stored['identity'][ $key ] ) || ! is_int( $stored['identity'][ $key ] ) || $stored['identity'][ $key ] < 0 || $stored['identity'][ $key ] > 1000 ) {
				return null;
			}
			$identity[ $key ] = $stored['identity'][ $key ];
		}
		foreach ( $artwork_keys as $key ) {
			if ( ! isset( $stored['artwork'][ $key ] ) || ! is_int( $stored['artwork'][ $key ] ) || $stored['artwork'][ $key ] < 0 || $stored['artwork'][ $key ] > 1000 ) {
				return null;
			}
			$artwork[ $key ] = $stored['artwork'][ $key ];
		}

		if ( $identity['total'] !== $identity['with_imdb_id'] + $identity['missing_imdb_id']
			|| $identity['needs_review'] > $identity['total']
			|| $artwork['eligible'] !== $identity['with_imdb_id']
			|| $artwork['eligible'] !== $artwork['complete'] + $artwork['missing'] ) {
			return null;
		}

		return array(
			'identity' => $identity,
			'artwork'  => $artwork,
		);
	}

	/**
	 * Build the canonical title-map key.
	 *
	 * @param string $title Review title.
	 * @param string $year  Review year.
	 * @return string
	 */
	private function build_map_key( $title, $year ) {
		$title = $this->normalize_title_key( $title );
		$year  = trim( (string) $year );

		if ( '' === $title || ! preg_match( '/^\d{4}$/', $year ) ) {
			return '';
		}

		return $title . '|' . $year;
	}

	/**
	 * Match Lunara's title normalization.
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	private function normalize_title_key( $title ) {
		$title = strtolower( remove_accents( (string) $title ) );
		$title = str_replace( '&', 'and', $title );
		$title = preg_replace( '/[^a-z0-9]+/', ' ', $title );
		return trim( preg_replace( '/\s+/', ' ', $title ) );
	}

	/**
	 * Redirect back to editor or audit page.
	 *
	 * @param int   $post_id Review post id.
	 * @param array $args    Query args.
	 * @return void
	 */
	private function redirect_back( $post_id, $args = array() ) {
		$referer = wp_get_referer();
		$target  = $referer ? $referer : get_edit_post_link( $post_id, 'raw' );

		wp_safe_redirect( add_query_arg( $args, $target ) );
		exit;
	}
}

register_activation_hook( LUNARA_IMDB_GUARD_FILE, array( 'Lunara_IMDb_Guard', 'activate' ) );
Lunara_IMDb_Guard::instance();

if ( ! function_exists( 'lunara_imdb_guard_get_title_map' ) ) {
	/**
	 * Return the canonical sanitized title map or NULL while unavailable.
	 *
	 * @return array|null
	 */
	function lunara_imdb_guard_get_title_map(): ?array {
		return Lunara_IMDb_Guard::instance()->get_title_map();
	}
}

if ( ! function_exists( 'lunara_imdb_guard_lookup_title_id' ) ) {
	/**
	 * Resolve an IMDb title ID from the canonical map.
	 *
	 * @param mixed $title Film title.
	 * @param mixed $year  Four-digit release year.
	 * @return string
	 */
	function lunara_imdb_guard_lookup_title_id( $title, $year = '' ): string {
		return Lunara_IMDb_Guard::instance()->lookup_title_id( $title, $year );
	}
}

if ( ! function_exists( 'lunara_imdb_guard_admin_url' ) ) {
	/**
	 * Return the canonical IMDb Guard admin URL.
	 *
	 * @return string
	 */
	function lunara_imdb_guard_admin_url(): string {
		return Lunara_IMDb_Guard::instance()->admin_url();
	}
}

if ( ! function_exists( 'lunara_imdb_guard_admin_capability' ) ) {
	/**
	 * Return the canonical IMDb Guard capability.
	 *
	 * @return string
	 */
	function lunara_imdb_guard_admin_capability(): string {
		return Lunara_IMDb_Guard::instance()->admin_capability();
	}
}

if ( ! function_exists( 'lunara_imdb_guard_health_status' ) ) {
	/**
	 * Return bounded, redacted identity and artwork health.
	 *
	 * @return array
	 */
	function lunara_imdb_guard_health_status(): array {
		return Lunara_IMDb_Guard::instance()->health_status();
	}
}
