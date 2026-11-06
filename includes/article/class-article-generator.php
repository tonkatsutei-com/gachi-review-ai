<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Activator;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Amazon_Client;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Box_Positioner;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Box_Renderer;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Card_Settings;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Providers;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Rakuten_Client;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Yahoo_Client;
use Tonkatsutei\Gachi_Review_AI\Ec\Ec_Review_Collector;
use Tonkatsutei\Gachi_Review_AI\Ec\Ec_Review_Rewriter;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Ledger;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\Event_Logger;
use Tonkatsutei\Gachi_Review_AI\Fivech\Fivech_Collector;
use Tonkatsutei\Gachi_Review_AI\Hacker_News\Hacker_News_Collector;
use Tonkatsutei\Gachi_Review_AI\Icons;
use Tonkatsutei\Gachi_Review_AI\Image\Eyecatch;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Llm\Jev_Questions;
use Tonkatsutei\Gachi_Review_AI\Llm\Manager;
use Tonkatsutei\Gachi_Review_AI\Mastodon\Mastodon_Collector;
use Tonkatsutei\Gachi_Review_AI\Reddit\Reddit_Collector;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Generator;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
use Tonkatsutei\Gachi_Review_AI\Youtube\YouTube_Collector;
final class Article_Generator {
	private const KEYS = array( 'title', 'lead', 'body', 'category', 'tags', 'slug' );
	private const BODY_TYPES = array( 'h2', 'h3', 'p', 'ul', 'quote', 'dialogue' );
	private const MAX_TOKENS = 8000;
	private const REVIEW_LIMITS = array(
		'summary'    => 3,
		'quote'      => 6,
		'experience' => 2,
		'dialogue'   => 2,
	);
	private const MAX_COMMENTS = 10;
	private const MAX_THREAD_TEXT = 2000;
	private const MAX_MATERIAL_TEXT = 8000;
	private const MAX_CANDIDATE_THREADS = 60;
	private const PROMPT_CATEGORY_LIMIT = 50;
	private const PROMPT_TAG_LIMIT = 100;
	private const AI_REVIEW_TYPES = array( 'summary', 'quote', 'experience', 'dialogue' );
	private const TYPE_INSTRUCTIONS = array(
		'summary' => '総評型: 複数のレビュー（複数ソースの投稿とコメント）を分析して共通点・相違点をまとめ、書く人の言葉で要約してください（元の投稿をそのまま引用しない）。長所・短所は「ul」ブロックの箇条書きにしてかまいません。**見出し（h2/h3）・本文とも、書き手自身が実際にその商品を使用した体験（例:「実際に使ってみた」「1ヶ月愛用した結果」）としては書かず、収集したレビューの分析・要約として書いてください（景品表示法の観点）。**',
		'quote'   => '引用型: 複数のレビューから高評価と低評価の声を数件ずつ取り上げてください。引用は「quote」ブロック（{"type":"quote","review":n,"text":"日本語訳","original":"原文"}。review は引用元の【レビュー n】の番号、text は引用文の日本語訳）で入れ、見出し（h2）で「高評価の声」「低評価の声」のように分け、文末に「総評」の見出しと段落（p）を付けてください。**引用元が英語のときは原文を original にそのまま入れ**（翻訳を text、原文を original）、日本語のときは original を省略してください。引用文には出典（サイト名・記事タイトル）を含めず、引用の本文だけを書いてください（出典は自動で付きます）。「高評価の声」「低評価の声」の各項目は、書き出し（最初の語）・口調・文の長さ・触れる観点を互いに変え、「ただ」「ただし」「しかし」「でも」のような同じ接続詞で連続して始めないでください。書き出しの型（結論から／体験から／購入のきっかけから／意外な点から など）も項目ごとに重複させないでください（実際の口コミらしい自然さを出す）。',
	);
	private Settings $settings;
	private Product_Ledger $ledger;
	public function __construct( ?Settings $settings = null, ?Product_Ledger $ledger = null ) {
		$this->settings = $settings ?? new Settings();
		$this->ledger   = $ledger ?? new Product_Ledger();
	}
	public function generate( ?array $product = null, ?array $collected = null, string $ec = '', string $query = '', bool $force = false, ?int $author_id = null, string $genre_id = '', bool $with_eyecatch = true ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 );
		}
		$profile = new Site_Profile();
		$user_id = get_current_user_id();
		$started = microtime( true );
		Manager::reset_retry_count();
		Generate_Progress::start( $user_id, __( '記事を生成開始...', GACHIREAI_TEXT_DOMAIN ), Generate_Progress::STEP_PICK, Generate_Progress::STEP_TOTAL );
		try {
			if ( null === $product ) {
				$selector       = new Product_Selector( $this->settings );
				$select_started = microtime( true );
				try {
					$selection = $selector->select();
				} catch ( \Throwable $e ) {
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
					throw $e;
				}
				if ( Product_Selector::STATUS_SELECTED !== (string) ( $selection['status'] ?? '' ) ) {
					$reason = $this->selection_message( $selection );
					Event_Logger::record(
						Event_Logger::TYPE_SELECT,
						Event_Logger::LEVEL_ERROR,
						$reason,
						array(
							'ec'     => $selector->ec(),
							'status' => (string) ( $selection['status'] ?? '' ),
							'reason' => (string) ( $selection['reason'] ?? '' ),
						),
						0,
						self::elapsed_ms( $select_started )
					);
					throw new \RuntimeException( $reason );
				}
				$product  = (array) ( $selection['product'] ?? array() );
				$ec       = $selector->ec();
				$query    = (string) ( $selection['query'] ?? '' );
				$genre_id = (string) ( $selection['genre_id'] ?? '' );
				$selected_name = (string) ( $product['product_name'] ?? '' );
				$selected_item = (array) ( $product['item'] ?? array() );
				Event_Logger::record(
					Event_Logger::TYPE_SELECT,
					Event_Logger::LEVEL_INFO,
					sprintf(
						__( '商品を選定しました: %s', GACHIREAI_TEXT_DOMAIN ),
						$selected_name
					),
					array(
						'ec'           => $ec,
						'query'        => $query,
						'product_name' => $selected_name,
						'item_code'    => (string) ( $selected_item['item_code'] ?? '' ),
						'price'        => (int) ( $selected_item['item_price'] ?? 0 ),
					),
					0,
					self::elapsed_ms( $select_started )
				);
			}
			$product = $this->product_of( $product );
			Event_Logger::record(
				Event_Logger::TYPE_GENERATE,
				Event_Logger::LEVEL_INFO,
				sprintf(
					__( '記事生成を開始しました: %s', GACHIREAI_TEXT_DOMAIN ),
					(string) $product['product_name']
				),
				array(
					'ec'           => $ec,
					'query'        => $query,
					'product_name' => (string) $product['product_name'],
					'force'        => $force,
				)
			);
			if ( ! $force ) {
				$this->assert_postable( $product );
			}
			$this->assert_not_excluded( $product );
			$ledger_id = $this->register_product( $ec, $product, $query, $genre_id );
			$reviews  = array();
			$matched  = false;
			$excluded = 0;
			$used     = 0;
			if ( ! Ai_Review::is_only() ) {
				if ( null === $collected ) {
					$collect_started = microtime( true );
					$collected       = ( new Review_Collector( $this->settings ) )->collect( $product, $profile->get_scope() );
					self::record_collect_event( $collected, self::elapsed_ms( $collect_started ) );
				}
				if ( ! empty( $collected['skipped'] ) ) {
					throw new \RuntimeException( (string) ( $collected['message'] ?? '' ) );
				}
				Generate_Progress::update( $user_id, __( '採用レビューを選定中...', GACHIREAI_TEXT_DOMAIN ), Generate_Progress::STEP_PICK, Generate_Progress::STEP_TOTAL );
				$picked   = $this->pick_reviews( (string) $product['product_name'], $force );
				$reviews  = $picked['picked'];
				$matched  = (bool) $picked['matched'];
				$excluded = (int) ( $picked['excluded'] ?? 0 );
				$used     = (int) ( $picked['used'] ?? 0 );
			}
			Generate_Progress::update( $user_id, __( 'レビューを加工中...', GACHIREAI_TEXT_DOMAIN ), Generate_Progress::STEP_REWRITE, Generate_Progress::STEP_TOTAL );
			$reviews = ( new Ec_Review_Rewriter( $this->settings ) )->process( $reviews, $product );
			$ai_reviews = array();
			if ( ! Ai_Review::is_off() && in_array( $this->article_type(), self::AI_REVIEW_TYPES, true ) ) {
				Generate_Progress::update( $user_id, __( 'AIレビューを生成中...', GACHIREAI_TEXT_DOMAIN ), Generate_Progress::STEP_AI_REVIEW, Generate_Progress::STEP_TOTAL );
				$ai_reviews = $this->ai_reviews( $profile, $product, count( $reviews ) );
				if ( array() !== $ai_reviews ) {
					$reviews = array_merge( $reviews, $ai_reviews );
					Event_Logger::record(
						Event_Logger::TYPE_GENERATE,
						Event_Logger::LEVEL_INFO,
						sprintf(
							__( 'AI によるレビューを %d 件生成しました（引用型）。', GACHIREAI_TEXT_DOMAIN ),
							count( $ai_reviews )
						),
						array(
							'ai_reviews' => count( $ai_reviews ),
							'mode'       => Ai_Review::mode(),
						)
					);
				}
			}
			if ( array() === $reviews ) {
				throw $this->empty_reviews_exception( $force, $matched, $excluded, $used );
			}
			$collected = is_array( $collected ) ? $collected : array();
			Generate_Progress::update( $user_id, __( '記事を生成中...', GACHIREAI_TEXT_DOMAIN ), Generate_Progress::STEP_GENERATE, Generate_Progress::STEP_TOTAL );
			$article = $this->generate_draft( $profile, $product, $reviews );
			$llm = $this->settings->active_llm();
			Event_Logger::record(
				Event_Logger::TYPE_GENERATE,
				Event_Logger::LEVEL_INFO,
				sprintf(
					__( '記事の下書きを生成しました: %s', GACHIREAI_TEXT_DOMAIN ),
					(string) ( $article['title'] ?? '' )
				),
				array(
					'provider'     => (string) ( $llm['provider'] ?? '' ),
					'model'        => (string) ( $llm['model'] ?? '' ),
					'review_count' => count( $reviews ),
					'retry_count'  => Manager::retry_count(),
				)
			);
			$saved = $this->save_post( $article, $product, $reviews, $collected, $profile, $author_id, $with_eyecatch );
			self::record_save_events( $saved );
			$this->record_posted( $ledger_id, (int) ( $saved['post_id'] ?? 0 ) );
			Event_Logger::record(
				Event_Logger::TYPE_GENERATE,
				Event_Logger::LEVEL_INFO,
				sprintf(
					__( '記事を保存しました（下書き）: %s', GACHIREAI_TEXT_DOMAIN ),
					(string) ( $saved['post_title'] ?? '' )
				),
				array(
					'post_id'      => (int) ( $saved['post_id'] ?? 0 ),
					'product_name' => (string) $product['product_name'],
					'review_count' => count( $reviews ),
				),
				(int) ( $saved['post_id'] ?? 0 ),
				self::elapsed_ms( $started )
			);
			return $saved;
		} catch ( Skippable_Exception $e ) {
			self::record_skip(
				is_array( $product ) ? $product : array(),
				$e->getMessage(),
				self::elapsed_ms( $started ),
				$force
			);
			throw $e;
		} catch ( \Throwable $e ) {
			Event_Logger::record(
				Event_Logger::TYPE_GENERATE,
				Event_Logger::LEVEL_ERROR,
				sprintf(
					__( '記事生成に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$e->getMessage()
				),
				array( 'force' => $force ),
				0,
				self::elapsed_ms( $started )
			);
			throw $e;
		} finally {
			Generate_Progress::finish( $user_id );
		}
	}
	private static function elapsed_ms( float $started ): int {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}
	private static function record_collect_event( array $collected, int $elapsed_ms ): void {
		$posts    = (int) ( $collected['posts'] ?? 0 );
		$comments = (int) ( $collected['comments'] ?? 0 );
		$errors   = array_values( array_filter( array_map( 'strval', (array) ( $collected['errors'] ?? array() ) ), 'strlen' ) );
		if ( ! empty( $collected['skipped'] ) ) {
			Event_Logger::record(
				Event_Logger::TYPE_COLLECT,
				Event_Logger::LEVEL_INFO,
				(string) ( $collected['message'] ?? '' ),
				array( 'source' => '', 'skipped' => true ),
				0,
				$elapsed_ms
			);
			return;
		}
		$payload = array(
			'source'   => '',
			'posts'    => $posts,
			'comments' => $comments,
			'found'    => (int) ( $collected['found'] ?? 0 ),
			'searches' => (array) ( $collected['searches'] ?? array() ),
		);
		if ( array() !== $errors ) {
			$payload['errors'] = $errors;
		}
		Event_Logger::record(
			Event_Logger::TYPE_COLLECT,
			array() === $errors ? Event_Logger::LEVEL_INFO : Event_Logger::LEVEL_WARN,
			sprintf(
				__( 'レビューを取得しました（投稿 %1$d / コメント %2$d）。', GACHIREAI_TEXT_DOMAIN ),
				$posts,
				$comments
			),
			$payload,
			0,
			$elapsed_ms
		);
	}
	private static function record_save_events( array $saved ): void {
		$linked = 0; 
		$search = 0; 
		$boxes  = array();
		foreach ( (array) ( $saved['affiliate_boxes'] ?? array() ) as $box ) {
			if ( ! is_array( $box ) ) {
				continue;
			}
			$status = (string) ( $box['status'] ?? '' );
			if ( 'matched' === $status ) {
				++$linked;
			} else {
				++$search;
			}
			$boxes[] = array(
				'provider' => (string) ( $box['provider'] ?? '' ),
				'status'   => $status,
				'message'  => (string) ( $box['message'] ?? '' ),
			);
		}
		Event_Logger::record(
			Event_Logger::TYPE_GENERATE,
			( array() !== $boxes && 0 === $linked ) ? Event_Logger::LEVEL_WARN : Event_Logger::LEVEL_INFO,
			sprintf(
				__( 'アフィリエイト: 商品リンク %1$d 件（メインEC）／検索ボタンのみ %2$d 件。', GACHIREAI_TEXT_DOMAIN ),
				$linked,
				$search
			),
			array( 'boxes' => $boxes )
		);
		$tags     = array_values( array_filter( array_map( 'strval', (array) ( $saved['tags'] ?? array() ) ), 'strlen' ) );
		$created  = array_values( array_filter( array_map( 'strval', (array) ( $saved['tags_created'] ?? array() ) ), 'strlen' ) );
		$warnings = array_values( array_filter( array_map( 'strval', (array) ( $saved['term_warnings'] ?? array() ) ), 'strlen' ) );
		Event_Logger::record(
			Event_Logger::TYPE_GENERATE,
			array() === $warnings ? Event_Logger::LEVEL_INFO : Event_Logger::LEVEL_WARN,
			sprintf(
				__( 'カテゴリー「%1$s」・タグ %2$d 件を割り当てました。', GACHIREAI_TEXT_DOMAIN ),
				(string) ( $saved['category_name'] ?? '' ),
				count( $tags )
			),
			array(
				'category'         => (string) ( $saved['category_name'] ?? '' ),
				'category_created' => (bool) ( $saved['category_created'] ?? false ),
				'tags'             => $tags,
				'tags_created'     => $created,
				'warnings'         => $warnings,
			)
		);
	}
	private function product_of( array $product ): array {
		return array(
			'product_name' => (string) ( $product['product_name'] ?? '' ),
			'brand'        => (string) ( $product['brand'] ?? '' ),
			'model_number' => (string) ( $product['model_number'] ?? '' ),
			'jan_code'     => (string) ( $product['jan_code'] ?? '' ),
			'is_generic'   => (bool) ( $product['is_generic'] ?? false ),
			'item'         => (array) ( $product['item'] ?? array() ),
		);
	}
	private function assert_postable( array $product ): void {
		$name_key = Product_Ledger::name_key( (string) ( $product['product_name'] ?? '' ) );
		if ( '' === $name_key ) {
			return;
		}
		$found = $this->ledger->find(
			(string) ( $product['jan_code'] ?? '' ),
			(string) ( $product['model_number'] ?? '' ),
			$name_key
		);
		if ( array() !== $found ) {
			if ( ! $this->ledger->can_post( $found['row'] ) ) {
				throw new Reuse_Interval_Exception( $this->reuse_message() );
			}
			return;
		}
		if ( in_array( $name_key, $this->used_recent_sources()['product_names'], true ) ) {
			throw new Reuse_Interval_Exception( $this->reuse_message() );
		}
	}
	private function assert_not_excluded( array $product ): void {
		$name_key = Product_Ledger::name_key( (string) ( $product['product_name'] ?? '' ) );
		if ( '' === $name_key ) {
			return;
		}
		$found = $this->ledger->find(
			(string) ( $product['jan_code'] ?? '' ),
			(string) ( $product['model_number'] ?? '' ),
			$name_key
		);
		if ( array() !== $found && $this->ledger->is_excluded( $found['row'] ) ) {
			throw new Product_Excluded_Exception(
				__( '選定した商品は「投稿対象外」に設定されているため、記事を作成できません。', GACHIREAI_TEXT_DOMAIN )
			);
		}
	}
	public function assert_usable( array $product ): void {
		$this->assert_not_excluded( $product );
		$this->assert_postable( $product );
	}
	public static function record_skip( array $product, string $reason, int $elapsed_ms = 0, bool $force = false ): void {
		$name = (string) ( $product['product_name'] ?? '' );
		Event_Logger::record(
			Event_Logger::TYPE_GENERATE,
			Event_Logger::LEVEL_WARN,
			sprintf(
				__( '生成をスキップしました: %1$s（%2$s）', GACHIREAI_TEXT_DOMAIN ),
				$name,
				$reason
			),
			array(
				'force'        => $force,
				'product_name' => $name,
				'item_code'    => (string) ( $product['item']['item_code'] ?? '' ),
				'skip'         => true,
			),
			0,
			$elapsed_ms
		);
	}
	private function register_product( string $ec, array $product, string $query, string $genre_id = '' ): int {
		$result = $this->ledger->register( $ec, $product, $query, $genre_id );
		return (int) ( $result['id'] ?? 0 );
	}
	private function record_posted( int $ledger_id, int $post_id ): void {
		if ( $ledger_id <= 0 || $post_id <= 0 ) {
			return;
		}
		$this->ledger->mark_posted( $ledger_id, $post_id );
		update_post_meta( $post_id, 'gachireai_product_id', $ledger_id );
	}
	private function reuse_message(): string {
		if ( Reuse_Interval::is_permanent() ) {
			return __( '選定した商品は「再投稿しない（永久）」に設定されているため、記事を作成できません。', GACHIREAI_TEXT_DOMAIN );
		}
		return sprintf(
			__( '選定した商品の記事を直近 %d 日に作成しています。同じ商品の記事は %d 日経過すると再び作成できます。', GACHIREAI_TEXT_DOMAIN ),
			Reuse_Interval::days(),
			Reuse_Interval::days()
		);
	}
	private function selection_message( array $selection ): string {
		$message = trim( (string) ( $selection['message'] ?? '' ) );
		if ( '' !== $message ) {
			return $message;
		}
		return __( 'EC で対象商品を選定できませんでした。「レビュー設定」のカテゴリー・キーワードを見直してください。', GACHIREAI_TEXT_DOMAIN );
	}
	private function empty_reviews_exception( bool $force, bool $matched, int $excluded = 0, int $used = 0 ): \RuntimeException {
		if ( ! $this->has_threads() ) {
			return new No_Usable_Reviews_Exception(
				__( '選定した商品のレビューを収集できませんでした。レビュー取得設定の検索先（5ちゃんねるの板・Reddit のサブレディット）と YouTube の API キーを確認して、もう一度お試しください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		if ( ! $matched ) {
			return new No_Usable_Reviews_Exception(
				__( '収集したレビューの中に、この商品に一致する投稿が見つかりませんでした。商品名の表記を変えるか、時間をおいてもう一度お試しください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		if ( 0 < $excluded ) {
			$message = sprintf(
				__( 'この商品に一致する投稿 %d 件は、Jev の判定で除外されました（商品のレビューでない、または広告・宣伝と判定）。記事に使えるレビューがありません。', GACHIREAI_TEXT_DOMAIN ),
				$excluded
			);
			if ( 0 < $used ) {
				$message .= ' ' . sprintf(
					__( '直近の記事で使用済みの %d 件は含みません。', GACHIREAI_TEXT_DOMAIN ),
					$used
				);
			}
			return new No_Usable_Reviews_Exception( $message );
		}
		if ( Reuse_Interval::is_permanent() ) {
			return new No_Usable_Reviews_Exception(
				__( '収集したレビューは「再投稿しない（永久）」の設定によりすべて使用済みのため、記事を作成できません。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$message = sprintf(
			__( 'この商品のレビューは直近の記事で使用済みです。同じ商品の記事は %d 日経過すると再び作成できます。', GACHIREAI_TEXT_DOMAIN ),
			Reuse_Interval::days()
		);
		return $force ? new \RuntimeException( $message ) : new Reuse_Interval_Exception( $message );
	}
	private function ai_reviews( Site_Profile $profile, array $product, int $existing ): array {
		$shortfall = Ai_Review::required_count() - $existing;
		if ( $shortfall <= 0 ) {
			return array();
		}
		return ( new Ai_Review_Generator( $this->settings ) )->generate( $profile, $product, $shortfall );
	}
	private function pick_reviews( string $product_name, bool $force = false ): array {
		$needles = $this->name_needles( $product_name );
		$limit   = self::REVIEW_LIMITS[ $this->article_type() ] ?? self::REVIEW_LIMITS['summary'];
		if ( ! Ai_Review::is_off() ) {
			$limit = max( $limit, Ai_Review::required_count() );
		}
		$used_keys = $force ? array() : $this->used_recent_sources()['source_keys'];
		$picked    = array();
		$matched   = false;
		$excluded  = 0;
		$used      = 0;
		foreach ( $this->top_threads( $product_name ) as $thread ) {
			if ( count( $picked ) >= $limit ) {
				break;
			}
			$thread_source = (string) ( $thread['source'] ?? '' );
			$by_product    = Ec_Review_Collector::SOURCE === $thread_source || Source_Settings::is_sns( $thread_source );
			$matches = $by_product
				? self::saved_product_matches( $thread, $needles )
				: self::title_matches( (string) ( $thread['title'] ?? '' ), $needles );
			if ( ! $matches ) {
				continue;
			}
			$matched = true;
			if ( Jev_Questions::is_excluded( Jev_Questions::verdict_of( (string) ( $thread['judge'] ?? '' ) ) ) ) {
				++$excluded;
				continue;
			}
			$key = self::source_key( $thread );
			if ( '' !== $key && in_array( $key, $used_keys, true ) ) {
				++$used;
				continue;
			}
			$picked[] = $thread;
		}
		return array(
			'picked'   => $picked,
			'matched'  => $matched,
			'excluded' => $excluded,
			'used'     => $used,
		);
	}
	private function name_needles( string $product_name ): array {
		$name = Product_Ledger::name_key( $product_name );
		if ( '' === $name ) {
			return array();
		}
		$needles = array( $name );
		$words   = preg_split( '/\s+/u', $name );
		foreach ( is_array( $words ) ? $words : array() as $word ) {
			if ( mb_strlen( $word ) >= 4 ) {
				$needles[] = $word;
			}
		}
		return array_values( array_unique( $needles ) );
	}
	private static function title_matches( string $title, array $needles ): bool {
		$haystack = mb_strtolower( $title );
		foreach ( $needles as $needle ) {
			if ( '' !== $needle && str_contains( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}
	private static function saved_product_matches( array $thread, array $needles ): bool {
		$product = Product_Ledger::name_key( (string) ( $thread['product_name'] ?? '' ) );
		return '' !== $product && in_array( $product, $needles, true );
	}
	private function used_recent_sources(): array {
		if ( Reuse_Interval::is_unlimited() ) {
			return array(
				'source_keys'   => array(),
				'product_names' => array(),
			);
		}
		$args = array(
			'post_type'      => 'post',
			'post_status'    => array( 'draft', 'publish', 'pending', 'future', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => 'gachireai_source_id',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => 'gachireai_source_reddit_id',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => 'gachireai_product_name',
					'compare' => 'EXISTS',
				),
			),
		);
		if ( ! Reuse_Interval::is_permanent() ) {
			$args['date_query'] = array(
				array(
					'after'     => Reuse_Interval::days() . ' days ago',
					'inclusive' => true,
				),
			);
		}
		$posts = get_posts( $args );
		$source_keys   = array();
		$product_names = array();
		foreach ( $posts as $post_id ) {
			foreach ( self::article_source_keys( (int) $post_id ) as $source_key ) {
				$source_keys[] = $source_key;
			}
			$name = Product_Ledger::name_key( (string) get_post_meta( (int) $post_id, 'gachireai_product_name', true ) );
			if ( '' !== $name ) {
				$product_names[] = $name;
			}
		}
		return array(
			'source_keys'   => array_values( array_unique( $source_keys ) ),
			'product_names' => array_values( array_unique( $product_names ) ),
		);
	}
	private static function article_source_keys( int $post_id ): array {
		$sources    = get_post_meta( $post_id, 'gachireai_source', true );
		$source_ids = get_post_meta( $post_id, 'gachireai_source_id', true );
		$sources    = is_array( $sources ) ? array_values( $sources ) : array( $sources );
		$source_ids = is_array( $source_ids ) ? array_values( $source_ids ) : array( $source_ids );
		$keys       = array();
		foreach ( $source_ids as $index => $id ) {
			$id = trim( (string) $id );
			if ( '' === $id ) {
				continue;
			}
			$source = trim( (string) ( $sources[ $index ] ?? '' ) );
			$keys[] = ( '' !== $source ? $source : 'reddit' ) . ':' . $id;
		}
		$reddit_id = trim( (string) get_post_meta( $post_id, 'gachireai_source_reddit_id', true ) );
		if ( '' !== $reddit_id ) {
			$keys[] = 'reddit:' . $reddit_id;
		}
		return array_values( array_unique( $keys ) );
	}
	private static function source_key( array $thread ): string {
		$source      = (string) ( $thread['source'] ?? '' );
		$external_id = (string) ( $thread['external_id'] ?? '' );
		if ( '' === $source || '' === $external_id ) {
			return '';
		}
		return $source . ':' . $external_id;
	}
	private function top_threads( string $product_name = '' ): array {
		$lists = array();
		$sources = array_merge(
			array( Ec_Review_Collector::SOURCE, Reddit_Collector::SOURCE, Fivech_Collector::SOURCE, YouTube_Collector::SOURCE, Hacker_News_Collector::SOURCE, Mastodon_Collector::SOURCE ),
			Source_Settings::PARTNER_SNS
		);
		foreach ( $sources as $source ) {
			$lists[] = $this->threads_by_source( $source, $product_name );
		}
		return self::interleave( ...$lists );
	}
	private function threads_by_source( string $source, string $product_name = '' ): array {
		global $wpdb;
		$table = Activator::table( 'threads' );
		$by_product = Ec_Review_Collector::SOURCE === $source || Source_Settings::is_sns( $source );
		$stored     = $by_product ? Ec_Review_Collector::stored_product_name( $product_name ) : '';
		$where  = 'source = %s';
		$params = array( $source );
		if ( '' !== $stored ) {
			$where   .= ' AND product_name = %s';
			$params[] = $stored;
		}
		$params[] = self::MAX_CANDIDATE_THREADS;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source, external_id, community, title, product_name, permalink, url, score, num_comments, judge, rating FROM {$table} WHERE {$where} ORDER BY score DESC, num_comments DESC, created_utc DESC LIMIT %d",
				$params
			),
			ARRAY_A
		);
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}
	private static function interleave( array ...$lists ): array {
		$merged = array();
		$max    = 0;
		foreach ( $lists as $list ) {
			$max = max( $max, count( $list ) );
		}
		for ( $i = 0; $i < $max; $i++ ) {
			foreach ( $lists as $list ) {
				if ( isset( $list[ $i ] ) ) {
					$merged[] = $list[ $i ];
				}
			}
		}
		return $merged;
	}
	private function reviews_material( array $reviews ): string {
		$chunks = array();
		$total  = 0;
		foreach ( $reviews as $index => $thread ) {
			$header  = $this->review_header( $thread, (int) $index + 1 );
			$text    = $this->thread_text( $thread );
			$chunk   = '' !== $text ? $header . "\n" . $text : $header;
			$chunks[] = $chunk;
			$total   += mb_strlen( $chunk );
			if ( $total >= self::MAX_MATERIAL_TEXT ) {
				break;
			}
		}
		$material = implode( "\n\n", $chunks );
		if ( mb_strlen( $material ) > self::MAX_MATERIAL_TEXT ) {
			$material = mb_substr( $material, 0, self::MAX_MATERIAL_TEXT ) . '…';
		}
		return $material;
	}
	private function review_header( array $thread, int $index ): string {
		$title   = trim( (string) ( $thread['title'] ?? '' ) );
		$context = self::source_context( $thread );
		$notes   = self::review_notes( $thread );
		if ( '' !== $title ) {
			return '' !== $context
				? sprintf( '【レビュー %d】%s｜%s%s', $index, $context, $title, $notes )
				: sprintf( '【レビュー %d】%s%s', $index, $title, $notes );
		}
		return '' !== $context
			? sprintf( '【レビュー %d】%s%s', $index, $context, $notes )
			: sprintf( '【レビュー %d】%s', $index, $notes );
	}
	private static function review_notes( array $thread ): string {
		$notes   = array();
		$answers = Jev_Questions::answers_of( (string) ( $thread['judge'] ?? '' ) );
		if ( Jev_Questions::has_criticism( $answers ) ) {
			$notes[] = __( '欠点の指摘あり', GACHIREAI_TEXT_DOMAIN );
		}
		if ( Ec_Review_Collector::SOURCE === (string) ( $thread['source'] ?? '' ) ) {
			$rating = $thread['rating'] ?? null;
			if ( is_numeric( $rating ) && 0 < (float) $rating ) {
				$notes[] = sprintf(
					__( '星 %s', GACHIREAI_TEXT_DOMAIN ),
					number_format( (float) $rating, 2 )
				);
			}
		}
		return array() === $notes ? '' : '（' . implode( '・', $notes ) . '）';
	}
	private function thread_text( array $thread ): string {
		$text = trim( (string) ( $thread['text'] ?? '' ) );
		if ( '' !== $text ) {
			return $text;
		}
		return $this->thread_comments_text( (int) ( $thread['id'] ?? 0 ) );
	}
	private function thread_comments_text( int $thread_id ): string {
		global $wpdb;
		$table = Activator::table( 'comments' );
		$bodies = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT body FROM {$table} WHERE thread_id = %d ORDER BY score DESC LIMIT %d",
				$thread_id,
				self::MAX_COMMENTS
			)
		);
		$bodies = is_array( $bodies ) ? $bodies : array();
		$text   = implode( "\n\n", array_filter( array_map( 'trim', array_map( 'strval', $bodies ) ), 'strlen' ) );
		if ( mb_strlen( $text ) > self::MAX_THREAD_TEXT ) {
			$text = mb_substr( $text, 0, self::MAX_THREAD_TEXT ) . '…';
		}
		return $text;
	}
	private function has_threads(): bool {
		global $wpdb;
		$table = Activator::table( 'threads' );
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		return null !== $count && 0 < (int) $count;
	}
	private function generate_draft( Site_Profile $profile, array $product, array $reviews ): array {
		$llm = $this->settings->active_llm();
		if ( '' === (string) ( $llm['api_key'] ?? '' ) ) {
			throw new \RuntimeException( __( 'API キーが未設定のため記事を生成できません。「AI設定」タブで LLM の API キーを保存してください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$material = $this->reviews_material( $reviews );
		$type = $this->article_type();
		$provider = Manager::provider_for( $llm, 'article.system.' . $type );
		$data     = Manager::generate_json(
			$provider,
			self::system_prompt( $type ),
			self::user_prompt( $profile, $product, $material, $type, $this->dialogue_context() ),
			array(
				'temperature' => 0.9,
				'max_tokens'  => self::MAX_TOKENS,
			)
		);
		$article = array(
			'title'    => self::clean_text( $data['title'] ?? '' ),
			'lead'     => self::clean_text( $data['lead'] ?? '' ),
			'body'     => self::normalize_body( $data['body'] ?? array() ),
			'category' => self::clean_text( $data['category'] ?? '' ),
			'tags'     => self::normalize_names( $data['tags'] ?? array() ),
			'slug'     => self::clean_text( $data['slug'] ?? '' ),
		);
		if ( '' === $article['title'] || array() === $article['body'] ) {
			throw new \RuntimeException( __( 'AI の応答から記事を取り出せませんでした。もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return $article;
	}
	private function save_post( array $article, array $product, array $reviews, array $collected, Site_Profile $profile, ?int $author_id = null, bool $with_eyecatch = true ): array {
		Generate_Progress::update( get_current_user_id(), __( '商品を照合中...', GACHIREAI_TEXT_DOMAIN ), Generate_Progress::STEP_AFFILIATE, Generate_Progress::STEP_TOTAL );
		$affiliate = $this->affiliate( $product );
		$affiliate_context = $this->ec_affiliate_context();
		$affiliate_item  = (array) ( $affiliate['item'] ?? array() );
		$review_page_url = (string) ( $affiliate_item['item_url'] ?? '' );
		if ( '' === $review_page_url ) {
			$review_page_url = (string) ( $affiliate_item['affiliate_url'] ?? '' );
		}
		$source_credits = self::source_credits_map( $reviews, $affiliate_context, $review_page_url );
		Generate_Progress::update( get_current_user_id(), __( '本文を組み立て中...', GACHIREAI_TEXT_DOMAIN ), Generate_Progress::STEP_ASSEMBLE, Generate_Progress::STEP_TOTAL );
		$parts = array();
		$lead = trim( (string) $article['lead'] );
		if ( '' !== $lead ) {
			$parts[] = $this->render_article_body( array( array( 'type' => 'p', 'text' => $lead, 'items' => array() ) ), $source_credits );
		}
		$parts[] = $this->render_article_body( $article['body'], $source_credits );
		$content = implode( "\n\n", array_filter( $parts, 'strlen' ) );
		if ( ! empty( $affiliate['has_box'] ) ) {
			$content = Box_Positioner::position_shortcodes(
				$content,
				Card_Settings::positions( $this->settings )
			);
		}
		$disclosure_top  = Disclosure_Shortcode::block( Settings::DISCLOSURE_POSITION_TOP );
		$eyecatch_prefix = Eyecatch_Shortcode::prefix_blocks();
		$prefix_blocks   = array_filter( array( $disclosure_top, $eyecatch_prefix ), 'strlen' );
		if ( array() !== $prefix_blocks ) {
			$content = implode( "\n\n", $prefix_blocks ) . "\n\n" . $content;
		}
		$disclosure_bottom = Disclosure_Shortcode::block( Settings::DISCLOSURE_POSITION_BOTTOM );
		if ( '' !== $disclosure_bottom ) {
			$content = $content . "\n\n" . $disclosure_bottom;
		}
		Generate_Progress::update( get_current_user_id(), __( '記事を保存中...', GACHIREAI_TEXT_DOMAIN ), Generate_Progress::STEP_SAVE, Generate_Progress::STEP_TOTAL );
		$slug = self::post_slug( $article );
		$post_args = array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => $article['title'],
			'post_content' => $content,
			'post_author'  => ( null !== $author_id && $author_id > 0 ) ? $author_id : get_current_user_id(),
		);
		if ( '' !== $slug ) {
			$post_args['post_name'] = $slug;
		}
		$post_id = wp_insert_post( $post_args, true );
		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException(
				sprintf(
					__( '記事の保存に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$post_id->get_error_message()
				)
			);
		}
		$post_id = (int) $post_id;
		$item    = (array) $affiliate['item'];
		$origin = self::origin_data( $reviews );
		$meta = array(
			'gachireai_product_name'     => (string) $product['product_name'],
			'gachireai_product_brand'    => (string) $product['brand'],
			'gachireai_product_model'    => (string) $product['model_number'],
			'gachireai_source'           => $origin['sources'],
			'gachireai_source_id'        => $origin['source_ids'],
			'gachireai_source_community' => $origin['communities'],
			'gachireai_source_url'       => $origin['urls'],
			'gachireai_source_reddit_id' => (string) ( $origin['reddit_ids'][0] ?? '' ),
			'gachireai_source_subreddit' => (string) ( $origin['subreddits'][0] ?? '' ),
			'gachireai_quality_score'    => 0.0,
			'gachireai_article_type'     => $this->article_type(),
			'gachireai_affiliate_provider'   => (string) $affiliate['provider'],
			'gachireai_affiliate_status'     => (string) $affiliate['status'],
			'gachireai_affiliate_item_code'  => (string) ( $item['item_code'] ?? '' ),
			'gachireai_affiliate_item_name'  => (string) ( $item['item_name'] ?? '' ),
			'gachireai_affiliate_price'      => (int) ( $item['item_price'] ?? 0 ),
			'gachireai_affiliate_url'        => (string) ( $item['affiliate_url'] ?? '' ),
			'gachireai_affiliate_image'      => (string) ( $item['image_url'] ?? '' ),
			'gachireai_affiliate_search_url' => (string) $affiliate['search_url'],
			'gachireai_affiliate_reason'     => (string) $affiliate['message'],
			'gachireai_affiliate_boxes'      => (array) $affiliate['boxes'],
			'gachireai_source_credits'       => $source_credits,
		);
		$meta += self::settings_trace_meta( $profile );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		$this->insert_candidate( $post_id, $product, $affiliate );
		$terms = ( new Term_Assigner() )->assign(
			$post_id,
			(string) ( $article['category'] ?? '' ),
			(array) ( $article['tags'] ?? array() )
		);
		$eyecatch          = array(
			'status'        => 'skipped',
			'message'       => '',
			'attachment_id' => 0,
			'warnings'      => array(),
		);
		$eyecatch_warnings = array();
		if ( $with_eyecatch ) {
			try {
				Generate_Progress::update( get_current_user_id(), __( 'アイキャッチ画像を生成中...', GACHIREAI_TEXT_DOMAIN ), Generate_Progress::STEP_EYECATCH, Generate_Progress::STEP_TOTAL );
				$eyecatch = ( new Eyecatch() )->generate_for_post(
					$post_id,
					(string) $article['title'],
					$product,
					$profile
				);
			} catch ( \Throwable $e ) {
				$eyecatch = array(
					'status'        => 'failed',
					'message'       => $e->getMessage(),
					'attachment_id' => 0,
					'warnings'      => array(),
				);
			}
			if ( in_array( (string) $eyecatch['status'], array( 'failed', 'skipped' ), true ) && '' !== trim( (string) $eyecatch['message'] ) ) {
				$eyecatch_warnings[] = (string) $eyecatch['message'];
				Event_Logger::record(
					Event_Logger::TYPE_GENERATE,
					Event_Logger::LEVEL_WARN,
					sprintf(
						__( 'アイキャッチ画像: %s', GACHIREAI_TEXT_DOMAIN ),
						(string) $eyecatch['message']
					),
					array( 'eyecatch_status' => (string) $eyecatch['status'] ),
					$post_id
				);
			}
			foreach ( (array) ( $eyecatch['warnings'] ?? array() ) as $warning ) {
				if ( is_scalar( $warning ) && '' !== trim( (string) $warning ) ) {
					$eyecatch_warnings[] = (string) $warning;
				}
			}
		}
		return array(
			'post_id'      => $post_id,
			'post_title'   => $article['title'],
			'edit_url'     => (string) get_edit_post_link( $post_id, 'raw' ),
			'preview_url'  => (string) get_preview_post_link( $post_id ),
			'product_name' => (string) $product['product_name'],
			'source'       => (string) ( $origin['sources'][0] ?? '' ),
			'community'    => (string) ( $origin['communities'][0] ?? '' ),
			'sources'      => $origin['pairs'],
			'subreddit'    => (string) ( $origin['subreddits'][0] ?? '' ),
			'posts'        => (int) ( $collected['posts'] ?? 0 ),
			'comments'     => (int) ( $collected['comments'] ?? 0 ),
			'affiliate_status'     => (string) $affiliate['status'],
			'affiliate_message'    => (string) $affiliate['message'],
			'affiliate_item_name'  => (string) ( $item['item_name'] ?? '' ),
			'affiliate_price'      => (int) ( $item['item_price'] ?? 0 ),
			'affiliate_has_item'   => (bool) $affiliate['has_item'],
			'affiliate_has_box'    => (bool) $affiliate['has_box'],
			'affiliate_search_url' => (string) $affiliate['search_url'],
			'affiliate_boxes'      => (array) $affiliate['boxes'],
			'category_name'    => null !== $terms['category'] ? (string) $terms['category']['name'] : '',
			'category_created' => null !== $terms['category'] ? (bool) $terms['category']['created'] : false,
			'tags'             => array_values(
				array_map(
					static fn( array $tag ): string => (string) $tag['name'],
					$terms['tags']
				)
			),
			'tags_created'     => array_values(
				array_map(
					static fn( array $tag ): string => (string) $tag['name'],
					array_filter(
						$terms['tags'],
						static fn( array $tag ): bool => (bool) $tag['created']
					)
				)
			),
			'term_warnings'    => array_values( array_filter( array_map( 'strval', $terms['warnings'] ), 'strlen' ) ),
			'eyecatch_status'        => (string) $eyecatch['status'],
			'eyecatch_message'       => (string) $eyecatch['message'],
			'eyecatch_attachment_id' => (int) ( $eyecatch['attachment_id'] ?? 0 ),
			'eyecatch_warnings'      => array_values( array_filter( $eyecatch_warnings, 'strlen' ) ),
		);
	}
	private static function post_slug( array $article ): string {
		$slug = sanitize_title( (string) ( $article['slug'] ?? '' ) );
		if ( '' !== $slug && function_exists( 'wp_unique_post_slug' ) ) {
			$slug = (string) wp_unique_post_slug( $slug, 0, 'draft', 'post', 0 );
		}
		return $slug;
	}
	private static function settings_trace_meta( Site_Profile $profile ): array {
		return array(
			'gachireai_profile_version'  => $profile->version(),
			'gachireai_profile_snapshot' => self::profile_snapshot( $profile ),
			'gachireai_app_version'      => GACHIREAI_APP_VERSION,
		);
	}
	private static function profile_snapshot( Site_Profile $profile ): string {
		$snapshot = wp_json_encode(
			array(
				'my_persona'     => $profile->get_my_persona(),
				'scope'          => $profile->get_scope(),
				'target_persona' => $profile->get_target_persona(),
				'site_elements'  => $profile->get_site_elements(),
			)
		);
		return is_string( $snapshot ) ? $snapshot : '';
	}
	private static function origin_data( array $reviews ): array {
		$sources     = array();
		$source_ids  = array();
		$communities = array();
		$urls        = array();
		$pairs       = array();
		$reddit_ids  = array();
		$subreddits  = array();
		foreach ( $reviews as $thread ) {
			$source        = (string) ( $thread['source'] ?? '' );
			$community     = (string) ( $thread['community'] ?? '' );
			$sources[]     = $source;
			$source_ids[]  = (string) ( $thread['external_id'] ?? '' );
			$communities[] = $community;
			$urls[]        = self::thread_url( $thread );
			$pairs[]       = array(
				'source'    => $source,
				'community' => $community,
			);
			if ( 'reddit' === $source ) {
				$reddit_ids[] = (string) ( $thread['external_id'] ?? '' );
				$subreddits[] = $community;
			}
		}
		return array(
			'sources'     => $sources,
			'source_ids'  => $source_ids,
			'communities' => $communities,
			'urls'        => $urls,
			'pairs'       => $pairs,
			'reddit_ids'  => $reddit_ids,
			'subreddits'  => $subreddits,
		);
	}
	private function affiliate( array $product ): array {
		$product_name  = (string) ( $product['product_name'] ?? '' );
		$selected_item = (array) ( $product['item'] ?? array() );
		$pattern   = Card_Settings::pattern( $this->settings );
		$ec_group  = (array) $this->settings->get( 'ec', array() );
		$base_ec   = Product_Selector::normalize_ec( (string) ( $ec_group['ec'] ?? '' ) );
		$boxes     = array();
		$searches  = array();
		$base_box  = array();
		$providers = $this->affiliate_providers();
		$enabled   = Card_Settings::enabled_ecs( $this->settings );
		foreach ( $providers as $provider ) {
			$slug     = (string) $provider['provider'];
			$class    = (string) $provider['class'];
			$settings = (array) $provider['settings'];
			$search   = ( new $class() )->search_url( $product_name, $settings );
			$is_base  = $slug === $base_ec;
			$plain_search = method_exists( $class, 'plain_search_url' )
				? (string) ( new $class() )->plain_search_url( $product_name )
				: '';
			$item     = $is_base ? $selected_item : array();
			$has_link = $is_base && '' !== Box_Renderer::product_link( $item );
			if ( $is_base && array() !== $item && '' === trim( (string) ( $item['price_fetched_at'] ?? '' ) ) ) {
				$item['price_fetched_at'] = current_time( 'mysql' );
			}
			$box = array(
				'provider'         => $slug,
				'label'            => (string) $provider['label'],
				'status'           => $is_base ? $class::STATUS_MATCHED : Providers::STATUS_SEARCH_ONLY,
				'message'          => '',
				'item'             => $item,
				'search_url'       => $search,
				'plain_search_url' => $plain_search,
				'has_item'         => $has_link,
				'has_box'          => $has_link || '' !== $search,
			);
			$boxes[] = $box;
			if ( $is_base ) {
				$base_box = $box;
			}
			if ( in_array( $slug, $enabled, true ) ) {
				$searches[] = array(
					'slug'  => $slug,
					'label' => (string) $provider['label'],
					'url'   => $search,
					'icon'  => (string) ( $provider['icon'] ?? '' ),
				);
			}
		}
		$lead_boxes = array();
		$cta_boxes  = array();
		$base_item  = (array) ( $base_box['item'] ?? array() );
		if ( '' !== Box_Renderer::product_link( $base_item ) || array() !== $searches ) {
			try {
				$renderer     = new Box_Renderer();
				$lead_boxes[] = $renderer->render( $base_item, $searches, false, $product_name, $pattern );
				$cta_boxes[]  = $renderer->render( $base_item, $searches, true, $product_name, $pattern );
			} catch ( \Throwable $e ) {
				$lead_boxes = array();
				$cta_boxes  = array();
				if ( array() !== $boxes ) {
					$boxes[0]['has_box'] = false;
					$boxes[0]['message'] = trim( (string) $boxes[0]['message'] . ' ' . $this->box_error( $e ) );
				}
			}
		}
		$primary = array() !== $base_box ? $base_box : ( array() === $boxes ? array() : $boxes[0] );
		return array(
			'provider'   => (string) ( $primary['provider'] ?? '' ),
			'status'     => (string) ( $primary['status'] ?? '' ),
			'message'    => array() === $boxes
				? __( '有効な ASP（接続テストに合格し、アフィリエイトID/コードを設定した EC）が無いため、商品ボックスを挿入していません。「ASP設定」タブで設定してください。', GACHIREAI_TEXT_DOMAIN )
				: (string) ( $primary['message'] ?? '' ),
			'item'       => (array) ( $primary['item'] ?? array() ),
			'search_url' => (string) ( $primary['search_url'] ?? '' ),
			'has_item'   => (bool) ( $primary['has_item'] ?? false ),
			'has_box'    => array() !== $lead_boxes || array() !== $cta_boxes,
			'lead_box'   => implode( "\n", $lead_boxes ),
			'cta_box'    => implode( "\n", $cta_boxes ),
			'boxes'      => $boxes,
		);
	}
	private function affiliate_providers(): array {
		return Providers::valid( $this->settings );
	}
	private function box_error( \Throwable $e ): string {
		return sprintf(
			__( '商品ボックスを描画できませんでした: %s', GACHIREAI_TEXT_DOMAIN ),
			$e->getMessage()
		);
	}
	private function render_article_body( array $blocks, array $source_credits = array() ): string {
		if ( array() === $blocks ) {
			return '';
		}
		$model = array( 'blocks' => array() );
		$dialogue_article = 'dialogue' === $this->article_type();
		foreach ( $blocks as $block ) {
			$type = (string) ( $block['type'] ?? 'p' );
			$attrs = '';
			if ( 'h2' === $type ) {
				$attrs = '{"level":2}';
			} elseif ( 'h3' === $type ) {
				$attrs = '{"level":3}';
			}
			$review   = 0;
			$cite_url = '';
			$has_cite = false;
			if ( 'quote' === $type ) {
				$review = (int) ( $block['review'] ?? 0 );
				$credit = ( $review > 0 && isset( $source_credits[ $review ] ) ) ? (array) $source_credits[ $review ] : array();
				if ( array() !== $credit ) {
					$has_cite = true;
					$cite_url = (string) ( $credit['url'] ?? '' );
				}
			}
			$block_source_reviews = array();
			if ( in_array( $type, array( 'p', 'ul', 'dialogue' ), true ) ) {
				foreach ( (array) ( $block['reviews'] ?? array() ) as $number ) {
					$number = (int) $number;
					if ( $number > 0 && isset( $source_credits[ $number ] ) ) {
						$block_source_reviews[] = $number;
					}
				}
				$block_source_reviews = array_values( array_unique( $block_source_reviews ) );
			}
			$dialogue_speaker = '';
			$text             = (string) ( $block['text'] ?? '' );
			if ( $dialogue_article ) {
				if ( 'dialogue' === $type ) {
					$dialogue_speaker = strtolower( trim( (string) ( $block['speaker'] ?? '' ) ) );
					if ( ! in_array( $dialogue_speaker, array( 'mentor', 'mentee' ), true ) ) {
						$dialogue_speaker = self::dialogue_speaker( $text );
					}
				} elseif ( 'p' === $type ) {
					$dialogue_speaker = self::dialogue_speaker( $text );
				}
				if ( '' !== $dialogue_speaker ) {
					$text = self::strip_dialogue_prefix( $text );
				}
			}
			$original = (string) ( $block['original'] ?? '' );
			$model['blocks'][] = array(
				'block_is_h2'           => 'h2' === $type,
				'block_is_h3'           => 'h3' === $type,
				'block_is_ul'           => 'ul' === $type,
				'block_is_quote'        => 'quote' === $type,
				'block_is_p'            => in_array( $type, array( 'p', 'dialogue' ), true ) && '' === $dialogue_speaker,
				'block_is_dialogue'     => '' !== $dialogue_speaker,
				'block_speaker'         => esc_attr( $dialogue_speaker ),
				'block_attrs'           => $attrs,
				'block_text'            => esc_html( $text ),
				'block_items'           => array_map( 'esc_html', array_map( 'strval', (array) ( $block['items'] ?? array() ) ) ),
				'block_has_original'    => '' !== $original,
				'block_original'        => esc_html( $original ),
				'block_original_label'  => esc_html__( '原文', GACHIREAI_TEXT_DOMAIN ),
				'block_has_cite'        => $has_cite,
				'block_cite_has_url'    => '' !== $cite_url,
				'block_cite_url'        => esc_url( $cite_url ),
				'block_review'          => $review,
				'block_has_source_note' => array() !== $block_source_reviews,
				'block_source_reviews'  => implode( ',', array_map( 'strval', $block_source_reviews ) ),
			);
		}
		return trim( ( new Div_Renderer() )->render( 'article/article-body', $model ) );
	}
	private static function dialogue_speaker( string $text ): string {
		$text = ltrim( $text );
		if ( 1 === preg_match( '/^メンター\s*[:：]/u', $text ) ) {
			return 'mentor';
		}
		if ( 1 === preg_match( '/^メンティー\s*[:：]/u', $text ) ) {
			return 'mentee';
		}
		return '';
	}
	private static function strip_dialogue_prefix( string $text ): string {
		return trim( (string) preg_replace( '/^\s*メン(?:ター|ティー)\s*[:：]\s*/u', '', $text, 1 ) );
	}
	private static function source_credits_map( array $reviews, ?array $affiliate_context = null, string $review_page_url = '' ): array {
		$credits = array();
		foreach ( array_values( $reviews ) as $index => $thread ) {
			$thread = (array) $thread;
			$source = (string) ( $thread['source'] ?? '' );
			if ( Ai_Review_Generator::SOURCE === $source ) {
				$slug  = is_array( $affiliate_context ) ? (string) ( $affiliate_context['slug'] ?? '' ) : '';
				$label = '' !== $slug ? Product_Selector::ec_label( $slug ) : '';
				$url   = '' !== $review_page_url
					? self::source_url( 'ec', array( 'url' => $review_page_url ), $affiliate_context )
					: '';
				if ( '' === $label || '' === $url ) {
					continue;
				}
				$credits[ $index + 1 ] = array(
					'url'          => $url,
					'original_url' => $review_page_url,
					'icon_url'     => Icons::ec( $slug ),
					'label'        => $label,
					'source'       => $source,
				);
				continue;
			}
			$label = self::source_label( $source, (string) ( $thread['community'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$credits[ $index + 1 ] = array(
				'url'          => self::source_url( $source, $thread, $affiliate_context ),
				'original_url' => self::thread_url( $thread ),
				'icon_url'     => self::source_icon_url( $source, $affiliate_context ),
				'label'        => $label,
				'source'       => $source,
			);
		}
		return $credits;
	}
	private function ec_affiliate_context(): ?array {
		$ec_group = (array) $this->settings->get( 'ec', array() );
		$base_ec  = Product_Selector::normalize_ec( (string) ( $ec_group['ec'] ?? '' ) );
		$classes  = array(
			Product_Selector::EC_RAKUTEN => Rakuten_Client::class,
			Product_Selector::EC_YAHOO   => Yahoo_Client::class,
			Product_Selector::EC_AMAZON  => Amazon_Client::class,
		);
		if ( ! isset( $classes[ $base_ec ] ) ) {
			return null;
		}
		return array(
			'slug'     => $base_ec,
			'class'    => $classes[ $base_ec ],
			'settings' => (array) $this->settings->get( $base_ec, array() ),
		);
	}
	private static function source_url( string $source, array $thread, ?array $context ): string {
		$url = self::thread_url( $thread );
		if ( Ec_Review_Collector::SOURCE !== $source || '' === $url || null === $context ) {
			return $url;
		}
		$class = (string) ( $context['class'] ?? '' );
		if ( '' === $class || ! method_exists( $class, 'page_affiliate_url' ) ) {
			return $url;
		}
		$affiliate = (string) ( new $class() )->page_affiliate_url( $url, (array) ( $context['settings'] ?? array() ) );
		return '' !== $affiliate ? $affiliate : $url;
	}
	private static function source_icon_url( string $source, ?array $affiliate_context = null ): string {
		if ( Ec_Review_Collector::SOURCE === $source && is_array( $affiliate_context ) ) {
			$slug = (string) ( $affiliate_context['slug'] ?? '' );
			if ( '' !== $slug ) {
				return Icons::ec( $slug );
			}
		}
		return Icons::source( $source );
	}
	private static function source_label( string $source, string $community = '' ): string {
		if ( Ec_Review_Collector::SOURCE === $source ) {
			$community = trim( $community );
			return '' !== $community ? $community : __( 'EC', GACHIREAI_TEXT_DOMAIN );
		}
		return Source_Settings::source_label( $source );
	}
	private function insert_candidate( int $post_id, array $product, array $affiliate ): void {
		global $wpdb;
		$item = (array) $affiliate['item'];
		$now  = current_time( 'mysql' );
		$wpdb->insert(
			Activator::table( 'candidates' ),
			array(
				'post_id'       => $post_id,
				'product_name'  => (string) $product['product_name'],
				'brand'         => (string) $product['brand'],
				'model_number'  => (string) $product['model_number'],
				'provider'      => (string) $affiliate['provider'],
				'status'        => (string) $affiliate['status'],
				'item_code'     => (string) ( $item['item_code'] ?? '' ),
				'item_name'     => (string) ( $item['item_name'] ?? '' ),
				'item_price'    => (int) ( $item['item_price'] ?? 0 ),
				'item_url'      => (string) ( $item['item_url'] ?? '' ),
				'affiliate_url' => (string) ( $item['affiliate_url'] ?? '' ),
				'image_url'     => (string) ( $item['image_url'] ?? '' ),
				'search_url'    => (string) $affiliate['search_url'],
				'reason'        => (string) $affiliate['message'],
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
	}
	private function article_type(): string {
		$article = (array) $this->settings->get( 'article', array() );
		$type    = (string) ( $article['article_type'] ?? 'summary' );
		$allowed = Status::capabilities()->allowed_article_types();
		if ( in_array( $type, $allowed, true ) ) {
			return $type;
		}
		return (string) ( $allowed[0] ?? 'summary' );
	}
	private function dialogue_context(): string {
		$article  = (array) $this->settings->get( 'article', array() );
		$dialogue = (array) ( $article['dialogue'] ?? array() );
		$mentee   = (array) ( $dialogue['mentee'] ?? array() );
		$name   = trim( (string) ( $mentee['name'] ?? '' ) );
		$gender = trim( (string) ( $mentee['gender'] ?? '' ) );
		$age    = trim( (string) ( $mentee['age'] ?? '' ) );
		$tone   = trim( (string) ( $mentee['tone'] ?? '' ) );
		if ( '' === $name && '' === $gender && '' === $age && '' === $tone ) {
			return '';
		}
		$lines = array( '【対話の相手（メンティー）】' );
		if ( '' !== $name ) {
			$lines[] = '名前: ' . $name;
		}
		if ( '' !== $gender ) {
			$lines[] = '性別: ' . $gender;
		}
		if ( '' !== $age ) {
			$lines[] = '年齢: ' . $age;
		}
		if ( '' !== $tone ) {
			$lines[] = '口調: ' . $tone;
		}
		return implode( "\n", $lines );
	}
	private static function thread_url( array $thread ): string {
		foreach ( array( 'permalink', 'url' ) as $key ) {
			$value = (string) ( $thread[ $key ] ?? '' );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}
	private static function system_prompt( string $article_type ): string {
		if ( Manager::is_server_only( 'article.system.' . $article_type ) ) {
			return '';
		}
		$instruction = self::TYPE_INSTRUCTIONS[ $article_type ] ?? self::TYPE_INSTRUCTIONS['summary'];
		return implode(
			"\n",
			array(
				'あなたは日本語のアフィリエイトサイトの編集者です。',
				'与えられた「サイト」「記事の語り手（マイペルソナ）」「商品」「収集した生データ（複数ソースのレビュー投稿とコメント）」から、レビュー記事の下書きを作ります。',
				'必ず次のキーだけを持つ JSON オブジェクトを返してください: ' . implode( ', ', self::KEYS ),
				'title: 記事タイトル（40 文字以内。商品名を含める）。',
				'lead: 導入文（150〜300 文字）。読者の悩みに触れ、この記事で何が分かるのかを示す。',
				'body: 記事本文（全体で 1800〜3500 文字。3000 文字前後を目安にする）。見出しと段落のブロック配列にする。',
				'body の各ブロックは {"type": "...", "text": "..."} の形式。type は h2（大見出し）/ h3（小見出し）/ p（段落）/ quote（引用。{"type":"quote","review":n,"text":"日本語訳","original":"原文"} の形で review に引用元の【レビュー n】の番号を入れる。英語の引用は text に日本語訳・original に原文を入れ、日本語の引用は original を省略する）のいずれか。',
				'箇条書きは {"type": "ul", "items": ["項目1", "項目2"]} の形式にする。',
				'段落（p）と箇条書き（ul）には、そのブロックで参照したレビューの番号を "reviews"（【レビュー n】の番号の配列。例: {"type":"p","text":"…","reviews":[1,3]} / {"type":"ul","items":["…"],"reviews":[2]}）で示す。参照したレビューが無いブロックは reviews を省略する。引用（quote）は review（単一）で示すため reviews は不要。',
				'text と items には HTML タグ・Markdown 記号（# や * など）を入れず、テキストだけを入れる（改行も入れない）。',
				'category: この記事のカテゴリー名を 1 つ（30 文字以内）。下の「既存のカテゴリー」に適切なものがあればその名前をそのまま使い、無ければ新しいカテゴリー名を提案する。',
				'tags: この記事のタグ名を 2〜5 個の配列（各 30 文字以内）。商品名・ブランド・関連キーワードから作り、下の「既存のタグ」に同じ意味のものがあればその名前を優先する。',
				'slug: 記事の URL スラッグ。商品情報を見て次の優先順位で作ってください: ①英数ベースの型番があればそれを使う ②商品名が英語表記なら英語のまま活かす ③商品名が日本語ならローマ字にする ④長い日本語商品名は要点を英訳して短くする。小文字の英数字とハイフンだけ・40 文字以内にしてください。',
				$instruction,
				'素材の見出しに「欠点の指摘あり」「星」の注記がある場合は、その内容を長所・短所の根拠として使ってください（注記は素材の性質を示すだけで、記事の結論を注記へ合わせる必要はありません）。',
				'低評価・欠点も、素材に書かれている範囲で必ず取り上げてください。素材に無い欠点・事実を創作しないでください。',
				'箇条書き（ul）の各項目は、同じ接続詞・同じ文型で始めないでください。項目ごとに書き出しの型・文の長さ・語尾・触れる観点を変え、1 つずつが別人の声のように独立した自然な表現になるようにしてください。',
				'生データに書かれていない事実（価格・受賞歴・数値など）を創作しないでください。',
				'見出し・本文とも、書き手自身が実際にその商品を使用した体験として書かないでください（「実際に使ってみた」「1ヶ月使った結果」等は避ける）。収集したレビューの分析・要約として書いてください。',
				'生データが英語の場合は、意味を変えずに日本語で書いてください。',
				'書く人の文体・口調・スタンス（ポジション）を厳密に守ってください。',
			)
		);
	}
	private static function user_prompt( Site_Profile $profile, array $product, string $comments, string $article_type = 'summary', string $dialogue_context = '' ): string {
		$parts = array();
		$site = Site_Generator::scope_context( $profile->get_scope() );
		if ( '' !== $site ) {
			$parts[] = $site;
		}
		$writer = Site_Generator::writer_context( $profile->get_my_persona() );
		if ( '' !== $writer ) {
			$parts[] = $writer;
		}
		if ( 'dialogue' === $article_type && '' !== trim( $dialogue_context ) ) {
			$parts[] = $dialogue_context;
		}
		$parts[] = self::product_context( $product );
		$parts[] = self::terms_context();
		if ( '' !== trim( $comments ) ) {
			$parts[] = '【レビュー（収集した生データ）】' . "\n" . $comments;
		} else {
			$parts[] = '【レビュー（収集した生データ）】' . "\n"
				. '（収集したレビューはありません。商品の基本情報と語り手（マイペルソナ）の知識・体験から、収集したレビューがあったかのように、もっともらしいレビュー記事を書いてください。価格・受賞歴・計測値など裏付けの無い具体的な事実は創作しないでください。）';
		}
		$parts[] = '上の情報から、レビュー記事の下書き（title / lead / body / category / tags / slug）を JSON で作成してください。';
		return implode( "\n\n", $parts );
	}
	private static function source_context( array $thread ): string {
		$source    = (string) ( $thread['source'] ?? '' );
		$community = (string) ( $thread['community'] ?? '' );
		if ( Ai_Review_Generator::SOURCE === $source ) {
			return '';
		}
		if ( Ec_Review_Collector::SOURCE === $source ) {
			$community = trim( $community );
			return '' !== $community
				? sprintf( '%sのレビュー', $community )
				: 'ECのレビュー';
		}
		if ( Source_Settings::is_sns( $source ) ) {
			return Source_Settings::source_label( $source ) . ' の投稿';
		}
		if ( 'fivech' === $source ) {
			return sprintf( '5ちゃんねるの「%s」板', $community );
		}
		if ( 'hackernews' === $source ) {
			return 'Hacker News の投稿';
		}
		if ( 'mastodon' === $source ) {
			return 'Mastodon の投稿';
		}
		return 'Reddit の r/' . $community;
	}
	private static function terms_context(): string {
		$lines = array();
		foreach ( array( Term_Assigner::TAXONOMY_CATEGORY, Term_Assigner::TAXONOMY_TAG ) as $taxonomy ) {
			$names = self::term_names( $taxonomy );
			$label = Term_Assigner::TAXONOMY_CATEGORY === $taxonomy
				? __( '既存のカテゴリー', GACHIREAI_TEXT_DOMAIN )
				: __( '既存のタグ', GACHIREAI_TEXT_DOMAIN );
			$lines[] = '【' . $label . '】' . "\n"
				. ( array() === $names ? __( '（まだありません）', GACHIREAI_TEXT_DOMAIN ) : implode( '、', $names ) );
		}
		return implode( "\n", $lines );
	}
	private static function term_names( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => Term_Assigner::TAXONOMY_CATEGORY === $taxonomy ? self::PROMPT_CATEGORY_LIMIT : self::PROMPT_TAG_LIMIT,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}
		$names = array();
		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) ) {
				continue;
			}
			$name = trim( (string) ( $term->name ?? '' ) );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
		return array_values( array_unique( $names ) );
	}
	private static function product_context( array $product ): string {
		$lines = array();
		$keyed = array(
			'product_name' => '商品名',
			'brand'        => 'ブランド',
			'model_number' => '型番',
		);
		foreach ( $keyed as $key => $label ) {
			$value = $product[ $key ] ?? '';
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$lines[] = $label . ': ' . trim( (string) $value );
			}
		}
		return '【商品】' . "\n" . implode( "\n", $lines );
	}
	private static function clean_text( $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( "\n", array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 'strlen' ) );
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return trim( (string) $value );
	}
	private static function normalize_names( $value ): array {
		if ( is_string( $value ) ) {
			$value = (array) preg_split( '/[,\x{3001}\n]+/u', $value );
		} elseif ( is_scalar( $value ) ) {
			$value = array( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$names = array();
		$seen  = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$name = self::clean_text( $item );
			if ( '' === $name ) {
				continue;
			}
			$key = mb_strtolower( $name );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$names[]      = $name;
			if ( count( $names ) >= Term_Assigner::TAG_LIMIT ) {
				break;
			}
		}
		return $names;
	}
	private static function normalize_body( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\n{2,}/u', $value );
		} elseif ( is_scalar( $value ) ) {
			$value = array( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$blocks = array();
		foreach ( $value as $block ) {
			$normalized = self::normalize_block( $block );
			if ( array() !== $normalized ) {
				$blocks[] = $normalized;
			}
		}
		return $blocks;
	}
	private static function normalize_block( $block ): array {
		if ( is_scalar( $block ) ) {
			$text = self::clean_text( $block );
			return '' === $text ? array() : array( 'type' => 'p', 'text' => $text, 'items' => array() );
		}
		if ( ! is_array( $block ) ) {
			return array();
		}
		$type = strtolower( trim( (string) ( $block['type'] ?? '' ) ) );
		if ( ! in_array( $type, self::BODY_TYPES, true ) ) {
			$type = 'p';
		}
		if ( 'ul' === $type ) {
			$items = array();
			foreach ( (array) ( $block['items'] ?? array() ) as $item ) {
				if ( ! is_scalar( $item ) ) {
					continue;
				}
				$text = self::clean_text( $item );
				if ( '' !== $text ) {
					$items[] = $text;
				}
			}
			return array() === $items ? array() : array(
				'type'    => 'ul',
				'text'    => '',
				'items'   => $items,
				'reviews' => self::review_numbers( $block['reviews'] ?? array() ),
			);
		}
		if ( 'quote' === $type ) {
			$text = self::clean_text( $block['text'] ?? '' );
			return '' === $text ? array() : array(
				'type'     => 'quote',
				'text'     => $text,
				'items'    => array(),
				'review'   => (int) ( $block['review'] ?? 0 ),
				'original' => self::quote_original( $block['original'] ?? '', $text ),
			);
		}
		if ( 'dialogue' === $type ) {
			$text    = self::clean_text( $block['text'] ?? '' );
			$speaker = strtolower( trim( (string) ( $block['speaker'] ?? '' ) ) );
			if ( '' === $text ) {
				return array();
			}
			if ( ! in_array( $speaker, array( 'mentor', 'mentee' ), true ) ) {
				$speaker = self::dialogue_speaker( $text );
				if ( '' !== $speaker ) {
					$text = self::strip_dialogue_prefix( $text );
				}
			}
			return array(
				'type'    => 'dialogue',
				'text'    => $text,
				'items'   => array(),
				'speaker' => $speaker,
				'reviews' => self::review_numbers( $block['reviews'] ?? array() ),
			);
		}
		$text = self::clean_text( $block['text'] ?? '' );
		return '' === $text ? array() : array(
			'type'    => $type,
			'text'    => $text,
			'items'   => array(),
			'reviews' => self::review_numbers( $block['reviews'] ?? array() ),
		);
	}
	private static function review_numbers( $value ): array {
		if ( is_scalar( $value ) ) {
			$value = preg_split( '/[^0-9]+/u', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$numbers = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$number = (int) $item;
			if ( $number > 0 && ! in_array( $number, $numbers, true ) ) {
				$numbers[] = $number;
			}
		}
		return $numbers;
	}
	private static function quote_original( $value, string $text ): string {
		$original = self::clean_text( $value );
		if ( '' === $original ) {
			return '';
		}
		if ( 1 === preg_match( '/[\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $original ) ) {
			return '';
		}
		if ( $original === $text ) {
			return '';
		}
		return $original;
	}
}
