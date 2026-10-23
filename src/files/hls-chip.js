import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showSuccess } from '@nextcloud/dialogs'
import { rowVideoInfo } from './nodePaths.js'
import { resolveFileCacheStatus, subscribeCacheBatchUpdates } from './cache-batch-store.js'
import { currentFilesDirectory } from './files-router.js'
import { apiPost } from '../shared/api.js'

/** @type {WeakMap<Element, { fileid: number, badge: HTMLElement, basename: string, directory: string }>} */
const rowState = new WeakMap()

let observer = null
let scanScheduled = false

function findIconHost(row) {
	return (
		row.querySelector('.files-list__row-icon')
		|| row.querySelector('.files-list__row-icon-preview-container')
		|| row.querySelector('.thumbnail')
	)
}

function ensureRelativeHost(host) {
	if (host && getComputedStyle(host).position === 'static') {
		host.style.position = 'relative'
	}
}

function applyStatusToBadge(badge, fileid, status) {
	badge.className = 'hv-hls-icon-badge'
	badge.dataset.fileid = String(fileid)
	badge.style.pointerEvents = 'none'
	const job = status.job
	if (job && job.state === 'failed') {
		badge.classList.add('hv-failed')
		badge.textContent = '!'
		badge.title = status.job_error || t('hyperviewer', 'Failed — click to retry')
		badge.style.pointerEvents = 'auto'
		badge.hidden = false
		return
	}
	if (job && (job.state === 'queued' || job.state === 'running')) {
		badge.classList.add('hv-progress')
		if (job.state === 'running' && job.progress > 0) {
			badge.textContent = `${job.progress}%`
		} else if (status.queue_position) {
			badge.textContent = `#${status.queue_position}`
		} else {
			badge.textContent = 'HLS'
		}
		badge.title = t('hyperviewer', 'HLS encoding in progress')
		badge.hidden = false
		return
	}
	const state = status.cache_state ?? (status.exists ? 'ready' : 'none')
	if (state === 'ready' || state === 'partial') {
		badge.classList.add('hv-ready')
		badge.textContent = 'HLS'
		badge.title = t('hyperviewer', 'HLS cache ready')
		badge.hidden = false
		return
	}
	badge.hidden = true
}

async function refreshEntry(entry) {
	const { badge, basename, directory, fileid } = entry
	try {
		const status = await resolveFileCacheStatus(fileid, basename, directory)
		applyStatusToBadge(badge, fileid, status)
	} catch {
		badge.hidden = true
	}
}

function createChipElement(fileid, basename, directory) {
	const badge = document.createElement('span')
	badge.className = 'hv-hls-icon-badge'
	badge.setAttribute('role', 'status')
	badge.setAttribute('aria-live', 'polite')
	badge.hidden = true
	badge.style.pointerEvents = 'none'
	badge.addEventListener('click', async (ev) => {
		ev.stopPropagation()
		const status = await resolveFileCacheStatus(fileid, basename, directory)
		if (status.job && status.job.state === 'failed') {
			await apiPost(generateUrl(`/apps/hyperviewer/api/jobs/${status.job.id}/retry`))
			showSuccess(t('hyperviewer', 'Job re-queued'))
			refreshEntry({ badge, basename, directory, fileid })
		}
	})
	return badge
}

function syncBadgeFileid(badge, fileid) {
	if (badge.dataset.fileid !== String(fileid)) {
		badge.remove()
		return null
	}
	return badge
}

function processRow(row) {
	const info = rowVideoInfo(row)
	const fileid = info?.fileid
	if (!info) {
		const prev = rowState.get(row)
		if (prev) {
			prev.badge.remove()
			rowState.delete(row)
		}
		return
	}

	const host = findIconHost(row)
	if (!host) {
		return
	}
	ensureRelativeHost(host)

	const directory = currentFilesDirectory()
	const basename = info.basename
	let state = rowState.get(row)

	if (state) {
		const badge = syncBadgeFileid(state.badge, state.fileid)
		if (!badge || state.fileid !== fileid) {
			state.badge?.remove()
			state = undefined
		}
	}

	if (!state) {
		const badge = createChipElement(fileid, basename, directory)
		host.appendChild(badge)
		state = { fileid, badge, basename, directory }
		rowState.set(row, state)
	} else {
		state.basename = basename
		state.directory = directory
		if (state.badge.parentElement !== host) {
			host.appendChild(state.badge)
		}
		if (state.fileid !== fileid) {
			state.badge.remove()
			const badge = createChipElement(fileid, basename, directory)
			host.appendChild(badge)
			state = { fileid, badge, basename, directory }
			rowState.set(row, state)
		}
	}

	refreshEntry(state)
}

function scanVisibleRows() {
	const rows = document.querySelectorAll('[data-cy-files-list-row-fileid]')
	for (const row of rows) {
		processRow(row)
	}
}

function scheduleScan() {
	if (scanScheduled) {
		return
	}
	scanScheduled = true
	requestAnimationFrame(() => {
		scanScheduled = false
		scanVisibleRows()
	})
}

function findListRoot() {
	return (
		document.querySelector('.files-list')
		|| document.querySelector('[data-cy-files-list]')
		|| document.getElementById('app-content')
	)
}

function attachObserver() {
	const root = findListRoot()
	if (!root || observer) {
		return
	}
	observer = new MutationObserver(() => {
		scheduleScan()
	})
	observer.observe(root, { childList: true, subtree: true })
	scheduleScan()
}

function refreshFromBatch(rowsMap) {
	const rows = document.querySelectorAll('[data-cy-files-list-row-fileid]')
	for (const row of rows) {
		const state = rowState.get(row)
		if (!state) {
			continue
		}
		const fileid = Number(row.getAttribute('data-cy-files-list-row-fileid'))
		if (fileid !== state.fileid) {
			processRow(row)
			continue
		}
		const cached = rowsMap.get(state.fileid)
		if (cached) {
			applyStatusToBadge(state.badge, state.fileid, {
				exists: cached.hasCache || cached.cache_state === 'ready' || cached.cache_state === 'partial',
				cache_state: cached.cache_state,
				job: cached.job,
				job_error: cached.job_error,
				queue_position: cached.queue_position,
			})
		}
	}
}

/** DOM overlay for HLS status on file icons (list, grid, mobile). Not a file action. */
export function registerHlsIconChip() {
	subscribeCacheBatchUpdates(refreshFromBatch)

	const boot = () => {
		attachObserver()
		scheduleScan()
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot, { once: true })
	} else {
		boot()
	}

	const router = window.OCP?.Files?.Router
	if (router?.addOnChangeListener) {
		router.addOnChangeListener(() => scheduleScan())
	}
}
