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
			const stats = data || {}
			
			// Handle both response formats (direct stats or wrapped in 'stats')
			const actualStats = stats.stats || stats

			document.getElementById('stat-active').textContent = actualStats.activeJobs || 0
			document.getElementById('stat-autogen').textContent = actualStats.autoGenDirectories || 0
			document.getElementById('stat-completed').textContent = actualStats.completedJobs || 0
			document.getElementById('stat-pending').textContent = actualStats.pendingJobs || 0
		})
		.catch(error => {
			console.error('❌ Failed to fetch statistics:', error)
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
