<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Affiliate;
use Tonkatsutei\Gachi_Review_AI\Activator;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
final class Price_Refresher {
	private const TTL = 86400;
	private const RETRY_TTL = 3600;
	private const LOCK_TTL = 60;
	private const ITEM_CODE_MAX = 100;
	private const STATUS_MAX = 32;
	private const MESSAGE_MAX = 300;
	private const STATUS_INITIAL = 'initial';
	private const STATUS_MATCHED = 'matched';
	private const STATUS_NO_PRICE = 'no_price';
	private const STATUS_UNSUPPORTED = 'unsupported_ec';
	private static array $memo = array();
	private static ?bool $table_ready = null;
	public function resolve( string $provider, array $item, array $settings ): array {
		$provider  = strtolower( trim( $provider ) );
		$item_code = $this->item_code( $item );
		$fallback  = $this->fallback( $item );
		if ( Product_Selector::EC_AMAZON === $provider || ! in_array( $provider, array( Product_Selector::EC_RAKUTEN, Product_Selector::EC_YAHOO ), true ) || '' === $item_code ) {
			return $fallback;
		}
		$memo_key = $provider . '|' . $item_code;
		if ( isset( self::$memo[ $memo_key ] ) ) {
			return self::$memo[ $memo_key ];
		}
		if ( ! $this->table_ready() ) {
			return self::$memo[ $memo_key ] = $fallback;
		}
		$row = $this->find( $provider, $item_code );
		if ( array() !== $row ) {
			$status = (string) ( $row['status'] ?? '' );
			$window = in_array( $status, array( self::STATUS_INITIAL, self::STATUS_MATCHED ), true )
				? self::TTL
				: self::RETRY_TTL;
			$cached = $this->from_row( $row, $fallback );
			if ( $this->is_fresh( (string) ( $row['checked_at'] ?? '' ), $window ) ) {
				return self::$memo[ $memo_key ] = $cached;
			}
			return self::$memo[ $memo_key ] = $this->refresh( $provider, $item_code, $settings, $row, $cached );
		}
		if ( '' !== $fallback['price_fetched_at'] && $this->is_fresh( (string) $fallback['price_fetched_at'], self::TTL ) ) {
			return self::$memo[ $memo_key ] = $fallback;
		}
		return self::$memo[ $memo_key ] = $this->refresh( $provider, $item_code, $settings, array(), $fallback );
	}
	private function refresh( string $provider, string $item_code, array $settings, array $row, array $fallback ): array {
		$lock_key = 'gachireai_price_lock_' . md5( $provider . '|' . $item_code );
		if ( false !== get_transient( $lock_key ) ) {
			return $fallback;
		}
		set_transient( $lock_key, 1, self::LOCK_TTL );
		try {
			try {
				$result = $this->lookup( $provider, $item_code, $settings );
			} catch ( \Throwable $e ) {
				$result = array(
					'status'  => 'api_error',
					'item'    => array(),
					'message' => $this->clip( $e->getMessage() ),
				);
			}
			$status  = $this->clip( (string) ( $result['status'] ?? '' ), self::STATUS_MAX );
			$price   = max( 0, (int) ( $result['item']['item_price'] ?? 0 ) );
			$message = $this->clip( (string) ( $result['message'] ?? '' ) );
			$now     = current_time( 'mysql' );
			if ( self::STATUS_MATCHED === $status && 0 < $price ) {
				$this->save( $row, $provider, $item_code, $price, $status, '', $now, $now );
				return array(
					'item_price'       => $price,
					'price_fetched_at' => $now,
					'refreshed'        => true,
					'message'          => '',
				);
			}
			if ( self::STATUS_MATCHED === $status ) {
				$status = self::STATUS_NO_PRICE;
			}
			$this->save(
				$row,
				$provider,
				$item_code,
				(int) $fallback['item_price'],
				$status,
				$message,
				(string) $fallback['price_fetched_at'],
				$now
			);
			return array(
				'item_price'       => (int) $fallback['item_price'],
				'price_fetched_at' => (string) $fallback['price_fetched_at'],
				'refreshed'        => false,
				'message'          => $message,
			);
		} finally {
			delete_transient( $lock_key );
		}
	}
	private function lookup( string $provider, string $item_code, array $settings ): array {
		if ( Product_Selector::EC_RAKUTEN === $provider ) {
			return ( new Rakuten_Client() )->lookup( $item_code, $settings );
		}
		if ( Product_Selector::EC_YAHOO === $provider ) {
			return ( new Yahoo_Client() )->lookup( $item_code, $settings );
		}
		return array(
			'status'  => self::STATUS_UNSUPPORTED,
			'item'    => array(),
			'message' => '',
		);
	}
	private function save( array $row, string $ec, string $item_code, int $price, string $status, string $message, string $fetched_at, string $checked_at ): void {
		global $wpdb;
		$table   = Activator::table( 'prices' );
		$price   = max( 0, $price );
		$status  = $this->clip( $status, self::STATUS_MAX );
		$message = $this->clip( $message );
		if ( array() !== $row && isset( $row['id'] ) ) {
			$fetched = '' !== $fetched_at ? '%s' : 'NULL';
			$sql     = "UPDATE {$table} SET item_price = %d, status = %s, message = %s, fetched_at = {$fetched}, checked_at = %s WHERE id = %d";
			$args    = array( $price, $status, $message );
			if ( '' !== $fetched_at ) {
				$args[] = $fetched_at;
			}
			$args[] = $checked_at;
			$args[] = (int) $row['id'];
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
			return;
		}
		$fetched = '' !== $fetched_at ? '%s' : 'NULL';
		$sql     = "INSERT INTO {$table} (ec, item_code, item_price, status, message, fetched_at, checked_at) VALUES (%s, %s, %d, %s, %s, {$fetched}, %s)";
		$args    = array( $ec, $item_code, $price, $status, $message );
		if ( '' !== $fetched_at ) {
			$args[] = $fetched_at;
		}
		$args[] = $checked_at;
		$wpdb->query( $wpdb->prepare( $sql, $args ) );
	}
	private function find( string $ec, string $item_code ): array {
		global $wpdb;
		$table = Activator::table( 'prices' );
		$sql   = "SELECT id, ec, item_code, item_price, status, message, fetched_at, checked_at FROM {$table} WHERE ec = %s AND item_code = %s LIMIT 1";
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $ec, $item_code ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}
	private function table_ready(): bool {
		if ( null !== self::$table_ready ) {
			return self::$table_ready;
		}
		global $wpdb;
		$table = Activator::table( 'prices' );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		self::$table_ready = ( $found === $table );
		return self::$table_ready;
	}
	private function from_row( array $row, array $fallback ): array {
		$price   = max( 0, (int) ( $row['item_price'] ?? 0 ) );
		$fetched = trim( (string) ( $row['fetched_at'] ?? '' ) );
		return array(
			'item_price'       => 0 < $price ? $price : (int) $fallback['item_price'],
			'price_fetched_at' => '' !== $fetched ? $fetched : (string) $fallback['price_fetched_at'],
			'refreshed'        => false,
			'message'          => (string) ( $row['message'] ?? '' ),
		);
	}
	private function fallback( array $item ): array {
		return array(
			'item_price'       => max( 0, (int) ( $item['item_price'] ?? 0 ) ),
			'price_fetched_at' => trim( (string) ( $item['price_fetched_at'] ?? '' ) ),
			'refreshed'        => false,
			'message'          => '',
		);
	}
	private function is_fresh( string $datetime, int $window ): bool {
		if ( '' === $datetime ) {
			return false;
		}
		$timestamp = strtotime( $datetime );
		$now       = strtotime( current_time( 'mysql' ) );
		if ( false === $timestamp || false === $now ) {
			return false;
		}
		return ( $timestamp + $window ) > $now;
	}
	private function item_code( array $item ): string {
		return $this->clip( (string) ( $item['item_code'] ?? '' ), self::ITEM_CODE_MAX );
	}
	private function clip( string $value, int $limit = self::MESSAGE_MAX ): string {
		return mb_substr( trim( $value ), 0, $limit );
	}
}
