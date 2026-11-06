<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\License\Store;
final class License_Settings {
	private const ACTION_FIELD = 'gachireai_action';
	private const ACTION_AUTH = 'authenticate_license';
	private const NONCE_ACTION = 'gachireai_authenticate_license';
	private const KEY_FIELD = 'gachireai_license_key';
	public const RESULT_ARG = 'gachireai_license_auth';
	public const COMMON_FIELD = 'gachireai_license_common';
	private static function matrix_rows(): array {
		$rows = array(
			array(
				__( 'メインEC', GACHIREAI_TEXT_DOMAIN ),
				array( __( '楽天のみ', GACHIREAI_TEXT_DOMAIN ), 'partial' ),
				array( __( '全 EC', GACHIREAI_TEXT_DOMAIN ), 'full' ),
				array( __( '全 EC', GACHIREAI_TEXT_DOMAIN ), 'full' ),
			),
			array(
				__( 'レビュー取得', GACHIREAI_TEXT_DOMAIN ),
				array( __( 'AI生成のみ', GACHIREAI_TEXT_DOMAIN ), 'partial' ),
				array( __( '全ソース', GACHIREAI_TEXT_DOMAIN ), 'full' ),
				array( __( '全ソース', GACHIREAI_TEXT_DOMAIN ), 'full' ),
			),
			array(
				__( '記事タイプ', GACHIREAI_TEXT_DOMAIN ),
				array( __( '引用型のみ', GACHIREAI_TEXT_DOMAIN ), 'partial' ),
				array( __( '総評型・引用型・体験談型', GACHIREAI_TEXT_DOMAIN ), 'partial' ),
				array( __( '全タイプ（対話形式を含む）', GACHIREAI_TEXT_DOMAIN ), 'full' ),
			),
			array(
				__( '商品ボックス', GACHIREAI_TEXT_DOMAIN ),
				array( __( '1スタイルのみ', GACHIREAI_TEXT_DOMAIN ), 'partial' ),
				array( __( '全 10 スタイル', GACHIREAI_TEXT_DOMAIN ), 'full' ),
				array( __( '全 10 スタイル', GACHIREAI_TEXT_DOMAIN ), 'full' ),
			),
			array(
				__( '自動投稿', GACHIREAI_TEXT_DOMAIN ),
				array( __( '不可', GACHIREAI_TEXT_DOMAIN ), 'none' ),
				array( __( '不可', GACHIREAI_TEXT_DOMAIN ), 'none' ),
				array( __( '可能（日時指定で自動公開）', GACHIREAI_TEXT_DOMAIN ), 'full' ),
			),
			array(
				__( '判定AI', GACHIREAI_TEXT_DOMAIN ),
				array( __( '利用不可', GACHIREAI_TEXT_DOMAIN ), 'none' ),
				array( __( '利用可', GACHIREAI_TEXT_DOMAIN ), 'full' ),
				array( __( '利用可', GACHIREAI_TEXT_DOMAIN ), 'full' ),
			),
			array(
				__( '画像生成AI', GACHIREAI_TEXT_DOMAIN ),
				array( __( '利用不可', GACHIREAI_TEXT_DOMAIN ), 'none' ),
				array( __( '利用可', GACHIREAI_TEXT_DOMAIN ), 'full' ),
				array( __( '利用可', GACHIREAI_TEXT_DOMAIN ), 'full' ),
			),
			array(
				__( 'マルチサイト', GACHIREAI_TEXT_DOMAIN ),
				array( __( '非対応', GACHIREAI_TEXT_DOMAIN ), 'none' ),
				array( __( '非対応', GACHIREAI_TEXT_DOMAIN ), 'none' ),
				array( __( '対応', GACHIREAI_TEXT_DOMAIN ), 'full' ),
			),
		);
		$models = array();
		foreach ( $rows as $row ) {
			$models[] = array(
				'feature'     => esc_html( $row[0] ),
				'free'        => esc_html( $row[1][0] ),
				'free_level'  => $row[1][1],
				'basic'       => esc_html( $row[2][0] ),
				'basic_level' => $row[2][1],
				'pro'         => esc_html( $row[3][0] ),
				'pro_level'   => $row[3][1],
			);
		}
		return $models;
	}
	public static function form_model(): array {
		$key  = Store::key();
		$note = self::status_note();
		return array(
			'license_title'         => esc_html__( 'ライセンス認証', GACHIREAI_TEXT_DOMAIN ),
			'license_intro'         => esc_html__( "PRO, BASICライセンスをご購入頂いた方は、ダウンロードサイトで発行されたライセンスIDで認証してください。\n未認証の場合は FREEライセンスとしてご利用いただけます。", GACHIREAI_TEXT_DOMAIN ),
			'license_form_action'   => esc_url( Tabs::url( Tabs::TAB_DASHBOARD ) ),
			'license_hidden_fields' => array(
				array(
					'name'  => esc_attr( self::ACTION_FIELD ),
					'value' => esc_attr( self::ACTION_AUTH ),
				),
				array(
					'name'  => esc_attr( '_wpnonce' ),
					'value' => esc_attr( wp_create_nonce( self::NONCE_ACTION ) ),
				),
			),
			'license_id_label'      => esc_html__( 'ライセンスID', GACHIREAI_TEXT_DOMAIN ),
			'license_key_name'      => esc_attr( self::KEY_FIELD ),
			'license_key_value'     => esc_attr( $key ),
			'license_submit_label'  => esc_html__( '認証', GACHIREAI_TEXT_DOMAIN ),
			'license_status'            => esc_html( self::status_line() ),
			'license_status_note'       => null === $note ? '' : esc_html( $note['text'] ),
			'license_status_note_level' => null === $note ? '' : esc_attr( $note['level'] ),
			'license_messages'      => self::result_messages(),
		);
	}
	public static function matrix_model(): array {
		return array(
			'license_matrix_title'  => esc_html__( 'ライセンス別の機能制限', GACHIREAI_TEXT_DOMAIN ),
			'license_matrix_intro'  => esc_html__( 'ライセンスの種別ごとにご利用いただける機能に制限が設けてあります。', GACHIREAI_TEXT_DOMAIN ),
			'license_matrix'        => self::matrix_rows(),
			'license_matrix_legend' => array(
				array(
					'level' => 'full',
					'label' => esc_html__( '利用可', GACHIREAI_TEXT_DOMAIN ),
				),
				array(
					'level' => 'partial',
					'label' => esc_html__( '一部制限あり', GACHIREAI_TEXT_DOMAIN ),
				),
				array(
					'level' => 'none',
					'label' => esc_html__( '利用不可', GACHIREAI_TEXT_DOMAIN ),
				),
			),
			'license_matrix_note'   => esc_html__( 'その他の機能は、すべてのライセンスで制限無くご利用いただけます。', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	public static function maybe_save(): void {
		if ( wp_doing_ajax() || ! isset( $_POST[ self::ACTION_FIELD ] ) ) {
			return;
		}
		if ( self::ACTION_AUTH !== sanitize_key( wp_unslash( (string) $_POST[ self::ACTION_FIELD ] ) ) ) {
			return;
		}
		$common = isset( $_POST[ self::COMMON_FIELD ] ) && '1' === sanitize_key( wp_unslash( (string) $_POST[ self::COMMON_FIELD ] ) );
		if ( ! current_user_can( $common ? 'manage_network' : 'manage_options' ) ) {
			wp_die(
				esc_html__( 'この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( self::NONCE_ACTION );
		$posted = isset( $_POST[ self::KEY_FIELD ] ) && is_scalar( $_POST[ self::KEY_FIELD ] )
			? trim( (string) wp_unslash( $_POST[ self::KEY_FIELD ] ) )
			: '';
		if ( '' !== $posted ) {
			Store::save_key( $posted );
			Store::clear_cache();
			Status::reset();
		}
		$success = Status::refresh() && Status::is_basic_or_higher();
		$args = array( self::RESULT_ARG => $success ? 'ok' : 'error' );
		wp_safe_redirect(
			$common
				? Common_Tabs::url( Common_Tabs::TAB_DASHBOARD, $args )
				: Tabs::url( Tabs::TAB_DASHBOARD, $args )
		);
		exit;
	}
	private static function result_messages(): array {
		$result = isset( $_GET[ self::RESULT_ARG ] )
			? sanitize_key( wp_unslash( (string) $_GET[ self::RESULT_ARG ] ) )
			: '';
		if ( 'ok' === $result ) {
			return array(
				array(
					'msg_type' => 'success',
					'msg_text' => esc_html(
						sprintf(
							__( '%sライセンスとして認証されました。', GACHIREAI_TEXT_DOMAIN ),
							self::tier_label( Status::tier() )
						)
					),
				),
			);
		}
		if ( 'error' === $result ) {
			return array(
				array(
					'msg_type' => 'error',
					'msg_text' => esc_html__( "認証に失敗しました。\nライセンスIDを確認してください。", GACHIREAI_TEXT_DOMAIN ),
				),
			);
		}
		return array();
	}
	private static function status_line(): string {
		$plan = Status::plan();
		if ( Status::TIER_FREE === $plan ) {
			return __( '未認証（FREE）', GACHIREAI_TEXT_DOMAIN );
		}
		$expires = Status::TIER_BASIC === $plan ? '' : substr( trim( Status::expires_at() ), 0, 10 );
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires ) ) {
			return sprintf(
				__( '%1$sライセンス（有効期限: %2$s）', GACHIREAI_TEXT_DOMAIN ),
				self::tier_label( $plan ),
				$expires
			);
		}
		return self::tier_label( $plan ) . 'ライセンス';
	}
	private static function status_note(): ?array {
		if ( Status::TIER_PRO !== Status::plan() ) {
			return null;
		}
		$days = Status::days_since_expiry( Status::expires_at() );
		if ( null === $days || $days <= 0 ) {
			return null;
		}
		if ( $days < Status::EXPIRY_GRACE_DAYS ) {
			return array(
				'text'  => __( '期限切れです。お支払い状況をご確認ください。', GACHIREAI_TEXT_DOMAIN ),
				'level' => 'warning',
			);
		}
		return array(
			'text'  => __( '期限切れのため BASICライセンスとして動作中です。', GACHIREAI_TEXT_DOMAIN ),
			'level' => 'error',
		);
	}
	private static function tier_label( string $tier ): string {
		switch ( $tier ) {
			case Status::TIER_PRO:
				return 'PRO';
			case Status::TIER_BASIC:
				return 'BASIC';
		}
		return 'FREE';
	}
}
