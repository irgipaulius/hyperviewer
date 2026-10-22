#!/usr/bin/env node
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'src')
const bad = []

function walk(dir) {
	for (const name of fs.readdirSync(dir)) {
		const p = path.join(dir, name)
		const st = fs.statSync(p)
		if (st.isDirectory()) {
			walk(p)
			continue
		}
		if (!/\.(js|vue)$/.test(name)) {
			continue
		}
		const text = fs.readFileSync(p, 'utf8')
		if (/^\s*template\s*:\s*[`'"]/m.test(text) || /\btemplate\s*:\s*`/m.test(text)) {
			bad.push(path.relative(root, p))
		}
	}
}

walk(root)
if (bad.length) {
	console.error('Inline Vue template: strings are forbidden (use .vue SFCs):\n', bad.join('\n'))
	process.exit(1)
}
console.log('OK: no inline Vue templates in src/')
