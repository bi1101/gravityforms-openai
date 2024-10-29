<?php
/**
 * @package gravityforms-openai
 * @copyright Copyright (c) 2022, Gravity Wiz, LLC
 * @author Gravity Wiz <support@gravitywiz.com>
 * @license GPLv2
 * @link https://github.com/gravitywiz/gravityforms-openai
 */
defined('ABSPATH') || die();

GFForms::include_feed_addon_framework();

/*
 * @todo Make notes configurable
 * @todo Make saving to meta configurable
 */
class GWiz_GF_OpenAI extends GFFeedAddOn
{

	/**
	 * @var array The default settings to pass to OpenAI
	 */
	public $default_settings = array(
		'completions' => array(
			'max_tokens' => 500,
			'temperature' => 1,
			'top_p' => 1,
			'frequency_penalty' => 0,
			'presence_penalty' => 0,
			'timeout' => 15,
		),
		'chat/completions' => array(
			'max_tokens' => 1000,
			'temperature' => 1,
			'top_p' => 1,
			'frequency_penalty' => 0,
			'presence_penalty' => 0,
			'timeout' => 15,
		),
		'edits' => array(
			'temperature' => 1,
			'top_p' => 1,
			'timeout' => 15,
		),
		'moderations' => array(
			'timeout' => 5,
		),
	);

	/**
	 * @var GWiz_GF_OpenAI\Dependencies\Inc2734\WP_GitHub_Plugin_Updater\Bootstrap The updater instance.
	 */
	public $updater;

	/**
	 * @var null|GWiz_GF_OpenAI
	 */
	private static $instance = null;

	protected $_version = GWIZ_GF_OPENAI_VERSION;
	protected $_path = 'gravityforms-openai/gravityforms-openai.php';
	protected $_full_path = __FILE__;
	protected $_slug = 'gravityforms-openai';
	protected $_title = 'Gravity Forms OpenAI';
	protected $_short_title = 'OpenAI';

	/**
	 * Defines the capabilities needed for the Add-On.
	 *
	 * @var array $_capabilities The capabilities needed for the Add-On
	 */
	protected $_capabilities = array(
		'gravityforms-openai',
		'gravityforms-openai_uninstall',
		'gravityforms-openai_results',
		'gravityforms-openai_settings',
		'gravityforms-openai_form_settings',
	);

	/**
	 * Defines the capability needed to access the Add-On settings page.
	 *
	 * @var string $_capabilities_settings_page The capability needed to access the Add-On settings page.
	 */
	protected $_capabilities_settings_page = 'gravityforms-openai_settings';

	/**
	 * Defines the capability needed to access the Add-On form settings page.
	 *
	 * @var string $_capabilities_form_settings The capability needed to access the Add-On form settings page.
	 */
	protected $_capabilities_form_settings = 'gravityforms-openai_form_settings';

	/**
	 * Defines the capability needed to uninstall the Add-On.
	 *
	 * @var string $_capabilities_uninstall The capability needed to uninstall the Add-On.
	 */
	protected $_capabilities_uninstall = 'gravityforms-openai_uninstall';

	/**
	 * Disable async feed processing for now as it can prevent results mapped to fields from working in notifications.
	 *
	 * @var bool
	 */
	protected $_async_feed_processing = false;

	/**
	 * Allow re-ordering of feeds.
	 *
	 * @var bool
	 */
	protected $_supports_feed_ordering = true;

	public static function get_instance()
	{
		if (self::$instance === null) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Give the form settings and plugin settings panels a nice shiny icon.
	 */
	public function get_menu_icon()
	{
		return $this->get_base_url() . '/icon.svg';
	}

	/**
	 * Defines the minimum requirements for the add-on.
	 *
	 * @return array
	 */
	public function minimum_requirements()
	{
		return array(
			'gravityforms' => array(
				'version' => '2.5',
			),
			'wordpress' => array(
				'version' => '4.8',
			),
		);
	}

	/**
	 * Load dependencies and initialize auto-updater
	 */
	public function pre_init()
	{
		parent::pre_init();

		$this->setup_autoload();
		$this->init_auto_updater();

		add_filter('gform_export_form', array($this, 'export_feeds_with_form'));
		add_action('gform_forms_post_import', array($this, 'import_feeds_with_form'));
	}

	/**
	 * @credit https://github.com/google/site-kit-wp
	 */
	public function setup_autoload()
	{
		$class_map = array_merge(
			include plugin_dir_path(__FILE__) . 'third-party/vendor/composer/autoload_classmap.php'
		);

		spl_autoload_register(
			function ($class) use ($class_map) {
				if (isset ($class_map[$class]) && substr($class, 0, 27) === 'GWiz_GF_OpenAI\\Dependencies') {
					require_once $class_map[$class];
				}
			},
			true,
			true
		);
	}

	/**
	 * Initialize the auto-updater.
	 */
	public function init_auto_updater()
	{
		// Initialize GitHub auto-updater
		add_filter(
			'inc2734_github_plugin_updater_plugins_api_gravitywiz/gravityforms-openai',
			array($this, 'filter_auto_updater_response'),
			10,
			2
		);

		$this->updater = new GWiz_GF_OpenAI\Dependencies\Inc2734\WP_GitHub_Plugin_Updater\Bootstrap(
			plugin_basename(plugin_dir_path(__FILE__) . 'gravityforms-openai.php'),
			'bi1101',
			'gravityforms-openai',
			array(
				'description_url' => 'https://raw.githubusercontent.com/bi1101/gravityforms-openai/master/readme.md',
				'changelog_url' => 'https://raw.githubusercontent.com/bi1101/gravityforms-openai/master/changelog.txt',
				'icons' => array(
					'svg' => 'https://raw.githubusercontent.com/bi1101/gravityforms-openai/master/icon.svg',
				),
				'banners' => array(
					'low' => 'https://gravitywiz.com/wp-content/uploads/2022/12/gfoai-by-dalle-1.png',
				),
				'requires_php' => '5.6.0',
			)
		);
	}

	/**
	 * Filter the GitHub auto-updater response to remove sections we don't need and update various fields.
	 *
	 * @param stdClass $obj
	 * @param stdClass $response
	 *
	 * @return stdClass
	 */
	public function filter_auto_updater_response($obj, $response)
	{
		$remove_sections = array(
			'installation',
			'faq',
			'screenshots',
			'reviews',
			'other_notes',
		);

		foreach ($remove_sections as $section) {
			if (isset($obj->sections[$section])) {
				unset($obj->sections[$section]);
			}
		}

		if (isset($obj->active_installs)) {
			unset($obj->active_installs);
		}

		$obj->homepage = 'https://gravitywiz.com/gravity-forms-openai/';
		$obj->author = '<a href="https://gravitywiz.com/" target="_blank">Gravity Wiz</a>';

		$parsedown = new GWiz_GF_OpenAI\Dependencies\Parsedown();
		$changelog = trim($obj->sections['changelog']);

		// Remove the "Changelog" h1.
		$changelog = preg_replace('/^# Changelog/m', '', $changelog);

		// Remove the tab before the list item so it's not treated as code.
		$changelog = preg_replace('/^\t- /m', '- ', $changelog);

		// Convert h2 to h4 to avoid weird styles that add a lot of whitespace.
		$changelog = preg_replace('/^## /m', '#### ', $changelog);

		$obj->sections['changelog'] = $parsedown->text($changelog);

		return $obj;
	}

	/**
	 * Initialize the add-on. Similar to construct, but done later.
	 *
	 * @return void
	 */
	public function init()
	{
		parent::init();

		load_plugin_textdomain($this->_slug, false, basename(dirname(__FILE__)) . '/languages/');

		// Filters/actions
		add_filter('gform_tooltips', array($this, 'tooltips'));
		add_filter('gform_validation', array($this, 'moderations_endpoint_validation'));
		add_filter('gform_validation_message', array($this, 'modify_validation_message'), 15, 2);
		add_filter('gform_entry_is_spam', array($this, 'moderations_endpoint_spam'), 10, 3);
		add_filter('gform_pre_replace_merge_tags', array($this, 'replace_merge_tags'), 10, 7);
	}

	/**
	 * Defines the available models.
	 */
	public function get_openai_models()
	{
		$models = array(
			'completions' => array(
				'text-davinci-003' => array(
					'type' => 'GPT-3',
					'description' => __('Most capable GPT-3 model. Can do any task the other models can do, often with higher quality, longer output and better instruction-following. Also supports <a href="https://beta.openai.com/docs/guides/completion/inserting-text" target="_blank">inserting</a> completions within text.', 'gravityforms-openai'),
				),
				'text-curie-001' => array(
					'type' => 'GPT-3',
					'description' => __('Very capable, but faster and lower cost than Davinci.', 'gravityforms-openai'),
				),
				'text-babbage-001' => array(
					'type' => 'GPT-3',
					'description' => __('Capable of straightforward tasks, very fast, and lower cost.', 'gravityforms-openai'),
				),
				'text-ada-001' => array(
					'type' => 'GPT-3',
					'description' => __('Capable of very simple tasks, usually the fastest model in the GPT-3 series, and lowest cost.', 'gravityforms-openai'),
				),
				'code-davinci-002' => array(
					'type' => 'Codex',
					'description' => __('Most capable Codex model. Particularly good at translating natural language to code. In addition to completing code, also supports <a href="https://beta.openai.com/docs/guides/code/inserting-code" target="_blank">inserting</a> completions within code.', 'gravityforms-openai'),
				),
				'code-cushman-001' => array(
					'type' => 'Codex',
					'description' => __('Almost as capable as Davinci Codex, but slightly faster. This speed advantage may make it preferable for real-time applications.', 'gravityforms-openai'),
				),
			),
			'chat/completions' => array(
				'gpt-3.5-turbo' => array(
					'description' => __('The same model used by <a href="https://chat.openai.com" target="_blank">ChatGPT</a>.', 'gravityforms-openai'),
				),
				'gpt-4o' => array(
					'description' => __('High-intelligence flagship model for complex, multi-step tasks.', 'gravityforms-openai'),
				),
				'gpt-4o-mini' => array(
					'description' => __('affordable and intelligent small model for fast, lightweight tasks. GPT-4o mini is cheaper and more capable than GPT-3.5 Turbo.', 'gravityforms-openai'),
				),
				'gpt-3.5-turbo-16k' => array(
					'description' => __('Same capabilities as the standard gpt-3.5-turbo model but with 4x the context length.', 'gravityforms-openai'),
				),
				'gpt-4-turbo-preview' => array(
					'description' => __('More capable than any GPT-3.5 model, able to do more complex tasks, and optimized for chat. Will be updated with the latest model iteration.', 'gravityforms-openai'),
				),
				'gpt-4-32k' => array(
					'description' => __('Same capabilities as the base gpt-4 mode but with 4x the context length. Will be updated with the latest model iteration.', 'gravityforms-openai'),
				),
				'gpt-4-vision-preview' => array(
					'description' => __('Same capabilities as the base gpt-4 mode but with Vision capabilities.', 'gravityforms-openai'),
				),
				'gemini-pro' => array(
					'description' => __('Similar to GPT-3.5 models.', 'gravityforms-openai'),
				),
				'gemini-pro-vision' => array(
					'description' => __('Similar to GPT-3.5 models but with Vision capabilities.', 'gravityforms-openai'),
				),
			),
			'edits' => array(
				'text-davinci-edit-001' => array(
					'type' => 'GPT-3',
					'description' => __('Most capable GPT-3 model. Can do any task the other models can do, often with higher quality, longer output and better instruction-following. Also supports <a href="https://beta.openai.com/docs/guides/completion/inserting-text" target="_blank">inserting</a> completions within text.', 'gravityforms-openai'),
				),
				'code-davinci-edit-001' => array(
					'type' => 'Codex',
					'description' => __('Most capable Codex model. Particularly good at translating natural language to code. In addition to completing code, also supports <a href="https://beta.openai.com/docs/guides/code/inserting-code" target="_blank">inserting</a> completions within code.', 'gravityforms-openai'),
				),
			),
			'moderations' => array(
				'text-moderation-stable' => array(
					'type' => 'Moderation',
				),
				'text-moderation-latest' => array(
					'type' => 'Moderation',
				),
			),
		);

		return apply_filters('gf_openai_models', $models);
	}

	/**
	 * Gets models owned by the user or organization.
	 */
	public function get_user_models()
	{
		$url = 'https://api.openai.com/v1/models';

		$cache_key = sha1($url);
		$transient = 'gform_openai_cache_' . $cache_key;

		if (get_transient($transient)) {
			return get_transient($transient);
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $this->get_headers(),
				'timeout' => 5,
			)
		);

		$models = array();

		if (!is_wp_error($response)) {
			try {
				$response = wp_remote_retrieve_body($response);
				$response = json_decode($response, true);

				// Filter results to only models owned by the user/org.
				foreach ($response['data'] as $model) {
					if (strpos($model['owned_by'], 'user-') === 0 || strpos($model['owned_by'], 'org-') === 0) {
						$models[$model['id']] = array_merge(
							$model,
							array(
								'user_model' => true,
							)
						);
					}
				}
			} catch (Exception $e) {
				// Do nothing, $models is already an empty array.
			}
		}

		// Cache for 5 minutes.
		set_transient($transient, $models, 5 * MINUTE_IN_SECONDS);

		return $models;
	}

