<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
final class Gateway_Provider implements Provider {
	private array $image;
	private string $api_key;
	private Gateway_Client $client;
	public function __construct( array $image, string $api_key, ?Gateway_Client $client = null ) {
		$this->image   = $image;
		$this->api_key = trim( $api_key );
		$this->client  = $client ?? new Gateway_Client();
	}
	public function generate( string $prompt, array $opts = array() ): array {
		return $this->client->generate( $this->image, $this->api_key, $prompt, $opts );
	}
	public function test(): array {
		try {
			return Manager::provider( $this->image, $this->api_key )->test();
		} catch ( \Throwable $e ) {
			return array(
				'type'    => 'error',
				'message' => Validator::scrub( $e->getMessage(), $this->api_key ),
			);
		}
	}
	public function model(): string {
		return Image_Catalog::normalize_model(
			(string) ( $this->image['provider'] ?? '' ),
			(string) ( $this->image['model'] ?? '' )
		);
	}
	public function label(): string {
		return Image_Catalog::label( (string) ( $this->image['provider'] ?? '' ) );
	}
}
