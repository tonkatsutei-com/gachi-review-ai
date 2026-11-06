<?php
declare(strict_types=1);
use Tonkatsutei\Gachi_Review_AI\Scraping\Service_Catalog;
use Tonkatsutei\Gachi_Review_AI\Scraping\Store;
if (!defined('ABSPATH')) {
    exit;
}
if (class_exists('Free_Amzn_Creators_API')) {
    return;
}
class Free_Amzn_Creators_API {
    public const CACHE_PREFIX = 'free_amzn_creators_api_';
    public const DEFAULT_CACHE_HOURS = 24;
    private static ?array $services = null;
    private static string $last_search_error = '';
    private static string $last_detail_error = '';
    private static string $last_reviews_error = '';
    private static bool $last_search_from_cache = false;
    private static bool $last_detail_from_cache = false;
    private static bool $last_reviews_from_cache = false;
    public static function services(): array {
        if (self::$services !== null) {
            return self::$services;
        }
        self::$services = [];
        foreach (Service_Catalog::all() as $id => $definition) {
            if (!in_array(Service_Catalog::AMZN, $definition['used_by'], true)) {
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
    private static function search_cache_key(string $service, string $keyword, array $args): string {
        $pages = max(1, (int) ($args['pages'] ?? 1));
        $limit = max(0, (int) ($args['limit'] ?? 0));
        return self::CACHE_PREFIX . 'search_' . $service . '_' . md5($pages . '|' . $limit . '|' . $keyword);
    }
    private static function search_cache_hit(string $keyword, array $args): array|false {
        foreach (self::service_ids() as $service) {
            $cached = get_transient(self::search_cache_key($service, $keyword, $args));
            if (is_array($cached)) {
                return $cached;
            }
        }
        return false;
    }
    private static function detail_cache_key(string $service, string $asin): string {
        return self::CACHE_PREFIX . 'detail_' . $service . '_' . md5($asin);
    }
    private static function detail_cache_hit(string $asin): array|false {
        foreach (self::service_ids() as $service) {
            $cached = get_transient(self::detail_cache_key($service, $asin));
            if (is_array($cached)) {
                return $cached;
            }
        }
        return false;
    }
    private static function reviews_cache_key(string $service, string $asin, array $args): string {
        $max = max(1, (int) ($args['max_reviews'] ?? Free_Amzn_Creators_API_Client::REVIEWS_DEFAULT_MAX));
        return self::CACHE_PREFIX . 'reviews_' . $service . '_' . md5($max . '|' . $asin);
    }
    private static function reviews_cache_hit(string $asin, array $args): array|false {
        foreach (self::service_ids() as $service) {
            $cached = get_transient(self::reviews_cache_key($service, $asin, $args));
            if (is_array($cached)) {
                return $cached;
            }
        }
        return false;
    }
    private static function cache_ttl(): int {
        return self::DEFAULT_CACHE_HOURS * HOUR_IN_SECONDS;
    }
    public static function search(string $keyword, array $args = []): array {
        self::$last_search_error      = '';
        self::$last_search_from_cache = false;
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }
        $cached = empty($args['no_cache']) ? self::search_cache_hit($keyword, $args) : false;
        if (is_array($cached)) {
            self::$last_search_from_cache = true;
            return $cached;
        }
        $services = self::service_ids();
        shuffle($services);
        foreach ($services as $service) {
            if (!self::is_usable($service)) {
                continue;
            }
            $outcome = self::search_once($service, $keyword, $args);
            if ($outcome['ok']) {
                if ($outcome['items'] !== []) {
                    set_transient(self::search_cache_key($service, $keyword, $args), $outcome['items'], self::cache_ttl());
                }
                return $outcome['items'];
            }
            if ($outcome['quota_exhausted'] || $outcome['fallback']) {
                continue;
            }
            return [];
        }
        if (self::$last_search_error === '') {
            self::$last_search_error = __('利用可能なサービスがありません（API キーの保存と通信テストの成功が必要です）。', GACHIREAI_TEXT_DOMAIN);
            error_log('[Free Amzn Creators API] search(): ' . self::$last_search_error);
        }
        return [];
    }
    public static function search_with(string $service, string $keyword, array $args = []): array {
        self::$last_search_error      = '';
        self::$last_search_from_cache = false;
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }
        if (!array_key_exists($service, self::services())) {
            self::$last_search_error = __('未知のサービスです。', GACHIREAI_TEXT_DOMAIN);
            return [];
        }
        $cached = empty($args['no_cache']) ? self::search_cache_hit($keyword, $args) : false;
        if (is_array($cached)) {
            self::$last_search_from_cache = true;
            return $cached;
        }
        if (!self::is_usable($service)) {
            self::$last_search_error = __('このサービスは利用できません（API キーの保存と通信テストの成功が必要です）。', GACHIREAI_TEXT_DOMAIN);
            error_log(sprintf('[Free Amzn Creators API] search_with() unavailable (%s): %s', $service, self::$last_search_error));
            return [];
        }
        $outcome = self::search_once($service, $keyword, $args);
        if ($outcome['ok'] && $outcome['items'] !== []) {
            set_transient(self::search_cache_key($service, $keyword, $args), $outcome['items'], self::cache_ttl());
        }
        return $outcome['items'];
    }
    private static function search_once(string $service, string $keyword, array $args): array {
        $state   = self::get_service($service);
        $api_key = isset($state['api_key']) ? (string) $state['api_key'] : '';
        $result = Free_Amzn_Creators_API_Client::search($service, $api_key, $keyword, $args);
        if (!$result['ok']) {
            self::$last_search_error = (string) $result['message'];
            if (!empty($result['quota_exhausted'])) {
                self::mark_quota_exhausted($service);
                error_log(sprintf('[Free Amzn Creators API] search() quota exhausted (%s): %s', $service, self::$last_search_error));
                return ['ok' => false, 'items' => [], 'quota_exhausted' => true, 'fallback' => false];
            }
            error_log(sprintf('[Free Amzn Creators API] search() failed (%s): %s', $service, self::$last_search_error));
            return ['ok' => false, 'items' => [], 'quota_exhausted' => false, 'fallback' => !empty($result['fallback'])];
        }
        return [
            'ok'              => true,
            'items'           => self::normalize_records($service, $result['records']),
            'quota_exhausted' => false,
            'fallback'        => false,
        ];
    }
    public static function detail(string $asin, array $args = []): array {
        self::$last_detail_error      = '';
        self::$last_detail_from_cache = false;
        $asin = strtoupper(trim($asin));
        if (preg_match('/^[A-Z0-9]{10}$/', $asin) !== 1) {
            self::$last_detail_error = __('ASIN が不正です（10 桁の英数字で指定してください）。', GACHIREAI_TEXT_DOMAIN);
            return [];
        }
        $cached = empty($args['no_cache']) ? self::detail_cache_hit($asin) : false;
        if (is_array($cached)) {
            self::$last_detail_from_cache = true;
            return $cached;
        }
        $services = self::service_ids();
        shuffle($services);
        foreach ($services as $service) {
            if (!self::is_usable($service)) {
                continue;
            }
            $outcome = self::detail_once($service, $asin);
            if ($outcome['ok']) {
                if ($outcome['item'] !== []) {
                    set_transient(self::detail_cache_key($service, $asin), $outcome['item'], self::cache_ttl());
                }
                return $outcome['item'];
            }
            if ($outcome['quota_exhausted'] || $outcome['fallback']) {
                continue;
            }
            return [];
        }
        if (self::$last_detail_error === '') {
            self::$last_detail_error = __('商品詳細を取得できるサービスが利用できません（API キーの保存と通信テストの成功が必要です）。', GACHIREAI_TEXT_DOMAIN);
            error_log('[Free Amzn Creators API] detail(): ' . self::$last_detail_error);
        }
        return [];
    }
    public static function detail_with(string $service, string $asin, array $args = []): array {
        self::$last_detail_error      = '';
        self::$last_detail_from_cache = false;
        $asin = strtoupper(trim($asin));
        if (preg_match('/^[A-Z0-9]{10}$/', $asin) !== 1) {
            self::$last_detail_error = __('ASIN が不正です（10 桁の英数字で指定してください）。', GACHIREAI_TEXT_DOMAIN);
            return [];
        }
        if (!in_array($service, self::service_ids(), true)) {
            self::$last_detail_error = __('このサービスでの商品詳細取得は未実装です（現在は ZenRows / Bright Data / ScraperAPI / Apify / WebScrapingAPI / Context.dev に対応）。', GACHIREAI_TEXT_DOMAIN);
            return [];
        }
        $cached = empty($args['no_cache']) ? self::detail_cache_hit($asin) : false;
        if (is_array($cached)) {
            self::$last_detail_from_cache = true;
            return $cached;
        }
        if (!self::is_usable($service)) {
            self::$last_detail_error = __('このサービスは利用できません（API キーの保存と通信テストの成功が必要です）。', GACHIREAI_TEXT_DOMAIN);
            error_log(sprintf('[Free Amzn Creators API] detail_with() unavailable (%s): %s', $service, self::$last_detail_error));
            return [];
        }
        $outcome = self::detail_once($service, $asin);
        if ($outcome['ok'] && $outcome['item'] !== []) {
            set_transient(self::detail_cache_key($service, $asin), $outcome['item'], self::cache_ttl());
        }
        return $outcome['item'];
    }
    private static function detail_once(string $service, string $asin): array {
        $state   = self::get_service($service);
        $api_key = isset($state['api_key']) ? (string) $state['api_key'] : '';
        $result = Free_Amzn_Creators_API_Client::detail($service, $api_key, $asin);
        if (!$result['ok']) {
            self::$last_detail_error = (string) $result['message'];
            if (!empty($result['quota_exhausted'])) {
                self::mark_quota_exhausted($service);
                error_log(sprintf('[Free Amzn Creators API] detail() quota exhausted (%s): %s', $service, self::$last_detail_error));
                return ['ok' => false, 'item' => [], 'quota_exhausted' => true, 'fallback' => false];
            }
            error_log(sprintf('[Free Amzn Creators API] detail() failed (%s): %s', $service, self::$last_detail_error));
            return ['ok' => false, 'item' => [], 'quota_exhausted' => false, 'fallback' => !empty($result['fallback'])];
        }
        $items = self::normalize_records($service, $result['records']);
        return ['ok' => true, 'item' => $items[0] ?? [], 'quota_exhausted' => false, 'fallback' => false];
    }
    public static function reviews(string $asin, array $args = []): array {
        self::$last_reviews_error      = '';
        self::$last_reviews_from_cache = false;
        $asin = strtoupper(trim($asin));
        if (preg_match('/^[A-Z0-9]{10}$/', $asin) !== 1) {
            self::$last_reviews_error = __('ASIN が不正です（10 桁の英数字で指定してください）。', GACHIREAI_TEXT_DOMAIN);
            return [];
        }
        $cached = empty($args['no_cache']) ? self::reviews_cache_hit($asin, $args) : false;
        if (is_array($cached)) {
            self::$last_reviews_from_cache = true;
            return $cached;
        }
        $services = self::service_ids();
        shuffle($services);
        foreach ($services as $service) {
            if (!self::is_usable($service)) {
                continue;
            }
            $outcome = self::reviews_once($service, $asin, $args);
            if ($outcome['ok']) {
                if ($outcome['reviews'] !== []) {
                    set_transient(self::reviews_cache_key($service, $asin, $args), $outcome['reviews'], self::cache_ttl());
                }
                return $outcome['reviews'];
            }
            if ($outcome['quota_exhausted'] || $outcome['fallback']) {
                continue;
            }
            return [];
        }
        if (self::$last_reviews_error === '') {
            self::$last_reviews_error = __('レビューを取得できるサービスが利用できません（API キーの保存と通信テストの成功が必要です）。', GACHIREAI_TEXT_DOMAIN);
            error_log('[Free Amzn Creators API] reviews(): ' . self::$last_reviews_error);
        }
        return [];
    }
    public static function reviews_with(string $service, string $asin, array $args = []): array {
        self::$last_reviews_error      = '';
        self::$last_reviews_from_cache = false;
        $asin = strtoupper(trim($asin));
        if (preg_match('/^[A-Z0-9]{10}$/', $asin) !== 1) {
            self::$last_reviews_error = __('ASIN が不正です（10 桁の英数字で指定してください）。', GACHIREAI_TEXT_DOMAIN);
            return [];
        }
        if (!in_array($service, self::service_ids(), true)) {
            self::$last_reviews_error = __('このサービスでのレビュー取得は未実装です（現在は ZenRows / Bright Data / ScraperAPI / Apify / WebScrapingAPI / Context.dev に対応）。', GACHIREAI_TEXT_DOMAIN);
            return [];
        }
        $cached = empty($args['no_cache']) ? self::reviews_cache_hit($asin, $args) : false;
        if (is_array($cached)) {
            self::$last_reviews_from_cache = true;
            return $cached;
        }
        if (!self::is_usable($service)) {
            self::$last_reviews_error = __('このサービスは利用できません（API キーの保存と通信テストの成功が必要です）。', GACHIREAI_TEXT_DOMAIN);
            error_log(sprintf('[Free Amzn Creators API] reviews_with() unavailable (%s): %s', $service, self::$last_reviews_error));
            return [];
        }
        $outcome = self::reviews_once($service, $asin, $args);
        if ($outcome['ok'] && $outcome['reviews'] !== []) {
            set_transient(self::reviews_cache_key($service, $asin, $args), $outcome['reviews'], self::cache_ttl());
        }
        return $outcome['reviews'];
    }
    private static function reviews_once(string $service, string $asin, array $args): array {
        $state   = self::get_service($service);
        $api_key = isset($state['api_key']) ? (string) $state['api_key'] : '';
        $result = Free_Amzn_Creators_API_Client::reviews($service, $api_key, $asin, $args);
        if (!$result['ok']) {
            self::$last_reviews_error = (string) $result['message'];
            if (!empty($result['quota_exhausted'])) {
                self::mark_quota_exhausted($service);
                error_log(sprintf('[Free Amzn Creators API] reviews() quota exhausted (%s): %s', $service, self::$last_reviews_error));
                return ['ok' => false, 'reviews' => [], 'quota_exhausted' => true, 'fallback' => false];
            }
            error_log(sprintf('[Free Amzn Creators API] reviews() failed (%s): %s', $service, self::$last_reviews_error));
            return ['ok' => false, 'reviews' => [], 'quota_exhausted' => false, 'fallback' => !empty($result['fallback'])];
        }
        return [
            'ok'              => true,
            'reviews'         => self::normalize_reviews_records($service, $result['records']),
            'quota_exhausted' => false,
            'fallback'        => false,
        ];
    }
    public static function last_search_error(): string {
        return self::$last_search_error;
    }
    public static function last_detail_error(): string {
        return self::$last_detail_error;
    }
    public static function last_reviews_error(): string {
        return self::$last_reviews_error;
    }
    public static function last_search_from_cache(): bool {
        return self::$last_search_from_cache;
    }
    public static function last_detail_from_cache(): bool {
        return self::$last_detail_from_cache;
    }
    public static function last_reviews_from_cache(): bool {
        return self::$last_reviews_from_cache;
    }
    public static function has_usable_service(): bool {
        foreach (self::service_ids() as $service) {
            if (self::is_usable($service)) {
                return true;
            }
        }
        return false;
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
        $state['reset_day'] = self::reset_day($service);
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
    private static function reset_day(string $service): int {
        $services = self::services();
        return isset($services[$service]['reset_day']) ? (int) $services[$service]['reset_day'] : 1;
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
    private static function normalize_records(string $service, array $records): array {
        $items = [];
        $now   = current_time('mysql');
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $items[] = self::normalize_record($service, $record, $now);
        }
        return $items;
    }
    private static function normalize_reviews_records(string $service, array $records): array {
        $reviews = [];
        $now     = current_time('mysql');
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $reviews[] = self::normalize_review_record($service, $record, $now);
        }
        return $reviews;
    }
    private static function normalize_review_record(string $service, array $record, string $now): array {
        $text = self::first_string_value($record, ['review_text', 'reviewText', 'reviewDescription', 'text', 'body', 'content', 'review']);
        $header = self::first_string_value($record, ['review_header', 'review_title', 'reviewTitle', 'title', 'header']);
        $author = self::first_string_value($record, ['author_name', 'authorName', 'author', 'reviewer', 'reviewer_name', 'reviewerName', 'name']);
        $posted = self::first_string_value($record, ['review_posted_date', 'review_date', 'reviewDate', 'posted_date', 'date', 'time']);
        $review_id = self::first_string_value($record, ['review_id', 'reviewId', 'id']);
        $asin = self::first_string_value($record, ['asin', 'product_asin', 'productAsin']);
        $url = self::first_string_value($record, ['url', 'review_url', 'reviewUrl', 'link']);
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
            'asin'               => $asin,
            'url'                => $url,
            'cached_at'          => $now,
            'service'            => $service,
        ];
    }
    private static function normalize_record(string $service, array $record, string $now): array {
        if ($service === 'zenrows') {
            return self::normalize_zenrows_record($record, $now);
        }
        if ($service === 'scraperapi') {
            return self::normalize_scraperapi_record($record, $now);
        }
        if ($service === 'bright_data') {
            return self::normalize_bright_data_record($record, $now);
        }
        if ($service === 'apify') {
            return self::normalize_apify_record($record, $now);
        }
        if ($service === 'webscrapingapi') {
            return self::normalize_webscrapingapi_record($record, $now);
        }
        if ($service === 'contextdev') {
            return self::normalize_contextdev_record($record, $now);
        }
        return [];
    }
    private static function normalize_bright_data_record(array $record, string $now): array {
        $title = $record['name'] ?? ($record['title'] ?? '');
        $price = $record['final_price'] ?? ($record['price'] ?? ($record['initial_price'] ?? null));
        $count = $record['num_ratings'] ?? ($record['reviews_count'] ?? null);
        $image = $record['image'] ?? ($record['image_url'] ?? ($record['main_image'] ?? ''));
        return [
            'asin'         => isset($record['asin']) ? (string) $record['asin'] : '',
            'url'          => isset($record['url']) ? (string) $record['url'] : '',
            'title'        => (string) $title,
            'price'        => self::numeric_or_zero($price),
            'currency'     => isset($record['currency']) ? (string) $record['currency'] : '',
            'rating'       => self::rating_value($record),
            'review_count' => is_numeric($count) ? (int) $count : 0,
            'image'        => (string) $image,
            'cached_at'    => $now,
            'service'      => 'bright_data',
        ];
    }
    private static function normalize_scraperapi_record(array $record, string $now): array {
        $url      = $record['link'] ?? ($record['url'] ?? '');
        $price    = $record['price'] ?? null;
        $amount   = is_array($price) ? ($price['value'] ?? null) : $price;
        $currency = is_array($price) ? ($price['currency'] ?? '') : (string) ($record['currency'] ?? '');
        $image = self::first_string_value($record, ['image', 'image_url', 'main_image']);
        if ($image === '' && isset($record['images']) && is_array($record['images']) && isset($record['images'][0])) {
            $first = $record['images'][0];
            $image = is_array($first) ? (string) ($first['url'] ?? '') : (string) $first;
        }
        return [
            'asin'         => isset($record['asin']) ? (string) $record['asin'] : '',
            'url'          => (string) $url,
            'title'        => self::title_value($record),
            'price'        => self::numeric_or_zero($amount),
            'currency'     => (string) $currency,
            'rating'       => self::rating_value($record),
            'review_count' => self::review_count_value($record),
            'image'        => $image,
            'cached_at'    => $now,
            'service'      => 'scraperapi',
        ];
    }
    private static function normalize_apify_record(array $record, string $now): array {
        $price  = $record['price'] ?? null;
        $amount = is_array($price) ? ($price['value'] ?? ($price['amount'] ?? null)) : $price;
        $symbol = is_array($price) ? (string) ($price['formatted'] ?? '') : (string) $price;
        $url = isset($record['url']) ? (string) $record['url'] : '';
        $asin = isset($record['asin']) ? (string) $record['asin'] : '';
        if ($asin === '' && $url !== '' && preg_match('~/dp/([A-Za-z0-9]{10})~', $url, $matches) === 1) {
            $asin = $matches[1];
        }
        $count = $record['review_count']
            ?? ($record['reviewsCount'] ?? ($record['reviews_count'] ?? ($record['reviews'] ?? ($record['num_ratings'] ?? null))));
        $image = isset($record['image']) ? (string) $record['image'] : '';
        if ($image === '' && isset($record['images']) && is_array($record['images']) && isset($record['images'][0])) {
            $image = (string) $record['images'][0];
        }
        $currency = isset($record['currency']) ? (string) $record['currency'] : '';
        if ($currency === '') {
            $currency = self::currency_from_symbol($symbol);
        }
        return [
            'asin'         => $asin,
            'url'          => $url,
            'title'        => self::title_value($record),
            'price'        => self::numeric_or_zero($amount),
            'currency'     => $currency,
            'rating'       => self::rating_value($record),
            'review_count' => (int) self::numeric_or_zero($count),
            'image'        => $image,
            'cached_at'    => $now,
            'service'      => 'apify',
        ];
    }
    private static function normalize_zenrows_record(array $record, string $now): array {
        $url      = isset($record['url']) ? (string) $record['url'] : '';
        $price    = $record['price'] ?? null;
        $amount   = is_array($price) ? ($price['value'] ?? null) : $price;
        $currency = is_array($price) ? (string) ($price['currency'] ?? '') : (string) ($record['currency'] ?? '');
        if ($currency === '') {
            $symbol   = is_array($price) ? (string) ($price['formatted'] ?? '') : (string) $price;
            $currency = self::currency_from_symbol($symbol);
        }
        $image = self::first_string_value($record, ['image', 'image_url', 'main_image']);
        if ($image === '' && isset($record['images']) && is_array($record['images']) && isset($record['images'][0])) {
            $first = $record['images'][0];
            $image = is_array($first) ? (string) ($first['url'] ?? '') : (string) $first;
        }
        return [
            'asin'         => isset($record['asin']) ? (string) $record['asin'] : '',
            'url'          => $url,
            'title'        => self::title_value($record),
            'price'        => self::numeric_or_zero($amount),
            'currency'     => $currency,
            'rating'       => self::rating_value($record),
            'review_count' => self::review_count_value($record),
            'image'        => $image,
            'cached_at'    => $now,
            'service'      => 'zenrows',
        ];
    }
    private static function normalize_webscrapingapi_record(array $record, string $now): array {
        $item            = self::normalize_zenrows_record($record, $now);
        $item['service'] = 'webscrapingapi';
        return $item;
    }
    private static function normalize_contextdev_record(array $record, string $now): array {
        $item            = self::normalize_zenrows_record($record, $now);
        $item['service'] = 'contextdev';
        return $item;
    }
    private static function currency_from_symbol(string $price_text): string {
        if (str_contains($price_text, '¥') || str_contains($price_text, '￥')) {
            return 'JPY';
        }
        if (str_contains($price_text, '$')) {
            return 'USD';
        }
        if (str_contains($price_text, '€')) {
            return 'EUR';
        }
        if (str_contains($price_text, '£')) {
            return 'GBP';
        }
        return 'JPY';
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
    private static function title_value(array $record): string {
        return self::first_string_value($record, ['title', 'name', 'product_name']);
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
    private static function review_count_value(array $record): int {
        foreach (['reviews_count', 'num_ratings', 'total_reviews', 'reviews', 'review_count', 'reviewsCount', 'num_reviews'] as $key) {
            if (isset($record[$key]) && !is_array($record[$key])) {
                $value = (int) self::numeric_or_zero($record[$key]);
                if ($value > 0) {
                    return $value;
                }
            }
        }
        return 0;
    }
}
