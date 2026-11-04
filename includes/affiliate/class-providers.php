<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Affiliate;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\Icons;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Providers {
	public const STATUS_SEARCH_ONLY = 'search_only';
	public static function icon( string $slug ): string {
		return Icons::ec( $slug );
	}
	public static function valid( Settings $settings ): array {
		$providers = array();
		$rakuten = (array) $settings->get( Product_Selector::EC_RAKUTEN, array() );
		if (
			Connection_Status::is_verified( Product_Selector::EC_RAKUTEN, $settings )
			&& '' !== trim( (string) ( $rakuten['affiliate_id'] ?? '' ) )
		) {
			$providers[] = array(
				'provider' => Product_Selector::EC_RAKUTEN,
				'label'    => __( '楽天市場', GACHIREAI_TEXT_DOMAIN ),
				'class'    => Rakuten_Client::class,
				'settings' => $rakuten,
				'icon'     => self::icon( Product_Selector::EC_RAKUTEN ),
			);
		}
		$yahoo = (array) $settings->get( Product_Selector::EC_YAHOO, array() );
		if (
			Connection_Status::is_verified( Product_Selector::EC_YAHOO, $settings )
			&& '' !== trim( (string) ( $yahoo['affiliate_code'] ?? '' ) )
		) {
			$providers[] = array(
				'provider' => Product_Selector::EC_YAHOO,
				'label'    => __( 'Yahoo!ショッピング', GACHIREAI_TEXT_DOMAIN ),
				'class'    => Yahoo_Client::class,
				'settings' => $yahoo,
				'icon'     => self::icon( Product_Selector::EC_YAHOO ),
			);
		}
		$amazon = (array) $settings->get( Product_Selector::EC_AMAZON, array() );
		if ( Amazon_Client::is_usable( $amazon, $settings ) ) {
			$providers[] = array(
				'provider' => Product_Selector::EC_AMAZON,
				'label'    => __( 'Amazon', GACHIREAI_TEXT_DOMAIN ),
				'class'    => Amazon_Client::class,
				'settings' => $amazon,
				'icon'     => self::icon( Product_Selector::EC_AMAZON ),
			);
		}
		return $providers;
	}
	public static function enabled( Settings $settings ): array {
		$enabled = Card_Settings::enabled_ecs( $settings );
		return array_values(
			array_filter(
				self::valid( $settings ),
				static function ( array $provider ) use ( $enabled ): bool {
					return in_array( (string) ( $provider['provider'] ?? '' ), $enabled, true );
				}
			)
		);
	}
	public static function render_info( Settings $settings ): array {
		return self::render_info_from( self::valid( $settings ) );
	}
	public static function render_info_from( array $providers ): array {
		$info = array();
		foreach ( $providers as $provider ) {
			$info[ (string) $provider['provider'] ] = array(
				'label' => (string) $provider['label'],
				'icon'  => (string) ( $provider['icon'] ?? '' ),
			);
		}
		return $info;
	}
}
