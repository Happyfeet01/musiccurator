import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import { test } from 'node:test'
import { runInNewContext } from 'node:vm'
import { createSSRApp, h } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parse as parseTemplate } from '@vue/compiler-dom'
import ts from 'typescript'
import { getCanonicalLocale } from '@nextcloud/l10n'
import { t, n } from '../../src/l10n.ts'
import { extractMessages, readPo, compilePo } from '../../scripts/l10n.mjs'

function language(code) {
  globalThis._nc_l10n_language = code
  globalThis._nc_l10n_locale = code === 'de' ? 'de_DE' : 'en_US'
  globalThis._oc_l10n_registry_translations.musiccurator = {}
  if (code !== 'de' && code !== 'en') return
  // Execute the shipped Nextcloud JS catalog, not a separate test dictionary.
  runInNewContext(readFileSync(new URL(`../../l10n/${code}.js`, import.meta.url), 'utf8'), {
    OC: { L10N: { register(app, translations, pluralForm) {
      assert.equal(app, 'musiccurator')
      assert.match(pluralForm, /nplurals=2/u)
      globalThis._oc_l10n_registry_translations[app] = translations
      globalThis._oc_l10n_registry_plural_functions[app] = number => Number(number !== 1)
    } } },
  })
}

test('German and English follow the Nextcloud catalog and use the Nextcloud locale', () => {
  language('de')
  assert.equal(t('musiccurator', 'Settings'), 'Einstellungen')
  assert.equal(t('musiccurator', 'Last.fm API key'), 'Last.fm-API-Schlüssel')
  assert.equal(t('musiccurator', 'Delete {label}', { label: 'Last.fm-API-Schlüssel' }), 'Last.fm-API-Schlüssel löschen')
  assert.equal(getCanonicalLocale(), 'de-DE')
  language('en')
  assert.equal(t('musiccurator', 'Settings'), 'Settings')
  assert.equal(getCanonicalLocale(), 'en-US')
})

test('zero, singular and plural counts and named values survive localization', () => {
  for (const [code, expected] of [['de', ['0 Alben', '1 Album', '2 Alben']], ['en', ['0 albums', '1 album', '2 albums']]]) {
    language(code)
    for (const count of [0, 1, 2]) assert.equal(n('musiccurator', '%n album', '%n albums', count), expected[count])
  }
  language('de')
  assert.equal(n('musiccurator', '%n result · {duration} ms', '%n results · {duration} ms', 2, { duration: 42 }), '2 Ergebnisse · 42 ms')
})

test('unavailable languages and unknown keys fall back to English source text', () => {
  language('fr')
  assert.equal(t('musiccurator', 'Settings'), 'Settings')
  assert.equal(n('musiccurator', '%n album', '%n albums', 2), '2 albums')
  language('de')
  assert.equal(t('musiccurator', 'A future source string'), 'A future source string')
})

test('filenames and user data are preserved and Vue escapes them exactly once', async () => {
  language('de')
  const filename = 'A&B <live> "Überraschung".mp3'
  const text = t('musiccurator', 'Select {track} for batch preview', { track: filename })
  assert.equal(text, `${filename} für Sammelvorschau auswählen`)
  const html = await renderToString(createSSRApp({ render: () => h('span', text) }))
  assert.ok(html.includes('A&amp;B &lt;live&gt;'))
  assert.ok(!html.includes('&amp;amp;'))
  assert.ok(!html.includes('<live>'))
})

test('every extracted UI and PHP message has validated German and English translations', () => {
  const messages = extractMessages()
  assert.ok(messages.length > 300)
  for (const code of ['de', 'en']) {
    const po = readPo(readFileSync(new URL(`../../translationfiles/${code}/musiccurator.po`, import.meta.url), 'utf8'))
    const compiled = compilePo(messages, po)
    const shipped = JSON.parse(readFileSync(new URL(`../../l10n/${code}.json`, import.meta.url), 'utf8'))
    assert.deepEqual(compiled, shipped)
  }
})

test('translation validation rejects missing translations and damaged placeholders', () => {
  const messages = [{ key: 'Hello {name}', singular: 'Hello {name}' }]
  const header = { msgid: '', msgstr: 'Plural-Forms: nplurals=2; plural=(n != 1);\n' }
  assert.throws(() => compilePo(messages, [header]), /Missing/u)
  assert.throws(() => compilePo(messages, [header, { msgid: 'Hello {name}', msgstr: 'Hallo' }]), /Placeholder mismatch/u)
})

