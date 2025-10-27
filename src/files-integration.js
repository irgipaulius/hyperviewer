/**
 * Files app integration for Hyper Viewer (Nextcloud 25 compatible)
 * Adds "Generate HLS Cache" action to MOV and MP4 files
 */

import shaka from "shaka-player/dist/shaka-player.ui.js";
import "shaka-player/dist/controls.css";

console.log("🎬 Hyper Viewer Files integration loading...");

// Only initialize if we're in the Files app
/**
 *
 */
function isInFilesApp() {
	// Check if we're in the Files app by looking for Files-specific elements
	return document.body.id === 'body-user' && 
	       (window.location.pathname.includes('/apps/files') || 
	        document.querySelector('#app-content-files') !== null ||
	        document.querySelector('.files-filestable') !== null);
}

// Wait for Files app to be ready
document.addEventListener("DOMContentLoaded", function() {
	// Only initialize if we're in the Files app
	if (!isInFilesApp()) {
		console.log("⏭️ Not in Files app, skipping integration");
		return;
	}
	
	// Wait a bit more for Files app to fully initialize
	setTimeout(initializeFilesIntegration, 1000);
});

/**
 * Initialize files integration
 */
function initializeFilesIntegration() {
	// Check if we're in the Files app
	if (!window.OCA || !window.OCA.Files || !window.OCA.Files.fileActions) {
		setTimeout(initializeFilesIntegration, 2000);
		return;
	}

	console.log("✅ Files app detected, registering actions...");

	// Register "Generate HLS Cache" action for MOV files
	OCA.Files.fileActions.registerAction({
		name: "generateHlsCacheMov",
		displayName: t("hyperviewer", "Generate HLS Cache"),
		mime: "video/quicktime",
		permissions: OC.PERMISSION_UPDATE,
		iconClass: "icon-category-multimedia",
		actionHandler(filename, context) {
			console.log(
				"🚀 Generate HLS Cache action triggered for MOV:",
				filename
			);
			console.log("📁 Context:", context);
			openCacheGenerationDialog([{ filename, context }]);
		}
	});

	// Override default video player for MOV files - auto-detect HLS cache
	OCA.Files.fileActions.registerAction({
		name: "playVideoSmart",
		displayName: t("hyperviewer", "Play"),
		mime: "video/quicktime",
		permissions: OC.PERMISSION_READ,
		iconClass: "icon-play",
		order: -1, // Higher priority than default
		async actionHandler(filename, context) {
			console.log("🎬 Smart play triggered for MOV:", filename);
			const directory =
				context?.dir || context?.fileList?.getCurrentDirectory() || "/";
			await playVideoSmart(filename, directory, context);
		}
	});

	// Set as default action for MOV files
	OCA.Files.fileActions.setDefault("video/quicktime", "playVideoSmart");

	// Register "Generate HLS Cache" action for MP4 files
	OCA.Files.fileActions.registerAction({
		name: "generateHlsCacheMp4",
		displayName: t("hyperviewer", "Generate HLS Cache"),
		mime: "video/mp4",
		permissions: OC.PERMISSION_UPDATE,
		iconClass: "icon-category-multimedia",
		actionHandler(filename, context) {
			console.log(
				"🚀 Generate HLS Cache action triggered for MP4:",
				filename
			);
			console.log("📁 Context:", context);
			openCacheGenerationDialog([{ filename, context }]);
		}
	});

	// Override default video player for MP4 files - auto-detect HLS cache
	OCA.Files.fileActions.registerAction({
		name: "playVideoSmartMp4",
		displayName: t("hyperviewer", "Play"),
		mime: "video/mp4",
		permissions: OC.PERMISSION_READ,
		iconClass: "icon-play",
		order: -1, // Higher priority than default
		async actionHandler(filename, context) {
			console.log("🎬 Smart play triggered for MP4:", filename);
			const directory =
				context?.dir || context?.fileList?.getCurrentDirectory() || "/";
			await playVideoSmart(filename, directory, context);
		}
	});

	// Set as default action for MP4 files
	OCA.Files.fileActions.setDefault("video/mp4", "playVideoSmartMp4");

	// Register "Generate HLS Cache" action for directories
	OCA.Files.fileActions.registerAction({
		name: "generateHlsCacheDirectory",
		displayName: t("hyperviewer", "Generate HLS Cache (Directory)"),
		mime: "httpd/unix-directory",
		permissions: OC.PERMISSION_UPDATE,
		iconClass: "icon-category-multimedia",
		actionHandler(filename, context) {
			console.log(
				"🚀 Generate HLS Cache action triggered for directory:",
				filename
			);
			console.log("📁 Context:", context);
			openDirectoryCacheGenerationDialog(filename, context);
		}
	});

	console.log("✅ Hyper Viewer Files integration registered!");
	
	// Add HLS badges to file list
	addHlsBadgesToFileList();
}

/**
 * Add HLS badges to videos in the file list
 */
function addHlsBadgesToFileList() {
	console.log('🎨 Initializing HLS badges for file list...');
	
	// Inject CSS for HLS badges (works for both list and grid view)
	const style = document.createElement('style');
	style.textContent = `
		/* HLS Badge styling */
		.hls-badge {
			position: absolute;
			top: 4px;
			right: 4px;
			background: rgba(60, 60, 60, 0.95);
			color: #FF9800;
			padding: 2px 6px;
			border-radius: 3px;
			font-size: 9px;
			font-weight: bold;
			letter-spacing: 0.5px;
			z-index: 1000;
			pointer-events: none;
			box-shadow: 0 1px 3px rgba(0,0,0,0.4);
			backdrop-filter: blur(4px);
			line-height: 1.2;
		}
		
		/* List view - make thumbnail container relative */
		.files-fileList tr[data-file] td.filename .thumbnail {
			position: relative !important;
		}
		
		/* Grid view - make thumbnail container relative */
		.files-fileList .files-filestable .filename .thumbnail,
		.files-fileList .grid-item .thumbnail,
		section.files-grid .grid-item .thumbnail {
			position: relative !important;
		}
	`;
	document.head.appendChild(style);
	
	// Function to check and add badges
	/**
	 *
	 */
	async function updateHlsBadges() {
		console.log('🔍 Checking for videos to badge...');
		
		const directory = window.OCA?.Files?.App?.fileList?.getCurrentDirectory() || '/';
		const fileList = window.OCA?.Files?.App?.fileList;
		
		if (!fileList || !fileList.files) {
			console.warn('⚠️ No fileList available');
			return;
		}
		
		// Get video files from fileList (the source of truth)
		const videoFiles = fileList.files.filter(file => 
			file.mimetype === 'video/quicktime' || 
			file.mimetype === 'video/mp4'
		);
		
		console.log(`📹 Found ${videoFiles.length} video files in fileList`);
		
		if (videoFiles.length === 0) {
			return;
		}
		
		// Get all filenames
		const filenames = videoFiles.map(f => f.name);
		
		try {
			// Batch check all videos at once (much faster!)
			const response = await fetch(
				OC.generateUrl('/apps/hyperviewer/cache/batch-check'),
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken
					},
					body: JSON.stringify({
						directory,
						filenames
					})
				}
			);
			
			if (!response.ok) {
				console.error('Batch check failed:', response.status);
				return;
			}
			
			const result = await response.json();
			const cachedVideos = new Set(result.cachedVideos || []);
			
			console.log(`✅ Batch check complete: ${cachedVideos.size}/${filenames.length} videos have HLS cache`);
			
			// Apply badges to videos with cache
			for (const videoFile of videoFiles) {
				const filename = videoFile.name;
				
				// Skip if video doesn't have cache
				if (!cachedVideos.has(filename)) {
					continue;
				}
				
				// Escape filename for querySelector (handle special chars)
				const escapedFilename = CSS.escape(filename);
				
				// Find the DOM element - try both list and grid view
				let fileElement = document.querySelector(`tr[data-file="${escapedFilename}"]`); // List view
				if (!fileElement) {
					fileElement = document.querySelector(`[data-name="${escapedFilename}"]`); // Grid view
				}
				if (!fileElement) {
					// Try without escaping for older Nextcloud versions
					fileElement = document.querySelector(`tr[data-file="${filename}"]`);
				}
				
				if (!fileElement) {
					// Skip silently - file might not be visible in current view
					continue;
				}
				
				// Skip if badge already exists
				if (fileElement.querySelector('.hls-badge')) {
					continue;
				}
				
				// Find thumbnail container in the element
				const thumbnailContainer = 
					fileElement.querySelector('td.filename .thumbnail') || // List view
					fileElement.querySelector('.thumbnail') || // Grid view
					fileElement.querySelector('.files-list__row-icon') || // New list view
					fileElement.querySelector('.icon');
				
				if (thumbnailContainer) {
					// Ensure container has relative positioning
					thumbnailContainer.style.position = 'relative';
					
					const badge = document.createElement('div');
					badge.className = 'hls-badge';
					badge.textContent = 'HLS';
					badge.title = 'HLS cache available';
					thumbnailContainer.appendChild(badge);
					
					console.log(`✅ Added HLS badge to: ${filename}`);
				}
			}
		} catch (error) {
			console.error('Failed to batch check HLS cache:', error);
		}
	}
	
	// Update badges with delay for initial load
	setTimeout(() => {
		console.log('🚀 Running initial badge update...');
		updateHlsBadges();
	}, 3000);
	
	// Update badges periodically but less frequently (30 seconds instead of 5)
	// MutationObserver will catch most changes, this is just a safety net
	setInterval(updateHlsBadges, 30000);
	
	// Throttle mechanism for MutationObserver
	let badgeUpdateTimeout = null;
	const throttledBadgeUpdate = () => {
		if (badgeUpdateTimeout) {
			clearTimeout(badgeUpdateTimeout);
		}
		badgeUpdateTimeout = setTimeout(updateHlsBadges, 1000);
	};
	
	// Update badges when file list changes (MutationObserver)
	const observer = new MutationObserver(throttledBadgeUpdate);
	
	// Observe both list and grid containers
	const observeFileList = () => {
		// List view container
		const listContainer = document.querySelector('#filestable tbody.files-fileList');
		if (listContainer) {
			observer.observe(listContainer, {
				childList: true,
				subtree: true
			});
			console.log('👀 Observing list view for changes');
		}
		
		// Grid view container
		const gridContainer = document.querySelector('section.files-grid');
		if (gridContainer) {
			observer.observe(gridContainer, {
				childList: true,
				subtree: true
			});
			console.log('👀 Observing grid view for changes');
		}
		
		// If containers don't exist yet, try again
		if (!listContainer && !gridContainer) {
			setTimeout(observeFileList, 1000);
		}
	};
	
	observeFileList();
	
	// Hook into directory changes
	if (window.OCA?.Files?.App?.fileList) {
		const originalChangeDirectory = window.OCA.Files.App.fileList.changeDirectory;
		window.OCA.Files.App.fileList.changeDirectory = function(...args) {
			const result = originalChangeDirectory.apply(this, args);
			setTimeout(() => {
				console.log('📂 Directory changed, updating badges...');
				updateHlsBadges();
			}, 1500);
			return result;
		};
	}
	
	console.log('✅ HLS badge system initialized');
}

