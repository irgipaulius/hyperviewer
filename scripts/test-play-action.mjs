#!/usr/bin/env node
import path from 'path'
import { fileURLToPath, pathToFileURL } from 'url'

const playActionUrl = pathToFileURL(
	path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'src/files/play-action.js'),
).href

const { executeDefaultPlay } = await import(playActionUrl)

const node = { basename: 'clip.mov', fileid: 42, mime: 'video/quicktime' }
const folder = { path: '/Videos' }

let failed = false
function fail(msg) {
	console.error(msg)
	failed = true
}

/** @type {string[]} */
const log = []
const result = await executeDefaultPlay(node, folder, [], {
	playVideoSmart: async () => {
		log.push('shaka')
	},
	openWithViewer: () => {
		log.push('viewer')
	},
})
if (result !== true) {
	fail('executeDefaultPlay must return true')
}
if (log.join(',') !== 'shaka') {
	fail(`With cache path expected shaka only, got: ${log.join(',')}`)
}

log.length = 0
await executeDefaultPlay(node, folder, [], {
	playVideoSmart: async () => {
		throw new Error('encode missing')
	},
	openWithViewer: () => {
		log.push('viewer')
	},
})
if (log.join(',') !== 'viewer') {
	fail(`On play error expected viewer fallback, got: ${log.join(',')}`)
}

if (failed) {
	process.exit(1)
}
console.log('OK: play-action regression checks passed')
