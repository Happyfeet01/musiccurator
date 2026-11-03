# Translating MusicCurator

MusicCurator follows the signed-in Nextcloud user's **language** setting. It ships German (`de`) and English (`en`). English is the source language and fallback for unavailable translations. Dates use the user's Nextcloud locale (which can differ from the interface language). There is no app-specific language switch. Reload MusicCurator after changing the Nextcloud language.

Only interface text, application messages and explanatory AI output are localized. Track tags, filenames, paths, provider names and API identifiers are preserved. External providers' diagnostic messages can remain in their original language.

## Contribute now

1. Edit `translationfiles/de/musiccurator.po` with a gettext editor such as Poedit or a text editor. For another language, copy `translationfiles/templates/musiccurator.pot` to `translationfiles/<language>/musiccurator.po` and set `Language`, `Plural-Forms`, and the translations.
2. Keep `{namedPlaceholders}`, `%n`, and PHP printf placeholders (`%s`, `%d`) intact. Translate complete sentences, including both plural forms. Do not mark completed translations as fuzzy.
3. Run `npm ci`, `npm run l10n:build`, `npm run l10n:check`, and `npm run test:frontend`.
4. Include the updated PO files, template, and generated `l10n/<language>.json` and `.js` files in your pull request. All strings in a shipped language must be translated; the check rejects missing translations or damaged placeholders.

`npm run l10n:build` extracts literal `t('musiccurator', ...)`, `n('musiccurator', ...)`, and PHP `$this->l10n->t(...)` calls. It updates the gettext template, generates the English reference catalog, and compiles PO files into Nextcloud's JSON/JS format. English is maintained in the source; don't edit the generated English PO or runtime catalogs by hand. When adding source strings, update the German PO before running the build.

For languages requiring more than two plural forms, set the correct `nplurals` and `plural` expression in the PO header and supply every `msgstr[index]`. The compiler preserves the rule for Nextcloud. The repository's template uses the English two-form rule only as a starting point.

## Nextcloud community on Transifex (prepared, not activated)

Nextcloud's community translation platform is **Transifex**. `.tx/config`, `.l10nignore`, the gettext template and PO files follow Nextcloud's documented setup:

- https://docs.nextcloud.com/server/stable/developer_manual/app_development/translation.html
- https://explore.transifex.com/nextcloud/

The repository configuration does **not** create a Transifex resource, invite a bot or activate synchronization. To participate, the repository owner must arrange repository write access for `nextcloud-bot` and request translation onboarding using the **Request translations** issue template in `nextcloud/docker-ci`. Follow their current permission guidance; no credentials or Transifex token belong in this repository. The resource name in `.tx/config` may need to be adjusted during onboarding. Contributors can already submit PO changes by GitHub pull request.

## Release and verification

The release helper includes `l10n/` in the signed archive and validates the catalogs before signing. Never modify compiled files after signing. CI also checks translations and runs language, fallback, plural, placeholder and interface-coverage tests.

Before releasing, open a Nextcloud test account in German and one in English. Check navigation, settings, credential deletion, batch review, confirmations, errors and dates. Switch languages and reload; saved keys, paths and tags must remain unchanged.
