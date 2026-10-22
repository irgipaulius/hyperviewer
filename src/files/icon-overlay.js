import { generateUrl } from '@nextcloud/router'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { isVideoMime } from './nodePaths.js'
import { resolveFileCacheStatus } from './cache-batch-store.js'
import { apiPost } from '../shared/api.js'

const BADGE_CLASS = 'hv-icon-overlay-badge'
let observerStarted = false

function rowFileid(row) {
	const raw = row.dataset.fileId
		|| row.getAttribute('data-cy-files-list-row-fileid')
		|| row.querySelector('[data-fileid]')?.getAttribute('data-fileid')
	return raw ? parseInt(raw, 10) : 0
}

function rowMime(row) {
	return row.getAttribute('data-cy-files-list-row-mime')
		|| row.querySelector('[data-mime]')?.getAttribute('data-mime')
		|| ''
}

function findIconHost(row) {
	return row.querySelector('.files-list__row-icon')
		|| row.querySelector('.thumbnail')
		|| row.querySelector('.files-list__row-name .icon')
		|| row.querySelector('.files-list__row-name')
}

async function paintBadge(row) {
	const fileid = rowFileid(row)
	if (!fileid || !isVideoMime(rowMime(row))) {
		return
	}
	const host = findIconHost(row)
	if (!host) {
		return
	}
	if (host.querySelector(`.${BADGE_CLASS}`)) {
		return
	}
	host.style.position = host.style.position || 'relative'
	const badge = document.createElement('span')
	badge.className = BADGE_CLASS
	badge.textContent = '…'
	host.appendChild(badge)

	const nameEl = row.querySelector('.files-list__row-name-text, .files-list__row-name')
	const filename = nameEl?.textContent?.trim() || ''
	const directory = window.location.pathname.replace(/^.*\/apps\/files\/files\//, '/') || '/'
	try {
		const status = await resolveFileCacheStatus(fileid, filename, directory)
		const job = status.job
		if (job && job.state === 'failed') {
			badge.classList.add('hv-overlay-failed')
			badge.textContent = '!'
			badge.title = status.job_error || t('hyperviewer', 'Failed — click to retry')
			badge.style.pointerEvents = 'auto'
			badge.style.cursor = 'pointer'
			badge.addEventListener('click', async (ev) => {
				ev.stopPropagation()
				await apiPost(generateUrl(`/apps/hyperviewer/api/jobs/${job.id}/retry`))
				showSuccess(t('hyperviewer', 'Job re-queued'))
			})
			return
		}
		if (job && (job.state === 'queued' || job.state === 'running')) {
			badge.classList.add('hv-overlay-progress')
			badge.textContent = job.state === 'running' && job.progress > 0 ? `${job.progress}%` : '…'
			return
		}
		const state = status.cache_state ?? (status.exists ? 'ready' : 'none')
		if (state === 'ready' || state === 'partial') {
			badge.classList.add('hv-overlay-ready')
			badge.textContent = 'HLS'
			return
		}
		badge.remove()
	} catch {
		badge.remove()
	}
}

function scan(root = document.body) {
	const rows = root.querySelectorAll?.('[data-cy-files-list-row], tr[data-fileid], .files-list__row') || []
	rows.forEach(row => {
		paintBadge(row)
	})
}

export function startIconOverlayObserver() {
	if (observerStarted || typeof MutationObserver === 'undefined') {
		return
	}
	observerStarted = true
	scan()
	const obs = new MutationObserver(mutations => {
		for (const m of mutations) {
			m.addedNodes.forEach(node => {
				if (node.nodeType === 1) {
					scan(node)
				}
			})
		}
	})
	obs.observe(document.body, { childList: true, subtree: true })
	setInterval(() => scan(), 8000)
}
