<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}
if (class_exists('Free_Amzn_Creators_API_Client')) {
    return;
}
class Free_Amzn_Creators_API_Client {
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
    private const BRIGHT_DATA_SNAPSHOT_ENDPOINT = 'https://api.brightdata.com/datasets/v3/snapshot/';
    private const POLL_INTERVAL = 5;
    private const POLL_MAX_ATTEMPTS = 24;
    private const ZENROWS_ENDPOINT = 'https://api.zenrows.com/v1/';
    private const ZENROWS_AMAZON_DOMAIN = 'https://www.amazon.co.jp';
    private const ZENROWS_PROXY_COUNTRY = 'jp';
    private const WEBSCRAPINGAPI_ENDPOINT = 'https://api.webscrapingapi.com/v2';
    private const WEBSCRAPINGAPI_AMAZON_DOMAIN = 'https://www.amazon.co.jp';
    private const WEBSCRAPINGAPI_COUNTRY = 'jp';
    private const CONTEXTDEV_ENDPOINT = 'https://api.context.dev/v1/web/scrape';
    private const CONTEXTDEV_AMAZON_DOMAIN = 'https://www.amazon.co.jp';
    private const CONTEXTDEV_COUNTRY = 'JP';
    private static bool $last_transport_fallback = false;
    public static function test(string $service, string $api_key): array {
        if (trim($api_key) === '') {
            return ['ok' => false, 'message' => __('API キーが入力されていません。', GACHIREAI_TEXT_DOMAIN)];
        }
        return match ($service) {
            'zenrows'        => self::test_zenrows($api_key),
            'bright_data'    => self::test_bright_data($api_key),
            'scraperapi'     => self::test_scraperapi($api_key),
            'apify'          => self::test_apify($api_key),
            'webscrapingapi' => self::test_webscrapingapi($api_key),
            'contextdev'     => self::test_contextdev($api_key),
            default          => ['ok' => false, 'message' => __('未知のサービスです。', GACHIREAI_TEXT_DOMAIN)],
        };
    }
    public static function search(string $service, string $api_key, string $keyword, array $args = []): array {
        @set_time_limit(300);
        self::$last_transport_fallback = false;
        if (trim($api_key) === '') {
            return self::with_failure_flags(['ok' => false, 'records' => [], 'message' => __('API キーが入力されていません。', GACHIREAI_TEXT_DOMAIN)]);
        }
        $result = match ($service) {
            'zenrows'        => self::search_zenrows($api_key, $keyword, $args),
            'bright_data'    => self::search_bright_data($api_key, $keyword, $args),
            'scraperapi'     => self::search_scraperapi($api_key, $keyword, $args),
            'apify'          => self::search_apify($api_key, $keyword, $args),
            'webscrapingapi' => self::search_webscrapingapi($api_key, $keyword, $args),
            'contextdev'     => self::search_contextdev($api_key, $keyword, $args),
            default          => ['ok' => false, 'records' => [], 'message' => __('このサービスでの商品取得は未実装です（現在は ZenRows / Bright Data / ScraperAPI / Apify / WebScrapingAPI / Context.dev に対応）。', GACHIREAI_TEXT_DOMAIN)],
        };
        return self::with_failure_flags($result);
    }
    public static function detail(string $service, string $api_key, string $asin): array {
        @set_time_limit(300);
        self::$last_transport_fallback = false;
        if (trim($api_key) === '') {
            return self::with_failure_flags(['ok' => false, 'records' => [], 'message' => __('API キーが入力されていません。', GACHIREAI_TEXT_DOMAIN)]);
        }
        $result = match ($service) {
            'zenrows'        => self::detail_zenrows($api_key, $asin),
            'bright_data'    => self::detail_bright_data($api_key, $asin),
            'scraperapi'     => self::detail_scraperapi($api_key, $asin),
            'apify'          => self::detail_apify($api_key, $asin),
            'webscrapingapi' => self::detail_webscrapingapi($api_key, $asin),
            'contextdev'     => self::detail_contextdev($api_key, $asin),
            default          => ['ok' => false, 'records' => [], 'message' => __('このサービスでの商品詳細取得は未実装です（現在は ZenRows / Bright Data / ScraperAPI / Apify / WebScrapingAPI / Context.dev に対応）。', GACHIREAI_TEXT_DOMAIN)],
        };
        return self::with_failure_flags($result);
    }
    public static function reviews(string $service, string $api_key, string $asin, array $args = []): array {
        @set_time_limit(300);
        self::$last_transport_fallback = false;
        if (trim($api_key) === '') {
            return self::with_failure_flags(['ok' => false, 'records' => [], 'message' => __('API キーが入力されていません。', GACHIREAI_TEXT_DOMAIN)]);
        }
        $result = match ($service) {
            'zenrows'        => self::reviews_zenrows($api_key, $asin, $args),
            'bright_data'    => self::reviews_bright_data($api_key, $asin, $args),
            'scraperapi'     => self::reviews_scraperapi($api_key, $asin, $args),
            'apify'          => self::reviews_apify($api_key, $asin, $args),
            'webscrapingapi' => self::reviews_webscrapingapi($api_key, $asin, $args),
            'contextdev'     => self::reviews_contextdev($api_key, $asin, $args),
            default          => ['ok' => false, 'records' => [], 'message' => __('このサービスでのレビュー取得は未実装です（現在は ZenRows / Bright Data / ScraperAPI / Apify / WebScrapingAPI / Context.dev に対応）。', GACHIREAI_TEXT_DOMAIN)],
        };
        return self::with_failure_flags($result);
    }
    private static function search_bright_data(string $api_key, string $keyword, array $args): array {
        $endpoint = add_query_arg(
            ['dataset_id' => self::BRIGHT_DATA_SEARCH_DATASET, 'format' => 'json'],
            self::BRIGHT_DATA_ENDPOINT
        );
        $input = [
            'keyword'         => $keyword,
            'url'             => self::BRIGHT_DATA_SEARCH_DOMAIN,
            'pages_to_search' => max(1, (int) ($args['pages'] ?? 1)),
        ];
        $payload = ['input' => [$input]];
        if (isset($args['limit']) && (int) $args['limit'] > 0) {
            $payload['limit_per_input'] = (int) $args['limit'];
        }
        $response = wp_remote_post(
            $endpoint,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => (string) wp_json_encode($payload),
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
                return ['ok' => false, 'records' => [], 'message' => __('応答を解析できませんでした（JSON 形式ではありません）。', GACHIREAI_TEXT_DOMAIN)];
            }
            return ['ok' => true, 'records' => $records, 'message' => ''];
        }
        if ($status === 202) {
            return self::fetch_bright_data_snapshot($api_key, $response_body);
        }
        return self::bright_data_search_error($status, $response_body);
    }
    private static function detail_bright_data(string $api_key, string $asin): array {
        $endpoint = add_query_arg(
            ['dataset_id' => self::BRIGHT_DATA_DETAIL_DATASET, 'format' => 'json'],
            self::BRIGHT_DATA_ENDPOINT
        );
        $input = [
            'url'  => self::BRIGHT_DATA_SEARCH_DOMAIN . '/dp/' . rawurlencode($asin),
            'asin' => $asin,
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
                return ['ok' => false, 'records' => [], 'message' => __('応答を解析できませんでした（JSON 形式ではありません）。', GACHIREAI_TEXT_DOMAIN)];
            }
            return ['ok' => true, 'records' => $records, 'message' => ''];
        }
        if ($status === 202) {
            return self::fetch_bright_data_snapshot($api_key, $response_body);
        }
        return self::bright_data_search_error($status, $response_body);
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
                return ['ok' => false, 'records' => [], 'message' => __('応答を解析できませんでした（JSON 形式ではありません）。', GACHIREAI_TEXT_DOMAIN)];
            }
            return ['ok' => true, 'records' => $records, 'message' => ''];
        }
        if ($status === 202) {
            return self::fetch_bright_data_snapshot($api_key, $response_body);
        }
        return self::bright_data_search_error($status, $response_body);
    }
    private static function search_scraperapi(string $api_key, string $keyword, array $args): array {
        $endpoint = self::SCRAPERAPI_AMAZON_SEARCH_ENDPOINT . '?' . http_build_query(
            [
                'api_key'   => $api_key,
                'query'     => $keyword,
                'tld'       => self::SCRAPERAPI_SEARCH_TLD,
                'num_pages' => max(1, (int) ($args['pages'] ?? 1)),
            ]
        );
        $response = wp_remote_get($endpoint, ['timeout' => self::SEARCH_TIMEOUT]);
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'records' => [], 'message' => $error];
        }
        return self::scraperapi_structured_response(
            (int) wp_remote_retrieve_response_code($response),
            (string) wp_remote_retrieve_body($response)
        );
    }
    private static function detail_scraperapi(string $api_key, string $asin): array {
        $product_url = self::SCRAPERAPI_AMAZON_DOMAIN . '/dp/' . rawurlencode($asin);
        $fetched = self::scraperapi_fetch_html($api_key, $product_url);
        if (!$fetched['ok']) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => $fetched['message'],
                'quota_exhausted' => (bool) ($fetched['quota_exhausted'] ?? false),
                'fallback'        => (bool) ($fetched['fallback'] ?? false),
            ];
        }
        return [
            'ok'      => true,
            'records' => [self::parse_amazon_product_html($fetched['html'], $asin, $product_url)],
            'message' => '',
        ];
    }
    private static function reviews_scraperapi(string $api_key, string $asin, array $args): array {
        $max_reviews = max(1, (int) ($args['max_reviews'] ?? self::REVIEWS_DEFAULT_MAX));
        $reviews_url = self::SCRAPERAPI_AMAZON_DOMAIN . '/product-reviews/' . rawurlencode($asin);
        $fetched = self::scraperapi_fetch_html($api_key, $reviews_url);
        if (!$fetched['ok']) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => $fetched['message'],
                'quota_exhausted' => (bool) ($fetched['quota_exhausted'] ?? false),
                'fallback'        => (bool) ($fetched['fallback'] ?? false),
            ];
        }
        return [
            'ok'      => true,
            'records' => self::parse_amazon_reviews_html($fetched['html'], $asin, $reviews_url, $max_reviews),
            'message' => '',
        ];
    }
    private static function scraperapi_structured_response(int $status, string $body): array {
        if ($status === 200) {
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'records' => [], 'message' => __('応答を解析できませんでした（JSON 形式ではありません）。', GACHIREAI_TEXT_DOMAIN)];
            }
            if (isset($decoded['results']) && is_array($decoded['results'])) {
                $records = $decoded['results'];
            } elseif (isset($decoded[0]) && is_array($decoded[0])) {
                $records = $decoded;
            } else {
                $records = [];
            }
            return ['ok' => true, 'records' => $records, 'message' => ''];
        }
        if ($status === 401 || $status === 403) {
            return array_merge([
                'ok'      => false,
                'records' => [],
                'message' => sprintf(
                    __('API キーが無効です（HTTP %d）。', GACHIREAI_TEXT_DOMAIN),
                    $status
                ),
            ], self::failure_flags($status));
        }
        if ($status === 429) {
            return array_merge([
                'ok'      => false,
                'records' => [],
                'message' => __('クレジットまたはレートの上限に達しました（HTTP 429）。時間をおいて再度お試しください。', GACHIREAI_TEXT_DOMAIN),
            ], self::failure_flags($status));
        }
        return array_merge([
            'ok'      => false,
            'records' => [],
            'message' => self::unexpected_response($status, $body)['message'],
        ], self::failure_flags($status));
    }
    private static function scraperapi_fetch_html(string $api_key, string $target_url): array {
        $endpoint = self::SCRAPERAPI_HTML_ENDPOINT . '?' . http_build_query(
            [
                'api_key'      => $api_key,
                'url'          => $target_url,
                'country_code' => self::SCRAPERAPI_COUNTRY_CODE,
                'premium'      => 'true',
            ]
        );
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
            ['ok' => false, 'html' => '', 'message' => self::scraperapi_html_error($status, $body)],
            self::failure_flags($status)
        );
    }
    private static function scraperapi_html_error(int $status, string $body): string {
        if ($status === 401 || $status === 403) {
            return sprintf(
                __('API キーが無効です（HTTP %d）。', GACHIREAI_TEXT_DOMAIN),
                $status
            );
        }
        if ($status === 429) {
            return __('クレジットまたはレートの上限に達しました（HTTP 429）。時間をおいて再度お試しください。', GACHIREAI_TEXT_DOMAIN);
        }
        if ($status === 404) {
            return __('対象ページを取得できませんでした（HTTP 404）。', GACHIREAI_TEXT_DOMAIN);
        }
        return self::unexpected_response($status, $body)['message'];
    }
    private static function parse_amazon_product_html(string $html, string $asin, string $url): array {
        $xpath = self::amazon_xpath($html);
        $title = self::xpath_text($xpath, '//span[@id="productTitle"]');
        if ($title === '') {
            $title = self::xpath_text($xpath, '//title');
        }
        $price = self::xpath_text($xpath, '//span[contains(@class,"a-price")]//span[contains(@class,"a-offscreen")]');
        if ($price === '') {
            $price = self::xpath_text($xpath, '//span[contains(@class,"a-price-whole")]');
        }
        if ($price === '') {
            $price = self::xpath_text($xpath, '//span[@id="priceblock_ourprice"]');
        }
        $rating_text = self::xpath_text($xpath, '//span[@data-hook="rating-out-of-text"]');
        if ($rating_text === '') {
            $rating_text = self::xpath_text($xpath, '//i[@data-hook="average-star-rating"]//span[contains(@class,"a-icon-alt")]');
        }
        $review_count_text = self::xpath_text($xpath, '//span[@id="acrCustomerReviewText"]');
        $image = self::xpath_attr($xpath, '//div[@id="imgTagWrapperId"]//img', 'src');
        if ($image === '') {
            $image = self::xpath_attr($xpath, '//img[@id="landingImage"]', 'src');
        }
        return [
            'asin'         => $asin,
            'title'        => $title,
            'price'        => $price,
            'currency'     => 'JPY',
            'rating'       => self::parse_star_rating($rating_text),
            'review_count' => (int) self::numeric_text($review_count_text),
            'image'        => $image,
            'url'          => $url,
        ];
    }
    private static function parse_amazon_reviews_html(string $html, string $asin, string $url, int $max_reviews): array {
        $xpath = self::amazon_xpath($html);
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
    private static function search_zenrows(string $api_key, string $keyword, array $args): array {
        $search_url = self::ZENROWS_AMAZON_DOMAIN . '/s?k=' . rawurlencode($keyword) . '&language=ja_JP';
        $fetched = self::zenrows_fetch_html($api_key, $search_url);
        if (!$fetched['ok']) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => $fetched['message'],
                'quota_exhausted' => (bool) ($fetched['quota_exhausted'] ?? false),
                'fallback'        => (bool) ($fetched['fallback'] ?? false),
            ];
        }
        return [
            'ok'      => true,
            'records' => self::parse_amazon_search_html($fetched['html']),
            'message' => '',
        ];
    }
    private static function detail_zenrows(string $api_key, string $asin): array {
        $product_url = self::ZENROWS_AMAZON_DOMAIN . '/dp/' . rawurlencode($asin);
        $fetched = self::zenrows_fetch_html($api_key, $product_url . '?language=ja_JP');
        if (!$fetched['ok']) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => $fetched['message'],
                'quota_exhausted' => (bool) ($fetched['quota_exhausted'] ?? false),
                'fallback'        => (bool) ($fetched['fallback'] ?? false),
            ];
        }
        return [
            'ok'      => true,
            'records' => [self::parse_amazon_product_html($fetched['html'], $asin, $product_url)],
            'message' => '',
        ];
    }
    private static function reviews_zenrows(string $api_key, string $asin, array $args): array {
        $max_reviews = max(1, (int) ($args['max_reviews'] ?? self::REVIEWS_DEFAULT_MAX));
        $reviews_url = self::ZENROWS_AMAZON_DOMAIN . '/product-reviews/' . rawurlencode($asin);
        $fetched = self::zenrows_fetch_html($api_key, $reviews_url);
        if (!$fetched['ok']) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => $fetched['message'],
                'quota_exhausted' => (bool) ($fetched['quota_exhausted'] ?? false),
                'fallback'        => (bool) ($fetched['fallback'] ?? false),
            ];
        }
        return [
            'ok'      => true,
            'records' => self::parse_amazon_reviews_html($fetched['html'], $asin, $reviews_url, $max_reviews),
            'message' => '',
        ];
    }
    private static function zenrows_fetch_html(string $api_key, string $target_url): array {
        $endpoint = self::ZENROWS_ENDPOINT . '?' . http_build_query(
            [
                'apikey'         => $api_key,
                'url'            => $target_url,
                'premium_proxy'  => 'true',
                'proxy_country'  => self::ZENROWS_PROXY_COUNTRY,
                'custom_headers' => 'true',
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
            ['ok' => false, 'html' => '', 'message' => self::zenrows_html_error($status, $body)],
            self::zenrows_failure_flags($status, $body)
        );
    }
    private static function zenrows_html_error(int $status, string $body): string {
        if ($status === 401 || $status === 403) {
            return sprintf(
                __('API キーが無効です（HTTP %d）。', GACHIREAI_TEXT_DOMAIN),
                $status
            );
        }
        if ($status === 429) {
            return __('クレジットまたはレートの上限に達しました（HTTP 429）。時間をおいて再度お試しください。', GACHIREAI_TEXT_DOMAIN);
        }
        if ($status === 404) {
            return __('対象ページを取得できませんでした（HTTP 404）。', GACHIREAI_TEXT_DOMAIN);
        }
        if ($status === 400 && str_contains($body, 'REQS001')) {
            return __('この URL は ZenRows では取得できません（アクセス制限）。別のサービスで取得してください。', GACHIREAI_TEXT_DOMAIN);
        }
        return self::unexpected_response($status, $body)['message'];
    }
    private static function zenrows_failure_flags(int $status, string $body): array {
        if ($status === 429) {
            return ['quota_exhausted' => true, 'fallback' => false];
        }
        if ($status === 400) {
            if (preg_match('/REQS001|REQS002/', $body) === 1) {
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
                    __('API キーが無効です（HTTP %d）。', GACHIREAI_TEXT_DOMAIN),
                    $status
                ),
            ];
        }
        if ($status === 400 || ($status >= 200 && $status < 300)) {
            return ['ok' => true, 'message' => __('API キーは有効です（無料枠は消費していません）。', GACHIREAI_TEXT_DOMAIN)];
        }
        return self::unexpected_response($status, $body);
    }
    private static function search_webscrapingapi(string $api_key, string $keyword, array $args): array {
        $search_url = self::WEBSCRAPINGAPI_AMAZON_DOMAIN . '/s?k=' . rawurlencode($keyword) . '&language=ja_JP';
        $fetched = self::webscrapingapi_fetch_html($api_key, $search_url);
        if (!$fetched['ok']) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => $fetched['message'],
                'quota_exhausted' => (bool) ($fetched['quota_exhausted'] ?? false),
                'fallback'        => (bool) ($fetched['fallback'] ?? false),
            ];
        }
        return [
            'ok'      => true,
            'records' => self::parse_amazon_search_html($fetched['html']),
            'message' => '',
        ];
    }
    private static function detail_webscrapingapi(string $api_key, string $asin): array {
        $product_url = self::WEBSCRAPINGAPI_AMAZON_DOMAIN . '/dp/' . rawurlencode($asin);
        $fetched = self::webscrapingapi_fetch_html($api_key, $product_url);
        if (!$fetched['ok']) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => $fetched['message'],
                'quota_exhausted' => (bool) ($fetched['quota_exhausted'] ?? false),
                'fallback'        => (bool) ($fetched['fallback'] ?? false),
            ];
        }
        return [
            'ok'      => true,
            'records' => [self::parse_amazon_product_html($fetched['html'], $asin, $product_url)],
            'message' => '',
        ];
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
        $product_html = '';
        if ($records === []) {
            $product_url = self::WEBSCRAPINGAPI_AMAZON_DOMAIN . '/dp/' . rawurlencode($asin);
            $product     = self::webscrapingapi_fetch_html($api_key, $product_url);
            if ($product['ok']) {
                $product_html = $product['html'];
                $records      = self::parse_amazon_reviews_html($product_html, $asin, $product_url, $max_reviews);
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
                'message'         => __('【診断】レビューの本文・タイトルを抽出できませんでした。お手数ですが以下の全文をご報告ください。 ', GACHIREAI_TEXT_DOMAIN) . $diagnostic,
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
    private static function search_contextdev(string $api_key, string $keyword, array $args): array {
        $search_url = self::CONTEXTDEV_AMAZON_DOMAIN . '/s?k=' . rawurlencode($keyword) . '&language=ja_JP';
        $fetched = self::contextdev_fetch_html($api_key, $search_url);
        if (!$fetched['ok']) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => $fetched['message'],
                'quota_exhausted' => (bool) ($fetched['quota_exhausted'] ?? false),
                'fallback'        => (bool) ($fetched['fallback'] ?? false),
            ];
        }
        return [
            'ok'      => true,
            'records' => self::parse_amazon_search_html($fetched['html']),
            'message' => '',
        ];
    }
    private static function detail_contextdev(string $api_key, string $asin): array {
        $product_url = self::CONTEXTDEV_AMAZON_DOMAIN . '/dp/' . rawurlencode($asin);
        $fetched = self::contextdev_fetch_html($api_key, $product_url);
        if (!$fetched['ok']) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => $fetched['message'],
                'quota_exhausted' => (bool) ($fetched['quota_exhausted'] ?? false),
                'fallback'        => (bool) ($fetched['fallback'] ?? false),
            ];
        }
        return [
            'ok'      => true,
            'records' => [self::parse_amazon_product_html($fetched['html'], $asin, $product_url)],
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
        if ($records === []) {
            $product_url = self::CONTEXTDEV_AMAZON_DOMAIN . '/dp/' . rawurlencode($asin);
            $product     = self::contextdev_fetch_html($api_key, $product_url);
            if ($product['ok']) {
                $records = self::parse_amazon_reviews_html($product['html'], $asin, $product_url, $max_reviews);
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
        $xpath = self::amazon_xpath($html);
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
            ['ok' => false, 'html' => '', 'message' => self::webscrapingapi_html_error($status, $body)],
            self::failure_flags($status)
        );
    }
    private static function webscrapingapi_html_error(int $status, string $body): string {
        if ($status === 401 || $status === 403) {
            return sprintf(
                __('リクエストが拒否されました（HTTP %1$d %2$s）。API キーが無効か、このプラン・対象では許可されていない可能性があります。', GACHIREAI_TEXT_DOMAIN),
                $status,
                self::excerpt($body)
            );
        }
        if ($status === 429) {
            return __('クレジットまたはレートの上限に達しました（HTTP 429）。時間をおいて再度お試しください。', GACHIREAI_TEXT_DOMAIN);
        }
        if ($status === 404) {
            return __('対象ページを取得できませんでした（HTTP 404）。', GACHIREAI_TEXT_DOMAIN);
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
                    __('API キーが無効です（HTTP %d）。', GACHIREAI_TEXT_DOMAIN),
                    $status
                ),
            ];
        }
        if ($status === 400 || ($status >= 200 && $status < 300)) {
            return ['ok' => true, 'message' => __('API キーは有効です（無料枠は消費していません）。', GACHIREAI_TEXT_DOMAIN)];
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
                ['ok' => false, 'html' => '', 'message' => self::contextdev_error($status, $body)],
                self::failure_flags($status)
            );
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'html' => '', 'message' => __('応答を JSON として解釈できませんでした。', GACHIREAI_TEXT_DOMAIN), 'quota_exhausted' => false, 'fallback' => false];
        }
        $html_output = (isset($decoded['html']) && is_array($decoded['html'])) ? $decoded['html'] : [];
        if (empty($html_output['success'])) {
            return [
                'ok'              => false,
                'html'            => '',
                'message'         => sprintf(
                    __('HTML の取得に失敗しました（%1$s %2$s）。', GACHIREAI_TEXT_DOMAIN),
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
                __('リクエストが拒否されました（HTTP %1$d %2$s）。API キーが無効か、このプラン・対象では許可されていない可能性があります。', GACHIREAI_TEXT_DOMAIN),
                $status,
                self::excerpt($body)
            );
        }
        if ($status === 402) {
            return __('クレジットが不足しています（HTTP 402）。プランと残クレジットをご確認ください。', GACHIREAI_TEXT_DOMAIN);
        }
        if ($status === 429) {
            return __('クレジットまたはレートの上限に達しました（HTTP 429）。時間をおいて再度お試しください。', GACHIREAI_TEXT_DOMAIN);
        }
        if ($status === 404) {
            return __('対象ページを取得できませんでした（HTTP 404）。', GACHIREAI_TEXT_DOMAIN);
        }
        return self::unexpected_response($status, $body)['message'];
    }
    private static function parse_amazon_search_html(string $html): array {
        $xpath = self::amazon_xpath($html);
        if ($xpath === null) {
            return [];
        }
        $nodes = $xpath->query('//div[@data-component-type="s-search-result"]');
        if ($nodes === false || $nodes->length === 0) {
            $nodes = $xpath->query('//div[contains(@class,"s-result-item") and @data-asin]');
        }
        if ($nodes === false) {
            return [];
        }
        $records = [];
        foreach ($nodes as $node) {
            $asin = $node instanceof \DOMElement ? trim($node->getAttribute('data-asin')) : '';
            if ($asin === '') {
                continue;
            }
            $title = self::xpath_text($xpath, './/h2//span', $node);
            if ($title === '') {
                $title = self::xpath_text($xpath, './/span[contains(@class,"a-text-normal")]', $node);
            }
            $price = self::xpath_text($xpath, './/span[contains(@class,"a-price")]//span[contains(@class,"a-offscreen")]', $node);
            if ($price === '') {
                $price = self::xpath_text($xpath, './/span[contains(@class,"a-price-whole")]', $node);
            }
            $rating_text = self::xpath_text($xpath, './/span[contains(@class,"a-icon-alt")]', $node);
            $review_count_text = self::xpath_text($xpath, './/span[contains(@class,"s-underline-text")]', $node);
            if ($review_count_text === '') {
                $review_count_text = self::xpath_text($xpath, './/span[contains(@class,"a-size-base")]', $node);
            }
            $image = self::xpath_attr($xpath, './/img[contains(@class,"s-image")]', 'src', $node);
            if ($image === '') {
                $image = self::xpath_attr($xpath, './/img[@data-image-latency]', 'src', $node);
            }
            $records[] = [
                'asin'         => $asin,
                'title'        => $title,
                'price'        => $price,
                'currency'     => 'JPY',
                'rating'       => self::parse_star_rating($rating_text),
                'review_count' => (int) self::numeric_text($review_count_text),
                'url'          => self::ZENROWS_AMAZON_DOMAIN . '/dp/' . $asin,
                'image'        => $image,
            ];
        }
        return $records;
    }
    private static function amazon_xpath(string $html): ?\DOMXPath {
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
    private static function search_apify(string $api_key, string $keyword, array $args): array {
        $max_items = (isset($args['limit']) && (int) $args['limit'] > 0)
            ? (int) $args['limit']
            : self::APIFY_DEFAULT_MAX_ITEMS;
        $input = [
            'keywords'             => [$keyword],
            'marketplace'          => self::APIFY_AMAZON_MARKETPLACE,
            'maxResultsPerKeyword' => $max_items,
            'proxyConfiguration'   => ['useApifyProxy' => true],
        ];
        return self::run_apify_actor($api_key, self::APIFY_AMAZON_SEARCH_ACTOR, $input);
    }
    private static function detail_apify(string $api_key, string $asin): array {
        $input = [
            'asins'              => [$asin],
            'marketplace'        => self::APIFY_AMAZON_MARKETPLACE,
            'fullDetails'        => true,
            'proxyConfiguration' => ['useApifyProxy' => true],
        ];
        return self::run_apify_actor($api_key, self::APIFY_AMAZON_SEARCH_ACTOR, $input);
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
            return ['ok' => false, 'records' => [], 'message' => __('Apify の実行 ID を取得できませんでした。', GACHIREAI_TEXT_DOMAIN)];
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
                    '[Free Amzn Creators API] Apify run %s: %s%s',
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
            return ['ok' => false, 'records' => [], 'message' => __('Apify の実行が完了しませんでした（実行中です）。時間をおいて再度お試しください。', GACHIREAI_TEXT_DOMAIN)];
        }
        if ($dataset_id === '') {
            return ['ok' => false, 'records' => [], 'message' => __('Apify のデータセット ID を取得できませんでした。', GACHIREAI_TEXT_DOMAIN)];
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
            return ['ok' => false, 'records' => [], 'message' => __('Apify の応答を解析できませんでした。', GACHIREAI_TEXT_DOMAIN)];
        }
        return ['ok' => true, 'records' => $records, 'message' => ''];
    }
    private static function apify_error(int $status, string $body): array {
        $message = match ($status) {
            401, 403 => __('API キーが無効です（HTTP 401/403）。', GACHIREAI_TEXT_DOMAIN),
            404      => __('Apify の Actor が見つかりません（HTTP 404）。', GACHIREAI_TEXT_DOMAIN),
            429      => __('リクエストが多すぎます（HTTP 429）。時間をおいて再度お試しください。', GACHIREAI_TEXT_DOMAIN),
            default  => self::unexpected_response($status, $body)['message'],
        };
        return array_merge(['ok' => false, 'records' => [], 'message' => $message], self::failure_flags($status));
    }
    private static function apify_failure_message(string $run_state, string $status_message): string {
        if ($status_message === '') {
            return sprintf(
                __('Apify の実行が失敗しました（%s）。', GACHIREAI_TEXT_DOMAIN),
                $run_state
            );
        }
        return sprintf(
            __('Apify の実行が失敗しました（%1$s: %2$s）。', GACHIREAI_TEXT_DOMAIN),
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
        error_log('[Free Amzn Creators API] Apify run log tail: ' . $tail);
    }
    private static function fetch_bright_data_snapshot(string $api_key, string $response_body): array {
        $decoded     = json_decode($response_body, true);
        $snapshot_id = (is_array($decoded) && isset($decoded['snapshot_id'])) ? (string) $decoded['snapshot_id'] : '';
        if ($snapshot_id === '') {
            return ['ok' => false, 'records' => [], 'message' => __('非同期ジョブの snapshot_id を取得できませんでした。', GACHIREAI_TEXT_DOMAIN)];
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
                return ['ok' => false, 'records' => [], 'message' => __('Bright Data の非同期ジョブが失敗しました。', GACHIREAI_TEXT_DOMAIN)];
            }
            if ($progress_status === 'ready' || $progress_status === '') {
                $records = self::download_bright_data_snapshot($api_key, $snapshot_id);
                if ($records['ok']) {
                    return $records;
                }
            }
        }
        return ['ok' => false, 'records' => [], 'message' => __('Bright Data の非同期処理が完了しませんでした。商品によっては収集に数分かかることがあります。時間をおいて再度お試しください。', GACHIREAI_TEXT_DOMAIN)];
    }
    private static function download_bright_data_snapshot(string $api_key, string $snapshot_id): array {
        $endpoint = add_query_arg(
            ['format' => 'json'],
            self::BRIGHT_DATA_SNAPSHOT_ENDPOINT . rawurlencode($snapshot_id)
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
        return ['ok' => false, 'records' => [], 'message' => __('snapshot のダウンロードに失敗しました。', GACHIREAI_TEXT_DOMAIN)];
    }
    private static function bright_data_search_error(int $status, string $body): array {
        $message = match ($status) {
            400     => __('入力内容が不正です（HTTP 400）。', GACHIREAI_TEXT_DOMAIN),
            401     => __('API キーが無効です（HTTP 401）。', GACHIREAI_TEXT_DOMAIN),
            404     => __('データセットが見つかりません（HTTP 404）。', GACHIREAI_TEXT_DOMAIN),
            429     => __('リクエストが多すぎます（HTTP 429）。時間をおいて再度お試しください。', GACHIREAI_TEXT_DOMAIN),
            default => self::unexpected_response($status, $body)['message'],
        };
        return array_merge(['ok' => false, 'records' => [], 'message' => $message], self::failure_flags($status));
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
            return ['ok' => false, 'message' => __('API キーが無効です（HTTP 401 Invalid credentials）。', GACHIREAI_TEXT_DOMAIN)];
        }
        if (($status === 404 && str_contains($body, 'Collector not found')) || ($status >= 200 && $status < 300)) {
            return ['ok' => true, 'message' => __('API キーは有効です（無料枠は消費していません）。', GACHIREAI_TEXT_DOMAIN)];
        }
        return self::unexpected_response($status, $body);
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
                        __('API キーは有効です（残クレジット: %s／無料枠は消費していません）。', GACHIREAI_TEXT_DOMAIN),
                        $credits
                    )
                    : __('API キーは有効です（無料枠は消費していません）。', GACHIREAI_TEXT_DOMAIN),
            ];
        }
        if ($status === 401 || $status === 403) {
            return [
                'ok'      => false,
                'message' => sprintf(
                    __('API キーが無効です（HTTP %d）。', GACHIREAI_TEXT_DOMAIN),
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
            return ['ok' => true, 'message' => __('API キーは有効です（無料枠は消費していません）。', GACHIREAI_TEXT_DOMAIN)];
        }
        if ($status === 401) {
            return ['ok' => false, 'message' => __('API キーが無効です（HTTP 401）。', GACHIREAI_TEXT_DOMAIN)];
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
                    __('API キーが無効です（HTTP %d）。', GACHIREAI_TEXT_DOMAIN),
                    $status
                ),
            ];
        }
        if ($status === 400 || ($status >= 200 && $status < 300)) {
            return ['ok' => true, 'message' => __('API キーは有効です（無料枠は消費していません）。', GACHIREAI_TEXT_DOMAIN)];
        }
        return self::unexpected_response($status, $body);
    }
    private static function transport_error($response): ?string {
        if (is_wp_error($response)) {
            self::$last_transport_fallback = self::is_transient_network_error($response);
            return sprintf(
                __('通信に失敗しました: %s', GACHIREAI_TEXT_DOMAIN),
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
                __('想定外の応答です（HTTP %1$d %2$s）。', GACHIREAI_TEXT_DOMAIN),
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
}
