/**
 * Video player module - Shaka Player integration with clipping functionality
 */

import shaka from "shaka-player/dist/shaka-player.ui.js";
import "shaka-player/dist/controls.css";
import playerModalTemplate from "../templates/player-modal.html";
import exportModalTemplate from "../templates/export-modal.html";

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
export async function playVideoSmart(filename, directory, context) {
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

	// Replace template variables using string replacement (CSP-safe)
	modal.innerHTML = playerModalTemplate.replace(/\$\{videoId\}/g, videoId);

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
	
	// Extract frame from original file and display it as overlay
	async function extractAndDisplayFrame(timestamp, targetVideo = video) {
		try {
			console.log("🖼️ Extracting frame at:", timestamp);
			const response = await fetch(OC.generateUrl("/apps/hyperviewer/api/extract-frame"), {
				method: "POST",
				headers: {
					"Content-Type": "application/json",
					requesttoken: OC.requestToken
				},
				body: JSON.stringify({
					filename,
					directory,
					timestamp
				})
			});
			console.log("📡 Response status:", response.status, response.statusText);
			console.log("📡 Response headers:", {
				contentType: response.headers.get('Content-Type'),
				contentLength: response.headers.get('Content-Length'),
				ffmpegTime: response.headers.get('X-Frame-Extraction-Time')
			});
		
			if (response.ok) {
				const contentType = response.headers.get('Content-Type');
				
				// Check if it's an image or JSON error
				if (contentType && contentType.startsWith('image/')) {
					console.log("📦 Receiving binary image, content-length:", response.headers.get('Content-Length'));
					
					const blob = await response.blob();
					console.log("✅ Blob received, size:", blob.size, "type:", blob.type);
					
					// Create object URL from blob
					const frameUrl = URL.createObjectURL(blob);
					
					// Create and display frame image overlay
					const frameImg = document.createElement("img");
					frameImg.src = frameUrl;
					// Use the exact same styles as the video element for precise alignment
					frameImg.style.cssText = `
						width: 100%;
						height: calc(100% - 50px);
						object-fit: contain;
						background: #000;
						position: absolute;
						top: 0;
						left: 0;
						pointer-events: none;
					`;
					frameImg.id = "pause-frame-display";
					
					// Append to videoContainer (not hide video, overlay it)
					videoContainer.appendChild(frameImg);
					
					// Store frame data for cleanup
					targetVideo._pauseFrameUrl = frameUrl;
					targetVideo._pauseFrameImg = frameImg;
				} else {
					// Might be JSON error response
					const text = await response.text();
					console.log("📦 Response text length:", text.length, "First 200 chars:", text.substring(0, 200));
					
					try {
						const data = JSON.parse(text);
						console.error("❌ Error from backend:", data);
					} catch (parseError) {
						console.error("❌ Failed to parse response:", parseError, "Response:", text);
					}
				}
			} else {
				const errorText = await response.text();
				console.error("❌ Frame extraction failed:", response.status, response.statusText, "Body:", errorText);
			}
		} catch (error) {
			console.error("❌ Failed to extract frame:", error);
		}
	}
	
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
				"playback_rate",
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

		const controls = typeof ui.getControls === "function" ? ui.getControls() : null;
		const playbackElement = controls?.getLocalVideo?.() || video;

		const clearPauseFrame = (targetVideo = playbackElement) => {
			if (targetVideo._pauseFrameImg) {
				targetVideo._pauseFrameImg.remove();
				targetVideo._pauseFrameImg = null;
			}
			if (targetVideo._pauseFrameUrl) {
				URL.revokeObjectURL(targetVideo._pauseFrameUrl);
				targetVideo._pauseFrameUrl = null;
			}
		};

		const handlePause = event => {
			const target = event.currentTarget || playbackElement;
			console.log("🎬 Video paused at:", target.currentTime);
			clearPauseFrame(target);
			extractAndDisplayFrame(target.currentTime, target);
		};

		const handlePlay = event => {
			const target = event.currentTarget || playbackElement;
			console.log("▶️ Video playing");
			clearPauseFrame(target);
		};

		// Override save_video_frame button to extract from original file
		setTimeout(() => {
			const saveFrameBtn = videoContainer.querySelector('button[aria-label*="Save"]') || 
					 videoContainer.querySelector('button[title*="Save"]');
			if (saveFrameBtn) {
				saveFrameBtn.addEventListener("click", async (e) => {
					e.preventDefault();
					e.stopPropagation();
					
					try {
						const response = await fetch(OC.generateUrl("/apps/hyperviewer/api/extract-frame"), {
							method: "POST",
							headers: {
								"Content-Type": "application/json",
								requesttoken: OC.requestToken
							},
							body: JSON.stringify({
								filename,
								directory,
								timestamp: video.currentTime
							})
						});

						if (response.ok) {
							const blob = await response.blob();
							const url = URL.createObjectURL(blob);
							const a = document.createElement("a");
							a.href = url;
							a.download = `${filename.split('.')[0]}_frame_${Math.floor(video.currentTime)}s.png`;
							document.body.appendChild(a);
							a.click();
							document.body.removeChild(a);
							URL.revokeObjectURL(url);
						}
					} catch (error) {
						console.error("Failed to save frame:", error);
					}
				}, true);
			}
		}, 500);

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

		// Listen to native HTML5 video pause/play events (Shaka playback element)
		playbackElement.addEventListener("pause", handlePause, false);
		playbackElement.addEventListener("play", handlePlay, false);
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

		// Replace template variables using string replacement (CSP-safe)
		exportModal.innerHTML = exportModalTemplate
			.replace(/\$\{defaultPath\}/g, defaultPath)
			.replace(/\$\{formatTime\(startTime\)\}/g, formatTime(startTime))
			.replace(/\$\{formatTime\(endTime\)\}/g, formatTime(endTime))
			.replace(/\$\{formatTime\(endTime - startTime\)\}/g, formatTime(endTime - startTime));

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
