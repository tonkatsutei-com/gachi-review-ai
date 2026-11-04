<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm\Providers;
use Tonkatsutei\Gachi_Review_AI\Llm\Model_Catalog;
final class Groq_Provider extends Openai_Compatible_Provider {
	protected const SLUG     = Model_Catalog::GROQ;
	protected const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';
	protected const LABEL    = 'Groq';
}