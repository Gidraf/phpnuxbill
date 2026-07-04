<?php

/**
 *  CVPAP Bridge — shared helpers: config, HMAC auth, request parsing,
 *  webhook emitter and event hooks.
 **/

if (!function_exists('register_hook')) {
    die('Direct access not allowed');
}

class CvpapApiError extends Exception
{
}

/* ---------------------------------------------------------------- config */

function cvpap_cfg($key, $default = '')
{
    global $config;
    return isset($config[$key]) && $config[$key] !== '' ? $config[$key] : $default;
}

function cvpap_save_cfg($key, $value)
{
    global $config;
    $d = ORM::for_table('tbl_appconfig')->where('setting', $key)->find_one();
    if (!$d) {
        $d = ORM::for_table('tbl_appconfig')->create();
        $d->setting = $key;
    }
    $d->value = $value;
    $d->save();
    $config[$key] = $value;
}

/* ------------------------------------------------------------- requests */

function cvpap_raw_body()
{
    static $body = null;
    if ($body === null) {
        $body = file_get_contents('php://input');
        if ($body === false) {
            $body = '';
        }
    }
    return $body;
}

/**
 * Parsed JSON request body (all bridge actions receive params as JSON).
 */
function cvpap_request_body()
{
    $raw = cvpap_raw_body();
    if (trim($raw) == '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new CvpapApiError('Request body must be a JSON object');
    }
    return $data;
}

function cvpap_param($q, $key, $default = null)
{
    return isset($q[$key]) && $q[$key] !== '' ? $q[$key] : $default;
}

function cvpap_require_params($q, $keys)
{
    $missing = [];
    foreach ($keys as $key) {
        if (!isset($q[$key]) || $q[$key] === '' || $q[$key] === null) {
            $missing[] = $key;
        }
    }
    if (count($missing) > 0) {
        throw new CvpapApiError('Missing required parameter(s): ' . implode(', ', $missing));
    }
}

/**
 * Router-name scope list sent by CVPAP (it owns the partner→router mapping).
 */
function cvpap_routers_scope($q, $required = true)
{
    $routers = cvpap_param($q, 'routers', []);
    if (is_string($routers)) {
        $routers = array_filter(array_map('trim', explode(',', $routers)));
    }
    if (!is_array($routers)) {
        $routers = [];
    }
    if ($required && count($routers) == 0) {
        throw new CvpapApiError('Missing required parameter: routers (router-name scope list)');
    }
    return array_values($routers);
}

/* ----------------------------------------------------------------- auth */

function cvpap_require_hmac()
{
    $secret = cvpap_cfg('cvpap_shared_secret');
    if (empty($secret)) {
        showResult(false, 'CVPAP bridge not configured: set the shared secret in Settings → CVPAP Bridge');
    }
    $ts = isset($_SERVER['HTTP_X_CVPAP_TIMESTAMP']) ? $_SERVER['HTTP_X_CVPAP_TIMESTAMP'] : '';
    $sig = isset($_SERVER['HTTP_X_CVPAP_SIGNATURE']) ? $_SERVER['HTTP_X_CVPAP_SIGNATURE'] : '';
    if (empty($ts) || empty($sig)) {
        showResult(false, 'CVPAP bridge: missing signature headers');
    }
    if (abs(time() - (int) $ts) > 300) {
        showResult(false, 'CVPAP bridge: signature expired');
    }
    $expected = hash_hmac('sha256', $ts . '.' . cvpap_raw_body(), $secret);
    if (!hash_equals($expected, strtolower(trim($sig)))) {
        showResult(false, 'CVPAP bridge: invalid signature');
    }
}

/* ----------------------------------------------------------------- misc */

/**
 * Convert an ORM row / row array to plain arrays, dropping sensitive keys.
 */
function cvpap_rows($rows, $hide = [])
{
    $out = [];
    foreach ($rows as $row) {
        $r = is_array($row) ? $row : $row->as_array();
        foreach ($hide as $h) {
            unset($r[$h]);
        }
        $out[] = $r;
    }
    return $out;
}

/**
 * Tag a nuxbill entity with the CVPAP partner id (webhook hint only —
 * authorization always relies on the router-name scope from CVPAP).
 */
