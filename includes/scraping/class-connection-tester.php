<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Scraping;
use Tonkatsutei\Gachi_Review_AI\Api_Error;
final class Connection_Tester {
	private const TIMEOUT = 20;
	private const ZENROWS_ENDPOINT            = 'https://api.zenrows.com/v1/';
	private const BRIGHT_DATA_ENDPOINT        = 'https://api.brightdata.com/datasets/v3/scrape';
	private const SCRAPERAPI_ACCOUNT_ENDPOINT = 'https://api.scraperapi.com/account';
	private const APIFY_ME_ENDPOINT           = 'https://api.apify.com/v2/users/me';
	private const WEBSCRAPINGAPI_ENDPOINT     = 'https://api.webscrapingapi.com/v2';
	private const CONTEXTDEV_ENDPOINT         = 'https://api.context.dev/v1/web/scrape';
	private const ENSEMBLEDATA_USAGE_ENDPOINT = 'https://ensembledata.com/apis/customer/get-used-units';
	private const SCRAPEDO_INFO_ENDPOINT      = 'https://api.scrape.do/info';
	public static function test( string $service, string $api_key ): array {
		$api_key = trim( $api_key );
		if ( '' === $api_key ) {
			return array(
				'ok'      => false,
				'message' => __( 'APIキーが入力されていません。', GACHIREAI_TEXT_DOMAIN ),
			);
		}
		switch ( $service ) {
			case Service_Catalog::ZENROWS:
				return self::test_zenrows( $api_key );
			case Service_Catalog::BRIGHT_DATA:
				return self::test_bright_data( $api_key );
			case Service_Catalog::SCRAPER_API:
				return self::test_scraperapi( $api_key );
			case Service_Catalog::APIFY:
				return self::test_apify( $api_key );
			case Service_Catalog::WEB_SCRAPING_API:
				return self::test_webscrapingapi( $api_key );
			case Service_Catalog::CONTEXT_DEV:
				return self::test_contextdev( $api_key );
			case Service_Catalog::ENSEMBLE_DATA:
				return self::test_ensembledata( $api_key );
			case Service_Catalog::SCRAPE_DO:
				return self::test_scrape_do( $api_key );
			default:
				return array(
					'ok'      => false,
					'message' => __( '未知のサービスです。', GACHIREAI_TEXT_DOMAIN ),
				);
		}
	}
	private static function test_zenrows( string $api_key ): array {
		$endpoint = add_query_arg( array( 'apikey' => $api_key ), self::ZENROWS_ENDPOINT );
		$response = self::get( $endpoint );
		$error = self::transport_error( $response );
		if ( null !== $error ) {
			return self::fail( $error );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( 401 === $status || 403 === $status ) {
			return self::invalid( $status, $body );
		}
		if ( 400 === $status || ( $status >= 200 && $status < 300 ) ) {
			return self::valid();
		}
		return self::unexpected( $status, $body );
	}
	private static function test_bright_data( string $api_key ): array {
		$response = self::post(
			self::BRIGHT_DATA_ENDPOINT . '?format=json',
			'[]',
			array( 'Authorization' => 'Bearer ' . $api_key )
		);
		$error = self::transport_error( $response );
		if ( null !== $error ) {
			return self::fail( $error );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( 401 === $status ) {
			return self::invalid( $status, $body );
		}
		if ( ( 404 === $status && str_contains( $body, 'Collector not found' ) ) || ( $status >= 200 && $status < 300 ) ) {
			return self::valid();
		}
		return self::unexpected( $status, $body );
	}
	private static function test_scraperapi( string $api_key ): array {
		$url      = add_query_arg( 'api_key', $api_key, self::SCRAPERAPI_ACCOUNT_ENDPOINT );
		$response = self::get( $url );
		$error = self::transport_error( $response );
		if ( null !== $error ) {
			return self::fail( $error );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( 200 === $status ) {
			return self::valid();
		}
		if ( 401 === $status || 403 === $status ) {
			return self::invalid( $status, $body );
		}
		return self::unexpected( $status, $body );
	}
	private static function test_apify( string $api_key ): array {
		$response = self::get( self::APIFY_ME_ENDPOINT, array( 'Authorization' => 'Bearer ' . $api_key ) );
		$error = self::transport_error( $response );
		if ( null !== $error ) {
			return self::fail( $error );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( 200 === $status ) {
			return self::valid();
		}
		if ( 401 === $status ) {
			return self::invalid( $status, $body );
		}
		return self::unexpected( $status, (string) wp_remote_retrieve_body( $response ) );
	}
	private static function test_webscrapingapi( string $api_key ): array {
		$endpoint = add_query_arg( array( 'api_key' => $api_key ), self::WEBSCRAPINGAPI_ENDPOINT );
		$response = self::get( $endpoint );
		$error = self::transport_error( $response );
		if ( null !== $error ) {
			return self::fail( $error );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( 401 === $status || 403 === $status ) {
			return self::invalid( $status, $body );
		}
		if ( 400 === $status || ( $status >= 200 && $status < 300 ) ) {
			return self::valid();
		}
		return self::unexpected( $status, $body );
	}
	private static function test_contextdev( string $api_key ): array {
		$response = self::post(
			self::CONTEXTDEV_ENDPOINT,
			(string) wp_json_encode( array( 'formats' => array( 'html' => true ) ) ),
			array(
				'Authorization' => 'Bearer ' . $api_key,
				'Accept'        => 'application/json',
			)
		);
		$error = self::transport_error( $response );
		if ( null !== $error ) {
			return self::fail( $error );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( 401 === $status || 403 === $status ) {
			return self::invalid( $status, $body );
		}
		if ( 400 === $status || ( $status >= 200 && $status < 300 ) ) {
			return self::valid();
		}
		return self::unexpected( $status, $body );
	}
	private static function test_ensembledata( string $api_key ): array {
		$endpoint = add_query_arg(
			array(
				'date'  => gmdate( 'Y-m-d' ),
				'token' => $api_key,
			),
			self::ENSEMBLEDATA_USAGE_ENDPOINT
		);
		$response = self::get( $endpoint );
		$error = self::transport_error( $response );
		if ( null !== $error ) {
			return self::fail( $error );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( 200 === $status ) {
			return self::valid();
		}
		if ( 401 === $status || 403 === $status || 491 === $status ) {
			return self::invalid( $status, $body );
		}
		return self::unexpected( $status, $body );
	}
	private static function test_scrape_do( string $api_key ): array {
		$endpoint = add_query_arg( array( 'token' => $api_key ), self::SCRAPEDO_INFO_ENDPOINT );
		$response = self::get( $endpoint );
		$error = self::transport_error( $response );
		if ( null !== $error ) {
			return self::fail( $error );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( 401 === $status || 403 === $status ) {
			return self::invalid( $status, $body );
		}
		if ( 200 !== $status ) {
			return self::unexpected( $status, $body );
		}
		$info = json_decode( $body, true );
		if ( is_array( $info ) && ! empty( $info['IsActive'] ) ) {
			return self::valid();
		}
		return array(
			'ok'      => false,
			'message' => Api_Error::append(
				__( 'APIキーが有効ではありません（アカウントが有効化されていません）。', GACHIREAI_TEXT_DOMAIN ),
				$body
			),
		);
	}
	private static function get( string $url, array $headers = array() ) {
		$args = array( 'timeout' => self::TIMEOUT );
		if ( array() !== $headers ) {
			$args['headers'] = $headers;
		}
		return wp_remote_get( $url, $args );
	}
	private static function post( string $url, string $body, array $headers ) {
		$headers['Content-Type'] = 'application/json';
		return wp_remote_post(
			$url,
			array(
				'headers' => $headers,
				'body'    => $body,
				'timeout' => self::TIMEOUT,
			)
		);
	}
	private static function transport_error( $response ): ?string {
		if ( is_wp_error( $response ) ) {
			return sprintf(
				__( '通信に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
				$response->get_error_message()
			);
		}
		return null;
	}
	private static function valid_message(): string {
		return __( 'APIキーは有効です。', GACHIREAI_TEXT_DOMAIN );
	}
	private static function valid(): array {
		return array(
			'ok'      => true,
			'message' => self::valid_message(),
		);
	}
	private static function invalid( int $status, string $body = '' ): array {
		return array(
			'ok'      => false,
			'message' => Api_Error::append(
				sprintf(
					__( 'APIキーが無効です（HTTP %d）。', GACHIREAI_TEXT_DOMAIN ),
					$status
				),
				$body
			),
		);
	}
	private static function fail( string $message ): array {
		return array(
			'ok'      => false,
			'message' => $message,
		);
	}
	private static function unexpected( int $status, string $body ): array {
		return array(
			'ok'      => false,
			'message' => Api_Error::append(
				sprintf(
					__( '想定外の応答です（HTTP %d）。', GACHIREAI_TEXT_DOMAIN ),
					$status
				),
				$body
			),
		);
	}
}
