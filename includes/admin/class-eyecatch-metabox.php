<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\Image\Eyecatch;
use Tonkatsutei\Gachi_Review_AI\Image\Manager;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Eyecatch_Metabox extends Screen {
	public const ACTION = 'gachireai_generate_eyecatch';
	private const NONCE_ACTION = 'gachireai_generate_eyecatch';
	private const NOTICE_PREFIX = 'gachireai_eyecatch_notice_';
	public function add(): void {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( (string) $_GET['post'] ) ) : 0; 
		if ( 0 >= $post_id || '' === (string) get_post_meta( $post_id, 'gachireai_product_name', true ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		add_meta_box(
			'gachireai-eyecatch',
			esc_html__( 'アイキャッチ画像（gachi-review-ai）', GACHIREAI_TEXT_DOMAIN ),
			array( $this, 'render' ),
			'post',
			'side',
			'default'
		);
	}
	public function render( \WP_Post $post ): void {
		$settings  = new Settings();
		$image     = $settings->image();
		$available = Manager::is_available( $image, $settings );
		$has_image = has_post_thumbnail( (int) $post->ID );
		$image_url = $has_image ? (string) get_the_post_thumbnail_url( (int) $post->ID, 'medium' ) : '';
		$this->render_template(
			'admin/eyecatch-metabox',
			array(
				'eyecatch_action_url'   => esc_url( admin_url( 'admin-post.php' ) ),
				'eyecatch_hidden_fields' => array(
					array(
						'name'  => esc_attr( 'action' ),
						'value' => esc_attr( self::ACTION ),
					),
					array(
						'name'  => esc_attr( 'post_id' ),
						'value' => esc_attr( (string) $post->ID ),
					),
					array(
						'name'  => esc_attr( '_wpnonce' ),
						'value' => esc_attr( wp_create_nonce( self::NONCE_ACTION ) ),
					),
				),
				'eyecatch_has_image'    => $has_image,
				'eyecatch_image_url'    => esc_url( $image_url ),
				'eyecatch_available'    => $available,
				'eyecatch_disabled'     => ! $available,
				'eyecatch_button_label' => esc_html__( $has_image ? 'AI でアイキャッチを再生成' : 'AI でアイキャッチを生成', GACHIREAI_TEXT_DOMAIN ),
				'eyecatch_note'         => esc_html(
					$available
						? __( '現在のアイキャッチを置き換えます。記事タイトル・商品名を画像に重ねます。', GACHIREAI_TEXT_DOMAIN )
						: __( '画像生成AIが未設定です。', GACHIREAI_TEXT_DOMAIN )
				),
				'eyecatch_settings_url' => esc_url( Tabs::url( Tabs::TAB_LLM ) ),
				'eyecatch_messages'     => $this->messages(),
			)
		);
	}
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( self::NONCE_ACTION );
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( (string) $_POST['post_id'] ) ) : 0;
		$post    = 0 < $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			wp_die(
				esc_html__( '記事が見つかりません。', GACHIREAI_TEXT_DOMAIN ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 404 )
			);
		}
		try {
			$result = ( new Eyecatch() )->generate_for_post(
				$post_id,
				(string) $post->post_title,
				array( 'product_name' => (string) get_post_meta( $post_id, 'gachireai_product_name', true ) ),
				new Site_Profile(),
				true
			);
			$notice = array(
				'type'    => 'success',
				'message' => (string) $result['message'],
			);
		} catch ( \Throwable $e ) {
			$notice = array(
				'type'    => 'error',
				'message' => $e->getMessage(),
			);
		}
		set_transient( self::NOTICE_PREFIX . get_current_user_id(), $notice, 60 );
		$url = (string) get_edit_post_link( $post_id, 'raw' );
		$url = add_query_arg( 'gachireai_eyecatch', '1', $url );
		wp_safe_redirect( $url );
		exit;
	}
	private function messages(): array {
		if ( ! isset( $_GET['gachireai_eyecatch'] ) ) {
			return array();
		}
		$result = get_transient( self::NOTICE_PREFIX . get_current_user_id() );
		delete_transient( self::NOTICE_PREFIX . get_current_user_id() );
		if ( ! is_array( $result ) || ! isset( $result['type'], $result['message'] ) ) {
			return array();
		}
		return array( $this->message( (string) $result['type'], esc_html( (string) $result['message'] ) ) );
	}
}
