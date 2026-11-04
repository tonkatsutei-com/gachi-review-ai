<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Image\Providers\Cloudflare_Provider;
use Tonkatsutei\Gachi_Review_AI\Image\Providers\Google_Provider;
use Tonkatsutei\Gachi_Review_AI\Image\Providers\Openai_Provider;
final class Manager {
	private const MAX_RETRIES = 1;
	private static array $retry_delays = array( 2 );
	public static function is_available( array $image, Settings $settings ): bool {
		$provider = (string) ( $image['provider'] ?? '' );
		$model    = Image_Catalog::normalize_model( $provider, (string) ( $image['model'] ?? '' ) );
		if ( ! Image_Catalog::has( $provider ) || '' === $model || '' === $settings->image_api_key( $image ) ) {
			return false;
		}
		if ( ! Connection_Status::is_verified( 'image', $settings ) ) {
			return false;
		}
		if ( Image_Catalog::CLOUDFLARE === $provider ) {
			return 1 === preg_match( '/^[a-f0-9]{32}$/i', trim( (string) ( $image['account_id'] ?? '' ) ) );
		}
		return true;
	}
	public static function provider( array $image, string $api_key ): Provider {
		$provider = (string) ( $image['provider'] ?? Image_Catalog::CLOUDFLARE );
		$model    = Image_Catalog::normalize_model( $provider, (string) ( $image['model'] ?? '' ) );
		self::assert_ready( $api_key, $model );
		switch ( $provider ) {
			case Image_Catalog::CLOUDFLARE:
				return new Cloudflare_Provider(
					(string) ( $image['account_id'] ?? '' ),
					$api_key,
					$model,
					(int) ( $image['steps'] ?? 4 )
				);
			case Image_Catalog::GOOGLE:
				return new Google_Provider( $api_key, $model );
			case Image_Catalog::OPENAI:
				return new Openai_Provider( $api_key, $model );
		}
		throw new \RuntimeException( __( '未対応の画像生成AIが選択されています。', GACHIREAI_TEXT_DOMAIN ) );
	}
	public static function provider_for( array $image, string $api_key ): Provider {
		if ( self::gateway_enabled() ) {
			$provider = (string) ( $image['provider'] ?? Image_Catalog::CLOUDFLARE );
			$model    = Image_Catalog::normalize_model( $provider, (string) ( $image['model'] ?? '' ) );
			self::assert_ready( $api_key, $model );
			return new Gateway_Provider( $image, $api_key );
		}
		return self::provider( $image, $api_key );
	}
	public static function gateway_enabled(): bool {
		if ( ! Gateway_Client::is_configured() ) {
			return false;
		}
		return Status::capabilities()->can_generate_image();
	}
	private static function assert_ready( string $api_key, string $model ): void {
		if ( '' === trim( $api_key ) ) {
			throw new \RuntimeException( __( '画像生成AIの API キーが未設定です。「AI設定」タブで保存してください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( '' === $model ) {
			throw new \RuntimeException( __( '画像生成AIのモデル名を解決できませんでした。モデル名を確認してください。', GACHIREAI_TEXT_DOMAIN ) );
		}
	}
	public static function generate( Provider $provider, string $prompt, array $opts = array() ): array {
		$attempt = 0;
		while ( true ) {
			try {
				return $provider->generate( $prompt, $opts );
			} catch ( Provider_Exception $e ) {
				if ( ! $e->is_retryable() || $attempt >= self::MAX_RETRIES ) {
					throw $e;
				}
				++$attempt;
				sleep( self::$retry_delays[ $attempt - 1 ] ?? 2 );
			}
		}
	}
	public static function test( Provider $provider ): array {
		try {
			return $provider->test();
		} catch ( \Throwable $e ) {
			return array(
				'type'    => 'error',
				'message' => Validator::scrub( $e->getMessage(), '' ),
			);
		}
	}
}
