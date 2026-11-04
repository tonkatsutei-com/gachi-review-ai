<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Policy;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
final class Policy_Renderer {
	public function content(): string {
		return ( new Div_Renderer() )->render( 'policy/policy' );
	}
}
