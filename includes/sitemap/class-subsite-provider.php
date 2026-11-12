<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Sitemap;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Subsite_Provider extends \WP_Sitemaps_Provider {
	private array $urls;
	public function __construct( array $urls ) {
		$this->name        = 'gachireai-subsites';
		$this->object_type = 'subsite';
		$this->urls        = array_values( array_filter( $urls, 'is_string' ) );
	}
	public function get_sitemap_entries() {
		$sitemaps = array();
		foreach ( $this->urls as $url ) {
			$sitemaps[] = array( 'loc' => $url );
		}
		return $sitemaps;
	}
	public function get_url_list( $page_num, $object_subtype = '' ) {
		return array();
	}
	public function get_max_num_pages( $object_subtype = '' ) {
		return 0;
	}
}
