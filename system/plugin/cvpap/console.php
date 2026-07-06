<?php

/**
 *  CVPAP Bridge — scoped Partner Console (Phase B).
 *
 *  The workspace for a business-owner login (user_type='Partner'). nuxbill's
 *  stock data controllers deny that type, so this self-contained page is what
 *  a partner actually uses: their OWN routers, packages, customers, active
 *  subscriptions, transactions and vouchers — all filtered by partner_uid /
 *  their owned routers. Reached at ?_route=plugin/cvpap_partner_console.
 *
 *  SuperAdmin/Admin may open any tenant with ?partner_uid=<uid> for support.
 **/

if (!function_exists('register_hook')) {
    die('Direct access not allowed');
}

function cvpap_partner_console()
{
    global $admin, $config;
    cvpap_ensure_schema();
    _admin();
    $admin = Admin::_info();

    // Resolve the tenant this session is scoped to.
    $partner = null;
    if (in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
        $uid = _get('partner_uid');
        if ($uid) {
            $partner = cvpap_partner_find($uid);
        }
    } else {
        $partner = cvpap_partner_by_admin($admin['id']);
    }

    if (!$partner) {
        cvpap_console_shell($config, 'My Business',
            '<div class="empty">No business is linked to this account yet. '
            . 'If you just enabled WiFi on CVPAP, give it a moment and refresh.</div>');
        return;
    }

    $uid = $partner['partner_uid'];
    $routers = cvpap_partner_router_names($uid);
    $brand = htmlspecialchars($partner['fullname'] ?: $partner['username'] ?: $uid);

    // ── scoped data ─────────────────────────────────────────────────────────
    $routerRows = $routers ? ORM::for_table('tbl_routers')->where_in('name', $routers)->find_many() : [];

    $planRows = $routers ? ORM::for_table('tbl_plans')->where_in('routers', $routers)
        ->order_by_desc('id')->limit(100)->find_many() : [];

    $custLinks = ORM::for_table('tbl_cvpap_partner_customers')->where('partner_uid', $uid)
        ->order_by_desc('id')->limit(100)->find_many();
    $custIds = [];
    foreach ($custLinks as $l) {
        $custIds[] = (int) $l['customer_id'];
    }
    $custRows = $custIds ? ORM::for_table('tbl_customers')->where_in('id', $custIds)
        ->find_many() : [];

    $activeCount = 0;
    $txRows = [];
    $txTotal = 0.0;
    $voucherRows = [];
    if ($routers) {
        $activeCount = ORM::for_table('tbl_user_recharges')->where_in('routers', $routers)
            ->where('status', 'on')->count();
        $txRows = ORM::for_table('tbl_transactions')->where_in('routers', $routers)
            ->order_by_desc('id')->limit(30)->find_many();
        foreach (ORM::for_table('tbl_transactions')->where_in('routers', $routers)
            ->where_gte('recharged_on', date('Y-m-d', strtotime('-30 days')))->find_many() as $t) {
            $txTotal += (float) $t['price'];
        }
        $voucherRows = ORM::for_table('tbl_voucher')->where_in('routers', $routers)
            ->order_by_desc('id')->limit(50)->find_many();
    }

    // ── render ──────────────────────────────────────────────────────────────
    $money = function ($v) { return 'KSh ' . number_format((float) $v, 0); };
    $esc = function ($v) { return htmlspecialchars((string) $v); };

    // Info banner — management lives in CVPAP; this console is a scoped view.
    $dash = cvpap_cfg('cvpap_dashboard_url', '');
    $dashLink = $dash
        ? ' <a href="' . htmlspecialchars($dash) . '" style="color:#93c5fd">Open your CVPAP dashboard &rarr;</a>'
        : '';
    $html = '<div style="background:#1e293b;border:1px solid #334155;border-radius:12px;'
        . 'padding:14px 18px;margin-bottom:18px;color:#cbd5e1;font-size:14px">'
        . '&#8505;&#65039; This is your read-only WiFi overview. To add routers, create packages, '
        . 'generate vouchers or run reports, use your <b>CVPAP dashboard &rarr; WiFi Billing</b>.'
        . $dashLink . '</div>';

    $html .= '<div class="cards">'
        . cvpap_card('Routers', count($routerRows))
        . cvpap_card('Active subscriptions', $activeCount)
        . cvpap_card('Customers', count($custLinks))
        . cvpap_card('Revenue (30d)', $money($txTotal))
        . '</div>';

    // routers
    $html .= '<h3>Routers</h3><table><tr><th>Name</th><th>IP</th><th>Status</th></tr>';
    foreach ($routerRows as $r) {
        $html .= '<tr><td>' . $esc($r['name']) . '</td><td>' . $esc($r['ip_address'])
            . '</td><td>' . $esc($r['status'] ?: 'unknown') . '</td></tr>';
    }
    if (!$routerRows) {
        $html .= '<tr><td colspan="3" class="muted">No routers assigned yet.</td></tr>';
    }
    $html .= '</table>';

    // packages
    $html .= '<h3>Packages</h3><table><tr><th>Plan</th><th>Type</th><th>Price</th><th>Validity</th><th>Router</th></tr>';
    foreach ($planRows as $p) {
        $html .= '<tr><td>' . $esc($p['name_plan']) . '</td><td>' . $esc($p['type'])
            . '</td><td>' . $money($p['price']) . '</td><td>' . $esc($p['validity'] . ' ' . $p['validity_unit'])
            . '</td><td>' . $esc($p['routers']) . '</td></tr>';
    }
    if (!$planRows) {
        $html .= '<tr><td colspan="5" class="muted">No packages yet.</td></tr>';
    }
    $html .= '</table>';

    // customers
    $html .= '<h3>Customers</h3><table><tr><th>Username</th><th>Name</th><th>Phone</th><th>Status</th></tr>';
    foreach ($custRows as $c) {
        $html .= '<tr><td>' . $esc($c['username']) . '</td><td>' . $esc($c['fullname'])
            . '</td><td>' . $esc($c['phonenumber']) . '</td><td>' . $esc($c['status']) . '</td></tr>';
    }
    if (!$custRows) {
        $html .= '<tr><td colspan="4" class="muted">No customers linked yet.</td></tr>';
    }
    $html .= '</table>';

    // recent transactions
    $html .= '<h3>Recent transactions</h3><table><tr><th>Invoice</th><th>Customer</th><th>Plan</th><th>Amount</th><th>Method</th><th>Date</th></tr>';
    foreach ($txRows as $t) {
        $html .= '<tr><td>' . $esc($t['invoice']) . '</td><td>' . $esc($t['username'])
            . '</td><td>' . $esc($t['plan_name']) . '</td><td>' . $money($t['price'])
            . '</td><td>' . $esc($t['method']) . '</td><td>' . $esc($t['recharged_on']) . '</td></tr>';
    }
    if (!$txRows) {
        $html .= '<tr><td colspan="6" class="muted">No transactions yet.</td></tr>';
    }
    $html .= '</table>';

    // vouchers
    $html .= '<h3>Vouchers</h3><table><tr><th>Code</th><th>Status</th><th>Used by</th><th>Used on</th></tr>';
    foreach ($voucherRows as $v) {
        $html .= '<tr><td>' . $esc($v['code']) . '</td><td>' . ($v['status'] == '1' ? 'Used' : 'Unused')
            . '</td><td>' . $esc($v['user']) . '</td><td>' . $esc($v['used_date']) . '</td></tr>';
    }
    if (!$voucherRows) {
        $html .= '<tr><td colspan="4" class="muted">No vouchers generated yet.</td></tr>';
    }
    $html .= '</table>';

    cvpap_console_shell($config, $brand, $html);
}

