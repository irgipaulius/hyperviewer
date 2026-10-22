import Vue from 'vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

const App = {
	name: 'HyperViewerCockpit',
	data() {
		return {
			tab: 'overview',
			overview: {},
			settings: {},
			jobs: [],
			logs: [],
			loading: true,
		}
	},
	async mounted() {
		await this.refresh()
		this._timer = setInterval(() => this.refresh(), 10000)
	},
	beforeDestroy() {
		clearInterval(this._timer)
	},
	methods: {
		async refresh() {
			this.loading = true
			try {
				const [ov, st, jobs, logs] = await Promise.all([
					axios.get(generateUrl('/apps/hyperviewer/api/admin/overview')),
					axios.get(generateUrl('/apps/hyperviewer/api/admin/settings')),
					axios.get(generateUrl('/apps/hyperviewer/api/jobs'), { params: { limit: 50, scope: 'all' } }),
					axios.get(generateUrl('/apps/hyperviewer/api/admin/logs')),
				])
				this.overview = ov.data
				this.settings = st.data.settings || {}
				this.jobs = jobs.data.jobs || []
				this.logs = (logs.data.entries || []).reverse()
			} catch (e) {
				console.error(e)
			}
			this.loading = false
		},
		async saveSetting(key, value) {
			await axios.post(generateUrl('/apps/hyperviewer/api/admin/settings'), { key, value })
			await this.refresh()
		},
		async resetSetting(key) {
			await axios.post(generateUrl('/apps/hyperviewer/api/admin/settings/reset'), { key })
			await this.refresh()
		},
		async retryJob(id) {
			await axios.post(generateUrl(`/apps/hyperviewer/api/jobs/${id}/retry`))
			await this.refresh()
		},
		async cancelJob(id) {
			await axios.post(generateUrl(`/apps/hyperviewer/api/jobs/${id}/cancel`))
			await this.refresh()
		},
	},
	template: `
	<div class="hv-cockpit section">
		<h2>Hyper Viewer</h2>
		<nav class="hv-tabs">
			<button v-for="t in ['overview','queue','settings','logs']" :key="t" :class="{ active: tab === t }" @click="tab = t">{{ t }}</button>
		</nav>
		<div v-if="loading" class="emptycontent">Loading…</div>
		<div v-else-if="tab === 'overview'" class="hv-panel">
			<p>Queued: {{ overview.queue_queued }} · Running: {{ overview.queue_running }} · Failed: {{ overview.queue_failed }}</p>
			<p v-if="overview.queue_paused">Queue is paused</p>
		</div>
		<div v-else-if="tab === 'queue'" class="hv-panel">
			<table class="grid">
				<tr v-for="j in jobs" :key="j.id">
					<td>#{{ j.id }} {{ j.type }}</td>
					<td>{{ j.state }} {{ j.progress }}%</td>
					<td>
						<button v-if="j.state === 'failed'" @click="retryJob(j.id)">Retry</button>
						<button v-if="j.state === 'running' || j.state === 'queued'" @click="cancelJob(j.id)">Cancel</button>
					</td>
				</tr>
			</table>
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
