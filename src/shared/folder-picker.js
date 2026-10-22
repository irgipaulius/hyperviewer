import { translate as t } from '@nextcloud/l10n'

/**
 * Opens the Nextcloud folder picker (core UI).
 * @returns {Promise<string|null>} Selected path relative to user files root
 */
export function pickFolderPath() {
	return new Promise((resolve) => {
		if (typeof OC === 'undefined' || !OC.dialogs || typeof OC.dialogs.filepicker !== 'function') {
			resolve(null)
			return
		}
		OC.dialogs.filepicker(
			t('hyperviewer', 'Choose a folder to watch'),
			(path) => resolve(path || null),
			false,
			'folder',
			true,
		)
	})
}
