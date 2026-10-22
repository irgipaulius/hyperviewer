/**
 * Path helpers for @nextcloud/files v4 nodes (viewer + API directory fields).
 */

export function folderPath(folder, node) {
	let p = folder?.path ?? node?.dirname ?? '/'
	if (typeof p !== 'string' || p === '') {
		p = '/'
	}
	if (!p.startsWith('/')) {
		p = `/${p}`
	}
	p = p.replace(/\/+$/, '')
	return p === '' ? '/' : p
}

export function viewerPath(node, folder) {
	const dir = folderPath(folder, node)
	return dir === '/' ? `/${node.basename}` : `${dir}/${node.basename}`
}

export function isVideoMime(mime) {
	return mime === 'video/mp4' || mime === 'video/quicktime'
}
