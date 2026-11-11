<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}
if (class_exists('Get_Ec_Reviews_API_Client')) {
    return;
}
class Get_Ec_Reviews_API_Client {
    private const TIMEOUT = 20;
    private const SEARCH_TIMEOUT = 90;
    private const BRIGHT_DATA_ENDPOINT = 'https://api.brightdata.com/datasets/v3/scrape';
    private const SCRAPERAPI_ACCOUNT_ENDPOINT = 'https://api.scraperapi.com/account';
    private const SCRAPERAPI_AMAZON_SEARCH_ENDPOINT = 'https://api.scraperapi.com/structured/amazon/search';
    private const SCRAPERAPI_HTML_ENDPOINT = 'https://api.scraperapi.com/';
    private const SCRAPERAPI_SEARCH_TLD = 'co.jp';
    private const SCRAPERAPI_COUNTRY_CODE = 'jp';
    private const SCRAPERAPI_AMAZON_DOMAIN = 'https://www.amazon.co.jp';
    private const APIFY_ME_ENDPOINT = 'https://api.apify.com/v2/users/me';
    private const APIFY_AMAZON_SEARCH_ACTOR = 'automly~amazon-products-scraper---fast-efficient-with-sales-data';
    private const APIFY_AMAZON_REVIEWS_ACTOR = 'junglee~amazon-reviews-scraper';
    private const APIFY_WEB_SCRAPER_ACTOR = 'apify~web-scraper';
    public const APIFY_WEB_SCRAPER_APPROVAL_URL = 'https://console.apify.com/actors/moJRLRc85AitArpNN?approvePermissions=true';
    private const APIFY_WEB_SCRAPER_PAGE_FUNCTION = "async function pageFunction(context) {\n    var html = '';\n    try {\n        if (context && context.page && typeof context.page.content === 'function') {\n            html = await context.page.content();\n        }\n    } catch (e) {\n        html = '';\n    }\n    if (!html && typeof document !== 'undefined' && document && document.documentElement) {\n        html = document.documentElement.outerHTML;\n    }\n    return { url: (context && context.request && context.request.url) || '', html: html };\n}";
    private const APIFY_ACTOR_RUNS_ENDPOINT = 'https://api.apify.com/v2/acts/';
    private const APIFY_ACTOR_RUN_ENDPOINT = 'https://api.apify.com/v2/actor-runs/';
    private const APIFY_DATASETS_ENDPOINT = 'https://api.apify.com/v2/datasets/';
    private const APIFY_AMAZON_MARKETPLACE = 'co.jp';
    private const APIFY_AMAZON_DOMAIN = 'https://www.amazon.co.jp';
    private const APIFY_POLL_INTERVAL = 5;
    private const APIFY_POLL_MAX_ATTEMPTS = 20;
    private const APIFY_DEFAULT_MAX_ITEMS = 10;
    private const BRIGHT_DATA_SEARCH_DATASET = 'gd_lwdb4vjm1ehb499uxs';
    private const BRIGHT_DATA_DETAIL_DATASET = 'gd_l7q7dkf244hwjntr0';
    private const BRIGHT_DATA_REVIEWS_DATASET = 'gd_le8e811kzy4ggddlq';
    public const REVIEWS_DEFAULT_MAX = 10;
    private const BRIGHT_DATA_SEARCH_DOMAIN = 'https://www.amazon.co.jp';
    private const BRIGHT_DATA_PROGRESS_ENDPOINT = 'https://api.brightdata.com/datasets/v3/progress/';
    private const BRIGHT_DATA_SNAPSHOTS_ENDPOINT = 'https://api.brightdata.com/datasets/v3/snapshot/';
    private const BRIGHT_DATA_UNLOCKER_ENDPOINT = 'https://api.brightdata.com/request';
    private const BRIGHT_DATA_ZONES_ENDPOINT = 'https://api.brightdata.com/zone/get_active_zones';
    private const BRIGHT_DATA_DEFAULT_UNLOCKER_ZONE = 'web_unlocker1';
    private const BRIGHT_DATA_ZONE_CACHE_TTL = 86400;
    private const BRIGHT_DATA_ZONE_CACHE_PREFIX = 'getecrev_brightdata_zone_';
    private const POLL_INTERVAL = 5;
    private const POLL_MAX_ATTEMPTS = 36;
    private const ZENROWS_ENDPOINT = 'https://api.zenrows.com/v1/';
    private const ZENROWS_AMAZON_DOMAIN = 'https://www.amazon.co.jp';
    private const ZENROWS_PROXY_COUNTRY = 'jp';
    private const WEBSCRAPINGAPI_ENDPOINT = 'https://api.webscrapingapi.com/v2';
    private const WEBSCRAPINGAPI_AMAZON_DOMAIN = 'https://www.amazon.co.jp';
    private const WEBSCRAPINGAPI_COUNTRY = 'jp';
    private const CONTEXTDEV_ENDPOINT = 'https://api.context.dev/v1/web/scrape';
    private const CONTEXTDEV_AMAZON_DOMAIN = 'https://www.amazon.co.jp';
    private const CONTEXTDEV_COUNTRY = 'JP';
    private const RAKUTEN_DOMAIN = 'https://review.rakuten.co.jp';
    private const RAKUTEN_API_ENDPOINT = 'https://openapi.rakuten.co.jp/ichibams/api/IchibaItem/Search/20260701';
    private const YAHOO_API_ENDPOINT = 'https://shopping.yahooapis.jp/ShoppingWebService/V3/itemSearch';
    private const YAHOO_ITEM_ENDPOINT = 'https://shopping.yahooapis.jp/ShoppingWebService/V1/json/itemLookup';
    private const RAKUTEN_SHOPID_CACHE_PREFIX = 'getecrev_rakuten_shopid_';
    private const RAKUTEN_MIN_INTERVAL_US = 1100000;
    private const RAKUTEN_THROTTLE_KEY = 'getecrev_rakuten_api_last';
    private const AMAZON_DOMAIN = 'https://www.amazon.co.jp';
    private static bool $last_transport_fallback = false;
    private static string $last_raw = '';
    public static function last_raw(): string {
        return self::$last_raw;
    }
    public static function test(string $service, string $api_key): array {
        if (trim($api_key) === '') {
            return ['ok' => false, 'message' => __('API キーが入力されていません。', 'get-ec-reviews-api')];
        }
        return match ($service) {
            'zenrows'        => self::test_zenrows($api_key),
            'bright_data'    => self::test_bright_data($api_key),
            'scraperapi'     => self::test_scraperapi($api_key),
            'apify'          => self::test_apify($api_key),
            'webscrapingapi' => self::test_webscrapingapi($api_key),
            'contextdev'     => self::test_contextdev($api_key),
            default          => ['ok' => false, 'message' => __('未知のサービスです。', 'get-ec-reviews-api')],
        };
    }
    public static function reviews(string $service, string $api_key, string $site, string $code, array $args = [], array $ec_keys = []): array {
        @set_time_limit(300);
        self::$last_transport_fallback = false;
        self::$last_raw = '';
        if (trim($api_key) === '') {
            return self::with_failure_flags(['ok' => false, 'records' => [], 'message' => __('API キーが入力されていません。', 'get-ec-reviews-api')]);
        }
        if ($site === 'amazon') {
            $result = match ($service) {
                'zenrows'        => self::reviews_zenrows($api_key, $code, $args),
                'bright_data'    => self::reviews_bright_data($api_key, $code, $args),
                'scraperapi'     => self::reviews_scraperapi($api_key, $code, $args),
                'apify'          => self::reviews_apify($api_key, $code, $args),
                'webscrapingapi' => self::reviews_webscrapingapi($api_key, $code, $args),
                'contextdev'     => self::reviews_contextdev($api_key, $code, $args),
                default          => ['ok' => false, 'records' => [], 'message' => __('このサービスでの Amazon レビュー取得は未実装です。', 'get-ec-reviews-api')],
            };
            if (!empty($result['ok']) && self::$last_raw === '') {
                self::$last_raw = (string) wp_json_encode($result['records'] ?? []);
            }
            return self::with_failure_flags($result);
        }
        $result = self::reviews_html_site($service, $api_key, $site, $code, $args, $ec_keys);
        return self::with_failure_flags($result);
    }
    private static function reviews_bright_data(string $api_key, string $asin, array $args): array {
        $endpoint = add_query_arg(
            ['dataset_id' => self::BRIGHT_DATA_REVIEWS_DATASET, 'format' => 'json'],
            self::BRIGHT_DATA_ENDPOINT
        );
        $input = [
            'url'         => self::BRIGHT_DATA_SEARCH_DOMAIN . '/dp/' . rawurlencode($asin),
            'max_reviews' => max(1, (int) ($args['max_reviews'] ?? self::REVIEWS_DEFAULT_MAX)),
        ];
        $response = wp_remote_post(
            $endpoint,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => (string) wp_json_encode(['input' => [$input]]),
                'timeout' => self::SEARCH_TIMEOUT,
            ]
        );
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'records' => [], 'message' => $error];
        }
        $status        = (int) wp_remote_retrieve_response_code($response);
        $response_body = (string) wp_remote_retrieve_body($response);
        if ($status === 200) {
            $records = json_decode($response_body, true);
            if (!is_array($records)) {
                return ['ok' => false, 'records' => [], 'message' => __('応答を解析できませんでした（JSON 形式ではありません）。', 'get-ec-reviews-api')];
            }
            return self::finalize_bright_data_result(['ok' => true, 'records' => $records, 'message' => '']);
        }
        if ($status === 202) {
            return self::finalize_bright_data_result(self::fetch_bright_data_snapshot($api_key, $response_body));
        }
        return self::bright_data_search_error($status, $response_body);
    }
    private static function finalize_bright_data_result(array $result): array {
        if (empty($result['ok']) || !isset($result['records']) || !is_array($result['records'])) {
            return $result;
        }
        $records = [];
        foreach ($result['records'] as $record) {
            if (is_array($record) && self::is_review_record($record)) {
                $records[] = $record;
            }
        }
        if ($records === []) {
            return [
                'ok'       => false,
                'records'  => [],
                'message'  => __('Bright Data でレビューを取得できませんでした（商品にレビューが無いか、応答を解析できませんでした）。', 'get-ec-reviews-api'),
                'fallback' => true,
            ];
        }
        return [
            'ok'      => true,
            'records' => $records,
            'message' => '',
        ];
    }
    private static function is_review_record(array $record): bool {
        foreach (['review_text', 'reviewText', 'reviewDescription', 'body', 'content', 'review'] as $key) {
            if (isset($record[$key]) && !is_array($record[$key]) && (string) $record[$key] !== '') {
                return true;
            }
        }
        foreach (['review_header', 'review_title', 'reviewTitle', 'title', 'header'] as $key) {
            if (isset($record[$key]) && !is_array($record[$key]) && (string) $record[$key] !== '') {
                return true;
            }
        }
        foreach (['author_name', 'authorName', 'author', 'reviewer', 'reviewer_name', 'reviewerName'] as $key) {
            if (isset($record[$key]) && !is_array($record[$key]) && (string) $record[$key] !== '') {
                return true;
            }
        }
        foreach (['review_id', 'reviewId'] as $key) {
            if (isset($record[$key]) && !is_array($record[$key]) && (string) $record[$key] !== '') {
                return true;
            }
        }
        foreach (['rating', 'ratingScore', 'stars'] as $key) {
            if (isset($record[$key]) && !is_array($record[$key]) && is_numeric($record[$key]) && (float) $record[$key] > 0.0) {
                return true;
            }
        }
        return false;
    }
    private static function reviews_scraperapi(string $api_key, string $asin, array $args): array {
        $max_reviews = max(1, (int) ($args['max_reviews'] ?? self::REVIEWS_DEFAULT_MAX));
        $reviews_url = self::SCRAPERAPI_AMAZON_DOMAIN . '/product-reviews/' . rawurlencode($asin);
        $fetched = self::scraperapi_fetch_html($api_key, $reviews_url);
        if (!$fetched['ok']) {
            $product_url = self::SCRAPERAPI_AMAZON_DOMAIN . '/dp/' . rawurlencode($asin);
            $product     = self::scraperapi_fetch_html($api_key, $product_url);
            if ($product['ok']) {
                self::$last_raw = (string) $product['html'];
                return [
                    'ok'      => true,
                    'records' => self::parse_amazon_reviews_html($product['html'], $asin, $product_url, $max_reviews),
                    'message' => '',
                ];
            }
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => $fetched['message'],
                'quota_exhausted' => (bool) ($fetched['quota_exhausted'] ?? false),
                'fallback'        => (bool) ($fetched['fallback'] ?? false),
            ];
        }
        $records = self::parse_amazon_reviews_html($fetched['html'], $asin, $reviews_url, $max_reviews);
        self::$last_raw = (string) $fetched['html'];
        if ($records === []) {
            $product_url = self::SCRAPERAPI_AMAZON_DOMAIN . '/dp/' . rawurlencode($asin);
            $product     = self::scraperapi_fetch_html($api_key, $product_url);
            if ($product['ok']) {
                $records = self::parse_amazon_reviews_html($product['html'], $asin, $product_url, $max_reviews);
                self::$last_raw = (string) $product['html'];
            }
        }
        return [
            'ok'      => true,
            'records' => $records,
            'message' => '',
        ];
    }
    private static function scraperapi_fetch_html(string $api_key, string $target_url, bool $js_render = false): array {
        $params = [
            'api_key'      => $api_key,
            'url'          => $target_url,
            'country_code' => self::SCRAPERAPI_COUNTRY_CODE,
            'premium'      => 'true',
        ];
        if ($js_render) {
            $params['render'] = 'true';
        }
        $endpoint = self::SCRAPERAPI_HTML_ENDPOINT . '?' . http_build_query($params);
        $response = wp_remote_get($endpoint, ['timeout' => self::SEARCH_TIMEOUT]);
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'html' => '', 'message' => $error, 'quota_exhausted' => false, 'fallback' => self::$last_transport_fallback];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        if ($status === 200) {
            return ['ok' => true, 'html' => $body, 'message' => '', 'quota_exhausted' => false, 'fallback' => false];
        }
        return array_merge(
            ['ok' => false, 'html' => '', 'message' => \Tonkatsutei\Gachi_Review_AI\Api_Error::append(self::scraperapi_html_error($status, $body), $body)],
            self::failure_flags($status)
        );
    }
    private static function scraperapi_html_error(int $status, string $body): string {
        if ($status === 401 || $status === 403) {
            return sprintf(
                __('API キーが無効です（HTTP %d）。', 'get-ec-reviews-api'),
                $status
            );
        }
        if ($status === 429) {
            return __('クレジットまたはレートの上限に達しました（HTTP 429）。時間をおいて再度お試しください。', 'get-ec-reviews-api');
        }
        if ($status === 404) {
            return __('対象ページを取得できませんでした（HTTP 404）。', 'get-ec-reviews-api');
        }
        return self::unexpected_response($status, $body)['message'];
    }
    private static function parse_amazon_reviews_html(string $html, string $asin, string $url, int $max_reviews): array {
        $xpath = self::html_xpath($html);
        if ($xpath === null) {
            return [];
        }
        $nodes = $xpath->query('//div[@data-hook="review"]');
        if ($nodes === false || $nodes->length === 0) {
            $nodes = $xpath->query('//li[@data-hook="review"]');
        }
        if ($nodes === false || $nodes->length === 0) {
            $nodes = $xpath->query('//div[starts-with(@id,"customer_review-")]');
        }
        if ($nodes === false) {
            return [];
        }
        $records = [];
        foreach ($nodes as $node) {
            if (count($records) >= $max_reviews) {
                break;
            }
            $rating_text = self::xpath_text($xpath, './/i[@data-hook="review-star-rating"]//span[contains(@class,"a-icon-alt")]', $node);
            if ($rating_text === '') {
                $rating_text = self::xpath_text($xpath, './/i[contains(@class,"a-icon-star")]//span[contains(@class,"a-icon-alt")]', $node);
            }
            $author = self::xpath_text($xpath, './/span[contains(@class,"a-profile-name")]', $node);
            if ($author === '') {
                $author = self::xpath_text($xpath, './/span[@data-hook="review-author"]', $node);
            }
            $review_url = self::xpath_attr($xpath, './/a[contains(@href,"/gp/customer-reviews/")]', 'href', $node);
            if ($review_url === '') {
                $review_url = $url;
            }
            $review_text = self::xpath_text($xpath, './/*[@data-hook="review-body"]', $node);
            if ($review_text === '') {
                $review_text = self::xpath_text($xpath, './/*[contains(@class,"review-text-content")]', $node);
            }
            if ($review_text === '') {
                $review_text = self::xpath_text($xpath, './/*[contains(@class,"review-text")]', $node);
            }
            if ($review_text === '') {
                $review_text = self::xpath_text($xpath, './/*[contains(@class,"reviewText")]', $node);
            }
            $review_header = self::xpath_text($xpath, './/*[@data-hook="review-title"]', $node);
            if ($review_header === '') {
                $review_header = self::xpath_text($xpath, './/*[contains(@class,"review-title")]', $node);
            }
            $review_text   = self::clean_review_text($review_text);
            $review_header = self::clean_review_text($review_header);
            $records[] = [
                'review_text'        => $review_text,
                'review_header'      => $review_header,
                'rating'             => self::parse_star_rating($rating_text),
                'author_name'        => $author,
                'review_posted_date' => self::xpath_text($xpath, './/span[@data-hook="review-date"]', $node),
                'helpful_count'      => (int) self::numeric_text(self::xpath_text($xpath, './/span[@data-hook="helpful-vote-statement"]', $node)),
                'is_verified'        => self::xpath_exists($xpath, './/span[@data-hook="avp-badge"]', $node),
                'asin'               => $asin,
                'url'                => $review_url,
            ];
        }
        return $records;
    }
    private static function clean_review_text(string $text): string {
        $text = str_replace(
            [
                'Brief content visible, double tap to read full content.',
                'Full content visible, double tap to read brief content.',
                'Brief content visible, double tap to read full content',
                'Full content visible, double tap to read brief content',
            ],
            '',
            $text
        );
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        $toggles  = ['続きを読む', '表示を減らす', 'Read more', 'Show less', 'See more', 'See less'];
        $previous = null;
        while ($previous !== $text) {
            $previous = $text;
            foreach ($toggles as $toggle) {
                $quoted = preg_quote($toggle, '/');
                $text   = (string) preg_replace('/^' . $quoted . '\s*/u', '', $text, 1);
                $text   = (string) preg_replace('/\s*' . $quoted . '$/u', '', $text, 1);
            }
        }
        return trim($text);
    }
    private static function reviews_zenrows(string $api_key, string $asin, array $args): array {
        $max_reviews = max(1, (int) ($args['max_reviews'] ?? self::REVIEWS_DEFAULT_MAX));
        $product_url = self::ZENROWS_AMAZON_DOMAIN . '/dp/' . rawurlencode($asin);
        $fetched = self::zenrows_fetch_html($api_key, $product_url);
        if (!$fetched['ok']) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => $fetched['message'],
                'quota_exhausted' => (bool) ($fetched['quota_exhausted'] ?? false),
                'fallback'        => (bool) ($fetched['fallback'] ?? false),
            ];
        }
        self::$last_raw = (string) $fetched['html'];
        return [
            'ok'      => true,
            'records' => self::parse_amazon_reviews_html($fetched['html'], $asin, $product_url, $max_reviews),
            'message' => '',
        ];
    }
    private static function zenrows_fetch_html(string $api_key, string $target_url, bool $js_render = false): array {
        $params = [
            'apikey'         => $api_key,
            'url'            => $target_url,
            'premium_proxy'  => 'true',
            'proxy_country'  => self::ZENROWS_PROXY_COUNTRY,
            'custom_headers' => 'true',
        ];
        if ($js_render) {
            $params['js_render'] = 'true';
            $params['wait'] = '3000';
        }
        $endpoint = self::ZENROWS_ENDPOINT . '?' . http_build_query($params);
        $response = wp_remote_get($endpoint, [
            'timeout' => self::SEARCH_TIMEOUT,
            'headers' => [
                'Accept-Language' => 'ja-JP,ja;q=0.9',
            ],
        ]);
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'html' => '', 'message' => $error, 'quota_exhausted' => false, 'fallback' => self::$last_transport_fallback];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        if ($status === 200) {
            return ['ok' => true, 'html' => $body, 'message' => '', 'quota_exhausted' => false, 'fallback' => false];
        }
        return array_merge(
            ['ok' => false, 'html' => '', 'message' => \Tonkatsutei\Gachi_Review_AI\Api_Error::append(self::zenrows_html_error($status, $body), $body)],
            self::zenrows_failure_flags($status, $body)
        );
    }
    private static function zenrows_html_error(int $status, string $body): string {
        if ($status === 401 || $status === 403) {
            return sprintf(
                __('API キーが無効です（HTTP %d）。', 'get-ec-reviews-api'),
                $status
            );
        }
        if ($status === 429) {
            return __('クレジットまたはレートの上限に達しました（HTTP 429）。時間をおいて再度お試しください。', 'get-ec-reviews-api');
        }
        if ($status === 404) {
            return __('対象ページを取得できませんでした（HTTP 404）。', 'get-ec-reviews-api');
        }
        if ($status === 400 && str_contains($body, 'REQS001')) {
            return __('この URL は ZenRows では取得できません（アクセス制限）。別のサービスで取得してください。', 'get-ec-reviews-api');
        }
        if (str_contains($body, 'RESP001')) {
            return __('このページの内容を取得できませんでした（RESP001）。別のサービスで取得してください。', 'get-ec-reviews-api');
        }
        return self::unexpected_response($status, $body)['message'];
    }
    private static function zenrows_failure_flags(int $status, string $body): array {
        if ($status === 429) {
            return ['quota_exhausted' => true, 'fallback' => false];
        }
        if ($status === 400 || $status === 422) {
            if (preg_match('/REQS001|REQS002|RESP001/', $body) === 1) {
                return ['quota_exhausted' => false, 'fallback' => true];
            }
            return ['quota_exhausted' => false, 'fallback' => false];
        }
        if ($status >= 500 || in_array($status, [401, 403, 404, 408], true)) {
            return ['quota_exhausted' => false, 'fallback' => true];
        }
        return ['quota_exhausted' => false, 'fallback' => false];
    }
    private static function test_webscrapingapi(string $api_key): array {
        $endpoint = add_query_arg(['api_key' => $api_key], self::WEBSCRAPINGAPI_ENDPOINT);
        $response = wp_remote_get($endpoint, ['timeout' => self::TIMEOUT]);
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'message' => $error];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        if ($status === 401 || $status === 403) {
            return [
                'ok'      => false,
                'message' => sprintf(
                    __('API キーが無効です（HTTP %d）。', 'get-ec-reviews-api'),
                    $status
                ),
            ];
        }
        if ($status === 400 || ($status >= 200 && $status < 300)) {
            return ['ok' => true, 'message' => __('API キーは有効です（無料枠は消費していません）。', 'get-ec-reviews-api')];
        }
        return self::unexpected_response($status, $body);
    }
    private static function reviews_webscrapingapi(string $api_key, string $asin, array $args): array {
        $max_reviews = max(1, (int) ($args['max_reviews'] ?? self::REVIEWS_DEFAULT_MAX));
        $reviews_url = self::WEBSCRAPINGAPI_AMAZON_DOMAIN . '/product-reviews/' . rawurlencode($asin);
        $fetched = self::webscrapingapi_fetch_html($api_key, $reviews_url);
        if (!$fetched['ok']) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => $fetched['message'],
                'quota_exhausted' => (bool) ($fetched['quota_exhausted'] ?? false),
                'fallback'        => (bool) ($fetched['fallback'] ?? false),
            ];
        }
        $records = self::parse_amazon_reviews_html($fetched['html'], $asin, $reviews_url, $max_reviews);
        self::$last_raw = (string) $fetched['html'];
        $product_html = '';
        if ($records === []) {
            $product_url = self::WEBSCRAPINGAPI_AMAZON_DOMAIN . '/dp/' . rawurlencode($asin);
            $product     = self::webscrapingapi_fetch_html($api_key, $product_url);
            if ($product['ok']) {
                $product_html = $product['html'];
                $records      = self::parse_amazon_reviews_html($product_html, $asin, $product_url, $max_reviews);
                self::$last_raw = (string) $product_html;
            }
        }
        if (self::reviews_incomplete($records)) {
            $diagnostic  = self::reviews_diagnostic($fetched['html'], 'review_page');
            $diagnostic .= ' || ' . ($product_html !== ''
                ? self::reviews_diagnostic($product_html, 'product_page')
                : 'product_page: (not fetched)');
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => __('【診断】レビューの本文・タイトルを抽出できませんでした。お手数ですが以下の全文をご報告ください。 ', 'get-ec-reviews-api') . $diagnostic,
                'quota_exhausted' => false,
                'fallback'        => false,
            ];
        }
        return [
            'ok'      => true,
            'records' => $records,
            'message' => '',
        ];
    }
    private static function reviews_contextdev(string $api_key, string $asin, array $args): array {
        $max_reviews = max(1, (int) ($args['max_reviews'] ?? self::REVIEWS_DEFAULT_MAX));
        $reviews_url = self::CONTEXTDEV_AMAZON_DOMAIN . '/product-reviews/' . rawurlencode($asin);
        $fetched = self::contextdev_fetch_html($api_key, $reviews_url);
        if (!$fetched['ok']) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => $fetched['message'],
                'quota_exhausted' => (bool) ($fetched['quota_exhausted'] ?? false),
                'fallback'        => (bool) ($fetched['fallback'] ?? false),
            ];
        }
        $records = self::parse_amazon_reviews_html($fetched['html'], $asin, $reviews_url, $max_reviews);
        self::$last_raw = (string) $fetched['html'];
        if ($records === []) {
            $product_url = self::CONTEXTDEV_AMAZON_DOMAIN . '/dp/' . rawurlencode($asin);
            $product     = self::contextdev_fetch_html($api_key, $product_url);
            if ($product['ok']) {
                $records = self::parse_amazon_reviews_html($product['html'], $asin, $product_url, $max_reviews);
                self::$last_raw = (string) $product['html'];
            }
        }
        return [
            'ok'      => true,
            'records' => $records,
            'message' => '',
        ];
    }
    private static function reviews_incomplete(array $records): bool {
        if ($records === []) {
            return true;
        }
        foreach ($records as $record) {
            if ((string) ($record['review_text'] ?? '') !== '' || (string) ($record['review_header'] ?? '') !== '') {
                return false;
            }
        }
        return true;
    }
    private static function reviews_diagnostic(string $html, string $label): string {
        $count = 0;
        $first = '';
        $hooks = '';
        $title = '';
        $xpath = self::html_xpath($html);
        if ($xpath !== null) {
            $title = self::xpath_text($xpath, '//title');
            $nodes = $xpath->query('//div[@data-hook="review"]');
            if ($nodes !== false) {
                $count = $nodes->length;
                if ($count > 0 && $nodes->item(0) instanceof \DOMNode) {
                    $node = $nodes->item(0);
                    $gw = $xpath->query('.//*[@data-hook="genome-widget"]', $node);
                    if ($gw !== false) {
                        $remove = [];
                        foreach ($gw as $g) {
                            $remove[] = $g;
                        }
                        foreach ($remove as $g) {
                            if ($g->parentNode instanceof \DOMNode) {
                                $g->parentNode->removeChild($g);
                            }
                        }
                    }
                    $hook_nodes = $xpath->query('.//*[@data-hook]', $node);
                    $hook_list  = [];
                    if ($hook_nodes !== false) {
                        foreach ($hook_nodes as $h) {
                            if ($h instanceof \DOMElement) {
                                $hook_list[] = $h->getAttribute('data-hook');
                            }
                        }
                    }
                    $hooks = implode(',', array_unique($hook_list));
                    $first = (string) $xpath->document->saveHTML($node);
                }
            }
        }
        $first = trim((string) preg_replace('/\s+/', ' ', $first));
        $first = function_exists('mb_substr') ? mb_substr($first, 0, 3500) : substr($first, 0, 3500);
        return sprintf(
            '%s: size=%d review_div=%d list=%d captcha=%s signin=%s title="%s" hooks=[%s] first=%s',
            $label,
            strlen($html),
            $count,
            strpos($html, 'cm_cr-review_list') !== false ? 1 : 0,
            (stripos($html, 'captcha') !== false || stripos($html, 'Enter the characters') !== false) ? 'yes' : 'no',
            (stripos($html, 'ap_signin') !== false || stripos($html, 'Sign in') !== false) ? 'yes' : 'no',
            $title,
            $hooks,
            $first
        );
    }
    private static function webscrapingapi_fetch_html(string $api_key, string $target_url): array {
        $endpoint = self::WEBSCRAPINGAPI_ENDPOINT . '?' . http_build_query(
            [
                'api_key'   => $api_key,
                'url'       => $target_url,
                'render_js' => 1,
                'country'   => self::WEBSCRAPINGAPI_COUNTRY,
            ]
        );
        $response = wp_remote_get($endpoint, [
            'timeout' => self::SEARCH_TIMEOUT,
            'headers' => [
                'Accept-Language' => 'ja-JP,ja;q=0.9',
            ],
        ]);
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'html' => '', 'message' => $error, 'quota_exhausted' => false, 'fallback' => self::$last_transport_fallback];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        if ($status === 200) {
            return ['ok' => true, 'html' => $body, 'message' => '', 'quota_exhausted' => false, 'fallback' => false];
        }
        return array_merge(
            ['ok' => false, 'html' => '', 'message' => \Tonkatsutei\Gachi_Review_AI\Api_Error::append(self::webscrapingapi_html_error($status, $body), $body)],
            self::failure_flags($status)
        );
    }
    private static function webscrapingapi_html_error(int $status, string $body): string {
        if ($status === 401 || $status === 403) {
            return sprintf(
                __('リクエストが拒否されました（HTTP %1$d %2$s）。API キーが無効か、このプラン・対象では許可されていない可能性があります。', 'get-ec-reviews-api'),
                $status,
                self::excerpt($body)
            );
        }
        if ($status === 429) {
            return __('クレジットまたはレートの上限に達しました（HTTP 429）。時間をおいて再度お試しください。', 'get-ec-reviews-api');
        }
        if ($status === 404) {
            return __('対象ページを取得できませんでした（HTTP 404）。', 'get-ec-reviews-api');
        }
        return self::unexpected_response($status, $body)['message'];
    }
    private static function test_contextdev(string $api_key): array {
        $response = wp_remote_post(self::CONTEXTDEV_ENDPOINT, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => (string) wp_json_encode(['formats' => ['html' => true]]),
        ]);
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'message' => $error];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        if ($status === 401 || $status === 403) {
            return [
                'ok'      => false,
                'message' => sprintf(
                    __('API キーが無効です（HTTP %d）。', 'get-ec-reviews-api'),
                    $status
                ),
            ];
        }
        if ($status === 400 || ($status >= 200 && $status < 300)) {
            return ['ok' => true, 'message' => __('API キーは有効です（無料枠は消費していません）。', 'get-ec-reviews-api')];
        }
        return self::unexpected_response($status, $body);
    }
    private static function contextdev_fetch_html(string $api_key, string $target_url): array {
        $request_body = [
            'url'          => $target_url,
            'formats'      => ['html' => true],
            'sharedParams' => [
                'country' => self::CONTEXTDEV_COUNTRY,
                'headers' => [
                    'Accept-Language' => 'ja-JP,ja;q=0.9',
                ],
            ],
            'maxAgeMs'     => 0,
            'timeoutOpts'  => ['milliseconds' => 75000, 'behavior' => 'fail'],
        ];
        $response = wp_remote_post(self::CONTEXTDEV_ENDPOINT, [
            'timeout' => self::SEARCH_TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => (string) wp_json_encode($request_body),
        ]);
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'html' => '', 'message' => $error, 'quota_exhausted' => false, 'fallback' => self::$last_transport_fallback];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        if ($status !== 200) {
            return array_merge(
                ['ok' => false, 'html' => '', 'message' => \Tonkatsutei\Gachi_Review_AI\Api_Error::append(self::contextdev_error($status, $body), $body)],
                self::failure_flags($status)
            );
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'html' => '', 'message' => __('応答を JSON として解釈できませんでした。', 'get-ec-reviews-api'), 'quota_exhausted' => false, 'fallback' => false];
        }
        $html_output = (isset($decoded['html']) && is_array($decoded['html'])) ? $decoded['html'] : [];
        if (empty($html_output['success'])) {
            return [
                'ok'              => false,
                'html'            => '',
                'message'         => sprintf(
                    __('HTML の取得に失敗しました（%1$s %2$s）。', 'get-ec-reviews-api'),
                    isset($html_output['error_code']) ? (string) $html_output['error_code'] : '',
                    isset($html_output['message']) ? (string) $html_output['message'] : ''
                ),
                'quota_exhausted' => false,
                'fallback'        => false,
            ];
        }
        $html = (isset($html_output['data']) && is_string($html_output['data'])) ? $html_output['data'] : '';
        return ['ok' => true, 'html' => $html, 'message' => '', 'quota_exhausted' => false, 'fallback' => false];
    }
    private static function contextdev_error(int $status, string $body): string {
        if ($status === 401 || $status === 403) {
            return sprintf(
                __('リクエストが拒否されました（HTTP %1$d %2$s）。API キーが無効か、このプラン・対象では許可されていない可能性があります。', 'get-ec-reviews-api'),
                $status,
                self::excerpt($body)
            );
        }
        if ($status === 402) {
            return __('クレジットが不足しています（HTTP 402）。プランと残クレジットをご確認ください。', 'get-ec-reviews-api');
        }
        if ($status === 429) {
            return __('クレジットまたはレートの上限に達しました（HTTP 429）。時間をおいて再度お試しください。', 'get-ec-reviews-api');
        }
        if ($status === 404) {
            return __('対象ページを取得できませんでした（HTTP 404）。', 'get-ec-reviews-api');
        }
        return self::unexpected_response($status, $body)['message'];
    }
    private static function html_xpath(string $html): ?\DOMXPath {
        if ($html === '' || !class_exists('DOMDocument')) {
            return null;
        }
        $dom      = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new \DOMXPath($dom);
    }
    private static function xpath_text(?\DOMXPath $xpath, string $query, ?\DOMNode $context = null): string {
        if ($xpath === null) {
            return '';
        }
        $nodes = $context === null ? $xpath->query($query) : $xpath->query($query, $context);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        return trim(preg_replace('/\s+/u', ' ', $nodes->item(0)->textContent) ?? '');
    }
    private static function xpath_attr(?\DOMXPath $xpath, string $query, string $attribute, ?\DOMNode $context = null): string {
        if ($xpath === null) {
            return '';
        }
        $nodes = $context === null ? $xpath->query($query) : $xpath->query($query, $context);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $node = $nodes->item(0);
        if (!$node instanceof \DOMElement) {
            return '';
        }
        return trim($node->getAttribute($attribute));
    }
    private static function xpath_exists(?\DOMXPath $xpath, string $query, ?\DOMNode $context = null): bool {
        if ($xpath === null) {
            return false;
        }
        $nodes = $context === null ? $xpath->query($query) : $xpath->query($query, $context);
        return $nodes !== false && $nodes->length > 0;
    }
    private static function parse_star_rating(string $text): float {
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*out of/i', $text, $matches) === 1) {
            return (float) $matches[1];
        }
        if (preg_match('/つ星のうち\s*([0-9]+(?:\.[0-9]+)?)/u', $text, $matches) === 1) {
            return (float) $matches[1];
        }
        return self::numeric_text($text);
    }
    private static function numeric_text(string $text): float {
        $normalized = str_replace([',', '，', ' '], '', $text);
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', $normalized, $matches) === 1) {
            return (float) $matches[1];
        }
        return 0.0;
    }
    private static function reviews_apify(string $api_key, string $asin, array $args): array {
        $input = [
            'productUrls'        => [['url' => self::APIFY_AMAZON_DOMAIN . '/dp/' . rawurlencode($asin)]],
            'maxReviews'         => max(1, (int) ($args['max_reviews'] ?? self::REVIEWS_DEFAULT_MAX)),
            'proxyConfiguration' => ['useApifyProxy' => true],
        ];
        return self::run_apify_actor($api_key, self::APIFY_AMAZON_REVIEWS_ACTOR, $input);
    }
    private static function run_apify_actor(string $api_key, string $actor, array $input): array {
        $run_response = wp_remote_post(
            self::APIFY_ACTOR_RUNS_ENDPOINT . $actor . '/runs',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => (string) wp_json_encode($input),
                'timeout' => self::TIMEOUT,
            ]
        );
        $error = self::transport_error($run_response);
        if ($error !== null) {
            return ['ok' => false, 'records' => [], 'message' => $error];
        }
        $run_status = (int) wp_remote_retrieve_response_code($run_response);
        $run_body   = (string) wp_remote_retrieve_body($run_response);
        if ($run_status < 200 || $run_status >= 300) {
            return self::apify_error($run_status, $run_body);
        }
        $run        = json_decode($run_body, true);
        $run_id     = (is_array($run) && isset($run['data']['id'])) ? (string) $run['data']['id'] : '';
        $dataset_id = (is_array($run) && isset($run['data']['defaultDatasetId'])) ? (string) $run['data']['defaultDatasetId'] : '';
        if ($run_id === '') {
            return ['ok' => false, 'records' => [], 'message' => __('Apify の実行 ID を取得できませんでした。', 'get-ec-reviews-api')];
        }
        $headers   = ['Authorization' => 'Bearer ' . $api_key];
        $succeeded = false;
        for ($attempt = 0; $attempt < self::APIFY_POLL_MAX_ATTEMPTS; $attempt++) {
            if ($attempt > 0) {
                sleep(self::APIFY_POLL_INTERVAL);
            }
            $status_response = wp_remote_get(
                self::APIFY_ACTOR_RUN_ENDPOINT . rawurlencode($run_id),
                ['headers' => $headers, 'timeout' => self::TIMEOUT]
            );
            $error = self::transport_error($status_response);
            if ($error !== null) {
                return ['ok' => false, 'records' => [], 'message' => $error];
            }
            $status = (int) wp_remote_retrieve_response_code($status_response);
            if ($status < 200 || $status >= 300) {
                return self::apify_error($status, (string) wp_remote_retrieve_body($status_response));
            }
            $data           = json_decode((string) wp_remote_retrieve_body($status_response), true);
            $run_state      = (is_array($data) && isset($data['data']['status'])) ? (string) $data['data']['status'] : '';
            $status_message = (is_array($data) && isset($data['data']['statusMessage'])) ? (string) $data['data']['statusMessage'] : '';
            $dataset_id     = (is_array($data) && isset($data['data']['defaultDatasetId'])) ? (string) $data['data']['defaultDatasetId'] : $dataset_id;
            if ($run_state === 'SUCCEEDED') {
                $succeeded = true;
                break;
            }
            if (in_array($run_state, ['FAILED', 'ABORTED', 'TIMED-OUT'], true)) {
                error_log(sprintf(
                    '[Get EC Reviews API] Apify run %s: %s%s',
                    $run_id,
                    $run_state,
                    $status_message !== '' ? ' / ' . $status_message : ''
                ));
                self::log_apify_run_tail($api_key, $run_id);
                return [
                    'ok'      => false,
                    'records' => [],
                    'message' => self::apify_failure_message($run_state, $status_message),
                ];
            }
        }
        if (!$succeeded) {
            return ['ok' => false, 'records' => [], 'message' => __('Apify の実行が完了しませんでした（実行中です）。時間をおいて再度お試しください。', 'get-ec-reviews-api')];
        }
        if ($dataset_id === '') {
            return ['ok' => false, 'records' => [], 'message' => __('Apify のデータセット ID を取得できませんでした。', 'get-ec-reviews-api')];
        }
        $items_response = wp_remote_get(
            add_query_arg(['format' => 'json'], self::APIFY_DATASETS_ENDPOINT . rawurlencode($dataset_id) . '/items'),
            ['headers' => $headers, 'timeout' => self::TIMEOUT]
        );
        $error = self::transport_error($items_response);
        if ($error !== null) {
            return ['ok' => false, 'records' => [], 'message' => $error];
        }
        $items_status = (int) wp_remote_retrieve_response_code($items_response);
        if ($items_status < 200 || $items_status >= 300) {
            return self::apify_error($items_status, (string) wp_remote_retrieve_body($items_response));
        }
        $records = json_decode((string) wp_remote_retrieve_body($items_response), true);
        if (!is_array($records)) {
            return ['ok' => false, 'records' => [], 'message' => __('Apify の応答を解析できませんでした。', 'get-ec-reviews-api')];
        }
        return ['ok' => true, 'records' => $records, 'message' => ''];
    }
    private static function apify_error(int $status, string $body): array {
        $snippet = trim((string) preg_replace('/\s+/', ' ', $body));
        if (strlen($snippet) > 300) {
            $snippet = substr($snippet, 0, 300) . '…';
        }
        if ($snippet !== '') {
            error_log(sprintf('[Get EC Reviews API] Apify API error HTTP %d: %s', $status, $snippet));
        }
        $decoded      = json_decode($body, true);
        $error_type   = (is_array($decoded) && isset($decoded['error']['type'])) ? (string) $decoded['error']['type'] : '';
        $approval_url = (is_array($decoded) && isset($decoded['error']['data']['approvalUrl'])) ? (string) $decoded['error']['data']['approvalUrl'] : '';
        if ($status === 403 && ($error_type === 'full-permission-actor-not-approved' || $approval_url !== '')) {
            $approval_message = $approval_url !== ''
                ? sprintf(
                    __('この Apify Actor は初回に「権限の承認」が必要です。次の URL をブラウザで開き【Approve（承認する）】ボタンを押して、この Actor にアカウントへのフルアクセス権限を許可してから再実行してください: %s', 'get-ec-reviews-api'),
                    $approval_url
                )
                : __('この Apify Actor は初回に「権限の承認」が必要です。Apify コンソールで【Approve（承認する）】を押して、アカウントへのフルアクセス権限を許可してから再実行してください。', 'get-ec-reviews-api');
            return array_merge(['ok' => false, 'records' => [], 'message' => $approval_message], self::failure_flags($status));
        }
        $message = match ($status) {
            401, 403 => $snippet !== ''
                ? sprintf(
                    __('Apify の認証・権限エラーです（HTTP %1$d）。API 側の応答: %2$s', 'get-ec-reviews-api'),
                    $status,
                    $snippet
                )
                : __('Apify の認証・権限エラーです（HTTP 401/403）。API キーの権限をご確認ください。', 'get-ec-reviews-api'),
            404      => __('Apify の Actor が見つかりません（HTTP 404）。', 'get-ec-reviews-api'),
            429      => __('リクエストが多すぎます（HTTP 429）。時間をおいて再度お試しください。', 'get-ec-reviews-api'),
            default  => self::unexpected_response($status, $body)['message'],
        };
        return array_merge(['ok' => false, 'records' => [], 'message' => \Tonkatsutei\Gachi_Review_AI\Api_Error::append($message, $body)], self::failure_flags($status));
    }
    private static function apify_failure_message(string $run_state, string $status_message): string {
        if ($status_message === '') {
            return sprintf(
                __('Apify の実行が失敗しました（%s）。', 'get-ec-reviews-api'),
                $run_state
            );
        }
        return sprintf(
            __('Apify の実行が失敗しました（%1$s: %2$s）。', 'get-ec-reviews-api'),
            $run_state,
            $status_message
        );
    }
    private static function log_apify_run_tail(string $api_key, string $run_id): void {
        $response = wp_remote_get(
            self::APIFY_ACTOR_RUN_ENDPOINT . rawurlencode($run_id) . '/log',
            [
                'headers' => ['Authorization' => 'Bearer ' . $api_key],
                'timeout' => self::TIMEOUT,
            ]
        );
        if (is_wp_error($response)) {
            return;
        }
        $body = trim((string) wp_remote_retrieve_body($response));
        if ($body === '') {
            return;
        }
        $lines = preg_split('/\r\n|\r|\n/', $body);
        $tail  = implode(' | ', array_slice($lines === false ? [] : $lines, -5));
        error_log('[Get EC Reviews API] Apify run log tail: ' . $tail);
    }
    private static function apify_fetch_html(string $api_key, string $target_url): array {
        $input = [
            'startUrls'          => [['url' => $target_url]],
            'pageFunction'       => self::APIFY_WEB_SCRAPER_PAGE_FUNCTION,
            'proxyConfiguration' => ['useApifyProxy' => true],
        ];
        $result = self::run_apify_actor($api_key, self::APIFY_WEB_SCRAPER_ACTOR, $input);
        if (empty($result['ok'])) {
            return [
                'ok'              => false,
                'html'            => '',
                'message'         => (string) ($result['message'] ?? ''),
                'quota_exhausted' => (bool) ($result['quota_exhausted'] ?? false),
                'fallback'        => (bool) ($result['fallback'] ?? false) || self::$last_transport_fallback,
            ];
        }
        $html = '';
        foreach ((array) ($result['records'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $candidate = $item['html'] ?? ($item['pageFunctionResult']['html'] ?? '');
            if (is_string($candidate) && $candidate !== '') {
                $html = $candidate;
                break;
            }
        }
        if ($html === '') {
            self::$last_raw = (string) wp_json_encode($result['records'] ?? []);
            return ['ok' => false, 'html' => '', 'message' => __('Apify の実行結果から HTML を取得できませんでした。', 'get-ec-reviews-api'), 'quota_exhausted' => false, 'fallback' => false];
        }
        return ['ok' => true, 'html' => $html, 'message' => '', 'quota_exhausted' => false, 'fallback' => false];
    }
    private static function fetch_bright_data_snapshot(string $api_key, string $response_body): array {
        $decoded     = json_decode($response_body, true);
        $snapshot_id = (is_array($decoded) && isset($decoded['snapshot_id'])) ? (string) $decoded['snapshot_id'] : '';
        if ($snapshot_id === '') {
            return ['ok' => false, 'records' => [], 'message' => __('非同期ジョブの snapshot_id を取得できませんでした。', 'get-ec-reviews-api')];
        }
        $headers = ['Authorization' => 'Bearer ' . $api_key];
        for ($attempt = 0; $attempt < self::POLL_MAX_ATTEMPTS; $attempt++) {
            if ($attempt > 0) {
                sleep(self::POLL_INTERVAL);
            }
            $progress_response = wp_remote_get(
                self::BRIGHT_DATA_PROGRESS_ENDPOINT . rawurlencode($snapshot_id),
                ['headers' => $headers, 'timeout' => self::TIMEOUT]
            );
            $error = self::transport_error($progress_response);
            if ($error !== null) {
                return ['ok' => false, 'records' => [], 'message' => $error];
            }
            $progress        = json_decode((string) wp_remote_retrieve_body($progress_response), true);
            $progress_status = (is_array($progress) && isset($progress['status'])) ? (string) $progress['status'] : '';
            if ($progress_status === 'failed') {
                return ['ok' => false, 'records' => [], 'message' => __('Bright Data の非同期ジョブが失敗しました。', 'get-ec-reviews-api')];
            }
            if ($progress_status === 'ready' || $progress_status === '') {
                $records = self::download_bright_data_snapshot($api_key, $snapshot_id);
                if ($records['ok']) {
                    return $records;
                }
            }
        }
        return ['ok' => false, 'records' => [], 'message' => __('Bright Data の非同期処理が完了しませんでした。商品によっては収集に数分かかることがあります。時間をおいて再度お試しください。', 'get-ec-reviews-api')];
    }
    private static function download_bright_data_snapshot(string $api_key, string $snapshot_id): array {
        $endpoint = add_query_arg(
            ['format' => 'json'],
            self::BRIGHT_DATA_SNAPSHOTS_ENDPOINT . rawurlencode($snapshot_id)
        );
        $response = wp_remote_get($endpoint, [
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
            'timeout' => self::TIMEOUT,
        ]);
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'records' => [], 'message' => $error];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        if ($status === 200) {
            $records = json_decode($body, true);
            if (is_array($records)) {
                return ['ok' => true, 'records' => $records, 'message' => ''];
            }
        }
        return ['ok' => false, 'records' => [], 'message' => __('snapshot のダウンロードに失敗しました。', 'get-ec-reviews-api')];
    }
    private static function bright_data_search_error(int $status, string $body): array {
        $message = match ($status) {
            400     => __('入力内容が不正です（HTTP 400）。', 'get-ec-reviews-api'),
            401     => __('API キーが無効です（HTTP 401）。', 'get-ec-reviews-api'),
            404     => __('データセットが見つかりません（HTTP 404）。', 'get-ec-reviews-api'),
            429     => __('リクエストが多すぎます（HTTP 429）。時間をおいて再度お試しください。', 'get-ec-reviews-api'),
            default => self::unexpected_response($status, $body)['message'],
        };
        return array_merge(['ok' => false, 'records' => [], 'message' => \Tonkatsutei\Gachi_Review_AI\Api_Error::append($message, $body)], self::failure_flags($status));
    }
    private static function test_bright_data(string $api_key): array {
        $response = wp_remote_post(
            self::BRIGHT_DATA_ENDPOINT . '?format=json',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => '[]',
                'timeout' => self::TIMEOUT,
            ]
        );
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'message' => $error];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        if ($status === 401) {
            return ['ok' => false, 'message' => __('API キーが無効です（HTTP 401 Invalid credentials）。', 'get-ec-reviews-api')];
        }
        if (($status === 404 && str_contains($body, 'Collector not found')) || ($status >= 200 && $status < 300)) {
            return ['ok' => true, 'message' => __('API キーは有効です（無料枠は消費していません）。', 'get-ec-reviews-api')];
        }
        return self::unexpected_response($status, $body);
    }
    private static function brightdata_fetch_html(string $api_key, string $target_url, bool $js_render = false): array {
        $payload = [
            'zone'   => self::brightdata_unlocker_zone($api_key),
            'url'    => $target_url,
            'format' => 'raw',
        ];
        if ($js_render) {
            $payload['render'] = 'true';
        }
        $response = wp_remote_post(self::BRIGHT_DATA_UNLOCKER_ENDPOINT, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => (string) wp_json_encode($payload),
            'timeout' => self::SEARCH_TIMEOUT,
        ]);
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'html' => '', 'message' => $error, 'quota_exhausted' => false, 'fallback' => self::$last_transport_fallback];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        if ($status === 200) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && array_key_exists('body', $decoded)) {
                return ['ok' => true, 'html' => (string) $decoded['body'], 'message' => '', 'quota_exhausted' => false, 'fallback' => false];
            }
            return ['ok' => true, 'html' => $body, 'message' => '', 'quota_exhausted' => false, 'fallback' => false];
        }
        return array_merge(
            ['ok' => false, 'html' => '', 'message' => \Tonkatsutei\Gachi_Review_AI\Api_Error::append(self::brightdata_unlocker_error($status, $body), $body)],
            self::failure_flags($status)
        );
    }
    private static function brightdata_unlocker_zone(string $api_key): string {
        $cache_key = self::BRIGHT_DATA_ZONE_CACHE_PREFIX . md5($api_key);
        $cached    = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $zone     = '';
        $response = wp_remote_get(self::BRIGHT_DATA_ZONES_ENDPOINT, [
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
            'timeout' => self::TIMEOUT,
        ]);
        if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
            $zones = json_decode((string) wp_remote_retrieve_body($response), true);
            if (is_array($zones)) {
                foreach ($zones as $candidate) {
                    if (
                        is_array($candidate)
                        && (string) ($candidate['type'] ?? '') === 'unblocker'
                        && (string) ($candidate['name'] ?? '') !== ''
                    ) {
                        $zone = (string) $candidate['name'];
                        break;
                    }
                }
            }
        }
        if ($zone === '') {
            $zone = self::BRIGHT_DATA_DEFAULT_UNLOCKER_ZONE;
        }
        set_transient($cache_key, $zone, self::BRIGHT_DATA_ZONE_CACHE_TTL);
        return $zone;
    }
    private static function brightdata_unlocker_error(int $status, string $body): string {
        if ($status === 401 || $status === 403) {
            return __('API キーが無効です（Web Unlocker API の認証エラー）。', 'get-ec-reviews-api');
        }
        if ($status === 400) {
            return __('Web Unlocker のリクエストが不正です（HTTP 400。ゾーン名・URL を確認してください）。', 'get-ec-reviews-api');
        }
        return self::unexpected_response($status, $body)['message'];
    }
    private static function test_scraperapi(string $api_key): array {
        $url      = add_query_arg('api_key', $api_key, self::SCRAPERAPI_ACCOUNT_ENDPOINT);
        $response = wp_remote_get($url, ['timeout' => self::TIMEOUT]);
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'message' => $error];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        if ($status === 200) {
            $data    = json_decode($body, true);
            $credits = is_array($data) && isset($data['creditsLeft']) ? (string) $data['creditsLeft'] : '';
            return [
                'ok'      => true,
                'message' => $credits !== ''
                    ? sprintf(
                        __('API キーは有効です（残クレジット: %s／無料枠は消費していません）。', 'get-ec-reviews-api'),
                        $credits
                    )
                    : __('API キーは有効です（無料枠は消費していません）。', 'get-ec-reviews-api'),
            ];
        }
        if ($status === 401 || $status === 403) {
            return [
                'ok'      => false,
                'message' => sprintf(
                    __('API キーが無効です（HTTP %d）。', 'get-ec-reviews-api'),
                    $status
                ),
            ];
        }
        return self::unexpected_response($status, $body);
    }
    private static function test_apify(string $api_key): array {
        $response = wp_remote_get(
            self::APIFY_ME_ENDPOINT,
            [
                'headers' => ['Authorization' => 'Bearer ' . $api_key],
                'timeout' => self::TIMEOUT,
            ]
        );
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'message' => $error];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 200) {
            return ['ok' => true, 'message' => __('API キーは有効です（無料枠は消費していません）。', 'get-ec-reviews-api')];
        }
        if ($status === 401) {
            return ['ok' => false, 'message' => __('API キーが無効です（HTTP 401）。', 'get-ec-reviews-api')];
        }
        return self::unexpected_response($status, (string) wp_remote_retrieve_body($response));
    }
    private static function test_zenrows(string $api_key): array {
        $endpoint = add_query_arg(['apikey' => $api_key], self::ZENROWS_ENDPOINT);
        $response = wp_remote_get($endpoint, ['timeout' => self::TIMEOUT]);
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'message' => $error];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        if ($status === 401 || $status === 403) {
            return [
                'ok'      => false,
                'message' => sprintf(
                    __('API キーが無効です（HTTP %d）。', 'get-ec-reviews-api'),
                    $status
                ),
            ];
        }
        if ($status === 400 || ($status >= 200 && $status < 300)) {
            return ['ok' => true, 'message' => __('API キーは有効です（無料枠は消費していません）。', 'get-ec-reviews-api')];
        }
        return self::unexpected_response($status, $body);
    }
    private static function transport_error($response): ?string {
        if (is_wp_error($response)) {
            self::$last_transport_fallback = self::is_transient_network_error($response);
            return sprintf(
                __('通信に失敗しました: %s', 'get-ec-reviews-api'),
                $response->get_error_message()
            );
        }
        self::$last_transport_fallback = false;
        return null;
    }
    private static function is_transient_network_error(\WP_Error $error): bool {
        $message = (string) $error->get_error_message();
        if (preg_match('/(SSL|certificate|TLS)/i', $message) === 1) {
            return false;
        }
        if (preg_match('/cURL error (35|51|58|60|66|77|80|83|90|91)\b/', $message) === 1) {
            return false;
        }
        return true;
    }
    private static function with_failure_flags(array $result): array {
        if (empty($result['ok']) && self::$last_transport_fallback) {
            $result['fallback'] = true;
        }
        return array_merge(['quota_exhausted' => false, 'fallback' => false], $result);
    }
    private static function failure_flags(int $status): array {
        if ($status === 429) {
            return ['quota_exhausted' => true, 'fallback' => false];
        }
        if ($status >= 500 || in_array($status, [401, 403, 404, 408], true)) {
            return ['quota_exhausted' => false, 'fallback' => true];
        }
        return ['quota_exhausted' => false, 'fallback' => false];
    }
    private static function unexpected_response(int $status, string $body): array {
        return [
            'ok'      => false,
            'message' => sprintf(
                __('想定外の応答です（HTTP %1$d %2$s）。', 'get-ec-reviews-api'),
                $status,
                self::excerpt($body)
            ),
        ];
    }
    private static function excerpt(string $body): string {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');
        if ($body === '') {
            return '';
        }
        return mb_substr($body, 0, 200);
    }
    private static function reviews_html_site(string $service, string $api_key, string $site, string $code, array $args, array $ec_keys = []): array {
        if ($site === 'rakuten') {
            $lookup = self::rakuten_lookup($ec_keys['application_id'] ?? '', $ec_keys['access_key'] ?? '', $code, $ec_keys['site_url'] ?? '');
            if (!$lookup['ok']) {
                return ['ok' => false, 'records' => [], 'message' => $lookup['message']];
            }
            if ($lookup['review_count'] <= 0) {
                return ['ok' => true, 'records' => [], 'message' => ''];
            }
            $shop_id = self::rakuten_resolve_shop_id($service, $api_key, $lookup['shop_code']);
            if ($shop_id === '') {
                return ['ok' => false, 'records' => [], 'message' => __('楽天市場の店舗 ID を解決できませんでした。', 'get-ec-reviews-api')];
            }
            $code = $shop_id . ':' . $lookup['item_id'];
        }
        $yahoo_review_url = '';
        if ($site === 'yahoo') {
            $lookup = self::yahoo_lookup($ec_keys['client_id'] ?? '', $code);
            if (!$lookup['ok']) {
                return ['ok' => false, 'records' => [], 'message' => $lookup['message']];
            }
            if ($lookup['review_count'] <= 0) {
                return ['ok' => true, 'records' => [], 'message' => ''];
            }
            $yahoo_review_url = $lookup['review_url'];
            if ($yahoo_review_url === '') {
                return ['ok' => false, 'records' => [], 'message' => __('Yahoo のレビューURLを取得できませんでした。', 'get-ec-reviews-api')];
            }
        }
        $url = $yahoo_review_url !== '' ? $yahoo_review_url : self::site_reviews_url($site, $code);
        if ($url === '') {
            return ['ok' => false, 'records' => [], 'message' => __('レビューページの URL を組み立てられませんでした。', 'get-ec-reviews-api')];
        }
        $fetched = self::fetch_html_for($service, $api_key, $url, true);
        if (!$fetched['ok']) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => $fetched['message'],
                'quota_exhausted' => (bool) ($fetched['quota_exhausted'] ?? false),
                'fallback'        => (bool) ($fetched['fallback'] ?? false),
            ];
        }
        self::$last_raw = (string) $fetched['html'];
        $max = max(1, (int) ($args['max_reviews'] ?? self::REVIEWS_DEFAULT_MAX));
        return [
            'ok'      => true,
            'records' => self::parse_site_reviews_html($site, $fetched['html'], $url, $max),
            'message' => '',
        ];
    }
    private static function fetch_html_for(string $service, string $api_key, string $url, bool $js_render = false): array {
        return match ($service) {
            'zenrows'        => self::zenrows_fetch_html($api_key, $url, $js_render),
            'bright_data'    => self::brightdata_fetch_html($api_key, $url, $js_render),
            'scraperapi'     => self::scraperapi_fetch_html($api_key, $url, $js_render),
            'apify'          => self::apify_fetch_html($api_key, $url),
            'webscrapingapi' => self::webscrapingapi_fetch_html($api_key, $url),
            'contextdev'     => self::contextdev_fetch_html($api_key, $url),
            default          => ['ok' => false, 'html' => '', 'message' => __('このサービスは HTML 取得に対応していません。', 'get-ec-reviews-api')],
        };
    }
    private static function site_reviews_url(string $site, string $code): string {
        if ($site === 'rakuten') {
            if (!str_contains($code, ':')) {
                return '';
            }
            [$shop, $item] = explode(':', $code, 2);
            if ($shop === '' || $item === '') {
                return '';
            }
            return sprintf('%s/item/1/%s_%s/1.1/', self::RAKUTEN_DOMAIN, rawurlencode($shop), rawurlencode($item));
        }
        if ($site === 'amazon') {
            return self::AMAZON_DOMAIN . '/product-reviews/' . rawurlencode($code);
        }
        return '';
    }
    private static function rakuten_resolve_shop_id(string $service, string $api_key, string $shop_code): string {
        if (preg_match('/^\d+$/', $shop_code) === 1) {
            return $shop_code;
        }
        $cache_key = self::RAKUTEN_SHOPID_CACHE_PREFIX . md5($shop_code);
        $cached    = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $shop_url = 'https://www.rakuten.co.jp/' . rawurlencode($shop_code) . '/';
        $fetched  = self::fetch_html_for($service, $api_key, $shop_url, true);
        if (empty($fetched['ok']) || empty($fetched['html'])) {
            return '';
        }
        if (preg_match('/shopId["\'\s:=]+(\d+)/', (string) $fetched['html'], $m) !== 1) {
            if (preg_match('/shop_bid[=:]+(\d+)/', (string) $fetched['html'], $m) !== 1) {
                return '';
            }
        }
        set_transient($cache_key, $m[1], DAY_IN_SECONDS);
        return $m[1];
    }
    public static function test_ec_api(string $id, array $keys): array {
        if ($id === 'rakuten_api') {
            return self::test_rakuten_api($keys['application_id'] ?? '', $keys['access_key'] ?? '', $keys['site_url'] ?? '');
        }
        if ($id === 'yahoo_api') {
            return self::test_yahoo_api($keys['client_id'] ?? '');
        }
        return ['ok' => false, 'message' => __('未知の EC 公式API です。', 'get-ec-reviews-api')];
    }
    private static function test_rakuten_api(string $application_id, string $access_key, string $site_url = ''): array {
        if (trim($application_id) === '' || trim($access_key) === '') {
            return ['ok' => false, 'message' => __('アプリID とアクセスキーが必要です。', 'get-ec-reviews-api')];
        }
        self::rakuten_throttle();
        $endpoint = add_query_arg([
            'applicationId' => $application_id,
            'format'        => 'json',
            'keyword'       => rawurlencode('test'),
            'hits'          => 1,
            'formatVersion' => 2,
        ], self::RAKUTEN_API_ENDPOINT);
        $response = wp_remote_get($endpoint, [
            'timeout' => self::TIMEOUT,
            'headers' => self::rakuten_api_headers($access_key, $site_url),
        ]);
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'message' => $error];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 200) {
            return ['ok' => true, 'message' => __('接続に成功しました。', 'get-ec-reviews-api')];
        }
        return ['ok' => false, 'message' => self::ec_api_error_message('楽天市場', $status, (string) wp_remote_retrieve_body($response))];
    }
    private static function test_yahoo_api(string $client_id): array {
        if (trim($client_id) === '') {
            return ['ok' => false, 'message' => __('Client ID が必要です。', 'get-ec-reviews-api')];
        }
        $endpoint = add_query_arg([
            'appid'   => $client_id,
            'query'   => rawurlencode('test'),
            'results' => 1,
        ], self::YAHOO_API_ENDPOINT);
        $response = wp_remote_get($endpoint, ['timeout' => self::TIMEOUT]);
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'message' => $error];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 200) {
            return ['ok' => true, 'message' => __('接続に成功しました。', 'get-ec-reviews-api')];
        }
        return ['ok' => false, 'message' => self::ec_api_error_message('Yahoo!ショッピング', $status, (string) wp_remote_retrieve_body($response))];
    }
    private static function rakuten_lookup(string $application_id, string $access_key, string $code, string $site_url = ''): array {
        $fail = ['ok' => false, 'shop_code' => '', 'item_id' => '', 'item_url' => '', 'review_count' => 0, 'message' => ''];
        if (trim($application_id) === '' || trim($access_key) === '') {
            $fail['message'] = __('楽天のアプリID/アクセスキーが設定されていません（設定画面で保存してください）。', 'get-ec-reviews-api');
            return $fail;
        }
        self::rakuten_throttle();
        $query = [
            'applicationId' => $application_id,
            'format'        => 'json',
            'formatVersion' => 2,
        ];
        if (str_contains($code, ':')) {
            $query['itemCode'] = rawurlencode($code);
        } else {
            $query['keyword'] = rawurlencode($code);
            $query['hits']    = 5;
        }
        $response = wp_remote_get(add_query_arg($query, self::RAKUTEN_API_ENDPOINT), [
            'timeout' => self::TIMEOUT,
            'headers' => self::rakuten_api_headers($access_key, $site_url),
        ]);
        $error = self::transport_error($response);
        if ($error !== null) {
            $fail['message'] = $error;
            return $fail;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            $fail['message'] = self::ec_api_error_message('楽天市場', $status, (string) wp_remote_retrieve_body($response));
            return $fail;
        }
        $data  = json_decode((string) wp_remote_retrieve_body($response), true);
        $items = [];
        if (is_array($data)) {
            if (isset($data['Items']) && is_array($data['Items'])) {
                $items = $data['Items'];
            } elseif (isset($data['items']) && is_array($data['items'])) {
                $items = $data['items'];
            }
        }
        $first = (isset($items[0]) && is_array($items[0])) ? $items[0] : [];
        $item = (isset($first['Item']) && is_array($first['Item'])) ? $first['Item'] : ((isset($first['item']) && is_array($first['item'])) ? $first['item'] : $first);
        $item_code = isset($item['itemCode']) ? (string) $item['itemCode'] : '';
        $shop_code = isset($item['shopCode']) ? (string) $item['shopCode'] : '';
        $item_url  = isset($item['itemUrl']) ? (string) $item['itemUrl'] : '';
        if (!str_contains($item_code, ':')) {
            $fail['message'] = __('楽天で商品コードを解決できませんでした（商品が見つかりません）。', 'get-ec-reviews-api');
            return $fail;
        }
        [$shop_from_code, $item_id] = explode(':', $item_code, 2);
        $review_count = isset($item['reviewCount']) ? (int) $item['reviewCount'] : 0;
        return [
            'ok'           => true,
            'shop_code'    => $shop_code !== '' ? $shop_code : $shop_from_code,
            'item_id'      => $item_id,
            'item_url'     => $item_url,
            'review_count' => $review_count,
            'message'      => '',
        ];
    }
    private static function yahoo_lookup(string $client_id, string $code): array {
        $fail = ['ok' => false, 'review_count' => 0, 'review_url' => '', 'message' => ''];
        if (trim($client_id) === '') {
            $fail['message'] = __('Yahoo の Client ID が設定されていません（設定画面で保存してください）。', 'get-ec-reviews-api');
            return $fail;
        }
        $itemcode = trim($code);
        if (str_contains($itemcode, ':')) {
            $itemcode = implode('_', explode(':', $itemcode, 2));
        }
        $response = wp_remote_get(add_query_arg([
            'appid'         => $client_id,
            'itemcode'      => rawurlencode($itemcode),
            'responsegroup' => 'medium',
        ], self::YAHOO_ITEM_ENDPOINT), ['timeout' => self::TIMEOUT]);
        $error = self::transport_error($response);
        if ($error !== null) {
            $fail['message'] = $error;
            return $fail;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            $fail['message'] = self::ec_api_error_message('Yahoo!ショッピング', $status, (string) wp_remote_retrieve_body($response));
            return $fail;
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $set     = (is_array($data) && isset($data['ResultSet']) && is_array($data['ResultSet'])) ? $data['ResultSet'] : [];
        $wrapper = self::yahoo_wrapped($set, '0');
        $results = (isset($wrapper['Result']) && is_array($wrapper['Result'])) ? $wrapper['Result'] : [];
        $item    = self::yahoo_wrapped($results, '0');
        if ($item === []) {
            $item = $results; 
        }
        $review = null;
        if ($item === []) {
            $review = self::yahoo_search_review($client_id, $itemcode);
            if ($review === null) {
                $fail['message'] = __('Yahoo で商品コードを解決できませんでした（商品が見つかりません）。Yahoo の商品コード（商品検索の Code。例: marbleshop_2915-001200）を指定してください。', 'get-ec-reviews-api');
                return $fail;
            }
        } else {
            $review = (isset($item['Review']) && is_array($item['Review'])) ? $item['Review'] : [];
        }
        return [
            'ok'           => true,
            'review_count' => isset($review['Count']) ? (int) $review['Count'] : 0,
            'review_url'   => isset($review['Url']) ? (string) $review['Url'] : '',
            'message'      => '',
        ];
    }
    private static function yahoo_search_review(string $client_id, string $itemcode): ?array {
        if ($itemcode === '') {
            return null;
        }
        $response = wp_remote_get(add_query_arg([
            'appid'   => $client_id,
            'query'   => rawurlencode($itemcode),
            'results' => 10,
        ], self::YAHOO_API_ENDPOINT), ['timeout' => self::TIMEOUT]);
        if (self::transport_error($response) !== null) {
            return null;
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $data  = json_decode((string) wp_remote_retrieve_body($response), true);
        $hits  = (is_array($data) && isset($data['hits']) && is_array($data['hits'])) ? $data['hits'] : [];
        $picked = null;
        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            if ($picked === null) {
                $picked = $hit; 
            }
            $hit_code = isset($hit['code']) ? (string) $hit['code'] : '';
            if ($hit_code !== '' && ($hit_code === $itemcode || str_ends_with($hit_code, '_' . $itemcode))) {
                $picked = $hit; 
                break;
            }
        }
        if ($picked === null) {
            return null;
        }
        $review = (isset($picked['review']) && is_array($picked['review'])) ? $picked['review'] : [];
        return [
            'Count' => isset($review['count']) ? (int) $review['count'] : 0,
            'Url'   => isset($review['url']) ? (string) $review['url'] : '',
        ];
    }
    private static function yahoo_wrapped(array $data, string $key): array {
        foreach ([$key, (int) $key] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                return $data[$k];
            }
        }
        return [];
    }
    private static function rakuten_throttle(): void {
        $last_us = (float) get_transient(self::RAKUTEN_THROTTLE_KEY);
        $now_us  = microtime(true);
        if ($last_us > 0) {
            $elapsed_us = (int) (($now_us - $last_us) * 1000000);
            if ($elapsed_us < self::RAKUTEN_MIN_INTERVAL_US) {
                usleep(self::RAKUTEN_MIN_INTERVAL_US - $elapsed_us);
            }
        }
        set_transient(self::RAKUTEN_THROTTLE_KEY, microtime(true), 60);
    }
    private static function rakuten_api_headers(string $access_key, string $site_url): array {
        $source = self::rakuten_source($site_url);
        return [
            'accessKey' => $access_key,
            'Origin'    => $source,
            'Referer'   => $source . '/',
        ];
    }
    private static function rakuten_source(string $configured): string {
        $base = trim($configured);
        if ($base === '') {
            $base = home_url();
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $base) !== 1) {
            $base = 'https://' . $base;
        }
        $parts  = wp_parse_url($base);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host   = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return rtrim($base, '/');
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }
    private static function ec_api_error_message(string $label, int $status, string $body): string {
        $detail = self::ec_api_error_detail($body);
        $suffix = $detail === '' ? '' : ': ' . $detail;
        if ($status === 401 || $status === 403) {
            return sprintf(
                __('%1$s のAPIキーが無効か、利用権限・送信元が許可されていません（HTTP %2$d%3$s）。', 'get-ec-reviews-api'),
                $label,
                $status,
                $suffix
            );
        }
        if ($status === 429) {
            return sprintf(
                __('%1$s のAPIリクエスト上限に達しました（HTTP 429%2$s）。時間をおいて再試行してください。', 'get-ec-reviews-api'),
                $label,
                $suffix
            );
        }
        return sprintf(
            __('%1$s のAPIで想定外の応答です（HTTP %2$d %3$s）。', 'get-ec-reviews-api'),
            $label,
            $status,
            self::excerpt($body)
        );
    }
    private static function ec_api_error_detail(string $body): string {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return '';
        }
        $errors = (isset($data['errors']) && is_array($data['errors'])) ? $data['errors'] : [];
        $error  = (isset($data['Error']) && is_array($data['Error'])) ? $data['Error'] : [];
        $candidates = [
            $errors['errorMessage'] ?? '',      
            $data['error_description'] ?? '',   
            $error['Message'] ?? '',            
            $data['error'] ?? '',
        ];
        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && (string) $candidate !== '') {
                return self::excerpt((string) $candidate);
            }
        }
        return '';
    }
    private static function parse_site_reviews_html(string $site, string $html, string $url, int $max): array {
        return match ($site) {
            'rakuten' => self::parse_rakuten_reviews_html($html, $url, $max),
            'yahoo'   => self::parse_yahoo_reviews_html($html, $url, $max),
            default   => [],
        };
    }
    private static function parse_rakuten_state_reviews(string $html, string $url, int $max): array {
        $state = self::extract_initial_state($html);
        if ($state === null) {
            return [];
        }
        $reviews      = (isset($state['reviews']) && is_array($state['reviews'])) ? $state['reviews'] : [];
        $entities     = (isset($reviews['data']) && is_array($reviews['data'])) ? $reviews['data'] : [];
        $item_reviews = (isset($reviews['itemReviews']) && is_array($reviews['itemReviews'])) ? $reviews['itemReviews'] : [];
        $keys         = (isset($item_reviews['keys']) && is_array($item_reviews['keys'])) ? $item_reviews['keys'] : [];
        $records = [];
        $seen    = [];
        foreach ($keys as $key) {
            if (count($records) >= $max) {
                break;
            }
            $key  = (string) $key;
            $item = (isset($entities[$key]) && is_array($entities[$key])) ? $entities[$key] : [];
            if ($item === [] || isset($seen[$key])) {
                continue;
            }
            $text = self::clean_review_text((string) ($item['body'] ?? ''));
            if ($text === '') {
                continue;
            }
            $seen[$key] = true;
            $records[] = [
                'review_id'          => $key,
                'rating'             => isset($item['rating']) ? (float) $item['rating'] : 0.0,
                'author_name'        => trim((string) ($item['nickname'] ?? '')),
                'review_header'      => trim((string) ($item['title'] ?? '')),
                'review_text'        => $text,
                'review_posted_date' => trim((string) ($item['postDate'] ?? '')),
                'helpful_count'      => isset($item['helpfulCount']) ? (int) $item['helpfulCount'] : 0,
                'is_verified'        => false,
                'url'                => $url,
            ];
        }
        return $records;
    }
    private static function extract_initial_state(string $html): ?array {
        $pos = strpos($html, 'window.__INITIAL_STATE__');
        if ($pos === false) {
            return null;
        }
        $start = strpos($html, '{', $pos);
        if ($start === false) {
            return null;
        }
        $depth     = 0;
        $in_string = false;
        $escaped   = false;
        $length    = strlen($html);
        for ($i = $start; $i < $length; $i++) {
            $char = $html[$i];
            if ($in_string) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $in_string = false;
                }
                continue;
            }
            if ($char === '"') {
                $in_string = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $data = json_decode(substr($html, $start, $i - $start + 1), true);
                    return is_array($data) ? $data : null;
                }
            }
        }
        return null;
    }
    private static function parse_rakuten_reviews_html(string $html, string $url, int $max): array {
        $records = self::parse_rakuten_state_reviews($html, $url, $max);
        if ($records !== []) {
            return $records;
        }
        $xpath = self::html_xpath($html);
        if ($xpath === null) {
            return [];
        }
        $bodies = $xpath->query('//*[contains(@class,"review-body")]');
        if ($bodies === false || $bodies->length === 0) {
            return [];
        }
        $records = [];
        $seen    = [];
        foreach ($bodies as $body) {
            if (count($records) >= $max) {
                break;
            }
            $card = self::review_card($xpath, $body, 'reviewer-name');
            if ($card === null) {
                continue;
            }
            $rating = self::numeric_text(self::xpath_text($xpath, './/*[contains(@class,"number-wrapper")]', $card));
            if ($rating <= 0.0) {
                $filled = $xpath->query('.//*[contains(@class,"element-yellow-star")]', $card);
                $rating = ($filled !== false) ? (float) $filled->length : 0.0;
            }
            $text = self::clean_review_text(trim((string) $body->textContent));
            $key = md5($text);
            if ($text === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $records[] = [
                'review_text'        => $text,
                'review_header'      => self::xpath_text($xpath, './/*[contains(@class,"type-header")]', $card),
                'rating'             => $rating,
                'author_name'        => self::xpath_text($xpath, './/*[contains(@class,"reviewer-name")]', $card),
                'review_posted_date' => self::first_date($card),
                'helpful_count'      => 0,
                'is_verified'        => false,
                'url'                => $url,
            ];
        }
        return $records;
    }
    private static function parse_yahoo_reviews_html(string $html, string $url, int $max): array {
        $xpath = self::html_xpath($html);
        if ($xpath === null) {
            return [];
        }
        $cards = $xpath->query('//*[@data-review-id]');
        if ($cards === false || $cards->length === 0) {
            return [];
        }
        $records = [];
        $seen    = [];
        foreach ($cards as $card) {
            if (count($records) >= $max) {
                break;
            }
            $review_id = '';
            $created   = '';
            if ($card->attributes !== null) {
                $id_attr = $card->attributes->getNamedItem('data-review-id');
                if ($id_attr !== null) {
                    $review_id = (string) $id_attr->nodeValue;
                }
                $created_attr = $card->attributes->getNamedItem('data-review-createtime');
                if ($created_attr !== null) {
                    $created = substr((string) $created_attr->nodeValue, 0, 10);
                }
            }
            $posted = self::xpath_text($xpath, './/*[contains(@class,"ReviewDetail__postedTime")]', $card);
            if ($posted === '') {
                $posted = $created;
            }
            $text = self::clean_review_text(self::xpath_text($xpath, './/*[contains(@class,"ReviewDetail__reviewBody")]', $card));
            $key = $review_id !== '' ? $review_id : md5($text);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $records[] = [
                'review_id'          => $review_id,
                'review_text'        => $text,
                'review_header'      => self::xpath_text($xpath, './/*[contains(@class,"ReviewDetail__reviewTitle")]', $card),
                'rating'             => self::numeric_text(self::xpath_text($xpath, './/*[contains(@class,"Review__average")]', $card)),
                'author_name'        => self::xpath_text($xpath, './/*[contains(@class,"ReviewDetail__userName")]', $card),
                'review_posted_date' => $posted,
                'helpful_count'      => (int) self::numeric_text(self::xpath_text($xpath, './/*[contains(@class,"ReviewDetail__likeText")]', $card)),
                'is_verified'        => false,
                'url'                => $url,
            ];
        }
        return $records;
    }
    private static function review_card(\DOMXPath $xpath, \DOMNode $body, string $anchor_class): ?\DOMNode {
        $nodes = $xpath->query('ancestor::*[descendant::*[contains(@class,"' . $anchor_class . '")]][1]', $body);
        if ($nodes !== false && $nodes->length > 0) {
            return $nodes->item(0);
        }
        return $body->parentNode;
    }
    private static function first_date(\DOMNode $node): string {
        if (preg_match('#\d{4}[/-]\d{1,2}[/-]\d{1,2}#', (string) $node->textContent, $m) === 1) {
            return $m[0];
        }
        return '';
    }
}
