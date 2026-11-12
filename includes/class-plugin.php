<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Box_Sample;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Box_Shortcode;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Card_Settings;
use Tonkatsutei\Gachi_Review_AI\Article\Auto_Post_Scheduler;
use Tonkatsutei\Gachi_Review_AI\Article\Dialogue_Shortcode;
use Tonkatsutei\Gachi_Review_AI\Article\Disclosure_Shortcode;
use Tonkatsutei\Gachi_Review_AI\Article\Eyecatch_Shortcode;
use Tonkatsutei\Gachi_Review_AI\Article\Retention_Cleaner;
use Tonkatsutei\Gachi_Review_AI\Article\Source_Shortcode;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Sitemap\Sitemap;
use Tonkatsutei\Gachi_Review_AI\Top\Ogp;
use Tonkatsutei\Gachi_Review_AI\Top\Top_Page_Shortcode;
use Tonkatsutei\Gachi_Review_AI\Update\Update_Checker;
use Tonkatsutei\Gachi_Review_AI\Widget\Sister_Sites;
final class Plugin {
	private static ?Plugin $instance = null;
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public function init(): void {
		if ( is_multisite() ) {
			add_action( 'wp_initialize_site', array( Activator::class, 'activate_new_site' ) );
			add_action( 'admin_init', array( Network_Setup::class, 'maybe_network_activate_recorded_plugins' ) );
		}
		if ( Multisite::should_show_network_notice() ) {
			add_action( 'network_admin_notices', array( Multisite::class, 'render_network_notice' ) );
		}
		add_action( 'admin_init', array( Status::class, 'maybe_refresh' ) );
		if ( Multisite::is_enabled_on_current_blog() ) {
			Auto_Post_Scheduler::apply_cron_runtime();
			$this->register_hooks();
		}
		if ( is_admin() ) {
			\Tonkatsutei\Gachi_Review_AI\Admin\Admin::instance()->init();
		}
	}
	private function register_hooks(): void {
		add_action( 'admin_init', array( Activator::class, 'maybe_upgrade' ) );
		add_action( 'admin_init', array( Activator::class, 'migrate_ec_to_scope' ) );
		add_action( 'admin_init', array( Activator::class, 'migrate_genre_parent' ) );
		add_action( 'admin_init', array( Activator::class, 'migrate_review_source_defaults' ) );
		add_action( 'admin_init', array( Activator::class, 'migrate_review_common_settings' ) );
		add_action( 'admin_init', array( Activator::class, 'migrate_review_sources' ) );
		add_action( 'admin_init', array( Activator::class, 'migrate_common_overrides' ) );
		add_action( 'admin_init', array( Activator::class, 'migrate_disclosure' ) );
		add_action( 'admin_init', array( Activator::class, 'migrate_verified_records' ) );
		Auto_Post_Scheduler::register_hooks();
		add_action( 'admin_init', array( Auto_Post_Scheduler::class, 'sync' ) );
		Retention_Cleaner::register_hooks();
		add_action( 'admin_init', array( Retention_Cleaner::class, 'sync' ) );
		Box_Shortcode::register();
		Source_Shortcode::register();
		Eyecatch_Shortcode::register();
		Disclosure_Shortcode::register();
		Dialogue_Shortcode::register();
		Top_Page_Shortcode::register();
		Ogp::register();
		Sister_Sites::register();
		Sitemap::register();
		Update_Checker::register();
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );
	}
	public function enqueue_front_assets(): void {
		if ( ! is_singular() ) {
			return;
		}
		$path = GACHIREAI_PLUGIN_DIR . 'assets/css/front.css';
		if ( ! is_readable( $path ) ) {
			return;
		}
		wp_enqueue_style(
			'gachireai-front',
			GACHIREAI_PLUGIN_URL . 'assets/css/front.css',
			array(),
			(string) filemtime( $path )
		);
		$settings = new Settings();
		$post_id  = (int) get_queried_object_id();
		$content  = (string) get_post_field( 'post_content', $post_id );
		if ( Box_Sample::is_sample( $post_id ) || str_contains( $content, 'gachireai-affiliate--' ) ) {
			Card_Settings::enqueue_all_patterns();
		} else {
			Card_Settings::enqueue_pattern( $settings );
		}
		$this->add_card_style( $settings );
	}
	private function add_card_style( Settings $settings ): void {
		wp_register_style( Card_Settings::INLINE_HANDLE, false, array( 'gachireai-front' ), GACHIREAI_APP_VERSION );
		wp_enqueue_style( Card_Settings::INLINE_HANDLE );
		try {
			$css = Card_Settings::styles( $settings );
		} catch ( \Throwable $e ) {
			return;
		}
		if ( '' !== $css ) {
			wp_add_inline_style( Card_Settings::INLINE_HANDLE, $css );
		}
	}
}
