import assert from 'node:assert/strict'
import { test } from 'node:test'
import { processGlobFilter } from 'webdav/dist/node/response.js'

// Exercise WebDAV's actual minimatch consumer across the security override.
const files = [
  { filename: '/Musik/Album/track.mp3' },
  { filename: '/Musik/Album/track.flac' },
  { filename: '/Musik/Album/cover.jpg' },
  { filename: '/Musik neu/Grüße/01 Lied.mp3' },
]

test('WebDAV glob filtering retains basename, recursive and brace matching', () => {
  assert.deepEqual(processGlobFilter(files, '*.mp3'), [files[0], files[3]])
  assert.deepEqual(processGlobFilter(files, '/Musik/**/*.flac'), [files[1]])
  assert.deepEqual(processGlobFilter(files, '*.{mp3,flac}'), [files[0], files[1], files[3]])
  assert.deepEqual(processGlobFilter(files, '*.ogg'), [])
})
