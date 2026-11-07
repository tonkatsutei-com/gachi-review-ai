<?php
declare(strict_types=1);
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Scraping\Service_Catalog;
use Tonkatsutei\Gachi_Review_AI\Scraping\Store;
use Tonkatsutei\Gachi_Review_AI\Settings;
if (!defined('ABSPATH')) {
    exit;
}
if (class_exists('Get_Ec_Reviews_API')) {
    return;
}
class Get_Ec_Reviews_API {
    public const CACHE_PREFIX = 'get_ec_reviews_api_';
    public const DEFAULT_CACHE_HOURS = 24;
    private static ?array $services = null;
    private static ?array $sites = null;
    private static string $last_reviews_error = '';
    private static string $last_reviews_site = '';
    private static bool $last_reviews_from_cache = false;
    public static function sites(): array {
        if (self::$sites !== null) {
            return self::$sites;
        }
        self::$sites = [
            'rakuten' => [
                'label'        => __('楽天市場', 'get-ec-reviews-api'),
                'code_label'   => 'itemCode',
                'code_example' => 'shopCode:itemId',
                'code_help'    => __('楽天市場の商品コード（例: 1234567:1234567890。「shopCode:itemId」の形式。型番などのキーワードでも検索します）。', 'get-ec-reviews-api'),
            ],
            'amazon'  => [
                'label'        => 'Amazon',
                'code_label'   => 'ASIN',
                'code_example' => 'B0XXXXXXXX',
                'code_help'    => __('Amazon の ASIN（10 桁の英数字。例: B0XXXXXXXX）。', 'get-ec-reviews-api'),
            ],
            'yahoo'   => [
                'label'        => __('Yahoo!ショッピング', 'get-ec-reviews-api'),
                'code_label'   => 'item_code',
                'code_example' => 'hmv_2737935',
                'code_help'    => __('Yahoo!ショッピングの商品コード（商品検索の「Code」。例: marbleshop_2915-001200）。ストア商品コード単体（例: 2915-001200）でも商品検索で解決します。', 'get-ec-reviews-api'),
            ],
        ];
        return self::$sites;
    }
    public static function site_ids(): array {
        return array_keys(self::sites());
    }
    public static function site(string $site): array {
        return self::sites()[$site] ?? [];
    }
    public static function services_for_site(string $site): array {
        if (!array_key_exists($site, self::sites())) {
            return [];
        }
        return self::service_ids();
    }
    public static function service_supports(string $service, string $site): bool {
        return in_array($service, self::services_for_site($site), true);
    }
    private static function is_valid_code(string $site, string $code): bool {
        if (!array_key_exists($site, self::sites())) {
            return false;
        }
        if ($code === '') {
            return false;
        }
        if ($site === 'amazon') {
            return preg_match('/^[A-Z0-9]{10}$/', $code) === 1;
        }
        if ($site === 'rakuten') {
            return true;
        }
        if ($site === 'yahoo') {
            return true;
        }
        return true;
    }
    public static function services(): array {
        if (self::$services !== null) {
            return self::$services;
        }
        self::$services = [];
        foreach (Service_Catalog::all() as $id => $definition) {
            if (!in_array(Service_Catalog::EC, $definition['used_by'], true)) {
                continue;
            }
            self::$services[$id] = [
                'label'     => $definition['label'],
                'reset_day' => 1,
            ];
        }
        return self::$services;
    }
    public static function service_ids(): array {
        return array_keys(self::services());
    }
    private static function reviews_cache_key(string $service, string $site, string $code, array $args): string {
        $max = max(1, (int) ($args['max_reviews'] ?? Get_Ec_Reviews_API_Client::REVIEWS_DEFAULT_MAX));
        return self::CACHE_PREFIX . 'reviews_' . $service . '_' . md5($site . '|' . $max . '|' . $code);
    }
    private static function reviews_cache_hit(string $site, string $code, array $args): array|false {
        foreach (self::services_for_site($site) as $service) {
            $key    = self::reviews_cache_key($service, $site, $code, $args);
            $cached = get_transient($key);
            if (!is_array($cached)) {
                continue;
            }
            if (!self::has_text_review($cached)) {
                delete_transient($key);
                continue;
            }
            return $cached;
        }
        return false;
    }
    private static function has_text_review(array $reviews): bool {
        foreach ($reviews as $review) {
            if (is_array($review) && trim((string) ($review['review_text'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }
    private static function cache_ttl(): int {
        return self::DEFAULT_CACHE_HOURS * HOUR_IN_SECONDS;
    }
    public static function reviews(string $site, string $code, array $args = []): array {
        self::$last_reviews_error      = '';
        self::$last_reviews_site       = $site;
        self::$last_reviews_from_cache = false;
        $site = strtolower(trim($site));
        $code = trim($code);
        if (!array_key_exists($site, self::sites())) {
            self::$last_reviews_error = __('未知のサイトです（rakuten / amazon / yahoo のいずれかを指定してください）。', 'get-ec-reviews-api');
            return [];
        }
        if ($site === 'amazon') {
            $code = strtoupper($code);
        }
        if (!self::is_valid_code($site, $code)) {
            self::$last_reviews_error = self::invalid_code_message($site);
            return [];
        }
        if (!self::is_site_api_usable($site)) {
            self::$last_reviews_error = self::api_unusable_message($site);
            return [];
        }
        $cached = empty($args['no_cache']) ? self::reviews_cache_hit($site, $code, $args) : false;
        if (is_array($cached)) {
            self::$last_reviews_from_cache = true;
            return $cached;
        }
        $services = self::services_for_site($site);
        shuffle($services);
        $without_text = [];
        $attempted    = false;
        foreach ($services as $service) {
            if (!self::is_usable($service)) {
                continue;
            }
            $outcome   = self::reviews_once($service, $site, $code, $args);
            $attempted = true;
            if ($outcome['ok']) {
                if (self::has_text_review($outcome['reviews'])) {
                    set_transient(self::reviews_cache_key($service, $site, $code, $args), $outcome['reviews'], self::cache_ttl());
                    return $outcome['reviews'];
                }
                if ($outcome['reviews'] !== []) {
                    $without_text = $outcome['reviews'];
                }
                continue;
            }
            if ($outcome['quota_exhausted'] || $outcome['fallback']) {
                continue;
            }
            return [];
        }
        if ($without_text !== []) {
            return $without_text;
        }
        if (!$attempted && self::$last_reviews_error === '') {
            self::$last_reviews_error = __('利用可能なサービスがありません（API キーの保存と通信テストの成功が必要です）。', 'get-ec-reviews-api');
            error_log('[Get EC Reviews API] reviews(): ' . self::$last_reviews_error);
        }
        return [];
    }
    public static function reviews_with(string $service, string $site, string $code, array $args = []): array {
        self::$last_reviews_error      = '';
        self::$last_reviews_site       = $site;
        self::$last_reviews_from_cache = false;
        $site = strtolower(trim($site));
        $code = trim($code);
        if (!array_key_exists($site, self::sites())) {
            self::$last_reviews_error = __('未知のサイトです（rakuten / amazon / yahoo のいずれかを指定してください）。', 'get-ec-reviews-api');
            return [];
        }
        if ($site === 'amazon') {
            $code = strtoupper($code);
        }
        if (!self::is_valid_code($site, $code)) {
            self::$last_reviews_error = self::invalid_code_message($site);
            return [];
        }
        if (!self::is_site_api_usable($site)) {
            self::$last_reviews_error = self::api_unusable_message($site);
            return [];
        }
        if (!in_array($service, self::services_for_site($site), true)) {
            self::$last_reviews_error = __('このサービスは指定サイトのレビュー取得に対応していません。', 'get-ec-reviews-api');
            return [];
        }
        $cached = empty($args['no_cache']) ? self::reviews_cache_hit($site, $code, $args) : false;
        if (is_array($cached)) {
            self::$last_reviews_from_cache = true;
            return $cached;
        }
        if (!self::is_usable($service)) {
            self::$last_reviews_error = __('このサービスは利用できません（API キーの保存と通信テストの成功が必要です）。', 'get-ec-reviews-api');
            error_log(sprintf('[Get EC Reviews API] reviews_with() unavailable (%s): %s', $service, self::$last_reviews_error));
            return [];
        }
        $outcome = self::reviews_once($service, $site, $code, $args);
        if ($outcome['ok'] && self::has_text_review($outcome['reviews'])) {
            set_transient(self::reviews_cache_key($service, $site, $code, $args), $outcome['reviews'], self::cache_ttl());
        }
        return $outcome['reviews'];
    }
    private static function reviews_once(string $service, string $site, string $code, array $args): array {
        $state   = self::get_service($service);
        $api_key = isset($state['api_key']) ? (string) $state['api_key'] : '';
        $ec_keys = self::ec_api_keys_for_site($site);
        $result  = Get_Ec_Reviews_API_Client::reviews($service, $api_key, $site, $code, $args, $ec_keys);
        if (!$result['ok']) {
            self::$last_reviews_error = (string) $result['message'];
            if (!empty($result['quota_exhausted'])) {
                self::mark_quota_exhausted($service);
                error_log(sprintf('[Get EC Reviews API] reviews() quota exhausted (%s): %s', $service, self::$last_reviews_error));
                return ['ok' => false, 'reviews' => [], 'quota_exhausted' => true, 'fallback' => false];
            }
            error_log(sprintf('[Get EC Reviews API] reviews() failed (%s): %s', $service, self::$last_reviews_error));
            return ['ok' => false, 'reviews' => [], 'quota_exhausted' => false, 'fallback' => !empty($result['fallback'])];
        }
        return [
            'ok'              => true,
            'reviews'         => self::normalize_reviews_records($service, $site, $code, $result['records']),
            'quota_exhausted' => false,
            'fallback'        => false,
        ];
    }
    public static function last_reviews_error(): string {
        return self::$last_reviews_error;
    }
    public static function last_reviews_site(): string {
        return self::$last_reviews_site;
    }
    public static function last_reviews_from_cache(): bool {
        return self::$last_reviews_from_cache;
    }
    public static function is_usable(string $service): bool {
        $state = self::get_service($service);
        if ($state === [] || ($state['api_key'] ?? '') === '' || ($state['status'] ?? '') !== 'ok') {
            return false;
        }
        if (!empty($state['quota_exhausted'])) {
            if (!self::quota_exhausted_expired($state)) {
                return false;
            }
            self::clear_quota_exhausted($service);
        }
        return true;
    }
    public static function is_quota_exhausted(string $service): bool {
        $state = self::get_service($service);
        if (empty($state['quota_exhausted'])) {
            return false;
        }
        return !self::quota_exhausted_expired($state);
    }
    private static function mark_quota_exhausted(string $service): void {
        self::save_service($service, [
            'quota_exhausted'    => true,
            'quota_exhausted_at' => current_time('mysql'),
        ]);
    }
    private static function quota_exhausted_expired(array $state): bool {
        $exhausted_at = isset($state['quota_exhausted_at']) ? (string) $state['quota_exhausted_at'] : '';
        if ($exhausted_at === '') {
            return false;
        }
        try {
            $exhausted = new \DateTimeImmutable($exhausted_at, wp_timezone());
        } catch (\Exception $e) {
            return false;
        }
        $reset_day = isset($state['reset_day']) ? (int) $state['reset_day'] : 1;
        return current_datetime() >= self::next_reset_after($exhausted, $reset_day);
    }
    public static function get_service(string $service): array {
        $state              = Store::get($service);
        $services           = self::services();
        $state['reset_day'] = isset($services[$service]['reset_day']) ? (int) $services[$service]['reset_day'] : 1;
        return $state;
    }
    public static function save_service(string $service, array $data): void {
        Store::save_service($service, $data);
    }
    public static function clear_quota_exhausted(string $service): void {
        Store::save_service($service, [
            'quota_exhausted'    => false,
            'quota_exhausted_at' => '',
        ]);
    }
    public static function usable_service_ids(): array {
        $usable = [];
        foreach (self::service_ids() as $id) {
            if (self::is_usable($id)) {
                $usable[] = $id;
            }
        }
        return $usable;
    }
    public static function has_usable_service(): bool {
        return [] !== self::usable_service_ids();
    }
    private static function next_reset_after(\DateTimeImmutable $from, int $reset_day): \DateTimeImmutable {
        $reset_day = max(1, min(31, $reset_day));
        $tz        = wp_timezone();
        $build = static function (int $year, int $month) use ($reset_day, $tz): \DateTimeImmutable {
            $first    = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
            $last_day = (int) $first->format('t');
            $day      = min($reset_day, $last_day);
            return $first->setDate($year, $month, $day);
        };
        $year  = (int) $from->format('Y');
        $month = (int) $from->format('n');
        $reset = $build($year, $month);
        if ($reset <= $from) {
            $month++;
            if ($month > 12) {
                $month = 1;
                $year++;
            }
            $reset = $build($year, $month);
        }
        return $reset;
    }
    public static function ec_api_keys_for_site(string $site): array {
        $settings = new Settings();
        if ($site === 'rakuten') {
            $rakuten = (array) $settings->get('rakuten', array());
            return array(
                'application_id' => trim((string) ($rakuten['application_id'] ?? '')),
                'access_key'     => trim((string) ($rakuten['access_key'] ?? '')),
                'site_url'       => trim((string) ($rakuten['site_url'] ?? '')),
            );
        }
        if ($site === 'yahoo') {
            $yahoo = (array) $settings->get('yahoo', array());
            return array(
                'client_id' => trim((string) ($yahoo['client_id'] ?? '')),
            );
        }
        return array();
    }
    public static function is_site_api_usable(string $site): bool {
        if ($site === 'amazon') {
            return true;
        }
        if ($site === 'rakuten' || $site === 'yahoo') {
            return Connection_Status::is_verified($site);
        }
        return true;
    }
    private static function invalid_code_message(string $site): string {
        $help = (string) (self::site($site)['code_help'] ?? '');
        if ($help === '') {
            return __('商品コードが不正です。', 'get-ec-reviews-api');
        }
        return sprintf(
            __('商品コードが不正です。%s', 'get-ec-reviews-api'),
            $help
        );
    }
    private static function api_unusable_message(string $site): string {
        return sprintf(
            __('%s の公式APIが利用できません（設定画面で API キーを保存し、接続テストを成功させてください）。', 'get-ec-reviews-api'),
            (string) (self::site($site)['label'] ?? $site)
        );
    }
    private static function normalize_reviews_records(string $service, string $site, string $code, array $records): array {
        $reviews = [];
        $now     = current_time('mysql');
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $review = self::normalize_review_record($service, $record, $now);
            if (self::is_empty_review($review)) {
                continue;
            }
            $review['site']      = $site;
            $review['item_code'] = $code;
            $reviews[] = $review;
        }
        return $reviews;
    }
    private static function is_empty_review(array $review): bool {
        return (string) ($review['review_text'] ?? '') === ''
            && (string) ($review['review_header'] ?? '') === ''
            && (string) ($review['author_name'] ?? '') === ''
            && (string) ($review['review_id'] ?? '') === ''
            && (float) ($review['rating'] ?? 0.0) <= 0.0;
    }
    private static function normalize_review_record(string $service, array $record, string $now): array {
        $text   = self::first_string_value($record, ['review_text', 'reviewText', 'reviewDescription', 'text', 'body', 'content', 'review']);
        $header = self::first_string_value($record, ['review_header', 'review_title', 'reviewTitle', 'title', 'header']);
        $author = self::first_string_value($record, ['author_name', 'authorName', 'author', 'reviewer', 'reviewer_name', 'reviewerName', 'name']);
        $posted = self::first_string_value($record, ['review_posted_date', 'review_date', 'reviewDate', 'posted_date', 'date', 'time']);
        $review_id = self::first_string_value($record, ['review_id', 'reviewId', 'id']);
        $site      = self::first_string_value($record, ['site']);
        $item_code = self::first_string_value($record, ['item_code', 'itemCode', 'asin', 'product_asin', 'productAsin']);
        $url       = self::first_string_value($record, ['url', 'review_url', 'reviewUrl', 'link']);
        $helpful = 0;
        foreach (['helpful_count', 'helpfulCount', 'helpful_votes', 'helpfulVotes', 'helpful'] as $key) {
            if (isset($record[$key]) && !is_array($record[$key])) {
                $helpful = (int) self::numeric_or_zero($record[$key]);
                break;
            }
        }
        $verified = false;
        foreach (['is_verified', 'isVerified', 'verified_purchase', 'verifiedPurchase', 'verified'] as $key) {
            if (isset($record[$key]) && !is_array($record[$key])) {
                $verified = !empty($record[$key]);
                break;
            }
        }
        return [
            'review_id'          => $review_id,
            'rating'             => self::rating_value($record),
            'author_name'        => $author,
            'review_header'      => $header,
            'review_text'        => $text,
            'review_posted_date' => $posted,
            'helpful_count'      => $helpful,
            'is_verified'        => $verified,
            'site'               => $site,
            'item_code'          => $item_code,
            'url'                => $url,
            'cached_at'          => $now,
            'service'            => $service,
        ];
    }
    private static function first_string_value(array $record, array $keys): string {
        foreach ($keys as $key) {
            if (isset($record[$key]) && !is_array($record[$key]) && (string) $record[$key] !== '') {
                return (string) $record[$key];
            }
        }
        return '';
    }
    private static function rating_value(array $record): float {
        foreach (['rating', 'ratingScore', 'stars', 'average_rating', 'star_rating', 'avg_rating'] as $key) {
            if (isset($record[$key]) && !is_array($record[$key])) {
                $value = self::numeric_or_zero($record[$key]);
                if ($value > 0.0) {
                    return $value;
                }
            }
        }
        return 0.0;
    }
    private static function numeric_or_zero($value): float {
        if ($value === null || $value === '' || is_bool($value) || is_array($value)) {
            return 0.0;
        }
        if (is_int($value) || is_float($value) || is_numeric($value)) {
            return (float) $value;
        }
        $normalized = str_replace(',', '', (string) $value);
        if (preg_match('/-?\d+(?:\.\d+)?/', $normalized, $matches) === 1) {
            return (float) $matches[0];
        }
        return 0.0;
    }
}