test('Vue templates contain no untranslated interface text or HTML injection directives', () => {
  const components = ['App.vue', ...readdirSync(new URL('../../src/components/', import.meta.url)).filter(file => file.endsWith('.vue')).map(file => `components/${file}`)]
  for (const file of components) {
    const source = readFileSync(new URL(`../../src/${file}`, import.meta.url), 'utf8')
    const template = parse(source).descriptor.template.content
    function visit(node) {
      if (node.type === 2) assert.ok(!/[A-Za-z]{2}/u.test(node.content), `${file}: untranslated ${node.content}`)
      for (const prop of node.props ?? []) {
        if (prop.type === 7) assert.notEqual(prop.name, 'html', 'Plain-text translation helper must never feed v-html')
        if (prop.type === 6 && ['title', 'aria-label', 'label', 'hint'].includes(prop.name)) assert.ok(!/[A-Za-z]{2}/u.test(prop.value?.content ?? ''), `${file}: untranslated ${prop.name}`)
      }
      node.children?.forEach(visit)
    }
    visit(parseTemplate(template))
  }
})

// Render the actual app template with only Nextcloud shell components stubbed.
async function appComponent(file = 'App.vue') {
  const source = readFileSync(new URL(`../../src/${file}`, import.meta.url), 'utf8')
  let script = compileScript(parse(source).descriptor, { id: 'localized-app', inlineTemplate: true }).content
  script = script.replace(/^import (\w+) from '[^']+\.vue'$/gmu, 'const $1 = Shell')
    .replace(/^import (\w+) from '@nextcloud\/vue\/[^']+'$/gmu, 'const $1 = Shell')
    .replaceAll("from 'vue'", `from '${import.meta.resolve('vue')}'`)
    .replaceAll('from "vue"', `from '${import.meta.resolve('vue')}'`)
    .replaceAll("from '@nextcloud/l10n'", `from '${import.meta.resolve('@nextcloud/l10n')}'`)
    .replaceAll("from './l10n.ts'", `from '${new URL('../../src/l10n.ts', import.meta.url).href}'`)
    .replaceAll("from '../l10n.ts'", `from '${new URL('../../src/l10n.ts', import.meta.url).href}'`)
    .replaceAll("from '../composables/useBatchMetadata.ts'", `from '${new URL('../../src/composables/useBatchMetadata.ts', import.meta.url).href}'`)
    .replaceAll("from './composables/useTrackProgress.ts'", `from '${new URL('../../src/composables/useTrackProgress.ts', import.meta.url).href}'`)
  const { outputText } = ts.transpileModule(script, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext } })
  const shell = `import { h } from '${import.meta.resolve('vue')}'; const Shell = { props: ['name'], setup(props, { slots }) { return () => h('div', [props.name ?? '', ...Object.values(slots).flatMap(slot => slot?.() ?? [])]) } };\n`
  return (await import(`data:text/javascript;base64,${Buffer.from(shell + outputText).toString('base64')}`)).default
}

test('the real app renders German navigation and changes to English with the user language', async () => {
  const component = await appComponent()
  language('de')
  const german = await renderToString(createSSRApp(component))
  assert.ok(german.includes('Musikordner auswählen'))
  assert.ok(german.includes('Tags fehlen'))
  assert.ok(german.includes('Einstellungen'))
  assert.ok(german.includes('Deine Musikbibliothek in Nextcloud.'))
  language('en')
  const english = await renderToString(createSSRApp(component))
  assert.ok(english.includes('Choose music folder'))
  assert.ok(english.includes('Needs tags'))
  assert.ok(!english.includes('Musikordner auswählen'))
})


test('German track rows preserve original metadata and machine-readable status', async () => {
  const component = await appComponent('components/BatchTrackList.vue')
  language('de')
  const track = { path: '/Music/Original & Live.mp3', filename: 'Original & Live.mp3', title: 'Original English Title', artist: 'Original Artist', album: 'Original Album', albumArtist: '', status: 'Needs tags' }
  const html = await renderToString(createSSRApp(component, { shownTracks: [track], allTracks: [track], selectedPath: '', activeProviderNames: [], hasMore: false, remaining: 0 }))
  assert.ok(html.includes('Tags fehlen'))
  assert.ok(html.includes('data-status="needs-tags"'))
  assert.ok(html.includes('Original English Title'))
  assert.ok(html.includes('Original Artist'))
  assert.ok(html.includes('Original Album'))
  assert.deepEqual(track, { path: '/Music/Original & Live.mp3', filename: 'Original & Live.mp3', title: 'Original English Title', artist: 'Original Artist', album: 'Original Album', albumArtist: '', status: 'Needs tags' })
})
