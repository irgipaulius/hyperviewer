/**
 * Video player module - Shaka Player integration with clipping functionality
 */

import shaka from "shaka-player/dist/shaka-player.ui.js";
import "shaka-player/dist/controls.css";
import playerModalTemplate from "../templates/player-modal.html";
import { ClipEditor } from "./clip-editor";
import { extractFrameBlob } from "./frame-extractor";
import { FileType } from "@nextcloud/files";
import { folderPath, isVideoMime, viewerPath } from "../files/nodePaths.js";

async function checkHlsCacheByFileid(fileid, filename, directory) {
	try {
		const response = await fetch(
			OC.generateUrl("/apps/hyperviewer/cache/check"),
			{
				method: "POST",
				headers: {
					"Content-Type": "application/json",
					requesttoken: OC.requestToken
				},
				body: JSON.stringify({ fileid, filename, directory })
			}
		);
		const result = await response.json();
		return result.exists ? result.fingerprint : null;
	} catch (error) {
		console.error("Failed to check HLS cache:", error);
		return null;
	}
}

/**
 * @param {import('@nextcloud/files').INode} node
 * @param {import('@nextcloud/files').IFolder} folder
 * @param {import('@nextcloud/files').INode[]} [contents]
 */
export async function playVideoSmart(node, folder, contents = []) {
	const directory = folderPath(folder, node);
	const filename = node.basename;
	const fileid = node.fileid ?? 0;
	console.log(`🎬 Smart play: ${filename} (fileid ${fileid})`);

	try {
		const fingerprint = await checkHlsCacheByFileid(
			fileid,
			filename,
			directory
		);
		if (fingerprint) {
			loadShakaPlayer(node, fingerprint, folder, contents);
		} else {
			openWithDefaultPlayer(viewerPath(node, folder));
		}
	} catch (error) {
		console.error("Error checking HLS cache:", error);
		openWithDefaultPlayer(viewerPath(node, folder));
	}
}

function openWithDefaultPlayer(path) {
	if (window.OCA?.Viewer) {
		window.OCA.Viewer.open({ path });
	} else {
		console.error("Viewer not available for path:", path);
	}
}

function videoPlaylistFromContents(contents) {
	return (contents || [])
		.filter(
			n =>
				n.type === FileType.File && isVideoMime(n.mime)
		)
		.map(n => n.basename)
		.sort();
}

/**
 * @param {import('@nextcloud/files').INode} node
 * @param {string} fingerprint
 * @param {import('@nextcloud/files').IFolder} folder
 * @param {import('@nextcloud/files').INode[]} contents
 */
