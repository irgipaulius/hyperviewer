import {
	DefaultType,
	FileType,
	Permission,
	registerFileAction,
} from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { playVideoSmart } from '../modules/video-player.js'
import { fetchCacheStatus } from './hls-cache-status.js'
import { folderPath, isVideoMime } from './nodePaths.js'

const ICON_MULTIMEDIA = () =>
	'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M4 6h16v12H4V6m2 2v8h12V8H6m2 2h8v4H8v-4z"/></svg>'

const ICON_PLAY = () =>
	'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M8 5v14l11-7z"/></svg>'

function injectBadgeStyles() {
	if (document.getElementById('hyperviewer-hls-badge-style')) {
		return
	}
	const style = document.createElement('style')
	style.id = 'hyperviewer-hls-badge-style'
	style.textContent = `
		.hv-hls-chip-wrap {
			position: relative;
			display: inline-block;
			vertical-align: middle;
			margin-inline-start: 4px;
		}
		.hls-badge {
			position: absolute;
			right: -2px;
			bottom: -2px;
			z-index: 2;
			min-width: 22px;
			text-align: center;
			background: var(--color-warning, #e6a700);
			color: var(--color-main-background, #fff);
			padding: 1px 5px;
			border-radius: 8px;
			font-size: 9px;
			font-weight: 700;
			line-height: 1.3;
			pointer-events: auto;
			box-shadow: 0 0 0 1px var(--color-main-background);
		}
		.hls-badge-ready {
			background: var(--color-warning, #e6a700);
		}
		.hls-badge-partial {
			background: transparent;
			color: var(--color-warning-text, #a85d00);
			box-shadow: inset 0 0 0 1px var(--color-warning, #e6a700);
		}
		.hls-badge-failed {
			background: var(--color-error, #e9322d);
			color: var(--color-error-text, #fff);
		}
	`
	document.head.appendChild(style)
}

/**
 * @param {object} handlers
 * @param {Function} handlers.openCacheGenerationDialog
 * @param {Function} handlers.openDirectoryCacheGenerationDialog
 */
export function registerHyperViewerFileActions(handlers) {
	const { openCacheGenerationDialog, openDirectoryCacheGenerationDialog } = handlers

	injectBadgeStyles()

	registerFileAction({
		id: 'hyperviewer-play',
		displayName: () => t('hyperviewer', 'Play'),
		iconSvgInline: () => ICON_PLAY(),
		order: -50,
		default: DefaultType.DEFAULT,
		enabled: ({ nodes }) =>
			nodes.length === 1 && isVideoMime(nodes[0].mime),
		exec: async ({ nodes, folder, contents }) => {
			await playVideoSmart(nodes[0], folder, contents ?? [])
			return true
		},
	})

	registerFileAction({
		id: 'hyperviewer-generate-video',
		displayName: () => t('hyperviewer', 'Generate HLS Cache'),
		iconSvgInline: () => ICON_MULTIMEDIA(),
		enabled: ({ nodes }) =>
			nodes.length === 1
			&& isVideoMime(nodes[0].mime)
			&& (nodes[0].permissions & Permission.UPDATE) !== 0,
		exec: async ({ nodes, folder }) => {
			const node = nodes[0]
			const directory = folderPath(folder, node)
			openCacheGenerationDialog([
				{
					filename: node.basename,
					fileid: node.fileid,
					directory,
				},
			])
			return true
		},
	})

	registerFileAction({
		id: 'hyperviewer-generate-directory',
		displayName: () => t('hyperviewer', 'Generate HLS Cache (Directory)'),
		iconSvgInline: () => ICON_MULTIMEDIA(),
		enabled: ({ nodes }) =>
			nodes.length === 1
			&& nodes[0].type === FileType.Folder
			&& (nodes[0].permissions & Permission.UPDATE) !== 0,
		exec: async ({ nodes, folder }) => {
			await openDirectoryCacheGenerationDialog(nodes[0], folder)
			return true
		},
	})

	registerFileAction({
		id: 'hyperviewer-hls-chip',
		displayName: () => '',
		iconSvgInline: () => '',
		default: DefaultType.HIDDEN,
		enabled: ({ nodes }) =>
			nodes.length === 1 && isVideoMime(nodes[0].mime),
		inline: () => true,
		renderInline: async ({ nodes, folder }) => {
			const node = nodes[0]
			const directory = folderPath(folder, node)
			const status = await fetchCacheStatus(
				node.fileid,
				node.basename,
				directory,
			)
			const state = status.cache_state ?? (status.exists ? 'ready' : 'none')
			const wrap = document.createElement('span')
			wrap.className = 'hv-hls-chip-wrap'
			wrap.setAttribute('aria-hidden', 'true')

			const job = status.job
			if (job && job.state === 'failed') {
				const badge = document.createElement('span')
				badge.className = 'hls-badge hls-badge-failed'
				badge.textContent = '!'
				badge.title = status.job_error || t('hyperviewer', 'Encoding failed — retry from Hyper Viewer queue')
				wrap.appendChild(badge)
				return wrap
			}
			if (job && (job.state === 'queued' || job.state === 'running')) {
				const badge = document.createElement('span')
				badge.className = 'hls-badge hls-badge-partial'
				if (job.state === 'running' && job.progress > 0) {
					badge.textContent = `${job.progress}%`
					badge.title = t('hyperviewer', 'Encoding {pct}%', { pct: job.progress })
				} else {
					badge.textContent = '…'
					badge.title = t('hyperviewer', 'Queued (job #{id})', { id: job.id })
				}
				wrap.appendChild(badge)
				return wrap
			}
			if (state !== 'ready' && state !== 'partial') {
				return null
			}
			const badge = document.createElement('span')
			badge.className = 'hls-badge hls-badge-ready' + (state === 'partial' ? ' hls-badge-partial' : '')
			badge.textContent = 'HLS'
			badge.title = state === 'partial'
				? t('hyperviewer', 'HLS encoding in progress')
				: t('hyperviewer', 'HLS cache available')
			wrap.appendChild(badge)
			return wrap
		},
		exec: async () => null,
	})
}
