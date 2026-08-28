# Divi→Elementor Launch Runbook

**Status as of 2026-08-28.** wordpress.org approved the free plugin on 2026-08-05 and the
launch is partly executed. Steps below are marked DONE (with evidence) or OPEN. Steps that
are still open are operator-confirmed by Lucas.

## 1. Confirm the real wp.org slug — DONE (2026-08-05, `4730bf5`)
Assigned slug is `jhmg-converter-for-divi-to-elementor` (the expected one). The free tree was
renamed, the Pro plugin's `Requires Plugins:` header points at it, and the free `Text Domain:`
matches.

## 2. First SVN release = the TRIMMED free plugin — DONE (2026-08-05)
Live on wp.org as 1.0.0 (`https://wordpress.org/plugins/jhmg-converter-for-divi-to-elementor/`).
The fat reviewed zip was never published; the trimmed post-pro-split tree shipped as 1.0.0.
For reference, the release procedure was:
```
svn co https://plugins.svn.wordpress.org/jhmg-converter-for-divi-to-elementor wporg-svn && cd wporg-svn
rsync -a --delete --exclude='.DS_Store' ../plugin/jhmg-converter-for-divi-to-elementor/ trunk/
# assets (banner/icon/screenshots) from repo assets/ → assets/
svn add --force trunk assets; svn status | grep '^!' | awk '{print $2}' | xargs -I{} svn rm {}
svn cp trunk tags/1.0.0
svn ci -m "1.0.0: initial release" --username lucaslopvet
```

## 3. Publish Pro 1.0.0 to prod — OPEN
No `plugin_releases` row exists for `divi-to-elementor-pro` in prod. Verify before and after:
```
curl -s "https://divi5lab.com/api/plugin/update-check?product=divi-to-elementor-pro&version=0.9.0"
# BEFORE: {"update":false}  ← latestRelease() returned null, i.e. no release row
# AFTER:  {"update":true,"version":"1.0.0",...} with no `package` (no key passed)
```
Publish:
```
cd layoutlab && set -a; source .env.prod; set +a; export POSTGRES_URL="$DATABASE_URL"
npx tsx scripts/release-plugin.ts --product divi-to-elementor-pro --version 1.0.0 \
  --dir ../jhmg-divi-to-elementor/plugin/jhmg-converter-divi-to-elementor-pro \
  --changelog "Initial Pro release: batch conversion, WooCommerce widget mapping, Divi Theme Builder import. Requires the free JHMG Converter plugin."
```
(Note: local dev DB has an e2e 1.0.1 release row — prod has none of that; publish 1.0.0 fresh.)

**Why this matters:** `/api/plugin/download` checks license validity BEFORE it checks for a
release row, so a buyer with a healthy license still 404s on `no_release`. The failure is
silent server-side — the account page looks normal and only the buyer sees the broken download.
Both delivery paths (the WP updater's `package` URL and the "Download Pro" button on
/account/licenses) funnel through the same `latestRelease()` lookup.

**Woo widget pre-publish check (OPEN, decoupled 2026-08-28):** open one Pro-converted page in the
Elementor editor on a site WITH Elementor Pro active and confirm each of the 11 Woo widgets
renders. Only `wc_price` and `wc_cart_notice` have test coverage; the other 9 `wc_*` →
Elementor Pro widgetType strings in `class-woo-modules.php` are unverified, and they were wrong
once already (`c4fb213`). A wrong name renders nothing — no error, no failing test. Decision:
do NOT block the 1.0.0 publish on this; a wrong name ships as a 1.0.1 that the updater can now
deliver. Cheaper alternative to the manual check: obtain an Elementor Pro copy and verify the
11 names against its widget `get_name()` values, then backfill the 9 missing tests.

## 4. Site flip (layoutlab) — DONE (2026-08-05, `bd8296a` + `b0dc60f`)
`BuyProButton product="divi-to-elementor-pro"` is live on /pricing and
/plugins/divi-to-elementor at **$25/yr** (the price was $49 in the original plan; $25 is the
real price as of launch). Pending banners removed, wp.org link added.

## 5. Waitlist email — DROPPED (2026-08-28)
The `divi_to_elementor_waitlist` Loops segment is no longer part of the launch. No action.

## 6. Verify end to end — OPEN (blocked on step 3)
Splits into two halves:
- **Delivery** (do this as soon as step 3 lands): purchase → key email → activate →
  `update-check` returns `update:true` → "Download Pro" on /account/licenses returns a real zip.
  This is the half that catches a missing release row.
- **Features**: batch / Woo / Theme Builder actually render on a real install. Same manual
  Elementor Pro QA as the Woo check in step 3.

## Known-good reference
`elementor-to-divi5-pro` is published and healthy (`update:true`, 1.0.0) — use it as the
comparison when diagnosing D2E delivery.
