<?php

/**
 *  CVPAP Bridge — API action handlers.
 *
 *  Every handler receives the parsed JSON body and returns an array
 *  (serialized by the dispatcher via showResult). Throw CvpapApiError
 *  for expected validation failures.
 *
 *  Partner scoping: CVPAP resolves its partner→router mapping server-side
 *  and passes the router-name list as `routers`; handlers never trust a
 *  partner id for authorization.
 **/

if (!function_exists('register_hook')) {
    die('Direct access not allowed');
}

/* ------------------------------------------------------------------ ping */

function cvpap_act_ping($q)
{
    global $config;
    return [
        'bridge' => 'cvpap',
        'bridge_version' => '1.0.0',
        'nuxbill_version' => isset($config['version']) ? $config['version'] : '',
        'time' => date('c'),
    ];
}

/* --------------------------------------------------------------- routers */

function cvpap_act_router_list($q)
{
    $query = ORM::for_table('tbl_routers');
    $scope = cvpap_routers_scope($q, false);
    if (count($scope) > 0) {
        $query->where_in('name', $scope);
    }
    $routers = cvpap_rows($query->find_many(), ['password']);
    foreach ($routers as &$router) {
        $owner = cvpap_meta_get('tbl_routers', $router['id']);
        if ($owner == '') {
            $link = ORM::for_table('tbl_cvpap_partner_routers')->where('router_id', $router['id'])->find_one();
            if ($link) {
                $owner = $link['partner_uid'];
            }
        }
        $router['partner_id'] = $owner;
    }
    return ['routers' => $routers];
}

function cvpap_act_router_get($q)
{
    $r = cvpap_router_find($q);
    $router = $r->as_array();
    unset($router['password']);
    $router['partner_id'] = cvpap_meta_get('tbl_routers', $r['id']);
    if ($router['partner_id'] == '') {
        $link = ORM::for_table('tbl_cvpap_partner_routers')->where('router_id', $r['id'])->find_one();
        if ($link) {
            $router['partner_id'] = $link['partner_uid'];
        }
    }
    return ['router' => $router];
}

function cvpap_act_router_create($q)
{
    cvpap_require_params($q, ['name', 'ip_address', 'username', 'password']);
    $exists = ORM::for_table('tbl_routers')->where('name', $q['name'])->find_one();
    if ($exists) {
        throw new CvpapApiError('Router name already exists');
    }
    $r = ORM::for_table('tbl_routers')->create();
    $r->name = $q['name'];
    $r->ip_address = $q['ip_address'];
    $r->username = $q['username'];
    $r->password = $q['password'];
    $r->description = cvpap_param($q, 'description', '');
    $r->coordinates = cvpap_param($q, 'coordinates', '');
    $r->coverage = cvpap_param($q, 'coverage', '');
    $r->enabled = (int) cvpap_param($q, 'enabled', 1);
    $r->save();
    cvpap_meta_tag('tbl_routers', $r->id(), $q);
    $partner_uid = cvpap_partner_uid($q, false);
    if ($partner_uid != '') {
        cvpap_partner_router_link($partner_uid, $r->id());
    }
    return ['id' => $r->id(), 'name' => $r['name']];
}

function cvpap_act_router_update($q)
{
    $r = cvpap_router_find($q);
    foreach (['name', 'ip_address', 'username', 'password', 'description', 'coordinates', 'coverage', 'enabled'] as $field) {
        if (isset($q[$field]) && $q[$field] !== '') {
            $r->$field = $q[$field];
        }
    }
    $r->save();
    cvpap_meta_tag('tbl_routers', $r['id'], $q);
    if (array_key_exists('partner_id', $q)) {
        $partner_uid = trim((string) $q['partner_id']);
        if ($partner_uid == '') {
            cvpap_partner_router_unlink($r['id']);
        } else {
            cvpap_partner_router_link($partner_uid, $r['id']);
        }
    }
    return ['id' => $r['id'], 'name' => $r['name']];
}

function cvpap_act_router_delete($q)
{
    $r = cvpap_router_find($q);
    $id = $r['id'];
    cvpap_partner_router_unlink($id);
    $r->delete();
    return ['deleted' => $id];
}

function cvpap_act_router_status($q)
{
    $names = cvpap_routers_scope($q);
    $statuses = [];
    foreach ($names as $name) {
        $statuses[] = cvpap_router_probe($name);
    }
    return ['statuses' => $statuses];
}

function cvpap_act_online_users($q)
{
    $names = cvpap_routers_scope($q);
    $online = [];
    $errors = [];
    foreach ($names as $name) {
        $router = Mikrotik::info($name);
        if (!$router) {
            $errors[$name] = 'Router not found';
            continue;
        }
        try {
            $client = Mikrotik::getClient($router['ip_address'], $router['username'], $router['password']);
            if ($client === null) { // demo mode
                continue;
            }
            $responses = $client->sendSync(new PEAR2\Net\RouterOS\Request('/ip/hotspot/active/print'));
            foreach ($responses as $response) {
                if ($response->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                    $online[] = [
                        'router' => $name,
                        'username' => $response->getProperty('user'),
                        'address' => $response->getProperty('address'),
                        'mac_address' => $response->getProperty('mac-address'),
                        'uptime' => $response->getProperty('uptime'),
                        'bytes_in' => $response->getProperty('bytes-in'),
                        'bytes_out' => $response->getProperty('bytes-out'),
                        'login_by' => $response->getProperty('login-by'),
                    ];
                }
            }
        } catch (Throwable $e) {
            $errors[$name] = $e->getMessage();
        }
    }
    return ['online' => $online, 'errors' => $errors];
}

function cvpap_act_disconnect_user($q)
{
    cvpap_require_params($q, ['router', 'username']);
    cvpap_assert_router_in_scope($q, $q['router']);
    $router = Mikrotik::info($q['router']);
    if (!$router) {
        throw new CvpapApiError('Router not found');
    }
    $client = Mikrotik::getClient($router['ip_address'], $router['username'], $router['password']);
    if ($client !== null) {
        Mikrotik::logMeOut($client, $q['username']);
    }
    return ['disconnected' => $q['username'], 'router' => $q['router']];
}

/* ------------------------------------------------- hotspot bypass devices */

function cvpap_bypass_client($q)
{
    cvpap_require_params($q, ['router']);
    cvpap_assert_router_in_scope($q, $q['router']);
    $router = Mikrotik::info($q['router']);
    if (!$router) {
        throw new CvpapApiError('Router not found');
    }
    return Mikrotik::getClient($router['ip_address'], $router['username'], $router['password']);
}

/**
 * List hotspot ip-bindings plus currently-seen hosts (so the dashboard can
 * offer a picker instead of asking people to find MAC addresses by hand).
 */
function cvpap_act_bypass_list($q)
{
    $client = cvpap_bypass_client($q);
    if ($client === null) { // demo mode
        return ['bindings' => [], 'hosts' => [], 'demo' => true];
    }
    $bindings = [];
    $responses = $client->sendSync(new PEAR2\Net\RouterOS\Request('/ip/hotspot/ip-binding/print'));
    foreach ($responses as $r) {
        if ($r->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
            $bindings[] = [
                'id' => $r->getProperty('.id'),
                'mac_address' => $r->getProperty('mac-address'),
                'address' => $r->getProperty('address'),
                'type' => $r->getProperty('type'),
                'comment' => $r->getProperty('comment'),
            ];
        }
    }
    $hosts = [];
    $responses = $client->sendSync(new PEAR2\Net\RouterOS\Request('/ip/hotspot/host/print'));
    foreach ($responses as $r) {
        if ($r->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
            $hosts[] = [
                'mac_address' => $r->getProperty('mac-address'),
                'address' => $r->getProperty('address'),
            ];
        }
    }
    return ['bindings' => $bindings, 'hosts' => $hosts];
}

/**
 * Bypass a device: it skips the captive portal login entirely (owner laptops,
 * office printers, CCTV...). Idempotent per MAC. Works any time the router is
 * reachable - before or after the hotspot itself is configured (bindings
 * simply sit there until a hotspot uses them, and persist across reboots).
 */
