<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
final class Font_Catalog {
	public const DEFAULT_FONT = 'noto_sans';
	public const DEFAULT_WEIGHT = '700';
	public static function families(): array {
		return array(
			'noto_sans'        => array(
				'label'          => 'Noto Sans Japanese',
				'google'         => 'Noto Sans JP',
				'weights'        => array( 400, 500, 700, 900 ),
				'default_weight' => 700,
			),
			'noto_serif'       => array(
				'label'          => 'Noto Serif Japanese',
				'google'         => 'Noto Serif JP',
				'weights'        => array( 400, 500, 700, 900 ),
				'default_weight' => 700,
			),
			'shippori_antique' => array(
				'label'          => 'Shippori Antique',
				'google'         => 'Shippori Antique',
				'weights'        => array( 400 ),
				'default_weight' => 400,
			),
			'mochiy_pop_one'   => array(
				'label'          => 'Mochiy Pop One',
				'google'         => 'Mochiy Pop One',
				'weights'        => array( 400 ),
				'default_weight' => 400,
			),
		);
	}
	public static function is_builtin( string $slug ): bool {
		return isset( self::families()[ $slug ] );
	}
	public static function family( string $slug ): ?array {
		return self::families()[ $slug ] ?? null;
	}
	public static function label( string $slug ): string {
		$family = self::family( $slug );
		return null !== $family ? (string) $family['label'] : $slug;
	}
	public static function css_family( string $slug ): string {
		$family = self::family( $slug );
		return null !== $family ? (string) $family['google'] : '';
	}
	public static function options(): array {
		$options = array();
		foreach ( self::families() as $slug => $family ) {
			$options[ $slug ] = (string) $family['label'];
		}
		return $options;
	}
	public static function weight_labels(): array {
		$weights = array();
		foreach ( self::families() as $family ) {
			foreach ( (array) $family['weights'] as $weight ) {
				$weights[ (int) $weight ] = true;
			}
		}
		ksort( $weights );
		$labels = array(
			400 => __( '標準', GACHIREAI_TEXT_DOMAIN ),
			500 => __( '中太', GACHIREAI_TEXT_DOMAIN ),
			700 => __( '太字', GACHIREAI_TEXT_DOMAIN ),
			900 => __( '極太', GACHIREAI_TEXT_DOMAIN ),
		);
		$result = array();
		foreach ( array_keys( $weights ) as $weight ) {
			$result[ (int) $weight ] = $labels[ (int) $weight ] ?? (string) $weight;
		}
		return $result;
	}
	public static function weights( string $slug ): array {
		$family = self::family( $slug );
		return null !== $family ? array_map( 'intval', (array) $family['weights'] ) : array();
	}
	public static function default_weight( string $slug ): string {
		$family = self::family( $slug );
		return null !== $family ? (string) (int) $family['default_weight'] : self::DEFAULT_WEIGHT;
	}
	public static function normalize_weight( string $slug, string $weight ): string {
		$weights = array_map( 'strval', self::weights( $slug ) );
		if ( in_array( $weight, $weights, true ) ) {
			return $weight;
		}
		return self::default_weight( $slug );
	}
}