/**
 * Open unified cache generation dialog for files or directories
 *
 * @param files Array of file objects with filename and context
 */
function openCacheGenerationDialog(files) {
	// For single files, call the unified function
	openUnifiedCacheDialog({ type: 'files', files });
}

/**
 * Unified cache generation dialog for both files and directories
 * 
 * @param {object} options - Configuration object
 * @param {string} options.type - Either 'files' or 'directory'
 * @param {Array} options.files - Array of file objects (for type='files')
 * @param {string} options.directoryPath - Full directory path (for type='directory')
 * @param {Array} options.videoFiles - Array of discovered video files (for type='directory')
 */
function openUnifiedCacheDialog(options) {
	const { type, files, directoryPath, videoFiles } = options;
	const isDirectory = type === 'directory';
	
	// Prepare content based on type
	let title, infoContent, fileCount, fileList;
	
	if (isDirectory) {
		title = 'Generate HLS Cache (Directory)';
		fileCount = videoFiles.length;
		fileList = videoFiles.map(f => f.filename).join(', ');
		infoContent = `
			<div class="directory-info-card">
				<div class="info-item">
					<strong>Directory:</strong> ${directoryPath}
				</div>
				<div class="info-item ${fileCount === 0 ? 'no-videos' : ''}">
					<strong>Video files found:</strong> ${fileCount}
					${fileCount > 0
						? `<div class="file-list">${fileList}</div>`
						: '<div class="no-videos-text">No videos currently, but auto-generation can monitor for new files</div>'
					}
				</div>
			</div>
		`;
	} else {
		title = 'Generate HLS Cache';
		fileList = files.map(f => f.filename).join(', ');
		infoContent = `
			<div class="directory-info-card">
				<div class="info-item">
					<strong>Files:</strong> ${fileList}
				</div>
			</div>
		`;
	}
	
	// Create modal
	const modal = document.createElement('div');
	modal.className = 'hyper-viewer-cache-modal';
	modal.innerHTML = `
		<div class="hyper-viewer-overlay"></div>
		<div class="hyper-viewer-cache-container">
			<div class="hyper-viewer-cache-header">
				<h3 class="hyper-viewer-cache-title">${title}</h3>
				<button class="hyper-viewer-close" aria-label="Close dialog">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<line x1="18" y1="6" x2="6" y2="18"></line>
						<line x1="6" y1="6" x2="18" y2="18"></line>
					</svg>
				</button>
			</div>
			<div class="hyper-viewer-cache-content">
				${infoContent}
				
				<div class="form-section">
					<label class="section-title">Cache Location</label>
					<div class="option-group" id="cache-locations-container">
						<p style="color: #999; font-size: 13px;">Loading cache locations...</p>
					</div>
				</div>
				
				<div class="form-section">
					<label class="section-title">Resolution Renditions</label>
					<div class="resolution-grid">
						<label class="resolution-item">
							<input type="checkbox" name="resolution" value="720p" checked>
							<div class="resolution-content">
								<span class="resolution-name">720p</span>
								<span class="resolution-desc">HD Quality</span>
							</div>
						</label>
						<label class="resolution-item">
							<input type="checkbox" name="resolution" value="480p" checked>
							<div class="resolution-content">
								<span class="resolution-name">480p</span>
								<span class="resolution-desc">SD Quality</span>
							</div>
						</label>
						<label class="resolution-item">
							<input type="checkbox" name="resolution" value="360p">
							<div class="resolution-content">
								<span class="resolution-name">360p</span>
								<span class="resolution-desc">Low Quality</span>
							</div>
						</label>
						<label class="resolution-item">
							<input type="checkbox" name="resolution" value="240p" checked>
							<div class="resolution-content">
								<span class="resolution-name">240p</span>
								<span class="resolution-desc">Mobile</span>
							</div>
						</label>
					</div>
				</div>
				
				<div class="form-section">
					<label class="section-title">Options</label>
					<div class="option-group">
						<label class="option-item">
							<input type="checkbox" id="overwrite_existing">
							<div class="option-content">
								<span class="option-title">Overwrite existing cache</span>
								<span class="option-desc">Replace existing HLS files</span>
							</div>
						</label>
						${isDirectory
							? `
								<label class="option-item highlight">
									<input type="checkbox" id="enable_auto_generation">
									<div class="option-content">
										<span class="option-title">Enable automatic generation</span>
										<span class="option-desc">Monitor directory for new videos and auto-generate HLS cache</span>
									</div>
								</label>
							`
							: ''}
					</div>
				</div>
				
				<div class="dialog-actions">
					<button class="btn-cancel">Cancel</button>
					<button class="btn-confirm">Generate HLS Cache</button>
				</div>
			</div>
		</div>
		
		<style>
			.hyper-viewer-cache-modal {
				position: fixed;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				z-index: 10001;
				display: flex;
				align-items: center;
				justify-content: center;
				padding: 20px;
				box-sizing: border-box;
				opacity: 0;
				visibility: hidden;
				transition: opacity 0.3s ease, visibility 0.3s ease;
			}
			
			.hyper-viewer-cache-modal.show {
				opacity: 1;
				visibility: visible;
			}
			
			.hyper-viewer-overlay {
				position: absolute;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				background: rgba(0, 0, 0, 0.7);
				backdrop-filter: blur(4px);
			}
			
			.hyper-viewer-cache-container {
				position: relative;
				background: #1a1a1a;
				border-radius: 12px;
				box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
				max-width: 600px;
				width: 100%;
				max-height: 90vh;
				display: flex;
				flex-direction: column;
				overflow: hidden;
				transform: scale(0.95);
				transition: transform 0.3s ease;
			}
			
			.hyper-viewer-cache-modal.show .hyper-viewer-cache-container {
				transform: scale(1);
			}
			
			.hyper-viewer-cache-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				padding: 16px 20px;
				background: #2a2a2a;
				border-bottom: 1px solid #3a3a3a;
			}
			
			.hyper-viewer-cache-title {
				margin: 0;
				color: #ffffff;
				font-size: 16px;
				font-weight: 600;
			}
			
			.hyper-viewer-cache-content {
				padding: 20px;
				color: #ffffff;
				overflow-y: auto;
				flex: 1;
			}
			
			.directory-info-card {
				background: #2a2a2a;
				border-radius: 8px;
				padding: 16px;
				margin-bottom: 24px;
				border: 1px solid #3a3a3a;
			}
			
			.info-item {
				margin-bottom: 12px;
			}
			
			.info-item:last-child {
				margin-bottom: 0;
			}
			
			.file-list {
				margin-top: 8px;
				padding: 8px 12px;
				background: #1a1a1a;
				border-radius: 4px;
				font-size: 13px;
				color: #cccccc;
				max-height: 80px;
				overflow-y: auto;
			}
			
			.no-videos-text {
				margin-top: 8px;
				padding: 8px 12px;
				background: #2d1b1b;
				border: 1px solid #4a3333;
				border-radius: 4px;
				font-size: 13px;
				color: #ffcc99;
			}
			
			.form-section {
				margin-bottom: 24px;
			}
			
			.section-title {
				display: block;
				font-weight: 600;
				margin-bottom: 12px;
				color: #ffffff;
				font-size: 14px;
			}
			
			.option-group {
				display: flex;
				flex-direction: column;
				gap: 8px;
			}
			
			.option-item {
				display: flex;
				align-items: flex-start;
				gap: 12px;
				padding: 12px;
				background: #2a2a2a;
				border: 1px solid #3a3a3a;
				border-radius: 8px;
				cursor: pointer;
				transition: all 0.2s ease;
			}
			
			.option-item:hover {
				background: #333333;
				border-color: #4a4a4a;
			}
			
			.option-item.highlight {
				border-color: #4a9eff;
				background: rgba(74, 158, 255, 0.1);
			}
			
			.option-content {
				flex: 1;
				display: flex;
				flex-direction: column;
				gap: 4px;
			}
			
			.option-title {
				font-weight: 500;
				color: #ffffff;
			}
			
			.option-desc {
				font-size: 13px;
				color: #999999;
			}
			
			.resolution-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
				gap: 8px;
			}
			
			.resolution-item {
				display: flex;
				align-items: center;
				gap: 8px;
				padding: 12px;
				background: #2a2a2a;
				border: 1px solid #3a3a3a;
				border-radius: 8px;
				cursor: pointer;
				transition: all 0.2s ease;
			}
			
			.resolution-item:hover {
				background: #333333;
				border-color: #4a4a4a;
			}
			
			.resolution-content {
				display: flex;
				flex-direction: column;
				gap: 2px;
			}
			
			.resolution-name {
				font-weight: 500;
				color: #ffffff;
				font-size: 14px;
			}
			
			.resolution-desc {
				font-size: 12px;
				color: #999999;
			}
			
			.dialog-actions {
				display: flex;
				gap: 12px;
				justify-content: flex-end;
				margin-top: 24px;
				padding-top: 20px;
				border-top: 1px solid #3a3a3a;
			}
			
			.btn-cancel, .btn-confirm {
				padding: 10px 20px;
				border: none;
				border-radius: 6px;
				font-weight: 500;
				cursor: pointer;
				transition: all 0.2s ease;
			}
			
			.btn-cancel {
				background: #3a3a3a;
				color: #ffffff;
			}
			
			.btn-cancel:hover {
				background: #4a4a4a;
			}
			
			.btn-confirm {
				background: #4a9eff;
				color: #ffffff;
			}
			
			.btn-confirm:hover {
				background: #0066cc;
			}
			
			.hyper-viewer-close {
				background: transparent;
				border: none;
				color: #ffffff;
				cursor: pointer;
				padding: 8px;
				border-radius: 6px;
				display: flex;
				align-items: center;
				justify-content: center;
				transition: background-color 0.2s ease, color 0.2s ease;
			}
			
			.hyper-viewer-close:hover {
				background: rgba(255, 255, 255, 0.1);
				color: #ff6b6b;
			}
		</style>
	`;
	
	// Add modal to DOM
	document.body.appendChild(modal);
	document.body.style.overflow = 'hidden';
	
	// Show modal with animation
	requestAnimationFrame(() => {
		modal.classList.add('show');
	});
	
	// Fetch cache locations from settings and populate
	fetchCacheLocations().then(locations => {
		const container = modal.querySelector('#cache-locations-container');
		if (locations && locations.length > 0) {
			container.innerHTML = locations.map((location, index) => `
				<label class="option-item">
					<input type="radio" name="cache_location" data-path="${location}" ${index === 0 ? 'checked' : ''}>
					<div class="option-content">
						<span class="option-title">${location}</span>
					</div>
				</label>
			`).join('');
		} else {
			container.innerHTML = '<p style="color: #ff6b6b;">No cache locations configured. Please configure in personal settings.</p>';
		}
	});
	
	// Handle escape key
	const handleKeydown = e => {
		if (e.key === 'Escape') {
			closeModal();
		}
	};
	
	// Close functionality
	const closeModal = () => {
		modal.classList.remove('show');
		document.removeEventListener('keydown', handleKeydown);
		setTimeout(() => {
			document.body.style.overflow = '';
			if (modal.parentNode) {
				modal.parentNode.removeChild(modal);
			}
		}, 300);
	};
	
	// Event listeners
	modal.querySelector('.hyper-viewer-close').addEventListener('click', closeModal);
	modal.querySelector('.hyper-viewer-overlay').addEventListener('click', closeModal);
	modal.querySelector('.btn-cancel').addEventListener('click', closeModal);
	
	modal.querySelector('.btn-confirm').addEventListener('click', () => {
		if (isDirectory) {
			startDirectoryCacheGeneration(videoFiles, directoryPath);
		} else {
			startCacheGeneration(files);
		}
		closeModal();
	});
	
	document.addEventListener('keydown', handleKeydown);
}

