<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\Common\Store as Common_Store;
use Tonkatsutei\Gachi_Review_AI\Scraping\Connection_Tester;
use Tonkatsutei\Gachi_Review_AI\Scraping\Service_Catalog;
use Tonkatsutei\Gachi_Review_AI\Scraping\Store;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Scraping_Settings extends Screen {
	public const ACTION_SAVE = 'gachireai_scraping_save';
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
		$copy    = isset( $_POST[ self::COPY_FIELD ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::COPY_FIELD ] ) ) : '';
		if ( ! Service_Catalog::exists( $service ) ) {
			wp_safe_redirect( Tabs::url( Tabs::TAB_SCRAPING ) );
			exit;
		}
		$copy_key = 'scraping_' . $service;
		if ( '' !== $copy && $copy_key === $copy ) {
			if ( is_multisite() ) {
				$this->add_pending_copy( $copy_key );
			}
			$this->redirect( $service, '' );
		}
		$use_common = $this->apply_scraping_switch( $service );
		if ( $use_common ) {
			$this->clear_pending_copy( $copy_key );
			$this->redirect( $service, '' );
		}
		$posted = trim( $posted );
		$key = Store::sanitize_api_key( $posted );
		Store::save_service( $service, array( 'api_key' => $key ) );
		$this->clear_pending_copy( $copy_key );
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
			'page_title'       => esc_html__( 'スクレイピング', GACHIREAI_TEXT_DOMAIN ),
			'notices'          => array(),
			'tabs'             => $this->tab_nav(),
			'tag_descriptions' => Scraping_Legend::model(),
			'sections'         => $this->sections_model(),
		);
	}
	private function sections_model(): array {
		$site   = new Settings();
		$common = is_multisite() ? new Common_Store() : null;
		$site_rows   = (array) $site->get( 'scraping.services', array() );
		$common_rows = null !== $common ? (array) $common->get( 'scraping.services', array() ) : array();
		$result         = isset( $_GET[ self::RESULT_ARG ] ) ? (string) $_GET[ self::RESULT_ARG ] : '';
		$result_service = isset( $_GET[ self::SERVICE_ARG ] ) ? sanitize_key( (string) $_GET[ self::SERVICE_ARG ] ) : '';
		$sections = array();
		foreach ( Service_Catalog::all() as $id => $definition ) {
			$copy_key   = 'scraping_' . $id;
			$use_common = is_multisite() && $site->uses_common_scraping( $id );
			$pending    = $this->has_pending_copy( $copy_key );
			$source = ( $use_common || $pending ) ? $common_rows : $site_rows;
			$row    = isset( $source[ $id ] ) && is_array( $source[ $id ] ) ? $source[ $id ] : array();
			$row    = array_merge(
				array(
					'api_key' => '',
					'status'  => '',
					'message' => '',
				),
				$row
			);
			$status_text = $this->status_text( $row );
			$messages = array();
			if ( $result_service === $id && '' !== $result ) {
				$message = $this->result_message( $result, $row );
				if ( null !== $message ) {
					$messages[] = $message;
				}
			}
			$used_by = Scraping_Legend::tags( $id );
			$switch_use_common = $use_common && ! $pending;
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
				'field_value'   => esc_attr( (string) ( $row['api_key'] ?? '' ) ),
				'placeholder'   => '',
				'save_label'    => esc_html__( '保存', GACHIREAI_TEXT_DOMAIN ),
				'switch'        => $this->scraping_switch_model( $id, $switch_use_common ),
				'common_target' => is_multisite() ? esc_attr( $copy_key ) : '',
				'status_text'   => esc_html( $status_text ),
				'messages'      => $messages,
			);
		}
		return $sections;
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
