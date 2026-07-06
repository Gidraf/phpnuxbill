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

function cvpap_platform_config_defaults()
{
    return [
        'advanced_settings_owner' => 'superadmin',
        'communications_owner' => 'cvpap',
        'billing_owner' => 'superadmin',
        'partner_access' => ['routers', 'customers', 'logs'],
    ];
}

function cvpap_platform_config()
{
    $defaults = cvpap_platform_config_defaults();
    $raw = cvpap_cfg('cvpap_platform_config_json', '');
    if ($raw == '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    $cfg = array_merge($defaults, $decoded);
    if (!is_array($cfg['partner_access'])) {
        $cfg['partner_access'] = $defaults['partner_access'];
    }
    $cfg['partner_access'] = array_values(array_unique(array_filter(array_map('strval', $cfg['partner_access']))));
    return $cfg;
}

function cvpap_save_platform_config($input)
{
    $current = cvpap_platform_config();
    $cfg = is_array($input) ? array_merge($current, $input) : $current;

    $owners = ['superadmin', 'partner'];
    foreach (['advanced_settings_owner', 'communications_owner', 'billing_owner'] as $key) {
        if (!empty($cfg[$key])) {
            $val = strtolower(trim((string) $cfg[$key]));
            if (in_array($val, $owners)) {
                $cfg[$key] = $val;
            } else {
                $cfg[$key] = $current[$key];
            }
        } else {
            $cfg[$key] = $current[$key];
        }
    }

    if (isset($cfg['partner_access']) && is_string($cfg['partner_access'])) {
        $cfg['partner_access'] = array_filter(array_map('trim', explode(',', $cfg['partner_access'])));
    }
    if (!isset($cfg['partner_access']) || !is_array($cfg['partner_access'])) {
        $cfg['partner_access'] = $current['partner_access'];
    }
    $allowed_partner_access = ['routers', 'customers', 'logs'];
    $cfg['partner_access'] = array_values(array_unique(array_intersect($allowed_partner_access, array_map('strval', $cfg['partner_access']))));
    if (count($cfg['partner_access']) == 0) {
        $cfg['partner_access'] = $current['partner_access'];
    }

    cvpap_save_cfg('cvpap_platform_config_json', json_encode($cfg));
    return $cfg;
}

function cvpap_actor_role($q, $default = 'superadmin')
{
    $role = strtolower(trim((string) cvpap_param($q, 'actor_role', $default)));
    return in_array($role, ['superadmin', 'partner']) ? $role : $default;
}

function cvpap_assert_superadmin_actor($q)
{
    if (cvpap_actor_role($q, 'superadmin') != 'superadmin') {
        throw new CvpapApiError('This action is restricted to CVPAP superadmin');
    }
}

function cvpap_sql($sql)
{
        try {
                ORM::get_db()->exec($sql);
        } catch (Throwable $e) {
                _log('CVPAP schema error: ' . $e->getMessage(), 'CVPAP');
        }
}

function cvpap_ensure_schema()
{
        static $done = false;
        if ($done) {
                return;
        }
        $done = true;

        cvpap_sql("CREATE TABLE IF NOT EXISTS `tbl_cvpap_partners` (
            `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
            `partner_uid` varchar(80) NOT NULL,
            `partner_code` varchar(100) DEFAULT NULL,
            `parent_partner_uid` varchar(80) DEFAULT NULL,
            `admin_user_id` int UNSIGNED DEFAULT NULL,
            `username` varchar(64) NOT NULL DEFAULT '',
            `fullname` varchar(128) NOT NULL DEFAULT '',
            `email` varchar(128) NOT NULL DEFAULT '',
            `phone` varchar(32) NOT NULL DEFAULT '',
            `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
            `settings_json` mediumtext,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_partner_uid` (`partner_uid`),
            KEY `idx_partner_admin_user` (`admin_user_id`),
            KEY `idx_partner_parent` (`parent_partner_uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        cvpap_sql("CREATE TABLE IF NOT EXISTS `tbl_cvpap_partner_routers` (
            `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
            `partner_uid` varchar(80) NOT NULL,
            `router_id` int NOT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_partner_router` (`partner_uid`,`router_id`),
            UNIQUE KEY `uniq_router_owner` (`router_id`),
            KEY `idx_partner_router_partner` (`partner_uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        cvpap_sql("CREATE TABLE IF NOT EXISTS `tbl_cvpap_partner_customers` (
            `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
            `partner_uid` varchar(80) NOT NULL,
            `customer_id` int NOT NULL,
            `external_customer_id` varchar(80) DEFAULT NULL,
            `external_phone` varchar(32) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_partner_customer` (`partner_uid`,`customer_id`),
            KEY `idx_partner_external_customer` (`partner_uid`,`external_customer_id`),
            KEY `idx_partner_external_phone` (`partner_uid`,`external_phone`),
            KEY `idx_partner_customer_customer` (`customer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        cvpap_sql("CREATE TABLE IF NOT EXISTS `tbl_cvpap_partner_webhooks` (
            `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
            `partner_uid` varchar(80) NOT NULL,
            `owner_type` enum('partner','customer') NOT NULL DEFAULT 'partner',
            `customer_id` int NOT NULL DEFAULT '0',
            `event_name` varchar(64) NOT NULL DEFAULT '*',
            `url` varchar(512) NOT NULL,
            `secret` varchar(128) NOT NULL,
            `headers_json` mediumtext,
            `enabled` tinyint(1) NOT NULL DEFAULT '1',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_partner_webhooks_partner` (`partner_uid`,`owner_type`,`customer_id`,`enabled`),
            KEY `idx_partner_webhooks_event` (`event_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        cvpap_sql("CREATE TABLE IF NOT EXISTS `tbl_cvpap_sso_tokens` (
            `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
            `token_hash` char(64) NOT NULL,
            `partner_uid` varchar(80) NOT NULL,
            `admin_user_id` int UNSIGNED NOT NULL,
            `redirect_to` varchar(255) NOT NULL DEFAULT 'dashboard',
            `expires_at` datetime NOT NULL,
            `used_at` datetime DEFAULT NULL,
            `request_ip` varchar(64) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_sso_token_hash` (`token_hash`),
            KEY `idx_sso_partner_expires` (`partner_uid`,`expires_at`),
            KEY `idx_sso_admin` (`admin_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
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

function cvpap_partner_uid($q, $required = false)
{
    $partner_uid = trim((string) cvpap_param($q, 'partner_id', ''));
    if ($required && $partner_uid == '') {
        throw new CvpapApiError('Missing required parameter: partner_id');
    }
    return $partner_uid;
}

/**
 * Resolve the tenant a nuxbill login owns (its scoped partner), or null for
 * platform admins. Used by the scoped partner console.
 */
function cvpap_partner_by_admin($admin_user_id)
{
    cvpap_ensure_schema();
    $admin_user_id = (int) $admin_user_id;
    if ($admin_user_id <= 0) {
        return null;
    }
    return ORM::for_table('tbl_cvpap_partners')
        ->where('admin_user_id', $admin_user_id)->find_one();
}

function cvpap_partner_find($partner_uid)
{
    cvpap_ensure_schema();
    $partner_uid = trim((string) $partner_uid);
    if ($partner_uid == '') {
        return null;
    }
    return ORM::for_table('tbl_cvpap_partners')->where('partner_uid', $partner_uid)->find_one();
}

function cvpap_partner_upsert_row($q, $admin_user_id = 0)
{
    cvpap_ensure_schema();
    $partner_uid = cvpap_partner_uid($q, true);
    $row = cvpap_partner_find($partner_uid);
    if (!$row) {
        $row = ORM::for_table('tbl_cvpap_partners')->create();
        $row->partner_uid = $partner_uid;
    }
    if (!empty($q['partner_code']) || !empty($q['partnerId'])) {
        $row->partner_code = !empty($q['partner_code']) ? $q['partner_code'] : $q['partnerId'];
    }
    if (array_key_exists('parent_partner_id', $q)) {
        $row->parent_partner_uid = trim((string) $q['parent_partner_id']);
    }
    if (!empty($q['username'])) {
        $row->username = trim((string) $q['username']);
    }
    if (!empty($q['fullname'])) {
        $row->fullname = trim((string) $q['fullname']);
    }
    if (!empty($q['email'])) {
        $row->email = trim((string) $q['email']);
    }
    if (!empty($q['phone'])) {
        $row->phone = trim((string) $q['phone']);
    }
    if (!empty($q['status']) && in_array($q['status'], ['Active', 'Inactive'])) {
        $row->status = $q['status'];
    }
    if (array_key_exists('admin_user_id', $q)) {
        $admin_user_id = (int) $q['admin_user_id'];
    }
    if (!empty($admin_user_id)) {
        $row->admin_user_id = (int) $admin_user_id;
    } else if (array_key_exists('admin_user_id', $q)) {
        $row->admin_user_id = null;
    }
    if (array_key_exists('settings', $q)) {
        $settings = $q['settings'];
        if (is_array($settings)) {
            $row->settings_json = json_encode($settings);
        } else if (is_string($settings)) {
            $row->settings_json = $settings;
        }
    }
    $row->save();
    return $row;
}

/**
 * Ensure the tenant has a backing tbl_users OWNER login (the business owner
 * who logs into nuxbill directly, mirroring the CVPAP partner). Created with
 * user_type='Partner' — a type NONE of nuxbill's stock data controllers
 * authorize, so those pages auto-deny; the tenant's own scoped plugin pages
 * are their workspace. Staff are synced separately as 'Agent'.
 *
 * $q may carry: username, fullname, email, phone, password, status.
 * Returns the tbl_users id (also written to the tenant row's admin_user_id).
 */
function cvpap_partner_ensure_owner_login($row, $q)
{
    $owner = null;
    if (!empty($row['admin_user_id'])) {
        $owner = ORM::for_table('tbl_users')->find_one((int) $row['admin_user_id']);
    }
    // fall back to matching an existing user by the tenant username
    if (!$owner && !empty($row['username'])) {
        $owner = ORM::for_table('tbl_users')->where('username', $row['username'])->find_one();
    }

    $created = false;
    if (!$owner) {
        $owner = ORM::for_table('tbl_users')->create();
        $owner->username = !empty($row['username']) ? $row['username']
            : (!empty($row['email']) ? $row['email'] : 'partner_' . $row['partner_uid']);
        $owner->user_type = 'Partner';   // deny-by-default in all stock controllers
        $owner->creationdate = date('Y-m-d H:i:s');
        // random placeholder until CVPAP forwards the real password
        $owner->password = Password::_crypt(bin2hex(random_bytes(16)));
        $created = true;
    }

    if (!empty($row['fullname'])) {
        $owner->fullname = $row['fullname'];
    }
    if (!empty($row['email'])) {
        $owner->email = $row['email'];
    }
    if (!empty($row['phone'])) {
        $owner->phone = $row['phone'];
    }
    // keep the owner type as Partner unless it is a native admin already
    if ($created || empty($owner->user_type)) {
        $owner->user_type = 'Partner';
    }
    $status = cvpap_param($q, 'status', $row['status']);
    if (in_array($status, ['Active', 'Inactive'])) {
        $owner->status = $status;
    }
    if (!empty($q['password'])) {
        $owner->password = Password::_crypt($q['password']);
    }
    $owner->save();

    $owner_id = (int) $owner->id();
    if ((int) $row['admin_user_id'] !== $owner_id) {
        $row->admin_user_id = $owner_id;
        $row->save();
    }
    return $owner_id;
}

function cvpap_partner_router_link($partner_uid, $router_id)
{
    cvpap_ensure_schema();
    $partner_uid = trim((string) $partner_uid);
    $router_id = (int) $router_id;
    if ($partner_uid == '' || $router_id <= 0) {
        return;
    }

    ORM::for_table('tbl_cvpap_partner_routers')->where('router_id', $router_id)->delete_many();

    $link = ORM::for_table('tbl_cvpap_partner_routers')->create();
    $link->partner_uid = $partner_uid;
    $link->router_id = $router_id;
    $link->save();
}

function cvpap_partner_router_unlink($router_id)
{
    cvpap_ensure_schema();
    ORM::for_table('tbl_cvpap_partner_routers')->where('router_id', (int) $router_id)->delete_many();
}

function cvpap_partner_router_names($partner_uid)
{
    cvpap_ensure_schema();
    $partner_uid = trim((string) $partner_uid);
    if ($partner_uid == '') {
        return [];
    }
    $links = ORM::for_table('tbl_cvpap_partner_routers')
        ->where('partner_uid', $partner_uid)
        ->find_many();
    if (count($links) == 0) {
        return [];
    }
    $router_ids = [];
    foreach ($links as $link) {
        $router_ids[] = (int) $link['router_id'];
    }
    if (count($router_ids) == 0) {
        return [];
    }
    $routers = ORM::for_table('tbl_routers')->where_in('id', $router_ids)->find_many();
    $names = [];
    foreach ($routers as $router) {
        $names[] = $router['name'];
    }
    return array_values(array_unique($names));
}

function cvpap_partner_customer_link($partner_uid, $customer_id, $external_customer_id = '', $external_phone = '')
{
    cvpap_ensure_schema();
    $partner_uid = trim((string) $partner_uid);
    $customer_id = (int) $customer_id;
    if ($partner_uid == '' || $customer_id <= 0) {
        return null;
    }
    $row = ORM::for_table('tbl_cvpap_partner_customers')
        ->where('partner_uid', $partner_uid)
        ->where('customer_id', $customer_id)
        ->find_one();
    if (!$row) {
        $row = ORM::for_table('tbl_cvpap_partner_customers')->create();
        $row->partner_uid = $partner_uid;
        $row->customer_id = $customer_id;
    }
    if ($external_customer_id !== null && $external_customer_id !== '') {
        $row->external_customer_id = trim((string) $external_customer_id);
    }
    if ($external_phone !== null && $external_phone !== '') {
        $row->external_phone = cvpap_identity_digits($external_phone);
    }
    $row->save();
    return $row;
}

function cvpap_partner_uid_for_customer($customer_id)
{
    cvpap_ensure_schema();
    $row = ORM::for_table('tbl_cvpap_partner_customers')
        ->where('customer_id', (int) $customer_id)
        ->order_by_desc('id')
        ->find_one();
    return $row ? $row['partner_uid'] : '';
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
    if (count($routers) == 0) {
        $partner_uid = cvpap_partner_uid($q, false);
        if ($partner_uid != '') {
            $routers = cvpap_partner_router_names($partner_uid);
        }
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

/**
 * Resolve a table row id by CVPAP partner id stored in tbl_meta.
 */
function cvpap_meta_find_tbl_id($tbl, $partner_id)
{
    $pid = trim((string) $partner_id);
    if ($pid == '') {
        return '';
    }
    $m = ORM::for_table('tbl_meta')
        ->where('tbl', $tbl)
        ->where('name', 'cvpap_partner_id')
        ->where('value', $pid)
        ->order_by_desc('id')
        ->find_one();
    return $m ? $m['tbl_id'] : '';
}

/**
 * Keep only digits for phone/username identity matching.
 */
function cvpap_identity_digits($value)
{
    return preg_replace('/\D+/', '', (string) $value);
}

function cvpap_issue_sso_token($partner_uid, $redirect_to = 'dashboard', $ttl_seconds = 120)
{
    cvpap_ensure_schema();
    $partner = cvpap_partner_find($partner_uid);
    if (!$partner || empty($partner['admin_user_id'])) {
        throw new CvpapApiError('Partner is not linked to a nuxbill admin user');
    }
    $ttl_seconds = max(30, min((int) $ttl_seconds, 600));
    $token_plain = bin2hex(random_bytes(24));

    $row = ORM::for_table('tbl_cvpap_sso_tokens')->create();
    $row->token_hash = hash('sha256', $token_plain);
    $row->partner_uid = $partner_uid;
    $row->admin_user_id = (int) $partner['admin_user_id'];
    $row->redirect_to = trim((string) $redirect_to) == '' ? 'dashboard' : $redirect_to;
    $row->expires_at = date('Y-m-d H:i:s', time() + $ttl_seconds);
    $row->request_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $row->save();

    return [
        'token' => $token_plain,
        'expires_at' => $row->expires_at,
        'login_url' => APP_URL . '/?_route=admin/cvpap_sso&token=' . urlencode($token_plain),
        'admin_user_id' => (int) $partner['admin_user_id'],
    ];
}

function cvpap_consume_sso_token($token_plain)
{
    cvpap_ensure_schema();
    $token_plain = trim((string) $token_plain);
    if ($token_plain == '') {
        return null;
    }
    $hash = hash('sha256', $token_plain);
    $row = ORM::for_table('tbl_cvpap_sso_tokens')
        ->where('token_hash', $hash)
        ->where_null('used_at')
        ->where_gte('expires_at', date('Y-m-d H:i:s'))
        ->find_one();
    if (!$row) {
        return null;
    }
    $row->used_at = date('Y-m-d H:i:s');
    $row->save();
    return [
        'admin_user_id' => (int) $row['admin_user_id'],
        'partner_uid' => $row['partner_uid'],
        'redirect_to' => $row['redirect_to'],
    ];
}

/* ------------------------------------------------- password links & email */

/**
 * Create a set/reset-password link for a customer. Reuses the forgot-password
 * token store, so the link lands on the "choose a new password" form.
 * Valid 20 minutes.
 */
function cvpap_password_reset_link($username)
{
    global $CACHE_PATH, $db_pass;
    $dir = $CACHE_PATH . File::pathFixer('/forgot/');
    if (!file_exists($dir)) {
        mkdir($dir);
    }
    $otp = mt_rand(100000, 999999);
    file_put_contents($dir . sha1($username . $db_pass) . ".txt", $otp);
    return APP_URL . '/?_route=forgot&step=2&username=' . urlencode($username) . '&otp_code=' . $otp;
}

/**
 * Colorful tech-styled welcome email (inline CSS — email-client safe).
 * No temporary passwords: the CTA is a set-your-password link.
 */
function cvpap_welcome_html($brand, $fullname, $username, $portal_url, $reset_link)
{
    $name = htmlspecialchars($fullname ?: $username);
    $user = htmlspecialchars($username);
    $b = htmlspecialchars($brand);
    return '
<!DOCTYPE html>
<html><body style="margin:0;padding:0;background:#0f172a;font-family:Segoe UI,Roboto,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0f172a;padding:32px 12px;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
  <tr><td style="background:linear-gradient(135deg,#2563eb,#7c3aed,#db2777);border-radius:16px 16px 0 0;padding:36px 32px;text-align:center;">
    <div style="font-size:44px;line-height:1;">&#128246;</div>
    <h1 style="color:#ffffff;margin:12px 0 4px;font-size:26px;letter-spacing:0.5px;">Welcome to ' . $b . '!</h1>
    <p style="color:#dbeafe;margin:0;font-size:15px;">Fast internet. Zero hassle. You&rsquo;re in, ' . $name . ' &#127881;</p>
  </td></tr>
  <tr><td style="background:#ffffff;padding:32px;">
    <p style="color:#0f172a;font-size:15px;margin:0 0 18px;">Your account is ready. One last step &mdash; choose your own password (we never send passwords by email):</p>
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr><td align="center" style="padding:6px 0 22px;">
      <a href="' . $reset_link . '" style="display:inline-block;background:linear-gradient(135deg,#2563eb,#7c3aed);color:#ffffff;text-decoration:none;font-weight:700;font-size:16px;padding:14px 36px;border-radius:10px;">&#128273; Set My Password</a>
    </td></tr></table>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;border-radius:12px;">
      <tr><td style="padding:16px 20px;">
        <p style="margin:0 0 6px;color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Your login details</p>
        <p style="margin:0;color:#0f172a;font-size:15px;">Portal: <a href="' . $portal_url . '" style="color:#2563eb;">' . $portal_url . '</a></p>
        <p style="margin:4px 0 0;color:#0f172a;font-size:15px;">Username: <b>' . $user . '</b></p>
        <p style="margin:4px 0 0;color:#0f172a;font-size:15px;">Password: <i>set it with the button above</i> &#128077;</p>
      </td></tr>
    </table>
    <p style="color:#64748b;font-size:13px;margin:20px 0 0;">The link is valid for 20 minutes. Missed it? Use &ldquo;Forgot password&rdquo; on the portal to get a new one anytime.</p>
  </td></tr>
  <tr><td style="background:#0f172a;border-radius:0 0 16px 16px;padding:20px 32px;text-align:center;">
    <p style="color:#94a3b8;font-size:12px;margin:0;">Need help? Just reply to this email &mdash; the ' . $b . ' team is here for you.</p>
  </td></tr>
</table>
</td></tr></table>
</body></html>';
}

/* ------------------------------------------------------------- webhooks */

function cvpap_webhook_post($url, $secret, $event, $payload)
{
    if (trim((string) $url) == '' || trim((string) $secret) == '') {
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
    }
}

function cvpap_partner_webhooks($partner_uid, $event, $customer_id = 0)
{
    cvpap_ensure_schema();
    $partner_uid = trim((string) $partner_uid);
    if ($partner_uid == '') {
        return [];
    }
    $query = ORM::for_table('tbl_cvpap_partner_webhooks')
        ->where('partner_uid', $partner_uid)
        ->where('enabled', 1)
        ->where_any_is([
            ['event_name' => '*'],
            ['event_name' => $event],
        ]);
    if ((int) $customer_id > 0) {
        $query->where_any_is([
            ['owner_type' => 'partner'],
            ['owner_type' => 'customer', 'customer_id' => (int) $customer_id],
        ]);
    } else {
        $query->where('owner_type', 'partner');
    }
    return $query->find_many();
}

function cvpap_emit_partner_webhooks($event, $payload)
{
    if ($event == 'recharges_expired' && isset($payload['recharges']) && is_array($payload['recharges'])) {
        foreach ($payload['recharges'] as $recharge) {
            if (!is_array($recharge)) {
                continue;
            }
            cvpap_emit_partner_webhooks('recharge_expired', $recharge);
        }
        return;
    }
    $partner_uid = !empty($payload['partner_id']) ? $payload['partner_id'] : '';
    $customer_id = !empty($payload['customer_id']) ? (int) $payload['customer_id'] : 0;
    if ($partner_uid == '' && $customer_id > 0) {
        $partner_uid = cvpap_partner_uid_for_customer($customer_id);
    }
    if ($partner_uid == '') {
        return;
    }
    foreach (cvpap_partner_webhooks($partner_uid, $event, $customer_id) as $hook) {
        cvpap_webhook_post($hook['url'], $hook['secret'], $event, $payload);
    }
}

/**
 * Fire-and-forget signed webhook to CVPAP.
 */
function cvpap_emit($event, $payload)
{
    cvpap_webhook_post(cvpap_cfg('cvpap_webhook_url'), cvpap_cfg('cvpap_shared_secret'), $event, $payload);
    cvpap_emit_partner_webhooks($event, $payload);
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
    $customer_id = 0;
    if ($rec && !empty($rec['customer_id'])) {
        $customer_id = (int) $rec['customer_id'];
    } else if (isset($c['id'])) {
        $customer_id = (int) $c['id'];
    }
    $payload = [
        'customer_id' => $customer_id,
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
    $code = alphanumeric(_post('code'), "-_.,");
    $voucher = ORM::for_table('tbl_voucher')->where('code', $code)->find_one();
    $customer_id = 0;
    if ($voucher && !empty($voucher['user'])) {
        $customer = ORM::for_table('tbl_customers')->where('username', $voucher['user'])->find_one();
        if ($customer) {
            $customer_id = (int) $customer['id'];
        }
    }
    cvpap_emit('voucher_activated', [
        'code' => $code,
        'customer_id' => $customer_id,
        'partner_id' => $voucher ? cvpap_meta_get('tbl_voucher', $voucher['id']) : '',
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
        $partner_id = cvpap_meta_get('tbl_plans', $r['plan_id']);
        if ($partner_id == '' && !empty($r['customer_id'])) {
            $partner_id = cvpap_partner_uid_for_customer((int) $r['customer_id']);
        }
        $expired[] = [
            'recharge_id' => $r['id'],
            'customer_id' => $r['customer_id'],
            'username' => $r['username'],
            'plan_id' => $r['plan_id'],
            'plan_name' => $r['namebp'],
            'router' => $r['routers'],
            'expiration' => $r['expiration'] . ' ' . $r['time'],
            'partner_id' => $partner_id,
        ];
    }
    cvpap_emit('recharges_expired', ['recharges' => $expired]);
}