/**
 * Fetch cache locations from personal settings
 */
async function fetchCacheLocations() {
	try {
		const response = await fetch(OC.generateUrl('/apps/hyperviewer/settings/cache-locations'), {
			headers: {
				requesttoken: OC.requestToken
			}
		});
		
		if (response.ok) {
			const data = await response.json();
			return data.locations || [];
		}
	} catch (error) {
		console.error('Failed to fetch cache locations:', error);
	}
	
	// No fallbacks - must be configured in settings
	return [];
}

/**
 * Open directory cache generation dialog with recursive scanning and auto-generation options
 *
 * @param directoryName Name of the directory
 * @param context Directory context from Files app
 */
async function openDirectoryCacheGenerationDialog(directoryName, context) {
	console.log(
		"🔧 Opening directory cache generation dialog for:",
		directoryName
	);

	const directory =
		context?.dir || context?.fileList?.getCurrentDirectory() || "/";
	const fullPath =
		directory === "/"
			? `/${directoryName}`
			: `${directory}/${directoryName}`;

	// Discover video files in directory
	console.log("🔍 Discovering video files in directory:", fullPath);
	const videoFiles = await discoverVideoFilesInDirectory(fullPath);

	// Call unified dialog for directory
	openUnifiedCacheDialog({ 
		type: 'directory', 
		directoryPath: fullPath, 
		videoFiles 
	});
}

/**
 * Discover video files recursively in a directory
 *
 * @param directoryPath Path to the directory to scan
 * @return Array of video file objects
 */
async function discoverVideoFilesInDirectory(directoryPath) {
	try {
		const response = await fetch(
			OC.generateUrl("/apps/hyperviewer/cache/discover-videos"),
			{
				method: "POST",
				headers: {
					"Content-Type": "application/json",
					requesttoken: OC.requestToken
				},
				body: JSON.stringify({
					directory: directoryPath
				})
			}
		);

		const result = await response.json();

		if (result.success) {
			return result.files || [];
		} else {
			throw new Error(result.error || "Failed to discover video files");
		}
	} catch (error) {
		console.error("Failed to discover video files:", error);
		OC.dialogs.alert(
			`Failed to scan directory: ${error.message}`,
			"Discovery Error"
		);
		return [];
	}
}

/**
 * Start directory cache generation process
 *
 * @param videoFiles Array of video file objects
 * @param directoryPath Full path to the directory
 */
async function startDirectoryCacheGeneration(videoFiles, directoryPath) {
	console.log("Starting directory HLS cache generation for:", directoryPath);

	// Get selected cache location path from settings
	const selectedLocationRadio = document.querySelector('input[name="cache_location"]:checked');
	if (!selectedLocationRadio) {
		OC.dialogs.alert('Please select a cache location', 'Error');
		return;
	}
	
	const cachePath = selectedLocationRadio.dataset.path;
	if (!cachePath) {
		OC.dialogs.alert('Invalid cache location selected', 'Error');
		return;
	}

	const overwriteExisting =
		document.getElementById("overwrite_existing")?.checked || false;
	const enableAutoGeneration =
		document.getElementById("enable_auto_generation")?.checked || false;

	// Get selected resolutions
	const selectedResolutions = Array.from(
		document.querySelectorAll('input[name="resolution"]:checked')
	).map(checkbox => checkbox.value);

	// Default to 720p, 480p, 240p if none selected
	const resolutions =
		selectedResolutions.length > 0
			? selectedResolutions
			: ["720p", "480p", "240p"];

	const options = {
		cachePath,
		overwriteExisting,
		resolutions,
		enableAutoGeneration,
		directoryPath
	};

	console.log("Directory cache generation options:", options);

	try {
		// Only start cache generation if there are files to process
		if (videoFiles.length > 0) {
			await startCacheGeneration(videoFiles);
		}

		// If auto-generation is enabled, register the directory for monitoring
		if (enableAutoGeneration) {
			try {
				const registerResponse = await fetch(
					OC.generateUrl("/apps/hyperviewer/cache/register-auto-generation"),
					{
						method: "POST",
						headers: {
							"Content-Type": "application/json",
							requesttoken: OC.requestToken
						},
						body: JSON.stringify({
							directory: directoryPath,
							options
						})
					}
				);

				if (registerResponse.ok) {
					console.log("✅ Directory registered for auto-generation");
				} else {
					console.error("❌ Failed to register directory for auto-generation");
				}
			} catch (error) {
				console.error("Error registering directory for auto-generation:", error);
			}
		}

		// Show appropriate success message
		if (videoFiles.length > 0 && enableAutoGeneration) {
			// Both processing and auto-generation
			console.log(
				`✅ Started processing ${videoFiles.length} files and enabled auto-generation for ${directoryPath}`
			);
		} else if (videoFiles.length > 0) {
			// Only processing files
			console.log(
				`✅ Started processing ${videoFiles.length} files in ${directoryPath}`
			);
		} else if (enableAutoGeneration) {
			// Only auto-generation setup
			console.log(
				`✅ Auto-generation enabled for ${directoryPath} - will monitor for new videos`
			);
			OC.dialogs.info(
				`Auto-generation has been enabled for "${directoryPath}".\n\nNew video files added to this directory will automatically have HLS cache generated.`,
				"Auto-Generation Enabled"
			);
		}
	} catch (error) {
		console.error("Failed to start directory cache generation:", error);
		OC.dialogs.alert(
			`Failed to start directory processing: ${error.message}`,
			"Processing Error"
		);
	}
}

/**
 * Start the actual cache generation process
 *
 * @param files Array of file objects
 */
async function startCacheGeneration(files) {
	console.log(
		"Starting HLS cache generation for:",
		files.map(f => f.filename)
	);

	// Get selected cache location path from settings
	const selectedLocationRadio = document.querySelector('input[name="cache_location"]:checked');
	if (!selectedLocationRadio) {
		OC.dialogs.alert('Please select a cache location', 'Error');
		return;
	}
	
	const cachePath = selectedLocationRadio.dataset.path;
	if (!cachePath) {
		OC.dialogs.alert('Invalid cache location selected', 'Error');
		return;
	}

	// Get selected options
	const overwriteExisting =
		document.getElementById("overwrite_existing")?.checked || false;

	// Get selected resolutions
	const selectedResolutions = Array.from(
		document.querySelectorAll('input[name="resolution"]:checked')
	).map(checkbox => checkbox.value);

	// Default to 720p, 480p, 240p if none selected
	const resolutions =
		selectedResolutions.length > 0
			? selectedResolutions
			: ["720p", "480p", "240p"];

	const options = {
		cachePath,
		overwriteExisting,
		resolutions
	};

	console.log("Cache generation options:", options);

	// Prepare files data for backend
	const filesData = files.map(file => ({
		filename: file.filename,
		directory:
			file.context?.dir ||
			file.context?.fileList?.getCurrentDirectory() ||
			"/"
	}));

	try {
		// Show appropriate progress interface
		if (files.length === 1) {
			// Single file: show sophisticated real-time progress modal
			const file = files[0];
			const directory =
				file.context?.dir ||
				file.context?.fileList?.getCurrentDirectory() ||
				"/";
			showProgressModal(file.filename, directory);
		} else {
			// Multiple files: show simple progress dialog
			showProgressDialog(files.length);
		}

		// Send to backend for processing
		const response = await fetch(
			OC.generateUrl("/apps/hyperviewer/cache/generate"),
			{
				method: "POST",
				headers: {
					"Content-Type": "application/json",
					requesttoken: OC.requestToken
				},
				body: JSON.stringify({
					files: filesData,
					cachePath: options.cachePath,
					overwriteExisting: options.overwriteExisting,
					resolutions: options.resolutions
				})
			}
		);

		const result = await response.json();

		if (result.success) {
			console.log("HLS cache generation started successfully", result);

			// Start progress tracking
			if (result.jobId) {
				console.log(`📈 Tracking progress for job: ${result.jobId}`);
			}

			OC.dialogs.info(
				`Cache generation started for ${
					files.length
				} file(s).\n\nCache location: ${options.cachePath}\n\nResolutions: ${options.resolutions.join(
					", "
				)}\n\nProcessing will run in the background.`,
				"HLS Cache Generation Started"
			);
		} else {
			throw new Error(result.error || "Unknown error occurred");
		}
	} catch (error) {
		console.error("Failed to start HLS cache generation:", error);
		OC.dialogs.alert(
			`Failed to start cache generation: ${error.message}`,
			"Error"
		);
	}
}

