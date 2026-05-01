<?php
/**
 * Plugin Name: Carbon Repro Instant Actions Widget
 * Plugin URI: https://carbonrepro.com/
 * Description: Displays a reusable instant-actions widget with lead capture, AI chat, and analytics.
 * Version: 3.0.0
 * Author: Carbon Repro
 * License: GPL-2.0-or-later
 * Text Domain: carbon-repro-widget
 */

if (! defined('ABSPATH')) {
    exit;
}

final class Carbon_Repro_Instant_Actions_Widget
{
    const OPTION_KEY = 'criaw_settings';
    const CONVERSATION_POST_TYPE = 'criaw_chat';
    const EVENTS_TABLE_SUFFIX = 'criaw_events';
    const GEO_CACHE_PREFIX = 'criaw_geo_';
    const CRAWL_PAGES_OPTION_KEY = 'criaw_crawl_pages';
    const CATALOG_INDEX_OPTION_KEY = 'criaw_catalog_index';
    const CATALOG_BATCH_TRANSIENT_PREFIX = 'criaw_catalog_batch_';
    const SCHEMA_VERSION = '3.0.0';

    public function __construct()
    {
        add_action('init', array($this, 'register_post_type'));
        add_action('init', array($this, 'maybe_upgrade_schema'));
        add_action('admin_menu', array($this, 'register_admin_pages'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_footer', array($this, 'render_widget'));
        add_action('wp_ajax_criaw_track_event', array($this, 'track_event'));
        add_action('wp_ajax_nopriv_criaw_track_event', array($this, 'track_event'));
        add_action('wp_ajax_criaw_chat_message', array($this, 'handle_chat_message'));
        add_action('wp_ajax_nopriv_criaw_chat_message', array($this, 'handle_chat_message'));
        add_action('wp_ajax_criaw_start_chat', array($this, 'handle_start_chat'));
        add_action('wp_ajax_nopriv_criaw_start_chat', array($this, 'handle_start_chat'));
        add_action('wp_ajax_criaw_chat_updates', array($this, 'get_chat_updates'));
        add_action('wp_ajax_nopriv_criaw_chat_updates', array($this, 'get_chat_updates'));
        add_action('wp_ajax_criaw_catalog_cards', array($this, 'get_catalog_cards'));
        add_action('wp_ajax_nopriv_criaw_catalog_cards', array($this, 'get_catalog_cards'));
        add_action('wp_ajax_criaw_chat_upload', array($this, 'handle_chat_upload'));
        add_action('wp_ajax_nopriv_criaw_chat_upload', array($this, 'handle_chat_upload'));
        add_action('wp_ajax_criaw_admin_inbox_snapshot', array($this, 'get_admin_inbox_snapshot'));
        add_action('wp_ajax_criaw_catalog_index_batch', array($this, 'handle_catalog_index_batch'));
        add_action('admin_post_criaw_send_human_reply', array($this, 'handle_human_reply'));
        add_action('admin_post_criaw_toggle_takeover', array($this, 'handle_takeover_toggle'));
        add_action('admin_post_criaw_run_site_crawl', array($this, 'handle_site_crawl'));
        add_action('admin_post_criaw_run_catalog_index', array($this, 'handle_catalog_index'));
        add_action('admin_post_criaw_test_twilio', array($this, 'handle_twilio_test'));
        add_action('wpforms_process_complete', array($this, 'handle_wpforms_submission'), 10, 4);
    }

    public static function activate()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::EVENTS_TABLE_SUFFIX;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            visitor_id varchar(64) NOT NULL DEFAULT '',
            conversation_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(50) NOT NULL DEFAULT '',
            cta varchar(50) NOT NULL DEFAULT '',
            page_url text NULL,
            page_path varchar(255) NOT NULL DEFAULT '',
            page_title varchar(255) NOT NULL DEFAULT '',
            referrer_url text NULL,
            referrer_domain varchar(191) NOT NULL DEFAULT '',
            utm_source varchar(100) NOT NULL DEFAULT '',
            utm_medium varchar(100) NOT NULL DEFAULT '',
            utm_campaign varchar(150) NOT NULL DEFAULT '',
            utm_term varchar(150) NOT NULL DEFAULT '',
            utm_content varchar(150) NOT NULL DEFAULT '',
            source_group varchar(50) NOT NULL DEFAULT '',
            device_type varchar(50) NOT NULL DEFAULT '',
            browser varchar(100) NOT NULL DEFAULT '',
            os varchar(100) NOT NULL DEFAULT '',
            country_code varchar(10) NOT NULL DEFAULT '',
            country_name varchar(100) NOT NULL DEFAULT '',
            language varchar(20) NOT NULL DEFAULT '',
            timezone varchar(64) NOT NULL DEFAULT '',
            screen_size varchar(32) NOT NULL DEFAULT '',
            ip_hash varchar(64) NOT NULL DEFAULT '',
            user_agent text NULL,
            lead_name varchar(191) NOT NULL DEFAULT '',
            lead_email varchar(191) NOT NULL DEFAULT '',
            lead_phone varchar(100) NOT NULL DEFAULT '',
            lead_need text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY created_at (created_at),
            KEY source_group (source_group),
            KEY device_type (device_type),
            KEY country_code (country_code),
            KEY conversation_id (conversation_id)
        ) {$charset_collate};";

        dbDelta($sql);
        update_option('criaw_schema_version', self::SCHEMA_VERSION);
    }

    public function maybe_upgrade_schema()
    {
        if (get_option('criaw_schema_version') !== self::SCHEMA_VERSION) {
            self::activate();
        }
    }

    public function register_post_type()
    {
        register_post_type(
            self::CONVERSATION_POST_TYPE,
            array(
                'labels' => array(
                    'name' => __('Widget Conversations', 'carbon-repro-widget'),
                    'singular_name' => __('Widget Conversation', 'carbon-repro-widget'),
                ),
                'public' => false,
                'show_ui' => false,
                'show_in_menu' => false,
                'supports' => array('title'),
                'capability_type' => 'post',
                'map_meta_cap' => true,
                'exclude_from_search' => true,
            )
        );
    }

