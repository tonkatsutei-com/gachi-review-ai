<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Ec;
use Tonkatsutei\Gachi_Review_AI\License\Client as License_Client;
use Tonkatsutei\Gachi_Review_AI\License\Store as License_Store;
final class Amazon_Category_Gateway {
	public const PATH = 'amazon/categories.php';
	public const TIMEOUT = 20;
	public const TTL = 86400;
	public const LEVEL_MAX = 3;
	private string $base;
	public function __construct( ?string $base = null ) {
		$resolved   = null === $base ? self::base_url() : $base;
		$this->base = rtrim( trim( $resolved ), '/' ) . '/';
	}
	public static function base_url(): string {
		foreach ( array( 'GACHIREAI_AMAZON_CATEGORY_GATEWAY', 'GACHIREAI_LLM_GATEWAY' ) as $constant ) {
			if ( defined( $constant ) && is_string( constant( $constant ) ) ) {
				$defined = trim( (string) constant( $constant ) );
				if ( '' !== $defined ) {
					return $defined;
				}
			}
		}
		return License_Client::api_base();
	}
	public static function is_configured(): bool {
		return defined( 'GACHIREAI_AMAZON_CATEGORY_GATEWAY' )
			&& is_string( GACHIREAI_AMAZON_CATEGORY_GATEWAY )
			&& '' !== trim( GACHIREAI_AMAZON_CATEGORY_GATEWAY );
	}
	public function children( string $parent_id, int $depth ): array {
		$suffix = $parent_id . ':' . $depth;
		$cached = $this->cached( 'children', $suffix );
		if ( null !== $cached ) {
			return $cached;
		}
		$data     = $this->post( array( 'parent_id' => $parent_id, 'depth' => $depth ) );
		$children = isset( $data['children'] ) && is_array( $data['children'] ) ? $data['children'] : array();
		$children = $this->normalize_children( $children );
		$this->store( 'children', $suffix, $children );
		return $children;
	}
	public function tree(): array {
		$cached = $this->cached( 'tree', 'all' );
		if ( null !== $cached ) {
			return $cached;
		}
		$data  = $this->post( array( 'mode' => 'tree' ) );
		$nodes = isset( $data['nodes'] ) && is_array( $data['nodes'] ) ? $data['nodes'] : array();
		$nodes = $this->normalize_nodes( $nodes );
		$this->store( 'tree', 'all', $nodes );
		return $nodes;
	}
	public function resolve( string $node_id ): array {
		$cached = $this->cached( 'node', $node_id );
		if ( null !== $cached ) {
			return $cached;
		}
		$data = $this->post( array( 'node_id' => $node_id ) );
		$node = isset( $data['node'] ) && is_array( $data['node'] ) ? $data['node'] : array();
		$out  = array();
		if ( array() !== $node ) {
			$out = array(
				'genre_id'  => (string) ( $node['id'] ?? '' ),
				'name'      => (string) ( $node['name'] ?? '' ),
				'parent_id' => (string) ( $node['parent_id'] ?? '' ),
				'level'     => self::LEVEL_MAX,
			);
		}
		$this->store( 'node', $node_id, $out );
		return $out;
	}
	private function post( array $payload ): array {
		$license_id = License_Store::key();
		if ( '' === $license_id ) {
			throw new \RuntimeException( __( 'ライセンスキーが未設定のため、Amazon のカテゴリーを取得できません。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$request = array_merge(
			array(
				'license_id' => $license_id,
				'site_url'   => function_exists( 'home_url' ) ? (string) home_url( '/' ) : '',
			),
			$payload
		);
		$response = wp_remote_post(
			$this->base . self::PATH,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $license_id,
					'User-Agent'    => 'gachi-review-ai/' . GACHIREAI_APP_VERSION,
				),
				'body'    => (string) wp_json_encode( $request ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException(
				sprintf(
					__( 'Amazon カテゴリーの取得に失敗しました（%s）。', GACHIREAI_TEXT_DOMAIN ),
					$response->get_error_message()
				)
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			$message = is_array( $data ) && isset( $data['message'] ) && is_scalar( $data['message'] ) ? trim( (string) $data['message'] ) : '';
			throw new \RuntimeException(
				'' !== $message
					? $message
					: sprintf(
						__( 'Amazon カテゴリーの取得に失敗しました（HTTP %d）。', GACHIREAI_TEXT_DOMAIN ),
						$code
					)
			);
		}
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( __( 'Amazon カテゴリーの応答を解釈できませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return $data;
	}
	private function cache_key( string $kind, string $suffix ): string {
		return 'gachireai_amazon_cat_v2_' . md5( $kind . ':' . $suffix );
	}
	private function cached( string $kind, string $suffix ): ?array {
		$value = get_transient( $this->cache_key( $kind, $suffix ) );
		return is_array( $value ) ? $value : null;
	}
	private function store( string $kind, string $suffix, array $value ): void {
		if ( array() === $value ) {
			return;
		}
		set_transient( $this->cache_key( $kind, $suffix ), $value, self::TTL );
	}
	private function normalize_children( array $children ): array {
		$out = array();
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$id = trim( (string) ( $child['id'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}
			$out[] = array( 'id' => $id, 'name' => (string) ( $child['name'] ?? '' ) );
		}
		return $out;
	}
	private function normalize_nodes( array $nodes ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$id = trim( (string) ( $node['genre_id'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}
			$out[] = array(
				'genre_id'  => $id,
				'name'      => (string) ( $node['name'] ?? '' ),
				'parent_id' => (string) ( $node['parent_id'] ?? '' ),
				'level'     => (int) ( $node['level'] ?? 0 ),
			);
		}
		return $out;
	}
}
