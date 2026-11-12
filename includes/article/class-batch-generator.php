<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Ec\Ec_Review_Collector;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Ledger;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\Event_Logger;
use Tonkatsutei\Gachi_Review_AI\Image\Eyecatch;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
use Tonkatsutei\Gachi_Review_AI\Sns\Sns_Review_Collector;
final class Batch_Generator {
	public const MAX_ATTEMPTS = 5;
	public const REVIEW_SOURCES = array( 'ec', 'x', 'instagram', 'facebook', 'youtube', 'reddit', 'fivech', 'hackernews', 'mastodon' );
	private const RETRY_WAIT_SECONDS = 3;
	public function run( int $user_id, bool $publish = false, string $draft_note = '' ): array {
		$messages      = array();
		$tried_codes   = array();
		$attempt       = 0;
		$result        = null;
		$post_id       = 0;
		$post_title    = '';
		$product       = array();
		$ec            = '';
		$query         = '';
		$collected_map = array();
		while ( $attempt < self::MAX_ATTEMPTS ) {
			$attempt++;
			if ( self::client_aborted() ) {
				return $this->result( false, 0, '', array(), false, $messages, $product, $ec, $query, $collected_map );
			}
			$selected = $this->select_product( $user_id, $messages );
			if ( null === $selected ) {
				return $this->result( false, 0, '', array(), false, $messages, $product, $ec, $query, $collected_map );
			}
			$selection = (array) $selected['selection'];
			$product   = (array) ( $selection['product'] ?? array() );
			$ec        = (string) $selected['ec'];
			$query     = (string) ( $selection['query'] ?? '' );
			$item      = (array) ( $product['item'] ?? array() );
			$item_code = (string) ( $item['item_code'] ?? '' );
			$name      = (string) ( $product['product_name'] ?? '' );
			if ( '' !== $item_code && in_array( $item_code, $tried_codes, true ) ) {
				$this->skip_product( $product, $ec );
				continue;
			}
			$tried_codes[] = $item_code;
			try {
				( new Article_Generator() )->assert_usable( $product );
			} catch ( Skippable_Exception $e ) {
				$this->skip_product( $product, $ec );
				Article_Generator::record_skip( $product, $e->getMessage() );
				$messages[] = $this->skip_message( $name, $e->getMessage() );
				continue;
			}
			$collected_map = array();
			if ( ! Ai_Review::is_only() ) {
				$scope = ( new Site_Profile() )->get_scope();
				foreach ( self::REVIEW_SOURCES as $source ) {
					if ( self::client_aborted() ) {
						return $this->result( false, 0, '', array(), false, $messages, $product, $ec, $query, $collected_map );
					}
					if ( ! Source_Settings::can_collect( $source ) ) {
						continue;
					}
					if ( Ec_Review_Collector::SOURCE === $source && ! Ec_Review_Collector::is_available() ) {
						continue;
					}
					if ( Source_Settings::is_sns( $source ) && ! Sns_Review_Collector::is_available() ) {
						continue;
					}
					Generate_Progress::start( $user_id, $this->source_progress_label( $source, $ec ), Generate_Progress::STEP_COLLECT, Generate_Progress::STEP_TOTAL );
					$collect_started = microtime( true );
					try {
						$collected = ( new Review_Collector() )->collect( $product, $scope, $source, $ec );
					} catch ( \Throwable $e ) {
						Generate_Progress::finish( $user_id );
						Event_Logger::record(
							Event_Logger::TYPE_COLLECT,
							Event_Logger::LEVEL_ERROR,
							sprintf(
								__( '%1$s からのレビュー取得に失敗しました: %2$s', GACHIREAI_TEXT_DOMAIN ),
								$this->source_label_text( $source, $ec ),
								$e->getMessage()
							),
							array( 'source' => $source ),
							0,
							self::elapsed_ms( $collect_started )
						);
						continue;
					}
					Generate_Progress::finish( $user_id );
					if ( empty( $collected['skipped'] ) ) {
						$collected_map[ $source ] = $collected;
					}
					$this->record_collect( $source, $collected, self::elapsed_ms( $collect_started ), $ec );
				}
			}
			if ( self::client_aborted() ) {
				return $this->result( false, 0, '', array(), false, $messages, $product, $ec, $query, $collected_map );
			}
			$result      = null;
			$skip_reason = '';
			$error       = '';
			for ( $gen_try = 0; $gen_try < 2; $gen_try++ ) {
				try {
					$result = ( new Article_Generator() )->generate(
						$product,
						self::aggregate_collected( $collected_map ),
						$ec,
						$query,
						false,
						null,
						'',
						false
					);
					break;
				} catch ( Skippable_Exception $e ) {
					$skip_reason = $e->getMessage();
					break;
				} catch ( \Throwable $e ) {
					$error = $e->getMessage();
					Event_Logger::record(
						Event_Logger::TYPE_GENERATE,
						Event_Logger::LEVEL_WARN,
						sprintf(
							__( '記事生成に失敗しました（再試行します）: %s', GACHIREAI_TEXT_DOMAIN ),
							$error
						),
						array( 'product_name' => $name ),
						0,
						0
					);
					if ( 0 === $gen_try ) {
						self::wait();
						continue;
					}
					break;
				}
			}
			if ( null === $result ) {
				if ( '' !== $skip_reason ) {
					$this->skip_product( $product, $ec );
					$messages[] = $this->skip_message( $name, $skip_reason );
					continue;
				}
				$messages[] = $this->message( 'error', esc_html( $error ) );
				return $this->result( false, 0, '', array(), false, $messages, $product, $ec, $query, $collected_map );
			}
			$post_id    = (int) ( $result['post_id'] ?? 0 );
			$post_title = (string) ( $result['post_title'] ?? '' );
			$messages[] = $this->message( 'success', esc_html__( '記事を下書きとして生成しました。', GACHIREAI_TEXT_DOMAIN ) );
			break;
		}  
		if ( null === $result ) {
			$messages[] = $this->message(
				'error',
				sprintf(
					__( '商品を %d 件まで試しましたが、記事を生成できませんでした。設定（レビュー取得元・再投稿間隔）を見直してください。', GACHIREAI_TEXT_DOMAIN ),
					self::MAX_ATTEMPTS
				)
			);
			return $this->result( false, 0, '', array(), false, $messages, $product, $ec, $query, $collected_map );
		}
		if ( self::client_aborted() ) {
			return $this->result( true, $post_id, $post_title, $result, false, $messages, $product, $ec, $query, $collected_map );
		}
		if ( $post_id > 0 ) {
			$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
			if ( $post instanceof \WP_Post ) {
				$eyecatch     = null;
				$eyecatch_ok  = false;
				$eyecatch_msg = '';
				for ( $eye_try = 0; $eye_try < 2; $eye_try++ ) {
					Generate_Progress::start( $user_id, __( 'アイキャッチ画像を生成中...', GACHIREAI_TEXT_DOMAIN ), Generate_Progress::STEP_EYECATCH, Generate_Progress::STEP_TOTAL );
					$eyecatch_started = microtime( true );
					try {
						$eyecatch = ( new Eyecatch() )->generate_for_post(
							$post_id,
							'' !== $post_title ? $post_title : (string) $post->post_title,
							$product,
							new Site_Profile(),
							true
						);
					} catch ( \Throwable $e ) {
						$eyecatch = array(
							'status'  => 'failed',
							'message' => $e->getMessage(),
						);
					}
					Generate_Progress::finish( $user_id );
					$eyecatch_status = (string) ( $eyecatch['status'] ?? '' );
					if ( 'failed' !== $eyecatch_status ) {
						$eyecatch_ok = true;
						break;
					}
					$eyecatch_msg = (string) ( $eyecatch['message'] ?? '' );
					Event_Logger::record(
						Event_Logger::TYPE_GENERATE,
						Event_Logger::LEVEL_WARN,
						sprintf(
							__( 'アイキャッチ画像の生成に失敗しました（再試行します）: %s', GACHIREAI_TEXT_DOMAIN ),
							$eyecatch_msg
						),
						array( 'post_id' => $post_id ),
						$post_id,
						self::elapsed_ms( $eyecatch_started )
					);
				}
				if ( ! $eyecatch_ok ) {
					$fail_behavior = (string) ( new Settings() )->image()['fail_behavior'];
					if ( Settings::IMAGE_FAIL_CONTINUE === $fail_behavior ) {
						$messages[] = $this->message(
							'warning',
							sprintf(
								__( 'アイキャッチ画像を生成できませんでした（設定どおり続行します）。手動で生成し直してください: %s', GACHIREAI_TEXT_DOMAIN ),
								$eyecatch_msg
							)
						);
					} else {
						$messages[] = $this->message(
							'warning',
							sprintf(
								__( 'アイキャッチ画像を生成できなかったため、公開せずに停止しました: %s', GACHIREAI_TEXT_DOMAIN ),
								$eyecatch_msg
							)
						);
						return $this->result( true, $post_id, $post_title, $result, false, $messages, $product, $ec, $query, $collected_map );
					}
				}
				$eyecatch_final = (string) ( $eyecatch['status'] ?? '' );
				Event_Logger::record(
					Event_Logger::TYPE_GENERATE,
					'generated' === $eyecatch_final ? Event_Logger::LEVEL_INFO : Event_Logger::LEVEL_WARN,
					sprintf(
						__( 'アイキャッチ画像: %s', GACHIREAI_TEXT_DOMAIN ),
						(string) ( $eyecatch['message'] ?? '' )
					),
					array(
						'post_id'         => $post_id,
						'eyecatch_status' => $eyecatch_final,
					),
					$post_id,
					self::elapsed_ms( $eyecatch_started )
				);
				if ( 'generated' !== $eyecatch_final && '' !== trim( (string) ( $eyecatch['message'] ?? '' ) ) ) {
					$messages[] = $this->message( 'warning', esc_html( (string) $eyecatch['message'] ) );
				}
			}
		}
		$published     = false;
		$publish_error = '';
		if ( $post_id > 0 && $publish && ! self::client_aborted() ) {
			Generate_Progress::start( $user_id, __( '記事を公開中...', GACHIREAI_TEXT_DOMAIN ) );
			for ( $pub_try = 0; $pub_try < 2; $pub_try++ ) {
				try {
					Auto_Post_Scheduler::publish( $post_id );
					$published = true;
					break;
				} catch ( \Throwable $e ) {
					$publish_error = $e->getMessage();
					Event_Logger::record(
						Event_Logger::TYPE_GENERATE,
						Event_Logger::LEVEL_WARN,
						sprintf(
							__( '記事の公開に失敗しました（再試行します）: %s', GACHIREAI_TEXT_DOMAIN ),
							$publish_error
						),
						array( 'post_id' => $post_id ),
						$post_id,
						0
					);
				}
			}
			Generate_Progress::finish( $user_id );
			if ( $published ) {
				$messages[] = $this->message( 'success', esc_html__( '記事を公開しました。', GACHIREAI_TEXT_DOMAIN ) );
			} else {
				$messages[] = $this->message(
					'warning',
					sprintf(
						__( '記事を公開できなかったため、下書きのまま停止しました: %s', GACHIREAI_TEXT_DOMAIN ),
						$publish_error
					)
				);
			}
		} elseif ( $post_id > 0 && '' !== $draft_note ) {
			$messages[] = $this->message( 'info', esc_html( $draft_note ) );
		}
		return $this->result( true, $post_id, $post_title, $result, $published, $messages, $product, $ec, $query, $collected_map );
	}
	private function select_product( int $user_id, array &$messages ): ?array {
		for ( $try = 0; $try < 2; $try++ ) {
			Generate_Progress::start( $user_id, __( '商品を選定中...', GACHIREAI_TEXT_DOMAIN ), Generate_Progress::STEP_SELECT, Generate_Progress::STEP_TOTAL );
			$selector       = new Product_Selector();
			$select_started = microtime( true );
			try {
				$selection = $selector->select( false );
			} catch ( \Throwable $e ) {
				Generate_Progress::finish( $user_id );
				Event_Logger::record(
					Event_Logger::TYPE_SELECT,
					Event_Logger::LEVEL_ERROR,
					sprintf(
						__( '商品の選定に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
						$e->getMessage()
					),
					array( 'ec' => $selector->ec() ),
					0,
					self::elapsed_ms( $select_started )
				);
				if ( 0 === $try ) {
					self::wait();
					continue;
				}
				$messages[] = $this->message( 'error', esc_html( $e->getMessage() ) );
				return null;
			}
			Generate_Progress::finish( $user_id );
			if ( Product_Selector::STATUS_SELECTED === (string) ( $selection['status'] ?? '' ) ) {
				$product = (array) ( $selection['product'] ?? array() );
				$item    = (array) ( $product['item'] ?? array() );
				Event_Logger::record(
					Event_Logger::TYPE_SELECT,
					Event_Logger::LEVEL_INFO,
					sprintf(
						__( '商品を選定しました: %s', GACHIREAI_TEXT_DOMAIN ),
						(string) ( $product['product_name'] ?? '' )
					),
					array(
						'ec'           => $selector->ec(),
						'query'        => (string) ( $selection['query'] ?? '' ),
						'product_name' => (string) ( $product['product_name'] ?? '' ),
						'item_code'    => (string) ( $item['item_code'] ?? '' ),
						'price'        => (int) ( $item['item_price'] ?? 0 ),
					),
					0,
					self::elapsed_ms( $select_started )
				);
				return array(
					'selection' => $selection,
					'ec'        => $selector->ec(),
				);
			}
			$message = trim( (string) ( $selection['message'] ?? '' ) );
			$message = '' !== $message ? $message : __( 'EC で対象商品を選定できませんでした。', GACHIREAI_TEXT_DOMAIN );
			Event_Logger::record(
				Event_Logger::TYPE_SELECT,
				Event_Logger::LEVEL_ERROR,
				$message,
				array(
					'ec'     => $selector->ec(),
					'status' => (string) ( $selection['status'] ?? '' ),
					'reason' => (string) ( $selection['reason'] ?? '' ),
				),
				0,
				self::elapsed_ms( $select_started )
			);
			$messages[] = $this->message( 'error', esc_html( $message ) );
			return null;
		}
		return null;
	}
	private function skip_product( array $product, string $ec ): void {
		$item_code = (string) ( $product['item']['item_code'] ?? '' );
		if ( '' === $item_code ) {
			return;
		}
		$ledger = new Product_Ledger();
		$row    = $ledger->find_by_item_code( $ec, $item_code );
		if ( array() !== $row ) {
			$ledger->mark_skipped( (int) ( $row['id'] ?? 0 ) );
		}
	}
	private function skip_message( string $name, string $reason ): array {
		return $this->message(
			'warning',
			sprintf(
				__( '「%1$s」はスキップして、次の商品で再試行します。理由: %2$s', GACHIREAI_TEXT_DOMAIN ),
				esc_html( $name ),
				esc_html( $reason )
			)
		);
	}
	private static function wait(): void {
		if ( self::RETRY_WAIT_SECONDS > 0 && function_exists( 'sleep' ) ) {
			sleep( self::RETRY_WAIT_SECONDS );
		}
	}
	private static function elapsed_ms( float $started ): int {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}
	private function message( string $type, string $text ): array {
		$allowed = array( 'info', 'success', 'warning', 'error' );
		return array(
			'msg_type' => in_array( $type, $allowed, true ) ? $type : 'info',
			'msg_text' => $text,
		);
	}
	private function result( bool $ok, int $post_id, string $post_title, array $result, bool $published, array $messages, array $product, string $ec, string $query, array $collected ): array {
		return array(
			'ok'          => $ok,
			'post_id'     => $post_id,
			'post_title'  => $post_title,
			'edit_url'    => (string) ( $result['edit_url'] ?? '' ),
			'preview_url' => (string) ( $result['preview_url'] ?? '' ),
			'permalink'   => $published && $post_id > 0 ? (string) get_permalink( $post_id ) : '',
			'published'   => $published,
			'messages'    => $messages,
			'product'     => $product,
			'ec'          => $ec,
			'query'       => $query,
			'collected'   => $collected,
		);
	}
	private function record_collect( string $source, array $collected, int $elapsed_ms, string $ec ): void {
		$label    = $this->source_label_text( $source, $ec );
		$posts    = (int) ( $collected['posts'] ?? 0 );
		$comments = (int) ( $collected['comments'] ?? 0 );
		$errors   = array_values( array_filter( array_map( 'strval', (array) ( $collected['errors'] ?? array() ) ), 'strlen' ) );
		if ( ! empty( $collected['skipped'] ) ) {
			Event_Logger::record(
				Event_Logger::TYPE_COLLECT,
				Event_Logger::LEVEL_INFO,
				(string) ( $collected['message'] ?? '' ),
				array( 'source' => $source, 'skipped' => true ),
				0,
				$elapsed_ms
			);
			return;
		}
		$payload = array(
			'source'   => $source,
			'posts'    => $posts,
			'comments' => $comments,
			'found'    => (int) ( $collected['found'] ?? 0 ),
			'searches' => (array) ( $collected['searches'] ?? array() ),
		);
		$judged = (array) ( $collected['judged'] ?? array() );
		if ( array() !== $judged ) {
			$payload['judge'] = $judged;
		}
		$rating = (array) ( $collected['rating'] ?? array() );
		if ( 0 < (int) ( $rating['count'] ?? 0 ) ) {
			$payload['rating'] = $rating;
		}
		if ( array() !== $errors ) {
			$payload['errors'] = $errors;
			Event_Logger::record(
				Event_Logger::TYPE_COLLECT,
				Event_Logger::LEVEL_WARN,
				sprintf(
					__( '%1$s の取得で一部エラーがありました（投稿 %2$d / コメント %3$d）。', GACHIREAI_TEXT_DOMAIN ),
					$label,
					$posts,
					$comments
				),
				$payload,
				0,
				$elapsed_ms
			);
			return;
		}
		Event_Logger::record(
			Event_Logger::TYPE_COLLECT,
			Event_Logger::LEVEL_INFO,
			sprintf(
				__( '%1$s からレビューを取得しました（投稿 %2$d / コメント %3$d）。', GACHIREAI_TEXT_DOMAIN ),
				$label,
				$posts,
				$comments
			),
			$payload,
			0,
			$elapsed_ms
		);
	}
	private static function aggregate_collected( array $map ): array {
		$aggregate = array(
			'posts'    => 0,
			'comments' => 0,
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
		$source_keys = array_merge(
			array( 'ec', 'subreddits', 'boards', 'youtube', 'hackernews', 'mastodon' ),
			Source_Settings::PARTNER_SNS
		);
		foreach ( $map as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}
			$aggregate['posts']    += (int) ( $result['posts'] ?? 0 );
			$aggregate['comments'] += (int) ( $result['comments'] ?? 0 );
			$aggregate['errors']    = array_merge( $aggregate['errors'], (array) ( $result['errors'] ?? array() ) );
			foreach ( $source_keys as $key ) {
				$aggregate['sources'][ $key ] = array_values(
					array_unique(
						array_merge( $aggregate['sources'][ $key ], (array) ( $result['sources'][ $key ] ?? array() ) )
					)
				);
			}
		}
		return $aggregate;
	}
	private static function client_aborted(): bool {
		return function_exists( 'connection_aborted' ) && 1 === connection_aborted();
	}
	private function source_label_text( string $source, string $ec ): string {
		if ( Ec_Review_Collector::SOURCE === $source ) {
			return Product_Selector::ec_label( Product_Selector::normalize_ec( $ec ) );
		}
		return Source_Settings::source_label( $source );
	}
	private function source_progress_label( string $source, string $ec ): string {
		switch ( $source ) {
			case Ec_Review_Collector::SOURCE:
				return sprintf(
					__( '%s のレビューを取得中...', GACHIREAI_TEXT_DOMAIN ),
					Product_Selector::ec_label( Product_Selector::normalize_ec( $ec ) )
				);
			case 'x':
			case 'instagram':
			case 'facebook':
				return sprintf(
					__( '%s を取得中...', GACHIREAI_TEXT_DOMAIN ),
					Source_Settings::source_label( $source )
				);
			case 'youtube':
				return __( 'YouTube を取得中...', GACHIREAI_TEXT_DOMAIN );
			case 'reddit':
				return __( 'Reddit を取得中...', GACHIREAI_TEXT_DOMAIN );
			case 'fivech':
				return __( '5ちゃんねる を取得中...', GACHIREAI_TEXT_DOMAIN );
			case 'hackernews':
				return __( 'Hacker News を取得中...', GACHIREAI_TEXT_DOMAIN );
			case 'mastodon':
				return __( 'Mastodon を取得中...', GACHIREAI_TEXT_DOMAIN );
		}
		return __( 'レビューを取得中...', GACHIREAI_TEXT_DOMAIN );
	}
}