	/**
	 * Defines the settings for the plugin's global settings such as API key. Accessible via Forms » Settings
	 *
	 * @return array[]
	 */
	public function plugin_settings_fields()
	{
		$fields = array();
		// Language Tool API Setting Fields
		$fields[] = array(
			'title' => 'Language Tool API',
			'fields' => array(
				array(
					'label'   => esc_html__('API Username', 'gravityforms-openai'),
					'type'    => 'text',
					'name'    => 'language_tool_username',
					'tooltip' => esc_html__('The Username for the Language Tool API.', 'gravityforms-openai'),
					'class'   => 'small',
				),
				array(
					'label'   => esc_html__('API Key', 'gravityforms-openai'),
					'type'    => 'text',
					'input_type' => 'password',
					'name'    => 'language_tool_apiKey',
					'tooltip' => esc_html__('The API key for the Language Tool API.', 'gravityforms-openai'),
					'class'   => 'small',
				),
				array(
					'label'   => esc_html__('Base URL', 'gravityforms-openai'),
					'type'    => 'text',
					'input_type' => 'url',
					'name'    => 'language_tool_base_url',
					'tooltip' => esc_html__('Base URL For The Language Tool.', 'gravityforms-openai'),
					'class'   => 'small',
					'default_value' => 'https://api.languagetoolplus.com/v2/check',
				),
			),
		);

		// Pronunciation API
		$fields[] = array(
			'title' => 'Pronunciation API',
			'fields' => array(
				array(
					'label'   => esc_html__('Speech Key', 'gravityforms-openai'),
					'type'    => 'text',
					'input_type' => 'password',
					'name'    => 'pronunciation_speech_key',
					'tooltip' => esc_html__('The Speech API key for the Pronunciation API.', 'gravityforms-openai'),
					'class'   => 'small',
				),
				array(
					'label'   => esc_html__('Base URL', 'gravityforms-openai'),
					'type'    => 'text',
					'input_type' => 'url',
					'name'    => 'pronunciation_base_url',
					'tooltip' => esc_html__('Base URL for the Pronunciation API.', 'gravityforms-openai'),
					'class'   => 'small',
					'default_value' => 'http://api2.ieltsscience.fun:8080/', // Example default value
				),
			),
		);

		// Google Drive Integration
		$fields[] = array(
			'title' => 'Google Drive',
			'fields' => array(
				array(
					'label'   => esc_html__('Google Cloud Console API Key', 'gravityforms-openai'),
					'type'    => 'text',
					'input_type' => 'password',
					'name'    => 'gcloud_console_api_key',
					'tooltip' => esc_html__('Google API key From Cloud Console.', 'gravityforms-openai'),
					'class'   => 'small',
				),
				array(
					'label'   => esc_html__('Client ID', 'gravityforms-openai'),
					'type'    => 'text',
					'input_type' => 'url',
					'name'    => 'gcloud_app_client_id',
					'tooltip' => esc_html__('Client ID of Your Cloud Console Project', 'gravityforms-openai'),
					'class'   => 'small',
				),
			),
		);
		for ($i = 1; $i <= 10; $i++) {

			$fields[] = array(
				'title' => $this->_title . " $i",
				'fields' => array(
					array(
						'name' => "secret_key_$i",
						'tooltip' => __('Enter your OpenAI secret key.', 'gravityforms-openai'),
						'description' => '<a href="https://beta.openai.com/account/api-keys" target="_blank">'
							. __('Manage API keys') . '</a><br />'
							. sprintf(
								__('Example: %s', 'gravityforms-openai'),
								'<code>sk-5q6D85X27xr1e1bNEUuLGQp6a0OANXvFxyIo1WnuUbsNb21Z</code>'
							),
						'label' => "Secret Key $i",
						'type' => 'text',
						'input_type' => 'password',
						'class' => 'medium',
						'required' => $i == 1,
						// Only the first key is required
					),
					array(
						'name' => "pb_key_$i",
						'tooltip' => __('Enter your Predibase API Key.', 'gravityforms-openai'),
						'description' => '<a href="https://app.predibase.com/settings" target="_blank">Predibase Settings</a><br />'
							. sprintf(
								__('Example: %s', 'gravityforms-openai'),
								'<code>pb_cmKe******************</code>'
							),
						'label' => "Predibase API $i",
						'type' => 'text',
						'input_type' => 'password',
						'class' => 'medium',
						'required' => false,
					),
					array(
						'name' => "api_key_$i",
						'tooltip' => __('Enter your Azure OpenAI API key.', 'gravityforms-openai'),
						'description' => __('Key for Azure OpenAI API.'),
						'label' => "Azure API Key_$i",
						'type' => 'text',
						'input_type' => 'password',
						'class' => 'medium',
						'required' => false,
					),
					array(
						'name' => "organization_$i",
						'tooltip' => __('Enter your OpenAI organization if you belong to multiple.', 'gravityforms-openai'),
						'description' => '<a href="https://beta.openai.com/account/org-settings" target="_blank">'
							. __('Organization Settings') . '</a><br />'
							. sprintf(
								__('Example: %s', 'gravityforms-openai'),
								'<code>org-st6H4JIzknQvU9MoNqRWxPst</code>'
							),
						'label' => "Organization $i",
						'type' => 'text',
						'class' => 'medium',
						'required' => false,
					),
					array(
						'name' => "usage_count_$i",
						'label' => "Usage Count $i",
						'type' => 'text',
						'class' => 'medium',
					),
				),
			);
		}

		return $fields;
	}


	public function getBestSecretKey()
	{
		$settings = get_option('gravityformsaddon_gravityforms-openai_settings', array());

		$minIndex = null;
		$minUsage = PHP_INT_MAX;

		for ($i = 1; $i <= 10; $i++) {
			$keyExistsAndHasValue = isset($settings["secret_key_$i"]) && !empty($settings["secret_key_$i"]);
			if (!$keyExistsAndHasValue) {
				continue;
			}

			$currentUsage = isset($settings["usage_count_$i"]) ? intval($settings["usage_count_$i"]) : 0;
			if ($currentUsage < $minUsage) {
				$minIndex = $i;
				$minUsage = $currentUsage;
			}
		}

		if ($minIndex !== null) {
			$settings["usage_count_$minIndex"] = $minUsage + 1;
			update_option('gravityformsaddon_gravityforms-openai_settings', $settings);
		}

		return $minIndex;
	}


	/**
	 * @return array
	 */
	public function feed_list_columns()
	{
		return array(
			'feed_name' => __('Name', 'gravityforms-openai'),
			'endpoint' => __('OpenAI Endpoint', 'gravityforms-openai'),
		);
	}

	/**
	 * Registers tooltips with Gravity Forms. Needed for some things like radio choices.
	 *
	 * @param $tooltips array Existing tooltips.
	 *
	 * @return array
	 */
	public function tooltips($tooltips)
	{
		foreach ($this->get_openai_models() as $endpoint => $models) {
			foreach ($models as $model => $model_info) {
				if (!rgar($model_info, 'description')) {
					continue;
				}

				$tooltips['openai_model_' . $model] = $model_info['description'];
			}
		}

		$tooltips['openai_endpoint_completions'] = __('Given a prompt, the model will return one or more predicted completions, and can also return the probabilities of alternative tokens at each position.', 'gravityforms-openai');
		$tooltips['openai_endpoint_chat_completions'] = __('Given a single message, the model will return a model-generated message as an output.', 'gravityforms-openai');
		$tooltips['openai_endpoint_edits'] = __('Given a prompt and an instruction, the model will return an edited version of the prompt.', 'gravityforms-openai');
		$tooltips['openai_endpoint_moderations'] = __('Given a input text, outputs if the model classifies it as violating OpenAI\'s content policy.', 'gravityforms-openai');

		return $tooltips;
	}

	/**
	 * Convert our array of models to choices that a radio settings field can use.
	 *
	 * @param $endpoint string The endpoint we're getting models for.
	 *
	 * @return array
	 */
	public function get_openai_model_choices($endpoint)
	{
		$choices = array();
		$models = rgar($this->get_openai_models(), $endpoint);

		// Add user models to completions models.
		/* if ($endpoint === 'completions') {
							  $models = array_merge($models, $this->get_user_models());
						  } */

		if (!$models) {
			return array();
		}

		foreach ($models as $model => $model_info) {
			$choices[] = array(
				'label' => $model . (rgar($model_info, 'waitlist') ? ' (' . __('Requires Waitlist', 'gravityforms-openai') . ')' : ''),
				'value' => $model,
				'tooltip' => !rgar($model_info, 'user_model') ? 'openai_model_' . $model : null,
			);
		}

		return $choices;
	}

	public function can_duplicate_feed($feed_id)
	{
		return true;
	}