function cvpap_act_bypass_add($q)
{
    cvpap_require_params($q, ['router', 'mac']);
    $mac = strtoupper(trim($q['mac']));
    if (!preg_match('/^([0-9A-F]{2}[:-]){5}[0-9A-F]{2}$/', $mac)) {
        throw new CvpapApiError('Invalid MAC address - use the format AA:BB:CC:DD:EE:FF');
    }
    $mac = str_replace('-', ':', $mac);
    $client = cvpap_bypass_client($q);
    if ($client === null) {
        return ['added' => false, 'demo' => true];
    }
    // replace any existing binding for this MAC (idempotent)
    $printRequest = new PEAR2\Net\RouterOS\Request('/ip/hotspot/ip-binding/print');
    $printRequest->setArgument('.proplist', '.id');
    $printRequest->setQuery(PEAR2\Net\RouterOS\Query::where('mac-address', $mac));
    $id = $client->sendSync($printRequest)->getProperty('.id');
    if (!empty($id)) {
        $rm = new PEAR2\Net\RouterOS\Request('/ip/hotspot/ip-binding/remove');
        $rm->setArgument('numbers', $id);
        $client->sendSync($rm);
    }
    $label = trim((string) cvpap_param($q, 'label', ''));
    $add = new PEAR2\Net\RouterOS\Request('/ip/hotspot/ip-binding/add');
    $add->setArgument('mac-address', $mac);
    $add->setArgument('type', 'bypassed');
    $add->setArgument('comment', 'cvpap: ' . ($label !== '' ? $label : 'bypassed device'));
    foreach ($client->sendSync($add) as $r) {
        if ($r->getType() === PEAR2\Net\RouterOS\Response::TYPE_ERROR) {
            throw new CvpapApiError('Router rejected the binding: ' . $r->getProperty('message'));
        }
    }
    return ['added' => true, 'mac_address' => $mac, 'label' => $label];
}

function cvpap_act_bypass_remove($q)
{
    cvpap_require_params($q, ['router', 'mac']);
    $mac = strtoupper(str_replace('-', ':', trim($q['mac'])));
    $client = cvpap_bypass_client($q);
    if ($client === null) {
        return ['removed' => 0, 'demo' => true];
    }
    $printRequest = new PEAR2\Net\RouterOS\Request('/ip/hotspot/ip-binding/print');
    $printRequest->setArgument('.proplist', '.id');
    $printRequest->setQuery(PEAR2\Net\RouterOS\Query::where('mac-address', $mac));
    $removed = 0;
    foreach ($client->sendSync($printRequest) as $r) {
        if ($r->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
            $rm = new PEAR2\Net\RouterOS\Request('/ip/hotspot/ip-binding/remove');
            $rm->setArgument('numbers', $r->getProperty('.id'));
            $client->sendSync($rm);
            $removed++;
        }
    }
    return ['removed' => $removed, 'mac_address' => $mac];
}


/**
 * Install the CVPAP captive-portal redirect as the hotspot login page.
 * Runs `/tool/fetch` on the router (over the tunnel) to pull login.html from
 * CVPAP straight into the hotspot/ folder — so the partner never touches the
 * terminal. Requires the hotspot to already be set up (the hotspot/ dir exists).
 */
function cvpap_act_hotspot_install_page($q)
{
    cvpap_require_params($q, ['router', 'login_url']);
    cvpap_assert_router_in_scope($q, $q['router']);
    $router = Mikrotik::info($q['router']);
    if (!$router) {
        throw new CvpapApiError('Router not found');
    }
    $dst = isset($q['dst_path']) && $q['dst_path'] !== '' ? $q['dst_path'] : 'hotspot/login.html';

    $client = Mikrotik::getClient($router['ip_address'], $router['username'], $router['password']);
    if ($client === null) { // demo/offline mode
        return ['installed' => false, 'router' => $q['router'], 'demo' => true];
    }

    $request = new PEAR2\Net\RouterOS\Request('/tool/fetch');
    $request->setArgument('url', $q['login_url']);
    $request->setArgument('dst-path', $dst);
    // https so the router validates the CVPAP cert; check-certificate off avoids
    // failures on routers without an up-to-date CA bundle.
    $request->setArgument('mode', 'https');
    $request->setArgument('check-certificate', 'no');

    $status = null;
    $error = null;
    try {
        $responses = $client->sendSync($request);
        foreach ($responses as $response) {
            if ($response->getType() === PEAR2\Net\RouterOS\Response::TYPE_ERROR) {
                $error = $response->getProperty('message') ?: 'fetch failed';
            }
            $s = $response->getProperty('status');
            if ($s) {
                $status = $s;
            }
        }
    } catch (Throwable $e) {
        throw new CvpapApiError('Could not install portal page: ' . $e->getMessage());
    }
    if ($error) {
        throw new CvpapApiError('Router rejected the fetch: ' . $error);
    }
    return [
        'installed' => true,
        'router' => $q['router'],
        'dst_path' => $dst,
        'status' => $status ?: 'finished',
    ];
}

/* ------------------------------------------------------------- bandwidth */

function cvpap_act_bandwidth_list($q)
{
    return ['bandwidths' => cvpap_rows(ORM::for_table('tbl_bandwidth')->find_many())];
}

function cvpap_act_bandwidth_create($q)
{
    cvpap_require_params($q, ['name_bw', 'rate_down', 'rate_down_unit', 'rate_up', 'rate_up_unit']);
    $b = ORM::for_table('tbl_bandwidth')->create();
    $b->name_bw = $q['name_bw'];
    $b->rate_down = $q['rate_down'];
    $b->rate_down_unit = $q['rate_down_unit'];
    $b->rate_up = $q['rate_up'];
    $b->rate_up_unit = $q['rate_up_unit'];
    $b->burst = cvpap_param($q, 'burst', '');
    $b->save();
    return ['id' => $b->id(), 'name_bw' => $b['name_bw']];
}

function cvpap_act_bandwidth_update($q)
{
    cvpap_require_params($q, ['id']);
    $b = ORM::for_table('tbl_bandwidth')->find_one($q['id']);
    if (!$b) {
        throw new CvpapApiError('Bandwidth profile not found');
    }
    foreach (['name_bw', 'rate_down', 'rate_down_unit', 'rate_up', 'rate_up_unit', 'burst'] as $field) {
        if (isset($q[$field]) && $q[$field] !== '') {
            $b->$field = $q[$field];
        }
    }
    $b->save();
    return ['id' => $b['id']];
}

function cvpap_act_bandwidth_delete($q)
{
    cvpap_require_params($q, ['id']);
    $used = ORM::for_table('tbl_plans')->where('id_bw', $q['id'])->count();
    if ($used > 0) {
        throw new CvpapApiError("Bandwidth profile is used by $used plan(s)");
    }
    $b = ORM::for_table('tbl_bandwidth')->find_one($q['id']);
    if ($b) {
        $b->delete();
    }
    return ['deleted' => $q['id']];
}

/* ----------------------------------------------------------------- plans */

function cvpap_act_plan_list($q)
{
    $query = ORM::for_table('tbl_plans')->where('type', cvpap_param($q, 'type', 'Hotspot'));
    $scope = cvpap_routers_scope($q, false);
    if (count($scope) > 0) {
        $query->where_in('routers', $scope);
    }
    $plans = cvpap_rows($query->find_many());
    // attach bandwidth details
    $bwIds = array_unique(array_column($plans, 'id_bw'));
    $bws = [];
    if (count($bwIds) > 0) {
        foreach (ORM::for_table('tbl_bandwidth')->where_in('id', $bwIds)->find_many() as $bw) {
            $bws[$bw['id']] = $bw->as_array();
        }
    }
    foreach ($plans as &$p) {
        $p['bandwidth'] = isset($bws[$p['id_bw']]) ? $bws[$p['id_bw']] : null;
        $p['partner_id'] = cvpap_meta_get('tbl_plans', $p['id']);
    }
    return ['plans' => $plans];
}

function cvpap_act_plan_get($q)
{
    cvpap_require_params($q, ['id']);
    $p = ORM::for_table('tbl_plans')->find_one($q['id']);
    if (!$p) {
        throw new CvpapApiError('Plan not found');
    }
    $plan = $p->as_array();
    $plan['bandwidth'] = null;
    $bw = ORM::for_table('tbl_bandwidth')->find_one($p['id_bw']);
    if ($bw) {
        $plan['bandwidth'] = $bw->as_array();
    }
    $plan['partner_id'] = cvpap_meta_get('tbl_plans', $p['id']);
    return ['plan' => $plan];
}

