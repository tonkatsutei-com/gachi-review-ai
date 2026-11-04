<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Top;
use Tonkatsutei\Gachi_Review_AI\Ec\Genre_Catalog;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
final class Top_Page_Renderer {
	private const POLICY_PAGE_SLUG = 'privacy-policy';
	public function content( Site_Profile $profile ): string {
		return ( new Div_Renderer() )->render( 'top/top-page', $this->model( $profile ) );
	}
	private function model( Site_Profile $profile ): array {
		$elements = $profile->get_site_elements();
		$scope    = $profile->get_scope();
		$privacy_url = $this->privacy_url();
		$has_privacy = '' !== $privacy_url;
		return array(
			'intro_heading'      => esc_html__( 'はじめまして', GACHIREAI_TEXT_DOMAIN ),
			'profile_paragraphs' => $this->paragraphs( $this->scalar( $elements, 'profile' ) ),
			'about_heading'      => esc_html__( 'このサイトについて', GACHIREAI_TEXT_DOMAIN ),
			'description'        => esc_html( $this->scalar( $elements, 'description' ) ),
			'scope_paragraphs'   => $this->scope_paragraphs( $scope ),
			'disclosure_text'    => esc_html__( '当サイトはアフィリエイト広告（楽天市場・Yahoo!ショッピング・Amazon 等）を利用しています。', GACHIREAI_TEXT_DOMAIN ),
			'disclosure_link_prefix' => esc_html__( '詳しくは', GACHIREAI_TEXT_DOMAIN ),
			'disclosure_link_suffix' => esc_html__( 'をご覧ください。', GACHIREAI_TEXT_DOMAIN ),
			'privacy_label'      => esc_html__( 'プライバシーポリシー', GACHIREAI_TEXT_DOMAIN ),
			'privacy_url'        => esc_url( $privacy_url ),
			'has_privacy'        => $has_privacy,
		);
	}
	private function privacy_url(): string {
		if ( function_exists( 'get_privacy_policy_url' ) ) {
			$url = (string) get_privacy_policy_url();
			if ( '' !== $url ) {
				return $url;
			}
		}
		if ( function_exists( 'get_page_by_path' ) ) {
			$page = get_page_by_path( self::POLICY_PAGE_SLUG );
			if ( $page instanceof \WP_Post && 'trash' !== get_post_status( $page->ID ) ) {
				return (string) get_permalink( $page->ID );
			}
		}
		return '';
	}
	private function paragraphs( string $text ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		$items = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$items[] = array( 'text' => esc_html( $line ) );
		}
		return $items;
	}
	private function scope_paragraphs( array $scope ): array {
		$items = array();
		$names = array();
		foreach ( Genre_Catalog::entries( (string) ( $scope['category'] ?? '' ) ) as $entry ) {
			$name = trim( (string) ( $entry['name'] ?? '' ) );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
		if ( array() !== $names ) {
			$items[] = array(
				'text' => esc_html(
					sprintf(
						__( '主に扱うカテゴリー: %s', GACHIREAI_TEXT_DOMAIN ),
						implode( '、', $names )
					)
				),
			);
		}
		$words = Product_Selector::words( (string) ( $scope['products'] ?? '' ) );
		if ( array() !== $words ) {
			$items[] = array(
				'text' => esc_html(
					sprintf(
						__( 'キーワード: %s', GACHIREAI_TEXT_DOMAIN ),
						implode( '、', $words )
					)
				),
			);
		}
		return $items;
	}
	private function scalar( array $values, string $key ): string {
		$value = $values[ $key ] ?? '';
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
