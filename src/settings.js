/**
 * Settings JavaScript for HyperViewer
 * Handles cache location configuration and FFmpeg job monitoring
 */

console.log('🎬 HyperViewer settings script loaded!')

let activeJobs = []
let autoGenDirs = []
let refreshInterval = null
let jobPollingInterval = null

document.addEventListener('DOMContentLoaded', function() {
	console.log('🔧 HyperViewer settings DOM ready')

	const settingsSection = document.getElementById('hyper_viewer_settings')
	if (!settingsSection) {
		console.log('⚠️ HyperViewer settings section not found')
		return
	}

	console.log('✅ HyperViewer settings section found')

	// Initialize dashboard sections
	initializeDashboard()

	// Add location button
	const addButton = document.getElementById('add-cache-location')
	if (addButton) {
		addButton.addEventListener('click', addCacheLocation)
	}

	// Save button
	const saveButton = document.getElementById('save-cache-settings')
	if (saveButton) {
		saveButton.addEventListener('click', saveCacheSettings)
	}

	// Remove location buttons
	document.addEventListener('click', function(e) {
		if (e.target.classList.contains('remove-location')) {
			console.log('🗑️ Removing cache location')
			e.target.closest('.cache-location-item').remove()
		}
		
		// Remove auto-gen directory
		if (e.target.classList.contains('remove-autogen')) {
			const configKey = e.target.dataset.configKey
			removeAutoGeneration(configKey)
		}
	})

	// Start auto-refresh
	refreshDashboardData()
	refreshInterval = setInterval(refreshDashboardData, 10000) // Every 10 seconds
})

/**
 * Initialize dashboard sections
 */
function initializeDashboard() {
	const settingsSection = document.getElementById('hyper_viewer_settings')
	
	// Add dashboard HTML after cache settings
	const dashboardHTML = `
		<div class="section">
			<h3>🔥 Active FFmpeg Jobs</h3>
			<div id="active-jobs-list" class="active-jobs-list">
				<p class="empty-state">No active jobs</p>
			</div>
		</div>
		
		<div class="section">
			<h3>🤖 Auto-Generation Directories</h3>
			<div id="autogen-dirs-list" class="autogen-dirs-list">
				<p class="empty-state">No auto-generation directories configured</p>
			</div>
		</div>
	`
	
	settingsSection.insertAdjacentHTML('beforeend', dashboardHTML)
}

/**
 * Refresh dashboard data
 */
async function refreshDashboardData() {
	await Promise.all([
		refreshActiveJobs(),
		refreshAutoGenDirs()
	])
}

/**
 * Refresh active jobs list
 */
async function refreshActiveJobs() {
	try {
		const response = await fetch(OC.generateUrl('/apps/hyperviewer/cache/progress/all'), {
			headers: { requesttoken: OC.requestToken }
		})
		
		if (!response.ok) {
			// No endpoint yet, use placeholder
			renderActiveJobs([])
			return
		}
		
		const data = await response.json()
		activeJobs = data.jobs || []
		renderActiveJobs(activeJobs)
		
		// Poll for progress if there are active jobs
		if (activeJobs.length > 0 && !jobPollingInterval) {
			jobPollingInterval = setInterval(pollJobProgress, 5000)
		} else if (activeJobs.length === 0 && jobPollingInterval) {
			clearInterval(jobPollingInterval)
			jobPollingInterval = null
		}
		
	} catch (error) {
		console.error('Failed to refresh active jobs:', error)
		renderActiveJobs([])
	}
}

/**
 * Poll individual job progress
 */
async function pollJobProgress() {
	for (const job of activeJobs) {
		try {
			const response = await fetch(
				OC.generateUrl(`/apps/hyperviewer/cache/progress/${encodeURIComponent(job.cachePath)}`),
				{ headers: { requesttoken: OC.requestToken } }
			)
			
			if (response.ok) {
				const progress = await response.json()
				updateJobProgress(job.filename, progress)
			}
		} catch (error) {
			console.error(`Failed to poll progress for ${job.filename}:`, error)
		}
	}
}

/**
 * Update job progress in UI
 */
function updateJobProgress(filename, progress) {
	const jobElement = document.querySelector(`[data-filename="${filename}"]`)
	if (!jobElement) return
	
	const progressBar = jobElement.querySelector('.progress-bar-fill')
	const progressText = jobElement.querySelector('.progress-text')
	const statusText = jobElement.querySelector('.job-status')
	
	if (progressBar) progressBar.style.width = `${progress.progress || 0}%`
	if (progressText) progressText.textContent = `${progress.progress || 0}%`
	if (statusText) statusText.textContent = progress.status || 'Processing'
	
	// Update stats if available
	const stats = jobElement.querySelector('.job-stats')
	if (stats && progress.speed) {
		stats.innerHTML = `
			<span>⚡ ${progress.speed}</span>
			<span>🎬 ${progress.fps || 0} fps</span>
			<span>⏱️ ${progress.time || '00:00:00'}</span>
		`
	}
}

/**
 * Render active jobs list
 */
