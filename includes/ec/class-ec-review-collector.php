<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Ec;
use Tonkatsutei\Gachi_Review_AI\Activator;
use Tonkatsutei\Gachi_Review_AI\Article\Source_Settings;
final class Ec_Review_Collector {
	public const SOURCE = 'ec';
	private const SOURCE_CLASS = 'Get_Ec_Reviews_API';
	private const MAX_REVIEWS = 20;
	private const COMMUNITY_MAX = 64;
	private const AUTHOR_MAX = 64;
	private const EXTERNAL_MAX = 64;
	private const PRODUCT_MAX = 255;
	private string $error = '';
	public static function is_available(): bool {
		return class_exists( self::SOURCE_CLASS ) && method_exists( self::SOURCE_CLASS, 'reviews' );
	}
	public function collect( array $product, string $site ): array {
		$result = array(
			'posts'    => 0,
			'comments' => 0,
			'found'    => 0,
			'message'  => '',
			'rating'   => array(
				'count'   => 0,
				'average' => null,
			),
			'searches' => array(),
			'sources'  => array( self::SOURCE => array() ),
			'errors'   => array(),
		);
		if ( ! self::is_available() ) {
			return $result;
		}
		$site = Product_Selector::normalize_ec( $site );
		$item = (array) ( $product['item'] ?? array() );
		$code = trim( (string) ( $item['item_code'] ?? '' ) );
		$product_name = trim( (string) ( $product['product_name'] ?? '' ) );
		if ( '' === $code ) {
			$result['errors'][] = __( '選定した商品に EC の商品コードが無いため、メインEC のレビューを取得できません。', GACHIREAI_TEXT_DOMAIN );
			return $result;
		}
		$label = Product_Selector::ec_label( $site );
		$result['sources'][ self::SOURCE ] = array( $label );
		Source_Settings::delete_stale( self::SOURCE );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 );
		}
		$reviews = $this->fetch( $site, $code );
		if ( array() === $reviews ) {
			$error = $this->last_error();
			if ( '' !== $error ) {
				$result['errors'][] = $error;
			} else {
				$result['message'] = __( 'メインEC のレビューが 0 件でした（該当レビューが無いか、取得元が一時的に応答していない可能性があります。少し時間をおいてもう一度お試しください）。', GACHIREAI_TEXT_DOMAIN );
			}
			return $result;
		}
		$result['found'] = count( $reviews );
		$rating_sum   = 0.0;
		$rating_count = 0;
		$empty        = 0;
		foreach ( $reviews as $review ) {
			if ( ! is_array( $review ) ) {
				continue;
			}
			if ( '' === trim( (string) ( $review['review_text'] ?? '' ) ) ) {
				++$empty;
				continue;
			}
			if ( $this->save_review( $site, $code, $label, $product_name, $review ) ) {
				++$result['posts'];
				$saved_rating = $this->rating( $review );
				if ( null !== $saved_rating ) {
					$rating_sum += $saved_rating;
					++$rating_count;
				}
			}
		}
		$result['rating'] = array(
			'count'   => $rating_count,
			'average' => 0 < $rating_count ? round( $rating_sum / $rating_count, 2 ) : null,
		);
		if ( 0 === $result['posts'] ) {
			if ( 0 < $empty && $empty === $result['found'] ) {
				$result['errors'][] = __( 'メインEC の取得元が本文の無い（空の）結果を返しました。レビューが無いか、取得元が一時的に応答していない可能性があります。少し時間をおいてもう一度お試しください。', GACHIREAI_TEXT_DOMAIN );
			} else {
				$result['message'] = sprintf(
					__( '取得したレビュー %d 件はすべて保存済みでした（新規に保存したものはありません）。', GACHIREAI_TEXT_DOMAIN ),
					$result['found']
				);
			}
		}
		return $result;
	}
	private function fetch( string $site, string $code ): array {
		$class = self::SOURCE_CLASS;
		try {
			$reviews = $class::reviews( $site, $code, array( 'max_reviews' => self::MAX_REVIEWS ) );
		} catch ( \Throwable $e ) {
			$this->error = $e->getMessage();
			return array();
		}
		return is_array( $reviews ) ? $reviews : array();
	}
	private function last_error(): string {
		if ( '' !== $this->error ) {
			return $this->error;
		}
		$class = self::SOURCE_CLASS;
		if ( method_exists( $class, 'last_reviews_error' ) ) {
			return trim( (string) $class::last_reviews_error() );
		}
		return '';
	}
	private function save_review( string $site, string $code, string $label, string $product_name, array $review ): bool {
		$text = trim( (string) ( $review['review_text'] ?? '' ) );
		if ( '' === $text ) {
			return false;
		}
		$external_id = $this->external_id( $site, $code, $review );
		if ( '' === $external_id ) {
			return false;
		}
		if ( $this->comment_exists( $external_id ) ) {
			return false;
		}
		$thread_id = $this->thread_id( $external_id );
		if ( $thread_id <= 0 ) {
			$thread_id = $this->save_thread( $label, $product_name, $review, $external_id );
		}
		if ( $thread_id <= 0 ) {
			return false;
		}
		return $this->save_comment( $thread_id, $external_id, $review, $text );
	}
	private function external_id( string $site, string $code, array $review ): string {
		$id = trim( (string) ( $review['review_id'] ?? '' ) );
		if ( '' !== $id ) {
			return mb_substr( $site . ':' . $id, 0, self::EXTERNAL_MAX );
		}
		$author = trim( (string) ( $review['author_name'] ?? '' ) );
		$text   = trim( (string) ( $review['review_text'] ?? '' ) );
		return 'h-' . sha1( $site . '|' . $code . '|' . $author . '|' . $text );
	}
	public static function stored_product_name( string $product_name ): string {
		return mb_substr( trim( $product_name ), 0, self::PRODUCT_MAX );
	}
	private function save_thread( string $label, string $product_name, array $review, string $external_id ): int {
		global $wpdb;
		$url = trim( (string) ( $review['url'] ?? '' ) );
		$inserted = $wpdb->insert(
			Activator::table( 'threads' ),
			array(
				'source'       => self::SOURCE,
				'external_id'  => $external_id,
				'community'    => mb_substr( $label, 0, self::COMMUNITY_MAX ),
				'title'        => trim( (string) ( $review['review_header'] ?? '' ) ),
				'product_name' => self::stored_product_name( $product_name ),
				'url'          => $url,
				'permalink'    => $url,
				'score'        => $this->helpful( $review ),
				'rating'       => $this->rating( $review ),
				'num_comments' => 0,
				'created_utc'  => $this->posted_at( $review ),
				'collected_at' => (string) current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%d', '%d', '%s' )
		);
		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}
	private function save_comment( int $thread_id, string $external_id, array $review, string $text ): bool {
		global $wpdb;
		$url = trim( (string) ( $review['url'] ?? '' ) );
		$inserted = $wpdb->insert(
			Activator::table( 'comments' ),
			array(
				'source'      => self::SOURCE,
				'external_id' => $external_id,
				'thread_id'   => $thread_id,
				'body'        => $text,
				'score'       => $this->helpful( $review ),
				'author'      => mb_substr( trim( (string) ( $review['author_name'] ?? '' ) ), 0, self::AUTHOR_MAX ),
				'permalink'   => $url,
				'created_utc' => $this->posted_at( $review ),
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d' )
		);
		return false !== $inserted;
	}
	private function helpful( array $review ): int {
		return max( 0, (int) ( $review['helpful_count'] ?? 0 ) );
	}
	private function rating( array $review ): ?float {
		$value = $review['rating'] ?? null;
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$rating = (float) $value;
		return ( $rating > 0.0 && $rating <= 5.0 ) ? round( $rating, 2 ) : null;
	}
	private function posted_at( array $review ): int {
		$date = trim( (string) ( $review['review_posted_date'] ?? '' ) );
		if ( '' === $date ) {
			return 0;
		}
		$time = strtotime( $date );
		return false === $time ? 0 : max( 0, $time );
	}
	private function thread_id( string $external_id ): int {
		global $wpdb;
		$table = Activator::table( 'threads' );
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source = %s AND external_id = %s", self::SOURCE, $external_id ) );
		return null === $id ? 0 : (int) $id;
	}
	private function comment_exists( string $external_id ): bool {
		global $wpdb;
		$table = Activator::table( 'comments' );
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source = %s AND external_id = %s", self::SOURCE, $external_id ) );
		return null !== $id;
	}
}
