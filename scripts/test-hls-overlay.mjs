#!/usr/bin/env node
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..')
const chipSrc = fs.readFileSync(path.join(root, 'src/files/hls-chip.js'), 'utf8')
const routerSrc = fs.readFileSync(path.join(root, 'src/files/files-router.js'), 'utf8')

let failed = false
function fail(msg) {
	console.error(msg)
	failed = true
}

if (/registerFileAction\s*\(/.test(chipSrc)) {
	fail('hls-chip.js must not use registerFileAction')
}
if (!chipSrc.includes('MutationObserver')) {
	fail('hls-chip.js must use MutationObserver for virtualized rows')
}
if (!chipSrc.includes('data-cy-files-list-row-fileid')) {
	fail('hls-chip.js must target Files v4 row fileid attribute')
}
if (!chipSrc.includes('.files-list__row-icon')) {
	fail('hls-chip.js must overlay on .files-list__row-icon')
}
if (!chipSrc.includes('pointer-events') && !chipSrc.includes('pointerEvents')) {
	fail('hls-chip.js must set pointer-events none on badge (failed state excepted)')
}
if (chipSrc.includes('setInterval') && /scanVisibleRows|processRow/.test(chipSrc)) {
	fail('hls-chip.js must not poll rows on an interval')
}
if (!routerSrc.includes('Router') || !routerSrc.includes('query')) {
	fail('files-router.js must read OCP.Files.Router query dir')
}
if (routerSrc.includes('location.pathname')) {
	fail('files-router.js must not parse location.pathname for directory')
}

const css = fs.readFileSync(path.join(root, 'css/files-integration.css'), 'utf8')
if (!css.includes('.hv-hls-icon-badge')) {
	fail('files-integration.css must style .hv-hls-icon-badge')
}
if (!css.includes('.files-list--grid')) {
	fail('files-integration.css must include grid badge sizing')
}

if (failed) {
	process.exit(1)
}
console.log('OK: HLS overlay regression checks passed')
