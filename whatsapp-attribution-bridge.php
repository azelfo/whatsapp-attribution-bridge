<?php
/**
 * Plugin Name: WhatsApp Attribution Bridge
 * Description: Liga cliques rastreados no WhatsApp a contatos do GoHighLevel.
 * Version: 0.2.6
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Marcelo
 * Update URI: https://github.com/azelfo/whatsapp-attribution-bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WAB_VERSION', '0.2.6');
define('WAB_FILE', __FILE__);
define('WAB_DIR', plugin_dir_path(__FILE__));
require_once WAB_DIR . 'includes/core.php';
require_once WAB_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/azelfo/whatsapp-attribution-bridge/',
    WAB_FILE,
    'whatsapp-attribution-bridge'
);

function wab_table()
{
    global $wpdb;
    return $wpdb->prefix . 'wab_attributions';
}

function wab_default_settings()
{
    return array(
        'enabled' => false,
        'location_id' => '',
        'hl_token' => '',
        'webhook_secret' => '',
        'processed_tag' => 'wab-attribution-processed',
        'retention_days' => 90,
        'delete_on_uninstall' => false,
        'field_map' => array(),
    );
}

function wab_settings()
{
    return wp_parse_args((array) get_option('wab_settings', array()), wab_default_settings());
}

function wab_messages()
{
    return (array) get_option('wab_messages', array());
}

function wab_install_schema()
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $table = wab_table();
    dbDelta("CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        token varchar(16) NOT NULL,
        first_payload longtext NOT NULL,
        last_payload longtext NOT NULL,
        classified_source varchar(64) NOT NULL,
        message_id varchar(100) NOT NULL,
        message_hash char(64) NOT NULL,
        clicked_at datetime NOT NULL,
        contact_id varchar(100) NULL,
        matched_at datetime NULL,
        processing_at datetime NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        attempts smallint(5) unsigned NOT NULL DEFAULT 0,
        last_error text NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY token (token),
        KEY status (status),
        KEY clicked_at (clicked_at),
        KEY contact_id (contact_id)
    ) {$charset};");

    update_option('wab_db_version', WAB_VERSION, false);
}

function wab_schedule_jobs()
{
    if (!wp_next_scheduled('wab_daily_cleanup')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wab_daily_cleanup');
    }
    if (!wp_next_scheduled('wab_retry_pending')) {
        wp_schedule_event(time() + 300, 'wab_five_minutes', 'wab_retry_pending');
    }
}

function wab_activate()
{
    global $wp_version;

    if (version_compare(PHP_VERSION, '7.4', '<') || version_compare($wp_version, '6.0', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('WhatsApp Attribution Bridge requer WordPress 6.0+ e PHP 7.4+.');
    }

    wab_install_schema();

    $settings = wab_settings();
    if ($settings['webhook_secret'] === '') {
        $settings['webhook_secret'] = wp_generate_password(48, false, false);
        update_option('wab_settings', $settings, false);
    }

    wab_schedule_jobs();
}
register_activation_hook(__FILE__, 'wab_activate');

function wab_cron_schedules($schedules)
{
    $schedules['wab_five_minutes'] = array('interval' => 300, 'display' => 'A cada 5 minutos');
    return $schedules;
}
add_filter('cron_schedules', 'wab_cron_schedules');

function wab_maybe_upgrade()
{
    if (get_option('wab_db_version') !== WAB_VERSION) {
        wab_install_schema();
    }
    wab_schedule_jobs();
}
add_action('plugins_loaded', 'wab_maybe_upgrade');

function wab_deactivate()
{
    wp_clear_scheduled_hook('wab_daily_cleanup');
    wp_clear_scheduled_hook('wab_cleanup_batch');
    wp_clear_scheduled_hook('wab_retry_pending');
}
register_deactivation_hook(__FILE__, 'wab_deactivate');

function wab_cleanup()
{
    global $wpdb;
    $days = max(1, min(365, (int) wab_settings()['retention_days']));
    $table = wab_table();
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$table} WHERE clicked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) LIMIT 500",
        $days
    ));
    if ($deleted === 500 && !wp_next_scheduled('wab_cleanup_batch')) {
        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'wab_cleanup_batch');
    }
}
add_action('wab_daily_cleanup', 'wab_cleanup');
add_action('wab_cleanup_batch', 'wab_cleanup');

function wab_message_link($id, array $message)
{
    $phone = preg_replace('/\D+/', '', $message['phone']);
    return 'https://wa.me/' . $phone . '?text=' . rawurlencode($message['message']) . '#wab=' . rawurlencode($id);
}

function wab_shortcode($attributes)
{
    $attributes = shortcode_atts(array('message' => '', 'label' => 'Falar no WhatsApp', 'class' => ''), $attributes);
    $messages = wab_messages();
    $id = sanitize_key($attributes['message']);
    if (empty($messages[$id]) || empty($messages[$id]['active'])) {
        return '';
    }

    return sprintf(
        '<a href="%s" data-wab-message="%s" class="%s">%s</a>',
        esc_url(wab_message_link($id, $messages[$id])),
        esc_attr($id),
        esc_attr($attributes['class']),
        esc_html($attributes['label'])
    );
}
add_shortcode('wab_whatsapp', 'wab_shortcode');

function wab_enqueue_tracker()
{
    $settings = wab_settings();
    if (is_admin() || empty($settings['enabled'])) {
        return;
    }

    $public_messages = array();
    foreach (wab_messages() as $id => $message) {
        if (!empty($message['active'])) {
            $public_messages[$id] = array(
                'phone' => preg_replace('/\D+/', '', $message['phone']),
                'message' => $message['message'],
            );
        }
    }
    if (!$public_messages) {
        return;
    }

    wp_enqueue_script('wab-tracker', plugins_url('assets/tracker.js', __FILE__), array(), WAB_VERSION, true);
    wp_localize_script('wab-tracker', 'WAB_CONFIG', array(
        'endpoint' => rest_url('wab/v1/click'),
        'messages' => $public_messages,
    ));
}
add_action('wp_enqueue_scripts', 'wab_enqueue_tracker');

function wab_client_ip()
{
    $candidates = array(
        isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']) : '',
        isset($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : '',
    );
    foreach ($candidates as $candidate) {
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return (string) $candidate;
        }
    }
    return 'unknown';
}

function wab_rate_limited($bucket, $limit, $seconds, $identity = '')
{
    $identity = $identity !== '' ? $identity : wab_client_ip();
    $key = 'wab_rate_' . md5($bucket . '|' . $identity);
    $now = time();
    $state = get_transient($key);
    if (!is_array($state) || empty($state['reset_at']) || (int) $state['reset_at'] <= $now) {
        $state = array('count' => 0, 'reset_at' => $now + $seconds);
    }
    if ((int) $state['count'] >= $limit) {
        return true;
    }
    $state['count']++;
    set_transient($key, $state, max(1, (int) $state['reset_at'] - $now));
    return false;
}

function wab_no_cache()
{
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

function wab_clean_payload($payload)
{
    if (!is_array($payload)) {
        return array();
    }

    $clean = array();
    $exact = array('gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'campaign_id', 'ad_group_id', 'ad_id', 'landing_url', 'referrer', 'captured_at');
    foreach ($payload as $key => $value) {
        $key = strtolower((string) $key);
        if ((!preg_match('/^utm_[a-z0-9_]+$/', $key) && !in_array($key, $exact, true)) || !is_scalar($value)) {
            continue;
        }
        $value = substr((string) $value, 0, in_array($key, array('landing_url', 'referrer'), true) ? 2048 : 512);
        $clean[$key] = in_array($key, array('landing_url', 'referrer'), true) ? wab_url_without_query($value) : sanitize_text_field($value);
    }
    return $clean;
}

function wab_url_without_query($value)
{
    $parts = wp_parse_url((string) $value);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $url = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
    if (!empty($parts['port'])) {
        $url .= ':' . (int) $parts['port'];
    }
    $url .= isset($parts['path']) ? $parts['path'] : '/';
    return esc_url_raw(substr($url, 0, 2048));
}

function wab_click_permission()
{
    return true;
}

function wab_click(WP_REST_Request $request)
{
    global $wpdb;
    wab_no_cache();

    if (empty(wab_settings()['enabled'])) {
        return new WP_Error('wab_disabled', 'Rastreamento desativado.', array('status' => 503));
    }

    if (strlen($request->get_body()) > 8192) {
        return new WP_Error('wab_payload_large', 'Payload excede 8 KB.', array('status' => 413));
    }
    if (wab_rate_limited('click', 30, MINUTE_IN_SECONDS)) {
        return new WP_Error('wab_rate_limit', 'Muitas requisições.', array('status' => 429));
    }

    $data = $request->get_json_params();
    $token = isset($data['token']) ? strtolower((string) $data['token']) : '';
    $message_id = isset($data['message_id']) ? sanitize_key($data['message_id']) : '';
    $messages = wab_messages();
    if (!preg_match('/^[a-f0-9]{12}$/', $token) || empty($messages[$message_id]) || empty($messages[$message_id]['active'])) {
        return new WP_Error('wab_invalid', 'Token ou mensagem inválida.', array('status' => 422));
    }

    $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . wab_table() . ' WHERE token = %s', $token));
    if ($existing) {
        return rest_ensure_response(array('registered' => true, 'duplicate' => true));
    }

    $first = wab_clean_payload(isset($data['first']) ? $data['first'] : array());
    $last = wab_clean_payload(isset($data['last']) ? $data['last'] : array());
    $source = wab_core_classify_source($last ?: $first);
    $inserted = $wpdb->insert(wab_table(), array(
        'token' => $token,
        'first_payload' => wp_json_encode($first),
        'last_payload' => wp_json_encode($last),
        'classified_source' => $source,
        'message_id' => $message_id,
        'message_hash' => hash('sha256', $messages[$message_id]['message']),
        'clicked_at' => current_time('mysql', true),
        'status' => 'pending',
    ), array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));

    if (!$inserted) {
        return new WP_Error('wab_database', 'Não foi possível registrar o clique.', array('status' => 500));
    }
    return new WP_REST_Response(array('registered' => true), 201);
}

function wab_match_permission(WP_REST_Request $request)
{
    $secret = (string) wab_settings()['webhook_secret'];
    $received = (string) $request->get_header('authorization');
    if ($secret === '' || !hash_equals('Bearer ' . $secret, $received)) {
        // Limita o log de falha de auth: sem isso, spam no endpoint publico viraria
        // escrita de option ilimitada (o rate limit do match roda so depois daqui).
        if (!wab_rate_limited('match_auth', 10, MINUTE_IN_SECONDS)) {
            wab_log_webhook($secret === '' ? 'secret_nao_configurado' : 'segredo_invalido', $request->get_body());
        }
        return new WP_Error('wab_unauthorized', 'Não autorizado.', array('status' => 401));
    }
    return true;
}

function wab_hl_token()
{
    if (defined('WAB_HL_TOKEN') && WAB_HL_TOKEN) {
        return (string) WAB_HL_TOKEN;
    }
    return (string) wab_settings()['hl_token'];
}

function wab_hl_request($method, $path, $body = null, $blocking = true)
{
    $args = array(
        'method' => $method,
        'timeout' => $blocking ? 4 : 1,
        'blocking' => $blocking,
        'headers' => array(
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . wab_hl_token(),
            'Content-Type' => 'application/json',
            'Version' => '2021-07-28',
        ),
    );
    if ($body !== null) {
        $args['body'] = wp_json_encode($body);
    }
    return wp_remote_request('https://services.leadconnectorhq.com/' . ltrim($path, '/'), $args);
}

function wab_add_processed_tag($contact_id)
{
    $tag = (string) wab_settings()['processed_tag'];
    if ($tag !== '') {
        wab_hl_request('POST', 'contacts/' . rawurlencode($contact_id) . '/tags', array('tags' => array($tag)), false);
    }
}

function wab_apply_attribution($contact_id, $row)
{
    $settings = wab_settings();
    if (wab_hl_token() === '' || $settings['location_id'] === '') {
        return new WP_Error('wab_not_configured', 'Token ou Location ID não configurado.');
    }

    $response = wab_hl_request('GET', 'contacts/' . rawurlencode($contact_id));
    if (is_wp_error($response)) {
        return $response;
    }
    $code = wp_remote_retrieve_response_code($response);
    $json = json_decode(wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300 || empty($json['contact'])) {
        return new WP_Error('wab_hl_get', 'HighLevel GET retornou HTTP ' . $code . '.');
    }

    $contact = $json['contact'];
    $existing = wab_core_contact_fields($contact);

    $first = (array) json_decode($row->first_payload, true);
    $last = (array) json_decode($row->last_payload, true);
    $first_source = wab_core_classify_source($first);
    $last_source = wab_core_classify_source($last ?: $first);
    $values = array(
        'first_source' => $first_source,
        'first_medium' => wab_core_payload_value($first, array('utm_medium')),
        'first_campaign' => wab_core_payload_value($first, array('utm_campaign', 'utm_id', 'campaign_id')),
        'first_content' => wab_core_payload_value($first, array('utm_content', 'ad_id')),
        'first_term' => wab_core_payload_value($first, array('utm_term', 'utm_keyword')),
        'first_click_id' => wab_core_payload_value($first, array('gclid', 'gbraid', 'wbraid', 'fbclid')),
        'first_landing' => wab_core_payload_value($first, array('landing_url')),
        'last_source' => $last_source,
        'last_medium' => wab_core_payload_value($last, array('utm_medium')),
        'last_campaign' => wab_core_payload_value($last, array('utm_campaign', 'utm_id', 'campaign_id')),
        'last_content' => wab_core_payload_value($last, array('utm_content', 'ad_id')),
        'last_term' => wab_core_payload_value($last, array('utm_term', 'utm_keyword')),
        'last_click_id' => wab_core_payload_value($last, array('gclid', 'gbraid', 'wbraid', 'fbclid')),
        'last_landing' => wab_core_payload_value($last, array('landing_url')),
        'confidence' => 'exact',
        'method' => 'invisible_token',
        'message_id' => $row->message_id,
    );

    $custom_fields = array();
    foreach ((array) $settings['field_map'] as $logical => $field_id) {
        if (empty($field_id) || !array_key_exists($logical, $values) || $values[$logical] === '') {
            continue;
        }
        if (strpos($logical, 'first_') === 0 && isset($existing[$field_id]) && (!$existing[$field_id]['recognized'] || $existing[$field_id]['filled'])) {
            continue;
        }
        $custom_fields[] = array('id' => $field_id, 'fieldValue' => $values[$logical]);
    }

    $update = array();
    if ($custom_fields) {
        $update['customFields'] = $custom_fields;
    }
    $current_source = strtolower(trim(isset($contact['source']) ? (string) $contact['source'] : ''));
    if ($current_source === '' || in_array($current_source, array('other', 'others', 'direct', 'direct traffic'), true)) {
        $update['source'] = $first_source;
    }

    if (!$update) {
        return new WP_Error('wab_no_fields', 'Nenhum campo de atribuição configurado para atualizar.');
    }

    $response = wab_hl_request('PUT', 'contacts/' . rawurlencode($contact_id), $update);
    if (is_wp_error($response)) {
        return $response;
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('wab_hl_put', 'HighLevel PUT retornou HTTP ' . $code . '.');
    }

    return true;
}

function wab_process_attribution($row, $contact_id)
{
    global $wpdb;
    $table = wab_table();

    if ($row->status === 'processing' && !empty($row->processing_at) && strtotime($row->processing_at . ' UTC') < time() - 120) {
        $wpdb->update($table, array('status' => 'pending', 'processing_at' => null), array('id' => $row->id));
        $row->status = 'pending';
    }

    $locked = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET status = 'processing', contact_id = %s, processing_at = UTC_TIMESTAMP(), attempts = attempts + 1 WHERE id = %d AND status = 'pending'",
        $contact_id,
        $row->id
    ));
    if ($locked !== 1) {
        return new WP_Error('wab_processing', 'Atribuição já está em processamento.');
    }

    $result = wab_apply_attribution($contact_id, $row);
    if (is_wp_error($result)) {
        $wpdb->update($table, array(
            'status' => 'pending',
            'processing_at' => null,
            'last_error' => substr(sanitize_text_field($result->get_error_message()), 0, 1000),
        ), array('id' => $row->id));
        return $result;
    }

    $wpdb->update($table, array(
        'status' => 'matched',
        'matched_at' => current_time('mysql', true),
        'processing_at' => null,
        'last_error' => '',
    ), array('id' => $row->id));
    wab_add_processed_tag($contact_id);
    return true;
}

function wab_retry_pending()
{
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT * FROM " . wab_table() . " WHERE status = 'pending' AND contact_id IS NOT NULL AND contact_id <> '' AND attempts < 8 ORDER BY id ASC LIMIT 10"
    );
    foreach ($rows as $row) {
        wab_process_attribution($row, (string) $row->contact_id);
    }
}
add_action('wab_retry_pending', 'wab_retry_pending');

// ponytail: ring buffer numa option, sem tabela. 50 entradas cobrem depurar um
// workflow; se precisar de historico longo ou busca, ai sim vira tabela.
function wab_log_webhook($reason, $body, $contact_id = '')
{
    $log = (array) get_option('wab_webhook_log', array());
    array_unshift($log, array(
        'at' => current_time('mysql', true),
        'reason' => substr((string) $reason, 0, 60),
        'contact' => substr((string) $contact_id, 0, 100),
        'ip' => wab_client_ip(),
        'body' => substr((string) $body, 0, 600),
    ));
    update_option('wab_webhook_log', array_slice($log, 0, 50), false);
}

function wab_match(WP_REST_Request $request)
{
    $result = wab_match_run($request);

    $error_code = is_wp_error($result) ? $result->get_error_code() : '';
    $data = is_wp_error($result) ? array() : (array) rest_ensure_response($result)->get_data();
    $body = $request->get_json_params();
    $custom = isset($body['customData']) && is_array($body['customData']) ? $body['customData'] : array();
    wab_log_webhook(
        wab_core_log_reason($error_code, $data),
        $request->get_body(),
        wab_core_body_field((array) $body, $custom, 'contact_id')
    );

    return $result;
}

function wab_match_run(WP_REST_Request $request)
{
    global $wpdb;
    wab_no_cache();

    if (empty(wab_settings()['enabled'])) {
        return rest_ensure_response(array('matched' => false, 'reason' => 'disabled'));
    }

    if (strlen($request->get_body()) > 12288) {
        return new WP_Error('wab_match_payload', 'Payload excede 12 KB.', array('status' => 413));
    }
    $data = $request->get_json_params();
    // Alguns webhooks (ex.: HighLevel) mandam os pares "Dados personalizados" dentro de
    // customData em vez de soltos na raiz do corpo. Aceita os dois formatos.
    $custom = isset($data['customData']) && is_array($data['customData']) ? $data['customData'] : array();
    $contact_id = sanitize_text_field(wab_core_body_field($data, $custom, 'contact_id'));
    $location_id = sanitize_text_field(wab_core_body_field($data, $custom, 'location_id'));
    $message = substr(wab_core_body_field($data, $custom, 'message'), 0, 5000);
    if (wab_rate_limited('match', 120, MINUTE_IN_SECONDS, $location_id !== '' ? $location_id : 'missing')) {
        return new WP_Error('wab_match_limit', 'Muitas requisições.', array('status' => 429));
    }
    if (!preg_match('/^[A-Za-z0-9_-]{3,100}$/', $contact_id)) {
        return new WP_Error('wab_contact', 'Contact ID inválido.', array('status' => 422));
    }
    $configured_location = (string) wab_settings()['location_id'];
    if ($configured_location === '') {
        return new WP_Error('wab_location_missing', 'Location ID não configurado.', array('status' => 503));
    }
    if ($location_id === '' || !hash_equals($configured_location, $location_id)) {
        return new WP_Error('wab_location', 'Location ID divergente.', array('status' => 403));
    }

    $tokens = wab_core_decode_tokens($message);
    if (!$tokens) {
        return rest_ensure_response(array('matched' => false, 'reason' => 'no_token'));
    }

    $row = null;
    foreach ($tokens as $token) {
        $candidate = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . wab_table() . ' WHERE token = %s', $token));
        if ($candidate) {
            $row = $candidate;
            break;
        }
    }
    if (!$row) {
        return new WP_REST_Response(array('matched' => false, 'reason' => 'token_pending'), 202);
    }
    if ($row->status === 'matched') {
        if (hash_equals((string) $row->contact_id, $contact_id)) {
            wab_add_processed_tag($contact_id);
            return rest_ensure_response(array('matched' => true, 'duplicate' => true));
        }
        return new WP_Error('wab_token_conflict', 'Token já associado a outro contato.', array('status' => 409));
    }
    if (!empty($row->contact_id) && !hash_equals((string) $row->contact_id, $contact_id)) {
        return new WP_Error('wab_token_conflict', 'Token já reservado para outro contato.', array('status' => 409));
    }

    $result = wab_process_attribution($row, $contact_id);
    if (is_wp_error($result) && $result->get_error_code() === 'wab_processing') {
        return new WP_REST_Response(array('matched' => false, 'reason' => 'processing'), 202);
    }
    if (is_wp_error($result)) {
        return new WP_Error('wab_highlevel', $result->get_error_message(), array('status' => 502));
    }
    return rest_ensure_response(array('matched' => true, 'confidence' => 'exact'));
}

function wab_register_routes()
{
    register_rest_route('wab/v1', '/click', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'wab_click',
        'permission_callback' => 'wab_click_permission',
    ));
    register_rest_route('wab/v1', '/match', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'wab_match',
        'permission_callback' => 'wab_match_permission',
    ));
}
add_action('rest_api_init', 'wab_register_routes');

function wab_admin_menu()
{
    add_menu_page('WhatsApp Attribution', 'WhatsApp Attribution', 'manage_options', 'wab', 'wab_admin_page', 'dashicons-chart-line');
}
add_action('admin_menu', 'wab_admin_menu');

function wab_admin_redirect($notice)
{
    wp_safe_redirect(add_query_arg(array('page' => 'wab', 'wab_notice' => $notice), admin_url('admin.php')));
    exit;
}

function wab_save_settings()
{
    if (!current_user_can('manage_options')) {
        wp_die('Sem permissão.');
    }
    check_admin_referer('wab_save_settings');
    $old = wab_settings();
    $map_json = isset($_POST['field_map']) ? wp_unslash($_POST['field_map']) : '{}';
    $map = json_decode($map_json, true);
    if (!is_array($map)) {
        wab_admin_redirect('invalid_map');
    }
    $clean_map = array();
    foreach ($map as $key => $value) {
        if (!is_scalar($value)) {
            wab_admin_redirect('invalid_map');
        }
        $clean_map[sanitize_key((string) $key)] = sanitize_text_field((string) $value);
    }

    $settings = array(
        'enabled' => !empty($_POST['enabled']),
        'location_id' => sanitize_text_field(isset($_POST['location_id']) ? wp_unslash($_POST['location_id']) : ''),
        'hl_token' => !empty($_POST['hl_token']) ? sanitize_text_field(wp_unslash($_POST['hl_token'])) : $old['hl_token'],
        'webhook_secret' => !empty($_POST['webhook_secret']) ? sanitize_text_field(wp_unslash($_POST['webhook_secret'])) : $old['webhook_secret'],
        'processed_tag' => sanitize_text_field(isset($_POST['processed_tag']) ? wp_unslash($_POST['processed_tag']) : ''),
        'retention_days' => max(1, min(365, isset($_POST['retention_days']) ? (int) $_POST['retention_days'] : 90)),
        'delete_on_uninstall' => !empty($_POST['delete_on_uninstall']),
        'field_map' => $clean_map,
    );
    update_option('wab_settings', $settings, false);
    wab_admin_redirect('settings_saved');
}
add_action('admin_post_wab_save_settings', 'wab_save_settings');

function wab_save_message()
{
    if (!current_user_can('manage_options')) {
        wp_die('Sem permissão.');
    }
    check_admin_referer('wab_save_message');
    $name = sanitize_text_field(isset($_POST['name']) ? wp_unslash($_POST['name']) : '');
    $id = sanitize_key(isset($_POST['message_id']) ? wp_unslash($_POST['message_id']) : '');
    $phone = preg_replace('/\D+/', '', isset($_POST['phone']) ? wp_unslash($_POST['phone']) : '');
    $message = sanitize_textarea_field(isset($_POST['message']) ? wp_unslash($_POST['message']) : '');
    if ($id === '') {
        $id = sanitize_title($name);
    }
    if ($id === '' || strlen($phone) < 10 || $message === '') {
        wab_admin_redirect('invalid_message');
    }

    $messages = wab_messages();
    $messages[$id] = array('name' => $name, 'phone' => $phone, 'message' => $message, 'active' => !empty($_POST['active']));
    update_option('wab_messages', $messages, false);
    wab_admin_redirect('message_saved');
}
add_action('admin_post_wab_save_message', 'wab_save_message');

function wab_delete_message()
{
    if (!current_user_can('manage_options')) {
        wp_die('Sem permissão.');
    }
    check_admin_referer('wab_delete_message');
    $messages = wab_messages();
    $id = sanitize_key(isset($_POST['message_id']) ? wp_unslash($_POST['message_id']) : '');
    if ($id === '') {
        wab_admin_redirect('invalid_message');
    }
    unset($messages[$id]);
    update_option('wab_messages', $messages, false);
    wab_admin_redirect('message_deleted');
}
add_action('admin_post_wab_delete_message', 'wab_delete_message');

function wab_test_connection()
{
    if (!current_user_can('manage_options')) {
        wp_die('Sem permissão.');
    }
    check_admin_referer('wab_test_connection');

    $settings = wab_settings();
    $location = (string) $settings['location_id'];
    if (wab_hl_token() === '' || $location === '') {
        set_transient('wab_conn_test', array('ok' => false, 'text' => 'Token ou Location ID não configurado.'), 300);
        wab_admin_redirect('conn_tested');
    }

    $response = wab_hl_request('GET', 'locations/' . rawurlencode($location) . '/customFields');
    if (is_wp_error($response)) {
        set_transient('wab_conn_test', array('ok' => false, 'text' => 'Falha de rede: ' . $response->get_error_message()), 300);
        wab_admin_redirect('conn_tested');
    }

    $code = wp_remote_retrieve_response_code($response);
    $json = json_decode(wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300) {
        $message = isset($json['message']) && is_scalar($json['message']) ? (string) $json['message'] : '';
        set_transient('wab_conn_test', array('ok' => false, 'text' => 'HTTP ' . $code . ($message !== '' ? ' — ' . $message : '')), 300);
        wab_admin_redirect('conn_tested');
    }

    $ids = array();
    foreach ((array) (isset($json['customFields']) ? $json['customFields'] : array()) as $field) {
        if (!empty($field['id'])) {
            $ids[(string) $field['id']] = true;
        }
    }
    $missing = array();
    foreach ((array) $settings['field_map'] as $logical => $field_id) {
        if ($field_id !== '' && !isset($ids[$field_id])) {
            $missing[] = $logical;
        }
    }

    $text = 'Conexão OK. ' . count($ids) . ' campos personalizados encontrados.';
    if ($missing) {
        $text .= ' Mapeados mas inexistentes no HighLevel: ' . implode(', ', $missing) . '.';
    }
    set_transient('wab_conn_test', array('ok' => !$missing, 'text' => $text), 300);
    wab_admin_redirect('conn_tested');
}
add_action('admin_post_wab_test_connection', 'wab_test_connection');

function wab_local_time($mysql_utc)
{
    if (!$mysql_utc) {
        return '—';
    }
    $timestamp = strtotime($mysql_utc . ' UTC');
    if (!$timestamp) {
        return '—';
    }
    $diff = time() - $timestamp;
    $when = wp_date('d/m/Y H:i', $timestamp);
    return $diff > 0 && $diff < DAY_IN_SECONDS
        ? $when . ' (há ' . human_time_diff($timestamp) . ')'
        : $when;
}

function wab_status_badge($status)
{
    $green = array('matched', 'ok');
    $grey = array('token_pending', 'processing', 'no_token', 'disabled', 'pending');
    $color = in_array($status, $green, true) ? '#008a20' : (in_array($status, $grey, true) ? '#646970' : '#d63638');
    return '<span style="color:' . $color . ';font-weight:600">' . esc_html($status !== '' ? $status : '—') . '</span>';
}

function wab_admin_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    global $wpdb;
    $settings = wab_settings();
    $messages = wab_messages();
    $metrics = $wpdb->get_results('SELECT status, COUNT(*) total FROM ' . wab_table() . ' GROUP BY status', OBJECT_K);
    $requested_view = isset($_GET['view']) ? sanitize_key($_GET['view']) : '';
    $view = in_array($requested_view, array('logs', 'webhooks'), true) ? $requested_view : 'settings';
    $webhook_log = (array) get_option('wab_webhook_log', array());
    $status_filter = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
    $log_records = array();
    if ($view === 'logs') {
        $table = wab_table();
        $allowed_statuses = array('pending', 'processing', 'matched');
        if (in_array($status_filter, $allowed_statuses, true)) {
            $log_records = $wpdb->get_results($wpdb->prepare(
                "SELECT token, message_id, classified_source, status, contact_id, clicked_at, matched_at, attempts, last_error FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT 100",
                $status_filter
            ));
        } else {
            $status_filter = '';
            $log_records = $wpdb->get_results("SELECT token, message_id, classified_source, status, contact_id, clicked_at, matched_at, attempts, last_error FROM {$table} ORDER BY id DESC LIMIT 100");
        }
    }
    $map_example = array(
        'first_source' => '', 'first_campaign' => '', 'first_term' => '', 'first_click_id' => '', 'first_landing' => '',
        'last_source' => '', 'last_campaign' => '', 'last_term' => '', 'last_click_id' => '', 'last_landing' => '',
        'confidence' => '', 'method' => '', 'message_id' => '',
    );
    $map = array_merge($map_example, (array) $settings['field_map']);
    ?>
    <div class="wrap">
        <h1>WhatsApp Attribution Bridge</h1>
        <?php
        $notice_key = isset($_GET['wab_notice']) ? sanitize_key(wp_unslash($_GET['wab_notice'])) : '';
        $notices = array(
            'settings_saved' => array('success', 'Configuração salva.'),
            'message_saved' => array('success', 'Mensagem rastreável salva.'),
            'message_deleted' => array('success', 'Mensagem excluída.'),
            'invalid_map' => array('error', 'O mapa de campos precisa ser um objeto JSON com valores de texto.'),
            'invalid_message' => array('error', 'Preencha nome, número válido e mensagem.'),
            'conn_tested' => array('success', 'Teste de conexão concluído — resultado ao lado do botão.'),
        );
        if (isset($notices[$notice_key])) : ?>
            <div class="notice notice-<?php echo esc_attr($notices[$notice_key][0]); ?> is-dismissible"><p><?php echo esc_html($notices[$notice_key][1]); ?></p></div>
        <?php endif; ?>

        <p><strong>Cliques:</strong> <?php echo esc_html(array_sum(array_map(function ($item) { return (int) $item->total; }, $metrics))); ?> ·
            <strong>Pendentes:</strong> <?php echo esc_html(isset($metrics['pending']) ? $metrics['pending']->total : 0); ?> ·
            <strong>Atribuídos:</strong> <?php echo esc_html(isset($metrics['matched']) ? $metrics['matched']->total : 0); ?></p>

        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url(admin_url('admin.php?page=wab')); ?>" class="nav-tab <?php echo $view === 'settings' ? 'nav-tab-active' : ''; ?>">Configuração</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wab&view=logs')); ?>" class="nav-tab <?php echo $view === 'logs' ? 'nav-tab-active' : ''; ?>">Registros</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wab&view=webhooks')); ?>" class="nav-tab <?php echo $view === 'webhooks' ? 'nav-tab-active' : ''; ?>">Webhooks</a>
        </h2>

        <?php if ($view === 'webhooks') : ?>
            <p class="description">Toda chamada recebida em <code>/wab/v1/match</code>, inclusive as recusadas. Guarda as 50 mais recentes.</p>
            <table class="widefat striped">
                <thead><tr><th>Quando</th><th>Resultado</th><th>Contato</th><th>IP</th><th>Corpo recebido</th></tr></thead>
                <tbody>
                <?php if (!$webhook_log) : ?>
                    <tr><td colspan="5">Nenhum webhook recebido ainda. Se o workflow do HighLevel já rodou, a chamada não chegou até aqui — verifique firewall/segurança e a URL configurada na ação.</td></tr>
                <?php endif; ?>
                <?php foreach ($webhook_log as $entry) : ?>
                    <tr>
                        <td><?php echo esc_html(wab_local_time(isset($entry['at']) ? $entry['at'] : '')); ?></td>
                        <td><?php echo wp_kses_post(wab_status_badge(isset($entry['reason']) ? $entry['reason'] : '')); ?></td>
                        <td><?php echo esc_html(!empty($entry['contact']) ? $entry['contact'] : '—'); ?></td>
                        <td><?php echo esc_html(isset($entry['ip']) ? $entry['ip'] : '—'); ?></td>
                        <td><textarea class="code" readonly rows="2" style="width:100%"><?php echo esc_textarea(isset($entry['body']) ? $entry['body'] : ''); ?></textarea></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($view === 'logs') : ?>
            <?php
            $status_labels = array('' => 'Todos', 'pending' => 'Pendentes', 'processing' => 'Processando', 'matched' => 'Atribuídos');
            $filter_links = array();
            foreach ($status_labels as $value => $label) {
                $url = admin_url('admin.php?page=wab&view=logs' . ($value !== '' ? '&status=' . $value : ''));
                $filter_links[] = $status_filter === $value
                    ? '<strong>' . esc_html($label) . '</strong>'
                    : '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
            }
            echo '<p>' . wp_kses_post(implode(' · ', $filter_links)) . '</p>';
            ?>
            <table class="widefat striped">
                <thead><tr><th>Clique em</th><th>Mensagem</th><th>Origem</th><th>Status</th><th>Contato</th><th>Atribuído em</th><th>Tentativas</th><th>Último erro</th></tr></thead>
                <tbody>
                <?php if (!$log_records) : ?>
                    <tr><td colspan="8">Nenhum registro encontrado.</td></tr>
                <?php endif; ?>
                <?php foreach ($log_records as $record) : ?>
                    <tr>
                        <td><?php echo esc_html(wab_local_time($record->clicked_at)); ?></td>
                        <td><?php echo esc_html($record->message_id); ?></td>
                        <td><?php echo esc_html($record->classified_source); ?></td>
                        <td><?php echo wp_kses_post(wab_status_badge($record->status)); ?></td>
                        <td><?php echo esc_html($record->contact_id ? $record->contact_id : '—'); ?></td>
                        <td><?php echo esc_html(wab_local_time($record->matched_at)); ?></td>
                        <td><?php echo esc_html($record->attempts); ?></td>
                        <td><?php echo esc_html($record->last_error ? $record->last_error : '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">Mostrando os 100 mais recentes. Registros expiram automaticamente após o período de retenção configurado.</p>
        <?php endif; ?>

        <?php if ($view === 'settings') : ?>
        <?php
        $active_messages = 0;
        foreach ($messages as $message) {
            $active_messages += empty($message['active']) ? 0 : 1;
        }
        $mapped = 0;
        foreach ((array) $settings['field_map'] as $field_id) {
            $mapped += $field_id === '' ? 0 : 1;
        }
        $last_webhook = $webhook_log ? $webhook_log[0] : null;
        $conn_test = get_transient('wab_conn_test');
        $checks = array(
            array(!empty($settings['enabled']), 'Rastreamento ativo', 'Marque "Ativo" abaixo — sem isso nada é registrado nem atribuído.'),
            array($settings['location_id'] !== '', 'Location ID configurado', 'Preencha o Location ID da subconta.'),
            array(wab_hl_token() !== '', 'Token do HighLevel presente', 'Defina WAB_HL_TOKEN no wp-config.php ou preencha o campo abaixo.'),
            array($mapped > 0, $mapped . ' campos mapeados', 'Preencha o mapa de campos com os IDs dos campos personalizados.'),
            array($active_messages > 0, $active_messages . ' mensagem(ns) ativa(s)', 'Crie e ative ao menos uma mensagem rastreável.'),
            array($last_webhook !== null, $last_webhook ? 'Último webhook: ' . esc_html($last_webhook['reason']) . ' em ' . esc_html(wab_local_time($last_webhook['at'])) : 'Nenhum webhook recebido', 'O HighLevel nunca chamou este site. Confira a URL na ação do workflow e se algum plugin de segurança bloqueia a rota REST.'),
        );
        ?>
        <h2>Diagnóstico</h2>
        <table class="widefat striped" style="max-width:900px">
            <tbody>
            <?php foreach ($checks as $check) : ?>
                <tr>
                    <td style="width:30px"><?php echo $check[0] ? '✅' : '⚠️'; ?></td>
                    <td><?php echo wp_kses_post($check[1]); ?></td>
                    <td class="description"><?php echo $check[0] ? '' : esc_html($check[2]); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:12px 0">
            <input type="hidden" name="action" value="wab_test_connection">
            <?php wp_nonce_field('wab_test_connection'); ?>
            <?php submit_button('Testar conexão com o HighLevel', 'secondary', 'submit', false); ?>
            <?php if (is_array($conn_test)) : ?>
                <span style="margin-left:10px;color:<?php echo empty($conn_test['ok']) ? '#d63638' : '#008a20'; ?>">
                    <?php echo esc_html($conn_test['text']); ?>
                </span>
            <?php endif; ?>
        </form>

        <h2>Configuração</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wab_save_settings">
            <?php wp_nonce_field('wab_save_settings'); ?>
            <table class="form-table"><tbody>
                <tr><th>Rastreamento</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled']); ?>> Ativo</label></td></tr>
                <tr><th>Location ID</th><td><input class="regular-text" name="location_id" value="<?php echo esc_attr($settings['location_id']); ?>"></td></tr>
                <tr><th>Private Integration Token</th><td><input class="regular-text" type="password" name="hl_token" autocomplete="new-password" placeholder="Deixe vazio para preservar"><p class="description">Preferível: defina <code>WAB_HL_TOKEN</code> no wp-config.php. <?php echo defined('WAB_HL_TOKEN') ? '<strong>Constante detectada.</strong>' : ''; ?></p></td></tr>
                <tr><th>Segredo do webhook</th><td><input class="large-text" type="password" name="webhook_secret" autocomplete="new-password" value="<?php echo esc_attr($settings['webhook_secret']); ?>"></td></tr>
                <tr><th>Tag após atribuição</th><td><input class="regular-text" name="processed_tag" value="<?php echo esc_attr($settings['processed_tag']); ?>"></td></tr>
                <tr><th>Retenção</th><td><input type="number" min="1" max="365" name="retention_days" value="<?php echo esc_attr($settings['retention_days']); ?>"> dias</td></tr>
                <tr><th>Ao desinstalar</th><td><label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked($settings['delete_on_uninstall']); ?>> Apagar tabela, mensagens e configurações</label><p class="description">Desmarcado preserva os dados se o plugin for removido por engano.</p></td></tr>
                <tr><th>Mapa de campos</th><td><textarea class="large-text code" rows="14" name="field_map"><?php echo esc_textarea(wp_json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea><p class="description">Associe as chaves aos IDs dos campos personalizados do contato no HighLevel.</p></td></tr>
            </tbody></table>
            <?php submit_button('Salvar configuração'); ?>
        </form>

        <h2>Workflow do HighLevel</h2>
        <p>POST para <code><?php echo esc_html(rest_url('wab/v1/match')); ?></code> com header <code>Authorization: Bearer SEGREDO</code>.</p>
        <pre>{"contact_id":"{{contact.id}}","location_id":"{{location.id}}","message":"{{message.body}}"}</pre>

        <h2>Mensagens rastreáveis</h2>
        <table class="widefat striped"><thead><tr><th>Nome</th><th>ID</th><th>Link seguro</th><th>Shortcode</th><th></th></tr></thead><tbody>
        <?php if (!$messages) : ?><tr><td colspan="5">Nenhuma mensagem criada.</td></tr><?php endif; ?>
        <?php foreach ($messages as $id => $message) : ?>
            <tr>
                <td><?php echo esc_html($message['name']); ?><?php echo empty($message['active']) ? ' (inativa)' : ''; ?></td>
                <td><code><?php echo esc_html($id); ?></code></td>
                <td><input class="large-text code" readonly value="<?php echo esc_attr(wab_message_link($id, $message)); ?>"></td>
                <td><code>[wab_whatsapp message="<?php echo esc_attr($id); ?>"]</code></td>
                <td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wab_delete_message'); ?><input type="hidden" name="action" value="wab_delete_message"><input type="hidden" name="message_id" value="<?php echo esc_attr($id); ?>"><?php submit_button('Excluir', 'delete small', 'submit', false); ?></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>

        <h3>Nova mensagem</h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wab_save_message">
            <?php wp_nonce_field('wab_save_message'); ?>
            <table class="form-table"><tbody>
                <tr><th>Nome interno</th><td><input class="regular-text" required name="name"></td></tr>
                <tr><th>Identificador</th><td><input class="regular-text" name="message_id" placeholder="agendamento-geral"></td></tr>
                <tr><th>WhatsApp</th><td><input class="regular-text" required name="phone" placeholder="5571999999999"></td></tr>
                <tr><th>Mensagem visível</th><td><textarea class="large-text" required rows="3" name="message" placeholder="Olá! Gostaria de agendar uma consulta."></textarea></td></tr>
                <tr><th>Status</th><td><label><input type="checkbox" name="active" value="1" checked> Ativa</label></td></tr>
            </tbody></table>
            <?php submit_button('Criar mensagem'); ?>
        </form>
        <?php endif; ?>
    </div>
    <?php
}
