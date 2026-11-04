<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Builder;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Create_Progress;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Generator;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Site_Create extends Screen {
	public const ACTION = 'gachireai_site_create';
	public const AJAX_ACTION = 'gachireai_site_create';
	private const NONCE = 'gachireai_site_create';
	private const PROPOSE_FIELD = 'gachireai_site_propose';
	public const THEME_FIELD = 'gachireai_theme';
	public const EC_FIELD = 'gachireai_ec';
	public const TYPE_FIELD = 'gachireai_site_type';
	public const ARTICLE_FIELD = 'gachireai_article_type';
	private const RESULT_TRANSIENT = 'gachireai_site_create_result_';
	private const THEMES_TRANSIENT = 'gachireai_site_create_themes_';
	private const TTL = 1800;
	public function form_model( ?array $result = null ): array {
		if ( null === $result ) {
			$result = $this->take( self::RESULT_TRANSIENT );
		}
		$themes = get_transient( self::THEMES_TRANSIENT . get_current_user_id() );
		$parsed = $this->result_model( $result );
		$theme_value = isset( $parsed['theme'] ) ? (string) $parsed['theme'] : '';
		return array(
			'site_create_form_action'       => esc_url( admin_url( 'admin-post.php' ) ),
			'site_create_hidden_fields'     => array(
				array( 'name' => 'action', 'value' => esc_attr( self::ACTION ) ),
				array( 'name' => '_wpnonce', 'value' => esc_attr( wp_create_nonce( self::NONCE ) ) ),
			),
			'site_create_title'             => esc_html__( 'AIサイト生成', GACHIREAI_TEXT_DOMAIN ),
			'site_create_intro'             => esc_html__( "サイトのコンセプトを決めて「AIサイト生成」を押すと、子サイトの作成から設定・固定ページ・サイト画像・最初の記事の公開までを自動で行います。\nコンセプトは AI に 5 案を提案させて選ぶことも、自由に入力することもできます。", GACHIREAI_TEXT_DOMAIN ),
			'site_create_theme_label'       => esc_html__( 'サイトのコンセプト', GACHIREAI_TEXT_DOMAIN ),
			'site_create_theme_name'        => esc_attr( self::THEME_FIELD ),
			'site_create_theme_value'       => esc_attr( $theme_value ),
			'site_create_theme_desc'        => esc_html__( "何を扱うサイトにするかを書きます。\n空のままなら完全おまかせになります。\n例: 空気清浄機の比較レビューサイト", GACHIREAI_TEXT_DOMAIN ),
			'site_create_propose_name'      => esc_attr( self::PROPOSE_FIELD ),
			'site_create_propose_label'     => esc_html__( 'AI提案', GACHIREAI_TEXT_DOMAIN ),
			'site_create_theme_candidates_label' => esc_html__( '候補（選ぶとコンセプト欄に入ります）', GACHIREAI_TEXT_DOMAIN ),
			'site_create_theme_candidates'  => $this->theme_candidates( $themes ),
			'site_create_ec_label'          => esc_html__( 'メインEC', GACHIREAI_TEXT_DOMAIN ),
			'site_create_ec_name'           => esc_attr( self::EC_FIELD ),
			'site_create_ec_options'        => $this->select_options(
				Site_Builder::EC_CHOICES,
				Site_Builder::ec_labels(),
				(string) ( $this->last_input( $result, 'ec' ) )
			),
			'site_create_type_label'        => esc_html__( 'サイトのタイプ', GACHIREAI_TEXT_DOMAIN ),
			'site_create_type_name'         => esc_attr( self::TYPE_FIELD ),
			'site_create_type_options'      => $this->select_options(
				Site_Builder::TYPE_CHOICES,
				Site_Builder::type_labels(),
				(string) ( $this->last_input( $result, 'type' ) )
			),
			'site_create_article_label'     => esc_html__( '記事タイプ', GACHIREAI_TEXT_DOMAIN ),
			'site_create_article_name'      => esc_attr( self::ARTICLE_FIELD ),
			'site_create_article_options'   => $this->select_options(
				Site_Builder::ARTICLE_CHOICES,
				Site_Builder::article_labels(),
				(string) ( $this->last_input( $result, 'article' ) )
			),
			'site_create_submit_label'      => esc_html__( 'AIサイト生成', GACHIREAI_TEXT_DOMAIN ),
			'site_create_generate_action'   => esc_attr( self::AJAX_ACTION ),
			'site_create_overlay_title'         => esc_html__( 'AIがサイトを生成しています', GACHIREAI_TEXT_DOMAIN ),
			'site_create_overlay_initial_label' => esc_html__( 'AI生成を開始...', GACHIREAI_TEXT_DOMAIN ),
			'site_create_overlay_abort_label'   => esc_html__( '中断', GACHIREAI_TEXT_DOMAIN ),
			'site_create_overlay_retry_label'   => esc_html__( 'リトライ中', GACHIREAI_TEXT_DOMAIN ),
			'site_create_overlay_total'         => 8,
			'site_create_overlay_initial_phase' => 1,
			'site_create_overlay_dots'          => array_map(
				static fn( int $i ): array => array( 'dot_index' => $i ),
				range( 1, 8 )
			),
			'site_create_messages'          => $parsed['messages'],
			'site_create_result_lines'      => $parsed['lines'],
		);
	}
	private function theme_candidates( $themes ): array {
		if ( ! is_array( $themes ) ) {
			return array();
		}
		$candidates = array();
		foreach ( $themes as $theme ) {
			if ( is_scalar( $theme ) && '' !== trim( (string) $theme ) ) {
				$candidates[] = array( 'cand_value' => esc_attr( trim( (string) $theme ) ) );
			}
		}
		return $candidates;
	}
	private function select_options( array $choices, array $labels, string $selected ): array {
		if ( ! in_array( $selected, $choices, true ) ) {
			$selected = Site_Builder::CHOICE_AUTO;
		}
		$options = array();
		foreach ( $choices as $choice ) {
			$label = Site_Builder::CHOICE_AUTO === $choice
				? __( 'おまかせ', GACHIREAI_TEXT_DOMAIN )
				: (string) ( $labels[ $choice ] ?? $choice );
			$options[] = array(
				'opt_value'    => esc_attr( $choice ),
				'opt_label'    => esc_html( $label ),
				'opt_selected' => ( $choice === $selected ),
			);
		}
		return $options;
	}
	private function last_input( $result, string $key ): string {
		if ( ! is_array( $result ) || ! isset( $result['inputs'] ) || ! is_array( $result['inputs'] ) ) {
			return Site_Builder::CHOICE_AUTO;
		}
		return (string) ( $result['inputs'][ $key ] ?? Site_Builder::CHOICE_AUTO );
	}
	private function result_model( $result ): array {
		if ( ! is_array( $result ) ) {
			return array(
				'messages' => array(),
				'lines'    => array(),
				'theme'    => '',
			);
		}
		$type = (string) ( $result['type'] ?? '' );
		if ( 'error' === $type ) {
			return array(
				'messages' => array( $this->message( 'error', esc_html( (string) ( $result['message'] ?? '' ) ) ) ),
				'lines'    => array(),
				'theme'    => (string) ( $result['theme'] ?? '' ),
			);
		}
		if ( 'success' !== $type || ! is_array( $result['result'] ?? null ) ) {
			return array(
				'messages' => array(),
				'lines'    => array(),
				'theme'    => '',
			);
		}
		return array(
			'messages' => array( $this->message( 'success', esc_html__( '子サイトを生成しました。', GACHIREAI_TEXT_DOMAIN ) ) ),
			'lines'    => $this->result_lines( (array) $result['result'] ),
			'theme'    => '',
		);
	}
	private function result_lines( array $result ): array {
		$ec             = (string) ( $result['ec'] ?? '' );
		$type           = (string) ( $result['site_type'] ?? '' );
		$article        = (string) ( $result['article_type'] ?? '' );
		$ec_labels      = Site_Builder::ec_labels();
		$type_labels    = Site_Builder::type_labels();
		$article_labels = Site_Builder::article_labels();
		$first_post     = is_array( $result['first_post'] ?? null ) ? (array) $result['first_post'] : array();
		$theme          = (string) ( $result['theme'] ?? '' );
		$lines = array(
			$this->line( __( 'サイト名', GACHIREAI_TEXT_DOMAIN ), (string) ( $result['site_name'] ?? '' ) ),
			$this->line( __( 'サイトURL', GACHIREAI_TEXT_DOMAIN ), (string) ( $result['site_url'] ?? '' ), true ),
			$this->line( __( '管理画面', GACHIREAI_TEXT_DOMAIN ), (string) ( $result['admin_url'] ?? '' ), true ),
			$this->line( __( 'サイトのコンセプト', GACHIREAI_TEXT_DOMAIN ), '' !== $theme ? $theme : __( '（完全おまかせ）', GACHIREAI_TEXT_DOMAIN ) ),
			$this->line( __( 'メインEC', GACHIREAI_TEXT_DOMAIN ), (string) ( $ec_labels[ $ec ] ?? $ec ) ),
			$this->line( __( 'サイトのタイプ', GACHIREAI_TEXT_DOMAIN ), (string) ( $type_labels[ $type ] ?? $type ) ),
			$this->line( __( '記事タイプ', GACHIREAI_TEXT_DOMAIN ), (string) ( $article_labels[ $article ] ?? $article ) ),
			$this->line( __( 'マイペルソナ', GACHIREAI_TEXT_DOMAIN ), (string) ( $result['persona_name'] ?? '' ) ),
		);
		$lines[] = $this->line(
			__( '最初の記事', GACHIREAI_TEXT_DOMAIN ),
			(string) ( $first_post['title'] ?? '' ),
			false,
			(string) ( $first_post['permalink'] ?? '' )
		);
		return $lines;
	}
	private function line( string $label, string $value, bool $as_url = false, string $url = '' ): array {
		$href = '' !== $url ? $url : ( $as_url ? $value : '' );
		return array(
			'result_label' => esc_html( $label ),
			'result_value' => esc_html( $value ),
			'result_url'   => '' !== $href ? esc_url( $href ) : '',
		);
	}
	private function take( string $prefix ) {
		$key   = $prefix . get_current_user_id();
		$value = get_transient( $key );
		if ( false !== $value ) {
			delete_transient( $key );
		}
		return $value;
	}
	public function ajax_generate(): void {
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN ) ),
				403
			);
		}
		if ( ! check_ajax_referer( self::NONCE, '_wpnonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'セキュリティチェックに失敗しました。画面を再読み込みしてから、もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ) ),
				403
			);
		}
		$user_id = get_current_user_id();
		$theme   = isset( $_POST[ self::THEME_FIELD ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::THEME_FIELD ] ) ) : '';
		$ec      = isset( $_POST[ self::EC_FIELD ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::EC_FIELD ] ) ) : Site_Builder::CHOICE_AUTO;
		$type    = isset( $_POST[ self::TYPE_FIELD ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::TYPE_FIELD ] ) ) : Site_Builder::CHOICE_AUTO;
		$article = isset( $_POST[ self::ARTICLE_FIELD ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::ARTICLE_FIELD ] ) ) : Site_Builder::CHOICE_AUTO;
		$progress = new Site_Create_Progress( $user_id );
		try {
			$result = ( new Site_Builder() )->build( $theme, $ec, $type, $article, $progress->callback() );
			$progress->finish();
			delete_transient( self::THEMES_TRANSIENT . $user_id );
			$this->send_form_html(
				fn(): string => $this->render_site_create_form(
					array(
						'type'   => 'success',
						'result' => $result,
					)
				)
			);
		} catch ( \Throwable $e ) {
			$progress->finish();
			wp_send_json_error(
				array(
					'message' => mb_substr( trim( $e->getMessage() ), 0, 500 ),
				),
				500
			);
		}
	}
	private function render_site_create_form( ?array $result = null ): string {
		return $this->render_template_string( 'admin/site-create-form', $this->form_model( $result ) );
	}
	public function handle_submit(): void {
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die(
				esc_html__( 'この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( self::NONCE );
		if ( ! empty( $_POST[ self::PROPOSE_FIELD ] ) ) {
			$this->handle_propose();
			return;
		}
		$this->handle_generate();
	}
	private function handle_propose(): void {
		$user_id = get_current_user_id();
		try {
			$themes = ( new Site_Generator() )->propose_themes();
			set_transient( self::THEMES_TRANSIENT . $user_id, $themes, self::TTL );
		} catch ( \Throwable $e ) {
			set_transient(
				self::RESULT_TRANSIENT . $user_id,
				array(
					'type'    => 'error',
					'message' => $e->getMessage(),
				),
				self::TTL
			);
		}
		$this->redirect();
	}
	private function handle_generate(): void {
		$user_id = get_current_user_id();
		$theme   = isset( $_POST[ self::THEME_FIELD ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::THEME_FIELD ] ) ) : '';
		$ec      = isset( $_POST[ self::EC_FIELD ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::EC_FIELD ] ) ) : Site_Builder::CHOICE_AUTO;
		$type    = isset( $_POST[ self::TYPE_FIELD ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::TYPE_FIELD ] ) ) : Site_Builder::CHOICE_AUTO;
		$article = isset( $_POST[ self::ARTICLE_FIELD ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::ARTICLE_FIELD ] ) ) : Site_Builder::CHOICE_AUTO;
		$inputs = array(
			'ec'      => $ec,
			'type'    => $type,
			'article' => $article,
		);
		try {
			$result = ( new Site_Builder() )->build( $theme, $ec, $type, $article );
			set_transient(
				self::RESULT_TRANSIENT . $user_id,
				array(
					'type'   => 'success',
					'result' => $result,
				),
				self::TTL
			);
			delete_transient( self::THEMES_TRANSIENT . $user_id );
		} catch ( \Throwable $e ) {
			set_transient(
				self::RESULT_TRANSIENT . $user_id,
				array(
					'type'    => 'error',
					'message' => $e->getMessage(),
					'theme'   => $theme,
					'inputs'  => $inputs,
				),
				self::TTL
			);
		}
		$this->redirect();
	}
	private function redirect(): void {
		wp_safe_redirect( Common_Tabs::url( Common_Tabs::TAB_DASHBOARD ) );
		exit;
	}
}
