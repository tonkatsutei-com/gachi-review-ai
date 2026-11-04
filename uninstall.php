<?php
declare(strict_types=1);
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}
global $wpdb;
$options = array(
	'gachireai_site_profile',
	'gachireai_db_version',
	'gachireai_settings',
	'gachireai_migrated_ec_to_scope',
	'gachireai_auto_post_last',
	'gachireai_auto_pipeline',
	'gachireai_font_custom',
	'gachireai_policy_page_seeded',
	'gachireai_policy_page_id',
	'gachireai_policy_page_created',
	'gachi_site_profile',
	'gachi_db_version',
);
$table_suffixes = array(
	'gachireai_threads',
	'gachireai_comments',
	'gachireai_events',
	'gachireai_candidates',
	'gachireai_genres',
	'gachireai_products',
	'gachireai_prices',
	'gachi_threads',
	'gachi_comments',
	'gachi_events',
	'gachi_candidates',
);
$gachireai_cleanup_site = static function () use ( $wpdb, $options, $table_suffixes ): void {
	foreach ( $options as $option ) {
		delete_option( $option );
	}
	wp_unschedule_hook( 'gachireai_auto_post' );
	wp_clear_scheduled_hook( 'gachireai_retention_cleanup' );
	foreach ( $table_suffixes as $suffix ) {
		$table = $wpdb->prefix . $suffix;
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
};
if ( is_multisite() ) {
	$sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $sites as $blog_id ) {
		$blog_id  = (int) $blog_id;
		$switched = false;
		if ( get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		$gachireai_cleanup_site();
		if ( $switched ) {
			restore_current_blog();
		}
	}
} else {
	$gachireai_cleanup_site();
}
delete_site_option( 'gachireai_license_key' );
delete_site_option( 'gachireai_license_cache' );
delete_site_option( 'gachireai_common_settings' );
