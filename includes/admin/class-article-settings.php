<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Box_Positioner;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Box_Renderer;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Box_Sample;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Card_Settings;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Providers;
use Tonkatsutei\Gachi_Review_AI\Article\Auto_Post_Scheduler;
use Tonkatsutei\Gachi_Review_AI\Article\Cron_Schedule;
use Tonkatsutei\Gachi_Review_AI\Article\Dialogue_Character;
use Tonkatsutei\Gachi_Review_AI\Article\Reuse_Interval;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\License\Capabilities;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Article_Settings extends Screen {
	private const ACTION_FIELD = 'gachireai_action';
	private const ACTION_ARTICLE = 'save_article_settings';
	private const NONCE_ACTION_ARTICLE = 'gachireai_save_article_settings';
	public const AJAX_ACTION_SAVE_ARTICLE = 'gachireai_save_article_settings';
	private const ARTICLE_GROUP = 'article';
	private const SAVED_ARTICLE_ARG = 'gachireai_article_saved';
	private const ERROR_ARTICLE_ARG = 'gachireai_article_error';
	private const ERROR_ARTICLE_TRANSIENT_PREFIX = 'gachireai_article_error_';
	private const ACTION_AUTO_POST = 'save_auto_post_settings';
	private const NONCE_ACTION_AUTO_POST = 'gachireai_save_auto_post_settings';
	public const AJAX_ACTION_SAVE_AUTO_POST = 'gachireai_save_auto_post_settings';
	private const SAVED_AUTO_POST_ARG = 'gachireai_auto_post_saved';
	private const ERROR_AUTO_POST_ARG = 'gachireai_auto_post_error';
	private const ERROR_AUTO_POST_TRANSIENT_PREFIX = 'gachireai_auto_post_error_';
	private const ACTION_CARD = 'save_card_settings';
	private const NONCE_ACTION_CARD = 'gachireai_save_card_settings';
	public const AJAX_ACTION_SAVE_CARD = 'gachireai_save_card_settings';
	private const SAVED_CARD_ARG = 'gachireai_card_saved';
	private const ACTION_EYECATCH = 'save_image_eyecatch';
	private const NONCE_ACTION_EYECATCH = 'gachireai_save_image_eyecatch';
	public const AJAX_ACTION_SAVE_EYECATCH = 'gachireai_save_image_eyecatch';
	private const EYECATCH_GROUP = 'image';
	private const SAVED_EYECATCH_ARG = 'gachireai_eyecatch_saved';
	public const AJAX_ACTION_GENERATE_MENTEE = 'gachireai_generate_dialogue_mentee';
	private array $style_preview_cache = array();
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->render_template( 'admin/article-setup', $this->model() );
	}
	public function maybe_save_article(): void {
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( ! isset( $_POST[ self::ACTION_FIELD ] ) ) {
			return;
		}
		if ( self::ACTION_ARTICLE !== sanitize_key( wp_unslash( (string) $_POST[ self::ACTION_FIELD ] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( self::NONCE_ACTION_ARTICLE );
		$errors = $this->article_save();
		if ( array() !== $errors ) {
			set_transient( $this->error_article_transient_key(), $errors, 60 );
			wp_safe_redirect( Tabs::url( Tabs::TAB_ARTICLE, array( self::ERROR_ARTICLE_ARG => '1' ) ) );
			exit;
		}
		wp_safe_redirect( Tabs::url( Tabs::TAB_ARTICLE, array( self::SAVED_ARTICLE_ARG => '1' ) ) );
		exit;
	}
	public function ajax_save_article(): void {
		$this->guard_ajax( self::NONCE_ACTION_ARTICLE );
		$errors = $this->article_save();
		if ( array() !== $errors ) {
			$messages = array();
			foreach ( $errors as $error ) {
				$messages[] = $this->message( 'error', esc_html( $error ) );
			}
			$this->send_form_html(
				fn(): string => $this->render_article_form( $messages, $this->article_display_values() )
			);
			return;
		}
		$this->send_form_html(
			fn(): string => $this->render_article_form(
				array( $this->message( 'success', esc_html__( '保存しました。', GACHIREAI_TEXT_DOMAIN ) ) )
			)
		);
	}
	public function maybe_save_auto_post(): void {
		if ( wp_doing_ajax() || ! isset( $_POST[ self::ACTION_FIELD ] ) ) {
			return;
		}
		if ( self::ACTION_AUTO_POST !== sanitize_key( wp_unslash( (string) $_POST[ self::ACTION_FIELD ] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( self::NONCE_ACTION_AUTO_POST );
		$errors = $this->auto_post_save();
		if ( array() !== $errors ) {
			set_transient( $this->error_auto_post_transient_key(), $errors, 60 );
			wp_safe_redirect( Tabs::url( Tabs::TAB_ARTICLE, array( self::ERROR_AUTO_POST_ARG => '1' ) ) );
			exit;
		}
		wp_safe_redirect( Tabs::url( Tabs::TAB_ARTICLE, array( self::SAVED_AUTO_POST_ARG => '1' ) ) );
		exit;
	}
	public function ajax_save_auto_post(): void {
		$this->guard_ajax( self::NONCE_ACTION_AUTO_POST );
		$errors = $this->auto_post_save();
		if ( array() !== $errors ) {
			$messages = array();
			foreach ( $errors as $error ) {
				$messages[] = $this->message( 'error', esc_html( $error ) );
			}
			$this->send_form_html(
				fn(): string => $this->render_auto_post_form( $messages, $this->auto_post_display_values() )
			);
			return;
		}
		$this->send_form_html(
			fn(): string => $this->render_auto_post_form(
				array( $this->message( 'success', esc_html__( '保存しました。', GACHIREAI_TEXT_DOMAIN ) ) )
			)
		);
	}
	public function render_auto_post_form( array $messages = array(), ?array $values = null ): string {
		$model                       = $this->model();
		$model['auto_post_messages'] = $messages;
		if ( null !== $values ) {
			$model['auto_post_fields'] = $this->auto_post_form()->build_list(
				$values,
				array_keys( $this->auto_post_definitions() ),
				self::ARTICLE_GROUP
			);
		}
		return $this->render_template_string( 'admin/article-setup-auto-post-form', $model );
	}
	public function ajax_generate_dialogue_mentee(): void {
		$this->guard_ajax( self::NONCE_ACTION_ARTICLE );
		$errors = $this->article_save();
		if ( array() !== $errors ) {
			$messages = array();
			foreach ( $errors as $error ) {
				$messages[] = $this->message( 'error', esc_html( $error ) );
			}
			$this->send_form_html( fn(): string => $this->render_article_form( array(), $this->article_display_values(), $messages ) );
			return;
		}
		$messages = array();
		try {
			$result = ( new Dialogue_Character() )->generate_mentee();
			$this->update_dialogue(
				array(
					'name'   => (string) ( $result['name'] ?? '' ),
					'gender' => (string) ( $result['gender'] ?? '' ),
					'age'    => (string) ( $result['age'] ?? '' ),
					'tone'   => (string) ( $result['tone'] ?? '' ),
					'image'  => (int) ( $result['attachment_id'] ?? 0 ),
				)
			);
			$messages[] = $this->message( 'success', esc_html( (string) ( $result['message'] ?? '' ) ) );
			if ( '' !== (string) ( $result['image_error'] ?? '' ) ) {
				$messages[] = $this->message(
					'warning',
					esc_html(
						sprintf(
							__( '画像の生成に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
							mb_substr( (string) $result['image_error'], 0, 300 )
						)
					)
				);
			}
		} catch ( \Throwable $e ) {
			$messages[] = $this->message( 'error', esc_html( mb_substr( trim( $e->getMessage() ), 0, 300 ) ) );
		}
		$this->send_form_html( fn(): string => $this->render_article_form( array(), null, $messages ) );
	}
	public function render_article_form( array $messages = array(), ?array $values = null, array $dialogue_messages = array() ): string {
		$model                      = $this->model();
		$model['article_messages']  = $messages;
		$model['dialogue_messages'] = $dialogue_messages;
		if ( null !== $values ) {
			$model['article_fields'] = $this->article_form()->build_list(
				$values,
				array_keys( $this->article_definitions() ),
				self::ARTICLE_GROUP
			);
		}
		return $this->render_template_string( 'admin/article-setup-article-form', $model );
	}
	public function maybe_save_eyecatch(): void {
		if ( wp_doing_ajax() || ! isset( $_POST[ self::ACTION_FIELD ] ) ) {
			return;
		}
		if ( self::ACTION_EYECATCH !== sanitize_key( wp_unslash( (string) $_POST[ self::ACTION_FIELD ] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( self::NONCE_ACTION_EYECATCH );
		$this->eyecatch_save();
		wp_safe_redirect( Tabs::url( Tabs::TAB_ARTICLE, array( self::SAVED_EYECATCH_ARG => '1' ) ) );
		exit;
	}
	public function ajax_save_eyecatch(): void {
		$this->guard_ajax( self::NONCE_ACTION_EYECATCH );
		$this->eyecatch_save();
		$this->send_form_html(
			fn(): string => $this->render_eyecatch_form(
				array( $this->message( 'success', esc_html__( '保存しました。', GACHIREAI_TEXT_DOMAIN ) ) )
			)
		);
	}
	public function render_eyecatch_form( array $messages = array(), ?array $values = null ): string {
		return $this->render_template_string(
			'admin/article-setup-eyecatch-form',
			$this->eyecatch_form_fields( $messages, $values )
		);
	}
	private function eyecatch_save(): void {
		$settings  = new Settings();
		$current   = $settings->image();
		$posted    = $this->posted_array( self::EYECATCH_GROUP ) ?? array();
		$clean     = $this->eyecatch_form()->sanitize( $posted, $current );
		$master_on = isset( $posted['eyecatch'] ) && is_scalar( $posted['eyecatch'] ) && '1' === (string) $posted['eyecatch'];
		if ( ! $master_on ) {
			$clean = array_intersect_key( $clean, array( 'eyecatch' => true ) );
		}
		$candidate = array_merge( $current, $clean );
		$settings->set( self::EYECATCH_GROUP, $candidate );
		$this->record_setting_save( __( 'アイキャッチ設定', GACHIREAI_TEXT_DOMAIN ) );
	}
	private function eyecatch_values(): array {
		return ( new Settings() )->image();
	}
	private function eyecatch_form(): Form {
		return new Form( $this->eyecatch_definitions() );
	}
	private function eyecatch_form_fields( array $messages = array(), ?array $values = null ): array {
		$definitions = $this->eyecatch_definitions();
		$values      = null === $values ? $this->eyecatch_values() : $values;
		$section_disabled = Capabilities::IMAGE_NONE === Status::capabilities()->image_background_mode();
		if ( $section_disabled ) {
			$values['eyecatch'] = '';
		}
		if (
			Capabilities::IMAGE_GENERATE !== Status::capabilities()->image_background_mode()
			&& 'generate' === (string) ( $values['eyecatch_mode'] ?? '' )
		) {
			$values['eyecatch_mode'] = 'top';
		}
		$fields      = $this->eyecatch_form()->build_list(
			$values,
			array_keys( $definitions ),
			self::EYECATCH_GROUP
		);
		return array(
			'form_action'   => esc_url( Tabs::url( Tabs::TAB_ARTICLE ) ),
			'form_id'       => esc_attr( 'gachireai-form-eyecatch' ),
			'hidden_fields' => array(
				array(
					'name'  => esc_attr( self::ACTION_FIELD ),
					'value' => esc_attr( self::ACTION_EYECATCH ),
				),
				array(
					'name'  => esc_attr( '_wpnonce' ),
					'value' => esc_attr( wp_create_nonce( self::NONCE_ACTION_EYECATCH ) ),
				),
			),
			'sections'      => array(
				array(
					'title'            => esc_html__( 'アイキャッチ', GACHIREAI_TEXT_DOMAIN ),
					'description'      => esc_html__( "記事のアイキャッチ画像の決め方と、文字入れ・表示を設定します。\n「アイキャッチを自動生成」を OFF にすると、以下の項目はすべて無効になります。\nサイト全体の画風は「サイト設定 > サイト画像」で設定します。", GACHIREAI_TEXT_DOMAIN ),
					'section_key'      => esc_attr( 'eyecatch' ),
					'blocks'           => array(
						array(
							'block_title'    => '',
							'fields'         => $fields,
							'block_buttons'  => array(),
							'block_messages' => array(),
						),
					),
					'section_buttons'  => array(
						array(
							'btn_name'     => '',
							'btn_value'    => '',
							'btn_label'    => esc_html__( '保存', GACHIREAI_TEXT_DOMAIN ),
							'btn_primary'  => true,
							'btn_disabled' => $section_disabled,
							'btn_title'    => '',
							'btn_action'   => esc_attr( self::AJAX_ACTION_SAVE_EYECATCH ),
							'btn_testing'  => false,
						),
					),
					'section_note'     => $section_disabled
						? esc_html__( 'アイキャッチは PRO, BASIC ライセンスで利用できます。', GACHIREAI_TEXT_DOMAIN )
						: '',
					'section_messages' => $messages,
				),
			),
			'submit_label'   => '',
			'submit_action'  => '',
			'submit_testing' => false,
			'form_messages'  => array(),
		);
	}
	private function eyecatch_definitions(): array {
		$image   = ( new Settings() )->image();
		$master  = '1' === (string) ( $image['eyecatch'] ?? '' );
		$license = Status::capabilities()->image_background_mode();
		$section_disabled = Capabilities::IMAGE_NONE === $license;
		$generate_disabled = Capabilities::IMAGE_GENERATE !== $license;
		$base_disabled = $section_disabled || ! $master;
		$gen_disabled = $base_disabled || $generate_disabled;
		return array(
			'eyecatch'     => array(
				'label'       => __( 'アイキャッチを自動生成', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'checkbox',
				'options'     => array( '1' => __( '記事生成時にアイキャッチ画像を生成して設定する', GACHIREAI_TEXT_DOMAIN ) ),
				'field_disabled' => $section_disabled,
				'master'      => 'eyecatch',
				'description' => __( "画像生成AIが未設定のときは生成せず、記事生成の結果に警告を出します。\nOFF にすると、以下のアイキャッチの項目はすべて無効になります。", GACHIREAI_TEXT_DOMAIN ),
			),
			'eyecatch_mode' => array(
				'label'       => __( 'アイキャッチ画像', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'radio',
				'options'     => array(
					'generate' => __( '記事に合わせてAI生成', GACHIREAI_TEXT_DOMAIN ),
					'top'      => __( 'トップページ用の画像で揃える', GACHIREAI_TEXT_DOMAIN ),
					'select'   => __( '選択した画像で揃える', GACHIREAI_TEXT_DOMAIN ),
				),
				'default'     => 'generate',
				'driver'      => 'eyecatch_mode',
				'disabled'    => $generate_disabled ? array( 'generate' ) : array(),
				'field_disabled' => $base_disabled,
				'disabled_by' => 'eyecatch',
				'field_locked' => $section_disabled,
				'description' => __( "記事に合わせてAI生成＝記事ごとに画像を生成します。\nトップページ用の画像で揃える＝サイト設定の「トップページ用アイキャッチ」を全記事で使います。\n選択した画像で揃える＝下で選んだ画像を全記事で使います。", GACHIREAI_TEXT_DOMAIN ),
			),
			'eyecatch_image' => array(
				'label'       => __( '選択した画像', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'image',
				'pick_label'  => __( '画像を選択', GACHIREAI_TEXT_DOMAIN ),
				'clear_label' => __( '削除', GACHIREAI_TEXT_DOMAIN ),
				'row_depends' => array(
					'on'     => 'eyecatch_mode',
					'values' => array( 'select' ),
				),
				'field_disabled' => $base_disabled,
				'disabled_by' => 'eyecatch',
				'field_locked' => $section_disabled,
				'description' => __( "「選択した画像で揃える」を選択した場合に使う画像です。\nメディアライブラリから選びます。\nここで選択した画像を全記事で使います。", GACHIREAI_TEXT_DOMAIN ),
			),
			'text_overlay' => array(
				'label'       => __( '文字入れ', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'checkbox',
				'options'     => array( '1' => __( '記事タイトル・商品名・サイト名を画像に重ねる', GACHIREAI_TEXT_DOMAIN ) ),
				'field_disabled' => $gen_disabled,
				'disabled_by' => 'eyecatch',
				'field_locked' => $section_disabled || $generate_disabled,
				'description' => __( '', GACHIREAI_TEXT_DOMAIN ),
			),
			'text_side'    => array(
				'label'       => __( '文字を置く側', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'radio',
				'options'     => array(
					'left'  => __( '左', GACHIREAI_TEXT_DOMAIN ),
					'right' => __( '右', GACHIREAI_TEXT_DOMAIN ),
				),
				'default'     => 'left',
				'field_disabled' => $gen_disabled,
				'disabled_by' => 'eyecatch',
				'field_locked' => $section_disabled || $generate_disabled,
				'description' => __( '生成プロンプト（AIへの指示文）で、この側に文字入れ用の余白を作るよう指示します。', GACHIREAI_TEXT_DOMAIN ),
			),
			'prompt_llm'   => array(
				'label'       => __( 'プロンプトの英語化', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'checkbox',
				'options'     => array( '1' => __( '既存の LLM で画像プロンプトを英語化する', GACHIREAI_TEXT_DOMAIN ) ),
				'field_disabled' => $gen_disabled,
				'disabled_by' => 'eyecatch',
				'field_locked' => $section_disabled || $generate_disabled,
				'description' => __( 'AIに作画内容を正確に伝えるために指示内容を英語で作ります。', GACHIREAI_TEXT_DOMAIN ),
			),
			'show_eyecatch' => array(
				'label'       => __( 'アイキャッチの表示', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'checkbox',
				'options'     => array( '1' => __( '表示する', GACHIREAI_TEXT_DOMAIN ) ),
				'field_disabled' => $base_disabled,
				'disabled_by' => 'eyecatch',
				'field_locked' => $section_disabled,
				'description' => __( "記事本文の先頭にアイキャッチ画像を表示します。\nテーマによってはアイキャッチ画像を表示する機能がある場合があります。\nその場合は、重複しないようどちらか一方だけをONにしてください。\n例: Cocoon", GACHIREAI_TEXT_DOMAIN ),
			),
			'disclosure'    => array(
				'label'       => __( 'AI生成画像の公示', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'checkbox',
				'options'     => array( '1' => __( '表示する', GACHIREAI_TEXT_DOMAIN ) ),
				'field_disabled' => $base_disabled,
				'disabled_by' => 'eyecatch',
				'field_locked' => $section_disabled,
				'description' => __( "記事本文のアイキャッチ直下に公示を表示します。\n文面は次の「公示の文面」で変更できます。", GACHIREAI_TEXT_DOMAIN ),
			),
			'disclosure_text' => array(
				'label'       => __( '公示の文面', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'placeholder' => __( 'この画像は記事を元にAIで生成したものであり、紹介する商品とは無関係です。', GACHIREAI_TEXT_DOMAIN ),
				'max'         => 300,
				'field_disabled' => $base_disabled,
				'disabled_by' => 'eyecatch',
				'field_locked' => $section_disabled,
				'description' => __( "記事のアイキャッチ直下に表示する文面です。\n空にすると既定の文面を使います。", GACHIREAI_TEXT_DOMAIN ),
			),
		);
	}
	private function article_save(): array {
		$settings = new Settings();
		$current  = (array) $settings->get( self::ARTICLE_GROUP, array() );
		$posted   = $this->article_posted();
		$sanitized = $this->article_form()->sanitize( $posted, $current );
		$clean = array(
			'article_type'        => (string) ( $sanitized['article_type'] ?? '' ),
			'reuse_interval_days' => (string) ( $sanitized['reuse_interval_days'] ?? Reuse_Interval::DEFAULT_DAYS ),
		);
		$clean['dialogue'] = array_key_exists( 'dialogue', $posted )
			? $this->sanitize_dialogue( $posted['dialogue'] )
			: (array) ( $current['dialogue'] ?? array() );
		$merged = array_merge( $current, $clean );
		unset( $merged['post_times'] );
		$settings->set( self::ARTICLE_GROUP, $merged );
		$this->record_setting_save( __( '記事生成投稿設定', GACHIREAI_TEXT_DOMAIN ) );
		return array();
	}
	private function auto_post_save(): array {
		$settings = new Settings();
		$current  = (array) $settings->get( self::ARTICLE_GROUP, array() );
		$posted   = $this->auto_post_posted();
		$parsed = Cron_Schedule::parse( $posted[ Cron_Schedule::KEY ] ?? array() );
		if ( array() !== $parsed['errors'] ) {
			return $parsed['errors'];
		}
		$sanitized = $this->auto_post_form()->sanitize( $posted, $current );
		$clean = array(
			Cron_Schedule::KEY                 => (string) $parsed['schedule'],
			Auto_Post_Scheduler::KEY_ENABLED   => (string) ( $sanitized[ Auto_Post_Scheduler::KEY_ENABLED ] ?? '' ),
			Auto_Post_Scheduler::KEY_CRON_MODE => (string) ( $sanitized[ Auto_Post_Scheduler::KEY_CRON_MODE ] ?? Auto_Post_Scheduler::MODE_WP ),
		);
		$settings->set( self::ARTICLE_GROUP, array_merge( $current, $clean ) );
		Auto_Post_Scheduler::sync();
		$this->record_setting_save( __( '自動投稿設定', GACHIREAI_TEXT_DOMAIN ) );
		return array();
	}
	private function article_posted(): array {
		return $this->posted_array( self::ARTICLE_GROUP ) ?? array();
	}
	private function auto_post_posted(): array {
		return $this->posted_array( self::ARTICLE_GROUP ) ?? array();
	}
	private function article_values(): array {
		return (array) ( new Settings() )->get( self::ARTICLE_GROUP, array() );
	}
	private function auto_post_values(): array {
		return (array) ( new Settings() )->get( self::ARTICLE_GROUP, array() );
	}
	private function article_display_values(): array {
		$values = $this->article_values();
		$posted = $this->article_posted();
		if ( isset( $posted['article_type'] ) && is_scalar( $posted['article_type'] ) ) {
			$clean = $this->article_form()->sanitize( array( 'article_type' => $posted['article_type'] ), array() );
			if ( isset( $clean['article_type'] ) ) {
				$values['article_type'] = $clean['article_type'];
			}
		}
		if ( isset( $posted['reuse_interval_days'] ) && is_scalar( $posted['reuse_interval_days'] ) ) {
			$clean = $this->article_form()->sanitize( array( 'reuse_interval_days' => $posted['reuse_interval_days'] ), array() );
			if ( isset( $clean['reuse_interval_days'] ) ) {
				$values['reuse_interval_days'] = $clean['reuse_interval_days'];
			}
		}
		return $values;
	}
	private function auto_post_display_values(): array {
		$values = $this->auto_post_values();
		$posted = $this->auto_post_posted();
		if ( isset( $posted[ Cron_Schedule::KEY ] ) ) {
			$posted_cron = $posted[ Cron_Schedule::KEY ];
			$parts       = array(
				'minute' => '',
				'hour'   => '',
			);
			if ( is_array( $posted_cron ) ) {
				$parts = array(
					'minute' => isset( $posted_cron['minute'] ) && is_scalar( $posted_cron['minute'] ) ? sanitize_text_field( (string) $posted_cron['minute'] ) : '',
					'hour'   => isset( $posted_cron['hour'] ) && is_scalar( $posted_cron['hour'] ) ? sanitize_text_field( (string) $posted_cron['hour'] ) : '',
				);
			} elseif ( is_scalar( $posted_cron ) ) {
				$parts = Cron_Schedule::parts( sanitize_text_field( (string) $posted_cron ) );
			}
			$values[ Cron_Schedule::KEY ] = Cron_Schedule::display_value( $parts['minute'], $parts['hour'] );
		}
		$enabled = isset( $posted[ Auto_Post_Scheduler::KEY_ENABLED ] ) && is_scalar( $posted[ Auto_Post_Scheduler::KEY_ENABLED ] )
			? (string) $posted[ Auto_Post_Scheduler::KEY_ENABLED ]
			: '';
		$values[ Auto_Post_Scheduler::KEY_ENABLED ] = ( '1' === $enabled ) ? '1' : '';
		$clean_mode = $this->auto_post_form()->sanitize(
			array( Auto_Post_Scheduler::KEY_CRON_MODE => $posted[ Auto_Post_Scheduler::KEY_CRON_MODE ] ?? '' ),
			array()
		);
		if ( isset( $clean_mode[ Auto_Post_Scheduler::KEY_CRON_MODE ] ) ) {
			$values[ Auto_Post_Scheduler::KEY_CRON_MODE ] = $clean_mode[ Auto_Post_Scheduler::KEY_CRON_MODE ];
		}
		return $values;
	}
	private function sanitize_dialogue( $raw ): array {
		$raw    = is_array( $raw ) ? $raw : array();
		$mentee = is_array( $raw['mentee'] ?? null ) ? $raw['mentee'] : array();
		return array(
			'mentee' => array(
				'name'   => sanitize_text_field( (string) ( $mentee['name'] ?? '' ) ),
				'gender' => sanitize_text_field( (string) ( $mentee['gender'] ?? '' ) ),
				'age'    => sanitize_text_field( (string) ( $mentee['age'] ?? '' ) ),
				'tone'   => sanitize_text_field( (string) ( $mentee['tone'] ?? '' ) ),
				'image'  => absint( $mentee['image'] ?? 0 ),
			),
		);
	}
	private function update_dialogue( array $mentee = array() ): void {
		$settings = new Settings();
		$current  = (array) $settings->get( self::ARTICLE_GROUP, array() );
		$dialogue = is_array( $current['dialogue'] ?? null ) ? $current['dialogue'] : array();
		if ( array() !== $mentee ) {
			$existing           = is_array( $dialogue['mentee'] ?? null ) ? $dialogue['mentee'] : array();
			$dialogue['mentee'] = array_merge( $existing, $mentee );
		}
		$current['dialogue'] = $dialogue;
		$settings->set( self::ARTICLE_GROUP, $current );
	}
	private function dialogue_form_fields(): array {
		$article  = (array) ( new Settings() )->get( self::ARTICLE_GROUP, array() );
		$dialogue = is_array( $article['dialogue'] ?? null ) ? $article['dialogue'] : array();
		$mentee   = is_array( $dialogue['mentee'] ?? null ) ? $dialogue['mentee'] : array();
		$caps     = Status::capabilities();
		$my        = ( new Site_Profile() )->get_my_persona();
		$mentor_id = absint( $my['photo'] ?? 0 );
		$mentee_id = absint( $mentee['image'] ?? 0 );
		return array(
			'dialogue_locked'                 => ! $caps->can_use_dialogue(),
			'dialogue_note'                   => $caps->can_use_dialogue()
				? esc_html__( 'メンターはプロフィール画像をそのまま使い、メンティーはターゲットペルソナを元に生成します。', GACHIREAI_TEXT_DOMAIN )
				: esc_html__( '対話型は PRO ライセンスのみ利用できます。', GACHIREAI_TEXT_DOMAIN ),
			'dialogue_mentor_image_url'       => $mentor_id > 0 ? esc_url( (string) wp_get_attachment_image_url( $mentor_id, 'medium' ) ) : '',
			'dialogue_mentee_name'            => esc_attr( (string) ( $mentee['name'] ?? '' ) ),
			'dialogue_mentee_gender'          => esc_attr( (string) ( $mentee['gender'] ?? '' ) ),
			'dialogue_mentee_age'             => esc_attr( (string) ( $mentee['age'] ?? '' ) ),
			'dialogue_mentee_tone'            => esc_attr( (string) ( $mentee['tone'] ?? '' ) ),
			'dialogue_mentee_image_id'        => $mentee_id,
			'dialogue_mentee_image_url'       => $mentee_id > 0 ? esc_url( (string) wp_get_attachment_image_url( $mentee_id, 'medium' ) ) : '',
			'dialogue_generate_mentee_action' => esc_attr( self::AJAX_ACTION_GENERATE_MENTEE ),
			'dialogue_generate_mentee_label'  => esc_html__( 'AI生成', GACHIREAI_TEXT_DOMAIN ),
			'button_icon'                     => 'robot',
			'dialogue_messages'               => array(),
		);
	}
	private function error_article_transient_key(): string {
		return self::ERROR_ARTICLE_TRANSIENT_PREFIX . get_current_user_id();
	}
	private function article_form(): Form {
		return new Form( $this->article_definitions() );
	}
	private function error_auto_post_transient_key(): string {
		return self::ERROR_AUTO_POST_TRANSIENT_PREFIX . get_current_user_id();
	}
	private function auto_post_form(): Form {
		return new Form( $this->auto_post_definitions() );
	}
	private function all_definitions(): array {
		return array(
			'article_type' => array(
				'label'       => __( '記事タイプ', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'radio',
				'options'     => array(
					'summary'    => __( '総評型', GACHIREAI_TEXT_DOMAIN ),
					'quote'      => __( '引用型', GACHIREAI_TEXT_DOMAIN ),
					'experience' => __( '体験談型', GACHIREAI_TEXT_DOMAIN ),
					'dialogue'   => __( '対話型', GACHIREAI_TEXT_DOMAIN ),
				),
				'disabled'    => array_values(
					array_diff(
						array( 'summary', 'quote', 'experience', 'dialogue' ),
						Status::capabilities()->allowed_article_types()
					)
				),
				'default'     => 'summary',
				'driver'      => 'article_type',
				'description' => __( "生成する記事のタイプです。\n総評型＝複数のレビューを分析し、投稿者の言葉で要約します。\n引用型＝良い評価と悪い評価のレビューを数件引用で列挙し、文末に総評を付けます。\n体験談型＝取得したデータであることを書かず、投稿者のレビューとして文章を書きます。\n対話型＝質問者と回答者の会話でレビューを伝えます（PRO ライセンスのみ）。", GACHIREAI_TEXT_DOMAIN ),
			),
			Cron_Schedule::KEY => array(
				'label'       => __( '投稿時刻', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'cron',
				'description' => __( "設定した時間に記事を生成します。\n最低間隔は1時間です。\n<strong>分</strong>\n0〜59 の整数を 1 つ入力します。\n範囲・間隔・カンマ区切りは使えません。\n<strong>時</strong>\ncrontab 形式で指定できます。\n0〜23 で、数値（例: 9,12,18）・範囲（例: 9-18）・間隔（例: */2）・カンマ区切りが使えます。\n<strong>日・月・曜日</strong>\n「*」固定（毎日実行）。", GACHIREAI_TEXT_DOMAIN ),
			),
			Auto_Post_Scheduler::KEY_ENABLED => array(
				'label'       => __( '自動投稿', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'checkbox',
				'options'     => array(
					'1' => __( '自動投稿を有効にする', GACHIREAI_TEXT_DOMAIN ),
				),
				'disabled'    => Status::capabilities()->can_use_cron() ? array() : array( '1' ),
				'description' => __( "指定時刻に自動生成された記事を投稿（公開）します。", GACHIREAI_TEXT_DOMAIN ),
			),
			Auto_Post_Scheduler::KEY_CRON_MODE => array(
				'label'       => __( '実行方式', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'radio',
				'options'     => array(
					Auto_Post_Scheduler::MODE_WP     => __( 'WP-Cron', GACHIREAI_TEXT_DOMAIN ),
					Auto_Post_Scheduler::MODE_SERVER => __( 'サーバーcron', GACHIREAI_TEXT_DOMAIN ),
				),
				'default'     => Auto_Post_Scheduler::MODE_WP,
				'description' => __( "自動投稿を実行する仕組みを選択してください。\n<strong>WP-Cron</strong>\nWordPressの標準タイマー機能です。\n疑似タイマー機能であり、サイトへの訪問をきっかけに実行されます。\n<strong>＜メリット＞</strong>\n特に設定しなくても動作します。\n<strong>＜デメリット＞</strong>\n訪問が無いと動きません（投稿されません）。\n訪問時に動作するので表示が重くなります。\n<strong>サーバーcron（推奨）</strong>\nサーバーの crontab（タイマー機能）を利用します。\n<strong>＜メリット＞</strong>\n訪問が無くても動作します（投稿されます）。\n定期的に処理を済ませていくので、訪問した際の表示が軽いです。\n<strong>＜デメリット＞</strong>\nサーバー側の設定が必要です。\nただし、設定ファイルは自動生成しますので、サーバーのコントロールパネルでマニュアルに従って簡単な設定をするだけです。", GACHIREAI_TEXT_DOMAIN ),
			),
			'reuse_interval_days' => array(
				'label'       => __( '投稿許可間隔', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'select',
				'options'     => Reuse_Interval::options(),
				'default'     => Reuse_Interval::DEFAULT_DAYS,
				'description' => __( "同じ商品や、同じ商品ジャンルの連続投稿を防止するための設定です。\n<strong>判定の単位</strong>\nサイトのタイプで変わります。\n「総合」＝同じジャンル\n「ジャンル指定」「ニッチ」＝同じ商品\n<strong>設定値</strong>\n一度投稿した商品や商品ジャンルを、次の投稿を許可するまでの間隔を指定してください。\n「制限無」＝制限間隔を設けずすぐに再投稿できます。\n「永久」＝一度記事にしたものは再投稿しません。", GACHIREAI_TEXT_DOMAIN ),
			),
		);
	}
	private function article_definitions(): array {
		$all = $this->all_definitions();
		return array(
			'article_type'        => $all['article_type'],
			'reuse_interval_days' => $all['reuse_interval_days'],
		);
	}
	private function auto_post_definitions(): array {
		$all = $this->all_definitions();
		return array(
			Auto_Post_Scheduler::KEY_ENABLED   => $all[ Auto_Post_Scheduler::KEY_ENABLED ],
			Cron_Schedule::KEY                 => $all[ Cron_Schedule::KEY ],
			Auto_Post_Scheduler::KEY_CRON_MODE => $all[ Auto_Post_Scheduler::KEY_CRON_MODE ],
		);
	}
	public function maybe_save_card(): void {
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( ! isset( $_POST[ self::ACTION_FIELD ] ) ) {
			return;
		}
		if ( self::ACTION_CARD !== sanitize_key( wp_unslash( (string) $_POST[ self::ACTION_FIELD ] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( self::NONCE_ACTION_CARD );
		$this->card_save();
		wp_safe_redirect( Tabs::url( Tabs::TAB_ARTICLE, array( self::SAVED_CARD_ARG => '1' ) ) );
		exit;
	}
	public function ajax_save_card(): void {
		$this->guard_ajax( self::NONCE_ACTION_CARD );
		$this->card_save();
		$this->send_form_html(
			fn(): string => $this->render_card_form(
				array( $this->message( 'success', esc_html__( '保存しました。', GACHIREAI_TEXT_DOMAIN ) ) )
			)
		);
	}
	public function render_card_form( array $messages = array() ): string {
		$model                  = $this->model();
		$model['card_messages'] = $messages;
		return $this->render_template_string( 'admin/article-setup-card-form', $model );
	}
	private function card_save(): void {
		$settings = new Settings();
		$posted   = $this->card_posted();
		$current  = (array) $settings->get( Card_Settings::GROUP_KEY, array() );
		$before_positions = Card_Settings::positions( $settings );
		$clean = array_merge(
			$this->card_form()->sanitize( $posted, $current ),
			Card_Settings::normalize( $posted )
		);
		$settings->set( Card_Settings::GROUP_KEY, $clean );
		$after_positions = Card_Settings::positions( $settings );
		if ( $before_positions !== $after_positions ) {
			Box_Positioner::reposition_all( $after_positions );
		}
		$this->record_setting_save( __( '商品ボックス設定', GACHIREAI_TEXT_DOMAIN ) );
	}
	private function card_posted(): array {
		return $this->posted_array( Card_Settings::GROUP_KEY ) ?? array();
	}
	private function card_definitions(): array {
		$colors = $this->card_color_options();
		$valid_ecs = array();
		foreach ( Providers::valid( new Settings() ) as $provider ) {
			$valid_ecs[] = (string) ( $provider['provider'] ?? '' );
		}
		$ec_disabled = static function ( string $ec ) use ( $valid_ecs ): array {
			return in_array( $ec, $valid_ecs, true ) ? array() : array( '1' );
		};
		$toggle = static function ( array $definition = array() ): array {
			return $definition + array(
				'type'      => 'checkbox',
				'options'   => array( '1' => __( '表示', GACHIREAI_TEXT_DOMAIN ) ),
				'default'   => '1',
				'normalize' => array( Card_Settings::class, 'normalize_toggle' ),
			);
		};
		return array(
			Card_Settings::KEY_POSITIONS        => array(
				'label'       => __( '表示する位置', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'checkbox_multi',
				'options'     => Card_Settings::position_options(),
				'description' => __( "記事の商品ボックスを表示する位置です。\n複数選べます。\nどこも選ばないと表示しません。", GACHIREAI_TEXT_DOMAIN ),
			),
			Card_Settings::KEY_SHOW_RAKUTEN     => $toggle(
				array(
					'label'    => Product_Selector::ec_label( Product_Selector::EC_RAKUTEN ),
					'disabled' => $ec_disabled( Product_Selector::EC_RAKUTEN ),
				)
			),
			Card_Settings::KEY_SHOW_YAHOO       => $toggle(
				array(
					'label'    => Product_Selector::ec_label( Product_Selector::EC_YAHOO ),
					'disabled' => $ec_disabled( Product_Selector::EC_YAHOO ),
				)
			),
			Card_Settings::KEY_SHOW_AMAZON      => $toggle(
				array(
					'label'    => Product_Selector::ec_label( Product_Selector::EC_AMAZON ),
					'disabled' => $ec_disabled( Product_Selector::EC_AMAZON ),
				)
			),
			Card_Settings::KEY_SHOW_NAME        => $toggle( array( 'label' => __( '商品名', GACHIREAI_TEXT_DOMAIN ) ) ),
			Card_Settings::KEY_SHOW_PRICE       => $toggle( array( 'label' => __( '価格', GACHIREAI_TEXT_DOMAIN ) ) ),
			Card_Settings::KEY_SHOW_DISCLOSURE  => $toggle( array( 'label' => __( '広告開示', GACHIREAI_TEXT_DOMAIN ) ) ),
			Card_Settings::KEY_NAME_COLOR       => array(
				'label'     => __( '商品名', GACHIREAI_TEXT_DOMAIN ),
				'type'      => 'color',
				'options'   => $colors,
				'normalize' => array( Card_Settings::class, 'valid_color' ),
			),
			Card_Settings::KEY_FRAME_COLOR      => array(
				'label'     => __( 'カードの枠', GACHIREAI_TEXT_DOMAIN ),
				'type'      => 'color',
				'options'   => $colors,
				'normalize' => array( Card_Settings::class, 'valid_color' ),
			),
			Card_Settings::KEY_BUTTON_COLOR     => array(
				'label'     => __( 'ボタン', GACHIREAI_TEXT_DOMAIN ),
				'type'      => 'color',
				'options'   => $colors,
				'normalize' => array( Card_Settings::class, 'valid_color' ),
			),
			Card_Settings::KEY_DECORATION_COLOR => array(
				'label'     => __( '装飾', GACHIREAI_TEXT_DOMAIN ),
				'type'      => 'color',
				'options'   => $colors,
				'normalize' => array( Card_Settings::class, 'valid_color' ),
			),
		);
	}
	private function card_color_options(): array {
		$palette = (array) wp_get_global_settings( array( 'color', 'palette' ) );
		$options = array();
		foreach ( array( 'theme', 'custom' ) as $origin ) {
			foreach ( (array) ( $palette[ $origin ] ?? array() ) as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$slug = isset( $entry['slug'] ) && is_scalar( $entry['slug'] ) ? (string) $entry['slug'] : '';
				if ( '' === $slug ) {
					continue;
				}
				$variable = 'var(--wp--preset--color--' . $slug . ')';
				$options[] = array(
					'value' => $variable,
					'label' => $variable,
					'hex'   => isset( $entry['color'] ) && is_scalar( $entry['color'] ) ? (string) $entry['color'] : '',
				);
			}
		}
		return $options;
	}
	private function card_form(): Form {
		return new Form( $this->card_definitions() );
	}
	private function card_grid(): array {
		$rows = array();
		$groups = array(
			array(
				'label' => esc_html__( '検索ボタン', GACHIREAI_TEXT_DOMAIN ),
				'keys'  => array_values( Card_Settings::ec_toggle_keys() ),
			),
			array(
				'label' => esc_html__( '要素', GACHIREAI_TEXT_DOMAIN ),
				'keys'  => array( Card_Settings::KEY_SHOW_NAME, Card_Settings::KEY_SHOW_PRICE, Card_Settings::KEY_SHOW_DISCLOSURE ),
			),
		);
		foreach ( $groups as $index => $group ) {
			$rows[] = array(
				'is_first' => 0 === $index,
				'heading'  => esc_html__( '表示切り替え', GACHIREAI_TEXT_DOMAIN ),
				'rowspan'  => count( $groups ),
				'label'    => (string) $group['label'],
				'is_color' => false,
				'checks'   => $this->toggle_checks( (array) $group['keys'] ),
			);
		}
		$colors = array_values( $this->card_color_fields() );
		foreach ( $colors as $index => $field ) {
			$field['is_first'] = 0 === $index;
			$field['heading']  = esc_html__( 'カラー設定', GACHIREAI_TEXT_DOMAIN );
			$field['rowspan']  = count( $colors );
			$field['is_color'] = true;
			$rows[] = $field;
		}
		return $rows;
	}
	private function toggle_checks( array $keys ): array {
		$fields = $this->card_form()->build_list(
			Card_Settings::toggles( new Settings() ),
			$keys,
			Card_Settings::GROUP_KEY
		);
		$checks = array();
		foreach ( $fields as $field ) {
			$option = (array) ( $field['checkboxes'][0] ?? array() );
			$checks[] = array(
				'check_label'    => (string) ( $field['label'] ?? '' ),
				'check_name'     => (string) ( $field['name'] ?? '' ),
				'check_checked'  => ! empty( $option['opt_checked'] ),
				'check_disabled' => ! empty( $option['opt_disabled'] ),
			);
		}
		return $checks;
	}
	private function card_color_fields(): array {
		return $this->card_form()->build_list(
			Card_Settings::colors( new Settings() ),
			array_keys( Card_Settings::color_keys() ),
			Card_Settings::GROUP_KEY
		);
	}
	private function card_positions_fields(): array {
		$values = array(
			Card_Settings::KEY_POSITIONS => implode( ',', Card_Settings::positions( new Settings() ) ),
		);
		return $this->card_form()->build_list(
			$values,
			array( Card_Settings::KEY_POSITIONS ),
			Card_Settings::GROUP_KEY
		);
	}
	private function style_previews(): array {
		if ( array() === $this->style_preview_cache ) {
			$this->style_preview_cache = ( new Box_Sample() )->previews();
		}
		return $this->style_preview_cache;
	}
	private function card_styles(): array {
		$previews = $this->style_previews();
		$selected = Card_Settings::pattern( new Settings() );
		$styles   = array();
		$allowed  = Status::capabilities()->allowed_card_patterns();
		foreach ( Box_Renderer::patterns() as $value => $style_label ) {
			$value    = (string) $value;
			$disabled = ! in_array( $value, $allowed, true );
			$styles[] = array(
				'style_id'      => esc_attr( 'gachireai-card-' . $value ),
				'style_value'   => esc_attr( $value ),
				'style_label'   => esc_html( (string) $style_label ),
				'style_checked' => ! $disabled && $value === $selected,
				'style_disabled' => $disabled,
				'style_preview' => (string) ( $previews[ $value ] ?? '' ),
			);
		}
		return $styles;
	}
	private function card_current(): array {
		$selected = Card_Settings::pattern( new Settings() );
		$previews = $this->style_previews();
		return array(
			'label'   => sprintf(
				esc_html__( '選択中: %s', GACHIREAI_TEXT_DOMAIN ),
				esc_html( $selected )
			),
			'preview' => (string) ( $previews[ $selected ] ?? '' ),
		);
	}
	private function model(): array {
		$notices = array();
		$card_current = $this->card_current();
		return array(
			'page_title'             => esc_html__( '記事設定', GACHIREAI_TEXT_DOMAIN ),
			'notices'                => $notices,
			'tabs'                   => $this->tab_nav(),
			'article_form_action'    => esc_url( Tabs::url( Tabs::TAB_ARTICLE ) ),
			'article_hidden_fields'  => array(
				array(
					'name'  => esc_attr( self::ACTION_FIELD ),
					'value' => esc_attr( self::ACTION_ARTICLE ),
				),
				array(
					'name'  => esc_attr( '_wpnonce' ),
					'value' => esc_attr( wp_create_nonce( self::NONCE_ACTION_ARTICLE ) ),
				),
			),
			'article_title'          => esc_html__( '記事生成投稿設定', GACHIREAI_TEXT_DOMAIN ),
			'article_anchor'         => esc_attr( 'article_settings' ),
			'article_intro'          => esc_html__( '生成する記事のタイプと、同じ商品や商品ジャンルを再投稿できる間隔を決めます。', GACHIREAI_TEXT_DOMAIN ),
			'article_fields'         => $this->article_form()->build_list( $this->article_values(), array_keys( $this->article_definitions() ), self::ARTICLE_GROUP ),
			'article_messages'       => array(),
			'article_submit_label'   => esc_html__( '保存', GACHIREAI_TEXT_DOMAIN ),
			'article_submit_action'  => esc_attr( self::AJAX_ACTION_SAVE_ARTICLE ),
			'auto_post_form_action'   => esc_url( Tabs::url( Tabs::TAB_ARTICLE ) ),
			'auto_post_hidden_fields' => array(
				array(
					'name'  => esc_attr( self::ACTION_FIELD ),
					'value' => esc_attr( self::ACTION_AUTO_POST ),
				),
				array(
					'name'  => esc_attr( '_wpnonce' ),
					'value' => esc_attr( wp_create_nonce( self::NONCE_ACTION_AUTO_POST ) ),
				),
			),
			'auto_post_title'         => esc_html__( '自動投稿設定', GACHIREAI_TEXT_DOMAIN ),
			'auto_post_anchor'        => esc_attr( 'auto_post_settings' ),
			'auto_post_intro'         => esc_html__( '自動投稿機能はPROライセンスのみご利用いただけます。', GACHIREAI_TEXT_DOMAIN ),
			'auto_post_fields'        => $this->auto_post_form()->build_list( $this->auto_post_values(), array_keys( $this->auto_post_definitions() ), self::ARTICLE_GROUP ),
			'auto_post_messages'      => array(),
			'auto_post_submit_label'  => esc_html__( '保存', GACHIREAI_TEXT_DOMAIN ),
			'auto_post_submit_action' => esc_attr( self::AJAX_ACTION_SAVE_AUTO_POST ),
			'card_form_action'       => esc_url( Tabs::url( Tabs::TAB_ARTICLE ) ),
			'card_hidden_fields'     => array(
				array(
					'name'  => esc_attr( self::ACTION_FIELD ),
					'value' => esc_attr( self::ACTION_CARD ),
				),
				array(
					'name'  => esc_attr( '_wpnonce' ),
					'value' => esc_attr( wp_create_nonce( self::NONCE_ACTION_CARD ) ),
				),
			),
			'card_title'             => esc_html__( '商品ボックス設定', GACHIREAI_TEXT_DOMAIN ),
			'card_anchor'            => esc_attr( 'card_settings' ),
			'card_intro'             => esc_html__( "記事に挿入する商品ボックス（アフィリエイトカード）を設定します。\n表示位置は複数選べ、この設定は生成済みの記事にも反映されます。", GACHIREAI_TEXT_DOMAIN ),
			'card_pattern_name'      => esc_attr( Card_Settings::GROUP_KEY . '[' . Card_Settings::KEY_PATTERN . ']' ),
			'card_preview_classes'   => esc_attr( Card_Settings::preview_classes( new Settings() ) ),
			'card_style_title'       => esc_html__( 'スタイル', GACHIREAI_TEXT_DOMAIN ),
			'card_style_summary'     => esc_html__( 'スタイルを選ぶ（10 種）', GACHIREAI_TEXT_DOMAIN ),
			'card_style_note'        => esc_html__( "記事の商品ボックスに使うテンプレートを 1 つ選びます。\nFREEライセンスでは「01」しか選択できません。", GACHIREAI_TEXT_DOMAIN ),
			'card_positions'         => $this->card_positions_fields(),
			'card_grid'              => $this->card_grid(),
			'card_color_note'        => esc_html__( "未設定なら現行（テーマの既定色）のままです。\n複数の設定方法に対応しています。\n・▼をクリックして WordPressやテーマのCSS変数を選択。\n・カラーピッカーから色を選択。\n・入力欄にカラーコード（例: #000000）やCSS変数を直接入力。\nボタンの文字色はボタンの色に応じて白黒を自動で選びます。", GACHIREAI_TEXT_DOMAIN ),
			'card_styles'            => $this->card_styles(),
			'card_current_label'     => $card_current['label'],
			'card_current_preview'   => $card_current['preview'],
			'card_messages'          => array(),
			'card_submit_label'      => esc_html__( '保存', GACHIREAI_TEXT_DOMAIN ),
			'card_submit_action'     => esc_attr( self::AJAX_ACTION_SAVE_CARD ),
		) + $this->eyecatch_form_fields() + $this->dialogue_form_fields();
	}
	private function posted_array( string $key ): ?array {
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return null;
		}
		return (array) wp_unslash( $_POST[ $key ] );
	}
}
