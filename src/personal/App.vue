<template>
	<div class="hv-personal section">
		<h2>Hyper Viewer</h2>

		<nav class="hv-tabs">
			<button
				v-for="t in tabs"
				:key="t.id"
				type="button"
				:class="{ active: tab === t.id }"
				@click="tab = t.id">
				{{ t.label }}
			</button>
		</nav>

		<div v-if="loading" class="emptycontent">
			Loading…
		</div>

		<div v-else-if="tab === 'overview'" class="hv-panel">
			<div v-if="overview" class="hv-summary-cards">
				<div class="hv-card">
					<span class="hv-card-n">{{ overview.ready_count || 0 }}</span> videos ready
				</div>
				<div class="hv-card">
					<span class="hv-card-n">{{ overview.video_minutes_ready || 0 }}</span> min ready
				</div>
				<div class="hv-card">
					<span class="hv-card-n">{{ formatBytes(overview.cache_bytes) }}</span> cache used
				</div>
				<div class="hv-card">
					<span class="hv-card-n">{{ overview.queued || 0 }}</span> in queue
				</div>
			</div>
			<p v-if="overview && overview.ready_count === 0" class="hv-empty-hint">
				Right-click a video in Files → <strong>Generate HLS Cache</strong>.
			</p>
		</div>

		<div v-else-if="tab === 'queue'" class="hv-panel">
			<div class="hv-queue-toolbar">
				<button type="button" @click="cancelAllQueued">
					Pause all mine (cancel queued)
				</button>
			</div>
			<div v-if="jobs.length === 0" class="hv-empty-hint">
				No active jobs.
			</div>
			<table v-else class="grid hv-jobs-table">
				<thead>
					<tr>
						<th>File</th><th>Chain</th><th>State</th><th>Progress</th><th>Queue</th><th></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="j in jobs" :key="j.id">
						<td class="hv-file-cell">
							<a
								v-if="j.filename"
								:href="filesLink(j)"
								target="_blank"
								rel="noopener">{{ j.filename }}</a>
							<span v-else>file #{{ j.fileid || '?' }}</span>
							<div class="hv-path-muted">
								{{ j.directory }}
							</div>
						</td>
						<td>{{ j.chain && j.chain.label ? j.chain.label : j.type_label }}</td>
						<td><span :class="'hv-state hv-state-' + j.state">{{ j.state }}</span></td>
						<td>
							<div class="hv-progress-bar">
								<div class="hv-progress-fill" :style="{ width: j.progress + '%' }" />
							</div>
							{{ j.progress }}%
						</td>
						<td>
							<span v-if="j.queue_position">{{ j.queue_position }} in line</span>
							<span v-else>—</span>
						</td>
						<td class="hv-actions">
							<button v-if="j.state === 'failed'" type="button" @click="retryJob(j.id)">
								Retry
							</button>
							<button v-if="j.state === 'queued' || j.state === 'running'" type="button" @click="cancelJob(j.id)">
								Cancel
							</button>
							<button v-if="j.state === 'queued'" type="button" @click="bumpJob(j.id)">
								Move up
							</button>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div v-else-if="tab === 'history'" class="hv-panel">
			<div v-if="historyJobs.length === 0" class="hv-empty-hint">
				No finished jobs yet.
			</div>
			<ul v-else class="hv-library-list">
				<li v-for="j in historyJobs" :key="j.id">
					<strong>#{{ j.id }}</strong> {{ j.filename || '?' }} — {{ j.state }}
					<span class="hv-path-muted">{{ formatTs(j.finished_at || j.created_at) }}</span>
					<button v-if="j.state === 'failed'" type="button" @click="retryJob(j.id)">
						Re-run
					</button>
				</li>
			</ul>
		</div>

		<div v-else-if="tab === 'library'" class="hv-panel">
			<div v-if="library.length === 0" class="hv-empty-hint">
				No HLS-ready videos indexed yet.
			</div>
			<ul v-else class="hv-library-list">
				<li v-for="item in library" :key="item.fileid">
					<a :href="filesLink(item)" target="_blank" rel="noopener">{{ item.filename }}</a>
					<span class="hv-path-muted">{{ item.cache_state }} · {{ formatBytes(item.cache_bytes) }}</span>
				</li>
			</ul>
		</div>

		<div v-else-if="tab === 'watch'" class="hv-panel">
			<p class="hv-empty-hint">
				Watch folders auto-queue new videos for HLS. Add one from Files → folder → Generate HLS (Directory) with auto-generation enabled.
			</p>
			<ul v-if="watchDirs.length" class="hv-library-list">
				<li v-for="w in watchDirs" :key="w.id">
					{{ w.directory }}
					<span class="hv-path-muted">{{ (w.resolutions || []).join(', ') }}</span>
					<button type="button" @click="removeWatch(w.id)">
						Remove
					</button>
				</li>
			</ul>
		</div>

		<div v-else-if="tab === 'logs'" class="hv-panel hv-logs">
			<pre v-for="l in userLogs" :key="l.id">[{{ l.level }}] {{ l.message }}</pre>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { apiDelete, apiGet, apiPost } from '../shared/api.js'
