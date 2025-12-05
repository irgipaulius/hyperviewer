/**
 * Job Management Module
 * Handles loading, filtering, and displaying jobs in three columns
 */

class JobManager {
	constructor() {
		this.jobs = new Map() // jobId -> job data
		this.lastId = ''
		this.isLoading = false
		this.hasMore = true
		this.pollInterval = null
	}

	/**
	 * Initialize and load all jobs
	 */
	async init() {
		await this.loadAllJobs()
		this.startPolling()
	}

	/**
	 * Load all jobs with pagination
	 */
	async loadAllJobs() {
		this.jobs.clear()
		this.lastId = ''
		this.hasMore = true

		while (this.hasMore && !this.isLoading) {
			await this.loadBatch()
		}

		this.render()
	}

	/**
	 * Load a batch of jobs
	 */
	async loadBatch() {
		if (this.isLoading) return

		this.isLoading = true
		const url = OC.generateUrl('/apps/hyperviewer/api/jobs/active')
		const params = new URLSearchParams({
			lastId: this.lastId,
			limit: '10'
		})

		try {
			const response = await fetch(`${url}?${params}`, {
				headers: { requesttoken: OC.requestToken }
			})
			const data = await response.json()

			// Add new jobs to map
			data.jobs.forEach(job => {
				this.jobs.set(job.id, job)
				this.lastId = job.id
			})

			this.hasMore = data.hasMore
		} catch (error) {
			console.error('Failed to load jobs:', error)
		} finally {
			this.isLoading = false
		}
	}

	/**
	 * Poll for new jobs
	 */
	async pollNewJobs() {
		const url = OC.generateUrl('/apps/hyperviewer/api/jobs/active')
		const params = new URLSearchParams({
			lastId: this.lastId,
			limit: '10'
		})

		try {
			const response = await fetch(`${url}?${params}`, {
				headers: { requesttoken: OC.requestToken }
			})
			const data = await response.json()

			let hasNewJobs = false
			data.jobs.forEach(job => {
				if (!this.jobs.has(job.id)) {
					hasNewJobs = true
				}
				this.jobs.set(job.id, job)
				this.lastId = job.id
			})

			if (hasNewJobs) {
				this.render()
			}
		} catch (error) {
			console.error('Failed to poll jobs:', error)
		}
	}

	/**
	 * Poll progress for processing jobs
	 */
	async pollProcessingJobs() {
		const processingJobs = Array.from(this.jobs.values()).filter(
			job => job.status === 'processing'
		)

		if (processingJobs.length === 0) return

		// Fetch each processing job individually
		const promises = processingJobs.map(job => this.fetchJobProgress(job.id))
		await Promise.all(promises)

		this.render()
	}

	/**
	 * Fetch progress for a single job
	 */
	async fetchJobProgress(jobId) {
		const url = OC.generateUrl(`/apps/hyperviewer/api/jobs/active/${jobId}`)

		try {
			const response = await fetch(url, {
				headers: { requesttoken: OC.requestToken }
			})
			const data = await response.json()

			if (data.job) {
				this.jobs.set(jobId, data.job)
			}
		} catch (error) {
			console.error(`Failed to fetch job ${jobId}:`, error)
		}
	}

	/**
	 * Start polling for new jobs and processing job progress
	 */
	startPolling() {
		if (this.pollInterval) {
			clearInterval(this.pollInterval)
		}
		if (this.progressPollInterval) {
			clearInterval(this.progressPollInterval)
		}

		// Poll for new jobs every 3 seconds
		this.pollInterval = setInterval(() => this.pollNewJobs(), 3000)

		// Poll processing jobs based on window focus
		const pollFrequency = document.hasFocus() ? 3000 : 20000
		this.progressPollInterval = setInterval(() => this.pollProcessingJobs(), pollFrequency)

		// Adjust polling frequency on focus change
		window.addEventListener('focus', () => this.adjustPollingFrequency(true))
		window.addEventListener('blur', () => this.adjustPollingFrequency(false))
	}

	/**
	 * Adjust polling frequency based on window focus
	 */
	adjustPollingFrequency(hasFocus) {
		if (this.progressPollInterval) {
			clearInterval(this.progressPollInterval)
		}

		const pollFrequency = hasFocus ? 3000 : 20000
		this.progressPollInterval = setInterval(() => this.pollProcessingJobs(), pollFrequency)
	}

	/**
	 * Stop polling
	 */
	stopPolling() {
		if (this.pollInterval) {
			clearInterval(this.pollInterval)
			this.pollInterval = null
		}
		if (this.progressPollInterval) {
			clearInterval(this.progressPollInterval)
			this.progressPollInterval = null
		}
	}

	/**
	 * Refresh all data
	 */
	async refresh() {
		this.stopPolling()
		await this.loadAllJobs()
		this.startPolling()
	}

