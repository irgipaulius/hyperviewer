import { generateUrl } from '@nextcloud/router'

export function formatBytes(bytes) {
	if (bytes == null || Number.isNaN(bytes)) {
		return '—'
	}
	const n = Number(bytes)
	if (n < 1024) {
		return `${n} B`
	}
	if (n < 1024 ** 2) {
		return `${(n / 1024).toFixed(1)} KiB`
	}
	if (n < 1024 ** 3) {
		return `${(n / 1024 ** 2).toFixed(1)} MiB`
	}
	return `${(n / 1024 ** 3).toFixed(2)} GiB`
}

export function formatTs(ts) {
	if (!ts) {
		return '—'
	}
	return new Date(ts * 1000).toLocaleString()
}

export function formatDuration(sec) {
	if (sec == null) {
		return '—'
	}
	if (sec < 60) {
		return `${sec}s`
	}
	const m = Math.floor(sec / 60)
	const s = sec % 60
	return `${m}m ${s}s`
}

export function filesLink(job) {
	if (!job.files_dir && !job.directory) {
		return generateUrl('/apps/files/')
	}
	const dir = encodeURIComponent(job.files_dir || job.directory || '/')
	return generateUrl(`/apps/files/?dir=${dir}`)
}
