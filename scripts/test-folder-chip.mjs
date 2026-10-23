#!/usr/bin/env node
// Watched-folder marker chip on NC 35 list + grid rows (fileid only, no mime).
import { JSDOM } from 'jsdom'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { applyWatchFolderChipToRow } from '../src/files/folder-watch-chip-dom.js'

const here = dirname(fileURLToPath(import.meta.url))
const fixture = readFileSync(join(here, 'fixtures/nc35-files-row.txt'), 'utf8')

let failed = false
const fail = (m) => { console.error(m); failed = true }

const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { pretendToBeVisual: true })
globalThis.window = dom.window
globalThis.document = dom.window.document
globalThis.requestAnimationFrame = (cb) => { cb(); return 0 }
globalThis.getComputedStyle = () => ({ position: 'static' })
globalThis.OC = { generateUrl: (p) => p, requestToken: 't' }

// List row excerpt from fixture (Cedevita NC 35.0.2)
const listRowHtml = `<table><tbody><tr data-cy-files-list-row="" data-cy-files-list-row-fileid="504169" data-cy-files-list-row-name="Pictures" class="files-list__row">
<td class="files-list__row-name"><span class="files-list__row-icon"><span class="files-list__row-icon-preview-container"></span></span></td></tr></tbody></table>`

// Grid row: same data-cy attrs on tr inside .files-list--grid
const gridRowHtml = `<div class="files-list files-list--grid"><table><tbody>
<tr data-cy-files-list-row="" data-cy-files-list-row-fileid="504169" data-cy-files-list-row-name="Pictures" class="files-list__row">
<td><span class="files-list__row-icon-preview-container"></span></td></tr>
</tbody></table></div>`

function mount(html) {
	document.body.innerHTML = html
	return document.querySelector('[data-cy-files-list-row-fileid]')
}

const watched = new Set([504169])

for (const [label, html] of [['list', listRowHtml], ['grid', gridRowHtml]]) {
	const row = mount(html)
	if (!row) {
		fail(`${label}: missing row`)
		continue
	}
	if (!applyWatchFolderChipToRow(row, watched)) {
		fail(`${label}: applyWatchFolderChipToRow returned false`)
	}
	const badge = row.querySelector('.hv-hls-icon-badge.hv-watch-folder')
	if (!badge) {
		fail(`${label}: expected orange watch-folder HLS badge`)
		continue
	}
	if (badge.textContent !== 'HLS') {
		fail(`${label}: badge text must be HLS marker only`)
	}
	if (badge.dataset.fileid !== '504169') {
		fail(`${label}: badge must match row fileid`)
	}
}

if (!fixture.includes('data-cy-files-list-row-fileid')) {
	fail('fixture must contain real NC 35 fileid attribute')
}

if (failed) process.exit(1)
console.log('OK: watch-folder chip on NC 35 list and grid rows')
