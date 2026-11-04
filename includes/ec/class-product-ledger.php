<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Ec;
use Tonkatsutei\Gachi_Review_AI\Activator;
use Tonkatsutei\Gachi_Review_AI\Article\Reuse_Interval;
use Tonkatsutei\Gachi_Review_AI\Article\Term_Assigner;
final class Product_Ledger {
	public const STATUS_SELECTED = 'selected';
	public const STATUS_POSTED = 'posted';
	public const STATUS_SKIPPED = 'skipped';
	private const KEYS = array( 'jan_code', 'model_number', 'name_key' );
	private const COLUMNS = 'id, ec, item_code, jan_code, name_key, product_name, brand, model_number,'
		. ' item_price, item_url, image_url, keyword, genre_id, status, first_seen_at, last_posted_at, post_id, excluded';
	private const ITEM_CODE_MAX = 100;
	private const JAN_CODE_MAX = 32;
	private const NAME_KEY_MAX = 191;
	private const BRAND_MAX = 191;
	private const MODEL_MAX = 191;
	private const KEYWORD_MAX = 100;
	private const GENRE_ID_MAX = 32;
	public function find( string $jan_code = '', string $model_number = '', string $name_key = '' ): array {
		global $wpdb;
		$values = array(
			'jan_code'     => $this->clip( $jan_code, self::JAN_CODE_MAX ),
			'model_number' => $this->clip( $model_number, self::MODEL_MAX ),
			'name_key'     => $this->clip( $name_key, self::NAME_KEY_MAX ),
		);
		foreach ( self::KEYS as $key ) {
			$value = $values[ $key ];
			if ( '' === $value ) {
				continue;
			}
			$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . Activator::table( 'products' )
				. " WHERE {$key} = %s AND {$key} <> '' ORDER BY id ASC LIMIT 1";
			$row = $wpdb->get_row( $wpdb->prepare( $sql, $value ), ARRAY_A );
			if ( is_array( $row ) ) {
				return array(
					'row'     => $this->row_of( $row ),
					'matched' => $key,
				);
			}
		}
		return array();
	}
	public function find_by_item_code( string $ec, string $item_code ): array {
		global $wpdb;
		$ec        = Product_Selector::normalize_ec( $ec );
		$item_code = $this->clip( $item_code, self::ITEM_CODE_MAX );
		if ( '' === $item_code ) {
			return array();
		}
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . Activator::table( 'products' )
			. " WHERE ec = %s AND item_code = %s AND item_code <> '' ORDER BY id ASC LIMIT 1";
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $ec, $item_code ), ARRAY_A );
		return is_array( $row ) ? $this->row_of( $row ) : array();
	}
	public function next_postable( string $ec ): array {
		global $wpdb;
		$sql    = 'SELECT ' . self::COLUMNS . ' FROM ' . Activator::table( 'products' )
			. ' WHERE ec = %s AND excluded = 0 AND status <> %s';
		$params = array( Product_Selector::normalize_ec( $ec ), self::STATUS_SKIPPED );
		if ( Reuse_Interval::is_permanent() ) {
			$sql .= ' AND last_posted_at IS NULL';
		} elseif ( ! Reuse_Interval::is_unlimited() ) {
			$sql     .= ' AND ( last_posted_at IS NULL OR last_posted_at < %s )';
			$params[] = $this->expiry();
		}
		$sql .= ' ORDER BY id ASC LIMIT 1';
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return is_array( $row ) ? $this->row_of( $row ) : array();
	}
	public function register( string $ec, array $product, string $keyword = '', string $genre_id = '' ): array {
		$ec   = Product_Selector::normalize_ec( $ec );
		$name = trim( (string) ( $product['product_name'] ?? '' ) );
		$key  = self::name_key( $name );
		if ( '' === $key ) {
			return array(
				'created' => false,
				'id'      => 0,
				'matched' => '',
			);
		}
		$item = is_array( $product['item'] ?? null ) ? (array) $product['item'] : array();
		$row = array(
			'ec'           => $ec,
			'item_code'    => $this->clip( (string) ( $item['item_code'] ?? '' ), self::ITEM_CODE_MAX ),
			'jan_code'     => $this->clip( (string) ( $product['jan_code'] ?? '' ), self::JAN_CODE_MAX ),
			'name_key'     => $key,
			'product_name' => $name,
			'brand'        => $this->clip( (string) ( $product['brand'] ?? '' ), self::BRAND_MAX ),
			'model_number' => $this->clip( (string) ( $product['model_number'] ?? '' ), self::MODEL_MAX ),
			'item_price'   => max( 0, (int) ( $item['item_price'] ?? 0 ) ),
			'item_url'     => (string) ( $item['item_url'] ?? '' ),
			'image_url'    => (string) ( $item['image_url'] ?? '' ),
			'keyword'      => $this->clip( $keyword, self::KEYWORD_MAX ),
			'genre_id'     => $this->clip( $genre_id, self::GENRE_ID_MAX ),
		);
		$found = $this->find( (string) $row['jan_code'], (string) $row['model_number'], (string) $row['name_key'] );
		if ( array() !== $found ) {
			$id = (int) ( $found['row']['id'] ?? 0 );
			$this->update( $id, $row );
			return array(
				'created' => false,
				'id'      => $id,
				'matched' => (string) $found['matched'],
			);
		}
		return array(
			'created' => true,
			'id'      => $this->insert( $row ),
			'matched' => '',
		);
	}
	public function mark_posted( int $id, int $post_id ): bool {
		global $wpdb;
		if ( $id <= 0 ) {
			return false;
		}
		$sql = 'UPDATE ' . Activator::table( 'products' )
			. ' SET last_posted_at = %s, post_id = %d, status = %s WHERE id = %d';
		return 0 < (int) $wpdb->query( $wpdb->prepare( $sql, array( $this->now(), max( 0, $post_id ), self::STATUS_POSTED, $id ) ) );
	}
	public function mark_skipped( int $id ): bool {
		global $wpdb;
		if ( $id <= 0 ) {
			return false;
		}
		$sql = 'UPDATE ' . Activator::table( 'products' ) . ' SET status = %s WHERE id = %d';
		return 0 < (int) $wpdb->query( $wpdb->prepare( $sql, array( self::STATUS_SKIPPED, $id ) ) );
	}
	public function mark_excluded( int $id ): bool {
		global $wpdb;
		if ( $id <= 0 ) {
			return false;
		}
		$sql = 'UPDATE ' . Activator::table( 'products' ) . ' SET excluded = 1 WHERE id = %d';
		return 0 < (int) $wpdb->query( $wpdb->prepare( $sql, array( $id ) ) );
	}
	public function is_excluded( array $row ): bool {
		return 1 === (int) ( $row['excluded'] ?? 0 );
	}
	public function can_post( array $row ): bool {
		$posted = trim( (string) ( $row['last_posted_at'] ?? '' ) );
		if ( '' === $posted ) {
			return true;
		}
		if ( Reuse_Interval::is_unlimited() ) {
			return true;
		}
		if ( Reuse_Interval::is_permanent() ) {
			return false;
		}
		return $posted < $this->expiry();
	}
	public function recent_genre_ids(): array {
		global $wpdb;
		if ( Reuse_Interval::is_unlimited() ) {
			return array();
		}
		$table = Activator::table( 'products' );
		if ( Reuse_Interval::is_permanent() ) {
			$sql = "SELECT DISTINCT genre_id FROM {$table} WHERE genre_id <> '' AND last_posted_at IS NOT NULL";
			$rows = $wpdb->get_col( $sql );
		} else {
			$sql = "SELECT DISTINCT genre_id FROM {$table} WHERE genre_id <> '' AND last_posted_at IS NOT NULL AND last_posted_at >= %s";
			$rows = $wpdb->get_col( $wpdb->prepare( $sql, $this->expiry() ) );
		}
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $rows ), 'strlen' ) );
	}
	public function counts( string $ec = '' ): array {
		global $wpdb;
		$sql = 'SELECT'
			. ' SUM(status = %s) AS selected,'
			. ' SUM(status = %s) AS posted,'
			. ' SUM(status = %s) AS skipped,'
			. ' COUNT(*) AS total'
			. ' FROM ' . Activator::table( 'products' );
		$params = array( self::STATUS_SELECTED, self::STATUS_POSTED, self::STATUS_SKIPPED );
		if ( '' !== trim( $ec ) ) {
			$sql     .= ' WHERE ec = %s';
			$params[] = Product_Selector::normalize_ec( $ec );
		}
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return array(
			'selected' => (int) ( $row['selected'] ?? 0 ),
			'posted'   => (int) ( $row['posted'] ?? 0 ),
			'skipped'  => (int) ( $row['skipped'] ?? 0 ),
			'total'    => (int) ( $row['total'] ?? 0 ),
		);
	}
	public static function name_key( string $name ): string {
		return mb_substr( Term_Assigner::normalize_name( $name ), 0, self::NAME_KEY_MAX );
	}
	private function insert( array $row ): int {
		global $wpdb;
		$sql = 'INSERT INTO ' . Activator::table( 'products' )
			. ' (ec, item_code, jan_code, name_key, product_name, brand, model_number,'
			. ' item_price, item_url, image_url, keyword, genre_id, status, first_seen_at, last_posted_at, post_id)'
			. ' VALUES (%s, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s, NULL, 0)';
		$params = array(
			(string) $row['ec'],
			(string) $row['item_code'],
			(string) $row['jan_code'],
			(string) $row['name_key'],
			(string) $row['product_name'],
			(string) $row['brand'],
			(string) $row['model_number'],
			(int) $row['item_price'],
			(string) $row['item_url'],
			(string) $row['image_url'],
			(string) $row['keyword'],
			(string) $row['genre_id'],
			self::STATUS_SELECTED,
			$this->now(),
		);
		$wpdb->query( $wpdb->prepare( $sql, $params ) );
		return (int) $wpdb->insert_id;
	}
	private function update( int $id, array $row ): void {
		global $wpdb;
		if ( $id <= 0 ) {
			return;
		}
		$sql = 'UPDATE ' . Activator::table( 'products' )
			. ' SET ec = %s, item_code = %s, jan_code = %s, name_key = %s, product_name = %s,'
			. ' brand = %s, model_number = %s, item_price = %d, item_url = %s, image_url = %s, keyword = %s, genre_id = %s'
			. ' WHERE id = %d';
		$params = array(
			(string) $row['ec'],
			(string) $row['item_code'],
			(string) $row['jan_code'],
			(string) $row['name_key'],
			(string) $row['product_name'],
			(string) $row['brand'],
			(string) $row['model_number'],
			(int) $row['item_price'],
			(string) $row['item_url'],
			(string) $row['image_url'],
			(string) $row['keyword'],
			(string) $row['genre_id'],
			$id,
		);
		$wpdb->query( $wpdb->prepare( $sql, $params ) );
	}
	private function row_of( array $row ): array {
		return array(
			'id'             => (int) ( $row['id'] ?? 0 ),
			'ec'             => (string) ( $row['ec'] ?? '' ),
			'item_code'      => (string) ( $row['item_code'] ?? '' ),
			'jan_code'       => (string) ( $row['jan_code'] ?? '' ),
			'name_key'       => (string) ( $row['name_key'] ?? '' ),
			'product_name'   => (string) ( $row['product_name'] ?? '' ),
			'brand'          => (string) ( $row['brand'] ?? '' ),
			'model_number'   => (string) ( $row['model_number'] ?? '' ),
			'item_price'     => (int) ( $row['item_price'] ?? 0 ),
			'item_url'       => (string) ( $row['item_url'] ?? '' ),
			'image_url'      => (string) ( $row['image_url'] ?? '' ),
			'keyword'        => (string) ( $row['keyword'] ?? '' ),
			'genre_id'       => (string) ( $row['genre_id'] ?? '' ),
			'status'         => (string) ( $row['status'] ?? '' ),
			'first_seen_at'  => (string) ( $row['first_seen_at'] ?? '' ),
			'last_posted_at' => null === ( $row['last_posted_at'] ?? null ) ? '' : (string) $row['last_posted_at'],
			'post_id'        => (int) ( $row['post_id'] ?? 0 ),
			'excluded'       => (int) ( $row['excluded'] ?? 0 ),
		);
	}
	private function now(): string {
		return current_datetime()->format( 'Y-m-d H:i:s' );
	}
	private function expiry(): string {
		return current_datetime()->modify( '-' . Reuse_Interval::days() . ' days' )->format( 'Y-m-d H:i:s' );
	}
	private function clip( string $value, int $max ): string {
		return mb_substr( trim( $value ), 0, $max );
	}
}
