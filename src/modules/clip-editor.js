import exportModalTemplate from "../templates/export-modal.html";

export class ClipEditor {

	constructor(modal, video, context, filename) {
		this.modal = modal;
		this.video = video;
		this.context = context;
		this.filename = filename;
		this.timelineContainer = modal.querySelector('#timeline-container');

		this.isClipMode = false;
		this.startTime = 0;
		this.endTime = 0;
		this.videoDuration = 0;
		this.selectedControl = null; // 'start' or 'end' for visual feedback
		this.isDragging = false;
		this.dragTarget = null;
		this.videoFrameRate = 30; // Default, will be updated when video loads
	}

	setVideoDuration(duration) {
		this.videoDuration = duration;
		this.endTime = this.videoDuration;
		this.updateTimelineMarkers();
		this.updateTimeDisplays();
	}

	init() {
		// Event listeners for clipping controls
		this.modal
			.querySelector("#toggle-clip-mode")
			.addEventListener("click", () => this.toggleClipMode());
		this.modal
			.querySelector("#exit-clip-mode")
			.addEventListener("click", () => this.toggleClipMode());

		// Start time controls - fixed to adjust markers, not seek to current time
		this.modal
			.querySelector("#start-frame-back")
			.addEventListener("click", () => this.adjustStartTime(-1 / 30)); // -1 frame at 30fps
		this.modal
			.querySelector("#start-frame-forward")
			.addEventListener("click", () => this.adjustStartTime(1 / 30)); // +1 frame at 30fps
		this.modal
			.querySelector("#start-set-current")
			.addEventListener("click", () => this.setCurrentTime(true));

		// End time controls - fixed to adjust markers, not seek to current time
		this.modal
			.querySelector("#end-frame-back")
			.addEventListener("click", () => this.adjustEndTime(-1 / 30)); // -1 frame at 30fps
		this.modal
			.querySelector("#end-frame-forward")
			.addEventListener("click", () => this.adjustEndTime(1 / 30)); // +1 frame at 30fps
		this.modal
			.querySelector("#end-set-current")
			.addEventListener("click", () => this.setCurrentTime(false));

		// Preview and export controls
		this.modal
			.querySelector("#preview-clip")
			.addEventListener("click", () => this.previewClip());
		this.modal
			.querySelector("#reset-markers")
			.addEventListener("click", () => this.resetMarkers());
		this.modal
			.querySelector("#export-clip")
			.addEventListener("click", () => this.showExportModal());

		const startMarker = this.modal.querySelector("#start-marker");
		const endMarker = this.modal.querySelector("#end-marker");
		this.setupMarkerDragging(startMarker, true);
		this.setupMarkerDragging(endMarker, false);
	}

	toggleClipMode() {
		this.isClipMode = !this.isClipMode;
		const panel = this.modal.querySelector("#clipping-panel");
		const toggleBtn = this.modal.querySelector("#toggle-clip-mode");
		const videoContainer = this.modal.querySelector("#video-player-container");

		if (this.isClipMode) {
			panel.style.display = "block";
			// Reduce size for clipping mode to fit screen better
			videoContainer.style.width = "min(90vw, 1200px)";
			videoContainer.style.height = "min(70vh, 600px)";
			videoContainer.style.borderRadius = "8px 8px 0 0";
			toggleBtn.textContent = "✂️ Exit Clip Mode";
			toggleBtn.style.background = "rgba(244, 67, 54, 0.9)";
			// Initialize markers
			this.startTime = 0;
			this.endTime = this.videoDuration;
			this.updateTimelineMarkers();
			this.updateTimeDisplays();
		} else {
			panel.style.display = "none";
			// Restore larger size when not clipping
			videoContainer.style.width = "min(95vw, 1400px)";
			videoContainer.style.height = "min(80vh, 800px)";
			videoContainer.style.borderRadius = "8px";
			toggleBtn.textContent = "✂️ Clip Video";
			toggleBtn.style.background = "rgba(255, 152, 0, 0.9)";
		}
	}

	formatTime(seconds) {
		const mins = Math.floor(seconds / 60);
		const secs = Math.floor(seconds % 60);
		const ms = Math.floor((seconds % 1) * 1000);
		return `${mins}:${secs
			.toString()
			.padStart(2, "0")}.${ms.toString().padStart(3, "0")}`;
	}

	updateTimeDisplays() {
		this.modal.querySelector("#start-time-display").textContent = this.formatTime(
			this.startTime
		);
		this.modal.querySelector("#end-time-display").textContent = this.formatTime(
			this.endTime
		);

		const duration = Math.max(0, this.endTime - this.startTime);
		const durationMins = Math.floor(duration / 60);
		const durationSecs = Math.floor(duration % 60);
		this.modal.querySelector(
			"#clip-duration"
		).textContent = `Clip Duration: ${durationMins}:${durationSecs
			.toString()
			.padStart(2, "0")}`;
	}

	updateTimelineMarkers() {
		if (this.videoDuration === 0) return;

		const startPercent = (this.startTime / this.videoDuration) * 100;
		const endPercent = (this.endTime / this.videoDuration) * 100;

		// Update markers (adjust for marker width)
		this.modal.querySelector(
			"#start-marker"
		).style.left = `calc(${startPercent}% - 6px)`;
		this.modal.querySelector(
			"#end-marker"
		).style.left = `calc(${endPercent}% - 6px)`;
		this.modal.querySelector("#clip-range").style.left = `${startPercent}%`;
		this.modal.querySelector("#clip-range").style.width = `${endPercent -
			startPercent}%`;
	}