import { formatBytes, filesLink, formatTs } from '../shared/format.js'

export default {
	name: 'App',
	data() {
		return {
			tab: 'queue',
			tabs: [
				{ id: 'overview', label: 'Overview' },
				{ id: 'queue', label: 'Queue' },
				{ id: 'history', label: 'History' },
				{ id: 'library', label: 'Library' },
				{ id: 'watch', label: 'Watch folders' },
				{ id: 'logs', label: 'My logs' },
			],
			overview: null,
			jobs: [],
			historyJobs: [],
			library: [],
			watchDirs: [],
			userLogs: [],
			loading: true,
		}
	},
	async mounted() {
		const open = window.sessionStorage && window.sessionStorage.getItem('hyperviewer_open_tab')
		if (open) {
			this.tab = open
			window.sessionStorage.removeItem('hyperviewer_open_tab')
		}
		await this.refresh()
		this._timer = setInterval(() => this.refresh(), 5000)
	},
	beforeDestroy() {
		clearInterval(this._timer)
	},
	methods: {
		formatBytes,
		formatTs,
		filesLink,
		async refresh() {
			try {
				if (this.tab === 'overview' || !this.overview) {
					this.overview = await apiGet(generateUrl('/apps/hyperviewer/api/user/overview'))
				}
				if (this.tab === 'queue' || this.tab === 'overview') {
					const res = await apiGet(generateUrl('/apps/hyperviewer/api/jobs'), { limit: 100 })
					this.jobs = (res.jobs || []).filter(j => ['queued', 'running', 'failed'].includes(j.state))
				}
				if (this.tab === 'history') {
					const h = await apiGet(generateUrl('/apps/hyperviewer/api/user/history'), { limit: 50 })
					this.historyJobs = h.jobs || []
				}
				if (this.tab === 'library') {
					const lib = await apiGet(generateUrl('/apps/hyperviewer/api/user/library'))
					this.library = lib.items || []
				}
				if (this.tab === 'watch') {
					const w = await apiGet(generateUrl('/apps/hyperviewer/api/auto-generation'))
					this.watchDirs = w.directories || []
				}
				if (this.tab === 'logs') {
					const lg = await apiGet(generateUrl('/apps/hyperviewer/api/user/logs'))
					this.userLogs = lg.entries || []
				}
			} catch (e) {
				console.error(e)
				showError(e.message || 'Failed to load your Hyper Viewer data')
			}
			this.loading = false
		},
		async retryJob(id) {
			await apiPost(generateUrl(`/apps/hyperviewer/api/jobs/${id}/retry`))
			await this.refresh()
		},
		async cancelJob(id) {
			await apiPost(generateUrl(`/apps/hyperviewer/api/jobs/${id}/cancel`))
			await this.refresh()
		},
		async bumpJob(id) {
			await apiPost(generateUrl(`/apps/hyperviewer/api/jobs/${id}/bump`))
			await this.refresh()
		},
		async cancelAllQueued() {
			await apiPost(generateUrl('/apps/hyperviewer/api/user/queue/cancel-queued'))
			showSuccess('Queued jobs cancelled')
			await this.refresh()
		},
		async removeWatch(id) {
			await apiDelete(generateUrl(`/apps/hyperviewer/api/auto-generation/${id}`))
			await this.refresh()
		},
	},
	watch: {
		tab() {
			this.loading = true
			this.refresh()
		},
	},
}
</script>
