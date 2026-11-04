<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Affiliate;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Box_Shortcode {
	public const TAG = 'gachireai_affiliate';
	private const META_BOXES = 'gachireai_affiliate_boxes';
	private const META_PRODUCT_NAME = 'gachireai_product_name';
	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}
	public static function render( $atts, $content = null, $tag = '' ): string {
		$atts   = shortcode_atts( array( 'type' => 'lead' ), $atts, self::TAG );
		$is_cta = 'cta' === (string) ( $atts['type'] ?? 'lead' );
		$post_id = (int) get_the_ID();
		if ( 0 >= $post_id ) {
			return '';
		}
		$boxes = get_post_meta( $post_id, self::META_BOXES, true );
		if ( ! is_array( $boxes ) || array() === $boxes ) {
			return '';
		}
		$settings = new Settings();
		$providers = Providers::valid( $settings );
		$info      = Providers::render_info_from( $providers );
		$valid     = self::filter_boxes( $boxes, $info );
		if ( array() === $valid ) {
			return '';
		}
		$product_name = (string) get_post_meta( $post_id, self::META_PRODUCT_NAME, true );
		$pattern      = Card_Settings::pattern( $settings );
		$ec_group     = (array) $settings->get( 'ec', array() );
		$base_ec      = Product_Selector::normalize_ec( (string) ( $ec_group['ec'] ?? '' ) );
		$plain = current_user_can( 'manage_options' );
		$base     = self::base_box( $valid, $base_ec );
		$item     = (array) ( $base['item'] ?? array() );
		$searches = self::search_buttons( $valid, $info, Card_Settings::enabled_ecs( $settings ), $plain );
		$item = self::refresh_price( $item, (string) ( $base['provider'] ?? '' ), $providers );
		if ( $plain ) {
			$item = self::plain_item( $item, (string) ( $base['provider'] ?? '' ), $providers );
		}
		try {
			return ( new Box_Renderer() )->render( $item, $searches, $is_cta, $product_name, $pattern, $plain );
		} catch ( \Throwable $e ) {
			return '';
		}
	}
	private static function filter_boxes( array $boxes, array $info ): array {
		$valid = array();
		foreach ( $boxes as $box ) {
			if ( ! is_array( $box ) ) {
				continue;
			}
			$provider = (string) ( $box['provider'] ?? '' );
			if ( '' === $provider || ! isset( $info[ $provider ] ) ) {
				continue;
			}
			if ( empty( $box['has_box'] ) ) {
				continue;
			}
			$valid[] = $box;
		}
		return $valid;
	}
	private static function base_box( array $valid, string $base_ec ): array {
		foreach ( $valid as $box ) {
			if ( (string) ( $box['provider'] ?? '' ) === $base_ec && array() !== (array) ( $box['item'] ?? array() ) ) {
				return $box;
			}
		}
		foreach ( $valid as $box ) {
			if ( array() !== (array) ( $box['item'] ?? array() ) ) {
				return $box;
			}
		}
		return array();
	}
	private static function refresh_price( array $item, string $slug, array $providers ): array {
		if ( array() === $item || '' === $slug ) {
			return $item;
		}
		$settings = array();
		foreach ( $providers as $provider ) {
			if ( (string) ( $provider['provider'] ?? '' ) === $slug ) {
				$settings = (array) ( $provider['settings'] ?? array() );
				break;
			}
		}
		if ( array() === $settings ) {
			return $item;
		}
		try {
			$resolved = ( new Price_Refresher() )->resolve( $slug, $item, $settings );
		} catch ( \Throwable $e ) {
			return $item;
		}
		$item['item_price'] = (int) $resolved['item_price'];
		if ( '' !== (string) $resolved['price_fetched_at'] ) {
			$item['price_fetched_at'] = (string) $resolved['price_fetched_at'];
		} else {
			unset( $item['price_fetched_at'] );
		}
		return $item;
	}
	private static function search_buttons( array $valid, array $info, array $enabled, bool $plain = false ): array {
		$searches = array();
		foreach ( $valid as $box ) {
			$slug = (string) ( $box['provider'] ?? '' );
			$url  = self::search_link( $box, $plain );
			if ( '' === $url || ! in_array( $slug, $enabled, true ) ) {
				continue;
			}
			$searches[] = array(
				'slug'  => $slug,
				'label' => (string) ( $info[ $slug ]['label'] ?? ( $box['label'] ?? '' ) ),
				'url'   => $url,
				'icon'  => (string) ( $info[ $slug ]['icon'] ?? '' ),
			);
		}
		return $searches;
	}
	private static function search_link( array $box, bool $plain ): string {
		if ( $plain ) {
			$plain_url = trim( (string) ( $box['plain_search_url'] ?? '' ) );
			if ( '' !== $plain_url ) {
				return $plain_url;
			}
		}
		return trim( (string) ( $box['search_url'] ?? '' ) );
	}
	private static function plain_item( array $item, string $slug, array $providers ): array {
		if ( array() === $item ) {
			return $item;
		}
		$class = '';
		foreach ( $providers as $provider ) {
			if ( (string) ( $provider['provider'] ?? '' ) === $slug ) {
				$class = (string) ( $provider['class'] ?? '' );
				break;
			}
		}
		if ( '' === $class || ! method_exists( $class, 'plain_url' ) ) {
			return $item;
		}
		$url = trim( (string) ( $item['item_url'] ?? '' ) );
		if ( '' === $url ) {
			$url = trim( (string) ( $item['affiliate_url'] ?? '' ) );
		}
		if ( '' !== $url ) {
			$item['item_url'] = $class::plain_url( $url );
		}
		return $item;
	}
}
