<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Card_Settings;
use Tonkatsutei\Gachi_Review_AI\Article\Generate_Progress;
use Tonkatsutei\Gachi_Review_AI\Multisite;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
final class Admin {
	private static ?Admin $instance = null;
	private ?Dashboard $dashboard = null;
	private ?Api_Affiliate $api_affiliate = null;
	private ?Site_Setup $site_setup = null;
	private ?Article_Settings $article_settings = null;
	private ?Data_Setup $data_setup = null;
	private ?Maintenance $maintenance = null;
	private ?Scraping_Settings $scraping_settings = null;
	private ?Common_Settings $common_settings = null;
	private ?Eyecatch_Metabox $eyecatch_metabox = null;
	private ?Ai_Generate_Metabox $ai_generate_metabox = null;
	public static function instance(): Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_init', array( License_Settings::class, 'maybe_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'register_common_menus' ) );
			add_action( 'admin_post_' . Common_Settings::ACTION_SAVE, array( $this->common_settings(), 'handle_save' ) );
			add_action( 'admin_post_' . Site_Create::ACTION, array( new Site_Create(), 'handle_submit' ) );
			add_action( 'wp_ajax_' . Site_Create::AJAX_ACTION, array( new Site_Create(), 'ajax_generate' ) );
		}
		if ( ! Multisite::is_enabled_on_current_blog() ) {
			return;
		}
		add_action( 'admin_init', array( $this->site_setup(), 'maybe_save' ) );
		add_action( 'admin_init', array( $this->site_setup(), 'maybe_save_elements' ) );
		add_action( 'admin_init', array( $this->site_setup(), 'maybe_save_site_images' ) );
		add_action( 'admin_init', array( $this->site_setup(), 'maybe_save_disclosure' ) );
		add_action( 'admin_init', array( $this->article_settings(), 'maybe_save_article' ) );
		add_action( 'admin_init', array( $this->article_settings(), 'maybe_save_auto_post' ) );
		add_action( 'admin_init', array( $this->article_settings(), 'maybe_save_card' ) );
		add_action( 'admin_init', array( $this->article_settings(), 'maybe_save_eyecatch' ) );
		add_action( 'admin_init', array( $this->api_affiliate(), 'maybe_save' ) );
		add_action( 'admin_init', array( $this->data_setup(), 'maybe_save' ) );
		$site_setup = $this->site_setup();
		foreach (
			array(
				Site_Setup::AJAX_ACTION_GENERATE          => 'ajax_generate_persona',
				Site_Setup::AJAX_ACTION_GENERATE_TARGET   => 'ajax_generate_target',
				Site_Setup::AJAX_ACTION_GENERATE_ELEMENTS => 'ajax_generate_elements',
				Site_Setup::AJAX_ACTION_SAVE              => 'ajax_save_persona',
				Site_Setup::AJAX_ACTION_SAVE_ELEMENTS     => 'ajax_save_elements',
				Site_Setup::AJAX_ACTION_GENERATE_TOP      => 'ajax_generate_top_page',
				Site_Setup::AJAX_ACTION_GENERATE_POLICY   => 'ajax_generate_policy_page',
				Site_Setup::AJAX_ACTION_CATEGORY_OPEN     => 'ajax_category_open',
				Site_Setup::AJAX_ACTION_CATEGORY_PICK     => 'ajax_category_pick',
				Site_Setup::AJAX_ACTION_PROPOSE_KEYWORDS  => 'ajax_propose_keywords',
				Site_Setup::AJAX_ACTION_GENERATE_FAVICON  => 'ajax_generate_favicon',
				Site_Setup::AJAX_ACTION_GENERATE_PROFILE_PHOTO => 'ajax_generate_profile_photo',
				Site_Setup::AJAX_ACTION_GENERATE_TOP_EYECATCH => 'ajax_generate_top_eyecatch',
				Site_Setup::AJAX_ACTION_UPLOAD_FONT => 'ajax_upload_font',
				Site_Setup::AJAX_ACTION_FONT_PREVIEW => 'ajax_font_preview',
				Site_Setup::AJAX_ACTION_SAVE_SITE_IMAGES => 'ajax_save_site_images',
			) as $ajax_action => $method
		) {
			add_action( 'wp_ajax_' . $ajax_action, array( $site_setup, $method ) );
		}
		$article_settings = $this->article_settings();
		foreach (
			array(
				Article_Settings::AJAX_ACTION_SAVE_ARTICLE  => 'ajax_save_article',
				Article_Settings::AJAX_ACTION_SAVE_AUTO_POST => 'ajax_save_auto_post',
				Article_Settings::AJAX_ACTION_SAVE_CARD     => 'ajax_save_card',
				Article_Settings::AJAX_ACTION_SAVE_EYECATCH => 'ajax_save_eyecatch',
				Article_Settings::AJAX_ACTION_GENERATE_MENTEE => 'ajax_generate_dialogue_mentee',
			) as $ajax_action => $method
		) {
			add_action( 'wp_ajax_' . $ajax_action, array( $article_settings, $method ) );
		}
		$api_affiliate = $this->api_affiliate();
		foreach ( Api_Affiliate::AJAX_ACTIONS as $key => $ajax_action ) {
			add_action( 'wp_ajax_' . $ajax_action, array( $api_affiliate, 'ajax_save_' . $key ) );
		}
		$data_setup = $this->data_setup();
		foreach (
			array(
				Data_Setup::AJAX_ACTION_SAVE    => 'ajax_save',
				Data_Setup::AJAX_ACTION_PROPOSE => 'ajax_propose',
			) as $ajax_action => $method
		) {
			add_action( 'wp_ajax_' . $ajax_action, array( $data_setup, $method ) );
		}
		$maintenance = $this->maintenance();
		add_action( 'admin_post_' . Maintenance::ACTION_BACKUP, array( $maintenance, 'handle_backup' ) );
		add_action( 'admin_post_' . Maintenance::ACTION_UPLOAD, array( $maintenance, 'handle_upload' ) );
		add_action( 'admin_post_' . Maintenance::ACTION_APPLY, array( $maintenance, 'handle_apply' ) );
		add_action( 'admin_post_' . Maintenance::ACTION_RESET, array( $maintenance, 'handle_reset' ) );
		add_action( 'admin_post_' . Maintenance::ACTION_NETWORK, array( $maintenance, 'handle_network_setup' ) );
		add_action( 'admin_post_' . Maintenance::ACTION_INSTALL_PLUGIN, array( $maintenance, 'handle_install_plugin' ) );
		add_action( 'admin_post_' . Maintenance::ACTION_WP_SETTINGS, array( $maintenance, 'handle_wp_settings' ) );
		add_action( 'admin_post_' . Maintenance::ACTION_INSTALL_THEME, array( $maintenance, 'handle_install_theme' ) );
		add_action( 'admin_post_' . Maintenance::ACTION_THEME_SETTINGS, array( $maintenance, 'handle_theme_settings' ) );
		add_action( 'admin_post_' . Scraping_Settings::ACTION_SAVE, array( $this->scraping_settings(), 'handle_save' ) );
		add_action( 'admin_post_' . Scraping_Settings::ACTION_SWITCH, array( $this->scraping_settings(), 'handle_switch' ) );
		$eyecatch_metabox = $this->eyecatch_metabox();
		add_action( 'add_meta_boxes_post', array( $eyecatch_metabox, 'add' ) );
		add_action( 'admin_post_' . Eyecatch_Metabox::ACTION, array( $eyecatch_metabox, 'handle' ) );
		$ai_generate_metabox = $this->ai_generate_metabox();
		add_action( 'add_meta_boxes_post', array( $ai_generate_metabox, 'add' ) );
		add_action( 'wp_ajax_' . Ai_Generate_Metabox::AJAX_ACTION, array( $ai_generate_metabox, 'ajax_generate' ) );
		add_action( 'wp_ajax_' . Dashboard::AJAX_ACTION_GENERATE, array( $this->dashboard(), 'ajax_generate' ) );
		add_action( 'wp_ajax_' . Dashboard::AJAX_ACTION_EYECATCH, array( $this->dashboard(), 'ajax_eyecatch' ) );
		add_action( 'wp_ajax_' . Dashboard::AJAX_ACTION_BULK, array( $this->dashboard(), 'ajax_bulk' ) );
		add_action( 'wp_ajax_' . Dashboard::AJAX_ACTION_FORCE, array( $this->dashboard(), 'ajax_force_generate' ) );
		add_action( 'wp_ajax_' . Dashboard::AJAX_ACTION_SELECT, array( $this->dashboard(), 'ajax_select' ) );
		add_action( 'wp_ajax_' . Dashboard::AJAX_ACTION_FORCE_SELECT, array( $this->dashboard(), 'ajax_force_select' ) );
		add_action( 'wp_ajax_' . Dashboard::AJAX_ACTION_REVIEWS, array( $this->dashboard(), 'ajax_reviews' ) );
		add_action( 'wp_ajax_' . Dashboard::AJAX_ACTION_CLEAR, array( $this->dashboard(), 'ajax_clear' ) );
		add_action( 'wp_ajax_' . Dashboard::AJAX_ACTION_MARK_POSTED, array( $this->dashboard(), 'ajax_mark_posted' ) );
		add_action( 'wp_ajax_' . Dashboard::AJAX_ACTION_MARK_EXCLUDED, array( $this->dashboard(), 'ajax_mark_excluded' ) );
	}
	public function enqueue_assets( string $hook_suffix ): void {
		if ( in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			$this->enqueue_editor_assets();
		}
		if (
			! str_contains( $hook_suffix, 'gachireai' )
			&& ! str_contains( $hook_suffix, 'gachi-review-ai' )
		) {
			return;
		}
		$path = GACHIREAI_PLUGIN_DIR . 'assets/css/admin.css';
		if ( is_readable( $path ) ) {
			wp_enqueue_style(
				'gachireai-admin',
				GACHIREAI_PLUGIN_URL . 'assets/css/admin.css',
				array( 'dashicons' ),
				(string) filemtime( $path )
			);
		}
		$overlay_css = GACHIREAI_PLUGIN_DIR . 'assets/css/ai-generate-metabox.css';
		if ( is_readable( $overlay_css ) ) {
			wp_enqueue_style(
				'gachireai-ai-generate',
				GACHIREAI_PLUGIN_URL . 'assets/css/ai-generate-metabox.css',
				array( 'dashicons' ),
				(string) filemtime( $overlay_css )
			);
		}
		$front = GACHIREAI_PLUGIN_DIR . 'assets/css/front.css';
		if ( is_readable( $front ) && Tabs::TAB_ARTICLE === Tabs::current() ) {
			wp_enqueue_style(
				'gachireai-front',
				GACHIREAI_PLUGIN_URL . 'assets/css/front.css',
				array(),
				(string) filemtime( $front )
			);
			Card_Settings::enqueue_all_patterns();
			wp_register_style( Card_Settings::INLINE_HANDLE, false, array( 'gachireai-front' ), GACHIREAI_APP_VERSION );
			wp_enqueue_style( Card_Settings::INLINE_HANDLE );
			$palette = Card_Settings::palette_variables();
			if ( '' !== $palette ) {
				wp_add_inline_style( Card_Settings::INLINE_HANDLE, $palette );
			}
			try {
				$css = Card_Settings::styles( new Settings(), false );
				if ( '' !== $css ) {
					wp_add_inline_style( Card_Settings::INLINE_HANDLE, $css );
				}
			} catch ( \Throwable $e ) {
			}
		}
		wp_enqueue_media();
		$script = GACHIREAI_PLUGIN_DIR . 'assets/js/admin.js';
		if ( is_readable( $script ) ) {
			wp_enqueue_script(
				'gachireai-admin',
				GACHIREAI_PLUGIN_URL . 'assets/js/admin.js',
				array(),
				(string) filemtime( $script ),
				true
			);
			wp_localize_script(
				'gachireai-admin',
				'gachireaiAdmin',
				array(
					'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
					'fontPreviewAction' => Site_Setup::AJAX_ACTION_FONT_PREVIEW,
					'dashboardActions' => array(
						'select'   => Dashboard::AJAX_ACTION_SELECT,
						'reviews'  => Dashboard::AJAX_ACTION_REVIEWS,
						'generate' => Dashboard::AJAX_ACTION_GENERATE,
						'eyecatch' => Dashboard::AJAX_ACTION_EYECATCH,
						'bulk'     => Dashboard::AJAX_ACTION_BULK,
					),
					'progressLabels'  => array(
						Dashboard::AJAX_ACTION_SELECT   => __( '商品を選定中...', GACHIREAI_TEXT_DOMAIN ),
						Dashboard::AJAX_ACTION_FORCE_SELECT => __( '商品を選定中...', GACHIREAI_TEXT_DOMAIN ),
						Dashboard::AJAX_ACTION_REVIEWS  => __( 'レビューを取得中...', GACHIREAI_TEXT_DOMAIN ),
						Dashboard::AJAX_ACTION_GENERATE => __( '記事即時生成中...', GACHIREAI_TEXT_DOMAIN ),
						Dashboard::AJAX_ACTION_EYECATCH => __( 'アイキャッチ画像を生成中...', GACHIREAI_TEXT_DOMAIN ),
						Dashboard::AJAX_ACTION_BULK     => __( '一括生成・公開を実行中...', GACHIREAI_TEXT_DOMAIN ),
						Dashboard::AJAX_ACTION_FORCE    => __( '記事即時生成中...', GACHIREAI_TEXT_DOMAIN ),
						Site_Setup::AJAX_ACTION_GENERATE_FAVICON        => __( 'ファビコンを生成中...', GACHIREAI_TEXT_DOMAIN ),
						Site_Setup::AJAX_ACTION_GENERATE_PROFILE_PHOTO  => __( 'プロフィール画像を生成中...', GACHIREAI_TEXT_DOMAIN ),
						Site_Create::AJAX_ACTION => __( 'サイトを生成中...', GACHIREAI_TEXT_DOMAIN ),
					),
					'progressBaseUrl' => Generate_Progress::base_url(),
					'progressToken'   => Generate_Progress::issue_token( get_current_user_id() ),
					'i18n'            => array(
						'requestFailed' => __( '通信に失敗しました。時間をおいて、もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ),
						'testing'       => __( '接続テスト中...', GACHIREAI_TEXT_DOMAIN ),
						'categoryPickRequired' => __( '選ぶカテゴリーを 1 つ選んでください。', GACHIREAI_TEXT_DOMAIN ),
						'imagePickTitle' => __( 'プロフィール画像を選択', GACHIREAI_TEXT_DOMAIN ),
						'imageUse'       => __( 'この画像を使う', GACHIREAI_TEXT_DOMAIN ),
					),
				)
			);
		}
	}
	private function enqueue_editor_assets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen instanceof \WP_Screen && 'post' !== (string) $screen->post_type ) {
			return;
		}
		$css = GACHIREAI_PLUGIN_DIR . 'assets/css/ai-generate-metabox.css';
		if ( is_readable( $css ) ) {
			wp_enqueue_style(
				'gachireai-ai-generate',
				GACHIREAI_PLUGIN_URL . 'assets/css/ai-generate-metabox.css',
				array( 'dashicons' ),
				(string) filemtime( $css )
			);
		}
		$script = GACHIREAI_PLUGIN_DIR . 'assets/js/ai-generate-metabox.js';
		if ( ! is_readable( $script ) ) {
			return;
		}
		wp_enqueue_script(
			'gachireai-ai-generate',
			GACHIREAI_PLUGIN_URL . 'assets/js/ai-generate-metabox.js',
			array(),
			(string) filemtime( $script ),
			true
		);
		wp_localize_script(
			'gachireai-ai-generate',
			'gachireaiAiGenerate',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'action'          => Ai_Generate_Metabox::AJAX_ACTION,
				'nonce'           => Ai_Generate_Metabox::create_nonce(),
				'progressBaseUrl' => Generate_Progress::base_url(),
				'progressToken'   => Generate_Progress::issue_token( get_current_user_id() ),
				'i18n'            => array(
					'requestFailed'    => __( '通信に失敗しました。時間をおいて、もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ),
					'generating'       => __( 'AI生成を開始...', GACHIREAI_TEXT_DOMAIN ),
					'unsavedInterrupt' => __( '保存していない内容があるため、AI生成を中断します。', GACHIREAI_TEXT_DOMAIN ),
					'retrying'         => __( 'リトライ中', GACHIREAI_TEXT_DOMAIN ),
				),
			)
		);
	}
	private function dashboard(): Dashboard {
		if ( null === $this->dashboard ) {
			$this->dashboard = new Dashboard();
		}
		return $this->dashboard;
	}
	private function api_affiliate(): Api_Affiliate {
		if ( null === $this->api_affiliate ) {
			$this->api_affiliate = new Api_Affiliate();
		}
		return $this->api_affiliate;
	}
	private function site_setup(): Site_Setup {
		if ( null === $this->site_setup ) {
			$this->site_setup = new Site_Setup();
		}
		return $this->site_setup;
	}
	private function article_settings(): Article_Settings {
		if ( null === $this->article_settings ) {
			$this->article_settings = new Article_Settings();
		}
		return $this->article_settings;
	}
	private function data_setup(): Data_Setup {
		if ( null === $this->data_setup ) {
			$this->data_setup = new Data_Setup();
		}
		return $this->data_setup;
	}
	private function maintenance(): Maintenance {
		if ( null === $this->maintenance ) {
			$this->maintenance = new Maintenance();
		}
		return $this->maintenance;
	}
	private function scraping_settings(): Scraping_Settings {
		if ( null === $this->scraping_settings ) {
			$this->scraping_settings = new Scraping_Settings();
		}
		return $this->scraping_settings;
	}
	private function common_settings(): Common_Settings {
		if ( null === $this->common_settings ) {
			$this->common_settings = new Common_Settings();
		}
		return $this->common_settings;
	}
	private function eyecatch_metabox(): Eyecatch_Metabox {
		if ( null === $this->eyecatch_metabox ) {
			$this->eyecatch_metabox = new Eyecatch_Metabox();
		}
		return $this->eyecatch_metabox;
	}
	private function ai_generate_metabox(): Ai_Generate_Metabox {
		if ( null === $this->ai_generate_metabox ) {
			$this->ai_generate_metabox = new Ai_Generate_Metabox();
		}
		return $this->ai_generate_metabox;
	}
	public function register_menus(): void {
		add_menu_page(
			__( 'ガチレビュー.AI', GACHIREAI_TEXT_DOMAIN ),
			__( 'ガチレビュー.AI', GACHIREAI_TEXT_DOMAIN ),
			'manage_options',
			Tabs::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-megaphone',
			58
		);
	}
	public function register_common_menus(): void {
		add_menu_page(
			__( 'ガチレビュー.AI', GACHIREAI_TEXT_DOMAIN ),
			__( 'ガチレビュー.AI', GACHIREAI_TEXT_DOMAIN ),
			'manage_network',
			Common_Tabs::PAGE_SLUG,
			array( $this, 'render_common_page' ),
			'dashicons-megaphone',
			59
		);
	}
	public function render_common_page(): void {
		if ( ! is_network_admin() || ! current_user_can( 'manage_network' ) ) {
			return;
		}
		$this->common_settings()->render();
	}
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! Multisite::is_enabled_on_current_blog() ) {
			$this->render_license_only();
			return;
		}
		switch ( Tabs::current() ) {
			case Tabs::TAB_LLM:
			case Tabs::TAB_ASP:
				$this->api_affiliate()->render();
				break;
			case Tabs::TAB_SITE:
				$this->site_setup()->render();
				break;
			case Tabs::TAB_ARTICLE:
				$this->article_settings()->render();
				break;
			case Tabs::TAB_REVIEW:
				$this->data_setup()->render();
				break;
			case Tabs::TAB_SCRAPING:
				$this->scraping_settings()->render();
				break;
			case Tabs::TAB_MAINTENANCE:
				$this->maintenance()->render();
				break;
			case Tabs::TAB_DASHBOARD:
			default:
				$this->dashboard()->render();
				break;
		}
	}
	private function render_license_only(): void {
		$model = array(
			'page_title' => esc_html__( 'ライセンス認証', GACHIREAI_TEXT_DOMAIN ),
			'notices'    => array(
				array(
					'type' => 'warning',
					'text' => esc_html__( 'このサイトでは gachi-review-ai の機能は利用できません（マルチサイト機能は PRO ライセンスのみです）。ここで認証すると、ネットワーク内のすべてのサイトへ結果が反映されます。', GACHIREAI_TEXT_DOMAIN ),
				),
			),
		) + License_Settings::form_model() + Screen::plugin_badge();
		$html = '';
		try {
			$html = ( new Div_Renderer() )->render( 'admin/license-only', $model );
		} catch ( \RuntimeException $e ) {
			wp_die(
				esc_html( $e->getMessage() ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 500 )
			);
		}
		echo $html; 
	}
}
