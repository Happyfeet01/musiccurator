import assert from 'node:assert/strict'
import { test } from 'node:test'
import { useBatchMetadata } from '../../src/composables/useBatchMetadata.ts'

const track = { path: '/Music/test.mp3', title: 'Test', artist: 'Artist', album: '', albumArtist: '', filename: 'test.mp3' }
const provider = { name: 'MusicBrainz', configured: true, attempted: true, ok: true, results: 0, message: '', durationMs: 1 }

async function run(response) {
  const batch = useBatchMetadata(async () => response)
  batch.replaceSelection([track.path])
  await batch.run([track])
  return batch
}

test('an API failure is an error rather than an unmatched recording', async () => {
  const batch = await run({ results: [], providers: [{ ...provider, ok: false, message: 'HTTP 503' }] })
  assert.equal(batch.errorCount.value, 1)
  assert.equal(batch.unmatchedCount.value, 0)
  assert.match(batch.items.value[track.path].error, /MusicBrainz: HTTP 503/)
})

test('successful empty searches remain unmatched', async () => {
  const batch = await run({ results: [], providers: [provider] })
  assert.equal(batch.unmatchedCount.value, 1)
  assert.equal(batch.errorCount.value, 0)
})

test('disabled or unconfigured providers cannot report a successful empty search', async () => {
  const batch = await run({ results: [], providers: [{ ...provider, attempted: false, configured: false, ok: false }] })
  assert.equal(batch.errorCount.value, 1)
  assert.match(batch.items.value[track.path].error, /No metadata provider/)
})

test('partial failures preserve available results and provider diagnostics', async () => {
  const best = { id: 'test', title: 'Test', artist: 'Artist', score: 99, autoAccept: true }
  const batch = await run({ results: [best], providers: [{ ...provider, ok: false, message: 'HTTP 503' }, { ...provider, name: 'Last.fm', results: 1 }] })
  assert.equal(batch.matchedCount.value, 1)
  assert.equal(batch.items.value[track.path].selected.id, 'test')
  assert.match(batch.items.value[track.path].error, /HTTP 503/)
})

test('one failed provider and one empty successful provider still exposes the failure', async () => {
  const batch = await run({ results: [], providers: [{ ...provider, ok: false, message: 'HTTP 503' }, { ...provider, name: 'Last.fm' }] })
  assert.equal(batch.errorCount.value, 1)
})
