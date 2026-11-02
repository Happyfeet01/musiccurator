import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { compileScript, parse } from '@vue/compiler-sfc'
import ts from 'typescript'

// Run the actual component's setup and request handler without a Nextcloud DOM.
const source = readFileSync(new URL('../../src/components/CredentialField.vue', import.meta.url), 'utf8')
const { descriptor } = parse(source)
const script = compileScript(descriptor, { id: 'credential-field-test' }).content
  .replace(/import NcButton from '[^']+'/u, 'const NcButton = {}')
  .replaceAll("from 'vue'", `from '${import.meta.resolve('vue')}'`)
const { outputText } = ts.transpileModule(script, {
  compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext },
})
const { default: component } = await import(`data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`)

function setup(t, fetch) {
  const original = { fetch: globalThis.fetch, window: globalThis.window }
  globalThis.fetch = fetch
  globalThis.window = { OC: { generateUrl: path => `/nextcloud${path}`, requestToken: 'csrf-token' } }
  t.after(() => Object.assign(globalThis, original))
  const events = []
  const field = component.setup({ credential: 'lastfm_key', modelValue: 'unsaved replacement', configured: true }, {
    expose() {}, emit: (...args) => events.push(args),
  })
  return { field, events }
}

test('deletion sends an authenticated CSRF-protected DELETE and clears local state on success', async t => {
  let request
  const { field, events } = setup(t, async (...args) => {
    request = args
    return { ok: true }
  })
  await field.deleteCredential()
  assert.equal(request[0], '/nextcloud/apps/musiccurator/api/settings/credentials/lastfm_key')
  assert.equal(request[1].method, 'DELETE')
  assert.equal(request[1].credentials, 'same-origin')
  assert.equal(request[1].headers.requesttoken, 'csrf-token')
  assert.equal(request[1].body, undefined)
  assert.deepEqual(events, [['busy', true], ['update:modelValue', ''], ['deleted'], ['busy', false]])
  assert.equal(field.deleting.value, false)
  assert.equal(field.error.value, '')
})

test('HTTP and network failures retain the configured credential and expose a retryable error', async t => {
  for (const fail of [async () => ({ ok: false }), async () => { throw new Error('offline') }]) {
    const { field, events } = setup(t, fail)
    await field.deleteCredential()
    assert.deepEqual(events, [['busy', true], ['busy', false]])
    assert.match(field.error.value, /Could not delete/u)
    assert.equal(field.message.value, '')
    assert.equal(field.deleting.value, false)
  }
})

test('a pending delete blocks duplicate requests until it completes', async t => {
  let finish
  let requests = 0
  const { field } = setup(t, () => {
    requests++
    return new Promise(resolve => { finish = resolve })
  })
  const pending = field.deleteCredential()
  assert.equal(field.deleting.value, true)
  await field.deleteCredential()
  assert.equal(requests, 1)
  finish({ ok: true })
  await pending
  assert.equal(field.deleting.value, false)
})
