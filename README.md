# 🎬 HyperViewer

**Professional video toolkit for Nextcloud with HLS streaming, m3u8 generation, and server-side cropping**

Transform how you work with videos in Nextcloud. Automatically generate HLS streams, crop videos on the server, and play large files instantly—perfect for videographers managing terabytes of footage without downloading everything.

## ✨ Features

- **Adaptive HLS Streaming** - Multi-bitrate playback with automatic quality switching
- **Automatic m3u8 Generation** - Background transcoding with FFmpeg
- **Server-Side Video Cropping** - Edit before downloading, save bandwidth
- **Progressive Playback** - Instant preview while transcoding
- **Bulk Processing** - Process entire directories at once
- **Smart Caching** - Reuses transcoded files efficiently

## 📦 Installation

### Manual Installation
```bash
cd /path/to/nextcloud/apps
git clone https://github.com/irgipaulius/hyperviewer.git
chown -R www-data:www-data hyperviewer/
sudo -u www-data php /path/to/nextcloud/occ app:enable hyperviewer
```

**Note**: Built files are included—no build step required!

## 🎮 Usage

1. Right-click any MOV/MP4 file in Files app
2. Select **"Play Progressive"** or **"Generate HLS Cache"**
3. Enjoy instant playback or automatic transcoding!

## ⚙️ Requirements

- Nextcloud 19+
- PHP 7.4+ (8.0+ recommended)
- FFmpeg 4.0+ with libx264 and AAC support

## 🛠️ Development

```bash
npm install
npm run watch  # Development
npm run build  # Production
```

**Legacy OpenSSL build:**
```bash
NODE_OPTIONS="--openssl-legacy-provider" npm run build --fix
```

## 📝 License

GNU AGPL v3 - see [LICENSE](LICENSE)

---

Made with ❤️ for videographers and the Nextcloud community
