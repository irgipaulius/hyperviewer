#!/usr/bin/env node
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const jsDir = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'js')
const bundles = {
	'cockpit.js': ['hv-cockpit', 'Aggregate control plane'],
	'personal.js': ['hv-personal', 'Right-click a video'],
	'files-integration.js': ['hyperviewer-play', 'hyperviewer-copy-stream', 'hv-hls-icon-badge', 'MutationObserver'],
}

let failed = false
for (const [file, needles] of Object.entries(bundles)) {
	const p = path.join(jsDir, file)
	if (!fs.existsSync(p)) {
		console.error(`Missing bundle: ${file}`)
		failed = true
		continue
	}
	const text = fs.readFileSync(p, 'utf8')
	for (const n of needles) {
		if (!text.includes(n)) {
			console.error(`${file}: expected substring "${n}"`)
			failed = true
		}
	}
	if (/\btemplate:\s*`/.test(text)) {
		console.error(`${file}: inline template string found in bundle`)
		failed = true
	}
}
if (failed) {
	process.exit(1)
}
console.log('OK: bundle smoke checks passed')
