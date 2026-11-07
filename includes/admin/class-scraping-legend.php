<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\Scraping\Service_Catalog;
final class Scraping_Legend {
	public static function model(): array {
		$items = array();
		foreach ( Service_Catalog::used_by_descriptions() as $tag ) {
			$items[] = array(
				'tag_key'         => esc_attr( $tag['key'] ),
				'tag_label'       => esc_html( $tag['label'] ),
				'tag_description' => esc_html( $tag['description'] ),
			);
		}
		return $items;
	}
	public static function tags( string $service_id ): array {
		$items = array();
		foreach ( Service_Catalog::used_by_tags( $service_id ) as $tag ) {
			$items[] = array(
				'tag_key'   => esc_attr( $tag['key'] ),
				'tag_label' => esc_html( $tag['label'] ),
			);
		}
		return $items;
	}
}
