/**
 * Files app integration for Hyper Viewer (Nextcloud 35, @nextcloud/files v4)
 */

import "./styles/cache-modal.css";
import { registerHyperViewerFileActions } from "./files/register-file-actions.js";
import { registerHlsIconChip } from "./files/hls-chip.js";
import { folderPath } from "./files/nodePaths.js";
import { showError, showSuccess } from "@nextcloud/dialogs";
import { toastQueued } from "./shared/toast-queue.js";
import { fetchJson } from "./shared/api.js";

console.log("🎬 Hyper Viewer Files integration loading…");

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
 * @param {import('@nextcloud/files').INode} node Folder node
 * @param {import('@nextcloud/files').IFolder} folder Current Files view folder
 */
async function openDirectoryCacheGenerationDialog(node, folder) {
	const parent = folderPath(folder, node);
	const fullPath =
		parent === "/" ? `/${node.basename}` : `${parent}/${node.basename}`;
	console.log("🔧 Opening directory cache generation dialog for:", fullPath);

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
		const result = await fetchJson(
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

		if (result.success) {
			return result.files || [];
		} else {
			throw new Error(result.error || "Failed to discover video files");
		}
	} catch (error) {
		console.error("Failed to discover video files:", error);
		showError(`Failed to scan directory: ${error.message}`);
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
				await fetchJson(
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
				console.log("✅ Directory registered for auto-generation");
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
			showSuccess(
				`Auto-generation enabled for ${directoryPath}. New videos will be queued for HLS.`
			);
		}
	} catch (error) {
		console.error("Failed to start directory cache generation:", error);
		showError(`Failed to start directory processing: ${error.message}`);
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
		overwriteExisting,
		resolutions
	};

	const filesData = files.map(file => ({
		filename: file.filename,
		fileid: file.fileid || file.context?.fileInfoModel?.id || 0,
		directory:
			directoryPath ||
			file.directory ||
			file.context?.dir ||
			"/"
	}));

	console.log("Cache generation options:", options);
	console.log("Files data:", filesData);
	
	try {
		// Send to backend for processing
		const result = await fetchJson(
			OC.generateUrl("/apps/hyperviewer/cache/generate"),
			{
				method: "POST",
				headers: {
					"Content-Type": "application/json",
					requesttoken: OC.requestToken
				},
				body: JSON.stringify({
					files: filesData,
					overwriteExisting: options.overwriteExisting,
					resolutions: options.resolutions
				})
			}
		);

		if (result.success) {
			const ids = result.jobIds || (result.jobId ? [result.jobId] : []);
			if (ids.length === 1) {
				toastQueued(ids[0]);
			} else if (ids.length > 1) {
				showSuccess(`Queued ${ids.length} jobs (#${ids.join(", #")}). Open Settings → Hyper Viewer → Queue.`);
			} else {
				showSuccess(
					`Cache generation queued for ${files.length} file(s). Start the worker to process jobs.`
				);
			}
		} else {
			throw new Error(result.error || "Unknown error occurred");
		}
	} catch (error) {
		console.error("Failed to start HLS cache generation:", error);
		showError(`Failed to start cache generation: ${error.message}`);
	}
}

async function bootstrapFilesIntegration() {
	let caps = {
		can_use_app: true,
		can_generate_hls: true,
		can_export_clip: true,
		can_create_public_link: true,
	};
	try {
		caps = await fetchJson(
			OC.generateUrl("/apps/hyperviewer/api/user/capabilities"),
			{ headers: { requesttoken: OC.requestToken } },
		);
	} catch (error) {
		console.warn("Hyper Viewer capabilities unavailable:", error);
	}
	if (!caps.can_use_app) {
		console.log("Hyper Viewer Files integration disabled for this account");
		return;
	}
	registerHyperViewerFileActions({
		openCacheGenerationDialog,
		openDirectoryCacheGenerationDialog,
		caps,
	});
	registerHlsIconChip();
	console.log("✅ Hyper Viewer file actions registered (@nextcloud/files v4)");
}

bootstrapFilesIntegration();
