#!/usr/bin/env node
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..')
const registerSrc = fs.readFileSync(path.join(root, 'src/files/register-file-actions.js'), 'utf8')
const chipSrc = fs.readFileSync(path.join(root, 'src/files/hls-chip.js'), 'utf8')

let failed = false
function fail(msg) {
	console.error(msg)
	failed = true
}

if (registerSrc.includes('hyperviewer-hls-icon-chip')) {
	fail('register-file-actions.js must not register hyperviewer-hls-icon-chip')
}
if (registerSrc.includes('DefaultType.HIDDEN')) {
	fail('register-file-actions.js must not use DefaultType.HIDDEN (steals row click)')
}
if (!/id:\s*['"]hyperviewer-play['"][\s\S]{0,400}default:\s*DefaultType\.DEFAULT/.test(registerSrc)) {
	fail('hyperviewer-play must be the only action with default: DefaultType.DEFAULT')
}

const actionBlocks = registerSrc.split('registerFileAction(').slice(1)
const withDefault = []
for (const block of actionBlocks) {
	const id = block.match(/id:\s*['"]([^'"]+)['"]/)?.[1]
	if (id && /default:\s*DefaultType/.test(block)) {
		withDefault.push(id)
	}
}
if (withDefault.length !== 1 || withDefault[0] !== 'hyperviewer-play') {
	fail(`Expected only hyperviewer-play to have default, got: ${withDefault.join(', ')}`)
}

if (/registerFileAction\s*\(/.test(chipSrc)) {
	fail('hls-chip.js must not register a file action (chip is DOM overlay only)')
}

if (failed) {
	process.exit(1)
}
console.log('OK: file action default regression checks passed')
