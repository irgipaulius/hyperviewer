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

		<div v-if="loading && tab === 'overview'" class="emptycontent">
			Loading…
		</div>

		<div v-else-if="tab === 'overview'" class="hv-panel">
			<div v-if="overview" class="hv-summary-cards">
				<div class="hv-card">
					<span class="hv-card-n">{{ overview.ready_count || 0 }}</span> videos ready
				</div>
				<div class="hv-card">
					<span class="hv-card-n">{{ formatBytes(overview.cache_bytes) }}</span> cache used
				</div>
				<div class="hv-card">
					<span class="hv-card-n">{{ overview.jobs_today || 0 }}</span> jobs today
				</div>
				<div class="hv-card">
					<span class="hv-card-n">{{ overview.queued || 0 }}</span> in queue
				</div>
			</div>
			<p v-if="overview && overview.ready_count === 0" class="hv-empty-hint">
				Right-click a video in Files → <strong>Generate HLS Cache</strong>, or use the queue tab after queuing work.
			</p>
		</div>

		<div v-else-if="tab === 'queue'" class="hv-panel">
			<div v-if="jobs.length === 0" class="hv-empty-hint">
				No active jobs. Generate HLS from Files to see progress here.
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
			<div v-for="j in jobs.filter(x => x.error)" :key="'e-' + j.id" class="hv-job-error">
				<strong>{{ j.filename || ('Job #' + j.id) }}</strong>: {{ j.error }}
			</div>
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

		<div v-else class="hv-panel hv-empty-hint">
			More sections (history, links, watch folders, preferences) coming in the next release.
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { apiGet, apiPost } from '../shared/api.js'
import { formatBytes, filesLink } from '../shared/format.js'

export default {
	name: 'App',
	data() {
		return {
			tab: 'queue',
			tabs: [
				{ id: 'overview', label: 'Overview' },
				{ id: 'queue', label: 'Queue' },
				{ id: 'library', label: 'My library' },
				{ id: 'more', label: 'More' },
			],
			overview: null,
			jobs: [],
			library: [],
			loading: true,
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
		formatBytes,
		filesLink,
		async refresh() {
			try {
				if (this.tab === 'overview' || !this.overview) {
					this.overview = await apiGet(generateUrl('/apps/hyperviewer/api/user/overview'))
				}
				if (this.tab === 'queue' || this.tab === 'overview') {
					const res = await apiGet(generateUrl('/apps/hyperviewer/api/jobs'), {
						limit: 100,
						state: '',
					})
					this.jobs = (res.jobs || []).filter(j => ['queued', 'running', 'failed'].includes(j.state))
				}
				if (this.tab === 'library') {
					const lib = await apiGet(generateUrl('/apps/hyperviewer/api/user/library'))
					this.library = lib.items || []
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
	},
	watch: {
		tab() {
			this.loading = true
			this.refresh()
		},
	},
}
</script>
