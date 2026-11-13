<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Widget;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
final class Disclosure_Widget extends \WP_Widget {
	public function __construct()
	{
		parent::__construct(
			Disclosure::WIDGET_ID_BASE,
			__('開示', GACHIREAI_TEXT_DOMAIN),
			array(
				'classname'   => 'gachireai-disclosure-widget',
				'description' => __('アフィリエイト広告・AI生成コンテンツであることを表示します。', GACHIREAI_TEXT_DOMAIN),
			)
		);
	}
	public static function register(): void
	{
		register_widget(self::class);
	}
	public function widget($args, $instance): void
	{
		$title = ( is_array( $instance ) && array_key_exists( 'title', $instance ) )
			? (string) $instance['title']
			: ( new Settings() )->disclosure_heading();
		$html  = Disclosure::render($title);
		if ('' === $html) {
			return;
		}
		echo isset($args['before_widget']) ? (string) $args['before_widget'] : ''; 
		echo $html; 
		echo isset($args['after_widget']) ? (string) $args['after_widget'] : ''; 
	}
	public function form($instance): void
	{
		$title = is_array($instance) && array_key_exists('title', $instance)
			? (string) $instance['title']
			: ( new Settings() )->disclosure_heading();
		$html = '';
		try {
			$html = (new Div_Renderer())->render(
				'admin/partials/disclosure-widget-form',
				array(
					'field_id'    => esc_attr($this->get_field_id('title')),
					'field_name'  => esc_attr($this->get_field_name('title')),
					'title_label' => esc_html__('タイトル:', GACHIREAI_TEXT_DOMAIN),
					'title_value' => esc_attr($title),
					'title_note'  => esc_html__('空欄で保存するとタイトルを出しません。', GACHIREAI_TEXT_DOMAIN),
				)
			);
		} catch (\Throwable $e) {
			return;
		}
		echo $html; 
	}
	public function update($new_instance, $old_instance): array
	{
		$instance          = is_array($old_instance) ? $old_instance : array();
		$instance['title'] = isset($new_instance['title']) ? sanitize_text_field((string) $new_instance['title']) : '';
		return $instance;
	}
}
