# Automated checks

Pull requests run the frontend build, ESLint, Stylelint, frontend regression tests,
PHP syntax checks on 8.4 and 8.5, coding standards, app metadata validation,
OpenAPI generation and Psalm 6 against Nextcloud 34 and 35.

## Security audit — 28 October 2026

Both the full npm audit (including development tools) and the production-only
audit report **zero known vulnerabilities**. Compatible lockfile updates patch
fast-uri, follow-redirects, qs and Vite (7.3.7).

The previous Nextcloud Vite configuration pulled in Node crypto polyfills and
elliptic, which has no patched release. MusicCurator does not need Node crypto
in its browser bundle. A small native Vite/Vue configuration now preserves the
Nextcloud asset paths, browser targets, source maps, extracted CSS and license
summary without installing that dependency tree. The former advisory exceptions
have been removed. No security checks were disabled or severity thresholds raised.

Composer audits cover the root lockfile and all five isolated development-tool
lockfiles: cs-fixer, openapi-extractor, phpunit, psalm and rector. All report zero
known advisories and no abandoned packages. CI checks all six lockfiles.
These are registry audit snapshots, not proof that the application has no bugs.

## Existing PHP analysis findings

The Nextcloud 34/35 baselines were migrated to Psalm 6.20.3 as part of the PHP 8.4
upgrade. They document existing source-analysis debt, including deprecated APIs,
imprecise metadata-array types and framework/stub findings. They are not security
fixes. Analysis runs at error level 1, rejects new findings and reports unused
baseline entries. CI never regenerates baselines automatically.

When fixing a finding, remove its matching baseline entry and run Psalm against
both supported API versions. New baseline entries require individual review.

## Reproduce the checks

Run `npm ci`, `npm run lint`, `npm run stylelint`, `npm run test:frontend`,
`npm run build`, and `node scripts/audit-dependencies.mjs`.
Run `composer audit --locked` in the repository root and each `vendor-bin/*`
directory. Full integration testing additionally requires a Nextcloud instance.
