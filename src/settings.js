/**
 * Settings JavaScript for Hyper Viewer
 * Handles cache location configuration UI and dashboard monitoring
 */

console.log('🎬 Hyper Viewer settings script loaded!')

console.log('🎬 Hyper Viewer settings script loaded!')

document.addEventListener('DOMContentLoaded', function() {
	// Initialize dashboard
	initializeDashboard()
	console.log('🔧 Hyper Viewer settings DOM ready')

	const settingsSection = document.getElementById('hyperviewer_settings')
	if (!settingsSection) {
		console.log('⚠️ Hyper Viewer settings section not found')
		return
	}

	console.log('✅ Hyper Viewer settings section found')
})

/**
 * Initialize dashboard monitoring
 */
function initializeDashboard() {
	console.log('📊 Initializing dashboard...')
	
	// Initial data load
	refreshAllData()
	
	// Refresh on window focus
	window.addEventListener('focus', () => {
		console.log('👀 Window focused, refreshing data...')
		refreshAllData()
	})

	// Setup refresh button
	const refreshBtn = document.getElementById('refresh-stats')
	if (refreshBtn) {
		refreshBtn.addEventListener('click', () => {
			console.log('🔄 Manual refresh triggered')
			refreshAllData()
		})
	}

	// Setup search filter
	const searchInput = document.getElementById('completed-jobs-search')
	if (searchInput) {
		searchInput.addEventListener('input', (e) => {
			filterCompletedJobs(e.target.value)
		})
	}
}

/**
 * Refresh all dashboard data
 */
function refreshAllData() {
	refreshStatistics()
	refreshAutoGeneration()
	updateLastRefreshedTime()
}

/**
 * Update the last refreshed timestamp
 */
function updateLastRefreshedTime() {
	const timeSpan = document.getElementById('last-updated-time')
	const refreshBtn = document.getElementById('refresh-stats')
	if (timeSpan) {
		const now = new Date()
		const timeStr = now.toLocaleTimeString()
		timeSpan.textContent = `Last updated: ${timeStr}`
		if (refreshBtn) {
			refreshBtn.title = `Last updated: ${now.toLocaleString()}`
		}
	}
}

/**
 * Refresh statistics
 */
function refreshStatistics() {
	fetch(OC.generateUrl('/apps/hyperviewer/api/jobs/statistics'), {
		headers: {
			requesttoken: OC.requestToken
		}
	})
		.then(response => response.json())
		.then(data => {
			console.log(data)
			const stats = data || {} // API returns stats directly now, or inside 'stats' key depending on controller
			
			// Handle both response formats (direct stats or wrapped in 'stats')
			const actualStats = stats.stats || stats

			document.getElementById('stat-active').textContent = actualStats.activeJobs || 0
			document.getElementById('stat-autogen').textContent = actualStats.autoGenDirectories || 0
			document.getElementById('stat-completed').textContent = actualStats.completedJobs || 0
			document.getElementById('stat-pending').textContent = actualStats.pendingJobs || 0
			
			// Update completed count badge
			const countBadge = document.getElementById('completed-count-badge')
			if (countBadge) {
				countBadge.textContent = actualStats.completedJobs || 0
			}

			// Populate completed jobs list
			if (actualStats.completedJobFilenames) {
				populateCompletedJobs(actualStats.completedJobFilenames)
			}
		})
		.catch(error => {
			console.error('❌ Failed to fetch statistics:', error)
		})
}

/**
 * Populate completed jobs list
 */
function populateCompletedJobs(filenames) {
	const list = document.getElementById('completed-jobs-list')
	const emptyMsg = document.getElementById('no-completed-jobs')
	
	if (!list) return

	console.log(filenames)

	// Store filenames for filtering
	list.dataset.allJobs = JSON.stringify(filenames.map(({ name }) => name))

	if (!filenames || filenames.length === 0) {
		list.innerHTML = ''
		if (emptyMsg) emptyMsg.style.display = 'block'
		return
	}

	if (emptyMsg) emptyMsg.style.display = 'none'

	// Render list
	renderJobList(filenames)
}

/**
 * Render job list items
 */
