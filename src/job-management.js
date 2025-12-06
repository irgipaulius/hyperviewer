/**
 * Job Management Module
 * Handles loading, filtering, and displaying jobs in three columns
 */

class JobManager {

	constructor() {
		this.jobs = new Map() // jobId -> job data
		this.selectedJobs = new Set() // Selected job IDs
		this.visibleJobs = new Set() // Visible job IDs
		this.searchQuery = ''
		this.lastId = ''
		this.isLoading = false
		this.hasMore = true
		this.pollInterval = null
		this.activePollInterval = null
		
		// Intersection Observer for visibility tracking
		this.observer = new IntersectionObserver((entries) => {
			entries.forEach(entry => {
				const jobId = entry.target.dataset.jobId
				if (entry.isIntersecting) {
					this.visibleJobs.add(jobId)
				} else {
					this.visibleJobs.delete(jobId)
				}
			})
		}, { threshold: 0.1 })
	}

	/**
	 * Initialize and load all jobs
	 */
	async init() {
		this.setupToolbar()
		await this.loadAllJobs()
		this.startPolling()
	}

	/**
	 * Setup toolbar event listeners
	 */
	setupToolbar() {
		// Search
		const searchInput = document.getElementById('jobs-search-input')
		if (searchInput) {
			searchInput.addEventListener('input', (e) => {
				this.searchQuery = e.target.value.toLowerCase().trim()
				this.render()
			})
		}

		// Batch Refresh
		const refreshBtn = document.getElementById('batch-refresh-btn')
		if (refreshBtn) {
			refreshBtn.addEventListener('click', () => this.batchRefresh())
		}

		// Batch Delete
		const deleteBtn = document.getElementById('batch-delete-btn')
		if (deleteBtn) {
			deleteBtn.addEventListener('click', () => this.batchDelete())
		}
		
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
			// Render immediately after each batch for reactive UI
			this.render()
		}
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
			limit: '100'
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
	 * Poll for new jobs (only checks for new additions)
	 */
	async pollNewJobs() {
		const url = OC.generateUrl('/apps/hyperviewer/api/jobs/active')
		const params = new URLSearchParams({
			lastId: this.lastId,
			limit: '100'
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
	 * Poll and update all pending and processing jobs
	 */
	async pollActiveJobs() {
		// Only poll jobs that are visible AND (pending or processing)
		const jobsToPoll = Array.from(this.jobs.values()).filter(job => 
			(job.status === 'pending' || job.status === 'processing') && 
			this.visibleJobs.has(job.id)
		)

		if (jobsToPoll.length === 0) return

		// Fetch filtered active jobs
		// Fetch filtered active jobs
		const jobIds = jobsToPoll.map(job => job.id)
		await this.fetchJobsProgressBatch(jobIds)

		this.render()
	}

	/**
	 * Fetch progress for a single job
	 */
	/**
	 * Fetch progress for a single job (using batch endpoint)
	 */
	/**
	 * Fetch progress for multiple jobs (using batch endpoint)
	 */
	async fetchJobsProgressBatch(jobIds) {
		if (!jobIds || jobIds.length === 0) return

		const url = OC.generateUrl('/apps/hyperviewer/api/jobs/batch-status')

		try {
			const response = await fetch(url, {
				method: 'POST',
				headers: { 
					requesttoken: OC.requestToken,
					'Content-Type': 'application/json'
				},
				body: JSON.stringify({ ids: jobIds })
			})
			const data = await response.json()

			// Update all returned jobs
			if (data.jobs) {
				data.jobs.forEach(job => {
					this.jobs.set(job.id, job)
				})
			}
		} catch (error) {
			console.error(`Failed to fetch jobs batch:`, error)
		}
	}

	/**
	 * Stop polling
	 */
	stopPolling() {
		if (this.pollInterval) {
			clearInterval(this.pollInterval)
			this.pollInterval = null
		}
		if (this.activePollInterval) {
			clearInterval(this.activePollInterval)
			this.activePollInterval = null
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
	 * Refresh a single job
	 */
	async refreshJob(jobId) {
		await this.fetchJobsProgressBatch([jobId])
		this.render()
	}

	/**
	 * Toggle selection of a single job
	 */
	toggleJobSelection(jobId) {
		if (this.selectedJobs.has(jobId)) {
			this.selectedJobs.delete(jobId)
		} else {
			this.selectedJobs.add(jobId)
		}
		this.render()
	}

	/**
	 * Select/Deselect all jobs in a column (respecting filter)
	 */
	toggleSelectAll(columnType) {
		const jobs = this.filterJobs(columnType)
		const allSelected = jobs.every(job => this.selectedJobs.has(job.id))
		
		if (allSelected) {
			jobs.forEach(job => this.selectedJobs.delete(job.id))
		} else {
			jobs.forEach(job => this.selectedJobs.add(job.id))
		}
		this.render()
	}

	/**
	 * Update toolbar button states
	 */
	updateToolbar() {
		const count = this.selectedJobs.size
		
		const refreshBtn = document.getElementById('batch-refresh-btn')
		const refreshCount = document.getElementById('batch-refresh-count')
		if (refreshBtn && refreshCount) {
			refreshBtn.disabled = count === 0
			refreshCount.textContent = count > 0 ? `(${count})` : ''
		}

		const deleteBtn = document.getElementById('batch-delete-btn')
		const deleteCount = document.getElementById('batch-delete-count')
		if (deleteBtn && deleteCount) {
			deleteBtn.disabled = count === 0
			deleteCount.textContent = count > 0 ? `(${count})` : ''
		}
	}

	/**
	 * Batch Delete
	 */
	async batchDelete() {
		if (this.selectedJobs.size === 0) return
		
		// Immediate UI update (optimistic)
		const idsToDelete = Array.from(this.selectedJobs)
		
		const url = OC.generateUrl('/apps/hyperviewer/api/jobs/batch-delete')
		
		try {
			await fetch(url, {
				method: 'POST',
				headers: { 
					requesttoken: OC.requestToken,
					'Content-Type': 'application/json'
				},
				body: JSON.stringify({ ids: idsToDelete })
			})
			
			// Remove from local state
			idsToDelete.forEach(id => this.jobs.delete(id))
			this.selectedJobs.clear()
			this.render()
			
		} catch (error) {
			console.error('Batch delete failed:', error)
			alert('Failed to delete selected jobs')
			this.refresh() // Revert UI
		}
	}

	/**
	 * Batch Refresh
	 */
	async batchRefresh() {
		if (this.selectedJobs.size === 0) return

		const idsToRefresh = Array.from(this.selectedJobs)
		const url = OC.generateUrl('/apps/hyperviewer/api/jobs/batch-status')
		
		try {
			const response = await fetch(url, {
				method: 'POST',
				headers: { 
					requesttoken: OC.requestToken,
					'Content-Type': 'application/json'
				},
				body: JSON.stringify({ ids: idsToRefresh })
			})
			
			const data = await response.json()
			
			if (data.jobs) {
				data.jobs.forEach(job => this.jobs.set(job.id, job))
				this.render()
			}
			
		} catch (error) {
			console.error('Batch refresh failed:', error)
		}
	}

	/**
	 * Delete a job from the queue
	 */
	/**
	 * Delete a job from the queue (using batch endpoint)
	 */
	async deleteJob(jobId) {
		const url = OC.generateUrl('/apps/hyperviewer/api/jobs/batch-delete')

		try {
			const response = await fetch(url, {
				method: 'POST',
				headers: { 
					requesttoken: OC.requestToken,
					'Content-Type': 'application/json'
				},
				body: JSON.stringify({ ids: [jobId] })
			})

			const data = await response.json()

			if (data.success) {
				this.jobs.delete(jobId)
				// Also remove from selection if present
				this.selectedJobs.delete(jobId) 
				this.render()
			} else {
				alert('Failed to delete job')
			}
		} catch (error) {
			console.error(`Failed to delete job ${jobId}:`, error)
			alert('Failed to delete job')
		}
	}

	/**
	 * Filter jobs by status
	 */
	filterJobs(status) {
		return Array.from(this.jobs.values()).filter(job => {
			// Status Filter
			let statusMatch = false
			if (status === 'current') {
				statusMatch = job.status === 'pending' || job.status === 'processing'
			} else if (status === 'done') {
				statusMatch = job.status === 'completed'
			} else if (status === 'failed') {
				statusMatch = job.status === 'failed' || job.status === 'aborted'
			}
			if (!statusMatch) return false

			// Search Filter
			if (this.searchQuery) {
				const filename = (job.filename || '').toLowerCase()
				const directory = (job.directory || '').toLowerCase()
				return filename.includes(this.searchQuery) || directory.includes(this.searchQuery)
			}
			
			return true
		})
	}

	/**
	 * Render all three columns
	 */
	render() {
		this.renderColumn('current', this.filterJobs('current'))
		this.renderColumn('done', this.filterJobs('done'))
		this.renderColumn('failed', this.filterJobs('failed'))
		this.updateToolbar()
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

		// Update Header (Select All)
		const headerEl = container.parentElement.querySelector('.jobs-column-header')
		if (headerEl) {
			let checkbox = headerEl.querySelector('.column-select-all')
			if (!checkbox) {
				// Inject checkbox
				const span = document.createElement('span')
				span.innerHTML = `<input type="checkbox" class="column-select-all" data-column="${columnType}">`
				headerEl.insertBefore(span.firstChild, headerEl.firstChild)
				
				// Re-query
				checkbox = headerEl.querySelector('.column-select-all')
				checkbox.addEventListener('change', (e) => this.toggleSelectAll(columnType))
			}
			
			// Update state
			const allSelected = jobs.length > 0 && jobs.every(job => this.selectedJobs.has(job.id))
			const someSelected = jobs.some(job => this.selectedJobs.has(job.id))
			
			checkbox.checked = allSelected
			checkbox.indeterminate = someSelected && !allSelected
		}

		if (jobs.length === 0) {
			const emptyMsg = this.searchQuery ? 'No matching jobs' : `No ${columnType} jobs`
			container.innerHTML = `<div class="jobs-empty">${emptyMsg}</div>`
			return
		}

		// Sort jobs by most relevant timestamp (newest first)
		const sortedJobs = jobs.sort((a, b) => {
			const timeA = a.completedAt || a.failedAt || a.startedAt || a.addedAt || 0
			const timeB = b.completedAt || b.failedAt || b.startedAt || b.addedAt || 0
			return timeB - timeA
		})

		container.innerHTML = sortedJobs.map(job => this.renderJobCard(job)).join('')

		// Attach Observers and Listeners
		container.querySelectorAll('.job-card').forEach(card => {
			// Visibility Observer
			this.observer.observe(card)
			
			// Checkbox Listener
			const checkbox = card.querySelector('.job-checkbox')
			checkbox.addEventListener('change', (e) => {
				this.toggleJobSelection(card.dataset.jobId)
			})
			
			// Action Buttons
			card.querySelectorAll('.job-action-btn').forEach(btn => {
				btn.addEventListener('click', (e) => {
					e.preventDefault()
					e.stopPropagation()
					const action = e.currentTarget.dataset.action
					const jobId = e.currentTarget.dataset.jobId
					if (action === 'refresh') {
						this.refreshJob(jobId)
					} else if (action === 'delete') {
						this.deleteJob(jobId)
					}
				})
			})
		})
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
		const isSelected = this.selectedJobs.has(job.id)

		return `
			<div class="job-card ${job.status === 'failed' ? 'retry-pending-card' : ''} ${isSelected ? 'selected' : ''}" data-job-id="${job.id}">
				<div class="job-selection-checkbox">
					<input type="checkbox" class="job-checkbox" data-job-id="${job.id}" ${isSelected ? 'checked' : ''}>
				</div>
				<div class="job-content">
					<div class="job-filename" title="${this.escapeHtml(job.filename)}">${this.escapeHtml(job.filename)}</div>
					
					${resolutionsHtml}

					<div class="job-details">
						${directoryHtml}
					</div>

					${progressHtml}
					${timestampsHtml}
					${errorHtml}

					<div class="job-footer">
						<div class="job-footer-left">
							<span class="job-status ${statusClass}">${statusText}</span>
						</div>
						
						<div class="job-actions">
							<button class="job-action-btn" data-action="refresh" data-job-id="${job.id}" title="Refresh status">
								<span class="icon-reset"></span>
							</button>
							<button class="job-action-btn" data-action="delete" data-job-id="${job.id}" title="Delete job">
								<span class="icon-delete"></span>
							</button>
						</div>
					</div>
				</div>
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
		console.log('rendering processing job', job)
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
			parts.push(`<div class="job-timestamp">📅 Queued: ${this.formatDate(job.addedAt)}</div>`)
		}
		if (job.startedAt) {
			parts.push(`<div class="job-timestamp">▶️ Started: ${this.formatDate(job.startedAt)}</div>`)
		}
		if (job.completedAt) {
			parts.push(`<div class="job-timestamp">✅ Completed: ${this.formatDate(job.completedAt)}</div>`)
		}
		if (job.failedAt) {
			parts.push(`<div class="job-timestamp">⚠️ Failed: ${this.formatDate(job.failedAt)}</div>`)
		}
		return `<div class="job-timestamps-container">${parts.join('')}</div>`
	}

	renderResolutions(job) {
		const resolutions = job.settings?.resolutions || []
		if (resolutions.length === 0) return ''
		return resolutions.map(res => `<span class="resolution-tag">${this.escapeHtml(res)}</span>`).join('')
	}

	startPolling() {
		if (this.pollInterval) {
			clearInterval(this.pollInterval)
		}
		if (this.activePollInterval) {
			clearInterval(this.activePollInterval)
		}

		// Poll for new jobs every 10 seconds
		this.pollInterval = setInterval(() => this.pollNewJobs(), 10000)

		// Poll active (pending/processing) jobs every 5 seconds
		this.activePollInterval = setInterval(() => this.pollActiveJobs(), 5000)
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
	// Only initialize if we're on the settings page with job containers
	const jobsGrid = document.querySelector('.jobs-grid')
	if (!jobsGrid) {
		console.log('Job management UI not found, skipping initialization')
		return
	}

	console.log('Initializing job manager...')
	jobManager = new JobManager()
	jobManager.init()

	// Refresh button
	const refreshBtn = document.getElementById('refresh-jobs-btn')
	if (refreshBtn) {
		refreshBtn.addEventListener('click', () => jobManager.refresh())
	}
})