	public function feed_settings_fields()
	{
		$editable_roles = get_editable_roles();
		// Start with the general settings
		$general_fields = array(
			array(
				'label' => __('Name', 'gp-limit-submissions'),
				'type' => 'text',
				'name' => 'feed_name',
				'default_value' => $this->get_default_feed_name(),
				'class' => 'medium',
				'tooltip' => __('Enter a name for this OpenAI feed. Only displayed on administrative screens.', 'gravityforms-openai'),
				'required' => true,
			),
			array(
				'name' => 'endpoint',
				'tooltip' => 'Select the OpenAI Endpoint to use.',
				'label' => __('OpenAI Endpoint', 'gravityforms-openai'),
				'type' => 'radio',
				'choices' => array(
					array('value' => 'completions', 'label' => __('Completions', 'gravityforms-openai'), 'tooltip' => 'openai_endpoint_completions'),
					array('value' => 'chat/completions', 'label' => __('Chat Completions', 'gravityforms-openai'), 'tooltip' => 'openai_endpoint_chat_completions'),
					array('value' => 'edits', 'label' => __('Edits', 'gravityforms-openai'), 'tooltip' => 'openai_endpoint_edits'),
					array('value' => 'moderations', 'label' => __('Moderations', 'gravityforms-openai'), 'tooltip' => 'openai_endpoint_moderations'),
					// Add Whisper endpoint choice
					array('value' => 'whisper', 'label' => __('Whisper (Audio Transcriptions)', 'gravityforms-openai')),
					// Language Tool
					array('value' => 'languagetool', 'label' => __('Language Tool', 'gravityforms-openai')),
					// Pronunciation API
					array('value' => 'pronunciation', 'label' => __('Pronunciation API', 'gravityforms-openai'))
				),
				'default_value' => 'completions',
			),
		);

		// Whisper API Settings
		$whisper_fields = array(
			array(
				'name' => 'whisper_model',
				'tooltip' => 'Select the Whisper model to use.',
				'label' => __('Whisper Model', 'gravityforms-openai'),
				'type' => 'radio',
				'choices' => array(
					array('value' => 'whisper-1', 'label' => __('Whisper Model 1', 'gravityforms-openai')),
					// Add other model options as needed
				),
				'required' => true,
			),
			array(
				'name' => 'whisper_file_field',
				'type' => 'field_select',
				'label' => __('Select File Upload Field', 'gravityforms-openai'),
				'description' => __('Choose the file upload field to use for Whisper API.', 'gravityforms-openai'),
				// Other properties as needed
			),
			array(
				'name' => 'whisper_prompt',
				'type' => 'text',
				'label' => __('Whisper Prompt to finetune the output', 'gravityforms-openai'),
				'description' => __('Write a Whisper Prompt to Finetune the Output', 'gravityforms-openai'),
				'default' => "I'm, uh, is a, you know, Vietnamese ESL student. So, like, uhm, I may make some mistake in my grammar."
			),
			$this->feed_setting_map_result_to_field('whisper')
			// Add other settings as needed
		);

		// Language Tool Fields
		$languagetool_fields = array(
			array(
				'label'   => esc_html__('Language', 'gravityforms-openai'),
				'type'    => 'text',
				'name'    => 'languagetool__language',
				'tooltip' => esc_html__('The language for the Language Tool API. See https://languagetool.org/http-api/ For Further detail', 'gravityforms-openai'),
				'class'   => 'small',
				'default_value' => 'en-US',
			),
			array(
				'label'   => esc_html__('Enabled Only', 'gravityforms-openai'),
				'type'    => 'radio',
				'choices' => array(
					array(
						'value' => true,
						'label' => __('Yes', 'gravityforms-openai')
					),
					array(
						'value' => false,
						'label' => __('No', 'gravityforms-openai')
					),
				),
				'name'    => 'languagetool__enabled_only',
				'tooltip' => esc_html__('Enable only selected categories for the Language Tool API.', 'gravityforms-openai'),
				'class'   => 'small',
				'default_value' => false,
			),
			array(
				'label'   => esc_html__('Level', 'gravityforms-openai'),
				'type'    => 'text',
				'name'    => 'languagetool__level',
				'tooltip' => esc_html__('The level for the Language Tool API. See https://languagetool.org/http-api/ For Further detail', 'gravityforms-openai'),
				'class'   => 'small',
				'default_value' => 'picky',
			),
			array(
				'label'   => esc_html__('Disabled Categories', 'gravityforms-openai'),
				'type'    => 'text',
				'name'    => 'languagetool__disabled_categories',
				'tooltip' => esc_html__('Disabled categories for the Language Tool API. See https://languagetool.org/http-api/ For Further detail', 'gravityforms-openai'),
				'class'   => 'small',
				'default_value' => 'PUNCTUATION,CASING,TYPOGRAPHY',
			),
			array(
				'name' => 'languagetool_text_source_field',
				'type' => 'field_select',
				'label' => __('Text Source Field', 'gravityforms-openai'),
				'description' => __('Choose Field that will have text to send to Language Tool API', 'gravityforms-openai'),
			),
			$this->feed_setting_map_result_to_field('languagetool')
		);

		// Pronunciation Fields
		$pronunciation_fields = array(
			array(
				'name' => 'pronunciation_grading_system',
				'tooltip' => 'Select the grading system to use.',
				'label' => __('Grading System', 'gravityforms-openai'),
				'type' => 'radio',
				'choices' => array(
					array('value' => 'HundredMark', 'label' => __('Hundred Mark', 'gravityforms-openai')),
					// Add other grading system options as needed
				),
				'required' => true,
				'default_value' => 'HundredMark',
			),
			array(
				'name' => 'pronunciation_granularity',
				'tooltip' => 'Select the granularity level.',
				'label' => __('Granularity', 'gravityforms-openai'),
				'type' => 'radio',
				'choices' => array(
					array('value' => 'Phoneme', 'label' => __('Phoneme', 'gravityforms-openai')),
					// Add other granularity options as needed
				),
				'required' => true,
				'default_value' => 'Phoneme',
			),
			array(
				'name' => 'pronunciation_dimension',
				'tooltip' => 'Select the dimension type.',
				'label' => __('Dimension', 'gravityforms-openai'),
				'type' => 'radio',
				'choices' => array(
					array('value' => 'Comprehensive', 'label' => __('Comprehensive', 'gravityforms-openai')),
					// Add other dimension options as needed
				),
				'required' => true,
				'default_value' => 'Comprehensive',
			),
			array(
				'name' => 'pronunciation_enable_prosody',
				'tooltip' => 'Enable or disable prosody.',
				'label' => __('Enable Prosody', 'gravityforms-openai'),
				'type' => 'radio',
				'choices' => array(
					array('value' => true, 'label' => __('Yes', 'gravityforms-openai')),
					array('value' => false, 'label' => __('No', 'gravityforms-openai')),
				),
				'default_value' => true,
			),
			array(
				'name' => 'pronunciation_reference_text_field',
				'type' => 'field_select',
				'label' => __('Select Reference Text Field', 'gravityforms-openai'),
				'description' => __('Choose the text field to use for the reference text.', 'gravityforms-openai'),
			),
			array(
				'name' => 'pronunciation_audio_file_field',
				'type' => 'field_select',
				'label' => __('Select Audio File Field', 'gravityforms-openai'),
				'description' => __('Choose the file upload field to use for the audio file(s).', 'gravityforms-openai'),

			),
			array(
				'name' => 'fluency_errors_field',
				'type' => 'field_select',
				'label' => __('Select a Field to Save Fluency Errors', 'gravityforms-openai'),

			),
			$this->feed_setting_map_result_to_field('pronunciation'),
		);

		if(class_exists('GP_Limit_Submissions')){ // add Extra Limit Submission Fields if Limit Submission Plugin in installed
			$GP_Limit_Submissions = new GP_Limit_Submissions();
			foreach ($editable_roles as $role => $details) {
				$pronunciation_fields[] = array(
					'label'    => __( "Submission Limit for Role: $role", 'gp-limit-submissions' ),
					'type'     => 'text',
					'class'    => 'small', /* Not needed in GF 2.5+ */
					'name'     => "rule_submission_limit_$role",
					'tooltip'  => __( 'Specify the number of entries that may be submitted if this limit feed applies.', 'gp-limit-submissions' ),
					'style'    => 'width:auto;',
				);
				$pronunciation_fields[] = array(
					'label'   => __( 'Time Period', 'gp-limit-submissions' ),
					'type'    => 'select',
					'name'    => "rule_limit_time_period_$role",
					'tooltip' => $GP_Limit_Submissions->get_time_period_setting_description(),
					'choices' => array(
						array(
							'label' => __( 'Forever', 'gp-limit-submissions' ),
							'value' => 'forever',
						),
						array(
							'label' => __( 'Time Period', 'gp-limit-submissions' ),
							'value' => 'time_period',
						),
						array(
							'label' => __( 'Calendar Period', 'gp-limit-submissions' ),
							'value' => 'calendar_period',
						)
					),
					'class' => 'gf_left_half',  // CSS class to display this field in the left half of the line
				);

				$pronunciation_fields[] = array(
					'label'   => __( 'Time Period Value', 'gp-limit-submissions' ),
					'type'    => 'text',
					'name'    => "rule_time_period_value_$role",
					'tooltip' => __( 'Enter the time period value', 'gp-limit-submissions' ),
					'placeholder' => __( 'Enter a number (i.e. 3)', 'gp-limit-submissions' ),
					'class' => 'gf_right_half', // CSS class to display this field in the right half of the line
					'dependency' => array(
						'live' => true,
						'fields' => array(
							array(
								'field' => "rule_limit_time_period_$role",
								'values' => array( 'time_period' ),
							),
						),
					),
				);

				$pronunciation_fields[] = array(
					'label'   => __( 'Time Period Unit', 'gp-limit-submissions' ),
					'type'    => 'select',
					'name'    => "rule_time_period_unit_$role",
					'tooltip' => __( 'Select time period unit', 'gp-limit-submissions' ),
					'choices' => array(
						array( 'label' => __( 'seconds', 'gp-limit-submissions' ), 'value' => 'seconds' ),
						array( 'label' => __( 'minutes', 'gp-limit-submissions' ), 'value' => 'minutes' ),
						array( 'label' => __( 'hours', 'gp-limit-submissions' ), 'value' => 'hours' ),
						array( 'label' => __( 'days', 'gp-limit-submissions' ), 'value' => 'days' ),
						array( 'label' => __( 'weeks', 'gp-limit-submissions' ), 'value' => 'weeks' ),
						array( 'label' => __( 'months', 'gp-limit-submissions' ), 'value' => 'months' ),
						array( 'label' => __( 'years', 'gp-limit-submissions' ), 'value' => 'years' ),
					),
					'class' => 'gf_left_half',  // Display the field inline (left half)
					'dependency' => array(
						'live' => true,
						'fields' => array(
							array(
								'field' => "rule_limit_time_period_$role",
								'values' => array( 'time_period' ),
							),
						),
					),
				);

				$pronunciation_fields[] = array(
					'label'   => __( 'Calendar Period', 'gp-limit-submissions' ),
					'type'    => 'select',
					'name'    => "rule_calendar_period_$role",
					'tooltip' => __( 'Enter the calendar period', 'gp-limit-submissions' ),
					'choices' => array_values( GPLS_RuleTest::get_calendar_periods() ),
					'class' => 'gf_right_half',  // Display the field inline (right half)
					'dependency' => array(
						'live' => true,
						'fields' => array(
							array(
								'field' => "rule_limit_time_period_$role",
								'values' => array( 'calendar_period' ),
							),
						),
					),
				);
			}
		}

		// Create a new section for API Provider
		$api_provider_fields = array();

		$items_to_check = array();

		// Check for MemberPress memberships
		if (class_exists('MeprProduct')) {
			$memberships = MeprProduct::get_all();
			if (!empty($memberships)) {
				foreach ($memberships as $membership) {
					$items_to_check[] = array(
						'type' => 'membership',
						'name' => $membership->post_name
					);
				}
				// Add an option for users without a membership
				$items_to_check[] = array(
					'type' => 'no_membership',
					'name' => 'No_membership'
				);
			}
		}

		// Fallback to roles if no memberships are available
		if (empty($items_to_check)) {
			foreach ($editable_roles as $role => $details) {
				$items_to_check[] = array(
					'type' => 'role',
					'name' => $role
				);
			}
		}

		foreach ($items_to_check as $item) {
			$identifier = $item['type'] == 'membership' ? sanitize_key($item['name']) : $item['name'];
			$display_name = ucfirst($item['name']);

			$api_provider_fields[] = array(
				'name' => 'api_base_' . $identifier,
				'tooltip' => 'Select the API Provider to use for ' . $display_name,
				'label' => __('API Provider for ' . $display_name, 'gravityforms-openai'),
				'type' => 'radio',
				'choices' => array(
					array(
						'value' => 'https://api.openai.com/v1/',
						'label' => __('OpenAI API', 'gravityforms-openai'),
						'tooltip' => 'API Provider: https://api.openai.com/v1/'
					),
					array(
						'value' => 'https://api.ieltsscience.fun/v1/',
						'label' => __('Plus In-house API', 'gravityforms-openai'),
						'tooltip' => 'API Provider: https://api.ieltsscience.fun/v1/'
					),
					array(
						'value' => 'https://api2.ieltsscience.fun/v1/',
						'label' => __('Basic In-house API', 'gravityforms-openai'),
						'tooltip' => 'API Provider: https://api2.ieltsscience.fun/v1/'
					),
					array(
						'value' => 'https://writify.openai.azure.com/openai/deployments/IELTS-Writify/',
						'label' => __('Azure OpenAI API', 'gravityforms-openai'),
						'tooltip' => 'API Provider: https://writify.openai.azure.com/openai/deployments/IELTS-Writify/'
					),
					array(
						'value' => 'https://serving.app.predibase.com/6266f0/deployments/v2/llms/mistral-7b-instruct/v1/',
						'label' => __('Predibase Mistral-7b-instruct', 'gravityforms-openai'),
						'tooltip' => 'API Provider: https://serving.app.predibase.com/6266f0/deployments/v2/llms/mistral-7b-instruct/v1/'
					),
					array(
						'value' => 'https://serving.app.predibase.com/6266f0/deployments/v2/llms/llama-3-8b-instruct/v1/',
						'label' => __('Predibase Llama-3-8b-instruct', 'gravityforms-openai'),
						'tooltip' => 'API Provider: https://serving.app.predibase.com/6266f0/deployments/v2/llms/llama-3-8b-instruct/v1/'
					),
					array(
						'value' => 'https://POD_ID-80.proxy.runpod.net/v1/',
						'label' => __('RunPod Mistral-7b-instruct v0.1', 'gravityforms-openai'),
						'tooltip' => 'API Provider: https://POD_ID-80.proxy.runpod.net/v1/'
					),
					array(
						'value' => 'http://api3.ieltsscience.fun/v1/',
						'label' => __('Home API Llama-3-8b-instruct', 'gravityforms-openai'),
						'tooltip' => 'API Provider: https://api3.ieltsscience.fun/v1/'
					),
					array(
						'value' => 'https://open.keyai.shop/v1/',
						'label' => __('Keyai Open AI API', 'gravityforms-openai'),
						'tooltip' => 'API Provider: https://open.keyai.shop/v1/'
					),
				),
				'default_value' => 'https://api2.ieltsscience.fun/v1/',
			);
		}

		// Define dynamic fields based on memberships or roles for Chat Completion Endpoints
		$dynamic_model_fields = array();
		foreach ($items_to_check as $item) {
			$identifier = $item['type'] == 'membership' ? sanitize_key($item['name']) : $item['name'];
			$display_name = ucfirst($item['name']);

			$dynamic_model_fields[] = array(
				'name' => 'chat_completion_model_' . $identifier,
				'label' => __('Chat Completion Model for ' . $display_name, 'gravityforms-openai'),
				'type' => 'radio',
				'choices' => $this->get_openai_model_choices('chat/completions'),
				'tooltip' => __('Select the OpenAI Chat Completion model to use for ' . $display_name, 'gravityforms-openai'),
			);
		}

		// Return the full settings array
		return array(
			array(
				'title' => 'General Settings',
				'fields' => $general_fields,
			),
			array(
				'title' => 'API Provider',
				'fields' => $api_provider_fields,
				'dependency' => array(
					'live' => true,
					'fields' => array(
						array(
							'field' => 'endpoint',
							'values' => array('chat/completions', 'completions', 'edits', 'moderations', 'whisper'),
						),
					),
				),
			),
			array(
				'title' => 'Runpod Settings',
				'fields' => array(
					array(
						'name' => 'runpod_pod_id',
						'label' => 'RunPod Pod ID',
						'type' => 'text',
						'tooltip' => 'Enter the RunPod Pod ID to use.',
						'class' => 'small',
					),
				),
			),
			array(
				'title' => 'Lorax Setting',
				'fields' => array(
					array(
						'name' => 'chat_completions_lora_adapter',
						'label' => 'Lora Adapter',
						'type' => 'text',
						'tooltip' => 'Enter the Lora Adapter to use.',
						'class' => 'small',
					),
					array(
						'name' => 'chat_completions_lora_adapter_HF',
						'label' => 'Lora Adapter HF',
						'type' => 'text',
						'tooltip' => 'Enter the Huggingface Lora Adapter to use.',
						'class' => 'small',
					),
					array(
						'name' => 'chat_completions_lorax_message',
						'tooltip' => 'Enter the message to send to LoraX.',
						'label' => 'Lorax Message',
						'type' => 'textarea',
						'class' => 'medium merge-tag-support mt-position-right',
					),
				),
				'dependency' => array(
					'live' => true,
					'fields' => array(
						array(
							'field' => 'endpoint',
							'values' => array('chat/completions'),
						),
					),
				),
			),
			array(
				'title' => 'Completions',
				'fields' => array(
					array(
						'name' => 'completions_model',
						'tooltip' => 'Select the OpenAI model to use.',
						'label' => __('OpenAI Model', 'gravityforms-openai'),
						'type' => 'radio',
						'choices' => $this->get_openai_model_choices('completions'),
						'required' => true,
					),
					array(
						'name' => 'completions_prompt',
						'tooltip' => 'Enter the prompt to send to OpenAI.',
						'label' => 'Prompt',
						'type' => 'textarea',
						'class' => 'medium merge-tag-support mt-position-right',
						'required' => true,
					),
					$this->feed_setting_enable_merge_tag('completions'),
					$this->feed_setting_map_result_to_field('completions'),
				),
				'dependency' => array(
					'live' => true,
					'fields' => array(
						array(
							'field' => 'endpoint',
							'values' => array('completions'),
						),
					),
				),
			),
			array(
				'title' => 'Chat Completions',
				'fields' => array_merge(
					$dynamic_model_fields,
					array(
						array(
							'name' => 'gpt_4_vision_image_link',
							'label' => __('Image Link for GPT-4 Vision', 'gravityforms-openai'),
							'type' => 'field_select',
							'tooltip' => __('Select the field containing the image link for GPT-4 Vision.', 'gravityforms-openai'),
						),
						array(
							'name' => 'chat_completions_message',
							'tooltip' => 'Enter the message to send to OpenAI.',
							'label' => 'Message',
							'type' => 'textarea',
							'class' => 'medium merge-tag-support mt-position-right',
							'required' => true,
						),
						// New "Stream to front end" field
						array(
							'name' => 'stream_to_frontend',
							'label' => 'Stream to front end',
							'type' => 'radio',
							'choices' => array(
								array('label' => 'As Feedback', 'value' => 'yes'),
								array('label' => 'No', 'value' => 'no'),
								array('label' => 'As the answer', 'value' => 'text'),
								array('label' => 'As the question', 'value' => 'question')
							),
							'default_value' => 'Yes',
							'tooltip' => 'Select whether to stream the chat completions to the front end.',
						),
						array(
							'name' => 'request_is_for_scores',
							'label' => 'Is This Request Get Scores From Open AI?',
							'type' => 'radio',
							'choices' => array(
								array('label' => 'No', 'value' => 'no'),
								array('label' => 'Grammer Score', 'value' => 'grammer_scores'),
								array('label' => 'Vocab Score', 'value' => 'vocab_scores')
							),
							'default_value' => 'no',
							'tooltip' => 'Select If The Chat Completions Request is For Getting Scores',
						),
						$this->feed_setting_enable_merge_tag('chat/completions'),
						$this->feed_setting_map_result_to_field('chat/completions'),
					)
				),
				'dependency' => array(
					'live' => true,
					'fields' => array(
						array(
							'field' => 'endpoint',
							'values' => array('chat/completions'),
						),
					),
				),
			),
			array(
				'title' => 'Edits',
				'fields' => array(
					array(
						'name' => 'edits_model',
						'tooltip' => 'Select the OpenAI model to use.',
						'label' => __('OpenAI Model', 'gravityforms-openai'),
						'type' => 'radio',
						'choices' => $this->get_openai_model_choices('edits'),
						'required' => true,
					),
					array(
						'name' => 'edits_input',
						'tooltip' => __('The input text to use as a starting point for the edit.', 'gravityforms-openai'),
						'label' => 'Input',
						'type' => 'textarea',
						'class' => 'medium merge-tag-support mt-position-right',
						'required' => false,
					),
					array(
						'name' => 'edits_instruction',
						'tooltip' => __('The instruction that tells the model how to edit the prompt.', 'gravityforms-openai'),
						'label' => __('Instruction', 'gravityforms-openai'),
						'type' => 'textarea',
						'class' => 'medium merge-tag-support mt-position-right',
						'required' => true,
					),
					$this->feed_setting_enable_merge_tag('edits'),
					$this->feed_setting_map_result_to_field('edits'),
				),
				'dependency' => array(
					'live' => true,
					'fields' => array(
						array(
							'field' => 'endpoint',
							'values' => array('edits'),
						),
					),
				),
			),
			array(
				'title' => 'Moderations',
				'fields' => array(
					array(
						'name' => 'moderations_model',
						'tooltip' => 'Select the OpenAI model to use.',
						'label' => __('OpenAI Model', 'gravityforms-openai'),
						'type' => 'radio',
						'choices' => $this->get_openai_model_choices('moderations'),
						'required' => true,
					),
					array(
						'name' => 'moderations_input',
						'tooltip' => 'Enter the input to send to OpenAI for moderation.',
						'label' => 'Input',
						'default_value' => '{all_fields}',
						'type' => 'textarea',
						'class' => 'medium merge-tag-support mt-position-right',
						'required' => true,
					),
					array(
						'name' => 'moderations_behavior',
						'tooltip' => 'What to do if moderations says the content policy is violated.',
						'label' => 'Behavior',
						'type' => 'select',
						'choices' => array(
							array(
								'label' => __('Do nothing'),
								'value' => '',
							),
							array(
								'label' => __('Mark entry as spam'),
								'value' => 'spam',
							),
							array(
								'label' => __('Prevent submission by showing validation error'),
								'value' => 'validation_error',
							),
						),
						'required' => false,
						'fields' => array(
							array(
								'name' => 'moderations_validation_message',
								'tooltip' => __('The validation message to display if the content policy is violated.', 'gravityforms-openai'),
								'label' => 'Validation Message',
								'type' => 'text',
								'placeholder' => $this->get_default_moderations_validation_message(),
								'dependency' => array(
									'live' => true,
									'fields' => array(
										array(
											'field' => 'moderations_behavior',
											'values' => array('validation_error'),
										),
									),
								),
							),
						),
					),
				),
				'dependency' => array(
					'live' => true,
					'fields' => array(
						array(
							'field' => 'endpoint',
							'values' => array('moderations'),
						),
					),
				),
			),
			array(
				'title' => 'Whisper API Settings',
				'fields' => $whisper_fields,
				'dependency' => array(
					'live' => true,
					'fields' => array(
						array(
							'field' => 'endpoint',
							'values' => array('whisper'),
						),
					),
				),
			),
			array(
				'title' => 'Language Tool API Settings',
				'fields' => $languagetool_fields,
				'dependency' => array(
					'live' => true,
					'fields' => array(
						array(
							'field' => 'endpoint',
							'values' => array('languagetool'),
						),
					),
				),
			),
			array(
				'title' => 'Pronunciation API Settings',
				'fields' => $pronunciation_fields,
				'dependency' => array(
					'live' => true,
					'fields' => array(
						array(
							'field' => 'endpoint',
							'values' => array('pronunciation'),
						),
					),
				),
			),
			//Conditional Logic
			array(
				'title' => esc_html__('Conditional Logic', 'gravityforms-openai'),
				'fields' => array(
					array(
						'label' => '',
						'name' => 'conditional_logic',
						'type' => 'feed_condition',
					),
				),
			),
			array(
				'title' => 'Advanced Settings: Completions',
				'fields' => array(
					$this->feed_advanced_setting_timeout('completions'),
					$this->feed_advanced_setting_max_tokens('completions'),
					$this->feed_advanced_setting_temperature('completions'),
					$this->feed_advanced_setting_top_p('completions'),
					$this->feed_advanced_setting_frequency_penalty('completions'),
					$this->feed_advanced_setting_presence_penalty('completions'),
				),
				'dependency' => array(
					'live' => true,
					'fields' => array(
						array(
							'field' => 'endpoint',
							'values' => array('completions'),
						),
					),
				),
			),
			array(
				'title' => 'Advanced Settings: Chat Completions',
				'fields' => array(
					$this->feed_advanced_setting_timeout('chat/completions'),
					$this->feed_advanced_setting_max_tokens('chat/completions'),
					$this->feed_advanced_setting_temperature('chat/completions'),
					$this->feed_advanced_setting_top_p('chat/completions'),
					$this->feed_advanced_setting_frequency_penalty('chat/completions'),
					$this->feed_advanced_setting_presence_penalty('chat/completions'),
				),
				'dependency' => array(
					'live' => true,
					'fields' => array(
						array(
							'field' => 'endpoint',
							'values' => array('chat/completions'),
						),
					),
				),
			),
			array(
				'title' => 'Advanced Settings: Edits',
				'fields' => array(
					$this->feed_advanced_setting_timeout('edits'),
					$this->feed_advanced_setting_temperature('edits'),
					$this->feed_advanced_setting_top_p('edits'),
				),
				'dependency' => array(
					'live' => true,
					'fields' => array(
						array(
							'field' => 'endpoint',
							'values' => array('edits'),
						),
					),
				),
			),
			array(
				'title' => 'Advanced Settings: Moderations',
				'fields' => array(
					$this->feed_advanced_setting_timeout('moderations'),
				),
				'dependency' => array(
					'live' => true,
					'fields' => array(
						array(
							'field' => 'endpoint',
							'values' => array('moderations'),
						),
					),
				),
			),
		);
	}