/**
 * Show progress dialog
 *
 * @param fileCount
 */
function showProgressDialog(fileCount) {
	console.log(`📊 Starting progress tracking for ${fileCount} files`);

	// Create progress modal
	const modal = document.createElement("div");
	modal.className = "hyper-viewer-progress-modal";
	modal.style.cssText = `
		position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 10000;
		background: rgba(0,0,0,0.8); display: flex; align-items: center; justify-content: center;
		padding: 20px; box-sizing: border-box;
	`;

	modal.innerHTML = `
		<div style="background: #1a1a1a; border-radius: 12px; padding: 24px; max-width: 500px; width: 100%; color: white;">
			<h3 style="margin: 0 0 16px 0; color: #fff;">HLS Generation Progress</h3>
			<p style="margin: 0 0 20px 0; color: #ccc;">Processing ${fileCount} file${
		fileCount > 1 ? "s" : ""
	}...</p>
			<div style="background: #333; border-radius: 8px; height: 8px; overflow: hidden; margin-bottom: 16px;">
				<div class="progress-bar" style="background: linear-gradient(90deg, #4a9eff, #0066cc); height: 100%; width: 0%; transition: width 0.3s ease;"></div>
			</div>
			<div class="progress-text" style="font-size: 14px; color: #999; text-align: center;">Starting...</div>
			<button onclick="this.closest('.hyper-viewer-progress-modal').remove()" style="
				margin-top: 20px; padding: 8px 16px; background: #333; border: none; color: white; 
				border-radius: 6px; cursor: pointer; float: right;">Close</button>
		</div>
	`;

	document.body.appendChild(modal);

	// Auto-close after 30 seconds if still showing
	setTimeout(() => {
		if (modal.parentNode) {
			modal.remove();
		}
	}, 30000);
}

/**
 * Show progress modal for HLS generation
 *
 * @param filename
 * @param directory
 */
function showProgressModal(filename, directory) {
	console.log(`📈 Showing progress modal for: ${filename}`);

	// Create unique modal ID
	const modalId = `progressModal_${Date.now()}`;

	// Create modal container
	const modal = document.createElement("div");
	modal.id = modalId;
	modal.className = "hyper-viewer-progress-modal";
	modal.setAttribute("role", "dialog");
	modal.setAttribute("aria-modal", "true");
	modal.setAttribute("aria-labelledby", "progress-title");

	modal.innerHTML = `
		<div class="hyper-viewer-overlay"></div>
		<div class="hyper-viewer-progress-container">
			<div class="hyper-viewer-progress-header">
				<h3 id="progress-title" class="hyper-viewer-progress-title">Generating HLS Cache</h3>
				<button class="hyper-viewer-close" aria-label="Close progress modal">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<line x1="18" y1="6" x2="6" y2="18"></line>
						<line x1="6" y1="6" x2="18" y2="18"></line>
					</svg>
				</button>
			</div>
			<div class="hyper-viewer-progress-content">
				<div class="progress-file-info">
					<strong>File:</strong> ${filename}
				</div>
				<div class="progress-bar-container">
					<div class="progress-bar">
						<div class="progress-bar-fill" style="width: 0%"></div>
					</div>
					<div class="progress-percentage">0%</div>
				</div>
				<div class="progress-details">
					<div class="progress-status">Starting...</div>
					<div class="progress-stats">
						<span class="progress-speed">Speed: 0x</span>
						<span class="progress-time">Time: 00:00:00</span>
						<span class="progress-fps">FPS: 0</span>
					</div>
				</div>
				<div class="progress-spinner">
					<div class="spinner"></div>
				</div>
			</div>
		</div>
		
		<style>
			.hyper-viewer-progress-modal {
				position: fixed;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				z-index: 10001;
				display: flex;
				align-items: center;
				justify-content: center;
				padding: 20px;
				box-sizing: border-box;
				opacity: 0;
				visibility: hidden;
				transition: opacity 0.3s ease, visibility 0.3s ease;
			}
			
			.hyper-viewer-progress-modal.show {
				opacity: 1;
				visibility: visible;
			}
			
			.hyper-viewer-progress-container {
				position: relative;
				background: #1a1a1a;
				border-radius: 12px;
				box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
				max-width: 500px;
				width: 100%;
				display: flex;
				flex-direction: column;
				overflow: hidden;
				transform: scale(0.95);
				transition: transform 0.3s ease;
			}
			
			.hyper-viewer-progress-modal.show .hyper-viewer-progress-container {
				transform: scale(1);
			}
			
			.hyper-viewer-progress-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				padding: 16px 20px;
				background: #2a2a2a;
				border-bottom: 1px solid #3a3a3a;
			}
			
			.hyper-viewer-progress-title {
				margin: 0;
				color: #ffffff;
				font-size: 16px;
				font-weight: 600;
			}
			
			.hyper-viewer-progress-content {
				padding: 20px;
				color: #ffffff;
			}
			
			.progress-file-info {
				margin-bottom: 20px;
				font-size: 14px;
				color: #cccccc;
			}
			
			.progress-bar-container {
				display: flex;
				align-items: center;
				gap: 12px;
				margin-bottom: 16px;
			}
			
			.progress-bar {
				flex: 1;
				height: 8px;
				background: #333333;
				border-radius: 4px;
				overflow: hidden;
			}
			
			.progress-bar-fill {
				height: 100%;
				background: linear-gradient(90deg, #4a9eff, #0066cc);
				border-radius: 4px;
				transition: width 0.3s ease;
			}
			
			.progress-percentage {
				font-weight: 600;
				min-width: 40px;
				text-align: right;
			}
			
			.progress-details {
				display: flex;
				flex-direction: column;
				gap: 8px;
			}
			
			.progress-status {
				font-weight: 500;
				color: #4a9eff;
			}
			
			.progress-stats {
				display: flex;
				gap: 16px;
				font-size: 13px;
				color: #999999;
			}
			
			.progress-spinner {
				display: flex;
				justify-content: center;
				margin-top: 16px;
			}
			
			.spinner {
				width: 24px;
				height: 24px;
				border: 2px solid #333333;
				border-top: 2px solid #4a9eff;
				border-radius: 50%;
				animation: spin 1s linear infinite;
			}
			
			@keyframes spin {
				0% { transform: rotate(0deg); }
				100% { transform: rotate(360deg); }
			}
			
			.hyper-viewer-close {
				background: transparent;
				border: none;
				color: #ffffff;
				cursor: pointer;
				padding: 8px;
				border-radius: 6px;
				display: flex;
				align-items: center;
				justify-content: center;
				transition: background-color 0.2s ease, color 0.2s ease;
			}
			
			.hyper-viewer-close:hover {
				background: rgba(255, 255, 255, 0.1);
				color: #ff6b6b;
			}
		</style>
	`;

	// Add modal to DOM
	document.body.appendChild(modal);

	// Prevent body scroll
	document.body.style.overflow = "hidden";

	// Show modal with animation
	requestAnimationFrame(() => {
		modal.classList.add("show");
	});

	// Close functionality
	const closeModal = () => {
		modal.classList.remove("show");
		setTimeout(() => {
			document.body.style.overflow = "";
			if (modal.parentNode) {
				modal.parentNode.removeChild(modal);
			}
		}, 300);
	};

	modal
		.querySelector(".hyper-viewer-close")
		.addEventListener("click", closeModal);
	modal
		.querySelector(".hyper-viewer-overlay")
		.addEventListener("click", closeModal);

	// Start progress polling
	startProgressPolling(filename, directory, modal);
}

/**
 * Start polling for progress updates
 *
 * @param filename
 * @param directory
 * @param modal
 */
function startProgressPolling(filename, directory, modal) {
	const baseFilename = filename.replace(/\.[^/.]+$/, ""); // Remove extension
	const cachePath =
		directory === "/"
			? `/.cached_hls/${baseFilename}`
			: `${directory}/.cached_hls/${baseFilename}`;

	console.log(`🔍 Starting progress polling for: ${filename}`);
	console.log(`📁 Directory: ${directory}`);
	console.log(`📂 Cache path: ${cachePath}`);

	let pollCount = 0;
	const maxPolls = 360; // 30 minutes max (polling every 5 seconds)

	// Start countdown timer for cron processing
	startCronCountdown(modal);

	const poll = async () => {
		try {
			pollCount++;
			console.log(
				`📊 Progress poll ${pollCount}/${maxPolls} for: ${filename}`
			);

			const encodedCachePath = encodeURIComponent(cachePath);
			const progressUrl = OC.generateUrl(
				`/apps/hyperviewer/cache/progress/${encodedCachePath}`
			);
			console.log(`🌐 Polling URL: ${progressUrl}`);

			const response = await fetch(progressUrl);
			const result = await response.json();

			console.log(`📈 Progress response:`, result);

			if (result.success && result.progress) {
				updateProgressModal(modal, result.progress);

				if (result.progress.status === "completed") {
					console.log(`✅ HLS generation completed for: ${filename}`);
					setTimeout(() => {
						modal.querySelector(".hyper-viewer-close").click();
					}, 2000); // Auto-close after 2 seconds
					return;
				} else if (result.progress.status === "failed") {
					console.error(`❌ HLS generation failed for: ${filename}`);
					updateProgressModal(modal, {
						...result.progress,
						status: "Error: Generation failed"
					});
					return;
				}
			}

			// Continue polling if not completed and within limits
			if (pollCount < maxPolls && modal.parentNode) {
				setTimeout(poll, 5000); // Poll every 5 seconds
			}
		} catch (error) {
			console.error("❌ Progress polling error:", error);

			// Update modal to show error state
			if (modal.parentNode) {
				const statusElement = modal.querySelector(".progress-status");
				if (statusElement) {
					statusElement.textContent = `Polling error: ${error.message}`;
				}
			}

			if (pollCount < maxPolls && modal.parentNode) {
				setTimeout(poll, 5000); // Retry on error
			}
		}
	};

	// Start polling after a short delay
	setTimeout(poll, 2000);
}

