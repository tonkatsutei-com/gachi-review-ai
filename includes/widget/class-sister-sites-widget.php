<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Widget;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
final class Sister_Sites_Widget extends \WP_Widget
{
	public function __construct()
	{
		parent::__construct(
			Sister_Sites::WIDGET_ID_BASE,
			__('姉妹サイト', GACHIREAI_TEXT_DOMAIN),
			array(
				'classname'   => 'gachireai-sister-sites-widget',
				'description' => __('マルチサイトの姉妹サイトをカードで一覧表示します。', GACHIREAI_TEXT_DOMAIN),
			)
		);
	}
	public static function register(): void
	{
		register_widget(self::class);
	}
	public function widget($args, $instance): void
	{
		if (! is_multisite()) {
			return;
		}
		$sites = Sister_Sites::sites();
		if (array() === $sites) {
			return;
		}
		$title = isset($instance['title']) ? (string) $instance['title'] : '';
		$html  = Sister_Sites::render($title, $sites);
		if ('' === $html) {
			return;
		}
		echo isset($args['before_widget']) ? (string) $args['before_widget'] : ''; 
		echo $html; 
		echo isset($args['after_widget']) ? (string) $args['after_widget'] : ''; 
	}
	public function form($instance): void
	{
		$title = isset($instance['title']) ? (string) $instance['title'] : __('姉妹サイト', GACHIREAI_TEXT_DOMAIN);
		$html  = '';
		try {
			$html = (new Div_Renderer())->render(
				'admin/partials/sister-sites-widget-form',
				array(
					'field_id'    => esc_attr($this->get_field_id('title')),
					'field_name'  => esc_attr($this->get_field_name('title')),
					'title_label' => esc_html__('タイトル:', GACHIREAI_TEXT_DOMAIN),
					'title_value' => esc_attr($title),
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
