<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm\Providers;
use Tonkatsutei\Gachi_Review_AI\Llm\Provider;
final class Anthropic_Provider implements Provider {
	private string $api_key;
	private string $model;
	public function __construct( string $api_key, string $model = '' ) {
		$this->api_key = $api_key;
		$this->model   = $model;
	}
	public function generate( string $system, string $user, array $opts = array() ): array {
		throw new \LogicException( 'Anthropic プロバイダーは未実装です。「LLM・ASP設定」で OpenAI / DeepSeek / Google AI Studio / Groq / Cerebras のいずれかを選択してください。' );
	}
	public function supports_json_mode(): bool {
		return false;
	}
}
