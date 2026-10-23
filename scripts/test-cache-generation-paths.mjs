#!/usr/bin/env node
import { buildCacheGenerationFilesPayload } from '../src/files/directory-discover.js'

let failed = false
const fail = (m) => { console.error(m); failed = true }

const watchRoot = '/Paulius & Mate/Pictures'
const files = [
	{ fileid: 10, filename: 'a.mp4', path: '/Paulius & Mate/Pictures/sub/a.mp4' },
	{ fileid: 11, filename: 'b.mp4', path: '/Paulius & Mate/Pictures/deep/nested/b.mp4' },
]

const payload = buildCacheGenerationFilesPayload(files, watchRoot)
if (payload.length !== 2) fail('expected 2 files in payload')
if (payload[0].directory !== '/Paulius & Mate/Pictures/sub') {
	fail(`file a directory must be nested sub, got ${payload[0].directory}`)
}
if (payload[1].directory !== '/Paulius & Mate/Pictures/deep/nested') {
	fail(`file b directory must be deep/nested, got ${payload[1].directory}`)
}
if (payload[0].directory === watchRoot || payload[1].directory === watchRoot) {
	fail('must not use watch root for every file')
}

if (failed) process.exit(1)
console.log('OK: start-now uses per-file directories')
