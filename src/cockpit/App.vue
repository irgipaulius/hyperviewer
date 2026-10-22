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
			<p v-if="dashboard.worker && dashboard.worker.ffmpeg" class="hv-worker-meta">
				<span v-if="dashboard.worker.ffmpeg.ffmpeg">{{ dashboard.worker.ffmpeg.ffmpeg }}</span>
				<span v-if="dashboard.worker.disk_free_bytes != null">
					· disk free {{ formatBytes(dashboard.worker.disk_free_bytes) }}
				</span>
				<span v-if="dashboard.worker.hardware_encoders && dashboard.worker.hardware_encoders.length">
					· HW: {{ dashboard.worker.hardware_encoders.join(', ') }}
				</span>
			</p>
			<p v-if="dashboard.queue && dashboard.queue.last_failed_category" class="hv-worker-meta hv-error-cat">
				Last failure category: {{ dashboard.queue.last_failed_category }}
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
			<span v-if="dashboard.queue.users_with_queued != null" class="hv-type-pill">
				users waiting: {{ dashboard.queue.users_with_queued }}
			</span>
			<span v-if="dashboard.queue.max_queued_per_user != null" class="hv-type-pill">
				max depth/user: {{ dashboard.queue.max_queued_per_user }}
			</span>
			<span v-if="dashboard.queue.avg_encode_seconds_24h != null" class="hv-type-pill">
				avg encode (24h): {{ formatDuration(dashboard.queue.avg_encode_seconds_24h) }}
			</span>
			<span
				v-for="row in (dashboard.queue && dashboard.queue.queued_depth_histogram) || []"
				:key="'d' + row.depth"
				class="hv-type-pill">
				{{ row.users }} users @ {{ row.depth }} queued
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

		<div v-else-if="isConfigTab" class="hv-panel">
			<div v-for="key in configKeysForTab" :key="key" class="hv-setting-row">
				<label>{{ key }} <small>({{ (settings[key] && settings[key].source) || 'default' }})</small></label>
				<input :value="settings[key] && settings[key].value" @change="saveSetting(key, $event.target.value)">
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
			<button type="button" @click="cancelFailed">
				Cancel all failed jobs
			</button>
			<button type="button" @click="purgeLogs">
				Purge old system logs
			</button>
			<button type="button" @click="purgeHistory">
				Purge old job history
			</button>
			<button type="button" @click="copyDiagnostic">
				Copy diagnostic report
			</button>
			<button type="button" class="hv-danger" @click="drainQueue">
				Drain queue (cancel all queued)
			</button>
			<button type="button" @click="verifyCache">
				Verify cache sample
			</button>
			<button type="button" @click="rebuildIndex">
				Rebuild index (scan + cache adopt)
			</button>
			<p v-if="maintenanceMsg" class="hv-path-muted">{{ maintenanceMsg }}</p>
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
import { showError, showSuccess } from '@nextcloud/dialogs'
import { apiGet, apiPost } from '../shared/api.js'
import { formatBytes, formatDuration } from '../shared/format.js'

export default {
	name: 'App',
	data() {
		return {
			tab: 'diagnostics',
			tabs: ['diagnostics', 'encoder', 'renditions', 'storage', 'permissions', 'advanced', 'maintenance'],
			configSections: {
				encoder: ['worker_concurrency', 'ffmpeg_threads', 'cpu_priority_mode', 'max_jobs_per_user', 'quiet_hours_start', 'quiet_hours_end', 'encode_timeout_duration_mult', 'encode_stall_seconds'],
				renditions: ['default_renditions', 'user_allowed_renditions', 'hls_segment_seconds'],
				storage: ['hls_cache_directory', 'cache_lru_limit_gb', 'gc_grace_days', 'default_user_cache_quota_gb'],
				permissions: ['allowed_groups', 'group_generate_hls', 'group_export_clip', 'group_public_links'],
			},
			dashboard: { worker: {}, queue: {}, cache: {} },
			settings: {},
			logs: [],
			loading: true,
			maintenanceMsg: '',
		}
	},
	computed: {
		isConfigTab() {
			return ['encoder', 'renditions', 'storage', 'permissions', 'advanced'].includes(this.tab)
		},
		configKeysForTab() {
			if (this.tab === 'advanced') {
				return Object.keys(this.settings).filter(k => {
					const all = Object.values(this.configSections).flat()
					return !all.includes(k)
				})
			}
			return this.configSections[this.tab] || []
		},
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
				if (this.isConfigTab && Object.keys(this.settings).length === 0) {
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
		async cancelFailed() {
			await apiPost(generateUrl('/apps/hyperviewer/api/admin/queue/cancel-failed'))
			showSuccess('Failed jobs cleared from queue')
			await this.refresh()
		},
		async purgeLogs() {
			if (!window.confirm('Purge old system logs?')) {
				return
			}
			await apiPost(generateUrl('/apps/hyperviewer/api/admin/logs/purge'))
			showSuccess('Old logs purged')
			await this.refresh()
		},
		async purgeHistory() {
			if (!window.confirm('Delete finished/cancelled jobs older than retention?')) {
				return
			}
			const res = await apiPost(generateUrl('/apps/hyperviewer/api/admin/history/purge'))
			this.maintenanceMsg = `Purged ${res.purged || 0} history rows`
		},
		async copyDiagnostic() {
			const report = await apiGet(generateUrl('/apps/hyperviewer/api/admin/diagnostic-report'))
			const text = JSON.stringify(report, null, 2)
			try {
				await navigator.clipboard.writeText(text)
				showSuccess('Diagnostic report copied (no user paths included)')
			} catch (e) {
				showError('Could not copy to clipboard')
			}
		},
		async drainQueue() {
			if (!window.confirm('Cancel ALL queued jobs for every user?')) {
				return
			}
			const res = await apiPost(generateUrl('/apps/hyperviewer/api/admin/queue/drain'))
			this.maintenanceMsg = `Cancelled ${res.cancelled || 0} queued jobs`
			await this.refresh()
		},
		async verifyCache() {
			const res = await apiPost(generateUrl('/apps/hyperviewer/api/admin/cache/verify'))
			this.maintenanceMsg = `Checked ${res.checked}, missing on disk: ${res.missing_on_disk}`
		},
		async rebuildIndex() {
			const res = await apiPost(generateUrl('/apps/hyperviewer/api/admin/index/rebuild'))
			this.maintenanceMsg = `Enqueued ${res.scan_jobs || 0} scan and ${res.adopt_jobs || 0} cache-adopt jobs`
		},
	},
}
</script>
