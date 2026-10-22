<template>
	<div class="hv-cockpit section">
		<h2>Hyper Viewer — Administration</h2>
		<p class="hv-privacy-note">
			Aggregate control plane only. File names, paths and user identities are never shown here.
		</p>

		<div :class="workerBannerClass">
			<strong>{{ workerTitle }}</strong>
			<p v-if="dashboard.worker && dashboard.worker.status === 'running'" class="hv-worker-meta">
				Uptime {{ formatDuration(dashboard.worker.uptime_seconds) }}
				· concurrency {{ dashboard.worker.concurrency_reported }}
				· ffmpeg threads {{ dashboard.worker.ffmpeg_threads }}
				<span v-if="dashboard.queue && dashboard.queue.paused"> · queue paused</span>
			</p>
			<p v-else class="hv-worker-meta">
				Start: <code>{{ dashboard.worker && dashboard.worker.start_hint_service }}</code> or
				<code>{{ dashboard.worker && dashboard.worker.start_hint_command }}</code>
			</p>
		</div>

		<div class="hv-summary-cards">
			<div class="hv-card">
				<span class="hv-card-n">{{ queueCount('queued') }}</span> queued
			</div>
			<div class="hv-card">
				<span class="hv-card-n">{{ queueCount('running') }}</span> running
			</div>
			<div class="hv-card">
				<span class="hv-card-n">{{ queueCount('failed') }}</span> failed
			</div>
			<div class="hv-card">
				<span class="hv-card-n">{{ queueCount('done_24h') }}</span> done (24h)
			</div>
			<div class="hv-card">
				<span class="hv-card-n">{{ queueCount('cancelled') }}</span> cancelled
			</div>
		</div>

		<div v-if="dashboard.cache" class="hv-cache-strip">
			<strong>Storage (aggregate)</strong>
			{{ dashboard.cache.total_indexed }} indexed ·
			{{ dashboard.cache.ready }} ready ·
			{{ formatBytes(dashboard.cache.cache_bytes_disk_estimate || dashboard.cache.cache_bytes_db) }}
			<span v-if="dashboard.cache.cache_directory"> · {{ dashboard.cache.cache_directory }}</span>
		</div>

		<div v-if="dashboard.queue && dashboard.queue.by_type" class="hv-type-breakdown">
			<strong>Queued by type:</strong>
			<span v-for="(count, type) in dashboard.queue.by_type" :key="type" class="hv-type-pill">
				{{ type }}: {{ count }}
			</span>
		</div>

		<nav class="hv-tabs">
			<button
				v-for="t in tabs"
				:key="t"
				type="button"
				:class="{ active: tab === t }"
				@click="switchTab(t)">
				{{ t }}
			</button>
		</nav>

		<div v-if="loading" class="emptycontent">
			Loading…
		</div>

		<div v-else-if="tab === 'config'" class="hv-panel">
			<div v-for="(meta, key) in settings" :key="key" class="hv-setting-row">
				<label>{{ key }} <small>({{ meta.source }})</small></label>
				<input :value="meta.value" @change="saveSetting(key, $event.target.value)">
				<button type="button" @click="resetSetting(key)">
					Reset
				</button>
			</div>
		</div>

		<div v-else-if="tab === 'maintenance'" class="hv-panel">
			<p>Queue controls affect all users. Individual jobs are managed in each user's Hyper Viewer settings.</p>
			<button type="button" @click="togglePause">
				{{ dashboard.queue && dashboard.queue.paused ? 'Resume queue' : 'Pause queue' }}
			</button>
		</div>

		<div v-else class="hv-panel hv-logs">
			<p class="hv-privacy-note">
				System diagnostics only (paths redacted).
			</p>
			<pre v-for="l in logs" :key="l.id">[{{ l.level }}] {{ l.message }}</pre>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { apiGet, apiPost } from '../shared/api.js'
import { formatBytes, formatDuration } from '../shared/format.js'

export default {
	name: 'App',
	data() {
		return {
			tab: 'diagnostics',
			tabs: ['diagnostics', 'config', 'maintenance'],
			dashboard: { worker: {}, queue: {}, cache: {} },
			settings: {},
			logs: [],
			loading: true,
		}
	},
	computed: {
		workerBannerClass() {
			const s = (this.dashboard.worker && this.dashboard.worker.status) || 'stopped'
			return `hv-worker-banner hv-worker-${s}`
		},
		workerTitle() {
			const w = this.dashboard.worker || {}
			const q = (this.dashboard.queue && this.dashboard.queue.queued) ?? 0
			if (w.status === 'running') {
				return `Worker running (PID ${w.pid ?? '?'})`
			}
			if (w.status === 'stale') {
				return `Worker stale — last heartbeat ${w.heartbeat_age_seconds ?? '?'}s ago`
			}
			if (q > 0) {
				return `${q} job${q === 1 ? '' : 's'} waiting — worker is not running`
			}
			return 'Worker stopped'
		},
	},
	async mounted() {
		await this.refresh()
		this._timer = setInterval(() => this.refresh(), 5000)
	},
	beforeDestroy() {
		clearInterval(this._timer)
	},
	methods: {
		formatBytes,
		formatDuration,
		queueCount(key) {
			return (this.dashboard.queue && this.dashboard.queue[key]) ?? 0
		},
		async refresh() {
			try {
				this.dashboard = await apiGet(generateUrl('/apps/hyperviewer/api/admin/status'))
				if (this.tab === 'config' && Object.keys(this.settings).length === 0) {
					const st = await apiGet(generateUrl('/apps/hyperviewer/api/admin/settings'))
					this.settings = st.settings || {}
				}
				if (this.tab === 'diagnostics') {
					const lg = await apiGet(generateUrl('/apps/hyperviewer/api/admin/logs'))
					this.logs = lg.entries || []
				}
			} catch (e) {
				console.error(e)
				showError(e.message || 'Failed to load admin data')
			}
			this.loading = false
		},
		async switchTab(t) {
			this.tab = t
			this.loading = true
			await this.refresh()
		},
		async saveSetting(key, value) {
			try {
				await apiPost(generateUrl('/apps/hyperviewer/api/admin/settings'), { key, value })
				await this.refresh()
			} catch (e) {
				showError(e.message || 'Save failed')
			}
		},
		async resetSetting(key) {
			try {
				await apiPost(generateUrl('/apps/hyperviewer/api/admin/settings/reset'), { key })
				await this.refresh()
			} catch (e) {
				showError(e.message || 'Reset failed')
			}
		},
		async togglePause() {
			const paused = !(this.dashboard.queue && this.dashboard.queue.paused)
			try {
				await apiPost(generateUrl('/apps/hyperviewer/api/admin/queue/pause'), { paused })
				await this.refresh()
			} catch (e) {
				showError(e.message || 'Could not update queue')
			}
		},
	},
}
</script>
