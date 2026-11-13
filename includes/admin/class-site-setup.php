<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Amazon_Client;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Ec\Genre_Catalog;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\Image\Favicon;
use Tonkatsutei\Gachi_Review_AI\Image\Font_Catalog;
use Tonkatsutei\Gachi_Review_AI\Image\Font_Manager;
use Tonkatsutei\Gachi_Review_AI\Image\Font_Preview;
use Tonkatsutei\Gachi_Review_AI\Image\Image_Catalog;
use Tonkatsutei\Gachi_Review_AI\Image\Manager as Image_Manager;
use Tonkatsutei\Gachi_Review_AI\Image\Profile;
use Tonkatsutei\Gachi_Review_AI\Image\Top_Eyecatch;
use Tonkatsutei\Gachi_Review_AI\License\Capabilities;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Llm\Keyword_Proposer;
use Tonkatsutei\Gachi_Review_AI\Llm\Style_Proposer;
use Tonkatsutei\Gachi_Review_AI\Policy\Policy_Page;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Generator;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
use Tonkatsutei\Gachi_Review_AI\Top\Top_Page;
use Tonkatsutei\Gachi_Review_AI\Widget\Disclosure;
final class Site_Setup extends Screen {
	public const ACTION = 'save_persona';
	private const NONCE_ACTION = 'gachireai_save_persona';
	private const ACTION_FIELD = 'gachireai_action';
	private const SAVED_ARG = 'gachireai_saved';
	private const GENERATE_FIELD = 'gachireai_generate';
	private const SAVE_SECTION_FIELD = 'gachireai_save_section';
	private const SECTION_SCOPE = 'scope';
	private const SECTION_PERSONA = 'my_persona';
	private const SECTION_TARGET = 'target';
	private const ACTION_DISCLOSURE = 'save_disclosure';
	private const NONCE_ACTION_DISCLOSURE = 'gachireai_save_disclosure';
	private const DISCLOSURE_GROUP = 'disclosure';
	private const SCOPE_CONFIRM_FIELD = 'gachireai_scope_confirm_delete';
	private const SCOPE_MIXED_ARG = 'gachireai_scope_mixed';
	private const SCOPE_MIXED_TRANSIENT_PREFIX = 'gachireai_scope_mixed_';
	public const AJAX_ACTION_GENERATE = 'gachireai_generate_persona';
	public const AJAX_ACTION_GENERATE_TARGET = 'gachireai_generate_target';
	public const AJAX_ACTION_GENERATE_ELEMENTS = 'gachireai_generate_elements';
	public const AJAX_ACTION_SAVE = 'gachireai_save_persona';
	public const AJAX_ACTION_SAVE_ELEMENTS = 'gachireai_save_elements';
	public const AJAX_ACTION_GENERATE_TOP = 'gachireai_generate_top_page';
	public const AJAX_ACTION_GENERATE_POLICY = 'gachireai_generate_policy_page';
	public const AJAX_ACTION_CATEGORY_OPEN = 'gachireai_open_site_setup_category';
	public const AJAX_ACTION_CATEGORY_PICK = 'gachireai_pick_site_setup_category';
	public const AJAX_ACTION_PROPOSE_KEYWORDS = 'gachireai_propose_site_keywords';
	public const AJAX_ACTION_GENERATE_FAVICON = 'gachireai_generate_site_favicon';
	private const GENERATE_FAVICON_FIELD = 'gachireai_generate_site_favicon';
	public const AJAX_ACTION_GENERATE_PROFILE_PHOTO = 'gachireai_generate_profile_photo';
	private const GENERATE_PROFILE_PHOTO_FIELD = 'gachireai_generate_profile_photo';
	public const AJAX_ACTION_GENERATE_TOP_EYECATCH = 'gachireai_generate_top_eyecatch';
	private const GENERATE_TOP_EYECATCH_FIELD = 'gachireai_generate_top_eyecatch';
	public const AJAX_ACTION_UPLOAD_FONT = 'gachireai_upload_eyecatch_font';
	public const AJAX_ACTION_FONT_PREVIEW = 'gachireai_font_preview';
	private const FONT_UPLOAD_FIELD = 'gachireai_font_file';
	private const UPLOAD_FONT_FIELD = 'gachireai_upload_font';
	private const PICKER_ID = 'gachireai-category-picker';
	private const PICKER_BODY_ID = 'gachireai-category-picker-body';
	private const CATEGORY_PARENT_FIELD = 'gachireai_category_parent';
	private const CATEGORY_ID_FIELD = 'gachireai_category_id';
	private const SCOPE_EC_KEY = 'ec';
	private const KEYWORD_FIELD = self::SCOPE_KEY . '[products]';
	private const PROPOSE_KEYWORDS_COUNT = 10;
	private const BLOCK_MY_PERSONA_MEMO = 'my_persona_memo';
	private const BLOCK_TARGET_MEMO = 'target_memo';
	private const GENERATED_ARG = 'gachireai_generated';
	private const GENERATED_TRANSIENT_PREFIX = 'gachireai_persona_gen_';
	private const PROFILE_KEY = 'my_persona';
	private const SCOPE_KEY = 'scope';
	private const FREE_TEXT_KEY = 'free_text';
	private const PHOTO_KEY = 'photo';
	private const MEMO_KEYS = array( 'gender', 'tone', self::FREE_TEXT_KEY );
	private const PERSONA_KEYS = array( 'name', 'author_slug', 'attributes', 'background', 'knowledge_level', 'writing_style', 'position', 'stance' );
	private const TARGET_KEY = 'target_persona';
	private const TARGET_GENERATE_FIELD = 'gachireai_generate_target';
	private const TARGET_MEMO_KEYS = array( self::FREE_TEXT_KEY );
	private const TARGET_PERSONA_KEYS = array( 'attributes', 'worries', 'wants', 'knowledge_level', 'search_flow', 'purchase_behavior' );
	public const ACTION_ELEMENTS = 'save_site_elements';
	public const ACTION_SITE_IMAGES = 'save_site_images';
	private const NONCE_ACTION_SITE_IMAGES = 'gachireai_save_site_images';
	public const AJAX_ACTION_SAVE_SITE_IMAGES = 'gachireai_save_site_images';
	public const AJAX_ACTION_PROPOSE_STYLE = 'gachireai_propose_image_style';
	private const GENERATE_STYLE_FIELD = 'gachireai_propose_image_style';
	private const SITE_IMAGES_SAVED_ARG = 'gachireai_site_images_saved';
	private const SITE_IMAGES_GENERATED_ARG = 'gachireai_site_images_generated';
	private const SITE_IMAGES_NOTICE_TRANSIENT_PREFIX = 'gachireai_site_images_notice_';
	private const NONCE_ACTION_ELEMENTS = 'gachireai_save_site_elements';
	private const ELEMENTS_KEY = 'site_elements';
	private const SITE_IMAGES_KEY = 'site_images';
	private const TOP_EYECATCH_KEY = 'top_eyecatch';
	private const IMAGE_KEY = 'image';
	private const GENERATE_ALL_FIELD = 'gachireai_generate_all';
	private const REFLECTED_ARG = 'gachireai_reflected';
	private const ELEMENTS_GENERATED_ARG = 'gachireai_elements_generated';
	private const ELEMENTS_NOTICE_TRANSIENT_PREFIX = 'gachireai_elements_notice_';
	private const ELEMENTS_TRANSIENT_PREFIX = 'gachireai_elements_candidates_';
	private const ELEMENTS_TRANSIENT_TTL = 1800;
	private const WRITING_SAMPLE_KEY = 'writing_sample';
	private const AUTHOR_SLUG_KEY = 'author_slug';
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$profile   = new Site_Profile();
		$mixed     = array();
		$overrides = array();
		if ( isset( $_GET[ self::SCOPE_MIXED_ARG ] ) ) {
			$state = get_transient( self::SCOPE_MIXED_TRANSIENT_PREFIX . get_current_user_id() );
			if ( is_array( $state ) && isset( $state['mixed'] ) && is_array( $state['mixed'] ) ) {
				$mixed  = $state['mixed'];
				$values = is_array( $state['values'] ?? null ) ? (array) $state['values'] : array();
				if ( isset( $values[ self::PROFILE_KEY ] ) && is_array( $values[ self::PROFILE_KEY ] ) ) {
					$overrides[ self::PROFILE_KEY ] = $this->sanitize_persona( (array) $values[ self::PROFILE_KEY ] );
				}
				if ( isset( $values[ self::SCOPE_KEY ] ) && is_array( $values[ self::SCOPE_KEY ] ) ) {
					$overrides[ self::SCOPE_KEY ] = $this->sanitize_scope( (array) $values[ self::SCOPE_KEY ] );
				}
				if ( isset( $values[ self::TARGET_KEY ] ) && is_array( $values[ self::TARGET_KEY ] ) ) {
					$overrides[ self::TARGET_KEY ] = $this->sanitize_target_persona( (array) $values[ self::TARGET_KEY ] );
				}
			}
		}
		$model = $this->model( $profile, $overrides );
		if ( array() !== $mixed ) {
			$model['sections'] = $this->with_section_messages(
				(array) $model['sections'],
				array( self::SECTION_SCOPE => array( $this->scope_mixed_message( $mixed ) ) )
			);
			$model = array_merge( $model, $this->scope_mixed_model( $mixed ) );
		}
		$this->render_template( 'admin/site-setup', $model );
	}
	public function maybe_save(): void {
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( ! isset( $_POST[ self::ACTION_FIELD ] ) ) {
			return;
		}
		if ( self::ACTION !== sanitize_key( wp_unslash( (string) $_POST[ self::ACTION_FIELD ] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( self::NONCE_ACTION );
		$section     = $this->save_section();
		$all         = null === $section;
		$raw_persona = ( $all || self::SECTION_PERSONA === $section ) ? $this->posted_array( self::PROFILE_KEY ) : null;
		$raw_scope   = ( $all || self::SECTION_SCOPE === $section ) ? $this->posted_array( self::SCOPE_KEY ) : null;
		$raw_target  = ( $all || self::SECTION_TARGET === $section ) ? $this->posted_array( self::TARGET_KEY ) : null;
		$generate_my     = isset( $_POST[ self::GENERATE_FIELD ] );
		$generate_target = isset( $_POST[ self::TARGET_GENERATE_FIELD ] );
		$result          = $this->handle_submission(
			$raw_persona,
			$raw_scope,
			$raw_target,
			$generate_my,
			$generate_target
		);
		if ( array() !== ( $result['mixed'] ?? array() ) ) {
			set_transient(
				self::SCOPE_MIXED_TRANSIENT_PREFIX . get_current_user_id(),
				array(
					'mixed'  => (array) $result['mixed'],
					'values' => array(
						self::PROFILE_KEY => $raw_persona,
						self::SCOPE_KEY   => $raw_scope,
						self::TARGET_KEY  => $raw_target,
					),
				),
				60
			);
			wp_safe_redirect( Tabs::url( Tabs::TAB_SITE, array( self::SCOPE_MIXED_ARG => '1' ) ) );
			exit;
		}
		if ( ! $generate_my && ! $generate_target ) {
			$this->record_setting_save( __( 'サイト設定', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( null !== $result['notice'] ) {
			set_transient(
				self::GENERATED_TRANSIENT_PREFIX . get_current_user_id(),
				$result['notice'],
				60
			);
		}
		wp_safe_redirect( Tabs::url( Tabs::TAB_SITE, array( $result['arg'] => '1' ) ) );
		exit;
	}
	public function maybe_save_disclosure(): void {
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( ! isset( $_POST[ self::ACTION_FIELD ] ) ) {
			return;
		}
		if ( self::ACTION_DISCLOSURE !== sanitize_key( wp_unslash( (string) $_POST[ self::ACTION_FIELD ] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( self::NONCE_ACTION_DISCLOSURE );
		$settings = new Settings();
		$posted   = $this->posted_array( self::DISCLOSURE_GROUP ) ?? array();
		$flat     = array(
			'heading' => $settings->disclosure_heading(),
		);
		foreach ( $settings->disclosure() as $key => $item ) {
			$flat[ $key . '_enabled' ] = $item['enabled'];
			$flat[ $key . '_text' ]    = $item['text'];
		}
		foreach ( $settings->image_annotation() as $key => $item ) {
			$flat[ $key . '_enabled' ] = $item['enabled'];
			$flat[ $key . '_text' ]    = $item['text'];
		}
		$form  = new Form( $this->disclosure_definitions() );
		$clean = $form->sanitize( $posted, $flat );
		$old_heading = $settings->disclosure_heading();
		$settings->set( self::DISCLOSURE_GROUP, $clean );
		$this->record_setting_save( __( '開示', GACHIREAI_TEXT_DOMAIN ) );
		$new_heading = $settings->disclosure_heading();
		if ( $new_heading !== $old_heading ) {
			Disclosure::sync_titles( $new_heading );
		}
		Disclosure::purge_caches();
		if ( self::has_enabled_widget_item( $clean ) ) {
			Disclosure::ensure_installed();
		}
		wp_safe_redirect( Tabs::url( Tabs::TAB_SITE, array( 'gachireai_disclosure_saved' => '1' ) ) . '#gachireai-section-disclosure' );
		exit;
	}
	private static function has_enabled_widget_item( array $clean ): bool {
		foreach ( Settings::DISCLOSURE_KEYS as $key ) {
			if ( '1' === (string) ( $clean[ $key . '_enabled' ] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}
	private function disclosure_definitions(): array {
		$widget_items = array(
			'affiliate' => __( 'アフィリエイト', GACHIREAI_TEXT_DOMAIN ),
			'review'    => __( 'AIレビュー', GACHIREAI_TEXT_DOMAIN ),
			'article'   => __( 'AI生成記事', GACHIREAI_TEXT_DOMAIN ),
		);
		$annotation_items = array(
			'image_top'     => array(
				'label'    => __( 'AI生成画像（トップページ）', GACHIREAI_TEXT_DOMAIN ),
				'checkbox' => __( 'トップページのトップ画の下に表示する', GACHIREAI_TEXT_DOMAIN ),
			),
			'image_article' => array(
				'label'    => __( 'AI生成画像（個別記事）', GACHIREAI_TEXT_DOMAIN ),
				'checkbox' => __( '個別記事のトップ画の下に表示する', GACHIREAI_TEXT_DOMAIN ),
			),
		);
		$definitions = array(
			'heading' => array(
				'label'       => __( 'ウィジェットタイトル', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 100,
				'placeholder' => Settings::DISCLOSURE_DEFAULT_HEADING,
			),
		);
		foreach ( $widget_items as $key => $label ) {
			$definitions[ $key . '_enabled' ] = array(
				'label'   => sprintf(
					__( '%sの開示', GACHIREAI_TEXT_DOMAIN ),
					$label
				),
				'type'    => 'checkbox',
				'options' => array( '1' => __( '表示する', GACHIREAI_TEXT_DOMAIN ) ),
			);
			$definitions[ $key . '_text' ] = array(
				'label'       => __( '開示文', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'textarea',
				'rows'        => 2,
				'max'         => 300,
				'placeholder' => Settings::DISCLOSURE_DEFAULT_TEXTS[ $key ],
			);
		}
		foreach ( $annotation_items as $key => $item ) {
			$definitions[ $key . '_enabled' ] = array(
				'label'   => $item['label'],
				'type'    => 'checkbox',
				'options' => array( '1' => $item['checkbox'] ),
			);
			$definitions[ $key . '_text' ] = array(
				'label'       => __( '注釈文', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'textarea',
				'rows'        => 2,
				'max'         => 300,
				'placeholder' => Settings::IMAGE_ANNOTATION_DEFAULT_TEXTS[ $key ],
			);
		}
		return $definitions;
	}
	private function disclosure_blocks(): array {
		$settings   = new Settings();
		$disclosure = $settings->disclosure();
		$annotation = $settings->image_annotation();
		$widget_labels = array(
			'affiliate' => __( 'アフィリエイト', GACHIREAI_TEXT_DOMAIN ),
			'review'    => __( 'AIレビュー', GACHIREAI_TEXT_DOMAIN ),
			'article'   => __( 'AI生成記事', GACHIREAI_TEXT_DOMAIN ),
		);
		$widget_rows   = array(
			array(
				'has_th'         => true,
				'th_label'       => esc_html__( 'ウィジェットタイトル', GACHIREAI_TEXT_DOMAIN ),
				'is_input'       => true,
				'is_item'        => false,
				'input_name'     => esc_attr( self::DISCLOSURE_GROUP . '[heading]' ),
				'input_value'    => esc_attr( $settings->disclosure_heading() ),
				'checkbox_name'  => '',
				'checkbox_label' => '',
				'checked'        => false,
				'text_name'      => '',
				'text_value'     => '',
				'placeholder'    => esc_attr( Settings::DISCLOSURE_DEFAULT_HEADING ),
			),
		);
		foreach ( $widget_labels as $key => $label ) {
			$widget_rows[] = $this->disclosure_item_row(
				$key,
				$label,
				__( '表示する', GACHIREAI_TEXT_DOMAIN ),
				$disclosure[ $key ]['enabled'],
				$disclosure[ $key ]['text'],
				Settings::DISCLOSURE_DEFAULT_TEXTS[ $key ]
			);
		}
		$annotation_rows = array(
			$this->disclosure_item_row(
				'image_top',
				__( 'トップページ', GACHIREAI_TEXT_DOMAIN ),
				__( '画像の下に表示する', GACHIREAI_TEXT_DOMAIN ),
				$annotation['image_top']['enabled'],
				$annotation['image_top']['text'],
				Settings::IMAGE_ANNOTATION_DEFAULT_TEXTS['image_top']
			),
			$this->disclosure_item_row(
				'image_article',
				__( '個別記事', GACHIREAI_TEXT_DOMAIN ),
				__( '画像の下に表示する', GACHIREAI_TEXT_DOMAIN ),
				$annotation['image_article']['enabled'],
				$annotation['image_article']['text'],
				Settings::IMAGE_ANNOTATION_DEFAULT_TEXTS['image_article']
			),
		);
		return array(
			array(
				'block_key'   => 'disclosure_widget',
				'block_title' => esc_html__( '開示ウィジェット', GACHIREAI_TEXT_DOMAIN ),
				'rows'        => $widget_rows,
			),
			array(
				'block_key'   => 'disclosure_image_annotation',
				'block_title' => esc_html__( '画像注釈', GACHIREAI_TEXT_DOMAIN ),
				'rows'        => $annotation_rows,
			),
		);
	}
	private function disclosure_item_row( string $key, string $th_label, string $checkbox_label, string $enabled, string $text, string $placeholder ): array {
		$prefix = self::DISCLOSURE_GROUP . '[' . $key;
		return array(
			'has_th'         => true,
			'th_label'       => esc_html( $th_label ),
			'is_input'       => false,
			'is_item'        => true,
			'input_name'     => '',
			'input_value'    => '',
			'checkbox_name'  => esc_attr( $prefix . '_enabled]' ),
			'checkbox_label' => esc_html( $checkbox_label ),
			'checked'        => '1' === $enabled,
			'text_name'      => esc_attr( $prefix . '_text]' ),
			'text_value'     => esc_html( $text ),
			'placeholder'    => esc_attr( $placeholder ),
		);
	}
	public function maybe_save_elements(): void {
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( ! isset( $_POST[ self::ACTION_FIELD ] ) ) {
			return;
		}
		if ( self::ACTION_ELEMENTS !== sanitize_key( wp_unslash( (string) $_POST[ self::ACTION_FIELD ] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( self::NONCE_ACTION_ELEMENTS );
		$raw_elements = isset( $_POST[ self::ELEMENTS_KEY ] ) && is_array( $_POST[ self::ELEMENTS_KEY ] )
			? (array) wp_unslash( $_POST[ self::ELEMENTS_KEY ] )
			: array();
		$result       = $this->handle_elements_submission(
			$raw_elements,
			isset( $_POST[ self::GENERATE_ALL_FIELD ] )
		);
		if ( null !== $result['notice'] ) {
			set_transient(
				self::ELEMENTS_NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
				$result['notice'],
				60
			);
		}
		wp_safe_redirect( Tabs::url( Tabs::TAB_SITE, array( $result['arg'] => '1' ) ) );
		exit;
	}
	public function maybe_save_site_images(): void {
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( ! isset( $_POST[ self::ACTION_FIELD ] ) ) {
			return;
		}
		if ( self::ACTION_SITE_IMAGES !== sanitize_key( wp_unslash( (string) $_POST[ self::ACTION_FIELD ] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( self::NONCE_ACTION_SITE_IMAGES );
		if ( isset( $_POST[ self::UPLOAD_FONT_FIELD ] ) ) {
			$this->save_posted_site_images();
			try {
				$file = isset( $_FILES[ self::FONT_UPLOAD_FIELD ] ) && is_array( $_FILES[ self::FONT_UPLOAD_FIELD ] )
					? (array) $_FILES[ self::FONT_UPLOAD_FIELD ]
					: array();
				$result = Font_Manager::upload( $file );
				set_transient(
					self::SITE_IMAGES_NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
					array(
						'type'    => 'success',
						'message' => sprintf(
							__( "フォント「%s」を追加しました。\n「保存」を押すと確定します。", GACHIREAI_TEXT_DOMAIN ),
							(string) $result['label']
						),
						'font'    => (string) $result['slug'],
					),
					60
				);
			} catch ( \Throwable $e ) {
				set_transient(
					self::SITE_IMAGES_NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
					array(
						'type'    => 'error',
						'message' => $e->getMessage(),
					),
					60
				);
			}
			wp_safe_redirect( Tabs::url( Tabs::TAB_SITE, array( self::SITE_IMAGES_GENERATED_ARG => '1' ) ) );
			exit;
		}
		if ( isset( $_POST[ self::GENERATE_FAVICON_FIELD ] ) ) {
			$this->save_posted_site_images();
			$result = $this->generate_favicon_result();
			set_transient(
				self::SITE_IMAGES_NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
				array(
					'type'          => $result['type'],
					'message'       => $result['message'],
					'kind'          => (string) ( $result['kind'] ?? '' ),
					'attachment_id' => (int) ( $result['attachment_id'] ?? 0 ),
				),
				60
			);
			wp_safe_redirect( Tabs::url( Tabs::TAB_SITE, array( self::SITE_IMAGES_GENERATED_ARG => '1' ) ) );
			exit;
		}
		if ( isset( $_POST[ self::GENERATE_PROFILE_PHOTO_FIELD ] ) ) {
			$this->save_posted_site_images();
			$result = $this->generate_profile_result();
			set_transient(
				self::SITE_IMAGES_NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
				array(
					'type'          => $result['type'],
					'message'       => $result['message'],
					'kind'          => (string) ( $result['kind'] ?? '' ),
					'attachment_id' => (int) ( $result['attachment_id'] ?? 0 ),
				),
				60
			);
			wp_safe_redirect( Tabs::url( Tabs::TAB_SITE, array( self::SITE_IMAGES_GENERATED_ARG => '1' ) ) );
			exit;
		}
		if ( isset( $_POST[ self::GENERATE_TOP_EYECATCH_FIELD ] ) ) {
			$this->save_posted_site_images();
			$result = $this->generate_top_eyecatch_result();
			set_transient(
				self::SITE_IMAGES_NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
				array(
					'type'          => $result['type'],
					'message'       => $result['message'],
					'kind'          => (string) ( $result['kind'] ?? '' ),
					'attachment_id' => (int) ( $result['attachment_id'] ?? 0 ),
				),
				60
			);
			wp_safe_redirect( Tabs::url( Tabs::TAB_SITE, array( self::SITE_IMAGES_GENERATED_ARG => '1' ) ) );
			exit;
		}
		$this->save_posted_site_images();
		set_transient(
			self::SITE_IMAGES_NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
			array(
				'type'    => 'success',
				'message' => __( '保存しました。', GACHIREAI_TEXT_DOMAIN ),
			),
			60
		);
		wp_safe_redirect( Tabs::url( Tabs::TAB_SITE, array( self::SITE_IMAGES_SAVED_ARG => '1' ) ) );
		exit;
	}
	public function ajax_generate_persona(): void {
		$this->persona_ajax( true, false, self::BLOCK_MY_PERSONA_MEMO );
	}
	public function ajax_generate_target(): void {
		$this->persona_ajax( false, true, self::BLOCK_TARGET_MEMO );
	}
	public function ajax_save_persona(): void {
		$this->persona_ajax( false, false, '', $this->save_section() );
	}
	public function ajax_propose_keywords(): void {
		$this->guard_ajax( self::NONCE_ACTION );
		$values   = $this->posted_all_values();
		$scope    = (array) ( $values[ self::SCOPE_KEY ] ?? array() );
		$current  = (string) ( $scope['products'] ?? '' );
		$existing = Product_Selector::words( $current );
		$messages = array();
		try {
			$new   = ( new Keyword_Proposer() )->propose( $scope, $existing, self::PROPOSE_KEYWORDS_COUNT );
			$added = count( $new );
			if ( 0 === $added ) {
				$messages[] = $this->message(
					'error',
					esc_html__( "新しく追加できるキーワードを提案できませんでした。\n既存のキーワードを見直してから、もう一度お試しください。", GACHIREAI_TEXT_DOMAIN )
				);
			} else {
				$values[ self::SCOPE_KEY ]['products'] = self::append_keywords( $current, $new );
				if ( self::PROPOSE_KEYWORDS_COUNT === $added ) {
					$messages[] = $this->message(
						'success',
						esc_html(
							sprintf(
								__( "キーワードを %d 件、入力欄の最終行へ追記しました。\n内容を確認して「保存」を押してください。", GACHIREAI_TEXT_DOMAIN ),
								$added
							)
						)
					);
				} else {
					$messages[] = $this->message(
						'warning',
						esc_html(
							sprintf(
								__( "重複を除いたところ %1\$d 件だけ追記できました（%2\$d 件には届きませんでした）。\nもう一度押すと追加で提案します。", GACHIREAI_TEXT_DOMAIN ),
								$added,
								self::PROPOSE_KEYWORDS_COUNT
							)
						)
					);
				}
			}
		} catch ( \Throwable $e ) {
			$messages[] = $this->message( 'error', esc_html( $e->getMessage() ) );
		}
		$this->send_form_html(
			fn(): string => $this->render_persona_form( array(), array(), $values, array(), array( self::KEYWORD_FIELD => $messages ) )
		);
	}
	private static function append_keywords( string $current, array $new ): string {
		$parts = array();
		foreach ( $new as $word ) {
			$word = trim( (string) $word );
			if ( '' !== $word ) {
				$parts[] = Product_Selector::quote_word( $word );
			}
		}
		if ( array() === $parts ) {
			return $current;
		}
		$line    = implode( ', ', $parts );
		$current = trim( $current );
		return '' === $current ? $line : $current . "\n" . $line;
	}
	private function posted_all_values(): array {
		return array(
			self::PROFILE_KEY => $this->sanitize_persona( $this->posted_array( self::PROFILE_KEY ) ?? array() ),
			self::SCOPE_KEY   => $this->sanitize_scope( $this->posted_array( self::SCOPE_KEY ) ?? array() ),
			self::TARGET_KEY  => $this->sanitize_target_persona( $this->posted_array( self::TARGET_KEY ) ?? array() ),
		);
	}
	private function persona_ajax( bool $generate_my, bool $generate_target, string $block_key, ?string $section = null ): void {
		$this->guard_ajax( self::NONCE_ACTION );
		$all = null === $section;
		$result           = $this->handle_submission(
			( $all || self::SECTION_PERSONA === $section ) ? $this->posted_array( self::PROFILE_KEY ) : null,
			( $all || self::SECTION_SCOPE === $section ) ? $this->posted_array( self::SCOPE_KEY ) : null,
			( $all || self::SECTION_TARGET === $section ) ? $this->posted_array( self::TARGET_KEY ) : null,
			$generate_my,
			$generate_target
		);
		if ( array() !== ( $result['mixed'] ?? array() ) ) {
			$mixed = (array) $result['mixed'];
			$this->send_form_html(
				fn(): string => $this->render_persona_form(
					array(),
					array(),
					$this->posted_all_values(),
					array( self::SECTION_SCOPE => array( $this->scope_mixed_message( $mixed ) ) ),
					array(),
					$mixed
				)
			);
			return;
		}
		if ( ! $generate_my && ! $generate_target ) {
			$this->record_setting_save( __( 'サイト設定', GACHIREAI_TEXT_DOMAIN ) );
		}
		$block_messages   = array();
		$form_messages    = array();
		$section_messages = array();
		if ( null !== $result['notice'] ) {
			$message = $this->message( (string) $result['notice']['type'], esc_html( (string) $result['notice']['message'] ) );
			if ( '' !== $block_key ) {
				$block_messages[ $block_key ] = array( $message );
			} elseif ( null !== $section ) {
				$section_messages[ $section ] = array( $message );
			} else {
				$form_messages[] = $message;
			}
		} elseif ( null !== $section ) {
			$section_messages[ $section ] = array( $this->message( 'success', esc_html__( '保存しました。', GACHIREAI_TEXT_DOMAIN ) ) );
		} else {
			$form_messages[] = $this->message( 'success', esc_html__( '保存しました。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$this->send_form_html(
			fn(): string => $this->render_persona_form( $block_messages, $form_messages, array(), $section_messages )
		);
	}
	public function ajax_generate_elements(): void {
		$this->elements_ajax( true );
	}
	public function ajax_generate_favicon(): void {
		$this->guard_ajax( self::NONCE_ACTION_SITE_IMAGES );
		$this->save_posted_site_images();
		$result  = $this->generate_favicon_result();
		$message = $this->message( (string) $result['type'], esc_html( (string) $result['message'] ) );
		$this->send_form_html(
			fn(): string => $this->render_site_images_form(
				array(),
				array( 'favicon' => (int) ( $result['attachment_id'] ?? 0 ) ),
				array( 'favicon' => array( $message ) )
			)
		);
	}
	public function ajax_generate_profile_photo(): void {
		$this->guard_ajax( self::NONCE_ACTION_SITE_IMAGES );
		$this->save_posted_site_images();
		$result  = $this->generate_profile_result();
		$message = $this->message( (string) $result['type'], esc_html( (string) $result['message'] ) );
		$this->send_form_html(
			fn(): string => $this->render_site_images_form(
				array(),
				array( 'profile' => (int) ( $result['attachment_id'] ?? 0 ) ),
				array( 'profile' => array( $message ) )
			)
		);
	}
	public function ajax_generate_top_eyecatch(): void {
		$this->guard_ajax( self::NONCE_ACTION_SITE_IMAGES );
		$this->save_posted_site_images();
		$site_name = isset( $_POST['site_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['site_name'] ) ) : null;
		$site_description = isset( $_POST['site_description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['site_description'] ) ) : null;
		$result  = $this->generate_top_eyecatch_result( $site_name, $site_description );
		$message = $this->message( (string) $result['type'], esc_html( (string) $result['message'] ) );
		$this->send_form_html(
			fn(): string => $this->render_site_images_form(
				array(),
				array( 'top_eyecatch' => (int) ( $result['attachment_id'] ?? 0 ) ),
				array( 'top_eyecatch' => array( $message ) )
			)
		);
	}
	public function ajax_save_site_images(): void {
		$this->guard_ajax( self::NONCE_ACTION_SITE_IMAGES );
		$this->save_posted_site_images();
		$this->send_form_html(
			fn(): string => $this->render_site_images_form(
				array( $this->message( 'success', esc_html__( '保存しました。', GACHIREAI_TEXT_DOMAIN ) ) )
			)
		);
	}
	public function ajax_upload_font(): void {
		$this->guard_ajax( self::NONCE_ACTION_SITE_IMAGES );
		$this->save_posted_site_images();
		$messages  = array();
		$overrides = array();
		$file = isset( $_FILES[ self::FONT_UPLOAD_FIELD ] ) && is_array( $_FILES[ self::FONT_UPLOAD_FIELD ] )
			? (array) $_FILES[ self::FONT_UPLOAD_FIELD ]
			: array();
		try {
			$result = Font_Manager::upload( $file );
			$messages[] = $this->message(
				'success',
				esc_html(
					sprintf(
						__( "フォント「%s」を追加しました。\n「保存」を押すと確定します。", GACHIREAI_TEXT_DOMAIN ),
						(string) $result['label']
					)
				)
			);
			$overrides['font']        = (string) $result['slug'];
			$overrides['font_weight'] = Font_Manager::default_weight( (string) $result['slug'] );
		} catch ( \Throwable $e ) {
			$messages[] = $this->message( 'error', esc_html( $e->getMessage() ) );
		}
		$this->send_form_html(
			fn(): string => $this->render_site_images_form( array(), $overrides, array( 'font' => $messages ) )
		);
	}
	public function ajax_font_preview(): void {
		$this->guard_ajax( self::NONCE_ACTION_SITE_IMAGES );
		$font = isset( $_POST['font'] ) ? sanitize_key( wp_unslash( (string) $_POST['font'] ) ) : Font_Catalog::DEFAULT_FONT;
		$weight = isset( $_POST['font_weight'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['font_weight'] ) ) : Font_Catalog::DEFAULT_WEIGHT;
		$top_eyecatch_id = isset( $_POST['top_eyecatch'] ) ? absint( wp_unslash( (string) $_POST['top_eyecatch'] ) ) : 0;
		$site_name = isset( $_POST['site_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['site_name'] ) ) : null;
		$site_description = isset( $_POST['site_description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['site_description'] ) ) : null;
		$font   = Font_Manager::normalize_font( $font );
		$weight = Font_Manager::normalize_weight( $font, $weight );
		try {
			$preview = Font_Preview::render( $font, $weight, $top_eyecatch_id, $site_name, $site_description );
			wp_send_json_success( $preview );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
		}
	}
	public function ajax_save_elements(): void {
		$this->elements_ajax( false );
	}
	public function ajax_generate_top_page(): void {
		$this->guard_ajax( self::NONCE_ACTION_ELEMENTS );
		$result  = $this->generate_top_page( new Site_Profile() );
		$message = $this->message( (string) $result['type'], esc_html( (string) $result['message'] ) );
		$message['msg_links'] = (array) ( $result['links'] ?? array() );
		$this->send_form_html(
			fn(): string => $this->render_static_pages_form( array( $message ) )
		);
	}
	public function ajax_generate_policy_page(): void {
		$this->guard_ajax( self::NONCE_ACTION_ELEMENTS );
		$result  = $this->generate_policy_page();
		$message = $this->message( (string) $result['type'], esc_html( (string) $result['message'] ) );
		$message['msg_links'] = (array) ( $result['links'] ?? array() );
		$this->send_form_html(
			fn(): string => $this->render_static_pages_form( array(), array( $message ) )
		);
	}
	private function generate_top_page( Site_Profile $profile ): array {
		$page_id = Top_Page::generate( $profile );
		if ( 0 >= $page_id ) {
			return array(
				'type'    => 'error',
				'message' => __( "トップページの生成に失敗しました。\n時間をおいて、もう一度お試しください。", GACHIREAI_TEXT_DOMAIN ),
				'links'   => array(),
			);
		}
		$this->record_setting_save( __( 'トップページ', GACHIREAI_TEXT_DOMAIN ) );
		return array(
			'type'    => 'success',
			'message' => __( "トップページを生成し、ホームページの表示に設定しました。\n以前のトップページは下書きに変更しています。", GACHIREAI_TEXT_DOMAIN ),
			'links'   => $this->page_notice_links( $page_id ),
		);
	}
	private function generate_policy_page(): array {
		$page_id = Policy_Page::generate();
		if ( 0 >= $page_id ) {
			return array(
				'type'    => 'error',
				'message' => __( "プライバシーポリシーの生成に失敗しました。\n時間をおいて、もう一度お試しください。", GACHIREAI_TEXT_DOMAIN ),
				'links'   => array(),
			);
		}
		$this->record_setting_save( __( 'プライバシーポリシー', GACHIREAI_TEXT_DOMAIN ) );
		return array(
			'type'    => 'success',
			'message' => __( 'プライバシーポリシーを生成しました。', GACHIREAI_TEXT_DOMAIN ),
			'links'   => $this->page_notice_links( $page_id ),
		);
	}
	private function page_notice_links( int $page_id ): array {
		$links = array();
		$edit  = (string) get_edit_post_link( $page_id, 'raw' );
		$view  = (string) get_permalink( $page_id );
		if ( '' !== $edit ) {
			$links[] = array(
				'url'   => esc_url( $edit ),
				'label' => esc_html__( '編集', GACHIREAI_TEXT_DOMAIN ),
			);
		}
		if ( '' !== $view ) {
			$links[] = array(
				'url'   => esc_url( $view ),
				'label' => esc_html__( '表示', GACHIREAI_TEXT_DOMAIN ),
			);
		}
		return $links;
	}
	private function elements_ajax( bool $generate_all ): void {
		$this->guard_ajax( self::NONCE_ACTION_ELEMENTS );
		$raw      = isset( $_POST[ self::ELEMENTS_KEY ] ) && is_array( $_POST[ self::ELEMENTS_KEY ] )
			? (array) wp_unslash( $_POST[ self::ELEMENTS_KEY ] )
			: array();
		$result   = $this->handle_elements_submission( $raw, $generate_all );
		$messages = array();
		if ( null !== $result['notice'] ) {
			$notice              = $this->message( (string) $result['notice']['type'], esc_html( (string) $result['notice']['message'] ) );
			$notice['msg_links'] = array();
			$messages[]          = $notice;
		}
		$this->send_form_html(
			fn(): string => $generate_all
				? $this->render_elements_form( array(), $messages )
				: $this->render_elements_form( $messages )
		);
	}
	private function save_posted_profile_photo(): void {
		$raw = $this->posted_array( self::PROFILE_KEY );
		if ( null === $raw || ! array_key_exists( self::PHOTO_KEY, $raw ) ) {
			return;
		}
		$value  = $raw[ self::PHOTO_KEY ];
		$photo  = is_scalar( $value ) && '' !== trim( (string) $value ) ? (string) absint( $value ) : '';
		$profile = new Site_Profile();
		$persona = $profile->get_my_persona();
		$persona[ self::PHOTO_KEY ] = $photo;
		$profile->update_field( self::PROFILE_KEY, $persona );
	}
	private function save_posted_site_images(): void {
		$this->save_posted_favicon();
		$this->save_posted_profile_photo();
		$this->save_posted_image_settings();
		$this->save_posted_top_eyecatch();
	}
	private function save_posted_favicon(): void {
		$raw = $this->posted_array( self::SITE_IMAGES_KEY );
		if ( null === $raw || ! array_key_exists( 'favicon', $raw ) ) {
			return;
		}
		$value = $raw['favicon'];
		$id    = is_scalar( $value ) && '' !== trim( (string) $value ) ? absint( $value ) : 0;
		if ( 0 >= $id ) {
			return;
		}
		update_option( 'site_icon', $id );
	}
	private function save_posted_top_eyecatch(): void {
		$raw = $this->posted_array( self::SITE_IMAGES_KEY );
		if ( null === $raw || ! array_key_exists( self::TOP_EYECATCH_KEY, $raw ) ) {
			return;
		}
		$value = $raw[ self::TOP_EYECATCH_KEY ];
		$id    = is_scalar( $value ) && '' !== trim( (string) $value ) ? absint( $value ) : 0;
		$profile     = new Site_Profile();
		$site_images = $profile->get_site_images();
		$site_images[ self::TOP_EYECATCH_KEY ] = $id;
		$profile->update_field( self::SITE_IMAGES_KEY, $site_images );
		Top_Page::apply_thumbnail( $id );
	}
	private function save_posted_image_settings(): void {
		$raw = $this->posted_array( self::IMAGE_KEY );
		if ( null === $raw ) {
			return;
		}
		$settings = new Settings();
		$current  = $settings->image();
		$form     = new Form(
			array(
				'style_family' => $this->site_image_style_family_definition(),
				'style_text'   => $this->site_image_style_text_definition(),
				'top_hero'     => $this->site_image_top_hero_definition(),
				'ogp'          => $this->site_image_ogp_definition(),
				'font'         => $this->site_image_font_definition(),
				'font_weight'  => $this->site_image_font_weight_definition(),
			)
		);
		$clean    = $form->sanitize( $raw, $current );
		$current['top_hero'] = (string) ( $clean['top_hero'] ?? Settings::TOP_HERO_IMAGE_ONLY );
		$current['ogp']      = (string) ( $clean['ogp'] ?? '' );
		if ( array_key_exists( 'style_family', $raw ) || array_key_exists( 'style_text', $raw ) ) {
			$current = $this->resolve_posted_image_style( $current, $clean );
		}
		$font                   = Font_Manager::normalize_font( (string) ( $clean['font'] ?? Font_Catalog::DEFAULT_FONT ) );
		$current['font']        = $font;
		$current['font_weight'] = Font_Manager::normalize_weight(
			$font,
			(string) ( $clean['font_weight'] ?? Font_Catalog::DEFAULT_WEIGHT )
		);
		$current = Connection_Status::preserve_record_keys( self::IMAGE_KEY, $current, $settings );
		$settings->set( self::IMAGE_KEY, $current );
	}
	private function generate_favicon_result(): array {
		try {
			$result = ( new Favicon() )->generate();
			return array(
				'type'          => 'success',
				'message'       => (string) $result['message'],
				'attachment_id' => (int) $result['attachment_id'],
				'kind'          => 'favicon',
			);
		} catch ( \Throwable $e ) {
			return array(
				'type'    => 'error',
				'message' => $e->getMessage(),
			);
		}
	}
	private function generate_profile_result(): array {
		try {
			$result = ( new Profile() )->generate();
			return array(
				'type'          => 'success',
				'message'       => (string) $result['message'],
				'attachment_id' => (int) $result['attachment_id'],
				'kind'          => 'profile',
			);
		} catch ( \Throwable $e ) {
			return array(
				'type'    => 'error',
				'message' => $e->getMessage(),
			);
		}
	}
	private function generate_top_eyecatch_result( ?string $site_name = null, ?string $description = null ): array {
		try {
			$result = ( new Top_Eyecatch() )->generate( $site_name, $description );
			return array(
				'type'          => 'success',
				'message'       => (string) $result['message'],
				'attachment_id' => (int) $result['attachment_id'],
				'kind'          => 'top_eyecatch',
			);
		} catch ( \Throwable $e ) {
			return array(
				'type'    => 'error',
				'message' => $e->getMessage(),
			);
		}
	}
	private function image_available(): bool {
		$settings = new Settings();
		return Image_Manager::is_available( $settings->image(), $settings );
	}
	private function posted_array( string $key ): ?array {
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return null;
		}
		return (array) wp_unslash( $_POST[ $key ] );
	}
	private function save_section(): ?string {
		$section = isset( $_POST[ self::SAVE_SECTION_FIELD ] )
			? sanitize_key( wp_unslash( (string) $_POST[ self::SAVE_SECTION_FIELD ] ) )
			: '';
		return in_array( $section, array( self::SECTION_SCOPE, self::SECTION_PERSONA, self::SECTION_TARGET ), true )
			? $section
			: null;
	}
	public function handle_submission( ?array $raw_persona, ?array $raw_scope, ?array $raw_target, bool $generate_my, bool $generate_target ): array {
		$persona = null === $raw_persona ? null : $this->sanitize_persona( $raw_persona );
		$scope   = null === $raw_scope ? null : $this->sanitize_scope( $raw_scope );
		$target  = null === $raw_target ? null : $this->sanitize_target_persona( $raw_target );
		$profile = new Site_Profile();
		if ( null !== $scope && '' !== (string) ( $scope['category'] ?? '' ) ) {
			$catalog = new Genre_Catalog();
			$foreign = $catalog->foreign_entries( $this->saved_ec(), (string) $scope['category'] );
			if ( array() !== $foreign ) {
				if ( ! $this->scope_confirm_requested() ) {
					return array(
						'arg'    => self::SCOPE_MIXED_ARG,
						'notice' => null,
						'mixed'  => $foreign,
					);
				}
				$scope['category'] = $catalog->remove_foreign( $this->saved_ec(), (string) $scope['category'] );
			}
		}
		if ( null !== $persona ) {
			$profile->update_field( self::PROFILE_KEY, array_merge( $profile->get_my_persona(), $persona ) );
		}
		if ( null !== $scope ) {
			$profile->update_field( self::SCOPE_KEY, array_merge( $profile->get_scope(), $scope ) );
		}
		if ( null !== $target ) {
			$profile->update_field( self::TARGET_KEY, $target );
		}
		if ( ! $generate_my && ! $generate_target ) {
			return array(
				'arg'    => self::SAVED_ARG,
				'notice' => null,
			);
		}
		if ( $generate_target ) {
			$base   = null !== $target ? $target : $profile->get_target_persona();
			$result = $this->generate_target_persona( $base, $profile->get_scope(), $profile->get_my_persona() );
			if ( null !== $result['persona'] ) {
				$profile->update_field( self::TARGET_KEY, array_merge( $base, $result['persona'] ) );
			}
			return array(
				'arg'    => self::GENERATED_ARG,
				'notice' => array(
					'type'    => $result['type'],
					'message' => $result['message'],
				),
			);
		}
		$base   = array_merge( $profile->get_my_persona(), null !== $persona ? $persona : array() );
		$result = $this->generate_persona( $base, $profile->get_scope() );
		if ( null !== $result['persona'] ) {
			$profile->update_field( self::PROFILE_KEY, array_merge( $base, $result['persona'] ) );
		}
		return array(
			'arg'    => self::GENERATED_ARG,
			'notice' => array(
				'type'    => $result['type'],
				'message' => $result['message'],
			),
		);
	}
	public function generate_persona( array $persona, array $scope = array() ): array {
		$generator = new Site_Generator();
		if ( ! $generator->is_available() ) {
			return array(
				'type'    => 'warning',
				'message' => Site_Generator::unavailable_message(),
				'persona' => null,
			);
		}
		try {
			$generated = $generator->structure_from_free_text(
				(string) ( $persona[ self::FREE_TEXT_KEY ] ?? '' ),
				$scope,
				$persona
			);
		} catch ( \Throwable $e ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					__( 'AI 自動生成に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					mb_substr( trim( $e->getMessage() ), 0, 300 )
				),
				'persona' => null,
			);
		}
		$generated = array_intersect_key(
			$this->sanitize_persona( $generated ),
			array_flip( Site_Generator::generated_keys() )
		);
		return array(
			'type'    => 'success',
			'message' => sprintf(
				__( "AI 自動生成して保存しました（%s）。\n下の内容を確認し、必要なら編集して保存し直してください。", GACHIREAI_TEXT_DOMAIN ),
				'' !== (string) ( $generated['name'] ?? '' ) ? (string) $generated['name'] : __( '名前は空欄', GACHIREAI_TEXT_DOMAIN )
			),
			'persona' => $generated,
		);
	}
	public function generate_target_persona( array $target, array $scope = array(), array $my_persona = array() ): array {
		$generator = new Site_Generator();
		if ( ! $generator->is_available() ) {
			return array(
				'type'    => 'warning',
				'message' => Site_Generator::unavailable_message(),
				'persona' => null,
			);
		}
		try {
			$generated = $generator->structure_target_persona(
				(string) ( $target[ self::FREE_TEXT_KEY ] ?? '' ),
				$scope,
				$my_persona
			);
		} catch ( \Throwable $e ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					__( 'AI 自動生成に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					mb_substr( trim( $e->getMessage() ), 0, 300 )
				),
				'persona' => null,
			);
		}
		$generated = array_intersect_key(
			$this->sanitize_target_persona( $generated ),
			array_flip( Site_Generator::target_generated_keys() )
		);
		return array(
			'type'    => 'success',
			'message' => sprintf(
				__( "AI 自動生成して保存しました（読者: %s）。\n下の内容を確認し、必要なら編集して保存し直してください。", GACHIREAI_TEXT_DOMAIN ),
				'' !== (string) ( $generated['attributes'] ?? '' ) ? (string) $generated['attributes'] : __( '属性は空欄', GACHIREAI_TEXT_DOMAIN )
			),
			'persona' => $generated,
		);
	}
	public function handle_elements_submission( array $raw_elements, bool $generate_all ): array {
		$elements = $this->sanitize_elements( $raw_elements );
		$profile  = new Site_Profile();
		$profile->update_field( self::ELEMENTS_KEY, $elements );
		if ( $generate_all ) {
			return $this->generate_elements_and_sample( $profile, $elements );
		}
		$result = $this->reflect_to_wp( $profile );
		delete_transient( self::ELEMENTS_TRANSIENT_PREFIX . get_current_user_id() );
		$this->record_setting_save( __( 'サイト要素', GACHIREAI_TEXT_DOMAIN ) );
		return array(
			'arg'    => self::REFLECTED_ARG,
			'notice' => array(
				'type'    => $result['type'],
				'message' => $result['message'],
			),
		);
	}
	public function generate_elements_and_sample( Site_Profile $profile, array $elements ): array {
		$element_result = $this->generate_elements(
			$profile->get_scope(),
			$profile->get_my_persona(),
			$profile->get_target_persona()
		);
		$sample_result  = $this->generate_sample( $profile->get_my_persona(), $elements );
		if ( null !== $element_result['candidates'] ) {
			set_transient(
				self::ELEMENTS_TRANSIENT_PREFIX . get_current_user_id(),
				$element_result['candidates'],
				self::ELEMENTS_TRANSIENT_TTL
			);
		}
		if ( null !== $sample_result['sample'] ) {
			$elements[ self::WRITING_SAMPLE_KEY ] = $sample_result['sample'];
			$profile->update_field( self::ELEMENTS_KEY, $elements );
		}
		return array(
			'arg'    => self::ELEMENTS_GENERATED_ARG,
			'notice' => $this->compose_generation_notice( $element_result, $sample_result ),
		);
	}
	private function compose_generation_notice( array $element_result, array $sample_result ): array {
		$elements_ok = 'success' === $element_result['type'];
		$sample_ok   = 'success' === $sample_result['type'];
		if ( $elements_ok && $sample_ok ) {
			return array(
				'type'    => 'success',
				'message' => __( "サイト要素の候補と文体サンプルを生成しました。\n各要素で案を選ぶか直接入力し「サイト要素を WordPress の設定に反映して保存」で確定してください。\n文体サンプルは保存済みです。", GACHIREAI_TEXT_DOMAIN ),
			);
		}
		if ( $elements_ok ) {
			return array(
				'type'    => 'warning',
				'message' => sprintf(
					__( 'サイト要素の候補は生成しましたが、文体サンプルの生成に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$sample_result['message']
				),
			);
		}
		if ( $sample_ok ) {
			return array(
				'type'    => 'warning',
				'message' => sprintf(
					__( '文体サンプルは生成しましたが、サイト要素の候補生成に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$element_result['message']
				),
			);
		}
		return array(
			'type'    => 'error',
			'message' => $element_result['message'],
		);
	}
	public function generate_elements( array $scope = array(), array $my_persona = array(), array $target_persona = array() ): array {
		$generator = new Site_Generator();
		if ( ! $generator->is_available() ) {
			return array(
				'type'       => 'warning',
				'message'    => Site_Generator::unavailable_message(),
				'candidates' => null,
			);
		}
		try {
			$generated = $generator->generate_site_elements( $scope, $my_persona, $target_persona );
		} catch ( \Throwable $e ) {
			return array(
				'type'       => 'error',
				'message'    => sprintf(
					__( 'サイト要素の自動生成に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					mb_substr( trim( $e->getMessage() ), 0, 300 )
				),
				'candidates' => null,
			);
		}
		$candidates = $this->sanitize_candidates( $generated );
		$count      = 0;
		foreach ( $candidates as $list ) {
			$count += count( $list );
		}
		if ( 0 === $count ) {
			return array(
				'type'       => 'warning',
				'message'    => __( "AI の応答からサイト要素の候補を取り出せませんでした。\nサイトのタイプやペルソナを具体的にして再実行してください。", GACHIREAI_TEXT_DOMAIN ),
				'candidates' => null,
			);
		}
		return array(
			'type'       => 'success',
			'message'    => sprintf(
				__( "サイト要素の候補を生成しました（%d 案）。\n各要素で案を選ぶか直接入力し「サイト要素を保存」で確定してください。", GACHIREAI_TEXT_DOMAIN ),
				$count
			),
			'candidates' => $candidates,
		);
	}
	public function generate_sample( array $my_persona = array(), array $site_elements = array() ): array {
		$generator = new Site_Generator();
		if ( ! $generator->is_available() ) {
			return array(
				'type'    => 'warning',
				'message' => Site_Generator::unavailable_message(),
				'sample'  => null,
			);
		}
		try {
			$sample = $generator->generate_writing_sample( $my_persona, $site_elements );
		} catch ( \Throwable $e ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					__( '文体サンプルの生成に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					mb_substr( trim( $e->getMessage() ), 0, 300 )
				),
				'sample'  => null,
			);
		}
		$sanitized = $this->sanitize_elements( array( self::WRITING_SAMPLE_KEY => $sample ) );
		$sample    = (string) ( $sanitized[ self::WRITING_SAMPLE_KEY ] ?? '' );
		if ( '' === $sample ) {
			return array(
				'type'    => 'warning',
				'message' => __( "AI の応答から文体サンプルを取り出せませんでした。\nペルソナを具体的にして再実行してください。", GACHIREAI_TEXT_DOMAIN ),
				'sample'  => null,
			);
		}
		return array(
			'type'    => 'success',
			'message' => __( "文体サンプルを生成して保存しました。\n下の内容を確認し、必要なら編集して保存し直してください。", GACHIREAI_TEXT_DOMAIN ),
			'sample'  => $sample,
		);
	}
	public function reflect_to_wp( Site_Profile $profile ): array {
		$persona     = $profile->get_my_persona();
		$elements    = $profile->get_site_elements();
		$name        = $this->scalar_value( $persona, 'name' );
		$site_name   = $this->scalar_value( $elements, 'site_name' );
		$description = $this->scalar_value( $elements, 'description' );
		$profile_txt = $this->scalar_value( $elements, 'profile' );
		$slug        = sanitize_title( $this->scalar_value( $persona, self::AUTHOR_SLUG_KEY ) );
		$reflected   = array();
		if ( '' !== $site_name ) {
			update_option( 'blogname', $site_name );
			$reflected[] = __( 'サイト名', GACHIREAI_TEXT_DOMAIN );
		}
		if ( '' !== $description ) {
			update_option( 'blogdescription', $description );
			$reflected[] = __( 'キャッチフレーズ', GACHIREAI_TEXT_DOMAIN );
		}
		$userdata = array( 'ID' => get_current_user_id() );
		if ( '' !== $name ) {
			$userdata['display_name'] = $name;
			$userdata['nickname']     = $name;
			$reflected[]              = __( 'ニックネーム・表示名', GACHIREAI_TEXT_DOMAIN );
		}
		if ( '' !== $profile_txt ) {
			$userdata['description'] = $profile_txt;
			$reflected[]             = __( 'プロフィール情報', GACHIREAI_TEXT_DOMAIN );
		}
		if ( '' !== $slug ) {
			$userdata['user_nicename'] = $slug;
			$reflected[]               = __( '投稿者スラッグ', GACHIREAI_TEXT_DOMAIN );
		}
		if ( 1 < count( $userdata ) ) {
			$updated = wp_update_user( $userdata );
			if ( is_wp_error( $updated ) ) {
				return array(
					'type'    => 'error',
					'message' => sprintf(
						__( 'ユーザー情報の反映に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
						mb_substr( $updated->get_error_message(), 0, 300 )
					),
				);
			}
		}
		if ( array() === $reflected ) {
			return array(
				'type'    => 'warning',
				'message' => __( "反映する項目がありません。\nサイト名・サイト説明文・プロフィールなどを入力してから、もう一度お試しください。", GACHIREAI_TEXT_DOMAIN ),
			);
		}
		return array(
			'type'    => 'success',
			'message' => sprintf(
				__( 'サイト要素を保存し、WordPress の設定に反映しました（%s）。', GACHIREAI_TEXT_DOMAIN ),
				implode( ' / ', $reflected )
			),
		);
	}
	private function scalar_value( array $values, string $key ): string {
		$value = $values[ $key ] ?? '';
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
	public function sanitize_persona( array $raw ): array {
		return $this->form()->sanitize( $raw );
	}
	public function sanitize_scope( array $raw ): array {
		$all = $this->scope_form()->sanitize( $raw );
		if ( $this->is_amazon_without_genre_search() && in_array( (string) ( $all['site_type'] ?? '' ), array( 'general', 'category' ), true ) ) {
			$all['site_type'] = 'niche';
		}
		return array_intersect_key( $all, array_flip( $this->scope_keys( $all ) ) );
	}
	private function scope_confirm_requested(): bool {
		$value = $_POST[ self::SCOPE_CONFIRM_FIELD ] ?? '';
		return is_scalar( $value ) && '1' === sanitize_text_field( wp_unslash( (string) $value ) );
	}
	private function scope_mixed_message( array $entries ): array {
		$labels = array();
		foreach ( $entries as $entry ) {
			$labels[] = Genre_Catalog::format_entry( (string) ( $entry['genre_id'] ?? '' ), (string) ( $entry['name'] ?? '' ) );
		}
		return $this->message(
			'warning',
			esc_html(
				sprintf(
					__( 'メインEC（%1$s）に存在しないカテゴリーが混じっています: %2$s', GACHIREAI_TEXT_DOMAIN ),
					Product_Selector::ec_label( $this->saved_ec() ),
					implode( ' / ', array_filter( $labels ) )
				)
			)
		);
	}
	private function scope_mixed_model( array $entries ): array {
		if ( array() === $entries ) {
			return array( 'show_scope_dialog' => false );
		}
		$rows = array();
		foreach ( $entries as $entry ) {
			$rows[] = array(
				'item_label' => esc_html( Genre_Catalog::format_entry( (string) ( $entry['genre_id'] ?? '' ), (string) ( $entry['name'] ?? '' ) ) ),
			);
		}
		return array(
			'show_scope_dialog'        => true,
			'scope_mixed_title'        => esc_html__( '別のECのカテゴリーが混じっています', GACHIREAI_TEXT_DOMAIN ),
			'scope_mixed_desc'         => esc_html(
				sprintf(
					__( '現在のメインEC（%s）に存在しないカテゴリーが含まれています。これらを削除して保存しますか？', GACHIREAI_TEXT_DOMAIN ),
					Product_Selector::ec_label( $this->saved_ec() )
				)
			),
			'scope_mixed_rows'         => $rows,
			'scope_mixed_submit_label' => esc_html__( '削除して保存', GACHIREAI_TEXT_DOMAIN ),
			'scope_mixed_cancel_label' => esc_html__( 'キャンセル', GACHIREAI_TEXT_DOMAIN ),
			'scope_mixed_confirm_name' => esc_attr( self::SCOPE_CONFIRM_FIELD ),
			'scope_mixed_save_action'  => esc_attr( self::AJAX_ACTION_SAVE ),
		);
	}
	public function sanitize_target_persona( array $raw ): array {
		return $this->target_form()->sanitize( $raw );
	}
	public function sanitize_elements( array $raw ): array {
		return $this->elements_form()->sanitize( $raw );
	}
	private function sanitize_candidates( array $candidates ): array {
		$definitions = $this->elements_definitions();
		$clean       = array();
		$keys        = array_merge( Site_Generator::element_keys(), array( self::WRITING_SAMPLE_KEY ) );
		foreach ( $keys as $key ) {
			$definition    = $definitions[ $key ] ?? array();
			$type          = (string) ( $definition['type'] ?? 'text' );
			$max           = (int) ( $definition['max'] ?? 200 );
			$clean[ $key ] = array();
			foreach ( (array) ( $candidates[ $key ] ?? array() ) as $candidate ) {
				if ( ! is_scalar( $candidate ) ) {
					continue;
				}
				$value = 'textarea' === $type
					? sanitize_textarea_field( (string) $candidate )
					: sanitize_text_field( (string) $candidate );
				$value = mb_substr( $value, 0, $max );
				if ( '' !== $value ) {
					$clean[ $key ][] = $value;
				}
			}
		}
		return $clean;
	}
	private function form(): Form {
		return new Form( $this->definitions() );
	}
	private function scope_keys( array $scope ): array {
		$type = (string) ( $scope['site_type'] ?? Site_Profile::DEFAULT_SITE_TYPE );
		if ( $this->is_amazon_without_genre_search() && in_array( $type, array( 'general', 'category' ), true ) ) {
			$type = 'niche';
		}
		$keys = array( 'site_type', self::SCOPE_EC_KEY );
		if ( 'general' === $type ) {
			return $keys;
		}
		$keys[] = 'category';
		if ( 'niche' === $type ) {
			$keys[] = 'products';
		}
		return $keys;
	}
	private function scope_form(): Form {
		return new Form( $this->scope_definitions() );
	}
	private function target_form(): Form {
		return new Form( $this->target_definitions() );
	}
	private function elements_form(): Form {
		return new Form( $this->elements_definitions() );
	}
	private function model( Site_Profile $profile, array $overrides = array(), array $site_image_overrides = array() ): array {
		$persona   = (array) ( $overrides[ self::PROFILE_KEY ] ?? $profile->get_my_persona() );
		$scope     = (array) ( $overrides[ self::SCOPE_KEY ] ?? $profile->get_scope() );
		$target    = (array) ( $overrides[ self::TARGET_KEY ] ?? $profile->get_target_persona() );
		$generator = new Site_Generator();
		$notices   = array();
		if ( ! $generator->is_available() ) {
			$notices[] = $this->notice( 'warning', esc_html( Site_Generator::unavailable_message() ) );
		}
		$candidates = get_transient( self::ELEMENTS_TRANSIENT_PREFIX . get_current_user_id() );
		$candidates = is_array( $candidates ) ? $candidates : array();
		$site_images_notices = array();
		if ( isset( $_GET[ self::SITE_IMAGES_SAVED_ARG ] ) ) {
			$site_images_notices[] = $this->notice( 'success', esc_html__( '保存しました。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( isset( $_GET[ self::SITE_IMAGES_GENERATED_ARG ] ) ) {
			$result = get_transient( self::SITE_IMAGES_NOTICE_TRANSIENT_PREFIX . get_current_user_id() );
			delete_transient( self::SITE_IMAGES_NOTICE_TRANSIENT_PREFIX . get_current_user_id() );
			if ( is_array( $result ) && isset( $result['type'], $result['message'] ) ) {
				$site_images_notices[] = $this->notice( (string) $result['type'], esc_html( (string) $result['message'] ) );
				$kind = (string) ( $result['kind'] ?? '' );
				$id   = (int) ( $result['attachment_id'] ?? 0 );
				if ( '' !== $kind && 0 < $id ) {
					$site_image_overrides[ $kind ] = $id;
				}
				if ( isset( $result['font'] ) && is_scalar( $result['font'] ) ) {
					$font                                = Font_Manager::normalize_font( (string) $result['font'] );
					$site_image_overrides['font']        = $font;
					$site_image_overrides['font_weight'] = Font_Manager::default_weight( $font );
				}
			}
		}
		$site_images                         = $this->site_images_model( $profile, $site_image_overrides );
		$site_images['site_images_messages'] = $site_images_notices;
		return array(
			'page_title'    => esc_html__( 'サイト設定', GACHIREAI_TEXT_DOMAIN ),
			'show_scope_dialog' => false,
			'notices'       => $notices,
			'tabs'          => $this->tab_nav(),
			'form_action'   => esc_url( Tabs::url( Tabs::TAB_SITE ) ),
			'hidden_fields' => array(
				array(
					'name'  => esc_attr( self::ACTION_FIELD ),
					'value' => esc_attr( self::ACTION ),
				),
				array(
					'name'  => esc_attr( '_wpnonce' ),
					'value' => esc_attr( wp_create_nonce( self::NONCE_ACTION ) ),
				),
			),
			'form_messages' => array(),
			'sections'      => array(
				array(
					'section_key'     => self::SECTION_SCOPE,
					'title'           => esc_html__( '取扱商品', GACHIREAI_TEXT_DOMAIN ),
					'description'     => esc_html__( "このサイトが何を扱うのかを決めます。\nこの内容は、ペルソナやサイト要素の自動生成にも反映されます。", GACHIREAI_TEXT_DOMAIN ),
					'blocks'          => array(
						array(
							'block_key'      => 'scope',
							'block_title'    => '',
							'fields'         => $this->scope_form()->build_list( $this->scope_values( $scope ), array_keys( $this->scope_definitions() ), self::SCOPE_KEY, $this->scope_field_overrides() ),
							'block_buttons'  => array(),
							'block_messages' => array(),
						),
					),
					'section_buttons'  => array( $this->save_button( self::SECTION_SCOPE ) ),
					'section_messages' => array(),
				),
				array(
					'section_key'     => self::SECTION_PERSONA,
					'title'           => esc_html__( 'マイペルソナ', GACHIREAI_TEXT_DOMAIN ),
					'description'     => esc_html__( "サイトオーナーのペルソナ（架空人格）を決めます。\nAIはこのペルソナになりきって記事を書きます。", GACHIREAI_TEXT_DOMAIN ),
					'blocks'          => array(
						array(
							'block_key'      => 'my_persona_memo',
							'block_title'    => '',
							'fields'         => $this->form()->build_list( $persona, self::MEMO_KEYS, self::PROFILE_KEY ),
							'block_buttons'  => array(
								$this->generate_button( $generator, self::GENERATE_FIELD, null, __( 'AI でマイペルソナを提案', GACHIREAI_TEXT_DOMAIN ) ),
							),
							'block_messages' => array(),
						),
						array(
							'block_key'      => 'my_persona',
							'block_title'    => esc_html__( 'マイペルソナ', GACHIREAI_TEXT_DOMAIN ),
							'fields'         => $this->form()->build_list( $persona, self::PERSONA_KEYS, self::PROFILE_KEY ),
							'block_buttons'  => array(),
							'block_messages' => array(),
						),
					),
					'section_buttons'  => array( $this->save_button( self::SECTION_PERSONA ) ),
					'section_messages' => array(),
				),
				array(
					'section_key'     => self::SECTION_TARGET,
					'title'           => esc_html__( 'ターゲットペルソナ', GACHIREAI_TEXT_DOMAIN ),
					'description'     => esc_html__( '読者（誰に向けて書くか）を決めます。', GACHIREAI_TEXT_DOMAIN ),
					'blocks'          => array(
						array(
							'block_key'      => 'target_memo',
							'block_title'    => '',
							'fields'         => $this->target_form()->build_list( $target, self::TARGET_MEMO_KEYS, self::TARGET_KEY ),
							'block_buttons'  => array(
								$this->generate_button(
									$generator,
									self::TARGET_GENERATE_FIELD,
									__( '入力中の内容を保存してから、サイトの情報と「書く人」を文脈に、読者（ターゲットペルソナ）を生成します（読者のメモはそのまま残ります）', GACHIREAI_TEXT_DOMAIN ),
									__( 'AI でターゲットペルソナを提案', GACHIREAI_TEXT_DOMAIN )
								),
							),
							'block_messages' => array(),
						),
						array(
							'block_key'      => 'target',
							'block_title'    => esc_html__( 'ターゲットペルソナ', GACHIREAI_TEXT_DOMAIN ),
							'fields'         => $this->target_form()->build_list( $target, self::TARGET_PERSONA_KEYS, self::TARGET_KEY ),
							'block_buttons'  => array(),
							'block_messages' => array(),
						),
					),
					'section_buttons'  => array( $this->save_button( self::SECTION_TARGET ) ),
					'section_messages' => array(),
				),
			),
			'submit_label'   => '',
			'submit_action'  => '',
			'submit_testing' => false,
			'elements_form_action'   => esc_url( Tabs::url( Tabs::TAB_SITE ) ),
			'elements_hidden_fields' => array(
				array(
					'name'  => esc_attr( self::ACTION_FIELD ),
					'value' => esc_attr( self::ACTION_ELEMENTS ),
				),
				array(
					'name'  => esc_attr( '_wpnonce' ),
					'value' => esc_attr( wp_create_nonce( self::NONCE_ACTION_ELEMENTS ) ),
				),
			),
			'elements_title'         => esc_html__( 'サイト要素', GACHIREAI_TEXT_DOMAIN ),
			'elements_anchor'        => esc_attr( 'site_elements' ),
			'elements_intro'         => esc_html__( "ここの内容はワードプレスのサイト設定や、記事の内容に反映されます。\nAIで生成することも、手動で編集することも可能です。", GACHIREAI_TEXT_DOMAIN ),
			'elements_blocks'        => $this->elements_model( $profile->get_site_elements(), $candidates, $profile->get_my_persona() ),
			'elements_buttons'       => $this->elements_buttons( $generator ),
			'elements_generate_messages' => array(),
			'elements_messages'      => array(),
			'elements_submit_label'  => esc_html__( 'サイト要素を WordPress の設定に反映して保存', GACHIREAI_TEXT_DOMAIN ),
			'elements_submit_action' => esc_attr( self::AJAX_ACTION_SAVE_ELEMENTS ),
			'static_pages_form_action'   => esc_url( Tabs::url( Tabs::TAB_SITE ) ),
			'static_pages_hidden_fields' => array(
				array(
					'name'  => esc_attr( '_wpnonce' ),
					'value' => esc_attr( wp_create_nonce( self::NONCE_ACTION_ELEMENTS ) ),
				),
			),
			'static_pages_title'         => esc_html__( '固定ページ', GACHIREAI_TEXT_DOMAIN ),
			'static_pages_anchor'        => esc_attr( 'static_pages' ),
			'static_pages_intro'         => esc_html__( 'サイトの固定ページ（トップページ・プライバシーポリシー）を用意します。', GACHIREAI_TEXT_DOMAIN ),
			'static_pages_top_title'    => esc_html__( 'トップページ', GACHIREAI_TEXT_DOMAIN ),
			'static_pages_top_text'     => esc_html__( 'このサイトの入り口となるページです。', GACHIREAI_TEXT_DOMAIN ),
			'static_pages_policy_title' => esc_html__( 'プライバシーポリシー', GACHIREAI_TEXT_DOMAIN ),
			'static_pages_policy_text'  => esc_html__( '個人情報の取扱い方法、免責事項、特記事項などをまとめて書いたページです。', GACHIREAI_TEXT_DOMAIN ),
			'static_pages_generate_top_label'     => esc_html__( 'トップページを生成', GACHIREAI_TEXT_DOMAIN ),
			'static_pages_generate_top_action'    => esc_attr( self::AJAX_ACTION_GENERATE_TOP ),
			'static_pages_generate_policy_label'  => esc_html__( 'プライバシーポリシーを生成', GACHIREAI_TEXT_DOMAIN ),
			'static_pages_generate_policy_action' => esc_attr( self::AJAX_ACTION_GENERATE_POLICY ),
			'static_pages_top_messages'    => array(),
			'static_pages_policy_messages' => array(),
			'disclosure_form_action'   => esc_url( Tabs::url( Tabs::TAB_SITE ) ),
			'disclosure_hidden_fields' => array(
				array(
					'name'  => esc_attr( self::ACTION_FIELD ),
					'value' => esc_attr( self::ACTION_DISCLOSURE ),
				),
				array(
					'name'  => esc_attr( '_wpnonce' ),
					'value' => esc_attr( wp_create_nonce( self::NONCE_ACTION_DISCLOSURE ) ),
				),
			),
			'disclosure_anchor'        => esc_attr( 'disclosure' ),
			'disclosure_title'         => esc_html__( '開示', GACHIREAI_TEXT_DOMAIN ),
			'disclosure_intro'         => esc_html__( "アフィリエイト広告・AI生成コンテンツであることを「開示ウィジェット」にまとめて表示します。\n表示ONの項目だけを 1 つのウィジェットに出します（初期値はすべて表示）。\n画像注釈は、トップページ・個別記事のトップ画の下に表示します。", GACHIREAI_TEXT_DOMAIN ),
			'disclosure_blocks'        => $this->disclosure_blocks(),
			'disclosure_messages'      => isset( $_GET['gachireai_disclosure_saved'] )
				? array( $this->message( 'success', esc_html__( '保存しました。', GACHIREAI_TEXT_DOMAIN ) ) )
				: array(),
			'disclosure_submit_label'  => esc_html__( '開示を保存', GACHIREAI_TEXT_DOMAIN ),
			'modal'                  => true,
		) + $site_images + $this->picker( $this->saved_ec(), '', array() );
	}
	public function render_persona_form( array $messages = array(), array $form_messages = array(), array $values = array(), array $section_messages = array(), array $field_messages = array(), array $scope_mixed = array() ): string {
		$model                  = $this->model( new Site_Profile(), $values );
		$model['sections']      = $this->with_block_messages( (array) $model['sections'], $messages );
		$model['sections']      = $this->with_section_messages( (array) $model['sections'], $section_messages );
		$model['sections']      = $this->with_field_messages( (array) $model['sections'], $field_messages );
		$model['form_messages'] = $form_messages;
		$model                  = array_merge( $model, $this->scope_mixed_model( $scope_mixed ) );
		return $this->render_template_string( 'admin/site-setup-persona-form', $model );
	}
	public function render_elements_form( array $messages = array(), array $generate_messages = array() ): string {
		$model                               = $this->model( new Site_Profile() );
		$model['elements_messages']          = $messages;
		$model['elements_generate_messages'] = $generate_messages;
		return $this->render_template_string( 'admin/site-setup-elements-form', $model );
	}
	public function render_static_pages_form( array $top_messages = array(), array $policy_messages = array() ): string {
		$model                                 = $this->model( new Site_Profile() );
		$model['static_pages_top_messages']    = $top_messages;
		$model['static_pages_policy_messages'] = $policy_messages;
		return $this->render_template_string( 'admin/site-setup-static-pages-form', $model );
	}
	public function render_site_images_form( array $messages = array(), array $site_image_overrides = array(), array $item_messages = array() ): string {
		$model                         = $this->model( new Site_Profile(), array(), $site_image_overrides );
		$model['site_images_messages'] = $messages;
		$model['site_images_items']    = $this->with_site_image_item_messages( (array) $model['site_images_items'], $item_messages );
		return $this->render_template_string( 'admin/site-setup-images-form', $model );
	}
	private function with_site_image_item_messages( array $items, array $messages ): array {
		if ( array() === $messages ) {
			return $items;
		}
		$profile_name      = self::PROFILE_KEY . '[' . self::PHOTO_KEY . ']';
		$top_eyecatch_name = self::SITE_IMAGES_KEY . '[' . self::TOP_EYECATCH_KEY . ']';
		foreach ( $items as $index => $item ) {
			if ( ! empty( $item['el_is_eyecatch_group'] ) && isset( $item['group_items'] ) && is_array( $item['group_items'] ) ) {
				$items[ $index ]['group_items'] = $this->with_site_image_item_messages( (array) $item['group_items'], $messages );
				continue;
			}
			if ( ! empty( $item['el_is_favicon'] ) && isset( $messages['favicon'] ) ) {
				$items[ $index ]['favicon_messages'] = (array) $messages['favicon'];
			}
			if ( ! empty( $item['el_is_image'] ) && isset( $item['name'] ) && is_scalar( $item['name'] ) ) {
				$name = (string) $item['name'];
				if ( $profile_name === $name && isset( $messages['profile'] ) ) {
					$items[ $index ]['field_messages'] = (array) $messages['profile'];
				}
				if ( $top_eyecatch_name === $name && isset( $messages['top_eyecatch'] ) ) {
					$items[ $index ]['field_messages'] = (array) $messages['top_eyecatch'];
				}
			}
			if ( ! empty( $item['el_is_font'] ) && isset( $messages['font'] ) ) {
				$items[ $index ]['font_messages'] = (array) $messages['font'];
			}
			if ( ! empty( $item['el_is_field'] ) && isset( $item['name'] ) && is_scalar( $item['name'] ) ) {
				if ( self::IMAGE_KEY . '[style_text]' === (string) $item['name'] && isset( $messages['style'] ) ) {
					$items[ $index ]['field_messages'] = (array) $messages['style'];
				}
			}
		}
		return $items;
	}
	private function with_block_messages( array $sections, array $messages ): array {
		foreach ( $sections as $section_index => $section ) {
			foreach ( (array) ( $section['blocks'] ?? array() ) as $block_index => $block ) {
				$key = (string) ( $block['block_key'] ?? '' );
				if ( '' === $key || ! isset( $messages[ $key ] ) ) {
					continue;
				}
				$sections[ $section_index ]['blocks'][ $block_index ]['block_messages'] = (array) $messages[ $key ];
			}
		}
		return $sections;
	}
	private function with_section_messages( array $sections, array $messages ): array {
		foreach ( $sections as $section_index => $section ) {
			$key = (string) ( $section['section_key'] ?? '' );
			if ( '' === $key || ! isset( $messages[ $key ] ) ) {
				continue;
			}
			$sections[ $section_index ]['section_messages'] = (array) $messages[ $key ];
		}
		return $sections;
	}
	private function with_field_messages( array $sections, array $messages ): array {
		if ( array() === $messages ) {
			return $sections;
		}
		foreach ( $sections as $section_index => $section ) {
			foreach ( (array) ( $section['blocks'] ?? array() ) as $block_index => $block ) {
				foreach ( (array) ( $block['fields'] ?? array() ) as $field_index => $field ) {
					$name = (string) ( $field['name'] ?? '' );
					if ( '' === $name || ! isset( $messages[ $name ] ) ) {
						continue;
					}
					$sections[ $section_index ]['blocks'][ $block_index ]['fields'][ $field_index ]['field_messages'] = (array) $messages[ $name ];
				}
			}
		}
		return $sections;
	}
	private function save_button( string $section ): array {
		return array(
			'btn_name'     => esc_attr( self::SAVE_SECTION_FIELD ),
			'btn_value'    => esc_attr( $section ),
			'btn_label'    => esc_html__( '保存', GACHIREAI_TEXT_DOMAIN ),
			'btn_primary'  => true,
			'btn_disabled' => false,
			'btn_title'    => '',
			'btn_action'   => esc_attr( self::AJAX_ACTION_SAVE ),
			'btn_testing'  => false,
		);
	}
	private function generate_button( Site_Generator $generator, string $field = self::GENERATE_FIELD, ?string $title = null, ?string $label = null ): array {
		$disabled = ! $generator->is_available();
		$title    = $title ?? __( '入力中の内容を保存してから、性別・口調・特記事項を文脈にペルソナを生成します（性別・口調・特記事項はそのまま残ります）', GACHIREAI_TEXT_DOMAIN );
		$ajax = match ( $field ) {
			self::GENERATE_FIELD        => self::AJAX_ACTION_GENERATE,
			self::TARGET_GENERATE_FIELD => self::AJAX_ACTION_GENERATE_TARGET,
			default                     => '',
		};
		return array(
			'btn_name'     => esc_attr( $field ),
			'btn_value'    => '1',
			'btn_label'    => esc_html( $label ?? __( 'AI で自動生成', GACHIREAI_TEXT_DOMAIN ) ),
			'btn_disabled' => $disabled,
			'btn_title'    => $disabled
				? esc_attr( Site_Generator::unavailable_message() )
				: esc_attr( $title ),
			'btn_action'   => esc_attr( $ajax ),
			'button_icon'  => 'robot',
		);
	}
	private function elements_model( array $elements, array $candidates, array $my_persona ): array {
		$definitions = $this->elements_definitions();
		return array(
			array(
				'block_title' => esc_html__( 'サイト一般設定', GACHIREAI_TEXT_DOMAIN ),
				'block_items' => array(
					$this->element_field_model( 'site_name', $elements, $candidates, $definitions ),
					$this->element_field_model( 'description', $elements, $candidates, $definitions ),
				),
			),
			array(
				'block_title' => esc_html__( 'ユーザープロフィール', GACHIREAI_TEXT_DOMAIN ),
				'block_items' => array(
					$this->element_readonly_model(
						__( '名前', GACHIREAI_TEXT_DOMAIN ),
						(string) ( $my_persona['name'] ?? '' ),
						__( 'マイペルソナの「名前」を表示しています（編集はマイペルソナ側で行います）。', GACHIREAI_TEXT_DOMAIN )
					),
					$this->element_readonly_model(
						__( '投稿者スラッグ', GACHIREAI_TEXT_DOMAIN ),
						(string) ( $my_persona[ self::AUTHOR_SLUG_KEY ] ?? '' ),
						__( "マイペルソナの「投稿者スラッグ」を表示しています（編集はマイペルソナ側で行います）。\n記事の投稿者 URL（/author/〇〇/）に使われます。", GACHIREAI_TEXT_DOMAIN )
					),
					$this->element_field_model( 'profile', $elements, $candidates, $definitions ),
				),
			),
			array(
				'block_title' => esc_html__( '文体サンプル', GACHIREAI_TEXT_DOMAIN ),
				'block_items' => array(
					$this->element_field_model( self::WRITING_SAMPLE_KEY, $elements, $candidates, $definitions, true, true ),
				),
			),
		);
	}
	private function site_images_model( Site_Profile $profile, array $site_image_overrides = array() ): array {
		$my_persona = $profile->get_my_persona();
		if ( isset( $site_image_overrides['profile'] ) ) {
			$my_persona[ self::PHOTO_KEY ] = (string) absint( (string) $site_image_overrides['profile'] );
		}
		$site_images = $profile->get_site_images();
		if ( isset( $site_image_overrides['top_eyecatch'] ) ) {
			$site_images[ self::TOP_EYECATCH_KEY ] = (string) absint( (string) $site_image_overrides['top_eyecatch'] );
		}
		$top_eyecatch_id      = isset( $site_images[ self::TOP_EYECATCH_KEY ] ) && is_scalar( $site_images[ self::TOP_EYECATCH_KEY ] )
			? absint( (string) $site_images[ self::TOP_EYECATCH_KEY ] )
			: 0;
		$top_eyecatch_base_id = 0 < $top_eyecatch_id ? (int) get_post_meta( $top_eyecatch_id, '_gachireai_image_base_attachment_id', true ) : 0;
		$preview_image_id     = 0 < $top_eyecatch_base_id ? $top_eyecatch_base_id : $top_eyecatch_id;
		$top_eyecatch_url     = 0 < $preview_image_id && function_exists( 'wp_get_attachment_image_url' )
			? (string) wp_get_attachment_image_url( $preview_image_id, 'full' )
			: '';
		$favicon_override = isset( $site_image_overrides['favicon'] )
			? absint( (string) $site_image_overrides['favicon'] )
			: 0;
		$site_images_disabled = Capabilities::IMAGE_NONE === Status::capabilities()->image_background_mode();
		return array(
			'site_images_form_action'   => esc_url( Tabs::url( Tabs::TAB_SITE ) ),
			'site_images_hidden_fields' => array(
				array(
					'name'  => esc_attr( self::ACTION_FIELD ),
					'value' => esc_attr( self::ACTION_SITE_IMAGES ),
				),
				array(
					'name'  => esc_attr( '_wpnonce' ),
					'value' => esc_attr( wp_create_nonce( self::NONCE_ACTION_SITE_IMAGES ) ),
				),
			),
			'site_images_title'         => esc_html__( 'サイト画像', GACHIREAI_TEXT_DOMAIN ),
			'site_images_anchor'        => esc_attr( 'site_images' ),
			'site_images_intro'         => esc_html__( 'AIで生成する画像の設定です。', GACHIREAI_TEXT_DOMAIN ),
			'site_images_items'         => array(
				...$this->site_image_style_models( $site_image_overrides ),
				$this->element_favicon_model( $favicon_override ),
				$this->profile_image_model( $my_persona ),
				$this->eyecatch_group_model( $site_images, $site_image_overrides, $top_eyecatch_url ),
				$this->site_image_ogp_model(),
			),
			'site_images_submit_label'  => esc_html__( '保存', GACHIREAI_TEXT_DOMAIN ),
			'site_images_submit_action' => esc_attr( self::AJAX_ACTION_SAVE_SITE_IMAGES ),
			'site_images_disabled'      => $site_images_disabled,
			'site_images_note'          => $site_images_disabled
				? esc_html__( 'サイト画像は PRO, BASIC ライセンスでご利用いただけます。', GACHIREAI_TEXT_DOMAIN )
				: '',
			'site_images_messages'      => array(),
		);
	}
	private function profile_image_model( array $my_persona ): array {
		$form   = new Form( array( self::PHOTO_KEY => $this->profile_image_definition() ) );
		$fields = $form->build_list( $my_persona, array( self::PHOTO_KEY ), self::PROFILE_KEY );
		$field  = $fields[0] ?? array();
		$field['el_is_image'] = true;
		return $field;
	}
	private function site_image_style_models( array $overrides = array() ): array {
		$settings = new Settings();
		$image    = $settings->image();
		$family = isset( $overrides['style_family'] ) && is_scalar( $overrides['style_family'] )
			? (string) $overrides['style_family']
			: Image_Catalog::family_of( (string) ( $image['style'] ?? '' ) );
		$text   = isset( $overrides['style_text'] ) && is_scalar( $overrides['style_text'] )
			? (string) $overrides['style_text']
			: (string) ( $image['style_text'] ?? '' );
		$form = new Form(
			array(
				'style_family' => $this->site_image_style_family_definition(),
				'style_text'   => $this->site_image_style_text_definition(),
			)
		);
		$fields = $form->build_list(
			array(
				'style_family' => $family,
				'style_text'   => $text,
			),
			array( 'style_family', 'style_text' ),
			self::IMAGE_KEY,
			array(
				'style_text' => array( 'field_header_buttons' => array( $this->propose_style_button() ) ),
			)
		);
		foreach ( $fields as &$field ) {
			$field['el_is_field'] = true;
		}
		unset( $field );
		return $fields;
	}
	private function top_eyecatch_model( array $site_images ): array {
		$value = isset( $site_images[ self::TOP_EYECATCH_KEY ] ) && is_scalar( $site_images[ self::TOP_EYECATCH_KEY ] )
			? (string) $site_images[ self::TOP_EYECATCH_KEY ]
			: '';
		$form   = new Form( array( self::TOP_EYECATCH_KEY => $this->top_eyecatch_definition() ) );
		$fields = $form->build_list(
			array( self::TOP_EYECATCH_KEY => $value ),
			array( self::TOP_EYECATCH_KEY ),
			self::SITE_IMAGES_KEY
		);
		$field  = $fields[0] ?? array();
		$field['el_is_image'] = true;
		$field['image_hide_preview'] = true;
		return $field;
	}
	private function eyecatch_group_model( array $site_images, array $site_image_overrides, string $preview_image_url ): array {
		return array(
			'el_is_eyecatch_group'   => true,
			'el_is_field'            => false,
			'el_is_image'            => false,
			'el_is_favicon'          => false,
			'el_is_font'             => false,
			'el_label'               => esc_html__( 'アイキャッチ', GACHIREAI_TEXT_DOMAIN ),
			'el_description'         => '',
			'font_preview_image_url' => esc_url( $preview_image_url ),
			'font_preview_loading'   => esc_html__( 'プレビューを生成中...', GACHIREAI_TEXT_DOMAIN ),
			'group_items'            => array(
				$this->top_eyecatch_model( $site_images ),
				$this->font_model( $site_image_overrides ),
				$this->site_image_top_hero_model(),
			),
		);
	}
	private function font_model( array $overrides = array() ): array {
		$settings = new Settings();
		$image    = $settings->image();
		$font = isset( $overrides['font'] ) && is_scalar( $overrides['font'] )
			? (string) $overrides['font']
			: (string) ( $image['font'] ?? Font_Catalog::DEFAULT_FONT );
		$font = Font_Manager::normalize_font( $font );
		$weight = isset( $overrides['font_weight'] ) && is_scalar( $overrides['font_weight'] )
			? (string) $overrides['font_weight']
			: (string) ( $image['font_weight'] ?? Font_Catalog::DEFAULT_WEIGHT );
		$weight = Font_Manager::normalize_weight( $font, $weight );
		$options = array();
		foreach ( Font_Manager::all_options() as $slug => $label ) {
			$slug = (string) $slug;
			$options[] = array(
				'opt_value'          => esc_attr( $slug ),
				'opt_label'          => esc_html( (string) $label ),
				'opt_selected'       => $slug === $font,
				'opt_weights'        => esc_attr( implode( ' ', Font_Manager::weights_for( $slug ) ) ),
				'opt_default_weight' => esc_attr( Font_Manager::default_weight( $slug ) ),
			);
		}
		$weight_options = array();
		foreach ( Font_Catalog::weight_labels() as $value => $label ) {
			$weight_options[] = array(
				'weight_value'    => esc_attr( (string) $value ),
				'weight_label'    => esc_html( (string) $label ),
				'weight_selected' => (string) $value === $weight,
			);
		}
		return array(
			'el_is_font'          => true,
			'el_is_field'         => false,
			'el_is_image'         => false,
			'el_is_favicon'       => false,
			'el_label'            => esc_html__( 'フォント', GACHIREAI_TEXT_DOMAIN ),
			'el_description'      => esc_html__( 'トップページと記事ページの両方のアイキャッチ画像で使うフォントです。', GACHIREAI_TEXT_DOMAIN ),
			'font_name'           => esc_attr( self::IMAGE_KEY . '[font]' ),
			'font_weight_name'    => esc_attr( self::IMAGE_KEY . '[font_weight]' ),
			'font_options'           => $options,
			'font_weight_options'    => $weight_options,
			'font_upload_action'     => esc_attr( self::AJAX_ACTION_UPLOAD_FONT ),
			'font_upload_title'   => esc_html__( 'フォントを追加', GACHIREAI_TEXT_DOMAIN ),
			'font_upload_button'  => esc_attr( self::UPLOAD_FONT_FIELD ),
			'font_upload_label'   => esc_html__( 'アップロード', GACHIREAI_TEXT_DOMAIN ),
			'font_file_name'      => esc_attr( self::FONT_UPLOAD_FIELD ),
			'font_file_accept'    => esc_attr( '.ttf,.otf' ),
			'font_weight_label'   => esc_html__( '太さ', GACHIREAI_TEXT_DOMAIN ),
			'font_messages'       => array(),
		);
	}
	private function site_image_ogp_model(): array {
		$settings = new Settings();
		$form     = new Form( array( 'ogp' => $this->site_image_ogp_definition() ) );
		$fields   = $form->build_list( $settings->image(), array( 'ogp' ), self::IMAGE_KEY );
		$field    = $fields[0] ?? array();
		$field['el_is_field'] = true;
		return $field;
	}
	private function site_image_top_hero_model(): array {
		$settings = new Settings();
		$form     = new Form( array( 'top_hero' => $this->site_image_top_hero_definition() ) );
		$fields   = $form->build_list( $settings->image(), array( 'top_hero' ), self::IMAGE_KEY );
		$field    = $fields[0] ?? array();
		$field['el_is_field'] = true;
		return $field;
	}
	private function element_field_model( string $key, array $elements, array $candidates, array $definitions, bool $readonly = false, bool $headless = false ): array {
		$definition = $definitions[ $key ] ?? array();
		$type       = (string) ( $definition['type'] ?? 'text' );
		$is_area    = 'textarea' === $type;
		$value      = isset( $elements[ $key ] ) && is_scalar( $elements[ $key ] ) ? (string) $elements[ $key ] : '';
		$list       = array_values( (array) ( $candidates[ $key ] ?? array() ) );
		if ( array() !== $list ) {
			$value = (string) $list[0];
		}
		return array(
			'el_is_field'            => true,
			'el_is_readonly_display' => false,
			'el_label'               => esc_html( (string) ( $definition['label'] ?? '' ) ),
			'el_description'         => esc_html( (string) ( $definition['description'] ?? '' ) ),
			'el_field_name'          => esc_attr( self::ELEMENTS_KEY . '[' . $key . ']' ),
			'el_value'               => $is_area ? esc_textarea( $value ) : esc_attr( $value ),
			'el_is_textarea'         => $is_area,
			'el_rows'                => (string) ( $definition['rows'] ?? 4 ),
			'el_is_readonly_field'   => $readonly,
			'el_is_headless'         => $headless,
			'el_pick_name'           => esc_attr( 'gachireai_pick_' . $key ),
			'el_candidates_label'    => esc_html__( '以下の候補に変更することも、記入欄で直接変更することも可能です。', GACHIREAI_TEXT_DOMAIN ),
			'el_candidates'          => $this->candidate_models( $list ),
			'el_readonly_value'      => '',
		);
	}
	private function element_favicon_model( int $override = 0 ): array {
		$id        = 0 < $override ? $override : (int) get_option( 'site_icon', 0 );
		$url       = 0 < $id ? (string) wp_get_attachment_image_url( $id, 'medium' ) : '';
		$available = $this->image_available();
		return array(
			'el_is_field'              => false,
			'el_is_favicon'            => true,
			'el_label'                 => esc_html__( 'ファビコン', GACHIREAI_TEXT_DOMAIN ),
			'el_description'           => esc_html__( 'サイトのアイコン（favicon）です。', GACHIREAI_TEXT_DOMAIN ),
			'favicon_id'               => esc_attr( (string) $id ),
			'favicon_url'              => esc_url( $url ),
			'favicon_has_image'        => '' !== $url,
			'favicon_disabled'         => ! $available,
			'favicon_button_label'     => esc_html__( 'AI生成', GACHIREAI_TEXT_DOMAIN ),
			'button_icon'              => 'robot',
			'favicon_button_action'    => esc_attr( self::AJAX_ACTION_GENERATE_FAVICON ),
			'favicon_button_name'      => esc_attr( self::GENERATE_FAVICON_FIELD ),
			'favicon_button_title'     => $available
				? esc_attr__( '文字なしのフラットアイコンを生成します（［保存］でサイトアイコンに反映）', GACHIREAI_TEXT_DOMAIN )
				: esc_attr__( '画像生成AIの設定を保存すると使えます。', GACHIREAI_TEXT_DOMAIN ),
			'favicon_messages'         => array(),
		);
	}
	private function element_readonly_model( string $label, string $value, string $description ): array {
		return array(
			'el_is_field'            => false,
			'el_is_readonly_display' => true,
			'el_label'               => esc_html( $label ),
			'el_description'         => esc_html( $description ),
			'el_field_name'          => '',
			'el_value'               => '',
			'el_is_textarea'         => false,
			'el_rows'                => '4',
			'el_is_readonly_field'   => false,
			'el_is_headless'         => false,
			'el_pick_name'           => '',
			'el_candidates_label'    => '',
			'el_candidates'          => array(),
			'el_readonly_value'      => '' !== trim( $value )
				? esc_html( $value )
				: esc_html__( '（未設定）', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	private function candidate_models( array $candidates ): array {
		$models = array();
		foreach ( array_values( $candidates ) as $index => $candidate ) {
			$models[] = array(
				'cand_value'   => esc_attr( (string) $candidate ),
				'cand_label'   => esc_html( (string) $candidate ),
				'cand_checked' => 0 === $index,
			);
		}
		return $models;
	}
	private function scope_definitions(): array {
		$ec     = $this->saved_ec();
		$term   = (string) ( Genre_Catalog::terms( $ec )['singular'] ?? '' );
		$amazon = $this->is_amazon_without_genre_search();
		return array(
			'site_type' => array(
				'label'       => __( 'サイトのタイプ', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'select',
				'driver'      => 'site_type',
				'description' => __( "総合＝何でも扱う、百貨店や総合スーパータイプ\nカテゴリー限定＝特定分野だけを扱う、専門店タイプ\nニッチ＝単一・数品種だけ深く扱う、マニア向けショップタイプ", GACHIREAI_TEXT_DOMAIN ),
				'options'       => array(
					'general'  => __( '総合（何でも扱う）', GACHIREAI_TEXT_DOMAIN ),
					'category' => __( 'カテゴリー限定（分野を絞る）', GACHIREAI_TEXT_DOMAIN ),
					'niche'    => __( 'ニッチ（単一・数品種）', GACHIREAI_TEXT_DOMAIN ),
				),
				'disabled'      => $amazon ? array( 'general', 'category' ) : array(),
				'default'       => $amazon ? 'niche' : Site_Profile::DEFAULT_SITE_TYPE,
				'warning'       => $amazon
					? __( 'メインEC で Amazonを選択し、使用APIが Free AMZN Creators API のときは、「ニッチ」しか選べません。Creators API に切り替えると全タイプを選べます。', GACHIREAI_TEXT_DOMAIN )
					: '',
			),
			self::SCOPE_EC_KEY => array(
				'label' => __( '検索ECサイト', GACHIREAI_TEXT_DOMAIN ),
				'type'  => 'readonly',
				'note'  => '<a href="' . esc_url( Tabs::url( Tabs::TAB_ASP ) ) . '">'
					. esc_html__( '「ASP設定」で変更します。', GACHIREAI_TEXT_DOMAIN )
					. '</a>',
			),
			'category'  => array(
				'row_depends'   => array(
					'on'     => 'site_type',
					'values' => array( 'category', 'niche' ),
				),
				'label'         => sprintf(
					__( '%s', GACHIREAI_TEXT_DOMAIN ),
					$term
				),
				'type'          => 'category',
				'rows'          => 5,
				'max'           => 1000,
				'placeholder'   => __( '例: (ID:211742)TV・オーディオ・カメラ', GACHIREAI_TEXT_DOMAIN ),
				'description'   => sprintf(
					__( "カテゴリー限定・ニッチのときに入力します。\n`(カテゴリーID)%1\$s名` の形式で指定します。\n改行またはカンマで区切ってください。\n［%2\$sを選ぶ］で EC の実データ（取得済みの%2\$s）から選べます。\n手入力もできます。\n［クリア］は入力欄を空にするだけで、データベースには「保存」を押すまで反映しません。\nカテゴリー名は一部の商品で検索語としても使われます。", GACHIREAI_TEXT_DOMAIN ),
					$term,
					$term
				),
				'picker_action' => self::AJAX_ACTION_CATEGORY_OPEN,
				'picker_label'  => sprintf(
					__( '%1$sの%2$s一覧から選択して追加', GACHIREAI_TEXT_DOMAIN ),
					Product_Selector::ec_short_label( $ec ),
					$term
				),
				'picker_target' => '#' . self::PICKER_ID,
				'picker_clear_label' => __( 'クリア', GACHIREAI_TEXT_DOMAIN ),
				'normalize'     => static function ( $value ): string {
					$lines = array();
					foreach ( Genre_Catalog::entries( (string) $value ) as $entry ) {
						$line = Genre_Catalog::format_entry( (string) $entry['genre_id'], (string) $entry['name'] );
						if ( '' !== $line ) {
							$lines[] = $line;
						}
					}
					return implode( "\n", $lines );
				},
			),
			'products'  => array(
				'row_depends' => array(
					'on'     => 'site_type',
					'values' => array( 'niche' ),
				),
				'label'       => __( 'キーワード', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'textarea',
				'rows'        => 3,
				'max'         => 1000,
				'description' => __( "ニッチのときに入力します。\n改行またはカンマで区切ってください。\n2語以上の複合キーワードは \"ワイヤレス イヤホン\" のようにダブルクォーテーションで囲ってください。\n［AI 提案］は最終行へ追記します。", GACHIREAI_TEXT_DOMAIN ),
				'normalize'   => static function ( $value ): string {
					return Product_Selector::keyword_block( (string) $value );
				},
			),
		);
	}
	private function target_definitions(): array {
		return array(
			self::FREE_TEXT_KEY => array(
				'label'       => __( '特記事項（任意）', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'textarea',
				'rows'        => 5,
				'max'         => 2000,
				'description' => __( "特に指定したい内容が有る時は自由記述形式で設定できます。\n任意項目なので空欄でも大丈夫です。", GACHIREAI_TEXT_DOMAIN ),
			),
			'attributes'        => array(
				'label'       => __( '属性', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
			),
			'worries'           => array(
				'label'       => __( '悩み・課題', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'textarea',
				'rows'        => 4,
				'max'         => 2000,
			),
			'wants'             => array(
				'label'       => __( '求める情報', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'textarea',
				'rows'        => 4,
				'max'         => 2000,
			),
			'knowledge_level'   => array(
				'label'   => __( '知識レベル', GACHIREAI_TEXT_DOMAIN ),
				'type'    => 'select',
				'options' => array(
					'beginner'     => __( '初心者', GACHIREAI_TEXT_DOMAIN ),
					'intermediate' => __( '中級者', GACHIREAI_TEXT_DOMAIN ),
					'advanced'     => __( '上級者', GACHIREAI_TEXT_DOMAIN ),
				),
				'default' => 'beginner',
			),
			'search_flow'       => array(
				'label'       => __( '検索動線', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
			),
			'purchase_behavior' => array(
				'label'       => __( '購買行動', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'textarea',
				'rows'        => 4,
				'max'         => 2000,
			),
		);
	}
	private function elements_definitions(): array {
		return array(
			'site_name'      => array(
				'label'       => __( 'サイト名', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
			),
			'description'    => array(
				'label'       => __( 'サイト説明文', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'textarea',
				'rows'        => 4,
				'max'         => 2000,
				'description' => __( '誰に向けて何を提供するサイトかを説明します（サイト上部や検索結果に使う想定）。', GACHIREAI_TEXT_DOMAIN ),
			),
			'profile'        => array(
				'label'       => __( '自己紹介・プロフィール', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'textarea',
				'rows'        => 5,
				'max'         => 2000,
				'description' => __( "トップページやウィジェットの自己紹介で表示します。\n一人称で書いてください。", GACHIREAI_TEXT_DOMAIN ),
			),
			'writing_sample' => array(
				'label' => __( '文体サンプル', GACHIREAI_TEXT_DOMAIN ),
				'type'  => 'textarea',
				'rows'  => 8,
				'max'   => 5000,
			),
		);
	}
	private function elements_buttons( Site_Generator $generator ): array {
		$disabled = ! $generator->is_available();
		return array(
			array(
				'btn_name'     => esc_attr( self::GENERATE_ALL_FIELD ),
				'btn_value'    => '1',
				'btn_label'    => esc_html__( 'AI でサイト要素と文体サンプルを提案', GACHIREAI_TEXT_DOMAIN ),
				'btn_disabled' => $disabled,
				'btn_title'    => $disabled
					? esc_attr( Site_Generator::unavailable_message() )
					: esc_attr( __( '入力中の内容を保存してから、サイトの情報・書く人・読者を文脈に、サイト名・説明文・プロフィールの候補と、マイペルソナの声の文体サンプルをまとめて生成します', GACHIREAI_TEXT_DOMAIN ) ),
				'btn_action'   => esc_attr( self::AJAX_ACTION_GENERATE_ELEMENTS ),
				'button_icon'  => 'robot',
			),
		);
	}
	private function resolve_posted_image_style( array $current, array $clean ): array {
		$family = (string) ( $clean['style_family'] ?? '' );
		$text   = (string) ( $clean['style_text'] ?? '' );
		if ( Image_Catalog::STYLE_CUSTOM === $family && Status::capabilities()->is_pro() ) {
			$previous_en  = (string) ( $current['style_text_en'] ?? '' );
			$previous_src = (string) ( $current['style_text_src'] ?? '' );
			$reuse = ( '' !== trim( $previous_en ) && $text === $previous_src );
			$current['style']         = Image_Catalog::STYLE_CUSTOM;
			$current['style_text']    = $text;
			$current['style_text_en'] = $this->translate_style_text(
				$text,
				$reuse ? $previous_en : '',
				$reuse ? $text : ''
			);
			$has_japanese = 1 === preg_match( '/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}]/u', $text );
			$current['style_text_src'] = ( ! $has_japanese || $current['style_text_en'] !== $text ) ? $text : '';
			return $current;
		}
		$families = Image_Catalog::families();
		if ( '' !== $family && isset( $families[ $family ] ) ) {
			$current_style = (string) ( $current['style'] ?? '' );
			if ( Image_Catalog::STYLE_CUSTOM === $current_style || Image_Catalog::family_of( $current_style ) !== $family ) {
				$current['style'] = Image_Catalog::random_style( $family );
			}
		}
		$current['style_text'] = $text;
		return $current;
	}
	private function translate_style_text( string $text, string $current_en, string $current_text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}
		if ( $text === $current_text && '' !== trim( $current_en ) ) {
			return $current_en;
		}
		if ( 1 !== preg_match( '/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}]/u', $text ) ) {
			return $text;
		}
		try {
			return ( new Style_Proposer() )->to_english( $text );
		} catch ( \Throwable $e ) {
			return $text;
		}
	}
	private function propose_style_button(): array {
		$available = ( new Style_Proposer() )->is_available();
		$is_pro    = Status::capabilities()->is_pro();
		$disabled  = ! $available || ! $is_pro;
		if ( ! $is_pro ) {
			$title = __( '画風の自由設定は PRO ライセンスでご利用いただけます。', GACHIREAI_TEXT_DOMAIN );
		} elseif ( ! $available ) {
			$title = Style_Proposer::unavailable_message();
		} else {
			$title = __( "画風（タッチ・技法・画材）の指定文を、毎回異なる系統から提案します。\n入力欄へ入るだけで、保存するまでデータベースに反映されません。", GACHIREAI_TEXT_DOMAIN );
		}
		return array(
			'btn_name'     => esc_attr( self::GENERATE_STYLE_FIELD ),
			'btn_value'    => '1',
			'btn_label'    => esc_html__( 'AI提案', GACHIREAI_TEXT_DOMAIN ),
			'btn_disabled' => $disabled,
			'btn_title'    => esc_attr( $title ),
			'btn_action'   => esc_attr( self::AJAX_ACTION_PROPOSE_STYLE ),
			'button_icon'  => 'robot',
		);
	}
	public function ajax_propose_style(): void {
		$this->guard_ajax( self::NONCE_ACTION_SITE_IMAGES );
		$raw  = $this->posted_array( self::IMAGE_KEY );
		$text = is_array( $raw ) && isset( $raw['style_text'] )
			? sanitize_textarea_field( (string) $raw['style_text'] )
			: '';
		$keep = array(
			'style_family' => Image_Catalog::STYLE_CUSTOM,
			'style_text'   => $text,
		);
		if ( ! Status::capabilities()->is_pro() ) {
			$this->send_form_html(
				fn(): string => $this->render_site_images_form(
					array(),
					$keep,
					array( 'style' => array( $this->message( 'error', esc_html__( '画風の自由設定は PRO ライセンスでご利用いただけます。', GACHIREAI_TEXT_DOMAIN ) ) ) )
				)
			);
			return;
		}
		try {
			$proposal = ( new Style_Proposer() )->propose();
		} catch ( \Throwable $e ) {
			$this->send_form_html(
				fn(): string => $this->render_site_images_form(
					array(),
					$keep,
					array( 'style' => array( $this->message( 'error', esc_html( $e->getMessage() ) ) ) )
				)
			);
			return;
		}
		$this->send_form_html(
			fn(): string => $this->render_site_images_form(
				array(),
				array(
					'style_family' => Image_Catalog::STYLE_CUSTOM,
					'style_text'   => $proposal,
				),
				array( 'style' => array( $this->message( 'success', esc_html__( '画風の候補を入力欄へ入れました。内容を確認して「保存」を押してください。', GACHIREAI_TEXT_DOMAIN ) ) ) )
			)
		);
	}
	private function site_image_style_family_definition(): array {
		$is_pro  = Status::capabilities()->is_pro();
		$options = Image_Catalog::families();
		$options[ Image_Catalog::STYLE_CUSTOM ] = __( '自由設定（自由記述）', GACHIREAI_TEXT_DOMAIN );
		$description = __( 'サイト全体でトーン＆マナーを統一します。系統を選ぶと、その系統内の画風が自動で決まります。', GACHIREAI_TEXT_DOMAIN );
		if ( ! $is_pro ) {
			$description .= ' ' . __( '「自由設定」は PRO ライセンスでご利用いただけます。', GACHIREAI_TEXT_DOMAIN );
		}
		return array(
			'label'       => __( 'サイト全体の画風', GACHIREAI_TEXT_DOMAIN ),
			'type'        => 'radio',
			'options'     => $options,
			'default'     => Image_Catalog::FAMILY_PHOTOREAL,
			'disabled'    => $is_pro ? array() : array( Image_Catalog::STYLE_CUSTOM ),
			'driver'      => 'style_family',
			'description' => $description,
		);
	}
	private function site_image_style_text_definition(): array {
		$is_pro = Status::capabilities()->is_pro();
		return array(
			'label'          => __( '画風の自由記述', GACHIREAI_TEXT_DOMAIN ),
			'type'           => 'textarea',
			'rows'           => 3,
			'row_depends'    => array(
				'on'     => 'style_family',
				'values' => array( Image_Catalog::STYLE_CUSTOM ),
			),
			'field_disabled' => ! $is_pro,
			'description'    => __( "「自由設定」を選んだときに、画風を日本語で記述します（例: やわらかい水彩タッチ、明るい色合い、温かみのある光）。\n保存時に英語へ変換して画像生成に使います。文章生成AIの設定が必要です。", GACHIREAI_TEXT_DOMAIN ),
		);
	}
	private function site_image_font_definition(): array {
		return array(
			'label'       => __( 'フォント', GACHIREAI_TEXT_DOMAIN ),
			'type'        => 'radio',
			'options'     => Font_Manager::all_options(),
			'default'     => Font_Catalog::DEFAULT_FONT,
			'description' => __( 'アイキャッチの文字入れに使うフォントです。', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	private function site_image_font_weight_definition(): array {
		return array(
			'label'   => __( '太さ', GACHIREAI_TEXT_DOMAIN ),
			'type'    => 'select',
			'options' => Font_Catalog::weight_labels(),
			'default' => Font_Catalog::DEFAULT_WEIGHT,
		);
	}
	private function site_image_ogp_definition(): array {
		return array(
			'label'       => __( 'OGPの有効化', GACHIREAI_TEXT_DOMAIN ),
			'type'        => 'checkbox',
			'options'     => array( '1' => __( 'トップページと記事ページの OGP タグを出力する', GACHIREAI_TEXT_DOMAIN ) ),
			'description' => __( "SNSでWebページのURLがシェアされた際に、そのページのタイトルや説明文、サムネイル画像を意図した通りにリッチカードとして自動表示させるための仕組みです。\nテーマにも OGP 出力機能が備わっている場合があります。\n重複しないように、いずれかの OGP を OFF にしてください。\n例: Cocoon", GACHIREAI_TEXT_DOMAIN ),
		);
	}
	private function site_image_top_hero_definition(): array {
		return array(
			'label'       => __( 'トップページのトップ画', GACHIREAI_TEXT_DOMAIN ),
			'type'        => 'radio',
			'options'     => array(
				Settings::TOP_HERO_WITH_TITLE => __( 'タイトル入りで表示（アイキャッチ画像）', GACHIREAI_TEXT_DOMAIN ),
				Settings::TOP_HERO_IMAGE_ONLY => __( 'タイトルなしで表示（画像のみ）', GACHIREAI_TEXT_DOMAIN ),
				Settings::TOP_HERO_HIDDEN     => __( '表示しない', GACHIREAI_TEXT_DOMAIN ),
			),
			'default'     => Settings::TOP_HERO_IMAGE_ONLY,
			'description' => __( 'トップページの先頭に表示する画像を選びます。', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	private function profile_image_definition(): array {
		$image_available = $this->image_available();
		return array(
			'label'             => __( 'プロフィール画像', GACHIREAI_TEXT_DOMAIN ),
			'type'              => 'image',
			'description'       => __( '自己紹介（トップページ, ウィジェットなど）に使う画像です。', GACHIREAI_TEXT_DOMAIN ),
			'pick_label'        => __( '画像を選択', GACHIREAI_TEXT_DOMAIN ),
			'clear_label'       => __( '削除', GACHIREAI_TEXT_DOMAIN ),
			'generate_label'    => __( 'AI生成', GACHIREAI_TEXT_DOMAIN ),
			'generate_name'     => self::GENERATE_PROFILE_PHOTO_FIELD,
			'generate_action'   => self::AJAX_ACTION_GENERATE_PROFILE_PHOTO,
			'generate_disabled' => ! $image_available,
			'generate_title'    => $image_available
				? __( 'マイペルソナの人物像からプロフィール画像を生成します', GACHIREAI_TEXT_DOMAIN )
				: __( '画像生成AIの設定を保存すると使えます。', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	private function top_eyecatch_definition(): array {
		$image_available = $this->image_available();
		return array(
			'label'             => __( 'トップページ用アイキャッチ', GACHIREAI_TEXT_DOMAIN ),
			'type'              => 'image',
			'description'       => __( "トップページの先頭に表示し、アイキャッチ画像として登録します。\nOGPの有効化が ON のときは SNS 共有時の og:image にも使われます。", GACHIREAI_TEXT_DOMAIN ),
			'pick_label'        => __( '画像を選択', GACHIREAI_TEXT_DOMAIN ),
			'clear_label'       => __( '削除', GACHIREAI_TEXT_DOMAIN ),
			'generate_label'    => __( 'AI生成', GACHIREAI_TEXT_DOMAIN ),
			'generate_name'     => self::GENERATE_TOP_EYECATCH_FIELD,
			'generate_action'   => self::AJAX_ACTION_GENERATE_TOP_EYECATCH,
			'generate_disabled' => ! $image_available,
			'generate_title'    => $image_available
				? __( 'サイトの世界観からトップページ用アイキャッチを生成します', GACHIREAI_TEXT_DOMAIN )
				: __( '画像生成AIの設定を保存すると使えます。', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	private function definitions(): array {
		return array(
			'gender'            => array(
				'label'       => __( '性別', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'radio',
				'options'     => array(
					'male'   => __( '男性', GACHIREAI_TEXT_DOMAIN ),
					'female' => __( '女性', GACHIREAI_TEXT_DOMAIN ),
				),
				'default'     => 'male',
				'description' => __( '記事を書く人の性別', GACHIREAI_TEXT_DOMAIN ),
			),
			'tone'              => array(
				'label'       => __( '口調', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'radio',
				'options'     => array(
					'academic' => __( '論文調', GACHIREAI_TEXT_DOMAIN ),
					'polite'   => __( 'ですます調', GACHIREAI_TEXT_DOMAIN ),
					'casual'   => __( '口語調', GACHIREAI_TEXT_DOMAIN ),
					'playful'  => __( 'ふざけ調', GACHIREAI_TEXT_DOMAIN ),
				),
				'default'     => 'polite',
				'description' => __( 'この口調や文体に合わせて記事を生成します。', GACHIREAI_TEXT_DOMAIN ),
			),
			self::FREE_TEXT_KEY => array(
				'label'       => __( '特記事項（任意）', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'textarea',
				'rows'        => 5,
				'max'         => 2000,
				'description' => __( "性格・職業・居住地などを自由記述形式で設定できます。\n任意項目なので空欄でも大丈夫です。", GACHIREAI_TEXT_DOMAIN ),
			),
			'name'              => array(
				'label'       => __( '名前', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
			),
			'author_slug'       => array(
				'label'       => __( '投稿者スラッグ', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
				'description' => __( "記事の投稿者 URL に使う識別子です。\n英数字とハイフンが使えます。\nマイペルソナの自動生成で作られ、反映時に自動でスラッグ化されます。", GACHIREAI_TEXT_DOMAIN ),
			),
			'attributes'        => array(
				'label'       => __( '属性', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
			),
			'background'        => array(
				'label'       => __( '背景', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'textarea',
				'rows'        => 4,
				'max'         => 2000,
			),
			'knowledge_level'   => array(
				'label'       => __( '知識レベル', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
			),
			'writing_style'     => array(
				'label'       => __( '文体・口調', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
			),
			'position'          => array(
				'label'   => __( 'ポジション', GACHIREAI_TEXT_DOMAIN ),
				'type'    => 'select',
				'options' => array(
					'experiencer' => __( '体験者', GACHIREAI_TEXT_DOMAIN ),
					'critic'      => __( '評論家', GACHIREAI_TEXT_DOMAIN ),
					'researcher'  => __( '研究者', GACHIREAI_TEXT_DOMAIN ),
					'curator'     => __( 'キュレーター', GACHIREAI_TEXT_DOMAIN ),
				),
				'default' => 'experiencer',
			),
			'stance'            => array(
				'label'       => __( 'スタンス', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'textarea',
				'rows'        => 4,
				'max'         => 2000,
			),
		);
	}
	private function saved_ec(): string {
		$values = (array) ( new Settings() )->get( 'ec', array() );
		return Product_Selector::normalize_ec( (string) ( $values['ec'] ?? '' ) );
	}
	private function is_amazon_without_genre_search(): bool {
		if ( Product_Selector::EC_AMAZON !== $this->saved_ec() ) {
			return false;
		}
		$amazon = (array) ( new Settings() )->get( Product_Selector::EC_AMAZON, array() );
		return Amazon_Client::BACKEND_FREE_AMZN === Amazon_Client::backend( $amazon );
	}
	private function scope_values( array $scope ): array {
		$scope[ self::SCOPE_EC_KEY ] = Product_Selector::ec_label( $this->saved_ec() );
		return $scope;
	}
	private function scope_field_overrides(): array {
		return array(
			'products' => array(
				'field_header_buttons' => array( $this->propose_keywords_button() ),
			),
		);
	}
	private function propose_keywords_button(): array {
		$available = ( new Keyword_Proposer() )->is_available();
		return array(
			'btn_name'     => '',
			'btn_value'    => '',
			'btn_label'    => esc_html__( 'AI 提案', GACHIREAI_TEXT_DOMAIN ),
			'btn_disabled' => ! $available,
			'btn_title'    => $available
				? esc_attr( __( "このサイトが扱う商品を検索するためのキーワードを提案します。\n既存分と重複しない 10 件を入力欄の最終行へ追記します。\n入力欄へ入るだけなので「保存」を押さなければデータベースに反映されません。", GACHIREAI_TEXT_DOMAIN ) )
				: esc_attr( Keyword_Proposer::unavailable_message() ),
			'btn_action'   => esc_attr( self::AJAX_ACTION_PROPOSE_KEYWORDS ),
			'button_icon'  => 'robot',
		);
	}
	public function ajax_category_open(): void {
		$this->guard_ajax( self::NONCE_ACTION );
		$ec     = $this->saved_ec();
		$parent = $this->posted_category_parent();
		$this->send_form_html(
			fn(): string => $this->render_template_string(
				'admin/site-setup-category-form',
				$this->picker( $ec, $parent, $this->fetch_messages( $ec, $parent ) )
			)
		);
	}
	private function fetch_messages( string $ec, string $parent ): array {
		$result = ( new Genre_Catalog() )->sync( $ec, $parent );
		$status = (string) ( $result['status'] ?? '' );
		if ( in_array( $status, array( Genre_Catalog::STATUS_OK, Genre_Catalog::STATUS_NO_HIT ), true ) ) {
			return array();
		}
		$reason = (string) ( $result['message'] ?? '' );
		return array(
			$this->message(
				'error',
				esc_html( '' !== $reason ? $reason : __( 'カテゴリーの取得に失敗しました。', GACHIREAI_TEXT_DOMAIN ) )
			),
		);
	}
	public function ajax_category_pick(): void {
		$this->guard_ajax( self::NONCE_ACTION );
		$values = array(
			self::PROFILE_KEY => $this->sanitize_persona( $this->posted_array( self::PROFILE_KEY ) ?? array() ),
			self::SCOPE_KEY   => $this->sanitize_scope( $this->posted_array( self::SCOPE_KEY ) ?? array() ),
			self::TARGET_KEY  => $this->sanitize_target_persona( $this->posted_array( self::TARGET_KEY ) ?? array() ),
		);
		$ec       = $this->saved_ec();
		$genre_id = $this->posted_category_id();
		$node     = '' !== $genre_id ? ( new Genre_Catalog() )->find( $ec, $genre_id ) : array();
		$messages = array();
		if ( array() === $node ) {
			$messages[ self::SCOPE_KEY ] = array(
				$this->message( 'error', esc_html__( "選んだカテゴリーが台帳に見つかりませんでした。\nモーダルを開き直してから、もう一度お試しください。", GACHIREAI_TEXT_DOMAIN ) ),
			);
		} else {
			$entry = Genre_Catalog::format_entry( (string) $node['genre_id'], (string) $node['name'] );
			$values[ self::SCOPE_KEY ]['category'] = Genre_Catalog::append_entry(
				(string) ( $values[ self::SCOPE_KEY ]['category'] ?? '' ),
				(string) $node['genre_id'],
				(string) $node['name']
			);
			$messages[ self::SCOPE_KEY ] = array(
				$this->message(
					'success',
					esc_html(
						sprintf(
							__( "%s を追加しました。\n「保存」を押すと確定します。", GACHIREAI_TEXT_DOMAIN ),
							$entry
						)
					)
				),
			);
		}
		$this->send_form_html(
			fn(): string => $this->render_persona_form( $messages, array(), $values )
		);
	}
	private function picker( string $ec, string $parent, array $messages ): array {
		$catalog  = new Genre_Catalog();
		$term     = (string) ( Genre_Catalog::terms( $ec )['singular'] ?? '' );
		$parent   = '' !== $parent ? $parent : Genre_Catalog::root_id( $ec );
		$children = $catalog->children( $ec, $parent );
		$chain    = $catalog->path( $ec, $parent );
		$last     = count( $chain ) - 1;
		$path     = array();
		foreach ( $chain as $index => $step ) {
			$path[] = array(
				'genre_id'    => esc_attr( (string) $step['genre_id'] ),
				'label'       => esc_html( '' !== (string) $step['name'] ? (string) $step['name'] : __( 'トップ', GACHIREAI_TEXT_DOMAIN ) ),
				'is_current'  => $index === $last,
				'open_action' => esc_attr( self::AJAX_ACTION_CATEGORY_OPEN ),
			);
		}
		$rows = array();
		foreach ( $children as $child ) {
			$rows[] = array(
				'genre_id'    => esc_attr( (string) $child['genre_id'] ),
				'name'        => esc_html( (string) $child['name'] ),
				'open_action' => esc_attr( self::AJAX_ACTION_CATEGORY_OPEN ),
				'open_label'  => esc_html__( '下層を展開', GACHIREAI_TEXT_DOMAIN ),
			);
		}
		$level         = max( 0, count( $chain ) - 1 );
		$limit         = $catalog->depth_limit( $ec );
		$depth_limited = array() === $children && $limit > 0 && $level >= $limit;
		if ( $depth_limited ) {
			$empty_message = sprintf(
				__( 'Free AMZN Creators API では第%d階層までしか取得できません。', GACHIREAI_TEXT_DOMAIN ),
				$limit
			);
		} else {
			$empty_message = sprintf(
				__( 'この階層に子%sはありません。', GACHIREAI_TEXT_DOMAIN ),
				$term
			);
		}
		return array(
			'body_id'       => esc_attr( self::PICKER_BODY_ID ),
			'picker_id'     => esc_attr( self::PICKER_ID ),
			'title'         => esc_html(
				sprintf(
					__( '%sを選ぶ', GACHIREAI_TEXT_DOMAIN ),
					$term
				)
			),
			'description'   => '',
			'path'          => $path,
			'children'      => $rows,
			'empty_message' => esc_html( $empty_message ),
			'empty_class'   => esc_attr( $depth_limited ? 'description gachireai-field__warning' : 'description' ),
			'depth_limited' => $depth_limited,
			'messages'      => $messages,
			'parent_id'     => esc_attr( $parent ),
			'open_action'   => esc_attr( self::AJAX_ACTION_CATEGORY_OPEN ),
			'pick_action'   => esc_attr( self::AJAX_ACTION_CATEGORY_PICK ),
			'pick_label'    => esc_html(
				sprintf(
					__( 'チェックした%sを追加する', GACHIREAI_TEXT_DOMAIN ),
					$term
				)
			),
			'close_label'   => esc_html__( '閉じる', GACHIREAI_TEXT_DOMAIN ),
			'current_id'        => esc_attr( $parent ),
			'add_current_label' => esc_html(
				sprintf(
					__( 'この%sを追加する', GACHIREAI_TEXT_DOMAIN ),
					$term
				)
			),
		);
	}
	private function posted_category_parent(): string {
		$value = isset( $_POST[ self::CATEGORY_PARENT_FIELD ] ) ? wp_unslash( (string) $_POST[ self::CATEGORY_PARENT_FIELD ] ) : '';
		return mb_substr( (string) preg_replace( '/[^0-9]/', '', $value ), 0, 32 );
	}
	private function posted_category_id(): string {
		$value = isset( $_POST[ self::CATEGORY_ID_FIELD ] ) ? wp_unslash( (string) $_POST[ self::CATEGORY_ID_FIELD ] ) : '';
		return mb_substr( (string) preg_replace( '/[^0-9]/', '', $value ), 0, 32 );
	}
}
