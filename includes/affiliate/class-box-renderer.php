<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Affiliate;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
final class Box_Renderer {
	public const DEFAULT_PATTERN = 'p01';
	public const DISCLOSURE = '本記事にはアフィリエイトリンクが含まれます。商品の購入により、当サイトが報酬を受け取ることがあります。';
	public static function patterns(): array {
		$labels = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$labels[ sprintf( 'p%02d', $i ) ] = sprintf( '%02d', $i );
		}
		return $labels;
	}
	public static function all_patterns(): array {
		return self::patterns();
	}
	public static function normalize_pattern( string $pattern ): string {
		return array_key_exists( $pattern, self::patterns() ) ? $pattern : self::DEFAULT_PATTERN;
	}
	public static function product_link( array $item, bool $plain = false ): string {
		if ( $plain ) {
			$plain_link = trim( (string) ( $item['item_url'] ?? '' ) );
			if ( '' !== $plain_link ) {
				return $plain_link;
			}
		}
		$link = trim( (string) ( $item['affiliate_url'] ?? '' ) );
		if ( '' !== $link ) {
			return $link;
		}
		return trim( (string) ( $item['item_url'] ?? '' ) );
	}
	public function render( array $item, array $searches, bool $is_cta, string $product_name, string $pattern = self::DEFAULT_PATTERN, bool $plain_links = false ): string {
		$matched = trim( (string) ( $item['item_name'] ?? '' ) );
		$name    = '' !== $matched ? $matched : trim( $product_name );
		$price   = (int) ( $item['item_price'] ?? 0 );
		$image   = trim( (string) ( $item['image_url'] ?? '' ) );
		$fetched_at = trim( (string) ( $item['price_fetched_at'] ?? '' ) );
		$note_date  = '';
		if ( '' !== $fetched_at ) {
			$formatted = mysql2date( 'Y年n月j日 H:i', $fetched_at, false );
			if ( is_string( $formatted ) && '' !== $formatted ) {
				$note_date = sprintf(
					__( '価格は %s現在です。', GACHIREAI_TEXT_DOMAIN ),
					$formatted
				);
			}
		}
		$link = self::product_link( $item, $plain_links );
		$buttons = array();
		foreach ( $searches as $search ) {
			$url = trim( (string) ( $search['url'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}
			$icon = trim( (string) ( $search['icon'] ?? '' ) );
			$buttons[] = array(
				'search_ec'       => esc_attr( (string) ( $search['slug'] ?? '' ) ),
				'search_url'      => esc_url( $url ),
				'search_label'    => esc_html( (string) ( $search['label'] ?? '' ) ),
				'search_icon'     => esc_url( $icon ),
				'search_has_icon' => '' !== $icon,
			);
		}
		$model = array(
			'affiliate_pattern'        => esc_attr( self::normalize_pattern( $pattern ) ),
			'affiliate_cta'            => $is_cta,
			'affiliate_title'          => $is_cta
				? esc_html__( 'この商品をチェックする', GACHIREAI_TEXT_DOMAIN )
				: '',
			'affiliate_has_image'      => '' !== $image,
			'affiliate_image_url'      => esc_url( $image ),
			'affiliate_has_image_link' => '' !== $link,
			'affiliate_image_link'     => esc_url( $link ),
			'affiliate_product_name'   => esc_html( $name ),
			'affiliate_has_link'       => '' !== $link,
			'affiliate_link'           => esc_url( $link ),
			'affiliate_has_price'      => 0 < $price,
			'affiliate_price'          => 0 < $price ? esc_html( number_format_i18n( $price ) . '円' ) : '',
			'affiliate_has_price_note'        => 0 < $price,
			'affiliate_has_price_note_date'   => '' !== $note_date,
			'affiliate_price_note_date'       => esc_html( $note_date ),
			'affiliate_price_note_disclaimer' => esc_html__( 'ご購入時は販売サイトで最新価格をご確認ください。', GACHIREAI_TEXT_DOMAIN ),
			'affiliate_searches'       => $buttons,
			'affiliate_disclosure'     => esc_html__( self::DISCLOSURE, GACHIREAI_TEXT_DOMAIN ),
		);
		return ( new Div_Renderer() )->render( 'article/affiliate-box', $model );
	}
}
