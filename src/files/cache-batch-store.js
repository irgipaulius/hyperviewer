/** @typedef {{ fileid: number, filename: string, directory: string, cache_state?: string, hasCache?: boolean, job?: object, job_error?: string, queue_position?: number }} RowStatus */

const byFileid = new Map()
/** @type {Set<(rows: Map<number, RowStatus>) => void>} */
const listeners = new Set()
/** @type {Map<string, { fileid: number, filename: string, directory: string }>} */
const pending = new Map()
let flushPromise = null
let pollTimer = null

function cacheKey(fileid, filename, directory) {
	return `${fileid || 0}:${directory}:${filename}`
}

function schedulePoll() {
	if (pollTimer) {
		return
	}
	pollTimer = setInterval(() => {
		for (const [fid, prev] of byFileid.entries()) {
			pending.set(cacheKey(fid, prev.filename || '', prev.directory || '/'), {
				fileid: fid,
				filename: prev.filename || '',
				directory: prev.directory || '/',
			})
		}
		if (pending.size > 0) {
			runFlush().catch(() => {})
		}
	}, 5000)
}

async function runFlush() {
	if (pending.size === 0) {
		return
	}
	const batch = [...pending.values()]
	pending.clear()
	const response = await fetch(
		OC.generateUrl('/apps/hyperviewer/cache/batch-check'),
		{
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
			},
			body: JSON.stringify({
				directory: batch[0].directory,
				files: batch,
			}),
		},
	)
	const ct = response.headers.get('Content-Type') || ''
	if (!response.ok || !ct.includes('application/json')) {
		return
	}
	const data = await response.json()
	for (const row of data.results || []) {
		if (row.fileid) {
			byFileid.set(row.fileid, row)
		}
	}
	notifyListeners()
}

function notifyListeners() {
	for (const fn of listeners) {
		try {
			fn(byFileid)
		} catch {
			// ignore
		}
	}
}

/** @param {(rows: Map<number, RowStatus>) => void} fn */
export function subscribeCacheBatchUpdates(fn) {
	listeners.add(fn)
	return () => listeners.delete(fn)
}

function enqueueFlush() {
	if (!flushPromise) {
		flushPromise = new Promise(resolve => {
			setTimeout(async () => {
				try {
					await runFlush()
				} finally {
					flushPromise = null
					resolve()
				}
			}, 40)
		})
	}
	return flushPromise
}

/**
 * @param {number|undefined} fileid
 * @param {string} filename
 * @param {string} directory
 * @returns {Promise<RowStatus>}
 */
export async function resolveFileCacheStatus(fileid, filename, directory) {
	pending.set(cacheKey(fileid ?? 0, filename, directory), {
		fileid: fileid ?? 0,
		filename,
		directory,
	})
	schedulePoll()
	await enqueueFlush()
	const cached = fileid ? byFileid.get(fileid) : null
	if (cached) {
		return {
			exists: cached.hasCache || cached.cache_state === 'ready' || cached.cache_state === 'partial',
			cache_state: cached.cache_state,
			job: cached.job,
			job_error: cached.job_error,
			queue_position: cached.queue_position,
		}
	}
	return { exists: false, cache_state: 'none' }
}
