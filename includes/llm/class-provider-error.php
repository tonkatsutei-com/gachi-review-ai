<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm;
final class Provider_Error {
	public static function message( array $body ): string {
		$error = $body['error'] ?? null;
		if ( is_array( $error ) && isset( $error['message'] ) && is_scalar( $error['message'] ) ) {
			return (string) $error['message'];
		}
		if ( isset( $body['message'] ) && is_scalar( $body['message'] ) ) {
			return (string) $body['message'];
		}
		return '';
	}
	public static function hint( int $code, string $raw = '' ): string {
		switch ( $code ) {
			case 401:
				return __( "API キーが正しくない可能性があります。\nキーを確認して保存し直してください。", GACHIREAI_TEXT_DOMAIN );
			case 402:
				return __( "このアカウントでは支払い情報（クレジット）が必要です。\nプロバイダーの Billing を確認してください。", GACHIREAI_TEXT_DOMAIN );
			case 403:
				return __( "このキーでは許可されていない操作です。\nキーの権限を確認してください。", GACHIREAI_TEXT_DOMAIN );
			case 404:
				return __( "モデルが見つかりません。\nモデル名が誤っているか、このアカウントでは利用できません。", GACHIREAI_TEXT_DOMAIN );
			case 429:
				return __( "レート制限（回数・トークン）に達しました。\n少し待ってから再試行してください。\n無料枠は 1 分あたりの上限が低いことがあります。", GACHIREAI_TEXT_DOMAIN );
			case 500:
			case 502:
			case 504:
				return __( "プロバイダー側で一時的な障害が起きています。\n少し待ってから再試行してください。", GACHIREAI_TEXT_DOMAIN );
			case 503:
				return __( "プロバイダー側が混雑しています。\n少し待ってから再試行してください。", GACHIREAI_TEXT_DOMAIN );
		}
		if ( $code >= 400 && $code < 500 && str_contains( $raw, 'response_format' ) ) {
			return __( "このモデルは JSON 出力の指定に対応していない可能性があります。\n開発者に連絡してください。\ninfo@tonkatsutei.com", GACHIREAI_TEXT_DOMAIN );
		}
		return '';
	}
}
