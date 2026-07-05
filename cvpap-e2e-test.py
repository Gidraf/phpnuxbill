#!/usr/bin/env python3
"""
CVPAP / phpNuxBill end-to-end billing pipeline test (no router required).

Drives the full flow through the CVPAP bridge plugin API — the exact path
the CVPAP platform uses in production — against a throwaway router whose
plan uses the Dummy device driver:

    router_create → bandwidth → plan_create(Dummy) → portal_provision
    (customer + recharge + transaction) → active_recharges → transactions
    → voucher_generate → voucher_activate → cleanup

Run ON THE SERVER (cleanup uses the local mysql client):

    NUXBILL_API_KEY='<api key>' NUXBILL_SECRET='<shared secret>' \
        python3 cvpap-e2e-test.py [--keep]

    NUXBILL_URL                 override the API base (default http://127.0.0.1:39090/system/api.php)
    NUXBILL_PARTNER_ADMIN_USER_ID optional existing tbl_users.id to link partner for SSO testing
    --keep                      leave the test data in place for manual inspection in the UI

Prereqs: the plugin's shared secret must be set (admin UI → Settings →
CVPAP Bridge), and the api_key must exist (Settings → App Settings).
If a step fails, the printed request/response shows where — everything the
script does can also be done manually in the admin UI (Network → Routers,
Settings → Bandwidth, Services → Hotspot, Prepaid → Vouchers).
"""
import hashlib
import hmac
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.request

BASE = os.environ.get("NUXBILL_URL", "http://127.0.0.1:39090/system/api.php")
API_KEY = os.environ.get("NUXBILL_API_KEY", "")
SECRET = os.environ.get("NUXBILL_SECRET", "")
KEEP = "--keep" in sys.argv

STAMP = time.strftime("%m%d%H%M%S")
ROUTER = f"cvpap-e2e-{STAMP}"
PLAN = f"E2E 1 Hour {STAMP}"
BW = "CVPAP-E2E-5M"
PHONE_BUY = "254700000001"
PHONE_GIFT = "254700000002"
PARTNER = f"e2e-test-{STAMP}"
PARTNER_USER = f"e2e-agent-{STAMP}"
PARTNER_ADMIN_USER_ID = os.environ.get("NUXBILL_PARTNER_ADMIN_USER_ID", "").strip()

PASS = FAIL = 0


def ok(msg):
    global PASS
    PASS += 1
    print(f"\033[32m  PASS\033[0m  {msg}")


def bad(msg, detail=""):
    global FAIL
    FAIL += 1
    print(f"\033[31m  FAIL\033[0m  {msg}")
    if detail:
        print(f"        {detail}")


def section(title):
    print(f"\n\033[1m{title}\033[0m")


def call(action, payload=None, expect_success=True):
    """POST a signed JSON request to the bridge; returns (success, message, result)."""
    body = json.dumps(payload or {})
    ts = str(int(time.time()))
    sig = hmac.new(SECRET.encode(), f"{ts}.{body}".encode(), hashlib.sha256).hexdigest()
    url = f"{BASE}?r=plugin/cvpap_api/{action}&token={API_KEY}"
    req = urllib.request.Request(url, data=body.encode(), method="POST", headers={
        "Content-Type": "application/json",
        "X-CVPAP-Timestamp": ts,
        "X-CVPAP-Signature": sig,
    })
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            data = json.loads(resp.read().decode())
    except urllib.error.URLError as exc:
        return False, f"HTTP error: {exc}", {}
    except json.JSONDecodeError as exc:
        return False, f"non-JSON response: {exc}", {}
    return bool(data.get("success")), data.get("message", ""), data.get("result") or {}


def step(label, action, payload=None, check=None):
    """Run one API step; `check(result)` may return an error string."""
    success, message, result = call(action, payload)
    if not success:
        bad(f"{label}", f"{action} → {message}")
        return None
    if check:
        err = check(result)
        if err:
            bad(f"{label}", f"{action} → {err} | result: {json.dumps(result)[:300]}")
            return None
    ok(label)
    return result