function cvpap_act_plan_create($q)
{
    cvpap_require_params($q, ['name_plan', 'id_bw', 'price', 'validity', 'validity_unit', 'routers']);
    $router = is_array($q['routers']) ? $q['routers'][0] : $q['routers'];
    if (!Mikrotik::info($router)) {
        throw new CvpapApiError("Router [$router] not found");
    }
    if (!ORM::for_table('tbl_bandwidth')->find_one($q['id_bw'])) {
        throw new CvpapApiError('Bandwidth profile not found');
    }
    if (!in_array($q['validity_unit'], ['Mins', 'Hrs', 'Days', 'Months', 'Period'])) {
        throw new CvpapApiError('Invalid validity_unit (Mins|Hrs|Days|Months|Period)');
    }
    $dup = ORM::for_table('tbl_plans')->where('name_plan', $q['name_plan'])->where('type', 'Hotspot')->find_one();
    if ($dup) {
        throw new CvpapApiError('Plan name already exists');
    }

    $p = ORM::for_table('tbl_plans')->create();
    $p->name_plan = $q['name_plan'];
    $p->id_bw = $q['id_bw'];
    $p->price = $q['price'];
    $p->type = 'Hotspot';
    $p->typebp = cvpap_param($q, 'typebp', 'Unlimited');
    $p->plan_type = cvpap_param($q, 'plan_type', 'Business');
    // limit fields are nullable ENUMs — only set them for Limited plans
    // (strict-mode MariaDB rejects '' with "Data truncated")
    if ($p->typebp == 'Limited') {
        $p->limit_type = cvpap_param($q, 'limit_type', 'Time_Limit');
        $p->time_limit = cvpap_param($q, 'time_limit', 0);
        $p->time_unit = cvpap_param($q, 'time_unit', 'Hrs');
        $p->data_limit = cvpap_param($q, 'data_limit', 0);
        $p->data_unit = cvpap_param($q, 'data_unit', 'MB');
    }
    $p->validity = $q['validity'];
    $p->validity_unit = $q['validity_unit'];
    $p->shared_users = cvpap_param($q, 'shared_users', 1);
    $p->is_radius = 0;
    $p->routers = $router;
    $p->enabled = (int) cvpap_param($q, 'enabled', 1);
    $p->prepaid = cvpap_param($q, 'prepaid', 'yes');
    $p->device = cvpap_param($q, 'device', 'MikrotikHotspot');
    $p->expired_date = 20;
    $p->save();
    cvpap_meta_tag('tbl_plans', $p->id(), $q);

    $synced = cvpap_push_plan_to_device($p);
    return ['id' => $p->id(), 'name_plan' => $p['name_plan'], 'device_synced' => $synced['ok'], 'device_error' => $synced['error']];
}

