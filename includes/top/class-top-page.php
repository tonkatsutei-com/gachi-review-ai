<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Top;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Top_Page {
	private const PAGE_TITLE = 'トップページ';
	public static function generate( Site_Profile $profile ): int {
		$previous_front = 'page' === get_option( 'show_on_front' )
			? (int) get_option( 'page_on_front' )
			: 0;
		$content = ( new Top_Page_Renderer() )->content( $profile );
		$inserted = wp_insert_post(
			array(
				'post_title'   => self::PAGE_TITLE,
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $inserted ) || 0 >= (int) $inserted ) {
			return 0;
		}
		$page_id = (int) $inserted;
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );
		$site_images  = $profile->get_site_images();
		$thumbnail_id = isset( $site_images['top_eyecatch'] ) && is_scalar( $site_images['top_eyecatch'] )
			? absint( (string) $site_images['top_eyecatch'] )
			: 0;
		$top_hero     = ( new Settings() )->image()['top_hero'];
		if ( 0 < $thumbnail_id && Settings::TOP_HERO_WITH_TITLE === $top_hero ) {
			set_post_thumbnail( $page_id, $thumbnail_id );
		}
		self::demote_previous_front( $previous_front, $page_id );
		return $page_id;
	}
	public static function apply_thumbnail( int $attachment_id ): void {
		$page_id = self::front_page_id();
		if ( 0 >= $page_id ) {
			return;
		}
		$top_hero = ( new Settings() )->image()['top_hero'];
		if ( Settings::TOP_HERO_WITH_TITLE === $top_hero && 0 < $attachment_id ) {
			set_post_thumbnail( $page_id, $attachment_id );
			return;
		}
		delete_post_thumbnail( $page_id );
	}
	private static function front_page_id(): int {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return 0;
		}
		$page_id = (int) get_option( 'page_on_front' );
		if ( 0 >= $page_id || 'page' !== get_post_type( $page_id ) || 'trash' === get_post_status( $page_id ) ) {
			return 0;
		}
		return $page_id;
	}
	private static function demote_previous_front( int $previous_id, int $page_id ): void {
		if ( 0 >= $previous_id || $previous_id === $page_id ) {
			return;
		}
		if ( 'page' !== get_post_type( $previous_id ) || 'trash' === get_post_status( $previous_id ) ) {
			return;
		}
		wp_update_post(
			array(
				'ID'          => $previous_id,
				'post_status' => 'draft',
			)
		);
	}
	public static function is_ready(): bool {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return false;
		}
		$page_id = (int) get_option( 'page_on_front' );
		return 0 < $page_id
			&& 'page' === get_post_type( $page_id )
			&& 'publish' === get_post_status( $page_id );
	}
}
