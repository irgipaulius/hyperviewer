import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'

export function toastQueued(jobId) {
	const settingsUrl = generateUrl('/settings/user/hyperviewer')
	showSuccess(`Queued (job #${jobId}). View your queue in Settings → Hyper Viewer.`)
	if (typeof window !== 'undefined' && window.sessionStorage) {
		window.sessionStorage.setItem('hyperviewer_open_tab', 'queue')
	}
	return settingsUrl
}
