<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
use Tonkatsutei\Gachi_Review_AI\Event_Logger;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Favicon {
	private const SIZE = 512;
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
		$prompt   = $builder->favicon_prompt();
		$started  = microtime( true );
		$generated = Manager::generate(
			$provider,
			$prompt,
			array(
				'steps'           => (int) $image['steps'],
				'aspect_ratio'    => '1:1',
				'size'            => '1024x1024',
				'quality'         => 'low',
				'output_format'   => 'png',
				'fallback_prompt' => $builder->safe_favicon_prompt(),
			)
		);
		$processed = Processor::square( $generated['data'], self::SIZE );
		$site_name = (string) ( $this->profile->get_site_elements()['site_name'] ?? '' );
		$title     = '' !== $site_name
			? sprintf(
				__( '%s のファビコン', GACHIREAI_TEXT_DOMAIN ),
				$site_name
			)
			: __( 'サイトのファビコン', GACHIREAI_TEXT_DOMAIN );
		$filename = 'gachireai-favicon-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.png';
		$attachment_id = Media::store(
			$processed,
			$filename,
			$title,
			__( 'サイトのアイコン', GACHIREAI_TEXT_DOMAIN ),
			array(
				'_gachireai_image_kind'         => 'favicon',
				'_gachireai_image_prompt'       => $prompt,
				'_gachireai_image_provider'     => (string) $image['provider'],
				'_gachireai_image_model'        => $generated['model'],
				'_gachireai_image_generated_at' => time(),
			)
		);
		Event_Logger::record(
			Event_Logger::TYPE_SYSTEM,
			Event_Logger::LEVEL_INFO,
			__( 'ファビコンを生成しました。', GACHIREAI_TEXT_DOMAIN ),
			array(
				'provider' => (string) $image['provider'],
				'model'    => $generated['model'],
				'size'     => self::SIZE,
			),
			0,
			(int) round( ( microtime( true ) - $started ) * 1000 )
		);
		return array(
			'attachment_id' => $attachment_id,
			'url'           => (string) wp_get_attachment_image_url( $attachment_id, 'full' ),
			'message'       => __( 'ファビコンを生成しました。［保存］でサイトアイコンに反映されます。', GACHIREAI_TEXT_DOMAIN ),
		);
	}
}
