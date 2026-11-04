<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
final class Tabs {
	public const PAGE_SLUG = 'gachi-review-ai';
	public const TAB_DASHBOARD = 'dashboard';
	public const TAB_LLM = 'llm';
	public const TAB_ASP = 'asp';
	public const TAB_SITE = 'site';
	public const TAB_ARTICLE = 'article';
	public const TAB_REVIEW = 'review';
	public const TAB_SCRAPING = 'scraping';
	public const TAB_MAINTENANCE = 'maintenance';
	public static function slugs(): array {
		return array(
			self::TAB_DASHBOARD,
			self::TAB_ASP,
			self::TAB_LLM,
			self::TAB_SCRAPING,
			self::TAB_SITE,
			self::TAB_ARTICLE,
			self::TAB_REVIEW,
			self::TAB_MAINTENANCE,
		);
	}
	public static function label( string $tab ): string {
		switch ( $tab ) {
			case self::TAB_LLM:
				return __( 'AI設定', GACHIREAI_TEXT_DOMAIN );
			case self::TAB_ASP:
				return __( 'ASP設定', GACHIREAI_TEXT_DOMAIN );
			case self::TAB_SITE:
				return __( 'サイト設定', GACHIREAI_TEXT_DOMAIN );
			case self::TAB_ARTICLE:
				return __( '記事設定', GACHIREAI_TEXT_DOMAIN );
			case self::TAB_REVIEW:
				return __( 'レビュー設定', GACHIREAI_TEXT_DOMAIN );
			case self::TAB_SCRAPING:
				return __( 'スクレイピング設定', GACHIREAI_TEXT_DOMAIN );
			case self::TAB_MAINTENANCE:
				return __( 'メンテナンス', GACHIREAI_TEXT_DOMAIN );
		}
		return __( 'ダッシュボード', GACHIREAI_TEXT_DOMAIN );
	}
	public static function current(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		return in_array( $tab, self::slugs(), true ) ? $tab : self::TAB_DASHBOARD;
	}
	public static function url( string $tab, array $args = array() ): string {
		$url = add_query_arg( 'tab', $tab, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		return array() === $args ? $url : add_query_arg( $args, $url );
	}
	public static function models( string $current ): array {
		$tabs = array();
		foreach ( self::slugs() as $tab ) {
			$tabs[] = array(
				'tab_label'  => esc_html( self::label( $tab ) ),
				'tab_url'    => esc_url( self::url( $tab ) ),
				'tab_active' => ( $tab === $current ),
			);
		}
		return $tabs;
	}
}
