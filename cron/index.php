<?php
declare(strict_types=1);
if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit;
}
$gachireai_wp_root = __DIR__;
while ( ! is_file( $gachireai_wp_root . '/wp-load.php' ) ) {
	$gachireai_parent = dirname( $gachireai_wp_root );
	if ( $gachireai_parent === $gachireai_wp_root ) {
		break;
	}
	$gachireai_wp_root = $gachireai_parent;
}
if ( ! is_file( $gachireai_wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "gachi-review-ai: wp-load.php が見つかりません。\n" );
	exit( 1 );
}
$GLOBALS['wp']         = null;
$GLOBALS['wp_rewrite'] = null;
require_once $gachireai_wp_root . '/wp-load.php';
$gachireai_blog_ids = is_multisite()
	? get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	)
	: array( get_current_blog_id() );
$gachireai_run_wp_cron = false;
foreach ( $gachireai_blog_ids as $gachireai_blog_id ) {
	$gachireai_blog_id  = (int) $gachireai_blog_id;
	$gachireai_switched = false;
	if ( is_multisite() && get_current_blog_id() !== $gachireai_blog_id ) {
		switch_to_blog( $gachireai_blog_id );
		$gachireai_switched = true;
	}
	try {
		if ( \Tonkatsutei\Gachi_Review_AI\Article\Auto_Post_Scheduler::MODE_SERVER === \Tonkatsutei\Gachi_Review_AI\Article\Auto_Post_Scheduler::cron_mode() ) {
			\Tonkatsutei\Gachi_Review_AI\Article\Auto_Post_Scheduler::server_tick();
			$gachireai_run_wp_cron = true;
		}
	} finally {
		if ( $gachireai_switched ) {
			restore_current_blog();
		}
	}
}
if ( $gachireai_run_wp_cron ) {
	require ABSPATH . 'wp-cron.php';
}
exit( 0 );