	/**
	 * @param $endpoint string The OpenAI endpoint.
	 *
	 * @return array
	 */
	public function feed_setting_enable_merge_tag($endpoint)
	{
		return array(
			'name' => $endpoint . '_enable_merge_tag',
			'type' => 'checkbox',
			'label' => __('Merge Tag', 'gravityforms-openai'),
			'description' => __('Enable getting the output of the OpenAI result using a merge tag.
								<br /><br />
								Pro Tip: This works with Gravity Forms Populate Anything\'s
								<a href="https://gravitywiz.com/documentation/gravity-forms-populate-anything/#live-merge-tags" target="_blank">Live Merge Tags</a>!', 'gravityforms-openai'),
			'choices' => array(
				array(
					'name' => $endpoint . '_enable_merge_tag',
					'label' => __('Enable Merge Tag', 'gravityforms'),
				),
			),
			'fields' => array(
				array(
					'name' => 'merge_tag_preview_' . $endpoint,
					'type' => 'html',
					'html' => rgget('fid') ? '<style>
									#openai_merge_tag_usage {
										line-height: 1.6rem;
									}

									#openai_merge_tag_usage ul {
										padding: 0 0 0 1rem;
									}

									#openai_merge_tag_usage ul li {
										list-style: disc;
									}
									</style>
									<div id="openai_merge_tag_usage"><strong>Usage:</strong><br />
									<ul>
										<li><code>{openai_feed_' . rgget('fid') . '}</code></li>
									</ul>
									<strong>Usage as a <a href="https://gravitywiz.com/documentation/gravity-forms-populate-anything/#live-merge-tags" target="_blank">Live Merge Tag</a>:</strong><br />
									<ul>
										<li><code>@{:FIELDID:openai_feed_' . rgget('fid') . '}</code><br />Replace <code>FIELDID</code> accordingly. Automatically refreshes in the form if the specified field ID changes.</li>
										<li><code>@{all_fields:openai_feed_' . rgget('fid') . '}</code><br />Automatically refreshes in the form if any field value changes.</li>
									</ul><div></div>' : 'Save feed to see merge tags.',
					'dependency' => array(
						'live' => true,
						'fields' => array(
							array(
								'field' => $endpoint . '_enable_merge_tag',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * @param $endpoint string The OpenAI endpoint.
	 *
	 * @return array
	 */
	public function feed_setting_map_result_to_field($endpoint)
	{
		return array(
			'name' => $endpoint . '_map_result_to_field',
			'type' => 'field_select',
			'label' => __('Map Result to Field', 'gravityforms-openai'),
			'description' => __('Take the result and attach it to a field\'s value upon submission.', 'gravityforms-openai'),
		);
	}


	/**
	 * @param $endpoint string The OpenAI endpoint.
	 *
	 * @return array
	 */
	public function feed_advanced_setting_timeout($endpoint)
	{
		$default = rgar(rgar($this->default_settings, $endpoint), 'timeout');

		return array(
			'name' => $endpoint . '_' . 'timeout',
			'tooltip' => 'Enter the number of seconds to wait for OpenAI to respond.',
			'label' => 'Timeout',
			'type' => 'text',
			'class' => 'small',
			'required' => false,
			'default_value' => $default,
			// translators: placeholder is a number
			'description' => sprintf(__('Default: <code>%d</code> seconds.', 'gravityforms-openai'), $default),
		);
	}

	/**
	 * @param $endpoint string The OpenAI endpoint.
	 *
	 * @return array
	 */
	public function feed_advanced_setting_max_tokens($endpoint)
	{
		$default = rgar(rgar($this->default_settings, $endpoint), 'max_tokens');

		return array(
			'name' => $endpoint . '_' . 'max_tokens',
			'tooltip' => __('The maximum number of <a href="https://beta.openai.com/tokenizer" target="_blank">tokens</a> to generate in the completion.
								<br /><br />
								The token count of your prompt plus max_tokens cannot exceed the model\'s context
								length. Most models have a context length of 2048 tokens (except for the newest models, which support 4096).', 'gravityforms-openai'),
			'label' => 'Max Tokens',
			'type' => 'text',
			'class' => 'small',
			'required' => false,
			'default_value' => $default,
			// translators: placeholder is a number
			'description' => sprintf(__('Default: <code>%d</code>', 'gravityforms-openai'), $default),
		);
	}

	/**
	 * @param $endpoint string The OpenAI endpoint.
	 *
	 * @return array
	 */
	public function feed_advanced_setting_temperature($endpoint)
	{
		$default = rgar(rgar($this->default_settings, $endpoint), 'temperature');

		return array(
			'name' => $endpoint . '_' . 'temperature',
			'tooltip' => __('What <a href="https://towardsdatascience.com/how-to-sample-from-language-models-682bceb97277" target="_blank">sampling</a>
								temperature to use. Higher values means the model will take more risks. Try 0.9 for more
								creative applications, and 0 (argmax sampling) for ones with a well-defined answer.
								<br /><br />
								We generally recommend altering this or <code>top_p</code> but not both.', 'gravityforms-openai'),
			'label' => 'Temperature',
			'type' => 'text',
			'class' => 'small',
			'required' => false,
			'default_value' => $default,
			// translators: placeholder is a number
			'description' => sprintf(__('Default: <code>%d</code>', 'gravityforms-openai'), $default),
		);
	}

	/**
	 * @param $endpoint string The OpenAI endpoint.
	 *
	 * @return array
	 */
	public function feed_advanced_setting_top_p($endpoint)
	{
		$default = rgar(rgar($this->default_settings, $endpoint), 'top_p');

		return array(
			'name' => $endpoint . '_' . 'top_p',
			'tooltip' => __('An alternative to sampling with temperature, called nucleus sampling,
								where the model considers the results of the tokens with top_p probability mass. So 0.1
								means only the tokens comprising the top 10% probability mass are considered.
								<br /><br />
								We generally recommend altering this or temperature but not both.', 'gravityforms-openai'),
			'label' => 'Top-p',
			'type' => 'text',
			'class' => 'small',
			'required' => false,
			'default_value' => $default,
			// translators: placeholder is a number
			'description' => sprintf(__('Default: <code>%d</code>', 'gravityforms-openai'), $default),

		);
	}

	/**
	 * @param $endpoint string The OpenAI endpoint.
	 *
	 * @return array
	 */
	public function feed_advanced_setting_frequency_penalty($endpoint)
	{
		$default = rgar(rgar($this->default_settings, $endpoint), 'frequency_penalty');

		return array(
			'name' => $endpoint . '_' . 'frequency_penalty',
			'tooltip' => __('Number between -2.0 and 2.0. Positive values penalize new tokens based
								on their existing frequency in the text so far, decreasing the model\'s likelihood to
								repeat the same line verbatim.
								<br /><br />
								<a href="https://beta.openai.com/docs/api-reference/parameter-details" target="_blank">See more information about frequency and presence penalties.</a>', 'gravityforms-openai'),
			'label' => 'Frequency Penalty',
			'type' => 'text',
			'class' => 'small',
			'required' => false,
			'default_value' => $default,
			// translators: placeholder is a number
			'description' => sprintf(__('Default: <code>%d</code>', 'gravityforms-openai'), $default),
		);
	}

	/**
	 * @param $endpoint string The OpenAI endpoint.
	 *
	 * @return array
	 */
	public function feed_advanced_setting_presence_penalty($endpoint)
	{
		$default = rgar(rgar($this->default_settings, $endpoint), 'presence_penalty');

		return array(
			'name' => $endpoint . '_' . 'presence_penalty',
			'tooltip' => __('Number between -2.0 and 2.0. Positive values penalize new tokens based
								on whether they appear in the text so far, increasing the model\'s likelihood to talk
								about new topics.
								<br /><br />
								<a href="https://beta.openai.com/docs/api-reference/parameter-details" target="_blank">See more information about frequency and presence penalties.</a>', 'gravityforms-openai'),
			'label' => 'Presence Penalty',
			'type' => 'text',
			'class' => 'small',
			'required' => false,
			'default_value' => $default,
			// translators: placeholder is a number
			'description' => sprintf(__('Default: <code>%d</code>', 'gravityforms-openai'), $default),
		);
	}

	/**
	 * Processes the feed and sends the data to OpenAI.
	 *
	 * @param array $feed
	 * @param array $entry
	 * @param array $form
	 *
	 * @return array|void|null
	 */
	public function process_feed($feed, $entry, $form)
	{
		$endpoint = $feed['meta']['endpoint'];

		switch ($endpoint) {
			case 'completions':
				$entry = $this->process_endpoint_completions($feed, $entry, $form);
				break;

			case 'chat/completions':
				$entry = $this->process_endpoint_chat_completions($feed, $entry, $form);
				break;

			case 'edits':
				$entry = $this->process_endpoint_edits($feed, $entry, $form);
				break;

			case 'moderations':
				$this->process_endpoint_moderations($feed, $entry, $form);
				break;

			case 'whisper':
				$entry = $this->process_endpoint_whisper($feed, $entry, $form);
				break;
			case 'languagetool':
				$entry = $this->process_endpoint_languagetool($feed, $entry, $form);
				break;
			case 'pronunciation':
				$entry = $this->process_endpoint_pronunciation($feed, $entry, $form);
				break;
			default:
				// translators: placeholder is an unknown OpenAI endpoint.
				$this->add_feed_error(sprintf(__('Unknown endpoint: %s'), $endpoint), $feed, $entry, $form);
				break;
		}

		return $entry;
	}

	/**
	 * Process completions endpoint.
	 *
	 * @param $feed array The current feed being processed.
	 * @param $entry array The current entry being processed.
	 * @param $form array The current form being processed.
	 *
	 * @return array Modified entry.
	 */
	public function process_endpoint_completions($feed, $entry, $form)
	{
		$model = $feed['meta']['completions_model'];
		$prompt = $feed['meta']['completions_prompt'];

		// Parse the merge tags in the prompt.
		$prompt = GFCommon::replace_variables($prompt, $form, $entry, false, false, false, 'text');

		GFAPI::add_note($entry['id'], 0, 'OpenAI Request (' . $feed['meta']['feed_name'] . ')', sprintf(__('Sent request to OpenAI completions endpoint.', 'gravityforms-openai')));

		// translators: placeholders are the feed name, model, prompt
		// $this->log_debug(__METHOD__ . '(): ' . sprintf(__('Sent request to OpenAI. Feed: %1$s, Endpoint: completions, Model: %2$s, Prompt: %3$s', 'gravityforms-openai'), $feed['meta']['feed_name'], $model, $prompt));

		$response = $this->make_request('completions', array(
			'prompt' => $prompt,
			'model' => $model,
		), $feed);

		if (is_wp_error($response)) {
			// If there was an error, log it and return.
			$this->add_feed_error($response->get_error_message(), $feed, $entry, $form);
			return $entry;
		}

		// Parse the response and add it as an entry note.
		$response_data = json_decode($response['body'], true);

		if (rgar($response_data, 'error')) {
			$this->add_feed_error($response_data['error']['message'], $feed, $entry, $form);
			return $entry;
		}

		$text = $this->get_text_from_response($response_data);

		if (!is_wp_error($text)) {
			GFAPI::add_note($entry['id'], 0, 'OpenAI Response (' . $feed['meta']['feed_name'] . ')', $text);
			$entry = $this->maybe_save_result_to_field($feed, $entry, $form, $text);
		} else {
			$this->add_feed_error($text->get_error_message(), $feed, $entry, $form);
		}

		gform_add_meta($entry['id'], 'openai_response_' . $feed['id'], $response['body']);

		return $entry;
	}

	/**
	 * Process chat endpoint.
	 *
	 * @param $feed array The current feed being processed.
	 * @param $entry array The current entry being processed.
	 * @param $form array The current form being processed.
	 *
	 * @return array Modified entry.
	 */
	public function process_endpoint_chat_completions($feed, $entry, $form)
	{
		$primary_identifier = $this->get_user_primary_identifier();
		$api_base = rgar($feed['meta'], "api_base_$primary_identifier", 'https://api.openai.com/v1/');
		// Retrieve the field ID for the image link and then get the URL from the entry
		$image_link_field_id = rgar($feed["meta"], 'gpt_4_vision_image_link');
		$image_link_json = rgar($entry, $image_link_field_id);

		// Decode the JSON string to extract the URL
		$image_link_array = json_decode($image_link_json, true);
		$image_link = $image_link_array ? reset($image_link_array) : ''; // Get the first element of the array

		// Get the model and message from the feed settings
		if (strpos($api_base, 'predibase') !== false) {
			$model = $feed["meta"]['chat_completions_lora_adapter'];
			$message = $feed["meta"]["chat_completions_lorax_message"];
		} elseif (strpos($api_base, 'runpod') !== false || strpos($api_base, 'api3') !== false) {
			$model = $feed["meta"]['chat_completions_lora_adapter_HF'];
			$message = $feed["meta"]["chat_completions_lorax_message"];
		} else {
			// Get the model from feed metadata based on user's role or membership
			$model = $feed["meta"]["chat_completion_model_$primary_identifier"];
			$message = $feed["meta"]["chat_completions_message"];
		}

		// Parse the merge tags in the message.
		$message = GFCommon::replace_variables($message, $form, $entry, false, false, false, 'text');

		GFAPI::add_note($entry['id'], 0, 'OpenAI Request (' . $feed['meta']['feed_name'] . ')', sprintf(__('Sent request to OpenAI chat/completions endpoint.', 'gravityforms-openai')));

		// translators: placeholders are the feed name, model, prompt
		// $this->log_debug(__METHOD__ . '(): ' . sprintf(__('Sent request to OpenAI. Feed: %1$s, Endpoint: chat, Model: %2$s, Message: %3$s', 'gravityforms-openai'), $feed['meta']['feed_name'], $model, $message));

		// Check if the model is GPT-4 Vision and if the image link is not empty
		if (strpos($model, 'vision') !== false && !empty($image_link)) {
			// Construct content with text and image URL
			$content = array(
				array('type' => 'text', 'text' => $message),
				array('type' => 'image_url', 'image_url' => array('url' => $image_link))
			);
		} else {
			// Construct content with only text
			$content = $message;
		}

		// Create the request payload
		$request_payload = array(
			'messages' => array(
				array(
					'role' => 'user',
					'content' => $content
				),
			),
			'model' => $model,
		);

		$response = $this->make_request('chat/completions', $request_payload, $feed);

		if (is_wp_error($response)) {
			// If there was an error, log it and return.
			$this->add_feed_error($response->get_error_message(), $feed, $entry, $form);
			return $entry;
		}

		// Parse the response and add it as an entry note.
		$response_data = json_decode($response['body'], true);

		if (rgar($response_data, 'error')) {
			$this->add_feed_error($response_data['error']['message'], $feed, $entry, $form);
			return $entry;
		}

		$text = $this->get_text_from_response($response_data);

		if (!is_wp_error($text)) {
			GFAPI::add_note($entry['id'], 0, 'OpenAI Response (' . $feed['meta']['feed_name'] . ')', $text);
			$entry = $this->maybe_save_result_to_field($feed, $entry, $form, $text);
		} else {
			$this->add_feed_error($text->get_error_message(), $feed, $entry, $form);
		}

		gform_add_meta($entry['id'], 'openai_response_' . $feed['id'], $response['body']);

		return $entry;
	}

	/**
	 * Process edits endpoint.
	 *
	 * @param $feed array The current feed being processed.
	 * @param $entry array The current entry being processed.
	 * @param $form array The current form being processed.
	 *
	 * @return array Modified entry.
	 */
	public function process_endpoint_edits($feed, $entry, $form)
	{
		$model = $feed['meta']['edits_model'];
		$input = $feed['meta']['edits_input'];
		$instruction = $feed['meta']['edits_instruction'];

		// Parse the merge tags in the input and instruction
		$input = GFCommon::replace_variables($input, $form, $entry, false, false, false, 'text');
		$instruction = GFCommon::replace_variables($instruction, $form, $entry, false, false, false, 'text');

		GFAPI::add_note($entry['id'], 0, 'OpenAI Request (' . $feed['meta']['feed_name'] . ')', sprintf(__('Sent request to OpenAI edits endpoint.', 'gravityforms-openai')));

		// translators: placeholders are the feed name, model, prompt
		// $this->log_debug(__METHOD__ . '(): ' . sprintf(__('Sent request to OpenAI. Feed: %1$s, Endpoint: edits, Model: %2$s, Input: %3$s, instruction: %4$s', 'gravityforms-openai'), $feed['meta']['feed_name'], $model, $input, $instruction));

		$response = $this->make_request('edits', array(
			'input' => $input,
			'instruction' => $instruction,
			'model' => $model,
		), $feed);

		if (is_wp_error($response)) {
			// If there was an error, log it and return.
			$this->add_feed_error($response->get_error_message(), $feed, $entry, $form);
			return $entry;
		}

		// Parse the response and add it as an entry note.
		$response_data = json_decode($response['body'], true);

		if (rgar($response_data, 'error')) {
			$this->add_feed_error($response_data['error']['message'], $feed, $entry, $form);
			return $entry;
		}

		$text = $this->get_text_from_response($response_data);

		if (!is_wp_error($text)) {
			GFAPI::add_note($entry['id'], 0, 'OpenAI Response (' . $feed['meta']['feed_name'] . ')', $text);
			$entry = $this->maybe_save_result_to_field($feed, $entry, $form, $text);
		} else {
			$this->add_feed_error($text->get_error_message(), $feed, $entry, $form);
		}

		gform_add_meta($entry['id'], 'openai_response_' . $feed['id'], $response['body']);

		return $entry;
	}

	public function process_endpoint_whisper($feed, $entry, $form)
	{
		$model = rgar($feed['meta'], 'whisper_model', 'whisper-1');
		$file_field_id = rgar($feed['meta'], 'whisper_file_field');

		// Get the file URLs from the entry (assuming it returns an array of URLs)
		$file_urls = rgar($entry, $file_field_id);
		$combined_text = '';

		foreach ($file_urls as $file_url) {
			// $this->log_debug("File URL from entry: " . $file_url);

			$file_path = $this->convert_url_to_path($file_url);

			if (is_readable($file_path)) {
				// $this->log_debug("File is accessible: " . $file_path);
				$curl_file = curl_file_create($file_path, 'audio/mpeg', basename($file_path));
				$body = array(
					'file' => $curl_file,
					 'model' => $model,
					 'response_format' => 'verbose_json',
                    'timestamp_granularities' => ['word']
					);

				$this->log_debug("Whisper Request Body: " . print_r($body, true));
				$response = $this->make_request('audio/transcriptions', $body, $feed, 'whisper');
				$this->log_debug("Raw Whisper API response: " . print_r($response, true));

				if (is_wp_error($response)) {
					$this->add_feed_error($response->get_error_message(), $feed, $entry, $form);
				} else if (rgar($response, 'error')) {
					$this->add_feed_error($response['error']['message'], $feed, $entry, $form);
				} else {
					$text = $this->get_text_from_response($response);
					if (!is_wp_error($text)) {
						$combined_text .= $text . "\n"; // Append the text with a newline
					} else {
						$this->add_feed_error($text->get_error_message(), $feed, $entry, $form);
					}
				}
			} else {
				$this->log_debug("File is not accessible or does not exist: " . $file_path);
			}
		}

		if (!empty($combined_text)) {
			GFAPI::add_note($entry['id'], 0, 'Whisper API Response (' . $feed['meta']['feed_name'] . ')', $combined_text);
			$entry = $this->maybe_save_result_to_field($feed, $entry, $form, $combined_text);
		}

		gform_add_meta($entry['id'], 'whisper_response_' . $feed['id'], $combined_text);
		return $entry;
	}

	public function process_endpoint_pronunciation($feed, $entry, $form)
	{
		$text_field_id = rgar($feed['meta'], 'pronunciation_reference_text_field');
		$file_field_id = rgar($feed['meta'], 'pronunciation_audio_file_field');
		$responses = array();
		// Get the file URLs from the entry
		$file_urls = rgar($entry, $file_field_id);
		$reference_text = rgar($entry, $text_field_id);
		// $this->log_debug("Text Field ID: " . $text_field_id);
		// $this->log_debug("File Field ID: " . $file_field_id);
		// $this->log_debug("File URLs from entry: " . print_r($file_urls, true));

		// Convert file_urls to array if it's a JSON string
		if (is_string($file_urls)) {
			$file_urls = json_decode($file_urls, true);
			// $this->log_debug("Converted file_urls to array: " . print_r($file_urls, true));
		}

		// Check if file_urls is now an array
		if (is_array($file_urls)) {
			foreach ($file_urls as $file_url) {
				// $this->log_debug("File URL from entry: " . $file_url);
				$body = array('url' => $file_url, 'reference_text' => $reference_text);
				// $this->log_debug("Request Data: " . print_r($body, true));
				$response = $this->make_request('pronunciation', $body, $feed);
				// $this->log_debug("Raw Pronunciation API response: " . print_r($response, true));
				if (is_wp_error($response)) {
					$this->add_feed_error($response->get_error_message(), $feed, $entry, $form);
				}
				$response_body = wp_remote_retrieve_body($response);
				$response_body = $this->parse_event_stream_data($response_body);
				$pretty_json = json_encode($response_body, JSON_PRETTY_PRINT);
				$this->log_debug("Pritty JSON: " . print_r($pretty_json, true));
				$responses[$file_url] = $pretty_json;
			}
		} else {
			$this->log_debug("file_urls is still not an array or is empty after conversion. It is of type: " . gettype($file_urls));
		}

		if (!empty($responses)) {

			GFAPI::add_note($entry['id'], 0, 'Pronunciation API Responses (' . $feed['meta']['feed_name'] . ')', print_r($responses,true));
			$entry = $this->maybe_save_result_to_field($feed, $entry, $form, print_r($responses,true));
		}

		gform_add_meta($entry['id'], 'pronunciation_response_' . $feed['id'], $responses);
		return $entry;

		return $entry;
	}

	public function parse_event_stream_data($event_stream_data) {
		$data_lines = explode("\n", trim($event_stream_data));
		$data_list = array();

		foreach ($data_lines as $line) {
			if (strpos($line, 'data: ') === 0) {
				$json_data = substr($line, strlen('data: '));
				$data_list[] = json_decode($json_data, true);
			}
		}

		return $data_list;
	}




	public function process_endpoint_languagetool($feed, $entry, $form)
	{
		// Prepare Payload
		$text_field_id = rgar($feed['meta'], 'languagetool_text_source_field');
		$text = rgar($entry, $text_field_id);
		$body = array('text' => $text);

		// Send Request
		$response = $this->make_request('languagetool', $body, $feed);
		if (is_wp_error($response)) {
			$this->add_feed_error($response->get_error_message(), $feed, $entry, $form);
		} else {
			$response_body = json_decode(wp_remote_retrieve_body($response), true);

			if (json_last_error() !== JSON_ERROR_NONE) {
				$this->add_feed_error('Error decoding JSON response: ' . json_last_error_msg(), $feed, $entry, $form);
				return $entry;
			}

			$this->log_debug("Response: " . print_r($response_body, true));
			GFAPI::add_note($entry['id'], 0, 'LanguageTool API Response (' . $feed['meta']['feed_name'] . ')', print_r($response_body, true));

			// Convert the response into human-readable format
			$human_readable_response = $this->convert_to_human_readable($response_body);

			// Save the human-readable response as a note
			// GFAPI::add_note($entry['id'], 0, 'LanguageTool API Response (Readable) (' . $feed['meta']['feed_name'] . ')', $human_readable_response);

			// Save the raw response
			gform_add_meta($entry['id'], 'languagetool_response_' . $feed['id'], $response_body);

			// Optionally save the human-readable response in a form field
			$entry = $this->maybe_save_result_to_field($feed, $entry, $form, $human_readable_response);

			return $entry;
		}
	}

	// Helper function to convert API response to human-readable format
	private function convert_to_human_readable($response_body)
	{
		$readable_text = "LanguageTool API found the following issues:\n";

		foreach ($response_body['matches'] as $match) {
			$message = $match['message'];
			$context = $match['context']['text'];
			$offset = $match['context']['offset'];
			$length = $match['context']['length'];
			$replacements = array_column($match['replacements'], 'value');
			$replacements_text = implode(', ', $replacements);

			$readable_text .= "\nIssue: $message\n";
			$readable_text .= "Context: " . substr($context, 0, $offset) . '[' . substr($context, $offset, $length) . ']' . substr($context, $offset + $length) . "\n";
			$readable_text .= "Suggested Replacements: $replacements_text\n";
		}

		return $readable_text;
	}



	public function convert_url_to_path($url)
	{
		// Check if $url is in JSON format and decode it
		if (is_string($url) && strpos($url, '[') === 0) {
			$url = json_decode($url);
			if (json_last_error() === JSON_ERROR_NONE && is_array($url)) {
				$url = array_shift($url);
			}
		}
		// Log the received URL
		$this->log_debug("Received URL: " . print_r($url, true));

		// Get the base directory of the WordPress uploads
		$upload_dir = wp_upload_dir();
		$upload_base_dir = $upload_dir['basedir'];

		// Check if $url is an array and extract the first element
		if (is_array($url)) {
			$url = array_shift($url);
			$this->log_debug("Extracted URL from array: " . $url);
		}

		// Extract the relative path from the URL
		$relativeFilePath = str_replace($upload_dir['baseurl'], '', $url);
		$this->log_debug("Relative file path: " . $relativeFilePath);

		// Trim leading slashes to avoid double slashes in the final path
		$relativeFilePath = ltrim($relativeFilePath, '/');

		// Combine the base directory with the relative file path
		$fullFilePath = $upload_base_dir . '/' . $relativeFilePath;

		// Log the converted path for debugging
		$this->log_debug("Converted URL to path: " . $fullFilePath);

		return $fullFilePath;
	}

	/**
	 * Saves the result to the selected field if configured.
	 *
	 * @return array Modified entry.
	 */
	public function maybe_save_result_to_field($feed, $entry, $form, $text)
	{
		$this->log_debug("maybe_save_result_to_field called. Entry ID: " . $entry['id']);
		$endpoint = rgars($feed, 'meta/endpoint');
		$map_result_to_field = rgar(rgar($feed, 'meta'), $endpoint . '_map_result_to_field');

		if (!is_numeric($map_result_to_field)) {
			$this->log_debug("No field mapped to save the result.");
			return $entry;
		}

		$this->log_debug("Mapping result to field ID: " . $map_result_to_field);

		$field = GFAPI::get_field($form, (int) $map_result_to_field);

		if (rgar($field, 'useRichTextEditor')) {
			$text = wp_kses_post($text); // Allow only certain HTML tags
		} else {
			// Convert <br> tags to line breaks
			if (!is_array($text)) {
				$text = htmlspecialchars_decode($text); // Decode any HTML entities
				$text = preg_replace('/<br\s*\/?>/i', "\n", $text); // Convert <br> to \n
				$text = wp_strip_all_tags($text); // Remove all HTML tags
			}
		}

		$entry[$map_result_to_field] = $text;

		$this->log_debug("Processed text to save in field: " . $text);
		GFAPI::update_entry_field($entry['id'], $map_result_to_field, $text);
		$this->log_debug("Entry field updated. Field ID: " . $map_result_to_field . ", Text: " . $text);

		gf_do_action(array('gf_openai_post_save_result_to_field', $form['id']), $text);

		return $entry;
	}

	/**
	 * Process moderations endpoint.
	 *
	 * @param $feed array The current feed being processed.
	 * @param $entry array The current entry being processed.
	 * @param $form array The current form being processed.
	 *
	 * @return boolean Whether the entry was flagged or not.
	 */
	public function process_endpoint_moderations($feed, $entry, $form)
	{
		$model = $feed['meta']['moderations_model'];
		$input = $feed['meta']['moderations_input'];

		// Parse the merge tags in the input
		$input = GFCommon::replace_variables($input, $form, $entry, false, false, false, 'text');

		// translators: placeholders are the feed name, model, and input
		$this->log_debug(__METHOD__ . '(): ' . sprintf(__('Sent request to OpenAI. Feed: %1$s, Endpoint: moderations, Model: %2$s, Input: %3$s', 'gravityforms-openai'), $feed['meta']['feed_name'], $model, $input));

		$response = $this->make_request('moderations', array(
			'input' => $input,
			'model' => $model,
		), $feed);

		// Do nothing if there is an API error.
		// @todo should this be configurable?
		if (is_wp_error($response)) {
			return false;
		}

		// Parse the response and add it as an entry note.
		$response_data = json_decode($response['body'], true);

		$categories = rgars($response_data, 'results/0/categories');

		if (!is_array($categories)) {
			return false;
		}

		if (rgar($entry, 'id')) {
			GFAPI::add_note($entry['id'], 0, 'OpenAI Response (' . $feed['meta']['feed_name'] . ')', print_r(rgar($response_data, 'results'), true));
		}

		// Check each category for true and if so, invalidate the form immediately.
		// @todo make categories configurable
		foreach ($categories as $category => $value) {
			if ($value && apply_filters('gf_openai_moderations_reject_category', true, $category)) {
				return true;
			}
		}

		return false;
	}

	public function get_default_moderations_validation_message()
	{
		return __('This submission violates our content policy.', 'gravityforms-openai');
	}

	/**
	 * Process moderations endpoint using gform_validation. We'll need to loop through all the feeds and find the
	 * ones using the moderations endpoint as they can't be handled using process_feed().
	 */
	public function moderations_endpoint_validation($validation_result)
	{
		$form = $validation_result['form'];

		// Loop through feeds for this form and find ones using the moderations endopint.
		foreach ($this->get_feeds($form['id']) as $feed) {
			if ($feed['meta']['endpoint'] !== 'moderations') {
				continue;
			}

			// Do not validate if the behavior is not set to validate.
			if ($feed['meta']['moderations_behavior'] !== 'validation_error') {
				continue;
			}

			// Create dummy entry with what has been submitted
			$entry = GFFormsModel::create_lead($form);

			if (!rgar($feed, 'is_active') || !$this->is_feed_condition_met($feed, $form, $entry)) {
				return $validation_result;
			}

			if ($this->process_endpoint_moderations($feed, $entry, $form)) {
				$validation_result['is_valid'] = false;

				$this->moderations_validation_message = rgar($feed['meta'], 'moderations_validation_message', $this->get_default_moderations_validation_message());

				return $validation_result;
			}
		}

		return $validation_result;
	}

	public function modify_validation_message($message, $form)
	{
		if (!isset($this->moderations_validation_message)) {
			return $message;
		}

		return $this->get_validation_error_markup($this->moderations_validation_message, $form);
	}

	/**
	 * Returns validation error message markup.
	 *
	 * @param string $validation_message  The validation message to add to the markup.
	 * @param array  $form                The submitted form data.
	 *
	 * @return false|string
	 */
	protected function get_validation_error_markup($validation_message, $form)
	{
		$error_classes = $this->get_validation_error_css_classes($form);
		ob_start();

		if (!$this->is_gravityforms_supported('2.5')) {
			?>
			<div class="<?php echo esc_attr($error_classes); ?>">
				<?php echo esc_html($validation_message); ?>
			</div>
			<?php
			return ob_get_clean();
		}
		?>
		<h2 class="<?php echo esc_attr($error_classes); ?>">
			<span class="gform-icon gform-icon--close"></span>
			<?php echo esc_html($validation_message); ?>
		</h2>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get the CSS classes for the validation markup.
	 *
	 * @param array $form The submitted form data.
	 */
	protected function get_validation_error_css_classes($form)
	{
		$container_css = $this->is_gravityforms_supported('2.5') ? 'gform_submission_error' : 'validation_error';

		return "{$container_css} hide_summary";
	}

	/**
	 * Process moderations endpoint using gform_validation. We'll need to loop through all the feeds and find the
	 * ones using the moderations endpoint as they can't be handled using process_feed().
	 *
	 * @param $is_spam boolean Whether the entry is spam or not.
	 * @param $form array The current form being processed.
	 * @param $entry array The current entry being processed.
	 *
	 * @return boolean Whether the entry is spam or not.
	 */
	public function moderations_endpoint_spam($is_spam, $form, $entry)
	{
		// Loop through feeds for this form and find ones using the moderations endpoint.
		foreach ($this->get_feeds($form['id']) as $feed) {
			if ($feed['meta']['endpoint'] !== 'moderations') {
				continue;
			}

			if ($feed['meta']['moderations_behavior'] !== 'spam') {
				continue;
			}

			if (!rgar($feed, 'is_active') || !$this->is_feed_condition_met($feed, $form, $entry)) {
				return '';
			}

			if ($this->process_endpoint_moderations($feed, $entry, $form)) {
				return true;
			}
		}

		return $is_spam;
	}

	/**
	 * @param array $response The JSON-decoded response from OpenAI.
	 *
	 * @return string|WP_Error
	 */
	public function get_text_from_response($response)
	{
		if (rgars($response, 'choices/0/text')) {
			return trim(rgars($response, 'choices/0/text'));
		}

		// Chat completions
		if (rgars($response, 'choices/0/message/content')) {
			return trim(rgars($response, 'choices/0/message/content'));
		}

		return trim(rgar($response, 'text'));
	}

	/**
	 * Replace merge tags using the OpenAI response.
	 *
	 * @param string      $text       The text in which merge tags are being processed.
	 * @param false|array $form       The Form object if available or false.
	 * @param false|array $entry      The Entry object if available or false.
	 * @param bool        $url_encode Indicates if the urlencode function should be applied.
	 * @param bool        $esc_html   Indicates if the esc_html function should be applied.
	 * @param bool        $nl2br      Indicates if the nl2br function should be applied.
	 * @param string      $format     The format requested for the location the merge is being used. Possible values: html, text or url.
	 *
	 * @return string The text with merge tags processed.
	 */

	public function replace_merge_tags($text, $form, $entry, $url_encode, $esc_html, $nl2br, $format)
	{
		// Process merge tags only if they are an openai feed.
		if (!strpos($text, 'openai_feed')) {
			return $text;
		}

		preg_match_all('/{[^{]*?:(\d+(\.\d+)?)(:(.*?))?}/mi', $text, $field_variable_matches, PREG_SET_ORDER);

		foreach ($field_variable_matches as $match) {
			$input_id = $match[1];
			$i = $match[0][0] === '{' ? 4 : 5;
			$modifiers_str = rgar($match, $i);
			$modifiers = $this->parse_modifiers($modifiers_str);

			$feed_id = null;

			foreach ($modifiers as $modifier) {
				if (strpos($modifier, 'openai_feed_') === 0) {
					$feed_id = str_replace('openai_feed_', '', $modifier);
					break;
				}
			}

			// Do not process if we don't have a match on openai_feed_ as a modifier as it could impact other merge tags.
			if (!is_numeric($feed_id)) {
				continue;
			}

			// Ensure our field in question has a value
			if (!rgar($entry, $input_id)) {
				$text = str_replace($match[0], '', $text);
				continue;
			}

			$nl2br = rgar($modifiers, 'nl2br') ? true : $nl2br;

			$replacement = $this->get_merge_tag_replacement($form, $entry, $feed_id, $url_encode, $esc_html, $nl2br, $format, $modifiers);
			$text = str_replace($match[0], $replacement, $text);
		}

		preg_match_all('/{(all_fields:)?openai_feed_(\d+)}/mi', $text, $all_fields_matches, PREG_SET_ORDER);

		foreach ($all_fields_matches as $match) {
			$feed_id = $match[2];

			if (!is_numeric($feed_id)) {
				continue;
			}

			$replacement = $this->get_merge_tag_replacement($form, $entry, $feed_id, $url_encode, $esc_html, $nl2br, $format, array());
			$text = str_replace($match[0], $replacement, $text);
		}

		return $text;
	}

	public function get_merge_tag_replacement($form, $entry, $feed_id, $url_encode, $esc_html, $nl2br, $format, $modifiers)
	{
		$feed = $this->get_feed($feed_id);
		$endpoint = rgars($feed, 'meta/endpoint');
		$primary_identifier = $this->get_user_primary_identifier();

		if (!$endpoint || (int) rgar($feed, 'form_id') !== (int) rgar($form, 'id')) {
			return '';
		}

		if (!$feed['meta'][$endpoint . '_enable_merge_tag']) {
			return '';
		}

		if (!rgar($feed, 'is_active') || !$this->is_feed_condition_met($feed, $form, $entry)) {
			return '';
		}

		$response_data = array();

		switch ($endpoint) {
			case 'completions':
				$model = $feed['meta']['completions_model'];
				$prompt = $feed['meta']['completions_prompt'];

				$prompt = GFCommon::replace_variables($prompt, $form, $entry, false, false, false, 'text');

				// If prompt is empty, do not generate any completion response, skip with blank.
				if (empty($prompt)) {
					return '';
				}

				$response = $this->make_request('completions', array(
					'model' => $model,
					'prompt' => $prompt,
				), $feed);

				if (!is_wp_error($response)) {
					$response_data = json_decode($response['body'], true);
				}
				break;

			case 'chat/completions':
				$api_base = rgar($feed['meta'], "api_base_$primary_identifier", 'https://api.openai.com/v1/');

				if (strpos($api_base, 'predibase') !== false) {
					// Get the model from feed metadata
					$model = $feed["meta"]['chat_completions_lora_adapter'];
				} elseif (strpos($api_base, 'runpod') !== false || strpos($api_base, 'api3') !== false) {
					// Get the model from feed metadata
					$model = $feed["meta"]['chat_completions_lora_adapter_HF'];
				} else {
					// Get the model from feed metadata based on user's role or membership
					$model = $feed["meta"]["chat_completion_model_$primary_identifier"];
				}
				$message = $feed['meta']['chat_completions_message'];

				$message = GFCommon::replace_variables($message, $form, $entry, false, false, false, 'text');

				// If message is empty, do not generate any chat response, skip with blank.
				if (empty($message)) {
					return '';
				}

				$response = $this->make_request('chat/completions', array(
					'messages' => array(
						array(
							'role' => 'user',
							'content' => $message,
						),
					),
					'model' => $model,
				), $feed);

				if (!is_wp_error($response)) {
					$response_data = json_decode($response['body'], true);
				}
				break;

			case 'edits':
				$model = $feed['meta']['edits_model'];
				$input = $feed['meta']['edits_input'];
				$instruction = $feed['meta']['edits_instruction'];

				$input = GFCommon::replace_variables($input, $form, $entry, false, false, false, 'text');
				$instruction = GFCommon::replace_variables($instruction, $form, $entry, false, false, false, 'text');

				// If input or instruction is empty, do not generate any edit response, skip with blank.
				if (empty($input) || empty($instruction)) {
					return '';
				}

				$response = $this->make_request('edits', array(
					'model' => $model,
					'input' => $input,
					'instruction' => $instruction,
				), $feed);

				if (!is_wp_error($response)) {
					$response_data = json_decode($response['body'], true);
				}
				break;

			default:
				return '';
		}

		if (!rgar($modifiers, 'raw')) {
			$text = $this->get_text_from_response($response_data);
		} else {
			$text = rgars($response_data, rgar($modifiers, 'raw'));
		}

		$text = $url_encode ? urlencode($text) : $text;
		$text = $format === 'html' ? wp_kses_post($text) : wp_strip_all_tags($text);
		$text = $nl2br ? nl2br($text) : $text;

		return $text;
	}

	/**
	 * @param string $modifiers_str
	 *
	 * @return array
	 */
	public function parse_modifiers($modifiers_str)
	{
		preg_match_all('/([a-z_0-9]+)(?:(?:\[(.+?)\])|,?)/i', $modifiers_str, $modifiers, PREG_SET_ORDER);
		$parsed = array();

		foreach ($modifiers as $modifier) {

			list($match, $modifier, $value) = array_pad($modifier, 3, null);
			if ($value === null) {
				$value = $modifier;
			}

			// Split '1,2,3' into array( 1, 2, 3 ).
			if (strpos($value, ',') !== false) {
				$value = array_map('trim', explode(',', $value));
			}

			$parsed[strtolower($modifier)] = $value;

		}

		return $parsed;
	}

	/**
	 * Helper method to get user Memberpress membership or fall back to user role.
	 */
	public function get_user_primary_identifier()
	{
		$current_user = wp_get_current_user();

		// Default role/membership
		$primary_identifier = 'default';

		// Check for MemberPress memberships
		if (class_exists('MeprUser')) {
			$mepr_user = new MeprUser($current_user->ID);
			$active_memberships = $mepr_user->active_product_subscriptions();

			if (!empty($active_memberships)) {
				$primary_membership = get_post($active_memberships[0]);
				if ($primary_membership) {
					$primary_identifier = $primary_membership->post_name; // User has a membership
				}
			} else {
				$primary_identifier = 'No_membership'; // No active membership
			}
		} else if (!empty($current_user->roles)) {
			$primary_identifier = $current_user->roles[0]; // Fallback to user role
		}

		return $primary_identifier;
	}

	/**
	 * Helper method to send a request to the OpenAI API but also cache it using runtime cache and transients.
	 *
	 * @param string $endpoint The OpenAI endpoint.
	 * @param array $body Request parameters.
	 * @param array $feed The feed being processed.
	 *
	 * @return array|WP_Error The response or WP_Error on failure.
	 */
	public function make_request($endpoint, $body, $feed)
	{
		// Update Method to deal with Language tool API
		static $request_cache = array();
		if($endpoint == 'languagetool'){
			$settings = $this->get_plugin_settings();
			$api_key = isset($settings['language_tool_apiKey']) ? $settings['language_tool_apiKey'] : '';
			$api_username = isset($settings['language_tool_username']) ? $settings['language_tool_username'] : '';
			$api_base = isset($settings['language_tool_base_url']) ? $settings['language_tool_base_url'] : 'https://api.languagetoolplus.com/v2/check';
			$url = $api_base;
		}elseif ($endpoint == 'pronunciation') {
			$settings = $this->get_plugin_settings();
			$speech_key = isset($settings['pronunciation_speech_key']) ? $settings['pronunciation_speech_key'] : '';
			$base_url = isset($settings['pronunciation_base_url']) ? $settings['pronunciation_base_url'] : '';
			$url = $base_url;
		}else{
			// Identify the user meber ship or role
			$primary_role = $this->get_user_primary_identifier();

			// Get the saved API base for the user role from the feed settings
			$option_name = 'api_base_' . $primary_role;
			$api_base = rgar($feed['meta'], $option_name, 'https://api.openai.com/v1/');

			$url = $api_base . $endpoint;

			if ($api_base === 'https://writify.openai.azure.com/openai/deployments/IELTS-Writify/') {
				$url .= '?api-version=2023-03-15-preview';
			}
		}

		$cache_body = $body;
		if (isset($cache_body['file']) && $cache_body['file'] instanceof CURLFile) {
			unset($cache_body['file']); // Exclude the CURLFile object
		}

		$cache_key = sha1(
			serialize(
				array(
					'url' => $url,
					'body' => $cache_body, // Use modified body for cache key
					'request_params' => $this->get_request_params($feed),
				)
			)
		);

		// Check runtime cache first and then transient (if enabled)
		if (isset($request_cache[$cache_key])) {
			return $request_cache[$cache_key];
		}

		$transient = 'gform_openai_cache_' . $cache_key;
		// $use_cache = gf_apply_filters(array('gf_openai_cache_responses', $feed['form_id'], $feed['id']), true, $feed['form_id'], $feed, $endpoint, $body);
		$use_cache = false;
		if ($use_cache && get_transient($transient)) {
			return get_transient($transient);
		}

		$this->log_debug("Making request to endpoint: " . $endpoint);

		// Adjust request for Whisper API
		if ($endpoint === 'audio/transcriptions') {
			// Special handling for Whisper API
			$url = 'https://api3.ieltsscience.fun/v1/' . $endpoint; // Direct URL for Whisper API

			$settings = $this->get_plugin_settings();
			$secret_key_index = $this->getBestSecretKey();

			// Create a new cURL resource
			$ch = curl_init();

			// Set the URL and other options
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt(
				$ch,
				CURLOPT_HTTPHEADER,
				array(
					'Authorization: Bearer ' . $settings["secret_key_$secret_key_index"],
					'Content-Type: multipart/form-data'
				)
			);

			// Execute the request and capture the response
			$response = curl_exec($ch);

			// Check for cURL errors
			if (curl_errno($ch)) {
				$error_msg = curl_error($ch);
				curl_close($ch);
				$this->log_debug("Whisper API request error: " . $error_msg);
				return;
			}

			// Close cURL resource
			curl_close($ch);

			// Process the response
			$this->log_debug("Whisper API response: " . $response);

			// Assuming the response is a JSON string, you might want to decode it
			$response_data = json_decode($response, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				$this->log_debug("Error decoding JSON response: " . json_last_error_msg());
				return;
			}

			// Handle the response data as needed

			return $response_data;
		}

		if ($endpoint === 'pronunciation') {
			// Special handling for Language Tool API
			$settings = $this->get_plugin_settings();
			$speech_key = isset($settings['pronunciation_speech_key']) ? $settings['pronunciation_speech_key'] : '';
			$base_url = isset($settings['pronunciation_base_url']) ? $settings['pronunciation_base_url'] : '';
			$url = $base_url;
		}

		switch ($endpoint) {
			case 'completions':
				$body['max_tokens'] = (float) rgar($feed['meta'], $endpoint . '_' . 'max_tokens', $this->default_settings['completions']['max_tokens']);
				$body['temperature'] = (float) rgar($feed['meta'], $endpoint . '_' . 'temperature', $this->default_settings['completions']['temperature']);
				$body['top_p'] = (float) rgar($feed['meta'], $endpoint . '_' . 'top_p', $this->default_settings['completions']['top_p']);
				$body['frequency_penalty'] = (float) rgar($feed['meta'], $endpoint . '_' . 'frequency_penalty', $this->default_settings['completions']['frequency_penalty']);
				$body['presence_penalty'] = (float) rgar($feed['meta'], $endpoint . '_' . 'presence_penalty', $this->default_settings['completions']['presence_penalty']);
				break;

			case 'chat/completions':
				$body['max_tokens'] = (float) rgar($feed['meta'], $endpoint . '_' . 'max_tokens', $this->default_settings['chat/completions']['max_tokens']);
				$body['temperature'] = (float) rgar($feed['meta'], $endpoint . '_' . 'temperature', $this->default_settings['chat/completions']['temperature']);
				$body['top_p'] = (float) rgar($feed['meta'], $endpoint . '_' . 'top_p', $this->default_settings['chat/completions']['top_p']);
				$body['frequency_penalty'] = (float) rgar($feed['meta'], $endpoint . '_' . 'frequency_penalty', $this->default_settings['chat/completions']['frequency_penalty']);
				$body['presence_penalty'] = (float) rgar($feed['meta'], $endpoint . '_' . 'presence_penalty', $this->default_settings['chat/completions']['presence_penalty']);
				break;

			case 'edits':
				$body['temperature'] = (float) rgar($feed['meta'], $endpoint . '_' . 'temperature', $this->default_settings['edits']['temperature']);
				$body['top_p'] = (float) rgar($feed['meta'], $endpoint . '_' . 'top_p', $this->default_settings['edits']['top_p']);
				break;
			case 'languagetool':
				$body['language'] = rgar($feed['meta'], 'languagetool__language', 'en-US');
				$body['apiKey'] = $api_key;
				$body['username'] = $api_username;
				$body['enabledOnly'] = rgar($feed['meta'], 'languagetool__enabled_only', 'false');
				$body['disabledCategories'] = rgar($feed['meta'], 'languagetool__disabled_categories', 'PUNCTUATION,CASING,TYPOGRAPHY');
				$body['level'] = rgar($feed['meta'], 'languagetool__level', 'picky');

				// Language tool API Requires the Data to be in 'application/x-www-form-urlencoded' format
				$args = array(
					'body'        => http_build_query($body), // Automatically converts array to URL-encoded format
					'headers'     => array(
						'Content-Type' => 'application/x-www-form-urlencoded',
						'Accept'       => 'application/json',
					),
					'method'      => 'POST',
					'timeout'     => 10000,
				);
				// $this->log_debug("Request Args:  " . print_r($args, true));
				// Perform the request
				$response = wp_remote_post($url, $args);
				// $this->log_debug("Languagetool API Payload: " . print_r($body, true));
				// $this->log_debug("Languagetool API Response: " . print_r($response, true));
				break;
			case 'pronunciation':
				// $body['url'] = 'https://beta.ieltsscience.fun/wp-content/uploads/2024/09/Describe-a-party-that-you-enjoyed-1716823970096.mp3'; //Temporary Server URL
				$body['grading_system'] = rgar($feed['meta'], 'pronunciation_grading_system', 'HundredMark');
				$body['granularity'] = rgar($feed['meta'], 'pronunciation_granularity', 'Phoneme');
				$body['dimension'] = rgar($feed['meta'], 'pronunciation_dimension', 'Comprehensive');
				$body['enable_prosody'] = rgar($feed['meta'], 'pronunciation_enable_prosody', 'true');

				// Special Request Headers for Pronunciation API
				$args = array(
					'body'        => http_build_query($body),
					'headers'     => array(
						'Content-Type' => 'application/x-www-form-urlencoded',
						'Accept'       => 'application/json',
						'speech-key'   => $speech_key,
						'service-region' => 'eastus',

					),
					'method'      => 'POST',
					'timeout'     => 1600,
				);
				$response = wp_remote_post($url, $args);
				break;
		}
		$body = apply_filters('gf_openai_request_body', $body, $endpoint, $feed);

		if ($endpoint !== 'languagetool' && $endpoint !== 'pronunciation') {
			$body = apply_filters('gf_openai_request_body', $body, $endpoint, $feed);
			$response = wp_remote_post(
				$url,
				array_merge(
					array(
						'body' => json_encode($body),
					),
					$this->get_request_params($feed)
				)
			);
		}

		if (is_wp_error($response)) {
			return $response;
		}

		$request_cache[$cache_key] = $response;

		if ($use_cache) {
			// Save as a transient for 5 minutes.
			set_transient($transient, $request_cache[$cache_key], 5 * MINUTE_IN_SECONDS);
		}

		return $request_cache[$cache_key];
	}

	/**
	 * Helper method for common headers/settings for wp_remote_post.
	 *
	 * @param array $feed The feed being processed.
	 *
	 * @return array
	 */
	public function get_request_params($feed)
	{
		$endpoint = rgars($feed, 'meta/endpoint');
		$default_timeout = rgar(rgar($this->default_settings, $endpoint), 'timeout');
		$headers = $this->get_headers($feed);

		return array(
			'headers' => $headers,
			'timeout' => rgar($feed['meta'], $endpoint . '_' . 'timeout', $default_timeout),
		);
	}

	/**
	 * Gets headers used for virtually every request.
	 *
	 * @return array
	 */
	public function get_headers($feed = array())
	{
		// Identify the user meber ship or role
		$primary_identifier = $this->get_user_primary_identifier();

		// Get the saved API base for the user role from the feed settings
		$option_name = 'api_base_' . $primary_identifier;
		$api_base = rgar($feed['meta'], $option_name, 'https://api.openai.com/v1/');

		$settings = $this->get_plugin_settings();
		$secret_key_index = $this->getBestSecretKey();

		// Log the retrieved settings and secret key
		// $this->log_debug("Settings: " . print_r($settings, true));
		// $this->log_debug("Selected Secret Key: " . $secret_key_index);

		$organization = $settings["organization_$secret_key_index"];

		$api_key = $settings["api_key_$secret_key_index"];
		if (strpos($api_base, 'predibase') !== false) {
			$secret_key = $settings["pb_key_$secret_key_index"];
		} else {
			$secret_key = $settings["secret_key_$secret_key_index"];
		}

		$headers = array(
			'Content-Type' => 'application/json',
			'Authorization' => 'Bearer ' . $secret_key,
			'api-key' => $api_key,
		);

		if ($organization) {
			$headers['OpenAI-Organization'] = $organization;
		}

		// Log the constructed headers
		// $this->log_debug("Headers: " . print_r($headers, true));

		return $headers;
	}

	/**
	 * Export OpenAI Add-On feeds when exporting forms.
	 *
	 * @param array $form The current form being exported.
	 *
	 * @return array
	 */
	public function export_feeds_with_form($form)
	{
		$feeds = $this->get_feeds($form['id']);

		if (!isset($form['feeds'])) {
			$form['feeds'] = array();
		}

		$form['feeds'][$this->get_slug()] = $feeds;

		return $form;
	}

	/**
	 * Import OpenAI Add-On feeds when importing forms.
	 *
	 * @param array $forms Imported forms.
	 */
	public function import_feeds_with_form($forms)
	{
		foreach ($forms as $import_form) {
			// Ensure the imported form is the latest.
			$form = GFAPI::get_form($import_form['id']);

			if (!rgars($form, 'feeds/' . $this->get_slug())) {
				continue;
			}

			foreach (rgars($form, 'feeds/' . $this->get_slug()) as $feed) {
				GFAPI::add_feed($form['id'], $feed['meta'], $this->get_slug());
			}

			// Remove feeds from the form array as it's no longer needed.
			unset($form['feeds'][$this->get_slug()]);

			if (empty($form['feeds'])) {
				unset($form['feeds']);
			}

			GFAPI::update_form($form);
		}
	}
}
