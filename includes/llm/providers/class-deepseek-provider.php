<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm\Providers;
use Tonkatsutei\Gachi_Review_AI\Llm\Model_Catalog;
final class Deepseek_Provider extends Openai_Compatible_Provider {
	protected const SLUG     = Model_Catalog::DEEPSEEK;
	protected const ENDPOINT = 'https://api.deepseek.com/chat/completions';
	protected const LABEL    = 'DeepSeek';
	public function supports_json_mode(): bool {
		return false;
	}
}
