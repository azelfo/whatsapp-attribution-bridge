<?php

require dirname(__DIR__) . '/includes/core.php';

function wab_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$token = '3f2a91c7d4e8';
$marker = wab_core_encode_marker($token);
wab_assert(count(preg_split('//u', $marker, -1, PREG_SPLIT_NO_EMPTY)) === 48, 'Marcador deve ter 48 caracteres.');
wab_assert(wab_core_decode_tokens($marker . 'Olá' . $marker) === array($token), 'Token deve sobreviver duplicado.');
wab_assert(wab_core_decode_tokens('📅 ' . $marker . ' Quero agendar') === array($token), 'Token deve sobreviver com emoji e texto ao redor.');
wab_assert(wab_core_decode_tokens($marker . "\u{200B}") === array(), 'Sequência desalinhada não pode virar token.');
wab_assert(wab_core_classify_source(array('gclid' => 'x')) === 'google_ads', 'GCLID deve ser Google Ads.');
wab_assert(wab_core_classify_source(array('fbclid' => 'x')) === 'meta_ads', 'FBCLID deve ser Meta Ads.');
wab_assert(wab_core_classify_source(array('referrer' => 'https://www.google.com/search?q=x')) === 'organic_search', 'Google sem click ID deve ser orgânico.');
wab_assert(wab_core_classify_source(array('referrer' => 'https://www.instagram.com/perfil')) === 'organic_social', 'Instagram sem click ID deve ser social orgânico.');
wab_assert(wab_core_classify_source(array()) === 'direct', 'Sem sinais deve ser direto.');

$fields = wab_core_contact_fields(array('customFields' => array(
    array('id' => 'a', 'fieldValue' => ''),
    array('id' => 'b', 'value' => 'original'),
    array('id' => 'c', 'field_value' => 'legado'),
    array('id' => 'd', 'fieldValueString' => 'texto'),
    array('id' => 'e', 'formatoNovo' => ''),
)));
wab_assert($fields['a']['recognized'] && !$fields['a']['filled'], 'Campo reconhecido vazio pode receber first-touch.');
wab_assert($fields['b']['filled'] && $fields['c']['filled'] && $fields['d']['filled'], 'Formatos conhecidos preenchidos devem ser preservados.');
wab_assert(!$fields['e']['recognized'] && $fields['e']['filled'], 'Formato desconhecido deve falhar fechado e preservar first-touch.');

wab_assert(wab_core_body_field(array(), array('location_id' => 'AQx123'), 'location_id') === 'AQx123', 'Deve ler de customData quando presente.');
wab_assert(wab_core_body_field(array('location_id' => 'raiz'), array(), 'location_id') === 'raiz', 'Deve cair para a raiz quando customData não tem a chave.');
wab_assert(wab_core_body_field(array('location_id' => 'raiz'), array('location_id' => 'custom'), 'location_id') === 'custom', 'customData tem prioridade sobre a raiz.');
wab_assert(wab_core_body_field(array(), array(), 'location_id') === '', 'Ausente nos dois deve retornar vazio.');

wab_assert(wab_core_log_reason('wab_location', array()) === 'wab_location', 'Erro deve logar o codigo do WP_Error.');
wab_assert(wab_core_log_reason('', array('matched' => false, 'reason' => 'no_token')) === 'no_token', 'Resposta com reason deve logar o reason.');
wab_assert(wab_core_log_reason('', array('matched' => true, 'confidence' => 'exact')) === 'matched', 'Sucesso sem reason deve logar matched.');
wab_assert(wab_core_log_reason('', array()) === 'unknown', 'Sem codigo e sem dados deve logar unknown.');

$campaign_keys = array('utm_campaign', 'utm_id', 'campaign_id');
wab_assert(wab_core_payload_value(array('utm_campaign' => 'nome', 'utm_id' => '123'), $campaign_keys) === 'nome', 'utm_campaign tem precedencia sobre utm_id.');
wab_assert(wab_core_payload_value(array('utm_id' => '123', 'campaign_id' => '999'), $campaign_keys) === '123', 'utm_id (padrao GA4) vem antes do campaign_id legado.');
wab_assert(wab_core_payload_value(array('campaign_id' => '999'), $campaign_keys) === '999', 'campaign_id legado continua funcionando.');
wab_assert(wab_core_payload_value(array('utm_campaign' => ''), $campaign_keys) === '', 'Chave vazia nao conta como preenchida.');

echo "core-test.php: ok\n";
