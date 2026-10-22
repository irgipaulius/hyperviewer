import Vue from 'vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'

async function apiGet(url, params = {}) {
	const response = await axios.get(url, { params })
	if (typeof response.data === 'string') {
		throw new Error('Unexpected HTML response — are you logged in as admin?')
	}
	return response.data
}

async function apiPost(url, data = {}) {
	const response = await axios.post(url, data)
	if (typeof response.data === 'string') {
		throw new Error('Unexpected HTML response')
	}
	return response.data
}

function formatBytes(bytes) {
	if (bytes == null || Number.isNaN(bytes)) {
		return '—'
	}
	const n = Number(bytes)
	if (n < 1024) {
		return `${n} B`
	}
	if (n < 1024 ** 2) {
		return `${(n / 1024).toFixed(1)} KiB`
	}
	if (n < 1024 ** 3) {
		return `${(n / 1024 ** 2).toFixed(1)} MiB`
	}
	return `${(n / 1024 ** 3).toFixed(2)} GiB`
}

function formatTs(ts) {
	if (!ts) {
		return '—'
	}
	return new Date(ts * 1000).toLocaleString()
}

function formatDuration(sec) {
	if (sec == null) {
		return '—'
	}
	if (sec < 60) {
		return `${sec}s`
	}
	const m = Math.floor(sec / 60)
	const s = sec % 60
	return `${m}m ${s}s`
}

function filesLink(job) {
	if (!job.files_dir && !job.directory) {
		return generateUrl('/apps/files/')
	}
	const dir = encodeURIComponent(job.files_dir || job.directory || '/')
	return generateUrl(`/apps/files/?dir=${dir}`)
}

