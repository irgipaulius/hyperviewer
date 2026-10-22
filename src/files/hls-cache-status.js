/** @typedef {{ exists?: boolean, cache_state?: string, fingerprint?: string }} CacheStatus */

const inflight = new Map()

/**
 * @param {number|undefined} fileid
 * @param {string} filename
 * @param {string} directory
 * @returns {Promise<CacheStatus>}
 */
export async function fetchCacheStatus(fileid, filename, directory) {
	const key = `${fileid ?? 0}:${directory}:${filename}`
	if (inflight.has(key)) {
		return inflight.get(key)
	}
	const promise = (async () => {
		try {
			const response = await fetch(
				OC.generateUrl('/apps/hyperviewer/cache/check'),
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						fileid: fileid ?? 0,
						filename,
						directory,
					}),
				},
			)
			if (!response.ok) {
				return { exists: false, cache_state: 'none' }
			}
			const data = await response.json()
			return data
		} catch {
			return { exists: false, cache_state: 'none' }
		} finally {
			inflight.delete(key)
		}
	})()
	inflight.set(key, promise)
	return promise
}
