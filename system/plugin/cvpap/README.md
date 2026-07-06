# CVPAP Bridge plugin

Connects this phpNuxBill instance to the CVPAP platform: exposes a JSON API
(routers, bandwidth, plans, customers, recharges, vouchers, transactions,
online users) and emits signed webhooks on recharge/voucher/expiry events.

- API entry: `system/api.php?r=plugin/cvpap_api/<action>&token=<api_key>`
- Auth: static api key + HMAC-SHA256 (`X-CVPAP-Timestamp` / `X-CVPAP-Signature`
  over `"<ts>.<raw body>"`, 300 s replay window)
- Settings page: admin UI → Settings → **CVPAP Bridge** (webhook URL + shared secret)
- Files: `../cvpap.php` (dispatch + hooks), `lib.php` (auth/webhooks), `actions.php` (handlers)

Full deployment guide lives in the CVPAP repo: `docs/WIFI_NUXBILL_DEPLOYMENT.md`.

## Multitenant Extensions (CVPAP)

This bridge now includes a tenant layer for CVPAP partner operations:

- Partner registry in nuxbill: `tbl_cvpap_partners`.
- Partner-owned routers: `tbl_cvpap_partner_routers`.
- Partner-to-customer (WhatsApp user) links: `tbl_cvpap_partner_customers`.
- Partner/customer webhook endpoints + secrets: `tbl_cvpap_partner_webhooks`.
- One-time SSO switch tokens (CVPAP → nuxbill): `tbl_cvpap_sso_tokens`.

New bridge actions include:

- `partner_get`, `partner_settings`, `partner_router_scope`
- `partner_customer_link`, `partner_customer_links`
- `partner_webhook_upsert`, `partner_webhook_list`, `partner_webhook_delete`
- `partner_sso_issue`, `partner_usage`
- `platform_config`, `customer_logs`

SSO consume route in nuxbill admin:

- `?_route=admin/cvpap_sso&token=<one-time-token>`

Notes:

- Existing actions still work as before.
- `partner_upsert` upserts `tbl_cvpap_partners` AND, unless
  `provision_owner:false` or an explicit `admin_user_id` is passed,
  auto-creates a backing **owner login** in `tbl_users` with
  `user_type='Partner'`. That user_type is authorized by NONE of nuxbill's
  stock data controllers (customers/plans/reports/routers all deny it), so
  the owner is safely scoped: stock cross-tenant pages are denied, and only
  the plugin's own partner-scoped pages / SSO session are usable. The
  optional `password` field sets the owner's login password (forwarded from
  CVPAP so the same credentials work on both systems).
- Partner staff should be synced as `user_type='Agent'` linked to the same
  `partner_uid` (Phase B scoped-views work).
- SSO now works out of the box: `partner_sso_issue` uses the auto-created
  owner (`admin_user_id`); the consume route is registered in `cvpap.php`
  (see below).
- Platform governance defaults are superadmin-owned: advanced settings,
  communications and billing are delegated to CVPAP superadmin.
- When communications owner is `cvpap`, `password_link` / `send_welcome`
  are blocked in nuxbill and should be handled via CVPAP WhatsApp/Gmail
  integrations.
- `platform_config` is superadmin-only and controls governance switches.
- `customer_logs` gives partner-scoped recharge/activity logs per customer.
- When `partner_id` is provided, router scope can be derived server-side from
  partner-owned routers (no need to pass explicit `routers` in every call).
- Global CVPAP webhooks remain unchanged; partner/customer webhooks are sent in
  addition to the global endpoint.

## Fork / upstream sync

This fork keeps `master` as a clean mirror of
[hotspotbilling/phpnuxbill](https://github.com/hotspotbilling/phpnuxbill);
all CVPAP work lives on the `cvpap` branch as **new files only**
(`system/plugin/cvpap*`, `docker-compose.cvpap.yml`) so upstream merges never
conflict. To pull upstream updates:

```bash
git fetch upstream
git checkout master && git merge --ff-only upstream/master && git push origin master
git checkout cvpap  && git merge master && git push origin cvpap
```

Note: upstream's `.gitignore` excludes `system/plugin/*`; these files are
force-added (`git add -f`), which keeps them tracked from then on.

### Core-file modifications (watch these during upstream merges)

Kept to an absolute minimum and marked with `// CVPAP` comments:

- `system/controllers/register.php` — email OTP on registration: an email
  address entered as the OTP identifier receives the code by email (serves
  as email verification; recorded as customer field "Email Verified"), and
  the OTP template no longer requires an SMS gateway to be configured.
- `system/controllers/forgot.php` — link-based password reset: step 2
  accepts GET (emailed links) and shows a choose-your-own-password form
  (new step 3 + additive template `customer/forgot-set-password.tpl`)
  instead of printing a random password on screen.

If an upstream merge conflicts in these files, re-apply the `// CVPAP`
blocks — each is a few self-contained lines.
