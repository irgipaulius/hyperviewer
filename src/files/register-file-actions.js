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
		.hls-badge {
			display: inline-block;
			margin-inline-start: 6px;
			background: rgba(60, 60, 60, 0.95);
			color: #FF9800;
			padding: 2px 6px;
			border-radius: 3px;
			font-size: 9px;
			font-weight: bold;
			letter-spacing: 0.5px;
			line-height: 1.2;
			vertical-align: middle;
			pointer-events: none;
		}
		.hls-badge-partial { color: #FFC107; }
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
			if (state !== 'ready' && state !== 'partial') {
				return null
			}
			const badge = document.createElement('span')
			badge.className = 'hls-badge' + (state === 'partial' ? ' hls-badge-partial' : '')
			badge.textContent = state === 'partial' ? 'HLS…' : 'HLS'
			badge.title = state === 'partial'
				? t('hyperviewer', 'HLS encoding in progress')
				: t('hyperviewer', 'HLS cache available')
			return badge
		},
		exec: async () => null,
	})
}