function renderActiveJobs(jobs) {
	const container = document.getElementById('active-jobs-list')
	if (!container) return
	
	if (jobs.length === 0) {
		container.innerHTML = '<p class="empty-state">No active jobs</p>'
		return
	}
	
	container.innerHTML = jobs.map(job => `
		<div class="job-item" data-filename="${job.filename}">
			<div class="job-header">
				<strong>${job.filename}</strong>
				<span class="job-status">${job.status || 'Processing'}</span>
			</div>
			<div class="job-progress">
				<div class="progress-bar">
					<div class="progress-bar-fill" style="width: ${job.progress || 0}%"></div>
				</div>
				<span class="progress-text">${job.progress || 0}%</span>
			</div>
			<div class="job-stats">
				${job.speed ? `<span>⚡ ${job.speed}</span>` : ''}
				${job.fps ? `<span>🎬 ${job.fps} fps</span>` : ''}
				${job.time ? `<span>⏱️ ${job.time}</span>` : ''}
			</div>
		</div>
	`).join('')
}

/**
 * Refresh auto-generation directories
 */
async function refreshAutoGenDirs() {
	try {
		const response = await fetch(OC.generateUrl('/apps/hyperviewer/api/auto-generation'), {
			headers: { requesttoken: OC.requestToken }
		})
		
		if (!response.ok) {
			renderAutoGenDirs([])
			return
		}
		
		const data = await response.json()
		autoGenDirs = data.autoGenDirs || []
		renderAutoGenDirs(autoGenDirs)
		
	} catch (error) {
		console.error('Failed to refresh auto-gen dirs:', error)
		renderAutoGenDirs([])
	}
}

/**
 * Render auto-generation directories
 */
function renderAutoGenDirs(dirs) {
	const container = document.getElementById('autogen-dirs-list')
	if (!container) return
	
	if (dirs.length === 0) {
		container.innerHTML = '<p class="empty-state">No auto-generation directories configured</p>'
		return
	}
	
	container.innerHTML = dirs.map(dir => `
		<div class="autogen-item">
			<div class="autogen-header">
				<strong>📁 ${dir.directory}</strong>
				<span class="autogen-status ${dir.enabled ? 'enabled' : 'disabled'}">
					${dir.enabled ? 'Enabled' : 'Disabled'}
				</span>
			</div>
			<div class="autogen-details">
				<span>📍 ${dir.cacheLocation}</span>
				<span>📅 ${new Date(dir.registeredAt * 1000).toLocaleDateString()}</span>
			</div>
			<div class="autogen-resolutions">
				${(dir.resolutions || []).map(res => `<span class="resolution-tag">${res}</span>`).join('')}
			</div>
			<button class="button remove-autogen" data-config-key="${dir.configKey}">
				🗑️ Remove
			</button>
		</div>
	`).join('')
}

/**
 * Remove auto-generation directory
 */
async function removeAutoGeneration(configKey) {
	if (!confirm('Remove this auto-generation directory?')) return
	
	try {
		const response = await fetch(
			OC.generateUrl(`/apps/hyperviewer/api/auto-generation/${configKey}`),
			{
				method: 'DELETE',
				headers: { requesttoken: OC.requestToken }
			}
		)
		
		if (response.ok) {
			OC.Notification.showTemporary('Auto-generation directory removed')
			refreshAutoGenDirs()
		} else {
			throw new Error('Failed to remove')
		}
	} catch (error) {
		console.error('Failed to remove auto-gen dir:', error)
		OC.Notification.showTemporary('Failed to remove auto-generation directory', { type: 'error' })
	}
}

/**
 * Add cache location
 */
function addCacheLocation() {
	const list = document.getElementById('cache-location-list')
	const newIndex = list.children.length

	const newItem = document.createElement('div')
	newItem.className = 'cache-location-item'
	newItem.setAttribute('data-index', newIndex)

	newItem.innerHTML = `
		<input type="text" 
			   class="cache-location-input" 
			   value="" 
			   placeholder="Enter cache path..." />
		<button class="icon-delete remove-location" title="Remove"></button>
	`

	list.appendChild(newItem)
	console.log('✅ Added new cache location input')
}

/**
 * Save cache settings
 */
function saveCacheSettings() {
	const inputs = document.querySelectorAll('.cache-location-input')
	const locations = Array.from(inputs)
		.map(input => input.value.trim())
		.filter(value => value.length > 0)

	console.log('📤 Saving cache locations:', locations)

	fetch(OC.generateUrl('/apps/hyperviewer/settings/cache-locations'), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
		},
		body: JSON.stringify({ locations }),
	})
		.then(response => response.json())
		.then(data => {
			console.log('✅ Cache settings saved successfully:', data)
			OC.Notification.showTemporary('Cache locations saved successfully')
		})
		.catch(error => {
			console.error('❌ Error saving cache settings:', error)
			OC.Notification.showTemporary('Error saving cache locations', { type: 'error' })
		})
}

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
	if (refreshInterval) clearInterval(refreshInterval)
	if (jobPollingInterval) clearInterval(jobPollingInterval)
})
