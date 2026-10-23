#!/usr/bin/env node
// Regression: 2.1.1–2.1.3 read the mime from a row attribute that NC 35 never sets,
// so no row was ever treated as a video and no HLS chip was shown.
import { rowVideoInfo, isVideoFilename } from '../src/files/nodePaths.js'

let failed = false
const fail = (m) => { console.error(m); failed = true }

// Row exactly as NC 35.0.2 renders it (see fixtures/nc35-files-row.txt)
function nc35Row(fileid, basename) {
	const attrs = {
		'data-cy-files-list-row': '',
		'data-cy-files-list-row-fileid': String(fileid),
		'data-cy-files-list-row-name': basename,
	}
	return { getAttribute: (k) => (k in attrs ? attrs[k] : null), querySelector: () => null }
}

const v = rowVideoInfo(nc35Row(123, 'Clip.MP4'))
if (!v || v.fileid !== 123 || v.basename !== 'Clip.MP4') fail('NC 35 mp4 row must be detected as video')
if (!rowVideoInfo(nc35Row(5, 'a.mov'))) fail('.mov row must be detected')
if (rowVideoInfo(nc35Row(6, 'notes.txt'))) fail('txt row must not be detected')
if (rowVideoInfo(nc35Row(0, 'a.mp4'))) fail('row without fileid must be ignored')
if (isVideoFilename('mp4')) fail('bare "mp4" is not a video filename')
if (failed) process.exit(1)
console.log('OK: NC 35 row detection')
