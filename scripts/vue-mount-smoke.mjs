#!/usr/bin/env node
import fs from 'fs'
import path from 'path'
import { fileURLToPath, pathToFileURL } from 'url'
import vueCompiler from 'vue-template-compiler'
import { JSDOM } from 'jsdom'

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..')

const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
	url: 'https://localhost',
})
const { window } = dom
global.window = window
global.document = window.document
for (const key of Object.getOwnPropertyNames(window)) {
	if (!(key in global)) {
		global[key] = window[key]
	}
}

async function loadVue() {
	const mod = await import(pathToFileURL(path.join(root, 'node_modules/vue/dist/vue.common.dev.js')).href)
	return mod.default
}

function cockpitStubData() {
	return {
		tab: 'diagnostics',
		tabs: ['diagnostics', 'encoder', 'renditions', 'storage', 'permissions', 'advanced', 'maintenance'],
		configSections: {
			encoder: ['worker_concurrency'],
			renditions: ['default_renditions'],
			storage: ['hls_cache_directory'],
			permissions: ['allowed_groups'],
		},
		dashboard: { worker: { status: 'stopped' }, queue: { queued: 0 }, cache: {} },
		settings: {},
		logs: [],
		loading: false,
		maintenanceMsg: '',
	}
}

function personalStubData() {
	return {
		tab: 'queue',
		tabs: [
			{ id: 'overview', label: 'Overview' },
			{ id: 'queue', label: 'Queue' },
		],
		overview: null,
		prefs: null,
		historyState: '',
		historyType: '',
		historyQuery: '',
		watchPath: '',
		publicLinks: [],
		jobs: [],
		historyJobs: [],
		library: [],
		watchDirs: [],
		userLogs: [],
		loading: false,
	}
}

async function main() {
	const Vue = await loadVue()
	Vue.config.productionTip = false

	const apps = [
		{
			file: path.join(root, 'src/cockpit/App.vue'),
			rootClass: 'hv-cockpit',
			data: cockpitStubData,
			computed: {
				isConfigTab() {
					return false
				},
				configKeysForTab() {
					return []
				},
				workerBannerClass() {
					return 'hv-worker-banner hv-worker-stopped'
				},
				workerTitle() {
					return 'Worker stopped'
				},
			},
			methods: {
				formatBytes: () => '0 B',
				formatDuration: () => '—',
				queueCount: () => 0,
			},
		},
		{
			file: path.join(root, 'src/personal/App.vue'),
			rootClass: 'hv-personal',
			data: personalStubData,
			methods: {
				formatBytes: () => '0 B',
				formatTs: () => '—',
				formatDuration: () => '—',
				filesLink: () => '#',
			},
		},
	]

	for (const { file, rootClass, data, computed = {}, methods = {} } of apps) {
		const src = fs.readFileSync(file, 'utf8')
		if (/\btemplate\s*:/.test(src)) {
			throw new Error(`${file}: inline template object`)
		}
		const parsed = vueCompiler.parseComponent(src)
		if (!parsed.template || !parsed.template.content) {
			throw new Error(`${file}: missing template block`)
		}
		const compiled = vueCompiler.compile(parsed.template.content)
		if (compiled.errors && compiled.errors.length) {
			throw new Error(`${file}: ${compiled.errors.join('; ')}`)
		}
		const Comp = Vue.extend({
			name: path.basename(file, '.vue'),
			template: parsed.template.content,
			data,
			computed,
			methods,
			mounted() {},
		})
		const vm = new Comp().$mount()
		if (!vm.$el || !vm.$el.classList.contains(rootClass)) {
			throw new Error(`${file}: expected root .${rootClass}`)
		}
		vm.$destroy()
	}
	console.log('OK: vue mount smoke passed')
}

main().catch((e) => {
	console.error(e.message || e)
	process.exit(1)
})
