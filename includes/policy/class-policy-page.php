<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Policy;
final class Policy_Page {
	private const POLICY_PAGE_SEEDED_OPTION = 'gachireai_policy_page_seeded';
	private const POLICY_PAGE_LEGACY_SHORTCODE = 'gachireai_policy';
	private const POLICY_PAGE_ID_OPTION = 'gachireai_policy_page_id';
	private const POLICY_PAGE_SLUG = 'privacy-policy';
	private const POLICY_PAGE_TITLE = 'プライバシーポリシー';
	public static function generate(): int {
		$page_id = self::find_policy_page();
		if ( 0 === $page_id ) {
			$page_id = self::insert_policy_page();
		} elseif ( self::needs_content( $page_id ) ) {
			self::write_policy_content( $page_id );
			self::publish_policy_page( $page_id );
		} else {
			self::publish_policy_page( $page_id );
		}
		if ( 0 < $page_id ) {
			update_option( self::POLICY_PAGE_ID_OPTION, $page_id );
		}
		update_option( self::POLICY_PAGE_SEEDED_OPTION, '1' );
		return $page_id;
	}
	private static function insert_policy_page(): int {
		$inserted = wp_insert_post(
			array(
				'post_title'   => self::POLICY_PAGE_TITLE,
				'post_name'    => self::POLICY_PAGE_SLUG,
				'post_content' => self::policy_content(),
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => get_current_user_id(),
			),
			true
		);
		return is_wp_error( $inserted ) ? 0 : (int) $inserted;
	}
	private static function find_policy_page(): int {
		$stored = (int) get_option( self::POLICY_PAGE_ID_OPTION, 0 );
		if ( 0 < $stored && self::is_page( $stored ) ) {
			return $stored;
		}
		$page = get_page_by_path( self::POLICY_PAGE_SLUG );
		if ( $page instanceof \WP_Post && self::is_page( (int) $page->ID ) ) {
			return (int) $page->ID;
		}
		return 0;
	}
	private static function is_page( int $post_id ): bool {
		return 'page' === get_post_type( $post_id ) && 'trash' !== get_post_status( $post_id );
	}
	private static function needs_content( int $post_id ): bool {
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( '' === trim( $content ) ) {
			return true;
		}
		if ( str_contains( $content, '[' . self::POLICY_PAGE_LEGACY_SHORTCODE ) ) {
			return true;
		}
		return 'publish' !== (string) get_post_status( $post_id );
	}
	private static function write_policy_content( int $post_id ): void {
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => self::policy_content(),
			)
		);
	}
	private static function publish_policy_page( int $post_id ): void {
		if ( 'publish' !== get_post_status( $post_id ) ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			);
		}
	}
	private static function policy_content(): string {
		try {
			return ( new Policy_Renderer() )->content();
		} catch ( \Throwable $e ) {
			return '';
		}
	}
	public static function is_ready(): bool {
		$page_id = self::find_policy_page();
		return 0 < $page_id && 'publish' === get_post_status( $page_id );
	}
}
