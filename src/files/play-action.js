/**
 * Default row-click handler for videos: Shaka when cached, else NC Viewer. Always returns true.
 *
 * @param {object} node
 * @param {object} folder
 * @param {object[]} contents
 * @param {{ playVideoSmart?: Function, openWithViewer?: Function }} [deps]
 */
export async function executeDefaultPlay(node, folder, contents, deps = {}) {
	let play = deps.playVideoSmart
	let openViewer = deps.openWithViewer
	if (!play || !openViewer) {
		const player = await import('../modules/video-player.js')
		play = play ?? player.playVideoSmart
		openViewer = openViewer ?? ((n, f) => player.openWithViewerForNode(n, f))
	}
	try {
		await play(node, folder, contents ?? [])
	} catch (error) {
		console.error('[hyperviewer] Play failed, falling back to Viewer', error)
		openViewer(node, folder)
	}
	return true
}
