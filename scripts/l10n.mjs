import { readFileSync, readdirSync, writeFileSync, mkdirSync } from 'node:fs'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { resolve, relative, dirname } from 'node:path'
import { parse as parseSfc } from '@vue/compiler-sfc'
import { parse as parseTemplate } from '@vue/compiler-dom'
import ts from 'typescript'

const root = fileURLToPath(new URL('../', import.meta.url))
const app = 'musiccurator'
function files(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
    const path = resolve(directory, entry.name)
    return entry.isDirectory() ? files(path) : [path]
  })
}

export function extractMessages() {
  const messages = new Map()
  function add(singular, plural, file) {
    const key = plural === undefined ? singular : `_${singular}_::_${plural}_`
    const entry = messages.get(key) ?? { key, singular, plural, references: new Set() }
    entry.references.add(relative(root, file))
    messages.set(key, entry)
  }
  function expression(source, file) {
    const tree = ts.createSourceFile('strings.ts', source, ts.ScriptTarget.Latest, true)
    function visit(node) {
      if (ts.isCallExpression(node) && ['t', 'n'].includes(node.expression.getText(tree)) && node.arguments[0]?.text === app) {
        const singular = node.arguments[1]
        if (!singular || !ts.isStringLiteral(singular)) throw new Error(`Non-literal translation source in ${file}`)
        const plural = node.expression.getText(tree) === 'n' ? node.arguments[2]?.text : undefined
        add(singular.text, plural, file)
      }
      ts.forEachChild(node, visit)
    }
    visit(tree)
  }
  for (const file of files(resolve(root, 'src'))) {
    const source = readFileSync(file, 'utf8')
    if (file.endsWith('.ts')) expression(source, file)
    if (!file.endsWith('.vue')) continue
    const { descriptor } = parseSfc(source)
    expression(descriptor.scriptSetup?.content ?? descriptor.script?.content ?? '', file)
    function visit(node) {
      if (node.type === 5) expression(node.content.content, file)
      for (const prop of node.props ?? []) if (prop.type === 7 && prop.exp) expression(prop.exp.content, file)
      node.children?.forEach(visit)
    }
    if (descriptor.template) visit(parseTemplate(descriptor.template.content))
  }
  for (const file of files(resolve(root, 'lib')).filter(file => file.endsWith('.php'))) {
    const source = readFileSync(file, 'utf8')
    for (const match of source.matchAll(/->t\(\s*'((?:\\.|[^'\\])*)'/gu)) {
      add(match[1].replace(/\\([\\'])/gu, '$1'), undefined, file)
    }
  }
  return [...messages.values()].sort((a, b) => a.key.localeCompare(b.key, 'en'))
}

// Standard gettext PO strings use the same quoted escapes as JSON. Handle
// multiline strings, plural forms, comments and fuzzy flags without dependencies.
export function readPo(source) {
  const entries = []
  let entry = {}, field
  const finish = () => {
    if (entry.msgid !== undefined) entries.push(entry)
    entry = {}; field = undefined
  }
  for (const raw of source.split('\n')) {
    const line = raw.trim()
    if (!line) { finish(); continue }
    if (line.startsWith('#')) {
      if (line.startsWith('#,') && /\bfuzzy\b/u.test(line)) entry.fuzzy = true
      continue
    }
    const match = /^(msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+(".*")$/u.exec(line)
    if (match) {
      if (match[1] === 'msgid' && entry.msgid !== undefined) finish()
      field = match[1]; entry[field] = JSON.parse(match[2]); continue
    }
    if (line.startsWith('"') && field) { entry[field] += JSON.parse(line); continue }
    throw new Error(`Unsupported PO line: ${line}`)
  }
  finish()
  return entries
}

const quote = value => JSON.stringify(value)
export function renderPo(messages, language, translations = {}) {
  const headers = `Project-Id-Version: MusicCurator\nLanguage: ${language}\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\nPlural-Forms: nplurals=2; plural=(n != 1);\n`
  return '# MusicCurator translations. See docs/TRANSLATING.md.\nmsgid ""\nmsgstr ' + quote(headers) + '\n\n' + messages.map(entry => {
    const prefix = '#: ' + [...entry.references].sort().join(' ') + '\nmsgid ' + quote(entry.singular) + '\n'
    if (entry.plural !== undefined) {
      const values = translations[entry.key] ?? (language === 'en' ? [entry.singular, entry.plural] : ['', ''])
      return prefix + 'msgid_plural ' + quote(entry.plural) + '\nmsgstr[0] ' + quote(values[0]) + '\nmsgstr[1] ' + quote(values[1]) + '\n'
    }
    return prefix + 'msgstr ' + quote(translations[entry.key] ?? (language === 'en' ? entry.singular : '')) + '\n'
  }).join('\n')
}

export function placeholders(value) {
  return [...value.matchAll(/\{[^{}]+\}|%(?:\d+\$)?[nsd]/gu)].map(match => match[0]).sort()
}
export function compilePo(messages, entries) {
  const header = entries.find(entry => entry.msgid === '')?.msgstr ?? ''
  const pluralForm = /Plural-Forms:\s*([^\n]+)/u.exec(header)?.[1]
  const count = Number(/nplurals=(\d+)/u.exec(pluralForm ?? '')?.[1])
  if (!pluralForm || !count) throw new Error('PO header must define Plural-Forms')
  const byKey = new Map(entries.filter(entry => entry.msgid).map(entry => [entry.msgid_plural === undefined ? entry.msgid : `_${entry.msgid}_::_${entry.msgid_plural}_`, entry]))
  const translations = {}
  for (const message of messages) {
    const entry = byKey.get(message.key)
    if (!entry || entry.fuzzy) throw new Error(`Missing or fuzzy translation: ${message.singular}`)
    const values = message.plural === undefined ? [entry.msgstr] : Array.from({ length: count }, (_, i) => entry[`msgstr[${i}]`])
    for (const value of values) {
      if (!value?.trim()) throw new Error(`Empty translation: ${message.singular}`)
      if (JSON.stringify(placeholders(value)) !== JSON.stringify(placeholders(message.singular))) throw new Error(`Placeholder mismatch: ${message.singular}`)
    }
    translations[message.key] = message.plural === undefined ? values[0] : values
  }
  return { translations, pluralForm }
}

function main() {
  const check = process.argv.includes('--check')
  const messages = extractMessages()
  function emit(file, content) {
    const path = resolve(root, file)
    if (check) {
      if (readFileSync(path, 'utf8') !== content) throw new Error(`Outdated ${file}; run npm run l10n:build`)
    } else {
      mkdirSync(dirname(path), { recursive: true }); writeFileSync(path, content)
    }
  }
  emit('translationfiles/templates/musiccurator.pot', renderPo(messages, ''))
  emit('translationfiles/en/musiccurator.po', renderPo(messages, 'en'))
  const languages = readdirSync(resolve(root, 'translationfiles'), { withFileTypes: true }).filter(entry => entry.isDirectory() && entry.name !== 'templates').map(entry => entry.name).sort()
  for (const language of languages) {
    const catalog = compilePo(messages, readPo(readFileSync(resolve(root, `translationfiles/${language}/musiccurator.po`), 'utf8')))
    emit(`l10n/${language}.json`, JSON.stringify(catalog, null, 2) + '\n')
    emit(`l10n/${language}.js`, `OC.L10N.register(\n  "musiccurator",\n  ${JSON.stringify(catalog.translations, null, 2)},\n  ${quote(catalog.pluralForm)}\n);\n`)
  }
  console.log(`${messages.length} messages validated for ${languages.join(', ')}.`)
}
if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) main()