function cvpap_act_plan_update($q)
{
    cvpap_require_params($q, ['id']);
    $p = ORM::for_table('tbl_plans')->find_one($q['id']);
    if (!$p) {
        throw new CvpapApiError('Plan not found');
    }
    $old = clone $p;
    foreach (['name_plan', 'id_bw', 'price', 'validity', 'validity_unit', 'shared_users', 'enabled', 'typebp', 'limit_type', 'time_limit', 'time_unit', 'data_limit', 'data_unit', 'prepaid'] as $field) {
        if (isset($q[$field]) && $q[$field] !== '') {
            $p->$field = $q[$field];
        }
    }
    $p->save();
    cvpap_meta_tag('tbl_plans', $p['id'], $q);

    $synced = ['ok' => true, 'error' => ''];
    try {
        $dvc = Package::getDevice($p);
        if (file_exists($dvc)) {
            require_once $dvc;
            (new $p['device'])->update_plan($old, $p);
        }
    } catch (Throwable $e) {
        $synced = ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['id' => $p['id'], 'device_synced' => $synced['ok'], 'device_error' => $synced['error']];
}

function cvpap_act_plan_delete($q)
{
    cvpap_require_params($q, ['id']);
    $p = ORM::for_table('tbl_plans')->find_one($q['id']);
    if (!$p) {
        throw new CvpapApiError('Plan not found');
    }
    $active = ORM::for_table('tbl_user_recharges')->where('plan_id', $p['id'])->where('status', 'on')->count();
    if ($active > 0) {
        throw new CvpapApiError("Plan has $active active recharge(s)");
    }
    $error = '';
    try {
        $dvc = Package::getDevice($p);
        if (file_exists($dvc)) {
            require_once $dvc;
            (new $p['device'])->remove_plan($p);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $id = $p['id'];
    $p->delete();
    return ['deleted' => $id, 'device_error' => $error];
}

function cvpap_act_plan_sync($q)
{
    $query = ORM::for_table('tbl_plans')->where('type', 'Hotspot');
    $scope = cvpap_routers_scope($q, false);
    if (count($scope) > 0) {
        $query->where_in('routers', $scope);
    }
    $results = [];
    foreach ($query->find_many() as $p) {
        $synced = cvpap_push_plan_to_device($p);
        $results[] = ['id' => $p['id'], 'name_plan' => $p['name_plan'], 'ok' => $synced['ok'], 'error' => $synced['error']];
    }
    return ['synced' => $results];
}

/* ------------------------------------------------------------- customers */

function cvpap_act_customer_list($q)
{
    $query = ORM::for_table('tbl_customers')->order_by_desc('id');
    $partner_uid = cvpap_partner_uid($q, false);
    if ($partner_uid != '') {
        $links = ORM::for_table('tbl_cvpap_partner_customers')
            ->where('partner_uid', $partner_uid)
            ->find_many();
        $ids = [];
        foreach ($links as $link) {
            $ids[] = (int) $link['customer_id'];
        }
        if (count($ids) == 0) {
            return ['customers' => []];
        }
        $query->where_in('id', array_values(array_unique($ids)));
    }
    $search = cvpap_param($q, 'search');
    if ($search) {
        $query->where_raw("(username LIKE ? OR phonenumber LIKE ? OR fullname LIKE ?)", ["%$search%", "%$search%", "%$search%"]);
    }
    $query->limit(min((int) cvpap_param($q, 'limit', 100), 500));
    $query->offset((int) cvpap_param($q, 'offset', 0));
    $customers = cvpap_rows($query->find_many(), ['password', 'pppoe_password']);
    foreach ($customers as &$customer) {
        $customer['partner_id'] = cvpap_partner_uid_for_customer($customer['id']);
    }
    return ['customers' => $customers];
}

function cvpap_act_customer_get($q)
{
    $c = cvpap_customer_find($q);
    $customer = $c->as_array();
    unset($customer['password'], $customer['pppoe_password']);
    $customer['partner_id'] = cvpap_partner_uid_for_customer($c['id']);
    return ['customer' => $customer];
}

/**
 * Idempotent create — portal purchases key customers by phone number.
 * Returns the login password so CVPAP can auto-login / deliver credentials.
 */
function cvpap_act_customer_create($q)
{
    cvpap_require_params($q, ['username']);
    $partner_uid = cvpap_partner_uid($q, false);
    $external_customer_id = cvpap_param($q, 'external_customer_id', cvpap_param($q, 'whatsapp_user_id', ''));

    $username_raw = trim((string) $q['username']);
    $username = $username_raw;
    if (!preg_match('/[A-Za-z]/', $username_raw)) {
        $normalized_username = cvpap_identity_digits($username_raw);
        if ($normalized_username != '') {
            $username = $normalized_username;
        }
    }
    if ($username == '') {
        throw new CvpapApiError('Invalid username');
    }

    $phone_raw = trim((string) cvpap_param($q, 'phonenumber', $username_raw));
    $phone = cvpap_identity_digits($phone_raw);
    if ($phone == '') {
        $phone = $phone_raw;
    }

    $c = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
    if (!$c && $username_raw != $username) {
        $c = ORM::for_table('tbl_customers')->where('username', $username_raw)->find_one();
    }
    if (!$c && $phone != '') {
        $c = ORM::for_table('tbl_customers')->where('phonenumber', $phone)->find_one();
    }
    if (!$c && $phone_raw != '' && $phone_raw != $phone) {
        $c = ORM::for_table('tbl_customers')->where('phonenumber', $phone_raw)->find_one();
    }

    if ($c) {
        $changed = false;
        if ($c['username'] != $username) {
            $dup = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
            if (!$dup || $dup['id'] == $c['id']) {
                $c->username = $username;
                $changed = true;
            }
        }
        if ($phone != '' && $c['phonenumber'] != $phone) {
            $c->phonenumber = $phone;
            $changed = true;
        }
        if (!empty($q['fullname']) && $c['fullname'] != $q['fullname']) {
            $c->fullname = $q['fullname'];
            $changed = true;
        }
        if (!empty($q['email']) && $c['email'] != $q['email']) {
            $c->email = $q['email'];
            $changed = true;
        }
        if (!empty($q['address']) && $c['address'] != $q['address']) {
            $c->address = $q['address'];
            $changed = true;
        }
        if ($changed) {
            $c->save();
        }
        cvpap_meta_tag('tbl_customers', $c['id'], $q);
        if ($partner_uid != '') {
            cvpap_partner_customer_link($partner_uid, $c['id'], $external_customer_id, $phone);
        }
        return [
            'id' => $c['id'],
            'username' => $c['username'],
            'password' => $c['password'],
            'created' => false,
            'partner_id' => $partner_uid,
        ];
    }

    $password = cvpap_param($q, 'password', (string) rand(100000, 999999));
    $c = ORM::for_table('tbl_customers')->create();
    $c->username = $username;
    $c->password = $password;
    $c->fullname = cvpap_param($q, 'fullname', $username);
    $c->phonenumber = $phone != '' ? $phone : ($phone_raw != '' ? $phone_raw : $username);
    $c->email = cvpap_param($q, 'email', '');
    $c->address = cvpap_param($q, 'address', '');
    $c->service_type = cvpap_param($q, 'service_type', 'Hotspot');
    $c->status = 'Active';
    $c->created_by = 0;
    $c->save();
    cvpap_meta_tag('tbl_customers', $c->id(), $q);
    if ($partner_uid != '') {
        cvpap_partner_customer_link($partner_uid, $c->id(), $external_customer_id, $phone);
    }
    return [
        'id' => $c->id(),
        'username' => $c['username'],
        'password' => $password,
        'created' => true,
        'partner_id' => $partner_uid,
    ];
}

function cvpap_act_customer_update($q)
{
    $c = cvpap_customer_find($q);
    $partner_uid = cvpap_partner_uid($q, false);
    $external_customer_id = cvpap_param($q, 'external_customer_id', cvpap_param($q, 'whatsapp_user_id', ''));
    foreach (['fullname', 'phonenumber', 'email', 'address', 'password', 'status'] as $field) {
        if (isset($q[$field]) && $q[$field] !== '') {
            $c->$field = $q[$field];
        }
    }
    $c->save();
    if ($partner_uid != '') {
        cvpap_partner_customer_link($partner_uid, $c['id'], $external_customer_id, cvpap_param($q, 'phonenumber', ''));
    }
    return ['id' => $c['id'], 'username' => $c['username']];
}

/* ----------------------------------------------------- passwords & welcome */

/**
 * Generate a set/reset-password link for a customer (no passwords by email).
 */
function cvpap_act_password_link($q)
{
    $platform = cvpap_platform_config();
    if ($platform['communications_owner'] == 'cvpap') {
        throw new CvpapApiError('Password-link communication is delegated to CVPAP superadmin integrations');
    }
    cvpap_require_params($q, ['username']);
    $c = ORM::for_table('tbl_customers')->where('username', $q['username'])->find_one();
    if (!$c) {
        throw new CvpapApiError('Customer not found');
    }
    return ['link' => cvpap_password_reset_link($c['username']), 'expires_minutes' => 20];
}

/**
 * Send the styled welcome email with a set-password link. Requires the
 * customer to have an email (pass one to set it at the same time).
 */
function cvpap_act_send_welcome($q)
{
    $platform = cvpap_platform_config();
    if ($platform['communications_owner'] == 'cvpap') {
        throw new CvpapApiError('Welcome-email delivery is delegated to CVPAP superadmin integrations');
    }
    global $config;
    cvpap_require_params($q, ['username']);
    $c = ORM::for_table('tbl_customers')->where('username', $q['username'])->find_one();
    if (!$c) {
        throw new CvpapApiError('Customer not found');
    }
    if (!empty($q['email']) && $q['email'] != $c['email']) {
        $c->email = $q['email'];
        $c->save();
    }
    if (empty($c['email'])) {
        return ['sent' => false, 'reason' => 'customer has no email'];
    }
    $reset = cvpap_password_reset_link($c['username']);
    $portal = APP_URL . '/?_route=login';
    $html = cvpap_welcome_html(
        $config['CompanyName'],
        $c['fullname'],
        $c['username'],
        $portal,
        $reset
    );
    Message::sendEmail($c['email'], 'Welcome to ' . $config['CompanyName'] . '! 🎉', $html);
    return ['sent' => true, 'to' => $c['email']];
}

/* ---------------------------------------------------------------- partners */

/**
 * Upsert a CVPAP partner record in tbl_cvpap_partners.
 *
 * This does NOT create/update tbl_users anymore.
 * If a local nuxbill admin/agent already exists, CVPAP may pass
 * `admin_user_id` to link that existing user for SSO.
 */
function cvpap_act_partner_upsert($q)
{
    $partner_id = cvpap_partner_uid($q, true);
    $existing = cvpap_partner_find($partner_id);
    $created = !$existing;

    $admin_user_id = 0;
    if (array_key_exists('admin_user_id', $q) && $q['admin_user_id'] !== '' && $q['admin_user_id'] !== null) {
        $admin_user_id = (int) $q['admin_user_id'];
        if ($admin_user_id > 0) {
            $admin_user = ORM::for_table('tbl_users')->find_one($admin_user_id);
            if (!$admin_user) {
                throw new CvpapApiError('admin_user_id not found in tbl_users');
            }
        }
    }

    $partner_row = cvpap_partner_upsert_row($q, $admin_user_id);

    // Provision (or refresh) the backing owner login unless the caller
    // explicitly linked an existing admin user. Default is to auto-create the
    // 'Partner'-type owner so the business owner can log into nuxbill/SSO.
    $provision_owner = cvpap_param($q, 'provision_owner', true);
    $owner_user_id = (int) $partner_row['admin_user_id'];
    if ($provision_owner && $admin_user_id <= 0) {
        $owner_user_id = cvpap_partner_ensure_owner_login($partner_row, $q);
    } else if (!empty($q['password']) && $owner_user_id > 0) {
        // password refresh for an already-linked owner
        cvpap_partner_ensure_owner_login($partner_row, $q);
    }

    return [
        'id' => $partner_row ? (int) $partner_row['id'] : 0,
        'username' => $partner_row ? $partner_row['username'] : '',
        'admin_user_id' => $owner_user_id,
        'created' => $created,
        'partner' => $partner_row ? $partner_row->as_array() : null,
    ];
}

function cvpap_act_partner_get($q)
{
    $partner_uid = cvpap_partner_uid($q, true);
    $partner = cvpap_partner_find($partner_uid);
    if (!$partner) {
        throw new CvpapApiError('Partner not found');
    }
    $data = $partner->as_array();
    $data['router_names'] = cvpap_partner_router_names($partner_uid);
    $data['customer_count'] = ORM::for_table('tbl_cvpap_partner_customers')
        ->where('partner_uid', $partner_uid)->count();
    if (!empty($data['settings_json'])) {
        $decoded = json_decode($data['settings_json'], true);
        $data['settings'] = is_array($decoded) ? $decoded : [];
    } else {
        $data['settings'] = [];
    }
    return ['partner' => $data];
}

function cvpap_act_partner_settings($q)
{
    $partner_uid = cvpap_partner_uid($q, true);
    $partner = cvpap_partner_find($partner_uid);
    if (!$partner) {
        throw new CvpapApiError('Partner not found');
    }
    if (array_key_exists('settings', $q)) {
        $partner = cvpap_partner_upsert_row($q, (int) $partner['admin_user_id']);
    }
    $settings = [];
    if (!empty($partner['settings_json'])) {
        $decoded = json_decode($partner['settings_json'], true);
        $settings = is_array($decoded) ? $decoded : [];
    }
    return ['partner_id' => $partner_uid, 'settings' => $settings];
}

function cvpap_act_partner_router_scope($q)
{
    $partner_uid = cvpap_partner_uid($q, true);
    return ['partner_id' => $partner_uid, 'routers' => cvpap_partner_router_names($partner_uid)];
}

function cvpap_act_partner_customer_link($q)
{
    $partner_uid = cvpap_partner_uid($q, true);
    $customer_id = (int) cvpap_param($q, 'customer_id', 0);
    if ($customer_id <= 0) {
        $username = cvpap_param($q, 'username', '');
        if ($username == '') {
            throw new CvpapApiError('Provide customer_id or username');
        }
        $customer = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
        if (!$customer) {
            throw new CvpapApiError('Customer not found');
        }
        $customer_id = (int) $customer['id'];
    }
    $link = cvpap_partner_customer_link(
        $partner_uid,
        $customer_id,
        cvpap_param($q, 'external_customer_id', cvpap_param($q, 'whatsapp_user_id', '')),
        cvpap_param($q, 'external_phone', cvpap_param($q, 'phone', ''))
    );
    return ['link' => $link ? $link->as_array() : null];
}

function cvpap_act_partner_customer_links($q)
{
    $partner_uid = cvpap_partner_uid($q, true);
    $query = ORM::for_table('tbl_cvpap_partner_customers')->where('partner_uid', $partner_uid);
    $query->order_by_desc('id');
    $query->limit(min((int) cvpap_param($q, 'limit', 200), 1000));
    $query->offset((int) cvpap_param($q, 'offset', 0));
    return ['links' => cvpap_rows($query->find_many())];
}

function cvpap_act_partner_webhook_upsert($q)
{
    cvpap_ensure_schema();
    $partner_uid = cvpap_partner_uid($q, true);
    cvpap_require_params($q, ['url', 'secret']);
    $id = (int) cvpap_param($q, 'id', 0);
    $owner_type = cvpap_param($q, 'owner_type', 'partner');
    if (!in_array($owner_type, ['partner', 'customer'])) {
        throw new CvpapApiError('owner_type must be partner or customer');
    }
    $row = null;
    if ($id > 0) {
        $row = ORM::for_table('tbl_cvpap_partner_webhooks')
            ->where('id', $id)
            ->where('partner_uid', $partner_uid)
            ->find_one();
    }
    if (!$row) {
        $row = ORM::for_table('tbl_cvpap_partner_webhooks')->create();
        $row->partner_uid = $partner_uid;
    }
    $row->owner_type = $owner_type;
    $row->customer_id = (int) cvpap_param($q, 'customer_id', 0);
    $row->event_name = trim((string) cvpap_param($q, 'event_name', '*'));
    $row->url = trim((string) $q['url']);
    $row->secret = trim((string) $q['secret']);
    $headers = cvpap_param($q, 'headers', null);
    if (is_array($headers)) {
        $row->headers_json = json_encode($headers);
    } else if (is_string($headers) && $headers !== '') {
        $row->headers_json = $headers;
    }
    if (isset($q['enabled'])) {
        $row->enabled = (int) !!$q['enabled'];
    }
    $row->save();
    return ['webhook' => $row->as_array()];
}

function cvpap_act_partner_webhook_list($q)
{
    cvpap_ensure_schema();
    $partner_uid = cvpap_partner_uid($q, true);
    $query = ORM::for_table('tbl_cvpap_partner_webhooks')->where('partner_uid', $partner_uid);
    if (!empty($q['owner_type'])) {
        $query->where('owner_type', $q['owner_type']);
    }
    if (isset($q['customer_id']) && $q['customer_id'] !== '') {
        $query->where('customer_id', (int) $q['customer_id']);
    }
    if (isset($q['enabled']) && $q['enabled'] !== '') {
        $query->where('enabled', (int) !!$q['enabled']);
    }
    $query->order_by_desc('id');
    return ['webhooks' => cvpap_rows($query->find_many())];
}

function cvpap_act_partner_webhook_delete($q)
{
    cvpap_ensure_schema();
    $partner_uid = cvpap_partner_uid($q, true);
    $id = (int) cvpap_param($q, 'id', 0);
    if ($id <= 0) {
        throw new CvpapApiError('Missing required parameter: id');
    }
    $row = ORM::for_table('tbl_cvpap_partner_webhooks')
        ->where('id', $id)
        ->where('partner_uid', $partner_uid)
        ->find_one();
    if (!$row) {
        throw new CvpapApiError('Webhook not found');
    }
    $row->delete();
    return ['deleted' => $id];
}

function cvpap_act_partner_sso_issue($q)
{
    cvpap_assert_superadmin_actor($q);
    $platform = cvpap_platform_config();
    if ($platform['advanced_settings_owner'] != 'superadmin') {
        throw new CvpapApiError('SSO issuance is disabled unless advanced settings owner is superadmin');
    }
    $partner_uid = cvpap_partner_uid($q, true);
    $ttl = (int) cvpap_param($q, 'ttl_seconds', 120);
    $redirect_to = cvpap_param($q, 'redirect_to', 'dashboard');
    return cvpap_issue_sso_token($partner_uid, $redirect_to, $ttl);
}

function cvpap_act_partner_usage($q)
{
    cvpap_assert_superadmin_actor($q);
    $platform = cvpap_platform_config();
    if ($platform['billing_owner'] != 'superadmin') {
        throw new CvpapApiError('Partner billing usage metrics are restricted to CVPAP superadmin');
    }
    $partner_uid = cvpap_partner_uid($q, true);
    $routers = cvpap_partner_router_names($partner_uid);
    $from = cvpap_param($q, 'date_from', date('Y-m-d', strtotime('-30 days')));
    $to = cvpap_param($q, 'date_to', date('Y-m-d'));

    $customer_count = ORM::for_table('tbl_cvpap_partner_customers')
        ->where('partner_uid', $partner_uid)
        ->count();
    $router_count = count($routers);

    $active_recharges = 0;
    $transactions_count = 0;
    $transactions_total = 0.0;
    if ($router_count > 0) {
        $active_recharges = ORM::for_table('tbl_user_recharges')
            ->where_in('routers', $routers)
            ->where('status', 'on')
            ->count();

        $txs = ORM::for_table('tbl_transactions')
            ->where_in('routers', $routers)
            ->where_gte('recharged_on', $from)
            ->where_lte('recharged_on', $to)
            ->find_many();
        $transactions_count = count($txs);
        foreach ($txs as $tx) {
            $transactions_total += (float) $tx['price'];
        }
    }

    return [
        'partner_id' => $partner_uid,
        'date_from' => $from,
        'date_to' => $to,
        'metrics' => [
            'routers' => $router_count,
            'customers' => $customer_count,
            'active_recharges' => $active_recharges,
            'transactions_count' => $transactions_count,
            'transactions_total' => round($transactions_total, 2),
        ],
    ];
}

function cvpap_act_platform_config($q)
{
    cvpap_assert_superadmin_actor($q);
    $cfg = cvpap_platform_config();
    if (array_key_exists('set', $q) && is_array($q['set'])) {
        $cfg = cvpap_save_platform_config($q['set']);
    }
    return ['platform_config' => $cfg];
}

function cvpap_act_customer_logs($q)
{
    cvpap_require_params($q, ['customer_id']);
    $customer_id = (int) $q['customer_id'];
    if ($customer_id <= 0) {
        throw new CvpapApiError('Invalid customer_id');
    }
    $partner_uid = cvpap_partner_uid($q, false);
    if ($partner_uid != '') {
        $owner = cvpap_partner_uid_for_customer($customer_id);
        if ($owner != $partner_uid) {
            throw new CvpapApiError('Customer not in partner scope');
        }
    }

    $rows = ORM::for_table('tbl_user_recharges')
        ->where('customer_id', $customer_id)
        ->order_by_desc('id')
        ->limit(min((int) cvpap_param($q, 'limit', 200), 1000))
        ->offset((int) cvpap_param($q, 'offset', 0))
        ->find_many();

    $logs = cvpap_rows($rows);
    foreach ($logs as &$log) {
        $log['partner_id'] = cvpap_partner_uid_for_customer($customer_id);
    }

    return [
        'customer_id' => $customer_id,
        'logs' => $logs,
    ];
}

/* -------------------------------------------------------------- recharge */

/**
 * Recharge an existing customer account (validated wrapper around
 * Package::rechargeUser — see plan: pre-validate to dodge its r2()/_alert()
 * redirect-and-die error paths).
 */
function cvpap_act_recharge($q)
{
    cvpap_require_params($q, ['customer_id', 'router', 'plan_id']);
    cvpap_assert_router_in_scope($q, $q['router']);

    $c = ORM::for_table('tbl_customers')->find_one($q['customer_id']);
    if (!$c) {
        throw new CvpapApiError('Customer not found');
    }
    if ($c['status'] != 'Active') {
        throw new CvpapApiError('Customer account is ' . $c['status']);
    }
    $p = cvpap_validate_plan_router($q['plan_id'], $q['router']);

    $gateway = cvpap_param($q, 'gateway', 'CVPAP');
    $channel = cvpap_param($q, 'channel', '');
    $note = cvpap_param($q, 'note', '');

    $inv = Package::rechargeUser($c['id'], $q['router'], $p['id'], $gateway, $channel, $note);
    if (!$inv) {
        throw new CvpapApiError('Recharge failed');
    }
    cvpap_verify_hotspot_user($q['router'], $p, $c['username'], $c['password']);
    $partner_uid = cvpap_partner_uid($q, false);
    if ($partner_uid != '') {
        cvpap_partner_customer_link(
            $partner_uid,
            $c['id'],
            cvpap_param($q, 'external_customer_id', cvpap_param($q, 'whatsapp_user_id', '')),
            $c['phonenumber']
        );
    }
    return cvpap_recharge_result($c['username'], $q['router'], $p, $inv, $c['password']);
}

/**
 * Composite portal provisioning: ensure a customer keyed by phone exists,
 * then recharge — one atomic call for the CVPAP purchase flow. Records the
 * full plan price in tbl_transactions so nuxbill reports show real revenue.
 */
/**
 * After a recharge, confirm the hotspot user actually landed on the router and
 * force its password to exactly what we hand the buyer. Heals silent drift
 * (stale manually-added users, out-of-band password changes) and turns
 * "paid but can't log in" into a loud provisioning error instead.
 */
function cvpap_verify_hotspot_user($router_name, $plan, $username, $password)
{
    if ($plan['device'] != 'MikrotikHotspot') {
        return ['verified' => false, 'skipped' => 'device ' . $plan['device']];
    }
    $router = Mikrotik::info($router_name);
    if (!$router) {
        return ['verified' => false, 'skipped' => 'router missing'];
    }
    $client = Mikrotik::getClient($router['ip_address'], $router['username'], $router['password']);
    if ($client === null) { // demo mode
        return ['verified' => false, 'skipped' => 'demo'];
    }
    $printRequest = new PEAR2\Net\RouterOS\Request('/ip/hotspot/user/print');
    $printRequest->setArgument('.proplist', '.id');
    $printRequest->setQuery(PEAR2\Net\RouterOS\Query::where('name', $username));
    $id = $client->sendSync($printRequest)->getProperty('.id');
    if (empty($id)) {
        throw new CvpapApiError(
            "Recharge saved but hotspot user [$username] did not reach router [$router_name] — check the router connection and plan device"
        );
    }
    Mikrotik::setHotspotUser($client, $username, $password);
    return ['verified' => true];
}


function cvpap_act_portal_provision($q)
{
    cvpap_require_params($q, ['phone', 'router', 'plan_id']);
    cvpap_assert_router_in_scope($q, $q['router']);
    $p = cvpap_validate_plan_router($q['plan_id'], $q['router']);

    $created = cvpap_act_customer_create([
        'username' => $q['phone'],
        'phonenumber' => $q['phone'],
        'fullname' => cvpap_param($q, 'fullname', $q['phone']),
        'partner_id' => cvpap_param($q, 'partner_id'),
    ]);

    $gateway = cvpap_param($q, 'gateway', 'CVPAP-Mpesa');
    $channel = cvpap_param($q, 'channel', '');
    $note = cvpap_param($q, 'note', '');

    $inv = Package::rechargeUser($created['id'], $q['router'], $p['id'], $gateway, $channel, $note);
    if (!$inv) {
        throw new CvpapApiError('Recharge failed');
    }
    $verify = cvpap_verify_hotspot_user($q['router'], $p, $created['username'], $created['password']);
    $res = cvpap_recharge_result($created['username'], $q['router'], $p, $inv, $created['password']);
    $res['router_user_verified'] = $verify;
    return $res;
}

/* -------------------------------------------------------------- vouchers */

function cvpap_act_voucher_generate($q)
{
    cvpap_require_params($q, ['plan_id', 'router', 'count']);
    cvpap_assert_router_in_scope($q, $q['router']);
    $p = cvpap_validate_plan_router($q['plan_id'], $q['router']);

    global $admin;
    $count = min((int) $q['count'], 500);
    $length = max((int) cvpap_param($q, 'length', 8), 6);
    $prefix = cvpap_param($q, 'prefix', '');

    $codes = [];
    $attempts = 0;
    while (count($codes) < $count && $attempts < $count * 3) {
        $attempts++;
        $code = $prefix . generateUniqueNumericVouchers(1, $length)[0];
        $dup = ORM::for_table('tbl_voucher')->where('code', $code)->find_one();
        if ($dup || in_array($code, $codes)) {
            continue;
        }
        $v = ORM::for_table('tbl_voucher')->create();
        $v->type = 'Hotspot';
        $v->routers = $q['router'];
        $v->id_plan = $p['id'];
        $v->code = $code;
        $v->user = '0';
        $v->status = '0';
        $v->generated_by = $admin['id'];
        $v->save();
        cvpap_meta_tag('tbl_voucher', $v->id(), $q);
        $codes[] = $code;
    }
    return ['codes' => $codes, 'plan_id' => $p['id'], 'router' => $q['router']];
}

function cvpap_act_voucher_list($q)
{
    $query = ORM::for_table('tbl_voucher')->where_in('routers', cvpap_routers_scope($q))->order_by_desc('id');
    $status = cvpap_param($q, 'status');
    if ($status !== null) {
        $query->where('status', $status);
    }
    $query->limit(min((int) cvpap_param($q, 'limit', 200), 1000));
    $query->offset((int) cvpap_param($q, 'offset', 0));
    return ['vouchers' => cvpap_rows($query->find_many())];
}

function cvpap_act_voucher_status($q)
{
    cvpap_require_params($q, ['code']);
    $v = ORM::for_table('tbl_voucher')->where('code', $q['code'])->find_one();
    if (!$v) {
        throw new CvpapApiError('Voucher not found');
    }
    return ['voucher' => $v->as_array()];
}

/**
 * Redeem a gift/printed voucher for a phone number: ensures the customer
 * exists, recharges via the voucher path (0-priced transaction — the sale
 * was recorded when the voucher was purchased/gifted), marks it used.
 */
function cvpap_act_voucher_activate($q)
{
    cvpap_require_params($q, ['code', 'phone']);
    $v = ORM::for_table('tbl_voucher')->where('code', $q['code'])->where('status', 0)->find_one();
    if (!$v) {
        throw new CvpapApiError('Voucher not valid or already used');
    }
    cvpap_assert_router_in_scope($q, $v['routers']);

    $created = cvpap_act_customer_create([
        'username' => $q['phone'],
        'phonenumber' => $q['phone'],
        'partner_id' => cvpap_param($q, 'partner_id'),
    ]);

    $inv = Package::rechargeUser($created['id'], $v['routers'], $v['id_plan'], 'Voucher', $q['code']);
    if (!$inv) {
        throw new CvpapApiError('Voucher activation failed');
    }
    $v->status = '1';
    $v->used_date = date('Y-m-d H:i:s');
    $v->user = $q['phone'];
    $v->save();

    $p = ORM::for_table('tbl_plans')->find_one($v['id_plan']);
    return cvpap_recharge_result($created['username'], $v['routers'], $p, $inv, $created['password']);
}

/* --------------------------------------------------------------- reports */

function cvpap_act_transactions($q)
{
    $scope = cvpap_routers_scope($q);
    $from = cvpap_param($q, 'date_from', date('Y-m-d', strtotime('-30 days')));
    $to = cvpap_param($q, 'date_to', date('Y-m-d'));

    $query = ORM::for_table('tbl_transactions')
        ->where_in('routers', $scope)
        ->where_gte('recharged_on', $from)
        ->where_lte('recharged_on', $to)
        ->order_by_desc('id');
    $method = cvpap_param($q, 'method');
    if ($method) {
        $query->where_like('method', "%$method%");
    }
    $plan_name = cvpap_param($q, 'plan_name');
    if ($plan_name) {
        $query->where('plan_name', $plan_name);
    }

    $rows = cvpap_rows($query->limit(min((int) cvpap_param($q, 'limit', 500), 2000))
        ->offset((int) cvpap_param($q, 'offset', 0))->find_many());

    // aggregation
    $group_by = cvpap_param($q, 'group_by'); // plan|router|method|day
    $groups = [];
    if (in_array($group_by, ['plan', 'router', 'method', 'day'])) {
        $col = ['plan' => 'plan_name', 'router' => 'routers', 'method' => 'method', 'day' => 'recharged_on'][$group_by];
        $in = "'" . implode("','", array_map('addslashes', $scope)) . "'";
        $agg = ORM::for_table('tbl_transactions')
            ->raw_query(
                "SELECT $col AS grp, COUNT(*) AS cnt, SUM(price) AS total
                 FROM tbl_transactions
                 WHERE routers IN ($in) AND recharged_on >= ? AND recharged_on <= ?
                 GROUP BY $col ORDER BY total DESC",
                [$from, $to]
            )->find_array();
        $groups = $agg;
    }

    return ['transactions' => $rows, 'groups' => $groups, 'date_from' => $from, 'date_to' => $to];
}

function cvpap_act_active_recharges($q)
{
    $query = ORM::for_table('tbl_user_recharges')
        ->where_in('routers', cvpap_routers_scope($q))
        ->order_by_desc('id');
    $status = cvpap_param($q, 'status');
    if ($status) {
        $query->where('status', $status);
    }
    $query->limit(min((int) cvpap_param($q, 'limit', 500), 2000));
    $query->offset((int) cvpap_param($q, 'offset', 0));
    return ['recharges' => cvpap_rows($query->find_many())];
}

/* ------------------------------------------------------- shared internals */

/**
 * Connectivity probe for one router: quick TCP check (5 s) before the
 * RouterOS API login, so offline routers don't hang for the full socket
 * timeout.
 */
function cvpap_router_probe($name)
{
    $router = Mikrotik::info($name);
    if (!$router) {
        return ['name' => $name, 'online' => false, 'error' => 'Router not found'];
    }
    if (isset($router['enabled']) && !$router['enabled']) {
        return ['name' => $name, 'online' => false, 'error' => 'Router disabled'];
    }
    $iport = explode(':', $router['ip_address']);
    $host = $iport[0];
    $port = isset($iport[1]) && $iport[1] !== '' ? (int) $iport[1] : 8728;

    $errno = 0;
    $errstr = '';
    $sock = @fsockopen($host, $port, $errno, $errstr, 5);
    if ($sock === false) {
        return ['name' => $name, 'online' => false, 'error' => "tcp $host:$port unreachable: $errstr"];
    }
    fclose($sock);

    try {
        $client = Mikrotik::getClient($router['ip_address'], $router['username'], $router['password']);
        if ($client === null) { // demo mode
            return ['name' => $name, 'online' => false, 'error' => 'demo mode'];
        }
        return ['name' => $name, 'online' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['name' => $name, 'online' => false, 'error' => $e->getMessage()];
    }
}

function cvpap_router_find($q)
{
    $r = null;
    if (!empty($q['id'])) {
        $r = ORM::for_table('tbl_routers')->find_one($q['id']);
    } elseif (!empty($q['name'])) {
        $r = ORM::for_table('tbl_routers')->where('name', $q['name'])->find_one();
    } else {
        throw new CvpapApiError('Provide router id or name');
    }
    if (!$r) {
        throw new CvpapApiError('Router not found');
    }
    return $r;
}

function cvpap_customer_find($q)
{
    $c = null;
    if (!empty($q['id'])) {
        $c = ORM::for_table('tbl_customers')->find_one($q['id']);
    } elseif (!empty($q['username'])) {
        $c = ORM::for_table('tbl_customers')->where('username', $q['username'])->find_one();
    } else {
        throw new CvpapApiError('Provide customer id or username');
    }
    if (!$c) {
        throw new CvpapApiError('Customer not found');
    }
    return $c;
}

/**
 * Mutating per-router actions must also carry the scope list and the target
 * router must be inside it — belt-and-braces against CVPAP-side bugs.
 */
function cvpap_assert_router_in_scope($q, $router)
{
    $scope = cvpap_routers_scope($q);
    if (!in_array($router, $scope)) {
        throw new CvpapApiError("Router [$router] not in authorized scope");
    }
}

function cvpap_validate_plan_router($plan_id, $router)
{
    $p = ORM::for_table('tbl_plans')->find_one($plan_id);
    if (!$p) {
        throw new CvpapApiError('Plan not found');
    }
    if (!$p['enabled']) {
        throw new CvpapApiError('Plan is disabled');
    }
    if ($p['routers'] != $router) {
        throw new CvpapApiError("Plan belongs to router [{$p['routers']}], not [$router]");
    }
    if (!Mikrotik::info($router)) {
        throw new CvpapApiError("Router [$router] not found");
    }
    return $p;
}

function cvpap_push_plan_to_device($p)
{
    try {
        $dvc = Package::getDevice($p);
        if (!file_exists($dvc)) {
            return ['ok' => false, 'error' => 'Device driver not found: ' . $p['device']];
        }
        require_once $dvc;
        (new $p['device'])->add_plan($p);
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function cvpap_recharge_result($username, $router, $p, $invoice, $password = '')
{
    $customer = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
    $customer_id = $customer ? (int) $customer['id'] : 0;
    $partner_id = cvpap_meta_get('tbl_plans', $p['id']);
    if ($partner_id == '' && $customer_id > 0) {
        $partner_id = cvpap_partner_uid_for_customer($customer_id);
    }
    $rec = ORM::for_table('tbl_user_recharges')
        ->where('username', $username)
        ->where('routers', $router)
        ->order_by_desc('id')
        ->find_one();
    return [
        'customer_id' => $customer_id,
        'partner_id' => $partner_id,
        'invoice' => $invoice,
        'username' => $username,
        'password' => $password,
        'plan_id' => $p['id'],
        'plan_name' => $p['name_plan'],
        'router' => $router,
        'expiration' => $rec ? $rec['expiration'] . ' ' . $rec['time'] : '',
        'status' => $rec ? $rec['status'] : '',
    ];
}

/* ═══════════════════════════════════════════ completeness pass (core data ops) */

/* ---- customers: delete + balance ---- */

function cvpap_act_customer_delete($q)
{
    cvpap_require_params($q, ['customer_id']);
    $c = ORM::for_table('tbl_customers')->find_one((int) $q['customer_id']);
    if (!$c) {
        throw new CvpapApiError('Customer not found');
    }
    $active = ORM::for_table('tbl_user_recharges')->where('customer_id', $c['id'])
        ->where('status', 'on')->count();
    if ($active > 0 && !cvpap_param($q, 'force', false)) {
        throw new CvpapApiError("Customer has $active active subscription(s); pass force=true to delete anyway");
    }
    $id = $c['id'];
    ORM::for_table('tbl_user_recharges')->where('customer_id', $id)->delete_many();
    ORM::for_table('tbl_customers_fields')->where('customer_id', $id)->delete_many();
    $c->delete();
    return ['deleted' => $id];
}

function cvpap_act_customer_balance($q)
{
    cvpap_require_params($q, ['customer_id', 'amount']);
    $c = ORM::for_table('tbl_customers')->find_one((int) $q['customer_id']);
    if (!$c) {
        throw new CvpapApiError('Customer not found');
    }
    $amount = (float) $q['amount'];
    $op = cvpap_param($q, 'operation', 'add'); // add | deduct | set
    if ($op === 'add') {
        Balance::plus($c['id'], $amount);
    } else if ($op === 'deduct') {
        Balance::min($c['id'], $amount);
    } else if ($op === 'set') {
        $c->balance = $amount;
        $c->save();
    } else {
        throw new CvpapApiError('operation must be add|deduct|set');
    }
    $fresh = ORM::for_table('tbl_customers')->find_one($c['id']);
    return ['customer_id' => $c['id'], 'balance' => (float) $fresh['balance'], 'operation' => $op];
}

function cvpap_act_customer_fields($q)
{
    cvpap_require_params($q, ['customer_id']);
    $cid = (int) $q['customer_id'];
    if (array_key_exists('fields', $q) && is_array($q['fields'])) {
        foreach ($q['fields'] as $name => $value) {
            $f = ORM::for_table('tbl_customers_fields')->where('customer_id', $cid)
                ->where('field_name', $name)->find_one();
            if (!$f) {
                $f = ORM::for_table('tbl_customers_fields')->create();
                $f->customer_id = $cid;
                $f->field_name = $name;
            }
            $f->field_value = $value;
            $f->save();
        }
    }
    $rows = ORM::for_table('tbl_customers_fields')->where('customer_id', $cid)->find_array();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['field_name']] = $r['field_value'];
    }
    return ['customer_id' => $cid, 'fields' => $out];
}

/* ---- vouchers: delete + print export ---- */

function cvpap_act_voucher_delete($q)
{
    if (!empty($q['code'])) {
        $v = ORM::for_table('tbl_voucher')->where('code', $q['code'])->find_one();
    } else {
        cvpap_require_params($q, ['id']);
        $v = ORM::for_table('tbl_voucher')->find_one((int) $q['id']);
    }
    if (!$v) {
        throw new CvpapApiError('Voucher not found');
    }
    if ($v['status'] == '1' && !cvpap_param($q, 'force', false)) {
        throw new CvpapApiError('Voucher already used; pass force=true to delete');
    }
    cvpap_assert_router_in_scope($q, $v['routers']);
    $id = $v['id'];
    $v->delete();
    return ['deleted' => $id];
}

function cvpap_act_voucher_print($q)
{
    $rows = ORM::for_table('tbl_voucher')
        ->table_alias('v')
        ->left_outer_join('tbl_plans', ['p.id', '=', 'v.id_plan'], 'p')
        ->where_in('v.routers', cvpap_routers_scope($q))
        ->select('v.code', 'code')->select('v.status', 'status')->select('v.routers', 'routers')
        ->select('p.name_plan', 'plan')->select('p.price', 'price')
        ->select('p.validity', 'validity')->select('p.validity_unit', 'validity_unit');
    $status = cvpap_param($q, 'status');
    if ($status !== null) {
        $rows->where('v.status', $status);
    }
    if (!empty($q['codes']) && is_array($q['codes'])) {
        $rows->where_in('v.code', $q['codes']);
    }
    return ['vouchers' => cvpap_rows($rows->limit(min((int) cvpap_param($q, 'limit', 500), 2000))->find_many())];
}

/* ---- IP pools (tbl_pool) ---- */

function cvpap_act_pool_list($q)
{
    $query = ORM::for_table('tbl_pool');
    $scope = cvpap_routers_scope($q, false);
    if (count($scope) > 0) {
        $query->where_in('routers', $scope);
    }
    return ['pools' => cvpap_rows($query->find_many())];
}

function cvpap_act_pool_create($q)
{
    // `router` = the target router; `routers` = the partner scope list
    cvpap_require_params($q, ['pool_name', 'range_ip', 'router']);
    cvpap_assert_router_in_scope($q, $q['router']);
    $p = ORM::for_table('tbl_pool')->create();
    $p->pool_name = $q['pool_name'];
    $p->local_ip = cvpap_param($q, 'local_ip', '');
    $p->range_ip = $q['range_ip'];
    $p->routers = $q['router'];
    $p->save();
    return ['id' => $p->id(), 'pool_name' => $p['pool_name'], 'router' => $q['router']];
}

function cvpap_act_pool_delete($q)
{
    cvpap_require_params($q, ['id']);
    $p = ORM::for_table('tbl_pool')->find_one((int) $q['id']);
    if (!$p) {
        throw new CvpapApiError('Pool not found');
    }
    cvpap_assert_router_in_scope($q, $p['routers']);
    $id = $p['id'];
    $p->delete();
    return ['deleted' => $id];
}

/* ---- coupons (tbl_coupons) ---- */

function cvpap_act_coupon_list($q)
{
    return ['coupons' => cvpap_rows(ORM::for_table('tbl_coupons')->order_by_desc('id')
        ->limit(min((int) cvpap_param($q, 'limit', 200), 1000))->find_many())];
}

function cvpap_act_coupon_create($q)
{
    cvpap_require_params($q, ['code', 'type', 'value']);
    if (!in_array($q['type'], ['fixed', 'percent'])) {
        throw new CvpapApiError('type must be fixed|percent');
    }
    if (ORM::for_table('tbl_coupons')->where('code', $q['code'])->find_one()) {
        throw new CvpapApiError('Coupon code already exists');
    }
    $c = ORM::for_table('tbl_coupons')->create();
    $c->code = $q['code'];
    $c->type = $q['type'];
    $c->value = (float) $q['value'];
    $c->description = cvpap_param($q, 'description', '');
    $c->max_usage = (int) cvpap_param($q, 'max_usage', 1);
    $c->usage_count = 0;
    $c->status = cvpap_param($q, 'status', 'active');
    $c->min_order_amount = (float) cvpap_param($q, 'min_order_amount', 0);
    $c->max_discount_amount = (float) cvpap_param($q, 'max_discount_amount', 0);
    $c->start_date = cvpap_param($q, 'start_date', date('Y-m-d'));
    $c->end_date = cvpap_param($q, 'end_date', date('Y-m-d', strtotime('+1 year')));
    $c->save();
    return ['id' => $c->id(), 'code' => $c['code']];
}

function cvpap_act_coupon_delete($q)
{
    cvpap_require_params($q, ['id']);
    $c = ORM::for_table('tbl_coupons')->find_one((int) $q['id']);
    if (!$c) {
        throw new CvpapApiError('Coupon not found');
    }
    $id = $c['id'];
    $c->delete();
    return ['deleted' => $id];
}

/* ---- app settings (tbl_appconfig) ---- */

// keys the bridge refuses to expose/overwrite (secrets / bridge internals)
function cvpap_appconfig_blocked()
{
    return ['api_key', 'cvpap_shared_secret', 'db_password', 'radius_pass'];
}

function cvpap_act_appconfig_get($q)
{
    $blocked = cvpap_appconfig_blocked();
    if (!empty($q['setting'])) {
        if (in_array($q['setting'], $blocked)) {
            throw new CvpapApiError('That setting is not readable via the bridge');
        }
        $row = ORM::for_table('tbl_appconfig')->where('setting', $q['setting'])->find_one();
        return ['setting' => $q['setting'], 'value' => $row ? $row['value'] : null];
    }
    $out = [];
    foreach (ORM::for_table('tbl_appconfig')->find_array() as $r) {
        if (!in_array($r['setting'], $blocked)) {
            $out[$r['setting']] = $r['value'];
        }
    }
    return ['settings' => $out];
}

function cvpap_act_appconfig_set($q)
{
    cvpap_require_params($q, ['setting', 'value']);
    if (in_array($q['setting'], cvpap_appconfig_blocked())) {
        throw new CvpapApiError('That setting cannot be changed via the bridge');
    }
    cvpap_save_cfg($q['setting'], $q['value']);
    return ['setting' => $q['setting'], 'value' => $q['value']];
}

/* ---- invoice / receipt for a transaction ---- */

function cvpap_act_invoice_get($q)
{
    if (!empty($q['invoice'])) {
        $t = ORM::for_table('tbl_transactions')->where('invoice', $q['invoice'])->find_one();
    } else {
        cvpap_require_params($q, ['id']);
        $t = ORM::for_table('tbl_transactions')->find_one((int) $q['id']);
    }
    if (!$t) {
        throw new CvpapApiError('Transaction not found');
    }
    cvpap_assert_router_in_scope($q, $t['routers']);
    $cust = ORM::for_table('tbl_customers')->where('username', $t['username'])->find_one();
    return ['invoice' => [
        'invoice' => $t['invoice'],
        'username' => $t['username'],
        'fullname' => $cust ? $cust['fullname'] : '',
        'plan_name' => $t['plan_name'],
        'type' => $t['type'],
        'price' => (float) $t['price'],
        'method' => $t['method'],
        'router' => $t['routers'],
        'created_on' => $t['recharged_on'] . ' ' . $t['recharged_time'],
        'expiration' => $t['expiration'] . ' ' . $t['time'],
        'note' => $t['note'],
    ]];
}
