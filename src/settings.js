/**
 * Settings JavaScript for Hyper Viewer
 * Handles cache location configuration UI
 */

console.log('🎬 Hyper Viewer settings script loaded!')

document.addEventListener('DOMContentLoaded', function() {
	console.log('🔧 Hyper Viewer settings DOM ready')

	const settingsSection = document.getElementById('hyper_viewer_settings')
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

	// Load active jobs
	loadActiveJobs()
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
			console.error('❌ Error saving cache settings:', error)
			OC.Notification.showTemporary('Error saving cache locations', { type: 'error' })
		})
}

/**
 * Load and display active FFmpeg jobs
 */
function loadActiveJobs() {
	const container = document.getElementById('active-jobs-container')
	if (!container) {
		console.log('⚠️ Active jobs container not found')
		return
	}

	console.log('📊 Loading active jobs...')

	fetch(OC.generateUrl('/apps/hyperviewer/api/jobs/active'), {
		method: 'GET',
		headers: {
			requesttoken: OC.requestToken,
		},
	})
		.then(response => response.json())
		.then(data => {
			console.log('✅ Active jobs loaded:', data)
			displayActiveJobs(data.jobs || [])
		})
		.catch(error => {
			console.error('❌ Error loading active jobs:', error)
			container.innerHTML = '<p class="error">Failed to load active jobs</p>'
		})
}

/**
 * Display active jobs in a table
 */
function displayActiveJobs(jobs) {
	const container = document.getElementById('active-jobs-container')
	
	if (jobs.length === 0) {
		container.innerHTML = '<p class="empty">No active jobs</p>'
		return
	}

	const table = document.createElement('table')
	table.className = 'active-jobs-table'
	
	table.innerHTML = `
		<thead>
			<tr>
				<th>Filename</th>
				<th>Status</th>
				<th>Progress</th>
				<th>Speed</th>
				<th>Time</th>
			</tr>
		</thead>
		<tbody>
			${jobs.map(job => `
				<tr>
					<td class="filename">${escapeHtml(job.filename || 'Unknown')}</td>
					<td class="status">${escapeHtml(job.status || 'processing')}</td>
					<td class="progress">
						<div class="progress-bar">
							<div class="progress-fill" style="width: ${job.progress || 0}%"></div>
						</div>
						<span class="progress-text">${job.progress || 0}%</span>
					</td>
					<td class="speed">${escapeHtml(job.speed || '0x')}</td>
					<td class="time">${escapeHtml(job.time || '00:00:00')}</td>
				</tr>
			`).join('')}
		</tbody>
	`
	
	container.innerHTML = ''
	container.appendChild(table)
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
	const div = document.createElement('div')
	div.textContent = text
	return div.innerHTML
}
