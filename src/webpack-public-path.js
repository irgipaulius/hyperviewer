/**
 * Must run before any dynamic import() so lazy chunks load from the app js/ URL.
 */
import { generateFilePath } from '@nextcloud/router'

// eslint-disable-next-line camelcase, no-undef
__webpack_public_path__ = generateFilePath('hyperviewer', '', 'js/')
