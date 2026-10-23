/**
 * Parse discover-videos API response (server contract for 2.1.8+).
 *
 * @param {object} result
 * @returns {{ success: boolean, count: number, files: object[] }}
 */
export function parseDiscoverResponse(result) {
	if (!result || result.success !== true) {
		return { success: false, count: 0, files: [] }
	}
	const files = Array.isArray(result.files) ? result.files : []
	const count = typeof result.count === 'number' ? result.count : files.length
	return { success: true, count, files }
}

/**
 * @param {object} file
 * @returns {string} directory path for cache/generate API
 */
export function directoryForDiscoveredFile(file) {
	if (file.directory && typeof file.directory === 'string') {
		return file.directory
	}
	if (file.path && typeof file.path === 'string') {
		const p = file.path.replace(/\\/g, '/')
		const idx = p.lastIndexOf('/')
		if (idx <= 0) {
			return '/'
		}
		return p.slice(0, idx) || '/'
	}
	return '/'
}

/**
 * HTML snippet for directory dialog "Video files found" line (for tests + dialog).
 *
 * @param {number} count
 * @param {string} directoryPath
 * @param {string[]} basenames
 */
/**
 * @param {object[]} files
 * @param {string|null} directoryPath
 */
export function buildCacheGenerationFilesPayload(files, directoryPath = null) {
	return files.map((file) => ({
		filename: file.filename,
		fileid: file.fileid || file.context?.fileInfoModel?.id || 0,
		directory:
			file.directory
			|| directoryForDiscoveredFile(file)
			|| file.context?.dir
			|| directoryPath
			|| '/',
	}))
}

export function buildDirectoryVideoCountHtml(count, directoryPath, basenames = []) {
	const fileList = basenames.length > 0 ? basenames.join(', ') : ''
	const countClass = count === 0 ? 'no-videos' : ''
	return `
			<div class="directory-info-card">
				<div class="info-item">
					<strong>Directory:</strong> ${directoryPath}
				</div>
				<div class="info-item ${countClass}">
					<strong>Video files found:</strong> ${count}
					${
						count > 0
							? `<div class="file-list">${fileList}</div>`
							: '<div class="no-videos-text">No videos currently, but auto-generation can monitor for new files</div>'
					}
				</div>
			</div>
		`
}