/**
 * Start countdown timer showing time until next cron execution
 *
 * @param modal
 */
function startCronCountdown(modal) {
	const statusElement = modal.querySelector(".progress-status");
	if (!statusElement) return;

	const updateCountdown = () => {
		const now = new Date();
		const minutes = now.getMinutes();
		const seconds = now.getSeconds();

		// Calculate seconds until next 5-minute mark (0, 5, 10, 15, etc.)
		const nextCronMinute = Math.ceil(minutes / 5) * 5;
		const minutesUntilCron = (nextCronMinute - minutes) % 5;
		const secondsUntilCron = minutesUntilCron * 60 - seconds;

		if (secondsUntilCron > 0) {
			const mins = Math.floor(secondsUntilCron / 60);
			const secs = secondsUntilCron % 60;
			statusElement.textContent = `Waiting for processing to start... ${mins}:${secs
				.toString()
				.padStart(2, "0")}`;
			setTimeout(updateCountdown, 1000);
		} else {
			statusElement.textContent = "Processing should start soon...";
		}
	};

	updateCountdown();
}

/**
 * Update progress modal with latest data
 *
 * @param modal
 * @param progress
 */
function updateProgressModal(modal, progress) {
	const progressBar = modal.querySelector(".progress-bar-fill");
	const progressPercentage = modal.querySelector(".progress-percentage");
	const progressStatus = modal.querySelector(".progress-status");
	const progressSpeed = modal.querySelector(".progress-speed");
	const progressTime = modal.querySelector(".progress-time");
	const progressFps = modal.querySelector(".progress-fps");
	const spinner = modal.querySelector(".progress-spinner");

	// Calculate progress percentage (rough estimate based on time)
	let percentage = progress.progress || 0;
	if (progress.time && progress.time !== "00:00:00") {
		// Rough estimation - this could be improved with video duration
		const timeMatch = progress.time.match(/(\d{2}):(\d{2}):(\d{2})/);
		if (timeMatch) {
			const totalSeconds =
				parseInt(timeMatch[1]) * 3600 +
				parseInt(timeMatch[2]) * 60 +
				parseInt(timeMatch[3]);
			// Assume average video is 3 minutes (180 seconds) for rough percentage
			percentage = Math.min(Math.round((totalSeconds / 180) * 100), 99);
		}
	}

	if (progress.status === "completed") {
		percentage = 100;
		spinner.style.display = "none";
	}

	// Update UI elements
	progressBar.style.width = `${percentage}%`;
	progressPercentage.textContent = `${percentage}%`;
	progressStatus.textContent = progress.status || "Processing...";
	progressSpeed.textContent = `Speed: ${progress.speed || "0x"}`;
	progressTime.textContent = `Time: ${progress.time || "00:00:00"}`;
	progressFps.textContent = `FPS: ${progress.fps || 0}`;
}

/**
 * Check if HLS cache exists for a video file
 *
 * @param filename
 * @param directory
 */
async function checkHlsCache(filename, directory) {
	try {
		const response = await fetch(
			OC.generateUrl("/apps/hyperviewer/cache/check"),
			{
				method: "POST",
				headers: {
					"Content-Type": "application/json",
					requesttoken: OC.requestToken
				},
				body: JSON.stringify({
					filename,
					directory
				})
			}
		);

		const result = await response.json();
		return result.exists ? result.cachePath : null;
	} catch (error) {
		console.error("Failed to check HLS cache:", error);
		return null;
	}
}

/**
 * Smart video player - automatically uses HLS if available, otherwise default player
 *
 * @param filename
 * @param directory
 * @param context
 */
async function playVideoSmart(filename, directory, context) {
	console.log(`🎬 Smart play: Checking HLS cache for: ${filename}`);

	try {
		// Check if HLS cache exists
		const cachePath = await checkHlsCache(filename, directory);

		if (cachePath) {
			console.log(`✅ HLS cache found, using Shaka Player`);
			// Load Shaka Player with HLS
			loadShakaPlayer(filename, cachePath, context, directory);
		} else {
			console.log(`ℹ️ No HLS cache, using default player`);
			// Fall back to default Nextcloud video player
			openWithDefaultPlayer(filename, directory, context);
		}
	} catch (error) {
		console.error("Error checking HLS cache:", error);
		// Fall back to default player on error
		openWithDefaultPlayer(filename, directory, context);
	}
}

/**
 * Open video with default Nextcloud player
 *
 * @param filename
 * @param directory
 * @param context
 */
function openWithDefaultPlayer(filename, directory, context) {
	console.log(`🎥 Opening with default player: ${filename}`);
	
	try {
		// Get the file list
		const fileList = context?.fileInfoModel?.fileList || context?.fileList || window.OCA?.Files?.App?.fileList;
		
		if (!fileList) {
			console.error('No fileList available');
			return;
		}
		
		// Find the file model
		const fileModel = fileList.files.find(f => f.name === filename);
		
		if (!fileModel) {
			console.error(`File not found in fileList: ${filename}`);
			return;
		}
		
		// Use Nextcloud Viewer directly
		console.log('🎬 Opening with Nextcloud Viewer');
		const filePath = directory === "/" ? `/${filename}` : `${directory}/${filename}`;
		
		if (window.OCA?.Viewer) {
			window.OCA.Viewer.open({
				path: filePath
			});
		} else {
			console.error('Viewer not available');
		}
	} catch (error) {
		console.error('Error opening with default player:', error);
	}
}

/**
 * Load Shaka Player in a modal
 *
 * @param {string} filename - Video filename
 * @param {string} cachePath - HLS cache path
 * @param {object} context - File context
 * @param {string} directory - Current directory path
 */