function cvpap_meta_tag($tbl, $tbl_id, $q)
{
    $partner_id = cvpap_param($q, 'partner_id');
    if (empty($partner_id)) {
        return;
    }
    $m = ORM::for_table('tbl_meta')
        ->where('tbl', $tbl)->where('tbl_id', $tbl_id)
        ->where('name', 'cvpap_partner_id')->find_one();
    if (!$m) {
        $m = ORM::for_table('tbl_meta')->create();
        $m->tbl = $tbl;
        $m->tbl_id = $tbl_id;
        $m->name = 'cvpap_partner_id';
    }
    $m->value = $partner_id;
    $m->save();
}

function cvpap_meta_get($tbl, $tbl_id)
{
    $m = ORM::for_table('tbl_meta')
        ->where('tbl', $tbl)->where('tbl_id', $tbl_id)
        ->where('name', 'cvpap_partner_id')->find_one();
    return $m ? $m['value'] : '';
}

/* ------------------------------------------------------------- webhooks */

/**
 * Fire-and-forget signed webhook to CVPAP.
 */
function cvpap_emit($event, $payload)
{
    $url = cvpap_cfg('cvpap_webhook_url');
    $secret = cvpap_cfg('cvpap_shared_secret');
    if (empty($url) || empty($secret)) {
        return;
    }
    $body = json_encode(['event' => $event, 'sent_at' => date('c'), 'data' => $payload]);
    $ts = time();
    $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-CVPAP-Timestamp: ' . $ts,
                'X-CVPAP-Signature: ' . $sig,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (Throwable $e) {
        // never break nuxbill flows because CVPAP is unreachable
    }
}

/* ------------------------------------------------------------ event hooks */

/**
 * recharge_user_finish — fires at the end of Package::rechargeUser().
 * Recharge context lives in globals set by that function.
 */
function cvpap_on_recharge_finish()
{
    global $c, $p, $t, $b, $d;
    $rec = (isset($d) && $d) ? $d : ((isset($b) && $b) ? $b : null);
    $payload = [
        'username' => isset($c['username']) ? $c['username'] : '',
        'fullname' => isset($c['fullname']) ? $c['fullname'] : '',
        'plan_id' => isset($p['id']) ? $p['id'] : '',
        'plan_name' => isset($p['name_plan']) ? $p['name_plan'] : '',
        'plan_type' => isset($p['type']) ? $p['type'] : '',
        'router' => $rec ? $rec['routers'] : (isset($t['routers']) ? $t['routers'] : ''),
        'expiration' => $rec ? $rec['expiration'] . ' ' . $rec['time'] : '',
        'invoice' => isset($t['invoice']) ? $t['invoice'] : '',
        'price' => isset($t['price']) ? $t['price'] : '',
        'method' => isset($t['method']) ? $t['method'] : '',
        'partner_id' => isset($p['id']) ? cvpap_meta_get('tbl_plans', $p['id']) : '',
    ];
    cvpap_emit('recharge_finished', $payload);
}

/**
 * customer_activate_voucher — fires when a customer redeems a voucher
 * through nuxbill's own UI (redemptions via the bridge are reported to
 * CVPAP synchronously in the API response).
 */
function cvpap_on_voucher_activate()
{
    cvpap_emit('voucher_activated', [
        'code' => alphanumeric(_post('code'), "-_.,"),
    ]);
}

/**
 * cronjob_end — after nuxbill's cron deactivated expired recharges.
 * Reports recharges that flipped to off within the last window; the CVPAP
 * receiver is idempotent, overlap is fine.
 */
function cvpap_on_cron_end()
{
    $since = date('Y-m-d H:i:s', strtotime('-15 minutes'));
    $rows = ORM::for_table('tbl_user_recharges')
        ->where('status', 'off')
        ->whereRaw("CONCAT(expiration, ' ', time) >= '$since'")
        ->whereRaw("CONCAT(expiration, ' ', time) <= NOW()")
        ->find_array();
    if (count($rows) == 0) {
        return;
    }
    $expired = [];
    foreach ($rows as $r) {
        $expired[] = [
            'recharge_id' => $r['id'],
            'customer_id' => $r['customer_id'],
            'username' => $r['username'],
            'plan_id' => $r['plan_id'],
            'plan_name' => $r['namebp'],
            'router' => $r['routers'],
            'expiration' => $r['expiration'] . ' ' . $r['time'],
        ];
    }
    cvpap_emit('recharges_expired', ['recharges' => $expired]);
}
