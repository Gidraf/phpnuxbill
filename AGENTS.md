# AGENTS.md — phpNuxBill (CVPAP fork)

MikroTik ISP billing (PHP, ORM idiorm). CVPAP (the Flask SaaS) drives this through the
custom **cvpap plugin**. Most agent work happens in `system/plugin/cvpap/`.

## The CVPAP plugin
- `system/plugin/cvpap.php` — bootstrap: dispatcher `cvpap_api()` (`$fn = 'cvpap_act_' . $action`
  → `call_user_func`), hooks, `cvpap_ensure_schema()`, the "My Business" partner menu,
  and the `admin/cvpap_sso` SSO landing.
- `system/plugin/cvpap/lib.php` — HMAC auth (X-CVPAP-Timestamp/Signature over
  `<ts>.<rawbody>`, 300s window), partner owner-login provisioning
  (`user_type='Partner'` enforced), SSO token issue/consume, `cvpap_add_partner_user_type`.
- `system/plugin/cvpap/actions.php` — ~60 `cvpap_act_*` actions (routers, plans, bandwidth,
  recharge/portal_provision, customers, vouchers, pools, coupons, tunnel_check, router_log,
  bypass_*, hotspot_install_page). Add a new endpoint = add a `cvpap_act_<name>($q)` function.

## Conventions & gotchas
- Auth: every action goes through the HMAC dispatcher; scope with `cvpap_routers_scope`,
  `cvpap_assert_router_in_scope`, `cvpap_require_params`.
- RouterOS calls use `Mikrotik::getClient($ip,$user,$pass)` + PEAR2 `Request`/`sendSync`.
  `getClient` returns `null` in demo mode — always null-check.
- Strict-mode MariaDB rejects `''` for nullable ENUMs → only set limit fields for Limited
  plans (see `cvpap_act_plan_create`).
- `Package::rechargeUser()` is the core provisioning call; it can `r2()/_alert()` (die) on
  error paths — pre-validate.
- Passwords are plain SHA1 (`Password::_crypt`). `tbl_users.user_type` ENUM has no
  `Partner` by default — `cvpap_add_partner_user_type()` ALTERs it.
- A nuxbill plan binds to exactly ONE router (`tbl_plans.routers`); CVPAP models
  "all-routers" packages as one plan per router.

## Deploy
Plugin is baked into the image: `docker compose -p nuxbill up -d --build`. DB is the
host VM's MariaDB (via `host.docker.internal`), NOT a container.

## Host scripts (run on the server, not in the container)
`cvpap-wg-server-init.sh` (one-time WG server + agent install), `cvpap-wg-agent.sh`
(peer/heartbeat timer, self-heals wg0), `cvpap-print-agent.py` (optional desktop print
agent). The captive-portal print console is the primary print path (browser-based).

Cross-repo context: the CVPAP Flask repo's `AGENTS.md`, `docs/ARCHITECTURE.md`, and
`docs/KNOWN_ISSUES.md`.
