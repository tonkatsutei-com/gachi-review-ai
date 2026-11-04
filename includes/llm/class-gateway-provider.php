<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm;
final class Gateway_Provider implements Provider {
	private array $settings;
	private string $prompt_key;
	private bool $json;
	private Gateway_Client $client;
	public function __construct( array $settings, string $prompt_key, bool $json = true, ?Gateway_Client $client = null ) {
		$this->settings   = $settings;
		$this->prompt_key = trim( $prompt_key );
		$this->json       = $json;
		$this->client     = $client ?? new Gateway_Client();
	}
	public function generate( string $system, string $user, array $opts = array() ): array {
		return $this->client->generate( $this->settings, $this->prompt_key, $user, $opts, $this->json );
	}
	public function supports_json_mode(): bool {
		return false;
	}
}
