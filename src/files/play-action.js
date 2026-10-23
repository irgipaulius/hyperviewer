import { viewerPath } from './nodePaths.js'

/** @type {() => Promise<typeof import('../modules/video-player.js')>} */
export const defaultLoadPlayer = () => import('../modules/video-player.js')

function openViewerFallback(node, folder) {
	const path = viewerPath(node, folder)
	if (window.OCA?.Viewer) {
		window.OCA.Viewer.open({ path })
	} else {
		console.error('[hyperviewer] Viewer not available for path:', path)
	}
}

/**
 * Default row-click handler for videos: Shaka when cached, else NC Viewer. Always returns true.
 *
 * @param {object} node
 * @param {object} folder
 * @param {object[]} contents
 * @param {{ playVideoSmart?: Function, openWithViewer?: Function, loadPlayer?: typeof defaultLoadPlayer }} [deps]
 */
export async function executeDefaultPlay(node, folder, contents, deps = {}) {
	const openViewer = deps.openWithViewer ?? ((n, f) => openViewerFallback(n, f))
	try {
		let play = deps.playVideoSmart
		if (!play) {
			const loadPlayer = deps.loadPlayer ?? defaultLoadPlayer
			const player = await loadPlayer()
			play = player.playVideoSmart
		}
		await play(node, folder, contents ?? [])
	} catch (error) {
		console.error('[hyperviewer] Play failed, falling back to Viewer', error)
		openViewer(node, folder)
	}
	return true
}

/**
 * @param {object} node
 * @param {object} folder
 * @param {object[]} contents
 * @param {Parameters<typeof executeDefaultPlay>[3]} [deps]
 */
export async function executeClipPlay(node, folder, contents, deps = {}) {
	try {
		const loadPlayer = deps.loadPlayer ?? defaultLoadPlayer
		const player = await loadPlayer()
		await player.playVideoSmart(node, folder, contents ?? [], { clipMode: true })
	} catch (error) {
		console.error('[hyperviewer] Clip player failed, falling back to default play', error)
		await executeDefaultPlay(node, folder, contents, deps)
	}
	return true
}
