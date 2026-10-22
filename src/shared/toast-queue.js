import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'

export function toastQueued(jobId) {
	const settingsUrl = generateUrl('/settings/user/hyperviewer')
	showSuccess(`Queued as job #${jobId}. Open Settings → Hyper Viewer → Queue.`, { timeout: 10_000 })
	if (typeof window !== 'undefined' && window.sessionStorage) {
		window.sessionStorage.setItem('hyperviewer_open_tab', 'queue')
	}
	return settingsUrl
}
