<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm\Providers;
use Tonkatsutei\Gachi_Review_AI\Llm\Model_Catalog;
final class Cerebras_Provider extends Openai_Compatible_Provider {
	protected const SLUG     = Model_Catalog::CEREBRAS;
	protected const ENDPOINT = 'https://api.cerebras.ai/v1/chat/completions';
	protected const LABEL    = 'Cerebras';
}