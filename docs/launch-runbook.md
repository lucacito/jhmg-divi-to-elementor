# Divi→Elementor Launch Runbook (gated on wordpress.org approval)

Free plugin submitted to wordpress.org; pending review. When approval lands, run these in order —
each step operator-confirmed by Lucas.

## 1. Confirm the real wp.org slug
The approval email / SVN URL names the slug (expected: `jhmg-converter-divi-to-elementor`).
If it differs, update the Pro plugin's `Requires Plugins:` header
(plugin/jhmg-converter-divi-to-elementor-pro/jhmg-converter-divi-to-elementor-pro.php) and re-release.

## 2. First SVN release = the TRIMMED free plugin
wp.org reviewed the fat 1.0.0 zip, but the first public release ships the trimmed tree (this repo,
main, post pro-split merge). Nobody has downloaded the fat zip → keep `Stable tag: 1.0.0`.
```
svn co https://plugins.svn.wordpress.org/<slug> wporg-svn && cd wporg-svn
rsync -a --delete --exclude='.DS_Store' ../plugin/jhmg-converter-divi-to-elementor/ trunk/
# assets (banner/icon/screenshots) from repo assets/ → assets/
svn add --force trunk assets; svn status | grep '^!' | awk '{print $2}' | xargs -I{} svn rm {}
svn cp trunk tags/1.0.0
svn ci -m "1.0.0: initial release" --username lucaslopvet
```

## 3. Publish Pro 1.0.0 to prod
```
cd layoutlab && set -a; source .env.prod; set +a; export POSTGRES_URL="$DATABASE_URL"
npx tsx scripts/release-plugin.ts --product divi-to-elementor-pro --version 1.0.0 \
  --dir ../jhmg-divi-to-elementor/plugin/jhmg-converter-divi-to-elementor-pro \
  --changelog "Initial Pro release: batch conversion, WooCommerce widget mapping, Divi Theme Builder import. Requires the free JHMG Converter plugin."
curl "https://divi5lab.com/api/plugin/update-check?product=divi-to-elementor-pro&version=0.9.0"  # expect update:true, no package
```
(Note: local dev DB has an e2e 1.0.1 release row — prod has none of that; publish 1.0.0 fresh.)

## 4. Site flip (layoutlab)
- /plugins/divi-to-elementor: drop the pending banner → add `<BuyProButton product="divi-to-elementor-pro" label="Get Pro — $49/yr" />`; keep the waitlist form as secondary ("get launch news").
- /pricing: card 2 becomes buyable (BuyProButton) — remove "Coming soon".
- /plugins hub + homepage PluginCards: chip → "Free on wordpress.org · Pro $49/yr"; add wp.org link.
- Update guides that say "pending review" (how-to-convert-divi-to-elementor.md).
- Tests to update: plugin-d2e-page (no-buy-button assertion flips), pricing-page (buy-divi-to-elementor-pro appears), plugins-hub chips.
- Deploy: push main; verify live checkout returns cs_live for divi-to-elementor-pro.

## 5. Waitlist email
Loops: email the `divi_to_elementor_waitlist` segment (source field) — "It's approved; free on wp.org; Pro at $49/yr".

## 6. Verify end to end
Real install from wp.org → Pro purchase → key email → activate → batch/Woo/TB work → update-check.
