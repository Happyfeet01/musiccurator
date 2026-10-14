import { test } from 'node:test'
import assert from 'node:assert/strict'
import { evaluateAudit, verifyBundleSources } from '../../scripts/audit-dependencies.mjs'

const id = 'GHSA-test-test-test'
const policy = { expires: '2026-11-14', advisories: { [id]: { package: 'sample', versions: ['1.0.0'], reason: 'Reviewed dev tool.' } } }
const lock = { packages: { 'node_modules/sample': { version: '1.0.0', dev: true } } }
const full = { vulnerabilities: { sample: { nodes: ['node_modules/sample'], fixAvailable: false, via: [{ name: 'sample', url: `https://github.com/advisories/${id}` }] } } }
const empty = { vulnerabilities: {} }
const run = (f = full, r = empty, l = lock, p = policy, date = '2026-10-14') => evaluateAudit(f, r, l, p, date)

test('permits a documented, pinned, unpatched dev-only advisory', () => assert.equal(run().errors.length, 0))
test('blocks production findings even if their advisory is allowlisted', () => assert.ok(run(full, full).errors.length))
test('blocks a dependency that moves into runtime scope', () => assert.ok(run(full, empty, { packages: { 'node_modules/sample': { version: '1.0.0' } } }).errors.length))
test('blocks new advisories', () => assert.ok(run(full, empty, lock, { expires: policy.expires, advisories: {} }).errors.length))
test('blocks expired exceptions', () => assert.ok(run(full, empty, lock, policy, '2026-11-14').errors.length))
test('blocks an exception once a fix becomes available', () => assert.ok(run({ vulnerabilities: { sample: { ...full.vulnerabilities.sample, fixAvailable: true } } }).errors.length))
test('blocks changed package versions', () => assert.ok(run(full, empty, { packages: { 'node_modules/sample': { version: '1.0.1', dev: true } } }).errors.length))
test('rejects malformed or unavailable reports', () => assert.throws(() => run({ error: 'Registry unavailable' })))
test('follows propagated dependency chains', () => {
  const f = structuredClone(full)
  f.vulnerabilities.parent = { nodes: ['node_modules/parent'], fixAvailable: false, via: ['sample'] }
  const l = structuredClone(lock)
  l.packages['node_modules/parent'] = { version: '1.0.0', dev: true }
  assert.equal(run(f, empty, l).errors.length, 0)
  f.vulnerabilities.parent.via.push('unknown')
  assert.ok(run(f, empty, l).errors.length)
})
test('detects excepted dev packages in browser source maps', () => {
  assert.deepEqual(verifyBundleSources(['../node_modules/sample/index.js'], policy), ['sample'])
  assert.deepEqual(verifyBundleSources(['../src/App.vue'], policy), [])
})
