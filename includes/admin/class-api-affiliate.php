<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Amazon_Client;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Rakuten_Client;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Yahoo_Client;
use Tonkatsutei\Gachi_Review_AI\Ec\Genre_Catalog;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\Image\Image_Catalog;
use Tonkatsutei\Gachi_Review_AI\Image\Manager as Image_Manager;
use Tonkatsutei\Gachi_Review_AI\License\Capabilities;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Llm\Jev_Client;
use Tonkatsutei\Gachi_Review_AI\Llm\Manager;
use Tonkatsutei\Gachi_Review_AI\Llm\Model_Catalog;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Api_Affiliate extends Screen
{
	public const TAB_LLM = 'llm';
	public const TAB_ASP = 'asp';
	private const KEY_LLM     = 'llm';
	private const KEY_RAKUTEN = 'rakuten';
	private const KEY_AMAZON  = 'amazon';
	private const EXCLUSIVE_GROUP_AMAZON = 'amazon-backend';
	private const KEY_YAHOO   = 'yahoo';
	private const KEY_EC = 'ec';
	private const KEY_JEV = 'jev';
	private const KEY_IMAGE = 'image';
	private const ACTIONS = array(
		self::KEY_LLM     => 'save_llm',
		self::KEY_JEV     => 'save_jev',
		self::KEY_IMAGE   => 'save_image_ai',
		self::KEY_EC      => 'save_ec',
		self::KEY_RAKUTEN => 'save_rakuten',
		self::KEY_AMAZON  => 'save_amazon',
		self::KEY_YAHOO   => 'save_yahoo',
	);
	private const NONCE_ACTION = 'gachireai_save_settings';
	public const AJAX_ACTIONS = array(
		self::KEY_LLM     => 'gachireai_save_llm',
		self::KEY_JEV     => 'gachireai_save_jev',
		self::KEY_IMAGE   => 'gachireai_save_image_ai',
		self::KEY_EC      => 'gachireai_save_ec',
		self::KEY_RAKUTEN => 'gachireai_save_rakuten',
		self::KEY_AMAZON  => 'gachireai_save_amazon',
		self::KEY_YAHOO   => 'gachireai_save_yahoo',
	);
	private const ACTION_FIELD = 'gachireai_action';
	private const SAVED_ARG = 'gachireai_settings_saved';
	private const TESTED_ARG = 'gachireai_settings_tested';
	private const TEST_TRANSIENT_PREFIX = 'gachireai_api_test_';
	private const TEST_MESSAGE_MAX = 300;
	private const TEST_PROMPT = '「OK」とだけ返してください。';
	private const TEST_MAX_TOKENS = 1024;
	private const TEST_TIMEOUT = 30;
	public function render(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		$this->render_template('admin/api-affiliate', $this->model($this->current_tab()));
	}
	public function maybe_save(): void
	{
		if (wp_doing_ajax()) {
			return;
		}
		if (! isset($_POST[self::ACTION_FIELD])) {
			return;
		}
		$posted = sanitize_key(wp_unslash((string) $_POST[self::ACTION_FIELD]));
		$key    = array_search($posted, self::ACTIONS, true);
		if (! is_string($key)) {
			return;
		}
		if (! current_user_can('manage_options')) {
			wp_die(
				esc_html__('この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN),
				esc_html__('gachi-review-ai', GACHIREAI_TEXT_DOMAIN),
				array('response' => 403)
			);
		}
		check_admin_referer(self::NONCE_ACTION);
		$result = $this->save_and_test($key);
		if ($result['saved']) {
			$this->record_setting_save(Tabs::label($this->tab_of($key)));
		}
		set_transient(
			self::TEST_TRANSIENT_PREFIX . get_current_user_id(),
			$result['test'],
			60
		);
		$args = array(self::TESTED_ARG => '1');
		if ($result['saved']) {
			$args[self::SAVED_ARG] = '1';
		}
		wp_safe_redirect(Tabs::url($this->tab_of($key), $args));
		exit;
	}
	private function save_and_test(string $key): array
	{
		delete_transient( self::TEST_TRANSIENT_PREFIX . get_current_user_id() );
		$this->apply_common_switch($key);
		if (self::KEY_LLM === $key) {
			return $this->save_llm_form();
		}
		if (self::KEY_JEV === $key) {
			return $this->save_jev_form();
		}
		if (self::KEY_IMAGE === $key) {
			return $this->save_image_form();
		}
		if (self::KEY_EC === $key) {
			return $this->save_ec_form();
		}
		$settings = new Settings();
		$posted   = $this->posted($key);
		$current  = (array) $settings->get($key, array());
		if (self::KEY_YAHOO === $key) {
			unset($current['affiliate_id']);
		}
		$clean = $this->sanitize($key, $posted, $current);
		if (self::KEY_AMAZON === $key) {
			$clean = $this->normalize_amazon_backend($clean);
		}
		$test  = $this->test($key, $clean, $posted);
		$saved = ('error' !== $test['type']) && $this->is_implemented($key);
		if ($saved) {
			$settings->set($key, array_merge($current, $clean));
			if (! empty($test['connected'])) {
				Connection_Status::mark_verified($key, $settings);
			} else {
				Connection_Status::mark_unverified($key, $settings);
			}
		}
		return array(
			'test'  => $test,
			'saved' => $saved,
		);
	}
	private function save_ec_form(): array
	{
		$settings = new Settings();
		$posted   = $this->posted(self::KEY_EC);
		$current  = (array) $settings->get(self::KEY_EC, array());
		$old_ec   = (string) ($current['ec'] ?? '');
		$clean    = $this->sanitize(self::KEY_EC, $posted, $current);
		$clean['selection'] = Product_Selector::normalize_selection(
			$this->current_ec(),
			(string) ($clean['selection'] ?? ''),
			$settings
		);
		$settings->set(self::KEY_EC, array_merge($current, $clean));
		$new_ec = (string) ($clean['ec'] ?? $old_ec);
		if ('' !== $new_ec && $old_ec !== $new_ec) {
			$notice = $this->ec_changed_category_notice($new_ec);
			if (null !== $notice) {
				return array(
					'test'  => $notice,
					'saved' => true,
				);
			}
		}
		return array(
			'test'  => array(
				'type'    => 'success',
				'message' => '',
			),
			'saved' => true,
		);
	}
	private function ec_changed_category_notice(string $ec): ?array
	{
		$scope = (new Site_Profile())->get_scope();
		$type  = (string) ($scope['site_type'] ?? Site_Profile::DEFAULT_SITE_TYPE);
		$text  = (string) ($scope['category'] ?? '');
		$foreign = array();
		if ('general' !== $type && '' !== trim($text)) {
			$foreign = (new Genre_Catalog())->foreign_entries($ec, $text);
		}
		if (! self::needs_ec_category_notice($type, $text, array() !== $foreign)) {
			return null;
		}
		return array(
			'type'    => 'warning',
			'message' => sprintf(
				__('「サイト設定」>「取り扱い商品」>「カテゴリー」に、%s以外のカテゴリーが設定されています。', GACHIREAI_TEXT_DOMAIN),
				Product_Selector::ec_label($ec)
			),
			'links'   => array(
				array(
					'url'   => esc_url(Tabs::url(Tabs::TAB_SITE) . '#gachireai-section-scope'),
					'label' => esc_html__('🔗カテゴリー編集画面', GACHIREAI_TEXT_DOMAIN),
				),
			),
		);
	}
	private static function needs_ec_category_notice(string $site_type, string $category, bool $has_foreign): bool
	{
		if ('general' === $site_type) {
			return false;
		}
		if ('' === trim($category)) {
			return false;
		}
		return $has_foreign;
	}
	private function save_llm_form(): array
	{
		$settings  = new Settings();
		$posted    = $this->posted(self::KEY_LLM);
		$providers = Model_Catalog::providers();
		$entries   = $settings->llm_entries();
		$current = array();
		foreach (array_keys($providers) as $provider) {
			$entry = (array) ($entries[$provider] ?? array());
			$current['api_key_' . $provider] = (string) ($entry['api_key'] ?? '');
			$current['model_' . $provider]   = (string) ($entry['model'] ?? '');
		}
		$clean = $this->form(self::KEY_LLM)->sanitize($posted, $current);
		foreach (array_keys($providers) as $provider) {
			$entry = array(
				'provider' => (string) $provider,
				'api_key'  => Settings::trim_secret((string) ($clean['api_key_' . $provider] ?? '')),
				'model'    => Settings::trim_secret((string) ($clean['model_' . $provider] ?? '')),
			);
			if ('' === $entry['api_key'] && '' === $entry['model']) {
				unset($entries[$provider]);
			} else {
				$entries[$provider] = $entry;
			}
		}
		$checked = $this->sanitize_llm_active($posted);
		$active  = (string) $settings->get('llm.active', '');
		if ('' !== $checked && isset($entries[$checked])) {
			$active = $checked;
		}
		if ('' === $active || ! isset($entries[$active]) || ! array_key_exists($active, $providers)) {
			$active = '';
			foreach (array_keys($providers) as $provider) {
				if (isset($entries[$provider]) && '' !== (string) ($entries[$provider]['api_key'] ?? '')) {
					$active = (string) $provider;
					break;
				}
			}
			if ('' === $active) {
				$first  = array_key_first($providers);
				$active = is_string($first) ? $first : '';
			}
		}
		$target_id = '' !== $checked ? $checked : $active;
		$target    = (array) ($entries[$target_id] ?? array());
		$test      = $this->test_llm(
			array(
				'provider' => $target_id,
				'api_key'  => (string) ($target['api_key'] ?? ''),
				'model'    => (string) ($target['model'] ?? ''),
			)
		);
		if ('error' === $test['type']) {
			return array(
				'test'  => $test,
				'saved' => false,
			);
		}
		$settings->save_llm($entries, $active);
		$connected = 'error' !== (string) $test['type'] && '' !== (string) ($target['api_key'] ?? '');
		$this->record_verified(self::KEY_LLM, $connected, $settings);
		return array(
			'test'  => $test,
			'saved' => true,
		);
	}
	private function save_jev_form(): array
	{
		$settings = new Settings();
		$posted   = $this->posted(self::KEY_JEV);
		$clean    = $this->form(self::KEY_JEV)->sanitize($posted, $settings->jev());
		$entry = array(
			'api_key' => (string) ($clean['api_key'] ?? ''),
			'model'   => (string) ($clean['model'] ?? ''),
		);
		$test = $this->test_jev($entry);
		if ('error' === $test['type']) {
			return array(
				'test'  => $test,
				'saved' => false,
			);
		}
		if ('' === $entry['api_key'] && '' === $entry['model']) {
			$settings->remove(self::KEY_JEV);
		} else {
			$settings->set(self::KEY_JEV, $entry);
		}
		$this->record_verified(self::KEY_JEV, !empty($test['connected']), $settings);
		return array(
			'test'  => $test,
			'saved' => true,
		);
	}
	private function save_image_form(): array
	{
		$settings  = new Settings();
		$current   = $settings->image();
		$posted    = $this->posted(self::KEY_IMAGE);
		$form      = $this->form(self::KEY_IMAGE);
		$clean     = $form->sanitize($posted, $current);
		$candidate = array_merge($current, $clean);
		if ('1' === (string) ($candidate['reuse_llm'] ?? '') && '' === trim((string) ($posted['api_key'] ?? ''))) {
			$candidate['api_key'] = '';
		}
		$candidate['provider']   = (string) ($candidate['provider'] ?? Image_Catalog::CLOUDFLARE);
		$candidate['account_id'] = trim((string) ($candidate['account_id'] ?? ''));
		$model                   = trim((string) ($candidate['model'] ?? ''));
		$candidate['model']      = '' === $model
			? ''
			: Image_Catalog::normalize_model($candidate['provider'], $model);
		$key = $settings->image_api_key($candidate);
		if ('' === $key) {
			$test = array(
				'type'    => 'error',
				'message' => __('API キー（または AI設定の同じプロバイダーのキー）を設定してください。', GACHIREAI_TEXT_DOMAIN),
			);
		} elseif (Image_Catalog::CLOUDFLARE === $candidate['provider'] && 1 !== preg_match('/^[a-f0-9]{32}$/i', $candidate['account_id'])) {
			$test = array(
				'type'    => 'error',
				'message' => __('Cloudflare の Account ID（32桁の16進）を入力してください。', GACHIREAI_TEXT_DOMAIN),
			);
		} else {
			try {
				$test = Image_Manager::test(Image_Manager::provider($candidate, $key));
			} catch (\Throwable $e) {
				$test = array(
					'type'    => 'error',
					'message' => $e->getMessage(),
				);
			}
		}
		if ('error' === (string) $test['type']) {
			return array(
				'test'  => $test,
				'saved' => false,
			);
		}
		$settings->set(self::KEY_IMAGE, $candidate);
		$this->record_verified(self::KEY_IMAGE, 'error' !== (string) $test['type'] && '' !== $key, $settings);
		return array(
			'test'  => $test,
			'saved' => true,
		);
	}
	private function record_verified(string $group, bool $connected, Settings $settings): void
	{
		if ($connected) {
			Connection_Status::mark_verified($group, $settings);
		} else {
			Connection_Status::mark_unverified($group, $settings);
		}
	}
	private function sanitize_llm_active(array $posted): string
	{
		$active = isset($posted['active']) && is_scalar($posted['active']) ? (string) $posted['active'] : '';
		return '' !== $active && array_key_exists($active, Model_Catalog::providers()) ? $active : '';
	}
	public function ajax_save_llm(): void
	{
		$this->ajax_save(self::KEY_LLM);
	}
	public function ajax_save_jev(): void
	{
		$this->ajax_save(self::KEY_JEV);
	}
	public function ajax_save_image(): void
	{
		$this->ajax_save(self::KEY_IMAGE);
	}
	public function ajax_save_rakuten(): void
	{
		$this->ajax_save(self::KEY_RAKUTEN);
	}
	public function ajax_save_amazon(): void
	{
		$this->ajax_save(self::KEY_AMAZON);
	}
	public function ajax_save_yahoo(): void
	{
		$this->ajax_save(self::KEY_YAHOO);
	}
	public function ajax_save_ec(): void
	{
		$this->ajax_save(self::KEY_EC);
	}
	private function ajax_save(string $key): void
	{
		$this->guard_ajax(self::NONCE_ACTION);
		$result = $this->save_and_test($key);
		if ($result['saved']) {
			$this->record_setting_save(Tabs::label($this->tab_of($key)));
		}
		$messages = array();
		if ('' !== trim((string) $result['test']['message'])) {
			$message              = $this->message((string) $result['test']['type'], esc_html((string) $result['test']['message']));
			$message['msg_links'] = (array) ($result['test']['links'] ?? array());
			$messages[]           = $message;
		}
		if (! $this->is_implemented($key)) {
			$footer = $messages;
		} elseif ($result['saved']) {
			$footer = array_merge(
				$messages,
				array($this->message('success', esc_html__('保存しました。', GACHIREAI_TEXT_DOMAIN)))
			);
		} else {
			$footer = array_merge(
				$messages,
				array($this->message('error', esc_html__('接続テストに失敗したため、保存しませんでした。', GACHIREAI_TEXT_DOMAIN)))
			);
		}
		$footer = $this->attach_contact_links($footer);
		$extra = array();
		if (self::TAB_ASP === $this->tab_of($key) && self::KEY_EC !== $key) {
			try {
				$extra['ec_form_html'] = $this->render_template_string(
					'admin/api-affiliate-form',
					$this->form_fields(self::KEY_EC)
				);
			} catch (\Throwable $e) {
				$extra['ec_form_html'] = '';
			}
		}
		$this->send_form_html(
			fn(): string => $this->render_template_string(
				'admin/api-affiliate-form',
				$this->form_fields($key, array(), $footer)
			),
			$extra
		);
	}
	private function attach_contact_links(array $messages): array
	{
		$email = 'info@tonkatsutei.com';
		foreach ($messages as $index => $message) {
			$text = (string) ($message['msg_text'] ?? '');
			if (! str_contains($text, $email)) {
				continue;
			}
			$messages[$index]['msg_text'] = trim(str_replace($email, '', $text));
			$messages[$index]['msg_links'] = array(
				array(
					'url'   => esc_url('mailto:' . $email),
					'label' => esc_html($email),
				),
			);
		}
		return $messages;
	}
	private function posted(string $key): array
	{
		return isset($_POST[$key]) && is_array($_POST[$key])
			? (array) wp_unslash($_POST[$key])
			: array();
	}
	private function sanitize(string $key, array $raw, array $current): array
	{
		return $this->form($key)->sanitize($raw, $current);
	}
	private function normalize_amazon_backend(array $clean): array
	{
		$free = ! empty($clean[Amazon_Client::USE_FREE_AMZN_CREATORS]);
		if ($free && empty($clean[Amazon_Client::USE_CREATORS])) {
			$clean[Amazon_Client::USE_FREE_AMZN_CREATORS] = '1';
			$clean[Amazon_Client::USE_CREATORS]           = '';
			return $clean;
		}
		$clean[Amazon_Client::USE_FREE_AMZN_CREATORS] = '';
		$clean[Amazon_Client::USE_CREATORS]           = '1';
		return $clean;
	}
	private function test(string $key, array $values, array $posted = array()): array
	{
		switch ($key) {
			case self::KEY_JEV:
				return $this->test_jev($values);
			case self::KEY_RAKUTEN:
				return $this->test_rakuten($values);
			case self::KEY_AMAZON:
				return (new Amazon_Client())->test($values);
			case self::KEY_YAHOO:
				return $this->test_yahoo($values, $posted);
		}
		return $this->test_llm($values);
	}
	public function test_llm(array $llm): array
	{
		$api_key = (string) ($llm['api_key'] ?? '');
		if ('' === $api_key) {
			return array(
				'type'    => 'warning',
				'message' => __('API キーが未設定です。入力してから接続テストを実行してください。', GACHIREAI_TEXT_DOMAIN),
			);
		}
		try {
			$provider = Manager::provider($llm);
			$response = Manager::generate(
				$provider,
				__('接続テストです。', GACHIREAI_TEXT_DOMAIN),
				__(self::TEST_PROMPT, GACHIREAI_TEXT_DOMAIN),
				array(
					'temperature' => 0.0,
					'max_tokens'  => self::TEST_MAX_TOKENS,
					'timeout'     => self::TEST_TIMEOUT,
				)
			);
			$content = trim((string) ($response['content'] ?? ''));
			$finish  = (string) ($response['finish_reason'] ?? '');
			$model   = (string) ($llm['model'] ?? '');
			$model   = '' !== $model ? $model : __('既定モデル', GACHIREAI_TEXT_DOMAIN);
			if ('' === $content) {
				return array(
					'type'    => 'warning',
					'message' => sprintf(
						__('接続は成功しましたが、応答本文が空でした（モデル: %1$s / finish_reason: %2$s）。推論モデルは推論にもトークンを使うため、モデルを変えるか max_tokens を増やしてください。', GACHIREAI_TEXT_DOMAIN),
						$model,
						'' !== $finish ? $finish : __('不明', GACHIREAI_TEXT_DOMAIN)
					),
				);
			}
			return array(
				'type'    => 'success',
				'message' => __('接続テストに成功しました。', GACHIREAI_TEXT_DOMAIN),
			);
		} catch (\Throwable $e) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					__('接続テストに失敗しました: %s', GACHIREAI_TEXT_DOMAIN),
					$this->scrub($e->getMessage(), $api_key)
				),
			);
		}
	}
	private function test_jev(array $values): array
	{
		return (new Jev_Client(
			(string) ($values['api_key'] ?? ''),
			(string) ($values['model'] ?? '')
		))->test();
	}
	private function scrub(string $message, string ...$secrets): string
	{
		foreach ($secrets as $secret) {
			if ('' !== $secret) {
				$message = str_replace($secret, '***', $message);
			}
		}
		return mb_substr(trim($message), 0, self::TEST_MESSAGE_MAX);
	}
	private function form_keys(string $tab): array
	{
		if (self::TAB_ASP === $tab) {
			return array(self::KEY_EC, self::KEY_RAKUTEN, self::KEY_AMAZON, self::KEY_YAHOO);
		}
		return array(self::KEY_LLM, self::KEY_JEV, self::KEY_IMAGE);
	}
	public static function llm_section_key(): string
	{
		return self::KEY_LLM;
	}
	private function tab_of(string $key): string
	{
		return in_array($key, array(self::KEY_EC, self::KEY_RAKUTEN, self::KEY_AMAZON, self::KEY_YAHOO), true)
			? self::TAB_ASP
			: self::TAB_LLM;
	}
	private function is_implemented(string $key): bool
	{
		return self::KEY_LLM === $key
			|| self::KEY_JEV === $key
			|| self::KEY_IMAGE === $key
			|| in_array($key, array(self::KEY_EC, self::KEY_RAKUTEN, self::KEY_AMAZON, self::KEY_YAHOO), true);
	}
	private function action(string $key): string
	{
		return (string) (self::ACTIONS[$key] ?? '');
	}
	private function ajax_action(string $key): string
	{
		return (string) (self::AJAX_ACTIONS[$key] ?? '');
	}
	private function secret_keys(string $key): array
	{
		if (self::KEY_LLM === $key) {
			$keys = array();
			foreach (array_keys(Model_Catalog::providers()) as $provider) {
				$keys[] = 'api_key_' . $provider;
			}
			return $keys;
		}
		if (self::KEY_RAKUTEN === $key) {
			return array('access_key');
		}
		if (self::KEY_AMAZON === $key) {
			return array(Amazon_Client::CLIENT_SECRET);
		}
		if (self::KEY_JEV === $key) {
			return array('api_key');
		}
		if (self::KEY_IMAGE === $key) {
			return array('api_key');
		}
		return array();
	}
	private function field_keys(string $key): array
	{
		if (self::KEY_LLM === $key) {
			$keys = array('active');
			foreach (array_keys(Model_Catalog::providers()) as $provider) {
				$keys[] = 'api_key_' . $provider;
				$keys[] = 'model_' . $provider;
			}
			return $keys;
		}
		if (self::KEY_JEV === $key) {
			return array('engine', 'api_key', 'model');
		}
		if (self::KEY_IMAGE === $key) {
			return array('provider', 'account_id', 'api_key', 'reuse_llm', 'model', 'steps');
		}
		if (self::KEY_EC === $key) {
			return array('ec', 'selection');
		}
		if (self::KEY_RAKUTEN === $key) {
			return array('application_id', 'access_key', 'affiliate_id', 'site_url');
		}
		if (self::KEY_AMAZON === $key) {
			return array(
				Amazon_Client::USE_FREE_AMZN_CREATORS,
				Amazon_Client::USE_CREATORS,
				Amazon_Client::CLIENT_ID,
				Amazon_Client::CLIENT_SECRET,
				Amazon_Client::VERSION,
				'partner_tag',
			);
		}
		if (self::KEY_YAHOO === $key) {
			return array('client_id', 'affiliate_code');
		}
		return array();
	}
	private function field_prefix(string $key): string
	{
		return $key;
	}
	private function stored_values(string $key): array
	{
		$settings = new Settings();
		if (self::KEY_LLM === $key) {
			$entries = $settings->llm_entries();
			$values  = array('active' => $settings->active_llm_id());
			foreach (array_keys(Model_Catalog::providers()) as $provider) {
				$entry = (array) ($entries[$provider] ?? array());
				$values['api_key_' . $provider] = (string) ($entry['api_key'] ?? '');
				$values['model_' . $provider]   = (string) ($entry['model'] ?? '');
			}
			return $values;
		}
		if (self::KEY_JEV === $key) {
			$jev           = $settings->jev();
			$jev['engine'] = 'jev';
			return $jev;
		}
		if (self::KEY_IMAGE === $key) {
			return $settings->image();
		}
		if (self::KEY_EC === $key) {
			return array_merge((array) $settings->get(self::KEY_EC, array()), $this->posted(self::KEY_EC));
		}
		if (self::KEY_AMAZON === $key) {
			$values = (array) $settings->get($key, array());
			if (! isset($values[Amazon_Client::USE_FREE_AMZN_CREATORS]) && ! isset($values[Amazon_Client::USE_CREATORS])) {
				$values[Amazon_Client::USE_FREE_AMZN_CREATORS] = '1';
			}
			return $values;
		}
		return (array) $settings->get($key, array());
	}
	private function current_ec(): string
	{
		$posted = $this->posted(self::KEY_EC);
		$ec     = isset($posted['ec']) && is_scalar($posted['ec']) ? (string) $posted['ec'] : '';
		if ('' === $ec) {
			$ec = (string) (new Settings())->get(self::KEY_EC . '.ec', '');
		}
		return Product_Selector::normalize_ec($ec);
	}
	private function current_tab(): string
	{
		$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : '';
		return in_array($tab, $this->tab_slugs(), true) ? $tab : self::TAB_LLM;
	}
	private function tab_slugs(): array
	{
		return array(self::TAB_LLM, self::TAB_ASP);
	}
	private function section_title(string $key): string
	{
		switch ($key) {
			case self::KEY_LLM:
				return __('文章生成AI', GACHIREAI_TEXT_DOMAIN);
			case self::KEY_JEV:
				return __('判定AI', GACHIREAI_TEXT_DOMAIN);
			case self::KEY_IMAGE:
				return __('画像生成AI', GACHIREAI_TEXT_DOMAIN);
			case self::KEY_EC:
				return __('メインEC', GACHIREAI_TEXT_DOMAIN);
			case self::KEY_RAKUTEN:
				return __('楽天市場', GACHIREAI_TEXT_DOMAIN);
			case self::KEY_AMAZON:
				return __('Amazon', GACHIREAI_TEXT_DOMAIN);
			case self::KEY_YAHOO:
				return __('Yahoo!ショッピング', GACHIREAI_TEXT_DOMAIN);
		}
		return '';
	}
	private function section_description(string $key): string
	{
		if (self::KEY_LLM === $key) {
			return __('記事の生成に使う文章生成AIの設定です。', GACHIREAI_TEXT_DOMAIN);
		}
		if (self::KEY_JEV === $key) {
			return __('レビューを判定する判定AI（Jev）の設定です。判定AI（Jev）を使うと、収集したレビューを「採用・保留・除外」に自動判定でき、記事に使う素材の品質を保てます（商品に無関係な投稿・宣伝目的の投稿などを除外。判定専用AIのため高速・低コスト）。判定AI（Jev）は安価な有料サービスです（利用には Jev（typesafe.ai）のアカウントと API キーが必要です）。必須ではありません（任意設定）。未設定でもレビューの収集・記事生成は行われ、判定だけがスキップされます。', GACHIREAI_TEXT_DOMAIN);
		}
		if (self::KEY_IMAGE === $key) {
			return __("ファビコン・プロフィール画像・アイキャッチなどの生成に使う画像生成AIの設定です。\n画像生成は PRO, BASIC ライセンスのみです（BASIC は一部制限あり）。", GACHIREAI_TEXT_DOMAIN);
		}
		if (self::KEY_EC === $key) {
			return __('記事にする商品を選ぶ元の EC（ショッピングサイト）と、商品の選び方（優先順）を決めます。', GACHIREAI_TEXT_DOMAIN);
		}
		if (self::KEY_AMAZON === $key) {
			return __('Amazon の商品データの取得方法を選びます。', GACHIREAI_TEXT_DOMAIN);
		}
		if (self::KEY_YAHOO === $key) {
			return __("Yahoo!ショッピング の設定です。\n1キーあたりの利用上限の目安: 50,000リクエスト/日、1クエリー/秒", GACHIREAI_TEXT_DOMAIN);
		}
		return '';
	}
	private function model(string $tab): array
	{
		$forms      = array();
		$head_forms = array();
		foreach ($this->form_keys($tab) as $key) {
			$form = $this->form_fields($key);
			if (self::KEY_EC === $key) {
				$head_forms[] = $form;
				continue;
			}
			$forms[] = $form;
		}
		return array(
			'page_title'    => esc_html(Tabs::label($tab)),
			'notices'       => $this->notices($tab),
			'tabs'          => $this->tab_nav(),
			'head_forms'    => $head_forms,
			'forms'         => $forms,
		);
	}
	private function notices(string $tab): array
	{
		$notices = array();
		$status  = $this->status_notice($tab);
		if (null !== $status) {
			$notices[] = $status;
		}
		return $notices;
	}
	private function status_notice(string $tab): ?array
	{
		if (self::TAB_LLM === $tab) {
			$llm = (new Settings())->active_llm();
			if ('' === (string) $llm['api_key']) {
				return $this->notice(
					'warning',
					esc_html__('使用するAIの API キーが未設定です。', GACHIREAI_TEXT_DOMAIN)
				);
			}
			return null;
		}
		$selector = new Product_Selector();
		if (Product_Selector::AVAILABILITY_READY === $selector->availability()) {
			return null;
		}
		return $this->notice(
			'warning',
			sprintf(
				esc_html__('メインEC をまだ選べません: %s', GACHIREAI_TEXT_DOMAIN),
				esc_html($selector->unavailable_message())
			)
		);
	}
	private function form_fields(string $key, array $block_messages = array(), array $form_messages = array()): array
	{
		$values = $this->stored_values($key);
		return array(
			'form_action'    => esc_url(Tabs::url($this->tab_of($key))),
			'form_id'        => esc_attr('gachireai-form-' . $key),
			'hidden_fields'  => array(
				array(
					'name'  => esc_attr(self::ACTION_FIELD),
					'value' => esc_attr($this->action($key)),
				),
				array(
					'name'  => esc_attr('_wpnonce'),
					'value' => esc_attr(wp_create_nonce(self::NONCE_ACTION)),
				),
			),
			'sections'       => $this->sections($key, $values, $block_messages, $form_messages),
			'submit_label'   => '',
			'submit_action'  => '',
			'submit_testing' => false,
			'form_messages'  => array(),
		);
	}
	private function save_button(string $key): array
	{
		$btn_disabled = (self::KEY_JEV === $key && ! Status::capabilities()->can_judge_with_jev())
			|| (self::KEY_IMAGE === $key && Capabilities::IMAGE_NONE === Status::capabilities()->image_background_mode());
		return array(
			'btn_name'     => '',
			'btn_value'    => '',
			'btn_label'    => esc_html__('保存', GACHIREAI_TEXT_DOMAIN),
			'btn_primary'  => true,
			'btn_disabled' => $btn_disabled,
			'btn_title'    => '',
			'btn_action'   => esc_attr($this->ajax_action($key)),
			'btn_testing'  => $this->is_implemented($key) && self::KEY_EC !== $key,
		);
	}
	private function section_note(string $key): string
	{
		if (self::KEY_JEV === $key && ! Status::capabilities()->can_judge_with_jev()) {
			return esc_html__('レビュー判定は PRO, BASIC ライセンスのみ利用できます。', GACHIREAI_TEXT_DOMAIN);
		}
		if (self::KEY_IMAGE === $key && Capabilities::IMAGE_NONE === Status::capabilities()->image_background_mode()) {
			return esc_html__('画像生成は PRO, BASIC ライセンスのみです。', GACHIREAI_TEXT_DOMAIN);
		}
		return '';
	}
	private function common_disabled_overrides(string $key, array $keys, array $overrides): array
	{
		if (! is_multisite() || ! in_array($key, Settings::COMMON_GROUPS, true)) {
			return $overrides;
		}
		$use_common = (new Settings())->uses_common($key);
		foreach ($keys as $k) {
			$overrides[$k] = array_merge((array) ($overrides[$k] ?? array()), array('common_target' => $key));
			if ($use_common) {
				$overrides[$k] = array_merge((array) $overrides[$k], array('field_disabled' => true));
			}
		}
		return $overrides;
	}
	private function sections(string $key, array $values, array $block_messages, array $form_messages): array
	{
		if (Connection_Status::is_revoked($key)) {
			$form_messages[] = $this->message('warning', esc_html(Connection_Status::revocation_message()));
		}
		if (! $this->is_implemented($key)) {
			return array(
				array(
					'title'            => esc_html($this->section_title($key)),
					'description'      => esc_html__('この事業者は器のみです（設定項目は実装時に追加します）。', GACHIREAI_TEXT_DOMAIN),
					'section_key'      => esc_attr($key),
					'section_switch'   => $this->common_switch_model($key),
					'blocks'           => array(),
					'section_buttons'  => array($this->save_button($key)),
					'section_note'     => $this->section_note($key),
					'section_messages' => $form_messages,
				),
			);
		}
		$blocks = array();
		foreach ($this->block_definitions($key) as $block) {
			$blocks[] = array(
				'block_title'    => esc_html((string) ($block['title'] ?? '')),
				'fields'         => $this->form($key)->build_list(
					$this->display_values($key, $values),
					(array) ($block['keys'] ?? array()),
					$this->field_prefix($key),
					$this->common_disabled_overrides($key, (array) ($block['keys'] ?? array()), $this->secret_overrides($key, $values))
				),
				'block_buttons'  => array(),
				'block_messages' => $block_messages,
			);
		}
		return array(
			array(
				'title'            => esc_html($this->section_title($key)),
				'description'      => esc_html($this->section_description($key)),
				'section_key'      => esc_attr($key),
				'section_switch'   => $this->common_switch_model($key),
				'blocks'           => $blocks,
				'section_buttons'  => array($this->save_button($key)),
				'section_note'     => $this->section_note($key),
				'section_messages' => $form_messages,
			),
		);
	}
	private function block_definitions(string $key): array
	{
		if (self::KEY_AMAZON === $key) {
			return array(
				array(
					'title' => __('API設定', GACHIREAI_TEXT_DOMAIN),
					'keys'  => array(
						Amazon_Client::USE_FREE_AMZN_CREATORS,
						Amazon_Client::USE_CREATORS,
						Amazon_Client::CLIENT_ID,
						Amazon_Client::CLIENT_SECRET,
						Amazon_Client::VERSION,
					),
				),
				array(
					'title' => __('アフィリエイト設定', GACHIREAI_TEXT_DOMAIN),
					'keys'  => array('partner_tag'),
				),
			);
		}
		return array(
			array(
				'title' => '',
				'keys'  => $this->field_keys($key),
			),
		);
	}
	private function display_values(string $key, array $values): array
	{
		foreach ($this->secret_keys($key) as $secret_key) {
			$values[$secret_key] = '';
		}
		if (self::KEY_AMAZON === $key) {
			$values[Amazon_Client::VERSION] = Amazon_Client::normalize_version();
		}
		return $values;
	}
	private function secret_overrides(string $key, array $values): array
	{
		$overrides = array();
		if (self::KEY_LLM === $key) {
			$definitions = $this->definitions($key);
			foreach ($this->secret_keys($key) as $secret_key) {
				$secret = (string) ($values[$secret_key] ?? '');
				$has    = '' !== $secret;
				$note   = (string) ($definitions[$secret_key]['description'] ?? '');
				$overrides[$secret_key] = array(
					'placeholder' => $has
						? Form::mask_secret($secret)
						: __('値を入力', GACHIREAI_TEXT_DOMAIN),
					'description' => trim(
						$note . ' ' . ($has
							? __('保存した値は再表示できません。', GACHIREAI_TEXT_DOMAIN)
							: __('現在: 未設定。', GACHIREAI_TEXT_DOMAIN))
					),
				);
			}
			return $overrides;
		}
		if (self::KEY_IMAGE === $key) {
			$secret = (string) ($values['api_key'] ?? '');
			$has    = '' !== $secret;
			$reuse  = '1' === (string) ($values['reuse_llm'] ?? '');
			$note   = (string) ($this->definitions($key)['api_key']['description'] ?? '');
			return array(
				'api_key' => array(
					'placeholder' => $has
						? Form::mask_secret($secret)
						: ($reuse
							? __('AI設定のキーを使用（値は表示しません）', GACHIREAI_TEXT_DOMAIN)
							: __('値を入力', GACHIREAI_TEXT_DOMAIN)),
					'description' => trim(
						$note . ' ' . ($has
							? __('保存した値は再表示できません。', GACHIREAI_TEXT_DOMAIN)
							: __('現在: 未設定。', GACHIREAI_TEXT_DOMAIN))
					),
				),
			);
		}
		foreach ($this->secret_keys($key) as $secret_key) {
			$secret = (string) ($values[$secret_key] ?? '');
			$has    = ('' !== $secret);
			$overrides[$secret_key] = array(
				'placeholder' => $has
					? Form::mask_secret($secret)
					: __('値を入力', GACHIREAI_TEXT_DOMAIN),
				'description' => $has
					? __('保存した値は再表示できません。', GACHIREAI_TEXT_DOMAIN)
					: __('現在: 未設定。', GACHIREAI_TEXT_DOMAIN),
			);
		}
		return $overrides;
	}
	private function test_rakuten(array $values): array
	{
		return (new Rakuten_Client())->test($values);
	}
	private function test_yahoo(array $values, array $posted = array()): array
	{
		return (new Yahoo_Client())->test($values, $posted);
	}
	private function definitions(string $key): array
	{
		switch ($key) {
			case self::KEY_LLM:
				return $this->definitions_llm();
			case self::KEY_JEV:
				return $this->definitions_jev();
			case self::KEY_IMAGE:
				return $this->definitions_image();
			case self::KEY_EC:
				return $this->definitions_ec();
			case self::KEY_RAKUTEN:
				return $this->definitions_rakuten();
			case self::KEY_AMAZON:
				return $this->definitions_amazon();
			case self::KEY_YAHOO:
				return $this->definitions_yahoo();
		}
		return array();
	}
	private function definitions_llm(): array
	{
		$providers   = Model_Catalog::providers();
		$definitions = array(
			'active' => array(
				'label'       => __('使用するAI', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'radio',
				'options'     => $providers,
				'driver'      => 'llm_provider',
				'description' => __('記事の生成に使うAIを選びます。', GACHIREAI_TEXT_DOMAIN),
			),
		);
		foreach (array_keys($providers) as $provider) {
			$provider = (string) $provider;
			$definitions['api_key_' . $provider] = array(
				'label'       => __('API キー', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'password',
				'max'         => 200,
				'row_depends' => array(
					'on'     => 'llm_provider',
					'values' => array($provider),
				),
			);
			$definitions['model_' . $provider] = array(
				'label'       => __('モデル', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'datalist',
				'max'         => 100,
				'options'     => Model_Catalog::models($provider),
				'placeholder' => Model_Catalog::default_model($provider),
				'row_depends' => array(
					'on'     => 'llm_provider',
					'values' => array($provider),
				),
				'description' => sprintf(
					__('空欄なら既定モデル（%s）を使います。', GACHIREAI_TEXT_DOMAIN),
					Model_Catalog::default_model($provider)
				),
			);
		}
		return $definitions;
	}
	private function definitions_jev(): array
	{
		$disabled = ! Status::capabilities()->can_judge_with_jev();
		return array(
			'engine'  => array(
				'label'       => __('使用するAI', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'radio',
				'options'     => array('jev' => 'Jev'),
				'default'     => 'jev',
				'disabled'    => $disabled ? array('jev') : array(),
				'description' => __('レビュー判定は PRO, BASIC ライセンスのみです。', GACHIREAI_TEXT_DOMAIN),
			),
			'api_key' => array(
				'label'       => __('API キー', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'password',
				'max'         => 200,
				'field_disabled' => $disabled,
				'description' => __('判定AI（Jev）は安価な有料サービスです（任意設定）。API キーは Jev（typesafe.ai）で取得してください。未設定でもレビューの収集・記事生成は行われます。', GACHIREAI_TEXT_DOMAIN),
			),
			'model'   => array(
				'label'       => __('モデル', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'datalist',
				'max'         => 100,
				'options'     => Jev_Client::model_choices(),
				'placeholder' => Jev_Client::default_model(),
				'field_disabled' => $disabled,
				'description' => sprintf(
					__('空欄なら既定モデル（%s）を使います。', GACHIREAI_TEXT_DOMAIN),
					Jev_Client::default_model()
				),
			),
		);
	}
	private function definitions_image(): array
	{
		$defaults = array();
		foreach (array_keys(Image_Catalog::providers()) as $provider) {
			$defaults[] = Image_Catalog::default_model((string) $provider);
		}
		$disabled = Capabilities::IMAGE_NONE === Status::capabilities()->image_background_mode();
		return array(
			'provider'   => array(
				'label'       => __('使用するAI', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'radio',
				'options'     => Image_Catalog::provider_choices(),
				'default'     => Image_Catalog::CLOUDFLARE,
				'driver'      => 'image_provider',
				'disabled'    => $disabled ? array_keys(Image_Catalog::provider_choices()) : array(),
				'description' => __('選択したAIを使って画像を生成します。', GACHIREAI_TEXT_DOMAIN),
			),
			'account_id' => array(
				'label'       => __('Account ID（Cloudflare）', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'text',
				'max'         => 64,
				'field_disabled' => $disabled,
				'row_depends' => array(
					'on'     => 'image_provider',
					'values' => array(Image_Catalog::CLOUDFLARE),
				),
				'description' => __('Cloudflare の Account ID（32 桁）です。', GACHIREAI_TEXT_DOMAIN),
			),
			'api_key'    => array(
				'label'       => __('API キー / API トークン', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'password',
				'max'         => 300,
				'field_disabled' => $disabled,
				'description' => __('保存した値は再表示できません。', GACHIREAI_TEXT_DOMAIN),
				'option_depends'  => 'image_provider',
				'ec_descriptions' => array(
					Image_Catalog::CLOUDFLARE => array(
						'text'  => __('Cloudflare は「Workers AI: Read」権限が必要です。', GACHIREAI_TEXT_DOMAIN),
						'class' => 'gachireai-field__warning',
					),
				),
			),
			'reuse_llm'  => array(
				'label'       => __('AI設定のキーを使う', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'checkbox',
				'options'     => array('1' => __('同じプロバイダーの API キーを AI設定から流用する', GACHIREAI_TEXT_DOMAIN)),
				'disabled'    => $disabled ? array('1') : array(),
				'row_depends' => array(
					'on'     => 'image_provider',
					'values' => array(Image_Catalog::GOOGLE, Image_Catalog::OPENAI),
				),
				'description' => __('画像生成APIキーが空のとき、文章生成AIキーを使います。', GACHIREAI_TEXT_DOMAIN),
			),
			'model'      => array(
				'label'       => __('モデル', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'datalist',
				'options'     => Image_Catalog::all_models(),
				'max'         => 200,
				'field_disabled' => $disabled,
				'placeholder' => implode(' / ', $defaults),
				'description' => __('空欄なら既定モデルを使います。', GACHIREAI_TEXT_DOMAIN),
			),
			'steps'      => array(
				'label'       => __('ステップ数（Cloudflare）', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'number',
				'min'         => 1,
				'max'         => 8,
				'step'        => 1,
				'default'     => 4,
				'field_disabled' => $disabled,
				'row_depends' => array(
					'on'     => 'image_provider',
					'values' => array(Image_Catalog::CLOUDFLARE),
				),
				'description' => __('増やすと品質が上がりますが、生成時間と料金も増えます。', GACHIREAI_TEXT_DOMAIN),
			),
		);
	}
	private function definitions_ec(): array
	{
		$ec       = $this->current_ec();
		$settings = new Settings();
		$ec_options  = array();
		$ec_disabled = array();
		foreach (
			array(
				Product_Selector::EC_RAKUTEN => __('楽天市場', GACHIREAI_TEXT_DOMAIN),
				Product_Selector::EC_YAHOO   => __('Yahoo!ショッピング', GACHIREAI_TEXT_DOMAIN),
				Product_Selector::EC_AMAZON  => __('Amazon', GACHIREAI_TEXT_DOMAIN),
			) as $slug => $label
		) {
			if (! Status::capabilities()->can_use_ec($slug)) {
				$ec_options[$slug] = sprintf(
					__('%s（このライセンスでは使えません）', GACHIREAI_TEXT_DOMAIN),
					$label
				);
				$ec_disabled[] = $slug;
				continue;
			}
			$state = Product_Selector::availability_of($slug, $settings);
			if (Product_Selector::AVAILABILITY_READY === $state) {
				$ec_options[$slug] = $label;
				continue;
			}
			if (Product_Selector::AVAILABILITY_UNSUPPORTED === $state) {
				$ec_options[$slug] = sprintf(
					__('%s（準備中）', GACHIREAI_TEXT_DOMAIN),
					$label
				);
			} elseif (Product_Selector::AVAILABILITY_MISSING_AFFILIATE === $state) {
				$ec_options[$slug] = sprintf(
					__('%s（アフィリエイトID/コード未設定）', GACHIREAI_TEXT_DOMAIN),
					$label
				);
			} else {
				$ec_options[$slug] = sprintf(
					__('%s（未接続）', GACHIREAI_TEXT_DOMAIN),
					$label
				);
			}
			$ec_disabled[] = $slug;
		}
		$selection_options = array();
		$option_ec         = array();
		foreach (array(Product_Selector::EC_RAKUTEN, Product_Selector::EC_YAHOO, Product_Selector::EC_AMAZON) as $slug) {
			if (! Status::capabilities()->can_use_ec($slug)) {
				continue;
			}
			foreach (Product_Selector::selection_options($slug, $settings) as $value => $label) {
				$selection_options[(string) $value] = $label;
				$option_ec[(string) $value]         = $slug;
			}
		}
		return array(
			'ec'        => array(
				'label'       => __('メインEC', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'radio',
				'options'     => $ec_options,
				'disabled'    => $ec_disabled,
				'default'     => Product_Selector::EC_RAKUTEN,
				'driver'      => 'ec',
				'description' => __('商品を選ぶ元にする EC です。', GACHIREAI_TEXT_DOMAIN),
			),
			'selection' => array(
				'label'           => __('商品の選び方', GACHIREAI_TEXT_DOMAIN),
				'type'            => 'radio',
				'options'         => $selection_options,
				'option_ec'       => $option_ec,
				'option_depends'  => 'ec',
				'default'         => Product_Selector::selection_default($ec, $settings),
				'ec_descriptions' => array(
					Product_Selector::EC_RAKUTEN => Product_Selector::selection_description(Product_Selector::EC_RAKUTEN, $settings),
					Product_Selector::EC_YAHOO   => Product_Selector::selection_description(Product_Selector::EC_YAHOO, $settings),
					Product_Selector::EC_AMAZON  => Product_Selector::selection_description(Product_Selector::EC_AMAZON, $settings),
				),
			),
		);
	}
	private function definitions_amazon(): array
	{
		$free_amzn_disabled = ! Amazon_Client::free_amzn_has_usable_service();
		$definitions = array(
			Amazon_Client::USE_FREE_AMZN_CREATORS => array(
				'label'           => __('Free AMZN Creators API', GACHIREAI_TEXT_DOMAIN),
				'type'            => 'checkbox',
				'options'         => array('1' => __('使用する', GACHIREAI_TEXT_DOMAIN)),
				'exclusive'       => true,
				'exclusive_group' => self::EXCLUSIVE_GROUP_AMAZON,
				'disabled'        => $free_amzn_disabled ? array('1') : array(),
				'description'     => __('Amazon公式APIをまだ使えない方はこちらをご利用ください。先にスクレイピング設定が必要です。[AMZN]が付いているサービスを最低一つ有効にすることでチェックできるようになります。', GACHIREAI_TEXT_DOMAIN),
			),
			'partner_tag' => array(
				'label'       => __('パートナータグ（アソシエイトタグ）', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'text',
				'max'         => 100,
				'description' => __('アソシエイトのトラッキング ID（例: example-22）です。', GACHIREAI_TEXT_DOMAIN),
			),
		);
		$definitions[Amazon_Client::USE_CREATORS] = array(
			'label'           => __('Creators API', GACHIREAI_TEXT_DOMAIN),
			'type'            => 'checkbox',
			'options'         => array('1' => __('使用する', GACHIREAI_TEXT_DOMAIN)),
			'exclusive'       => true,
			'exclusive_group' => self::EXCLUSIVE_GROUP_AMAZON,
			'description'     => __('Amazon公式API Creators API（旧 PA-API 5.0 の後継）を使います。', GACHIREAI_TEXT_DOMAIN),
		);
		$definitions[Amazon_Client::CLIENT_ID] = array(
			'label'       => __('Credential ID', GACHIREAI_TEXT_DOMAIN),
			'type'        => 'text',
			'max'         => 200,
			'description' => __('Amazon アソシエイトの「CreatorsAPI」タブで作成した資格情報の ID です。', GACHIREAI_TEXT_DOMAIN),
		);
		$definitions[Amazon_Client::CLIENT_SECRET] = array(
			'label'       => __('Credential Secret', GACHIREAI_TEXT_DOMAIN),
			'type'        => 'password',
			'max'         => 200,
			'description' => __('Credential ID と対になる秘密の値です。', GACHIREAI_TEXT_DOMAIN),
		);
		$definitions[Amazon_Client::VERSION] = array(
			'label'       => __('Credential version', GACHIREAI_TEXT_DOMAIN),
			'type'        => 'select',
			'options'     => Amazon_Client::version_options(),
			'default'     => '3.3',
			'description' => __('資格情報の作成時に割り当てられた version です（Amazon.co.jp は 3.3 固定）。', GACHIREAI_TEXT_DOMAIN),
		);
		return $definitions;
	}
	private function definitions_yahoo(): array
	{
		return array(
			'client_id'    => array(
				'label'       => __('Client ID（アプリケーションID）', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'text',
				'max'         => 200,
				'description' => __('Yahoo!デベロッパーネットワークで発行した Client ID です（ショッピングAPI の利用権限が必要）。', GACHIREAI_TEXT_DOMAIN),
			),
			'affiliate_code' => array(
				'label'       => __('アフィリエイトコード（バリューコマース）', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'textarea',
				'rows'        => 3,
				'max'         => 300,
				'normalize'   => static function ($value): string {
					return Yahoo_Client::extract_affiliate_url((string) $value);
				},
				'description' => __('バリューコマースの「自由テキストリンク」の HTML をそのまま貼り付けます（任意・sid 単体では動きません）。', GACHIREAI_TEXT_DOMAIN),
			),
		);
	}
	private function definitions_rakuten(): array
	{
		return array(
			'application_id' => array(
				'label'       => __('アプリケーションID', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'text',
				'max'         => 100,
				'description' => __('楽天ウェブサービスのアプリケーションIDです。', GACHIREAI_TEXT_DOMAIN),
			),
			'access_key'     => array(
				'label'       => __('アクセスキー', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'password',
				'max'         => 200,
				'description' => __('楽天ウェブサービスのアクセスキーです。', GACHIREAI_TEXT_DOMAIN),
			),
			'affiliate_id'   => array(
				'label'       => __('アフィリエイトID', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'text',
				'max'         => 100,
				'description' => __('楽天アフィリエイトID です。', GACHIREAI_TEXT_DOMAIN),
			),
			'site_url'       => array(
				'label'       => __('サイトURL（Referer / Origin）', GACHIREAI_TEXT_DOMAIN),
				'type'        => 'text',
				'max'         => 200,
				'placeholder' => __('例: https://example.com（空欄なら自サイトのURL）', GACHIREAI_TEXT_DOMAIN),
				'description' => __("楽天アプリの「許可されたWebサイト」に登録したドメインまたはURLです。\n空欄なら、このサイトのURLをセットします。", GACHIREAI_TEXT_DOMAIN),
			),
		);
	}
	private function form(string $key): Form
	{
		return new Form($this->definitions($key));
	}
}
