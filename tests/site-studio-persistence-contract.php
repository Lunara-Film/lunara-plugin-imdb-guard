<?php
/**
 * Runtime contracts for canonical IMDb map persistence and redacted health.
 *
 * Run with: php tests/site-studio-persistence-contract.php
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['lunara_test_options']              = array();
$GLOBALS['lunara_test_option_mutations']     = array();
$GLOBALS['lunara_test_add_behaviors']        = array();
$GLOBALS['lunara_test_add_observers']        = array();
$GLOBALS['lunara_test_actions']              = array();
$GLOBALS['lunara_test_activation_hooks']     = array();
$GLOBALS['lunara_test_stylesheet_directory'] = sys_get_temp_dir() . '/lunara-imdb-guard-missing-theme';
$GLOBALS['lunara_test_stylesheet_reads']     = 0;
$GLOBALS['lunara_test_review_ids']           = array();
$GLOBALS['lunara_test_meta']                 = array();
$GLOBALS['lunara_test_get_posts_calls']      = array();
$GLOBALS['lunara_test_titles']               = array();
$GLOBALS['lunara_test_content']              = array();
$GLOBALS['lunara_test_transients']           = array();
$GLOBALS['lunara_test_cache_deletes']        = array();

final class Lunara_Test_WPDB {
	public $options = 'wp_options';
	public $conditional_deletes = array();

	public function delete( string $table, array $where, array $formats ) {
		$this->conditional_deletes[] = array( $table, $where, $formats );
		$option = $where['option_name'] ?? '';
		if ( ! array_key_exists( $option, $GLOBALS['lunara_test_options'] ) ) {
			return 0;
		}
		if ( maybe_serialize( $GLOBALS['lunara_test_options'][ $option ] ) !== ( $where['option_value'] ?? null ) ) {
			return 0;
		}
		unset( $GLOBALS['lunara_test_options'][ $option ] );
		return 1;
	}
}

$GLOBALS['wpdb'] = new Lunara_Test_WPDB();

function plugin_dir_path( string $file ): string {
	return trailingslashit( dirname( $file ) );
}

function trailingslashit( string $path ): string {
	return rtrim( $path, '/\\' ) . '/';
}

function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['lunara_test_actions'][] = array( $hook, $callback, $priority, $accepted_args );
}

function register_activation_hook( string $file, $callback ): void {
	$GLOBALS['lunara_test_activation_hooks'][] = array( $file, $callback );
}

function get_option( string $option, $default = false ) {
	return array_key_exists( $option, $GLOBALS['lunara_test_options'] )
		? $GLOBALS['lunara_test_options'][ $option ]
		: $default;
}

function add_option( string $option, $value = '', $deprecated = '', $autoload = null ): bool {
	$GLOBALS['lunara_test_option_mutations'][] = array(
		'operation' => 'add',
		'option'    => $option,
		'value'     => $value,
		'autoload'  => $autoload,
	);
	if ( isset( $GLOBALS['lunara_test_add_observers'][ $option ] ) ) {
		$GLOBALS['lunara_test_add_observers'][ $option ]( $option, $value, $autoload );
	}

	if ( isset( $GLOBALS['lunara_test_add_behaviors'][ $option ] ) ) {
		$behavior = $GLOBALS['lunara_test_add_behaviors'][ $option ];
		unset( $GLOBALS['lunara_test_add_behaviors'][ $option ] );
		return (bool) $behavior( $option, $value, $autoload );
	}

	if ( array_key_exists( $option, $GLOBALS['lunara_test_options'] ) ) {
		return false;
	}

	$GLOBALS['lunara_test_options'][ $option ] = $value;
	return true;
}

function update_option( string $option, $value, $autoload = null ): bool {
	$GLOBALS['lunara_test_option_mutations'][] = array(
		'operation' => 'update',
		'option'    => $option,
		'value'     => $value,
		'autoload'  => $autoload,
	);
	$GLOBALS['lunara_test_options'][ $option ] = $value;
	return true;
}

function delete_option( string $option ): bool {
	$GLOBALS['lunara_test_option_mutations'][] = array(
		'operation' => 'delete',
		'option'    => $option,
	);
	unset( $GLOBALS['lunara_test_options'][ $option ] );
	return true;
}

function maybe_serialize( $value ): string {
	return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
}

function wp_cache_delete( string $key, string $group = '' ): bool {
	$GLOBALS['lunara_test_cache_deletes'][] = array( $key, $group );
	return true;
}

function get_stylesheet_directory(): string {
	$GLOBALS['lunara_test_stylesheet_reads']++;
	return $GLOBALS['lunara_test_stylesheet_directory'];
}

function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function remove_accents( string $value ): string {
	$value = strtr( $value, array( 'é' => 'e', 'É' => 'E' ) );
	$converted = iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $value );
	return false === $converted ? $value : $converted;
}

function sanitize_text_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

function wp_strip_all_tags( string $value ): string {
	return strip_tags( $value );
}

function absint( $value ): int {
	return abs( (int) $value );
}

function get_the_title( int $post_id ): string {
	return $GLOBALS['lunara_test_titles'][ $post_id ] ?? '';
}

function get_post_field( string $field, int $post_id ): string {
	return 'post_content' === $field ? ( $GLOBALS['lunara_test_content'][ $post_id ] ?? '' ) : '';
}

function current_time( string $type = 'mysql' ): string {
	return '2026-08-29 12:00:00';
}

function get_transient( string $key ) {
	return $GLOBALS['lunara_test_transients'][ $key ] ?? false;
}

function get_posts( array $args = array() ): array {
	$GLOBALS['lunara_test_get_posts_calls'][] = $args;
	return $GLOBALS['lunara_test_review_ids'];
}

function get_post_meta( int $post_id, string $key, bool $single = false ) {
	return $GLOBALS['lunara_test_meta'][ $post_id ][ $key ] ?? '';
}

function update_post_meta( int $post_id, string $key, $value ): void {
	$GLOBALS['lunara_test_meta'][ $post_id ][ $key ] = $value;
}

function __( string $text ): string {
	return $text;
}

require dirname( __DIR__ ) . '/lunara-imdb-guard.php';

function lunara_test_fail( string $message ): void {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function lunara_test_assert( $condition, string $message ): void {
	if ( ! $condition ) {
		lunara_test_fail( $message );
	}
}

function lunara_test_assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function lunara_test_reset(): void {
	$GLOBALS['lunara_test_options']          = array();
	$GLOBALS['lunara_test_option_mutations'] = array();
	$GLOBALS['lunara_test_add_behaviors']    = array();
	$GLOBALS['lunara_test_add_observers']    = array();
	$GLOBALS['lunara_test_stylesheet_reads'] = 0;
	$GLOBALS['lunara_test_review_ids']       = array();
	$GLOBALS['lunara_test_meta']             = array();
	$GLOBALS['lunara_test_get_posts_calls']  = array();
	$GLOBALS['lunara_test_titles']           = array();
	$GLOBALS['lunara_test_content']          = array();
	$GLOBALS['lunara_test_transients']       = array();
	$GLOBALS['lunara_test_cache_deletes']    = array();
	$GLOBALS['wpdb']->conditional_deletes    = array();
}

function lunara_test_invoke_private( object $object, string $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( $object, $method );
	return $reflection->invokeArgs( $object, $arguments );
}

function lunara_test_write_legacy( string $json ): string {
	$root = sys_get_temp_dir() . '/lunara-imdb-guard-' . bin2hex( random_bytes( 6 ) );
	$dir  = $root . '/assets/data';
	mkdir( $dir, 0777, true );
	file_put_contents( $dir . '/imdb-title-map.json', $json );
	$GLOBALS['lunara_test_stylesheet_directory'] = $root;
	return $root;
}

function lunara_test_remove_tree( string $root ): void {
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $files as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $root );
}

$required_functions = array(
	'lunara_imdb_guard_get_title_map',
	'lunara_imdb_guard_lookup_title_id',
	'lunara_imdb_guard_admin_url',
	'lunara_imdb_guard_admin_capability',
	'lunara_imdb_guard_health_status',
);
foreach ( $required_functions as $required_function ) {
	lunara_test_assert( function_exists( $required_function ), 'Missing stable public API: ' . $required_function );
}

$guard = Lunara_IMDb_Guard::instance();
lunara_test_assert( method_exists( $guard, 'maybe_migrate_title_map' ), 'The activation/admin upgrade migration method must exist.' );
lunara_test_assert( method_exists( $guard, 'refresh_health_snapshot' ), 'The owner-controlled health refresh method must exist.' );

// An absent or invalid canonical option remains nullable so the later theme rollout can fall back.
lunara_test_reset();
lunara_test_assert_same( null, lunara_imdb_guard_get_title_map(), 'An absent canonical map must return NULL.' );
$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP ] = false;
lunara_test_assert_same( null, lunara_imdb_guard_get_title_map(), 'An invalid canonical map must return NULL.' );
$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP ] = array();
lunara_test_assert_same( array(), lunara_imdb_guard_get_title_map(), 'An intentionally empty canonical map must remain authoritative.' );

// The valid importer normalizes all entries, creates non-autoloaded storage, and marks only exact readback.
lunara_test_reset();
$legacy_root = lunara_test_write_legacy( '{"Zodiac|2007":"HTTPS://www.imdb.com/title/TT0443706/","Amélie|2001":"TT0211915"}' );
lunara_test_assert_same( true, $guard->maybe_migrate_title_map(), 'A valid legacy object should migrate.' );
$expected_map = array(
	'amelie|2001' => 'tt0211915',
	'zodiac|2007' => 'tt0443706',
);
lunara_test_assert_same( $expected_map, lunara_imdb_guard_get_title_map(), 'Migration must normalize and sort the canonical map.' );
$map_add = $GLOBALS['lunara_test_option_mutations'][0] ?? array();
lunara_test_assert_same( 'add', $map_add['operation'] ?? '', 'First canonical creation must use add_option.' );
lunara_test_assert_same( Lunara_IMDb_Guard::OPTION_TITLE_MAP, $map_add['option'] ?? '', 'The first add must create canonical storage.' );
lunara_test_assert_same( false, $map_add['autoload'] ?? null, 'Canonical storage must be non-autoloaded.' );
$marker_adds = array_values(
	array_filter(
		$GLOBALS['lunara_test_option_mutations'],
		static fn( array $mutation ): bool => ( $mutation['option'] ?? '' ) === Lunara_IMDb_Guard::OPTION_TITLE_MAP_MIGRATION
	)
);
lunara_test_assert_same( 1, count( $marker_adds ), 'Successful exact readback must create one migration marker.' );
lunara_test_assert_same( false, $marker_adds[0]['autoload'] ?? null, 'The migration marker must be non-autoloaded.' );

// Existing canonical storage wins and idempotence avoids every legacy filesystem lookup.
$GLOBALS['lunara_test_stylesheet_reads'] = 0;
$GLOBALS['lunara_test_stylesheet_directory'] = $legacy_root . '/missing';
lunara_test_assert_same( true, $guard->maybe_migrate_title_map(), 'A valid canonical map should make migration idempotently complete.' );
lunara_test_assert_same( 0, $GLOBALS['lunara_test_stylesheet_reads'], 'Existing canonical storage must win without legacy I/O.' );
lunara_test_remove_tree( $legacy_root );

// Missing, corrupt, list-root, oversized, and conflicting legacy sources stay retryable.
$invalid_sources = array(
	'corrupt JSON'       => '{broken',
	'list root'          => '[{"film":"tt1234567"}]',
	'normalized conflict'=> '{"A & B|2020":"tt1234567","A and B|2020":"tt7654321"}',
	'oversized input'    => '{"Film|2020":"tt1234567","padding":"' . str_repeat( 'x', 1048576 ) . '"}',
);
foreach ( $invalid_sources as $label => $json ) {
	lunara_test_reset();
	$root = lunara_test_write_legacy( $json );
	lunara_test_assert_same( false, $guard->maybe_migrate_title_map(), $label . ' must not migrate.' );
	lunara_test_assert( ! array_key_exists( Lunara_IMDb_Guard::OPTION_TITLE_MAP, $GLOBALS['lunara_test_options'] ), $label . ' must not create a partial map.' );
	lunara_test_assert( ! array_key_exists( Lunara_IMDb_Guard::OPTION_TITLE_MAP_MIGRATION, $GLOBALS['lunara_test_options'] ), $label . ' must remain retryable.' );
	lunara_test_remove_tree( $root );
}

// The bounded root-level legacy candidate is supported when the primary path is absent.
lunara_test_reset();
$root = sys_get_temp_dir() . '/lunara-imdb-guard-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0777, true );
file_put_contents( $root . '/imdb-title-map.json', '{"Root Fallback|1988":"tt1234567"}' );
$GLOBALS['lunara_test_stylesheet_directory'] = $root;
lunara_test_assert_same( true, $guard->maybe_migrate_title_map(), 'The active stylesheet root fallback should migrate when the primary candidate is absent.' );
lunara_test_assert_same( array( 'root fallback|1988' => 'tt1234567' ), lunara_imdb_guard_get_title_map(), 'Root fallback migration must use the same canonical normalization.' );
lunara_test_remove_tree( $root );

// Two present legacy candidates must agree exactly; conflicting sources stay retryable.
lunara_test_reset();
$root = lunara_test_write_legacy( '{"Primary|1999":"tt1234567"}' );
file_put_contents( $root . '/imdb-title-map.json', '{"Conflicting|2000":"tt7654321"}' );
lunara_test_assert_same( false, $guard->maybe_migrate_title_map(), 'Conflicting bounded legacy candidates must not choose one source silently.' );
lunara_test_assert( ! array_key_exists( Lunara_IMDb_Guard::OPTION_TITLE_MAP, $GLOBALS['lunara_test_options'] ), 'Conflicting candidates must not create canonical storage.' );
lunara_test_assert( ! array_key_exists( Lunara_IMDb_Guard::OPTION_TITLE_MAP_MIGRATION, $GLOBALS['lunara_test_options'] ), 'Conflicting candidates must remain retryable.' );
lunara_test_remove_tree( $root );

lunara_test_reset();
$GLOBALS['lunara_test_stylesheet_directory'] = sys_get_temp_dir() . '/lunara-imdb-guard-no-source-' . bin2hex( random_bytes( 4 ) );
lunara_test_assert_same( false, $guard->maybe_migrate_title_map(), 'A missing legacy source must remain retryable.' );
lunara_test_assert( ! array_key_exists( Lunara_IMDb_Guard::OPTION_TITLE_MAP_MIGRATION, $GLOBALS['lunara_test_options'] ), 'Missing legacy storage must not mark migration complete.' );

// A current marker without its canonical map is incomplete and retries the legacy source.
lunara_test_reset();
$root = lunara_test_write_legacy( '{}' );
$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP_MIGRATION ] = Lunara_IMDb_Guard::TITLE_MAP_SCHEMA_VERSION;
lunara_test_assert_same( true, $guard->maybe_migrate_title_map(), 'A marker without a map must retry migration.' );
lunara_test_assert_same( array(), lunara_imdb_guard_get_title_map(), 'An empty legacy object must become authoritative empty storage.' );
lunara_test_remove_tree( $root );

// An add race loser validates the winner and never overwrites it.
lunara_test_reset();
$root = lunara_test_write_legacy( '{"Legacy|1999":"tt1234567"}' );
$race_winner = array( 'winner|2024' => 'tt7654321' );
$GLOBALS['lunara_test_add_behaviors'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP ] = static function ( string $option ) use ( $race_winner ): bool {
	$GLOBALS['lunara_test_options'][ $option ] = $race_winner;
	return false;
};
lunara_test_assert_same( true, $guard->maybe_migrate_title_map(), 'A race loser should accept a valid canonical winner.' );
lunara_test_assert_same( $race_winner, lunara_imdb_guard_get_title_map(), 'A race loser must not overwrite canonical winner data.' );
lunara_test_remove_tree( $root );

// Completion is forbidden when canonical readback is not byte-for-byte normalized input.
lunara_test_reset();
$root = lunara_test_write_legacy( '{"Legacy|1999":"tt1234567"}' );
$GLOBALS['lunara_test_add_behaviors'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP ] = static function ( string $option ): bool {
	$GLOBALS['lunara_test_options'][ $option ] = array( 'changed|2000' => 'tt7654321' );
	return true;
};
lunara_test_assert_same( false, $guard->maybe_migrate_title_map(), 'Mismatched readback must fail migration.' );
lunara_test_assert( ! array_key_exists( Lunara_IMDb_Guard::OPTION_TITLE_MAP_MIGRATION, $GLOBALS['lunara_test_options'] ), 'Mismatched readback must not create a marker.' );
lunara_test_remove_tree( $root );

// Lookup uses canonical normalized title/year keys and honors nullable-vs-empty semantics.
lunara_test_reset();
$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP ] = array(
	'amelie|2001' => 'tt0211915',
	'zodiac|2007' => 'tt0443706',
);
lunara_test_assert_same( 'tt0211915', lunara_imdb_guard_lookup_title_id( ' Amélie ', '2001' ), 'Lookup must use canonical title normalization.' );
lunara_test_assert_same( '', lunara_imdb_guard_lookup_title_id( 'Amélie', '2002' ), 'Lookup must not cross years.' );
lunara_test_assert_same( '', lunara_imdb_guard_lookup_title_id( array( 'Amélie' ), '2001' ), 'Lookup must fail closed for non-scalar input.' );

// Canonical mutation preserves existing entries, refuses partial seeding, and never writes the theme file.
$root = lunara_test_write_legacy( '{"Legacy Theme|1990":"tt1111111"}' );
$theme_file_before = file_get_contents( $root . '/assets/data/imdb-title-map.json' );
$GLOBALS['lunara_test_stylesheet_reads'] = 0;
lunara_test_invoke_private( $guard, 'sync_title_map_entry', array( 'New Film', '2024', 'TT7654321' ) );
lunara_test_assert_same(
	array(
		'amelie|2001'  => 'tt0211915',
		'new film|2024'=> 'tt7654321',
		'zodiac|2007'  => 'tt0443706',
	),
	lunara_imdb_guard_get_title_map(),
	'Canonical mutation must preserve the full prior map while adding a normalized entry.'
);
lunara_test_assert_same( $theme_file_before, file_get_contents( $root . '/assets/data/imdb-title-map.json' ), 'Canonical mutation must never write the legacy theme file.' );
lunara_test_assert_same( 0, $GLOBALS['lunara_test_stylesheet_reads'], 'Canonical mutation and public reads must not resolve theme paths.' );

// A concurrent canonical update that lands before lock acquisition is re-read and preserved.
lunara_test_reset();
$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP ] = array( 'first|2020' => 'tt1111111' );
$GLOBALS['lunara_test_add_behaviors'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP_LOCK ] = static function ( string $option, $token ): bool {
	$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP ]['racer|2023'] = 'tt2222222';
	$GLOBALS['lunara_test_options'][ $option ] = $token;
	return true;
};
lunara_test_assert_same( true, lunara_test_invoke_private( $guard, 'sync_title_map_entry', array( 'Second', '2024', 'tt3333333' ) ), 'Locked mutation should verify its canonical readback.' );
lunara_test_assert_same(
	array(
		'first|2020' => 'tt1111111',
		'racer|2023' => 'tt2222222',
		'second|2024'=> 'tt3333333',
	),
	lunara_imdb_guard_get_title_map(),
	'Locked mutation must re-read and preserve a concurrent canonical update.'
);

// A live lock loser retries; once the owner merges and releases, the loser preserves every sibling.
lunara_test_reset();
$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP ] = array( 'first|2020' => 'tt1111111' );
$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP_LOCK ] = array(
	'token'       => str_repeat( 'a', 32 ),
	'acquired_at' => time(),
	'expires_at'  => time() + 30,
);
$live_attempts = 0;
$GLOBALS['lunara_test_add_observers'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP_LOCK ] = static function () use ( &$live_attempts ): void {
	$live_attempts++;
	if ( 2 === $live_attempts ) {
		$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP ]['owner|2023'] = 'tt2222222';
		unset( $GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP_LOCK ] );
	}
};
lunara_test_assert_same( true, lunara_test_invoke_private( $guard, 'sync_title_map_entry', array( 'Loser', '2024', 'tt3333333' ) ), 'A live lock loser must retry and merge after the owner releases.' );
lunara_test_assert( $live_attempts >= 2, 'Live contention must make more than one bounded acquisition attempt.' );
lunara_test_assert_same(
	array(
		'first|2020' => 'tt1111111',
		'loser|2024' => 'tt3333333',
		'owner|2023' => 'tt2222222',
	),
	lunara_imdb_guard_get_title_map(),
	'The losing writer must re-read and preserve the live owner\'s sibling entry.'
);
$lock_adds = array_values(
	array_filter(
		$GLOBALS['lunara_test_option_mutations'],
		static fn( array $mutation ): bool => 'add' === ( $mutation['operation'] ?? '' ) && Lunara_IMDb_Guard::OPTION_TITLE_MAP_LOCK === ( $mutation['option'] ?? '' )
	)
);
$created_lock = $lock_adds[ count( $lock_adds ) - 1 ] ?? array();
lunara_test_assert_same( false, $created_lock['autoload'] ?? null, 'Every mutation lock acquisition must remain non-autoloaded.' );
lunara_test_assert_same( array( 'token', 'acquired_at', 'expires_at' ), array_keys( $created_lock['value'] ?? array() ), 'A mutation lock must contain only the strict owner token and timestamps.' );
lunara_test_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', $created_lock['value']['token'] ?? '' ), 'A mutation lock token must use the strict opaque format.' );
lunara_test_assert( is_int( $created_lock['value']['acquired_at'] ?? null ) && is_int( $created_lock['value']['expires_at'] ?? null ), 'Mutation lock timestamps must be integers.' );
lunara_test_assert( $created_lock['value']['expires_at'] > $created_lock['value']['acquired_at'], 'Mutation lock expiry must be later than acquisition.' );

// An expired strict lock is reclaimed with a conditional compare-and-delete before retry.
lunara_test_reset();
$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP ] = array( 'first|2020' => 'tt1111111' );
$expired_lock = array(
	'token'       => str_repeat( 'b', 32 ),
	'acquired_at' => time() - 30,
	'expires_at'  => time() - 1,
);
$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP_LOCK ] = $expired_lock;
lunara_test_assert_same( true, lunara_test_invoke_private( $guard, 'sync_title_map_entry', array( 'Recovered', '2024', 'tt4444444' ) ), 'An expired lock must be safely reclaimed and the pending entry merged.' );
lunara_test_assert_same( 'tt4444444', lunara_imdb_guard_lookup_title_id( 'Recovered', '2024' ), 'Stale recovery must preserve exact canonical readback.' );
lunara_test_assert( count( $GLOBALS['wpdb']->conditional_deletes ) >= 2, 'Stale recovery and owner release must both use conditional database deletes.' );
lunara_test_assert_same( maybe_serialize( $expired_lock ), $GLOBALS['wpdb']->conditional_deletes[0][1]['option_value'] ?? '', 'Stale recovery must compare the exact observed lock record.' );

// Releasing one owner can never delete a replacement owner's live lock.
lunara_test_reset();
$original_lock = array(
	'token'       => str_repeat( 'c', 32 ),
	'acquired_at' => time(),
	'expires_at'  => time() + 5,
);
$replacement_lock = array(
	'token'       => str_repeat( 'd', 32 ),
	'acquired_at' => time() + 1,
	'expires_at'  => time() + 6,
);
$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP_LOCK ] = $replacement_lock;
lunara_test_assert_same( false, lunara_test_invoke_private( $guard, 'release_title_map_lock', array( $original_lock ) ), 'A former owner must not release a replacement owner\'s lock.' );
lunara_test_assert_same( $replacement_lock, $GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP_LOCK ], 'Owner-token-only release must preserve the replacement lock exactly.' );

// Terminal contention is surfaced by both canonical sync outcomes in validation.
foreach ( array( 'verified' => false, 'autofilled' => true ) as $label => $auto_fill ) {
	lunara_test_reset();
	$post_id = 'verified' === $label ? 90 : 91;
	$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP ] = array( 'existing|2020' => 'tt1111111' );
	$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP_LOCK ] = array(
		'token'       => str_repeat( 'e', 32 ),
		'acquired_at' => time(),
		'expires_at'  => time() + 30,
	);
	$GLOBALS['lunara_test_titles'][ $post_id ] = 'Blocked Film';
	$GLOBALS['lunara_test_meta'][ $post_id ] = array(
		'_lunara_year'          => '2024',
		'_lunara_imdb_title_id' => $auto_fill ? '' : 'tt5555555',
	);
	$GLOBALS['lunara_test_transients'][ 'lunara_imdb_guard_v2_' . md5( 'blocked film|2024' ) ] = array(
		'status'  => 'ok',
		'title'   => 'Blocked Film',
		'year'    => '2024',
		'imdb_id' => 'tt5555555',
	);
	$validation = lunara_test_invoke_private( $guard, 'validate_review', array( $post_id, $auto_fill ) );
	lunara_test_assert_same( 'error', $validation['status'], 'Terminal lock contention must not report ' . $label . ' canonical sync as successful.' );
	lunara_test_assert_same( 'error', $GLOBALS['lunara_test_meta'][ $post_id ][ Lunara_IMDb_Guard::META_STATUS ] ?? '', 'Terminal contention must persist a visible error for the ' . $label . ' call site.' );
}

lunara_test_reset();
$GLOBALS['lunara_test_stylesheet_directory'] = $root;
lunara_test_invoke_private( $guard, 'sync_title_map_entry', array( 'Partial Seed', '2025', 'tt1234567' ) );
lunara_test_assert( ! array_key_exists( Lunara_IMDb_Guard::OPTION_TITLE_MAP, $GLOBALS['lunara_test_options'] ), 'A save before migration must not create a partial canonical seed.' );
lunara_test_assert_same( 0, $GLOBALS['lunara_test_stylesheet_reads'], 'A pre-migration mutation must not perform fallback file I/O.' );
lunara_test_remove_tree( $root );

// Canonical destination and capability are stable, exact, and class-backed.
lunara_test_assert_same( 'https://example.test/wp-admin/edit.php?post_type=review&page=lunara-imdb-guard', lunara_imdb_guard_admin_url(), 'Admin URL must use the canonical IMDb Guard destination.' );
lunara_test_assert_same( 'edit_posts', lunara_imdb_guard_admin_capability(), 'Admin capability must remain edit_posts.' );
lunara_test_assert_same( lunara_imdb_guard_admin_url(), $guard->admin_url(), 'The URL wrapper must delegate to the class method.' );
lunara_test_assert_same( lunara_imdb_guard_admin_capability(), $guard->admin_capability(), 'The capability wrapper must delegate to the class method.' );

// Explicit owner refresh performs one bounded projection and creates a non-autoloaded compact snapshot.
lunara_test_reset();
$GLOBALS['lunara_test_review_ids'] = array( 11, 12, 13 );
$GLOBALS['lunara_test_meta'] = array(
	11 => array(
		'_lunara_imdb_title_id'        => 'tt1111111',
		'_lunara_imdb_guard_status'    => 'verified',
		'_lunara_tmdb_poster_url'      => 'https://image.test/poster-11.jpg',
		'_lunara_tmdb_backdrop_url'    => 'https://image.test/backdrop-11.jpg',
	),
	12 => array(
		'_lunara_imdb_title_id'        => 'tt2222222',
		'_lunara_imdb_guard_status'    => 'mismatch',
		'_lunara_tmdb_poster_url'      => 'https://image.test/poster-12.jpg',
		'_lunara_tmdb_backdrop_url'    => '',
	),
	13 => array(
		'_lunara_imdb_title_id'        => '',
		'_lunara_imdb_guard_status'    => 'unchecked',
		'_lunara_tmdb_poster_url'      => '',
		'_lunara_tmdb_backdrop_url'    => '',
	),
);
lunara_test_assert_same( true, $guard->refresh_health_snapshot(), 'Explicit health refresh should persist a compact projection.' );
lunara_test_assert_same( 1, count( $GLOBALS['lunara_test_get_posts_calls'] ), 'Health refresh should perform exactly one Review query.' );
lunara_test_assert_same( 1000, $GLOBALS['lunara_test_get_posts_calls'][0]['posts_per_page'] ?? null, 'Health refresh must keep the existing scan bounded to 1,000 Reviews.' );
$snapshot_adds = array_values(
	array_filter(
		$GLOBALS['lunara_test_option_mutations'],
		static fn( array $mutation ): bool => ( $mutation['option'] ?? '' ) === Lunara_IMDb_Guard::OPTION_HEALTH_SNAPSHOT
	)
);
lunara_test_assert_same( 1, count( $snapshot_adds ), 'First health refresh must create exactly one snapshot option.' );
lunara_test_assert_same( false, $snapshot_adds[0]['autoload'] ?? null, 'Health snapshot storage must be non-autoloaded.' );

// Ordinary status reads only allowlisted snapshot aggregates, key presence, and canonical-map state.
$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TITLE_MAP ] = array( 'film|2024' => 'tt1111111' );
$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_API_KEY ] = 'OMDB-SUPER-SECRET';
$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_TMDB_API_KEY ] = 'TMDB-SUPER-SECRET';
$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_HEALTH_SNAPSHOT ]['secret'] = 'SNAPSHOT-SUPER-SECRET';
$GLOBALS['lunara_test_options'][ Lunara_IMDb_Guard::OPTION_HEALTH_SNAPSHOT ]['identity']['title'] = 'PRIVATE REVIEW TITLE';
$GLOBALS['lunara_test_get_posts_calls']  = array();
$GLOBALS['lunara_test_stylesheet_reads'] = 0;
$status = lunara_imdb_guard_health_status();
$expected_status = array(
	'state'       => 'needs_attention',
	'identity'    => array(
		'state'           => 'needs_attention',
		'total'           => 3,
		'with_imdb_id'    => 2,
		'missing_imdb_id' => 1,
		'needs_review'    => 2,
	),
	'artwork'     => array(
		'state'    => 'needs_attention',
		'eligible' => 2,
		'complete' => 1,
		'missing'  => 1,
	),
	'title_map'  => array(
		'state'   => 'ready',
		'entries' => 1,
	),
	'credentials'=> array(
		'omdb_configured' => true,
		'tmdb_configured' => true,
	),
);
lunara_test_assert_same( $expected_status, $status, 'Health status must expose only the exact redacted aggregate schema.' );
lunara_test_assert_same( array(), $GLOBALS['lunara_test_get_posts_calls'], 'Ordinary health reads must never scan Reviews.' );
lunara_test_assert_same( 0, $GLOBALS['lunara_test_stylesheet_reads'], 'Ordinary health reads must never inspect theme files.' );
$serialized_status = json_encode( $status );
foreach ( array( 'OMDB-SUPER-SECRET', 'TMDB-SUPER-SECRET', 'SNAPSHOT-SUPER-SECRET', 'PRIVATE REVIEW TITLE', 'option', 'path', 'url', 'message', 'source' ) as $forbidden ) {
	lunara_test_assert( false === stripos( $serialized_status, $forbidden ), 'Redacted health leaked forbidden data: ' . $forbidden );
}

// Missing/corrupt snapshots remain unknown and need attention; they never become healthy zeroes.
lunara_test_reset();
$unknown_status = lunara_imdb_guard_health_status();
lunara_test_assert_same( 'needs_attention', $unknown_status['state'], 'Unknown status must not claim health.' );
lunara_test_assert_same( 'unknown', $unknown_status['identity']['state'], 'Missing identity aggregates must remain unknown.' );
lunara_test_assert_same( null, $unknown_status['identity']['total'], 'Missing identity totals must remain NULL rather than zero.' );
lunara_test_assert_same( 'unknown', $unknown_status['artwork']['state'], 'Missing artwork aggregates must remain unknown.' );
lunara_test_assert_same( null, $unknown_status['artwork']['eligible'], 'Missing artwork totals must remain NULL rather than zero.' );
lunara_test_assert_same( 'missing', $unknown_status['title_map']['state'], 'Missing canonical storage must be explicit.' );
lunara_test_assert_same( null, $unknown_status['title_map']['entries'], 'Missing canonical storage must not report zero entries.' );
lunara_test_assert_same( false, $unknown_status['credentials']['omdb_configured'], 'Missing OMDb credentials must be a boolean only.' );
lunara_test_assert_same( false, $unknown_status['credentials']['tmdb_configured'], 'Missing TMDB credentials must be a boolean only.' );
lunara_test_assert_same( array(), $GLOBALS['lunara_test_get_posts_calls'], 'Unknown ordinary status must not recompute aggregates.' );

lunara_test_assert_same( '0.4.2', LUNARA_IMDB_GUARD_VERSION, 'Runtime release identity must be 0.4.2.' );
$plugin_source = file_get_contents( dirname( __DIR__ ) . '/lunara-imdb-guard.php' );
lunara_test_assert( false !== strpos( $plugin_source, 'Version: 0.4.2' ), 'Plugin header release identity must be 0.4.2.' );
$readme = file_get_contents( dirname( __DIR__ ) . '/README.md' );
lunara_test_assert( false !== strpos( $readme, 'Current baseline: `0.4.2`.' ), 'README release identity must be 0.4.2.' );

fwrite( STDOUT, "Site Studio persistence contracts passed.\n" );
