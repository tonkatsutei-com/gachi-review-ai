<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\Article\Batch_Generator;
use Tonkatsutei\Gachi_Review_AI\Article\Generate_Progress;
use Tonkatsutei\Gachi_Review_AI\Setup_Progress;
final class Ai_Generate_Metabox extends Screen {
	public const AJAX_ACTION = 'gachireai_generate_from_editor';
	private const NONCE_ACTION = 'gachireai_generate_from_editor';
	private const BOX_ID = 'gachireai-ai-generate';
	public static function create_nonce(): string {
		return wp_create_nonce( self::NONCE_ACTION );
	}
	public function add(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen instanceof \WP_Screen && 'post' !== (string) $screen->post_type ) {
			return;
		}
		add_meta_box(
			self::BOX_ID,
			esc_html__( 'ガチレビュー', GACHIREAI_TEXT_DOMAIN ),
			array( $this, 'render' ),
			'post',
			'side',
			'default'
		);
	}
	public function render( \WP_Post $post ): void {
		$this->render_template( 'admin/ai-generate-metabox', $this->model() );
	}
	public function ajax_generate(): void {
		$this->guard_ajax( self::NONCE_ACTION );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}
		$progress = new Setup_Progress();
		if ( ! $progress->all_complete() ) {
			$this->send_form_html(
				fn(): string => $this->render_template_string( 'admin/ai-generate-metabox', $this->model() ),
				array(
					'error' => __( '設定が完了していないため、AI生成を実行できません。「設定の進捗」をすべて「完了」にしてください。', GACHIREAI_TEXT_DOMAIN ),
				)
			);
			return;
		}
		$outcome = ( new Batch_Generator() )->run(
			get_current_user_id(),
			false,
			__( '公開は行わず、下書きのまま保存しました。', GACHIREAI_TEXT_DOMAIN )
		);
		$extra = array();
		$error = '';
		if ( 0 < (int) ( $outcome['post_id'] ?? 0 ) && '' !== (string) ( $outcome['edit_url'] ?? '' ) ) {
			$extra['post_id'] = (int) $outcome['post_id'];
			$extra['edit_url'] = esc_url_raw( (string) $outcome['edit_url'] );
		} else {
			$error = $this->first_error( (array) $outcome['messages'] );
		}
		if ( '' !== $error ) {
			$extra['error'] = wp_specialchars_decode( $error, ENT_QUOTES );
		}
		$this->send_form_html(
			fn(): string => $this->render_template_string( 'admin/ai-generate-metabox', $this->model() ),
			$extra
		);
	}
	private function model(): array {
		$total = Generate_Progress::STEP_TOTAL;
		$ready = ( new Setup_Progress() )->all_complete();
		$dots = array();
		for ( $i = 1; $i <= $total; $i++ ) {
			$dots[] = array( 'dot_index' => (string) $i );
		}
		return array(
			'ai_generate_label'          => esc_html__( 'AIで記事を生成', GACHIREAI_TEXT_DOMAIN ),
			'ai_generate_icon'           => esc_attr( 'robot' ),
			'ai_generate_is_disabled'    => ! $ready,
			'ai_generate_blocked_reason' => esc_attr__( '設定が完了していないため、AI生成を実行できません。「設定の進捗」をすべて「完了」にしてください。', GACHIREAI_TEXT_DOMAIN ),
			'ai_generate_total'          => (string) $total,
			'ai_generate_initial_phase'  => '1',
			'ai_generate_overlay_title'  => esc_html__( 'AIが記事を生成しています', GACHIREAI_TEXT_DOMAIN ),
			'ai_generate_initial_label'  => esc_html__( 'AI生成を開始...', GACHIREAI_TEXT_DOMAIN ),
			'ai_generate_retry_label'    => esc_html__( 'リトライ中', GACHIREAI_TEXT_DOMAIN ),
			'ai_generate_abort_label'    => esc_html__( '中断', GACHIREAI_TEXT_DOMAIN ),
			'ai_generate_dots'           => $dots,
		);
	}
	private function first_error( array $messages ): string {
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || 'error' !== (string) ( $message['msg_type'] ?? '' ) ) {
				continue;
			}
			return (string) ( $message['msg_text'] ?? '' );
		}
		return '';
	}
}
