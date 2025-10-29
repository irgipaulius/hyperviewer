/**
 * Settings JavaScript for Hyper Viewer
 * Handles cache location configuration UI and dashboard monitoring
 */

console.log('🎬 Hyper Viewer settings script loaded!')

let refreshInterval = null
let statsInterval = null

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

	// Add location button
	const addButton = document.getElementById('add-cache-location')
	if (addButton) {
		addButton.addEventListener('click', function() {
			console.log('➕ Adding new cache location')
			addCacheLocation()
		})
	}

	// Save button
	const saveButton = document.getElementById('save-cache-settings')
	if (saveButton) {
		saveButton.addEventListener('click', function() {
			console.log('💾 Saving cache settings')
			saveCacheSettings()
		})
	}

	// Remove location buttons
	document.addEventListener('click', function(e) {
		if (e.target.classList.contains('remove-location')) {
			console.log('🗑️ Removing cache location')
			e.target.closest('.cache-location-item').remove()
		}
	})
})

/**
 *
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
 *
 */
function saveCacheSettings() {
	const inputs = document.querySelectorAll('.cache-location-input')
	const locations = Array.from(inputs)
		.map(input => input.value.trim())
		.filter(value => value.length > 0)

	console.log('📤 Saving cache locations:', locations)

	// Make AJAX request to save settings
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
			console.error('❌ Failed to save cache locations:', error)
			OC.Notification.showTemporary('Failed to save cache locations')
		})
}

/**
 * Initialize dashboard monitoring
 */
function initializeDashboard() {
	console.log('📊 Initializing dashboard...')
	
	// Initial data load
	refreshStatistics()
	refreshActiveJobs()
	refreshAutoGeneration()
	
	// Set up auto-refresh every 10 seconds
	refreshInterval = setInterval(() => {
		refreshActiveJobs()
	}, 10000)
	
	statsInterval = setInterval(() => {
		refreshStatistics()
		refreshAutoGeneration()
	}, 10000)
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
			const stats = data.stats || {}
			document.getElementById('stat-active').textContent = stats.activeJobs || 0
			document.getElementById('stat-autogen').textContent = stats.autoGenDirectories || 0
			document.getElementById('stat-completed').textContent = stats.completedJobs || 0
			document.getElementById('stat-pending').textContent = stats.pendingJobs || 0
		})
		.catch(error => {
			console.error('❌ Failed to fetch statistics:', error)
		})
}

/**
 * Refresh active jobs
 */
function refreshActiveJobs() {
	fetch(OC.generateUrl('/apps/hyperviewer/api/jobs/active'), {
		headers: {
			requesttoken: OC.requestToken
		}
	})
		.then(response => response.json())
		.then(data => {
			const jobs = data.activeJobs || []
			const container = document.getElementById('active-jobs-container')
			
			if (jobs.length === 0) {
				container.innerHTML = '<p class="emptycontent-desc">No active jobs running</p>'
			} else {
				container.innerHTML = jobs.map(job => `
					<div class="job-card">
						<div class="job-header">
							<span class="job-filename">${escapeHtml(job.filename || 'Unknown')}</span>
							<span class="job-status">${escapeHtml(job.status || 'Processing')}</span>
						</div>
						<div class="job-progress">
							<progress value="${job.progress || 0}" max="100"></progress>
							<span style="font-size: 12px; margin-left: 8px;">${job.progress || 0}%</span>
						</div>
						<div class="job-details">
							<span>Time: ${escapeHtml(job.time || '00:00:00')}</span>
							<span>Frames: ${job.frame || 0}</span>
							<span>Speed: ${escapeHtml(job.speed || '0x')}</span>
							<span>FPS: ${escapeHtml(job.fps || '0')}</span>
							${job.cacheSize ? `<span>Size: ${escapeHtml(job.cacheSize)}</span>` : ''}
						</div>
						${job.resolutions && job.resolutions.length > 0
							? `
							<div class="job-resolutions">
								${job.resolutions.map(res => `<span class="resolution-tag">${escapeHtml(res)}</span>`).join('')}
							</div>
						`
							: ''}
					</div>
				`).join('')
			}
		})
		.catch(error => {
			console.error('❌ Failed to fetch active jobs:', error)
		})
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
