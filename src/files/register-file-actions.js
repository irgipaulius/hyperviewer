import { registerFileAction, FileAction, DefaultType, FileType, Permission } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'

const ICON_MULTIMEDIA = () =>
	'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M4 6h16v12H4V6m2 2v8h12V8H6m2 2h8v4H8v-4z"/></svg>'

const ICON_PLAY = () =>
	'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M8 5v14l11-7z"/></svg>'

function isVideoNode(node) {
	return node.mime === 'video/mp4' || node.mime === 'video/quicktime'
}

function contextFromNode(node, dir) {
	const directory = dir || node.dirname || '/'
	return {
		fileInfoModel: { id: node.fileid },
		dir: directory.startsWith('/') ? directory : `/${directory}`,
	}
}

/**
 * @param {object} handlers
 * @param {Function} handlers.playVideoSmart
 * @param {Function} handlers.openCacheGenerationDialog
 * @param {Function} handlers.openDirectoryCacheGenerationDialog
 */
export function registerHyperViewerFileActions(handlers) {
	const { playVideoSmart, openCacheGenerationDialog, openDirectoryCacheGenerationDialog } = handlers

	const registerPlay = (id, mime) => {
		registerFileAction(
			new FileAction({
				id,
				displayName: () => t('hyperviewer', 'Play'),
				iconSvgInline: ICON_PLAY,
				order: -50,
				default: DefaultType.DEFAULT,
				enabled: nodes => nodes.length === 1 && nodes[0].mime === mime,
				exec: async (node, view, dir) => {
					const ctx = contextFromNode(node, dir)
					await playVideoSmart(node.basename, ctx.dir, ctx)
					return true
				},
			}),
		)
	}

	registerPlay('hyperviewer-play-mov', 'video/quicktime')
	registerPlay('hyperviewer-play-mp4', 'video/mp4')

	const registerGenerate = (id, mime) => {
		registerFileAction(
			new FileAction({
				id,
				displayName: () => t('hyperviewer', 'Generate HLS Cache'),
				iconSvgInline: ICON_MULTIMEDIA,
				enabled: nodes =>
					nodes.length === 1
					&& nodes[0].mime === mime
					&& (nodes[0].permissions & Permission.UPDATE) !== 0,
				exec: async (node, view, dir) => {
					const ctx = contextFromNode(node, dir)
					openCacheGenerationDialog([{ filename: node.basename, context: ctx, fileid: node.fileid }])
					return true
				},
			}),
		)
	}

	registerGenerate('hyperviewer-generate-mov', 'video/quicktime')
	registerGenerate('hyperviewer-generate-mp4', 'video/mp4')

	registerFileAction(
		new FileAction({
			id: 'hyperviewer-generate-directory',
			displayName: () => t('hyperviewer', 'Generate HLS Cache (Directory)'),
			iconSvgInline: ICON_MULTIMEDIA,
			enabled: nodes =>
				nodes.length === 1
				&& nodes[0].type === FileType.Folder
				&& (nodes[0].permissions & Permission.UPDATE) !== 0,
			exec: async (node, view, dir) => {
				const ctx = contextFromNode(node, dir)
				await openDirectoryCacheGenerationDialog(node.basename, ctx)
				return true
			},
		}),
	)

	registerFileAction(
		new FileAction({
			id: 'hyperviewer-hls-badge',
			displayName: () => '',
			iconSvgInline: () => '',
			default: DefaultType.HIDDEN,
			enabled: nodes => nodes.length === 1 && isVideoNode(nodes[0]),
			inline: () => true,
			renderInline: async node => {
				const badge = document.createElement('div')
				badge.className = 'hls-badge hls-badge-pending'
				badge.textContent = '…'
				badge.title = 'Checking HLS cache…'
				badge.dataset.fileid = String(node.fileid ?? '')
				badge.dataset.basename = node.basename
				return badge
			},
			exec: async () => null,
		}),
	)
}