	updateTimelineProgress() {
		if (this.videoDuration === 0) return;
		const progressPercent = (this.video.currentTime / this.videoDuration) * 100;
		this.modal.querySelector(
			"#timeline-progress"
		).style.width = `${progressPercent}%`;

		// Update playback cursor
		this.modal.querySelector(
			"#playback-cursor"
		).style.left = `calc(${progressPercent}% - 1px)`;
	}

	updateControlSelection(selected) {
		this.selectedControl = selected;

		// Update start control styling
		const startControl = this.modal
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
		const endControl = this.modal
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

	setCurrentTime(isStart = true) {
		if (isStart) {
			this.startTime = this.video.currentTime;
			this.updateControlSelection("start");
		} else {
			this.endTime = this.video.currentTime;
			this.updateControlSelection("end");
		}

		// Ensure start < end
		if (this.startTime >= this.endTime) {
			if (isStart) {
				this.endTime = Math.min(this.videoDuration, this.startTime + 1);
			} else {
				this.startTime = Math.max(0, this.endTime - 1);
			}
		}

		this.updateTimelineMarkers();
		this.updateTimeDisplays();
	}

	previewClip() {
		this.video.currentTime = this.startTime;
		this.video.play();

		// Stop at end time
		const checkTime = () => {
			if (this.video.currentTime >= this.endTime) {
				this.video.pause();
			} else {
				requestAnimationFrame(checkTime);
			}
		};
		requestAnimationFrame(checkTime);
	}

	resetMarkers() {
		this.startTime = 0;
		this.endTime = this.videoDuration;
		this.updateTimelineMarkers();
		this.updateTimeDisplays();
	}

	showExportModal() {
		// Determine default export path
		const isInHome =
			this.context.dir === "/" ||
			this.context.dir.startsWith("/home") ||
			this.context.dir.startsWith("/Users");
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
			.replace(/\$\{formatTime\(startTime\)\}/g, this.formatTime(this.startTime))
			.replace(/\$\{formatTime\(endTime\)\}/g, this.formatTime(this.endTime))
			.replace(
				/\$\{formatTime\(endTime - startTime\)\}/g,
				this.formatTime(this.endTime - this.startTime)
			);

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
			this.startExport(exportPath);
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
	async startExport(exportPath) {
		try {
			// Generate unique filename for the clip
			const timestamp = new Date()
				.toISOString()
				.replace(/[:.]/g, "-")
				.slice(0, -5);
			const baseName = this.filename.replace(/\.[^/.]+$/, ""); // Remove extension
			const extension = this.filename.split(".").pop();
			const clipFilename = `${baseName}_clip_${timestamp}.${extension}`;

			// Show immediate notification
			this.showExportNotification(clipFilename, exportPath);

			// Get the full file path for the original video
			const originalPath =
				this.context.dir === "/"
					? `/${this.filename}`
					: `${this.context.dir}/${this.filename}`;

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
						startTime: this.startTime,
						endTime: this.endTime,
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

	showExportNotification(clipFilename, exportPath) {
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

	adjustStartTime(deltaSeconds) {
		const newStartTime = Math.max(
			0,
			Math.min(this.startTime + deltaSeconds, this.endTime - 0.1)
		);
		this.startTime = newStartTime;
		this.video.currentTime = newStartTime; // Seek video to show the frame
		this.updateTimelineMarkers();
		this.updateTimeDisplays();
	}

	adjustEndTime(deltaSeconds) {
		const newEndTime = Math.max(
			this.startTime + 0.1,
			Math.min(this.endTime + deltaSeconds, this.videoDuration)
		);
		this.endTime = newEndTime;
		this.video.currentTime = newEndTime; // Seek video to show the frame
		this.updateTimelineMarkers();
		this.updateTimeDisplays();
	}

	setupMarkerDragging(marker, isStart) {
		marker.addEventListener("mousedown", e => {
			e.preventDefault();
			e.stopPropagation();
			this.isDragging = true;
			this.dragTarget = isStart ? "start" : "end";
			this.updateControlSelection(this.dragTarget);

			const handleMouseMove = e => {
				if (!this.isDragging) return;

				const rect = this.timelineContainer.getBoundingClientRect();
				const mouseX = e.clientX - rect.left;
				const percent = Math.max(0, Math.min(1, mouseX / rect.width));
				const newTime = percent * this.videoDuration;

				if (isStart) {
					this.startTime = Math.min(newTime, this.endTime - 0.1); // Keep 0.1s minimum gap
				} else {
					this.endTime = Math.max(newTime, this.startTime + 0.1);
				}

				// Update video position to show frame
				this.video.currentTime = newTime;

				this.updateTimelineMarkers();
				this.updateTimeDisplays();
			};

			const handleMouseUp = () => {
				this.isDragging = false;
				this.dragTarget = null;
				document.removeEventListener("mousemove", handleMouseMove);
				document.removeEventListener("mouseup", handleMouseUp);
			};

			document.addEventListener("mousemove", handleMouseMove);
			document.addEventListener("mouseup", handleMouseUp);
		});

		// Visual feedback and tooltip on hover
		const tooltip = marker.querySelector('div[id$="-tooltip"]');

		marker.addEventListener("mouseenter", () => {
			if (!this.isDragging) {
				marker.style.transform = "scale(1.1)";
				marker.style.boxShadow = "0 0 8px rgba(255,255,255,0.5)";
				if (tooltip) {
					tooltip.style.display = "block";
					tooltip.textContent = this.formatTime(
						isStart ? this.startTime : this.endTime
					);
				}
			}
		});

		marker.addEventListener("mouseleave", () => {
			if (!this.isDragging) {
				marker.style.transform = "scale(1)";
				marker.style.boxShadow = "none";
				if (tooltip) {
					tooltip.style.display = "none";
				}
			}
		});
	}

}