	/**
	 * Filter jobs by status
	 */
	filterJobs(status) {
		return Array.from(this.jobs.values()).filter(job => {
			if (status === 'current') {
				return job.status === 'pending' || job.status === 'processing'
			} else if (status === 'done') {
				return job.status === 'completed'
			} else if (status === 'failed') {
				return job.status === 'failed' || job.status === 'aborted'
			}
			return false
		})
	}

	/**
	 * Render all three columns
	 */
	render() {
		this.renderColumn('current', this.filterJobs('current'))
		this.renderColumn('done', this.filterJobs('done'))
		this.renderColumn('failed', this.filterJobs('failed'))
	}

	/**
	 * Render a single column
	 */
	renderColumn(columnType, jobs) {
		const container = document.getElementById(`jobs-${columnType}`)
		if (!container) return

		// Update count
		const countEl = document.getElementById(`jobs-${columnType}-count`)
		if (countEl) {
			countEl.textContent = jobs.length
		}

		if (jobs.length === 0) {
			container.innerHTML = `<div class="jobs-empty">No ${columnType} jobs</div>`
			return
		}

		container.innerHTML = jobs.map(job => this.renderJobCard(job)).join('')
	}

	/**
	 * Render a single job card
	 */
	renderJobCard(job) {
		const statusClass = this.getStatusClass(job)
		const statusText = this.getStatusText(job)
		const directoryHtml = job.directory ? `📁 ${this.escapeHtml(job.directory)}` : ''
		const progressHtml = job.status === 'processing' ? this.renderProgress(job) : ''
		const timestampsHtml = this.renderTimestamps(job)
		const errorHtml = job.error ? `<strong>❌ Error:</strong> ${this.escapeHtml(job.error)}` : ''
		const resolutionsHtml = this.renderResolutions(job)

		return `
			<div class="job-card ${job.status === 'failed' ? 'retry-pending-card' : ''}" data-job-id="${job.id}">
				<div class="job-header">
					<span class="job-filename" title="${this.escapeHtml(job.directory || '')}/${this.escapeHtml(job.filename || '')}">
						${this.escapeHtml(job.filename || 'Unknown')}
					</span>
					<span class="job-status ${statusClass}">${statusText}</span>
				</div>
				${directoryHtml ? `<div class="job-directory">${directoryHtml}</div>` : ''}
				${progressHtml}
				${timestampsHtml ? `<div class="job-timestamps">${timestampsHtml}</div>` : ''}
				${errorHtml ? `<div class="job-error">${errorHtml}</div>` : ''}
				${resolutionsHtml ? `<div class="job-resolutions">${resolutionsHtml}</div>` : ''}
			</div>
		`
	}

	getStatusClass(job) {
		return job.status || 'pending'
	}

	getStatusText(job) {
		const attempts = job.attempts || 0
		if (job.status === 'aborted') {
			return `Aborted (${attempts}/3 attempts)`
		} else if (job.status === 'failed') {
			return `Failed (${attempts}/3) - Retry pending`
		} else if (job.status === 'processing') {
			return `Processing (attempt ${attempts}/3)`
		}
		return job.status || 'Pending'
	}

	renderProgress(job) {
		if (job.status !== 'processing') return ''
		const progress = job.progress || 0
		return `
			<div class="job-progress">
				<progress value="${progress}" max="100"></progress>
				<span style="font-size: 12px; margin-left: 8px;">${progress}%</span>
			</div>
			<div class="job-details">
				<span>Time: ${this.escapeHtml(job.time || '00:00:00')}</span>
				<span>Frames: ${job.frame || 0}</span>
				<span>Speed: ${this.escapeHtml(job.speed || '0x')}</span>
				<span>FPS: ${this.escapeHtml(job.fps || '0')}</span>
			</div>
		`
	}

	renderTimestamps(job) {
		const parts = []
		if (job.addedAt) {
			parts.push(`📅 Queued: ${this.formatDate(job.addedAt)}`)
		}
		if (job.startedAt) {
			parts.push(`▶️ Started: ${this.formatDate(job.startedAt)}`)
		}
		if (job.completedAt) {
			parts.push(`✅ Completed: ${this.formatDate(job.completedAt)}`)
		}
		if (job.failedAt) {
			parts.push(`⚠️ Failed: ${this.formatDate(job.failedAt)}`)
		}
		return parts.map(p => `<span>${p}</span>`).join('')
	}

	renderResolutions(job) {
		const resolutions = job.settings?.resolutions || []
		if (resolutions.length === 0) return ''
		return resolutions.map(res => `<span class="resolution-tag">${this.escapeHtml(res)}</span>`).join('')
	}

	formatDate(timestamp) {
		return new Date(timestamp * 1000).toLocaleString()
	}

	escapeHtml(text) {
		const div = document.createElement('div')
		div.textContent = text
		return div.innerHTML
	}
}

// Initialize on page load
let jobManager
document.addEventListener('DOMContentLoaded', () => {
	jobManager = new JobManager()
	jobManager.init()

	// Refresh button
	const refreshBtn = document.getElementById('refresh-jobs-btn')
	if (refreshBtn) {
		refreshBtn.addEventListener('click', () => jobManager.refresh())
	}
})
