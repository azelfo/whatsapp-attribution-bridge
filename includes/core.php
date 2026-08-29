<?php

if (!defined('WAB_MARKER_LENGTH')) {
    define('WAB_MARKER_LENGTH', 48);
}

function wab_core_encode_marker($token)
{
    $token = strtolower((string) $token);
    if (!preg_match('/^[a-f0-9]{12}$/', $token)) {
        return '';
    }

    $bits = '';
    foreach (str_split($token) as $hex) {
        $bits .= str_pad(base_convert($hex, 16, 2), 4, '0', STR_PAD_LEFT);
    }

    return strtr($bits, array('0' => "\u{200B}", '1' => "\u{200C}"));
}

function wab_core_decode_tokens($message)
{
    if (!is_string($message) || $message === '') {
        return array();
    }

    preg_match_all('/(?<![\x{200B}\x{200C}])[\x{200B}\x{200C}]{' . WAB_MARKER_LENGTH . '}(?![\x{200B}\x{200C}])/u', $message, $matches);
    $tokens = array();

    foreach ($matches[0] as $marker) {
        $bits = strtr($marker, array("\u{200B}" => '0', "\u{200C}" => '1'));
        $token = '';
        for ($offset = 0; $offset < WAB_MARKER_LENGTH; $offset += 4) {
            $token .= dechex(bindec(substr($bits, $offset, 4)));
        }
        $tokens[$token] = true;
    }

    return array_keys($tokens);
}

function wab_core_classify_source(array $payload)
{
    foreach (array('gclid', 'gbraid', 'wbraid') as $key) {
        if (!empty($payload[$key])) {
            return 'google_ads';
        }
    }

    $source = strtolower(trim(isset($payload['utm_source']) ? (string) $payload['utm_source'] : ''));
    if (!empty($payload['fbclid']) || in_array($source, array('fb_ad', 'facebook', 'instagram', 'meta'), true)) {
        return 'meta_ads';
    }

    if ($source !== '') {
        return preg_replace('/[^a-z0-9_.-]+/', '_', $source);
    }

    $referrer = isset($payload['referrer']) ? (string) $payload['referrer'] : '';
    $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host);

    if ($host === '') {
        return 'direct';
    }

    foreach (array('google.', 'bing.com', 'yahoo.', 'duckduckgo.com') as $search_host) {
        if (strpos($host, $search_host) !== false) {
            return 'organic_search';
        }
    }

    foreach (array('facebook.com', 'instagram.com', 'linkedin.com', 'tiktok.com', 'x.com', 'twitter.com') as $social_host) {
        if ($host === $social_host || substr($host, -strlen('.' . $social_host)) === '.' . $social_host) {
            return 'organic_social';
        }
    }

    return 'referral';
}

function wab_core_log_reason($error_code, array $data)
{
    if ($error_code !== '') {
        return (string) $error_code;
    }
    if (isset($data['reason']) && is_scalar($data['reason'])) {
        return (string) $data['reason'];
    }
    return !empty($data['matched']) ? 'matched' : 'unknown';
}

function wab_core_body_field(array $data, array $custom, $key)
{
    if (isset($custom[$key]) && is_scalar($custom[$key])) {
        return (string) $custom[$key];
    }
    if (isset($data[$key]) && is_scalar($data[$key])) {
        return (string) $data[$key];
    }
    return '';
}

function wab_core_contact_fields(array $contact)
{
    $fields = array();
    foreach ((array) (isset($contact['customFields']) ? $contact['customFields'] : array()) as $field) {
        if (!is_array($field) || empty($field['id'])) {
            continue;
        }
        $recognized = false;
        $filled = true;
        foreach (array('fieldValue', 'value', 'field_value', 'fieldValueString', 'valueString', 'valueNumber') as $key) {
            if (!array_key_exists($key, $field)) {
                continue;
            }
            $recognized = true;
            $value = $field[$key];
            $filled = is_scalar($value) ? trim((string) $value) !== '' : !empty($value);
            break;
        }
        $fields[(string) $field['id']] = array('recognized' => $recognized, 'filled' => $filled);
    }
    return $fields;
}
