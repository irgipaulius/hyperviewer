/**
 * Video player module - Shaka Player integration with clipping functionality
 */

import shaka from "shaka-player/dist/shaka-player.ui.js";
import "shaka-player/dist/controls.css";
import playerModalTemplate from "../templates/player-modal.html";
import { ClipEditor } from "./clip-editor";
import { extractFrameBlob } from "./frame-extractor";

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
		const fileList =
			context?.fileInfoModel?.fileList ||
			context?.fileList ||
			window.OCA?.Files?.App?.fileList;

		if (!fileList) {
			console.error("No fileList available");
			return;
		}

		// Find the file model
		const fileModel = fileList.files.find(f => f.name === filename);

		if (!fileModel) {
			console.error(`File not found in fileList: ${filename}`);
			return;
		}

		// Use Nextcloud Viewer directly
		console.log("🎬 Opening with Nextcloud Viewer");
		const filePath =
			directory === "/" ? `/${filename}` : `${directory}/${filename}`;

		if (window.OCA?.Viewer) {
			window.OCA.Viewer.open({
				path: filePath
			});
		} else {
			console.error("Viewer not available");
		}
	} catch (error) {
		console.error("Error opening with default player:", error);
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
		directory =
			context?.dir || context?.fileList?.getCurrentDirectory() || "/";
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
				.filter(
					file =>
						file.mimetype === "video/quicktime" ||
						file.mimetype === "video/mp4"
				)
				.map(file => file.name)
				.sort();
			currentVideoIndex = videoPlaylist.indexOf(filename);
			console.log(
				`📹 Video playlist: ${videoPlaylist.length} videos, current index: ${currentVideoIndex}`
			);
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
	const clipEditor = new ClipEditor(modal, video, context, filename);

	// Add close button event listener
	modal.querySelector(".close-btn").addEventListener("click", closeModal);

	// Add default player button event listener
	modal
		.querySelector("#open-default-player")
		.addEventListener("click", () => {
			closeModal();
			openWithDefaultPlayer(filename, directory, context);
		});

	// Video navigation functionality
	const navigateToVideo = async direction => {
		if (videoPlaylist.length <= 1) return; // No other videos to navigate to

		// Calculate new index with loop-around
		let newIndex = currentVideoIndex + direction;
		if (newIndex < 0) newIndex = videoPlaylist.length - 1; // Loop to end
		if (newIndex >= videoPlaylist.length) newIndex = 0; // Loop to start

		const nextFilename = videoPlaylist[newIndex];
		console.log(
			`🔄 Navigating to video ${newIndex + 1}/${
				videoPlaylist.length
			}: ${nextFilename}`
		);

		// Close current modal
		closeModal();

		// Play next video with smart player
		await playVideoSmart(nextFilename, directory, context);
	};

	// Navigation arrow buttons (desktop)
	const prevBtn = modal.querySelector("#prev-video-btn");
	const nextBtn = modal.querySelector("#next-video-btn");
	const videoContainer = modal.querySelector("#video-player-container");
	const pauseFrameImg = modal.querySelector("#pause-frame-display");

	// Track frame extraction state to allow cancellation
	let frameExtractionId = 0;
	let currentFrameUrl = null;

	// Extract frame from original file and display it as overlay
	async function extractAndDisplayFrame(timestamp, targetVideo = video) {
		// Create unique ID for this extraction request
		const currentExtractionId = ++frameExtractionId;
		try {
			console.log("🖼️ Extracting frame at:", timestamp);
			const blob = await extractFrameBlob(filename, directory, timestamp);
			console.log(
				"✅ Blob received, size:",
				blob.size,
				"type:",
				blob.type
			);

			// Check if this extraction was cancelled (video started playing)
			if (currentExtractionId !== frameExtractionId) {
				console.log(
					"⚠️ Frame extraction cancelled - video is playing"
				);
				return;
			}

			// Clean up previous frame URL if exists
			if (currentFrameUrl) {
				URL.revokeObjectURL(currentFrameUrl);
			}

			// Create object URL from blob and display
			currentFrameUrl = URL.createObjectURL(blob);
			pauseFrameImg.src = currentFrameUrl;
			pauseFrameImg.style.display = "block";
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
			if (window.innerWidth > 768) {
				// Desktop only
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
		prevBtn.addEventListener("click", e => {
			e.stopPropagation();
			navigateToVideo(-1);
		});

		nextBtn.addEventListener("click", e => {
			e.stopPropagation();
			navigateToVideo(1);
		});

		// Swipe detection for mobile
		let touchStartX = 0;
		let touchEndX = 0;
		const minSwipeDistance = 50;

		videoContainer.addEventListener("touchstart", e => {
			touchStartX = e.changedTouches[0].screenX;
		});

		videoContainer.addEventListener("touchend", e => {
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

		const controls =
			typeof ui.getControls === "function" ? ui.getControls() : null;
		const playbackElement = controls?.getLocalVideo?.() || video;

		const clearPauseFrame = () => {
			// Cancel any pending frame extractions
			frameExtractionId++;

			// Hide the pause frame overlay
			pauseFrameImg.style.display = "none";

			// Clean up the object URL
			if (currentFrameUrl) {
				URL.revokeObjectURL(currentFrameUrl);
				currentFrameUrl = null;
			}
		};

		const handlePause = event => {
			const target = event.currentTarget || playbackElement;
			clearPauseFrame();

			if (target.currentTime >= target.duration) {
				return;
			}

			extractAndDisplayFrame(target.currentTime, target);
		};

		const handlePlay = event => {
			clearPauseFrame();
		};

		// Override save_video_frame button to extract from original file
		setTimeout(() => {
			const saveFrameBtn =
				videoContainer.querySelector('button[aria-label*="Save"]') ||
				videoContainer.querySelector('button[title*="Save"]');
			if (saveFrameBtn) {
				saveFrameBtn.addEventListener(
					"click",
					async e => {
						e.preventDefault();
						e.stopPropagation();

						try {
							const blob = await extractFrameBlob(
								filename,
								directory,
								video.currentTime
							);
							const url = URL.createObjectURL(blob);
							const a = document.createElement("a");
							a.href = url;
							a.download = `${
								filename.split(".")[0]
							}_frame_${Math.floor(video.currentTime)}s.png`;
							document.body.appendChild(a);
							a.click();
							document.body.removeChild(a);
							URL.revokeObjectURL(url);
						} catch (error) {
							console.error("Failed to save frame:", error);
						}
					},
					true
				);
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
			clipEditor.setVideoDuration(video.duration);
		});

		video.addEventListener("timeupdate", () => {
			if (clipEditor.isClipMode) {
				clipEditor.updateTimelineProgress();
			}
		});

		// Listen to native HTML5 video pause/play events (Shaka playback element)
		playbackElement.addEventListener("pause", handlePause, false);
		playbackElement.addEventListener("play", handlePlay, false);
		playbackElement.addEventListener(
			"seeking",
			() => {
				console.log("🔍 Video seeking - clearing pause frame");
				clearPauseFrame();
			},
			false
		);
	}

	// Clipping functionality
	clipEditor.init()

	// Timeline interactions
	const timelineContainer = modal.querySelector("#timeline-container");

	// Timeline click to seek
	timelineContainer.addEventListener("click", e => {
		if (clipEditor.isDragging) return; // Don't seek while dragging

		const rect = timelineContainer.getBoundingClientRect();
		const clickX = e.clientX - rect.left;
		const clickPercent = Math.max(0, Math.min(1, clickX / rect.width));
		const seekTime = clickPercent * clipEditor.videoDuration;
		video.currentTime = seekTime;
	});

	// Keyboard controls for video clipping
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
		if (
			e.shiftKey &&
			e.key === "ArrowRight" &&
			!e.target.matches("input, textarea")
		) {
			e.preventDefault();
			if (videoPlaylist.length > 1) {
				navigateToVideo(1); // Next video
			}
			return;
		}

		if (
			e.shiftKey &&
			e.key === "ArrowLeft" &&
			!e.target.matches("input, textarea")
		) {
			e.preventDefault();
			if (videoPlaylist.length > 1) {
				navigateToVideo(-1); // Previous video
			}
			return;
		}

		// Only handle keys when clipping mode is active
		if (
			!clipEditor.isClipMode
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
					clipEditor.showExportModal();
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