function renderJobList(jobs) {
	const list = document.getElementById('completed-jobs-list')
	if (!list) return

	// Limit to 500 items for performance
	const maxItems = 500
	const items = jobs.slice(0, maxItems)
	
	list.innerHTML = items.map(job => {
		// Handle both old format (strings) and new format (objects)
		if (typeof job === 'string') {
			// Old format: job is just a string filename
			return `
				<li class="job-item">
					<span class="job-name" title="${escapeHtml(job)}">${escapeHtml(job)}</span>
				</li>
			`
		}
		
		// New format: job is an object with name, timestamp, and sizeBytes
		const name = job.name || 'Unknown'
		const timestamp = job.timestamp || 0
		const sizeBytes = job.sizeBytes || 0
		
		// Format timestamp to local date/time
		const date = timestamp > 0 ? new Date(timestamp * 1000) : null
		const dateStr = date ? date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'Unknown'
		
		// Format size in MB or GB
		let sizeStr = '0 MB'
		if (sizeBytes > 0) {
			const mb = sizeBytes / (1024 * 1024)
			const gb = sizeBytes / (1024 * 1024 * 1024)
			sizeStr = gb >= 1 ? `${gb.toFixed(2)} GB` : `${mb.toFixed(2)} MB`
		}
		
		const displayText = `${name}: ${dateStr} - ${sizeStr}`
		
		return `
			<li class="job-item">
				<span class="job-name" title="${escapeHtml(name)}">${escapeHtml(displayText)}</span>
			</li>
		`
	}).join('')
	
	if (jobs.length > maxItems) {
		list.innerHTML += `<li class="job-item more-items">...and ${jobs.length - maxItems} more</li>`
	}
}

/**
 * Filter completed jobs list
 */
function filterCompletedJobs(query) {
	const list = document.getElementById('completed-jobs-list')
	if (!list || !list.dataset.allJobs) return

	const allJobs = JSON.parse(list.dataset.allJobs)
	const normalizedQuery = query.toLowerCase().trim()

	if (!normalizedQuery) {
		renderJobList(allJobs)
		return
	}

	const filtered = allJobs.filter(job => {
		// Handle both string format and object format
		const searchText = typeof job === 'string' ? job : (job.name || '')
		return searchText.toLowerCase().includes(normalizedQuery)
	})
	
	renderJobList(filtered)
	
	const emptyMsg = document.getElementById('no-completed-jobs')
	if (emptyMsg) {
		emptyMsg.style.display = filtered.length === 0 ? 'block' : 'none'
		if (filtered.length === 0) emptyMsg.textContent = 'No matching jobs found'
	}
}

/**
 * Refresh auto-generation directories
 */
function refreshAutoGeneration() {
	fetch(OC.generateUrl('/apps/hyperviewer/api/auto-generation'), {
		headers: {
			requesttoken: OC.requestToken
		}
	})
		.then(response => response.json())
		.then(data => {
			const dirs = data.autoGenDirs || []
			const container = document.getElementById('autogen-container')
			
			if (dirs.length === 0) {
				container.innerHTML = '<p class="emptycontent-desc">No auto-generation directories configured</p>'
			} else {
				container.innerHTML = dirs.map(dir => `
					<div class="auto-gen-card">
						<div class="auto-gen-header">
							<span class="auto-gen-path">${escapeHtml(dir.directory || 'Unknown')}</span>
							<span class="auto-gen-status ${dir.enabled ? 'enabled' : 'disabled'}">
								${dir.enabled ? 'Enabled' : 'Disabled'}
							</span>
						</div>
						<div class="auto-gen-details">
							<span>Cache: ${escapeHtml(dir.cachePath || dir.cacheLocation || 'Not set')}</span>
							<span>Registered: ${formatDate(dir.createdAt || dir.registeredAt)}</span>
						</div>
						${dir.resolutions && dir.resolutions.length > 0
							? `
							<div class="auto-gen-resolutions">
								${dir.resolutions.map(res => `<span class="resolution-tag">${escapeHtml(res)}</span>`).join('')}
							</div>
						`
							: ''}
						<div class="auto-gen-actions">
							<button class="button remove-autogen" data-config-key="${escapeHtml(dir.configKey)}">
								Remove
							</button>
						</div>
					</div>
				`).join('')
				
				// Attach event listeners after rendering (CSP-safe)
				container.querySelectorAll('.remove-autogen').forEach(btn => {
					btn.addEventListener('click', () => {
						removeAutoGeneration(btn.dataset.configKey);
					});
				});
			}
		})
		.catch(error => {
			console.error('❌ Failed to fetch auto-generation settings:', error)
		})
}

/**
 * Remove auto-generation directory
 */
function removeAutoGeneration(configKey) {
	if (!confirm('Remove this auto-generation directory?')) {
		return
	}
	
	fetch(OC.generateUrl('/apps/hyperviewer/api/auto-generation/' + encodeURIComponent(configKey)), {
		method: 'DELETE',
		headers: {
			requesttoken: OC.requestToken
		}
	})
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				OC.Notification.showTemporary('Auto-generation directory removed')
				refreshAutoGeneration()
				refreshStatistics()
			} else {
				OC.Notification.showTemporary('Failed to remove directory')
			}
		})
		.catch(error => {
			console.error('❌ Failed to remove auto-generation:', error)
			OC.Notification.showTemporary('Failed to remove directory')
		})
}

/**
 * Format date for display
 */
function formatDate(timestamp) {
	if (!timestamp) return 'Unknown'
	const date = new Date(timestamp * 1000)
	return date.toLocaleDateString() + ' ' + date.toLocaleTimeString()
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
	if (!text) return ''
	const div = document.createElement('div')
	div.textContent = text
	return div.innerHTML
}