function loadShakaPlayer(filename, cachePath, context, directory) {
	const videoId = `hyperVideo_${Date.now()}`;

	// Ensure directory is set
	if (!directory) {
		directory = context?.dir || context?.fileList?.getCurrentDirectory() || "/";
	}

	// Get video playlist for navigation
	let videoPlaylist = [];
	let currentVideoIndex = 0;
	if (context?.fileInfoModel || context?.fileList) {
		const fileList = context.fileInfoModel?.fileList || context.fileList;
		if (fileList) {
			const files = fileList.files || [];
			// Filter video files (MOV and MP4)
			videoPlaylist = files
				.filter(file => 
					file.mimetype === 'video/quicktime' || 
					file.mimetype === 'video/mp4'
				)
				.map(file => file.name)
				.sort();
			currentVideoIndex = videoPlaylist.indexOf(filename);
			console.log(`📹 Video playlist: ${videoPlaylist.length} videos, current index: ${currentVideoIndex}`);
		}
	}

	// Create enhanced modal with clipping controls
	const modal = document.createElement("div");
	modal.style.cssText = `
        position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 10000;
        background: rgba(0,0,0,0.9); display: flex; flex-direction: column; align-items: center; justify-content: center;
        padding: 20px; box-sizing: border-box;
    `;

	modal.innerHTML = `
        <!-- Video Player Container (Shaka Player will be attached here) -->
        <div id="video-player-container" style="
            position: relative; width: min(90vw, 1200px); height: min(70vh, 600px);
            background: #000; border-radius: 8px 8px 0 0; overflow: visible; padding-bottom: 50px;
        ">
            <!-- Navigation Arrows (Desktop only) -->
            <button id="prev-video-btn" class="nav-arrow prev-arrow" style="
                position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
                width: 50px; height: 50px; border-radius: 50%; border: none;
                background: rgba(0,0,0,0.6); color: white; font-size: 24px;
                cursor: pointer; z-index: 1000; display: flex; align-items: center; justify-content: center;
                opacity: 0; transition: opacity 0.3s, background 0.2s;
            " title="Previous video (Shift+Left)">‹</button>
            
            <button id="next-video-btn" class="nav-arrow next-arrow" style="
                position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
                width: 50px; height: 50px; border-radius: 50%; border: none;
                background: rgba(0,0,0,0.6); color: white; font-size: 24px;
                cursor: pointer; z-index: 1000; display: flex; align-items: center; justify-content: center;
                opacity: 0; transition: opacity 0.3s, background 0.2s;
            " title="Next video (Shift+Right)">›</button>
            
            <video id="${videoId}" autoplay style="
                width: 100%; height: calc(100% - 50px); object-fit: contain; background: #000;
            "></video>
        </div>
        
        <!-- Clipping Controls Panel (Outside Shaka Player scope) -->
        <div id="clipping-panel" style="
            width: min(90vw, 1200px); background: rgba(20,20,20,0.95); border-radius: 0 0 8px 8px;
            padding: 15px; display: none; border-top: 1px solid #444; position: relative; z-index: 10002;
        ">
            <!-- Clip Mode Toggle -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 style="color: white; margin: 0; font-size: 16px;">📹 Video Clipping Mode</h3>
                <button id="exit-clip-mode" style="
                    background: #666; border: none; color: white; padding: 6px 12px; 
                    border-radius: 4px; cursor: pointer; font-size: 12px;">Exit Clip Mode</button>
            </div>
            
            <!-- Timeline with Clip Markers -->
            <div style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; color: #ccc; font-size: 12px; margin-bottom: 5px;">
                    <span>Timeline</span>
                    <span id="clip-duration">Clip Duration: 0:00</span>
                </div>
                <div id="timeline-container" style="position: relative; height: 40px; background: #333; border-radius: 4px; overflow: hidden; cursor: pointer;">
                    <div id="timeline-progress" style="height: 100%; background: #555; width: 0%; transition: width 0.1s;"></div>
                    <div id="clip-range" style="
                        position: absolute; top: 0; left: 0%; right: 0%; height: 100%; 
                        background: rgba(76, 175, 80, 0.2); z-index: 1;
                    "></div>
                    <div id="playback-cursor" style="
                        position: absolute; top: 0; left: 0%; width: 2px; height: 100%; 
                        background: #fff; box-shadow: 0 0 4px rgba(255,255,255,0.8); z-index: 4;
                    "></div>
                    <div id="start-marker" style="
                        position: absolute; top: -2px; left: 0%; width: 12px; height: 44px; 
                        background: #4CAF50; cursor: ew-resize; z-index: 3; border-radius: 2px;
                        display: flex; align-items: center; justify-content: center; color: white; font-size: 8px;
                    " title="Start time">
                        S
                        <div id="start-marker-tooltip" style="
                            position: absolute; bottom: 50px; left: 50%; transform: translateX(-50%);
                            background: rgba(0,0,0,0.9); color: white; padding: 4px 8px; border-radius: 4px;
                            font-size: 11px; white-space: nowrap; pointer-events: none; display: none;
                        ">0:00.000</div>
                    </div>
                    <div id="end-marker" style="
                        position: absolute; top: -2px; right: 0%; width: 12px; height: 44px; 
                        background: #f44336; cursor: ew-resize; z-index: 3; border-radius: 2px;
                        display: flex; align-items: center; justify-content: center; color: white; font-size: 8px;
                    " title="End time">
                        E
                        <div id="end-marker-tooltip" style="
                            position: absolute; bottom: 50px; left: 50%; transform: translateX(-50%);
                            background: rgba(0,0,0,0.9); color: white; padding: 4px 8px; border-radius: 4px;
                            font-size: 11px; white-space: nowrap; pointer-events: none; display: none;
                        ">0:00.000</div>
                    </div>
                </div>
            </div>
            
            <!-- Frame-Accurate Controls -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <!-- Start Time Controls -->
                <div style="background: rgba(76, 175, 80, 0.1); padding: 12px; border-radius: 6px; border: 1px solid #4CAF50;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <label style="color: #4CAF50; font-weight: bold; font-size: 14px;">🟢 Start Time</label>
                        <span id="start-time-display" style="color: white; font-family: monospace;">0:00.000</span>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <button id="start-frame-back" style="background: #4CAF50; border: none; color: white; padding: 6px 8px; border-radius: 3px; cursor: pointer; font-size: 11px;">⏪ -1f</button>
                        <button id="start-set-current" style="background: #4CAF50; border: none; color: white; padding: 6px 12px; border-radius: 3px; cursor: pointer; font-size: 11px;">Set Current</button>
                        <button id="start-frame-forward" style="background: #4CAF50; border: none; color: white; padding: 6px 8px; border-radius: 3px; cursor: pointer; font-size: 11px;">+1f ⏩</button>
                    </div>
                </div>
                
                <!-- End Time Controls -->
                <div style="background: rgba(244, 67, 54, 0.1); padding: 12px; border-radius: 6px; border: 1px solid #f44336;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <label style="color: #f44336; font-weight: bold; font-size: 14px;">🔴 End Time</label>
                        <span id="end-time-display" style="color: white; font-family: monospace;">0:00.000</span>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <button id="end-frame-back" style="background: #f44336; border: none; color: white; padding: 6px 8px; border-radius: 3px; cursor: pointer; font-size: 11px;">⏪ -1f</button>
                        <button id="end-set-current" style="background: #f44336; border: none; color: white; padding: 6px 12px; border-radius: 3px; cursor: pointer; font-size: 11px;">Set Current</button>
                        <button id="end-frame-forward" style="background: #f44336; border: none; color: white; padding: 6px 8px; border-radius: 3px; cursor: pointer; font-size: 11px;">+1f ⏩</button>
                    </div>
                </div>
            </div>
            
            <!-- Preview and Export Controls -->
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; gap: 10px;">
                    <button id="preview-clip" style="
                        background: #2196F3; border: none; color: white; padding: 8px 16px; 
                        border-radius: 4px; cursor: pointer; font-size: 14px;">🎬 Preview Clip</button>
                    <button id="reset-markers" style="
                        background: #666; border: none; color: white; padding: 8px 16px; 
                        border-radius: 4px; cursor: pointer; font-size: 14px;">🔄 Reset</button>
                </div>
                <button id="export-clip" style="
                    background: #FF9800; border: none; color: white; padding: 10px 20px; 
                    border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: bold;">📤 Export Clip</button>
            </div>
        </div>
        
        <!-- Top Controls (Over video player) -->
        <div style="position: absolute; top: 20px; left: 20px; z-index: 10003;">
            <div class="hls-player-badge" style="
                background: rgba(60, 60, 60, 0.9); color: #FF9800; 
                padding: 6px 12px; border-radius: 4px; font-size: 11px; font-weight: bold;
                letter-spacing: 0.5px; box-shadow: 0 2px 8px rgba(0,0,0,0.3);
                backdrop-filter: blur(4px); display: flex; align-items: center; gap: 6px;">
                <span style="font-size: 14px;">📡</span> HLS STREAMING
            </div>
        </div>
        
        <div style="position: absolute; top: 20px; right: 20px; display: flex; gap: 10px; z-index: 10003;">
            <button id="toggle-clip-mode" style="
                background: rgba(255, 152, 0, 0.9); border: none; color: white; 
                padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">✂️ Clip Video</button>
            <button id="open-default-player" style="
                background: rgba(33, 150, 243, 0.9); border: none; color: white; 
                padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; 
                display: flex; align-items: center; gap: 5px;" title="Open with default Nextcloud player">🎬 Default Player</button>
            <button class="close-btn" style="
                background: rgba(0,0,0,0.7); border: none; color: white; 
                width: 40px; height: 40px; border-radius: 50%; font-size: 18px; cursor: pointer;">✕</button>
        </div>
    `;

	const closeModal = () => {
		modal.remove();
		document.body.style.overflow = "";
		document.removeEventListener("keydown", handleKeydown);
	};

	const handleKeydown = e => {
		if (e.key === "Escape" || e.key === "Backspace") {
			closeModal();
		}
	};

	modal.onclick = e => {
		if (e.target === modal) {
			closeModal();
		}
	};

	document.body.appendChild(modal);
	document.body.style.overflow = "hidden";
	document.addEventListener("keydown", handleKeydown);

	const video = document.getElementById(videoId);

	// Clipping state
	let isClipMode = false;
	let startTime = 0;
	let endTime = 0;
	let videoDuration = 0;
	let selectedControl = null; // 'start' or 'end' for visual feedback
	let isDragging = false;
	let dragTarget = null;
	const videoFrameRate = 30; // Default, will be updated when video loads

	// Add close button event listener
	modal.querySelector(".close-btn").addEventListener("click", closeModal);

	// Add default player button event listener
	modal.querySelector("#open-default-player").addEventListener("click", () => {
		closeModal();
		openWithDefaultPlayer(filename, directory, context);
	});

	// Video navigation functionality
	const navigateToVideo = async (direction) => {
		if (videoPlaylist.length <= 1) return; // No other videos to navigate to
		
		// Calculate new index with loop-around
		let newIndex = currentVideoIndex + direction;
		if (newIndex < 0) newIndex = videoPlaylist.length - 1; // Loop to end
		if (newIndex >= videoPlaylist.length) newIndex = 0; // Loop to start
		
		const nextFilename = videoPlaylist[newIndex];
		console.log(`🔄 Navigating to video ${newIndex + 1}/${videoPlaylist.length}: ${nextFilename}`);
		
		// Close current modal
		closeModal();
		
		// Play next video with smart player
		await playVideoSmart(nextFilename, directory, context);
	};

	// Navigation arrow buttons (desktop)
	const prevBtn = modal.querySelector("#prev-video-btn");
	const nextBtn = modal.querySelector("#next-video-btn");
	const videoContainer = modal.querySelector("#video-player-container");
	
	// Hide arrows if only one video
	if (videoPlaylist.length <= 1) {
		prevBtn.style.display = "none";
		nextBtn.style.display = "none";
	} else {
		// Show arrows on hover (desktop only)
		videoContainer.addEventListener("mouseenter", () => {
			if (window.innerWidth > 768) { // Desktop only
				prevBtn.style.opacity = "0.7";
				nextBtn.style.opacity = "0.7";
			}
		});
		
		videoContainer.addEventListener("mouseleave", () => {
			prevBtn.style.opacity = "0";
			nextBtn.style.opacity = "0";
		});
		
		// Arrow hover effects
		prevBtn.addEventListener("mouseenter", () => {
			prevBtn.style.opacity = "1";
			prevBtn.style.background = "rgba(0,0,0,0.8)";
		});
		prevBtn.addEventListener("mouseleave", () => {
			prevBtn.style.background = "rgba(0,0,0,0.6)";
		});
		
		nextBtn.addEventListener("mouseenter", () => {
			nextBtn.style.opacity = "1";
			nextBtn.style.background = "rgba(0,0,0,0.8)";
		});
		nextBtn.addEventListener("mouseleave", () => {
			nextBtn.style.background = "rgba(0,0,0,0.6)";
		});
		
		// Click handlers
		prevBtn.addEventListener("click", (e) => {
			e.stopPropagation();
			navigateToVideo(-1);
		});
		
		nextBtn.addEventListener("click", (e) => {
			e.stopPropagation();
			navigateToVideo(1);
		});
		
		// Swipe detection for mobile
		let touchStartX = 0;
		let touchEndX = 0;
		const minSwipeDistance = 50;
		
		videoContainer.addEventListener("touchstart", (e) => {
			touchStartX = e.changedTouches[0].screenX;
		});
		
		videoContainer.addEventListener("touchend", (e) => {
			touchEndX = e.changedTouches[0].screenX;
			const swipeDistance = touchEndX - touchStartX;
			
			if (Math.abs(swipeDistance) > minSwipeDistance) {
				if (swipeDistance > 0) {
					// Swipe right = previous video
					navigateToVideo(-1);
				} else {
					// Swipe left = next video
					navigateToVideo(1);
				}
			}
		});
	}

	// Initialize Shaka Player
	shaka.polyfill.installAll();

	if (shaka.Player.isBrowserSupported()) {
		const player = new shaka.Player(video);

		// Configure Shaka UI with professional video editing controls
		const uiConfig = {
			controlPanelElements: [
				"play_pause",
				"time_and_duration",
				"mute",
				"volume",
				"spacer",
				"overflow_menu",
				"fullscreen"
			],
			overflowMenuButtons: [
				"picture_in_picture",
				"quality",
				"playback_speed",
				"save_video_frame",
				"statistics"
			],
			enableTooltips: true,
			addSeekBar: true,
			customContextMenu: true,
			playbackRates: [0.25, 0.5, 0.75, 1, 1.25, 1.5, 2],
			seekBarColors: {
				base: "rgba(255, 255, 255, 0.3)",
				buffered: "rgba(76, 175, 80, 0.4)",
				played: "rgb(255, 152, 0)" // Match orange theme
			}
		};

		const ui = new shaka.ui.Overlay(player, videoContainer, video);
		ui.configure(uiConfig);

		// Build manifest URL
		const encodedCachePath = encodeURIComponent(cachePath);
		const masterUrl = `${OC.generateUrl(
			"/apps/hyperviewer/hls"
		)}/${encodedCachePath}/master.m3u8`;
		const playlistUrl = `${OC.generateUrl(
			"/apps/hyperviewer/hls"
		)}/${encodedCachePath}/playlist.m3u8`;

		// Try master.m3u8 first, fallback to playlist.m3u8
		player.load(masterUrl).catch(() => player.load(playlistUrl));

		// Video event listeners
		video.addEventListener("loadedmetadata", () => {
			videoDuration = video.duration;
			endTime = videoDuration;
			updateTimelineMarkers();
			updateTimeDisplays();
		});

		video.addEventListener("timeupdate", () => {
			if (isClipMode) {
				updateTimelineProgress();
			}
		});
	}

	// Clipping functionality
	/**
	 *
	 */
	function toggleClipMode() {
		isClipMode = !isClipMode;
		const panel = modal.querySelector("#clipping-panel");
		const toggleBtn = modal.querySelector("#toggle-clip-mode");
		const videoContainer = modal.querySelector("#video-player-container");

		if (isClipMode) {
			panel.style.display = "block";
			videoContainer.style.borderRadius = "8px 8px 0 0";
			toggleBtn.textContent = "✂️ Exit Clip Mode";
			toggleBtn.style.background = "rgba(244, 67, 54, 0.9)";
			// Initialize markers
			startTime = 0;
			endTime = videoDuration;
			updateTimelineMarkers();
			updateTimeDisplays();
		} else {
			panel.style.display = "none";
			videoContainer.style.borderRadius = "8px";
			toggleBtn.textContent = "✂️ Clip Video";
			toggleBtn.style.background = "rgba(255, 152, 0, 0.9)";
		}
	}

	/**
	 * @param seconds
	 */
	function formatTime(seconds) {
		const mins = Math.floor(seconds / 60);
		const secs = Math.floor(seconds % 60);
		const ms = Math.floor((seconds % 1) * 1000);
		return `${mins}:${secs
			.toString()
			.padStart(2, "0")}.${ms.toString().padStart(3, "0")}`;
	}

	/**
	 *
	 */
	function updateTimeDisplays() {
		modal.querySelector("#start-time-display").textContent = formatTime(
			startTime
		);
		modal.querySelector("#end-time-display").textContent = formatTime(
			endTime
		);

		const duration = Math.max(0, endTime - startTime);
		const durationMins = Math.floor(duration / 60);
		const durationSecs = Math.floor(duration % 60);
		modal.querySelector(
			"#clip-duration"
		).textContent = `Clip Duration: ${durationMins}:${durationSecs
			.toString()
			.padStart(2, "0")}`;
	}

	/**
	 *
	 */
	function updateTimelineMarkers() {
		if (videoDuration === 0) return;

		const startPercent = (startTime / videoDuration) * 100;
		const endPercent = (endTime / videoDuration) * 100;

		// Update markers (adjust for marker width)
		modal.querySelector(
			"#start-marker"
		).style.left = `calc(${startPercent}% - 6px)`;
		modal.querySelector(
			"#end-marker"
		).style.left = `calc(${endPercent}% - 6px)`;
		modal.querySelector("#clip-range").style.left = `${startPercent}%`;
		modal.querySelector("#clip-range").style.width = `${endPercent -
			startPercent}%`;
	}

	/**
	 *
	 */
	function updateTimelineProgress() {
		if (videoDuration === 0) return;
		const progressPercent = (video.currentTime / videoDuration) * 100;
		modal.querySelector(
			"#timeline-progress"
		).style.width = `${progressPercent}%`;

		// Update playback cursor
		modal.querySelector(
			"#playback-cursor"
		).style.left = `calc(${progressPercent}% - 1px)`;
	}

	/**
	 * @param selected
	 */
	function updateControlSelection(selected) {
		selectedControl = selected;

		// Update start control styling
		const startControl = modal
			.querySelector("#start-time-display")
			.closest("div")
			.closest("div");
		if (selected === "start") {
			startControl.style.background = "rgba(76, 175, 80, 0.3)";
			startControl.style.borderColor = "#4CAF50";
			startControl.style.borderWidth = "2px";
		} else {
			startControl.style.background = "rgba(76, 175, 80, 0.1)";
			startControl.style.borderColor = "#4CAF50";
			startControl.style.borderWidth = "1px";
		}

		// Update end control styling
		const endControl = modal
			.querySelector("#end-time-display")
			.closest("div")
			.closest("div");
		if (selected === "end") {
			endControl.style.background = "rgba(244, 67, 54, 0.3)";
			endControl.style.borderColor = "#f44336";
			endControl.style.borderWidth = "2px";
		} else {
			endControl.style.background = "rgba(244, 67, 54, 0.1)";
			endControl.style.borderColor = "#f44336";
			endControl.style.borderWidth = "1px";
		}
	}

	/**
	 * @param direction
	 * @param isStart
	 */
	function stepFrame(direction, isStart = true) {
		const frameTime = 1 / videoFrameRate;
		const newTime = video.currentTime + direction * frameTime;
		const clampedTime = Math.max(0, Math.min(videoDuration, newTime));

		video.currentTime = clampedTime;

		if (isStart) {
			startTime = clampedTime;
		} else {
			endTime = clampedTime;
		}

		updateTimelineMarkers();
		updateTimeDisplays();
	}

	/**
	 * @param isStart
	 */
	function setCurrentTime(isStart = true) {
		if (isStart) {
			startTime = video.currentTime;
			updateControlSelection("start");
		} else {
			endTime = video.currentTime;
			updateControlSelection("end");
		}

		// Ensure start < end
		if (startTime >= endTime) {
			if (isStart) {
				endTime = Math.min(videoDuration, startTime + 1);
			} else {
				startTime = Math.max(0, endTime - 1);
			}
		}

		updateTimelineMarkers();
		updateTimeDisplays();
	}

	/**
	 *
	 */
	function previewClip() {
		video.currentTime = startTime;
		video.play();

		// Stop at end time
		const checkTime = () => {
			if (video.currentTime >= endTime) {
				video.pause();
			} else {
				requestAnimationFrame(checkTime);
			}
		};
		requestAnimationFrame(checkTime);
	}

	/**
	 *
	 */
	function resetMarkers() {
		startTime = 0;
		endTime = videoDuration;
		updateTimelineMarkers();
		updateTimeDisplays();
	}

	/**
	 *
	 */
	function showExportModal() {
		// Determine default export path
		const isInHome =
			context.dir === "/" ||
			context.dir.startsWith("/home") ||
			context.dir.startsWith("/Users");
		const defaultPath = isInHome ? "../exports" : "../exports";

		// Create simplified export modal
		const exportModal = document.createElement("div");
		exportModal.style.cssText = `
			position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 10002;
			background: rgba(0,0,0,0.8); display: flex; align-items: center; justify-content: center;
		`;

		exportModal.innerHTML = `
			<div style="
				background: #2a2a2a; border-radius: 8px; padding: 24px; max-width: 400px; width: 90%;
				color: white; box-shadow: 0 4px 20px rgba(0,0,0,0.5);
			">
				<h3 style="margin: 0 0 20px 0; color: #FF9800;">📤 Export Video Clip</h3>
				
				<div style="margin-bottom: 20px;">
					<label style="display: block; margin-bottom: 8px; font-weight: bold;">Export Location:</label>
					<input id="export-path" type="text" value="${defaultPath}" style="
						width: 100%; padding: 12px; border: 1px solid #555; border-radius: 4px; 
						background: #333; color: white; font-family: monospace; box-sizing: border-box;
					">
					<small style="color: #ccc; margin-top: 4px; display: block;">
						Relative to video location. Directory will be created if it doesn't exist.
					</small>
				</div>
				
				<div style="margin-bottom: 20px; padding: 12px; background: rgba(76, 175, 80, 0.1); border-radius: 4px; border: 1px solid #4CAF50;">
					<div style="font-size: 14px; margin-bottom: 8px;">📊 Clip Details:</div>
					<div style="font-family: monospace; font-size: 12px; color: #ccc;">
						<div>Start: ${formatTime(startTime)}</div>
						<div>End: ${formatTime(endTime)}</div>
						<div>Duration: ${formatTime(endTime - startTime)}</div>
						<div style="color: #4CAF50; margin-top: 8px;">✨ Original quality lossless cut</div>
					</div>
				</div>
				
				<div style="display: flex; justify-content: flex-end; gap: 12px;">
					<button id="cancel-export" style="
						background: #666; border: none; color: white; padding: 10px 16px; 
						border-radius: 4px; cursor: pointer;">Cancel</button>
					<button id="confirm-export" style="
						background: #FF9800; border: none; color: white; padding: 10px 16px; 
						border-radius: 4px; cursor: pointer; font-weight: bold;">🚀 Start Export</button>
				</div>
			</div>
		`;

		document.body.appendChild(exportModal);

		// Focus on export path input
		const exportPathInput = exportModal.querySelector("#export-path");
		exportPathInput.focus();
		exportPathInput.select();

		// Export modal event listeners
		exportModal
			.querySelector("#cancel-export")
			.addEventListener("click", () => {
				exportModal.remove();
			});

		// Handle Enter key to start export
		const handleExport = () => {
			const exportPath = exportModal
				.querySelector("#export-path")
				.value.trim();
			if (!exportPath) {
				OC.dialogs.alert(
					"Please enter an export location.",
					"Export Error"
				);
				return;
			}

			// Start the export process
			startExport(exportPath);
			exportModal.remove();
		};

		exportModal
			.querySelector("#confirm-export")
			.addEventListener("click", handleExport);

		// Enter key support
		exportPathInput.addEventListener("keydown", e => {
			if (e.key === "Enter") {
				e.preventDefault();
				handleExport();
			}
		});

		exportModal.addEventListener("click", e => {
			if (e.target === exportModal) {
				exportModal.remove();
			}
		});
	}

	// Export functionality
	/**
	 * @param exportPath
	 */
	async function startExport(exportPath) {
		try {
			// Generate unique filename for the clip
			const timestamp = new Date()
				.toISOString()
				.replace(/[:.]/g, "-")
				.slice(0, -5);
			const baseName = filename.replace(/\.[^/.]+$/, ""); // Remove extension
			const extension = filename.split(".").pop();
			const clipFilename = `${baseName}_clip_${timestamp}.${extension}`;

			// Show immediate notification
			showExportNotification(clipFilename, exportPath);

			// Get the full file path for the original video
			const originalPath =
				context.dir === "/"
					? `/${filename}`
					: `${context.dir}/${filename}`;

			// Call the export API
			const response = await fetch(
				OC.generateUrl("/apps/hyperviewer/api/export-clip"),
				{
					method: "POST",
					headers: {
						"Content-Type": "application/json",
						requesttoken: OC.requestToken
					},
					body: JSON.stringify({
						originalPath,
						startTime,
						endTime,
						exportPath,
						clipFilename
					})
				}
			);

			const result = await response.json();

			if (!response.ok) {
				throw new Error(result.error || "Export failed");
			}

			console.log("✅ Export started successfully:", result);
		} catch (error) {
			console.error("❌ Export failed:", error);
			OC.dialogs.alert(`Export failed: ${error.message}`, "Export Error");
		}
	}

	/**
	 * @param clipFilename
	 * @param exportPath
	 */
	function showExportNotification(clipFilename, exportPath) {
		// Create notification
		const notification = document.createElement("div");
		notification.style.cssText = `
			position: fixed; top: 20px; right: 20px; z-index: 10003;
			background: #4CAF50; color: white; padding: 16px 20px; border-radius: 8px;
			box-shadow: 0 4px 12px rgba(0,0,0,0.3); max-width: 400px;
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
		`;

		notification.innerHTML = `
			<div style="display: flex; align-items: center; gap: 12px;">
				<div style="font-size: 20px;">✂️</div>
				<div>
					<div style="font-weight: bold; margin-bottom: 4px;">Export Started</div>
					<div style="font-size: 13px; opacity: 0.9;">
						Creating: ${clipFilename}<br>
						Location: ${exportPath}
					</div>
				</div>
			</div>
		`;

		document.body.appendChild(notification);

		// Auto-remove after 5 seconds
		setTimeout(() => {
			if (notification.parentNode) {
				notification.style.opacity = "0";
				notification.style.transform = "translateX(100%)";
				notification.style.transition = "all 0.3s ease";
				setTimeout(() => notification.remove(), 300);
			}
		}, 5000);
	}

	// Fixed frame adjustment functions
	/**
	 * @param deltaSeconds
	 */
	function adjustStartTime(deltaSeconds) {
		const newStartTime = Math.max(
			0,
			Math.min(startTime + deltaSeconds, endTime - 0.1)
		);
		startTime = newStartTime;
		video.currentTime = newStartTime; // Seek video to show the frame
		updateTimelineMarkers();
		updateTimeDisplays();
	}

	/**
	 * @param deltaSeconds
	 */
	function adjustEndTime(deltaSeconds) {
		const newEndTime = Math.max(
			startTime + 0.1,
			Math.min(endTime + deltaSeconds, videoDuration)
		);
		endTime = newEndTime;
		video.currentTime = newEndTime; // Seek video to show the frame
		updateTimelineMarkers();
		updateTimeDisplays();
	}

	// Event listeners for clipping controls
	modal
		.querySelector("#toggle-clip-mode")
		.addEventListener("click", toggleClipMode);
	modal
		.querySelector("#exit-clip-mode")
		.addEventListener("click", toggleClipMode);

	// Start time controls - fixed to adjust markers, not seek to current time
	modal
		.querySelector("#start-frame-back")
		.addEventListener("click", () => adjustStartTime(-1 / 30)); // -1 frame at 30fps
	modal
		.querySelector("#start-frame-forward")
		.addEventListener("click", () => adjustStartTime(1 / 30)); // +1 frame at 30fps
	modal
		.querySelector("#start-set-current")
		.addEventListener("click", () => setCurrentTime(true));

	// End time controls - fixed to adjust markers, not seek to current time
	modal
		.querySelector("#end-frame-back")
		.addEventListener("click", () => adjustEndTime(-1 / 30)); // -1 frame at 30fps
	modal
		.querySelector("#end-frame-forward")
		.addEventListener("click", () => adjustEndTime(1 / 30)); // +1 frame at 30fps
	modal
		.querySelector("#end-set-current")
		.addEventListener("click", () => setCurrentTime(false));

	// Preview and export controls
	modal.querySelector("#preview-clip").addEventListener("click", previewClip);
	modal
		.querySelector("#reset-markers")
		.addEventListener("click", resetMarkers);
	modal
		.querySelector("#export-clip")
		.addEventListener("click", showExportModal);

	// Timeline interactions
	const timelineContainer = modal.querySelector("#timeline-container");
	const startMarker = modal.querySelector("#start-marker");
	const endMarker = modal.querySelector("#end-marker");

	// Timeline click to seek
	timelineContainer.addEventListener("click", e => {
		if (isDragging) return; // Don't seek while dragging

		const rect = timelineContainer.getBoundingClientRect();
		const clickX = e.clientX - rect.left;
		const clickPercent = Math.max(0, Math.min(1, clickX / rect.width));
		const seekTime = clickPercent * videoDuration;
		video.currentTime = seekTime;
	});

	// Marker dragging functionality
	/**
	 * @param marker
	 * @param isStart
	 */
	function setupMarkerDragging(marker, isStart) {
		marker.addEventListener("mousedown", e => {
			e.preventDefault();
			e.stopPropagation();
			isDragging = true;
			dragTarget = isStart ? "start" : "end";
			updateControlSelection(dragTarget);

			const handleMouseMove = e => {
				if (!isDragging) return;

				const rect = timelineContainer.getBoundingClientRect();
				const mouseX = e.clientX - rect.left;
				const percent = Math.max(0, Math.min(1, mouseX / rect.width));
				const newTime = percent * videoDuration;

				if (isStart) {
					startTime = Math.min(newTime, endTime - 0.1); // Keep 0.1s minimum gap
				} else {
					endTime = Math.max(newTime, startTime + 0.1);
				}

				// Update video position to show frame
				video.currentTime = newTime;

				updateTimelineMarkers();
				updateTimeDisplays();
			};

			const handleMouseUp = () => {
				isDragging = false;
				dragTarget = null;
				document.removeEventListener("mousemove", handleMouseMove);
				document.removeEventListener("mouseup", handleMouseUp);
			};

			document.addEventListener("mousemove", handleMouseMove);
			document.addEventListener("mouseup", handleMouseUp);
		});

		// Visual feedback and tooltip on hover
		const tooltip = marker.querySelector('div[id$="-tooltip"]');

		marker.addEventListener("mouseenter", () => {
			if (!isDragging) {
				marker.style.transform = "scale(1.1)";
				marker.style.boxShadow = "0 0 8px rgba(255,255,255,0.5)";
				if (tooltip) {
					tooltip.style.display = "block";
					tooltip.textContent = formatTime(
						isStart ? startTime : endTime
					);
				}
			}
		});

		marker.addEventListener("mouseleave", () => {
			if (!isDragging) {
				marker.style.transform = "scale(1)";
				marker.style.boxShadow = "none";
				if (tooltip) {
					tooltip.style.display = "none";
				}
			}
		});
	}

	setupMarkerDragging(startMarker, true);
	setupMarkerDragging(endMarker, false);

	// Keyboard controls for video clipping
	/**
	 * @param e
	 */
	function handleKeyDown(e) {
		// Handle ESC to close modal (works always)
		if (e.key === "Escape") {
			e.preventDefault();
			closeModal();
			return;
		}

		// Handle spacebar for play/pause (works always)
		if (e.key === " " || e.key === "Spacebar") {
			e.preventDefault();
			if (video.paused) {
				video.play();
			} else {
				video.pause();
			}
			return;
		}

		// Handle video navigation with Shift+Arrow keys (works always, unless typing in input)
		if (e.shiftKey && e.key === "ArrowRight" && !e.target.matches("input, textarea")) {
			e.preventDefault();
			if (videoPlaylist.length > 1) {
				navigateToVideo(1); // Next video
			}
			return;
		}

		if (e.shiftKey && e.key === "ArrowLeft" && !e.target.matches("input, textarea")) {
			e.preventDefault();
			if (videoPlaylist.length > 1) {
				navigateToVideo(-1); // Previous video
			}
			return;
		}

		// Only handle keys when clipping mode is active
		if (
			!modal.querySelector("#clipping-panel").style.display ||
			modal.querySelector("#clipping-panel").style.display === "none"
		) {
			return;
		}

		// Prevent default for our handled keys
		const handledKeys = ["ArrowLeft", "ArrowRight", "Enter"];
		if (
			handledKeys.includes(e.key) ||
			(e.key === "Enter" && (e.ctrlKey || e.metaKey))
		) {
			e.preventDefault();
		}

		switch (e.key) {
			case "ArrowLeft":
				// Move playback cursor back one frame
				if (video.currentTime > 0) {
					video.currentTime = Math.max(0, video.currentTime - 1 / 30);
				}
				break;

			case "ArrowRight":
				// Move playback cursor forward one frame
				if (video.currentTime < video.duration) {
					video.currentTime = Math.min(
						video.duration,
						video.currentTime + 1 / 30
					);
				}
				break;

			case "Enter":
				if (e.ctrlKey || e.metaKey) {
					// Ctrl/Cmd + Enter: Open export dialog
					showExportModal();
				} else {
					// Just Enter: Start export (if export modal is open)
					const exportModal = document.querySelector(
						"#confirm-export"
					);
					if (exportModal) {
						exportModal.click();
					}
				}
				break;
		}
	}

	// Add keyboard event listener
	document.addEventListener("keydown", handleKeyDown);

	// Clean up keyboard listener when modal is closed
	const originalClose = modal.remove;
	modal.remove = function() {
		document.removeEventListener("keydown", handleKeyDown);
		originalClose.call(this);
	};
}