function cvpap_card($label, $value)
{
    return '<div class="card"><div class="v">' . htmlspecialchars((string) $value)
        . '</div><div class="l">' . htmlspecialchars($label) . '</div></div>';
}

function cvpap_console_shell($config, $title, $body)
{
    $company = htmlspecialchars(isset($config['CompanyName']) ? $config['CompanyName'] : 'WiFi Billing');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . ' — ' . $company . '</title><style>'
        . 'body{font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:0}'
        . '.top{background:#1e293b;padding:16px 24px;display:flex;justify-content:space-between;align-items:center}'
        . '.top h1{font-size:18px;margin:0;color:#fff}.top a{color:#93c5fd;text-decoration:none;font-size:14px}'
        . '.wrap{max-width:1000px;margin:0 auto;padding:24px}'
        . 'h3{margin:28px 0 10px;color:#fff;font-size:16px}'
        . '.cards{display:flex;flex-wrap:wrap;gap:12px}'
        . '.card{flex:1;min-width:150px;background:#1e293b;border-radius:12px;padding:16px}'
        . '.card .v{font-size:24px;font-weight:700;color:#fff}.card .l{font-size:12px;color:#94a3b8;text-transform:uppercase;margin-top:4px}'
        . 'table{width:100%;border-collapse:collapse;background:#1e293b;border-radius:10px;overflow:hidden}'
        . 'th,td{text-align:left;padding:10px 12px;font-size:13px;border-bottom:1px solid #334155}'
        . 'th{background:#0f172a;color:#94a3b8;text-transform:uppercase;font-size:11px}'
        . '.muted{color:#64748b}.empty{background:#1e293b;border-radius:12px;padding:32px;text-align:center;color:#94a3b8}'
        . '</style></head><body>'
        . '<div class="top"><h1>' . htmlspecialchars($title) . '</h1>'
        . '<a href="' . getUrl('logout') . '">Sign out</a></div>'
        . '<div class="wrap">' . $body . '</div></body></html>';
    die();
}
