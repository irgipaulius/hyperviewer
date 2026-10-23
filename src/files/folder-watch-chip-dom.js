/**
 * Watched-folder HLS marker on folder rows (DOM only — safe for Node smoke tests).
 *
 * @param {Element} row
 * @param {Set<number>} watchedFolderFileids
 * @returns {boolean} true if row was handled as a watch folder
 */
export function applyWatchFolderChipToRow(row, watchedFolderFileids) {
	const fileid = Number(row.getAttribute('data-cy-files-list-row-fileid'))
	if (!watchedFolderFileids.has(fileid)) {
		return false
	}
	const host =
		row.querySelector('.files-list__row-icon')
		|| row.querySelector('.files-list__row-icon-preview-container')
		|| row.querySelector('.thumbnail')
	if (!host) {
		return false
	}
	if (getComputedStyle(host).position === 'static') {
		host.style.position = 'relative'
	}
	let badge = host.querySelector('.hv-hls-icon-badge.hv-watch-folder')
	if (!badge) {
		badge = document.createElement('span')
		badge.className = 'hv-hls-icon-badge hv-watch-folder'
		badge.setAttribute('role', 'status')
		host.appendChild(badge)
	}
	badge.dataset.fileid = String(fileid)
	badge.textContent = 'HLS'
	badge.style.pointerEvents = 'none'
	badge.hidden = false
	return true
}
