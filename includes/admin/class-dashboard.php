<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\Article\Ai_Review;
use Tonkatsutei\Gachi_Review_AI\Article\Article_Generator;
use Tonkatsutei\Gachi_Review_AI\Article\Batch_Generator;
use Tonkatsutei\Gachi_Review_AI\Article\Generate_Progress;
use Tonkatsutei\Gachi_Review_AI\Article\Reuse_Interval_Exception;
use Tonkatsutei\Gachi_Review_AI\Article\Review_Collector;
use Tonkatsutei\Gachi_Review_AI\Article\Source_Settings;
use Tonkatsutei\Gachi_Review_AI\Ec\Ec_Review_Collector;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Ledger;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\Event_Logger;
use Tonkatsutei\Gachi_Review_AI\Icons;
use Tonkatsutei\Gachi_Review_AI\Image\Eyecatch;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Llm\Jev_Judger;
use Tonkatsutei\Gachi_Review_AI\Mastodon\Mastodon_Collector;
use Tonkatsutei\Gachi_Review_AI\Setup_Progress;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Dashboard extends Screen {
	public const AJAX_ACTION_GENERATE = 'gachireai_generate_article';
	public const AJAX_ACTION_EYECATCH = 'gachireai_generate_eyecatch_article';
	public const AJAX_ACTION_BULK = 'gachireai_generate_publish_article';
	public const AJAX_ACTION_SELECT = 'gachireai_select_product';
	public const AJAX_ACTION_REVIEWS = 'gachireai_collect_reviews';
	public const AJAX_ACTION_CLEAR = 'gachireai_clear_flow_step';
	public const AJAX_ACTION_FORCE = 'gachireai_force_generate_article';
	public const AJAX_ACTION_FORCE_SELECT = 'gachireai_force_select_product';
	public const AJAX_ACTION_MARK_POSTED = 'gachireai_mark_product_posted';
	public const AJAX_ACTION_MARK_EXCLUDED = 'gachireai_mark_product_excluded';
	private const ACTION = 'generate_article';
	private const NONCE_ACTION = 'gachireai_generate_article';
	private const ACTION_FIELD = 'gachireai_action';
	private const GENERATE_FIELD = 'gachireai_generate_article';
	private const EYECATCH_FIELD = 'gachireai_generate_eyecatch';
	private const BULK_FIELD = 'gachireai_bulk_publish';
	private const SELECT_FIELD = 'gachireai_select_product';
	private const REVIEWS_FIELD = 'gachireai_collect_reviews';
	private const CLEAR_FIELD = 'gachireai_clear_step';
	private const CLEAR_ALL = 'reviews';
	private const REVIEW_SOURCES = array( 'ec', 'x', 'instagram', 'facebook', 'youtube', 'reddit', 'fivech', 'hackernews', 'mastodon' );
	private const FLOW_STATE_PREFIX = 'gachireai_flow_state_';
	private const FLOW_STATE_TTL = 43200; 
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->render_template( 'admin/dashboard', $this->model() );
	}
	public function ajax_generate(): void {
		$this->run_generate( false );
	}
	public function ajax_force_generate(): void {
		$this->run_generate( true );
	}
	private function run_generate( bool $force ): void {
		$this->guard_ajax( self::NONCE_ACTION );
		$progress = new Setup_Progress();
		if ( ! $progress->all_complete() ) {
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					'generate',
					array( $this->message( 'error', esc_html__( '設定が未完了のため生成できません。', GACHIREAI_TEXT_DOMAIN ) ) )
				)
			);
			return;
		}
		$state = $this->flow_state();
		if ( ! self::has_product( $state ) ) {
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					'generate',
					array( $this->message( 'error', esc_html__( '先に商品選定を実行してください。', GACHIREAI_TEXT_DOMAIN ) ) )
				)
			);
			return;
		}
		if ( ! Ai_Review::is_only() && ! self::has_reviews( $state ) ) {
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					'generate',
					array( $this->message( 'error', esc_html__( '先にレビュー取得を実行してください。', GACHIREAI_TEXT_DOMAIN ) ) )
				)
			);
			return;
		}
		try {
			$result = ( new Article_Generator() )->generate(
				(array) $state['product'],
				self::aggregate_collected( (array) $state['collected'] ),
				(string) $state['ec'],
				(string) $state['query'],
				$force,
				null,
				'',
				false
			);
		} catch ( Reuse_Interval_Exception $e ) {
			$message = $this->message( 'error', esc_html( $e->getMessage() ) );
			if ( ! $force ) {
				$message['msg_force']        = true;
				$message['msg_force_action'] = esc_attr( self::AJAX_ACTION_FORCE );
				$message['msg_force_label']  = esc_html__( '強制的に生成', GACHIREAI_TEXT_DOMAIN );
			}
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					'generate',
					array( $message )
				)
			);
			return;
		} catch ( \Throwable $e ) {
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					'generate',
					array( $this->message( 'error', esc_html( $e->getMessage() ) ) )
				)
			);
			return;
		}
		$state['post_id']    = (int) ( $result['post_id'] ?? 0 );
		$state['post_title'] = (string) ( $result['post_title'] ?? '' );
		$this->save_flow_state( $state );
		$this->send_run_response(
			fn(): string => $this->render_generate_form(
				'generate',
				array( $this->message( 'success', esc_html__( '下書きを生成しました。', GACHIREAI_TEXT_DOMAIN ) ) ),
				$result
			)
		);
	}
	public function ajax_eyecatch(): void {
		$this->guard_ajax( self::NONCE_ACTION );
		$state   = $this->flow_state();
		$post_id = (int) ( $state['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					'eyecatch',
					array( $this->message( 'error', esc_html__( '先に記事生成を実行してください。', GACHIREAI_TEXT_DOMAIN ) ) )
				)
			);
			return;
		}
		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					'eyecatch',
					array( $this->message( 'error', esc_html__( '記事が見つかりません。', GACHIREAI_TEXT_DOMAIN ) ) )
				)
			);
			return;
		}
		$started = microtime( true );
		try {
			$result = ( new Eyecatch() )->generate_for_post(
				$post_id,
				'' !== (string) ( $state['post_title'] ?? '' ) ? (string) $state['post_title'] : (string) $post->post_title,
				(array) $state['product'],
				new Site_Profile(),
				true
			);
		} catch ( \Throwable $e ) {
			Event_Logger::record(
				Event_Logger::TYPE_GENERATE,
				Event_Logger::LEVEL_ERROR,
				sprintf(
					__( 'アイキャッチ画像の生成に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$e->getMessage()
				),
				array( 'post_id' => $post_id ),
				$post_id,
				self::elapsed_ms( $started )
			);
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					'eyecatch',
					array( $this->message( 'error', esc_html( $e->getMessage() ) ) )
				)
			);
			return;
		}
		$status = (string) ( $result['status'] ?? '' );
		Event_Logger::record(
			Event_Logger::TYPE_GENERATE,
			'generated' === $status ? Event_Logger::LEVEL_INFO : Event_Logger::LEVEL_WARN,
			sprintf(
				__( 'アイキャッチ画像: %s', GACHIREAI_TEXT_DOMAIN ),
				(string) ( $result['message'] ?? '' )
			),
			array(
				'post_id'         => $post_id,
				'eyecatch_status' => $status,
			),
			$post_id,
			self::elapsed_ms( $started )
		);
		$messages = array(
			$this->message(
				'generated' === $status ? 'success' : 'warning',
				esc_html( (string) ( $result['message'] ?? '' ) )
			),
		);
		foreach ( (array) ( $result['warnings'] ?? array() ) as $warning ) {
			if ( is_scalar( $warning ) && '' !== trim( (string) $warning ) ) {
				$messages[] = $this->message( 'warning', esc_html( (string) $warning ) );
			}
		}
		$this->send_run_response(
			fn(): string => $this->render_generate_form( 'eyecatch', $messages, $result )
		);
	}
	public function ajax_bulk(): void {
		$this->guard_ajax( self::NONCE_ACTION );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}
		$progress = new Setup_Progress();
		if ( ! $progress->all_complete() ) {
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					'bulk',
					array( $this->message( 'error', esc_html__( '設定が未完了のため一括生成できません。', GACHIREAI_TEXT_DOMAIN ) ) )
				)
			);
			return;
		}
		$outcome = ( new Batch_Generator() )->run(
			get_current_user_id(),
			$progress->can_auto_post(),
			__( 'PRO 限定のため下書きのままにしました。', GACHIREAI_TEXT_DOMAIN )
		);
		if ( array() !== (array) $outcome['product'] ) {
			$state               = $this->flow_state();
			$state['product']    = (array) $outcome['product'];
			$state['ec']         = (string) $outcome['ec'];
			$state['query']      = (string) $outcome['query'];
			$state['collected']  = (array) $outcome['collected'];
			$state['post_id']    = (int) $outcome['post_id'];
			$state['post_title'] = (string) $outcome['post_title'];
			$this->save_flow_state( $state );
		}
		$bulk_result = 0 < (int) $outcome['post_id']
			? array(
				'post_id'     => (int) $outcome['post_id'],
				'published'   => (bool) $outcome['published'],
				'edit_url'    => (string) $outcome['edit_url'],
				'preview_url' => (string) $outcome['preview_url'],
				'permalink'   => (string) $outcome['permalink'],
			)
			: null;
		$this->send_run_response(
			fn(): string => $this->render_generate_form( 'bulk', (array) $outcome['messages'], $bulk_result )
		);
	}
	public function ajax_select(): void {
		$this->run_select( false );
	}
	public function ajax_force_select(): void {
		$this->run_select( true );
	}
	private function run_select( bool $force ): void {
		$this->guard_ajax( self::NONCE_ACTION );
		$progress = new Setup_Progress();
		if ( ! $progress->is_ec_basis_complete() ) {
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					'select',
					array( $this->message( 'error', esc_html__( '「ASP設定」で「メインEC」を設定してください。', GACHIREAI_TEXT_DOMAIN ) ) )
				)
			);
			return;
		}
		$user_id = get_current_user_id();
		Generate_Progress::start( $user_id, __( '商品を選定中...', GACHIREAI_TEXT_DOMAIN ) );
		$selector = new Product_Selector();
		$started  = microtime( true );
		try {
			$selection = $selector->select( $force );
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
				self::elapsed_ms( $started )
			);
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					'select',
					array( $this->message( 'error', esc_html( $e->getMessage() ) ) )
				)
			);
			return;
		}
		Generate_Progress::finish( $user_id );
		if ( Product_Selector::STATUS_SELECTED !== (string) ( $selection['status'] ?? '' ) ) {
			$message = trim( (string) ( $selection['message'] ?? '' ) );
			$message = '' !== $message ? $message : __( 'EC で対象商品を選定できませんでした。', GACHIREAI_TEXT_DOMAIN );
			$reason  = (string) ( $selection['reason'] ?? '' );
			Event_Logger::record(
				Event_Logger::TYPE_SELECT,
				Event_Logger::LEVEL_ERROR,
				$message,
				array(
					'ec'     => $selector->ec(),
					'status' => (string) ( $selection['status'] ?? '' ),
					'reason' => $reason,
				),
				0,
				self::elapsed_ms( $started )
			);
			$box = $this->message( 'error', esc_html( $message ) );
			if ( ! $force && Product_Selector::REASON_NO_UNUSED === $reason ) {
				$box['msg_force']        = true;
				$box['msg_force_action'] = esc_attr( self::AJAX_ACTION_FORCE_SELECT );
				$box['msg_force_label']  = esc_html__( '強制的に選択する', GACHIREAI_TEXT_DOMAIN );
			}
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					'select',
					array( $box )
				)
			);
			return;
		}
		$state               = $this->flow_state();
		$state['product']    = (array) ( $selection['product'] ?? array() );
		$state['ec']         = $selector->ec();
		$state['query']      = (string) ( $selection['query'] ?? '' );
		$state['post_id']    = 0;
		$state['post_title'] = '';
		$this->save_flow_state( $state );
		$selected = (array) ( $selection['product'] ?? array() );
		$item     = (array) ( $selected['item'] ?? array() );
		Event_Logger::record(
			Event_Logger::TYPE_SELECT,
			Event_Logger::LEVEL_INFO,
			sprintf(
				__( '商品を選定しました: %s', GACHIREAI_TEXT_DOMAIN ),
				(string) ( $selected['product_name'] ?? '' )
			),
			array(
				'ec'           => $selector->ec(),
				'query'        => (string) ( $selection['query'] ?? '' ),
				'product_name' => (string) ( $selected['product_name'] ?? '' ),
				'item_code'    => (string) ( $item['item_code'] ?? '' ),
				'price'        => (int) ( $item['item_price'] ?? 0 ),
			),
			0,
			self::elapsed_ms( $started )
		);
		$this->send_run_response(
			fn(): string => $this->render_generate_form(
				'select',
				array( $this->message( 'success', esc_html__( '商品を選定しました。', GACHIREAI_TEXT_DOMAIN ) ) )
			)
		);
	}
	public function ajax_mark_posted(): void {
		$this->mark_product( true );
	}
	public function ajax_mark_excluded(): void {
		$this->mark_product( false );
	}
	private function mark_product( bool $posted ): void {
		$this->guard_ajax( self::NONCE_ACTION );
		$state = $this->flow_state();
		if ( ! self::has_product( $state ) ) {
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					'select',
					array( $this->message( 'error', esc_html__( '先に商品選定を実行してください。', GACHIREAI_TEXT_DOMAIN ) ) )
				)
			);
			return;
		}
		$product = (array) $state['product'];
		$ec      = (string) $state['ec'];
		$query   = (string) $state['query'];
		$ledger  = new Product_Ledger();
		$registered = $ledger->register( $ec, $product, $query );
		$id         = (int) ( $registered['id'] ?? 0 );
		if ( $id <= 0 ) {
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					'select',
					array( $this->message( 'error', esc_html__( '商品名を取得できないため、商品台帳に記録できませんでした。', GACHIREAI_TEXT_DOMAIN ) ) )
				)
			);
			return;
		}
		$updated = $posted ? $ledger->mark_posted( $id, 0 ) : $ledger->mark_excluded( $id );
		if ( ! $updated ) {
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					'select',
					array( $this->message( 'error', esc_html__( '商品台帳を更新できませんでした。時間をおいて、もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ) ) )
				)
			);
			return;
		}
		$state['product'] = array();
		$state['ec']      = '';
		$state['query']   = '';
		$this->save_flow_state( $state );
		$name = (string) ( $product['product_name'] ?? '' );
		Event_Logger::record(
			Event_Logger::TYPE_SELECT,
			Event_Logger::LEVEL_INFO,
			$posted
				? sprintf(
					__( '商品を投稿済みとして記録しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$name
				)
				: sprintf(
					__( '商品を投稿対象外として記録しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$name
				),
			array(
				'ec'           => $ec,
				'product_name' => $name,
				'item_code'    => (string) ( ( (array) ( $product['item'] ?? array() ) )['item_code'] ?? '' ),
				'mark'         => $posted ? 'posted' : 'excluded',
			)
		);
		$message = $posted
			? __( '投稿済みとして記録しました。', GACHIREAI_TEXT_DOMAIN )
			: __( '投稿対象外として記録しました。', GACHIREAI_TEXT_DOMAIN );
		$this->send_run_response(
			fn(): string => $this->render_generate_form(
				'select',
				array( $this->message( 'success', esc_html( $message ) ) )
			)
		);
	}
	private function selection_info( array $product ): array {
		$item  = (array) ( $product['item'] ?? array() );
		$name  = (string) ( $product['product_name'] ?? '' );
		$url   = (string) ( $item['item_url'] ?? '' );
		$price = (int) ( $item['item_price'] ?? 0 );
		$shop  = (string) ( $item['item_name'] ?? '' );
		$label = esc_html( $name );
		$text = esc_html__( '選定した商品:', GACHIREAI_TEXT_DOMAIN );
		if ( '' === $url ) {
			$text = sprintf(
				esc_html__( '選定した商品: %s', GACHIREAI_TEXT_DOMAIN ),
				$label
			);
		}
		$suffix = array();
		if ( 0 < $price ) {
			$suffix[] = sprintf(
				esc_html__( '価格: %s円', GACHIREAI_TEXT_DOMAIN ),
				esc_html( number_format_i18n( $price ) )
			);
		}
		if ( '' !== $shop && $shop !== $name ) {
			$suffix[] = sprintf(
				esc_html__( '出品名: %s', GACHIREAI_TEXT_DOMAIN ),
				esc_html( mb_substr( $shop, 0, 90 ) )
			);
		}
		return array(
			'action_info'            => true,
			'action_info_text'       => $text,
			'action_info_link_url'   => '' !== $url ? esc_url( $url ) : '',
			'action_info_link_label' => $label,
			'action_info_suffix'     => implode( ' / ', $suffix ),
			'action_mark_posted'          => true,
			'action_mark_posted_action'   => esc_attr( self::AJAX_ACTION_MARK_POSTED ),
			'action_mark_posted_label'    => esc_html__( 'この商品を投稿済みとする', GACHIREAI_TEXT_DOMAIN ),
			'action_mark_excluded'        => true,
			'action_mark_excluded_action' => esc_attr( self::AJAX_ACTION_MARK_EXCLUDED ),
			'action_mark_excluded_label'  => esc_html__( 'この商品を投稿対象外とする', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	public function ajax_reviews(): void {
		$this->guard_ajax( self::NONCE_ACTION );
		$source = isset( $_POST[ self::REVIEWS_FIELD ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::REVIEWS_FIELD ] ) ) : '';
		if ( ! in_array( $source, self::REVIEW_SOURCES, true ) ) {
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					'reviews',
					array( $this->message( 'error', esc_html__( '取得元を判別できませんでした。画面を再読み込みしてください。', GACHIREAI_TEXT_DOMAIN ) ) )
				)
			);
			return;
		}
		if ( ! Source_Settings::can_collect( $source ) ) {
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					$source,
					array( $this->message( 'error', esc_html( Source_Settings::switch_off_reason( $source ) ) ) )
				)
			);
			return;
		}
		if ( Ai_Review::is_only() ) {
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					$source,
					array( $this->message( 'error', esc_html__( '「AI生成のみ」ではレビューを収集しません。', GACHIREAI_TEXT_DOMAIN ) ) )
				)
			);
			return;
		}
		$progress = new Setup_Progress();
		if ( ! $progress->is_ec_basis_complete() ) {
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					$source,
					array( $this->message( 'error', esc_html__( '「ASP設定」で「メインEC」を設定してください。', GACHIREAI_TEXT_DOMAIN ) ) )
				)
			);
			return;
		}
		$state = $this->flow_state();
		if ( ! self::has_product( $state ) ) {
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					$source,
					array( $this->message( 'error', esc_html__( '先に商品選定を実行してください。', GACHIREAI_TEXT_DOMAIN ) ) )
				)
			);
			return;
		}
		$product = (array) $state['product'];
		$user_id = get_current_user_id();
		Generate_Progress::start( $user_id, $this->source_progress_label( $source ) );
		$started = microtime( true );
		try {
			$collected = ( new Review_Collector() )->collect( $product, ( new Site_Profile() )->get_scope(), $source, (string) $state['ec'] );
		} catch ( \Throwable $e ) {
			Generate_Progress::finish( $user_id );
			Event_Logger::record(
				Event_Logger::TYPE_COLLECT,
				Event_Logger::LEVEL_ERROR,
				sprintf(
					__( '%1$s からのレビュー取得に失敗しました: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$this->source_label_text( $source ),
					$e->getMessage()
				),
				array( 'source' => $source ),
				0,
				self::elapsed_ms( $started )
			);
			$this->send_run_response(
				fn(): string => $this->render_generate_form(
					$source,
					array( $this->message( 'error', esc_html( $e->getMessage() ) ) )
				)
			);
			return;
		}
		Generate_Progress::finish( $user_id );
		if ( empty( $collected['skipped'] ) ) {
			$map = (array) $state['collected'];
			$map[ $source ] = $collected;
			$state['collected'] = $map;
			$this->save_flow_state( $state );
			$messages = $this->review_result_messages( $collected, $source );
		} else {
			$messages = array( $this->message( 'info', esc_html( (string) ( $collected['message'] ?? '' ) ) ) );
		}
		$this->record_collect( $source, $collected, self::elapsed_ms( $started ) );
		$this->send_run_response(
			fn(): string => $this->render_generate_form( $source, $messages )
		);
	}
	private function review_result_messages( array $collected, string $source ): array {
		$messages = array();
		$posts    = (int) ( $collected['posts'] ?? 0 );
		$comments = (int) ( $collected['comments'] ?? 0 );
		$found    = (int) ( $collected['found'] ?? 0 );
		$errors = array();
		foreach ( (array) ( $collected['errors'] ?? array() ) as $error ) {
			if ( is_scalar( $error ) && '' !== trim( (string) $error ) ) {
				$errors[] = (string) $error;
			}
		}
		if ( $posts > 0 || $comments > 0 ) {
			$messages[] = $this->message( 'success', esc_html__( 'レビューを取得しました。', GACHIREAI_TEXT_DOMAIN ) );
		} elseif ( array() === $errors ) {
			if ( $found > 0 ) {
				$messages[] = $this->message(
					'info',
					sprintf(
						esc_html__( '%1$s の投稿 %2$d 件はすべて取得済み（または期間外）でした。', GACHIREAI_TEXT_DOMAIN ),
						esc_html( $this->source_label_text( $source ) ),
						$found
					)
				);
			} else {
				$messages[] = $this->message(
					'warning',
					sprintf(
						esc_html__( '%s に該当する投稿・コメントは見つかりませんでした。', GACHIREAI_TEXT_DOMAIN ),
						esc_html( $this->source_label_text( $source ) )
					)
				);
			}
		}
		foreach ( $errors as $error ) {
			$messages[] = $this->message( 'warning', esc_html( $error ) );
		}
		return $messages;
	}
	public function ajax_clear(): void {
		$this->guard_ajax( self::NONCE_ACTION );
		$target = isset( $_POST[ self::CLEAR_FIELD ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::CLEAR_FIELD ] ) ) : '';
		if ( 'select' === $target ) {
			$state               = $this->flow_state();
			$state['product']    = array();
			$state['ec']         = '';
			$state['query']      = '';
			$state['post_id']    = 0;
			$state['post_title'] = '';
			$this->save_flow_state( $state );
		} elseif ( in_array( $target, self::REVIEW_SOURCES, true ) ) {
			$state = $this->flow_state();
			$map   = (array) $state['collected'];
			unset( $map[ $target ] );
			$state['collected'] = $map;
			$this->save_flow_state( $state );
		} elseif ( self::CLEAR_ALL === $target ) {
			$state              = $this->flow_state();
			$state['collected'] = array();
			$this->save_flow_state( $state );
		}
		$this->send_run_response(
			fn(): string => $this->render_generate_form( '' )
		);
	}
	private function source_review_lines( string $source, array $collected ): array {
		if ( ! empty( $collected['skipped'] ) ) {
			return array( $this->review_line( esc_html( (string) ( $collected['message'] ?? '' ) ), false ) );
		}
		$sources = (array) ( $collected['sources'] ?? array() );
		if ( 'youtube' === $source ) {
			return $this->youtube_review_lines( $collected, $sources );
		}
		if ( Ec_Review_Collector::SOURCE === $source || Source_Settings::is_sns( $source ) ) {
			$posts = (int) ( $collected['posts'] ?? 0 );
			if ( 0 === $posts && '' !== (string) ( $collected['message'] ?? '' ) ) {
				return array( $this->review_line( esc_html( (string) $collected['message'] ), true ) );
			}
			$lines = array(
				$this->review_line(
					sprintf(
						esc_html__( '新規に保存: レビュー %d 件', GACHIREAI_TEXT_DOMAIN ),
						$posts
					),
					0 === $posts
				),
			);
			$rating = (array) ( $collected['rating'] ?? array() );
			$count  = (int) ( $rating['count'] ?? 0 );
			if ( Ec_Review_Collector::SOURCE === $source && 0 < $count ) {
				$lines[] = $this->review_line(
					sprintf(
						esc_html__( '星: 平均 %1$s（%2$d 件）', GACHIREAI_TEXT_DOMAIN ),
						number_format_i18n( (float) ( $rating['average'] ?? 0 ), 2 ),
						$count
					),
					false
				);
			}
			return $lines;
		}
		$comments_off = 'mastodon' === $source && ! Mastodon_Collector::fetches_comments();
		$parts = array(
			$comments_off
				? sprintf(
					esc_html__( '新規に保存: 投稿 %d 件 / コメント ×', GACHIREAI_TEXT_DOMAIN ),
					(int) ( $collected['posts'] ?? 0 )
				)
				: sprintf(
					esc_html__( '新規に保存: 投稿 %1$d 件 / コメント %2$d 件', GACHIREAI_TEXT_DOMAIN ),
					(int) ( $collected['posts'] ?? 0 ),
					(int) ( $collected['comments'] ?? 0 )
				),
		);
		if ( 'reddit' === $source ) {
			$subreddits = $this->source_targets( (array) ( $sources['subreddits'] ?? array() ) );
			$parts[] = array() === $subreddits
				? esc_html__( 'サブレディットが未設定です。', GACHIREAI_TEXT_DOMAIN )
				: sprintf(
					esc_html__( 'r/%s', GACHIREAI_TEXT_DOMAIN ),
					esc_html( implode( ' / r/', $subreddits ) )
				);
		}
		if ( 'fivech' === $source ) {
			$boards = $this->source_targets( (array) ( $sources['boards'] ?? array() ) );
			$parts[] = array() === $boards
				? esc_html__( '板が未設定です。', GACHIREAI_TEXT_DOMAIN )
				: sprintf(
					esc_html__( '%s 板', GACHIREAI_TEXT_DOMAIN ),
					esc_html( implode( ' / ', $boards ) )
				);
		}
		if ( 'hackernews' === $source ) {
			$terms = $this->source_targets( (array) ( $sources['hackernews'] ?? array() ) );
			$parts[] = array() === $terms
				? esc_html__( '商品名が未設定のため検索できません。', GACHIREAI_TEXT_DOMAIN )
				: sprintf(
					esc_html__( '検索語: %s', GACHIREAI_TEXT_DOMAIN ),
					esc_html( implode( ' / ', $terms ) )
				);
		}
		if ( 'mastodon' === $source ) {
			$terms = $this->source_targets( (array) ( $sources['mastodon'] ?? array() ) );
			$parts[] = array() === $terms
				? esc_html__( 'インスタンス・トークンが未設定です。', GACHIREAI_TEXT_DOMAIN )
				: sprintf(
					esc_html__( '検索語: %s', GACHIREAI_TEXT_DOMAIN ),
					esc_html( implode( ' / ', $terms ) )
				);
		}
		return array(
			$this->review_line(
				implode( ' / ', $parts ),
				0 === ( (int) ( $collected['posts'] ?? 0 ) + (int) ( $collected['comments'] ?? 0 ) )
			),
		);
	}
	private function review_line( string $text, bool $zero ): array {
		return array(
			'line_text' => $text,
			'line_zero' => $zero,
		);
	}
	private function judge_review_line( array $collected ): array {
		$judged = (array) ( $collected['judged'] ?? array() );
		if ( array() === $judged ) {
			return array();
		}
		if ( Jev_Judger::REASON_NO_KEY === (string) ( $judged['reason'] ?? '' ) ) {
			return array(
				$this->review_line(
					esc_html__( '判定: 未実施（Jev の API キーが未設定です）', GACHIREAI_TEXT_DOMAIN ),
					false
				),
			);
		}
		$attempted = (int) ( $judged['attempted'] ?? 0 );
		$adopt     = (int) ( $judged['adopt'] ?? 0 );
		$hold      = (int) ( $judged['hold'] ?? 0 );
		$exclude   = (int) ( $judged['exclude'] ?? 0 );
		$pending   = (int) ( $judged['failed'] ?? 0 ) + (int) ( $judged['skipped'] ?? 0 );
		if ( 0 === $attempted && 0 === $pending && empty( $judged['capped'] ) ) {
			return array();
		}
		$text = sprintf(
			esc_html__( '判定: 採用 %1$d / 保留 %2$d / 除外 %3$d', GACHIREAI_TEXT_DOMAIN ),
			$adopt,
			$hold,
			$exclude
		);
		if ( 0 < $pending ) {
			$text .= sprintf(
				esc_html__( '（未判定 %d 件）', GACHIREAI_TEXT_DOMAIN ),
				$pending
			);
		}
		if ( ! empty( $judged['capped'] ) ) {
			$text .= esc_html__( '（1 回の上限で打ち切り）', GACHIREAI_TEXT_DOMAIN );
		}
		return array( $this->review_line( $text, false ) );
	}
	private function youtube_review_lines( array $collected, array $sources ): array {
		$terms = $this->source_targets( (array) ( $sources['youtube'] ?? array() ) );
		if ( array() === $terms ) {
			return array( $this->review_line( esc_html__( 'API キーが未設定です。', GACHIREAI_TEXT_DOMAIN ), false ) );
		}
		$searches = $this->source_searches( (array) ( $collected['searches'] ?? array() ) );
		if ( array() === $searches ) {
			return array(
				$this->review_line(
					sprintf(
						esc_html__( '検索語: %s', GACHIREAI_TEXT_DOMAIN ),
						esc_html( implode( ' / ', $terms ) )
					),
					false
				),
			);
		}
		$lines = array();
		foreach ( $searches as $row ) {
			$lines[] = $this->review_line(
				sprintf(
					esc_html__( '新規に保存: 投稿 %1$d 件 / コメント %2$d 件 / 検索語: %3$s', GACHIREAI_TEXT_DOMAIN ),
					(int) $row['posts'],
					(int) $row['comments'],
					esc_html( (string) $row['query'] )
				),
				0 === ( (int) $row['posts'] + (int) $row['comments'] )
			);
		}
		return $lines;
	}
	private function source_searches( array $values ): array {
		$rows = array();
		foreach ( $values as $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			$query = trim( (string) ( $value['query'] ?? '' ) );
			if ( '' === $query ) {
				continue;
			}
			$rows[] = array(
				'query'    => $query,
				'posts'    => (int) ( $value['posts'] ?? 0 ),
				'comments' => (int) ( $value['comments'] ?? 0 ),
			);
		}
		return $rows;
	}
	private function source_targets( array $values ): array {
		$list = array();
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$list[] = (string) $value;
			}
		}
		return array_values( array_unique( $list ) );
	}
	public function render_generate_form( string $target, array $messages = array(), ?array $result = null ): string {
		$model = $this->model();
		$state = $this->flow_state();
		if ( 'bulk' === $target ) {
			$model = $this->apply_bulk_result( $model, $messages, $result );
		} else {
			$model['generate_steps'] = $this->apply_step_result(
				(array) $model['generate_steps'],
				$target,
				$messages,
				$result,
				self::normalize_collected( $state['collected'] ?? null )
			);
		}
		return $this->render_template_string( 'admin/dashboard-form', $model );
	}
	private function apply_bulk_result( array $model, array $messages, ?array $result ): array {
		$model['bulk_messages'] = $messages;
		if ( null === $result || (int) ( $result['post_id'] ?? 0 ) <= 0 ) {
			return $model;
		}
		$model['bulk_result'] = true;
		if ( ! empty( $result['published'] ) ) {
			$model['bulk_result_text']  = esc_html__( '公開した記事を開けます。', GACHIREAI_TEXT_DOMAIN );
			$model['bulk_result_url']   = esc_url( (string) ( $result['permalink'] ?? '' ) );
			$model['bulk_result_label'] = esc_html__( '公開した記事を開く', GACHIREAI_TEXT_DOMAIN );
			return $model;
		}
		$model['bulk_result_text']  = esc_html__( '下書きを編集できます。', GACHIREAI_TEXT_DOMAIN );
		$model['bulk_result_url']   = esc_url( (string) ( $result['edit_url'] ?? '' ) );
		$model['bulk_result_label'] = esc_html__( '下書きを編集する', GACHIREAI_TEXT_DOMAIN );
		return $model;
	}
	private function apply_step_result( array $steps, string $target, array $messages, ?array $result, array $collected = array() ): array {
		if ( '' === $target ) {
			return $steps;
		}
		if ( in_array( $target, self::REVIEW_SOURCES, true ) ) {
			foreach ( $steps as $index => $step ) {
				if ( 'reviews' !== (string) ( $step['step_key'] ?? '' ) ) {
					continue;
				}
				$steps[ $index ]['step_messages'] = $messages;
				$lines = isset( $collected[ $target ] ) && is_array( $collected[ $target ] )
					? $this->source_review_lines( $target, (array) $collected[ $target ] )
					: array();
				$judged_lines = $this->judge_review_line( isset( $collected[ $target ] ) && is_array( $collected[ $target ] ) ? (array) $collected[ $target ] : array() );
				if ( array() !== $judged_lines ) {
					$lines = array_merge( $lines, $judged_lines );
				}
				$steps[ $index ]['step_review_lines'] = $lines;
				return $steps;
			}
			return $steps;
		}
		foreach ( $steps as $index => $step ) {
			foreach ( (array) ( $step['step_actions'] ?? array() ) as $position => $action ) {
				if ( (string) ( $action['action_key'] ?? '' ) !== $target ) {
					continue;
				}
				$steps[ $index ]['step_actions'][ $position ]['action_messages'] = $messages;
				if ( null !== $result ) {
					$steps[ $index ]['step_actions'][ $position ] = 'eyecatch' === $target
						? $this->apply_eyecatch_result( (array) $steps[ $index ]['step_actions'][ $position ], $result )
						: $this->apply_article_result( (array) $steps[ $index ]['step_actions'][ $position ], $result );
				}
				return $steps;
			}
		}
		foreach ( $steps as $index => $step ) {
			if ( (string) ( $step['step_key'] ?? '' ) !== $target ) {
				continue;
			}
			$steps[ $index ]['step_messages'] = $messages;
			return $steps;
		}
		return $steps;
	}
	private function apply_article_result( array $action, array $result ): array {
		$action['action_result']      = false;
		$action['action_result_text'] = '';
		$action['action_result_url']   = esc_url( (string) ( $result['edit_url'] ?? '' ) );
		$action['action_result_label'] = esc_html__( '下書きを編集する', GACHIREAI_TEXT_DOMAIN );
		$action['action_preview_url']   = esc_url( (string) ( $result['preview_url'] ?? '' ) );
		$action['action_preview_label'] = esc_html__( 'プレビュー', GACHIREAI_TEXT_DOMAIN );
		return $action;
	}
	private function apply_eyecatch_result( array $action, array $result ): array {
		$status = (string) ( $result['status'] ?? '' );
		$url    = (string) ( $result['url'] ?? '' );
		$action['action_result']      = true;
		$action['action_result_text'] = 'generated' === $status
			? esc_html__( 'アイキャッチ画像を生成し、記事に設定しました。', GACHIREAI_TEXT_DOMAIN )
			: esc_html(
				'' !== trim( (string) ( $result['message'] ?? '' ) )
					? (string) $result['message']
					: __( 'アイキャッチ画像を生成しませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		if ( 'generated' === $status && '' !== $url ) {
			$action['action_result_url']   = esc_url( $url );
			$action['action_result_label'] = esc_html__( '画像を開く', GACHIREAI_TEXT_DOMAIN );
		}
		return $action;
	}
	private static function section_footnote( string $mark ): string {
		$footnotes = array(
			Setup_Progress::MARK_EC  => '*2',
			Setup_Progress::MARK_ANY => '*3',
		);
		return $footnotes[ $mark ] ?? '';
	}
	private function model(): array {
		$progress = new Setup_Progress();
		$tabs     = array();
		foreach ( $progress->tabs() as $tab ) {
			$tab_url  = Tabs::url( (string) $tab['tab_slug'] );
			$tab_done = Setup_Progress::STATUS_COMPLETE === (string) $tab['tab_status'];
			$sections = array();
			$group_seen = array();
			foreach ( (array) $tab['sections'] as $section ) {
				$mark = (string) $section['section_mark'];
				$span = (int) $section['section_span'];
				$note = (string) $section['section_note'];
				$note_mark = '';
				if ( in_array( substr( $note, 0, 2 ), array( '*1', '*2', '*3' ), true ) ) {
					$note_mark = substr( $note, 0, 2 );
					$note      = ltrim( substr( $note, 2 ) );
				}
				$is_group = in_array(
					$mark,
					array( Setup_Progress::MARK_EC, Setup_Progress::MARK_ANY ),
					true
				);
				$note_skip            = $is_group && isset( $group_seen[ $mark ] );
				$group_seen[ $mark ]  = true;
				$anchor      = (string) ( $section['section_anchor'] ?? '' );
				$section_url = '' === $anchor ? $tab_url : $tab_url . '#gachireai-section-' . $anchor;
				$sections[] = array(
					'section_label'     => esc_html( (string) $section['section_label'] ),
					'section_url'       => esc_url( $section_url ),
					'section_marker'    => Setup_Progress::MARK_SINGLE === $mark,
					'section_footnote'  => self::section_footnote( $mark ),
					'section_saved'     => (bool) $section['section_saved'],
					'section_warning'   => ! empty( $section['section_warning'] ),
					'section_note_cell' => ! $note_skip,
					'section_note_mark' => esc_html( $note_mark ),
					'section_note'      => esc_html( $note ),
					'section_rowspan'   => 1 < $span,
					'section_span'      => $span,
				);
			}
			$tabs[] = array(
				'progress_tab_label'      => esc_html( (string) $tab['tab_label'] ),
				'progress_tab_open'       => ! $tab_done,
				'progress_tab_complete'   => $tab_done,
				'progress_tab_incomplete' => ! $tab_done,
				'progress_sections'       => $sections,
			);
		}
		$generate_enabled = $progress->all_complete();
		$state            = $this->flow_state();
		$logs_model       = $this->logs_model();
		$notices       = array();
		$status_notice = $this->status_notice( $progress, $generate_enabled );
		if ( null !== $status_notice ) {
			$notices[] = $status_notice;
		}
		return array(
			'page_title'       => esc_html__( 'ダッシュボード', GACHIREAI_TEXT_DOMAIN ),
			'tabs'             => $this->tab_nav(),
			'js_notice'        => array(
				$this->notice(
					'error',
					esc_html__( 'JavaScript を有効にしてください。設定の保存や記事生成に必要です。', GACHIREAI_TEXT_DOMAIN )
				),
			),
			'notices'          => $notices,
			'progress_title'   => esc_html__( '設定状況', GACHIREAI_TEXT_DOMAIN ),
			'progress_intro'   => esc_html__( '必須項目がすべて設定完了になると、AIによる記事生成が可能になります。', GACHIREAI_TEXT_DOMAIN ),
			'progress_item'    => esc_html__( '項目', GACHIREAI_TEXT_DOMAIN ),
			'progress_required' => esc_html__( '必須', GACHIREAI_TEXT_DOMAIN ),
			'progress_saved'   => esc_html__( '設定', GACHIREAI_TEXT_DOMAIN ),
			'progress_note'    => esc_html__( '備考', GACHIREAI_TEXT_DOMAIN ),
			'progress_tabs'    => $tabs,
			'generate_title'         => esc_html__( '即時実行', GACHIREAI_TEXT_DOMAIN ),
			'generate_visible'       => self::is_dev_site(),
			'generate_enabled'       => $generate_enabled,
			'generate_ready'         => esc_html__( 'Step1 から順に実行してください。', GACHIREAI_TEXT_DOMAIN ),
			'generate_wait'          => esc_html__( '設定がすべて完了すると実行できます。', GACHIREAI_TEXT_DOMAIN ),
			'generate_form_action'   => esc_url( Tabs::url( Tabs::TAB_DASHBOARD ) ),
			'generate_hidden_fields' => array(
				array(
					'name'  => esc_attr( self::ACTION_FIELD ),
					'value' => esc_attr( self::ACTION ),
				),
				array(
					'name'  => esc_attr( '_wpnonce' ),
					'value' => esc_attr( wp_create_nonce( self::NONCE_ACTION ) ),
				),
			),
			'generate_steps'         => $this->steps( $generate_enabled, $state ),
			'bulk_enabled'      => $generate_enabled,
			'bulk_action'       => esc_attr( self::AJAX_ACTION_BULK ),
			'bulk_name'         => esc_attr( self::BULK_FIELD ),
			'bulk_value'        => '1',
			'bulk_label'        => esc_html__( '一括生成・公開', GACHIREAI_TEXT_DOMAIN ),
			'bulk_icon'         => esc_attr( 'robot' ),
			'bulk_desc'         => esc_html__( 'Step1〜4 をまとめて実行し、公開します（公開は PRO のみ）。', GACHIREAI_TEXT_DOMAIN ),
			'bulk_messages'     => array(),
			'bulk_result'       => false,
			'bulk_result_text'  => '',
			'bulk_result_url'   => '',
			'bulk_result_label' => '',
		) + $logs_model + License_Settings::form_model() + License_Settings::matrix_model();
	}
	private static function is_dev_site(): bool {
		$host = (string) wp_parse_url( (string) home_url(), PHP_URL_HOST );
		return '' !== $host && str_ends_with( $host, '.ton' );
	}
	private function logs_model(): array {
		$per_page    = Event_Logger::page_size();
		$total       = Event_Logger::count();
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = min( max( 1, self::logs_page() ), $total_pages );
		$offset      = ( $page - 1 ) * $per_page;
		$pagination = $total_pages > 1 ? $this->logs_pagination_model( $page, $total_pages, $total ) : array();
		return array(
			'logs_title'          => esc_html__( '実行ログ', GACHIREAI_TEXT_DOMAIN ),
			'logs'                => Event_Logger::display( $per_page, $offset ),
			'logs_empty'          => esc_html__( 'まだありません（新しい順に 100 件ずつ表示・30 日で消えます）。', GACHIREAI_TEXT_DOMAIN ),
			'logs_pagination'     => (bool) $pagination,
			'logs_pager_summary'  => $pagination['summary'] ?? '',
			'logs_pager_prev_url' => $pagination['prev_url'] ?? '',
			'logs_pager_next_url' => $pagination['next_url'] ?? '',
			'logs_pages'          => $pagination['pages'] ?? array(),
		);
	}
	private static function logs_page(): int {
		$page = isset( $_GET['logs_page'] ) ? absint( wp_unslash( $_GET['logs_page'] ) ) : 1;
		return max( 1, $page );
	}
	private function logs_pagination_model( int $page, int $total_pages, int $total ): array {
		return array(
			'summary'  => sprintf(
				esc_html__( '全 %1$s 件・%2$s / %3$s ページ', GACHIREAI_TEXT_DOMAIN ),
				number_format_i18n( $total ),
				number_format_i18n( $page ),
				number_format_i18n( $total_pages )
			),
			'prev_url' => $page > 1
				? esc_url( Tabs::url( Tabs::TAB_DASHBOARD, array( 'logs_page' => (string) ( $page - 1 ) ) ) )
				: '',
			'next_url' => $page < $total_pages
				? esc_url( Tabs::url( Tabs::TAB_DASHBOARD, array( 'logs_page' => (string) ( $page + 1 ) ) ) )
				: '',
			'pages'    => $this->logs_page_links( $page, $total_pages ),
		);
	}
	private function logs_page_links( int $page, int $total_pages ): array {
		$links = array();
		$numbers = array_unique( array_merge(
			array( 1, $total_pages ),
			range( max( 1, $page - 1 ), min( $total_pages, $page + 1 ) )
		) );
		sort( $numbers );
		$prev = 0;
		foreach ( $numbers as $number ) {
			if ( $number - $prev > 1 ) {
				$links[] = array(
					'page_current' => false,
					'page_url'     => '',
					'page_label'   => esc_html( '…' ),
				);
			}
			$links[] = array(
				'page_current' => ( $number === $page ),
				'page_url'     => ( $number === $page )
					? ''
					: esc_url( Tabs::url( Tabs::TAB_DASHBOARD, array( 'logs_page' => (string) $number ) ) ),
				'page_label'   => (string) $number,
			);
			$prev = $number;
			}
		return $links;
	}
	private function render_logs_section(): string {
		return $this->render_template_string( 'admin/partials/logs', $this->logs_model() );
	}
	private function send_run_response( callable $renderer, array $extra = array() ): void {
		try {
			$extra['logs_html'] = $this->render_logs_section();
		} catch ( \Throwable $e ) {
		}
		$this->send_form_html( $renderer, $extra );
	}
	private static function elapsed_ms( float $started ): int {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}
	private function record_collect( string $source, array $collected, int $elapsed_ms ): void {
		$label    = $this->source_label_text( $source );
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
	private function steps( bool $enabled, array $state ): array {
		if ( ! $enabled ) {
			return array();
		}
		$has_product = self::has_product( $state );
		$has_reviews = self::has_reviews( $state );
		$collected   = self::normalize_collected( $state['collected'] ?? null );
		$has_post    = (int) ( $state['post_id'] ?? 0 ) > 0;
		$ai_only = Ai_Review::is_only();
		$review_disabled_reason = $ai_only
			? __( '「AI生成のみ」ではレビューを収集しません。', GACHIREAI_TEXT_DOMAIN )
			: __( '先に商品選定を実行してください。', GACHIREAI_TEXT_DOMAIN );
		$review_target = array();
		$review_other  = array();
		$capabilities  = Status::capabilities();
		foreach ( self::REVIEW_SOURCES as $source ) {
			$license_ok      = $capabilities->can_collect_source( $source );
			$disabled        = $ai_only || ! $has_product;
			$disabled_reason = $review_disabled_reason;
			$collectable = Source_Settings::can_collect( $source ) && $license_ok;
			if ( ! $ai_only && ! $license_ok ) {
				$disabled        = true;
				$disabled_reason = __( 'この取得元は現在のライセンスでは利用できません。FREE は AI 生成のみ利用できます。', GACHIREAI_TEXT_DOMAIN );
			}
			if ( ! $disabled && ! $collectable ) {
				$disabled        = true;
				$disabled_reason = Source_Settings::switch_off_reason( $source );
			}
			$button = $this->action(
				$source,
				self::REVIEWS_FIELD,
				$source,
				esc_html( $this->source_label_text( $source ) ),
				self::AJAX_ACTION_REVIEWS,
				$disabled,
				$disabled_reason
			);
			if ( $collectable ) {
				$review_target[] = array_merge(
					$button,
					array( 'action_count' => $this->action_count_text( (array) ( $collected[ $source ] ?? array() ) ) )
				);
				continue;
			}
			$review_other[] = $button;
		}
		$review_rows = array(
			array(
				'row_label'   => esc_html__( '取得対象', GACHIREAI_TEXT_DOMAIN ),
				'row_actions' => $review_target,
			),
			array(
				'row_label'   => esc_html__( '対象外', GACHIREAI_TEXT_DOMAIN ),
				'row_actions' => $review_other,
			),
		);
		return array(
			array(
				'step_key'           => 'select',
				'step_title'         => esc_html__( 'Step1 商品選定', GACHIREAI_TEXT_DOMAIN ),
				'step_messages'      => array(),
				'step_button_rows'   => array(),
				'step_review_lines'  => array(),
				'step_clear_name'    => '',
				'step_clear_action'  => '',
				'step_clear_label'   => '',
				'step_clear_options' => array(),
				'step_actions'       => array(
					array_merge(
						$this->action(
							'select',
							self::SELECT_FIELD,
							'1',
							esc_html__( '商品選定', GACHIREAI_TEXT_DOMAIN ),
							self::AJAX_ACTION_SELECT
						),
						$has_product ? $this->selection_info( (array) $state['product'] ) : array()
					),
				),
			),
			array(
				'step_key'           => 'reviews',
				'step_title'         => esc_html__( 'Step2 レビュー取得', GACHIREAI_TEXT_DOMAIN ),
				'step_messages'      => array(),
				'step_button_rows'   => $review_rows,
				'step_review_lines'  => array(),
				'step_clear_name'    => esc_attr( self::CLEAR_FIELD ),
				'step_clear_action'  => esc_attr( self::AJAX_ACTION_CLEAR ),
				'step_clear_label'   => esc_html__( 'クリア', GACHIREAI_TEXT_DOMAIN ),
				'step_clear_options' => $this->clear_options(),
				'step_actions'       => array(),
			),
			array(
				'step_key'           => 'generate',
				'step_title'         => esc_html__( 'Step3 記事生成', GACHIREAI_TEXT_DOMAIN ),
				'step_messages'      => array(),
				'step_button_rows'   => array(),
				'step_review_lines'  => array(),
				'step_clear_name'    => '',
				'step_clear_action'  => '',
				'step_clear_label'   => '',
				'step_clear_options' => array(),
				'step_actions'       => array(
					$this->action(
						'generate',
						self::GENERATE_FIELD,
						'1',
						esc_html__( '記事生成', GACHIREAI_TEXT_DOMAIN ),
						self::AJAX_ACTION_GENERATE,
						$ai_only ? ! $has_product : ! ( $has_product && $has_reviews ),
						$has_product
							? ( $ai_only
								? __( '「AI生成のみ」です。', GACHIREAI_TEXT_DOMAIN )
								: __( '先にレビュー取得を実行してください。', GACHIREAI_TEXT_DOMAIN ) )
							: __( '先に商品選定を実行してください。', GACHIREAI_TEXT_DOMAIN )
					),
				),
			),
			array(
				'step_key'           => 'eyecatch',
				'step_title'         => esc_html__( 'Step4 アイキャッチ生成', GACHIREAI_TEXT_DOMAIN ),
				'step_messages'      => array(),
				'step_button_rows'   => array(),
				'step_review_lines'  => array(),
				'step_clear_name'    => '',
				'step_clear_action'  => '',
				'step_clear_label'   => '',
				'step_clear_options' => array(),
				'step_actions'       => array(
					$this->action(
						'eyecatch',
						self::EYECATCH_FIELD,
						'1',
						esc_html__( 'アイキャッチ生成', GACHIREAI_TEXT_DOMAIN ),
						self::AJAX_ACTION_EYECATCH,
						! $has_post,
						$has_post
							? ''
							: __( '先に記事生成を実行してください。', GACHIREAI_TEXT_DOMAIN )
					),
				),
			),
		);
	}
	private function action_count_text( array $collected ): string {
		$count = (int) ( $collected['posts'] ?? 0 ) + (int) ( $collected['comments'] ?? 0 );
		return esc_html( sprintf( '(%d)', $count ) );
	}
	private function clear_options(): array {
		$options = array(
			array(
				'option_value'    => esc_attr( self::CLEAR_ALL ),
				'option_label'    => esc_html__( 'すべて', GACHIREAI_TEXT_DOMAIN ),
				'option_selected' => true,
			),
		);
		foreach ( self::REVIEW_SOURCES as $source ) {
			$options[] = array(
				'option_value'    => esc_attr( $source ),
				'option_label'    => esc_html( $this->source_label_text( $source ) ),
				'option_selected' => false,
			);
		}
		return $options;
	}
	private function action( string $key, string $field, string $value, string $label, string $action, bool $disabled = false, string $disabled_reason = '', string $info = '' ): array {
		$icon = $this->action_icon( $key );
		return array(
			'action_key'                   => $key,
			'action_name'                  => esc_attr( $field ),
			'action_value'                 => esc_attr( $value ),
			'action_label'                 => $label,
			'action_count'                 => '',
			'action_action'                => esc_attr( $action ),
			'button_icon'                  => esc_attr( $icon['icon'] ),
			'button_icon_url'              => esc_url( $icon['icon_url'] ),
			'action_disabled'              => $disabled,
			'action_disabled_reason'       => $disabled && '' !== $disabled_reason ? esc_html( $disabled_reason ) : '',
			'action_clear_name'            => esc_attr( self::CLEAR_FIELD ),
			'action_clear_action'          => esc_attr( self::AJAX_ACTION_CLEAR ),
			'action_clear_label'           => esc_html__( 'クリア', GACHIREAI_TEXT_DOMAIN ),
			'action_mark_posted'           => false,
			'action_mark_posted_action'    => '',
			'action_mark_posted_label'     => '',
			'action_mark_excluded'         => false,
			'action_mark_excluded_action'  => '',
			'action_mark_excluded_label'   => '',
			'action_messages'              => array(),
			'action_info'                  => '' !== $info,
			'action_info_text'             => $info,
			'action_info_link_url'         => '',
			'action_info_link_label'       => '',
			'action_info_suffix'           => '',
			'action_result'                => false,
			'action_result_text'           => '',
			'action_result_url'            => '',
			'action_result_label'          => '',
			'action_preview_url'           => '',
			'action_preview_label'         => '',
		);
	}
	private function action_icon( string $key ): array {
		if ( 'select' === $key ) {
			return array(
				'icon'     => 'search',
				'icon_url' => '',
			);
		}
		if ( 'generate' === $key ) {
			return array(
				'icon'     => 'edit',
				'icon_url' => '',
			);
		}
		if ( 'eyecatch' === $key ) {
			return array(
				'icon'     => 'robot',
				'icon_url' => '',
			);
		}
		return array(
			'icon'     => '',
			'icon_url' => 'ec' === $key ? Icons::ec( $this->base_ec() ) : Icons::source( $key ),
		);
	}
	private function flow_state(): array {
		$state = get_transient( self::FLOW_STATE_PREFIX . get_current_user_id() );
		if ( ! is_array( $state ) ) {
			return self::empty_flow_state();
		}
		return array(
			'product'    => is_array( $state['product'] ?? null ) ? $state['product'] : array(),
			'ec'         => (string) ( $state['ec'] ?? '' ),
			'query'      => (string) ( $state['query'] ?? '' ),
			'collected'  => self::normalize_collected( $state['collected'] ?? null ),
			'post_id'    => (int) ( $state['post_id'] ?? 0 ),
			'post_title' => (string) ( $state['post_title'] ?? '' ),
		);
	}
	private static function empty_flow_state(): array {
		return array(
			'product'    => array(),
			'ec'         => '',
			'query'      => '',
			'collected'  => array(),
			'post_id'    => 0,
			'post_title' => '',
		);
	}
	private function save_flow_state( array $state ): void {
		set_transient( self::FLOW_STATE_PREFIX . get_current_user_id(), $state, self::FLOW_STATE_TTL );
	}
	private static function has_product( array $state ): bool {
		return '' !== trim( (string) ( $state['product']['product_name'] ?? '' ) );
	}
	private static function has_reviews( array $state ): bool {
		return array() !== self::normalize_collected( $state['collected'] ?? null );
	}
	private static function normalize_collected( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		if ( array_key_exists( 'posts', $value ) || array_key_exists( 'sources', $value ) ) {
			return array( 'legacy' => $value );
		}
		$map = array();
		foreach ( $value as $source => $result ) {
			if ( is_array( $result ) ) {
				$map[ (string) $source ] = $result;
			}
		}
		return $map;
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
	private function source_label_text( string $source ): string {
		if ( Ec_Review_Collector::SOURCE === $source ) {
			return Product_Selector::ec_label( $this->base_ec() );
		}
		return Source_Settings::source_label( $source );
	}
	private function base_ec(): string {
		$base = (string) ( $this->flow_state()['ec'] ?? '' );
		if ( '' === trim( $base ) ) {
			$base = ( new Product_Selector() )->ec();
		}
		return Product_Selector::normalize_ec( $base );
	}
	private function source_progress_label( string $source ): string {
		switch ( $source ) {
			case Ec_Review_Collector::SOURCE:
				return sprintf(
					__( '%s のレビューを取得中...', GACHIREAI_TEXT_DOMAIN ),
					Product_Selector::ec_label( $this->base_ec() )
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
	private function status_notice( Setup_Progress $progress, bool $generate_enabled ): ?array {
		if ( $generate_enabled ) {
			return null;
		}
		$lines = array();
		foreach ( $progress->missing_required() as $entry ) {
			$lines[] = sprintf(
				esc_html__( '%1$s: %2$s', GACHIREAI_TEXT_DOMAIN ),
				esc_html( (string) $entry['tab_label'] ),
				esc_html( implode( '、', (array) $entry['section_labels'] ) )
			);
		}
		return $this->notice(
			'warning',
			sprintf(
				esc_html__( '未完了の必須設定: %s', GACHIREAI_TEXT_DOMAIN ),
				implode( ' / ', $lines )
			)
		);
	}
}
