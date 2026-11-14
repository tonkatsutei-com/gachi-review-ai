<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\Common\Store as Common_Store;
use Tonkatsutei\Gachi_Review_AI\Event_Logger;
use Tonkatsutei\Gachi_Review_AI\Scraping\Service_Catalog;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
abstract class Screen {
	private ?Div_Renderer $renderer = null;
	protected bool $common_mode = false;
	protected const COPY_FIELD = 'gachireai_copy_common';
	private const PENDING_COPY_PREFIX = 'gachireai_pending_copy_';
	private const PENDING_COPY_TTL = 1800;
	private ?array $pending_copies_cache = null;
	public function use_common_mode(): void {
		$this->common_mode = true;
	}
	final protected function common_switch_model( string $key ): array {
		if ( ! is_multisite() || ! in_array( $key, Settings::COMMON_GROUPS, true ) ) {
			return array();
		}
		$use_common = ( new Settings() )->uses_common( $key ) && ! $this->has_pending_copy( $key );
		$description = $use_common
			? esc_html__( '共通設定（ガチレビュー.AI）の値を使用します。このサイト個別の値は設定できません。', GACHIREAI_TEXT_DOMAIN )
			: esc_html__( 'このサイトで設定した値を使用します。共通設定の値は使用しません。', GACHIREAI_TEXT_DOMAIN );
		if ( ! $use_common && $this->same_as_common( $key ) ) {
			$description .= ' ' . esc_html__( '※共通設定と同じ値です。共通を使う場合は「共通設定の値を使用する」を選んでください。', GACHIREAI_TEXT_DOMAIN );
		}
		$item = array(
			'name'        => 'common[' . $key . ']',
			'value'       => '1',
			'is_checkbox' => true,
			'checkboxes'  => array(
				array(
					'opt_value'    => '1',
					'opt_label'    => esc_html__( '共通設定の値を使用する', GACHIREAI_TEXT_DOMAIN ),
					'opt_checked'  => $use_common,
					'opt_disabled' => false,
					'common_switch' => $key,
				),
			),
			'description' => $description,
		);
		$item['copy_visible']     = true;
		$item['copy_name']        = esc_attr( self::COPY_FIELD );
		$item['copy_value']       = esc_attr( $key );
		$item['copy_label']       = esc_html__( '共通設定の値をコピーする', GACHIREAI_TEXT_DOMAIN );
		$item['copy_disabled']    = $use_common;
		$item['copy_switch']      = esc_attr( $key );
		$item['copy_description'] = wp_kses(
			__( '共通設定（ガチレビュー.AI）の値を以下にセットしますので、必要個所を編集した後に保存してください。<br>このサイト個別の値として保存され、共通設定とは連動はしません。', GACHIREAI_TEXT_DOMAIN ),
			array( 'br' => array() )
		);
		return array( $item );
	}
	final protected function scraping_switch_model( string $service_id, bool $use_common ): array {
		if ( ! is_multisite() || ! Service_Catalog::exists( $service_id ) ) {
			return array();
		}
		$key = 'scraping_' . $service_id;
		$description = $use_common
			? esc_html__( '共通設定（ガチレビュー.AI）の値を使用します。このサービス個別の値は設定できません。', GACHIREAI_TEXT_DOMAIN )
			: esc_html__( 'このサイトで設定した値を使用します。共通設定の値は使用しません。', GACHIREAI_TEXT_DOMAIN );
		$item = array(
			'name'        => 'common[' . $key . ']',
			'value'       => '1',
			'is_checkbox' => true,
			'checkboxes'  => array(
				array(
					'opt_value'     => '1',
					'opt_label'     => esc_html__( '共通設定の値を使用する', GACHIREAI_TEXT_DOMAIN ),
					'opt_checked'   => $use_common,
					'opt_disabled'  => false,
					'common_switch' => $key,
				),
			),
			'description' => $description,
		);
		$item['copy_visible']     = true;
		$item['copy_name']        = esc_attr( self::COPY_FIELD );
		$item['copy_value']       = esc_attr( $key );
		$item['copy_label']       = esc_html__( '共通設定の値をコピーする', GACHIREAI_TEXT_DOMAIN );
		$item['copy_disabled']    = $use_common;
		$item['copy_switch']      = esc_attr( $key );
		$item['copy_description'] = wp_kses(
			__( '共通設定（ガチレビュー.AI）の値を以下にセットしますので、必要個所を編集した後に保存してください。<br>このサイト個別の値として保存され、共通設定とは連動はしません。', GACHIREAI_TEXT_DOMAIN ),
			array( 'br' => array() )
		);
		return array( $item );
	}
	final protected function apply_scraping_switch( string $service_id ): bool {
		if ( ! is_multisite() || ! Service_Catalog::exists( $service_id ) ) {
			return false;
		}
		$key = 'scraping_' . $service_id;
		$common = isset( $_POST['common'] ) && is_array( $_POST['common'] ) ? (array) wp_unslash( $_POST['common'] ) : array();
		$settings = new Settings();
		$settings->set_scraping_override( $service_id, empty( $common[ $key ] ) );
		return $settings->uses_common_scraping( $service_id );
	}
	private function same_as_common( string $key ): bool {
		$common = (array) ( new Common_Store() )->get( $key, array() );
		if ( array() === $common ) {
			return false;
		}
		foreach ( $common as $value ) {
			if ( is_array( $value ) ) {
				return false;
			}
		}
		$site     = (array) ( new Settings() )->get( $key, array() );
		$compared = 0;
		foreach ( $common as $field => $value ) {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $value ) {
				continue;
			}
			$site_value = isset( $site[ $field ] ) && is_scalar( $site[ $field ] ) ? trim( (string) $site[ $field ] ) : '';
			if ( $site_value !== $value ) {
				return false;
			}
			++$compared;
		}
		return $compared > 0;
	}
	final protected function apply_common_switch( string $key ): bool {
		if ( ! is_multisite() || ! in_array( $key, Settings::COMMON_GROUPS, true ) ) {
			return false;
		}
		$common = isset( $_POST['common'] ) && is_array( $_POST['common'] ) ? (array) wp_unslash( $_POST['common'] ) : array();
		$settings = new Settings();
		$settings->set_common_override( $key, empty( $common[ $key ] ) );
		return $settings->uses_common( $key );
	}
	private function pending_copies(): array {
		if ( null !== $this->pending_copies_cache ) {
			return $this->pending_copies_cache;
		}
		$saved = get_transient( self::PENDING_COPY_PREFIX . get_current_user_id() );
		$this->pending_copies_cache = is_array( $saved ) ? $saved : array();
		return $this->pending_copies_cache;
	}
	final protected function add_pending_copy( string $key ): void {
		$list         = $this->pending_copies();
		$list[ $key ] = true;
		set_transient( self::PENDING_COPY_PREFIX . get_current_user_id(), $list, self::PENDING_COPY_TTL );
		$this->pending_copies_cache = $list;
	}
	final protected function has_pending_copy( string $key ): bool {
		return isset( $this->pending_copies()[ $key ] );
	}
	final protected function clear_pending_copy( string $key ): void {
		$list = $this->pending_copies();
		if ( ! isset( $list[ $key ] ) ) {
			return;
		}
		unset( $list[ $key ] );
		if ( array() === $list ) {
			delete_transient( self::PENDING_COPY_PREFIX . get_current_user_id() );
		} else {
			set_transient( self::PENDING_COPY_PREFIX . get_current_user_id(), $list, self::PENDING_COPY_TTL );
		}
		$this->pending_copies_cache = $list;
	}
	final public static function plugin_badge(): array {
		return array(
			'plugin_brand'   => esc_html__( 'ガチレビュー.AI', GACHIREAI_TEXT_DOMAIN ),
			'plugin_version' => esc_html( 'Ver.' . GACHIREAI_APP_VERSION ),
		);
	}
	final protected function render_template( string $template, array $model ): void {
		$model = array_merge( $model, self::plugin_badge() );
		try {
			$html = $this->render_template_string( $template, $model );
		} catch ( \RuntimeException $e ) {
			wp_die(
				esc_html( $e->getMessage() ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 500 )
			);
		}
		echo $html; 
	}
	final protected function render_template_string( string $template, array $model ): string {
		if ( null === $this->renderer ) {
			$this->renderer = new Div_Renderer();
		}
		return $this->renderer->render( $template, $model );
	}
	final protected function notice( string $type, string $text ): array {
		$allowed = array( 'info', 'success', 'warning', 'error' );
		return array(
			'type' => in_array( $type, $allowed, true ) ? $type : 'info',
			'text' => $text,
		);
	}
	final protected function message( string $type, string $text ): array {
		$allowed = array( 'info', 'success', 'warning', 'error' );
		return array(
			'msg_type' => in_array( $type, $allowed, true ) ? $type : 'info',
			'msg_text' => $text,
		);
	}
	final protected function tab_nav(): array {
		if ( $this->common_mode ) {
			return Common_Tabs::models( Common_Tabs::current() );
		}
		return Tabs::models( Tabs::current() );
	}
	final protected function record_setting_save( string $label ): void {
		Event_Logger::record(
			Event_Logger::TYPE_SYSTEM,
			Event_Logger::LEVEL_INFO,
			sprintf(
				__( '「%s」を保存しました。', GACHIREAI_TEXT_DOMAIN ),
				$label
			)
		);
	}
	final protected function guard_ajax( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN ) ),
				403
			);
		}
		if ( ! check_ajax_referer( $nonce_action, '_wpnonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'セキュリティチェックに失敗しました。画面を再読み込みしてから、もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ) ),
				403
			);
		}
	}
	final protected function send_form_html( callable $renderer, array $extra = array() ): void {
		try {
			$html = (string) $renderer();
		} catch ( \Throwable $e ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						__( '画面の再描画に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
						mb_substr( trim( $e->getMessage() ), 0, 300 )
					),
				),
				500
			);
		}
		wp_send_json_success( array_merge( array( 'html' => $html ), $extra ) );
	}
}
