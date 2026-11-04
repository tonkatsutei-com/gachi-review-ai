<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
use Tonkatsutei\Gachi_Review_AI\Event_Logger;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Top_Eyecatch {
	private const WIDTH  = 1200;
	private const HEIGHT = 630;
	private Settings $settings;
	private Site_Profile $profile;
	public function __construct( ?Settings $settings = null, ?Site_Profile $profile = null ) {
		$this->settings = $settings ?? new Settings();
		$this->profile  = $profile ?? new Site_Profile();
	}
	public function is_available(): bool {
		return Manager::is_available( $this->settings->image(), $this->settings );
	}
	public function generate(): array {
		$image   = $this->settings->image();
		$api_key = $this->settings->image_api_key( $image );
		if ( '' === $api_key ) {
			throw new \RuntimeException( __( '画像生成AIの API キーが未設定です。「AI設定」タブで保存してください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$provider = Manager::provider_for( $image, $api_key );
		$builder  = new Prompt_Builder( $this->settings, $this->profile );
		$prompt   = $builder->top_eyecatch_prompt();
		$started  = microtime( true );
		$generated = Manager::generate(
			$provider,
			$prompt,
			array(
				'steps'           => (int) $image['steps'],
				'aspect_ratio'    => '16:9',
				'size'            => '1536x1024',
				'quality'         => 'low',
				'output_format'   => 'jpeg',
				'fallback_prompt' => $builder->safe_top_eyecatch_prompt(),
			)
		);
		$processed = Processor::cover( $generated['data'], self::WIDTH, self::HEIGHT, 'image/jpeg' );
		$elements  = $this->profile->get_site_elements();
		$site_name = (string) ( $elements['site_name'] ?? '' );
		$warnings  = array();
		$data      = $processed;
		$base_id   = 0;
		$title = '' !== $site_name
			? sprintf(
				__( '%s のトップページ用アイキャッチ', GACHIREAI_TEXT_DOMAIN ),
				$site_name
			)
			: __( 'トップページ用アイキャッチ', GACHIREAI_TEXT_DOMAIN );
		if ( '1' === $image['text_overlay'] && '' !== trim( $site_name ) ) {
			try {
				$base_id = Media::store(
					$processed,
					'gachireai-top-eyecatch-base-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.jpg',
					$title . '（背景）',
					'',
					array( '_gachireai_image_kind' => 'top_eyecatch_base' )
				);
			} catch ( \Throwable $e ) {
				$base_id = 0;
			}
			$font_path = Font_Manager::resolve(
				(string) ( $image['font'] ?? Font_Catalog::DEFAULT_FONT ),
				(string) ( $image['font_weight'] ?? Font_Catalog::DEFAULT_WEIGHT )
			);
			$overlay = Text_Overlay::apply(
				$processed['data'],
				array(
					'site_name'    => '',
					'title'        => $site_name,
					'product_name' => self::subtitle( (string) ( $elements['description'] ?? '' ) ),
				),
				(string) $image['text_side'],
				$font_path
			);
			if ( '' !== (string) $overlay['warning'] ) {
				$warnings[] = (string) $overlay['warning'];
			}
			$data = array(
				'data'   => $overlay['data'],
				'mime'   => $overlay['mime'],
				'width'  => self::WIDTH,
				'height' => self::HEIGHT,
			);
		}
		$filename = 'gachireai-top-eyecatch-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.jpg';
		$attachment_id = Media::store(
			$data,
			$filename,
			$title,
			$title,
			array(
				'_gachireai_image_kind'               => 'top_eyecatch',
				'_gachireai_image_prompt'             => $prompt,
				'_gachireai_image_provider'           => (string) $image['provider'],
				'_gachireai_image_model'              => $generated['model'],
				'_gachireai_image_generated_at'       => time(),
				'_gachireai_image_base_attachment_id' => $base_id,
				'_gachireai_image_text_warning'       => implode( ' / ', $warnings ),
			)
		);
		Event_Logger::record(
			Event_Logger::TYPE_SYSTEM,
			Event_Logger::LEVEL_INFO,
			__( 'トップページ用アイキャッチを生成しました。', GACHIREAI_TEXT_DOMAIN ),
			array(
				'provider' => (string) $image['provider'],
				'model'    => $generated['model'],
				'size'     => self::WIDTH . 'x' . self::HEIGHT,
			),
			0,
			(int) round( ( microtime( true ) - $started ) * 1000 )
		);
		$message = __( 'トップページ用アイキャッチを生成しました。［保存］でトップページに反映されます。', GACHIREAI_TEXT_DOMAIN );
		if ( array() !== $warnings ) {
			$message .= ' ' . implode( ' / ', $warnings );
		}
		return array(
			'attachment_id' => $attachment_id,
			'url'           => (string) wp_get_attachment_image_url( $attachment_id, 'full' ),
			'message'       => $message,
		);
	}
	private static function subtitle( string $description ): string {
		$description = trim( (string) preg_replace( '/\s+/u', ' ', $description ) );
		if ( '' === $description ) {
			return '';
		}
		return 60 < mb_strlen( $description )
			? mb_substr( $description, 0, 60 ) . '…'
			: $description;
	}
}
