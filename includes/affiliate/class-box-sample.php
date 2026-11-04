<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Affiliate;
use Tonkatsutei\Gachi_Review_AI\Activator;
final class Box_Sample {
	public const SAMPLE_META = 'gachireai_card_sample';
	public static function is_sample( int $post_id ): bool {
		return 0 < $post_id && '1' === (string) get_post_meta( $post_id, self::SAMPLE_META, true );
	}
	public function previews( array $product = array(), string $ec = '' ): array {
		$source  = $this->resolve( $product, $ec );
		$product = (array) $source['product'];
		$ec      = (string) $source['ec'];
		$renderer = new Box_Renderer();
		$item     = $this->sample_item( $product );
		$searches = $this->sample_searches( $ec );
		$name     = (string) $item['item_name'];
		$previews = array();
		foreach ( Box_Renderer::patterns() as $class => $label ) {
			$previews[ $class ] = $renderer->render( $item, $searches, false, $name, $class );
		}
		return $previews;
	}
	private function resolve( array $product, string $ec ): array {
		if ( '' !== trim( (string) ( $product['product_name'] ?? '' ) ) ) {
			return array( 'product' => $product, 'ec' => $ec, 'real' => true );
		}
		$db = $this->product_from_db();
		if ( array() !== $db ) {
			return array( 'product' => $db['product'], 'ec' => $db['ec'], 'real' => true );
		}
		return array( 'product' => array(), 'ec' => $ec, 'real' => false );
	}
	private function product_from_db(): array {
		global $wpdb;
		$row = $wpdb->get_row(
			'SELECT provider, product_name, item_name, item_price, affiliate_url, image_url FROM ' . Activator::table( 'candidates' )
			. " WHERE item_name <> '' ORDER BY updated_at DESC LIMIT 1",
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return array();
		}
		$name = trim( (string) ( $row['product_name'] ?? '' ) );
		if ( '' === $name ) {
			$name = trim( (string) ( $row['item_name'] ?? '' ) );
		}
		if ( '' === $name ) {
			return array();
		}
		return array(
			'product' => array(
				'product_name' => $name,
				'item'         => array(
					'item_name'     => $name,
					'item_price'    => (int) $row['item_price'],
					'affiliate_url' => (string) $row['affiliate_url'],
					'image_url'     => (string) $row['image_url'],
				),
			),
			'ec'      => (string) $row['provider'],
		);
	}
	private function sample_searches( string $ec = '' ): array {
		$base     = '' !== $ec ? $ec : 'rakuten';
		$slugs    = array( 'rakuten', 'yahoo', 'amazon' );
		$order    = array_merge( array( $base ), array_values( array_diff( $slugs, array( $base ) ) ) );
		$searches = array();
		foreach ( $order as $slug ) {
			$searches[] = array(
				'slug'  => $slug,
				'label' => $this->provider_label( $slug ),
				'url'   => $this->sample_search_url(),
				'icon'  => Providers::icon( $slug ),
			);
		}
		return $searches;
	}
	private function provider_label( string $slug ): string {
		$labels = array(
			'rakuten' => __( '楽天市場', GACHIREAI_TEXT_DOMAIN ),
			'yahoo'   => __( 'Yahoo!ショッピング', GACHIREAI_TEXT_DOMAIN ),
			'amazon'  => __( 'Amazon', GACHIREAI_TEXT_DOMAIN ),
		);
		return $labels[ $slug ] ?? $labels['rakuten'];
	}
	private function sample_item( array $product = array() ): array {
		$item = (array) ( $product['item'] ?? array() );
		$name = trim( (string) ( $product['product_name'] ?? '' ) );
		if ( '' === $name ) {
			$name = trim( (string) ( $item['item_name'] ?? '' ) );
		}
		if ( '' !== $name ) {
			$image = trim( (string) ( $item['image_url'] ?? '' ) );
			return array(
				'item_name'        => $name,
				'item_price'       => (int) ( $item['item_price'] ?? 0 ),
				'affiliate_url'    => (string) ( $item['affiliate_url'] ?? '' ),
				'item_url'         => (string) ( $item['item_url'] ?? '' ),
				'image_url'        => '' !== $image ? $image : GACHIREAI_PLUGIN_URL . 'assets/img/sample-product.svg',
				'price_fetched_at' => current_time( 'mysql' ),
			);
		}
		return array(
			'item_name'        => __( 'サンプル商品 ワイヤレスノイズキャンセリングイヤホン XYZ-1000', GACHIREAI_TEXT_DOMAIN ),
			'item_price'       => 12980,
			'affiliate_url'    => 'https://example.com/sample-item',
			'item_url'         => 'https://example.com/sample-item',
			'image_url'        => GACHIREAI_PLUGIN_URL . 'assets/img/sample-product.svg',
			'price_fetched_at' => current_time( 'mysql' ),
		);
	}
	private function sample_search_url(): string {
		return 'https://example.com/sample-search';
	}
}
