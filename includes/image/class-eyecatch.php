<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
use Tonkatsutei\Gachi_Review_AI\License\Capabilities;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Eyecatch {
	private const WIDTH  = 1200;
	private const HEIGHT = 630;
	private Settings $settings;
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings ?? new Settings();
	}
	public function is_enabled(): bool {
		$image = $this->settings->image();
		return '1' === $image['eyecatch'] && '' !== $this->settings->image_api_key( $image );
	}
	public function generate_for_post( int $post_id, string $title, array $product, Site_Profile $profile, bool $force = false ): array {
		$image = $this->settings->image();
		if ( '1' !== $image['eyecatch'] ) {
			return $this->result( 'disabled', '' );
		}
		$license_mode = Status::capabilities()->image_background_mode();
		if ( Capabilities::IMAGE_NONE === $license_mode ) {
			return $this->result(
				'skipped',
				__( '現在のライセンス（FREE）ではアイキャッチ画像を利用できません。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$mode = (string) $image['eyecatch_mode'];
		if ( 'generate' === $mode && Capabilities::IMAGE_GENERATE !== $license_mode ) {
			$mode = 'top';
		}
		if ( 'top' === $mode ) {
			return $this->apply_fixed_top( $post_id, $profile, $force );
		}
		if ( 'select' === $mode ) {
			return $this->apply_selected_image( $post_id, (int) $image['eyecatch_image'], $force );
		}
		$api_key = $this->settings->image_api_key( $image );
		if ( '' === $api_key ) {
			return $this->result(
				'skipped',
				__( '画像生成AIが未設定のため、アイキャッチ画像を生成していません。「AI設定」タブで設定してください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		if ( ! $force && function_exists( 'has_post_thumbnail' ) && has_post_thumbnail( $post_id ) ) {
			return $this->result( 'skipped', __( 'アイキャッチ画像が既に設定されているため、生成しませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$provider = Manager::provider_for( $image, $api_key );
		$builder  = new Prompt_Builder( $this->settings, $profile );
		$prompt   = $builder->eyecatch_prompt( $title, $product );
		$generated = Manager::generate(
			$provider,
			$prompt,
			array(
				'steps'           => (int) $image['steps'],
				'aspect_ratio'    => '16:9',
				'size'            => '1536x1024',
				'quality'         => 'low',
				'output_format'   => 'jpeg',
				'fallback_prompt' => $builder->safe_eyecatch_prompt(),
			)
		);
		$processed = Processor::cover( $generated['data'], self::WIDTH, self::HEIGHT, 'image/jpeg' );
		$warnings  = array();
		$data      = $processed;
		if ( '1' === $image['text_overlay'] ) {
			$font_path = Font_Manager::resolve(
				(string) ( $image['font'] ?? Font_Catalog::DEFAULT_FONT ),
				(string) ( $image['font_weight'] ?? Font_Catalog::DEFAULT_WEIGHT )
			);
			$overlay = Text_Overlay::apply(
				$processed['data'],
				array(
					'site_name'    => (string) ( $profile->get_site_elements()['site_name'] ?? '' ),
					'title'        => $title,
					'product_name' => (string) ( $product['product_name'] ?? '' ),
				),
				(string) $image['text_side'],
				$font_path
			);
			if ( '' !== $overlay['warning'] ) {
				$warnings[] = (string) $overlay['warning'];
			}
			$data = array(
				'data'   => $overlay['data'],
				'mime'   => $overlay['mime'],
				'width'  => self::WIDTH,
				'height' => self::HEIGHT,
			);
		}
		$filename = 'gachireai-eyecatch-' . $post_id . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.jpg';
		$alt      = sprintf(
			__( '記事「%s」のアイキャッチ画像', GACHIREAI_TEXT_DOMAIN ),
			mb_substr( wp_strip_all_tags( $title ), 0, 60 )
		);
		$attachment_id = Media::store(
			$data,
			$filename,
			wp_strip_all_tags( $title ),
			$alt,
			array(
				'_gachireai_image_kind'         => 'eyecatch',
				'_gachireai_image_prompt'       => $prompt,
				'_gachireai_image_provider'     => (string) $image['provider'],
				'_gachireai_image_model'        => $generated['model'],
				'_gachireai_image_generated_at' => time(),
			),
			$post_id
		);
		if ( ! set_post_thumbnail( $post_id, $attachment_id ) ) {
			throw new \RuntimeException( __( '画像は保存できましたが、アイキャッチに設定できませんでした（テーマがアイキャッチに対応しているか確認してください）。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( (int) get_post_thumbnail_id( $post_id ) !== $attachment_id ) {
			throw new \RuntimeException(
				sprintf(
					__( '画像は保存できましたが、アイキャッチに設定されませんでした（添付 ID: %d）。メディアの画像を確認してください。', GACHIREAI_TEXT_DOMAIN ),
					$attachment_id
				)
			);
		}
		update_post_meta( $post_id, 'gachireai_eyecatch_attachment_id', $attachment_id );
		update_post_meta( $post_id, 'gachireai_eyecatch_prompt', $prompt );
		update_post_meta( $post_id, 'gachireai_eyecatch_provider', (string) $image['provider'] );
		update_post_meta( $post_id, 'gachireai_eyecatch_model', $generated['model'] );
		update_post_meta( $post_id, 'gachireai_eyecatch_generated_at', time() );
		update_post_meta( $post_id, 'gachireai_eyecatch_text_warning', implode( ' / ', $warnings ) );
		return array(
			'status'        => 'generated',
			'message'       => __( 'アイキャッチ画像を生成し、記事に設定しました。', GACHIREAI_TEXT_DOMAIN ),
			'attachment_id' => $attachment_id,
			'url'           => (string) wp_get_attachment_image_url( $attachment_id, 'full' ),
			'warnings'      => $warnings,
		);
	}
	private function apply_fixed_top( int $post_id, Site_Profile $profile, bool $force ): array {
		if ( ! $force && function_exists( 'has_post_thumbnail' ) && has_post_thumbnail( $post_id ) ) {
			return $this->result( 'skipped', __( 'アイキャッチ画像が既に設定されているため、変更しませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$images = $profile->get_site_images();
		$top_id = isset( $images['top_eyecatch'] ) ? (int) $images['top_eyecatch'] : 0;
		if ( $top_id <= 0 ) {
			return $this->result(
				'skipped',
				__( '「トップページ用の画像で揃える」が選ばれていますが、サイト設定の「トップページ用アイキャッチ」が未設定です。先にトップ画像を生成してください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		if ( ! function_exists( 'set_post_thumbnail' ) || ! set_post_thumbnail( $post_id, $top_id ) ) {
			return $this->result(
				'failed',
				__( 'サイトのトップ画像をアイキャッチに設定できませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		return array(
			'status'        => 'fixed',
			'message'       => __( 'トップページ用の画像をアイキャッチとして使用します。', GACHIREAI_TEXT_DOMAIN ),
			'attachment_id' => $top_id,
			'url'           => function_exists( 'wp_get_attachment_image_url' )
				? (string) wp_get_attachment_image_url( $top_id, 'full' )
				: '',
			'warnings'      => array(),
		);
	}
	private function apply_selected_image( int $post_id, int $image_id, bool $force ): array {
		if ( ! $force && function_exists( 'has_post_thumbnail' ) && has_post_thumbnail( $post_id ) ) {
			return $this->result( 'skipped', __( 'アイキャッチ画像が既に設定されているため、変更しませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( $image_id <= 0 ) {
			return $this->result(
				'skipped',
				__( '「選択した画像で揃える」の画像が未選択です。「記事設定」タブで画像を選択してください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		if ( ! function_exists( 'set_post_thumbnail' ) || ! set_post_thumbnail( $post_id, $image_id ) ) {
			return $this->result(
				'failed',
				__( '選択した画像をアイキャッチに設定できませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		return array(
			'status'        => 'fixed',
			'message'       => __( '選択した画像をアイキャッチとして使用します。', GACHIREAI_TEXT_DOMAIN ),
			'attachment_id' => $image_id,
			'url'           => function_exists( 'wp_get_attachment_image_url' )
				? (string) wp_get_attachment_image_url( $image_id, 'full' )
				: '',
			'warnings'      => array(),
		);
	}
	private function result( string $status, string $message ): array {
		return array(
			'status'        => $status,
			'message'       => $message,
			'attachment_id' => 0,
			'url'           => '',
			'warnings'      => array(),
		);
	}
}
