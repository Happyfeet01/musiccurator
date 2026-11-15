import assert from 'node:assert/strict'
import { test } from 'node:test'
import { filterReviewTracks, useTrackProgress } from '../../src/composables/useTrackProgress.ts'

const data = { reviewStatus: 'done', reviewUpdatedAt: 100, history: [{ id: 1, action: 'marked_done', timestamp: 100 }] }
const response = value => ({ ok: true, json: async () => value })
function environment(t) {
  const original = globalThis.window
  globalThis.window = { OC: { generateUrl: path => `/nextcloud${path}`, requestToken: 'csrf' }, location: { origin: 'https://cloud.example' } }
  t.after(() => { globalThis.window = original })
}

test('a fresh view restores persisted review state; status writes carry CSRF and the selected path', async t => {
  environment(t)
  const requests = []
  const review = useTrackProgress(async (...args) => { requests.push(args); return response(data) })
  await review.load('/Music/Test & Live.mp3')
  assert.deepEqual(review.progress.value, data)
  await review.setStatus('/Music/Test & Live.mp3', 'pending')
  const [url, options] = requests[1]
  assert.equal(new URL(url).searchParams.get('path'), '/Music/Test & Live.mp3')
  assert.equal(options.method, 'POST')
  assert.equal(options.credentials, 'same-origin')
  assert.equal(options.headers.requesttoken, 'csrf')
  assert.equal(options.body.get('status'), 'pending')
  const fresh = useTrackProgress(async () => response(data))
  await fresh.load('/Music/Test & Live.mp3')
  assert.deepEqual(fresh.progress.value, data)
})

test('HTTP, network and malformed responses never mark a track done optimistically', async t => {
  environment(t)
  for (const request of [async () => ({ ok: false, json: async () => ({ message: 'database unavailable' }) }),
    async () => { throw new Error('offline') }, async () => response({}), async () => response({ ...data, history: [null] })]) {
    const review = useTrackProgress(request)
    assert.equal(await review.setStatus('/Music/a.mp3', 'done'), null)
    assert.equal(review.progress.value, null)
    assert.ok(review.error.value)
    assert.equal(review.loading.value, false)
  }
})

test('late responses from the previous track cannot replace the current history', async t => {
  environment(t)
  let finish
  let requests = 0
  const review = useTrackProgress(async () => ++requests === 1 ? new Promise(resolve => { finish = resolve }) : response(data))
  const first = review.load('/Music/old.mp3')
  review.reset()
  await review.load('/Music/new.mp3')
  finish(response({ ...data, reviewStatus: 'pending' }))
  assert.equal(await first, null)
  assert.equal(review.progress.value.reviewStatus, 'done')
})

test('pending saves block duplicate clicks and failures preserve the previous status', async t => {
  environment(t)
  let finish
  let calls = 0
  const review = useTrackProgress(async () => ++calls === 1 ? response(data) : new Promise(resolve => { finish = resolve }))
  await review.load('/Music/a.mp3')
  const pending = review.setStatus('/Music/a.mp3', 'pending')
  assert.equal(await review.setStatus('/Music/a.mp3', 'pending'), null)
  assert.equal(calls, 2)
  finish({ ok: false, json: async () => ({ message: 'failed' }) })
  await pending
  assert.equal(review.progress.value.reviewStatus, 'done')
  assert.equal(review.error.value, 'failed')
})

test('filters include unreviewed existing tracks and operate over more than 5,000 entries', () => {
  const tracks = Array.from({ length: 10001 }, (_, id) => ({ id, reviewStatus: id % 2 ? 'done' : undefined }))
  assert.equal(filterReviewTracks(tracks, 'all').length, 10001)
  assert.equal(filterReviewTracks(tracks, 'pending').length, 5001)
  assert.equal(filterReviewTracks(tracks, 'done').length, 5000)
})
