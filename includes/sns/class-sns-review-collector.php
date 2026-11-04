<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Sns;
use Tonkatsutei\Gachi_Review_AI\Activator;
use Tonkatsutei\Gachi_Review_AI\Article\Source_Settings;
final class Sns_Review_Collector {
	private const SOURCE_CLASS = 'Get_SNS_Reviews_API';
	private const COMMUNITY_MAX = 64;
	private const AUTHOR_MAX = 64;
	private const EXTERNAL_MAX = 64;
	private const PRODUCT_MAX = 255;
	private const SNS_TIMEOUT = 90;
	private string $error = '';
	public static function is_available(): bool {
		return class_exists( self::SOURCE_CLASS ) && method_exists( self::SOURCE_CLASS, 'reviews' );
	}
	public function collect( array $product, string $sns ): array {
		$result = array(
			'posts'    => 0,
			'comments' => 0,
			'found'    => 0,
			'message'  => '',
			'searches' => array(),
			'sources'  => array( $sns => array() ),
			'errors'   => array(),
		);
		if ( ! self::is_available() || ! Source_Settings::is_sns( $sns ) ) {
			return $result;
		}
		$keyword = trim( (string) ( $product['product_name'] ?? '' ) );
		if ( '' === $keyword ) {
			$result['errors'][] = __( '商品名が空のため、SNS の投稿を取得できません。先に「即時商品選定」を実行してください。', GACHIREAI_TEXT_DOMAIN );
			return $result;
		}
		$result['sources'][ $sns ] = array( $keyword );
		Source_Settings::delete_stale( $sns );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 );
		}
		$posts = $this->fetch( $sns, $keyword );
		if ( array() === $posts ) {
			$error = $this->last_error();
			if ( '' !== $error ) {
				$result['errors'][] = $error;
			} else {
				$result['message'] = sprintf(
					__( '%s で該当する投稿が見つかりませんでした（該当が無いか、取得元が一時的に応答していない可能性があります。少し時間をおいてもう一度お試しください）。', GACHIREAI_TEXT_DOMAIN ),
					Source_Settings::source_label( $sns )
				);
			}
			return $result;
		}
		$result['found'] = count( $posts );
		foreach ( $posts as $post ) {
			if ( ! is_array( $post ) ) {
				continue;
			}
			if ( $this->save_post( $sns, $keyword, $post ) ) {
				++$result['posts'];
			}
		}
		if ( 0 === $result['posts'] ) {
			$result['message'] = sprintf(
				__( '取得した投稿 %d 件はすべて保存済みでした（新規に保存したものはありません）。', GACHIREAI_TEXT_DOMAIN ),
				$result['found']
			);
		}
		return $result;
	}
	private function fetch( string $sns, string $keyword ): array {
		$class = self::SOURCE_CLASS;
		$deadline = microtime( true ) + self::SNS_TIMEOUT;
		$filter   = static function ( $pre, $args, $url ) use ( $deadline ) {
			if ( false !== $pre || microtime( true ) < $deadline ) {
				return $pre;
			}
			if ( ! self::is_sns_endpoint( (string) $url ) ) {
				return $pre;
			}
			return new \WP_Error(
				'gachireai_sns_timeout',
				sprintf(
					__( 'SNS の取得が %d 秒以内に終わりませんでした（タイムアウト）。この取得元はスキップします。', GACHIREAI_TEXT_DOMAIN ),
					self::SNS_TIMEOUT
				)
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );
		try {
			$posts = $class::reviews( $sns, $keyword );
		} catch ( \Throwable $e ) {
			$this->error = $e->getMessage();
			$posts       = array();
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
		}
		if ( array() === $posts && '' === $this->error && microtime( true ) >= $deadline ) {
			$this->error = sprintf(
				__( 'SNS の取得が %d 秒以内に終わりませんでした（タイムアウト）。次の取得元へ進みます。', GACHIREAI_TEXT_DOMAIN ),
				self::SNS_TIMEOUT
			);
		}
		return is_array( $posts ) ? $posts : array();
	}
	private static function is_sns_endpoint( string $url ): bool {
		foreach ( array( 'api.apify.com', 'api.brightdata.com', 'api.zenrows.com', 'api.webscrapingapi.com', 'ensembledata.com', 'scrape.do' ) as $host ) {
			if ( false !== strpos( $url, $host ) ) {
				return true;
			}
		}
		return false;
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
	private function save_post( string $sns, string $keyword, array $post ): bool {
		$text = trim( (string) ( $post['review_text'] ?? '' ) );
		if ( '' === $text ) {
			return false;
		}
		$external_id = $this->external_id( $sns, $post );
		if ( '' === $external_id ) {
			return false;
		}
		if ( $this->comment_exists( $sns, $external_id ) ) {
			return false;
		}
		$thread_id = $this->thread_id( $sns, $external_id );
		if ( $thread_id <= 0 ) {
			$thread_id = $this->save_thread( $sns, $keyword, $post, $external_id );
		}
		if ( $thread_id <= 0 ) {
			return false;
		}
		return $this->save_comment( $sns, $thread_id, $external_id, $post, $text );
	}
	private function external_id( string $sns, array $post ): string {
		$id = trim( (string) ( $post['review_id'] ?? '' ) );
		if ( '' !== $id ) {
			return mb_substr( $sns . ':' . $id, 0, self::EXTERNAL_MAX );
		}
		$author = trim( (string) ( $post['author_name'] ?? '' ) );
		$text   = trim( (string) ( $post['review_text'] ?? '' ) );
		return 'h-' . sha1( $sns . '|' . $author . '|' . $text );
	}
	private function save_thread( string $sns, string $keyword, array $post, string $external_id ): int {
		global $wpdb;
		$url = trim( (string) ( $post['url'] ?? '' ) );
		$inserted = $wpdb->insert(
			Activator::table( 'threads' ),
			array(
				'source'       => $sns,
				'external_id'  => $external_id,
				'community'    => mb_substr( Source_Settings::source_label( $sns ), 0, self::COMMUNITY_MAX ),
				'title'        => trim( (string) ( $post['review_header'] ?? '' ) ),
				'product_name' => mb_substr( $keyword, 0, self::PRODUCT_MAX ),
				'url'          => $url,
				'permalink'    => $url,
				'score'        => $this->helpful( $post ),
				'num_comments' => 0,
				'created_utc'  => $this->posted_at( $post ),
				'collected_at' => (string) current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);
		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}
	private function save_comment( string $sns, int $thread_id, string $external_id, array $post, string $text ): bool {
		global $wpdb;
		$url = trim( (string) ( $post['url'] ?? '' ) );
		$inserted = $wpdb->insert(
			Activator::table( 'comments' ),
			array(
				'source'      => $sns,
				'external_id' => $external_id,
				'thread_id'   => $thread_id,
				'body'        => $text,
				'score'       => $this->helpful( $post ),
				'author'      => mb_substr( trim( (string) ( $post['author_name'] ?? '' ) ), 0, self::AUTHOR_MAX ),
				'permalink'   => $url,
				'created_utc' => $this->posted_at( $post ),
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d' )
		);
		return false !== $inserted;
	}
	private function helpful( array $post ): int {
		return max( 0, (int) ( $post['helpful_count'] ?? 0 ) );
	}
	private function posted_at( array $post ): int {
		$date = trim( (string) ( $post['review_posted_date'] ?? '' ) );
		if ( '' === $date ) {
			return 0;
		}
		$time = strtotime( $date );
		return false === $time ? 0 : max( 0, $time );
	}
	private function thread_id( string $sns, string $external_id ): int {
		global $wpdb;
		$table = Activator::table( 'threads' );
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source = %s AND external_id = %s", $sns, $external_id ) );
		return null === $id ? 0 : (int) $id;
	}
	private function comment_exists( string $sns, string $external_id ): bool {
		global $wpdb;
		$table = Activator::table( 'comments' );
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source = %s AND external_id = %s", $sns, $external_id ) );
		return null !== $id;
	}
}
