#!/usr/bin/env node
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const jsDir = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'js')
const entries = ['files-integration.js', 'cockpit.js', 'personal.js']

let failed = false
function fail(msg) {
	console.error(msg)
	failed = true
}

for (const file of entries) {
	const p = path.join(jsDir, file)
	if (!fs.existsSync(p)) {
		fail(`Missing bundle: ${file}`)
		continue
	}
	const text = fs.readFileSync(p, 'utf8')
	if (/\bn\.p\s*=\s*"\/js\/"/.test(text)) {
		fail(`${file}: webpack publicPath is "/js/" — lazy chunks will 404 on Nextcloud`)
	}
	if (!text.includes('/apps/hyperviewer')) {
		fail(`${file}: expected chunk base URL under /apps/hyperviewer/`)
	}
}

const chunkFiles = fs.readdirSync(jsDir).filter((name) => /^hyperviewer-\d+\.js$/.test(name))
if (chunkFiles.length === 0) {
	fail('Expected at least one hyperviewer-*.js lazy chunk beside files-integration.js')
}

if (failed) {
	process.exit(1)
}
console.log('OK: webpack publicPath regression checks passed')
