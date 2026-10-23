import {
	DefaultType,
	registerFileAction,
} from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showSuccess } from '@nextcloud/dialogs'
import { folderPath, isVideoMime } from './nodePaths.js'
import { resolveFileCacheStatus, subscribeCacheBatchUpdates } from './cache-batch-store.js'
import { apiPost } from '../shared/api.js'

/** @type {Map<number, HTMLElement>} */
const badgesByFileid = new Map()

function injectStyles() {
	if (document.getElementById('hyperviewer-hls-chip-style')) {
		return
	}
	const style = document.createElement('style')
	style.id = 'hyperviewer-hls-chip-style'
	style.textContent = `
		.hv-hls-icon-badge {
			position: absolute;
			right: 0;
			bottom: 0;
			z-index: 4;
			min-width: 22px;
			text-align: center;
			font-size: 8px;
			font-weight: 700;
			line-height: 1.3;
			padding: 1px 4px;
			border-radius: 6px;
			pointer-events: auto;
			box-shadow: 0 0 0 1px var(--color-main-background);
		}
		.hv-hls-icon-badge.hv-ready {
			background: var(--color-warning, #e6a700);
			color: var(--color-main-text, #fff);
		}
		.hv-hls-icon-badge.hv-progress {
			background: transparent;
			color: var(--color-warning-text, #a85d00);
			box-shadow: inset 0 0 0 1px var(--color-warning, #e6a700);
			animation: hv-hls-pulse 1.2s ease-in-out infinite;
		}
		.hv-hls-icon-badge.hv-failed {
			background: var(--color-error, #e9322d);
			color: var(--color-error-text, #fff);
		}
		.files-list__row-icon,
		.files-list__row-icon-preview-container {
			position: relative;
		}
		@keyframes hv-hls-pulse {
			0%, 100% { opacity: 1; }
			50% { opacity: 0.55; }
		}
	`
	document.head.appendChild(style)
}

function currentFilesDirectory() {
	return window.location.pathname.replace(/^.*\/apps\/files\/files\//, '/') || '/'
}

function findRowForFileid(fileid) {
	return document.querySelector(`[data-cy-files-list-row-fileid="${fileid}"]`)
		|| document.querySelector(`[data-fileid="${fileid}"]`)
}

function rowMime(row) {
	return row.getAttribute('data-cy-files-list-row-mime')
		|| row.querySelector('[data-mime]')?.getAttribute('data-mime')
		|| ''
}

function mountBadgeOnIcon(fileid, badge) {
	const row = findRowForFileid(fileid)
	const icon = row?.querySelector('.files-list__row-icon')
	if (!icon) {
		return false
	}
	icon.style.position = icon.style.position || 'relative'
	const existing = icon.querySelector('.hv-hls-icon-badge')
	if (existing) {
		existing.replaceWith(badge)
	} else {
		icon.appendChild(badge)
	}
	badgesByFileid.set(fileid, badge)
	return true
}

function applyStatusToBadge(badge, fileid, status) {
	badge.className = 'hv-hls-icon-badge'
	badge.dataset.fileid = String(fileid)
	const job = status.job
	if (job && job.state === 'failed') {
		badge.classList.add('hv-failed')
		badge.textContent = '!'
		badge.title = status.job_error || t('hyperviewer', 'Failed — click to retry')
		return
	}
	if (job && (job.state === 'queued' || job.state === 'running')) {
		badge.classList.add('hv-progress')
		const pos = status.queue_position
		if (job.state === 'running' && job.progress > 0) {
			badge.textContent = `${job.progress}%`
		} else if (pos) {
			badge.textContent = `#${pos}`
		} else {
			badge.textContent = '…'
		}
		badge.title = t('hyperviewer', 'HLS encoding in progress')
		return
	}
	const state = status.cache_state ?? (status.exists ? 'ready' : 'none')
	if (state === 'ready' || state === 'partial') {
		badge.classList.add('hv-ready')
		badge.textContent = 'HLS'
		badge.title = t('hyperviewer', 'HLS cache ready')
		return
	}
	badge.remove()
	badgesByFileid.delete(fileid)
}

async function refreshBadge(fileid, filename, directory) {
	let badge = badgesByFileid.get(fileid)
	if (!badge) {
		badge = document.createElement('span')
		badge.className = 'hv-hls-icon-badge'
		badge.setAttribute('role', 'status')
		badge.addEventListener('click', async (ev) => {
			ev.stopPropagation()
			const status = await resolveFileCacheStatus(fileid, filename, directory)
			if (status.job && status.job.state === 'failed') {
				await apiPost(generateUrl(`/apps/hyperviewer/api/jobs/${status.job.id}/retry`))
				showSuccess(t('hyperviewer', 'Job re-queued'))
			}
		})
		if (!mountBadgeOnIcon(fileid, badge)) {
			return
		}
	}
	try {
		const status = await resolveFileCacheStatus(fileid, filename, directory)
		applyStatusToBadge(badge, fileid, status)
	} catch {
		badge.remove()
		badgesByFileid.delete(fileid)
	}
}

export function scanVisibleVideoRows() {
	const directory = currentFilesDirectory()
	const rows = document.querySelectorAll('[data-cy-files-list-row-fileid], [data-fileid]')
	rows.forEach(row => {
		const raw = row.getAttribute('data-cy-files-list-row-fileid')
			|| row.getAttribute('data-fileid')
		const fileid = raw ? parseInt(raw, 10) : 0
		if (!fileid || !isVideoMime(rowMime(row))) {
			return
		}
		const nameEl = row.querySelector('.files-list__row-name-text, .files-list__row-name')
		const filename = nameEl?.textContent?.trim() || ''
		refreshBadge(fileid, filename, directory)
	})
}

export function registerHlsIconChip() {
	injectStyles()
	subscribeCacheBatchUpdates(() => scanVisibleVideoRows())
	setInterval(() => scanVisibleVideoRows(), 4000)

	registerFileAction({
		id: 'hyperviewer-hls-icon-chip',
		displayName: () => t('hyperviewer', 'HLS status'),
		iconSvgInline: () => '',
		default: DefaultType.HIDDEN,
		order: -100,
		enabled: ({ nodes }) =>
			nodes.length === 1 && isVideoMime(nodes[0].mime),
		renderInline: async ({ nodes, folder }) => {
			const node = nodes[0]
			const fileid = node.fileid ?? 0
			if (!fileid) {
				return null
			}
			await refreshBadge(fileid, node.basename, folderPath(folder, node))
			return null
		},
		exec: async () => null,
	})

	scanVisibleVideoRows()
}
