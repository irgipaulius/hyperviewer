import {
	DefaultType,
	FileType,
	Permission,
	registerFileAction,
} from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showSuccess } from '@nextcloud/dialogs'
import { executeDefaultPlay } from './play-action.js'
import { resolveFileCacheStatus } from './cache-batch-store.js'
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
	const {
		openCacheGenerationDialog,
		openDirectoryCacheGenerationDialog,
		caps = {},
	} = handlers
	const canGenerateHls = caps.can_generate_hls !== false
	const canExportClip = caps.can_export_clip !== false

	injectBadgeStyles()

	registerFileAction({
		id: 'hyperviewer-play',
		displayName: () => t('hyperviewer', 'Play'),
		iconSvgInline: () => ICON_PLAY(),
		order: -50,
		default: DefaultType.DEFAULT,
		enabled: ({ nodes }) =>
			nodes.length === 1 && isVideoMime(nodes[0].mime),
		exec: async ({ nodes, folder, contents }) =>
			executeDefaultPlay(nodes[0], folder, contents ?? []),
	})

	registerFileAction({
		id: 'hyperviewer-generate-video',
		displayName: () => t('hyperviewer', 'Generate HLS Cache'),
		iconSvgInline: () => ICON_MULTIMEDIA(),
		enabled: ({ nodes }) =>
			canGenerateHls
			&& nodes.length === 1
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
			canGenerateHls
			&& nodes.length === 1
			&& nodes[0].type === FileType.Folder
			&& (nodes[0].permissions & Permission.UPDATE) !== 0,
		exec: async ({ nodes, folder }) => {
			await openDirectoryCacheGenerationDialog(nodes[0], folder)
			return true
		},
	})

	registerFileAction({
		id: 'hyperviewer-open-settings',
		displayName: () => t('hyperviewer', 'Show in Hyper Viewer'),
		iconSvgInline: () => ICON_MULTIMEDIA(),
		enabled: ({ nodes }) =>
			nodes.length === 1 && isVideoMime(nodes[0].mime),
		exec: async ({ nodes }) => {
			if (window.sessionStorage) {
				window.sessionStorage.setItem('hyperviewer_filter_fileid', String(nodes[0].fileid))
				window.sessionStorage.setItem('hyperviewer_open_tab', 'library')
			}
			window.location.href = generateUrl('/settings/user/hyperviewer')
			return true
		},
	})

	registerFileAction({
		id: 'hyperviewer-export-clip',
		displayName: () => t('hyperviewer', 'Export clip'),
		iconSvgInline: () => ICON_MULTIMEDIA(),
		enabled: ({ nodes }) =>
			canExportClip
			&& nodes.length === 1 && isVideoMime(nodes[0].mime),
		exec: async ({ nodes, folder, contents }) => {
			const { playVideoSmart } = await import('../modules/video-player.js')
			await playVideoSmart(nodes[0], folder, contents ?? [], { clipMode: true })
			return true
		},
	})

	registerFileAction({
		id: 'hyperviewer-copy-stream',
		displayName: () => t('hyperviewer', 'Copy HLS stream link'),
		iconSvgInline: () => ICON_MULTIMEDIA(),
		enabled: ({ nodes }) =>
			nodes.length === 1 && isVideoMime(nodes[0].mime),
		exec: async ({ nodes, folder }) => {
			const directory = folderPath(folder, nodes[0])
			const status = await resolveFileCacheStatus(nodes[0].fileid, nodes[0].basename, directory)
			const fp = status.fingerprint
			if (!fp) {
				return false
			}
			const url = `${window.location.origin}${generateUrl(`/apps/hyperviewer/hls/${encodeURIComponent(fp)}/master.m3u8`)}`
			await navigator.clipboard.writeText(url)
			showSuccess(t('hyperviewer', 'Stream URL copied (session auth required)'))
			return true
		},
	})
}
