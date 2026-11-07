<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\Scraping\Connection_Tester;
use Tonkatsutei\Gachi_Review_AI\Scraping\Service_Catalog;
use Tonkatsutei\Gachi_Review_AI\Scraping\Store;
final class Scraping_Settings extends Screen {
	public const ACTION_SAVE = 'gachireai_scraping_save';
	public const ACTION_SWITCH = 'gachireai_scraping_switch';
	private const NONCE_SWITCH = 'gachireai_scraping_switch';
	private const NONCE_SAVE = 'gachireai_scraping_settings';
	public const RESULT_ARG = 'gachireai_scraping_result';
	public const SERVICE_ARG = 'gachireai_scraping_service';
	private const FIELD = 'api_key';
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->render_template( 'admin/scraping', $this->model() );
	}
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( self::NONCE_SAVE );
		$service = isset( $_POST['service'] ) ? sanitize_key( wp_unslash( (string) $_POST['service'] ) ) : '';
		$posted  = isset( $_POST[ self::FIELD ] ) ? (string) wp_unslash( $_POST[ self::FIELD ] ) : '';
		if ( ! Service_Catalog::exists( $service ) ) {
			wp_safe_redirect( Tabs::url( Tabs::TAB_SCRAPING ) );
			exit;
		}
		$current = Store::get( $service )['api_key'];
		$posted  = trim( $posted );
		$key = ( '' === $posted || $posted === $this->mask_api_key( $current ) )
			? $current
			: Store::sanitize_api_key( $posted );
		Store::save_service( $service, array( 'api_key' => $key ) );
		if ( '' === $key ) {
			$this->redirect( $service, 'empty' );
		}
		$result = Connection_Tester::test( $service, $key );
		Store::save_service(
			$service,
			array(
				'status'    => $result['ok'] ? 'ok' : 'error',
				'message'   => $result['message'],
				'tested_at' => (string) current_time( 'mysql' ),
			)
		);
		$this->record_setting_save( __( 'スクレイピング', GACHIREAI_TEXT_DOMAIN ) );
		$this->redirect( $service, $result['ok'] ? '1' : '0' );
	}
	public function handle_switch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( self::NONCE_SWITCH );
		$this->apply_common_switch( 'scraping' );
		wp_safe_redirect( Tabs::url( Tabs::TAB_SCRAPING ) );
		exit;
	}
	private function redirect( string $service, string $result ): void {
		$url = Tabs::url(
			Tabs::TAB_SCRAPING,
			array(
				self::RESULT_ARG  => $result,
				self::SERVICE_ARG => $service,
			)
		);
		wp_safe_redirect( $url . '#gachireai-section-scraping-' . rawurlencode( $service ) );
		exit;
	}
	private function model(): array {
		return array(
			'page_title'                => esc_html__( 'スクレイピング', GACHIREAI_TEXT_DOMAIN ),
			'notices'                   => array(),
			'tabs'                      => $this->tab_nav(),
			'common_switch'             => $this->common_switch_model( 'scraping' ),
			'common_switch_action'      => esc_url( admin_url( 'admin-post.php' ) ),
			'common_switch_hidden'      => array(
				array( 'name' => 'action', 'value' => esc_attr( self::ACTION_SWITCH ) ),
				array( 'name' => '_wpnonce', 'value' => esc_attr( wp_create_nonce( self::NONCE_SWITCH ) ) ),
			),
			'common_switch_title'       => esc_html__( '共通設定', GACHIREAI_TEXT_DOMAIN ),
			'common_switch_description' => esc_html__( 'スクレイピング設定を全サイト共通にするか、このサイト独自の値を使うかを選びます。', GACHIREAI_TEXT_DOMAIN ),
			'common_switch_save_label'  => esc_html__( '保存', GACHIREAI_TEXT_DOMAIN ),
			'tag_descriptions'          => Scraping_Legend::model(),
			'sections'                  => $this->sections_model(),
		);
	}
	private function sections_model(): array {
		$store = Store::all();
		$result         = isset( $_GET[ self::RESULT_ARG ] ) ? (string) $_GET[ self::RESULT_ARG ] : '';
		$result_service = isset( $_GET[ self::SERVICE_ARG ] ) ? sanitize_key( (string) $_GET[ self::SERVICE_ARG ] ) : '';
		$sections = array();
		foreach ( Service_Catalog::all() as $id => $definition ) {
			$row         = $store[ $id ];
			$status_text = $this->status_text( $row );
			$messages = array();
			if ( $result_service === $id && '' !== $result ) {
				$message = $this->result_message( $result, $row );
				if ( null !== $message ) {
					$messages[] = $message;
				}
			}
			$used_by = Scraping_Legend::tags( $id );
			$sections[] = array(
				'section_key'   => esc_attr( 'scraping-' . $id ),
				'title'         => esc_html( $definition['label'] ),
				'used_by'       => $used_by,
				'url'           => esc_url( $definition['url'] ),
				'description'   => esc_html( $definition['features'] ),
				'form_action'   => esc_url( admin_url( 'admin-post.php' ) ),
				'hidden_fields' => array(
					array(
						'name'  => 'action',
						'value' => self::ACTION_SAVE,
					),
					array(
						'name'  => '_wpnonce',
						'value' => wp_create_nonce( self::NONCE_SAVE ),
					),
					array(
						'name'  => 'service',
						'value' => $id,
					),
				),
				'field_id'      => esc_attr( 'gachireai-scraping-' . $id ),
				'field_name'    => esc_attr( self::FIELD ),
				'field_label'   => esc_html__( 'APIキー', GACHIREAI_TEXT_DOMAIN ),
				'field_value'   => esc_attr( $this->mask_api_key( $row['api_key'] ) ),
				'placeholder'   => '',
				'save_label'    => esc_html__( '保存', GACHIREAI_TEXT_DOMAIN ),
				'common_target' => is_multisite() ? esc_attr( 'scraping' ) : '',
				'status_text'   => esc_html( $status_text ),
				'messages'      => $messages,
			);
		}
		return $sections;
	}
	private function mask_api_key( string $api_key ): string {
		$length = strlen( $api_key );
		if ( 0 === $length ) {
			return '';
		}
		if ( $length <= 6 ) {
			return str_repeat( '*', $length );
		}
		return substr( $api_key, 0, 3 ) . str_repeat( '*', $length - 6 ) . substr( $api_key, -3 );
	}
	private function status_text( array $row ): string {
		if ( '' === $row['api_key'] ) {
			return '';
		}
		if ( 'ok' === $row['status'] ) {
			$text = __( '接続OK', GACHIREAI_TEXT_DOMAIN );
		} elseif ( 'error' === $row['status'] ) {
			$text = __( '接続エラー', GACHIREAI_TEXT_DOMAIN );
		} else {
			$text = __( '未テスト', GACHIREAI_TEXT_DOMAIN );
		}
		if ( '' !== $row['message'] ) {
			$text .= '：' . $row['message'];
		}
		return $text;
	}
	private function result_message( string $result, array $row ): ?array {
		if ( '1' === $result ) {
			return null;
		}
		if ( 'empty' === $result ) {
			return $this->message( 'info', esc_html__( 'APIキーが未入力のため、接続テストは行いませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$detail = '' !== $row['message']
			? sprintf(
				__( 'APIキーを保存しましたが、接続テストに失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
				$row['message']
			)
			: __( 'APIキーを保存しましたが、接続テストに失敗しました。', GACHIREAI_TEXT_DOMAIN );
		return $this->message( 'warning', esc_html( $detail ) );
	}
}
