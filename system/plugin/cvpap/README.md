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
