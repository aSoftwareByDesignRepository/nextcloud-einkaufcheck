# EinkaufCheck — App Store release checklist

**App id:** `einkaufcheck` · **Repo:** https://github.com/aSoftwareByDesignRepository/nextcloud-einkaufcheck

## Before you build

1. Bump `appinfo/info.xml` `<version>` and `appinfo/version` (same string).
2. Add `## [X.Y.Z]` section to `CHANGELOG.md`.
3. Regenerate screenshots if UI changed:  
   `npx playwright test e2e/capture-store-screenshots.spec.js --project=chromium-store`
4. Push screenshots to `main` on GitHub (store reads raw.githubusercontent.com URLs in `info.xml`).

## Build & gate

```bash
cd apps/einkaufcheck
make release
bash ../../ready2publish/scripts/release-preflight.sh einkaufcheck build/release/einkaufcheck-$(grep version appinfo/version | tr -d '[:space:]')-production.tar.gz
```

Or from monorepo root:

```bash
./ready4upload/build-production-archives.sh einkaufcheck
```

## Sign (requires App Store certificate)

Request `einkaufcheck` certificate via https://github.com/nextcloud/app-certificate-requests  
Place `~/.nextcloud/certificates/einkaufcheck.{key,crt}` then:

```bash
make release-signed
bash ../../ready2publish/scripts/sign-nextcloud-appstore-archive.sh einkaufcheck build/release/einkaufcheck-1.6.3.tar.gz
```

Copy the **single base64 signature line** for apps.nextcloud.com.

## GitHub release

1. Tag `v1.6.3` on `nextcloud-einkaufcheck`.
2. Attach `einkaufcheck-1.6.3.tar.gz` (signed build from `make release-signed`).
3. Handoff URL: `https://github.com/aSoftwareByDesignRepository/nextcloud-einkaufcheck/releases/download/v1.6.3/einkaufcheck-1.6.3.tar.gz`

## Upload

https://apps.nextcloud.com/apps/einkaufcheck — paste signature, set download URL, upload screenshots if store UI requires refresh.

Full monorepo procedure: `ready2publish/APPSTORE-RELEASE.md`
