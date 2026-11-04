<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Ec\Ec_Review_Collector;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\Fivech\Fivech_Client;
use Tonkatsutei\Gachi_Review_AI\Fivech\Fivech_Collector;
use Tonkatsutei\Gachi_Review_AI\Hacker_News\Hacker_News_Collector;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Llm\Jev_Judger;
use Tonkatsutei\Gachi_Review_AI\Mastodon\Mastodon_Collector;
use Tonkatsutei\Gachi_Review_AI\Reddit\Reddit_Collector;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\Sns\Sns_Review_Collector;
use Tonkatsutei\Gachi_Review_AI\Youtube\YouTube_Collector;
final class Review_Collector {
	private const FIVECH_GROUP = 'fivech';
	private const REDDIT_GROUP = 'reddit';
	private Settings $settings;
	private ?Reddit_Collector $reddit;
	private ?Fivech_Collector $fivech;
	private ?YouTube_Collector $youtube;
	private ?Hacker_News_Collector $hackernews;
	private ?Mastodon_Collector $mastodon;
	private ?Ec_Review_Collector $ec;
	private ?Sns_Review_Collector $sns;
	public function __construct( ?Settings $settings = null, ?Reddit_Collector $reddit = null, ?Fivech_Collector $fivech = null, ?YouTube_Collector $youtube = null, ?Hacker_News_Collector $hackernews = null, ?Mastodon_Collector $mastodon = null, ?Ec_Review_Collector $ec = null, ?Sns_Review_Collector $sns = null ) {
		$this->settings   = $settings ?? new Settings();
		$this->reddit     = $reddit;
		$this->fivech     = $fivech;
		$this->youtube    = $youtube;
		$this->hackernews = $hackernews;
		$this->mastodon   = $mastodon;
		$this->ec         = $ec;
		$this->sns        = $sns;
	}
	public function collect( array $product, array $scope = array(), string $source = '', string $base_ec = '' ): array {
		unset( $scope );
		$started = (string) current_time( 'mysql' );
		$name = trim( (string) ( $product['product_name'] ?? '' ) );
		if ( '' === $name ) {
			throw new \RuntimeException( __( '商品名が空のためレビューを取得できません。先に「即時商品選定」を実行してください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$result = array(
			'posts'    => 0,
			'comments' => 0,
			'found'    => 0,
			'rating'   => array(
				'count'   => 0,
				'average' => null,
			),
			'searches' => array(),
			'sources'  => array_merge(
				array(
					'ec'         => array(),
					'subreddits' => array(),
					'boards'     => array(),
					'youtube'    => array(),
					'hackernews' => array(),
					'mastodon'   => array(),
				),
				array_fill_keys( Source_Settings::PARTNER_SNS, array() )
			),
			'errors'   => array(),
			'skipped'  => false,
			'message'  => '',
		);
		$is_generic = ! empty( $product['is_generic'] ) && '' === trim( (string) ( $product['jan_code'] ?? '' ) );
		$all_sources = array_merge(
			array( 'ec' ),
			Source_Settings::PARTNER_SNS,
			array( 'reddit', 'fivech', 'youtube', 'hackernews', 'mastodon' )
		);
		$wanted      = '' === $source ? Source_Settings::collectable( $all_sources ) : array( $source );
		$capabilities = Status::capabilities();
		$wanted       = array_values( array_filter( $wanted, array( $capabilities, 'can_collect_source' ) ) );
		if ( $is_generic ) {
			$wanted = array_values( array_diff( $wanted, array( 'reddit', 'fivech' ) ) );
		}
		if ( $is_generic && '' !== $source && array() === $wanted ) {
			$result['skipped'] = true;
			$result['message'] = sprintf(
				__( '「%s」はブランド・型番が取れない一般名称のため、5ちゃんねる・Reddit からのレビュー取得を省略しました（他商品のレビューが混じるため）。', GACHIREAI_TEXT_DOMAIN ),
				$name
			);
			return $result;
		}
		if ( '' !== $source && ! Source_Settings::can_collect( $source ) ) {
			$result['skipped'] = true;
			$result['message'] = Source_Settings::switch_off_reason( $source );
			return $result;
		}
		if ( '' !== $source && array() === $wanted ) {
			$result['skipped'] = true;
			$result['message'] = __( 'この取得元は現在のライセンスでは利用できません。FREE は AI 生成のみ利用できます。', GACHIREAI_TEXT_DOMAIN );
			return $result;
		}
		if ( '' === $source && array() === $wanted ) {
			$result['errors'][] = __( '「レビュー設定」タブでレビューを取得する取得元のスイッチが 1 つも ON になっていません。取得したい取得元を ON にしてください。', GACHIREAI_TEXT_DOMAIN );
			return $result;
		}
		$youtube        = $this->youtube ?? new YouTube_Collector();
		$youtube_wanted = in_array( 'youtube', $wanted, true );
		$youtube_ready  = $youtube_wanted && $youtube->is_available();
		$hackernews        = $this->hackernews ?? new Hacker_News_Collector();
		$hackernews_wanted = in_array( 'hackernews', $wanted, true );
		$hackernews_ready  = $hackernews_wanted && $hackernews->is_available();
		$mastodon        = $this->mastodon ?? new Mastodon_Collector();
		$mastodon_wanted = in_array( 'mastodon', $wanted, true );
		$mastodon_ready  = $mastodon_wanted && $mastodon->is_available();
		$ec_collector = $this->ec ?? new Ec_Review_Collector();
		$ec_wanted    = in_array( 'ec', $wanted, true );
		$ec_site      = Product_Selector::normalize_ec(
			'' !== trim( $base_ec )
				? $base_ec
				: (string) ( ( (array) $this->settings->get( 'ec', array() ) )['ec'] ?? '' )
		);
		$ec_ready     = $ec_wanted && $ec_collector->is_available();
		$sources           = $this->sources( array_values( array_intersect( $wanted, array( 'reddit', 'fivech' ) ) ) );
		$result['sources'] = array_merge(
			array(
				'ec'         => $ec_ready ? array( Product_Selector::ec_label( $ec_site ) ) : array(),
				'subreddits' => $sources['subreddits'],
				'boards'     => $sources['boards'],
				'youtube'    => $youtube_ready ? YouTube_Collector::search_terms( $name ) : array(),
				'hackernews' => $hackernews_ready ? Hacker_News_Collector::search_terms( $name ) : array(),
				'mastodon'   => $mastodon_ready ? $mastodon->search_terms( $name ) : array(),
			),
			array_fill_keys( Source_Settings::PARTNER_SNS, array() )
		);
		$result['errors']  = $sources['errors'];
		$sns_ready = array() !== array_intersect( $wanted, Source_Settings::PARTNER_SNS ) && Sns_Review_Collector::is_available();
		if ( '' === $source && array() === $sources['subreddits'] && array() === $sources['boards'] && ! $youtube_ready && ! $hackernews_ready && ! $mastodon_ready && ! $ec_ready && ! $sns_ready ) {
			$result['errors'][] = __( '「レビュー設定」でレビューの検索先（5ちゃんねるの板・Reddit のサブレディット）が未設定です。「AI 提案」で選んで保存してください。', GACHIREAI_TEXT_DOMAIN );
			return $result;
		}
		$keywords = $this->keywords( $product );
		if ( array() !== $sources['subreddits'] ) {
			$result = $this->merge(
				$result,
				fn(): array => ( $this->reddit ?? new Reddit_Collector() )->collect(
					array(
						'subreddits' => implode( ',', $sources['subreddits'] ),
						'keywords'   => implode( ' ', $keywords['reddit'] ),
					)
				)
			);
		}
		if ( array() !== $sources['boards'] ) {
			$result = $this->merge(
				$result,
				fn(): array => ( $this->fivech ?? new Fivech_Collector() )->collect(
					array(
						'boards'   => implode( ',', $sources['boards'] ),
						'keywords' => implode( ' ', $keywords['fivech'] ),
					)
				)
			);
		}
		if ( $youtube_ready ) {
			$result = $this->merge(
				$result,
				fn(): array => $youtube->collect( array( 'product_name' => $name ) )
			);
		}
		if ( $hackernews_ready ) {
			$result = $this->merge(
				$result,
				fn(): array => $hackernews->collect( array( 'product_name' => $name ) )
			);
		}
		if ( $mastodon_ready ) {
			$result = $this->merge(
				$result,
				fn(): array => $mastodon->collect( array( 'product_name' => $name ) )
			);
		}
		if ( $ec_ready ) {
			$result = $this->merge(
				$result,
				fn(): array => $ec_collector->collect( $product, $ec_site )
			);
		}
		$sns_wanted = array_values( array_intersect( $wanted, Source_Settings::PARTNER_SNS ) );
		if ( array() !== $sns_wanted ) {
			$sns_collector = $this->sns ?? new Sns_Review_Collector();
			if ( $sns_collector->is_available() ) {
				foreach ( $sns_wanted as $sns_id ) {
					$result['sources'][ $sns_id ] = array( $name );
					$result = $this->merge(
						$result,
						fn(): array => $sns_collector->collect( $product, $sns_id )
					);
				}
			}
		}
		$result['judged'] = Jev_Judger::from_settings( $this->settings )->judge_pending( $wanted, $started );
		return $result;
	}
	private function merge( array $result, callable $collect ): array {
		try {
			$one = $collect();
		} catch ( \Throwable $e ) {
			$one = array(
				'posts'    => 0,
				'comments' => 0,
				'errors'   => array( $e->getMessage() ),
			);
		}
		$result['posts']    += (int) ( $one['posts'] ?? 0 );
		$result['comments'] += (int) ( $one['comments'] ?? 0 );
		$result['found']     = (int) ( $result['found'] ?? 0 ) + (int) ( $one['found'] ?? 0 );
		$result['errors']    = array_merge( (array) $result['errors'], (array) ( $one['errors'] ?? array() ) );
		$result['searches'] = array_merge( (array) ( $result['searches'] ?? array() ), (array) ( $one['searches'] ?? array() ) );
		if ( isset( $one['rating'] ) && is_array( $one['rating'] ) ) {
			$result['rating'] = self::merge_rating( (array) ( $result['rating'] ?? array() ), (array) $one['rating'] );
		}
		return $result;
	}
	private static function merge_rating( array $base, array $add ): array {
		$base_count = max( 0, (int) ( $base['count'] ?? 0 ) );
		$add_count  = max( 0, (int) ( $add['count'] ?? 0 ) );
		$total      = $base_count + $add_count;
		if ( 0 === $total ) {
			return array(
				'count'   => 0,
				'average' => null,
			);
		}
		$base_sum = (float) ( $base['average'] ?? 0 ) * $base_count;
		$add_sum  = (float) ( $add['average'] ?? 0 ) * $add_count;
		return array(
			'count'   => $total,
			'average' => round( ( $base_sum + $add_sum ) / $total, 2 ),
		);
	}
	private function sources( array $wanted = array( 'reddit', 'fivech' ) ): array {
		$subreddits = in_array( 'reddit', $wanted, true )
			? $this->list_from( (string) $this->settings->get( self::REDDIT_GROUP . '.subreddits', '' ) )
			: array();
		$requested  = in_array( 'fivech', $wanted, true )
			? $this->list_from( (string) $this->settings->get( self::FIVECH_GROUP . '.boards', '' ) )
			: array();
		$boards  = array();
		$unknown = array();
		$client  = null;
		foreach ( $requested as $board ) {
			try {
				$client = $client ?? new Fivech_Client();
				$key    = $client->resolve_board( $board );
			} catch ( \Throwable $e ) {
				return array(
					'subreddits' => array_values( array_unique( $subreddits ) ),
					'boards'     => array(),
					'errors'     => array( $e->getMessage() ),
				);
			}
			if ( null === $key ) {
				$unknown[] = $board;
				continue;
			}
			$boards[] = $key;
		}
		$errors = array();
		if ( array() !== $unknown ) {
			$errors[] = sprintf(
				__( '5ch の板を特定できませんでした（設定: %s）。「レビュー設定」タブの「AI 提案」で板を選び直すか、入力を確認してください。', GACHIREAI_TEXT_DOMAIN ),
				implode( ' / ', array_slice( $unknown, 0, 10 ) )
			);
		}
		return array(
			'subreddits' => array_values( array_unique( $subreddits ) ),
			'boards'     => array_values( array_unique( $boards ) ),
			'errors'     => $errors,
		);
	}
	private function keywords( array $product ): array {
		$name  = trim( (string) ( $product['product_name'] ?? '' ) );
		$brand = trim( (string) ( $product['brand'] ?? '' ) );
		$model = trim( (string) ( $product['model_number'] ?? '' ) );
		return array(
			'fivech' => self::unique( array( $name, $brand, $model ) ),
			'reddit' => self::unique( array( $brand, $model, $name ) ),
		);
	}
	private function list_from( string $value ): array {
		$parts = preg_split( '/[,\x{3001}\n]+/u', $value );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$list = array();
		foreach ( $parts as $part ) {
			$text = trim( (string) $part );
			if ( '' !== $text ) {
				$list[] = $text;
			}
		}
		return array_values( array_unique( $list ) );
	}
	private static function unique( array $values ): array {
		return array_values( array_unique( array_filter( $values, static fn( string $v ): bool => '' !== trim( $v ) ) ) );
	}
}
