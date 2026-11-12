<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\Event_Logger;
use Tonkatsutei\Gachi_Review_AI\Image\Eyecatch;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Auto_Post_Pipeline {
	public const OPTION = 'gachireai_auto_pipeline';
	public const STEP_SELECT = 'select';
	public const STEP_COLLECT = 'collect';
	public const STEP_GENERATE = 'generate';
	public const STEP_EYECATCH = 'eyecatch';
	public const STEP_READY = 'ready';
	public const STEP_FAILED = 'failed';
	private const MAX_ATTEMPTS = 3;
	private const FAILED_COOLDOWN = 3600;
	private const STALE_SECONDS = 86400;
	private Settings $settings;
	private $select_callable;
	private $collect_callable;
	private $generate_callable;
	private $eyecatch_callable;
	public function __construct(
		?Settings $settings = null,
		?callable $select = null,
		?callable $collect = null,
		?callable $generate = null,
		?callable $eyecatch = null
	) {
		$this->settings          = $settings ?? new Settings();
		$this->select_callable   = $select;
		$this->collect_callable  = $collect;
		$this->generate_callable = $generate;
		$this->eyecatch_callable = $eyecatch;
	}
	public static function clear(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::OPTION );
		}
	}
	public function mark_published(): void {
		self::clear();
	}
	public function state(): array {
		$raw = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		if ( ! is_array( $raw ) || array() === $raw ) {
			return array();
		}
		$state = $this->normalize( $raw );
		if ( array() === $state ) {
			self::clear();
			return array();
		}
		$now = time();
		if ( 0 < (int) $state['updated_ts'] && $now - (int) $state['updated_ts'] > self::STALE_SECONDS ) {
			Event_Logger::record(
				Event_Logger::TYPE_GENERATE,
				Event_Logger::LEVEL_WARN,
				__( '自動投稿の準備が長時間停止していたため、状態を破棄して次の準備からやり直します。', GACHIREAI_TEXT_DOMAIN ),
				array( 'updated_ts' => (int) $state['updated_ts'] )
			);
			self::clear();
			return array();
		}
		if ( self::STEP_FAILED === $state['step'] && $now >= (int) $state['failed_until'] ) {
			self::clear();
			return array();
		}
		return $state;
	}
	public function is_empty(): bool {
		return array() === $this->state();
	}
	public function is_ready(): bool {
		$state = $this->state();
		return array() !== $state && self::STEP_READY === $state['step'];
	}
	public function post_id(): int {
		$state = $this->state();
		return (int) ( $state['post_id'] ?? 0 );
	}
	public function post_title(): string {
		$state = $this->state();
		return (string) ( $state['post_title'] ?? '' );
	}
	public function start( int $author_id ): void {
		$this->save(
			array(
				'step'         => self::STEP_SELECT,
				'product'      => array(),
				'ec'           => '',
				'query'        => '',
				'genre_id'     => '',
				'pending'      => array(),
				'attempt'      => 1,
				'collected'    => self::empty_collected(),
				'post_id'      => 0,
				'post_title'   => '',
				'author_id'    => max( 0, $author_id ),
				'failed_until' => 0,
				'last_error'   => '',
			)
		);
	}
	public function advance(): void {
		$state = $this->state();
		if ( array() === $state ) {
			return;
		}
		try {
			switch ( $state['step'] ) {
				case self::STEP_SELECT:
					$this->step_select( $state );
					break;
				case self::STEP_COLLECT:
					$this->step_collect( $state );
					break;
				case self::STEP_GENERATE:
					$this->step_generate( $state );
					break;
				case self::STEP_EYECATCH:
					$this->step_eyecatch( $state );
					break;
				case self::STEP_READY:
				case self::STEP_FAILED:
				default:
					break;
			}
		} catch ( \Throwable $e ) {
			Event_Logger::record(
				Event_Logger::TYPE_GENERATE,
				Event_Logger::LEVEL_ERROR,
				sprintf(
					__( '自動投稿の準備で想定外のエラーが発生しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$e->getMessage()
				),
				array( 'step' => (string) $state['step'] )
			);
		}
	}
	private function step_select( array $state ): void {
		$started = microtime( true );
		try {
			$selection = $this->select();
		} catch ( \Throwable $e ) {
			$this->fail_step( $state, $e->getMessage(), true, Event_Logger::TYPE_SELECT );
			return;
		}
		$status = (string) ( $selection['status'] ?? '' );
		if ( Product_Selector::STATUS_SELECTED !== $status ) {
			$message = trim( (string) ( $selection['message'] ?? '' ) );
			if ( '' === $message ) {
				$message = __( '商品を選定できませんでした。', GACHIREAI_TEXT_DOMAIN );
			}
			$retryable = Product_Selector::STATUS_API_ERROR === $status;
			$this->fail_step(
				$state,
				$message,
				$retryable,
				Event_Logger::TYPE_SELECT,
				array(
					'status' => $status,
					'reason' => (string) ( $selection['reason'] ?? '' ),
				),
				self::elapsed_ms( $started )
			);
			return;
		}
		$product = is_array( $selection['product'] ?? null ) ? (array) $selection['product'] : array();
		$state['product']   = $product;
		$state['ec']        = (string) ( $selection['ec'] ?? '' );
		$state['query']     = (string) ( $selection['query'] ?? '' );
		$state['genre_id']  = (string) ( $selection['genre_id'] ?? '' );
		$state['attempt']   = 1;
		$state['collected'] = self::empty_collected();
		$skip_collect = Ai_Review::is_only();
		$pending      = $skip_collect ? array() : Source_Settings::collectable( self::all_sources() );
		if ( ! $skip_collect && array() === $pending ) {
			$this->fail_step(
				$state,
				__( '「レビュー設定」タブでレビューを取得する取得元のスイッチが 1 つも ON になっていません。取得したい取得元を ON にしてください。', GACHIREAI_TEXT_DOMAIN ),
				false,
				Event_Logger::TYPE_COLLECT,
				array( 'ec' => $state['ec'] ),
				self::elapsed_ms( $started )
			);
			return;
		}
		if ( ! empty( $product['is_generic'] ) && '' === trim( (string) ( $product['jan_code'] ?? '' ) ) ) {
			$pending = array_values( array_diff( $pending, array( 'reddit', 'fivech' ) ) );
		}
		$state['pending'] = $pending;
		$state['step']    = ( $skip_collect || array() === $pending ) ? self::STEP_GENERATE : self::STEP_COLLECT;
		$this->save( $state );
		Event_Logger::record(
			Event_Logger::TYPE_SELECT,
			Event_Logger::LEVEL_INFO,
			sprintf(
				__( '商品を選定しました: %1$s（レビュー取得元 %2$d 件）', GACHIREAI_TEXT_DOMAIN ),
				(string) ( $product['product_name'] ?? '' ),
				count( $pending )
			),
			array(
				'ec'           => (string) $state['ec'],
				'query'        => (string) $state['query'],
				'product_name' => (string) ( $product['product_name'] ?? '' ),
				'item_code'    => (string) ( ( (array) ( $product['item'] ?? array() ) )['item_code'] ?? '' ),
				'sources'      => $pending,
			),
			0,
			self::elapsed_ms( $started )
		);
	}
	private function step_collect( array $state ): void {
		while ( array() !== $state['pending'] && ! Source_Settings::can_collect( (string) $state['pending'][0] ) ) {
			array_shift( $state['pending'] );
		}
		if ( array() === $state['pending'] ) {
			$state['step']    = self::STEP_GENERATE;
			$state['attempt'] = 1;
			$this->save( $state );
			return;
		}
		$source  = (string) $state['pending'][0];
		$label   = Source_Settings::source_label( $source );
		$started = microtime( true );
		try {
			$one = $this->collect( (array) $state['product'], $source, (string) $state['ec'] );
		} catch ( \Throwable $e ) {
			$this->fail_step( $state, $e->getMessage(), true, Event_Logger::TYPE_COLLECT, array( 'source' => $source ) );
			return;
		}
		if ( ! empty( $one['skipped'] ) ) {
			$this->fail_step(
				$state,
				(string) ( $one['message'] ?? '' ),
				false,
				Event_Logger::TYPE_COLLECT,
				array( 'source' => $source, 'skipped' => true ),
				self::elapsed_ms( $started )
			);
			return;
		}
		$state['collected'] = self::merge_collected( (array) $state['collected'], $one );
		array_shift( $state['pending'] );
		$state['attempt'] = 1;
		if ( array() === $state['pending'] ) {
			$state['step'] = self::STEP_GENERATE;
		}
		$this->save( $state );
		$posts    = (int) ( $one['posts'] ?? 0 );
		$comments = (int) ( $one['comments'] ?? 0 );
		$errors   = array_values( array_filter( array_map( 'strval', (array) ( $one['errors'] ?? array() ) ), 'strlen' ) );
		if ( 0 === $posts && 0 === $comments && array() !== $errors ) {
			Event_Logger::record(
				Event_Logger::TYPE_COLLECT,
				Event_Logger::LEVEL_WARN,
				sprintf(
					__( 'レビューを取得できませんでした（%1$s）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$label,
					implode( ' / ', $errors )
				),
				array( 'source' => $source, 'errors' => $errors ),
				0,
				self::elapsed_ms( $started )
			);
			return;
		}
		Event_Logger::record(
			Event_Logger::TYPE_COLLECT,
			Event_Logger::LEVEL_INFO,
			sprintf(
				__( 'レビューを取得しました（%1$s）: スレッド %2$d 件 / コメント %3$d 件', GACHIREAI_TEXT_DOMAIN ),
				$label,
				$posts,
				$comments
			),
			array(
				'source'   => $source,
				'posts'    => $posts,
				'comments' => $comments,
				'found'    => (int) ( $one['found'] ?? 0 ),
			),
			0,
			self::elapsed_ms( $started )
		);
	}
	private function step_generate( array $state ): void {
		$started = microtime( true );
		try {
			$saved = $this->generate(
				(array) $state['product'],
				(array) $state['collected'],
				(string) $state['ec'],
				(string) $state['query'],
				(int) $state['author_id'],
				(string) $state['genre_id']
			);
		} catch ( Skippable_Exception $e ) {
			$this->fail_step( $state, $e->getMessage(), false, Event_Logger::TYPE_GENERATE );
			return;
		} catch ( \Throwable $e ) {
			$this->fail_step( $state, $e->getMessage(), true, Event_Logger::TYPE_GENERATE );
			return;
		}
		$post_id = (int) ( $saved['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			$this->fail_step(
				$state,
				__( '記事の保存結果に投稿 ID がありません。', GACHIREAI_TEXT_DOMAIN ),
				false,
				Event_Logger::TYPE_GENERATE
			);
			return;
		}
		$state['post_id']    = $post_id;
		$state['post_title'] = (string) ( $saved['post_title'] ?? '' );
		$state['step']       = self::STEP_EYECATCH;
		$state['attempt']    = 1;
		$this->save( $state );
		Event_Logger::record(
			Event_Logger::TYPE_GENERATE,
			Event_Logger::LEVEL_INFO,
			sprintf(
				__( '自動投稿の下書きを用意しました（続いてアイキャッチ画像を生成します）: %s', GACHIREAI_TEXT_DOMAIN ),
				(string) $state['post_title']
			),
			array(
				'post_id'      => $post_id,
				'product_name' => (string) ( ( (array) $state['product'] )['product_name'] ?? '' ),
			),
			$post_id,
			self::elapsed_ms( $started )
		);
	}
	private function step_eyecatch( array $state ): void {
		$started = microtime( true );
		$post_id = (int) ( $state['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			$this->fail_step(
				$state,
				__( 'アイキャッチ画像を生成する記事がありません。', GACHIREAI_TEXT_DOMAIN ),
				false,
				Event_Logger::TYPE_GENERATE
			);
			return;
		}
		$fail_behavior = (string) $this->settings->image()['fail_behavior'];
		try {
			$result = $this->eyecatch(
				$post_id,
				(string) ( $state['post_title'] ?? '' ),
				(array) $state['product']
			);
		} catch ( \Throwable $e ) {
			if ( Settings::IMAGE_FAIL_CONTINUE === $fail_behavior ) {
				$this->advance_without_eyecatch( $state, $e->getMessage(), 'failed', $post_id, $started );
				return;
			}
			$this->fail_step( $state, $e->getMessage(), true, Event_Logger::TYPE_GENERATE );
			return;
		}
		$status = (string) ( $result['status'] ?? '' );
		if ( 'generated' !== $status ) {
			if ( 'failed' === $status && Settings::IMAGE_FAIL_ABORT === $fail_behavior ) {
				$this->fail_step( $state, (string) ( $result['message'] ?? '' ), true, Event_Logger::TYPE_GENERATE );
				return;
			}
			$this->advance_without_eyecatch( $state, (string) ( $result['message'] ?? '' ), $status, $post_id, $started );
			return;
		}
		$state['step']    = self::STEP_READY;
		$state['attempt'] = 1;
		$this->save( $state );
		Event_Logger::record(
			Event_Logger::TYPE_GENERATE,
			Event_Logger::LEVEL_INFO,
			sprintf(
				__( 'アイキャッチ画像を生成しました（投稿時刻まで待機します）: %s', GACHIREAI_TEXT_DOMAIN ),
				(string) ( $state['post_title'] ?? '' )
			),
			array(
				'post_id'         => $post_id,
				'eyecatch_status' => $status,
			),
			$post_id,
			self::elapsed_ms( $started )
		);
	}
	private function advance_without_eyecatch( array $state, string $message, string $status, int $post_id, float $started ): void {
		$state['step']    = self::STEP_READY;
		$state['attempt'] = 1;
		$this->save( $state );
		Event_Logger::record(
			Event_Logger::TYPE_GENERATE,
			Event_Logger::LEVEL_WARN,
			sprintf(
				__( 'アイキャッチ画像を生成せずに待機します: %s', GACHIREAI_TEXT_DOMAIN ),
				$message
			),
			array(
				'post_id'         => $post_id,
				'eyecatch_status' => $status,
			),
			$post_id,
			self::elapsed_ms( $started )
		);
	}
	private function select(): array {
		if ( null !== $this->select_callable ) {
			return (array) ( $this->select_callable )();
		}
		$selector         = new Product_Selector( $this->settings );
		$selection        = $selector->select();
		$selection['ec'] = $selector->ec();
		return $selection;
	}
	private function collect( array $product, string $source, string $ec ): array {
		if ( null !== $this->collect_callable ) {
			return (array) ( $this->collect_callable )( $product, $source, $ec );
		}
		return ( new Review_Collector( $this->settings ) )->collect( $product, array(), $source, $ec );
	}
	private function generate( array $product, array $collected, string $ec, string $query, int $author_id, string $genre_id = '' ): array {
		if ( null !== $this->generate_callable ) {
			return (array) ( $this->generate_callable )( $product, $collected, $ec, $query, $author_id, $genre_id );
		}
		return ( new Article_Generator( $this->settings ) )->generate( $product, $collected, $ec, $query, false, $author_id, $genre_id, false );
	}
	private function eyecatch( int $post_id, string $title, array $product ): array {
		if ( null !== $this->eyecatch_callable ) {
			return (array) ( $this->eyecatch_callable )( $post_id, $title, $product );
		}
		return ( new Eyecatch( $this->settings ) )->generate_for_post(
			$post_id,
			$title,
			$product,
			new Site_Profile(),
			true
		);
	}
	private function normalize( array $raw ): array {
		$step = (string) ( $raw['step'] ?? '' );
		if ( ! in_array( $step, array( self::STEP_SELECT, self::STEP_COLLECT, self::STEP_GENERATE, self::STEP_EYECATCH, self::STEP_READY, self::STEP_FAILED ), true ) ) {
			return array();
		}
		$pending = array();
		foreach ( (array) ( $raw['pending'] ?? array() ) as $source ) {
			$source = trim( (string) $source );
			if ( '' !== $source && ! in_array( $source, $pending, true ) ) {
				$pending[] = $source;
			}
		}
		return array(
			'step'         => $step,
			'product'      => is_array( $raw['product'] ?? null ) ? (array) $raw['product'] : array(),
			'ec'           => (string) ( $raw['ec'] ?? '' ),
			'query'        => (string) ( $raw['query'] ?? '' ),
			'genre_id'     => (string) ( $raw['genre_id'] ?? '' ),
			'pending'      => $pending,
			'attempt'      => max( 1, (int) ( $raw['attempt'] ?? 1 ) ),
			'collected'    => is_array( $raw['collected'] ?? null ) ? (array) $raw['collected'] : self::empty_collected(),
			'post_id'      => (int) ( $raw['post_id'] ?? 0 ),
			'post_title'   => (string) ( $raw['post_title'] ?? '' ),
			'author_id'    => (int) ( $raw['author_id'] ?? 0 ),
			'failed_until' => (int) ( $raw['failed_until'] ?? 0 ),
			'last_error'   => (string) ( $raw['last_error'] ?? '' ),
			'updated_ts'   => (int) ( $raw['updated_ts'] ?? 0 ),
			'started_at'   => (string) ( $raw['started_at'] ?? '' ),
		);
	}
	private function save( array $state ): void {
		$state['updated_ts'] = time();
		$state['updated_at'] = $this->now();
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION, $state, false );
		}
	}
	private function fail_step( array $state, string $message, bool $retryable, string $type, array $payload = array(), int $elapsed_ms = 0 ): void {
		$message = trim( $message );
		if ( '' === $message ) {
			$message = __( '不明なエラーが発生しました。', GACHIREAI_TEXT_DOMAIN );
		}
		$attempt = max( 1, (int) ( $state['attempt'] ?? 1 ) );
		if ( $retryable && $attempt < self::MAX_ATTEMPTS ) {
			$state['attempt'] = $attempt + 1;
			$this->save( $state );
			Event_Logger::record(
				$type,
				Event_Logger::LEVEL_WARN,
				sprintf(
					__( '自動投稿の準備に失敗しました（%1$d/%2$d）。次の実行で再試行します: %3$s', GACHIREAI_TEXT_DOMAIN ),
					$attempt,
					self::MAX_ATTEMPTS,
					$message
				),
				array_merge( $payload, array( 'step' => (string) $state['step'], 'attempt' => $attempt ) ),
				0,
				$elapsed_ms
			);
			return;
		}
		$state['step']         = self::STEP_FAILED;
		$state['failed_until'] = time() + self::FAILED_COOLDOWN;
		$state['last_error']   = $message;
		$this->save( $state );
		Event_Logger::record(
			$type,
			Event_Logger::LEVEL_ERROR,
			sprintf(
				__( '自動投稿の準備を中止しました（%1$d 回試行）。次の投稿時刻に再挑戦します: %2$s', GACHIREAI_TEXT_DOMAIN ),
				$attempt,
				$message
			),
			array_merge( $payload, array( 'step' => (string) $state['step'], 'attempt' => $attempt ) ),
			0,
			$elapsed_ms
		);
	}
	private static function empty_collected(): array {
		return array(
			'posts'    => 0,
			'comments' => 0,
			'found'    => 0,
			'errors'   => array(),
			'searches' => array(),
			'rating'   => array(
				'count'   => 0,
				'average' => null,
			),
			'skipped'  => false,
			'message'  => '',
		);
	}
	private static function merge_collected( array $base, array $one ): array {
		$base['posts']    = (int) ( $base['posts'] ?? 0 ) + (int) ( $one['posts'] ?? 0 );
		$base['comments'] = (int) ( $base['comments'] ?? 0 ) + (int) ( $one['comments'] ?? 0 );
		$base['found']    = (int) ( $base['found'] ?? 0 ) + (int) ( $one['found'] ?? 0 );
		$base['errors']   = array_merge( (array) ( $base['errors'] ?? array() ), (array) ( $one['errors'] ?? array() ) );
		$base['searches'] = array_merge( (array) ( $base['searches'] ?? array() ), (array) ( $one['searches'] ?? array() ) );
		$base['skipped']  = false;
		$base['message']  = '';
		$rating = (array) ( $one['rating'] ?? array() );
		if ( (int) ( $rating['count'] ?? 0 ) > 0 ) {
			$base['rating'] = array(
				'count'   => (int) $rating['count'],
				'average' => $rating['average'] ?? null,
			);
		}
		return $base;
	}
	private static function all_sources(): array {
		return array_merge(
			array( 'ec' ),
			Source_Settings::PARTNER_SNS,
			array( 'reddit', 'fivech', 'youtube', 'hackernews', 'mastodon' )
		);
	}
	private static function elapsed_ms( float $started ): int {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}
	private function now(): string {
		return function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
