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

/** @type {Map<number, { badge: HTMLElement, basename: string, directory: string }>} */
const chipsByFileid = new Map()

function injectStyles() {
	if (document.getElementById('hyperviewer-hls-chip-style')) {
		return
	}
	const style = document.createElement('style')
	style.id = 'hyperviewer-hls-chip-style'
	style.textContent = `
		.hv-hls-icon-badge {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			min-width: 28px;
			min-height: 22px;
			margin-inline-end: 4px;
			text-align: center;
			font-size: 9px;
			font-weight: 700;
			line-height: 1.2;
			padding: 2px 6px;
			border-radius: 8px;
			vertical-align: middle;
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
		@keyframes hv-hls-pulse {
			0%, 100% { opacity: 1; }
			50% { opacity: 0.55; }
		}
	`
	document.head.appendChild(style)
}

function applyStatusToBadge(badge, fileid, status) {
	badge.className = 'hv-hls-icon-badge'
	badge.dataset.fileid = String(fileid)
	const job = status.job
	if (job && job.state === 'failed') {
		badge.classList.add('hv-failed')
		badge.textContent = '!'
		badge.title = status.job_error || t('hyperviewer', 'Failed — click to retry')
		badge.hidden = false
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

async function refreshChip(entry) {
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
	badge.addEventListener('click', async (ev) => {
		ev.stopPropagation()
		const status = await resolveFileCacheStatus(fileid, basename, directory)
		if (status.job && status.job.state === 'failed') {
			await apiPost(generateUrl(`/apps/hyperviewer/api/jobs/${status.job.id}/retry`))
			showSuccess(t('hyperviewer', 'Job re-queued'))
		}
	})
	return badge
}

export function registerHlsIconChip() {
	injectStyles()

	subscribeCacheBatchUpdates(() => {
		for (const entry of chipsByFileid.values()) {
			refreshChip(entry)
		}
	})

	registerFileAction({
		id: 'hyperviewer-hls-icon-chip',
		displayName: () => t('hyperviewer', 'HLS status'),
		iconSvgInline: () => '',
		default: DefaultType.HIDDEN,
		order: -100,
		enabled: ({ nodes }) =>
			nodes.length === 1 && isVideoMime(nodes[0].mime),
		inline: () => true,
		renderInline: async ({ nodes, folder }) => {
			const node = nodes[0]
			const fileid = node.fileid ?? 0
			if (!fileid) {
				return null
			}
			const directory = folderPath(folder, node)
			const basename = node.basename
			let entry = chipsByFileid.get(fileid)
			if (!entry) {
				const badge = createChipElement(fileid, basename, directory)
				entry = { badge, basename, directory, fileid }
				chipsByFileid.set(fileid, entry)
			} else {
				entry.basename = basename
				entry.directory = directory
			}
			await refreshChip(entry)
			return entry.badge.hidden ? null : entry.badge
		},
		exec: async () => null,
	})
}
