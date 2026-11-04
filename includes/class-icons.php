<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
final class Icons {
	public static function source( string $source ): string {
		$files = array(
			'youtube'    => 'youtube.svg',
			'reddit'     => 'reddit.svg',
			'fivech'     => 'fivech.svg',
			'hackernews' => 'hackernews.svg',
			'mastodon'   => 'mastodon.svg',
			'x'          => 'x.svg',
			'instagram'  => 'instagram.svg',
			'facebook'   => 'facebook.svg',
		);
		if ( ! isset( $files[ $source ] ) ) {
			return '';
		}
		return self::url( 'source/' . $files[ $source ] );
	}
	public static function ec( string $ec ): string {
		$files = array(
			Product_Selector::EC_RAKUTEN => 'rakuten.svg',
			Product_Selector::EC_YAHOO   => 'yahoo.svg',
			Product_Selector::EC_AMAZON  => 'amazon.svg',
		);
		if ( ! isset( $files[ $ec ] ) ) {
			return '';
		}
		return self::url( $files[ $ec ] );
	}
	private static function url( string $relative ): string {
		if ( '' === $relative || str_ends_with( $relative, '/' ) ) {
			return '';
		}
		if ( ! is_readable( GACHIREAI_PLUGIN_DIR . 'assets/img/' . $relative ) ) {
			return '';
		}
		return GACHIREAI_PLUGIN_URL . 'assets/img/' . $relative;
	}
}
