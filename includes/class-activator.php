<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Article\Retention_Cleaner;
use Tonkatsutei\Gachi_Review_AI\Article\Source_Settings;
use Tonkatsutei\Gachi_Review_AI\Common\Store as Common_Store;
use Tonkatsutei\Gachi_Review_AI\Ec\Genre_Catalog;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Activator {
	public static function activate( bool $network_wide = false ): void {
		self::check_environment();
		self::migrate_legacy_schema();
		self::migrate_source_schema();
		self::create_tables();
		self::migrate_genre_schema();
		self::migrate_genre_reset();
		self::migrate_reuse_units_schema();
		self::migrate_product_exclude_schema();
		self::migrate_drop_keyword_stock();
		self::set_default_options();
		Retention_Cleaner::schedule();
	}
	public static function activate_new_site( \WP_Site $new_site ): void {
		if ( ! self::is_network_active() ) {
			return;
		}
		$blog_id = isset( $new_site->blog_id ) ? (int) $new_site->blog_id : 0;
		if ( $blog_id <= 0 ) {
			return;
		}
		$switched = false;
		if ( get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		try {
			self::migrate_legacy_schema();
			self::migrate_source_schema();
			self::create_tables();
			self::migrate_genre_schema();
			self::migrate_genre_reset();
			self::migrate_reuse_units_schema();
			self::migrate_product_exclude_schema();
			self::migrate_drop_keyword_stock();
			self::set_default_options();
			Retention_Cleaner::schedule();
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
	private static function is_network_active(): bool {
		if ( ! is_multisite() ) {
			return false;
		}
		$plugin = plugin_basename( GACHIREAI_PLUGIN_FILE );
		if ( function_exists( 'is_plugin_active_for_network' ) ) {
			return is_plugin_active_for_network( $plugin );
		}
		$active = get_site_option( 'active_sitewide_plugins', array() );
		return is_array( $active ) && isset( $active[ $plugin ] );
	}
	private const GENRE_RESET_VERSION = '1.5.0';
	private const EC_SCOPE_MIGRATED_OPTION = 'gachireai_migrated_ec_to_scope';
	private const GENRE_PARENT_MIGRATED_OPTION = 'gachireai_migrated_genre_parent';
	private const REVIEW_DEFAULTS_MIGRATED_OPTION = 'gachireai_migrated_review_defaults';
	private const REVIEW_COMMON_MIGRATED_OPTION = 'gachireai_migrated_review_common';
	private const REVIEW_SOURCES_MIGRATED_OPTION = 'gachireai_migrated_review_sources';
	private const VERIFIED_RECORDS_MIGRATED_OPTION = 'gachireai_migrated_verified_records';
	private const REVIEW_SOURCE_GROUPS = array( 'reddit', 'fivech', 'youtube', 'hackernews', 'mastodon' );
	private const EC_SCOPE_MAP = array(
		'category' => 'category',
		'keywords' => 'products',
	);
	public static function maybe_upgrade(): void {
		if ( get_option( 'gachireai_db_version' ) === GACHIREAI_DB_VERSION ) {
			return;
		}
		self::migrate_legacy_schema();
		self::migrate_source_schema();
		self::create_tables();
		self::migrate_genre_schema();
		self::migrate_genre_reset();
		self::migrate_reuse_units_schema();
		self::migrate_product_exclude_schema();
		self::migrate_drop_keyword_stock();
		self::set_default_options();
	}
	public static function migrate_ec_to_scope(): void {
		if ( '1' === get_option( self::EC_SCOPE_MIGRATED_OPTION ) ) {
			return;
		}
		$settings = new Settings();
		$ec       = (array) $settings->get( 'ec', array() );
		$profile = new Site_Profile();
		$scope   = $profile->get_scope();
		$moved   = false;
		foreach ( self::EC_SCOPE_MAP as $from => $to ) {
			$value = trim( (string) ( $ec[ $from ] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			$scope[ $to ] = $value;
			$moved        = true;
		}
		$removed = array_key_exists( 'category', $ec ) || array_key_exists( 'keywords', $ec );
		unset( $ec['category'], $ec['keywords'] );
		if ( $moved ) {
			$profile->update_field( 'scope', $scope );
		}
		if ( $removed ) {
			$settings->set( 'ec', $ec );
		}
		update_option( self::EC_SCOPE_MIGRATED_OPTION, '1' );
	}
	public static function migrate_genre_parent(): void {
		if ( '1' === get_option( self::GENRE_PARENT_MIGRATED_OPTION ) ) {
			return;
		}
		global $wpdb;
		$table = self::table( 'genres' );
		if ( ! in_array( $table, self::existing_tables(), true ) ) {
			update_option( self::GENRE_PARENT_MIGRATED_OPTION, '1' );
			return;
		}
		foreach ( array( Product_Selector::EC_RAKUTEN, Product_Selector::EC_YAHOO ) as $ec ) {
			$root = Genre_Catalog::root_id( (string) $ec );
			if ( '' === $root ) {
				continue;
			}
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET parent_id = '' WHERE ec = %s AND parent_id = %s AND level >= 1",
					(string) $ec,
					$root
				)
			);
		}
		update_option( self::GENRE_PARENT_MIGRATED_OPTION, '1' );
	}
	public static function migrate_review_source_defaults(): void {
		if ( '1' === get_option( self::REVIEW_DEFAULTS_MIGRATED_OPTION ) ) {
			return;
		}
		$settings = new Settings();
		foreach ( self::REVIEW_SOURCE_GROUPS as $group ) {
			foreach ( array( 'period', 'retention', 'credit', 'show_credit' ) as $key ) {
				$settings->remove( $group . '.' . $key );
			}
		}
		update_option( self::REVIEW_DEFAULTS_MIGRATED_OPTION, '1' );
	}
	public static function migrate_review_common_settings(): void {
		if ( '1' === get_option( self::REVIEW_COMMON_MIGRATED_OPTION ) ) {
			return;
		}
		$settings = new Settings();
		$carry    = array();
		foreach ( self::REVIEW_SOURCE_GROUPS as $group ) {
			$values = $settings->get( $group, array() );
			$values = is_array( $values ) ? $values : array();
			foreach ( array( 'retention', 'credit' ) as $key ) {
				$value = trim( (string) ( $values[ $key ] ?? '' ) );
				if ( '' === $value ) {
					continue;
				}
				if ( 'retention' === $key && 'permanent' === $value ) {
					$value = (string) Source_Settings::DEFAULT_RETENTION_DAYS;
				}
				if ( ! isset( $carry[ $key ] ) ) {
					$carry[ $key ] = $value;
				}
			}
			$settings->remove( $group . '.retention' );
			$settings->remove( $group . '.credit' );
		}
		if ( array() !== $carry ) {
			$current = $settings->get( Source_Settings::GROUP, array() );
			$settings->set( Source_Settings::GROUP, array_merge( $carry, is_array( $current ) ? $current : array() ) );
		}
		update_option( self::REVIEW_COMMON_MIGRATED_OPTION, '1' );
	}
	public static function migrate_review_sources(): void {
		if ( '1' === get_option( self::REVIEW_SOURCES_MIGRATED_OPTION ) ) {
			return;
		}
		$settings = new Settings();
		$partner  = $settings->get( Source_Settings::PARTNER_GROUP, array() );
		$partner  = is_array( $partner ) ? $partner : array();
		if ( '' === trim( (string) ( $partner[ Source_Settings::PARTNER_GACHI_KEY ] ?? '' ) ) ) {
			$enabled = array();
			foreach ( Source_Settings::DIRECT_SOURCES as $source ) {
				$values = $settings->get( $source, array() );
				$values = is_array( $values ) ? $values : array();
				if ( '1' === trim( (string) ( $values[ 'enabled' ] ?? '' ) ) ) {
					$enabled[] = $source;
				}
			}
			if ( array() !== $enabled ) {
				$partner[ Source_Settings::PARTNER_GACHI_KEY ] = implode( ',', $enabled );
				$settings->set( Source_Settings::PARTNER_GROUP, $partner );
			}
		}
		update_option( self::REVIEW_SOURCES_MIGRATED_OPTION, '1' );
	}
	public static function migrate_common_overrides(): void {
		$settings = new Settings();
		$all      = $settings->all();
		if ( array_key_exists( 'common_overrides', $all ) ) {
			return;
		}
		$settings->set( 'common_overrides', $settings->common_overrides() );
	}
	public static function migrate_verified_records(): void {
		if ( '1' === get_option( self::VERIFIED_RECORDS_MIGRATED_OPTION ) ) {
			return;
		}
		$site = new Settings();
		foreach ( self::verified_groups() as $group => $has_key ) {
			if ( $has_key( $site ) ) {
				Connection_Status::mark_verified( $group, $site );
			}
		}
		if ( is_multisite() && Multisite::is_main_site() ) {
			$common = new Common_Store();
			foreach ( self::verified_groups() as $group => $has_key ) {
				if ( $has_key( $common ) ) {
					Connection_Status::mark_verified( $group, $common );
				}
			}
		}
		update_option( self::VERIFIED_RECORDS_MIGRATED_OPTION, '1' );
	}
	private static function verified_groups(): array {
		return array(
			'llm'   => static function ( Settings $settings ): bool {
				foreach ( $settings->llm_entries() as $entry ) {
					if ( '' !== trim( (string) ( $entry['api_key'] ?? '' ) ) ) {
						return true;
					}
				}
				return false;
			},
			'jev'   => static function ( Settings $settings ): bool {
				$jev = $settings->jev();
				return '' !== trim( (string) ( $jev['api_key'] ?? '' ) );
			},
			'image' => static function ( Settings $settings ): bool {
				return '' !== $settings->image_api_key( $settings->image() );
			},
		);
	}
	public static function table_names(): array {
		return array( 'threads', 'comments', 'events', 'candidates', 'genres', 'products', 'prices' );
	}
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'gachireai_' . $name;
	}
	public static function tables(): array {
		$tables = array();
		foreach ( self::table_names() as $name ) {
			$tables[] = self::table( $name );
		}
		return $tables;
	}
	public static function migrate_legacy_schema(): void {
		global $wpdb;
		$existing = self::existing_tables();
		foreach ( self::table_names() as $name ) {
			$legacy  = $wpdb->prefix . 'gachi_' . $name;
			$current = self::table( $name );
			if ( ! in_array( $legacy, $existing, true ) || in_array( $current, $existing, true ) ) {
				continue;
			}
			$wpdb->query( "RENAME TABLE `{$legacy}` TO `{$current}`" );
		}
		$legacy_options = array(
			'gachi_site_profile' => 'gachireai_site_profile',
			'gachi_db_version'   => 'gachireai_db_version',
		);
		foreach ( $legacy_options as $old => $new ) {
			$value = get_option( $old, null );
			if ( null === $value ) {
				continue;
			}
			if ( false === get_option( $new, false ) ) {
				update_option( $new, $value );
			}
			delete_option( $old );
		}
		delete_transient( 'gachi_reddit_rl_last' );
	}
	public static function migrate_source_schema(): void {
		global $wpdb;
		$threads  = self::table( 'threads' );
		$comments = self::table( 'comments' );
		if ( self::column_exists( $threads, 'reddit_id' ) && ! self::column_exists( $threads, 'external_id' ) ) {
			$wpdb->query(
				"ALTER TABLE `{$threads}`
					ADD COLUMN `source` VARCHAR(16) NOT NULL DEFAULT 'reddit' AFTER `id`,
					CHANGE COLUMN `reddit_id` `external_id` VARCHAR(64) NOT NULL,
					CHANGE COLUMN `subreddit` `community` VARCHAR(64) NOT NULL,
					DROP INDEX `reddit_id`,
					DROP INDEX `subreddit`,
					ADD UNIQUE KEY `source_external_id` (`source`, `external_id`),
					ADD KEY `source` (`source`),
					ADD KEY `community` (`community`)"
			);
		}
		if ( self::column_exists( $comments, 'reddit_id' ) && ! self::column_exists( $comments, 'external_id' ) ) {
			$wpdb->query(
				"ALTER TABLE `{$comments}`
					ADD COLUMN `source` VARCHAR(16) NOT NULL DEFAULT 'reddit' AFTER `id`,
					CHANGE COLUMN `reddit_id` `external_id` VARCHAR(64) NOT NULL,
					DROP INDEX `reddit_id`,
					ADD UNIQUE KEY `source_external_id` (`source`, `external_id`),
					ADD KEY `source` (`source`)"
			);
		}
	}
	public static function migrate_genre_schema(): void {
		global $wpdb;
		$table = self::table( 'genres' );
		$nullable = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				'fetched_at'
			)
		);
		if ( null === $nullable || 'YES' === (string) $nullable ) {
			return;
		}
		$wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `fetched_at` DATETIME NULL DEFAULT NULL" );
	}
	public static function migrate_genre_reset(): void {
		$version = (string) get_option( 'gachireai_db_version', '' );
		if ( '' !== $version && version_compare( $version, self::GENRE_RESET_VERSION, '>=' ) ) {
			return;
		}
		global $wpdb;
		$table = self::table( 'genres' );
		if ( ! in_array( $table, self::existing_tables(), true ) ) {
			return;
		}
		$wpdb->query( "DELETE FROM `{$table}`" );
	}
	public static function migrate_reuse_units_schema(): void {
		global $wpdb;
		$products = self::table( 'products' );
		$genres   = self::table( 'genres' );
		if ( ! self::column_exists( $products, 'genre_id' ) ) {
			$wpdb->query( "ALTER TABLE `{$products}` ADD COLUMN `genre_id` VARCHAR(32) NOT NULL DEFAULT '' AFTER `keyword`, ADD KEY `genre_id` (`genre_id`)" );
		}
		if ( ! self::column_exists( $genres, 'excluded' ) ) {
			$wpdb->query( "ALTER TABLE `{$genres}` ADD COLUMN `excluded` TINYINT(1) NOT NULL DEFAULT 0 AFTER `level`" );
		}
	}
	public static function migrate_product_exclude_schema(): void {
		global $wpdb;
		$products = self::table( 'products' );
		if ( ! self::column_exists( $products, 'excluded' ) ) {
			$wpdb->query( "ALTER TABLE `{$products}` ADD COLUMN `excluded` TINYINT(1) NOT NULL DEFAULT 0 AFTER `post_id`" );
		}
	}
	public static function migrate_drop_keyword_stock(): void {
		global $wpdb;
		$table = self::table( 'keywords' );
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}
	private static function column_exists( string $table, string $column ): bool {
		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				$column
			)
		);
		return null !== $found;
	}
	private static function existing_tables(): array {
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		return is_array( $tables ) ? $tables : array();
	}
	public static function check_environment(): void {
		if ( version_compare( PHP_VERSION, '8.0.0', '<' ) ) {
			deactivate_plugins( plugin_basename( GACHIREAI_PLUGIN_FILE ) );
			wp_die(
				esc_html__( 'gachi-review-ai を利用するには PHP 8.0 以上が必要です。', GACHIREAI_TEXT_DOMAIN ),
				'',
				array( 'back_link' => true )
			);
		}
	}
	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;
		$sql_threads = "CREATE TABLE {$prefix}gachireai_threads (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(16) NOT NULL DEFAULT 'reddit',
			external_id VARCHAR(64) NOT NULL,
			job_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			community VARCHAR(64) NOT NULL,
			title TEXT NOT NULL,
			product_name VARCHAR(255) NOT NULL DEFAULT '',
			url TEXT NOT NULL,
			permalink TEXT NOT NULL,
			score INT NOT NULL DEFAULT 0,
			num_comments INT NOT NULL DEFAULT 0,
			created_utc INT UNSIGNED NOT NULL DEFAULT 0,
			collected_at DATETIME NOT NULL,
			judge JSON NULL DEFAULT NULL,
			rating DECIMAL(3,2) NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_external_id (source,external_id),
			KEY source (source),
			KEY job_id (job_id),
			KEY community (community)
		) {$charset_collate};";
		$sql_comments = "CREATE TABLE {$prefix}gachireai_comments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(16) NOT NULL DEFAULT 'reddit',
			external_id VARCHAR(64) NOT NULL,
			thread_id BIGINT UNSIGNED NOT NULL,
			body LONGTEXT NOT NULL,
			score INT NOT NULL DEFAULT 0,
			author VARCHAR(64) NOT NULL DEFAULT '',
			permalink TEXT NOT NULL,
			created_utc INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY source_external_id (source,external_id),
			KEY source (source),
			KEY thread_id (thread_id)
		) {$charset_collate};";
		$sql_events = "CREATE TABLE {$prefix}gachireai_events (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			type VARCHAR(32) NOT NULL DEFAULT '',
			level VARCHAR(8) NOT NULL DEFAULT 'info',
			message TEXT NOT NULL,
			payload JSON NULL,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			elapsed_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY job_id (job_id)
		) {$charset_collate};";
		$sql_candidates = "CREATE TABLE {$prefix}gachireai_candidates (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_name TEXT NOT NULL,
			brand VARCHAR(191) NOT NULL DEFAULT '',
			model_number VARCHAR(191) NOT NULL DEFAULT '',
			provider VARCHAR(32) NOT NULL DEFAULT '',
			status VARCHAR(32) NOT NULL DEFAULT '',
			item_code VARCHAR(64) NOT NULL DEFAULT '',
			item_name TEXT NOT NULL,
			item_price INT NOT NULL DEFAULT 0,
			item_url TEXT NOT NULL,
			affiliate_url TEXT NOT NULL,
			image_url TEXT NOT NULL,
			search_url TEXT NOT NULL,
			reason TEXT NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY status (status)
		) {$charset_collate};";
		$sql_genres = "CREATE TABLE {$prefix}gachireai_genres (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ec VARCHAR(16) NOT NULL DEFAULT '',
			parent_id VARCHAR(32) NOT NULL DEFAULT '',
			genre_id VARCHAR(32) NOT NULL DEFAULT '',
			name VARCHAR(191) NOT NULL DEFAULT '',
			level INT NOT NULL DEFAULT 0,
			excluded TINYINT(1) NOT NULL DEFAULT 0,
			fetched_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ec_genre_id (ec,genre_id),
			KEY ec_parent_id (ec,parent_id),
			KEY fetched_at (fetched_at)
		) {$charset_collate};";
		$sql_products = "CREATE TABLE {$prefix}gachireai_products (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ec VARCHAR(16) NOT NULL DEFAULT '',
			item_code VARCHAR(100) NOT NULL DEFAULT '',
			jan_code VARCHAR(32) NOT NULL DEFAULT '',
			name_key VARCHAR(191) NOT NULL DEFAULT '',
			product_name TEXT NOT NULL,
			brand VARCHAR(191) NOT NULL DEFAULT '',
			model_number VARCHAR(191) NOT NULL DEFAULT '',
			item_price INT NOT NULL DEFAULT 0,
			item_url TEXT NOT NULL,
			image_url TEXT NOT NULL,
			keyword VARCHAR(100) NOT NULL DEFAULT '',
			genre_id VARCHAR(32) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'selected',
			first_seen_at DATETIME NOT NULL,
			last_posted_at DATETIME NULL DEFAULT NULL,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			excluded TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY ec_item_code (ec,item_code),
			KEY jan_code (jan_code),
			KEY name_key (name_key),
			KEY model_number (model_number),
			KEY genre_id (genre_id),
			KEY last_posted_at (last_posted_at)
		) {$charset_collate};";
		$sql_prices = "CREATE TABLE {$prefix}gachireai_prices (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ec VARCHAR(16) NOT NULL DEFAULT '',
			item_code VARCHAR(100) NOT NULL DEFAULT '',
			item_price INT NOT NULL DEFAULT 0,
			status VARCHAR(32) NOT NULL DEFAULT '',
			message TEXT NOT NULL,
			fetched_at DATETIME NULL DEFAULT NULL,
			checked_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ec_item_code (ec,item_code),
			KEY checked_at (checked_at)
		) {$charset_collate};";
		dbDelta( $sql_threads );
		dbDelta( $sql_comments );
		dbDelta( $sql_events );
		dbDelta( $sql_candidates );
		dbDelta( $sql_genres );
		dbDelta( $sql_products );
		dbDelta( $sql_prices );
		update_option( 'gachireai_db_version', GACHIREAI_DB_VERSION );
	}
	public static function set_default_options(): void {
		if ( false === get_option( 'gachireai_site_profile' ) ) {
			add_option( 'gachireai_site_profile', array() );
		}
	}
}