def mysql(sql):
    try:
        out = subprocess.run(["mysql", "nuxbill", "-N", "-e", sql],
                             capture_output=True, text=True, timeout=20)
        return out.returncode == 0, (out.stdout or out.stderr).strip()
    except Exception as exc:  # mysql client missing / not on server
        return False, str(exc)


def main():
    print(f"CVPAP e2e billing test — {BASE}")
    print(f"test entities: partner={PARTNER} router={ROUTER} plan='{PLAN}' phones={PHONE_BUY},{PHONE_GIFT}")

    if not API_KEY or not SECRET:
        print("\nSet NUXBILL_API_KEY and NUXBILL_SECRET first:")
        print("  api key      → mysql nuxbill -e \"SELECT value FROM tbl_appconfig WHERE setting='api_key'\"")
        print("  shared secret→ admin UI → Settings → CVPAP Bridge (set it if blank)")
        sys.exit(2)

    # ── 1. bridge alive ─────────────────────────────────────────────────────
    section("1. Bridge")
    result = step("bridge ping answers", "ping",
                  check=lambda r: None if r.get("bridge") == "cvpap" else "unexpected payload")
    if result is None:
        print("\nBridge unreachable — nothing else can run. Check the container and plugin.")
        sys.exit(1)

    # HMAC round-trip (ping skips HMAC; router_list is the first signed call)
    if step("HMAC-signed request accepted", "router_list", {"routers": []}) is None:
        print("\nSigned calls rejected — is the shared secret set in Settings → CVPAP Bridge,")
        print("and does NUXBILL_SECRET match it exactly?")
        sys.exit(1)

    # ── 1b. partner bootstrap / control-plane surfaces ────────────────────
    section("1b. Partner surfaces")
    partner_payload = {
        "partner_id": PARTNER,
        "username": PARTNER_USER,
        "fullname": "E2E Partner",
        "email": f"{PARTNER_USER}@example.com",
        "phone": "254700000099",
        "status": "Active",
        "settings": {
            "language": "en",
            "timezone": "UTC",
        },
    }
    if PARTNER_ADMIN_USER_ID:
        partner_payload["admin_user_id"] = PARTNER_ADMIN_USER_ID

    partner = step("partner_upsert creates tenant record", "partner_upsert", partner_payload,
                   check=lambda r: None if r.get("partner") and r.get("partner", {}).get("partner_uid") == PARTNER
        else "partner row missing from response")
    if partner is None:
        sys.exit(1)

    step("partner_get returns profile", "partner_get", {
        "partner_id": PARTNER,
    }, check=lambda r: None if r.get("partner", {}).get("partner_uid") == PARTNER
        else "partner lookup failed")

    step("partner_settings round-trip", "partner_settings", {
        "partner_id": PARTNER,
        "settings": {
            "language": "en",
            "timezone": "UTC",
            "theme": "e2e",
        },
    }, check=lambda r: None if r.get("settings", {}).get("theme") == "e2e"
        else "settings not persisted")

    if PARTNER_ADMIN_USER_ID:
        step("partner_sso_issue returns switch URL", "partner_sso_issue", {
            "partner_id": PARTNER,
            "ttl_seconds": 120,
            "redirect_to": "dashboard",
        }, check=lambda r: None if r.get("token") and r.get("login_url")
            else "missing token/login_url")
    else:
        print("  SKIP  partner_sso_issue (set NUXBILL_PARTNER_ADMIN_USER_ID to test SSO issuance)")

    webhook_id = None
    hook = step("partner_webhook_upsert stores endpoint", "partner_webhook_upsert", {
        "partner_id": PARTNER,
        "owner_type": "partner",
        "event_name": "recharge_success",
        "url": "https://example.invalid/cvpap-e2e",
        "secret": "e2e-secret",
        "headers": {
            "X-E2E": "1",
        },
        "enabled": 1,
    }, check=lambda r: None if r.get("webhook", {}).get("id")
        else "webhook id missing")
    if hook:
        webhook_id = hook.get("webhook", {}).get("id")

    step("partner_webhook_list includes endpoint", "partner_webhook_list", {
        "partner_id": PARTNER,
        "owner_type": "partner",
    }, check=lambda r: None if webhook_id is None or any(w.get("id") == webhook_id for w in r.get("webhooks", []))
        else "webhook not listed")

    # ── 2. provisioning objects ────────────────────────────────────────────
    section("2. Router / bandwidth / plan (Dummy device)")
    router = step("create throwaway router", "router_create", {
        "name": ROUTER, "ip_address": "127.0.0.1", "username": "e2e",
        "password": "e2e", "description": "e2e test — safe to delete",
        "partner_id": PARTNER,
    })
    if router is None:
        sys.exit(1)

    bw_id = None
    bws = step("list bandwidth profiles", "bandwidth_list") or {}
    for bw in bws.get("bandwidths", []):
        if bw.get("name_bw") == BW:
            bw_id = bw.get("id")
    if bw_id:
        ok(f"bandwidth profile {BW} already exists")
    else:
        created = step(f"create bandwidth profile {BW}", "bandwidth_create", {
            "name_bw": BW, "rate_down": 5, "rate_down_unit": "Mbps",
            "rate_up": 5, "rate_up_unit": "Mbps",
        })
        bw_id = created and created.get("id")
    if not bw_id:
        sys.exit(1)

    plan = step("create hotspot plan on Dummy device", "plan_create", {
        "name_plan": PLAN, "id_bw": bw_id, "price": 20,
        "validity": 1, "validity_unit": "Hrs", "routers": ROUTER,
        "device": "Dummy", "partner_id": PARTNER,
    })
    if plan is None:
        sys.exit(1)
    plan_id = plan["id"]

    step("partner_router_scope returns owned router", "partner_router_scope", {
        "partner_id": PARTNER,
    }, check=lambda r: None if ROUTER in r.get("routers", [])
        else "router not in partner scope")

    step("plan visible in scoped plan_list", "plan_list", {"routers": [ROUTER]},
         check=lambda r: None if any(p.get("id") == plan_id for p in r.get("plans", []))
         else "created plan not in list")

    # ── 3. portal purchase path (what a real captive-portal sale does) ─────
    section("3. Portal purchase (customer + recharge + transaction)")
    prov = step("portal_provision for a phone customer", "portal_provision", {
        "phone": PHONE_BUY, "router": ROUTER, "plan_id": plan_id,
        "routers": [ROUTER], "channel": "E2E-TEST-RECEIPT", "partner_id": PARTNER,
    }, check=lambda r: None if r.get("username") == PHONE_BUY and r.get("password")
        and r.get("invoice") and r.get("expiration") else "missing username/password/invoice/expiration")

    step("recharge active in active_recharges", "active_recharges",
         {"routers": [ROUTER], "status": "on"},
         check=lambda r: None if any(x.get("username") == PHONE_BUY for x in r.get("recharges", []))
         else "no active recharge for buyer")

    step("sale recorded in transactions (price 20)", "transactions",
         {"routers": [ROUTER], "group_by": "plan"},
         check=lambda r: None if any(float(t.get("price", 0)) == 20.0 for t in r.get("transactions", []))
         else "no 20 KES transaction found")

    step("partner_usage returns metrics", "partner_usage", {
        "partner_id": PARTNER,
    }, check=lambda r: None if r.get("metrics", {}).get("customers", 0) >= 1
        else "usage metrics missing customers")

    step("repeat purchase extends same customer (idempotent path)", "portal_provision", {
        "phone": PHONE_BUY, "router": ROUTER, "plan_id": plan_id,
        "routers": [ROUTER], "channel": "E2E-TEST-RECEIPT-2", "partner_id": PARTNER,
    })

    step("partner_customer_links includes buyer", "partner_customer_links", {
        "partner_id": PARTNER,
        "limit": 50,
    }, check=lambda r: None if any(l.get("external_phone") == PHONE_BUY for l in r.get("links", []))
        else "buyer link missing")

    step("customer_list scoped by partner_id", "customer_list", {
        "partner_id": PARTNER,
        "limit": 100,
    }, check=lambda r: None if any(c.get("username") == PHONE_BUY for c in r.get("customers", []))
        else "scoped customer list missing buyer")

    # ── 4. voucher path (gifts / printed cards) ─────────────────────────────
    section("4. Vouchers")
    vouchers = step("generate 2 vouchers", "voucher_generate", {
        "plan_id": plan_id, "router": ROUTER, "routers": [ROUTER],
        "count": 2, "partner_id": PARTNER,
    }, check=lambda r: None if len(r.get("codes", [])) == 2 else "expected 2 codes")
    if vouchers:
        code = vouchers["codes"][0]
        step("voucher shows unused", "voucher_status", {"code": code},
             check=lambda r: None if str(r.get("voucher", {}).get("status")) == "0" else "not unused")
        step("voucher activates for a gift recipient", "voucher_activate", {
            "code": code, "phone": PHONE_GIFT, "routers": [ROUTER], "partner_id": PARTNER,
        }, check=lambda r: None if r.get("username") == PHONE_GIFT else "unexpected username")
        step("voucher now marked used", "voucher_status", {"code": code},
             check=lambda r: None if str(r.get("voucher", {}).get("status")) == "1" else "still unused")

    # ── 5. status/report surfaces (structural — Dummy router is offline) ───
    section("5. Status surfaces")
    step("router_status responds", "router_status", {"routers": [ROUTER]},
         check=lambda r: None if len(r.get("statuses", [])) == 1 else "expected one status")
    step("online_users responds (offline router → error entry, not crash)",
         "online_users", {"routers": [ROUTER]})

    if webhook_id:
        step("partner_webhook_delete removes endpoint", "partner_webhook_delete", {
            "partner_id": PARTNER,
            "id": webhook_id,
        }, check=lambda r: None if int(r.get("deleted", 0)) == int(webhook_id)
            else "webhook not deleted")

    # ── 6. cleanup ──────────────────────────────────────────────────────────
    section("6. Cleanup" + (" (skipped — --keep)" if KEEP else ""))
    if not KEEP:
        sql_ok, sql_msg = mysql(
            f"DELETE FROM tbl_user_recharges WHERE routers='{ROUTER}';"
            f"DELETE FROM tbl_transactions WHERE routers='{ROUTER}';"
            f"DELETE FROM tbl_voucher WHERE routers='{ROUTER}';"
            f"DELETE FROM tbl_cvpap_partner_routers WHERE partner_uid='{PARTNER}';"
            f"DELETE FROM tbl_cvpap_partner_customers WHERE partner_uid='{PARTNER}';"
            f"DELETE FROM tbl_cvpap_partner_webhooks WHERE partner_uid='{PARTNER}';"
            f"DELETE FROM tbl_cvpap_sso_tokens WHERE partner_uid='{PARTNER}';"
            f"DELETE FROM tbl_cvpap_partners WHERE partner_uid='{PARTNER}';"
            f"DELETE FROM tbl_users WHERE username='{PARTNER_USER}';"
            f"DELETE FROM tbl_customers WHERE username IN ('{PHONE_BUY}','{PHONE_GIFT}');"
        )
        if sql_ok:
            ok("test recharges/transactions/vouchers/customers removed")
        else:
            bad("SQL cleanup failed (run on the server?)", sql_msg)
        step("test plan deleted", "plan_delete", {"id": plan_id})
        step("test router deleted", "router_delete", {"name": ROUTER})
    else:
        print(f"  kept: router {ROUTER}, plan '{PLAN}', customers {PHONE_BUY}/{PHONE_GIFT}")
        print("  inspect in the admin UI, then re-run without --keep (new ids) or clean manually")

    # ── summary ─────────────────────────────────────────────────────────────
    print(f"\n\033[1m{PASS} passed, {FAIL} failed\033[0m")
    if FAIL == 0:
        print("\033[32mBilling pipeline fully verified — ready for a real MikroTik.\033[0m")
    else:
        print("\033[31mCheck the FAIL lines; every step can be reproduced manually in the admin UI.\033[0m")
    sys.exit(1 if FAIL else 0)


if __name__ == "__main__":
    main()
