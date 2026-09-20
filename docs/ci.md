# Automated checks

Pull requests run the frontend build, ESLint, Stylelint, PHP syntax checks,
PHP coding standards, the app metadata schema check, OpenAPI generation and
Psalm against the supported Nextcloud 34 and 35 APIs. Jobs use GitHub's
standard `ubuntu-latest` runner. The Node workflow also fails on moderate,
high or critical npm audit findings.

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
`npm audit --audit-level=moderate` before merging frontend dependency updates.
The refreshed lockfile resolves js-yaml 4.3.2. At the time of this repair,
npm audit reports seven low-severity findings in the build-tool dependency
chain, with no moderate, high or critical findings. This is an audit snapshot,
not a guarantee against newly disclosed vulnerabilities.

Vite 8 and TypeScript 7 remain deferred: @nextcloud/vite-config 2.5.4 requires
Vite ^7.3.6, and typescript-eslint 8.70.0 requires TypeScript <6.1.0. Recheck
upstream compatibility before upgrading; do not use `--force` or
`--legacy-peer-deps` to bypass those requirements.

These checks do not replace a functional test in a running Nextcloud instance.