function loadShakaPlayer(node, fingerprint, folder, contents) {
	const filename = node.basename;
	const directory = folderPath(folder, node);
	const fileid = node.fileid ?? 0;
	const videoId = `hyperVideo_${Date.now()}`;

	const videoPlaylist = videoPlaylistFromContents(contents);
	const currentVideoIndex = videoPlaylist.indexOf(filename);

	const modal = document.createElement("div");
	modal.style.cssText = `
        position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 10000;
        background: rgba(0,0,0,0.9); display: flex; flex-direction: column; align-items: center; justify-content: center;
        padding: 20px; box-sizing: border-box;
    `;

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
	const context = { dir: directory, fileInfoModel: { id: fileid } };
	const clipEditor = new ClipEditor(modal, video, context, filename, fileid);

	modal.querySelector(".close-btn").addEventListener("click", closeModal);
	modal
		.querySelector("#open-default-player")
		.addEventListener("click", () => {
			closeModal();
			openWithDefaultPlayer(viewerPath(node, folder));
		});

	const navigateToVideo = async direction => {
		if (videoPlaylist.length <= 1) {
			return;
		}
		let newIndex = currentVideoIndex + direction;
		if (newIndex < 0) {
			newIndex = videoPlaylist.length - 1;
		}
		if (newIndex >= videoPlaylist.length) {
			newIndex = 0;
		}
		const nextName = videoPlaylist[newIndex];
		const nextNode = (contents || []).find(n => n.basename === nextName);
		if (!nextNode) {
			return;
		}
		closeModal();
		await playVideoSmart(nextNode, folder, contents);
	};

	const prevBtn = modal.querySelector("#prev-video-btn");
	const nextBtn = modal.querySelector("#next-video-btn");
	const videoContainer = modal.querySelector("#video-player-container");
	const pauseFrameImg = modal.querySelector("#pause-frame-display");

	let frameExtractionId = 0;
	let currentFrameUrl = null;

	async function extractAndDisplayFrame(timestamp, targetVideo = video) {
		const currentExtractionId = ++frameExtractionId;
		try {
			const blob = await extractFrameBlob(
				filename,
				directory,
				timestamp,
				fileid
			);
			if (currentExtractionId !== frameExtractionId) {
				return;
			}
			if (currentFrameUrl) {
				URL.revokeObjectURL(currentFrameUrl);
			}
			currentFrameUrl = URL.createObjectURL(blob);
			pauseFrameImg.src = currentFrameUrl;
			pauseFrameImg.style.display = "block";
		} catch (error) {
			console.error("Failed to extract frame:", error);
		}
	}

	if (prevBtn) {
		prevBtn.addEventListener("click", () => navigateToVideo(-1));
	}
	if (nextBtn) {
		nextBtn.addEventListener("click", () => navigateToVideo(1));
	}

	let touchStartX = 0;
	const minSwipeDistance = 50;
	videoContainer.addEventListener(
		"touchstart",
		e => {
			touchStartX = e.changedTouches[0].screenX;
		},
		{ passive: true }
	);
	videoContainer.addEventListener(
		"touchend",
		e => {
			const touchEndX = e.changedTouches[0].screenX;
			const swipeDistance = touchEndX - touchStartX;
			if (Math.abs(swipeDistance) > minSwipeDistance) {
				navigateToVideo(swipeDistance > 0 ? -1 : 1);
			}
		},
		{ passive: true }
	);

	shaka.polyfill.installAll();

	if (shaka.Player.isBrowserSupported()) {
		const player = new shaka.Player(video);

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
				played: "rgb(255, 152, 0)"
			}
		};

		const ui = new shaka.ui.Overlay(player, videoContainer, video);
		ui.configure(uiConfig);

		const controls =
			typeof ui.getControls === "function" ? ui.getControls() : null;
		const playbackElement = controls?.getLocalVideo?.() || video;

		const clearPauseFrame = () => {
			frameExtractionId++;
			pauseFrameImg.style.display = "none";
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

		const handlePlay = () => {
			clearPauseFrame();
		};

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
								video.currentTime,
								fileid
							);
							const url = URL.createObjectURL(blob);
							const a = document.createElement("a");
							a.href = url;
							a.download = `${filename.split(".")[0]}_frame_${Math.floor(video.currentTime)}s.png`;
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

		const enc = encodeURIComponent(fingerprint);
		const base = OC.generateUrl("/apps/hyperviewer/hls");
		const masterUrl = `${base}/${enc}/master.m3u8`;
		const playlistUrl = `${base}/${enc}/playlist.m3u8`;

		player.load(masterUrl).catch(() => player.load(playlistUrl));

		player.addEventListener("trackschanged", () => {
			const variants = player.getVariantTracks().filter(t => t.type === "variant");
			if (variants.length > 1) {
				ui.configure({
					overflowMenuButtons: [
						"picture_in_picture",
						"quality",
						"playback_rate",
						"save_video_frame",
						"statistics"
					]
				});
			}
		});

		video.addEventListener("loadedmetadata", () => {
			clipEditor.setVideoDuration(video.duration);
		});

		video.addEventListener("timeupdate", () => {
			if (clipEditor.isClipMode) {
				clipEditor.updateTimelineProgress();
			}
		});

		playbackElement.addEventListener("pause", handlePause, false);
		playbackElement.addEventListener("play", handlePlay, false);
		playbackElement.addEventListener(
			"seeking",
			() => {
				clearPauseFrame();
			},
			false
		);
	}

	clipEditor.init();

	const timelineContainer = modal.querySelector("#timeline-container");
	timelineContainer.addEventListener("click", e => {
		if (clipEditor.isDragging) {
			return;
		}
		const rect = timelineContainer.getBoundingClientRect();
		const clickX = e.clientX - rect.left;
		const clickPercent = Math.max(0, Math.min(1, clickX / rect.width));
		video.currentTime = clickPercent * clipEditor.videoDuration;
	});

	function handleKeyDown(e) {
		if (e.key === "Escape") {
			e.preventDefault();
			closeModal();
			return;
		}
		if (e.key === " " || e.key === "Spacebar") {
			e.preventDefault();
			if (video.paused) {
				video.play();
			} else {
				video.pause();
			}
			return;
		}
		if (
			e.shiftKey &&
			e.key === "ArrowRight" &&
			!e.target.matches("input, textarea")
		) {
			e.preventDefault();
			if (videoPlaylist.length > 1) {
				navigateToVideo(1);
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
				navigateToVideo(-1);
			}
			return;
		}
		if (!clipEditor.isClipMode) {
			return;
		}
		const handledKeys = ["ArrowLeft", "ArrowRight", "Enter"];
		if (
			handledKeys.includes(e.key) ||
			(e.key === "Enter" && (e.ctrlKey || e.metaKey))
		) {
			e.preventDefault();
		}
		switch (e.key) {
			case "ArrowLeft":
				if (video.currentTime > 0) {
					video.currentTime = Math.max(0, video.currentTime - 1 / 30);
				}
				break;
			case "ArrowRight":
				if (video.currentTime < video.duration) {
					video.currentTime = Math.min(
						video.duration,
						video.currentTime + 1 / 30
					);
				}
				break;
			case "Enter":
				if (e.ctrlKey || e.metaKey) {
					clipEditor.showExportModal();
				} else {
					const exportModal = document.querySelector("#confirm-export");
					if (exportModal) {
						exportModal.click();
					}
				}
				break;
		}
	}

	document.addEventListener("keydown", handleKeyDown);
	const originalClose = modal.remove;
	modal.remove = function() {
		document.removeEventListener("keydown", handleKeyDown);
		originalClose.call(this);
	};
}
