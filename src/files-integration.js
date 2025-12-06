/**
 * Files app integration for Hyper Viewer (Nextcloud 25 compatible)
 * Adds "Generate HLS Cache" action to MOV and MP4 files
 */

import "./styles/cache-modal.css";
import { playVideoSmart } from "./modules/video-player.js";

console.log("🎬 Hyper Viewer Files integration loading...");

// Only initialize if we're in the Files app
/**
 *
 */
function isInFilesApp() {
	// Check if we're in the Files app by looking for Files-specific elements
	return (
		document.body.id === "body-user" &&
		(window.location.pathname.includes("/apps/files") ||
			document.querySelector("#app-content-files") !== null ||
			document.querySelector(".files-filestable") !== null)
	);
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
	console.log("🎨 Initializing HLS badges for file list...");

	// Inject CSS for HLS badges (works for both list and grid view)
	const style = document.createElement("style");
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
		console.log("🔍 Checking for videos to badge...");

		const directory =
			window.OCA?.Files?.App?.fileList?.getCurrentDirectory() || "/";
		const fileList = window.OCA?.Files?.App?.fileList;

		if (!fileList || !fileList.files) {
			console.warn("⚠️ No fileList available");
			return;
		}

		// Get video files from fileList (the source of truth)
		const videoFiles = fileList.files.filter(
			file =>
				file.mimetype === "video/quicktime" ||
				file.mimetype === "video/mp4"
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
				OC.generateUrl("/apps/hyperviewer/cache/batch-check"),
				{
					method: "POST",
					headers: {
						"Content-Type": "application/json",
						requesttoken: OC.requestToken
					},
					body: JSON.stringify({
						directory,
						filenames
					})
				}
			);

			if (!response.ok) {
				console.error("Batch check failed:", response.status);
				return;
			}

			const result = await response.json();
			const cachedVideos = new Set(result.cachedVideos || []);

			console.log(
				`✅ Batch check complete: ${cachedVideos.size}/${filenames.length} videos have HLS cache`
			);

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
				let fileElement = document.querySelector(
					`tr[data-file="${escapedFilename}"]`
				); // List view
				if (!fileElement) {
					fileElement = document.querySelector(
						`[data-name="${escapedFilename}"]`
					); // Grid view
				}
				if (!fileElement) {
					// Try without escaping for older Nextcloud versions
					fileElement = document.querySelector(
						`tr[data-file="${filename}"]`
					);
				}

				if (!fileElement) {
					// Skip silently - file might not be visible in current view
					continue;
				}

				// Skip if badge already exists
				if (fileElement.querySelector(".hls-badge")) {
					continue;
				}

				// Find thumbnail container in the element
				const thumbnailContainer =
					fileElement.querySelector("td.filename .thumbnail") || // List view
					fileElement.querySelector(".thumbnail") || // Grid view
					fileElement.querySelector(".files-list__row-icon") || // New list view
					fileElement.querySelector(".icon");

				if (thumbnailContainer) {
					// Ensure container has relative positioning
					thumbnailContainer.style.position = "relative";

					const badge = document.createElement("div");
					badge.className = "hls-badge";
					badge.textContent = "HLS";
					badge.title = "HLS cache available";
					thumbnailContainer.appendChild(badge);

					console.log(`✅ Added HLS badge to: ${filename}`);
				}
			}
		} catch (error) {
			console.error("Failed to batch check HLS cache:", error);
		}
	}

	// Update badges with delay for initial load
	setTimeout(() => {
		console.log("🚀 Running initial badge update...");
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
		const listContainer = document.querySelector(
			"#filestable tbody.files-fileList"
		);
		if (listContainer) {
			observer.observe(listContainer, {
				childList: true,
				subtree: true
			});
			console.log("👀 Observing list view for changes");
		}

		// Grid view container
		const gridContainer = document.querySelector("section.files-grid");
		if (gridContainer) {
			observer.observe(gridContainer, {
				childList: true,
				subtree: true
			});
			console.log("👀 Observing grid view for changes");
		}

		// If containers don't exist yet, try again
		if (!listContainer && !gridContainer) {
			setTimeout(observeFileList, 1000);
		}
	};

	observeFileList();

	// Hook into directory changes
	if (window.OCA?.Files?.App?.fileList) {
		const originalChangeDirectory =
			window.OCA.Files.App.fileList.changeDirectory;
		window.OCA.Files.App.fileList.changeDirectory = function(...args) {
			const result = originalChangeDirectory.apply(this, args);
			setTimeout(() => {
				console.log("📂 Directory changed, updating badges...");
				updateHlsBadges();
			}, 1500);
			return result;
		};
	}

	console.log("✅ HLS badge system initialized");
}

/**
 * Open unified cache generation dialog for files or directories
 *
 * @param files Array of file objects with filename and context
 */
