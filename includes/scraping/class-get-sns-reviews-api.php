<?php
declare(strict_types=1);
use Tonkatsutei\Gachi_Review_AI\Scraping\Service_Catalog;
use Tonkatsutei\Gachi_Review_AI\Scraping\Store;
if (!defined('ABSPATH')) {
    exit;
}
if (class_exists('Get_SNS_Reviews_API')) {
    return;
}
class Get_SNS_Reviews_API
{
    public const CACHE_PREFIX = 'get_sns_reviews_api_';
    public const DEFAULT_CACHE_HOURS = 24;
    public const SNS_SERVICES = [
        'x'         => ['apify', 'bright_data', 'zenrows', 'webscrapingapi', 'scrape_do'],
        'instagram' => ['apify', 'bright_data', 'webscrapingapi', 'ensembledata', 'scrape_do'],
        'facebook'  => ['apify', 'bright_data'],
    ];
    private static ?array $services = null;
    private static ?array $snss = null;
    private static string $last_reviews_error = '';
    private static string $last_reviews_sns = '';
    private static bool $last_reviews_from_cache = false;
    public static function sns(): array
    {
        if (self::$snss !== null) {
            return self::$snss;
        }
        self::$snss = [
            'x'         => [
                'label'           => 'X',
                'keyword_label'   => __('キーワード', 'get-sns-reviews-api'),
                'keyword_example' => __('商品名', 'get-sns-reviews-api'),
                'keyword_help'    => __('', 'get-sns-reviews-api'),
            ],
            'instagram' => [
                'label'           => 'Instagram',
                'keyword_label'   => __('キーワード', 'get-sns-reviews-api'),
                'keyword_example' => __('商品名', 'get-sns-reviews-api'),
                'keyword_help'    => __('', 'get-sns-reviews-api'),
            ],
            'facebook'  => [
                'label'           => 'Facebook',
                'keyword_label'   => __('キーワード', 'get-sns-reviews-api'),
                'keyword_example' => __('商品名', 'get-sns-reviews-api'),
                'keyword_help'    => __('', 'get-sns-reviews-api'),
            ],
        ];
        return self::$snss;
    }
    public static function sns_ids(): array
    {
        return array_keys(self::sns());
    }
    public static function sns_definition(string $sns): array
    {
        return self::sns()[$sns] ?? [];
    }
    public static function sites(): array
    {
        return self::sns();
    }
    public static function site_ids(): array
    {
        return self::sns_ids();
    }
    public static function site(string $sns): array
    {
        return self::sns_definition($sns);
    }
    public static function services_for_sns(string $sns): array
    {
        return self::SNS_SERVICES[$sns] ?? [];
    }
    public static function services_for_site(string $sns): array
    {
        return self::services_for_sns($sns);
    }
    public static function service_supports(string $service, string $sns): bool
    {
        return in_array($service, self::services_for_sns($sns), true);
    }
    private static function is_valid_keyword(string $sns, string $keyword): bool
    {
        if (!array_key_exists($sns, self::sns())) {
            return false;
        }
        if ($keyword === '') {
            return false;
        }
        return mb_strlen($keyword) <= 100;
    }
    private static function invalid_keyword_message(string $sns): string
    {
        $help = (string) (self::sns_definition($sns)['keyword_help'] ?? '');
        if ($help === '') {
            return __('キーワードが不正です（1〜100 文字で指定してください）。', 'get-sns-reviews-api');
        }
        return sprintf(
            __('キーワードが不正です（1〜100 文字で指定してください）。%s', 'get-sns-reviews-api'),
            $help
        );
    }
    public static function services(): array
    {
        if (self::$services !== null) {
            return self::$services;
        }
        self::$services = [];
        foreach (Service_Catalog::all() as $id => $definition) {
            if (!in_array(Service_Catalog::SNS, $definition['used_by'], true)) {
                continue;
            }
            self::$services[$id] = [
                'label'       => $definition['label'],
                'description' => $definition['features'],
                'docs_url'    => $definition['url'],
                'free_quota'  => '',
                'reset_day'   => 1,
            ];
        }
        return self::$services;
    }
    public static function service_ids(): array
    {
        return array_keys(self::services());
    }
    private static function reviews_cache_key(string $service, string $sns, string $keyword, array $args): string
    {
        $max = max(1, (int) ($args['max_reviews'] ?? Get_SNS_Reviews_API_Client::REVIEWS_DEFAULT_MAX));
        return self::CACHE_PREFIX . 'reviews_' . $service . '_' . md5($sns . '|' . $max . '|' . $keyword);
    }
    private static function reviews_cache_hit(string $sns, string $keyword, array $args): array|false
    {
        foreach (self::services_for_sns($sns) as $service) {
            $cached = get_transient(self::reviews_cache_key($service, $sns, $keyword, $args));
            if (is_array($cached)) {
                return $cached;
            }
        }
        return false;
    }
    private static function cache_ttl(): int
    {
        return self::DEFAULT_CACHE_HOURS * HOUR_IN_SECONDS;
    }
    public static function reviews(string $sns, string $keyword, array $args = []): array
    {
        self::$last_reviews_error      = '';
        self::$last_reviews_sns        = $sns;
        self::$last_reviews_from_cache = false;
        $sns     = strtolower(trim($sns));
        $keyword = trim($keyword);
        if (!array_key_exists($sns, self::sns())) {
            self::$last_reviews_error = __('未知の SNS です（x / instagram / facebook のいずれかを指定してください）。', 'get-sns-reviews-api');
            return [];
        }
        if (!self::is_valid_keyword($sns, $keyword)) {
            self::$last_reviews_error = self::invalid_keyword_message($sns);
            return [];
        }
        $cached = empty($args['no_cache']) ? self::reviews_cache_hit($sns, $keyword, $args) : false;
        if (is_array($cached)) {
            self::$last_reviews_from_cache = true;
            return $cached;
        }
        $services = self::services_for_sns($sns);
        shuffle($services);
        foreach ($services as $service) {
            if (!self::is_usable($service)) {
                continue;
            }
            $outcome = self::reviews_once($service, $sns, $keyword, $args);
            if ($outcome['ok']) {
                if ($outcome['reviews'] !== []) {
                    set_transient(self::reviews_cache_key($service, $sns, $keyword, $args), $outcome['reviews'], self::cache_ttl());
                }
                return $outcome['reviews'];
            }
            if ($outcome['quota_exhausted'] || $outcome['fallback']) {
                continue;
            }
            return [];
        }
        if (self::$last_reviews_error === '') {
            self::$last_reviews_error = __('利用可能なサービスがありません（API キーの保存と通信テストの成功が必要です）。', 'get-sns-reviews-api');
            error_log('[Get SNS Reviews API] reviews(): ' . self::$last_reviews_error);
        }
        return [];
    }
    public static function reviews_with(string $service, string $sns, string $keyword, array $args = []): array
    {
        self::$last_reviews_error      = '';
        self::$last_reviews_sns        = $sns;
        self::$last_reviews_from_cache = false;
        $sns     = strtolower(trim($sns));
        $keyword = trim($keyword);
        if (!array_key_exists($sns, self::sns())) {
            self::$last_reviews_error = __('未知の SNS です（x / instagram / facebook のいずれかを指定してください）。', 'get-sns-reviews-api');
            return [];
        }
        if (!self::is_valid_keyword($sns, $keyword)) {
            self::$last_reviews_error = self::invalid_keyword_message($sns);
            return [];
        }
        if (!in_array($service, self::services_for_sns($sns), true)) {
            self::$last_reviews_error = __('このサービスは指定 SNS の投稿取得に対応していません。', 'get-sns-reviews-api');
            return [];
        }
        $cached = empty($args['no_cache']) ? self::reviews_cache_hit($sns, $keyword, $args) : false;
        if (is_array($cached)) {
            self::$last_reviews_from_cache = true;
            return $cached;
        }
        if (!self::is_usable($service)) {
            self::$last_reviews_error = __('このサービスは利用できません（API キーの保存と通信テストの成功が必要です）。', 'get-sns-reviews-api');
            error_log(sprintf('[Get SNS Reviews API] reviews_with() unavailable (%s): %s', $service, self::$last_reviews_error));
            return [];
        }
        $outcome = self::reviews_once($service, $sns, $keyword, $args);
        if ($outcome['ok'] && $outcome['reviews'] !== []) {
            set_transient(self::reviews_cache_key($service, $sns, $keyword, $args), $outcome['reviews'], self::cache_ttl());
        }
        return $outcome['reviews'];
    }
    private static function reviews_once(string $service, string $sns, string $keyword, array $args): array
    {
        $state   = self::get_service($service);
        $api_key = isset($state['api_key']) ? (string) $state['api_key'] : '';
        $result = Get_SNS_Reviews_API_Client::reviews($service, $api_key, $sns, $keyword, $args);
        if (!$result['ok']) {
            self::$last_reviews_error = (string) $result['message'];
            if (!empty($result['quota_exhausted'])) {
                self::mark_quota_exhausted($service);
                error_log(sprintf('[Get SNS Reviews API] reviews() quota exhausted (%s): %s', $service, self::$last_reviews_error));
                return ['ok' => false, 'reviews' => [], 'quota_exhausted' => true, 'fallback' => false];
            }
            error_log(sprintf('[Get SNS Reviews API] reviews() failed (%s): %s', $service, self::$last_reviews_error));
            return ['ok' => false, 'reviews' => [], 'quota_exhausted' => false, 'fallback' => !empty($result['fallback'])];
        }
        return [
            'ok'              => true,
            'reviews'         => self::normalize_posts_records($service, $sns, $keyword, $result['records']),
            'quota_exhausted' => false,
            'fallback'        => false,
        ];
    }
    public static function last_reviews_error(): string
    {
        return self::$last_reviews_error;
    }
    public static function last_reviews_sns(): string
    {
        return self::$last_reviews_sns;
    }
    public static function last_reviews_from_cache(): bool
    {
        return self::$last_reviews_from_cache;
    }
    public static function is_usable(string $service): bool
    {
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
    public static function is_quota_exhausted(string $service): bool
    {
        $state = self::get_service($service);
        if (empty($state['quota_exhausted'])) {
            return false;
        }
        return !self::quota_exhausted_expired($state);
    }
    private static function mark_quota_exhausted(string $service): void
    {
        $data = [
            'quota_exhausted'    => true,
            'quota_exhausted_at' => current_time('mysql'),
        ];
        if (self::uses_network_service($service)) {
            self::save_network_service($service, $data);
            return;
        }
        self::save_service($service, $data);
    }
    private static function quota_exhausted_expired(array $state): bool
    {
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
    public static function get_settings(): array
    {
        $services = [];
        foreach (self::services() as $id => $definition) {
            $services[$id] = self::get_service($id);
        }
        return ['services' => $services];
    }
    public static function get_site_settings(): array
    {
        return self::get_settings();
    }
    public static function get_network_settings(): array
    {
        return ['services' => []];
    }
    public static function get_network_service(string $service): array
    {
        return [];
    }
    public static function network_service_key_exists(string $service): bool
    {
        return false;
    }
    public static function get_service(string $service): array
    {
        $state = Store::get($service);
        $services           = self::services();
        $state['reset_day'] = isset($services[$service]['reset_day']) ? (int) $services[$service]['reset_day'] : 1;
        return $state;
    }
    public static function mask_api_key(string $api_key): string
    {
        $length = strlen($api_key);
        if ($length === 0) {
            return '';
        }
        if ($length <= 6) {
            return str_repeat('*', $length);
        }
        return substr($api_key, 0, 3) . str_repeat('*', $length - 6) . substr($api_key, -3);
    }
    public static function save_service(string $service, array $data): void
    {
        if (!array_key_exists($service, self::services())) {
            return;
        }
        Store::save_service($service, $data);
    }
    public static function save_network_service(string $service, array $data): void
    {
    }
    private static function uses_network_service(string $service): bool
    {
        return false;
    }
    public static function clear_quota_exhausted(string $service): void
    {
        Store::save_service($service, [
            'quota_exhausted'    => false,
            'quota_exhausted_at' => '',
        ]);
    }
    public static function is_network_quota_exhausted(string $service): bool
    {
        return false;
    }
    public static function clear_network_quota_exhausted(string $service): void
    {
    }
    public static function usable_service_ids(): array
    {
        $usable = [];
        foreach (self::service_ids() as $id) {
            if (self::is_usable($id)) {
                $usable[] = $id;
            }
        }
        return $usable;
    }
    public static function has_usable_service(): bool
    {
        return [] !== self::usable_service_ids();
    }
    public static function next_reset_at(int $reset_day): \DateTimeImmutable
    {
        return self::next_reset_after(current_datetime(), $reset_day);
    }
    private static function next_reset_after(\DateTimeImmutable $from, int $reset_day): \DateTimeImmutable
    {
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
    private static function normalize_posts_records(string $service, string $sns, string $keyword, array $records): array
    {
        $posts = [];
        $now   = current_time('mysql');
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $post = self::normalize_post_record($record, $sns, $now);
            $post['sns']       = $sns;
            $post['site']      = $sns;
            $post['keyword']   = $keyword;
            $post['item_code'] = $keyword;
            $post['service']   = $service;
            $posts[] = $post;
        }
        return $posts;
    }
    private static function normalize_post_record(array $record, string $sns, string $now): array
    {
        $author_record = [];
        foreach (['author', 'owner', 'user'] as $author_key) {
            if (isset($record[$author_key]) && is_array($record[$author_key])) {
                $author_record = $record[$author_key];
                break;
            }
        }
        $text       = self::first_string_value($record, ['text', 'full_text', 'fullText', 'caption', 'message', 'content', 'description', 'post_text', 'postText', 'tweet_text', 'tweetText']);
        if ($text === '' && $sns === 'instagram') {
            $text = trim((string) ($record['edge_media_to_caption']['edges'][0]['node']['text'] ?? ''));
        }
        $author     = self::first_string_value($record, ['author_name', 'authorName', 'author', 'username', 'user_name', 'userName', 'ownerUsername', 'owner_username', 'page_name', 'pageName', 'name']);
        if ($author === '') {
            $author = self::first_string_value($author_record, ['userName', 'username', 'name', 'fullName', 'full_name', 'title', 'id']);
        }
        $author_url = self::first_string_value($record, ['author_url', 'authorUrl', 'user_url', 'userUrl', 'profile_url', 'profileUrl', 'owner_url', 'page_url', 'pageUrl']);
        if ($author_url === '') {
            $author_url = self::first_string_value($author_record, ['url', 'profileUrl', 'profile_url', 'link']);
        }
        if ($author_url === '' && $sns === 'instagram') {
            $owner_name = ltrim(self::first_string_value($record, ['ownerUsername', 'owner_username', 'username']), '@');
            if ($owner_name !== '') {
                $author_url = 'https://www.instagram.com/' . $owner_name . '/';
            }
        }
        $posted     = self::first_string_value($record, ['posted_date', 'postedDate', 'timestamp', 'created_at', 'createdAt', 'date_posted', 'datePosted', 'date', 'time', 'taken_at_timestamp', 'taken_at']);
        $post_id    = self::first_string_value($record, ['post_id', 'postId', 'id', 'tweet_id', 'tweetId', 'shortCode', 'shortcode']);
        $url        = self::first_string_value($record, ['url', 'post_url', 'postUrl', 'link', 'permalink']);
        if ($url === '' && $sns === 'instagram') {
            $shortcode = self::first_string_value($record, ['shortcode', 'shortCode']);
            if ($shortcode !== '') {
                $url = 'https://www.instagram.com/p/' . $shortcode . '/';
            }
        }
        $media      = self::first_string_value($record, ['media_type', 'mediaType', 'mediaTypeName']);
        if ($media === '') {
            $media = self::media_type_from_record($record, $sns);
        }
        $likes    = (int) round(self::first_numeric_value($record, ['likes', 'likesCount', 'like_count', 'likeCount', 'favorite_count', 'favoriteCount', 'reactions', 'reactions_count', 'reactionsCount']));
        if ($likes <= 0) {
            $likes = (int) round(self::first_nested_count($record, ['edge_liked_by', 'edge_media_preview_like']));
        }
        $comments = (int) round(self::first_numeric_value($record, ['comments', 'commentsCount', 'comment_count', 'commentCount', 'num_comments', 'numComments', 'replies', 'replyCount', 'reply_count']));
        if ($comments <= 0) {
            $comments = (int) round(self::first_nested_count($record, ['edge_media_to_comment', 'edge_media_preview_comment']));
        }
        $shares   = (int) round(self::first_numeric_value($record, ['shares', 'sharesCount', 'share_count', 'shareCount', 'retweets', 'retweet_count', 'retweetCount', 'reposts']));
        $verified = false;
        foreach ([$record, $author_record] as $source) {
            foreach (['is_verified', 'isVerified', 'verified', 'user_verified', 'userVerified', 'isBlueVerified'] as $key) {
                if (isset($source[$key]) && !is_array($source[$key]) && filter_var($source[$key], FILTER_VALIDATE_BOOLEAN)) {
                    $verified = true;
                    break 2;
                }
            }
        }
        return [
            'review_id'          => $post_id,
            'rating'             => 0.0,
            'author_name'        => $author,
            'review_header'      => '',
            'review_text'        => $text,
            'review_posted_date' => self::format_posted_date($posted),
            'helpful_count'      => $likes,
            'is_verified'        => $verified,
            'url'                => $url,
            'likes'              => $likes,
            'comments'           => $comments,
            'shares'             => $shares,
            'media_type'         => $media,
            'author_url'         => $author_url,
            'cached_at'          => $now,
        ];
    }
    private static function format_posted_date(string $posted): string
    {
        $posted = trim($posted);
        if ($posted === '') {
            return '';
        }
        if (preg_match('/^\d{12,}$/', $posted) === 1) {
            return (string) wp_date('Y-m-d H:i:s', (int) floor((float) $posted / 1000));
        }
        if (preg_match('/^\d{9,11}$/', $posted) === 1) {
            return (string) wp_date('Y-m-d H:i:s', (int) $posted);
        }
        $twitter = \DateTimeImmutable::createFromFormat('D M d H:i:s O Y', $posted);
        if ($twitter instanceof \DateTimeImmutable) {
            return (string) wp_date('Y-m-d H:i:s', $twitter->getTimestamp());
        }
        $timestamp = strtotime($posted);
        if ($timestamp !== false) {
            return (string) wp_date('Y-m-d H:i:s', $timestamp);
        }
        return $posted;
    }
    private static function first_string_value(array $record, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($record[$key]) && !is_array($record[$key]) && (string) $record[$key] !== '') {
                return (string) $record[$key];
            }
        }
        return '';
    }
    private static function media_type_from_record(array $record, string $sns): string
    {
        if ($sns === 'instagram') {
            $type = self::first_string_value($record, ['type']);
            if ($type !== '') {
                return $type;
            }
            return str_replace('Graph', '', self::first_string_value($record, ['__typename', 'typename']));
        }
        if ($sns === 'x') {
            $media = $record['extendedEntities']['media'] ?? null;
        } elseif ($sns === 'facebook') {
            $media = $record['attachments'] ?? null;
        } else {
            return '';
        }
        if (!is_array($media)) {
            return '';
        }
        foreach ($media as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = self::first_string_value($item, ['type']);
            if ($type !== '') {
                return $type;
            }
        }
        return '';
    }
    private static function first_numeric_value(array $record, array $keys): float
    {
        foreach ($keys as $key) {
            if (isset($record[$key]) && !is_array($record[$key])) {
                $value = self::numeric_or_zero($record[$key]);
                if ($value > 0.0) {
                    return $value;
                }
            }
        }
        return 0.0;
    }
    private static function first_nested_count(array $record, array $keys): float
    {
        foreach ($keys as $key) {
            if (isset($record[$key]['count']) && !is_array($record[$key]['count'])) {
                $value = self::numeric_or_zero($record[$key]['count']);
                if ($value > 0.0) {
                    return $value;
                }
            }
        }
        return 0.0;
    }
    private static function numeric_or_zero($value): float
    {
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
