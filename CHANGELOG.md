# Changelog

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
