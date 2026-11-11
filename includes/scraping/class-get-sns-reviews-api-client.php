<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}
if (class_exists('Get_SNS_Reviews_API_Client')) {
    return;
}
class Get_SNS_Reviews_API_Client {
    private const TIMEOUT = 20;
    private const BRIGHT_DATA_ENDPOINT = 'https://api.brightdata.com/datasets/v3/scrape';
    private const APIFY_ME_ENDPOINT = 'https://api.apify.com/v2/users/me';
    private const APIFY_ACTOR_RUNS_ENDPOINT = 'https://api.apify.com/v2/acts/';
    private const APIFY_ACTOR_RUN_ENDPOINT = 'https://api.apify.com/v2/actor-runs/';
    private const APIFY_DATASETS_ENDPOINT = 'https://api.apify.com/v2/datasets/';
    private const APIFY_POLL_INTERVAL = 5;
    private const APIFY_POLL_MAX_ATTEMPTS = 20;
    public const REVIEWS_DEFAULT_MAX = 10;
    private const APIFY_SNS_ACTORS = [
        'x'         => 'kaitoeasyapi~twitter-x-data-tweet-scraper-pay-per-result-cheapest',
        'instagram' => 'apify~instagram-scraper',
        'facebook'  => 'scraper_one~facebook-posts-search',
    ];
    private const APIFY_MIN_ITEMS = 20;
    private const ZENROWS_ENDPOINT = 'https://api.zenrows.com/v1/';
    private const WEBSCRAPINGAPI_ENDPOINT = 'https://api.webscrapingapi.com/v2';
    private const HTML_TIMEOUT = 90;
    private const BRIGHT_DATA_UNLOCKER_ENDPOINT = 'https://api.brightdata.com/request';
    private const BRIGHT_DATA_ZONES_ENDPOINT = 'https://api.brightdata.com/zone/get_active_zones';
    private const BRIGHT_DATA_ZONE_CACHE_PREFIX = 'get_sns_reviews_api_bd_zone_';
    private const BRIGHT_DATA_ZONE_CACHE_TTL = DAY_IN_SECONDS;
    private const BRIGHT_DATA_DEFAULT_UNLOCKER_ZONE = 'web_unlocker1';
    private const BRIGHT_DATA_DATASETS_ENDPOINT = 'https://api.brightdata.com/datasets/v3/';
    private const BRIGHT_DATA_SNAPSHOT_PROGRESS_ENDPOINT = 'https://api.brightdata.com/datasets/v3/progress/';
    private const BRIGHT_DATA_SNAPSHOT_DOWNLOAD_ENDPOINT = 'https://api.brightdata.com/datasets/v3/snapshot/';
    private const BRIGHT_DATA_POLL_INTERVAL = 5;
    private const BRIGHT_DATA_POLL_MAX_ATTEMPTS = 12;
    private const BRIGHT_DATA_FB_PAGES_DATASET = 'gd_lkaxegm826bjpoo9m5';
    private const BRIGHT_DATA_FB_GROUPS_DATASET = 'gd_lz11l67o2cb3r0lkj3';
    private const FACEBOOK_MAX_PAGES = 3;
    private const FACEBOOK_POSTS_PER_PAGE = 10;
    private const ZENROWS_PROXY_COUNTRY = 'jp';
    private const WEBSCRAPINGAPI_COUNTRY = 'jp';
    private const ENSEMBLEDATA_ENDPOINT = 'https://ensembledata.com/apis';
    private const ENSEMBLEDATA_USAGE_ENDPOINT = 'https://ensembledata.com/apis/customer/get-used-units';
    private const SCRAPEDO_ENDPOINT = 'https://api.scrape.do/';
    private const SCRAPEDO_INFO_ENDPOINT = 'https://api.scrape.do/info';
    private const SERP_ENDPOINTS = [
        'https://html.duckduckgo.com/html/',
        'https://lite.duckduckgo.com/lite/',
    ];
    private const SERP_DOMAINS = [
        'x'         => 'x.com',
        'instagram' => 'instagram.com',
        'facebook'  => 'facebook.com',
    ];
    private const SERP_URLS_CACHE_PREFIX = 'get_sns_reviews_api_serp_urls_';
    private const SERP_URLS_CACHE_TTL = DAY_IN_SECONDS;
    private const SERP_URLS_CACHE_MAX = 100;
    private static bool $last_transport_fallback = false;
    private static string $last_raw = '';
    public static function last_raw(): string {
        return self::$last_raw;
    }
    public static function test(string $service, string $api_key): array {
        if (trim($api_key) === '') {
            return ['ok' => false, 'message' => __('API キーが入力されていません。', 'get-sns-reviews-api')];
        }
        return match ($service) {
            'zenrows'        => self::test_zenrows($api_key),
            'bright_data'    => self::test_bright_data($api_key),
            'apify'          => self::test_apify($api_key),
            'webscrapingapi' => self::test_webscrapingapi($api_key),
            'ensembledata'   => self::test_ensembledata($api_key),
            'scrape_do'      => self::test_scrape_do($api_key),
            default          => ['ok' => false, 'message' => __('未知のサービスです。', 'get-sns-reviews-api')],
        };
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
                    __('API キーが無効です（HTTP %d）。', 'get-sns-reviews-api'),
                    $status
                ),
            ];
        }
        if ($status === 400 || ($status >= 200 && $status < 300)) {
            return ['ok' => true, 'message' => __('API キーは有効です（無料枠は消費していません）。', 'get-sns-reviews-api')];
        }
        return self::unexpected_response($status, $body);
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
            return ['ok' => false, 'message' => __('API キーが無効です（HTTP 401 Invalid credentials）。', 'get-sns-reviews-api')];
        }
        if (($status === 404 && str_contains($body, 'Collector not found')) || ($status >= 200 && $status < 300)) {
            return ['ok' => true, 'message' => __('API キーは有効です（無料枠は消費していません）。', 'get-sns-reviews-api')];
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
            return ['ok' => true, 'message' => __('API キーは有効です（無料枠は消費していません）。', 'get-sns-reviews-api')];
        }
        if ($status === 401) {
            return ['ok' => false, 'message' => __('API キーが無効です（HTTP 401）。', 'get-sns-reviews-api')];
        }
        return self::unexpected_response($status, (string) wp_remote_retrieve_body($response));
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
                    __('API キーが無効です（HTTP %d）。', 'get-sns-reviews-api'),
                    $status
                ),
            ];
        }
        if ($status === 400 || ($status >= 200 && $status < 300)) {
            return ['ok' => true, 'message' => __('API キーは有効です（無料枠は消費していません）。', 'get-sns-reviews-api')];
        }
        return self::unexpected_response($status, $body);
    }
    private static function test_ensembledata(string $api_key): array {
        $endpoint = add_query_arg(
            [
                'date'  => gmdate('Y-m-d'),
                'token' => $api_key,
            ],
            self::ENSEMBLEDATA_USAGE_ENDPOINT
        );
        $response = wp_remote_get($endpoint, ['timeout' => self::TIMEOUT]);
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'message' => $error];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        if ($status === 200) {
            return ['ok' => true, 'message' => __('API キーは有効です（無料枠は消費していません）。', 'get-sns-reviews-api')];
        }
        if ($status === 401 || $status === 403 || $status === 491) {
            return [
                'ok'      => false,
                'message' => sprintf(
                    __('API キーが無効です（HTTP %d）。', 'get-sns-reviews-api'),
                    $status
                ),
            ];
        }
        return self::unexpected_response($status, $body);
    }
    private static function test_scrape_do(string $api_key): array {
        $endpoint = add_query_arg(['token' => $api_key], self::SCRAPEDO_INFO_ENDPOINT);
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
                    __('API キーが無効です（HTTP %d）。', 'get-sns-reviews-api'),
                    $status
                ),
            ];
        }
        if ($status !== 200) {
            return self::unexpected_response($status, $body);
        }
        $info = json_decode($body, true);
        if (is_array($info) && !empty($info['IsActive'])) {
            return [
                'ok'      => true,
                'message' => sprintf(
                    __('API キーは有効です（無料枠は消費していません。月間上限: %s リクエスト）。', 'get-sns-reviews-api'),
                    number_format_i18n((int) ($info['MaxMonthlyRequest'] ?? 0))
                ),
            ];
        }
        return ['ok' => false, 'message' => __('API キーが有効ではありません（アカウントが有効化されていません）。', 'get-sns-reviews-api')];
    }
    private static function transport_error($response): ?string {
        if (is_wp_error($response)) {
            self::$last_transport_fallback = self::is_transient_network_error($response);
            return sprintf(
                __('通信に失敗しました: %s', 'get-sns-reviews-api'),
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
                __('想定外の応答です（HTTP %1$d %2$s）。', 'get-sns-reviews-api'),
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
            return ['ok' => false, 'records' => [], 'message' => __('Apify の実行 ID を取得できませんでした。', 'get-sns-reviews-api')];
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
                    '[Get SNS Reviews API] Apify run %s: %s%s',
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
            return ['ok' => false, 'records' => [], 'message' => __('Apify の実行が完了しませんでした（実行中です）。時間をおいて再度お試しください。', 'get-sns-reviews-api')];
        }
        if ($dataset_id === '') {
            return ['ok' => false, 'records' => [], 'message' => __('Apify のデータセット ID を取得できませんでした。', 'get-sns-reviews-api')];
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
            return ['ok' => false, 'records' => [], 'message' => __('Apify の応答を解析できませんでした。', 'get-sns-reviews-api')];
        }
        return ['ok' => true, 'records' => $records, 'message' => ''];
    }
    private static function apify_error(int $status, string $body): array {
        $snippet = trim((string) preg_replace('/\s+/', ' ', $body));
        if (strlen($snippet) > 300) {
            $snippet = substr($snippet, 0, 300) . '…';
        }
        if ($snippet !== '') {
            error_log(sprintf('[Get SNS Reviews API] Apify API error HTTP %d: %s', $status, $snippet));
        }
        $decoded      = json_decode($body, true);
        $error_type   = (is_array($decoded) && isset($decoded['error']['type'])) ? (string) $decoded['error']['type'] : '';
        $approval_url = (is_array($decoded) && isset($decoded['error']['data']['approvalUrl'])) ? (string) $decoded['error']['data']['approvalUrl'] : '';
        if ($status === 403 && ($error_type === 'full-permission-actor-not-approved' || $approval_url !== '')) {
            $approval_message = $approval_url !== ''
                ? sprintf(
                    __('この Apify Actor は初回に「権限の承認」が必要です。次の URL をブラウザで開き【Approve（承認する）】ボタンを押して、この Actor にアカウントへのフルアクセス権限を許可してから再実行してください: %s', 'get-sns-reviews-api'),
                    $approval_url
                )
                : __('この Apify Actor は初回に「権限の承認」が必要です。Apify コンソールで【Approve（承認する）】を押して、アカウントへのフルアクセス権限を許可してから再実行してください。', 'get-sns-reviews-api');
            return array_merge(['ok' => false, 'records' => [], 'message' => $approval_message], self::failure_flags($status));
        }
        $message = match ($status) {
            401, 403 => $snippet !== ''
                ? sprintf(
                    __('Apify の認証・権限エラーです（HTTP %1$d）。API 側の応答: %2$s', 'get-sns-reviews-api'),
                    $status,
                    $snippet
                )
                : __('Apify の認証・権限エラーです（HTTP 401/403）。API キーの権限をご確認ください。', 'get-sns-reviews-api'),
            404      => __('Apify の Actor が見つかりません（HTTP 404）。', 'get-sns-reviews-api'),
            429      => __('リクエストが多すぎます（HTTP 429）。時間をおいて再度お試しください。', 'get-sns-reviews-api'),
            default  => self::unexpected_response($status, $body)['message'],
        };
        return array_merge(['ok' => false, 'records' => [], 'message' => \Tonkatsutei\Gachi_Review_AI\Api_Error::append($message, $body)], self::failure_flags($status));
    }
    private static function apify_failure_message(string $run_state, string $status_message): string {
        if ($status_message === '') {
            return sprintf(
                __('Apify の実行が失敗しました（%s）。', 'get-sns-reviews-api'),
                $run_state
            );
        }
        return sprintf(
            __('Apify の実行が失敗しました（%1$s: %2$s）。', 'get-sns-reviews-api'),
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
        error_log('[Get SNS Reviews API] Apify run log tail: ' . $tail);
    }
    public static function reviews(string $service, string $api_key, string $sns, string $keyword, array $args = []): array {
        @set_time_limit(300);
        self::$last_transport_fallback = false;
        self::$last_raw                = '';
        if (trim($api_key) === '') {
            return self::with_failure_flags(['ok' => false, 'records' => [], 'message' => __('API キーが入力されていません。', 'get-sns-reviews-api')]);
        }
        $result = match ($service) {
            'apify' => self::reviews_apify($api_key, $sns, $keyword, $args),
            'ensembledata' => self::reviews_ensembledata($api_key, $sns, $keyword, $args),
            'bright_data' => $sns === 'facebook'
                    ? self::reviews_facebook_pages($api_key, $keyword, $args)
                    : self::reviews_html_search($service, $api_key, $sns, $keyword, $args),
            'zenrows', 'webscrapingapi', 'scrape_do'
                    => self::reviews_html_search($service, $api_key, $sns, $keyword, $args),
            default => [
                'ok'      => false,
                'records' => [],
                'message' => __('このサービスでの SNS 投稿取得は未実装です。', 'get-sns-reviews-api'),
            ],
        };
        return self::with_failure_flags($result);
    }
    private static function reviews_apify(string $api_key, string $sns, string $keyword, array $args): array {
        $actor = self::APIFY_SNS_ACTORS[$sns] ?? '';
        if ($actor === '') {
            return ['ok' => false, 'records' => [], 'message' => __('この SNS は Apify での投稿取得に対応していません。', 'get-sns-reviews-api')];
        }
        $max = max(1, (int) ($args['max_reviews'] ?? self::REVIEWS_DEFAULT_MAX));
        $input = match ($sns) {
            'x'         => [
                'searchTerms' => [$keyword],
                'maxItems'    => max(self::APIFY_MIN_ITEMS, $max),
                'queryType'   => 'Latest',
            ],
            'instagram' => [
                'directUrls'   => ['https://www.instagram.com/explore/tags/' . rawurlencode(self::instagram_hashtag($keyword)) . '/'],
                'resultsType'  => 'posts',
                'resultsLimit' => $max,
            ],
            'facebook'  => [
                'query'        => $keyword,
                'resultsCount' => $max,
                'searchType'   => 'latest',
            ],
            default     => [],
        };
        $result = self::run_apify_actor($api_key, $actor, $input);
        if (empty($result['ok'])) {
            return $result;
        }
        $records = array_slice(array_values((array) ($result['records'] ?? [])), 0, $max);
        self::$last_raw = (string) wp_json_encode($records);
        return ['ok' => true, 'records' => $records, 'message' => ''];
    }
    private static function reviews_ensembledata(string $api_key, string $sns, string $keyword, array $args): array {
        if ($sns !== 'instagram') {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => __('EnsembleData は Instagram のみ対応しています。', 'get-sns-reviews-api'),
                'quota_exhausted' => false,
                'fallback'        => false,
            ];
        }
        $max = max(1, (int) ($args['max_reviews'] ?? self::REVIEWS_DEFAULT_MAX));
        $endpoint = self::ENSEMBLEDATA_ENDPOINT . '/instagram/hashtag/posts?' . http_build_query(
            [
                'name'  => self::instagram_hashtag($keyword),
                'token' => $api_key,
            ]
        );
        $response = wp_remote_get($endpoint, ['timeout' => self::HTML_TIMEOUT]);
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'records' => [], 'message' => $error, 'quota_exhausted' => false, 'fallback' => self::$last_transport_fallback];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        if ($status !== 200) {
            return array_merge(
                ['ok' => false, 'records' => [], 'message' => \Tonkatsutei\Gachi_Review_AI\Api_Error::append(self::ensembledata_error($status, $body), $body)],
                self::ensembledata_failure_flags($status)
            );
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            self::$last_raw = $body;
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => __('応答を解析できませんでした（JSON 形式ではありません）。', 'get-sns-reviews-api'),
                'quota_exhausted' => false,
                'fallback'        => false,
            ];
        }
        $records = self::ensembledata_posts($json);
        if ($records === []) {
            self::$last_raw = $body;
            error_log('[Get SNS Reviews API] EnsembleData: 応答に投稿が見つかりませんでした（tools/results/raw/ の生データを確認してください）');
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => __('投稿を取得できませんでした（応答に投稿が含まれていません）。', 'get-sns-reviews-api'),
                'quota_exhausted' => false,
                'fallback'        => true,
            ];
        }
        $records = array_slice($records, 0, $max);
        self::$last_raw = (string) wp_json_encode($records, JSON_UNESCAPED_UNICODE);
        return [
            'ok'              => true,
            'records'         => $records,
            'message'         => '',
            'quota_exhausted' => false,
            'fallback'        => false,
        ];
    }
    private static function ensembledata_posts(array $json): array {
        $data = $json['data'] ?? $json;
        if (!is_array($data) || $data === []) {
            return [];
        }
        $merged = [];
        $seen   = [];
        foreach (['top_posts', 'recent_posts'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                continue;
            }
            foreach ($data[$key] as $post) {
                if (!is_array($post)) {
                    continue;
                }
                if (isset($post['node']) && is_array($post['node'])) {
                    $post = $post['node'];
                }
                $id = (string) ($post['pk'] ?? $post['id'] ?? $post['code'] ?? '');
                if ($id !== '' && isset($seen[$id])) {
                    continue;
                }
                if ($id !== '') {
                    $seen[$id] = true;
                }
                $merged[] = $post;
            }
        }
        if ($merged !== []) {
            return $merged;
        }
        if (isset($data[0]) && is_array($data[0])) {
            return array_values(array_filter($data, 'is_array'));
        }
        foreach (['posts', 'items', 'results'] as $key) {
            if (isset($data[$key][0]) && is_array($data[$key][0])) {
                return array_values(array_filter($data[$key], 'is_array'));
            }
        }
        return [];
    }
    private static function ensembledata_error(int $status, string $body): string {
        if ($status === 401 || $status === 403 || $status === 491) {
            return sprintf(
                __('API キーが無効です（HTTP %d）。', 'get-sns-reviews-api'),
                $status
            );
        }
        if ($status === 493) {
            return __('EnsembleData の契約が有効ではありません（HTTP 493）。料金プランをご確認ください。', 'get-sns-reviews-api');
        }
        if ($status === 429 || $status === 495) {
            return __('本日の利用上限に達しました（EnsembleData）。無料枠は毎日 00:00 UTC にリセットされます。', 'get-sns-reviews-api');
        }
        if ($status === 400 || $status === 422) {
            return sprintf(
                __('入力が不正です（HTTP %1$d %2$s）。', 'get-sns-reviews-api'),
                $status,
                self::excerpt($body)
            );
        }
        return self::unexpected_response($status, $body)['message'];
    }
    private static function ensembledata_failure_flags(int $status): array {
        if ($status === 429 || $status === 495) {
            return ['quota_exhausted' => false, 'fallback' => true];
        }
        if ($status === 491 || $status === 493) {
            return ['quota_exhausted' => false, 'fallback' => true];
        }
        return self::failure_flags($status);
    }
    private static function webscrapingapi_fetch_html(string $api_key, string $url, bool $js_render = true): array {
        $params = [
            'api_key'   => $api_key,
            'url'       => $url,
            'render_js' => $js_render ? 1 : 0,
            'country'   => self::WEBSCRAPINGAPI_COUNTRY,
        ];
        $endpoint = self::WEBSCRAPINGAPI_ENDPOINT . '?' . http_build_query($params);
        $response = wp_remote_get($endpoint, [
            'timeout' => self::HTML_TIMEOUT,
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
            if ($body === '') {
                error_log(sprintf('[Get SNS Reviews API] WebScrapingAPI: 応答が空でした（URL: %s / render: %s）', $url, $js_render ? 'true' : 'false'));
                return [
                    'ok'              => false,
                    'html'            => '',
                    'message'         => sprintf(
                        __('HTML を取得できませんでした（HTTP 200・本文が空）。JS レンダリングの指定と URL をご確認ください: %s', 'get-sns-reviews-api'),
                        $url
                    ),
                    'quota_exhausted' => false,
                    'fallback'        => true,
                ];
            }
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
                __('API キーが無効です（HTTP %d）。', 'get-sns-reviews-api'),
                $status
            );
        }
        if ($status === 429) {
            return __('リクエストまたはレートの上限に達しました（HTTP 429）。時間をおいて再度お試しください。', 'get-sns-reviews-api');
        }
        if ($status === 404) {
            return __('対象ページを取得できませんでした（HTTP 404）。', 'get-sns-reviews-api');
        }
        return self::unexpected_response($status, $body)['message'];
    }
    private static function scrapedo_fetch_html(string $api_key, string $url, bool $js_render = true): array {
        $params = [
            'token' => $api_key,
            'url'   => $url,
        ];
        if ($js_render) {
            $params['render'] = 'true';
        }
        $endpoint = self::SCRAPEDO_ENDPOINT . '?' . http_build_query($params);
        $response = wp_remote_get($endpoint, [
            'timeout' => self::HTML_TIMEOUT,
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
            if ($body === '') {
                error_log(sprintf('[Get SNS Reviews API] Scrape.do: 応答が空でした（URL: %s / render: %s）', $url, $js_render ? 'true' : 'false'));
                return [
                    'ok'              => false,
                    'html'            => '',
                    'message'         => sprintf(
                        __('HTML を取得できませんでした（HTTP 200・本文が空）。JS レンダリングの指定と URL をご確認ください: %s', 'get-sns-reviews-api'),
                        $url
                    ),
                    'quota_exhausted' => false,
                    'fallback'        => true,
                ];
            }
            return ['ok' => true, 'html' => $body, 'message' => '', 'quota_exhausted' => false, 'fallback' => false];
        }
        return array_merge(
            ['ok' => false, 'html' => '', 'message' => \Tonkatsutei\Gachi_Review_AI\Api_Error::append(self::scrapedo_html_error($status, $body), $body)],
            self::scrapedo_failure_flags($status)
        );
    }
    private static function scrapedo_html_error(int $status, string $body): string {
        if ($status === 401 || $status === 403) {
            return sprintf(
                __('API キーが無効です（HTTP %d）。', 'get-sns-reviews-api'),
                $status
            );
        }
        if ($status === 402 || $status === 429) {
            return sprintf(
                __('クレジットまたはレートの上限に達しました（HTTP %d）。毎月の無料枠をご確認ください。', 'get-sns-reviews-api'),
                $status
            );
        }
        if ($status === 404 || $status === 410) {
            return sprintf(
                __('対象ページを取得できませんでした（HTTP %d）。', 'get-sns-reviews-api'),
                $status
            );
        }
        if ($status === 400) {
            return sprintf(
                __('リクエストが不正です（HTTP 400 %s）。対象 URL をご確認ください。', 'get-sns-reviews-api'),
                self::excerpt($body)
            );
        }
        if ($status >= 200 && $status < 300) {
            return sprintf(
                __('対象サイトが取得結果ではない応答を返しました（HTTP %d）。時間をおくか、別のサービスでお試しください。', 'get-sns-reviews-api'),
                $status
            );
        }
        return self::unexpected_response($status, $body)['message'];
    }
    private static function scrapedo_failure_flags(int $status): array {
        if ($status === 402 || $status === 429) {
            return ['quota_exhausted' => true, 'fallback' => false];
        }
        if ($status >= 200 && $status < 300) {
            return ['quota_exhausted' => false, 'fallback' => true];
        }
        return self::failure_flags($status);
    }
    private static function brightdata_dataset_fetch(string $api_key, string $dataset_id, array $input): array {
        $endpoint = add_query_arg(
            ['dataset_id' => $dataset_id, 'format' => 'json'],
            self::BRIGHT_DATA_DATASETS_ENDPOINT . 'scrape'
        );
        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => (string) wp_json_encode($input),
            'timeout' => self::HTML_TIMEOUT,
        ]);
        $error = self::transport_error($response);
        if ($error !== null) {
            return ['ok' => false, 'records' => [], 'message' => $error, 'quota_exhausted' => false, 'fallback' => self::$last_transport_fallback];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        if ($status === 200) {
            $records = json_decode($body, true);
            if (!is_array($records)) {
                return ['ok' => false, 'records' => [], 'message' => __('応答を解析できませんでした（JSON 形式ではありません）。', 'get-sns-reviews-api'), 'quota_exhausted' => false, 'fallback' => false];
            }
            return ['ok' => true, 'records' => $records, 'message' => '', 'quota_exhausted' => false, 'fallback' => false];
        }
        if ($status === 202) {
            return self::brightdata_dataset_snapshot($api_key, $body);
        }
        return array_merge(
            ['ok' => false, 'records' => [], 'message' => \Tonkatsutei\Gachi_Review_AI\Api_Error::append(self::brightdata_dataset_error($status, $body), $body)],
            self::failure_flags($status)
        );
    }
    private static function brightdata_dataset_snapshot(string $api_key, string $response_body): array {
        $decoded     = json_decode($response_body, true);
        $snapshot_id = (is_array($decoded) && isset($decoded['snapshot_id'])) ? (string) $decoded['snapshot_id'] : '';
        if ($snapshot_id === '') {
            return ['ok' => false, 'records' => [], 'message' => __('非同期ジョブの snapshot_id を取得できませんでした。', 'get-sns-reviews-api'), 'quota_exhausted' => false, 'fallback' => false];
        }
        $headers = ['Authorization' => 'Bearer ' . $api_key];
        $ready   = false;
        for ($attempt = 0; $attempt < self::BRIGHT_DATA_POLL_MAX_ATTEMPTS; $attempt++) {
            if ($attempt > 0) {
                sleep(self::BRIGHT_DATA_POLL_INTERVAL);
            }
            $progress_response = wp_remote_get(
                self::BRIGHT_DATA_SNAPSHOT_PROGRESS_ENDPOINT . rawurlencode($snapshot_id),
                ['headers' => $headers, 'timeout' => self::TIMEOUT]
            );
            $error = self::transport_error($progress_response);
            if ($error !== null) {
                return ['ok' => false, 'records' => [], 'message' => $error, 'quota_exhausted' => false, 'fallback' => self::$last_transport_fallback];
            }
            $progress = json_decode((string) wp_remote_retrieve_body($progress_response), true);
            $state    = (is_array($progress) && isset($progress['status'])) ? (string) $progress['status'] : '';
            if ($state === 'ready') {
                $ready = true;
                break;
            }
            if ($state === 'failed') {
                return ['ok' => false, 'records' => [], 'message' => __('Bright Data のジョブが失敗しました。', 'get-sns-reviews-api'), 'quota_exhausted' => false, 'fallback' => false];
            }
        }
        if (!$ready) {
            error_log(sprintf('[Get SNS Reviews API] Bright Data: snapshot が時間内に ready になりませんでした（%s）', $snapshot_id));
        }
        $items_response = wp_remote_get(
            add_query_arg(['format' => 'json'], self::BRIGHT_DATA_SNAPSHOT_DOWNLOAD_ENDPOINT . rawurlencode($snapshot_id)),
            ['headers' => $headers, 'timeout' => self::HTML_TIMEOUT]
        );
        $error = self::transport_error($items_response);
        if ($error !== null) {
            return ['ok' => false, 'records' => [], 'message' => $error, 'quota_exhausted' => false, 'fallback' => self::$last_transport_fallback];
        }
        $status     = (int) wp_remote_retrieve_response_code($items_response);
        $items_body = (string) wp_remote_retrieve_body($items_response);
        if ($status < 200 || $status >= 300) {
            return array_merge(
                [
                    'ok'      => false,
                    'records' => [],
                    'message' => \Tonkatsutei\Gachi_Review_AI\Api_Error::append(self::brightdata_dataset_error($status, $items_body), $items_body),
                ],
                self::failure_flags($status)
            );
        }
        $records = json_decode((string) wp_remote_retrieve_body($items_response), true);
        if (!is_array($records)) {
            return ['ok' => false, 'records' => [], 'message' => __('応答を解析できませんでした。', 'get-sns-reviews-api'), 'quota_exhausted' => false, 'fallback' => false];
        }
        return ['ok' => true, 'records' => $records, 'message' => '', 'quota_exhausted' => false, 'fallback' => false];
    }
    private static function brightdata_dataset_error(int $status, string $body): string {
        if ($status === 401 || $status === 403) {
            return __('API キーが無効、またはこの dataset を実行する権限がありません（Bright Data）。', 'get-sns-reviews-api');
        }
        if ($status === 400) {
            return sprintf(
                __('Bright Data の入力が不正です（HTTP 400 %s）。', 'get-sns-reviews-api'),
                self::excerpt($body)
            );
        }
        if ($status === 404) {
            return __('Bright Data の dataset が見つかりません（HTTP 404）。', 'get-sns-reviews-api');
        }
        if ($status === 429) {
            return __('Bright Data の上限に達しました（HTTP 429）。時間をおいて再度お試しください。', 'get-sns-reviews-api');
        }
        return self::unexpected_response($status, $body)['message'];
    }
    private static function zenrows_fetch_html(string $api_key, string $url, bool $js_render = true): array {
        $params = [
            'apikey'         => $api_key,
            'url'            => $url,
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
            'timeout' => self::HTML_TIMEOUT,
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
            if ($body === '') {
                error_log(sprintf('[Get SNS Reviews API] ZenRows: 応答が空でした（URL: %s / render: %s）', $url, $js_render ? 'true' : 'false'));
                return [
                    'ok'              => false,
                    'html'            => '',
                    'message'         => sprintf(
                        __('HTML を取得できませんでした（HTTP 200・本文が空）。JS レンダリングの指定と URL をご確認ください: %s', 'get-sns-reviews-api'),
                        $url
                    ),
                    'quota_exhausted' => false,
                    'fallback'        => true,
                ];
            }
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
                __('API キーが無効です（HTTP %d）。', 'get-sns-reviews-api'),
                $status
            );
        }
        if ($status === 429) {
            return __('クレジットまたはレートの上限に達しました（HTTP 429）。時間をおいて再度お試しください。', 'get-sns-reviews-api');
        }
        if ($status === 404) {
            return __('対象ページを取得できませんでした（HTTP 404）。', 'get-sns-reviews-api');
        }
        if ($status === 400 && str_contains($body, 'REQS001')) {
            return __('この URL は ZenRows では取得できません（アクセス制限）。別のサービスで取得してください。', 'get-sns-reviews-api');
        }
        if (str_contains($body, 'RESP001')) {
            return __('このページの内容を取得できませんでした（RESP001）。別のサービスで取得してください。', 'get-sns-reviews-api');
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
    private static function brightdata_fetch_html(string $api_key, string $url): array {
        $payload = [
            'zone'   => self::brightdata_unlocker_zone($api_key),
            'url'    => $url,
            'format' => 'raw',
        ];
        $response = wp_remote_post(self::BRIGHT_DATA_UNLOCKER_ENDPOINT, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => (string) wp_json_encode($payload),
            'timeout' => self::HTML_TIMEOUT,
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
                $html = (string) $decoded['body'];
            } else {
                $html = $body;
            }
            if ($html === '') {
                error_log(sprintf('[Get SNS Reviews API] Bright Data Unlocker: 応答が空でした（URL: %s / render: %s）', $url, $js_render ? 'true' : 'false'));
                return [
                    'ok'              => false,
                    'html'            => '',
                    'message'         => sprintf(
                        __('HTML を取得できませんでした（HTTP 200・本文が空）。JS レンダリングの指定と URL をご確認ください: %s', 'get-sns-reviews-api'),
                        $url
                    ),
                    'quota_exhausted' => false,
                    'fallback'        => true,
                ];
            }
            return ['ok' => true, 'html' => $html, 'message' => '', 'quota_exhausted' => false, 'fallback' => false];
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
            return __('API キーが無効です（Web Unlocker API の認証エラー）。', 'get-sns-reviews-api');
        }
        if ($status === 400) {
            return __('Web Unlocker のリクエストが不正です（HTTP 400。zone 名・URL を確認してください）。', 'get-sns-reviews-api');
        }
        return self::unexpected_response($status, $body)['message'];
    }
    private static function instagram_hashtag(string $keyword): string {
        $tag = ltrim(trim($keyword), '#');
        return (string) preg_replace('/[\s\x{3000}]+/u', '', $tag);
    }
    private static function serp_urls_cache_key(string $sns, string $keyword, int $limit): string {
        return self::SERP_URLS_CACHE_PREFIX . md5($sns . '|' . $limit . '|' . $keyword);
    }
    private static function serp_urls_cached(string $sns, string $keyword, int $limit): array {
        $cached = get_transient(self::serp_urls_cache_key($sns, $keyword, $limit));
        if (!is_array($cached)) {
            return [];
        }
        $urls = [];
        foreach ($cached as $url) {
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }
        return $urls;
    }
    private static function serp_urls_store(string $sns, string $keyword, int $limit, array $urls): void {
        $urls = array_slice(array_values(array_unique($urls)), 0, self::SERP_URLS_CACHE_MAX);
        if ($urls === []) {
            return;
        }
        set_transient(self::serp_urls_cache_key($sns, $keyword, $limit), $urls, self::SERP_URLS_CACHE_TTL);
    }
    private static function serp_queries(string $sns, string $keyword): array {
        $domain = self::SERP_DOMAINS[$sns] ?? '';
        if ($domain === '') {
            return [];
        }
        $scopes = $sns === 'instagram' ? ['/p/', '/reel/'] : [''];
        $queries = [];
        foreach ($scopes as $scope) {
            $queries[] = 'site:' . $domain . $scope . ' ' . $keyword;
        }
        return $queries;
    }
    private static function serp_post_urls(string $html, string $sns, int $max): array {
        if ($html === '' || $max <= 0) {
            return [];
        }
        $candidates = [];
        if (preg_match_all('/uddg=([^&"\']+)/', $html, $matches) > 0) {
            foreach ($matches[1] as $encoded) {
                $candidates[] = rawurldecode($encoded);
            }
        }
        $domain = self::SERP_DOMAINS[$sns] ?? '';
        if ($domain !== '' && preg_match_all('#https?://(?:[a-z0-9-]+\.)?' . preg_quote($domain, '#') . '/[^\s"\'<>\\\\]+#i', $html, $direct) > 0) {
            foreach ($direct[0] as $url) {
                $candidates[] = $url;
            }
        }
        $posts = [];
        foreach ($candidates as $candidate) {
            $url = self::normalize_post_url($sns, $candidate);
            if ($url === '' || in_array($url, $posts, true)) {
                continue;
            }
            $posts[] = $url;
            if (count($posts) >= $max) {
                break;
            }
        }
        return $posts;
    }
    private static function normalize_post_url(string $sns, string $url): string {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || stripos($url, 'http') !== 0) {
            return '';
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $host = strtolower((string) $parts['host']);
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($sns === 'x') {
            if (!str_contains($host, 'x.com') && !str_contains($host, 'twitter.com')) {
                return '';
            }
            if (preg_match('#^/[^/]+/status/\d+$#', $path) !== 1) {
                return '';
            }
            return 'https://x.com' . $path;
        }
        if ($sns === 'instagram') {
            if (!str_contains($host, 'instagram.com')) {
                return '';
            }
            if (preg_match('#^/(?:p|reel|tv)/[A-Za-z0-9_-]+$#', $path) !== 1) {
                return '';
            }
            return 'https://www.instagram.com' . $path . '/';
        }
        if ($sns === 'facebook') {
            if (!str_contains($host, 'facebook.com') && !str_contains($host, 'fb.com')) {
                return '';
            }
            if (
                preg_match('#^/[^/]+/(?:posts|videos)/#', $path) !== 1
                && !str_contains($path, 'permalink.php')
                && !str_contains($path, 'story.php')
            ) {
                return '';
            }
            return 'https://www.facebook.com' . $path;
        }
        return '';
    }
    private static function numeric_from_text(string $text): float {
        $text = str_replace([',', '，', ' '], '', trim($text));
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)([KkMm])?$/', $text, $match) !== 1) {
            return 0.0;
        }
        $value  = (float) $match[1];
        $suffix = strtoupper((string) ($match[2] ?? ''));
        if ($suffix === 'K') {
            $value *= 1000;
        } elseif ($suffix === 'M') {
            $value *= 1000000;
        }
        return $value;
    }
    private static function meta_content(string $html, string $property): string {
        $pattern = '#<meta[^>]+(?:property|name)=["\']' . preg_quote($property, '#') . '["\'][^>]*>#i';
        if (preg_match($pattern, $html, $tag) !== 1) {
            return '';
        }
        if (preg_match('#content=["\']([^"\']*)["\']#i', $tag[0], $content) !== 1) {
            return '';
        }
        return trim(html_entity_decode($content[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    private static function unique_json_value(string $html, string $pattern): string {
        $count = preg_match_all($pattern, $html, $matches);
        if ($count !== 1) {
            return '';
        }
        return (string) ($matches[1][0] ?? '');
    }
    private static function parse_post_page(string $sns, string $html, string $url): array {
        $og_description = self::meta_content($html, 'og:description');
        $og_title       = self::meta_content($html, 'og:title');
        $record = [
            'url'         => $url,
            'description' => $og_description,
            'name'        => '',
            'username'    => '',
            'date_posted' => '',
            'likes'       => 0,
            'replies'     => 0,
            'reposts'     => 0,
            'is_verified' => false,
        ];
        if ($sns === 'x') {
            if (preg_match('/^(.*?)\s*\(@([A-Za-z0-9_]+)\)/u', $og_title, $match) === 1) {
                $record['name']     = trim($match[1]);
                $record['username'] = $match[2];
            } elseif ($og_title !== '') {
                $record['name'] = $og_title;
            }
            if (preg_match('#/status/(\d+)#', $url, $match) === 1) {
                $record['id'] = $match[1];
            }
            $record['date_posted'] = self::unique_json_value($html, '/created_at_ms:(\d+)/');
            $record['likes']       = (int) self::unique_json_value($html, '/favorite_count:(\d+)/');
            $record['replies']     = (int) self::unique_json_value($html, '/reply_count:(\d+)/');
            $record['reposts']     = (int) self::unique_json_value($html, '/retweet_count:(\d+)/');
            return $record;
        }
        if ($sns === 'instagram') {
            if (preg_match('/["“](.*)["”]\s*\.?\s*$/us', $og_title, $match) === 1) {
                $record['description'] = trim($match[1]);
            } elseif (preg_match('/["“](.*)["”]\s*\.?\s*$/us', $og_description, $match) === 1) {
                $record['description'] = trim($match[1]);
            }
            $name = (string) preg_replace('/["“].*$/us', '', $og_title);
            $name = (string) preg_replace('/^\s*Instagram\s+\S*\s*/us', '', $name);
            $name = (string) preg_replace('/\s*\S*\s*Instagram\s*:?\s*$/us', '', $name);
            $record['name'] = trim((string) preg_replace('/[\s:：]+$/u', '', $name));
            if (preg_match('/^([\d.,]+[KkMm]?)\s+likes?,\s*([\d.,]+)\s+comments?\s*-\s*([A-Za-z0-9_.]+)\s+on\s+(.+?):/u', $og_description, $match) === 1) {
                $record['likes']       = (int) self::numeric_from_text($match[1]);
                $record['replies']     = (int) self::numeric_from_text($match[2]);
                $record['username']    = $match[3];
                $record['date_posted'] = trim($match[4]);
            } elseif (preg_match('/^([A-Za-z0-9_.]+)\s+on\s+(.+?):/u', $og_description, $match) === 1) {
                $record['username']    = $match[1];
                $record['date_posted'] = trim($match[2]);
            } elseif (preg_match('/^(.+?)[、,]\s*([A-Za-z0-9_.]*[A-Za-z_][A-Za-z0-9_.]*):/u', $og_description, $match) === 1) {
                $record['date_posted'] = trim($match[1]);
                $record['username']    = $match[2];
            }
            if ($record['likes'] <= 0) {
                $record['likes'] = (int) self::unique_json_value($html, '/"like_count":(\d+)/');
            }
            if ($record['replies'] <= 0) {
                $record['replies'] = (int) self::unique_json_value($html, '/"comment_count":(\d+)/');
            }
            if ($record['date_posted'] === '') {
                $record['date_posted'] = self::unique_json_value($html, '/"taken_at":(\d+)/');
            }
            if (preg_match('#/(?:p|reel|tv)/([A-Za-z0-9_-]+)#', $url, $match) === 1) {
                $record['id'] = $match[1];
            }
            $record['likes']   = (int) self::unique_json_value($html, '/"like_count":(\d+)/');
            $record['replies'] = (int) self::unique_json_value($html, '/"comment_count":(\d+)/');
            return $record;
        }
        if ($og_title !== '') {
            $record['name'] = trim((string) preg_replace('/\s*\|\s*Facebook$/u', '', $og_title));
        }
        return $record;
    }
    private static function reviews_html_search(string $service, string $api_key, string $sns, string $keyword, array $args): array {
        $queries = self::serp_queries($sns, $keyword);
        if ($queries === []) {
            return ['ok' => false, 'records' => [], 'message' => __('この SNS は検索エンジン経由の取得に対応していません。', 'get-sns-reviews-api')];
        }
        $max = max(1, (int) ($args['max_reviews'] ?? self::REVIEWS_DEFAULT_MAX));
        $no_cache   = !empty($args['no_cache']);
        $post_urls  = $no_cache ? [] : self::serp_urls_cached($sns, $keyword, $max);
        $last_error = '';
        $endpoints = $post_urls === [] ? self::SERP_ENDPOINTS : [];
        foreach ($endpoints as $endpoint) {
            foreach ($queries as $query) {
                $search_url = $endpoint . '?' . http_build_query([
                    'q'  => $query,
                    'kl' => 'jp-jp',
                ]);
                $serp = self::fetch_html_for($service, $api_key, $search_url, false);
                if (empty($serp['ok'])) {
                    if (!empty($serp['quota_exhausted'])) {
                        return [
                            'ok'              => false,
                            'records'         => [],
                            'message'         => (string) ($serp['message'] ?? ''),
                            'quota_exhausted' => true,
                            'fallback'        => false,
                        ];
                    }
                    $last_error = (string) ($serp['message'] ?? '');
                    continue;
                }
                self::$last_raw = (string) $serp['html'];
                foreach (self::serp_post_urls((string) $serp['html'], $sns, $max) as $url) {
                    if (!in_array($url, $post_urls, true)) {
                        $post_urls[] = $url;
                    }
                }
                if (count($post_urls) >= $max) {
                    break 2;
                }
            }
            if ($post_urls !== []) {
                break;
            }
        }
        if ($post_urls !== []) {
            self::serp_urls_store($sns, $keyword, $max, $post_urls);
        }
        $urls = array_slice($post_urls, 0, $max);
        if ($urls === []) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => $last_error !== ''
                    ? $last_error
                    : sprintf(
                        __('検索エンジンで投稿ページが見つかりませんでした（%s）。キーワードを変えてお試しください。', 'get-sns-reviews-api'),
                        $sns
                    ),
                'quota_exhausted' => false,
                'fallback'        => false,
            ];
        }
        $records = [];
        foreach ($urls as $url) {
            $page = self::fetch_html_for($service, $api_key, $url, false);
            if (empty($page['ok'])) {
                if (!empty($page['quota_exhausted'])) {
                    return [
                        'ok'              => false,
                        'records'         => [],
                        'message'         => (string) ($page['message'] ?? ''),
                        'quota_exhausted' => true,
                        'fallback'        => false,
                    ];
                }
                continue;
            }
            $records[] = self::parse_post_page($sns, (string) $page['html'], $url);
        }
        self::$last_raw = (string) wp_json_encode($records, JSON_UNESCAPED_UNICODE);
        return ['ok' => true, 'records' => $records, 'message' => ''];
    }
    private static function facebook_page_urls(string $html, int $max): array {
        if ($html === '' || $max <= 0) {
            return [];
        }
        $candidates = [];
        if (preg_match_all('/uddg=([^&"\']+)/', $html, $matches) > 0) {
            foreach ($matches[1] as $encoded) {
                $candidates[] = rawurldecode($encoded);
            }
        }
        if (preg_match_all('#https?://(?:[a-z0-9-]+\.)?facebook\.com/[^\s"\'<>\\\\]+#i', $html, $direct) > 0) {
            foreach ($direct[0] as $url) {
                $candidates[] = $url;
            }
        }
        $reserved = [
            'login', 'search', 'policies', 'help', 'watch', 'marketplace', 'groups', 'events',
            'photo', 'video', 'reel', 'permalink.php', 'profile.php', 'story.php', 'messages',
        ];
        $pages = [];
        foreach ($candidates as $candidate) {
            $url = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($url === '' || stripos($url, 'http') !== 0) {
                continue;
            }
            $parts = wp_parse_url($url);
            if (!is_array($parts) || empty($parts['host'])) {
                continue;
            }
            $host = strtolower((string) $parts['host']);
            if (!str_contains($host, 'facebook.com') && !str_contains($host, 'fb.com')) {
                continue;
            }
            $path = trim((string) ($parts['path'] ?? ''), '/');
            if ($path === '' || str_contains($path, '/')) {
                continue;
            }
            if (in_array(strtolower($path), $reserved, true)) {
                continue;
            }
            $page = 'https://www.facebook.com/' . $path . '/';
            if (in_array($page, $pages, true)) {
                continue;
            }
            $pages[] = $page;
            if (count($pages) >= $max) {
                break;
            }
        }
        return $pages;
    }
    private static function contains_keyword(string $text, string $keyword): bool {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return false;
        }
        $normalize = static function (string $value): string {
            return mb_strtolower((string) preg_replace('/[\s\x{3000}]+/u', '', $value));
        };
        return str_contains($normalize($text), $normalize($keyword));
    }
    private static function reviews_facebook_pages(string $api_key, string $keyword, array $args): array {
        $max = max(1, (int) ($args['max_reviews'] ?? self::REVIEWS_DEFAULT_MAX));
        $no_cache = !empty($args['no_cache']);
        $pages    = $no_cache ? [] : self::serp_urls_cached('facebook', $keyword, self::FACEBOOK_MAX_PAGES);
        if ($pages === []) {
            $serp = [];
            foreach (self::SERP_ENDPOINTS as $endpoint) {
                $search_url = $endpoint . '?' . http_build_query([
                    'q'  => 'site:facebook.com ' . $keyword,
                    'kl' => 'jp-jp',
                ]);
                $serp = self::brightdata_fetch_html($api_key, $search_url);
                if (!empty($serp['ok']) || !empty($serp['quota_exhausted'])) {
                    break;
                }
            }
            if (empty($serp['ok'])) {
                return [
                    'ok'              => false,
                    'records'         => [],
                    'message'         => (string) ($serp['message'] ?? ''),
                    'quota_exhausted' => (bool) ($serp['quota_exhausted'] ?? false),
                    'fallback'        => (bool) ($serp['fallback'] ?? false),
                ];
            }
            self::$last_raw = (string) $serp['html'];
            $pages = self::facebook_page_urls((string) $serp['html'], self::FACEBOOK_MAX_PAGES);
            self::serp_urls_store('facebook', $keyword, self::FACEBOOK_MAX_PAGES, $pages);
        }
        if ($pages === []) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => __('検索エンジンで Facebook のページが見つかりませんでした。キーワードを変えてお試しください。', 'get-sns-reviews-api'),
                'quota_exhausted' => false,
                'fallback'        => true,
            ];
        }
        $input = [];
        foreach ($pages as $page) {
            $input[] = ['url' => $page, 'num_of_posts' => self::FACEBOOK_POSTS_PER_PAGE];
        }
        $fetched = self::brightdata_dataset_fetch($api_key, self::BRIGHT_DATA_FB_PAGES_DATASET, $input);
        if (empty($fetched['ok'])) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => (string) ($fetched['message'] ?? ''),
                'quota_exhausted' => (bool) ($fetched['quota_exhausted'] ?? false),
                'fallback'        => (bool) ($fetched['fallback'] ?? false),
            ];
        }
        $all = (array) ($fetched['records'] ?? []);
        self::$last_raw = (string) wp_json_encode($all, JSON_UNESCAPED_UNICODE);
        $matched = [];
        foreach ($all as $record) {
            if (!is_array($record)) {
                continue;
            }
            $content = isset($record['content']) ? (string) $record['content'] : '';
            if ($content === '' || !self::contains_keyword($content, $keyword)) {
                continue;
            }
            $matched[] = $record;
            if (count($matched) >= $max) {
                break;
            }
        }
        if ($matched === []) {
            return [
                'ok'              => false,
                'records'         => [],
                'message'         => sprintf(
                    __('Facebook のページ投稿を %1$d 件取得しましたが、キーワード「%2$s」を含む投稿はありませんでした。', 'get-sns-reviews-api'),
                    count($all),
                    $keyword
                ),
                'quota_exhausted' => false,
                'fallback'        => true,
            ];
        }
        return ['ok' => true, 'records' => $matched, 'message' => ''];
    }
    private static function sns_posts_url(string $sns, string $keyword): string {
        return match ($sns) {
            'x'         => 'https://x.com/search?q=' . rawurlencode($keyword) . '&src=typed_query&f=live',
            'instagram' => 'https://www.instagram.com/explore/tags/' . rawurlencode(self::instagram_hashtag($keyword)) . '/',
            'facebook'  => 'https://www.facebook.com/search/posts/?q=' . rawurlencode($keyword),
            default     => '',
        };
    }
    private static function fetch_html_for(string $service, string $api_key, string $url, bool $js_render = true): array {
        return match ($service) {
            'bright_data' => self::brightdata_fetch_html($api_key, $url),
            'zenrows'     => self::zenrows_fetch_html($api_key, $url, $js_render),
            'webscrapingapi' => self::webscrapingapi_fetch_html($api_key, $url, $js_render),
            'scrape_do'   => self::scrapedo_fetch_html($api_key, $url, $js_render),
            default       => [
                'ok'              => false,
                'html'            => '',
                'message'         => __('このサービスの HTML 取得は未実装です。', 'get-sns-reviews-api'),
                'quota_exhausted' => false,
                'fallback'        => false,
            ],
        };
    }
}