const App = {
	name: 'HyperViewerCockpit',
	data() {
		return {
			tab: 'queue',
			dashboard: { worker: {}, queue: {}, cache: {} },
			jobs: [],
			settings: {},
			logs: [],
			loading: true,
			jobFilter: { state: '', type: '', user: '' },
			jobOffset: 0,
			jobLimit: 50,
			expandedErrors: {},
		}
	},
	async mounted() {
		await this.refresh()
		this._timer = setInterval(() => this.refresh(), 5000)
	},
	beforeDestroy() {
		clearInterval(this._timer)
	},
	methods: {
		async refresh() {
			try {
				const [dashboard, jobs] = await Promise.all([
					apiGet(generateUrl('/apps/hyperviewer/api/admin/status')),
					apiGet(generateUrl('/apps/hyperviewer/api/jobs'), {
						limit: this.jobLimit,
						offset: this.jobOffset,
						scope: 'all',
						state: this.jobFilter.state || undefined,
						type: this.jobFilter.type || undefined,
						user: this.jobFilter.user || undefined,
					}),
				])
				this.dashboard = dashboard
				this.jobs = jobs.jobs || []
				if (this.tab === 'settings' && Object.keys(this.settings).length === 0) {
					const st = await apiGet(generateUrl('/apps/hyperviewer/api/admin/settings'))
					this.settings = st.settings || {}
				}
				if (this.tab === 'logs' && this.logs.length === 0) {
					const lg = await apiGet(generateUrl('/apps/hyperviewer/api/admin/logs'))
					this.logs = (lg.entries || []).reverse()
				}
			} catch (e) {
				console.error(e)
				showError(e.message || 'Failed to load Hyper Viewer admin data')
			}
			this.loading = false
		},
		workerBannerClass() {
			const s = this.dashboard.worker?.status || 'stopped'
			return `hv-worker-banner hv-worker-${s}`
		},
		workerTitle() {
			const w = this.dashboard.worker || {}
			const q = this.dashboard.queue?.queued ?? 0
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
		async retryJob(id) {
			try {
				await apiPost(generateUrl(`/apps/hyperviewer/api/jobs/${id}/retry`))
				await this.refresh()
			} catch (e) {
				showError(e.message || 'Retry failed')
			}
		},
		async cancelJob(id) {
			try {
				await apiPost(generateUrl(`/apps/hyperviewer/api/jobs/${id}/cancel`))
				await this.refresh()
			} catch (e) {
				showError(e.message || 'Cancel failed')
			}
		},
		async bumpJob(id) {
			try {
				await apiPost(generateUrl(`/apps/hyperviewer/api/jobs/${id}/bump`))
				await this.refresh()
			} catch (e) {
				showError(e.message || 'Bump failed')
			}
		},
		toggleError(id) {
			this.$set(this.expandedErrors, id, !this.expandedErrors[id])
		},
		async applyJobFilters() {
			this.jobOffset = 0
			await this.refresh()
		},
		async nextPage() {
			this.jobOffset += this.jobLimit
			await this.refresh()
		},
		async prevPage() {
			this.jobOffset = Math.max(0, this.jobOffset - this.jobLimit)
			await this.refresh()
		},
		formatBytes,
		formatTs,
		formatDuration,
		filesLink,
	},
	template: `
	<div class="hv-cockpit section">
		<h2>Hyper Viewer</h2>

		<div :class="workerBannerClass()">
			<strong>{{ workerTitle() }}</strong>
			<p v-if="dashboard.worker?.status === 'running'" class="hv-worker-meta">
				Uptime {{ formatDuration(dashboard.worker.uptime_seconds) }}
				· concurrency {{ dashboard.worker.concurrency_reported }}
				· ffmpeg threads {{ dashboard.worker.ffmpeg_threads }}
				<span v-if="dashboard.queue?.paused"> · queue paused</span>
			</p>
			<p v-else class="hv-worker-meta">
				Start: <code>{{ dashboard.worker?.start_hint_service }}</code> or
				<code>{{ dashboard.worker?.start_hint_command }}</code>
			</p>
		</div>

		<div class="hv-summary-cards">
			<div class="hv-card"><span class="hv-card-n">{{ dashboard.queue?.queued ?? 0 }}</span> queued</div>
			<div class="hv-card"><span class="hv-card-n">{{ dashboard.queue?.running ?? 0 }}</span> running</div>
			<div class="hv-card"><span class="hv-card-n">{{ dashboard.queue?.failed ?? 0 }}</span> failed</div>
			<div class="hv-card"><span class="hv-card-n">{{ dashboard.queue?.done_24h ?? 0 }}</span> done (24h)</div>
			<div class="hv-card"><span class="hv-card-n">{{ dashboard.queue?.cancelled ?? 0 }}</span> cancelled</div>
		</div>

		<div class="hv-cache-strip" v-if="dashboard.cache">
			<strong>Cache</strong>
			{{ dashboard.cache.total_indexed }} indexed ·
			{{ dashboard.cache.ready }} ready ·
			{{ formatBytes(dashboard.cache.cache_bytes_disk_estimate ?? dashboard.cache.cache_bytes_db) }} on disk
			<span v-if="dashboard.cache.cache_directory"> ({{ dashboard.cache.cache_directory }})</span>
			· {{ dashboard.cache.failed_jobs ?? 0 }} failed jobs
		</div>

		<nav class="hv-tabs">
			<button v-for="t in ['queue','settings','logs']" :key="t" :class="{ active: tab === t }" @click="tab = t; refresh()">{{ t }}</button>
		</nav>

		<div v-if="loading && jobs.length === 0" class="emptycontent">Loading…</div>

		<div v-else-if="tab === 'queue'" class="hv-panel">
			<div class="hv-filters">
				<label>State <select v-model="jobFilter.state" @change="applyJobFilters">
					<option value="">All</option>
					<option value="queued">queued</option>
					<option value="running">running</option>
					<option value="failed">failed</option>
					<option value="done">done</option>
					<option value="cancelled">cancelled</option>
				</select></label>
				<label>Type <select v-model="jobFilter.type" @change="applyJobFilters">
					<option value="">All</option>
					<option value="fingerprint">fingerprint</option>
					<option value="hls">hls</option>
					<option value="clip">clip</option>
				</select></label>
				<label>User <input v-model="jobFilter.user" placeholder="uid" @change="applyJobFilters" /></label>
			</div>
			<table class="grid hv-jobs-table">
				<thead>
					<tr>
						<th>ID</th><th>Type</th><th>File</th><th>User</th><th>State</th><th>Progress</th>
						<th>Attempts</th><th>Times</th><th>Chain</th><th></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="j in jobs" :key="j.id">
						<td>#{{ j.id }}</td>
						<td>{{ j.type_label || j.type }}</td>
						<td class="hv-file-cell">
							<a v-if="j.filename" :href="filesLink(j)" target="_blank" rel="noopener">{{ j.filename }}</a>
							<span v-else>—</span>
							<div class="hv-path-muted">{{ j.directory }}</div>
						</td>
						<td>{{ j.user_id }}</td>
						<td><span :class="'hv-state hv-state-' + j.state">{{ j.state }}</span></td>
						<td>{{ j.progress }}%</td>
						<td>{{ j.attempts }}/{{ j.max_attempts }}</td>
						<td class="hv-times">
							<div>Q {{ formatTs(j.created_at) }}</div>
							<div v-if="j.started_at">S {{ formatTs(j.started_at) }}</div>
							<div v-if="j.finished_at">F {{ formatTs(j.finished_at) }}</div>
							<div v-if="j.duration_seconds != null">{{ formatDuration(j.duration_seconds) }}</div>
						</td>
						<td class="hv-chain">{{ j.chain?.label || '—' }}</td>
						<td class="hv-actions">
							<button v-if="j.state === 'failed'" type="button" @click="retryJob(j.id)">Retry</button>
							<button v-if="j.state === 'queued' || j.state === 'running'" type="button" @click="cancelJob(j.id)">Cancel</button>
							<button v-if="j.state === 'queued'" type="button" @click="bumpJob(j.id)">Bump</button>
						</td>
					</tr>
				</tbody>
			</table>
			<div v-for="j in jobs.filter(x => x.error)" :key="'err-' + j.id" class="hv-job-error">
				<button type="button" class="hv-error-toggle" @click="toggleError(j.id)">#{{ j.id }} error</button>
				<pre v-if="expandedErrors[j.id]">{{ j.error }}</pre>
			</div>
			<div class="hv-pagination">
				<button type="button" :disabled="jobOffset === 0" @click="prevPage">Previous</button>
				<span>offset {{ jobOffset }}</span>
				<button type="button" :disabled="jobs.length < jobLimit" @click="nextPage">Next</button>
			</div>
		</div>

		<div v-else-if="tab === 'settings'" class="hv-panel">
			<div v-for="(meta, key) in settings" :key="key" class="hv-setting-row">
				<label>{{ key }} <small>({{ meta.source }})</small></label>
				<input :value="meta.value" @change="saveSetting(key, $event.target.value)" />
				<button type="button" @click="resetSetting(key)">Reset</button>
			</div>
		</div>

		<div v-else class="hv-panel hv-logs">
			<pre v-for="l in logs" :key="l.id">[{{ l.level }}] {{ l.message }}</pre>
		</div>
	</div>`,
}

document.addEventListener('DOMContentLoaded', () => {
	const mount = document.getElementById('hyperviewer-cockpit') || document.getElementById('vue-content')
	if (mount) {
		new Vue({ render: h => h(App) }).$mount(mount)
	}
})
