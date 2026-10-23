/**
 * Current directory in the Files app (query param, not pathname).
 */
export function currentFilesDirectory() {
	const router = typeof window !== 'undefined' ? window.OCP?.Files?.Router : undefined
	let dir = router?.query?.dir ?? router?.params?.dir ?? '/'
	if (typeof dir !== 'string' || dir === '') {
		dir = '/'
	}
	if (!dir.startsWith('/')) {
		dir = `/${dir}`
	}
	dir = dir.replace(/\/+$/, '')
	return dir === '' ? '/' : dir
}