function openCacheGenerationDialog(files) {
	// For single files, call the unified function
	openUnifiedCacheDialog({ type: "files", files });
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
	const isDirectory = type === "directory";

	// Prepare content based on type
	let title, infoContent, fileCount, fileList;

	if (isDirectory) {
		title = "Generate HLS Cache (Directory)";
		fileCount = videoFiles.length;
		fileList = videoFiles.map(f => f.filename).join(", ");
		infoContent = `
			<div class="directory-info-card">
				<div class="info-item">
					<strong>Directory:</strong> ${directoryPath}
				</div>
				<div class="info-item ${fileCount === 0 ? "no-videos" : ""}">
					<strong>Video files found:</strong> ${fileCount}
					${
						fileCount > 0
							? `<div class="file-list">${fileList}</div>`
							: '<div class="no-videos-text">No videos currently, but auto-generation can monitor for new files</div>'
					}
				</div>
			</div>
		`;
	} else {
		title = "Generate HLS Cache";
		fileList = files.map(f => f.filename).join(", ");
		infoContent = `
			<div class="directory-info-card">
				<div class="info-item">
					<strong>Files:</strong> ${fileList}
				</div>
			</div>
		`;
	}

	// Create modal
	const modal = document.createElement("div");
	modal.className = "hyper-viewer-cache-modal";
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
							<input type="checkbox" name="resolution" value="360p" checked>
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
						${
							isDirectory
								? `
								<label class="option-item highlight">
									<input type="checkbox" id="enable_auto_generation">
									<div class="option-content">
										<span class="option-title">Enable automatic generation</span>
										<span class="option-desc">Monitor directory for new videos and auto-generate HLS cache</span>
									</div>
								</label>
							`
								: ""
						}
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
	document.body.style.overflow = "hidden";

	// Show modal with animation
	requestAnimationFrame(() => {
		modal.classList.add("show");
	});

	// Determine directory path for display
	const displayPath = isDirectory 
		? directoryPath 
		: (files[0]?.context?.dir || files[0]?.context?.fileList?.getCurrentDirectory() || "/");

	// Populate cache location options
	const container = modal.querySelector("#cache-locations-container");
	container.innerHTML = `
		<label class="option-item">
			<input type="radio" name="cache_location" value="relative" checked>
			<div class="option-content">
				<span class="option-title">Relative (Parent Directory)</span>
				<span class="option-desc">Cache stored in: <strong>${displayPath}/.cached_hls</strong></span>
			</div>
		</label>
		<label class="option-item">
			<input type="radio" name="cache_location" value="home">
			<div class="option-content">
				<span class="option-title">Home (User Root)</span>
				<span class="option-desc">Cache stored in your home directory (~/.cached_hls).</span>
			</div>
		</label>
	`;

	// Handle escape key
	const handleKeydown = e => {
		if (e.key === "Escape") {
			closeModal();
		}
	};

	// Close functionality
	const closeModal = () => {
		modal.classList.remove("show");
		document.removeEventListener("keydown", handleKeydown);
		setTimeout(() => {
			document.body.style.overflow = "";
			if (modal.parentNode) {
				modal.parentNode.removeChild(modal);
			}
		}, 300);
	};

	// Event listeners
	modal
		.querySelector(".hyper-viewer-close")
		.addEventListener("click", closeModal);
	modal
		.querySelector(".hyper-viewer-overlay")
		.addEventListener("click", closeModal);
	modal.querySelector(".btn-cancel").addEventListener("click", closeModal);

	modal.querySelector(".btn-confirm").addEventListener("click", () => {
		if (isDirectory) {
			startDirectoryCacheGeneration(videoFiles, directoryPath);
		} else {
			startCacheGeneration(files);
		}
		closeModal();
	});

	document.addEventListener("keydown", handleKeydown);
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
		type: "directory",
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

	// Get selected cache location type
	const selectedLocationRadio = document.querySelector(
		'input[name="cache_location"]:checked'
	);
	if (!selectedLocationRadio) {
		OC.dialogs.alert("Please select a cache location", "Error");
		return;
	}

	const locationType = selectedLocationRadio.value;
	if (!locationType) {
		OC.dialogs.alert("Invalid cache location selected", "Error");
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
			: ["720p", "480p", "360p", "240p"];

	const options = {
		locationType,
		overwriteExisting,
		resolutions,
		enableAutoGeneration,
		directoryPath
	};

	console.log("Directory cache generation options:", options);

	try {
		// Only start cache generation if there are files to process
		if (videoFiles.length > 0) {
			await startCacheGeneration(videoFiles, directoryPath);
		}

		// If auto-generation is enabled, register the directory for monitoring
		if (enableAutoGeneration) {
			try {
				const registerResponse = await fetch(
					OC.generateUrl(
						"/apps/hyperviewer/cache/register-auto-generation"
					),
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
					console.error(
						"❌ Failed to register directory for auto-generation"
					);
				}
			} catch (error) {
				console.error(
					"Error registering directory for auto-generation:",
					error
				);
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
 * @param directoryPath Optional directory path (if called from directory cache generation)
 */
async function startCacheGeneration(files, directoryPath = null) {
	console.log(
		"Starting HLS cache generation for:",
		files.map(f => f.filename)
	);

	// Get selected cache location type
	const selectedLocationRadio = document.querySelector(
		'input[name="cache_location"]:checked'
	);
	if (!selectedLocationRadio) {
		OC.dialogs.alert("Please select a cache location", "Error");
		return;
	}

	const locationType = selectedLocationRadio.value;
	if (!locationType) {
		OC.dialogs.alert("Invalid cache location selected", "Error");
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
		locationType,
		overwriteExisting,
		resolutions
	};

	// Prepare files data for backend
	const filesData = files.map(file => ({
		filename: file.filename,
		// Prioritize file's own directory (from discovery), then provided directoryPath, then context
		directory:
			file.directory ||
			directoryPath ||
			file.context?.dir ||
			file.context?.fileList?.getCurrentDirectory() ||
			"/"
	}));

	console.log("Cache generation options:", options);
	console.log("Files data:", filesData);
	
	try {
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
					locationType: options.locationType,
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
				} file(s).\n\nCache location: ${
					filesData.directory
				}\n\nResolutions: ${options.resolutions.join(
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
