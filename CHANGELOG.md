# Changelog

## [1.6.3] — 2026-08-31

### Security
- Contributor force-refresh bound to workspace saved PLZ/week (cannot probe arbitrary postcodes into shared cache)
- List/watch update and delete serialized per workspace (`ekc-li-` / `ekc-wa-` locks)
- Personal and create-workspace lock keys fit `oc_file_locks` (64-char `ekc-pw-` / `ekc-wc-` + md5)
- Service-layer ACL on shopping list and watch user methods; cron uses job-trusted paths only
- Peer-scoped directory search for non-admins; opaque 403 on forbidden workspace access
- Removed dual prefs source-of-truth in `OfferFetchService`; workspace prefs only
- HTTP offers use ACL-aware `hitsForUser`; workspace delete with cascade for individual managers

### UI / accessibility
- Theme tokens map to Nextcloud `--color-*`; light/dark/high-contrast and custom accent safe
- Contributor PLZ/week fields locked in UI; settings-locked chrome matches server policy
- Danger delete section and solid danger CTAs; private badge uses main-text (AA under custom accents)
- Visual regression + theme matrix + axe WCAG 2.1 AA E2E gauntlet (mobile through 4K)
- App Store screenshots + `info.xml` metadata (bugs, repository, keywords)

### Tests
- PHPUnit 184 / 709; integration 35 / 83; mutation gate 15/15; a11y 6/6; theme 19/19; visual 13/13

## [1.6.0] — 2026-08-30

### Security
- Private-by-default shopping spaces (BudgetCheck-style): each user gets their own workspace; only invited members see the list
- Optional sharing via individual invite (viewer / contributor / manager); groups only on Standard spaces
- Opaque `access_denied` (403) for missing ≡ forbidden workspace IDs; ghost IDs no longer grant app-admin write
- User/group delete purges memberships (cascade sole-owned spaces) instead of legacy `user_id` row deletes

### UI
- Shopping-space switcher, New private list, Settings → Shopping space / People
- Viewer read-only chrome; honest privacy copy (not encryption)

## [1.5.6] — 2026-08-30

### UI
- Cache-bust app menu icon URL (`app-menu.svg`) so browsers that cached the old green tile under `app.svg` (multi-month Cache-Control) pick up the shopping-bag glyph

## [1.5.5] — 2026-08-30

### UI
- Replace filled green tile icons with NC-standard monochrome shopping-bag glyphs (`app.svg` white / `app-dark.svg` black), matching SnackCheck / HomeCheck / HealthCheck

## [1.5.4] — 2026-08-30

### Security / reliability
- Force-refresh coalesce under PLZ lock (90s) to stop Python stampede
- Never persist empty offer lists to the shared PLZ cache
- Reject noise-only staple watches (`bio`, `plus`, `frisch`, …)
- Claim-then-notify for watch alerts (CAS + rollback on notify failure)
- Reject tiny/absurd pack unit rates and negative pack prices in week tips

### Tests
- PHPUnit 186 / 634; mutation gate 11/11; Playwright a11y + theme matrix 25/25

## [1.5.3] — 2026-08-30

### UI / a11y
- Theme tokens for light/dark/high-contrast and custom accents
- Breadcrumb uses main text (AA with custom brand colors)
- Touch targets ≥44px (primary/danger ≥48px)
- Theme × viewport × axe E2E gauntlet

## [1.5.2] — 2026-08-30

### Security
- GET rate limits on list/watch/settings/stores/access
- Distinct `admin_required` vs app-door denial
- Negative €/kg / €/l rejected in `OfferUnitPrice`

## [1.5.0] — 2026-08-30

### UI
- Mobile-first responsive CSS (NC breakpoints)

## [1.4.9] — 2026-08-30

- Baseline household offers app (ALDI Nord + Lidl), list, watches, trends
