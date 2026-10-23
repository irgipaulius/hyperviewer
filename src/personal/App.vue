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

		<template v-else>
			<div
				v-if="overview && overview.worker_status !== 'running' && (overview.queued > 0 || overview.global_queued > 0)"
				class="hv-worker-banner hv-worker-stopped">
				<strong>Background worker is not running</strong>
				<p class="hv-path-muted">
					You have {{ overview.queued }} job(s) queued locally
					<span v-if="overview.global_queued > overview.queued">
						({{ overview.global_queued }} waiting on the server).
					</span>
					<span v-else>.</span>
					Jobs will not start until an admin starts the hyperviewer worker on the server.
				</p>
			</div>

			<div v-if="tab === 'overview'" class="hv-panel">
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
				<div v-if="overview && overview.sparkline_done_7d" class="hv-sparkline" title="Jobs finished (7 days)">
					<span
						v-for="(n, i) in overview.sparkline_done_7d"
						:key="i"
						class="hv-spark-bar"
						:style="{ height: sparkHeight(n) }" />
				</div>
				<p v-if="overview" class="hv-path-muted">
					<span v-if="overview.storage_free_bytes != null">Account free: {{ formatBytes(overview.storage_free_bytes) }}</span>
					· Cache used: {{ formatBytes(overview.cache_bytes) }}
					<span v-if="overview.cache_quota_bytes"> / {{ formatBytes(overview.cache_quota_bytes) }} quota</span>
				</p>
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
							<th>File</th><th>Chain</th><th>State</th><th>Progress</th><th>Queue / ETA</th><th />
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
								<span v-else-if="j.eta_seconds">~{{ formatDuration(j.eta_seconds) }} left</span>
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
				<div class="hv-queue-toolbar">
					<input
						v-model="historyQuery"
						type="search"
						placeholder="Search filename or type"
						@change="refresh">
					<label>State
						<select v-model="historyState" @change="refresh">
							<option value="">done + failed</option>
							<option value="done">done</option>
							<option value="failed">failed</option>
						</select>
					</label>
					<label>Type
						<select v-model="historyType" @change="refresh">
							<option value="">all</option>
							<option value="hls">HLS</option>
							<option value="clip">clip</option>
							<option value="fingerprint">fingerprint</option>
						</select>
					</label>
				</div>
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
						<button v-if="j.type === 'clip' && j.state === 'done'" type="button" @click="openHistoryResult(j)">
							Open clip
						</button>
						<button v-if="(j.type === 'encode' || j.type === 'upgrade' || j.type_label === 'hls') && j.state === 'done' && j.fileid" type="button" @click="openHistoryResult(j)">
							Play HLS
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
						<span v-if="item.pinned" class="hv-pin">★</span>
						<a :href="filesLink(item)" target="_blank" rel="noopener">{{ item.filename }}</a>
						<span class="hv-path-muted">
							{{ item.cache_state }} · {{ formatBytes(item.cache_bytes) }}
							<span v-if="item.renditions && item.renditions.length"> · {{ item.renditions.join('/') }}</span>
							<span v-else> · auto</span>
						</span>
						<div class="hv-actions">
							<button type="button" @click="playLibraryItem(item)">
								Play
							</button>
							<button type="button" @click="copyStream(item.fileid)">
								Copy stream
							</button>
							<button type="button" @click="createPublicLink(item.fileid)">
								Public link
							</button>
							<button type="button" @click="regenerate(item.fileid)">
								Regenerate
							</button>
							<button type="button" @click="togglePin(item)">
								{{ item.pinned ? 'Unpin' : 'Pin' }}
							</button>
							<button type="button" @click="deleteCache(item.fileid)">
								Delete cache
							</button>
						</div>
					</li>
				</ul>
			</div>

			<div v-else-if="tab === 'preferences'" class="hv-panel">
				<div v-if="prefs" class="hv-prefs">
					<label><input v-model="prefs.auto_generate_upload" type="checkbox"> Auto-generate HLS on upload (when enabled globally)</label>
					<label>HLS notifications
						<select v-model="prefs.hls_notify_mode">
							<option value="summary">
								Summary only (recommended)
							</option>
							<option value="per_file">
								Per file
							</option>
							<option value="off">
								Off
							</option>
						</select>
					</label>
					<label>Default renditions (comma-separated)
						<input v-model="prefs.default_renditions" type="text">
					</label>
					<label>Clip export folder (relative to Files)
						<input v-model="prefs.clip_export_dir" type="text">
					</label>
					<button type="button" @click="savePrefs">
						Save preferences
					</button>
				</div>
			</div>

			<div v-else-if="tab === 'watch'" class="hv-panel">
				<div class="hv-queue-toolbar">
					<input
						v-model="watchPath"
						type="text"
						placeholder="/Videos/inbox"
						class="hv-watch-input">
					<button type="button" @click="pickWatchFolder">
						Pick folder…
					</button>
					<button type="button" @click="addWatchFolder">
						Add watch folder
					</button>
				</div>
				<p class="hv-empty-hint">
					Watch folders auto-queue new videos for HLS. Path is relative to your Files root.
				</p>
				<ul v-if="watchDirs.length" class="hv-library-list">
					<li v-for="w in watchDirs" :key="w.id">
						<label><input type="checkbox" :checked="w.enabled" @change="toggleWatch(w, $event)"> {{ w.directory }}</label>
						<span class="hv-path-muted">{{ (w.resolutions || []).join(', ') }}</span>
						<button type="button" @click="removeWatch(w.id)">
							Remove
						</button>
					</li>
				</ul>
			</div>

			<div v-else-if="tab === 'links'" class="hv-panel">
				<button type="button" @click="refreshLinks">
					Refresh links
				</button>
				<ul v-if="publicLinks.length" class="hv-library-list">
					<li v-for="link in publicLinks" :key="link.id">
						{{ link.filename || ('file #' + link.fileid) }}
						<span class="hv-path-muted">{{ link.view_count }} views · expires {{ link.expires_at ? formatTs(link.expires_at) : 'never' }}</span>
						<button type="button" @click="copyText(link.url)">
							Copy URL
						</button>
						<button type="button" @click="revokeLink(link.id)">
							Revoke
						</button>
					</li>
				</ul>
				<p v-else class="hv-empty-hint">
					No active public links. Create one from Library.
				</p>
			</div>

			<div v-else-if="tab === 'logs'" class="hv-panel hv-logs">
				<button type="button" @click="downloadLogs">
					Download logs
				</button>
				<pre v-for="l in userLogs" :key="l.id">[{{ l.level }}] {{ l.message }}</pre>
			</div>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { apiDelete, apiGet, apiPost } from '../shared/api.js'
