<?php

/**
 *  CVPAP Bridge — phpNuxBill plugin
 *
 *  Exposes a JSON API consumed by the CVPAP platform and emits signed
 *  webhooks back to CVPAP on recharge / voucher / expiry events.
 *
 *  API entry: system/api.php?r=plugin/cvpap_api/<action>&token=<api_key>
 *  All requests must carry X-CVPAP-Timestamp / X-CVPAP-Signature headers
 *  (HMAC-SHA256 over "<timestamp>.<raw body>" with the shared secret),
 *  except the "ping" action.
 *
 *  Settings page: Settings menu → CVPAP Bridge (webhook URL + shared secret).
 *
 *  NOTE: phpNuxBill's run_hook() only executes the FIRST hook registered per
 *  action. This plugin registers recharge_user_finish, customer_activate_voucher
 *  and cronjob_end — if another plugin needs the same hooks, load order matters.
 **/

if (!function_exists('register_hook')) {
    die('Direct access not allowed');
}

include_once __DIR__ . DIRECTORY_SEPARATOR . 'cvpap' . DIRECTORY_SEPARATOR . 'lib.php';
include_once __DIR__ . DIRECTORY_SEPARATOR . 'cvpap' . DIRECTORY_SEPARATOR . 'actions.php';
include_once __DIR__ . DIRECTORY_SEPARATOR . 'cvpap' . DIRECTORY_SEPARATOR . 'console.php';

register_hook('recharge_user_finish', 'cvpap_on_recharge_finish');
register_hook('customer_activate_voucher', 'cvpap_on_voucher_activate');
register_hook('cronjob_end', 'cvpap_on_cron_end');

register_menu("CVPAP Bridge", true, "cvpap_settings", "SETTINGS", "ion-link", "", "", ['SuperAdmin', 'Admin']);
register_menu("My Business", true, "cvpap_partner_console", "MAIN", "ion-briefcase", "", "", ['Partner', 'SuperAdmin', 'Admin']);

/**
 * Partner landing: a 'Partner'-type owner has no access to stock pages, so
 * send them straight to their scoped console instead of the stock dashboard.
 * Runs at plugin-load time (session already started in index.php).
 */
if (isset($_GET['_route'])) {
    $__cvpap_land = explode('/', $_GET['_route']);
    $__cvpap_land0 = isset($__cvpap_land[0]) ? $__cvpap_land[0] : '';
    if (in_array($__cvpap_land0, ['', 'dashboard'], true) && class_exists('Admin')) {
        $__cvpap_aid = Admin::getID();
        if ($__cvpap_aid) {
            $__cvpap_me = ORM::for_table('tbl_users')->find_one((int) $__cvpap_aid);
            if ($__cvpap_me && $__cvpap_me['user_type'] === 'Partner') {
                header('Location: ' . APP_URL . '/?_route=plugin/cvpap_partner_console');
                exit();
            }
        }
    }
}

/**
 * SSO landing: consume a one-time token issued by partner_sso_issue and log
 * the browser into the partner owner's scoped nuxbill session.
 * URL: /?_route=admin/cvpap_sso&token=<token>
 * The route matches because $routes[0]='admin' and $routes[1]='cvpap_sso' —
 * but admin.php is a controller; we intercept early here on plugin load.
 */
if (isset($_GET['_route'])) {
    $__cvpap_route = explode('/', $_GET['_route']);
    if (isset($__cvpap_route[0], $__cvpap_route[1])
        && $__cvpap_route[0] === 'admin' && $__cvpap_route[1] === 'cvpap_sso') {
        cvpap_sso_login_handler();
    }
}

function cvpap_sso_login_handler()
{
    $token = isset($_GET['token']) ? $_GET['token'] : '';
    $session = cvpap_consume_sso_token($token);
    if (!$session || empty($session['admin_user_id'])) {
        header('Location: ' . APP_URL . '/?_route=admin&sso=invalid');
        exit();
    }
    $admin = ORM::for_table('tbl_users')->find_one((int) $session['admin_user_id']);
    if (!$admin) {
        header('Location: ' . APP_URL . '/?_route=admin&sso=invalid');
        exit();
    }
    // establish the nuxbill admin session (same mechanism admin.php login uses)
    Admin::setCookie($admin['id']);
    $_SESSION['aid'] = $admin['id'];
    $admin->last_login = date('Y-m-d H:i:s');
    $admin->save();
    // _log() is defined later in init.php than the plugin-include block, so
    // it may not exist yet at this point — guard it.
    if (function_exists('_log')) {
        _log($admin['username'] . ' CVPAP SSO login', $admin['user_type'], $admin['id']);
    }

    $redirect = !empty($session['redirect_to']) ? $session['redirect_to'] : 'dashboard';
    // only allow internal relative routes
    $redirect = preg_replace('/[^a-zA-Z0-9_\/-]/', '', $redirect);
    header('Location: ' . APP_URL . '/?_route=' . $redirect);
    exit();
}

