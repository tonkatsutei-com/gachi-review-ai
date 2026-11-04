<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Article\Ai_Review;
use Tonkatsutei\Gachi_Review_AI\Article\Source_Settings;
use Tonkatsutei\Gachi_Review_AI\Ec\Ec_Review_Use;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\Fivech\Fivech_Client;
use Tonkatsutei\Gachi_Review_AI\Fivech\Fivech_Collector;
use Tonkatsutei\Gachi_Review_AI\Hacker_News\Hacker_News_Collector;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Llm\Source_Selector;
use Tonkatsutei\Gachi_Review_AI\Mastodon\Mastodon_Client;
use Tonkatsutei\Gachi_Review_AI\Mastodon\Mastodon_Collector;
use Tonkatsutei\Gachi_Review_AI\Reddit\Reddit_Collector;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
use Tonkatsutei\Gachi_Review_AI\Youtube\YouTube_Client;
use Tonkatsutei\Gachi_Review_AI\Youtube\YouTube_Collector;
final class Data_Setup extends Screen {
	public const AJAX_ACTION_SAVE = 'gachireai_save_data_setup';
	public const AJAX_ACTION_PROPOSE = 'gachireai_propose_data_setup';
	private const FIVECH_GROUP = 'fivech';
	private const REDDIT_GROUP = 'reddit';
	private const YOUTUBE_GROUP = 'youtube';
	private const HACKERNEWS_GROUP = 'hackernews';
	private const MASTODON_GROUP = 'mastodon';
	private const COMMON_GROUP = Source_Settings::GROUP;
	private const PARTNER_GROUP = Source_Settings::PARTNER_GROUP;
	private const REWRITE_GROUP = Ec_Review_Use::GROUP;
	private const AI_REVIEW_GROUP = 'ai_review';
	private const AI_REVIEW_DRIVER = 'ai_review_mode';
	private const ACTION = 'save_data_setup';
	private const NONCE_ACTION = 'gachireai_save_data_setup';
	private const ACTION_FIELD = 'gachireai_action';
	private const SAVED_ARG = 'gachireai_data_saved';
	private const PROPOSE_FIELD = 'gachireai_propose';
	private const SAVE_FIELD = 'gachireai_save';
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->render_template( 'admin/data-setup', $this->model() );
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
		$this->save();
		wp_safe_redirect( Tabs::url( Tabs::TAB_REVIEW, array( self::SAVED_ARG => '1' ) ) );
		exit;
	}
	public function ajax_save(): void {
		$this->guard_ajax( self::NONCE_ACTION );
		$saved = $this->save();
		$section = isset( $_POST[ self::SAVE_FIELD ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::SAVE_FIELD ] ) ) : '';
		if ( ! in_array( $section, $this->groups(), true ) ) {
			$section = self::AI_REVIEW_GROUP;
		}
		$message = $saved
			? $this->message( 'success', esc_html__( '保存しました。', GACHIREAI_TEXT_DOMAIN ) )
			: $this->message( 'error', esc_html__( '保存できませんでした。画面を再読み込みしてから、もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ) );
		$this->send_form_html(
			fn(): string => $this->render_template_string(
				'admin/data-setup-form',
				$this->form_fields( array( $section => array( $message ) ) )
			)
		);
	}
	public function ajax_propose(): void {
		$this->guard_ajax( self::NONCE_ACTION );
		$section = isset( $_POST[ self::PROPOSE_FIELD ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::PROPOSE_FIELD ] ) ) : '';
		$values = $this->posted_values();
		if ( ! in_array( $section, array( self::FIVECH_GROUP, self::REDDIT_GROUP ), true ) ) {
			$this->send_form_html(
				fn(): string => $this->render_template_string(
					'admin/data-setup-form',
					$this->form_fields(
						array(),
						$values,
						array(),
						array(),
						array( $this->message( 'error', esc_html__( 'AI 提案の対象を判別できませんでした。画面を再読み込みしてから、もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ) ) )
					)
				)
			);
			return;
		}
		try {
			$pending = $this->propose( $section );
		} catch ( \Throwable $e ) {
			$this->send_form_html(
				fn(): string => $this->render_template_string(
					'admin/data-setup-form',
					$this->form_fields(
						array(),
						$values,
						array(),
						array( $section => array( $this->message( 'error', esc_html( $e->getMessage() ) ) ) )
					)
				)
			);
			return;
		}
		$this->send_form_html(
			fn(): string => $this->render_template_string(
				'admin/data-setup-form',
				$this->form_fields(
					array(),
					$values,
					array( $section => $pending ),
					array( $section => array( $this->message( 'success', esc_html__( "AI 提案を入力欄へ入れました。\n内容を確認して「保存」を押してください。", GACHIREAI_TEXT_DOMAIN ) ) ) )
				)
			)
		);
	}
	private function posted_values(): array {
		$settings = new Settings();
		$values   = array();
		foreach ( $this->groups() as $group ) {
			$current          = (array) $settings->get( $group, array() );
			$values[ $group ] = $this->sanitize( $this->posted( $group ), $current, $group );
		}
		return $values;
	}
	private function groups(): array {
		return array(
			self::AI_REVIEW_GROUP,
			self::COMMON_GROUP,
			self::PARTNER_GROUP,
			self::REWRITE_GROUP,
			self::FIVECH_GROUP,
			self::REDDIT_GROUP,
			self::YOUTUBE_GROUP,
			self::HACKERNEWS_GROUP,
			self::MASTODON_GROUP,
		);
	}
	private function propose( string $section ): array {
		$scope = ( new Site_Profile() )->get_scope();
		if ( self::FIVECH_GROUP === $section ) {
			$result = ( new Source_Selector() )->select_fivech( ( new Fivech_Client() )->categories(), $scope );
			return array(
				'categories' => implode( ', ', $result['categories'] ),
				'boards'     => implode( ', ', $result['boards'] ),
			);
		}
		$result = ( new Source_Selector() )->select_reddit( Source_Selector::subreddit_catalog(), $scope );
		return array( 'subreddits' => implode( ', ', $result['subreddits'] ) );
	}
	private function save(): bool {
		$settings = new Settings();
		$section = isset( $_POST[ self::SAVE_FIELD ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::SAVE_FIELD ] ) ) : '';
		foreach ( $this->groups() as $group ) {
			$this->apply_common_switch( $group );
			$posted  = $this->posted( $group );
			$current = (array) $settings->get( $group, array() );
			$clean   = $this->sanitize( $posted, $current, $group );
			$settings->set( $group, array_merge( $current, $clean ) );
			if ( '' === $section || $section === $group ) {
				$this->record_connection( $group, $settings );
			}
		}
		$this->record_setting_save( __( 'レビュー設定', GACHIREAI_TEXT_DOMAIN ) );
		return true;
	}
	private function record_connection( string $group, Settings $settings ): void {
		if ( self::YOUTUBE_GROUP === $group ) {
			$values = (array) $settings->get( $group, array() );
			$key    = trim( (string) ( $values['api_key'] ?? '' ) );
			if ( '' === $key ) {
				Connection_Status::mark_unverified( $group, $settings );
				return;
			}
			$this->apply_connection_result( $group, ( new YouTube_Client() )->test( $key ), $settings );
			return;
		}
		if ( self::MASTODON_GROUP === $group ) {
			$values   = (array) $settings->get( $group, array() );
			$instance = trim( (string) ( $values['instance'] ?? '' ) );
			$token    = trim( (string) ( $values['token'] ?? '' ) );
			if ( '' === $instance || '' === $token ) {
				Connection_Status::mark_unverified( $group, $settings );
				return;
			}
			$this->apply_connection_result( $group, ( new Mastodon_Client() )->test( $instance, $token ), $settings );
		}
	}
	private function apply_connection_result( string $group, array $test, Settings $settings ): void {
		if ( ! empty( $test['connected'] ) ) {
			Connection_Status::mark_verified( $group, $settings );
		} else {
			Connection_Status::mark_unverified( $group, $settings );
		}
	}
	private function sanitize( array $raw, array $current, string $group = self::FIVECH_GROUP ): array {
		return ( new Form( $this->definitions( $group ) ) )->sanitize( $raw, $current );
	}
	private function posted( string $group = self::FIVECH_GROUP ): array {
		return isset( $_POST[ $group ] ) && is_array( $_POST[ $group ] )
			? (array) wp_unslash( $_POST[ $group ] )
			: array();
	}
	private function model(): array {
		return array(
			'page_title' => esc_html__( 'レビュー設定', GACHIREAI_TEXT_DOMAIN ),
			'notices'    => $this->notices(),
			'tabs'       => $this->tab_nav(),
			'forms'      => array( $this->form_fields() ),
		);
	}
	private function notices(): array {
		$notices = array();
		foreach ( $this->status_notices() as $notice ) {
			$notices[] = $notice;
		}
		return $notices;
	}
	private function status_notices(): array {
		$notices  = array();
		$settings = new Settings();
		$missing  = array();
		if ( Source_Settings::can_collect( self::FIVECH_GROUP ) && '' === trim( (string) $settings->get( self::FIVECH_GROUP . '.boards', '' ) ) ) {
			$missing[] = __( '5ちゃんねるの板', GACHIREAI_TEXT_DOMAIN );
		}
		if ( Source_Settings::can_collect( self::REDDIT_GROUP ) && '' === trim( (string) $settings->get( self::REDDIT_GROUP . '.subreddits', '' ) ) ) {
			$missing[] = __( 'Reddit のサブレディット', GACHIREAI_TEXT_DOMAIN );
		}
		if ( array() !== $missing ) {
			$notices[] = $this->notice(
				'warning',
				esc_html(
					sprintf(
						__( '%s を入力して「保存」を押してください（AI 提案も使えます）。', GACHIREAI_TEXT_DOMAIN ),
						implode( '・', $missing )
					)
				)
			);
		}
		return $notices;
	}
	private function form_fields( array $messages = array(), ?array $values = null, array $pending = array(), array $header_messages = array(), array $form_messages = array() ): array {
		$settings = new Settings();
		$groups   = $this->groups();
		if ( null === $values ) {
			$values = array();
			foreach ( $groups as $group ) {
				$values[ $group ] = (array) $settings->get( $group, array() );
			}
		}
		foreach ( $groups as $group ) {
			$values[ $group ] = array_merge( (array) ( $values[ $group ] ?? array() ), (array) ( $pending[ $group ] ?? array() ) );
		}
		return array(
			'form_action'    => esc_url( Tabs::url( Tabs::TAB_REVIEW ) ),
			'hidden_fields'  => array(
				array(
					'name'  => esc_attr( self::ACTION_FIELD ),
					'value' => esc_attr( self::ACTION ),
				),
				array(
					'name'  => esc_attr( '_wpnonce' ),
					'value' => esc_attr( wp_create_nonce( self::NONCE_ACTION ) ),
				),
			),
			'sections'       => $this->sections( $values, $messages, $header_messages ),
			'submit_label'   => '',
			'submit_action'  => '',
			'submit_testing' => false,
			'form_messages'  => $form_messages,
		);
	}
	private function sections( array $values, array $messages, array $header_messages ): array {
		$fivech  = (array) ( $values[ self::FIVECH_GROUP ] ?? array() );
		$reddit  = (array) ( $values[ self::REDDIT_GROUP ] ?? array() );
		$youtube    = (array) ( $values[ self::YOUTUBE_GROUP ] ?? array() );
		$hackernews = (array) ( $values[ self::HACKERNEWS_GROUP ] ?? array() );
		$mastodon   = (array) ( $values[ self::MASTODON_GROUP ] ?? array() );
		$ai_review  = (array) ( $values[ self::AI_REVIEW_GROUP ] ?? array() );
		$common     = (array) ( $values[ self::COMMON_GROUP ] ?? array() );
		$partner    = (array) ( $values[ self::PARTNER_GROUP ] ?? array() );
		$rewrite    = (array) ( $values[ self::REWRITE_GROUP ] ?? array() );
		$youtube_overrides  = array();
		$mastodon_overrides = array();
		if ( '' !== trim( (string) ( $youtube['api_key'] ?? '' ) ) ) {
			$youtube_overrides['api_key'] = array( 'placeholder' => Form::mask_secret( (string) $youtube['api_key'] ) );
		}
		$youtube['api_key'] = '';
		if ( '' !== trim( (string) ( $mastodon['token'] ?? '' ) ) ) {
			$mastodon_overrides['token'] = array( 'placeholder' => Form::mask_secret( (string) $mastodon['token'] ) );
		}
		$mastodon['token'] = '';
		if ( is_multisite() ) {
			$settings = new Settings();
			$youtube_overrides['api_key'] = array_merge( (array) ( $youtube_overrides['api_key'] ?? array() ), array( 'common_target' => self::YOUTUBE_GROUP ) );
			foreach ( array( 'instance', 'token' ) as $field ) {
				$mastodon_overrides[ $field ] = array_merge( (array) ( $mastodon_overrides[ $field ] ?? array() ), array( 'common_target' => self::MASTODON_GROUP ) );
			}
			if ( $settings->uses_common( self::YOUTUBE_GROUP ) ) {
				$youtube_overrides['api_key'] = array_merge( $youtube_overrides['api_key'], array( 'field_disabled' => true ) );
			}
			if ( $settings->uses_common( self::MASTODON_GROUP ) ) {
				foreach ( array( 'instance', 'token' ) as $field ) {
					$mastodon_overrides[ $field ] = array_merge( $mastodon_overrides[ $field ], array( 'field_disabled' => true ) );
				}
			}
		}
		return array(
			$this->section(
				self::AI_REVIEW_GROUP,
				__( 'AIによるレビュー生成', GACHIREAI_TEXT_DOMAIN ),
				__( 'AIによるレビュー生成を設定します。', GACHIREAI_TEXT_DOMAIN ),
				array(
					array(
						'block_title'    => '',
						'fields'         => $this->form( self::AI_REVIEW_GROUP )->build_list( $ai_review, array( 'mode', 'min_count', 'required_count' ), self::AI_REVIEW_GROUP ),
						'block_buttons'  => array(),
						'block_messages' => array(),
					),
				),
				$messages[ self::AI_REVIEW_GROUP ] ?? array(),
				$header_messages[ self::AI_REVIEW_GROUP ] ?? array()
			),
			$this->section(
				self::COMMON_GROUP,
				__( '表示と保持', GACHIREAI_TEXT_DOMAIN ),
				__( '収集したレビュー（生データ）を保持する期間と、記事へ出典を表示する条件を決めます。', GACHIREAI_TEXT_DOMAIN ),
				array(
					array(
						'block_title'    => '',
						'fields'         => $this->form( self::COMMON_GROUP )->build_list( $common, array( 'credit', 'retention' ), self::COMMON_GROUP ),
						'block_buttons'  => array(),
						'block_messages' => array(),
					),
				),
				$messages[ self::COMMON_GROUP ] ?? array(),
				$header_messages[ self::COMMON_GROUP ] ?? array()
			),
			$this->section(
				self::PARTNER_GROUP,
				__( 'レビュー取得元', GACHIREAI_TEXT_DOMAIN ),
				__( "どこからレビューを取得するかを決めます。\nチェックしたサイトだけが取得対象になります。", GACHIREAI_TEXT_DOMAIN ),
				array(
					array(
						'block_title'    => '',
						'fields'         => $this->form( self::PARTNER_GROUP )->build_list(
							$partner,
							array( Source_Settings::PARTNER_EC_KEY, Source_Settings::PARTNER_SNS_KEY, Source_Settings::PARTNER_GACHI_KEY ),
							self::PARTNER_GROUP
						),
						'block_buttons'  => array(),
						'block_messages' => array(),
					),
				),
				$messages[ self::PARTNER_GROUP ] ?? array(),
				$header_messages[ self::PARTNER_GROUP ] ?? array()
			),
			$this->section(
				self::REWRITE_GROUP,
				__( 'リライト', GACHIREAI_TEXT_DOMAIN ),
				__( 'EC公式サイトから取得したレビューを、記事の素材としてどのように使うかを決めます。', GACHIREAI_TEXT_DOMAIN ),
				array(
					array(
						'block_title'    => '',
						'fields'         => $this->form( self::REWRITE_GROUP )->build_list( $rewrite, array( Ec_Review_Use::KEY_MODE ), self::REWRITE_GROUP ),
						'block_buttons'  => array(),
						'block_messages' => array(),
					),
				),
				$messages[ self::REWRITE_GROUP ] ?? array(),
				$header_messages[ self::REWRITE_GROUP ] ?? array()
			),
			$this->section(
				self::FIVECH_GROUP,
				__( '5ちゃんねる', GACHIREAI_TEXT_DOMAIN ),
				__( 'レビューを集める 5ちゃんねるのカテゴリー・板を決めます。「AI 提案」は実際の板一覧（実データ）から選びます。収集に使うのは「板」（日本語の板名）です。', GACHIREAI_TEXT_DOMAIN ),
				array(
					array(
						'block_title'    => __( 'カテゴリー', GACHIREAI_TEXT_DOMAIN ),
						'fields'         => $this->form( self::FIVECH_GROUP )->build_list( $fivech, array( 'categories' ), self::FIVECH_GROUP ),
						'block_buttons'  => array(),
						'block_messages' => array(),
					),
					array(
						'block_title'    => __( '板', GACHIREAI_TEXT_DOMAIN ),
						'fields'         => $this->form( self::FIVECH_GROUP )->build_list( $fivech, array( 'boards' ), self::FIVECH_GROUP ),
						'block_buttons'  => array(),
						'block_messages' => array(),
					),
					array(
						'block_title'    => __( '検索', GACHIREAI_TEXT_DOMAIN ),
						'fields'         => $this->form( self::FIVECH_GROUP )->build_list( $fivech, array( 'limit', 'period' ), self::FIVECH_GROUP ),
						'block_buttons'  => array(),
						'block_messages' => array(),
					),
				),
				$messages[ self::FIVECH_GROUP ] ?? array(),
				$header_messages[ self::FIVECH_GROUP ] ?? array()
			),
			$this->section(
				self::REDDIT_GROUP,
				__( 'Reddit', GACHIREAI_TEXT_DOMAIN ),
				__( 'レビューを集める Reddit のサブレディットを決めます。「AI 提案」は同梱の一覧（実在するサブレ）から選びます。', GACHIREAI_TEXT_DOMAIN ),
				array(
					array(
						'block_title'    => __( 'サブレディット', GACHIREAI_TEXT_DOMAIN ),
						'fields'         => $this->form( self::REDDIT_GROUP )->build_list( $reddit, array( 'subreddits' ), self::REDDIT_GROUP ),
						'block_buttons'  => array(),
						'block_messages' => array(),
					),
					array(
						'block_title'    => __( '検索', GACHIREAI_TEXT_DOMAIN ),
						'fields'         => $this->form( self::REDDIT_GROUP )->build_list( $reddit, array( 'limit', 'period' ), self::REDDIT_GROUP ),
						'block_buttons'  => array(),
						'block_messages' => array(),
					),
				),
				$messages[ self::REDDIT_GROUP ] ?? array(),
				$header_messages[ self::REDDIT_GROUP ] ?? array()
			),
			$this->section(
				self::YOUTUBE_GROUP,
				__( 'YouTube', GACHIREAI_TEXT_DOMAIN ),
				__( "商品名で YouTube を検索し、レビュー・開封動画のコメント（実ユーザーの声）を集めます。\nAPI キーは Google Cloud で「YouTube Data API v3」を有効にして発行します。", GACHIREAI_TEXT_DOMAIN ),
				array(
					array(
						'block_title'    => __( '接続', GACHIREAI_TEXT_DOMAIN ),
						'fields'         => $this->form( self::YOUTUBE_GROUP )->build_list( $youtube, array( 'api_key' ), self::YOUTUBE_GROUP, $youtube_overrides ),
						'block_buttons'  => array(),
						'block_messages' => array(),
					),
					array(
						'block_title'    => __( '検索', GACHIREAI_TEXT_DOMAIN ),
						'fields'         => $this->form( self::YOUTUBE_GROUP )->build_list( $youtube, array( 'terms', 'limit', 'period' ), self::YOUTUBE_GROUP ),
						'block_buttons'  => array(),
						'block_messages' => array(),
					),
				),
				$messages[ self::YOUTUBE_GROUP ] ?? array(),
				$header_messages[ self::YOUTUBE_GROUP ] ?? array()
			),
			$this->section(
				self::HACKERNEWS_GROUP,
				__( 'Hacker News', GACHIREAI_TEXT_DOMAIN ),
				__( "商品名で Hacker News を検索し、投稿（story）とコメント（実ユーザーの発言）を集めます。\nAPI キーは不要です。", GACHIREAI_TEXT_DOMAIN ),
				array(
					array(
						'block_title'    => __( '検索', GACHIREAI_TEXT_DOMAIN ),
						'fields'         => $this->form( self::HACKERNEWS_GROUP )->build_list( $hackernews, array( 'terms', 'limit', 'period' ), self::HACKERNEWS_GROUP ),
						'block_buttons'  => array(),
						'block_messages' => array(),
					),
				),
				$messages[ self::HACKERNEWS_GROUP ] ?? array(),
				$header_messages[ self::HACKERNEWS_GROUP ] ?? array()
			),
			$this->section(
				self::MASTODON_GROUP,
				__( 'Mastodon', GACHIREAI_TEXT_DOMAIN ),
				__( "商品名で Mastodon を全文検索し、投稿（実ユーザーの発言）を集めます。\n全文検索には「インスタンス」と「アクセストークン（read:search）」が必要です。", GACHIREAI_TEXT_DOMAIN ),
				array(
					array(
						'block_title'    => __( '接続', GACHIREAI_TEXT_DOMAIN ),
						'fields'         => $this->form( self::MASTODON_GROUP )->build_list( $mastodon, array( 'instance', 'token' ), self::MASTODON_GROUP, $mastodon_overrides ),
						'block_buttons'  => array(),
						'block_messages' => array(),
					),
					array(
						'block_title'    => __( '検索', GACHIREAI_TEXT_DOMAIN ),
						'fields'         => $this->form( self::MASTODON_GROUP )->build_list( $mastodon, array( 'terms', 'limit', 'period', 'fetch_replies' ), self::MASTODON_GROUP ),
						'block_buttons'  => array(),
						'block_messages' => array(),
					),
				),
				$messages[ self::MASTODON_GROUP ] ?? array(),
				$header_messages[ self::MASTODON_GROUP ] ?? array()
			),
		);
	}
	private function section( string $group, string $title, string $description, array $blocks, array $messages, array $propose_messages ): array {
		$is_editable = true;
		$proposable  = in_array( $group, array( self::FIVECH_GROUP, self::REDDIT_GROUP ), true );
		foreach ( $blocks as $index => $block ) {
			$blocks[ $index ] += array(
				'block_head_buttons'  => array(),
				'block_head_messages' => array(),
			);
		}
		if ( $is_editable && $proposable && isset( $blocks[0] ) ) {
			$blocks[0]['block_head_buttons']  = array( $this->propose_button( $group ) );
			$blocks[0]['block_head_messages'] = $propose_messages;
		}
		if ( in_array( $group, array( self::YOUTUBE_GROUP, self::MASTODON_GROUP ), true )
			&& Connection_Status::is_revoked( $group ) ) {
			$messages[] = $this->message( 'warning', esc_html( Connection_Status::revocation_message() ) );
		}
		return array(
			'section_key'      => esc_attr( $group ),
			'section_switch'   => $this->common_switch_model( $group ),
			'title'            => esc_html( $title ),
			'description'      => esc_html( $description ),
			'blocks'           => $blocks,
			'section_buttons'  => $is_editable ? array( $this->save_button( $group ) ) : array(),
			'section_messages' => $messages,
		);
	}
	private function save_button( string $group ): array {
		return array(
			'btn_name'     => esc_attr( self::SAVE_FIELD ),
			'btn_value'    => esc_attr( $group ),
			'btn_label'    => esc_html__( '保存', GACHIREAI_TEXT_DOMAIN ),
			'btn_primary'  => true,
			'btn_disabled' => false,
			'btn_title'    => '',
			'btn_action'   => esc_attr( self::AJAX_ACTION_SAVE ),
			'btn_testing'  => false,
		);
	}
	private function propose_button( string $group ): array {
		$available = ( new Source_Selector() )->is_available();
		$disabled  = ! $available;
		$reason    = Source_Selector::unavailable_message();
		return array(
			'btn_name'     => esc_attr( self::PROPOSE_FIELD ),
			'btn_value'    => esc_attr( $group ),
			'btn_label'    => esc_html__( 'AI 提案', GACHIREAI_TEXT_DOMAIN ),
			'btn_disabled' => $disabled,
			'btn_title'    => $disabled ? esc_attr( $reason ) : esc_attr( $this->propose_hint( $group ) ),
			'btn_action'   => esc_attr( self::AJAX_ACTION_PROPOSE ),
			'button_icon'  => 'robot',
		);
	}
	private function propose_hint( string $group ): string {
		if ( self::FIVECH_GROUP === $group ) {
			return __( '実際の 5ch の板一覧（実データ）から、サイトに合うカテゴリー・板を選びます（提案は入力欄へ入るだけで、保存は「保存」を押したときに行われます）', GACHIREAI_TEXT_DOMAIN );
		}
		return __( '同梱のサブレディット一覧（実在するサブレ）から、サイトに合うものを選びます（提案は入力欄へ入るだけで、保存は「保存」を押したときに行われます）', GACHIREAI_TEXT_DOMAIN );
	}
	private function definitions( string $group = self::FIVECH_GROUP ): array {
		if ( self::COMMON_GROUP === $group ) {
			return $this->common_definitions();
		}
		if ( self::PARTNER_GROUP === $group ) {
			return $this->review_sources_definitions();
		}
		if ( self::REWRITE_GROUP === $group ) {
			return $this->rewrite_definitions();
		}
		if ( self::FIVECH_GROUP === $group ) {
			return $this->fivech_definitions();
		}
		if ( self::REDDIT_GROUP === $group ) {
			return $this->reddit_definitions();
		}
		if ( self::YOUTUBE_GROUP === $group ) {
			return $this->youtube_definitions();
		}
		if ( self::HACKERNEWS_GROUP === $group ) {
			return $this->hackernews_definitions();
		}
		if ( self::MASTODON_GROUP === $group ) {
			return $this->mastodon_definitions();
		}
		if ( self::AI_REVIEW_GROUP === $group ) {
			return $this->ai_review_definitions();
		}
		return array();
	}
	private function form( string $group = self::FIVECH_GROUP ): Form {
		return new Form( $this->definitions( $group ) );
	}
	private function period_definition(): array {
		return array(
			'label'       => __( '取得期間', GACHIREAI_TEXT_DOMAIN ),
			'type'        => 'select',
			'options'     => Source_Settings::period_options(),
			'default'     => (string) Source_Settings::DEFAULT_PERIOD_DAYS,
			'description' => __( 'この期間に投稿されたレビューを集めます。', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	private function common_definitions(): array {
		return array(
			'credit'    => $this->credit_definition(),
			'retention' => $this->retention_definition(),
		);
	}
	private function review_sources_definitions(): array {
		$sns_options   = Source_Settings::partner_sns_options();
		$gachi_options = Source_Settings::direct_source_options();
		return array(
			Source_Settings::PARTNER_EC_KEY    => array(
				'label'       => '公式ECスクレイピング',
				'type'        => 'checkbox',
				'options'     => array( '1' => $this->partner_ec_label() ),
				'normalize'   => array( Source_Settings::class, 'normalize_enabled' ),
				'disabled'    => ! Status::capabilities()->can_collect_source( 'ec' ) ? array( '1' ) : array(),
				'description' => __( "チェックすると、ASP設定で選んだメインEC の商品レビューを集めます。\nFREEライセンスではご利用いただけません。", GACHIREAI_TEXT_DOMAIN ),
			),
			Source_Settings::PARTNER_SNS_KEY   => array(
				'label'       => 'SNSスクレイピング',
				'type'        => 'checkbox_multi',
				'options'     => $sns_options,
				'disabled'    => ! Status::capabilities()->can_collect_source( 'x' )
					? array_keys( $sns_options )
					: array(),
				'description' => __( "チェックした SNS を商品名で検索し、投稿をレビューとして集めます。\nFREEライセンスではご利用いただけません。", GACHIREAI_TEXT_DOMAIN ),
			),
			Source_Settings::PARTNER_GACHI_KEY => array(
				'label'       => 'その他',
				'type'        => 'checkbox_multi',
				'options'     => $gachi_options,
				'disabled'    => ! Status::capabilities()->can_collect_source( 'reddit' )
					? array_keys( $gachi_options )
					: array(),
				'description' => __( "当プラグインで直接レビューを取得するサービス（サイト）です。\nチェックしたサイトを商品名で検索し、投稿をレビューとして集めます。\nFREEライセンスではご利用いただけません。", GACHIREAI_TEXT_DOMAIN ),
			),
		);
	}
	private function rewrite_definitions(): array {
		return array(
			Ec_Review_Use::KEY_MODE => array(
				'label'       => __( 'EC公式サイトのレビュー', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'radio',
				'options'     => Ec_Review_Use::mode_options(),
				'default'     => Ec_Review_Use::DEFAULT_MODE,
				'description' => __( "楽天市場・Amazon・Yahoo!ショッピングのレビューは、各社のアフィリエイト規約で公式サイトのレビューの転載が禁止されています。\n取得したレビューを記事の素材としてどう使うかを選びます。", GACHIREAI_TEXT_DOMAIN ),
			),
		);
	}
	private function partner_ec_label(): string {
		$labels = array();
		foreach ( array( Product_Selector::EC_RAKUTEN, Product_Selector::EC_AMAZON, Product_Selector::EC_YAHOO ) as $slug ) {
			$labels[] = Product_Selector::ec_label( $slug );
		}
		return sprintf(
			__( 'メインECで選択しているサイト（%s）', GACHIREAI_TEXT_DOMAIN ),
			implode( ', ', $labels )
		);
	}
	private function retention_definition(): array {
		return array(
			'label'       => __( 'データ保持', GACHIREAI_TEXT_DOMAIN ),
			'type'        => 'radio',
			'options'     => Source_Settings::retention_options(),
			'default'     => (string) Source_Settings::DEFAULT_RETENTION_DAYS,
			'description' => __( "収集した生データ（元の投稿とコメント）をデータベースに保存しておく期間です。\n期間を過ぎても生成した記事内容は削除されず残ります。", GACHIREAI_TEXT_DOMAIN ),
		);
	}
	private function credit_definition(): array {
		return array(
			'label'       => __( '出典表示', GACHIREAI_TEXT_DOMAIN ),
			'type'        => 'radio',
			'options'     => Source_Settings::credit_options(),
			'default'     => Source_Settings::DEFAULT_CREDIT,
			'description' => __( "記事へ出典元を表示する条件です。\n「表示する」= 引用ブロックと段落・箇条書きの末尾に表示します。\n「引用ブロックのみ表示」= 引用ブロックだけに出典を表示します。\n「表示しない」= 出典を表示しません。\n※ただし、AIが生成したレビューを含む場合は、設定に関わらず出典を表示しません。", GACHIREAI_TEXT_DOMAIN ),
		);
	}
	private function ai_review_definitions(): array {
		return array(
			Ai_Review::KEY_MODE           => array(
				'label'       => __( 'AI生成', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'select',
				'options'     => Ai_Review::mode_options(),
				'default'     => Ai_Review::DEFAULT_MODE,
				'driver'      => self::AI_REVIEW_DRIVER,
				'description' => __( 'AIによるレビュー生成の条件を選びます。', GACHIREAI_TEXT_DOMAIN ),
			),
			Ai_Review::KEY_MIN_COUNT      => array(
				'label'       => __( '最低数', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'number',
				'min'         => Ai_Review::MIN_COUNT,
				'max'         => Ai_Review::MAX_COUNT,
				'default'     => (string) Ai_Review::DEFAULT_MIN_COUNT,
				'row_depends' => array(
					'on'     => self::AI_REVIEW_DRIVER,
					'values' => array( Ai_Review::MODE_OFF ),
				),
				'description' => __( "収集したレビューがこの数に満たないときは記事を作成しません。\n記事生成のためにAIが採用する元となるレビューの最低数です。\n※AIは収集したすべてのレビューを使用する訳ではありません。\n記事への掲載数ではありません。", GACHIREAI_TEXT_DOMAIN ),
			),
			Ai_Review::KEY_REQUIRED_COUNT => array(
				'label'       => __( '必要数', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'number',
				'min'         => Ai_Review::MIN_COUNT,
				'max'         => Ai_Review::MAX_COUNT,
				'default'     => (string) Ai_Review::DEFAULT_COUNT,
				'row_depends' => array(
					'on'     => self::AI_REVIEW_DRIVER,
					'values' => array( Ai_Review::MODE_FILL, Ai_Review::MODE_ONLY ),
				),
				'description' => __( "収集したレビューがこの数に満たない分を AI が生成して補います。\n「AI生成のみ」では全量を AI が生成します。", GACHIREAI_TEXT_DOMAIN ),
			),
		);
	}
	private function fivech_definitions(): array {
		return array(
			'categories' => array(
				'label'       => __( 'カテゴリー', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'textarea',
				'rows'        => 3,
				'max'         => 1000,
				'headless'    => true,
				'placeholder' => __( '例: 家電製品, 生活', GACHIREAI_TEXT_DOMAIN ),
				'description' => __( "5ch のカテゴリー名をカンマ区切りで指定します。\n収集に使うのは「板」です。\n「AI 提案」が実データから選びます。", GACHIREAI_TEXT_DOMAIN ),
			),
			'boards'     => array(
				'label'       => __( '板', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'textarea',
				'rows'        => 5,
				'max'         => 1000,
				'headless'    => true,
				'placeholder' => __( '例: 家電製品, パソコン, 家具', GACHIREAI_TEXT_DOMAIN ),
				'description' => __( "レビューを集める 5ch の板名（日本語）をカンマ区切りで指定します。\n収集時に実データ（板一覧）で照合し、見つからない板は使いません。", GACHIREAI_TEXT_DOMAIN ),
			),
			'limit'      => array(
				'label'       => __( '取得の上限', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'select',
				'options'     => array(
					'25'  => __( '25 レス', GACHIREAI_TEXT_DOMAIN ),
					'50'  => __( '50 レス', GACHIREAI_TEXT_DOMAIN ),
					'100' => __( '100 レス（上限）', GACHIREAI_TEXT_DOMAIN ),
				),
				'default'     => (string) Fivech_Collector::DEFAULT_RES_LIMIT,
				'description' => __( "1 スレッドあたりに保存するレス数の上限です。\n板ごとのスレッド数は現状 6 件までです。", GACHIREAI_TEXT_DOMAIN ),
			),
			'period'   => $this->period_definition(),
		);
	}
	private function reddit_definitions(): array {
		return array(
			'subreddits' => array(
				'label'       => __( 'サブレディット', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'textarea',
				'rows'        => 5,
				'max'         => 1000,
				'headless'    => true,
				'placeholder' => __( '例: headphones, buildapc, Cooking', GACHIREAI_TEXT_DOMAIN ),
				'description' => __( "レビューを集める Reddit のサブレディット名をカンマ区切りで指定します。\n「AI 提案」が同梱の一覧（実在するサブレ）から選びます。", GACHIREAI_TEXT_DOMAIN ),
			),
			'limit'      => array(
				'label'       => __( '取得の上限', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'select',
				'options'     => array(
					'25'  => __( '25 件', GACHIREAI_TEXT_DOMAIN ),
					'50'  => __( '50 件', GACHIREAI_TEXT_DOMAIN ),
					'100' => __( '100 件（上限）', GACHIREAI_TEXT_DOMAIN ),
				),
				'default'     => (string) Reddit_Collector::DEFAULT_LIMIT,
				'description' => __( '1 回の収集で採用する投稿数の上限です（コメントは現状、上位 10 投稿 × 各 50 件まで）。', GACHIREAI_TEXT_DOMAIN ),
			),
			'period'   => $this->period_definition(),
		);
	}
	private function youtube_definitions(): array {
		return array(
			'api_key'   => array(
				'label'       => __( 'API キー', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'password',
				'max'         => 200,
				'placeholder' => __( 'YouTube Data API v3 の API キー', GACHIREAI_TEXT_DOMAIN ),
				'description' => __( 'Google Cloud で「YouTube Data API v3」を有効にして発行した API キーを貼り付けます。', GACHIREAI_TEXT_DOMAIN ),
			),
			'terms'     => array(
				'label'       => __( '検索時付加語', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
				'placeholder' => __( '例: レビュー, 開封', GACHIREAI_TEXT_DOMAIN ),
				'description' => __( "検索時に商品名へ付加する語（カンマ区切り）。\n空なら「商品名のみ」で検索します。\n既定は「レビュー」「開封」", GACHIREAI_TEXT_DOMAIN ),
			),
			'limit'     => array(
				'label'       => __( '取得の上限', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'select',
				'options'     => array(
					'3'  => __( '3 本', GACHIREAI_TEXT_DOMAIN ),
					'5'  => __( '5 本', GACHIREAI_TEXT_DOMAIN ),
					'10' => __( '10 本（上限）', GACHIREAI_TEXT_DOMAIN ),
				),
				'default'     => (string) YouTube_Collector::DEFAULT_VIDEO_LIMIT,
				'description' => __( '検索語ごとに採用する動画数の上限です（各動画のコメントは現状 50 件まで）。', GACHIREAI_TEXT_DOMAIN ),
			),
			'period'    => $this->period_definition(),
		);
	}
	private function hackernews_definitions(): array {
		return array(
			'terms'     => array(
				'label'       => __( '検索時付加語', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
				'placeholder' => __( '例: レビュー', GACHIREAI_TEXT_DOMAIN ),
				'description' => __( "検索時に商品名へ付加する語（カンマ区切り）。\n空なら「商品名のみ」で検索します。", GACHIREAI_TEXT_DOMAIN ),
			),
			'limit'     => array(
				'label'       => __( '取得の上限', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'select',
				'options'     => array(
					'3'  => __( '3 件', GACHIREAI_TEXT_DOMAIN ),
					'5'  => __( '5 件', GACHIREAI_TEXT_DOMAIN ),
					'10' => __( '10 件（上限）', GACHIREAI_TEXT_DOMAIN ),
				),
				'default'     => (string) Hacker_News_Collector::DEFAULT_STORY_LIMIT,
				'description' => __( '採用する投稿（story）数の上限です（コメントは現状 50 件まで）。', GACHIREAI_TEXT_DOMAIN ),
			),
			'period'   => $this->period_definition(),
		);
	}
	private function mastodon_definitions(): array {
		return array(
			'instance'      => array(
				'label'       => __( 'インスタンス', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 191,
				'default'     => Mastodon_Collector::DEFAULT_INSTANCE,
				'placeholder' => __( '例: mastodon.social', GACHIREAI_TEXT_DOMAIN ),
				'description' => __( "収集する Mastodon のインスタンス（ドメイン）です。\n全文検索が有効なインスタンスを指定してください。", GACHIREAI_TEXT_DOMAIN ),
			),
			'token'         => array(
				'label'       => __( 'アクセストークン', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'password',
				'max'         => 200,
				'placeholder' => __( 'read:search スコープのアクセストークン', GACHIREAI_TEXT_DOMAIN ),
				'description' => __( 'Mastodon の「開発」で作成したアプリのアクセストークン（read:search）を貼り付けます。', GACHIREAI_TEXT_DOMAIN ),
			),
			'terms'         => array(
				'label'       => __( '検索時付加語', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
				'placeholder' => __( '例: レビュー, 使ってみた', GACHIREAI_TEXT_DOMAIN ),
				'description' => __( "検索時に商品名へ付加する語（カンマ区切り）。\n空なら「商品名のみ」で検索します。", GACHIREAI_TEXT_DOMAIN ),
			),
			'limit'         => array(
				'label'       => __( '取得の上限', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'select',
				'options'     => array(
					'10' => __( '10 件', GACHIREAI_TEXT_DOMAIN ),
					'20' => __( '20 件', GACHIREAI_TEXT_DOMAIN ),
					'40' => __( '40 件（上限）', GACHIREAI_TEXT_DOMAIN ),
				),
				'default'     => (string) Mastodon_Collector::DEFAULT_LIMIT,
				'description' => __( '採用する投稿（status）数の上限です。', GACHIREAI_TEXT_DOMAIN ),
			),
			'period'        => $this->period_definition(),
			'fetch_replies' => array(
				'label'       => __( '返信の取得', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'radio',
				'options'     => array(
					'off' => __( '取得しない', GACHIREAI_TEXT_DOMAIN ),
					'on'  => __( '取得する', GACHIREAI_TEXT_DOMAIN ),
				),
				'default'     => 'off',
				'description' => __( '投稿に付いた返信もコメントとして取得します（通信が増えます）。', GACHIREAI_TEXT_DOMAIN ),
			),
		);
	}
}
