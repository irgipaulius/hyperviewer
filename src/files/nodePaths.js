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

const VIDEO_EXTENSIONS = ['mp4', 'm4v', 'mov']

export function isVideoFilename(name) {
	const m = /\.([^./]+)$/.exec(String(name || '').toLowerCase())
	return !!m && VIDEO_EXTENSIONS.includes(m[1])
}

/**
 * Identify a video row in the NC 35 Files list/grid.
 * NC 35 puts fileid + basename as attributes on the row itself
 * (data-cy-files-list-row-fileid / data-cy-files-list-row-name="<basename>").
 * data-cy-files-list-row-mime is NOT a row attribute: it is an empty marker on the
 * optional mime column <td>, so it can never be used to read the mime type.
 */
export function rowVideoInfo(row) {
	const fileid = Number(row.getAttribute('data-cy-files-list-row-fileid'))
	const basename = row.getAttribute('data-cy-files-list-row-name') || ''
	if (!fileid || !isVideoFilename(basename)) {
		return null
	}
	return { fileid, basename }
}