/**
 * API dispatcher. Reached through system/api.php which already validated the
 * static api_key (sets $admin to a SuperAdmin/Admin row) before including
 * controllers/plugin.php, which calls this function.
 */
function cvpap_api()
{
    global $admin, $routes;

    cvpap_ensure_schema();

    if (!isset($admin) || empty($admin['id']) || !in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
        showResult(false, 'CVPAP bridge: unauthorized');
    }

    $action = isset($routes[2]) ? preg_replace('/[^a-z0-9_]/', '', strtolower($routes[2])) : '';
    $fn = 'cvpap_act_' . $action;
    if (empty($action) || !function_exists($fn)) {
        showResult(false, "CVPAP bridge: unknown action [$action]");
    }

    if ($action != 'ping') {
        cvpap_require_hmac();
    }

    $q = cvpap_request_body();

    // Buffer any stray output from device drivers / core helpers so the
    // response stays valid JSON, and never let an exception bubble to HTML.
    ob_start();
    try {
        $result = call_user_func($fn, $q);
        while (ob_get_level()) {
            ob_end_clean();
        }
        showResult(true, '', is_array($result) ? $result : []);
    } catch (CvpapApiError $e) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        showResult(false, $e->getMessage());
    } catch (Throwable $e) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        _log('CVPAP bridge error [' . $action . ']: ' . $e->getMessage(), 'CVPAP');
        showResult(false, 'CVPAP bridge error: ' . $e->getMessage());
    }
}

/**
 * Admin settings page (Settings → CVPAP Bridge).
 */
function cvpap_settings()
{
    global $admin;

    cvpap_ensure_schema();

    _admin();
    $admin = Admin::_info();
    if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
        r2(getUrl('dashboard'), 'e', Lang::T('You do not have permission to access this page'));
    }

    if (_post('save') == 'save') {
        cvpap_save_cfg('cvpap_webhook_url', _post('cvpap_webhook_url'));
        $secret = _post('cvpap_shared_secret');
        if (!empty($secret)) {
            cvpap_save_cfg('cvpap_shared_secret', $secret);
        }
        r2(getUrl('plugin/cvpap_settings'), 's', 'CVPAP Bridge settings saved');
    }

    $url = cvpap_cfg('cvpap_webhook_url');
    $hasSecret = cvpap_cfg('cvpap_shared_secret') != '';

    echo '<!DOCTYPE html><html><head><title>CVPAP Bridge</title>'
        . '<style>body{font-family:sans-serif;max-width:640px;margin:40px auto;padding:0 16px;color:#222}'
        . 'label{display:block;margin:16px 0 4px;font-weight:bold}input{width:100%;padding:8px;box-sizing:border-box}'
        . 'button{margin-top:16px;padding:10px 24px;background:#2c7be5;color:#fff;border:0;border-radius:4px;cursor:pointer}'
        . 'p.hint{color:#777;font-size:13px;margin:4px 0 0}</style></head><body>'
        . '<h2>CVPAP Bridge</h2>'
        . '<p>Connects this phpNuxBill to the CVPAP platform. The API is served at '
        . '<code>system/api.php?r=plugin/cvpap_api/&lt;action&gt;&amp;token=&lt;api key&gt;</code>.</p>'
        . '<form method="post">'
        . '<label>CVPAP Webhook URL</label>'
        . '<input type="url" name="cvpap_webhook_url" value="' . htmlspecialchars($url) . '" placeholder="https://api.example.com/api/v1/wifi/webhooks/nuxbill">'
        . '<p class="hint">Recharge / voucher / expiry events are POSTed here, signed with the shared secret.</p>'
        . '<label>Shared Secret</label>'
        . '<input type="password" name="cvpap_shared_secret" value="" placeholder="' . ($hasSecret ? '(configured — leave blank to keep)' : '(not configured)') . '">'
        . '<p class="hint">Must match NUXBILL_WEBHOOK_SECRET on the CVPAP side. Used to sign API requests and webhooks.</p>'
        . '<button type="submit" name="save" value="save">Save</button>'
        . '</form>'
        . '<p><a href="' . getUrl('settings/app') . '">&laquo; Back to settings</a></p>'
        . '</body></html>';
    die();
}
