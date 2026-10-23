#!/usr/bin/env node
import { parseDiscoverResponse, buildDirectoryVideoCountHtml } from '../src/files/directory-discover.js'

let failed = false
const fail = (m) => { console.error(m); failed = true }

// Real 2.1.8 server shape (subset of 42 videos under external mount path)
const serverReply = {
	success: true,
	count: 42,
	files: [
		{
			fileid: 504200,
			filename: 'clip.mp4',
			path: '/Paulius & Mate/Pictures/a/b/clip.mp4',
		},
	],
}

const parsed = parseDiscoverResponse(serverReply)
if (!parsed.success || parsed.count !== 42 || parsed.files.length !== 1) {
	fail(`parseDiscoverResponse: expected success count=42, got ${JSON.stringify(parsed)}`)
}

const legacy = parseDiscoverResponse({ videos: [], count: 99 })
if (legacy.success || legacy.count !== 0) {
	fail('legacy {videos,count} must not count as success')
}

const html = buildDirectoryVideoCountHtml(parsed.count, '/Paulius & Mate/Pictures', ['clip.mp4'])
if (!html.includes('Video files found:</strong> 42')) {
	fail('dialog HTML must show count 42')
}
if (!html.includes('/Paulius & Mate/Pictures')) {
	fail('dialog HTML must show directory path')
}

if (failed) process.exit(1)
console.log('OK: discover dialog count from server reply')
