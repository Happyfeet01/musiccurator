# Automated checks

Pull requests run the frontend build, ESLint, Stylelint, PHP syntax checks,
PHP coding standards, the app metadata schema check, OpenAPI generation and
Psalm against the supported Nextcloud 34 and 35 APIs. Jobs use GitHub's
standard `ubuntu-latest` runner. The Node workflow and signing helper run
the same dependency policy against full and production-only npm audits.

## Release 1.5.0 audit snapshot (2026-10-14)

The release lockfile updates DOMPurify to 3.4.17, pbkdf2 to 3.1.7 and
source-map-js to 1.2.2 using compatible npm audit fixes. ESLint, Stylelint,
the five frontend regression tests and the production build pass afterward.

The complete audit still reports 24 findings (6 low, 6 moderate, 12 high).
The remaining advisory roots are braces, elliptic and sprintf-js; upstream
has no patched versions at this snapshot. `npm audit --omit=dev` reports
zero findings, which limits the remaining reported dependency paths to
development tooling; this is not a blanket proof of application security.
The raw `npm audit --audit-level=moderate` command still exits nonzero.
The release gate uses `node scripts/audit-dependencies.mjs` after building.
It does not discard development dependencies: it evaluates the full report
and a separate production-only report, then verifies browser source maps.

### Temporary reviewed development-tool exceptions

`scripts/audit-policy.json` accepts only three exact advisories and the
reviewed package versions until 2026-11-14:

- braces 3.0.3: GHSA-vfj7-8cjw-p6xm. Build patterns originate from source/configuration; no runtime HTTP/music input reaches these Node tools.
- elliptic 6.6.1: GHSA-848j-6mx2-7j84. Installed by Vite's polyfill tooling, absent from compiled application source maps.
- sprintf-js 1.0.3: GHSA-hp3w-g68c-fv3c. Declaration/API-extractor dependency, absent from compiled application source maps.

These are acknowledged upstream vulnerabilities, not patched packages.
Every affected dependency node must remain marked dev-only in the lockfile.
Runtime vulnerabilities, new advisories, changed exception versions, available
fixes, expiry, unavailable registry reports, and missing bundle-map coverage
all fail the gate. Propagated findings are traced to their advisory roots.
The helper also fails if an excepted package appears in a generated browser
module. The signing helper's runtime allowlist excludes node_modules and
build tools. Ten policy regression tests verify rejection cases. Extending
an exception requires a fresh review; expiry is never renewed automatically.

Psalm 5.26.1 crashes on PHP 8.4 while reflecting RoundingMode enum constants.
Its analysis job uses PHP 8.3 with the matching Composer platform until an
upgrade to Psalm 6 and its baseline implications has been validated. The
separate PHP lint matrix continues checking the app on PHP 8.4. Existing
source-analysis findings remain actionable and are not suppressed.

## Existing PHP analysis findings

Restoring the previously stalled Psalm jobs exposed existing findings in the
backend. The initial check reported 304 findings against Nextcloud 34 and
235 against Nextcloud 35. The `lib/` sources are unchanged from main commit
`6664317fe1ff38adbd69acad1e8f255b205d74ac` in this dependency/CI repair.

The version-specific `psalm-baseline-dev-stable34.xml` and
`psalm-baseline-dev-stable35.xml` files record these findings explicitly.
They are technical debt, not fixes and not proof of a clean PHP analysis.
Psalm remains at error level 1, fails on additional findings, and reports
unused baseline entries when a previously recorded issue is fixed. CI never
regenerates these files automatically.

The backlog includes deprecated Nextcloud configuration APIs, imprecise
metadata-array types, framework-instantiated classes reported as unused, and
Nextcloud 34 stub dependencies. Follow-up work should review array shapes and
provider response validation, migrate deprecated APIs, and improve the
framework annotations/stubs. Findings such as the nullable cached scan row
and the Discogs array operations need individual review; a static-analysis
finding alone does not establish a runtime failure or an exploitable bug.

When fixing a finding, remove its matching baseline entry and run Psalm for
both supported API versions. After installing the matching nextcloud/ocp
version, use `composer run psalm -- --use-baseline=psalm-baseline-dev-stable34.xml`
or `composer run psalm -- --use-baseline=psalm-baseline-dev-stable35.xml`. Do not regenerate the baseline just to make a
failing pull request pass. Any new baseline entry requires explicit review
of the underlying diagnostic and an explanation of why it remains unfixed.

## Dependency updates

Run `npm ci`, `npm run lint`, `npm run stylelint`, `npm run build`, and
`node scripts/audit-dependencies.mjs` before merging frontend dependency updates.
The refreshed lockfile resolves js-yaml 4.3.2. At the time of this repair,
npm audit reports seven low-severity findings in the build-tool dependency
chain, with no moderate, high or critical findings. This is an audit snapshot,
not a guarantee against newly disclosed vulnerabilities.

Vite 8 and TypeScript 7 remain deferred: @nextcloud/vite-config 2.5.4 requires
Vite ^7.3.6, and typescript-eslint 8.70.0 requires TypeScript <6.1.0. Recheck
upstream compatibility before upgrading; do not use `--force` or
`--legacy-peer-deps` to bypass those requirements.

These checks do not replace a functional test in a running Nextcloud instance.

## App Store archive signature

The release helper produces three files: `musiccurator-VERSION.tar.gz`,
its `.sha256` checksum and a `.tar.gz.sig` file containing a single-line
Base64 SHA-512/RSA signature of the exact compressed archive. It verifies
that signature with the public certificate before reporting success.

For the App Store release form, use the direct download URL of that exact
archive and paste the complete contents of `.tar.gz.sig` into Signature,
including any final `=` characters. Do not use the app-registration signature,
SHA-256 checksum, or the archive's internal `appinfo/signature.json` there.
Repackaging or replacing the archive requires generating a new archive
signature. Official guide: https://nextcloudappstore.readthedocs.io/en/latest/developer.html