    public function register_admin_pages()
    {
        $menu_label = $this->get_setting('white_label_plugin_name', __('Carbon Repro Widget', 'carbon-repro-widget'));

        add_menu_page(
            $menu_label,
            $menu_label,
            'manage_options',
            'carbon-repro-widget',
            array($this, 'render_dashboard_page'),
            'dashicons-format-chat',
            58
        );

        add_submenu_page(
            'carbon-repro-widget',
            __('Dashboard', 'carbon-repro-widget'),
            __('Dashboard', 'carbon-repro-widget'),
            'manage_options',
            'carbon-repro-widget',
            array($this, 'render_dashboard_page')
        );

        add_submenu_page(
            'carbon-repro-widget',
            __('Conversations', 'carbon-repro-widget'),
            __('Conversations', 'carbon-repro-widget'),
            'manage_options',
            'carbon-repro-widget-conversations',
            array($this, 'render_conversations_page')
        );

        add_submenu_page(
            'carbon-repro-widget',
            __('Form Submissions', 'carbon-repro-widget'),
            __('Form Submissions', 'carbon-repro-widget'),
            'manage_options',
            'carbon-repro-widget-forms',
            array($this, 'render_form_submissions_page')
        );

        add_submenu_page(
            'carbon-repro-widget',
            __('Settings', 'carbon-repro-widget'),
            __('Settings', 'carbon-repro-widget'),
            'manage_options',
            'carbon-repro-widget-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings()
    {
        register_setting(
            'criaw_settings_group',
            self::OPTION_KEY,
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default' => $this->get_default_settings(),
            )
        );

        add_settings_section(
            'criaw_business_section',
            __('Business Profile', 'carbon-repro-widget'),
            '__return_empty_string',
            'carbon-repro-widget-settings'
        );

        add_settings_section(
            'criaw_widget_section',
            __('Widget Actions', 'carbon-repro-widget'),
            '__return_empty_string',
            'carbon-repro-widget-settings'
        );

        add_settings_section(
            'criaw_design_section',
            __('Design & Branding', 'carbon-repro-widget'),
            '__return_empty_string',
            'carbon-repro-widget-settings'
        );

        add_settings_section(
            'criaw_chat_section',
            __('AI Chatbot', 'carbon-repro-widget'),
            '__return_empty_string',
            'carbon-repro-widget-settings'
        );

        $fields = array(
            'white_label_plugin_name' => __('Admin Plugin Name', 'carbon-repro-widget'),
            'assistant_mode' => __('Assistant Mode', 'carbon-repro-widget'),
            'business_name' => __('Business Name', 'carbon-repro-widget'),
            'business_industry' => __('Business Industry', 'carbon-repro-widget'),
            'business_location' => __('Business Location', 'carbon-repro-widget'),
            'business_services' => __('Services Offered', 'carbon-repro-widget'),
            'business_faqs' => __('Common FAQs / Key Info', 'carbon-repro-widget'),
            'business_terminology_notes' => __('Critical Terminology / Don\'t Confuse Notes', 'carbon-repro-widget'),
            'qualification_questions' => __('Qualification Questions', 'carbon-repro-widget'),
            'auto_crawl_enabled' => __('Enable Website Crawl Knowledge', 'carbon-repro-widget'),
            'crawl_focus_paths' => __('Preferred Crawl Paths', 'carbon-repro-widget'),
            'crawled_knowledge' => __('Crawled Website Knowledge', 'carbon-repro-widget'),
            'catalog_index_enabled' => __('Enable Ecommerce Catalog Index', 'carbon-repro-widget'),
            'catalog_index_knowledge' => __('Indexed Catalog Knowledge', 'carbon-repro-widget'),
            'brand_logo_url' => __('Business Logo', 'carbon-repro-widget'),
            'launcher_icon_url' => __('Launcher Icon', 'carbon-repro-widget'),
            'theme_preset' => __('Theme Preset', 'carbon-repro-widget'),
            'primary_color' => __('Primary Color', 'carbon-repro-widget'),
            'secondary_color' => __('Secondary Color', 'carbon-repro-widget'),
            'accent_color' => __('Accent Color', 'carbon-repro-widget'),
            'panel_background_color' => __('Panel Background', 'carbon-repro-widget'),
            'surface_color' => __('Surface Color', 'carbon-repro-widget'),
            'text_color' => __('Text Color', 'carbon-repro-widget'),
            'button_text_color' => __('Button Text Color', 'carbon-repro-widget'),
            'launcher_text_color' => __('Launcher Text Color', 'carbon-repro-widget'),
            'label_text' => __('Launcher Label', 'carbon-repro-widget'),
            'menu_title' => __('Menu Title', 'carbon-repro-widget'),
            'form_title' => __('Form Title', 'carbon-repro-widget'),
            'footer_brand' => __('Footer Brand', 'carbon-repro-widget'),
            'phone_number' => __('Phone Number', 'carbon-repro-widget'),
            'store_contact_phone' => __('Bot Contact Phone', 'carbon-repro-widget'),
            'store_contact_email' => __('Bot Contact Email', 'carbon-repro-widget'),
            'store_contact_location' => __('Bot Contact Location', 'carbon-repro-widget'),
            'store_contact_location_url' => __('Bot Location Link', 'carbon-repro-widget'),
            'wpforms_id' => __('WPForms ID', 'carbon-repro-widget'),
            'form_enabled' => __('Enable Form Action', 'carbon-repro-widget'),
            'call_enabled' => __('Enable Call Action', 'carbon-repro-widget'),
            'text_enabled' => __('Enable SMS Action', 'carbon-repro-widget'),
            'call_label' => __('Call Button Label', 'carbon-repro-widget'),
            'text_label' => __('SMS Button Label', 'carbon-repro-widget'),
            'chatbot_enabled' => __('Enable AI Chatbot', 'carbon-repro-widget'),
            'chatbot_title' => __('Chatbot Title', 'carbon-repro-widget'),
            'chatbot_welcome_message' => __('Welcome Message', 'carbon-repro-widget'),
            'chat_message_limit' => __('Chat Message Limit', 'carbon-repro-widget'),
            'chat_limit_message' => __('Limit Reached Message', 'carbon-repro-widget'),
            'notification_email' => __('Notification Email', 'carbon-repro-widget'),
            'notification_sms_number' => __('Admin Notification Phone', 'carbon-repro-widget'),
            'customer_reply_notifications' => __('Customer Email Reply Alerts', 'carbon-repro-widget'),
            'customer_takeover_notifications' => __('Customer Takeover Alerts', 'carbon-repro-widget'),
            'admin_sms_notifications' => __('Admin SMS Alerts', 'carbon-repro-widget'),
            'customer_sms_notifications' => __('Customer SMS Alerts', 'carbon-repro-widget'),
            'require_email_consent' => __('Require Email Consent', 'carbon-repro-widget'),
            'customer_consent_label' => __('Consent Checkbox Label', 'carbon-repro-widget'),
            'sms_consent_label' => __('SMS Consent Label', 'carbon-repro-widget'),
            'whatsapp_consent_label' => __('WhatsApp Consent Label', 'carbon-repro-widget'),
            'twilio_enabled' => __('Enable Twilio SMS', 'carbon-repro-widget'),
            'twilio_account_sid' => __('Twilio Account SID', 'carbon-repro-widget'),
            'twilio_auth_token' => __('Twilio Auth Token', 'carbon-repro-widget'),
            'twilio_from_number' => __('Twilio From Number', 'carbon-repro-widget'),
            'chatbot_model' => __('OpenAI Model', 'carbon-repro-widget'),
            'openai_api_key' => __('OpenAI API Key', 'carbon-repro-widget'),
            'chatbot_prompt' => __('Custom Bot Instructions', 'carbon-repro-widget'),
        );

        foreach ($fields as $key => $label) {
            $section = 'criaw_chat_section';
            if (in_array($key, array('white_label_plugin_name', 'assistant_mode', 'business_name', 'business_industry', 'business_location', 'business_services', 'business_faqs', 'business_terminology_notes', 'qualification_questions', 'auto_crawl_enabled', 'crawl_focus_paths', 'crawled_knowledge', 'catalog_index_enabled', 'catalog_index_knowledge'), true)) {
                $section = 'criaw_business_section';
            } elseif (in_array($key, array('brand_logo_url', 'launcher_icon_url', 'theme_preset', 'primary_color', 'secondary_color', 'accent_color', 'panel_background_color', 'surface_color', 'text_color', 'button_text_color', 'launcher_text_color'), true)) {
                $section = 'criaw_design_section';
            } elseif (in_array($key, array('label_text', 'menu_title', 'form_title', 'footer_brand', 'phone_number', 'store_contact_phone', 'store_contact_email', 'store_contact_location', 'store_contact_location_url', 'wpforms_id', 'form_enabled', 'call_enabled', 'text_enabled', 'call_label', 'text_label'), true)) {
                $section = 'criaw_widget_section';
            }

            add_settings_field(
                $key,
                $label,
                array($this, 'render_settings_field'),
                'carbon-repro-widget-settings',
                $section,
                array('key' => $key)
            );
        }
    }

    public function sanitize_settings($input)
    {
        $defaults = $this->get_default_settings();

        return array(
            'white_label_plugin_name' => sanitize_text_field(isset($input['white_label_plugin_name']) ? $input['white_label_plugin_name'] : $defaults['white_label_plugin_name']),
            'assistant_mode' => in_array(isset($input['assistant_mode']) ? $input['assistant_mode'] : $defaults['assistant_mode'], array('ecommerce', 'general'), true) ? (isset($input['assistant_mode']) ? $input['assistant_mode'] : $defaults['assistant_mode']) : 'ecommerce',
            'business_name' => sanitize_text_field(isset($input['business_name']) ? $input['business_name'] : $defaults['business_name']),
            'business_industry' => sanitize_text_field(isset($input['business_industry']) ? $input['business_industry'] : $defaults['business_industry']),
            'business_location' => sanitize_text_field(isset($input['business_location']) ? $input['business_location'] : $defaults['business_location']),
            'business_services' => sanitize_textarea_field(isset($input['business_services']) ? $input['business_services'] : $defaults['business_services']),
            'business_faqs' => sanitize_textarea_field(isset($input['business_faqs']) ? $input['business_faqs'] : $defaults['business_faqs']),
            'business_terminology_notes' => sanitize_textarea_field(isset($input['business_terminology_notes']) ? $input['business_terminology_notes'] : $defaults['business_terminology_notes']),
            'qualification_questions' => sanitize_textarea_field(isset($input['qualification_questions']) ? $input['qualification_questions'] : $defaults['qualification_questions']),
            'auto_crawl_enabled' => empty($input['auto_crawl_enabled']) ? '0' : '1',
            'crawl_focus_paths' => sanitize_textarea_field(isset($input['crawl_focus_paths']) ? $input['crawl_focus_paths'] : $defaults['crawl_focus_paths']),
            'crawled_knowledge' => sanitize_textarea_field(isset($input['crawled_knowledge']) ? $input['crawled_knowledge'] : $defaults['crawled_knowledge']),
            'catalog_index_enabled' => empty($input['catalog_index_enabled']) ? '0' : '1',
            'catalog_index_knowledge' => sanitize_textarea_field(isset($input['catalog_index_knowledge']) ? $input['catalog_index_knowledge'] : $defaults['catalog_index_knowledge']),
            'brand_logo_url' => esc_url_raw(isset($input['brand_logo_url']) ? $input['brand_logo_url'] : $defaults['brand_logo_url']),
            'launcher_icon_url' => esc_url_raw(isset($input['launcher_icon_url']) ? $input['launcher_icon_url'] : $defaults['launcher_icon_url']),
            'theme_preset' => sanitize_text_field(isset($input['theme_preset']) ? $input['theme_preset'] : $defaults['theme_preset']),
            'primary_color' => sanitize_hex_color(isset($input['primary_color']) ? $input['primary_color'] : $defaults['primary_color']) ?: $defaults['primary_color'],
            'secondary_color' => sanitize_hex_color(isset($input['secondary_color']) ? $input['secondary_color'] : $defaults['secondary_color']) ?: $defaults['secondary_color'],
            'accent_color' => sanitize_hex_color(isset($input['accent_color']) ? $input['accent_color'] : $defaults['accent_color']) ?: $defaults['accent_color'],
            'panel_background_color' => sanitize_hex_color(isset($input['panel_background_color']) ? $input['panel_background_color'] : $defaults['panel_background_color']) ?: $defaults['panel_background_color'],
            'surface_color' => sanitize_hex_color(isset($input['surface_color']) ? $input['surface_color'] : $defaults['surface_color']) ?: $defaults['surface_color'],
            'text_color' => sanitize_hex_color(isset($input['text_color']) ? $input['text_color'] : $defaults['text_color']) ?: $defaults['text_color'],
            'button_text_color' => sanitize_hex_color(isset($input['button_text_color']) ? $input['button_text_color'] : $defaults['button_text_color']) ?: $defaults['button_text_color'],
            'launcher_text_color' => sanitize_hex_color(isset($input['launcher_text_color']) ? $input['launcher_text_color'] : $defaults['launcher_text_color']) ?: $defaults['launcher_text_color'],
            'label_text' => sanitize_text_field(isset($input['label_text']) ? $input['label_text'] : $defaults['label_text']),
            'menu_title' => sanitize_text_field(isset($input['menu_title']) ? $input['menu_title'] : $defaults['menu_title']),
            'form_title' => sanitize_text_field(isset($input['form_title']) ? $input['form_title'] : $defaults['form_title']),
            'footer_brand' => sanitize_text_field(isset($input['footer_brand']) ? $input['footer_brand'] : $defaults['footer_brand']),
            'phone_number' => preg_replace('/[^0-9+]/', '', (string) (isset($input['phone_number']) ? $input['phone_number'] : $defaults['phone_number'])),
            'store_contact_phone' => preg_replace('/[^0-9+]/', '', (string) (isset($input['store_contact_phone']) ? $input['store_contact_phone'] : $defaults['store_contact_phone'])),
            'store_contact_email' => sanitize_email(isset($input['store_contact_email']) ? $input['store_contact_email'] : $defaults['store_contact_email']),
            'store_contact_location' => sanitize_text_field(isset($input['store_contact_location']) ? $input['store_contact_location'] : $defaults['store_contact_location']),
            'store_contact_location_url' => esc_url_raw(isset($input['store_contact_location_url']) ? $input['store_contact_location_url'] : $defaults['store_contact_location_url']),
            'wpforms_id' => absint(isset($input['wpforms_id']) ? $input['wpforms_id'] : $defaults['wpforms_id']),
            'form_enabled' => empty($input['form_enabled']) ? '0' : '1',
            'call_enabled' => empty($input['call_enabled']) ? '0' : '1',
            'text_enabled' => empty($input['text_enabled']) ? '0' : '1',
            'call_label' => sanitize_text_field(isset($input['call_label']) ? $input['call_label'] : $defaults['call_label']),
            'text_label' => sanitize_text_field(isset($input['text_label']) ? $input['text_label'] : $defaults['text_label']),
            'chatbot_enabled' => empty($input['chatbot_enabled']) ? '0' : '1',
            'chatbot_title' => sanitize_text_field(isset($input['chatbot_title']) ? $input['chatbot_title'] : $defaults['chatbot_title']),
            'chatbot_welcome_message' => sanitize_textarea_field(isset($input['chatbot_welcome_message']) ? $input['chatbot_welcome_message'] : $defaults['chatbot_welcome_message']),
            'chat_message_limit' => max(0, absint(isset($input['chat_message_limit']) ? $input['chat_message_limit'] : $defaults['chat_message_limit'])),
            'chat_limit_message' => sanitize_textarea_field(isset($input['chat_limit_message']) ? $input['chat_limit_message'] : $defaults['chat_limit_message']),
            'notification_email' => sanitize_email(isset($input['notification_email']) ? $input['notification_email'] : $defaults['notification_email']),
            'notification_sms_number' => preg_replace('/[^0-9+]/', '', (string) (isset($input['notification_sms_number']) ? $input['notification_sms_number'] : $defaults['notification_sms_number'])),
            'customer_reply_notifications' => empty($input['customer_reply_notifications']) ? '0' : '1',
            'customer_takeover_notifications' => empty($input['customer_takeover_notifications']) ? '0' : '1',
            'admin_sms_notifications' => empty($input['admin_sms_notifications']) ? '0' : '1',
            'customer_sms_notifications' => empty($input['customer_sms_notifications']) ? '0' : '1',
            'require_email_consent' => empty($input['require_email_consent']) ? '0' : '1',
            'customer_consent_label' => sanitize_text_field(isset($input['customer_consent_label']) ? $input['customer_consent_label'] : $defaults['customer_consent_label']),
            'sms_consent_label' => sanitize_text_field(isset($input['sms_consent_label']) ? $input['sms_consent_label'] : $defaults['sms_consent_label']),
            'whatsapp_consent_label' => sanitize_text_field(isset($input['whatsapp_consent_label']) ? $input['whatsapp_consent_label'] : $defaults['whatsapp_consent_label']),
            'twilio_enabled' => empty($input['twilio_enabled']) ? '0' : '1',
            'twilio_account_sid' => sanitize_text_field(isset($input['twilio_account_sid']) ? $input['twilio_account_sid'] : $defaults['twilio_account_sid']),
            'twilio_auth_token' => sanitize_text_field(isset($input['twilio_auth_token']) ? $input['twilio_auth_token'] : $defaults['twilio_auth_token']),
            'twilio_from_number' => preg_replace('/[^0-9+]/', '', (string) (isset($input['twilio_from_number']) ? $input['twilio_from_number'] : $defaults['twilio_from_number'])),
            'chatbot_model' => sanitize_text_field(isset($input['chatbot_model']) ? $input['chatbot_model'] : $defaults['chatbot_model']),
            'openai_api_key' => sanitize_text_field(isset($input['openai_api_key']) ? $input['openai_api_key'] : $defaults['openai_api_key']),
            'chatbot_prompt' => sanitize_textarea_field(isset($input['chatbot_prompt']) ? $input['chatbot_prompt'] : $defaults['chatbot_prompt']),
        );
    }

    public function render_settings_field($args)
    {
        $key = $args['key'];
        $settings = wp_parse_args(get_option(self::OPTION_KEY, array()), $this->get_default_settings());
        $value = isset($settings[$key]) ? $settings[$key] : '';

        if (in_array($key, array('chatbot_enabled', 'auto_crawl_enabled', 'catalog_index_enabled', 'customer_reply_notifications', 'customer_takeover_notifications', 'admin_sms_notifications', 'customer_sms_notifications', 'require_email_consent', 'form_enabled', 'call_enabled', 'text_enabled', 'twilio_enabled'), true)) {
            $labels = array(
                'chatbot_enabled' => __('Show the chatbot inside the widget.', 'carbon-repro-widget'),
                'auto_crawl_enabled' => __('Allow the plugin to crawl key website pages and summarize them for the chatbot.', 'carbon-repro-widget'),
                'catalog_index_enabled' => __('Use WooCommerce products and categories as a structured knowledge source when available.', 'carbon-repro-widget'),
                'customer_reply_notifications' => __('Email visitors when a human reply is sent, when email and consent are available.', 'carbon-repro-widget'),
                'customer_takeover_notifications' => __('Email visitors when a team member takes over the chat, when email and consent are available.', 'carbon-repro-widget'),
                'admin_sms_notifications' => __('Send SMS alerts to the store team when a visitor starts or escalates a chat.', 'carbon-repro-widget'),
                'customer_sms_notifications' => __('Send SMS alerts to the visitor when chat starts, when takeover begins, and when a human replies.', 'carbon-repro-widget'),
                'require_email_consent' => __('Only send visitor email alerts when they explicitly opt in.', 'carbon-repro-widget'),
                'form_enabled' => __('Show the WPForms action in the widget menu.', 'carbon-repro-widget'),
                'call_enabled' => __('Show the call action in the widget menu.', 'carbon-repro-widget'),
                'text_enabled' => __('Show the SMS action in the widget menu.', 'carbon-repro-widget'),
                'twilio_enabled' => __('Enable SMS notifications through Twilio.', 'carbon-repro-widget'),
            );
            printf(
                '<label><input type="checkbox" id="%1$s" name="%2$s[%1$s]" value="1" %3$s /> %4$s</label>',
                esc_attr($key),
                esc_attr(self::OPTION_KEY),
                checked($value, '1', false),
                esc_html($labels[$key])
            );
            return;
        }

        if (in_array($key, array('business_services', 'business_faqs', 'business_terminology_notes', 'qualification_questions', 'crawl_focus_paths', 'crawled_knowledge', 'catalog_index_knowledge', 'chatbot_welcome_message', 'chatbot_prompt', 'chat_limit_message'), true)) {
            $rows = in_array($key, array('business_services', 'business_faqs', 'business_terminology_notes', 'qualification_questions', 'crawl_focus_paths', 'crawled_knowledge', 'catalog_index_knowledge', 'chatbot_prompt', 'chat_limit_message'), true) ? 6 : 4;
            printf(
                '<textarea id="%1$s" name="%2$s[%1$s]" rows="%3$d" class="large-text">%4$s</textarea>',
                esc_attr($key),
                esc_attr(self::OPTION_KEY),
                $rows,
                esc_textarea($value)
            );

            if ($key === 'business_services') {
                echo '<p class="description">' . esc_html__('Add each service on a new line. Example: Teeth whitening, Dental implants, Root canal, Emergency dental care.', 'carbon-repro-widget') . '</p>';
            }

            if ($key === 'qualification_questions') {
                echo '<p class="description">' . esc_html__('Add one question per line. The bot will prioritize these before asking broader follow-up questions.', 'carbon-repro-widget') . '</p>';
            }

            if ($key === 'crawl_focus_paths') {
                echo '<p class="description">' . esc_html__('Optional: add one path per line, such as /services, /products, /security-doors. The crawler will prioritize these pages.', 'carbon-repro-widget') . '</p>';
            }

            if ($key === 'crawled_knowledge') {
                echo '<p class="description">' . esc_html__('This is the stored summary generated from website crawling. You can edit it manually after a crawl.', 'carbon-repro-widget') . '</p>';
            }

            if ($key === 'catalog_index_knowledge') {
                echo '<p class="description">' . esc_html__('This is the stored ecommerce catalog index. It is generated from WooCommerce products and categories, and can be reviewed manually.', 'carbon-repro-widget') . '</p>';
            }

            return;
        }

        if (in_array($key, array('primary_color', 'secondary_color', 'accent_color', 'panel_background_color', 'surface_color', 'text_color', 'button_text_color', 'launcher_text_color'), true)) {
            printf(
                '<input type="color" id="%1$s" name="%2$s[%1$s]" value="%3$s" />',
                esc_attr($key),
                esc_attr(self::OPTION_KEY),
                esc_attr($value)
            );
            return;
        }

        if ($key === 'assistant_mode') {
            $modes = array(
                'ecommerce' => __('Ecommerce (products + business info)', 'carbon-repro-widget'),
                'general' => __('General Business (no product catalog suggestions)', 'carbon-repro-widget'),
            );
            echo '<select id="' . esc_attr($key) . '" name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($key) . ']">';
            foreach ($modes as $mode_key => $mode_label) {
                echo '<option value="' . esc_attr($mode_key) . '" ' . selected($value, $mode_key, false) . '>' . esc_html($mode_label) . '</option>';
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__('Use General mode for non-WooCommerce websites. Ecommerce mode enables product and catalog intelligence.', 'carbon-repro-widget') . '</p>';
            return;
        }

        if ($key === 'theme_preset') {
            $presets = array(
                'custom' => __('Custom Colors', 'carbon-repro-widget'),
                'ocean' => __('Ocean Luxury', 'carbon-repro-widget'),
                'midnight' => __('Midnight Gold', 'carbon-repro-widget'),
                'forest' => __('Forest Estate', 'carbon-repro-widget'),
                'sunset' => __('Sunset Commerce', 'carbon-repro-widget'),
            );
            echo '<select id="' . esc_attr($key) . '" name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($key) . ']">';
            foreach ($presets as $preset_key => $preset_label) {
                echo '<option value="' . esc_attr($preset_key) . '" ' . selected($value, $preset_key, false) . '>' . esc_html($preset_label) . '</option>';
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__('Pick a ready-made theme or keep Custom Colors and control each color manually.', 'carbon-repro-widget') . '</p>';
            return;
        }

        if (in_array($key, array('brand_logo_url', 'launcher_icon_url'), true)) {
            printf(
                '<input type="text" id="%1$s" name="%2$s[%1$s]" value="%3$s" class="regular-text criaw-logo-url" /> <button type="button" class="button criaw-upload-logo" data-target="%1$s">%4$s</button>',
                esc_attr($key),
                esc_attr(self::OPTION_KEY),
                esc_attr($value),
                esc_html__('Upload Logo', 'carbon-repro-widget')
            );
            if ($value !== '') {
                echo '<div style="margin-top:10px;"><img src="' . esc_url($value) . '" alt="" style="max-height:56px;width:auto;border-radius:10px;border:1px solid #dbe7e3;padding:6px;background:#fff;" /></div>';
            }
            return;
        }

        $type = 'text';
        $attrs = '';

        if ($key === 'wpforms_id' || $key === 'chat_message_limit') {
            $type = 'number';
            $attrs = $key === 'chat_message_limit' ? ' min="0" step="1"' : ' min="1" step="1"';
        } elseif (in_array($key, array('notification_email', 'store_contact_email'), true)) {
            $type = 'email';
        } elseif ($key === 'store_contact_location_url') {
            $type = 'url';
        } elseif (in_array($key, array('openai_api_key', 'twilio_auth_token'), true)) {
            $type = 'password';
            $attrs = ' autocomplete="off"';
        }

        printf(
            '<input type="%1$s" id="%2$s" name="%3$s[%2$s]" value="%4$s" class="regular-text"%5$s />',
            esc_attr($type),
            esc_attr($key),
            esc_attr(self::OPTION_KEY),
            esc_attr($value),
            $attrs
        );
    }

    public function enqueue_assets()
    {
        if (is_admin()) {
            return;
        }

        $plugin_url = plugin_dir_url(__FILE__);
        $plugin_path = plugin_dir_path(__FILE__);
        $css_path = $plugin_path . 'assets/css/widget.css';
        $js_path = $plugin_path . 'assets/js/widget.js';
        $version = file_exists($css_path) ? (string) filemtime($css_path) : '3.0.0';

        wp_enqueue_style(
            'carbon-repro-widget',
            $plugin_url . 'assets/css/widget.css',
            array(),
            $version
        );

        wp_enqueue_script(
            'carbon-repro-widget',
            $plugin_url . 'assets/js/widget.js',
            array(),
            file_exists($js_path) ? (string) filemtime($js_path) : '3.0.0',
            true
        );

        wp_localize_script(
            'carbon-repro-widget',
            'carbonReproWidget',
            array(
                'smsNumber' => $this->get_setting('phone_number', '7137056097'),
                'chatLimit' => (int) $this->get_setting('chat_message_limit', 0),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('criaw_track_event'),
                'chatNonce' => wp_create_nonce('criaw_chat_message'),
                'startNonce' => wp_create_nonce('criaw_start_chat'),
                'uploadNonce' => wp_create_nonce('criaw_chat_upload'),
                'catalogNonce' => wp_create_nonce('criaw_catalog_cards'),
                'chatEnabled' => $this->is_chatbot_enabled() ? '1' : '0',
                'chatTitle' => $this->get_setting('chatbot_title', 'AI Assistant'),
                'archiveLabel' => __('Previous Chats', 'carbon-repro-widget'),
                'welcomeMessage' => $this->get_default_start_reply(
                    array(
                        'name' => '',
                        'email' => '',
                        'phone' => '',
                        'looking_for' => '',
                    )
                ),
                'consentRequired' => $this->get_setting('require_email_consent', '0'),
                'consentLabel' => $this->get_setting('customer_consent_label', $this->get_default_settings()['customer_consent_label']),
                'smsConsentLabel' => $this->get_setting('sms_consent_label', $this->get_default_settings()['sms_consent_label']),
                'whatsappConsentLabel' => $this->get_setting('whatsapp_consent_label', $this->get_default_settings()['whatsapp_consent_label']),
                'intakeRequired' => '1',
                'strings' => array(
                    'thinking' => __('Typing...', 'carbon-repro-widget'),
                    'error' => __('Sorry, the assistant is unavailable right now. Please call, text, or use the form and the team will contact you.', 'carbon-repro-widget'),
                    'previousChats' => __('Previous Chats', 'carbon-repro-widget'),
                    'resetChat' => __('Reset Chat', 'carbon-repro-widget'),
                    'closeHistory' => __('Close History', 'carbon-repro-widget'),
                    'emptyHistory' => __('No previous chats saved on this browser yet.', 'carbon-repro-widget'),
                    'newReply' => __('New reply ready', 'carbon-repro-widget'),
                    'humanTakeover' => __('A team member has joined the chat.', 'carbon-repro-widget'),
                    'intakeTitle' => __('Before we start', 'carbon-repro-widget'),
                    'intakeBody' => '',
                    'intakeName' => __('Full name', 'carbon-repro-widget'),
                    'intakeEmail' => __('Email', 'carbon-repro-widget'),
                    'intakePhone' => __('Phone', 'carbon-repro-widget'),
                    'intakeNeed' => __('What are you looking for?', 'carbon-repro-widget'),
                    'startChat' => __('Start Chat', 'carbon-repro-widget'),
                    'uploadPhoto' => __('Upload Photo', 'carbon-repro-widget'),
                    'uploading' => __('Uploading...', 'carbon-repro-widget'),
                    'carouselPrev' => __('Previous products', 'carbon-repro-widget'),
                    'carouselNext' => __('Next products', 'carbon-repro-widget'),
                ),
            )
        );
    }

    public function enqueue_admin_assets($hook)
    {
        if (strpos((string) $hook, 'carbon-repro-widget') === false) {
            return;
        }

        wp_enqueue_media();
    }

    public function render_widget()
    {
        if (is_admin()) {
            return;
        }

        $label_text = $this->get_setting('label_text', 'Start Growing Today');
        $menu_title = $this->get_setting('menu_title', 'Instant Actions');
        $form_title = $this->get_setting('form_title', 'Get Your Strategy');
        $footer_brand = $this->get_setting('footer_brand', 'Carbon Repro');
        $form_id = absint($this->get_setting('wpforms_id', '46362'));
        $chatbot_title = $this->get_setting('chatbot_title', 'AI Assistant');
        $chat_enabled = $this->is_chatbot_enabled();
        $brand_logo_url = $this->get_setting('brand_logo_url', '');
        $launcher_icon_url = $this->get_setting('launcher_icon_url', '');
        $theme_colors = $this->get_theme_colors();
        $form_enabled = $this->get_setting('form_enabled', '1') === '1';
        $call_enabled = $this->get_setting('call_enabled', '1') === '1';
        $text_enabled = $this->get_setting('text_enabled', '1') === '1';
        $call_label = $this->get_setting('call_label', __('Call Us', 'carbon-repro-widget'));
        $text_label = $this->get_setting('text_label', __('Text Us', 'carbon-repro-widget'));
        $widget_style = sprintf(
            '--watch-primary:%1$s; --watch-secondary:%2$s; --watch-accent:%3$s; --watch-panel-bg:%4$s; --watch-surface:%5$s; --watch-text:%6$s; --watch-button-text:%7$s; --watch-launcher-text:%8$s;',
            esc_attr($theme_colors['primary_color']),
            esc_attr($theme_colors['secondary_color']),
            esc_attr($theme_colors['accent_color']),
            esc_attr($theme_colors['panel_background_color']),
            esc_attr($theme_colors['surface_color']),
            esc_attr($theme_colors['text_color']),
            esc_attr($theme_colors['button_text_color']),
            esc_attr($theme_colors['launcher_text_color'])
        );
        $menu_actions = array();
        if ($chat_enabled) {
            $menu_actions[] = array('key' => 'chat', 'label' => $chatbot_title, 'icon' => 'chat');
        }
        if ($form_enabled) {
            $menu_actions[] = array('key' => 'show-form', 'label' => $form_title, 'icon' => 'form');
        }
        if ($call_enabled) {
            $menu_actions[] = array('key' => 'call', 'label' => $call_label, 'icon' => 'call');
        }
        if ($text_enabled) {
            $menu_actions[] = array('key' => 'text', 'label' => $text_label, 'icon' => 'text');
        }
        ?>
        <div id="watchWidget" data-watch-form-id="<?php echo esc_attr((string) $form_id); ?>" style="<?php echo $widget_style; ?>">
            <div class="watch-launcher" id="watchLauncher">
                <div class="watch-toast" id="watchToast" aria-live="polite"></div>
                <div class="watch-label" id="watchLabel"><?php echo esc_html($label_text); ?></div>
                <button type="button" class="watch-button" id="watchBtn" aria-label="<?php esc_attr_e('Open quick actions', 'carbon-repro-widget'); ?>">
                    <span class="watch-unread-badge" id="watchUnreadBadge" aria-hidden="true">0</span>
                    <?php if ($launcher_icon_url !== '') : ?>
                        <img class="watch-icon-image" src="<?php echo esc_url($launcher_icon_url); ?>" alt="" />
                    <?php else : ?>
                        <svg class="watch-icon-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                        </svg>
                    <?php endif; ?>
                </button>
            </div>

            <div id="watchOverlay"></div>

            <div class="watch-menu" id="watchMenu" aria-hidden="true">
                <div class="watch-menu-header">
                    <?php if ($brand_logo_url !== '') : ?>
                        <img class="watch-brand-logo" src="<?php echo esc_url($brand_logo_url); ?>" alt="<?php echo esc_attr($footer_brand); ?>" />
                    <?php endif; ?>
                    <span class="watch-menu-title"><?php echo esc_html($menu_title); ?></span>
                    <button type="button" class="watch-menu-close-btn" id="watchMenuCloseBtn" aria-label="<?php esc_attr_e('Close menu', 'carbon-repro-widget'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polyline points="18 15 12 9 6 15"></polyline>
                        </svg>
                    </button>
                </div>

                <div class="watch-menu-body">
                    <?php foreach ($menu_actions as $action_index => $action) : ?>
                        <div class="watch-menu-action watch-action-<?php echo esc_attr((string) ($action_index + 1)); ?>" data-watch-action="<?php echo esc_attr($action['key']); ?>" role="button" tabindex="0">
                            <div class="watch-action-icon">
                                <?php echo $this->get_menu_action_icon_svg($action['icon']); ?>
                            </div>
                            <div class="watch-action-text">
                                <div class="watch-action-title"><?php echo esc_html($action['label']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="watch-menu-footer">
                    <div class="watch-footer-content">
                        <?php if ($brand_logo_url !== '') : ?>
                            <img src="<?php echo esc_url($brand_logo_url); ?>" class="watch-footer-logo" alt="<?php echo esc_attr($footer_brand); ?>" style="height: 20px; object-fit: contain; opacity: 0.9;" />
                        <?php else : ?>
                            <svg class="watch-footer-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z"></path>
                            </svg>
                        <?php endif; ?>
                        <small><?php echo wp_kses_post(sprintf(__('Powered by <strong>%s</strong>', 'carbon-repro-widget'), esc_html($footer_brand))); ?></small>
                    </div>
                </div>
            </div>

            <?php if ($chat_enabled) : ?>
                <div class="watch-chat-popup" id="watchChatPopup" aria-hidden="true">
                    <div class="watch-chat-header">
                        <button type="button" class="watch-chat-back-btn" id="watchChatBackBtn" aria-label="<?php esc_attr_e('Back to menu', 'carbon-repro-widget'); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="15 18 9 12 15 6"></polyline>
                            </svg>
                        </button>
                        <?php if ($brand_logo_url !== '') : ?>
                            <img class="watch-brand-logo watch-brand-logo-chat" src="<?php echo esc_url($brand_logo_url); ?>" alt="<?php echo esc_attr($footer_brand); ?>" />
                        <?php endif; ?>
                        <span class="watch-chat-title"><?php echo esc_html($chatbot_title); ?></span>
                        <button type="button" class="watch-chat-close-btn" id="watchChatCloseBtn" aria-label="<?php esc_attr_e('Close', 'carbon-repro-widget'); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                    <div class="watch-chat-intake" id="watchChatIntake">
                        <div class="watch-chat-intake-card">
                            <h3><?php esc_html_e('Before we start', 'carbon-repro-widget'); ?></h3>
                            <div class="watch-chat-intake-grid">
                                <input type="text" id="watchChatLeadName" class="watch-chat-intake-input" placeholder="<?php esc_attr_e('Full name', 'carbon-repro-widget'); ?>" />
                                <input type="email" id="watchChatLeadEmail" class="watch-chat-intake-input" placeholder="<?php esc_attr_e('Email', 'carbon-repro-widget'); ?>" />
                                <input type="text" id="watchChatLeadPhone" class="watch-chat-intake-input" placeholder="<?php esc_attr_e('Phone', 'carbon-repro-widget'); ?>" />
                                <textarea id="watchChatLeadNeed" class="watch-chat-intake-input watch-chat-intake-textarea" rows="3" placeholder="<?php esc_attr_e('What are you looking for?', 'carbon-repro-widget'); ?>"></textarea>
                            </div>
                            <div class="watch-chat-intake-consents">
                                <label class="watch-chat-consent watch-chat-consent-intake">
                                    <input type="checkbox" id="watchChatConsent" />
                                    <span><?php echo esc_html($this->get_setting('customer_consent_label', $this->get_default_settings()['customer_consent_label'])); ?></span>
                                </label>
                                <label class="watch-chat-consent watch-chat-consent-intake">
                                    <input type="checkbox" id="watchChatSmsConsent" />
                                    <span><?php echo esc_html($this->get_setting('sms_consent_label', $this->get_default_settings()['sms_consent_label'])); ?></span>
                                </label>
                                <label class="watch-chat-consent watch-chat-consent-intake">
                                    <input type="checkbox" id="watchChatWhatsappConsent" />
                                    <span><?php echo esc_html($this->get_setting('whatsapp_consent_label', $this->get_default_settings()['whatsapp_consent_label'])); ?></span>
                                </label>
                            </div>
                            <button type="button" class="watch-chat-start-btn" id="watchChatStartBtn"><?php esc_html_e('Start Chat', 'carbon-repro-widget'); ?></button>
                        </div>
                    </div>
                    <div class="watch-chat-body" id="watchChatMessages"></div>
                    <div class="watch-chat-history" id="watchChatHistory" aria-hidden="true">
                        <div class="watch-chat-history-header">
                            <strong><?php esc_html_e('Previous Chats', 'carbon-repro-widget'); ?></strong>
                            <button type="button" class="watch-chat-history-close" id="watchChatHistoryClose"><?php esc_html_e('Close', 'carbon-repro-widget'); ?></button>
                        </div>
                        <div class="watch-chat-history-list" id="watchChatHistoryList"></div>
                    </div>
                    <div class="watch-chat-toolbar" id="watchChatToolbar">
                        <button type="button" class="watch-chat-tool-btn" id="watchChatPreviousBtn"><?php esc_html_e('Previous Chats', 'carbon-repro-widget'); ?></button>
                        <button type="button" class="watch-chat-tool-btn" id="watchChatResetBtn"><?php esc_html_e('Reset Chat', 'carbon-repro-widget'); ?></button>
                    </div>
                    <form class="watch-chat-form" id="watchChatForm">
                        <div class="watch-chat-composer">
                            <div class="watch-chat-attachments" id="watchChatAttachments" aria-live="polite"></div>
                            <div class="watch-chat-input-wrap">
                                <textarea id="watchChatInput" class="watch-chat-input" rows="1" placeholder="<?php esc_attr_e('Type your message...', 'carbon-repro-widget'); ?>"></textarea>
                            </div>
                            <div class="watch-chat-composer-actions">
                                <label class="watch-chat-upload-btn" for="watchChatUpload" aria-label="<?php esc_attr_e('Upload Photo', 'carbon-repro-widget'); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M21.44 11.05l-8.49 8.49a5.5 5.5 0 0 1-7.78-7.78l8.49-8.49a3.5 3.5 0 0 1 4.95 4.95l-8.5 8.49a1.5 1.5 0 0 1-2.12-2.12l7.43-7.42"></path>
                                    </svg>
                                </label>
                                <input type="file" id="watchChatUpload" class="watch-chat-upload-input" accept="image/png,image/jpeg,image/webp,image/gif" />
                                <div class="watch-chat-upload-status" id="watchChatUploadStatus" aria-live="polite"></div>
                                <button type="submit" class="watch-chat-send" id="watchChatSend" aria-label="<?php esc_attr_e('Send message', 'carbon-repro-widget'); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <line x1="22" y1="2" x2="11" y2="13"></line>
                                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <div class="watch-form-popup" id="watchFormPopup" aria-hidden="true">
                <div class="watch-form-header">
                    <button type="button" class="watch-form-back-btn" id="watchFormBackBtn" aria-label="<?php esc_attr_e('Back to menu', 'carbon-repro-widget'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polyline points="15 18 9 12 15 6"></polyline>
                        </svg>
                    </button>
                    <?php if ($brand_logo_url !== '') : ?>
                        <img class="watch-brand-logo watch-brand-logo-form" src="<?php echo esc_url($brand_logo_url); ?>" alt="<?php echo esc_attr($footer_brand); ?>" />
                    <?php endif; ?>
                    <span class="watch-form-title"><?php echo esc_html($form_title); ?></span>
                    <button type="button" class="watch-form-close-btn" id="watchFormCloseBtn" aria-label="<?php esc_attr_e('Close', 'carbon-repro-widget'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>
                <div class="watch-form-body">
                    <?php $this->render_form_content($form_id); ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_dashboard_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $filters = $this->get_dashboard_filters();
        $data = $this->get_dashboard_data($filters);
        ?>
        <div class="wrap criaw-admin">
            <style>
                .criaw-admin { --criaw-green:#1abc9c; --criaw-green-dark:#12806b; --criaw-ink:#1f2937; --criaw-muted:#667085; --criaw-bg:#f4fbf9; }
                .criaw-admin .criaw-shell { background:linear-gradient(180deg,#f7fdfb 0%,#eef8f5 100%); border:1px solid #d9ece6; border-radius:24px; padding:28px; }
                .criaw-admin .criaw-hero { display:flex; justify-content:space-between; gap:24px; align-items:flex-start; margin-bottom:24px; }
                .criaw-admin .criaw-title { margin:0; font-size:30px; color:var(--criaw-ink); }
                .criaw-admin .criaw-subtitle { margin:8px 0 0; color:var(--criaw-muted); max-width:780px; }
                .criaw-admin .criaw-filters { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin:20px 0 26px; }
                .criaw-admin .criaw-filter { background:#fff; border:1px solid #dbe7e3; border-radius:16px; padding:12px; }
                .criaw-admin .criaw-filter label { display:block; font-size:12px; color:var(--criaw-muted); margin-bottom:6px; font-weight:600; }
                .criaw-admin .criaw-filter input, .criaw-admin .criaw-filter select { width:100%; border:1px solid #cfe0da; border-radius:10px; padding:8px 10px; }
                .criaw-admin .criaw-actions { margin-top:8px; display:flex; gap:10px; }
                .criaw-admin .criaw-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:24px; }
                .criaw-admin .criaw-card { background:#fff; border:1px solid #dbe7e3; border-radius:20px; padding:18px; box-shadow:0 10px 24px rgba(16,24,40,.04); }
                .criaw-admin .criaw-card-label { color:var(--criaw-muted); font-size:13px; margin-bottom:8px; font-weight:600; }
                .criaw-admin .criaw-card-value { color:var(--criaw-ink); font-size:34px; font-weight:800; line-height:1; }
                .criaw-admin .criaw-card-note { margin-top:8px; color:var(--criaw-green-dark); font-size:12px; font-weight:700; }
                .criaw-admin .criaw-panels { display:grid; grid-template-columns:2fr 1fr; gap:18px; margin-bottom:24px; }
                .criaw-admin .criaw-panel { background:#fff; border:1px solid #dbe7e3; border-radius:20px; padding:20px; }
                .criaw-admin .criaw-panel h2 { margin:0 0 16px; font-size:18px; }
                .criaw-admin .criaw-trend { display:flex; align-items:flex-end; gap:8px; height:240px; }
                .criaw-admin .criaw-bar-wrap { flex:1; display:flex; flex-direction:column; justify-content:flex-end; align-items:center; min-width:0; }
                .criaw-admin .criaw-bar-stack { width:100%; max-width:42px; display:flex; flex-direction:column; justify-content:flex-end; gap:4px; height:200px; }
                .criaw-admin .criaw-bar { border-radius:10px 10px 4px 4px; width:100%; }
                .criaw-admin .criaw-bar-opens { background:linear-gradient(180deg,#7be7d3,#1abc9c); }
                .criaw-admin .criaw-bar-leads { background:linear-gradient(180deg,#9ad7ff,#2f80ed); }
                .criaw-admin .criaw-bar-label { margin-top:10px; font-size:11px; color:var(--criaw-muted); white-space:nowrap; }
                .criaw-admin .criaw-legend { display:flex; gap:16px; color:var(--criaw-muted); font-size:12px; margin-bottom:12px; }
                .criaw-admin .criaw-legend span::before { content:''; display:inline-block; width:10px; height:10px; border-radius:999px; margin-right:8px; }
                .criaw-admin .criaw-legend .opens::before { background:#1abc9c; }
                .criaw-admin .criaw-legend .leads::before { background:#2f80ed; }
                .criaw-admin .criaw-breakdown-item { margin-bottom:14px; }
                .criaw-admin .criaw-breakdown-top { display:flex; justify-content:space-between; gap:10px; margin-bottom:6px; }
                .criaw-admin .criaw-progress { background:#edf3f1; border-radius:999px; height:10px; overflow:hidden; }
                .criaw-admin .criaw-progress > span { display:block; height:100%; background:linear-gradient(90deg,#1abc9c,#2f80ed); border-radius:999px; }
                .criaw-admin .criaw-source-chart { display:flex; flex-direction:column; gap:12px; }
                .criaw-admin .criaw-source-row { display:grid; grid-template-columns:120px 1fr 50px; gap:12px; align-items:center; }
                .criaw-admin .criaw-tables { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:18px; }
                .criaw-admin table.widefat { border-radius:16px; overflow:hidden; border:1px solid #dbe7e3; }
                .criaw-admin .criaw-pill { display:inline-flex; align-items:center; padding:4px 10px; border-radius:999px; background:#ebf8f5; color:var(--criaw-green-dark); font-size:12px; font-weight:700; }
                @media (max-width: 1100px) { .criaw-admin .criaw-panels { grid-template-columns:1fr; } }
            </style>

            <div class="criaw-shell">
                <div class="criaw-hero">
                    <div>
                        <h1 class="criaw-title"><?php esc_html_e('Performance Dashboard', 'carbon-repro-widget'); ?></h1>
                        <p class="criaw-subtitle"><?php esc_html_e('Track opens, clicks, form conversions, chat leads, source quality, device mix, country, and page-level performance across any client business using the same plugin.', 'carbon-repro-widget'); ?></p>
                    </div>
                    <div class="criaw-pill"><?php echo esc_html($this->get_setting('business_name', __('Business not set', 'carbon-repro-widget'))); ?></div>
                </div>

                <form method="get">
                    <input type="hidden" name="page" value="carbon-repro-widget" />
                    <div class="criaw-filters">
                        <?php $this->render_filter_control('date_from', __('From', 'carbon-repro-widget'), $filters['date_from']); ?>
                        <?php $this->render_filter_control('date_to', __('To', 'carbon-repro-widget'), $filters['date_to']); ?>
                        <?php $this->render_filter_control('source_group', __('Source', 'carbon-repro-widget'), $filters['source_group'], $data['filter_options']['source_group']); ?>
                        <?php $this->render_filter_control('device_type', __('Device', 'carbon-repro-widget'), $filters['device_type'], $data['filter_options']['device_type']); ?>
                        <?php $this->render_filter_control('country_name', __('Country', 'carbon-repro-widget'), $filters['country_name'], $data['filter_options']['country_name']); ?>
                        <?php $this->render_filter_control('page_path', __('Page Path', 'carbon-repro-widget'), $filters['page_path']); ?>
                    </div>
                    <div class="criaw-actions">
                        <button class="button button-primary"><?php esc_html_e('Apply Filters', 'carbon-repro-widget'); ?></button>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=carbon-repro-widget')); ?>"><?php esc_html_e('Reset', 'carbon-repro-widget'); ?></a>
                    </div>
                </form>

                <div class="criaw-grid">
                    <?php $this->render_stat_card(__('Widget Opens', 'carbon-repro-widget'), $data['cards']['widget_open']); ?>
                    <?php $this->render_stat_card(__('Call Clicks', 'carbon-repro-widget'), $data['cards']['call_click']); ?>
                    <?php $this->render_stat_card(__('SMS Clicks', 'carbon-repro-widget'), $data['cards']['text_click']); ?>
                    <?php $this->render_stat_card(__('Form Leads', 'carbon-repro-widget'), $data['cards']['form_submit']); ?>
                    <?php $this->render_stat_card(__('Chat Leads', 'carbon-repro-widget'), $data['cards']['chat_start']); ?>
                    <?php $this->render_stat_card(__('Lead Conversion Rate', 'carbon-repro-widget'), $data['cards']['conversion_rate'], true); ?>
                </div>

                <div class="criaw-panels">
                    <div class="criaw-panel">
                        <h2><?php esc_html_e('Daily Opens vs Leads', 'carbon-repro-widget'); ?></h2>
                        <div class="criaw-legend">
                            <span class="opens"><?php esc_html_e('Opens', 'carbon-repro-widget'); ?></span>
                            <span class="leads"><?php esc_html_e('Leads', 'carbon-repro-widget'); ?></span>
                        </div>
                        <div class="criaw-trend">
                            <?php foreach ($data['trend'] as $point) : ?>
                                <?php
                                $open_height = $data['trend_max'] > 0 ? max(8, (int) round(($point['opens'] / $data['trend_max']) * 160)) : 8;
                                $lead_height = $data['trend_max'] > 0 ? max(8, (int) round(($point['leads'] / $data['trend_max']) * 160)) : 8;
                                ?>
                                <div class="criaw-bar-wrap" title="<?php echo esc_attr($point['label'] . ' - Opens: ' . $point['opens'] . ', Leads: ' . $point['leads']); ?>">
                                    <div class="criaw-bar-stack">
                                        <span class="criaw-bar criaw-bar-opens" style="height:<?php echo esc_attr((string) $open_height); ?>px;"></span>
                                        <span class="criaw-bar criaw-bar-leads" style="height:<?php echo esc_attr((string) $lead_height); ?>px;"></span>
                                    </div>
                                    <div class="criaw-bar-label"><?php echo esc_html($point['short']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="criaw-panel">
                        <h2><?php esc_html_e('CTA Breakdown', 'carbon-repro-widget'); ?></h2>
                        <?php foreach ($data['cta_breakdown'] as $label => $count) : ?>
                            <?php $percent = $data['cta_total'] > 0 ? round(($count / $data['cta_total']) * 100, 1) : 0; ?>
                            <div class="criaw-breakdown-item">
                                <div class="criaw-breakdown-top">
                                    <strong><?php echo esc_html($label); ?></strong>
                                    <span><?php echo esc_html((string) $count . ' (' . $percent . '%)'); ?></span>
                                </div>
                                <div class="criaw-progress"><span style="width:<?php echo esc_attr((string) $percent); ?>%;"></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="criaw-panel" style="margin-bottom:24px;">
                    <h2><?php esc_html_e('Campaign / Source Chart', 'carbon-repro-widget'); ?></h2>
                    <div class="criaw-source-chart">
                        <?php
                        $source_max = 0;
                        foreach ($data['source_breakdown'] as $row) {
                            $source_max = max($source_max, (int) $row['total']);
                        }
                        ?>
                        <?php if (empty($data['source_breakdown'])) : ?>
                            <p><?php esc_html_e('No source data for current filters.', 'carbon-repro-widget'); ?></p>
                        <?php else : ?>
                            <?php foreach ($data['source_breakdown'] as $row) : ?>
                                <?php $percent = $source_max > 0 ? round(((int) $row['total'] / $source_max) * 100, 1) : 0; ?>
                                <div class="criaw-source-row">
                                    <strong><?php echo esc_html($row['label']); ?></strong>
                                    <div class="criaw-progress"><span style="width:<?php echo esc_attr((string) $percent); ?>%;"></span></div>
                                    <span><?php echo esc_html((string) $row['total']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="criaw-tables">
                    <?php $this->render_dashboard_table(__('Top Form Submission Pages', 'carbon-repro-widget'), $data['form_pages'], array(__('Page', 'carbon-repro-widget'), __('Form Leads', 'carbon-repro-widget'))); ?>
                    <?php $this->render_dashboard_table(__('Top Pages', 'carbon-repro-widget'), $data['top_pages'], array(__('Page', 'carbon-repro-widget'), __('Events', 'carbon-repro-widget'))); ?>
                    <?php $this->render_dashboard_table(__('Traffic Sources', 'carbon-repro-widget'), $data['source_breakdown'], array(__('Source', 'carbon-repro-widget'), __('Events', 'carbon-repro-widget'))); ?>
                    <?php $this->render_dashboard_table(__('Countries', 'carbon-repro-widget'), $data['country_breakdown'], array(__('Country', 'carbon-repro-widget'), __('Events', 'carbon-repro-widget'))); ?>
                    <?php $this->render_dashboard_table(__('Devices', 'carbon-repro-widget'), $data['device_breakdown'], array(__('Device', 'carbon-repro-widget'), __('Events', 'carbon-repro-widget'))); ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_settings_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            add_settings_error('criaw_settings_messages', 'criaw-settings-saved', __('Settings updated successfully.', 'carbon-repro-widget'), 'updated');
        }
        if (isset($_GET['criaw_sms_test'])) {
            $status = sanitize_key(wp_unslash($_GET['criaw_sms_test']));
            $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
            add_settings_error(
                'criaw_settings_messages',
                'criaw-sms-test',
                $message !== '' ? $message : ($status === 'success' ? __('Twilio test SMS sent successfully.', 'carbon-repro-widget') : __('Twilio test SMS failed.', 'carbon-repro-widget')),
                $status === 'success' ? 'updated' : 'error'
            );
        }
        $crawl_pages = get_option(self::CRAWL_PAGES_OPTION_KEY, array());
        $crawl_pages = is_array($crawl_pages) ? $crawl_pages : array();
        $catalog_index = get_option(self::CATALOG_INDEX_OPTION_KEY, array());
        $catalog_index = is_array($catalog_index) ? $catalog_index : array();
        $saved_product_count = isset($catalog_index['stored_product_count']) ? (int) $catalog_index['stored_product_count'] : 0;
        $saved_category_count = isset($catalog_index['stored_category_count']) ? (int) $catalog_index['stored_category_count'] : 0;
        $catalog_total_products = $this->is_woocommerce_active() ? (int) wp_count_posts('product')->publish : 0;
        $catalog_total_categories = $this->is_woocommerce_active() ? (int) wp_count_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false)) : 0;
        wp_enqueue_media();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Widget Settings', 'carbon-repro-widget'); ?></h1>
            <?php settings_errors(); ?>
            <div class="criaw-settings-shell">
                <div class="criaw-settings-tabs">
                    <button type="button" class="button button-primary" data-criaw-panel="settings"><?php esc_html_e('Settings', 'carbon-repro-widget'); ?></button>
                    <button type="button" class="button" data-criaw-panel="crawlers"><?php esc_html_e('Crawlers & Indexers', 'carbon-repro-widget'); ?></button>
                </div>
                <div class="criaw-settings-panel active" data-criaw-panel-id="settings">
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('criaw_settings_group');
                        echo '<div style="display:flex;justify-content:flex-end;margin:0 0 18px;">';
                        submit_button(__('Save Settings', 'carbon-repro-widget'), 'primary', 'submit', false);
                        echo '</div>';
                        do_settings_sections('carbon-repro-widget-settings');
                        echo '<div style="display:flex;justify-content:flex-end;margin-top:24px;">';
                        submit_button(__('Save Settings', 'carbon-repro-widget'), 'primary', 'submit', false);
                        echo '</div>';
                        ?>
                    </form>
                    <div style="margin-top:18px;background:#fff;border:1px solid #dbe7e3;border-radius:18px;padding:18px;">
                        <h2 style="margin-top:0;"><?php esc_html_e('Twilio Test', 'carbon-repro-widget'); ?></h2>
                        <p><?php esc_html_e('Send a test SMS to the admin notification phone so you can verify credentials, sender number, and US-format delivery.', 'carbon-repro-widget'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('criaw_test_twilio'); ?>
                            <input type="hidden" name="action" value="criaw_test_twilio" />
                            <p><button class="button button-secondary" type="submit"><?php esc_html_e('Send Test SMS', 'carbon-repro-widget'); ?></button></p>
                        </form>
                    </div>
                </div>
                <div class="criaw-settings-panel" data-criaw-panel-id="crawlers">
                    <h2><?php esc_html_e('Website Crawl', 'carbon-repro-widget'); ?></h2>
                    <p><?php esc_html_e('Run a crawl to summarize important website pages for the chatbot. This works best when the business profile and focus paths are already filled in.', 'carbon-repro-widget'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('criaw_run_site_crawl'); ?>
                        <input type="hidden" name="action" value="criaw_run_site_crawl" />
                        <p><button class="button button-secondary" type="submit"><?php esc_html_e('Run Crawl Now', 'carbon-repro-widget'); ?></button></p>
                    </form>
                    <?php if ($this->is_woocommerce_active()) : ?>
                        <h2><?php esc_html_e('Catalog Index', 'carbon-repro-widget'); ?></h2>
                        <p><?php esc_html_e('Run the catalog indexer to store product and category details from WooCommerce for accurate SKU, model, product, and category replies.', 'carbon-repro-widget'); ?></p>
                        <div style="max-width:720px;background:#fff;border:1px solid #dbe7e3;border-radius:18px;padding:16px;">
                    <p><button class="button button-secondary" type="button" id="criawRunCatalogIndexer"><?php esc_html_e('Run Catalog Indexer', 'carbon-repro-widget'); ?></button></p>
                    <div style="height:12px;background:#edf4f1;border-radius:999px;overflow:hidden;margin:12px 0;">
                        <span id="criawCatalogIndexerBar" style="display:block;height:100%;width:0;background:linear-gradient(90deg,#1abc9c,#2f80ed);transition:width .25s ease;"></span>
                    </div>
                    <div id="criawCatalogIndexerStatus" style="color:#475467;font-size:13px;"><?php esc_html_e('Ready to index products and categories.', 'carbon-repro-widget'); ?></div>
                    <div style="margin-top:8px;color:#667085;font-size:12px;">
                        <?php
                        printf(
                            esc_html__('Catalog contains %1$s products and %2$s categories. Saved index currently includes %3$s products and %4$s categories.', 'carbon-repro-widget'),
                            number_format_i18n($catalog_total_products),
                            number_format_i18n($catalog_total_categories),
                            number_format_i18n($saved_product_count),
                            number_format_i18n($saved_category_count)
                        );
                        ?>
                    </div>
                </div>
                    <?php endif; ?>
                    <h2><?php esc_html_e('Knowledge Review', 'carbon-repro-widget'); ?></h2>
                    <p><?php esc_html_e('Review the latest crawled pages below, then edit the stored website knowledge field above so the bot uses the right language and avoids wrong assumptions.', 'carbon-repro-widget'); ?></p>
                    <?php if (empty($crawl_pages)) : ?>
                        <p><?php esc_html_e('No crawl pages available yet. Run the crawler after filling in the business profile.', 'carbon-repro-widget'); ?></p>
                    <?php else : ?>
                        <div style="display:grid; gap:16px;">
                            <?php foreach ($crawl_pages as $page) : ?>
                                <div style="background:#fff;border:1px solid #dbe7e3;border-radius:18px;padding:16px;">
                                    <div style="font-weight:700;margin-bottom:8px;"><?php echo esc_html(isset($page['title']) && $page['title'] !== '' ? $page['title'] : (isset($page['url']) ? $page['url'] : '')); ?></div>
                                    <?php if (! empty($page['url'])) : ?>
                                        <div style="margin-bottom:8px;"><a href="<?php echo esc_url($page['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($page['url']); ?></a></div>
                                    <?php endif; ?>
                                    <div style="color:#475467;line-height:1.6;"><?php echo nl2br(esc_html(isset($page['summary']) ? $page['summary'] : '')); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <style>
                .criaw-settings-shell { margin-top: 16px; }
                .criaw-settings-tabs { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
                .criaw-settings-panel { display:none; }
                .criaw-settings-panel.active { display:block; }
            </style>
            <script>
            (function () {
              document.querySelectorAll('[data-criaw-panel]').forEach(function (button) {
                button.addEventListener('click', function () {
                  var target = button.getAttribute('data-criaw-panel');
                  document.querySelectorAll('[data-criaw-panel]').forEach(function (item) {
                    item.classList.toggle('button-primary', item === button);
                  });
                  document.querySelectorAll('[data-criaw-panel-id]').forEach(function (panel) {
                    panel.classList.toggle('active', panel.getAttribute('data-criaw-panel-id') === target);
                  });
                });
              });

              var form = document.querySelector('.wrap form[action="options.php"]');
              if (form) {
                var headings = Array.prototype.slice.call(form.querySelectorAll('h2'));
                if (headings.length) {
                  var nav = document.createElement('div');
                  nav.style.display = 'flex';
                  nav.style.gap = '10px';
                  nav.style.flexWrap = 'wrap';
                  nav.style.margin = '10px 0 18px';
                  headings.forEach(function (heading, index) {
                    var title = heading.textContent || ('Tab ' + (index + 1));
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = title;
                    button.className = 'button';
                    button.style.borderRadius = '999px';
                    button.dataset.tabIndex = String(index);
                    nav.appendChild(button);

                    var panel = document.createElement('div');
                    panel.className = 'criaw-settings-tab';
                    panel.dataset.tabIndex = String(index);
                    heading.parentNode.insertBefore(panel, heading);
                    panel.appendChild(heading);
                    var sibling = panel.nextSibling;
                    while (sibling && !(sibling.tagName && sibling.tagName.toLowerCase() === 'h2')) {
                      var next = sibling.nextSibling;
                      panel.appendChild(sibling);
                      sibling = next;
                    }
                  });
                  form.insertBefore(nav, form.firstChild.nextSibling);
                  function activateTab(index) {
                    form.querySelectorAll('.criaw-settings-tab').forEach(function (panel) {
                      panel.style.display = panel.dataset.tabIndex === String(index) ? 'block' : 'none';
                    });
                    nav.querySelectorAll('button').forEach(function (button) {
                      button.classList.toggle('button-primary', button.dataset.tabIndex === String(index));
                    });
                  }
                  nav.addEventListener('click', function (event) {
                    if (event.target.tagName === 'BUTTON') {
                      activateTab(event.target.dataset.tabIndex);
                    }
                  });
                  activateTab(0);
                }
              }

              document.querySelectorAll('.criaw-upload-logo').forEach(function (button) {
                button.addEventListener('click', function () {
                  var targetId = button.getAttribute('data-target');
                  var target = targetId ? document.getElementById(targetId) : null;
                  if (!target) {
                    target = button.parentNode ? button.parentNode.querySelector('input[type="text"]') : null;
                  }
                  if (!target || !window.wp || !window.wp.media) { return; }
                  var frame = window.wp.media({ title: 'Select Logo', multiple: false, library: { type: 'image' } });
                  frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    target.value = attachment.url || '';
                  });
                  frame.open();
                });
              });

              var runIndexer = document.getElementById('criawRunCatalogIndexer');
              if (runIndexer) {
                runIndexer.addEventListener('click', function () {
                  var offset = 0;
                  var limit = 20;
                  var products = [];
                  var categories = [];
                  var status = document.getElementById('criawCatalogIndexerStatus');
                  var bar = document.getElementById('criawCatalogIndexerBar');

                  function updateProgress(done, total) {
                    var percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
                    bar.style.width = percent + '%';
                    status.textContent = 'Indexed ' + done + ' of ' + total + ' products...';
                  }

                  function runBatch() {
                    var formData = new window.FormData();
                    formData.append('action', 'criaw_catalog_index_batch');
                    formData.append('_ajax_nonce', '<?php echo esc_js(wp_create_nonce('criaw_catalog_index_batch')); ?>');
                    formData.append('offset', offset);
                    formData.append('limit', limit);
                    window.fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: formData })
                      .then(function (response) { return response.json(); })
                      .then(function (data) {
                        if (!data || !data.success || !data.data) {
                          status.textContent = 'Catalog indexing failed.';
                          return;
                        }
                        products = products.concat(data.data.products || []);
                        if (offset === 0) {
                          categories = data.data.categories || [];
                        }
                        offset = data.data.next_offset || offset + limit;
                        updateProgress(data.data.indexed_products || Math.min(offset, data.data.total || products.length), data.data.total || products.length);
                        if (data.data.complete) {
                          status.textContent = 'Catalog index complete and saved. Indexed ' + (data.data.indexed_products || products.length) + ' products and ' + (data.data.indexed_categories || categories.length) + ' categories.';
                          var textarea = document.getElementById('catalog_index_knowledge');
                          if (textarea) {
                            textarea.value = data.data.summary || '';
                          }
                          var enabled = document.getElementById('catalog_index_enabled');
                          if (enabled) { enabled.checked = true; }
                          return;
                        }
                        runBatch();
                      })
                      .catch(function () {
                        status.textContent = 'Catalog indexing failed.';
                      });
                  }

                  bar.style.width = '0%';
                  status.textContent = 'Starting catalog indexing...';
                  runBatch();
                });
              }
            }());
            </script>
        </div>
        <?php
    }

    public function render_form_submissions_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $table = $this->get_events_table();
        $filters = array(
            'source' => isset($_GET['source']) ? sanitize_text_field(wp_unslash($_GET['source'])) : '',
            'device' => isset($_GET['device']) ? sanitize_text_field(wp_unslash($_GET['device'])) : '',
            'country' => isset($_GET['country']) ? sanitize_text_field(wp_unslash($_GET['country'])) : '',
            'page' => isset($_GET['page_path_filter']) ? sanitize_text_field(wp_unslash($_GET['page_path_filter'])) : '',
        );

        $where = array("event_type = 'form_submit'");
        $params = array();

        if ($filters['source'] !== '') {
            $where[] = 'source_group = %s';
            $params[] = $filters['source'];
        }

        if ($filters['device'] !== '') {
            $where[] = 'device_type = %s';
            $params[] = $filters['device'];
        }

        if ($filters['country'] !== '') {
            $where[] = 'country_name = %s';
            $params[] = $filters['country'];
        }

        if ($filters['page'] !== '') {
            $where[] = 'page_path LIKE %s';
            $params[] = '%' . $wpdb->esc_like($filters['page']) . '%';
        }

        $where_sql = implode(' AND ', $where);
        $rows_query = "SELECT created_at, page_path, page_url, source_group, device_type, country_name, lead_name, lead_email, lead_phone
            FROM {$table}
            WHERE {$where_sql}
            ORDER BY created_at DESC
            LIMIT 200";
        $count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $top_pages_query = "SELECT page_path, COUNT(*) AS total
            FROM {$table}
            WHERE {$where_sql}
            GROUP BY page_path
            ORDER BY total DESC, page_path ASC
            LIMIT 8";
        $top_sources_query = "SELECT source_group, COUNT(*) AS total
            FROM {$table}
            WHERE {$where_sql}
            GROUP BY source_group
            ORDER BY total DESC, source_group ASC
            LIMIT 8";

        if (! empty($params)) {
            $rows = $wpdb->get_results($wpdb->prepare($rows_query, $params), ARRAY_A);
            $total_submissions = (int) $wpdb->get_var($wpdb->prepare($count_query, $params));
            $top_pages = $wpdb->get_results($wpdb->prepare($top_pages_query, $params), ARRAY_A);
            $top_sources = $wpdb->get_results($wpdb->prepare($top_sources_query, $params), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($rows_query, ARRAY_A);
            $total_submissions = (int) $wpdb->get_var($count_query);
            $top_pages = $wpdb->get_results($top_pages_query, ARRAY_A);
            $top_sources = $wpdb->get_results($top_sources_query, ARRAY_A);
        }

        $unique_pages = (int) $wpdb->get_var("SELECT COUNT(DISTINCT page_path) FROM {$table} WHERE event_type = 'form_submit'");
        $unique_sources = (int) $wpdb->get_var("SELECT COUNT(DISTINCT source_group) FROM {$table} WHERE event_type = 'form_submit'");
        $filter_base_url = admin_url('admin.php?page=carbon-repro-widget-forms');
        $source_options = $wpdb->get_col("SELECT DISTINCT source_group FROM {$table} WHERE event_type = 'form_submit' AND source_group <> '' ORDER BY source_group ASC");
        $device_options = $wpdb->get_col("SELECT DISTINCT device_type FROM {$table} WHERE event_type = 'form_submit' AND device_type <> '' ORDER BY device_type ASC");
        $country_options = $wpdb->get_col("SELECT DISTINCT country_name FROM {$table} WHERE event_type = 'form_submit' AND country_name <> '' ORDER BY country_name ASC");

        ?>
        <div class="wrap criaw-admin">
            <style>
                .criaw-admin .criaw-shell { background:linear-gradient(180deg,#f7fdfb 0%,#eef8f5 100%); border:1px solid #d9ece6; border-radius:24px; padding:28px; }
                .criaw-admin .criaw-title { margin:0; font-size:30px; color:#1f2937; }
                .criaw-admin .criaw-subtitle { margin:8px 0 0; color:#667085; max-width:780px; }
                .criaw-admin .criaw-filters { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin:20px 0 24px; }
                .criaw-admin .criaw-filter { background:#fff; border:1px solid #dbe7e3; border-radius:16px; padding:12px; }
                .criaw-admin .criaw-filter label { display:block; font-size:12px; color:#667085; margin-bottom:6px; font-weight:600; }
                .criaw-admin .criaw-filter input, .criaw-admin .criaw-filter select { width:100%; border:1px solid #cfe0da; border-radius:10px; padding:8px 10px; }
                .criaw-admin .criaw-actions { display:flex; gap:10px; margin-top:8px; }
                .criaw-admin .criaw-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:24px; }
                .criaw-admin .criaw-card { background:#fff; border:1px solid #dbe7e3; border-radius:20px; padding:18px; box-shadow:0 10px 24px rgba(16,24,40,.04); }
                .criaw-admin .criaw-card-label { color:#667085; font-size:13px; margin-bottom:8px; font-weight:600; }
                .criaw-admin .criaw-card-value { color:#1f2937; font-size:34px; font-weight:800; line-height:1; }
                .criaw-admin .criaw-columns { display:grid; grid-template-columns:1.5fr 1fr 1fr; gap:18px; margin-bottom:24px; }
                .criaw-admin .criaw-panel { background:#fff; border:1px solid #dbe7e3; border-radius:20px; padding:20px; }
                .criaw-admin .criaw-panel h2 { margin:0 0 14px; font-size:18px; color:#1f2937; }
                .criaw-admin .criaw-mini-table { width:100%; border-collapse:collapse; }
                .criaw-admin .criaw-mini-table th, .criaw-admin .criaw-mini-table td { text-align:left; padding:10px 0; border-bottom:1px solid #edf3f0; }
                .criaw-admin .criaw-table { background:#fff; border:1px solid #dbe7e3; border-radius:20px; padding:18px; }
                .criaw-admin .criaw-table table { margin-top:0; }
            </style>
            <div class="criaw-shell">
            <h1 class="criaw-title"><?php esc_html_e('Widget Form Submissions', 'carbon-repro-widget'); ?></h1>
            <p class="criaw-subtitle"><?php esc_html_e('Review every WPForms submission that came through the widget, including the page, traffic source, device, and country behind it.', 'carbon-repro-widget'); ?></p>
            <form method="get">
                <input type="hidden" name="page" value="carbon-repro-widget-forms" />
                <div class="criaw-filters">
                    <div class="criaw-filter">
                        <label for="criaw-form-source"><?php esc_html_e('Source', 'carbon-repro-widget'); ?></label>
                        <select id="criaw-form-source" name="source">
                            <option value=""><?php esc_html_e('All sources', 'carbon-repro-widget'); ?></option>
                            <?php foreach ($source_options as $option) : ?>
                                <option value="<?php echo esc_attr($option); ?>" <?php selected($filters['source'], $option); ?>><?php echo esc_html($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="criaw-filter">
                        <label for="criaw-form-device"><?php esc_html_e('Device', 'carbon-repro-widget'); ?></label>
                        <select id="criaw-form-device" name="device">
                            <option value=""><?php esc_html_e('All devices', 'carbon-repro-widget'); ?></option>
                            <?php foreach ($device_options as $option) : ?>
                                <option value="<?php echo esc_attr($option); ?>" <?php selected($filters['device'], $option); ?>><?php echo esc_html($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="criaw-filter">
                        <label for="criaw-form-country"><?php esc_html_e('Country', 'carbon-repro-widget'); ?></label>
                        <select id="criaw-form-country" name="country">
                            <option value=""><?php esc_html_e('All countries', 'carbon-repro-widget'); ?></option>
                            <?php foreach ($country_options as $option) : ?>
                                <option value="<?php echo esc_attr($option); ?>" <?php selected($filters['country'], $option); ?>><?php echo esc_html($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="criaw-filter">
                        <label for="criaw-form-page"><?php esc_html_e('Page path contains', 'carbon-repro-widget'); ?></label>
                        <input id="criaw-form-page" type="text" name="page_path_filter" value="<?php echo esc_attr($filters['page']); ?>" placeholder="/services/security-doors" />
                    </div>
                </div>
                <div class="criaw-actions">
                    <button class="button button-primary" type="submit"><?php esc_html_e('Apply Filters', 'carbon-repro-widget'); ?></button>
                    <a class="button button-secondary" href="<?php echo esc_url($filter_base_url); ?>"><?php esc_html_e('Reset', 'carbon-repro-widget'); ?></a>
                </div>
            </form>
            <div class="criaw-grid">
                <div class="criaw-card">
                    <div class="criaw-card-label"><?php esc_html_e('Filtered Submissions', 'carbon-repro-widget'); ?></div>
                    <div class="criaw-card-value"><?php echo esc_html(number_format_i18n($total_submissions)); ?></div>
                </div>
                <div class="criaw-card">
                    <div class="criaw-card-label"><?php esc_html_e('Tracked Pages', 'carbon-repro-widget'); ?></div>
                    <div class="criaw-card-value"><?php echo esc_html(number_format_i18n($unique_pages)); ?></div>
                </div>
                <div class="criaw-card">
                    <div class="criaw-card-label"><?php esc_html_e('Traffic Sources', 'carbon-repro-widget'); ?></div>
                    <div class="criaw-card-value"><?php echo esc_html(number_format_i18n($unique_sources)); ?></div>
                </div>
            </div>
            <div class="criaw-columns">
                <div class="criaw-panel">
                    <h2><?php esc_html_e('Latest Widget Leads', 'carbon-repro-widget'); ?></h2>
                    <table class="criaw-mini-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Date', 'carbon-repro-widget'); ?></th>
                                <th><?php esc_html_e('Lead', 'carbon-repro-widget'); ?></th>
                                <th><?php esc_html_e('Page', 'carbon-repro-widget'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)) : ?>
                                <tr><td colspan="3"><?php esc_html_e('No widget form submissions recorded yet.', 'carbon-repro-widget'); ?></td></tr>
                            <?php else : ?>
                                <?php foreach (array_slice($rows, 0, 6) as $row) : ?>
                                    <tr>
                                        <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $row['created_at'])); ?></td>
                                        <td><?php echo esc_html(trim($row['lead_name'] . ' ' . $row['lead_phone'])); ?></td>
                                        <td><?php echo esc_html($row['page_path']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="criaw-panel">
                    <h2><?php esc_html_e('Top Pages', 'carbon-repro-widget'); ?></h2>
                    <table class="criaw-mini-table">
                        <tbody>
                            <?php if (empty($top_pages)) : ?>
                                <tr><td><?php esc_html_e('No page data yet.', 'carbon-repro-widget'); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ($top_pages as $row) : ?>
                                    <tr>
                                        <td><?php echo esc_html($row['page_path'] ? $row['page_path'] : '/'); ?></td>
                                        <td><?php echo esc_html(number_format_i18n((int) $row['total'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="criaw-panel">
                    <h2><?php esc_html_e('Top Sources', 'carbon-repro-widget'); ?></h2>
                    <table class="criaw-mini-table">
                        <tbody>
                            <?php if (empty($top_sources)) : ?>
                                <tr><td><?php esc_html_e('No source data yet.', 'carbon-repro-widget'); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ($top_sources as $row) : ?>
                                    <tr>
                                        <td><?php echo esc_html($row['source_group'] ? $row['source_group'] : __('Unknown', 'carbon-repro-widget')); ?></td>
                                        <td><?php echo esc_html(number_format_i18n((int) $row['total'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="criaw-table">
            <h2><?php esc_html_e('Widget Form Submissions', 'carbon-repro-widget'); ?></h2>
            <p><?php esc_html_e('Detailed submission log from the widget popup.', 'carbon-repro-widget'); ?></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'carbon-repro-widget'); ?></th>
                        <th><?php esc_html_e('Page', 'carbon-repro-widget'); ?></th>
                        <th><?php esc_html_e('Source', 'carbon-repro-widget'); ?></th>
                        <th><?php esc_html_e('Device', 'carbon-repro-widget'); ?></th>
                        <th><?php esc_html_e('Country', 'carbon-repro-widget'); ?></th>
                        <th><?php esc_html_e('Lead', 'carbon-repro-widget'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr><td colspan="6"><?php esc_html_e('No widget form submissions recorded yet.', 'carbon-repro-widget'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($rows as $row) : ?>
                            <tr>
                                <td><?php echo esc_html($row['created_at']); ?></td>
                                <td>
                                    <div><?php echo esc_html($row['page_path']); ?></div>
                                    <?php if (! empty($row['page_url'])) : ?>
                                        <a href="<?php echo esc_url($row['page_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open Page', 'carbon-repro-widget'); ?></a>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($row['source_group']); ?></td>
                                <td><?php echo esc_html($row['device_type']); ?></td>
                                <td><?php echo esc_html($row['country_name']); ?></td>
                                <td>
                                    <?php echo esc_html($row['lead_name']); ?><br />
                                    <?php echo esc_html($row['lead_email']); ?><br />
                                    <?php echo esc_html($row['lead_phone']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
            </div>
        </div>
        <?php
    }

    public function render_conversations_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $selected_id = isset($_GET['conversation_id']) ? absint($_GET['conversation_id']) : 0;
        ?>
        <div class="wrap criaw-inbox-wrap">
            <style>
                .criaw-inbox-wrap .criaw-inbox-shell { background:linear-gradient(180deg,#f7fdfb 0%,#eef8f5 100%); border:1px solid #d9ece6; border-radius:24px; padding:24px; }
                .criaw-inbox-wrap .criaw-inbox-grid { display:grid; grid-template-columns:340px 1fr; gap:20px; align-items:start; }
                .criaw-inbox-wrap .criaw-inbox-panel { background:#fff; border:1px solid #dbe7e3; border-radius:20px; padding:18px; box-shadow:0 10px 24px rgba(16,24,40,.04); }
                .criaw-inbox-wrap .criaw-inbox-note { color:#667085; margin-top:8px; }
            </style>
            <div class="criaw-inbox-shell">
                <h1><?php esc_html_e('Live Chat Inbox', 'carbon-repro-widget'); ?></h1>
                <p class="criaw-inbox-note"><?php esc_html_e('This view refreshes automatically so your team can follow live conversations and reply without losing context.', 'carbon-repro-widget'); ?></p>
                <div class="criaw-inbox-grid">
                    <div class="criaw-inbox-panel" id="criawInboxList"><?php echo $this->get_conversation_list_html($selected_id); ?></div>
                    <div class="criaw-inbox-panel" id="criawInboxDetail"><?php echo $this->get_conversation_detail_html($selected_id); ?></div>
                </div>
            </div>
            <script>
            (function () {
              var selectedId = <?php echo (int) $selected_id; ?>;
              function canRefreshDetail() {
                var active = document.activeElement;
                var draft = document.querySelector('textarea[name="human_reply"]');
                if (draft && draft.value && draft.value.trim() !== '') {
                  return false;
                }
                if (!active) { return true; }
                if (active.matches && active.matches('textarea[name="human_reply"], input, textarea, select')) {
                  return false;
                }
                return true;
              }
              function refreshInbox() {
                var formData = new window.FormData();
                formData.append('action', 'criaw_admin_inbox_snapshot');
                formData.append('conversation_id', selectedId);
                formData.append('_ajax_nonce', '<?php echo esc_js(wp_create_nonce('criaw_admin_inbox')); ?>');
                window.fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: formData })
                  .then(function (response) { return response.json(); })
                  .then(function (data) {
                    if (!data || !data.success || !data.data) { return; }
                    if (data.data.list_html) {
                      document.getElementById('criawInboxList').innerHTML = data.data.list_html;
                    }
                    if (data.data.detail_html && canRefreshDetail()) {
                      document.getElementById('criawInboxDetail').innerHTML = data.data.detail_html;
                    }
                  })
                  .catch(function () {});
              }
              window.setInterval(refreshInbox, 5000);
            }());
            </script>
        </div>
        <?php
    }

    public function get_admin_inbox_snapshot()
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'carbon-repro-widget')), 403);
        }

        check_ajax_referer('criaw_admin_inbox');
        $selected_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;

        wp_send_json_success(
            array(
                'list_html' => $this->get_conversation_list_html($selected_id),
                'detail_html' => $this->get_conversation_detail_html($selected_id),
            )
        );
    }

    public function track_event()
    {
        check_ajax_referer('criaw_track_event', 'nonce');

        $payload = $this->sanitize_event_payload($_POST);
        if (empty($payload['event_type'])) {
            wp_send_json_error(array('message' => __('Missing event type.', 'carbon-repro-widget')), 400);
        }

        $event_id = $this->insert_event($payload);
        wp_send_json_success(array('event_id' => $event_id));
    }

    public function handle_start_chat()
    {
        check_ajax_referer('criaw_start_chat', 'nonce');

        $meta = $this->sanitize_event_payload($_POST);
        $lead = $this->get_empty_lead();
        $lead['name'] = isset($_POST['lead_name']) ? sanitize_text_field(wp_unslash($_POST['lead_name'])) : '';
        $lead['email'] = isset($_POST['lead_email']) ? sanitize_email(wp_unslash($_POST['lead_email'])) : '';
        $lead['phone'] = isset($_POST['lead_phone']) ? sanitize_text_field(wp_unslash($_POST['lead_phone'])) : '';
        $lead['looking_for'] = isset($_POST['lead_need']) ? sanitize_textarea_field(wp_unslash($_POST['lead_need'])) : '';

        if ($lead['name'] === '' || $lead['email'] === '' || $lead['phone'] === '') {
            wp_send_json_error(array('message' => __('Please complete name, email, and phone before starting the chat.', 'carbon-repro-widget')), 400);
        }

        if (! is_email($lead['email'])) {
            wp_send_json_error(array('message' => __('Please enter a valid email address.', 'carbon-repro-widget')), 400);
        }

        $conversation_id = $this->get_or_create_conversation(0, $meta);
        $meta_store = get_post_meta($conversation_id, '_criaw_meta', true);
        $meta_store = is_array($meta_store) ? array_merge($meta_store, $meta) : $meta;
        if (isset($_POST['email_updates_opt_in'])) {
            $meta_store['email_updates_opt_in'] = sanitize_text_field(wp_unslash($_POST['email_updates_opt_in']));
        }
        if (isset($_POST['sms_updates_opt_in'])) {
            $meta_store['sms_updates_opt_in'] = sanitize_text_field(wp_unslash($_POST['sms_updates_opt_in']));
        }
        if (isset($_POST['whatsapp_updates_opt_in'])) {
            $meta_store['whatsapp_updates_opt_in'] = sanitize_text_field(wp_unslash($_POST['whatsapp_updates_opt_in']));
        }
        $transcript = array();
        $reply = $this->get_default_start_reply($lead);
        $assistant_meta = array('catalog_cards' => array(), 'catalog_links' => array(), 'contact_actions' => array());
        $intent = 'UNKNOWN';

        if ($lead['looking_for'] !== '') {
            $catalog_suggestions = $this->get_catalog_suggestions_for_query($lead['looking_for']);
            $intent = $this->classify_user_intent($lead['looking_for'], $catalog_suggestions);
            $transcript[] = array(
                'role' => 'user',
                'content' => $lead['looking_for'],
                'time' => current_time('mysql'),
            );

            if ($intent === 'GREETING') {
                $reply = __('Hi! How can I help you today?', 'carbon-repro-widget');
            } elseif ($intent === 'GRATITUDE') {
                $reply = __('You are welcome. Let me know if you need anything else.', 'carbon-repro-widget');
            } elseif ($intent === 'BUSINESS_INFO') {
                $reply = $this->get_business_info_reply();
                $assistant_meta = $this->build_assistant_meta(array('cards' => array(), 'links' => array()), $lead['looking_for'], true);
            } elseif ($intent === 'PRODUCT_BROWSE') {
                $catalog_suggestions = $this->get_brand_browse_suggestions();
                $reply = __('Sure! Here are some of our watch brands and collections. Tell me which brand you want and I will show matching pieces.', 'carbon-repro-widget');
                $assistant_meta = $this->build_assistant_meta($catalog_suggestions, $lead['looking_for'], false);
            } elseif ($intent === 'PRODUCT_SEARCH' || $intent === 'PRODUCT_DETAILS') {
                $reply = $this->format_catalog_assisted_reply($lead['looking_for'], '', $catalog_suggestions);
                $assistant_meta = $this->build_assistant_meta($catalog_suggestions, $lead['looking_for'], false);
            } else {
                $result = $this->request_openai_reply($transcript, $lead);
                if (! is_wp_error($result) && ! empty($result['reply'])) {
                    $reply = $result['reply'];
                    $lead = $this->merge_lead_data($lead, $result['lead']);
                } else {
                    $reply = __('Can you tell me your preferred brand or price range?', 'carbon-repro-widget');
                }
            }
        }

        $transcript[] = array(
            'role' => 'assistant',
            'content' => $reply,
            'time' => current_time('mysql'),
            'meta' => $assistant_meta,
        );
        update_post_meta($conversation_id, '_criaw_lead', $lead);
        update_post_meta($conversation_id, '_criaw_meta', $meta_store);
        update_post_meta($conversation_id, '_criaw_transcript', $transcript);
        update_post_meta($conversation_id, '_criaw_last_message_at', current_time('mysql'));
        $this->set_chat_context($conversation_id, $intent, $lead['looking_for'], isset($assistant_meta['catalog_cards']) ? $assistant_meta['catalog_cards'] : array());
        $this->refresh_conversation_title($conversation_id, $lead);

        $event = $meta;
        $event['event_type'] = 'chat_start';
        $event['conversation_id'] = $conversation_id;
        $event['cta'] = 'chat';
        $event['lead_name'] = $lead['name'];
        $event['lead_email'] = $lead['email'];
        $event['lead_phone'] = $lead['phone'];
        $event['lead_need'] = $lead['looking_for'];
        $this->insert_event($event);
        $meta_store['conversation_id'] = $conversation_id;
        update_post_meta($conversation_id, '_criaw_meta', $meta_store);
        $this->send_new_conversation_notification($conversation_id, $lead, $meta_store);
        $this->send_admin_sms_notification($conversation_id, $lead, $meta_store, 'new_chat');
        $this->send_customer_chat_sms($lead, __('Thanks for starting a chat with us. We have your details and will follow up if needed.', 'carbon-repro-widget'), $meta_store, 'new_chat');

        wp_send_json_success(
            array(
                'conversation_id' => $conversation_id,
                'lead' => $lead,
                'reply' => $reply,
                'catalog_cards' => isset($assistant_meta['catalog_cards']) ? $assistant_meta['catalog_cards'] : array(),
                'catalog_links' => isset($assistant_meta['catalog_links']) ? $assistant_meta['catalog_links'] : array(),
                'contact_actions' => isset($assistant_meta['contact_actions']) ? $assistant_meta['contact_actions'] : array(),
            )
        );
    }

    public function handle_chat_message()
    {
        check_ajax_referer('criaw_chat_message', 'nonce');

        if (! $this->is_chatbot_enabled()) {
            wp_send_json_error(array('message' => __('The chatbot is disabled.', 'carbon-repro-widget')), 400);
        }

        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        if ($message === '') {
            wp_send_json_error(array('message' => __('Please enter a message.', 'carbon-repro-widget')), 400);
        }

        $meta = $this->sanitize_event_payload($_POST);
        $conversation_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        $conversation_id = $this->get_or_create_conversation($conversation_id, $meta);

        $transcript = get_post_meta($conversation_id, '_criaw_transcript', true);
        $transcript = is_array($transcript) ? $transcript : array();
        $lead = get_post_meta($conversation_id, '_criaw_lead', true);
        $lead = is_array($lead) ? $lead : $this->get_empty_lead();
        $meta_store = get_post_meta($conversation_id, '_criaw_meta', true);
        $meta_store = is_array($meta_store) ? $meta_store : array();
        $is_new_conversation = empty($transcript);
        $human_takeover = get_post_meta($conversation_id, '_criaw_human_takeover', true) === '1';
        $meta_store = array_merge($meta_store, $meta);
        $meta_store['conversation_id'] = $conversation_id;
        $context = $this->get_chat_context($conversation_id);
        if (! empty($meta['email_updates_opt_in'])) {
            $meta_store['email_updates_opt_in'] = $meta['email_updates_opt_in'];
        }
        if (isset($meta['sms_updates_opt_in'])) {
            $meta_store['sms_updates_opt_in'] = $meta['sms_updates_opt_in'];
        }
        if (isset($meta['whatsapp_updates_opt_in'])) {
            $meta_store['whatsapp_updates_opt_in'] = $meta['whatsapp_updates_opt_in'];
        }

        $transcript[] = array(
            'role' => 'user',
            'content' => $message,
            'time' => current_time('mysql'),
        );

        $limit_state = $this->maybe_apply_chat_limit($conversation_id, $transcript, $lead, $meta_store);
        if (! empty($limit_state['triggered'])) {
            $transcript[] = array(
                'role' => 'assistant',
                'content' => $limit_state['reply'],
                'time' => current_time('mysql'),
                'meta' => isset($limit_state['meta']) ? $limit_state['meta'] : array(),
            );
            update_post_meta($conversation_id, '_criaw_transcript', $transcript);
            update_post_meta($conversation_id, '_criaw_lead', $lead);
            update_post_meta($conversation_id, '_criaw_meta', $meta_store);
            update_post_meta($conversation_id, '_criaw_last_message_at', current_time('mysql'));
            $this->refresh_conversation_title($conversation_id, $lead);

            wp_send_json_success(
                array(
                    'conversation_id' => $conversation_id,
                    'reply' => $limit_state['reply'],
                    'lead' => $lead,
                    'human_takeover' => false,
                    'catalog_cards' => isset($limit_state['meta']['catalog_cards']) ? $limit_state['meta']['catalog_cards'] : array(),
                    'catalog_links' => isset($limit_state['meta']['catalog_links']) ? $limit_state['meta']['catalog_links'] : array(),
                    'contact_actions' => isset($limit_state['meta']['contact_actions']) ? $limit_state['meta']['contact_actions'] : array(),
                )
            );
        }

        if ($human_takeover) {
            update_post_meta($conversation_id, '_criaw_transcript', $transcript);
            update_post_meta($conversation_id, '_criaw_meta', $meta_store);
            wp_send_json_success(
                array(
                    'conversation_id' => $conversation_id,
                    'reply' => __('A team member has taken over this chat and will reply shortly.', 'carbon-repro-widget'),
                    'lead' => $lead,
                    'human_takeover' => true,
                )
            );
        }

        $index = get_option(self::CATALOG_INDEX_OPTION_KEY, array());
        $index_products = isset($index['products']) && is_array($index['products']) ? $index['products'] : array();
        $index_categories = isset($index['categories']) && is_array($index['categories']) ? $index['categories'] : array();
        $entities = $this->extract_message_entities($message, $index_categories, $index_products);
        $initial_intent = $this->classify_user_intent($message, array());
        $entities = $this->merge_entities_with_context($entities, $context, $initial_intent, $message);
        $search_query = $this->build_constrained_search_query($message, $entities);
        $catalog_suggestions = $this->get_catalog_suggestions_for_query($search_query);
        $intent = $this->classify_user_intent($message, $catalog_suggestions);
        $result = array('reply' => '', 'lead' => $lead);
        $assistant_meta = array('catalog_cards' => array(), 'catalog_links' => array(), 'contact_actions' => array());

        if (isset($context['conversation_stage']) && $context['conversation_stage'] === 'handoff_confirmed') {
            $result['reply'] = __('Our team has your details and will contact you shortly. If you want, I can also share our direct contact details again.', 'carbon-repro-widget');
            $intent = 'HUMAN_AGENT_REQUEST';
        } elseif (isset($context['conversation_stage']) && $context['conversation_stage'] === 'awaiting_contact_details' && $intent === 'PROVIDE_CONTACT_INFO') {
            $parsed = $this->extract_contact_details_from_text($message);
            $lead['name'] = $lead['name'] !== '' ? $lead['name'] : (isset($parsed['name']) ? $parsed['name'] : '');
            $lead['email'] = $lead['email'] !== '' ? $lead['email'] : (isset($parsed['email']) ? $parsed['email'] : '');
            $lead['phone'] = $lead['phone'] !== '' ? $lead['phone'] : (isset($parsed['phone']) ? $parsed['phone'] : '');
            $result['lead'] = $lead;

            if ($lead['name'] !== '' && $lead['email'] !== '' && $lead['phone'] !== '') {
                $contact = $this->get_bot_contact_details();
                $result['reply'] = sprintf(
                    __('Thanks! Our team will contact you shortly.%1$sPhone: %2$s%1$sEmail: %3$s', 'carbon-repro-widget'),
                    "\n",
                    ! empty($contact['phone']) ? $contact['phone'] : '713-705-6097',
                    ! empty($contact['email']) ? $contact['email'] : 'info@fsfinewatches.com'
                );
                $assistant_meta = $this->build_assistant_meta(array('cards' => array(), 'links' => array()), 'contact', true);
                $this->set_chat_context(
                    $conversation_id,
                    'HUMAN_AGENT_REQUEST',
                    $message,
                    array(),
                    array(
                        'conversation_stage' => 'handoff_confirmed',
                        'last_brand' => ! empty($entities['brand_explicit']) ? (string) $entities['brand'] : (isset($context['last_brand']) ? (string) $context['last_brand'] : ''),
                        'last_price_range' => (! empty($entities['price_explicit']) || ! empty($entities['has_price_filter'])) ? array('min_price' => $entities['min_price'], 'max_price' => $entities['max_price']) : (isset($context['last_price_range']) && is_array($context['last_price_range']) ? $context['last_price_range'] : array('min_price' => null, 'max_price' => null)),
                    )
                );
            } else {
                $result['reply'] = __('I can connect you with a real agent. Please share your name, email, and phone.', 'carbon-repro-widget');
                $this->set_chat_context(
                    $conversation_id,
                    'HUMAN_AGENT_REQUEST',
                    $message,
                    array(),
                    array(
                        'conversation_stage' => 'awaiting_contact_details',
                        'last_brand' => ! empty($entities['brand_explicit']) ? (string) $entities['brand'] : (isset($context['last_brand']) ? (string) $context['last_brand'] : ''),
                        'last_price_range' => (! empty($entities['price_explicit']) || ! empty($entities['has_price_filter'])) ? array('min_price' => $entities['min_price'], 'max_price' => $entities['max_price']) : (isset($context['last_price_range']) && is_array($context['last_price_range']) ? $context['last_price_range'] : array('min_price' => null, 'max_price' => null)),
                    )
                );
            }
        } elseif ($intent === 'HUMAN_AGENT_REQUEST') {
            $has_lead = ! empty($lead['name']) && (! empty($lead['email']) || ! empty($lead['phone']));
            if ($has_lead) {
                $contact = $this->get_bot_contact_details();
                $result['reply'] = sprintf(
                    __("I've notified our team and a real agent will be with you shortly!\n\nIn the meantime, you can also reach us directly:\nPhone: %s\nEmail: %s", 'carbon-repro-widget'),
                    ! empty($contact['phone']) ? $contact['phone'] : '713-705-6097',
                    ! empty($contact['email']) ? $contact['email'] : 'info@fsfinewatches.com'
                );
                $assistant_meta = $this->build_assistant_meta(array('cards' => array(), 'links' => array()), 'contact', true);
                $this->set_chat_context(
                    $conversation_id,
                    'HUMAN_AGENT_REQUEST',
                    $message,
                    array(),
                    array(
                        'conversation_stage' => 'handoff_confirmed',
                        'last_brand' => ! empty($entities['brand_explicit']) ? (string) $entities['brand'] : (isset($context['last_brand']) ? (string) $context['last_brand'] : ''),
                        'last_price_range' => (! empty($entities['price_explicit']) || ! empty($entities['has_price_filter'])) ? array('min_price' => $entities['min_price'], 'max_price' => $entities['max_price']) : (isset($context['last_price_range']) && is_array($context['last_price_range']) ? $context['last_price_range'] : array('min_price' => null, 'max_price' => null)),
                    )
                );
            } else {
                $result['reply'] = __('I can connect you with a real agent. Please share your name, email, and phone so we can reach you.', 'carbon-repro-widget');
                $this->set_chat_context(
                    $conversation_id,
                    'HUMAN_AGENT_REQUEST',
                    $message,
                    array(),
                    array(
                        'conversation_stage' => 'awaiting_contact_details',
                        'last_brand' => ! empty($entities['brand_explicit']) ? (string) $entities['brand'] : (isset($context['last_brand']) ? (string) $context['last_brand'] : ''),
                        'last_price_range' => (! empty($entities['price_explicit']) || ! empty($entities['has_price_filter'])) ? array('min_price' => $entities['min_price'], 'max_price' => $entities['max_price']) : (isset($context['last_price_range']) && is_array($context['last_price_range']) ? $context['last_price_range'] : array('min_price' => null, 'max_price' => null)),
                    )
                );
            }
        } elseif ($intent === 'GREETING') {
            $result['reply'] = __('Hi! How can I help you today?', 'carbon-repro-widget');
        } elseif ($intent === 'GRATITUDE') {
            $result['reply'] = __('You are welcome! Let me know if you need anything else.', 'carbon-repro-widget');
        } elseif ($intent === 'CONFUSION') {
            $result['reply'] = __('I understand the frustration — let me fix that for you. Please tell me your preferred brand or budget and I will narrow it down accurately.', 'carbon-repro-widget');
        } elseif ($intent === 'BUSINESS_INFO') {
            $result['reply'] = $this->get_business_info_reply();
            $assistant_meta = $this->build_assistant_meta(array('cards' => array(), 'links' => array()), $message, true);
        } elseif ($intent === 'BRAND_REQUEST') {
            if (! $this->is_ecommerce_mode()) {
                $result['reply'] = __('This assistant is currently in general business mode. Tell me what service or information you need and I will help.', 'carbon-repro-widget');
            } else {
            $catalog_suggestions = $this->get_brand_browse_suggestions();
            $result['reply'] = __('Sure! Here are our brands and collections. Tell me the brand or model you want and I will narrow it down.', 'carbon-repro-widget');
            $assistant_meta = $this->build_assistant_meta($catalog_suggestions, $message, false);
            }
        } elseif ($intent === 'PRODUCT_SEARCH' || $intent === 'PRODUCT_FILTER' || $intent === 'PRODUCT_DETAILS') {
            if (! $this->is_ecommerce_mode()) {
                $result['reply'] = __('I can help with business information and support. Can you tell me what service or help you need?', 'carbon-repro-widget');
            } else {
            $refined_cards = $this->refine_cards_from_context($message, isset($context['last_catalog_cards']) ? $context['last_catalog_cards'] : array());
            if (! empty($refined_cards)) {
                $catalog_suggestions['cards'] = $refined_cards;
                $catalog_suggestions['links'] = array();
                $result['reply'] = __('Sure — here are options based on your previous results.', 'carbon-repro-widget');
            } else {
                $result['reply'] = $this->format_catalog_assisted_reply($search_query, '', $catalog_suggestions);
            }
            $assistant_meta = $this->build_assistant_meta($catalog_suggestions, $message, false);
            }
        } else {
            $result = $this->request_openai_reply($transcript, $lead);
            if (is_wp_error($result)) {
                $result = array(
                    'reply' => __('Can you tell me your preferred brand or price range?', 'carbon-repro-widget'),
                    'lead' => $lead,
                );
            }
            if (empty($result['reply'])) {
                $result['reply'] = __('Can you tell me your preferred brand or price range?', 'carbon-repro-widget');
        }
        }

        $lead = $this->merge_lead_data($lead, $result['lead']);
        $transcript[] = array(
            'role' => 'assistant',
            'content' => $result['reply'],
            'time' => current_time('mysql'),
            'meta' => $assistant_meta,
        );

        update_post_meta($conversation_id, '_criaw_transcript', $transcript);
        update_post_meta($conversation_id, '_criaw_lead', $lead);
        update_post_meta($conversation_id, '_criaw_meta', $meta_store);
        update_post_meta($conversation_id, '_criaw_last_message_at', current_time('mysql'));
        if (! (isset($context['conversation_stage']) && in_array($context['conversation_stage'], array('awaiting_contact_details', 'handoff_confirmed'), true) && in_array($intent, array('HUMAN_AGENT_REQUEST', 'PROVIDE_CONTACT_INFO'), true))) {
            $this->set_chat_context(
                $conversation_id,
                $intent,
                $message,
                isset($assistant_meta['catalog_cards']) ? $assistant_meta['catalog_cards'] : array(),
                array(
                    'last_brand' => ! empty($entities['brand_explicit']) ? (string) $entities['brand'] : (isset($context['last_brand']) ? (string) $context['last_brand'] : ''),
                    'last_price_range' => (! empty($entities['price_explicit']) || ! empty($entities['has_price_filter'])) ? array('min_price' => $entities['min_price'], 'max_price' => $entities['max_price']) : (isset($context['last_price_range']) && is_array($context['last_price_range']) ? $context['last_price_range'] : array('min_price' => null, 'max_price' => null)),
                    'conversation_stage' => (isset($context['conversation_stage']) && in_array($intent, array('PRODUCT_SEARCH', 'PRODUCT_FILTER', 'PRODUCT_DETAILS', 'BRAND_REQUEST', 'GREETING', 'GRATITUDE', 'BUSINESS_INFO', 'UNKNOWN', 'CONFUSION'), true)) ? 'normal' : (isset($context['conversation_stage']) ? $context['conversation_stage'] : 'normal'),
                )
            );
        }
        $this->refresh_conversation_title($conversation_id, $lead);

        if ($is_new_conversation) {
            $meta['event_type'] = 'chat_start';
            $meta['conversation_id'] = $conversation_id;
            $meta['cta'] = 'chat';
            $meta['lead_name'] = $lead['name'];
            $meta['lead_email'] = $lead['email'];
            $meta['lead_phone'] = $lead['phone'];
            $meta['lead_need'] = $lead['looking_for'];
            $this->insert_event($meta);
            $this->send_new_conversation_notification($conversation_id, $lead, $meta);
        }

        wp_send_json_success(
            array(
                'conversation_id' => $conversation_id,
                'reply' => $result['reply'],
                'lead' => $lead,
                'human_takeover' => false,
                'catalog_cards' => isset($assistant_meta['catalog_cards']) ? $assistant_meta['catalog_cards'] : array(),
                'catalog_links' => isset($assistant_meta['catalog_links']) ? $assistant_meta['catalog_links'] : array(),
                'contact_actions' => isset($assistant_meta['contact_actions']) ? $assistant_meta['contact_actions'] : array(),
            )
        );
    }

    public function handle_chat_upload()
    {
        check_ajax_referer('criaw_chat_upload', 'nonce');

        if (empty($_FILES['chat_image'])) {
            wp_send_json_error(array('message' => __('No image uploaded.', 'carbon-repro-widget')), 400);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = $_FILES['chat_image'];
        $allowed_mimes = array(
            'jpg|jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        );
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed_mimes);
        if (empty($check['type']) || strpos((string) $check['type'], 'image/') !== 0) {
            wp_send_json_error(array('message' => __('Only JPG, PNG, GIF, and WEBP images are allowed.', 'carbon-repro-widget')), 400);
        }
        if (! empty($file['size']) && (int) $file['size'] > 5 * MB_IN_BYTES) {
            wp_send_json_error(array('message' => __('Image is too large. Maximum size is 5MB.', 'carbon-repro-widget')), 400);
        }

        $uploaded = wp_handle_upload($file, array('test_form' => false, 'mimes' => $allowed_mimes));
        if (isset($uploaded['error'])) {
            wp_send_json_error(array('message' => $uploaded['error']), 400);
        }

        wp_send_json_success(
            array(
                'url' => isset($uploaded['url']) ? esc_url_raw($uploaded['url']) : '',
            )
        );
    }

    public function get_chat_updates()
    {
        check_ajax_referer('criaw_chat_message', 'nonce');

        $conversation_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        $since = isset($_POST['since']) ? absint($_POST['since']) : 0;
        $conversation = get_post($conversation_id);

        if (! $conversation || $conversation->post_type !== self::CONVERSATION_POST_TYPE) {
            wp_send_json_error(array('message' => __('Conversation not found.', 'carbon-repro-widget')), 404);
        }

        $transcript = get_post_meta($conversation_id, '_criaw_transcript', true);
        $transcript = is_array($transcript) ? $transcript : array();
        $slice = array_slice($transcript, $since);

        wp_send_json_success(
            array(
                'messages' => array_values($slice),
                'count' => count($transcript),
                'human_takeover' => get_post_meta($conversation_id, '_criaw_human_takeover', true) === '1',
                'conversation_id' => $conversation_id,
            )
        );
    }

    public function get_catalog_cards()
    {
        check_ajax_referer('criaw_catalog_cards', 'nonce');

        $urls = isset($_POST['urls']) ? (array) wp_unslash($_POST['urls']) : array();
        $cards = $this->build_catalog_cards_for_urls($urls);
        wp_send_json_success(array('cards' => $cards));
    }

    public function handle_human_reply()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'carbon-repro-widget'));
        }

        check_admin_referer('criaw_human_reply');

        $conversation_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        $message = isset($_POST['human_reply']) ? sanitize_textarea_field(wp_unslash($_POST['human_reply'])) : '';
        $image_url = isset($_POST['human_reply_image']) ? esc_url_raw(wp_unslash($_POST['human_reply_image'])) : '';
        $content = $message;
        if ($image_url !== '') {
            $content .= ($content !== '' ? "\n" : '') . 'Photo: ' . $image_url;
        }

        if ($conversation_id > 0 && $content !== '') {
            $transcript = get_post_meta($conversation_id, '_criaw_transcript', true);
            $transcript = is_array($transcript) ? $transcript : array();
            $transcript[] = array(
                'role' => 'assistant',
                'content' => $content,
                'time' => current_time('mysql'),
                'sender' => 'human',
            );
            update_post_meta($conversation_id, '_criaw_transcript', $transcript);
            update_post_meta($conversation_id, '_criaw_human_takeover', '1');
            update_post_meta($conversation_id, '_criaw_last_message_at', current_time('mysql'));
            $lead = get_post_meta($conversation_id, '_criaw_lead', true);
            $lead = is_array($lead) ? $lead : $this->get_empty_lead();
            $meta = get_post_meta($conversation_id, '_criaw_meta', true);
            $meta = is_array($meta) ? $meta : array();
            $meta['conversation_id'] = $conversation_id;
            $this->send_customer_human_reply_email($lead, $content, $meta);
            $this->send_customer_chat_sms($lead, $content, $meta, 'human_reply');
            $this->send_admin_sms_notification($conversation_id, $lead, $meta, 'human_reply');
            wp_update_post(array('ID' => $conversation_id));
        }

        wp_safe_redirect(admin_url('admin.php?page=carbon-repro-widget-conversations&conversation_id=' . $conversation_id));
        exit;
    }

    public function handle_takeover_toggle()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'carbon-repro-widget'));
        }

        check_admin_referer('criaw_toggle_takeover');

        $conversation_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        $active = isset($_POST['takeover_active']) ? '1' : '0';

        if ($conversation_id > 0) {
            $was_active = get_post_meta($conversation_id, '_criaw_human_takeover', true) === '1';
            update_post_meta($conversation_id, '_criaw_human_takeover', $active);
            wp_update_post(array('ID' => $conversation_id));
            if ($active === '1' && ! $was_active) {
                $transcript = get_post_meta($conversation_id, '_criaw_transcript', true);
                $transcript = is_array($transcript) ? $transcript : array();
                $transcript[] = array(
                    'role' => 'assistant',
                    'content' => __('A team member has taken over this chat and will reply shortly.', 'carbon-repro-widget'),
                    'time' => current_time('mysql'),
                    'sender' => 'human',
                );
                update_post_meta($conversation_id, '_criaw_transcript', $transcript);
                update_post_meta($conversation_id, '_criaw_last_message_at', current_time('mysql'));
                $lead = get_post_meta($conversation_id, '_criaw_lead', true);
                $lead = is_array($lead) ? $lead : $this->get_empty_lead();
                $meta = get_post_meta($conversation_id, '_criaw_meta', true);
                $meta = is_array($meta) ? $meta : array();
                $meta['conversation_id'] = $conversation_id;
                $this->send_customer_takeover_email($lead, $meta);
                $this->send_customer_chat_sms($lead, __('A team member has joined your chat and will respond personally.', 'carbon-repro-widget'), $meta, 'takeover');
                $this->send_admin_sms_notification($conversation_id, $lead, $meta, 'takeover');
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=carbon-repro-widget-conversations&conversation_id=' . $conversation_id));
        exit;
    }

    public function handle_site_crawl()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'carbon-repro-widget'));
        }

        check_admin_referer('criaw_run_site_crawl');

        $crawl = $this->crawl_site_content();
        $settings = wp_parse_args(get_option(self::OPTION_KEY, array()), $this->get_default_settings());
        $settings['crawled_knowledge'] = isset($crawl['summary']) ? $crawl['summary'] : '';
        update_option(self::OPTION_KEY, $settings, false);
        update_option(self::CRAWL_PAGES_OPTION_KEY, isset($crawl['pages']) ? $crawl['pages'] : array(), false);

        wp_safe_redirect(admin_url('admin.php?page=carbon-repro-widget-settings'));
        exit;
    }

    public function handle_twilio_test()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'carbon-repro-widget'));
        }

        check_admin_referer('criaw_test_twilio');

        $target = $this->get_setting('notification_sms_number', '');
        if ($target === '') {
            wp_safe_redirect(
                add_query_arg(
                    array(
                        'page' => 'carbon-repro-widget-settings',
                        'criaw_sms_test' => 'error',
                        'message' => __('Please set the Admin Notification Phone before running a test SMS.', 'carbon-repro-widget'),
                    ),
                    admin_url('admin.php')
                )
            );
            exit;
        }

        $message = sprintf(
            __('Test SMS from %s at %s. If you received this message, your Twilio settings are working.', 'carbon-repro-widget'),
            $this->get_setting('business_name', get_bloginfo('name')),
            current_time('mysql')
        );
        $result = $this->send_twilio_sms($target, $message);
        $status = ! empty($result['success']) ? 'success' : 'error';
        $notice = isset($result['message']) ? $result['message'] : ($status === 'success' ? __('Twilio test SMS sent successfully.', 'carbon-repro-widget') : __('Twilio test SMS failed.', 'carbon-repro-widget'));

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'carbon-repro-widget-settings',
                    'criaw_sms_test' => $status,
                    'message' => $notice,
                ),
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function handle_catalog_index()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'carbon-repro-widget'));
        }

        check_admin_referer('criaw_run_catalog_index');

        $index = $this->build_woocommerce_catalog_index();
        $settings = wp_parse_args(get_option(self::OPTION_KEY, array()), $this->get_default_settings());
        $settings['catalog_index_knowledge'] = isset($index['summary']) ? $index['summary'] : '';
        update_option(self::OPTION_KEY, $settings, false);
        update_option(self::CATALOG_INDEX_OPTION_KEY, $index, false);

        wp_safe_redirect(admin_url('admin.php?page=carbon-repro-widget-settings'));
        exit;
    }

    public function handle_catalog_index_batch()
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'carbon-repro-widget')), 403);
        }

        check_ajax_referer('criaw_catalog_index_batch');

        if (! $this->is_woocommerce_active()) {
            wp_send_json_error(array('message' => __('WooCommerce is not active.', 'carbon-repro-widget')), 400);
        }

        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $limit = isset($_POST['limit']) ? max(1, min(50, absint($_POST['limit']))) : 20;
        $batch_key = self::CATALOG_BATCH_TRANSIENT_PREFIX . get_current_user_id();
        $batch_data = get_transient($batch_key);
        $batch_data = is_array($batch_data) ? $batch_data : array('products' => array(), 'categories' => array());

        if ($offset === 0) {
            $batch_data = array('products' => array(), 'categories' => array());
        }

        $product_posts = get_posts(
            array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'numberposts' => $limit,
                'offset' => $offset,
                'orderby' => 'date',
                'order' => 'DESC',
            )
        );
        $total = (int) wp_count_posts('product')->publish;
        $products = array();

        foreach ($product_posts as $post) {
            $product = function_exists('wc_get_product') ? wc_get_product($post->ID) : null;
            if (! $product) {
                continue;
            }
            $products[] = array(
                'id' => $post->ID,
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'price' => wp_strip_all_tags($product->get_price_html()),
                'url' => get_permalink($post->ID),
                'categories' => wp_get_post_terms($post->ID, 'product_cat', array('fields' => 'names')),
                'short_description' => wp_trim_words(wp_strip_all_tags($product->get_short_description(), true), 30, '...'),
                'image' => get_the_post_thumbnail_url($post->ID, 'medium'),
                'attributes' => $this->extract_product_attributes($product),
                'searchable_text' => $this->build_product_searchable_text($product),
            );
        }

        $is_complete = ($offset + $limit) >= $total;
        $categories = array();
        if ($offset === 0) {
            $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
            if (! is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $categories[] = array(
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                        'url' => is_string(get_term_link($term)) ? get_term_link($term) : '',
                        'description' => wp_trim_words(wp_strip_all_tags($term->description, true), 24, '...'),
                    );
                }
            }
        }

        $batch_data['products'] = array_merge($batch_data['products'], $products);
        if ($offset === 0) {
            $batch_data['categories'] = $categories;
        }
        set_transient($batch_key, $batch_data, HOUR_IN_SECONDS);

        $summary = '';
        if ($is_complete) {
            $final_index = array(
                'summary' => $this->build_catalog_summary($batch_data['products'], $batch_data['categories']),
                'products' => $batch_data['products'],
                'categories' => $batch_data['categories'],
                'stored_product_count' => count($batch_data['products']),
                'stored_category_count' => count($batch_data['categories']),
                'catalog_product_count' => $total,
                'catalog_category_count' => count($batch_data['categories']),
                'indexed_at' => current_time('mysql'),
            );
            $summary = $final_index['summary'];
            update_option(self::CATALOG_INDEX_OPTION_KEY, $final_index, false);
            $settings = wp_parse_args(get_option(self::OPTION_KEY, array()), $this->get_default_settings());
            $settings['catalog_index_enabled'] = '1';
            $settings['catalog_index_knowledge'] = $summary;
            update_option(self::OPTION_KEY, $settings, false);
            delete_transient($batch_key);
        }

        wp_send_json_success(
            array(
                'offset' => $offset,
                'next_offset' => $offset + $limit,
                'total' => $total,
                'complete' => $is_complete,
                'products' => $products,
                'categories' => $categories,
                'indexed_products' => count($batch_data['products']),
                'indexed_categories' => count($batch_data['categories']),
                'summary' => $summary,
            )
        );
    }

    public function handle_wpforms_submission($fields, $entry, $form_data, $entry_id)
    {
        if (empty($_COOKIE['criaw_widget_form']) || $_COOKIE['criaw_widget_form'] !== '1') {
            return;
        }

        $context = array();
        if (! empty($_COOKIE['criaw_tracking_context'])) {
            $decoded = json_decode(base64_decode(sanitize_text_field(wp_unslash($_COOKIE['criaw_tracking_context']))), true);
            if (is_array($decoded)) {
                $context = $this->sanitize_event_payload($decoded);
            }
        }

        $context['event_type'] = 'form_submit';
        $context['cta'] = 'form';
        $context['lead_name'] = $this->extract_wpforms_field_value($fields, array('name', 'full name', 'first name'));
        $context['lead_email'] = sanitize_email($this->extract_wpforms_field_value($fields, array('email', 'e-mail')));
        $context['lead_phone'] = $this->extract_wpforms_field_value($fields, array('phone', 'mobile', 'telephone'));
        $context['lead_need'] = $this->extract_wpforms_field_value($fields, array('message', 'details', 'service', 'strategy', 'looking for'));
        $context['page_title'] = isset($context['page_title']) ? $context['page_title'] : '';
        $context['page_path'] = isset($context['page_path']) ? $context['page_path'] : '';
        $context['page_url'] = isset($context['page_url']) ? $context['page_url'] : '';

        $this->insert_event($context);
    }

    private function sanitize_event_payload($raw)
    {
        $payload = array(
            'visitor_id' => isset($raw['visitor_id']) ? sanitize_text_field(wp_unslash($raw['visitor_id'])) : '',
            'conversation_id' => isset($raw['conversation_id']) ? absint($raw['conversation_id']) : 0,
            'event_type' => isset($raw['event']) ? sanitize_key(wp_unslash($raw['event'])) : (isset($raw['event_type']) ? sanitize_key(wp_unslash($raw['event_type'])) : ''),
            'cta' => isset($raw['cta']) ? sanitize_key(wp_unslash($raw['cta'])) : '',
            'page_url' => isset($raw['page_url']) ? esc_url_raw(wp_unslash($raw['page_url'])) : '',
            'page_path' => isset($raw['page_path']) ? sanitize_text_field(wp_unslash($raw['page_path'])) : '',
            'page_title' => isset($raw['page_title']) ? sanitize_text_field(wp_unslash($raw['page_title'])) : '',
            'referrer_url' => isset($raw['referrer_url']) ? esc_url_raw(wp_unslash($raw['referrer_url'])) : '',
            'referrer_domain' => isset($raw['referrer_domain']) ? sanitize_text_field(wp_unslash($raw['referrer_domain'])) : '',
            'utm_source' => isset($raw['utm_source']) ? sanitize_text_field(wp_unslash($raw['utm_source'])) : '',
            'utm_medium' => isset($raw['utm_medium']) ? sanitize_text_field(wp_unslash($raw['utm_medium'])) : '',
            'utm_campaign' => isset($raw['utm_campaign']) ? sanitize_text_field(wp_unslash($raw['utm_campaign'])) : '',
            'utm_term' => isset($raw['utm_term']) ? sanitize_text_field(wp_unslash($raw['utm_term'])) : '',
            'utm_content' => isset($raw['utm_content']) ? sanitize_text_field(wp_unslash($raw['utm_content'])) : '',
            'device_type' => isset($raw['device_type']) ? sanitize_text_field(wp_unslash($raw['device_type'])) : '',
            'browser' => isset($raw['browser']) ? sanitize_text_field(wp_unslash($raw['browser'])) : '',
            'os' => isset($raw['os']) ? sanitize_text_field(wp_unslash($raw['os'])) : '',
            'language' => isset($raw['language']) ? sanitize_text_field(wp_unslash($raw['language'])) : '',
            'timezone' => isset($raw['timezone']) ? sanitize_text_field(wp_unslash($raw['timezone'])) : '',
            'screen_size' => isset($raw['screen_size']) ? sanitize_text_field(wp_unslash($raw['screen_size'])) : '',
            'user_agent' => isset($raw['user_agent']) ? sanitize_textarea_field(wp_unslash($raw['user_agent'])) : '',
            'lead_name' => isset($raw['lead_name']) ? sanitize_text_field(wp_unslash($raw['lead_name'])) : '',
            'lead_email' => isset($raw['lead_email']) ? sanitize_email(wp_unslash($raw['lead_email'])) : '',
            'lead_phone' => isset($raw['lead_phone']) ? sanitize_text_field(wp_unslash($raw['lead_phone'])) : '',
            'lead_need' => isset($raw['lead_need']) ? sanitize_textarea_field(wp_unslash($raw['lead_need'])) : '',
            'email_updates_opt_in' => isset($raw['email_updates_opt_in']) ? sanitize_text_field(wp_unslash($raw['email_updates_opt_in'])) : '',
            'sms_updates_opt_in' => isset($raw['sms_updates_opt_in']) ? sanitize_text_field(wp_unslash($raw['sms_updates_opt_in'])) : '',
            'whatsapp_updates_opt_in' => isset($raw['whatsapp_updates_opt_in']) ? sanitize_text_field(wp_unslash($raw['whatsapp_updates_opt_in'])) : '',
        );

        $payload['source_group'] = $this->derive_source_group($payload);

        $country = $this->lookup_country_from_ip($this->get_ip_address());
        $payload['country_code'] = isset($country['country_code']) ? $country['country_code'] : '';
        $payload['country_name'] = isset($country['country_name']) ? $country['country_name'] : '';
        $payload['ip_hash'] = hash('sha256', (string) $this->get_ip_address());

        return $payload;
    }

    private function insert_event($payload)
    {
        global $wpdb;

        $table = $this->get_events_table();

        $wpdb->insert(
            $table,
            array(
                'visitor_id' => $payload['visitor_id'],
                'conversation_id' => $payload['conversation_id'],
                'event_type' => $payload['event_type'],
                'cta' => $payload['cta'],
                'page_url' => $payload['page_url'],
                'page_path' => $payload['page_path'],
                'page_title' => $payload['page_title'],
                'referrer_url' => $payload['referrer_url'],
                'referrer_domain' => $payload['referrer_domain'],
                'utm_source' => $payload['utm_source'],
                'utm_medium' => $payload['utm_medium'],
                'utm_campaign' => $payload['utm_campaign'],
                'utm_term' => $payload['utm_term'],
                'utm_content' => $payload['utm_content'],
                'source_group' => $payload['source_group'],
                'device_type' => $payload['device_type'],
                'browser' => $payload['browser'],
                'os' => $payload['os'],
                'country_code' => isset($payload['country_code']) ? $payload['country_code'] : '',
                'country_name' => isset($payload['country_name']) ? $payload['country_name'] : '',
                'language' => $payload['language'],
                'timezone' => $payload['timezone'],
                'screen_size' => $payload['screen_size'],
                'ip_hash' => isset($payload['ip_hash']) ? $payload['ip_hash'] : '',
                'user_agent' => $payload['user_agent'],
                'lead_name' => $payload['lead_name'],
                'lead_email' => $payload['lead_email'],
                'lead_phone' => $payload['lead_phone'],
                'lead_need' => $payload['lead_need'],
                'created_at' => current_time('mysql'),
            ),
            array(
                '%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function get_dashboard_filters()
    {
        return array(
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : gmdate('Y-m-d', strtotime('-29 days')),
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : gmdate('Y-m-d'),
            'source_group' => isset($_GET['source_group']) ? sanitize_text_field(wp_unslash($_GET['source_group'])) : '',
            'device_type' => isset($_GET['device_type']) ? sanitize_text_field(wp_unslash($_GET['device_type'])) : '',
            'country_name' => isset($_GET['country_name']) ? sanitize_text_field(wp_unslash($_GET['country_name'])) : '',
            'page_path' => isset($_GET['page_path']) ? sanitize_text_field(wp_unslash($_GET['page_path'])) : '',
        );
    }

    private function get_dashboard_data($filters)
    {
        global $wpdb;

        $table = $this->get_events_table();
        $where = array('created_at >= %s', 'created_at <= %s');
        $params = array($filters['date_from'] . ' 00:00:00', $filters['date_to'] . ' 23:59:59');

        foreach (array('source_group', 'device_type', 'country_name', 'page_path') as $filter_key) {
            if ($filters[$filter_key] !== '') {
                $where[] = $filter_key . ' = %s';
                $params[] = $filters[$filter_key];
            }
        }

        $where_sql = 'WHERE ' . implode(' AND ', $where);

        $totals_raw = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_type, COUNT(*) AS total FROM {$table} {$where_sql} GROUP BY event_type",
                $params
            ),
            ARRAY_A
        );

        $cards = array(
            'widget_open' => 0,
            'call_click' => 0,
            'text_click' => 0,
            'form_submit' => 0,
            'chat_start' => 0,
        );

        foreach ($totals_raw as $row) {
            $event_type = $row['event_type'];
            if (isset($cards[$event_type])) {
                $cards[$event_type] = (int) $row['total'];
            }
        }

        $lead_total = $cards['form_submit'] + $cards['chat_start'];
        $cards['conversion_rate'] = $cards['widget_open'] > 0 ? round(($lead_total / $cards['widget_open']) * 100, 1) : 0;

        $trend_raw = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) AS event_date,
                        SUM(CASE WHEN event_type = 'widget_open' THEN 1 ELSE 0 END) AS opens,
                        SUM(CASE WHEN event_type IN ('form_submit', 'chat_start') THEN 1 ELSE 0 END) AS leads
                 FROM {$table}
                 {$where_sql}
                 GROUP BY DATE(created_at)
                 ORDER BY DATE(created_at) ASC",
                $params
            ),
            ARRAY_A
        );

        $trend = array();
        $trend_max = 0;
        foreach ($trend_raw as $row) {
            $opens = (int) $row['opens'];
            $leads = (int) $row['leads'];
            $trend_max = max($trend_max, $opens, $leads);
            $trend[] = array(
                'label' => $row['event_date'],
                'short' => gmdate('M j', strtotime($row['event_date'])),
                'opens' => $opens,
                'leads' => $leads,
            );
        }

        $cta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_type, COUNT(*) AS total
                 FROM {$table}
                 {$where_sql}
                 AND event_type IN ('call_click','text_click','form_submit','chat_start')
                 GROUP BY event_type",
                $params
            ),
            ARRAY_A
        );

        $cta_breakdown = array(
            __('Call', 'carbon-repro-widget') => 0,
            __('SMS', 'carbon-repro-widget') => 0,
            __('Form', 'carbon-repro-widget') => 0,
            __('Chat', 'carbon-repro-widget') => 0,
        );

        foreach ($cta_rows as $row) {
            if ($row['event_type'] === 'call_click') {
                $cta_breakdown[__('Call', 'carbon-repro-widget')] = (int) $row['total'];
            } elseif ($row['event_type'] === 'text_click') {
                $cta_breakdown[__('SMS', 'carbon-repro-widget')] = (int) $row['total'];
            } elseif ($row['event_type'] === 'form_submit') {
                $cta_breakdown[__('Form', 'carbon-repro-widget')] = (int) $row['total'];
            } elseif ($row['event_type'] === 'chat_start') {
                $cta_breakdown[__('Chat', 'carbon-repro-widget')] = (int) $row['total'];
            }
        }

        return array(
            'cards' => $cards,
            'trend' => $trend,
            'trend_max' => $trend_max,
            'cta_breakdown' => $cta_breakdown,
            'cta_total' => array_sum($cta_breakdown),
            'top_pages' => $this->get_grouped_results($where_sql, $params, 'page_path'),
            'form_pages' => $this->get_grouped_results($where_sql . " AND event_type = 'form_submit'", $params, 'page_path'),
            'source_breakdown' => $this->get_grouped_results($where_sql, $params, 'source_group'),
            'country_breakdown' => $this->get_grouped_results($where_sql, $params, 'country_name'),
            'device_breakdown' => $this->get_grouped_results($where_sql, $params, 'device_type'),
            'filter_options' => array(
                'source_group' => $this->get_distinct_values('source_group'),
                'device_type' => $this->get_distinct_values('device_type'),
                'country_name' => $this->get_distinct_values('country_name'),
            ),
        );
    }

    private function get_grouped_results($where_sql, $params, $column)
    {
        global $wpdb;
        $table = $this->get_events_table();

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$column} AS label, COUNT(*) AS total
                 FROM {$table}
                 {$where_sql}
                 AND {$column} <> ''
                 GROUP BY {$column}
                 ORDER BY total DESC
                 LIMIT 8",
                $params
            ),
            ARRAY_A
        );

        if (empty($results)) {
            return array();
        }

        $formatted = array();
        foreach ($results as $row) {
            $formatted[] = array(
                'label' => $row['label'],
                'total' => (int) $row['total'],
            );
        }

        return $formatted;
    }

    private function get_distinct_values($column)
    {
        global $wpdb;
        $table = $this->get_events_table();

        return $wpdb->get_col("SELECT DISTINCT {$column} FROM {$table} WHERE {$column} <> '' ORDER BY {$column} ASC");
    }

    private function render_filter_control($name, $label, $value, $options = array())
    {
        echo '<div class="criaw-filter">';
        echo '<label for="' . esc_attr($name) . '">' . esc_html($label) . '</label>';

        if (! empty($options)) {
            echo '<select id="' . esc_attr($name) . '" name="' . esc_attr($name) . '">';
            echo '<option value="">' . esc_html__('All', 'carbon-repro-widget') . '</option>';
            foreach ($options as $option) {
                echo '<option value="' . esc_attr($option) . '" ' . selected($value, $option, false) . '>' . esc_html($option) . '</option>';
            }
            echo '</select>';
        } else {
            $type = strpos($name, 'date_') === 0 ? 'date' : 'text';
            echo '<input type="' . esc_attr($type) . '" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" />';
        }

        echo '</div>';
    }

    private function render_stat_card($label, $value, $suffix_percent = false)
    {
        echo '<div class="criaw-card">';
        echo '<div class="criaw-card-label">' . esc_html($label) . '</div>';
        echo '<div class="criaw-card-value">' . esc_html($suffix_percent ? $value . '%' : (string) $value) . '</div>';
        echo '</div>';
    }

    private function render_dashboard_table($title, $rows, $headers)
    {
        echo '<div class="criaw-panel">';
        echo '<h2>' . esc_html($title) . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html($headers[0]) . '</th><th>' . esc_html($headers[1]) . '</th></tr></thead><tbody>';
        if (empty($rows)) {
            echo '<tr><td colspan="2">' . esc_html__('No data for current filters.', 'carbon-repro-widget') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                echo '<tr><td>' . esc_html($row['label']) . '</td><td>' . esc_html((string) $row['total']) . '</td></tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    private function get_conversations()
    {
        return get_posts(
            array(
                'post_type' => self::CONVERSATION_POST_TYPE,
                'post_status' => 'publish',
                'posts_per_page' => 50,
                'orderby' => 'modified',
                'order' => 'DESC',
            )
        );
    }

    private function get_conversation_list_html($selected_id)
    {
        $conversations = $this->get_conversations();
        ob_start();
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Visitor', 'carbon-repro-widget') . '</th><th>' . esc_html__('Status', 'carbon-repro-widget') . '</th></tr></thead><tbody>';
        if (empty($conversations)) {
            echo '<tr><td colspan="2">' . esc_html__('No conversations recorded yet.', 'carbon-repro-widget') . '</td></tr>';
        } else {
            foreach ($conversations as $conversation) {
                $lead = get_post_meta($conversation->ID, '_criaw_lead', true);
                $lead = is_array($lead) ? $lead : $this->get_empty_lead();
                $meta = get_post_meta($conversation->ID, '_criaw_meta', true);
                $meta = is_array($meta) ? $meta : array();
                $is_human = get_post_meta($conversation->ID, '_criaw_human_takeover', true) === '1';
                echo '<tr' . ($selected_id === (int) $conversation->ID ? ' style="background:#f2fffb;"' : '') . '>';
                echo '<td><a href="' . esc_url(admin_url('admin.php?page=carbon-repro-widget-conversations&conversation_id=' . $conversation->ID)) . '">';
                echo esc_html($lead['name'] ? $lead['name'] : $conversation->post_title);
                echo '</a><div style="font-size:12px;color:#667085;margin-top:4px;">' . esc_html(isset($meta['source_group']) ? $meta['source_group'] : __('Unknown', 'carbon-repro-widget')) . '</div></td>';
                echo '<td><span class="criaw-pill">' . esc_html($is_human ? __('Human', 'carbon-repro-widget') : __('Bot', 'carbon-repro-widget')) . '</span></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        return ob_get_clean();
    }

    private function get_conversation_detail_html($selected_id)
    {
        ob_start();
        if ($selected_id > 0) {
            $this->render_single_conversation($selected_id);
        } else {
            echo '<p>' . esc_html__('Select a conversation to inspect the transcript, lead details, source, page, device, and country.', 'carbon-repro-widget') . '</p>';
        }
        return ob_get_clean();
    }

    private function render_single_conversation($conversation_id)
    {
        $conversation = get_post($conversation_id);
        if (! $conversation || $conversation->post_type !== self::CONVERSATION_POST_TYPE) {
            echo '<p>' . esc_html__('Conversation not found.', 'carbon-repro-widget') . '</p>';
            return;
        }

        $lead = get_post_meta($conversation_id, '_criaw_lead', true);
        $lead = is_array($lead) ? $lead : $this->get_empty_lead();
        $meta = get_post_meta($conversation_id, '_criaw_meta', true);
        $meta = is_array($meta) ? $meta : array();
        $transcript = get_post_meta($conversation_id, '_criaw_transcript', true);
        $transcript = is_array($transcript) ? $transcript : array();
        $human_takeover = get_post_meta($conversation_id, '_criaw_human_takeover', true) === '1';

        echo '<h2>' . esc_html($conversation->post_title) . '</h2>';
        echo '<div style="display:flex; gap:12px; align-items:center; margin:0 0 16px;">';
        echo '<span class="criaw-pill">' . esc_html($human_takeover ? __('Human Takeover Active', 'carbon-repro-widget') : __('Bot Handling', 'carbon-repro-widget')) . '</span>';
        echo '</div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 16px;">';
        wp_nonce_field('criaw_toggle_takeover');
        echo '<input type="hidden" name="action" value="criaw_toggle_takeover" />';
        echo '<input type="hidden" name="conversation_id" value="' . esc_attr((string) $conversation_id) . '" />';
        echo '<label><input type="checkbox" name="takeover_active" value="1" ' . checked($human_takeover, true, false) . ' /> ' . esc_html__('Enable human takeover for this conversation', 'carbon-repro-widget') . '</label> ';
        echo '<button class="button" type="submit">' . esc_html__('Save', 'carbon-repro-widget') . '</button>';
        echo '</form>';
        echo '<table class="widefat striped" style="margin-bottom:24px;">';
        echo '<tbody>';
        echo '<tr><th style="width:180px;">' . esc_html__('Name', 'carbon-repro-widget') . '</th><td>' . esc_html($lead['name']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Email', 'carbon-repro-widget') . '</th><td>' . esc_html($lead['email']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Phone', 'carbon-repro-widget') . '</th><td>' . esc_html($lead['phone']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Looking For', 'carbon-repro-widget') . '</th><td>' . esc_html($lead['looking_for']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Email Updates Consent', 'carbon-repro-widget') . '</th><td>' . esc_html((isset($meta['email_updates_opt_in']) && $meta['email_updates_opt_in'] === '1') ? __('Yes', 'carbon-repro-widget') : __('No', 'carbon-repro-widget')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('SMS Consent', 'carbon-repro-widget') . '</th><td>' . esc_html((isset($meta['sms_updates_opt_in']) && $meta['sms_updates_opt_in'] === '1') ? __('Yes', 'carbon-repro-widget') : __('No', 'carbon-repro-widget')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('WhatsApp Consent', 'carbon-repro-widget') . '</th><td>' . esc_html((isset($meta['whatsapp_updates_opt_in']) && $meta['whatsapp_updates_opt_in'] === '1') ? __('Yes', 'carbon-repro-widget') : __('No', 'carbon-repro-widget')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Page', 'carbon-repro-widget') . '</th><td>' . esc_html(isset($meta['page_path']) ? $meta['page_path'] : '') . '</td></tr>';
        echo '<tr><th>' . esc_html__('Source', 'carbon-repro-widget') . '</th><td>' . esc_html(isset($meta['source_group']) ? $meta['source_group'] : '') . '</td></tr>';
        echo '<tr><th>' . esc_html__('Device', 'carbon-repro-widget') . '</th><td>' . esc_html(isset($meta['device_type']) ? $meta['device_type'] : '') . '</td></tr>';
        echo '<tr><th>' . esc_html__('Country', 'carbon-repro-widget') . '</th><td>' . esc_html(isset($meta['country_name']) ? $meta['country_name'] : '') . '</td></tr>';
        echo '</tbody></table>';

        echo '<div style="background:#fff; border:1px solid #dbe7e3; border-radius:16px; padding:18px;">';
        if (empty($transcript)) {
            echo '<p>' . esc_html__('No transcript available.', 'carbon-repro-widget') . '</p>';
        } else {
            foreach ($transcript as $entry) {
                $role = $entry['role'] === 'assistant' ? (isset($entry['sender']) && $entry['sender'] === 'human' ? __('Human Agent', 'carbon-repro-widget') : __('Assistant', 'carbon-repro-widget')) : __('Visitor', 'carbon-repro-widget');
                echo '<div style="margin-bottom:16px;">';
                echo '<strong>' . esc_html($role) . ':</strong><br />';
                echo '<div>' . nl2br(esc_html($entry['content'])) . '</div>';
                echo '<div style="font-size:12px;color:#667085;margin-top:4px;">' . esc_html($entry['time']) . '</div>';
                echo '</div>';
            }
        }
        echo '</div>';

        $timeline = get_post_meta($conversation_id, '_criaw_notification_timeline', true);
        $timeline = is_array($timeline) ? array_reverse($timeline) : array();
        echo '<h3 style="margin-top:24px;">' . esc_html__('Notification Timeline', 'carbon-repro-widget') . '</h3>';
        echo '<div style="background:#fff; border:1px solid #dbe7e3; border-radius:16px; padding:18px;">';
        if (empty($timeline)) {
            echo '<p>' . esc_html__('No notification events recorded yet.', 'carbon-repro-widget') . '</p>';
        } else {
            foreach ($timeline as $entry) {
                $label = strtoupper((string) (isset($entry['channel']) ? $entry['channel'] : 'log')) . ' / ' . ucfirst((string) (isset($entry['audience']) ? $entry['audience'] : 'system'));
                echo '<div style="padding:12px 0;border-bottom:1px solid #edf3f0;">';
                echo '<strong>' . esc_html($label) . '</strong> ';
                echo '<span class="criaw-pill">' . esc_html(isset($entry['status']) ? ucfirst((string) $entry['status']) : __('Unknown', 'carbon-repro-widget')) . '</span>';
                echo '<div style="margin-top:6px;color:#344054;">' . esc_html(isset($entry['context']) ? str_replace('_', ' ', (string) $entry['context']) : '') . '</div>';
                if (! empty($entry['detail'])) {
                    echo '<div style="margin-top:6px;color:#667085;">' . esc_html($entry['detail']) . '</div>';
                }
                echo '<div style="font-size:12px;color:#98a2b3;margin-top:6px;">' . esc_html(isset($entry['time']) ? $entry['time'] : '') . '</div>';
                echo '</div>';
            }
        }
        echo '</div>';

        echo '<h3 style="margin-top:24px;">' . esc_html__('Reply as Human', 'carbon-repro-widget') . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('criaw_human_reply');
        echo '<input type="hidden" name="action" value="criaw_send_human_reply" />';
        echo '<input type="hidden" name="conversation_id" value="' . esc_attr((string) $conversation_id) . '" />';
        echo '<textarea name="human_reply" rows="5" class="large-text" placeholder="' . esc_attr__('Write your reply to the visitor...', 'carbon-repro-widget') . '"></textarea>';
        echo '<p><input type="text" name="human_reply_image" class="regular-text criaw-logo-url" placeholder="' . esc_attr__('Optional image URL', 'carbon-repro-widget') . '" /> <button type="button" class="button criaw-upload-logo">' . esc_html__('Upload Image', 'carbon-repro-widget') . '</button></p>';
        echo '<p><button class="button button-primary" type="submit">' . esc_html__('Send Human Reply', 'carbon-repro-widget') . '</button></p>';
        echo '</form>';
    }

    private function crawl_site_content()
    {
        $urls = array(home_url('/'));
        $focus_paths = preg_split('/\r\n|\r|\n/', $this->get_setting('crawl_focus_paths', ''));

        foreach ($focus_paths as $path) {
            $path = trim($path);
            if ($path !== '') {
                $urls[] = home_url($path);
            }
        }

        $homepage_links = $this->extract_priority_internal_links(home_url('/'));
        $urls = array_unique(array_merge($urls, $homepage_links));
        $urls = array_slice($urls, 0, 10);

        $chunks = array();
        $pages = array();
        foreach ($urls as $url) {
            $html = $this->fetch_local_url($url);
            if ($html === '') {
                continue;
            }

            $text = $this->extract_text_from_html($html);
            if ($text !== '') {
                $title = $this->extract_title_from_html($html);
                $summary = wp_trim_words($text, 220, '...');
                $chunks[] = $url . "\n" . $summary;
                $pages[] = array(
                    'url' => $url,
                    'title' => $title,
                    'summary' => $summary,
                );
            }
        }

        return array(
            'summary' => implode("\n\n---\n\n", $chunks),
            'pages' => $pages,
        );
    }

    private function build_woocommerce_catalog_index()
    {
        if (! $this->is_woocommerce_active()) {
            return array(
                'summary' => '',
                'products' => array(),
                'categories' => array(),
            );
        }

        $product_posts = get_posts(
            array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'numberposts' => -1,
                'orderby' => 'date',
                'order' => 'DESC',
            )
        );

        $products = array();
        foreach ($product_posts as $post) {
            $product = function_exists('wc_get_product') ? wc_get_product($post->ID) : null;
            if (! $product) {
                continue;
            }

            $products[] = array(
                'id' => $post->ID,
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'price' => wp_strip_all_tags($product->get_price_html()),
                'url' => get_permalink($post->ID),
                'categories' => wp_get_post_terms($post->ID, 'product_cat', array('fields' => 'names')),
                'short_description' => wp_trim_words(wp_strip_all_tags($product->get_short_description(), true), 30, '...'),
                'image' => get_the_post_thumbnail_url($post->ID, 'medium'),
                'attributes' => $this->extract_product_attributes($product),
                'searchable_text' => $this->build_product_searchable_text($product),
            );
        }

        $terms = get_terms(
            array(
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
            )
        );
        $categories = array();
        if (! is_wp_error($terms)) {
            foreach ($terms as $term) {
                $categories[] = array(
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'parent' => (int) $term->parent,
                    'url' => get_term_link($term),
                    'description' => wp_trim_words(wp_strip_all_tags($term->description, true), 24, '...'),
                );
            }
        }

        return array(
            'summary' => $this->build_catalog_summary($products, $categories),
            'products' => $products,
            'categories' => $categories,
            'stored_product_count' => count($products),
            'stored_category_count' => count($categories),
            'catalog_product_count' => count($products),
            'catalog_category_count' => count($categories),
        );
    }

    private function get_live_catalog_products($query, $price_filter, $categories, $brand_filters = array(), $limit = 80)
    {
        if (! $this->is_woocommerce_active()) {
            return array();
        }

        $intent_query = $this->extract_catalog_intent_query($query);
        $tokens = $this->get_catalog_significant_tokens($intent_query);
        $tokens = array_values(array_filter($tokens, function ($token) {
            return ! preg_match('/^\d+$/', (string) $token);
        }));
        $search_phrase = trim(implode(' ', array_slice($tokens, 0, 3)));
        $sku_tokens = array_values(array_filter($this->get_catalog_significant_tokens($query), function ($token) {
            return (bool) preg_match('/[a-z]{1,4}\d{5,}|\d{5,}/i', (string) $token);
        }));

        $meta_query = array('relation' => 'AND');
        if (is_array($price_filter) && ! empty($price_filter['has_price_filter'])) {
            if (isset($price_filter['min_price']) && $price_filter['min_price'] !== null && isset($price_filter['max_price']) && $price_filter['max_price'] !== null) {
                $meta_query[] = array(
                    'key' => '_price',
                    'value' => array((float) $price_filter['min_price'], (float) $price_filter['max_price']),
                    'compare' => 'BETWEEN',
                    'type' => 'NUMERIC',
                );
            } elseif (isset($price_filter['max_price']) && $price_filter['max_price'] !== null) {
                $meta_query[] = array(
                    'key' => '_price',
                    'value' => (float) $price_filter['max_price'],
                    'compare' => '<=',
                    'type' => 'NUMERIC',
                );
            } elseif (isset($price_filter['min_price']) && $price_filter['min_price'] !== null) {
                $meta_query[] = array(
                    'key' => '_price',
                    'value' => (float) $price_filter['min_price'],
                    'compare' => '>=',
                    'type' => 'NUMERIC',
                );
            }
        }

        if (! empty($sku_tokens)) {
            $sku_meta = array('relation' => 'OR');
            foreach ($sku_tokens as $sku_token) {
                $sku_meta[] = array('key' => '_sku', 'value' => (string) $sku_token, 'compare' => '=');
                $sku_meta[] = array('key' => '_sku', 'value' => (string) $sku_token, 'compare' => 'LIKE');
            }
            $meta_query[] = $sku_meta;
        }

        $tax_query = array();
        if (! empty($brand_filters) && is_array($categories)) {
            $brand_slugs = array();
            foreach ($categories as $category) {
                $name = trim((string) (isset($category['name']) ? $category['name'] : ''));
                $slug = trim((string) (isset($category['slug']) ? $category['slug'] : ''));
                foreach ((array) $brand_filters as $brand) {
                    if ($brand !== '' && strcasecmp($name, (string) $brand) === 0 && $slug !== '') {
                        $brand_slugs[] = $slug;
                    }
                }
            }
            $brand_slugs = array_values(array_unique($brand_slugs));
            if (! empty($brand_slugs)) {
                $tax_query[] = array(
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => $brand_slugs,
                    'operator' => 'IN',
                );
            }
        } elseif (! empty($tokens) && is_array($categories)) {
            $matched_slugs = array();
            foreach ($categories as $category) {
                $name = isset($category['name']) ? strtolower((string) $category['name']) : '';
                $slug = isset($category['slug']) ? strtolower((string) $category['slug']) : '';
                foreach ($tokens as $token) {
                    if ($token !== '' && ($token === $name || $token === $slug || strpos($name, $token) !== false || strpos($slug, $token) !== false)) {
                        if ($slug !== '') {
                            $matched_slugs[] = $slug;
                        }
                        break;
                    }
                }
            }
            $matched_slugs = array_values(array_unique($matched_slugs));
            if (! empty($matched_slugs)) {
                $tax_query[] = array(
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => $matched_slugs,
                    'operator' => 'IN',
                );
            }
        }

        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'numberposts' => max(10, absint($limit)),
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => false,
        );
        if (count($meta_query) > 1) {
            $args['meta_query'] = $meta_query;
        }
        if (! empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }
        if ($search_phrase !== '') {
            $args['s'] = $search_phrase;
        }

        $posts = get_posts($args);
        if (empty($posts) && isset($args['s'])) {
            unset($args['s']);
            $posts = get_posts($args);
        }

        $products = array();
        foreach ((array) $posts as $post) {
            $product = function_exists('wc_get_product') ? wc_get_product($post->ID) : null;
            if (! $product) {
                continue;
            }
            $products[] = array(
                'id' => $post->ID,
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'price' => wp_strip_all_tags($product->get_price_html()),
                'url' => get_permalink($post->ID),
                'categories' => wp_get_post_terms($post->ID, 'product_cat', array('fields' => 'names')),
                'short_description' => wp_trim_words(wp_strip_all_tags($product->get_short_description(), true), 30, '...'),
                'image' => get_the_post_thumbnail_url($post->ID, 'medium'),
                'attributes' => $this->extract_product_attributes($product),
                'searchable_text' => $this->build_product_searchable_text($product),
            );
        }

        return $products;
    }

    private function build_catalog_cards_for_urls($urls)
    {
        $index = get_option(self::CATALOG_INDEX_OPTION_KEY, array());
        $index = is_array($index) ? $index : array();
        $products = isset($index['products']) && is_array($index['products']) ? $index['products'] : array();
        $categories = isset($index['categories']) && is_array($index['categories']) ? $index['categories'] : array();
        $cards = array();

        foreach ($urls as $url) {
            $url = $this->normalize_catalog_match_url($url);
            if ($url === '') {
                continue;
            }

            foreach ($products as $product) {
                if (isset($product['url']) && $this->normalize_catalog_match_url($product['url']) === $url) {
                    $cards[] = array(
                        'type' => 'product',
                        'id' => isset($product['id']) ? (int) $product['id'] : 0,
                        'url' => $url,
                        'title' => isset($product['name']) ? $product['name'] : '',
                        'price' => isset($product['price']) ? $product['price'] : '',
                        'category' => ! empty($product['categories']) ? implode(', ', (array) $product['categories']) : '',
                        'description' => isset($product['short_description']) ? $product['short_description'] : '',
                        'image' => isset($product['image']) ? $product['image'] : '',
                    );
                    continue 2;
                }
            }

            foreach ($categories as $category) {
                if (isset($category['url']) && $this->normalize_catalog_match_url($category['url']) === $url) {
                    $cards[] = array(
                        'type' => 'category',
                        'url' => $url,
                        'title' => isset($category['name']) ? $category['name'] : '',
                        'price' => '',
                        'category' => __('Category', 'carbon-repro-widget'),
                        'image' => '',
                    );
                    continue 2;
                }
            }
        }

        return $cards;
    }

    private function is_woocommerce_active()
    {
        return class_exists('WooCommerce') || function_exists('wc_get_product');
    }

    private function is_ecommerce_mode()
    {
        return $this->get_setting('assistant_mode', 'ecommerce') === 'ecommerce' && $this->is_woocommerce_active();
    }

    private function extract_priority_internal_links($url)
    {
        $html = $this->fetch_local_url($url);
        if ($html === '') {
            return array();
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//a[@href]');
        $links = array();

        foreach ($nodes as $node) {
            $href = $node->getAttribute('href');
            if ($href === '' || strpos($href, '#') === 0) {
                continue;
            }

            $absolute = $this->normalize_internal_url($href);
            if ($absolute === '') {
                continue;
            }

            if (preg_match('/services|products|solutions|faq|about|security|doors|locks|vehicles|equipment/i', $absolute)) {
                $links[] = $absolute;
            }
        }

        return array_values(array_unique(array_slice($links, 0, 8)));
    }

    private function fetch_local_url($url)
    {
        $response = wp_remote_get($url, array('timeout' => 12));
        if (is_wp_error($response)) {
            return '';
        }

        if ((int) wp_remote_retrieve_response_code($response) >= 300) {
            return '';
        }

        return (string) wp_remote_retrieve_body($response);
    }

    private function extract_text_from_html($html)
    {
        if ($html === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//noscript') as $node) {
            $node->parentNode->removeChild($node);
        }

        $text = wp_strip_all_tags($dom->textContent, true);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim((string) $text);
    }

    private function extract_title_from_html($html)
    {
        if ($html === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();
        $titles = $dom->getElementsByTagName('title');
        if ($titles->length < 1) {
            return '';
        }

        return trim((string) $titles->item(0)->textContent);
    }

    private function normalize_internal_url($href)
    {
        $home = home_url('/');
        $home_host = wp_parse_url($home, PHP_URL_HOST);

        if (strpos($href, '//') === 0) {
            $href = (is_ssl() ? 'https:' : 'http:') . $href;
        }

        if (preg_match('#^https?://#i', $href)) {
            $host = wp_parse_url($href, PHP_URL_HOST);
            return $host === $home_host ? $href : '';
        }

        if (strpos($href, '/') === 0) {
            return home_url($href);
        }

        return home_url('/' . ltrim($href, '/'));
    }

    private function extract_wpforms_field_value($fields, $keywords)
    {
        if (! is_array($fields)) {
            return '';
        }

        foreach ($fields as $field) {
            $name = isset($field['name']) ? strtolower((string) $field['name']) : '';
            foreach ($keywords as $keyword) {
                if ($name !== '' && strpos($name, strtolower($keyword)) !== false) {
                    if (isset($field['value'])) {
                        return sanitize_text_field((string) $field['value']);
                    }
                }
            }
        }

        return '';
    }

    private function request_openai_reply($transcript, $lead)
    {
        $api_key = $this->get_setting('openai_api_key', '');
        if ($api_key === '') {
            return new WP_Error('criaw_missing_api_key', __('Please add an OpenAI API key in the plugin settings.', 'carbon-repro-widget'));
        }

        $input = array(
            array(
                'role' => 'developer',
                'content' => $this->build_chatbot_prompt($lead, $this->get_latest_user_message($transcript)),
            ),
        );

        foreach ($transcript as $entry) {
            $input[] = array(
                'role' => $entry['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => (string) $entry['content'],
            );
        }

        $response = wp_remote_post(
            'https://api.openai.com/v1/responses',
            array(
                'timeout' => 45,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode(
                    array(
                        'model' => $this->get_setting('chatbot_model', 'gpt-4o-mini'),
                        'input' => $input,
                        'text' => array(
                            'format' => array(
                                'type' => 'json_schema',
                                'name' => 'widget_chat_response',
                                'strict' => true,
                                'schema' => array(
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'properties' => array(
                                        'reply' => array('type' => 'string'),
                                        'lead' => array(
                                            'type' => 'object',
                                            'additionalProperties' => false,
                                            'properties' => array(
                                                'name' => array('type' => 'string'),
                                                'email' => array('type' => 'string'),
                                                'phone' => array('type' => 'string'),
                                                'looking_for' => array('type' => 'string'),
                                            ),
                                            'required' => array('name', 'email', 'phone', 'looking_for'),
                                        ),
                                    ),
                                    'required' => array('reply', 'lead'),
                                ),
                            ),
                        ),
                    )
                ),
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('criaw_openai_request_failed', __('The AI service could not be reached.', 'carbon-repro-widget'));
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($status_code < 200 || $status_code >= 300) {
            $message = is_array($decoded) && isset($decoded['error']['message']) ? $decoded['error']['message'] : __('The AI service returned an error.', 'carbon-repro-widget');
            return new WP_Error('criaw_openai_bad_status', $message);
        }

        $json_text = $this->extract_response_text($decoded);
        if ($json_text === '') {
            return new WP_Error('criaw_openai_empty_reply', __('The AI service returned an empty response.', 'carbon-repro-widget'));
        }

        $parsed = json_decode($json_text, true);
        if (! is_array($parsed) || ! isset($parsed['reply']) || ! isset($parsed['lead']) || ! is_array($parsed['lead'])) {
            return new WP_Error('criaw_openai_invalid_json', __('The AI response was not in the expected format.', 'carbon-repro-widget'));
        }

        return array(
            'reply' => sanitize_textarea_field($parsed['reply']),
            'lead' => array(
                'name' => sanitize_text_field(isset($parsed['lead']['name']) ? $parsed['lead']['name'] : ''),
                'email' => sanitize_email(isset($parsed['lead']['email']) ? $parsed['lead']['email'] : ''),
                'phone' => sanitize_text_field(isset($parsed['lead']['phone']) ? $parsed['lead']['phone'] : ''),
                'looking_for' => sanitize_textarea_field(isset($parsed['lead']['looking_for']) ? $parsed['lead']['looking_for'] : ''),
            ),
        );
    }

    private function build_chatbot_prompt($lead, $latest_message = '')
    {
        $services = trim($this->get_setting('business_services', ''));
        $faqs = trim($this->get_setting('business_faqs', ''));
        $terminology = trim($this->get_setting('business_terminology_notes', ''));
        $qualification = trim($this->get_setting('qualification_questions', ''));
        $crawled_knowledge = trim($this->get_setting('crawled_knowledge', ''));
        $catalog_knowledge = trim($this->get_setting('catalog_index_knowledge', ''));
        $custom = trim($this->get_setting('chatbot_prompt', ''));
        $catalog_context = $this->get_catalog_context_for_query($latest_message);
        $catalog_suggestions = $this->get_catalog_suggestions_for_query($latest_message);
        $bot_contact = $this->get_bot_contact_details();

        return implode(
            "\n\n",
            array(
                'You are the lead intake assistant for this business website.',
                'Business name: ' . $this->get_setting('business_name', 'This business'),
                'Industry: ' . $this->get_setting('business_industry', 'General service business'),
                'Location: ' . $this->get_setting('business_location', ''),
                'Preferred contact details the chatbot should use when sharing store information: phone=' . $bot_contact['phone'] . '; email=' . $bot_contact['email'] . '; location=' . $bot_contact['location'] . '; map=' . (isset($bot_contact['location_url']) ? $bot_contact['location_url'] : ''),
                'Services: ' . ($services !== '' ? $services : 'Not provided'),
                'FAQs / facts: ' . ($faqs !== '' ? $faqs : 'Not provided'),
                'Crawled website knowledge summary: ' . ($crawled_knowledge !== '' ? $crawled_knowledge : 'Not provided'),
                'Indexed ecommerce catalog summary: ' . ($catalog_knowledge !== '' ? $catalog_knowledge : 'Not provided'),
                'Current visitor question intent type: ' . (isset($catalog_suggestions['intent_type']) ? $catalog_suggestions['intent_type'] : 'unknown'),
                'Live catalog matches for the current visitor question: ' . ($catalog_context !== '' ? $catalog_context : 'No direct product or category matches found for this question.'),
                'Critical terminology / disambiguation notes: ' . ($terminology !== '' ? $terminology : 'Not provided'),
                'Required qualification questions, asked in order when still relevant: ' . ($qualification !== '' ? $qualification : 'Not provided'),
                'Your goal is to help visitors with basic questions and collect lead details.',
                'Always try to collect, if missing: full name, email, phone number, and what service or outcome they are looking for.',
                'If the visitor asks about services, answer based only on the provided services and FAQs.',
                'If the current question intent type is service_info, support, repair, feature, warranty, delivery, or policy related, do not answer with product listings. Answer from services, FAQs, and crawled website knowledge instead.',
                'If the website is an ecommerce store and a product or category match exists, include the exact product or category link in the reply.',
                'If multiple matching products exist within a family or collection, mention the closest matching category or collection link instead of giving a vague answer.',
                'If the visitor mentions a SKU, reference number, model, product name, or collection name, prefer the live catalog matches over generic website crawl information.',
                'Use matched product attributes when answering product-specific questions.',
                'When multiple structured matches exist, do not write a long numbered list of products in the message. Keep the reply short and let the interface show the product cards and links.',
                'When the query is generic, such as only a brand or broad collection, confirm availability briefly and ask the visitor to narrow down the model.',
                'If information is missing, say the team will confirm details.',
                'Do not make naive assumptions about ambiguous words. Use the terminology notes and business context before answering.',
                'Prioritize the qualification questions when the visitor has not yet supplied that information.',
                'Ask for only one or two missing lead fields at a time.',
                'Be business-specific. If the business is dental, speak naturally about dental services. If it is legal, speak naturally about legal services, and so on.',
                'Return JSON only with keys: reply and lead.',
                'Lead object keys must be: name, email, phone, looking_for.',
                'Use empty strings for unknown values.',
                'Current collected lead data: name=' . (isset($lead['name']) ? $lead['name'] : '') . '; email=' . (isset($lead['email']) ? $lead['email'] : '') . '; phone=' . (isset($lead['phone']) ? $lead['phone'] : '') . '; looking_for=' . (isset($lead['looking_for']) ? $lead['looking_for'] : ''),
                $custom !== '' ? 'Additional instructions: ' . $custom : '',
            )
        );
    }

    private function get_bot_contact_details()
    {
        return array(
            'phone' => $this->get_setting('store_contact_phone', $this->get_setting('phone_number', '')),
            'email' => $this->get_setting('store_contact_email', ''),
            'location' => $this->get_setting('store_contact_location', $this->get_setting('business_location', '')),
            'location_url' => $this->get_setting('store_contact_location_url', ''),
        );
    }

    private function get_chat_message_limit()
    {
        $configured = absint($this->get_setting('chat_message_limit', 0));

        // 0 = disabled (recommended for AI-first flow).
        if ($configured <= 0) {
            return 0;
        }

        // Backward-compat: old default (7) was too aggressive and hurt UX.
        if ($configured === 7) {
            return 30;
        }

        return $configured;
    }

    private function count_user_messages($transcript)
    {
        if (! is_array($transcript)) {
            return 0;
        }

        $count = 0;
        foreach ($transcript as $message) {
            if (isset($message['role']) && $message['role'] === 'user' && ! empty($message['content'])) {
                $count++;
            }
        }

        return $count;
    }

    private function get_chat_limit_reply($lead)
    {
        $custom = trim((string) $this->get_setting('chat_limit_message', ''));
        if ($custom !== '') {
            return $custom;
        }

        $contact = $this->get_bot_contact_details();
        $parts = array(
            __('Thanks for chatting with us. To keep things moving, one of our team members will follow up with you shortly.', 'carbon-repro-widget'),
            __('You can also contact the store directly using the details below:', 'carbon-repro-widget'),
        );

        if (! empty($contact['phone'])) {
            $parts[] = sprintf(__('Phone: %s', 'carbon-repro-widget'), $contact['phone']);
        }
        if (! empty($contact['email'])) {
            $parts[] = sprintf(__('Email: %s', 'carbon-repro-widget'), $contact['email']);
        }
        if (! empty($contact['location'])) {
            $parts[] = sprintf(__('Location: %s', 'carbon-repro-widget'), $contact['location']);
        }
        if (! empty($contact['location_url'])) {
            $parts[] = sprintf(__('Map: %s', 'carbon-repro-widget'), $contact['location_url']);
        }
        if (! empty($lead['name'])) {
            $parts[] = sprintf(__('Thanks again, %s. Our team has your details.', 'carbon-repro-widget'), $lead['name']);
        }

        return implode("\n", $parts);
    }

    private function get_contact_actions_meta($query = '', $force = false)
    {
        $contact = $this->get_bot_contact_details();
        $actions = array();
        $query = strtolower(trim((string) $query));
        $looks_contact_related = $force || (bool) preg_match('/\b(call|phone|email|contact|location|address|direction|directions|visit|get in touch|repair|service)\b/', $query);

        if (! $looks_contact_related) {
            return array();
        }

        if (! empty($contact['phone'])) {
            $actions[] = array(
                'type' => 'call',
                'label' => __('Call Store', 'carbon-repro-widget'),
                'url' => 'tel:' . $contact['phone'],
            );
        }

        if (! empty($contact['email'])) {
            $actions[] = array(
                'type' => 'email',
                'label' => __('Email Store', 'carbon-repro-widget'),
                'url' => 'mailto:' . $contact['email'],
            );
        }

        if (! empty($contact['location_url'])) {
            $actions[] = array(
                'type' => 'directions',
                'label' => __('Get Directions', 'carbon-repro-widget'),
                'url' => $contact['location_url'],
            );
        }

        return $actions;
    }

    private function clean_reply_contact_block($reply)
    {
        $reply = preg_replace('/(?:^|\n)\s*[-*]\s*\*{0,2}(phone|email|location)\*{0,2}\s*:\s*[^\n]+/i', '', (string) $reply);
        $reply = preg_replace('/(?:^|\n)\s*[-*]\s*\*{0,2}(map|directions?)\*{0,2}\s*:\s*[^\n]+/i', '', (string) $reply);
        $reply = preg_replace('/(?:^|\n)\s*(phone|email|location|map|directions?)\s*:\s*[^\n]+/i', '', (string) $reply);
        $reply = preg_replace('/https?:\/\/[^\s<>"\']+/i', '', (string) $reply);
        $reply = preg_replace('/\bthanks again,[^\n]+/i', '', (string) $reply);
        $reply = preg_replace('/\byou can also contact the store directly using the details below:\s*/i', '', (string) $reply);
        $reply = preg_replace('/\n{3,}/', "\n\n", (string) $reply);

        return trim((string) $reply);
    }

    private function build_assistant_meta($catalog_suggestions, $query = '', $force_contact = false)
    {
        return array(
            'catalog_cards' => isset($catalog_suggestions['cards']) ? $catalog_suggestions['cards'] : array(),
            'catalog_links' => isset($catalog_suggestions['links']) ? $catalog_suggestions['links'] : array(),
            'contact_actions' => $this->get_contact_actions_meta($query, $force_contact),
        );
    }

    private function classify_user_intent($message, $catalog_suggestions = array())
    {
        $text = strtolower(trim((string) $message));
        if ($text === '') {
            return 'UNKNOWN';
        }

        if (preg_match('/^(hi|hello|hey|salam|assalam o alaikum|good morning|good afternoon|good evening)\b[!. ]*$/i', $text)) {
            return 'GREETING';
        }

        if (preg_match('/\b(thanks|thank you|thx|ok thanks|great thanks|appreciate it)\b/i', $text)) {
            return 'GRATITUDE';
        }

         if (preg_match('/\b(human|real agent|live agent|representative|support team|talk to (a )?person|connect me)\b/i', $text)) {
            return 'HUMAN_AGENT_REQUEST';
        }

        if (preg_match('/\b(i am angry|i\'m angry|angry|frustrated|are you crazy|you are dumb|stupid|not helpful|you don\'t understand)\b/i', $text)) {
            return 'CONFUSION';
        }

        if (preg_match('/\b([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})\b/i', $text) || preg_match('/\+?\d[\d\-\s\(\)]{7,}\d/', $text)) {
            return 'PROVIDE_CONTACT_INFO';
        }

        if (preg_match('/\b(where are you|location|address|directions|contact|phone|email|hours|timing|open|close|about your company|company background|about us|authentic|fake|genuine|trust)\b/i', $text)) {
            return 'BUSINESS_INFO';
        }

        if (preg_match('/\b(show brands|what brands|which brands|show categories|collections)\b/i', $text)) {
            return 'BRAND_REQUEST';
        }

        if (preg_match('/\b(tell me about|details|description|specs|specifications|about this watch)\b/i', $text)) {
            return 'PRODUCT_DETAILS';
        }

        if (preg_match('/\b(under|below|between|to|above|over|budget|price range|less than|more than)\b/i', $text)) {
            return 'PRODUCT_FILTER';
        }

        if (preg_match('/\b(rolex|cartier|omega|patek|audemars|breitling|iwc|hublot|panerai|datejust|submariner|daytona|sku|reference|ref|model|buy|looking for|show me)\b/i', $text)) {
            return 'PRODUCT_SEARCH';
        }

        if (is_array($catalog_suggestions) && (! empty($catalog_suggestions['cards']) || ! empty($catalog_suggestions['links']))) {
            return 'PRODUCT_SEARCH';
        }

        return 'UNKNOWN';
    }

    private function get_chat_context($conversation_id)
    {
        $context = get_post_meta($conversation_id, '_criaw_chat_context', true);
        return is_array($context) ? $context : array(
            'last_intent' => '',
            'last_query' => '',
            'last_catalog_cards' => array(),
            'last_brand' => '',
            'last_price_range' => array('min_price' => null, 'max_price' => null),
            'conversation_stage' => 'normal',
        );
    }

    private function set_chat_context($conversation_id, $intent, $query, $catalog_cards, $extra = array())
    {
        $current = $this->get_chat_context($conversation_id);
        $merged = array_merge($current, is_array($extra) ? $extra : array());

        update_post_meta($conversation_id, '_criaw_chat_context', array(
            'last_intent' => (string) $intent,
            'last_query' => (string) $query,
            'last_catalog_cards' => is_array($catalog_cards) ? array_values($catalog_cards) : array(),
            'last_brand' => isset($merged['last_brand']) ? (string) $merged['last_brand'] : '',
            'last_price_range' => isset($merged['last_price_range']) && is_array($merged['last_price_range']) ? $merged['last_price_range'] : array('min_price' => null, 'max_price' => null),
            'conversation_stage' => isset($merged['conversation_stage']) ? (string) $merged['conversation_stage'] : 'normal',
        ));
    }

    private function extract_contact_details_from_text($text)
    {
        $text = trim((string) $text);
        $details = array('name' => '', 'email' => '', 'phone' => '');
        if ($text === '') {
            return $details;
        }

        if (preg_match('/([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/i', $text, $m)) {
            $details['email'] = sanitize_email($m[1]);
        }
        if (preg_match('/(\+?\d[\d\-\s\(\)]{7,}\d)/', $text, $m)) {
            $details['phone'] = preg_replace('/[^0-9+]/', '', $m[1]);
        }
        if (preg_match('/\b(?:my name is|i am|this is)\s+([a-z][a-z\s\'.-]{1,80})$/i', $text, $m)) {
            $details['name'] = sanitize_text_field(trim($m[1]));
        }

        return $details;
    }

    private function extract_message_entities($message, $categories, $products)
    {
        $price = $this->parse_price_constraints($message);
        $brands = $this->extract_brand_filters($message, $categories, $products);

        return array(
            'brand' => ! empty($brands) ? (string) $brands[0] : '',
            'brands' => $brands,
            'brand_explicit' => ! empty($brands),
            'min_price' => isset($price['min_price']) ? $price['min_price'] : null,
            'max_price' => isset($price['max_price']) ? $price['max_price'] : null,
            'has_price_filter' => ! empty($price['has_price_filter']),
            'price_explicit' => ! empty($price['has_price_filter']),
        );
    }

    private function is_followup_product_filter_message($message)
    {
        $text = strtolower(trim((string) $message));
        if ($text === '') {
            return false;
        }

        return (bool) preg_match('/\b(cheaper|lower|budget|under|below|within budget|show more|more options|similar|these|those|that one|same brand|same)\b/i', $text);
    }

    private function merge_entities_with_context($entities, $context, $intent, $message)
    {
        $entities = is_array($entities) ? $entities : array();
        $context = is_array($context) ? $context : array();
        $merged = $entities;
        $intent = strtoupper((string) $intent);
        $followup = $this->is_followup_product_filter_message($message);
        $is_product_intent = in_array($intent, array('PRODUCT_SEARCH', 'PRODUCT_FILTER', 'PRODUCT_DETAILS'), true);

        if (empty($merged['brand']) && ! empty($context['last_brand']) && ($is_product_intent || $followup)) {
            $merged['brand'] = (string) $context['last_brand'];
            $merged['brands'] = array($merged['brand']);
            $merged['brand_explicit'] = false;
        }

        if (empty($merged['has_price_filter']) && ! empty($context['last_price_range']) && is_array($context['last_price_range']) && ($intent === 'PRODUCT_FILTER' || $followup)) {
            $merged['min_price'] = isset($context['last_price_range']['min_price']) ? $context['last_price_range']['min_price'] : null;
            $merged['max_price'] = isset($context['last_price_range']['max_price']) ? $context['last_price_range']['max_price'] : null;
            $merged['has_price_filter'] = ($merged['min_price'] !== null || $merged['max_price'] !== null);
            $merged['price_explicit'] = false;
        }

        return $merged;
    }

    private function build_constrained_search_query($message, $entities)
    {
        $parts = array(trim((string) $message));
        if (! empty($entities['brand'])) {
            $parts[] = (string) $entities['brand'];
        }
        if (! empty($entities['has_price_filter'])) {
            if ($entities['min_price'] !== null && $entities['max_price'] !== null) {
                $parts[] = 'between ' . (int) $entities['min_price'] . ' and ' . (int) $entities['max_price'];
            } elseif ($entities['max_price'] !== null) {
                $parts[] = 'under ' . (int) $entities['max_price'];
            } elseif ($entities['min_price'] !== null) {
                $parts[] = 'above ' . (int) $entities['min_price'];
            }
        }

        return trim(implode(' ', array_filter($parts)));
    }

    private function parse_price_value($price_html)
    {
        $raw = wp_strip_all_tags((string) $price_html);
        if (! preg_match('/[\d\.,]+/', $raw, $match)) {
            return null;
        }
        $normalized = str_replace(',', '', $match[0]);
        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function refine_cards_from_context($message, $context_cards)
    {
        $text = strtolower((string) $message);
        $cards = is_array($context_cards) ? $context_cards : array();
        if (empty($cards)) {
            return array();
        }

        if (preg_match('/\b(cheaper|lower|budget|affordable|less expensive)\b/i', $text)) {
            usort($cards, function ($a, $b) {
                $pa = $this->parse_price_value(isset($a['price']) ? $a['price'] : '');
                $pb = $this->parse_price_value(isset($b['price']) ? $b['price'] : '');
                if ($pa === null && $pb === null) { return 0; }
                if ($pa === null) { return 1; }
                if ($pb === null) { return -1; }
                return $pa <=> $pb;
            });
            return array_slice($cards, 0, 4);
        }

        if (preg_match('/\b(more expensive|premium|higher end|luxury)\b/i', $text)) {
            usort($cards, function ($a, $b) {
                $pa = $this->parse_price_value(isset($a['price']) ? $a['price'] : '');
                $pb = $this->parse_price_value(isset($b['price']) ? $b['price'] : '');
                if ($pa === null && $pb === null) { return 0; }
                if ($pa === null) { return 1; }
                if ($pb === null) { return -1; }
                return $pb <=> $pa;
            });
            return array_slice($cards, 0, 4);
        }

        return array();
    }

    private function get_brand_browse_suggestions()
    {
        if (! $this->is_ecommerce_mode()) {
            return array(
                'cards' => array(),
                'links' => array(),
                'has_multiple_products' => false,
                'is_generic_query' => true,
                'intent_query' => 'brands',
                'original_query' => 'brands',
                'correction' => '',
                'intent_type' => 'browse',
                'model_names' => array(),
                'price_filter' => array('has_price_filter' => false, 'min_price' => null, 'max_price' => null),
                'brand_filters' => array(),
            );
        }

        $index = get_option(self::CATALOG_INDEX_OPTION_KEY, array());
        $categories = isset($index['categories']) && is_array($index['categories']) ? $index['categories'] : array();
        $products = isset($index['products']) && is_array($index['products']) ? $index['products'] : array();
        $brand_counts = array();
        foreach ($products as $product) {
            foreach ((array) (isset($product['categories']) ? $product['categories'] : array()) as $cat_name) {
                $cat_name = trim((string) $cat_name);
                if ($cat_name === '') {
                    continue;
                }
                $brand_counts[$cat_name] = isset($brand_counts[$cat_name]) ? $brand_counts[$cat_name] + 1 : 1;
            }
        }
        $links = array();

        foreach ($categories as $category) {
            if (isset($category['parent']) && (int) $category['parent'] !== 0) {
                continue;
            }
            if (empty($category['name']) || empty($category['url'])) {
                continue;
            }
            $label = trim((string) $category['name']);
            if (! preg_match('/^[a-z][a-z0-9 &\-]{2,}$/i', $label)) {
                continue;
            }
            if ((int) (isset($brand_counts[$label]) ? $brand_counts[$label] : 0) < 4) {
                continue;
            }
            $links[] = array(
                'label' => sprintf(__('View All %s', 'carbon-repro-widget'), $label),
                'url' => (string) $category['url'],
            );
            if (count($links) >= 8) {
                break;
            }
        }

        return array(
            'cards' => array(),
            'links' => $this->dedupe_catalog_links($links),
            'has_multiple_products' => false,
            'is_generic_query' => true,
            'intent_query' => 'brands',
            'original_query' => 'brands',
            'correction' => '',
            'intent_type' => 'browse',
            'model_names' => array(),
            'price_filter' => array('has_price_filter' => false, 'min_price' => null, 'max_price' => null),
            'brand_filters' => array(),
        );
    }

    private function extract_brand_filters($query, $categories, $products)
    {
        $query = strtolower((string) $query);
        $tokens = $this->get_catalog_significant_tokens($query);
        $brand_counts = array();
        foreach ((array) $products as $product) {
            foreach ((array) (isset($product['categories']) ? $product['categories'] : array()) as $cat_name) {
                $cat_name = trim((string) $cat_name);
                if ($cat_name === '') {
                    continue;
                }
                $brand_counts[strtolower($cat_name)] = isset($brand_counts[strtolower($cat_name)]) ? $brand_counts[strtolower($cat_name)] + 1 : 1;
            }
        }

        $filters = array();
        foreach ((array) $categories as $category) {
            if (isset($category['parent']) && (int) $category['parent'] !== 0) {
                continue;
            }
            $name = trim((string) (isset($category['name']) ? $category['name'] : ''));
            $slug = trim((string) (isset($category['slug']) ? $category['slug'] : ''));
            if ($name === '') {
                continue;
            }
            if ((int) (isset($brand_counts[strtolower($name)]) ? $brand_counts[strtolower($name)] : 0) < 1) {
                continue;
            }
            foreach ($tokens as $token) {
                if ($token !== '' && (strtolower($name) === strtolower($token) || strtolower($slug) === strtolower($token) || strpos(strtolower($name), strtolower($token)) !== false)) {
                    $filters[] = $name;
                    break;
                }
                if ($token !== '' && strlen($token) >= 5) {
                    $distance_name = levenshtein($token, strtolower($name));
                    $distance_slug = $slug !== '' ? levenshtein($token, strtolower($slug)) : 99;
                    if ($distance_name <= 2 || $distance_slug <= 2) {
                        $filters[] = $name;
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($filters));
    }

    private function get_business_info_reply()
    {
        $contact = $this->get_bot_contact_details();
        $parts = array();
        $name = trim((string) $this->get_setting('business_name', 'FS Fine Watches'));
        if ($name !== '') {
            $parts[] = $name;
        }
        if (! empty($contact['location'])) {
            $parts[] = sprintf(__('Location: %s', 'carbon-repro-widget'), $contact['location']);
        }
        if (! empty($contact['phone'])) {
            $parts[] = sprintf(__('Phone: %s', 'carbon-repro-widget'), $contact['phone']);
        }
        if (! empty($contact['email'])) {
            $parts[] = sprintf(__('Email: %s', 'carbon-repro-widget'), $contact['email']);
        }

        if (empty($parts)) {
            return __('Sure. I can help with store details. Please ask for location, phone, or email and I will share it.', 'carbon-repro-widget');
        }

        return __('Sure — here are our store details:', 'carbon-repro-widget') . "\n" . implode("\n", $parts);
    }

    private function get_static_intent_reply($query, $lead, $catalog_suggestions = array())
    {
        $query = trim((string) $query);
        $intent_type = isset($catalog_suggestions['intent_type']) ? (string) $catalog_suggestions['intent_type'] : 'unknown';
        $contact = $this->get_bot_contact_details();
        $name = ! empty($lead['name']) ? $lead['name'] : '';

        if ($intent_type === 'greeting') {
            return $name !== ''
                ? sprintf(__('Hello %s. How can I help you today? If you are looking for a specific watch, repair service, or store information, just let me know.', 'carbon-repro-widget'), $name)
                : __('Hello. How can I help you today? If you are looking for a specific watch, repair service, or store information, just let me know.', 'carbon-repro-widget');
        }

        if (preg_match('/\b(location|address|directions?|where are you|map)\b/i', $query)) {
            if (! empty($contact['location'])) {
                return __('We would be happy to help. You can use the buttons below to call, email, or get directions to the store.', 'carbon-repro-widget');
            }

            return __('We would be happy to help. Use the buttons below to contact the store directly.', 'carbon-repro-widget');
        }

        if (preg_match('/\b(what brands|which brands|brands? do you (sell|carry)|what .* brands|brands (do you have|available))\b/i', $query)) {
            $index = get_option(self::CATALOG_INDEX_OPTION_KEY, array());
            $categories = isset($index['categories']) && is_array($index['categories']) ? $index['categories'] : array();
            $brands = array();

            foreach ($categories as $category) {
                if (empty($category['name'])) {
                    continue;
                }
                if (isset($category['parent']) && (int) $category['parent'] !== 0) {
                    continue;
                }
                $label = trim((string) $category['name']);
                if ($label === '' || strcasecmp($label, 'uncategorized') === 0) {
                    continue;
                }
                $brands[] = $label;
            }

            $brands = array_values(array_unique($brands));
            sort($brands, SORT_NATURAL | SORT_FLAG_CASE);
            $brands = array_slice($brands, 0, 18);

            if (! empty($brands)) {
                return sprintf(
                    __('We carry brands including %s. Tell me which brand you like (for example Rolex or Cartier) and I will show you the best matches.', 'carbon-repro-widget'),
                    implode(', ', $brands)
                );
            }

            return __('We carry a curated selection of luxury watch brands. Tell me the brand you are interested in (for example Rolex, Cartier, Omega) and I will share matching inventory.', 'carbon-repro-widget');
        }

        return '';
    }

    private function maybe_apply_chat_limit($conversation_id, $transcript, $lead, $meta_store)
    {
        $limit = $this->get_chat_message_limit();
        if ($limit <= 0) {
            return array('triggered' => false);
        }

        $user_count = $this->count_user_messages($transcript);
        if ($user_count < $limit) {
            return array('triggered' => false);
        }

        $reply = $this->get_chat_limit_reply($lead);
        $already_sent = get_post_meta($conversation_id, '_criaw_limit_notified', true) === '1';

        if (! $already_sent) {
            update_post_meta($conversation_id, '_criaw_limit_notified', '1');
            $this->send_new_conversation_notification($conversation_id, $lead, $meta_store);
            $this->send_admin_sms_notification($conversation_id, $lead, $meta_store, 'limit_reached');
            $this->send_customer_human_reply_email($lead, $reply, $meta_store);
            $this->send_customer_chat_sms($lead, __('Thanks for contacting us. Our team has your details and will follow up shortly. You can also call or email the store directly if needed.', 'carbon-repro-widget'), $meta_store, 'limit_reached');
        }

        return array(
            'triggered' => true,
            'reply' => $reply,
            'meta' => array(
                'catalog_cards' => array(),
                'catalog_links' => array(),
                'contact_actions' => $this->get_contact_actions_meta('contact location call email directions', true),
            ),
        );
    }

    private function get_latest_user_message($transcript)
    {
        if (! is_array($transcript)) {
            return '';
        }

        for ($index = count($transcript) - 1; $index >= 0; $index--) {
            if (isset($transcript[$index]['role']) && $transcript[$index]['role'] === 'user') {
                return isset($transcript[$index]['content']) ? (string) $transcript[$index]['content'] : '';
            }
        }

        return '';
    }

    private function get_default_start_reply($lead)
    {
        $name = trim(isset($lead['name']) ? $lead['name'] : '');
        $looking_for = trim(isset($lead['looking_for']) ? $lead['looking_for'] : '');

        if ($looking_for !== '') {
            return sprintf(
                __('Thanks%s. I have your details and noted that you are looking for %s. Let me help with that.', 'carbon-repro-widget'),
                $name !== '' ? ', ' . $name : '',
                $looking_for
            );
        }

        return sprintf(
            __('Thanks%s. I have your details. What would you like help with today?', 'carbon-repro-widget'),
            $name !== '' ? ', ' . $name : ''
        );
    }

    private function build_catalog_summary($products, $categories)
    {
        $summary_parts = array();

        foreach (array_slice((array) $products, 0, 120) as $product) {
            $summary_parts[] = sprintf(
                'Product: %s | SKU: %s | Categories: %s | Price: %s | URL: %s | Attributes: %s | Details: %s',
                isset($product['name']) ? $product['name'] : '',
                ! empty($product['sku']) ? $product['sku'] : 'N/A',
                ! empty($product['categories']) ? implode(', ', (array) $product['categories']) : 'Uncategorized',
                ! empty($product['price']) ? $product['price'] : 'N/A',
                isset($product['url']) ? $product['url'] : '',
                ! empty($product['attributes']) ? implode(', ', (array) $product['attributes']) : 'No attributes',
                ! empty($product['short_description']) ? $product['short_description'] : 'No short description'
            );
        }

        foreach (array_slice((array) $categories, 0, 60) as $category) {
            $summary_parts[] = sprintf(
                'Category: %s | Slug: %s | URL: %s | Details: %s',
                isset($category['name']) ? $category['name'] : '',
                isset($category['slug']) ? $category['slug'] : '',
                isset($category['url']) ? (string) $category['url'] : '',
                ! empty($category['description']) ? $category['description'] : 'No description'
            );
        }

        return implode("\n", $summary_parts);
    }

    private function normalize_catalog_match_url($url)
    {
        $url = esc_url_raw((string) $url);
        $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
        $url = preg_replace('/[)\].,!?:;]+$/', '', $url);

        if ($url === '') {
            return '';
        }

        return untrailingslashit($url);
    }

    private function extract_product_attributes($product)
    {
        if (! $product || ! method_exists($product, 'get_attributes')) {
            return array();
        }

        $attributes = array();
        foreach ((array) $product->get_attributes() as $attribute) {
            if (is_object($attribute) && method_exists($attribute, 'is_taxonomy') && $attribute->is_taxonomy()) {
                $terms = wc_get_product_terms($product->get_id(), $attribute->get_name(), array('fields' => 'names'));
                if (! empty($terms) && ! is_wp_error($terms)) {
                    $label = wc_attribute_label($attribute->get_name());
                    $attributes[] = $label . ': ' . implode(', ', $terms);
                }
            } elseif (is_object($attribute) && method_exists($attribute, 'get_options')) {
                $options = array_filter(array_map('trim', (array) $attribute->get_options()));
                if (! empty($options)) {
                    $label = $attribute->get_name();
                    $attributes[] = $label . ': ' . implode(', ', $options);
                }
            }
        }

        return array_values(array_unique($attributes));
    }

    private function build_product_searchable_text($product)
    {
        $parts = array(
            $product->get_name(),
            $product->get_sku(),
            wp_strip_all_tags($product->get_short_description(), true),
        );

        foreach ($this->extract_product_attributes($product) as $attribute_line) {
            $parts[] = $attribute_line;
        }

        $parts = array_merge($parts, wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names')));

        return strtolower(trim(implode(' ', array_filter($parts))));
    }

    private function get_theme_colors()
    {
        $preset = $this->get_setting('theme_preset', 'custom');
        $custom = array(
            'primary_color' => $this->get_setting('primary_color', '#126A59'),
            'secondary_color' => $this->get_setting('secondary_color', '#0D4E41'),
            'accent_color' => $this->get_setting('accent_color', '#B88E52'),
            'panel_background_color' => $this->get_setting('panel_background_color', '#ffffff'),
            'surface_color' => $this->get_setting('surface_color', '#F9F9F9'),
            'text_color' => $this->get_setting('text_color', '#111111'),
            'button_text_color' => $this->get_setting('button_text_color', '#ffffff'),
            'launcher_text_color' => $this->get_setting('launcher_text_color', '#126A59'),
        );

        $presets = array(
            'ocean' => array(
                'primary_color' => '#1677ff',
                'secondary_color' => '#0d4fd6',
                'accent_color' => '#00b894',
                'panel_background_color' => '#ffffff',
                'surface_color' => '#f4f8ff',
                'text_color' => '#18273f',
                'button_text_color' => '#ffffff',
                'launcher_text_color' => '#1677ff',
            ),
            'midnight' => array(
                'primary_color' => '#111827',
                'secondary_color' => '#000000',
                'accent_color' => '#d4af37',
                'panel_background_color' => '#ffffff',
                'surface_color' => '#f7f7f5',
                'text_color' => '#111827',
                'button_text_color' => '#ffffff',
                'launcher_text_color' => '#111827',
            ),
            'forest' => array(
                'primary_color' => '#0f766e',
                'secondary_color' => '#115e59',
                'accent_color' => '#84cc16',
                'panel_background_color' => '#ffffff',
                'surface_color' => '#f3fbf7',
                'text_color' => '#17352f',
                'button_text_color' => '#ffffff',
                'launcher_text_color' => '#0f766e',
            ),
            'sunset' => array(
                'primary_color' => '#ef6c00',
                'secondary_color' => '#d9480f',
                'accent_color' => '#7c3aed',
                'panel_background_color' => '#ffffff',
                'surface_color' => '#fff8f1',
                'text_color' => '#3f2a1d',
                'button_text_color' => '#ffffff',
                'launcher_text_color' => '#ef6c00',
            ),
        );

        return isset($presets[$preset]) ? $presets[$preset] : $custom;
    }

    private function get_menu_action_icon_svg($icon)
    {
        $svgs = array(
            'chat' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path><circle cx="9" cy="11" r="1"></circle><circle cx="12" cy="11" r="1"></circle><circle cx="15" cy="11" r="1"></circle></svg>',
            'form' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path><line x1="9" y1="10" x2="15" y2="10"></line><line x1="9" y1="14" x2="15" y2="14"></line></svg>',
            'call' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.13 11.91 19.79 19.79 0 0 1 1.06 3.24 2 2 0 0 1 3.03 1h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.09 8.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 21 16.92z"></path></svg>',
            'text' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"></rect><line x1="7" y1="7" x2="17" y2="7"></line><line x1="7" y1="11" x2="17" y2="11"></line><line x1="7" y1="15" x2="17" y2="15"></line></svg>',
        );

        return isset($svgs[$icon]) ? $svgs[$icon] : $svgs['chat'];
    }

    private function parse_price_constraints($query)
    {
        $text = strtolower((string) $query);
        $result = array(
            'min_price' => null,
            'max_price' => null,
            'has_price_filter' => false,
        );

        if (preg_match('/\bbetween\s+\$?(\d{2,6}(?:[.,]\d{1,2})?)\s*(?:to|and|-)\s*\$?(\d{2,6}(?:[.,]\d{1,2})?)\b/i', $text, $m)) {
            $a = (float) str_replace(',', '', $m[1]);
            $b = (float) str_replace(',', '', $m[2]);
            $result['min_price'] = min($a, $b);
            $result['max_price'] = max($a, $b);
            $result['has_price_filter'] = true;
            return $result;
        }

        if (preg_match('/\b(?:below|under|less than|max(?:imum)?(?: price)?|upto|up to)\s+\$?(\d{2,6}(?:[.,]\d{1,2})?)\b/i', $text, $m)) {
            $result['max_price'] = (float) str_replace(',', '', $m[1]);
            $result['has_price_filter'] = true;
            return $result;
        }

        if (preg_match('/\b(?:above|over|more than|min(?:imum)?(?: price)?|starting from)\s+\$?(\d{2,6}(?:[.,]\d{1,2})?)\b/i', $text, $m)) {
            $result['min_price'] = (float) str_replace(',', '', $m[1]);
            $result['has_price_filter'] = true;
            return $result;
        }

        return $result;
    }

    private function build_product_search_url($keyword = '', $price_filter = array())
    {
        $args = array();
        $keyword = trim((string) $keyword);
        $price_filter = is_array($price_filter) ? $price_filter : array();
        $min = isset($price_filter['min_price']) ? $price_filter['min_price'] : null;
        $max = isset($price_filter['max_price']) ? $price_filter['max_price'] : null;

        if ($keyword !== '') {
            $args['s'] = $keyword;
        }
        $args['post_type'] = 'product';
        if ($min !== null && $min !== '') {
            $args['min_price'] = (int) floor((float) $min);
        }
        if ($max !== null && $max !== '') {
            $args['max_price'] = (int) floor((float) $max);
        }

        return add_query_arg($args, home_url('/shop/'));
    }

    private function extract_catalog_intent_query($query)
    {
        $query = strtolower(trim((string) $query));
        if ($query === '') {
            return '';
        }

        $query = preg_replace('/\b(i am|i\'m|im)\s+(looking|searching)\s+(for|to buy)?\b/i', '', $query);
        $query = preg_replace('/\b(i mean|meant|mean)\b/i', '', $query);
        $query = preg_replace('/\b(can you|could you|please|tell me|show me|find me|help me find)\b/i', '', $query);
        $query = preg_replace('/\b(do you have|are you carrying|have you got|looking for|searching for|need|want)\b/i', '', $query);
        $query = preg_replace('/\b(with|has|having)\s+(the\s+)?(following|this)\s+(sku|ref|reference|description)\b/i', '', $query);
        $query = preg_replace('/\b(sku|ref|reference number|model number)\s*(=|:)?\s*/i', ' ', $query);
        $query = preg_replace('/\b(i\s+want\s+the\s+watch|watch\s+with|which\s+has|that\s+has)\b/i', ' ', $query);
        $query = preg_replace('/\bthe\b/i', ' ', $query);
        $query = preg_replace('/[^a-z0-9\-\s]/i', ' ', $query);
        $query = preg_replace('/\s+/', ' ', $query);

        return trim($query);
    }

    private function get_catalog_correction_suggestion($query, $products, $categories)
    {
        $intent = $this->extract_catalog_intent_query($query);
        if ($intent === '') {
            return '';
        }

        preg_match_all('/[A-Za-z0-9\-_]{3,}/', $intent, $matches);
        $tokens = array_values(array_unique(array_map('strtolower', $matches[0])));
        if (empty($tokens)) {
            return '';
        }

        $candidate_map = array();
        foreach ($categories as $category) {
            $name = isset($category['name']) ? strtolower(trim((string) $category['name'])) : '';
            if ($name !== '') {
                $candidate_map[$name] = isset($category['name']) ? (string) $category['name'] : $name;
                foreach (preg_split('/\s+/', $name) as $part) {
                    if (strlen($part) >= 4) {
                        $candidate_map[$part] = ucfirst($part);
                    }
                }
            }
        }

        foreach (array_slice($products, 0, 300) as $product) {
            if (empty($product['categories']) || ! is_array($product['categories'])) {
                continue;
            }
            foreach ($product['categories'] as $category_name) {
                $category_name = trim((string) $category_name);
                if ($category_name === '') {
                    continue;
                }
                $candidate_map[strtolower($category_name)] = $category_name;
            }
            $title = isset($product['name']) ? trim((string) $product['name']) : '';
            if ($title !== '') {
                $parts = preg_split('/\s+/', strtolower($title));
                if (! empty($parts[0]) && strlen($parts[0]) >= 4) {
                    $candidate_map[$parts[0]] = ucfirst($parts[0]);
                }
            }
        }

        $best = '';
        $best_distance = PHP_INT_MAX;
        foreach ($tokens as $token) {
            if (strlen($token) < 4) {
                continue;
            }
            foreach ($candidate_map as $candidate_key => $candidate_label) {
                if ($candidate_key === $token) {
                    return $candidate_label;
                }
                $distance = levenshtein($token, $candidate_key);
                if ($distance <= 2 && $distance < $best_distance) {
                    $best = $candidate_label;
                    $best_distance = $distance;
                }
            }
        }

        return $best;
    }

    private function classify_catalog_query_intent($original_query, $intent_query)
    {
        $text = strtolower(trim((string) $original_query));
        $intent_text = strtolower(trim((string) $intent_query));
        $combined_text = trim($text . ' ' . $intent_text);

        if ($combined_text === '') {
            return 'unknown';
        }

        if (
            preg_match('/^(hi|hello|hey|good morning|good afternoon|good evening|assalam o alaikum|salam|thanks|thank you)\b[!. ]*$/', $text) ||
            preg_match('/^(hi|hello|hey|good morning|good afternoon|good evening|assalam o alaikum|salam|thanks|thank you)\b[!. ]*$/', $intent_text)
        ) {
            return 'greeting';
        }

        if (preg_match('/\b(repair|service|servicing|replacement|replace|restoration|restore|refinish|polish|battery|bezel|bazel|strap|band|bracelet|sizing|resize|authentication|authenticate|warranty|shipping|return|refund|finance|financing|payment|delivery|feature|features|policy|pickup|location|hours|address|directions|where are you)\b/', $combined_text)) {
            return 'service_info';
        }

        if (preg_match('/\b(what models|which models|what collections|which collections|what options|what do you have|what .* do you have of|do you have of)\b/', $combined_text)) {
            return 'model_overview';
        }

        if (preg_match('/\b(ref|reference|reference number|sku|model number)\b/', $combined_text) || preg_match('/\d{3,}/', $combined_text)) {
            return 'product_lookup';
        }

        return 'generic_catalog';
    }

    private function get_catalog_significant_tokens($query)
    {
        preg_match_all('/[A-Za-z0-9\-_]{2,}/', strtolower((string) $query), $matches);
        $tokens = array_values(array_unique($matches[0]));
        $stopwords = array(
            'i', 'im', 'am', 'looking', 'look', 'for', 'the', 'a', 'an', 'do', 'you', 'offer',
            'offers', 'have', 'has', 'with', 'and', 'or', 'to', 'of', 'in', 'on', 'at', 'is',
            'are', 'me', 'my', 'we', 'our', 'your', 'can', 'could', 'would', 'should', 'this',
            'that', 'these', 'those', 'watch', 'watches', 'model', 'models', 'please', 'need',
            'bro', 'buddy', 'yaar', 'sir', 'hey', 'hello', 'hi'
        );

        $filtered = array_values(array_filter($tokens, function ($token) use ($stopwords) {
            if ($token === '' || in_array($token, $stopwords, true)) {
                return false;
            }

            return (bool) preg_match('/[a-z0-9]/i', $token);
        }));

        return ! empty($filtered) ? $filtered : $tokens;
    }

    private function category_matches_catalog_query($category, $query_lower, $tokens)
    {
        $name = strtolower(trim((string) (isset($category['name']) ? $category['name'] : '')));
        $slug = strtolower(trim((string) (isset($category['slug']) ? $category['slug'] : '')));

        if ($name === '' && $slug === '') {
            return false;
        }

        if ($query_lower !== '' && ($name === $query_lower || $slug === $query_lower || strpos($name, $query_lower) !== false || strpos($slug, $query_lower) !== false)) {
            return true;
        }

        foreach ((array) $tokens as $token) {
            if ($token === '') {
                continue;
            }

            if ($name === $token || $slug === $token || strpos($name, $token) !== false || strpos($slug, $token) !== false) {
                return true;
            }
        }

        return false;
    }

    private function get_catalog_suggestions_for_query($query)
    {
        if (! $this->is_ecommerce_mode() || $this->get_setting('catalog_index_enabled', '0') !== '1') {
            return array('cards' => array(), 'links' => array());
        }

        $index = get_option(self::CATALOG_INDEX_OPTION_KEY, array());
        $products = isset($index['products']) && is_array($index['products']) ? $index['products'] : array();
        $categories = isset($index['categories']) && is_array($index['categories']) ? $index['categories'] : array();
        $original_query = trim((string) $query);
        $query = $this->extract_catalog_intent_query($query);
        if ($query === '') {
            return array('cards' => array(), 'links' => array());
        }
        $intent_type = $this->classify_catalog_query_intent($original_query, $query);
        if ($intent_type === 'service_info' || $intent_type === 'greeting') {
            return array(
                'cards' => array(),
                'links' => array(),
                'has_multiple_products' => false,
                'is_generic_query' => false,
                'intent_query' => $query,
                'original_query' => $original_query,
                'correction' => '',
                'intent_type' => $intent_type,
                'model_names' => array(),
            );
        }

        $price_filter = $this->parse_price_constraints($original_query);
        $brand_filters = $this->extract_brand_filters($original_query, $categories, $products);
        if (! empty($price_filter['has_price_filter']) || $intent_type === 'product_lookup' || $intent_type === 'generic_catalog') {
            $live_products = $this->get_live_catalog_products($original_query, $price_filter, $categories, $brand_filters, 120);
            if (! empty($live_products)) {
                $products = $live_products;
            }
        }

        preg_match_all('/[A-Za-z0-9\-_]{2,}/', $query, $matches);
        $tokens = array_values(array_unique(array_map('strtolower', $matches[0])));
        $significant_tokens = $this->get_catalog_significant_tokens($query);
        $query_lower = strtolower($query);
        $has_numeric_token = (bool) preg_match('/\d{3,}/', $query);
        $is_generic_query = count($tokens) <= 2 && ! $has_numeric_token;
        $sku_tokens = array_values(array_filter($tokens, function ($token) {
            return (bool) preg_match('/[a-z]{1,4}\d{5,}|\d{5,}/i', (string) $token);
        }));
        $scored_products = array();
        foreach ($products as $product) {
            if (! empty($brand_filters)) {
                $product_categories = array_map('strtolower', (array) (isset($product['categories']) ? $product['categories'] : array()));
                $brand_match = false;
                foreach ($brand_filters as $brand_filter) {
                    if (in_array(strtolower((string) $brand_filter), $product_categories, true)) {
                        $brand_match = true;
                        break;
                    }
                }
                if (! $brand_match) {
                    continue;
                }
            }
            if (! empty($price_filter['has_price_filter'])) {
                $price_value = $this->parse_price_value(isset($product['price']) ? $product['price'] : '');
                if ($price_value === null) {
                    continue;
                }
                if ($price_filter['min_price'] !== null && $price_value < (float) $price_filter['min_price']) {
                    continue;
                }
                if ($price_filter['max_price'] !== null && $price_value > (float) $price_filter['max_price']) {
                    continue;
                }
            }

            $searchable = isset($product['searchable_text']) ? strtolower((string) $product['searchable_text']) : '';
            $name = ! empty($product['name']) ? strtolower((string) $product['name']) : '';
            $sku = ! empty($product['sku']) ? strtolower((string) $product['sku']) : '';
            $score = 0;
            $strong_match = false;
            $matched_title_tokens = 0;

            if ($query_lower !== '' && ($name === $query_lower || $sku === $query_lower)) {
                $score += 55;
                $strong_match = true;
            } elseif ($query_lower !== '' && (strpos($name, $query_lower) !== false || strpos($sku, $query_lower) !== false)) {
                $score += 28;
                $strong_match = true;
            } elseif ($query_lower !== '' && strpos($searchable, $query_lower) !== false) {
                $score += 14;
            }

            foreach ($significant_tokens as $token) {
                if ($token === '') {
                    continue;
                }
                if ($sku !== '' && $sku === $token) {
                    $score += 40;
                    $strong_match = true;
                } elseif ($sku !== '' && strpos($sku, $token) !== false) {
                    $score += 18;
                    $strong_match = true;
                }
                if ($name !== '' && strpos($name, $token) !== false) {
                    $score += 8;
                    $matched_title_tokens++;
                }
                if (strpos($searchable, $token) !== false) {
                    $score += 3;
                }
            }

            if (! empty($significant_tokens) && $matched_title_tokens >= count($significant_tokens)) {
                $score += 16;
                $strong_match = true;
            }

            if ($score > 0 && ($strong_match || $matched_title_tokens > 0 || $intent_type === 'product_lookup')) {
                $scored_products[] = array(
                    'score' => $score,
                    'product' => $product,
                    'strong_match' => $strong_match,
                    'title_token_matches' => $matched_title_tokens,
                );
            }
        }

        usort($scored_products, function ($left, $right) {
            return $right['score'] <=> $left['score'];
        });

        if (! empty($sku_tokens)) {
            $sku_exact_matches = array();
            foreach ($scored_products as $row) {
                $sku = isset($row['product']['sku']) ? strtolower(trim((string) $row['product']['sku'])) : '';
                if ($sku !== '' && in_array($sku, $sku_tokens, true)) {
                    $row['score'] += 80;
                    $sku_exact_matches[] = $row;
                }
            }
            if (! empty($sku_exact_matches)) {
                usort($sku_exact_matches, function ($left, $right) {
                    return $right['score'] <=> $left['score'];
                });
                $scored_products = $sku_exact_matches;
                $is_generic_query = false;
            }
        }

        $cards = array();
        $links = array();
        $product_matches = array_slice($scored_products, 0, 4);
        $brand_token = ! empty($tokens) ? ucfirst($tokens[0]) : '';
        $category_match = null;
        $matched_categories = array();
        $model_names = array();

        if (! empty($product_matches)) {
            $category_counts = array();
            foreach ($product_matches as $row) {
                foreach ((array) $row['product']['categories'] as $category_name) {
                    $category_name = trim((string) $category_name);
                    if ($category_name === '') {
                        continue;
                    }
                    $category_counts[$category_name] = isset($category_counts[$category_name]) ? $category_counts[$category_name] + 1 : 1;
                }
            }
            arsort($category_counts);
            foreach (array_keys($category_counts) as $category_name) {
                if ($brand_token !== '' && strtolower((string) $category_name) === strtolower($brand_token)) {
                    continue;
                }
                $model_names[] = $category_name;
            }
            $top_category_name = key($category_counts);
            if ($top_category_name) {
                foreach ($categories as $category) {
                    if (
                        isset($category['name']) &&
                        strcasecmp((string) $category['name'], (string) $top_category_name) === 0 &&
                        $this->category_matches_catalog_query($category, $query_lower, $significant_tokens)
                    ) {
                        $category_match = $category;
                        break;
                    }
                }
            }
        }

        $scored_categories = array();
        foreach ($categories as $category) {
            $name = strtolower(trim((string) (isset($category['name']) ? $category['name'] : '')));
            $slug = strtolower(trim((string) (isset($category['slug']) ? $category['slug'] : '')));
            $score = 0;
            if ($name === '' && $slug === '') {
                continue;
            }
            if ($query_lower !== '' && ($name === $query_lower || $slug === $query_lower)) {
                $score += 30;
            } elseif ($query_lower !== '' && (strpos($name, $query_lower) !== false || strpos($slug, $query_lower) !== false)) {
                $score += 14;
            }
            foreach ($significant_tokens as $token) {
                if ($token !== '' && ($name === $token || $slug === $token)) {
                    $score += 12;
                } elseif ($token !== '' && (strpos($name, $token) !== false || strpos($slug, $token) !== false)) {
                    $score += 5;
                }
            }
            if ($score > 0) {
                $scored_categories[] = array('score' => $score, 'category' => $category);
            }
        }
        usort($scored_categories, function ($left, $right) {
            return $right['score'] <=> $left['score'];
        });
        foreach ($scored_categories as $row) {
            if (empty($row['category']['url'])) {
                continue;
            }
            $matched_categories[] = $row['category'];
            if (count($matched_categories) >= 2) {
                break;
            }
        }
        if (! $category_match) {
            $category_match = ! empty($matched_categories) ? $matched_categories[0] : null;
        }

        $has_strong_product_match = false;
        foreach ($scored_products as $row) {
            $minimum_token_match = ! empty($significant_tokens) ? min(2, count($significant_tokens)) : 1;
            if (! empty($row['strong_match']) || (! empty($row['title_token_matches']) && $row['title_token_matches'] >= $minimum_token_match)) {
                $has_strong_product_match = true;
                break;
            }
        }

        if (! $has_strong_product_match && $intent_type !== 'product_lookup') {
            $product_matches = array();
            $scored_products = array();
        }

        if ($intent_type !== 'model_overview' && $intent_type !== 'service_info') {
            foreach ($product_matches as $row) {
                $product = $row['product'];
                $cards[] = array(
                    'type' => 'product',
                    'id' => isset($product['id']) ? (int) $product['id'] : 0,
                    'url' => isset($product['url']) ? $product['url'] : '',
                    'title' => isset($product['name']) ? $product['name'] : '',
                    'price' => isset($product['price']) ? $product['price'] : '',
                    'category' => ! empty($product['categories']) ? implode(', ', array_slice((array) $product['categories'], 0, 2)) : '',
                    'description' => isset($product['short_description']) ? $product['short_description'] : '',
                    'image' => isset($product['image']) ? $product['image'] : '',
                );
            }
        }

        if (count($scored_products) > 4 && ! empty($price_filter['has_price_filter'])) {
            $links[] = array(
                'label' => __('View More', 'carbon-repro-widget'),
                'url' => $this->build_product_search_url('', $price_filter),
            );
        }

        if (! empty($brand_filters) && count($brand_filters) === 1) {
            foreach ($categories as $category) {
                if (! empty($category['name']) && ! empty($category['url']) && strcasecmp((string) $category['name'], (string) $brand_filters[0]) === 0) {
                    $links[] = array(
                        'label' => sprintf(__('View All %s', 'carbon-repro-widget'), (string) $brand_filters[0]),
                        'url' => (string) $category['url'],
                    );
                    break;
                }
            }
            if (empty($links)) {
                foreach ($categories as $category) {
                    if (
                        ! empty($category['name']) && ! empty($category['url']) &&
                        stripos((string) $category['name'], (string) $brand_filters[0]) !== false
                    ) {
                        $links[] = array(
                            'label' => sprintf(__('View All %s', 'carbon-repro-widget'), (string) $brand_filters[0]),
                            'url' => (string) $category['url'],
                        );
                        break;
                    }
                }
            }
        }

        if (empty($links) && $category_match && ! empty($category_match['url']) && ! empty($category_match['name'])) {
            $links[] = array(
                'label' => sprintf(__('View All %s', 'carbon-repro-widget'), $category_match['name']),
                'url' => $category_match['url'],
            );
        }

        $cards = $this->dedupe_catalog_cards($cards);
        $links = $this->dedupe_catalog_links($links);

        return array(
            'cards' => array_slice($cards, 0, 4),
            'links' => array_slice($links, 0, 3),
            'has_multiple_products' => count($scored_products) > 1,
            'is_generic_query' => $is_generic_query,
            'intent_query' => $query,
            'original_query' => $original_query,
            'correction' => (empty($scored_products) && ! $category_match) ? $this->get_catalog_correction_suggestion($query, $products, $categories) : '',
            'intent_type' => $intent_type,
            'model_names' => array_slice(array_values(array_unique($model_names)), 0, 8),
            'price_filter' => $price_filter,
            'brand_filters' => $brand_filters,
        );
    }

    private function dedupe_catalog_cards($cards)
    {
        $cards = is_array($cards) ? $cards : array();
        $seen = array();
        $unique = array();

        foreach ($cards as $card) {
            $url = isset($card['url']) ? $this->normalize_catalog_match_url($card['url']) : '';
            $title = isset($card['title']) ? strtolower(trim((string) $card['title'])) : '';
            $type = isset($card['type']) ? strtolower(trim((string) $card['type'])) : 'product';
            $key = $url !== '' ? $type . '|' . $url : $type . '|' . $title;
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $card;
        }

        return $unique;
    }

    private function dedupe_catalog_links($links)
    {
        $links = is_array($links) ? $links : array();
        $seen = array();
        $unique = array();

        foreach ($links as $link) {
            $url = isset($link['url']) ? $this->normalize_catalog_match_url($link['url']) : '';
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $unique[] = $link;
        }

        return $unique;
    }

    private function should_use_catalog_fast_path($query, $suggestions)
    {
        if ($this->get_setting('catalog_index_enabled', '0') !== '1') {
            return false;
        }

        $query = trim((string) $query);
        if ($query === '' || ! is_array($suggestions)) {
            return false;
        }

        $cards = isset($suggestions['cards']) && is_array($suggestions['cards']) ? $suggestions['cards'] : array();
        $links = isset($suggestions['links']) && is_array($suggestions['links']) ? $suggestions['links'] : array();
        $intent_type = isset($suggestions['intent_type']) ? $suggestions['intent_type'] : 'unknown';

        if ($intent_type === 'service_info' || $intent_type === 'greeting') {
            return false;
        }

        return ! empty($cards) || ! empty($links) || ($intent_type === 'model_overview' && ! empty($suggestions['model_names']));
    }

    private function format_catalog_assisted_reply($query, $reply, $suggestions)
    {
        $query = trim((string) $query);
        if ($query === '' || ! is_array($suggestions)) {
            return $reply;
        }

        $cards = isset($suggestions['cards']) && is_array($suggestions['cards']) ? $suggestions['cards'] : array();
        $links = isset($suggestions['links']) && is_array($suggestions['links']) ? $suggestions['links'] : array();
        $has_multiple = ! empty($suggestions['has_multiple_products']);
        $is_generic = ! empty($suggestions['is_generic_query']);
        $intent_type = isset($suggestions['intent_type']) ? $suggestions['intent_type'] : 'unknown';
        $model_names = isset($suggestions['model_names']) && is_array($suggestions['model_names']) ? $suggestions['model_names'] : array();
        $price_filter = isset($suggestions['price_filter']) && is_array($suggestions['price_filter']) ? $suggestions['price_filter'] : array();
        $has_price_filter = ! empty($price_filter['has_price_filter']);

        if ($intent_type === 'model_overview' && ! empty($model_names)) {
            return sprintf(
                __('We carry %1$s models such as %2$s. If you already know the model you want, send the model name or reference number and I will narrow it down.', 'carbon-repro-widget'),
                isset($suggestions['intent_query']) && $suggestions['intent_query'] !== '' ? $suggestions['intent_query'] : $query,
                implode(', ', array_slice($model_names, 0, 6))
            );
        }

        if ($intent_type === 'greeting') {
            return $reply !== '' ? $reply : __('Hello. How can I help you today? If you are looking for a specific watch, service, or location information, tell me what you need.', 'carbon-repro-widget');
        }

        if (empty($cards)) {
            if ($has_price_filter) {
                $brand_text = '';
                if (! empty($suggestions['brand_filters']) && is_array($suggestions['brand_filters'])) {
                    $brand_text = implode(', ', array_slice((array) $suggestions['brand_filters'], 0, 2)) . ' ';
                }
                return sprintf(__('Currently, we do not have %swatches in that price range. Would you like to see slightly higher options?', 'carbon-repro-widget'), $brand_text);
            }
            if (! empty($links)) {
                return sprintf(
                    __('I found the closest collection link(s) for %s below. If you share a model name, dial color, size, or reference number, I can narrow it down to specific inventory.', 'carbon-repro-widget'),
                    isset($suggestions['intent_query']) && $suggestions['intent_query'] !== '' ? $suggestions['intent_query'] : $query
                );
            }
            return $reply !== '' ? $reply : __('I could not find an exact match, but I can help you quickly if you share a brand, model name, dial color, size, or reference number.', 'carbon-repro-widget');
        }

        if ($has_price_filter) {
            if (isset($price_filter['min_price']) && $price_filter['min_price'] !== null && isset($price_filter['max_price']) && $price_filter['max_price'] !== null) {
                return sprintf(__('Here are some watches between $%1$s and $%2$s.', 'carbon-repro-widget'), number_format((float) $price_filter['min_price'], 0), number_format((float) $price_filter['max_price'], 0));
            }
            if (isset($price_filter['max_price']) && $price_filter['max_price'] !== null) {
                return sprintf(__('Here are some watches under $%s.', 'carbon-repro-widget'), number_format((float) $price_filter['max_price'], 0));
            }
            if (isset($price_filter['min_price']) && $price_filter['min_price'] !== null) {
                return sprintf(__('Here are some watches above $%s.', 'carbon-repro-widget'), number_format((float) $price_filter['min_price'], 0));
            }
        }

        if ($is_generic) {
            if (! empty($suggestions['brand_filters']) && count($suggestions['brand_filters']) === 1) {
                return sprintf(
                    __('Yes, we do have %s options. I have shared a few matching products below, and you can also use the %s collection button for full inventory.', 'carbon-repro-widget'),
                    isset($suggestions['intent_query']) && $suggestions['intent_query'] !== '' ? $suggestions['intent_query'] : $query,
                    $suggestions['brand_filters'][0]
                );
            }
            return sprintf(
                __('Yes, we do have %s options. I have shared a few matching products below, and you can use the collection links to browse more. If you have a specific model or reference number, send it and I can narrow it down further.', 'carbon-repro-widget'),
                isset($suggestions['intent_query']) && $suggestions['intent_query'] !== '' ? $suggestions['intent_query'] : $query
            );
        }

        if ($has_multiple) {
            return __('Sure! Here are a few matching watches.', 'carbon-repro-widget');
        }

        return $reply;
    }

    private function get_catalog_context_for_query($query)
    {
        if (! $this->is_woocommerce_active() || $this->get_setting('catalog_index_enabled', '0') !== '1') {
            return '';
        }

        $query = trim((string) $query);
        if ($query === '') {
            return '';
        }

        $lines = array();
        $category_lines = array();
        $index = get_option(self::CATALOG_INDEX_OPTION_KEY, array());
        $products = isset($index['products']) && is_array($index['products']) ? $index['products'] : array();
        $categories = isset($index['categories']) && is_array($index['categories']) ? $index['categories'] : array();
        $query_lower = strtolower($query);
        preg_match_all('/[A-Za-z0-9\-_]{3,}/', $query, $matches);
        $tokens = array_values(array_unique(array_map('strtolower', $matches[0])));

        $scored_products = array();
        foreach ($products as $product) {
            $searchable = isset($product['searchable_text']) ? strtolower((string) $product['searchable_text']) : strtolower(
                trim(
                    implode(
                        ' ',
                        array(
                            isset($product['name']) ? $product['name'] : '',
                            isset($product['sku']) ? $product['sku'] : '',
                            isset($product['short_description']) ? $product['short_description'] : '',
                            ! empty($product['attributes']) ? implode(' ', (array) $product['attributes']) : '',
                            ! empty($product['categories']) ? implode(' ', (array) $product['categories']) : '',
                        )
                    )
                )
            );
            $score = 0;

            if ($query_lower !== '' && strpos($searchable, $query_lower) !== false) {
                $score += 12;
            }

            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }
                if (! empty($product['sku']) && strtolower((string) $product['sku']) === $token) {
                    $score += 30;
                } elseif (! empty($product['sku']) && strpos(strtolower((string) $product['sku']), $token) !== false) {
                    $score += 16;
                }
                if (! empty($product['name']) && strpos(strtolower((string) $product['name']), $token) !== false) {
                    $score += 6;
                }
                if (strpos($searchable, $token) !== false) {
                    $score += 3;
                }
            }

            if ($score > 0) {
                $scored_products[] = array('score' => $score, 'product' => $product);
            }
        }

        usort($scored_products, function ($left, $right) {
            return $right['score'] <=> $left['score'];
        });

        foreach (array_slice($scored_products, 0, 5) as $row) {
            $product = $row['product'];
            $lines[] = sprintf(
                'Matched product: %s | SKU: %s | URL: %s | Price: %s | Categories: %s | Attributes: %s',
                isset($product['name']) ? $product['name'] : '',
                ! empty($product['sku']) ? $product['sku'] : 'N/A',
                isset($product['url']) ? $product['url'] : '',
                ! empty($product['price']) ? $product['price'] : 'N/A',
                ! empty($product['categories']) ? implode(', ', (array) $product['categories']) : 'Uncategorized',
                ! empty($product['attributes']) ? implode(', ', (array) $product['attributes']) : 'No attributes'
            );
        }

        if (count($scored_products) > 1) {
            $category_counts = array();
            foreach (array_slice($scored_products, 0, 8) as $row) {
                foreach ((array) $row['product']['categories'] as $category_name) {
                    $category_name = trim((string) $category_name);
                    if ($category_name === '') {
                        continue;
                    }
                    $category_counts[$category_name] = isset($category_counts[$category_name]) ? $category_counts[$category_name] + 1 : 1;
                }
            }

            arsort($category_counts);
            $top_category_name = key($category_counts);
            if ($top_category_name) {
                foreach ($categories as $category) {
                    if (isset($category['name']) && strcasecmp((string) $category['name'], (string) $top_category_name) === 0) {
                        $category_lines[] = array(
                            'score' => 999,
                            'line' => sprintf(
                                'Matched collection category: %s | URL: %s | Description: %s',
                                $category['name'],
                                isset($category['url']) ? $category['url'] : '',
                                ! empty($category['description']) ? $category['description'] : 'No description'
                            ),
                        );
                        break;
                    }
                }
            }
        }

        foreach ($categories as $category) {
            $score = 0;
            $haystack = strtolower(trim(implode(' ', array(
                isset($category['name']) ? $category['name'] : '',
                isset($category['slug']) ? $category['slug'] : '',
                isset($category['description']) ? $category['description'] : '',
            ))));

            if ($query_lower !== '' && strpos($haystack, $query_lower) !== false) {
                $score += 10;
            }

            foreach ($tokens as $token) {
                if ($token !== '' && strpos($haystack, $token) !== false) {
                    $score += 4;
                }
            }

            if ($score > 0) {
                $category_lines[] = array(
                    'score' => $score,
                    'line' => sprintf(
                        'Matched category: %s | URL: %s | Description: %s',
                        isset($category['name']) ? $category['name'] : '',
                        isset($category['url']) ? $category['url'] : '',
                        ! empty($category['description']) ? $category['description'] : 'No description'
                    ),
                );
            }
        }

        usort($category_lines, function ($left, $right) {
            return $right['score'] <=> $left['score'];
        });

        return implode(
            "\n",
            array_merge(
                $lines,
                array_map(
                    function ($row) {
                        return $row['line'];
                    },
                    array_slice($category_lines, 0, 5)
                )
            )
        );
    }

    private function extract_response_text($response)
    {
        if (! empty($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }

        if (empty($response['output']) || ! is_array($response['output'])) {
            return '';
        }

        foreach ($response['output'] as $output_item) {
            if (empty($output_item['content']) || ! is_array($output_item['content'])) {
                continue;
            }

            foreach ($output_item['content'] as $content_item) {
                if (isset($content_item['text']) && is_string($content_item['text'])) {
                    return $content_item['text'];
                }
            }
        }

        return '';
    }

    private function get_or_create_conversation($conversation_id, $meta)
    {
        if ($conversation_id > 0) {
            $post = get_post($conversation_id);
            if ($post && $post->post_type === self::CONVERSATION_POST_TYPE) {
                return $conversation_id;
            }
        }

        $conversation_id = wp_insert_post(
            array(
                'post_type' => self::CONVERSATION_POST_TYPE,
                'post_status' => 'publish',
                'post_title' => __('New Widget Chat', 'carbon-repro-widget') . ' - ' . current_time('mysql'),
            )
        );

        update_post_meta($conversation_id, '_criaw_meta', $meta);
        update_post_meta($conversation_id, '_criaw_human_takeover', '0');

        return (int) $conversation_id;
    }

    private function send_new_conversation_notification($conversation_id, $lead, $meta)
    {
        $email = $this->get_setting('notification_email', get_option('admin_email'));
        if (! is_email($email)) {
            $this->append_notification_timeline($conversation_id, 'email', 'admin', 'skipped', 'new_chat', __('Notification email is not configured.', 'carbon-repro-widget'));
            return;
        }

        $subject = sprintf(
            __('[%s] New Widget Chat Lead', 'carbon-repro-widget'),
            $this->get_setting('business_name', get_bloginfo('name'))
        );

        $message = array(
            __('A new visitor started a chat in the widget.', 'carbon-repro-widget'),
            '',
            __('Business:', 'carbon-repro-widget') . ' ' . $this->get_setting('business_name', get_bloginfo('name')),
            __('Page:', 'carbon-repro-widget') . ' ' . (isset($meta['page_url']) ? $meta['page_url'] : ''),
            __('Source:', 'carbon-repro-widget') . ' ' . (isset($meta['source_group']) ? $meta['source_group'] : ''),
            __('Device:', 'carbon-repro-widget') . ' ' . (isset($meta['device_type']) ? $meta['device_type'] : ''),
            __('Country:', 'carbon-repro-widget') . ' ' . (isset($meta['country_name']) ? $meta['country_name'] : ''),
            __('Conversation:', 'carbon-repro-widget') . ' ' . admin_url('admin.php?page=carbon-repro-widget-conversations&conversation_id=' . $conversation_id),
            '',
            __('Lead details so far:', 'carbon-repro-widget'),
            __('Name:', 'carbon-repro-widget') . ' ' . $lead['name'],
            __('Email:', 'carbon-repro-widget') . ' ' . $lead['email'],
            __('Phone:', 'carbon-repro-widget') . ' ' . $lead['phone'],
            __('Need:', 'carbon-repro-widget') . ' ' . $lead['looking_for'],
        );

        $transcript = get_post_meta($conversation_id, '_criaw_transcript', true);
        $transcript = is_array($transcript) ? $transcript : array();
        if (! empty($transcript[0]['content'])) {
            $message[] = '';
            $message[] = __('Conversation started with:', 'carbon-repro-widget') . ' ' . $transcript[0]['content'];
        }

        $sent = wp_mail($email, $subject, implode("\n", $message));
        $this->append_notification_timeline(
            $conversation_id,
            'email',
            'admin',
            $sent ? 'sent' : 'failed',
            'new_chat',
            $sent ? __('Admin email notification sent.', 'carbon-repro-widget') : __('wp_mail returned false for the admin new chat email.', 'carbon-repro-widget')
        );
    }

    private function can_email_customer($lead, $meta)
    {
        if (empty($lead['email']) || ! is_email($lead['email'])) {
            return false;
        }

        if ($this->get_setting('require_email_consent', '1') !== '1') {
            return true;
        }

        if (! isset($meta['email_updates_opt_in']) || $meta['email_updates_opt_in'] === '') {
            return true;
        }

        return $meta['email_updates_opt_in'] === '1';
    }

    private function send_customer_takeover_email($lead, $meta)
    {
        $conversation_id = isset($meta['conversation_id']) ? absint($meta['conversation_id']) : 0;
        if ($this->get_setting('customer_takeover_notifications', '1') !== '1') {
            $this->append_notification_timeline($conversation_id, 'email', 'customer', 'skipped', 'takeover', __('Customer takeover emails are disabled.', 'carbon-repro-widget'));
            return;
        }
        if (! $this->can_email_customer($lead, $meta)) {
            $this->append_notification_timeline($conversation_id, 'email', 'customer', 'skipped', 'takeover', __('Customer email consent or address is missing.', 'carbon-repro-widget'));
            return;
        }

        $subject = sprintf(
            __('A team member from %s is now handling your chat', 'carbon-repro-widget'),
            $this->get_setting('business_name', get_bloginfo('name'))
        );

        $body = array(
            sprintf(__('A team member from %s has joined your chat and will respond personally.', 'carbon-repro-widget'), $this->get_setting('business_name', get_bloginfo('name'))),
            '',
            __('Return to the website any time to continue the conversation from the widget.', 'carbon-repro-widget'),
        );

        $sent = wp_mail($lead['email'], $subject, implode("\n", $body));
        $this->append_notification_timeline(
            $conversation_id,
            'email',
            'customer',
            $sent ? 'sent' : 'failed',
            'takeover',
            $sent ? __('Customer takeover email sent.', 'carbon-repro-widget') : __('wp_mail returned false for the takeover email.', 'carbon-repro-widget')
        );
    }

    private function send_customer_human_reply_email($lead, $message, $meta = array())
    {
        $conversation_id = isset($meta['conversation_id']) ? absint($meta['conversation_id']) : 0;
        if ($this->get_setting('customer_reply_notifications', '1') !== '1') {
            $this->append_notification_timeline($conversation_id, 'email', 'customer', 'skipped', 'human_reply', __('Customer reply emails are disabled.', 'carbon-repro-widget'));
            return;
        }
        if (! $this->can_email_customer($lead, $meta)) {
            $this->append_notification_timeline($conversation_id, 'email', 'customer', 'skipped', 'human_reply', __('Customer email consent or address is missing.', 'carbon-repro-widget'));
            return;
        }

        $subject = sprintf(
            __('New Reply from %s', 'carbon-repro-widget'),
            $this->get_setting('business_name', get_bloginfo('name'))
        );

        $body = array(
            sprintf(__('A team member from %s replied to your website chat.', 'carbon-repro-widget'), $this->get_setting('business_name', get_bloginfo('name'))),
            '',
            $message,
            '',
            __('Return to the website to view the full conversation and reply in the widget.', 'carbon-repro-widget'),
        );

        $sent = wp_mail($lead['email'], $subject, implode("\n", $body));
        $this->append_notification_timeline(
            $conversation_id,
            'email',
            'customer',
            $sent ? 'sent' : 'failed',
            'human_reply',
            $sent ? __('Customer reply email sent.', 'carbon-repro-widget') : __('wp_mail returned false for the human reply email.', 'carbon-repro-widget')
        );
    }

    private function can_sms_customer($lead, $meta = array())
    {
        if (empty($lead['phone']) || $this->normalize_sms_number($lead['phone']) === '') {
            return false;
        }

        return isset($meta['sms_updates_opt_in']) && $meta['sms_updates_opt_in'] === '1';
    }

    private function normalize_sms_number($number)
    {
        $number = trim((string) $number);
        if ($number === '') {
            return '';
        }

        $normalized = preg_replace('/[^0-9+]/', '', $number);
        if ($normalized === '') {
            return '';
        }

        if ($normalized[0] !== '+') {
            if (strlen($normalized) === 10) {
                $normalized = '+1' . $normalized;
            } elseif (strlen($normalized) === 11 && strpos($normalized, '1') === 0) {
                $normalized = '+' . $normalized;
            } else {
                $normalized = '+' . ltrim($normalized, '+');
            }
        }

        return $normalized;
    }

    private function is_twilio_enabled()
    {
        return $this->get_setting('twilio_enabled', '0') === '1'
            && $this->get_setting('twilio_account_sid', '') !== ''
            && $this->get_setting('twilio_auth_token', '') !== ''
            && $this->normalize_sms_number($this->get_setting('twilio_from_number', '')) !== '';
    }

    private function send_twilio_sms($to, $message)
    {
        if (! $this->is_twilio_enabled()) {
            return array('success' => false, 'status' => 0, 'message' => __('Twilio is not fully configured.', 'carbon-repro-widget'));
        }

        $to = $this->normalize_sms_number($to);
        $from = $this->normalize_sms_number($this->get_setting('twilio_from_number', ''));
        if ($to === '' || $from === '') {
            return array('success' => false, 'status' => 0, 'message' => __('Phone number formatting is invalid for Twilio.', 'carbon-repro-widget'));
        }

        $sid = $this->get_setting('twilio_account_sid', '');
        $token = $this->get_setting('twilio_auth_token', '');
        $endpoint = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';

        $response = wp_remote_post(
            $endpoint,
            array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($sid . ':' . $token),
                ),
                'body' => array(
                    'To' => $to,
                    'From' => $from,
                    'Body' => wp_strip_all_tags((string) $message, true),
                ),
            )
        );

        if (is_wp_error($response)) {
            return array('success' => false, 'status' => 0, 'message' => $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $error_message = is_array($decoded) && isset($decoded['message']) ? $decoded['message'] : __('Twilio returned an error response.', 'carbon-repro-widget');
            return array('success' => false, 'status' => $status, 'message' => $error_message);
        }

        return array(
            'success' => true,
            'status' => $status,
            'message' => is_array($decoded) && isset($decoded['sid']) ? sprintf(__('Twilio SID %s', 'carbon-repro-widget'), $decoded['sid']) : __('Twilio accepted the message.', 'carbon-repro-widget'),
        );
    }

    private function send_admin_sms_notification($conversation_id, $lead, $meta, $context = 'new_chat')
    {
        if ($this->get_setting('admin_sms_notifications', '0') !== '1') {
            $this->append_notification_timeline($conversation_id, 'sms', 'admin', 'skipped', $context, __('Admin SMS alerts are disabled.', 'carbon-repro-widget'));
            return;
        }

        $to = $this->get_setting('notification_sms_number', '');
        if ($to === '') {
            $this->append_notification_timeline($conversation_id, 'sms', 'admin', 'skipped', $context, __('Admin notification phone is not configured.', 'carbon-repro-widget'));
            return;
        }

        $label_map = array(
            'new_chat' => __('New widget chat lead', 'carbon-repro-widget'),
            'takeover' => __('Chat assigned to human', 'carbon-repro-widget'),
            'human_reply' => __('Human reply sent', 'carbon-repro-widget'),
            'limit_reached' => __('Chat message limit reached', 'carbon-repro-widget'),
        );
        $contact = $this->get_bot_contact_details();
        $body = array(
            '[' . $this->get_setting('business_name', get_bloginfo('name')) . '] ' . (isset($label_map[$context]) ? $label_map[$context] : __('Widget chat update', 'carbon-repro-widget')),
            __('Name:', 'carbon-repro-widget') . ' ' . (! empty($lead['name']) ? $lead['name'] : __('Unknown', 'carbon-repro-widget')),
            __('Phone:', 'carbon-repro-widget') . ' ' . (! empty($lead['phone']) ? $lead['phone'] : __('Unknown', 'carbon-repro-widget')),
            __('Need:', 'carbon-repro-widget') . ' ' . (! empty($lead['looking_for']) ? $lead['looking_for'] : __('Not provided', 'carbon-repro-widget')),
        );
        if (! empty($contact['phone'])) {
            $body[] = __('Store Phone:', 'carbon-repro-widget') . ' ' . $contact['phone'];
        }
        if ($conversation_id > 0) {
            $body[] = admin_url('admin.php?page=carbon-repro-widget-conversations&conversation_id=' . $conversation_id);
        }

        $result = $this->send_twilio_sms($to, implode("\n", $body));
        $this->append_notification_timeline(
            $conversation_id,
            'sms',
            'admin',
            ! empty($result['success']) ? 'sent' : 'failed',
            $context,
            isset($result['message']) ? $result['message'] : ''
        );
    }

    private function send_customer_chat_sms($lead, $message, $meta = array(), $context = 'customer_update')
    {
        $conversation_id = isset($meta['conversation_id']) ? absint($meta['conversation_id']) : 0;
        if ($this->get_setting('customer_sms_notifications', '0') !== '1') {
            $this->append_notification_timeline($conversation_id, 'sms', 'customer', 'skipped', $context, __('Customer SMS alerts are disabled.', 'carbon-repro-widget'));
            return;
        }
        if (! $this->can_sms_customer($lead, $meta)) {
            $reason = empty($lead['phone']) || $this->normalize_sms_number($lead['phone']) === ''
                ? __('Customer phone number is missing or invalid.', 'carbon-repro-widget')
                : __('Customer SMS consent was not captured at chat start.', 'carbon-repro-widget');
            $this->append_notification_timeline($conversation_id, 'sms', 'customer', 'skipped', $context, $reason);
            return;
        }

        $contact = $this->get_bot_contact_details();
        $body = array(
            wp_strip_all_tags((string) $message, true),
        );
        if (! empty($contact['phone'])) {
            $body[] = __('Phone:', 'carbon-repro-widget') . ' ' . $contact['phone'];
        }
        if (! empty($contact['email'])) {
            $body[] = __('Email:', 'carbon-repro-widget') . ' ' . $contact['email'];
        }
        if (! empty($contact['location'])) {
            $body[] = __('Location:', 'carbon-repro-widget') . ' ' . $contact['location'];
        }
        if (! empty($contact['location_url'])) {
            $body[] = __('Map:', 'carbon-repro-widget') . ' ' . $contact['location_url'];
        }

        $result = $this->send_twilio_sms($lead['phone'], implode("\n", $body));
        $this->append_notification_timeline(
            $conversation_id,
            'sms',
            'customer',
            ! empty($result['success']) ? 'sent' : 'failed',
            $context,
            isset($result['message']) ? $result['message'] : ''
        );
    }

    private function append_notification_timeline($conversation_id, $channel, $audience, $status, $context, $detail = '')
    {
        if ($conversation_id <= 0) {
            return;
        }

        $timeline = get_post_meta($conversation_id, '_criaw_notification_timeline', true);
        $timeline = is_array($timeline) ? $timeline : array();
        $timeline[] = array(
            'time' => current_time('mysql'),
            'channel' => sanitize_key($channel),
            'audience' => sanitize_key($audience),
            'status' => sanitize_key($status),
            'context' => sanitize_key($context),
            'detail' => sanitize_textarea_field((string) $detail),
        );
        update_post_meta($conversation_id, '_criaw_notification_timeline', $timeline);
    }

    private function refresh_conversation_title($conversation_id, $lead)
    {
        $name = trim(isset($lead['name']) ? $lead['name'] : '');
        $title = $name !== '' ? $name : __('Widget Visitor', 'carbon-repro-widget');

        wp_update_post(
            array(
                'ID' => $conversation_id,
                'post_title' => $title . ' - ' . gmdate('Y-m-d H:i'),
            )
        );
    }

    private function render_form_content($form_id)
    {
        if ($form_id > 0 && shortcode_exists('wpforms')) {
            echo do_shortcode(sprintf('[wpforms id="%d"]', $form_id));
            return;
        }

        echo '<div class="watch-form-fallback">';
        echo '<p><strong>' . esc_html__('WPForms is not configured yet.', 'carbon-repro-widget') . '</strong></p>';
        echo '<p>' . esc_html__('Install WPForms and add a form ID in the plugin settings.', 'carbon-repro-widget') . '</p>';
        echo '</div>';
    }

    private function derive_source_group($payload)
    {
        $utm_source = strtolower($payload['utm_source']);
        $utm_medium = strtolower($payload['utm_medium']);
        $referrer_domain = strtolower($payload['referrer_domain']);

        if ($utm_source !== '' || $utm_medium !== '') {
            $google_ads_mediums = array('cpc', 'ppc', 'paid', 'paid_search');
            $social_mediums = array('social', 'paid_social');

            if (strpos($utm_source, 'google') !== false && in_array($utm_medium, $google_ads_mediums, true)) {
                return 'Google Ads';
            }

            if (strpos($utm_source, 'google') !== false) {
                return 'Google';
            }

            if (in_array($utm_medium, $social_mediums, true) || preg_match('/facebook|instagram|linkedin|tiktok|x|twitter/', $utm_source)) {
                return 'Social Media';
            }

            if ($utm_medium === 'email') {
                return 'Email';
            }

            return ucfirst(str_replace('_', ' ', $utm_source !== '' ? $utm_source : $utm_medium));
        }

        if ($referrer_domain === '') {
            return 'Direct';
        }

        if (strpos($referrer_domain, 'google.') !== false) {
            return 'Google';
        }

        if (preg_match('/facebook|instagram|linkedin|tiktok|twitter|x\.com|youtube/', $referrer_domain)) {
            return 'Social Media';
        }

        return $referrer_domain;
    }

    private function lookup_country_from_ip($ip)
    {
        if (! $ip) {
            return array();
        }

        $cache_key = self::GEO_CACHE_PREFIX . md5($ip);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get('https://ipwho.is/' . rawurlencode($ip), array('timeout' => 6));
        if (is_wp_error($response)) {
            return array();
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (! is_array($decoded) || empty($decoded['success'])) {
            return array();
        }

        $country = array(
            'country_code' => isset($decoded['country_code']) ? sanitize_text_field($decoded['country_code']) : '',
            'country_name' => isset($decoded['country']) ? sanitize_text_field($decoded['country']) : '',
        );

        set_transient($cache_key, $country, DAY_IN_SECONDS);

        return $country;
    }

    private function get_ip_address()
    {
        $keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');

        foreach ($keys as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }

            $value = sanitize_text_field(wp_unslash($_SERVER[$key]));
            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $value);
                $value = trim($parts[0]);
            }

            if (filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }
        }

        return '';
    }

    private function get_events_table()
    {
        global $wpdb;
        return $wpdb->prefix . self::EVENTS_TABLE_SUFFIX;
    }

    private function merge_lead_data($lead, $incoming)
    {
        $merged = $this->get_empty_lead();

        foreach ($merged as $key => $default) {
            $existing = trim(isset($lead[$key]) ? $lead[$key] : '');
            $new = trim(isset($incoming[$key]) ? $incoming[$key] : '');
            $merged[$key] = $new !== '' ? $new : $existing;
        }

        return $merged;
    }

    private function get_empty_lead()
    {
        return array(
            'name' => '',
            'email' => '',
            'phone' => '',
            'looking_for' => '',
        );
    }

    private function is_chatbot_enabled()
    {
        return $this->get_setting('chatbot_enabled', '0') === '1';
    }

    private function get_setting($key, $default = '')
    {
        $settings = wp_parse_args(get_option(self::OPTION_KEY, array()), $this->get_default_settings());
        $value = isset($settings[$key]) ? $settings[$key] : $default;
        return is_scalar($value) ? (string) $value : $default;
    }

    private function get_default_settings()
    {
        return array(
            'white_label_plugin_name' => 'Carbon Repro Widget',
            'assistant_mode' => 'ecommerce',
            'business_name' => '',
            'business_industry' => '',
            'business_location' => '',
            'business_services' => '',
            'business_faqs' => '',
            'business_terminology_notes' => '',
            'qualification_questions' => '',
            'auto_crawl_enabled' => '0',
            'crawl_focus_paths' => '',
            'crawled_knowledge' => '',
            'catalog_index_enabled' => '0',
            'catalog_index_knowledge' => '',
            'brand_logo_url' => '',
            'launcher_icon_url' => '',
            'theme_preset' => 'custom',
            'primary_color' => '#126A59',
            'secondary_color' => '#0D4E41',
            'accent_color' => '#B88E52',
            'panel_background_color' => '#ffffff',
            'surface_color' => '#F9F9F9',
            'text_color' => '#111111',
            'button_text_color' => '#ffffff',
            'launcher_text_color' => '#126A59',
            'label_text' => 'Start Growing Today',
            'menu_title' => 'Instant Actions',
            'form_title' => 'Get Your Strategy',
            'footer_brand' => 'Carbon Repro',
            'phone_number' => '7137056097',
            'store_contact_phone' => '',
            'store_contact_email' => '',
            'store_contact_location' => '',
            'store_contact_location_url' => '',
            'wpforms_id' => '46362',
            'form_enabled' => '1',
            'call_enabled' => '1',
            'text_enabled' => '1',
            'call_label' => 'Call Us',
            'text_label' => 'Text Us',
            'chatbot_enabled' => '0',
            'chatbot_title' => 'AI Assistant',
            'chatbot_welcome_message' => 'Thanks. I have your details. What would you like help with today?',
            'chat_message_limit' => 0,
            'chat_limit_message' => '',
            'notification_email' => get_option('admin_email'),
            'notification_sms_number' => '',
            'customer_reply_notifications' => '1',
            'customer_takeover_notifications' => '1',
            'admin_sms_notifications' => '0',
            'customer_sms_notifications' => '0',
            'require_email_consent' => '1',
            'customer_consent_label' => 'Email me when a team member replies to this chat',
            'sms_consent_label' => 'I agree to receive SMS updates about my inquiry. Message and data rates may apply.',
            'whatsapp_consent_label' => 'I agree to receive WhatsApp follow-ups about my inquiry.',
            'twilio_enabled' => '0',
            'twilio_account_sid' => '',
            'twilio_auth_token' => '',
            'twilio_from_number' => '',
            'chatbot_model' => 'gpt-4o-mini',
            'openai_api_key' => '',
            'chatbot_prompt' => 'Keep replies friendly, concise, and lead-focused.',
        );
    }
}

register_activation_hook(__FILE__, array('Carbon_Repro_Instant_Actions_Widget', 'activate'));

new Carbon_Repro_Instant_Actions_Widget();
