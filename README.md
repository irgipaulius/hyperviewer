# Hyper Viewer

**Review footage instantly, choose highlights, and export only what you need in full quality.**

Professional video processing toolkit for Nextcloud. Automatically generates HLS streams for smooth remote video review, then extract and download specific clips in original quality without re-encoding. Stop downloading entire 50GB files just to get a 30-second segment - review remotely, clip precisely, export losslessly. Perfect for videographers managing large footage libraries.

![Shaka Player with Clip Mode](screenshots/visualization3.png)
*Frame-accurate clip selection with real-time preview*

![HLS Video Streaming](screenshots/visualization2.png)
*Adaptive bitrate streaming with Shaka Player*

## Features

- 🎬 **Queue-backed HLS encoding** - Fingerprint-addressed cache store, first-rendition-fast then background upgrades (720p–1080p)
- ⚙️ **Dedicated worker** - Run `HV_WORKER=1 occ hyperviewer:worker` (see `scripts/freebsd/hyperviewer_worker` for rc.d)
- 🎞️ **Shaka Player Integration** - Professional HLS video playback interface
- 📱 **Mobile Friendly** - Responsive UI works on all devices

## Installation (No Build Required!)

1. ☁ Clone into your Nextcloud `apps` folder: `git clone https://github.com/irgipaulius/hyperviewer.git`
2. 🔧 Fix file ownership: `chown -R www-data:www-data hyperviewer/`
3. ✅ Enable the app through Nextcloud app management, and refresh the page
4. 🎉 Ready to use!

**Note**: Build files are committed, so no Node.js or build step required on server.

## Requirements

- Nextcloud 30+
- PHP 8.1+
- FFmpeg installed on server
- Sufficient disk space for HLS cache files

## Development

### Building (Webpack - Current)
```bash
npm install
NODE_OPTIONS="--openssl-legacy-provider" npm run build
```

### Building (Vite - Faster Alternative)
```bash
npm install
npm run build:vite
```

### Watch Mode
```bash
npm run watch          # Webpack
npm run watch:vite     # Vite
```

### Code Quality
```bash
composer install                # Install PHP tools
composer cs:check               # Check code style
composer cs:fix                 # Fix code style
composer psalm                  # Static analysis
composer test:unit              # Run tests
```

## Configuration

- **Settings → Administration → Hyper Viewer** — queue overview, settings overrides, logs (admin cockpit)
- **Settings → Personal → Hyper Viewer** — watch directories, job board, cache path hints

### CLI (occ)

| Command | Purpose |
|---------|---------|
| `hyperviewer:worker` | Process `hv_jobs` (set `HV_WORKER=1`) |
| `hyperviewer:scan` | Metadata scan + enqueue fingerprint/encode jobs |
| `hyperviewer:reindex` | Rebuild fileid ↔ fingerprint index |
| `hyperviewer:cache:convert-legacy` | Import old `.cached_hls` trees into the fingerprint store |
| `hyperviewer:cache:stats` | Cache size / entry counts |

## How It Works

### HLS Streaming
1. Right-click any MOV/MP4 file → **Generate HLS Cache** (enqueues jobs; no heavy fingerprinting in the web request)
2. Select quality variants; the worker fingerprints content and encodes into the shared cache store
3. Playback uses Shaka against `/apps/hyperviewer/hls/{fingerprint}/…` while APIs stay fileid-based
4. Watched folders and `hyperviewer:scan` keep external renames/moves in sync via fingerprint rebind

### Video Clipping & Export
1. Open any video file in the HLS player
2. Use the timeline to find your desired start and end points
3. Click **Export Clip** to extract just that segment
4. Original quality export using lossless stream copy (no re-encoding)
5. Download only the clip you need - not the entire 50GB file!

## Screenshots Gallery

<details>
<summary>Click to view more screenshots</summary>

![Screenshot 1](screenshots/visualization1.png)
![Screenshot 4](screenshots/visualization4.png)
![Screenshot 5](screenshots/visualization5.png)
![Screenshot 6](screenshots/visualization6.png)

</details>

## License

AGPL-3.0-or-later

## Links

- 🐛 [Issues](https://github.com/irgipaulius/hyperviewer/issues)
- 👨‍💻 [GitHub](https://github.com/irgipaulius/hyperviewer)