import { pickFolderPath } from '../shared/folder-picker.js'
import { formatBytes, filesLink, formatTs, formatDuration } from '../shared/format.js'

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
				{ id: 'links', label: 'Links' },
				{ id: 'logs', label: 'My logs' },
				{ id: 'preferences', label: 'Preferences' },
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
			loading: true,
		}
	},
	watch: {
		tab() {
			this.loading = true
			this.refresh()
		},
	},
	async mounted() {
		const open = window.sessionStorage && window.sessionStorage.getItem('hyperviewer_open_tab')
		if (open) {
			this.tab = open
			window.sessionStorage.removeItem('hyperviewer_open_tab')
		}
		const filterFid = window.sessionStorage && window.sessionStorage.getItem('hyperviewer_filter_fileid')
		if (filterFid) {
			window.sessionStorage.removeItem('hyperviewer_filter_fileid')
			this._filterFileid = parseInt(filterFid, 10)
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
		formatDuration,
		filesLink,
		sparkHeight(n) {
			const max = Math.max(1, ...(this.overview && this.overview.sparkline_done_7d ? this.overview.sparkline_done_7d : [1]))
			return `${Math.max(4, Math.round((n / max) * 48))}px`
		},
		async refresh() {
			try {
				this.overview = await apiGet(generateUrl('/apps/hyperviewer/api/user/overview'))
				if (this.tab === 'queue' || this.tab === 'overview') {
					const res = await apiGet(generateUrl('/apps/hyperviewer/api/jobs'), { limit: 100 })
					this.jobs = (res.jobs || []).filter(j => ['queued', 'running', 'failed'].includes(j.state))
				}
				if (this.tab === 'history') {
					const params = { limit: 50 }
					if (this.historyState) {
						params.state = this.historyState
					}
					if (this.historyType) {
						params.type = this.historyType
					}
					if (this.historyQuery) {
						params.q = this.historyQuery
					}
					const h = await apiGet(generateUrl('/apps/hyperviewer/api/user/history'), params)
					this.historyJobs = h.jobs || []
				}
				if (this.tab === 'preferences' || !this.prefs) {
					const p = await apiGet(generateUrl('/apps/hyperviewer/api/user/preferences'))
					const raw = p.preferences || {}
					this.prefs = {
						auto_generate_upload: !!raw.auto_generate_upload,
						hls_notify_mode: raw.hls_notify_mode || 'summary',
						default_renditions: raw.default_renditions || '',
						clip_export_dir: raw.clip_export_dir || '../exports',
					}
				}
				if (this.tab === 'library') {
					const lib = await apiGet(generateUrl('/apps/hyperviewer/api/user/library'))
					let items = lib.items || []
					if (this._filterFileid) {
						items = items.filter(i => i.fileid === this._filterFileid)
					}
					this.library = items
				}
				if (this.tab === 'watch') {
					const w = await apiGet(generateUrl('/apps/hyperviewer/api/auto-generation'))
					this.watchDirs = w.directories || []
				}
				if (this.tab === 'links') {
					await this.refreshLinks()
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
		async savePrefs() {
			await apiPost(generateUrl('/apps/hyperviewer/api/user/preferences'), this.prefs)
			showSuccess('Preferences saved')
		},
		async regenerate(fileid) {
			await apiPost(generateUrl('/apps/hyperviewer/api/user/library/regenerate'), { fileid })
			showSuccess('Regeneration queued')
			this.tab = 'queue'
			await this.refresh()
		},
		async deleteCache(fileid) {
			await apiPost(generateUrl('/apps/hyperviewer/api/user/library/delete-cache'), { fileid })
			showSuccess('Cache removed')
			await this.refresh()
		},
		async togglePin(item) {
			await apiPost(generateUrl('/apps/hyperviewer/api/user/library/pin'), { fileid: item.fileid, pinned: !item.pinned })
			await this.refresh()
		},
		async copyStream(fileid) {
			const res = await apiGet(generateUrl('/apps/hyperviewer/api/user/library/stream-url'), { fileid })
			await navigator.clipboard.writeText(res.url)
			showSuccess('Stream URL copied')
		},
		async refreshLinks() {
			const res = await apiGet(generateUrl('/apps/hyperviewer/api/user/links'))
			this.publicLinks = res.links || []
		},
		async createPublicLink(fileid) {
			const days = parseInt(window.prompt('Link expires in days (0 = never)', '30') || '0', 10)
			await apiPost(generateUrl('/apps/hyperviewer/api/user/links'), { fileid, expires_days: days })
			showSuccess('Public link created')
			this.tab = 'links'
			await this.refreshLinks()
		},
		async revokeLink(id) {
			await apiPost(generateUrl('/apps/hyperviewer/api/user/links/revoke'), { id })
			await this.refreshLinks()
		},
		async copyText(text) {
			await navigator.clipboard.writeText(text)
			showSuccess('Copied')
		},
		playLibraryItem(item) {
			window.open(filesLink(item), '_blank', 'noopener')
		},
		openHistoryResult(j) {
			if (j.fileid) {
				window.open(filesLink(j), '_blank', 'noopener')
			}
		},
		async pickWatchFolder() {
			const path = await pickFolderPath()
			if (path) {
				this.watchPath = path
			}
		},
		async toggleWatch(w, ev) {
			await apiPost(generateUrl(`/apps/hyperviewer/api/auto-generation/${w.id}/enabled`), {
				enabled: ev.target.checked,
			})
		},
		downloadLogs() {
			window.location.href = generateUrl('/apps/hyperviewer/api/user/logs/export')
		},
		async addWatchFolder() {
			const directory = (this.watchPath || '').trim()
			if (!directory) {
				return
			}
			await apiPost(generateUrl('/apps/hyperviewer/cache/register-auto-generation'), {
				directory,
				options: { enableAutoGeneration: true, resolutions: ['720p', '480p', '360p', '240p'] },
			})
			this.watchPath = ''
			showSuccess('Watch folder registered')
			await this.refresh()
		},
	},
}
</script>
