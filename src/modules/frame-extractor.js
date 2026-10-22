// Utilities for extracting a video frame from the server

/**
 * @param {string} filename
 * @param {string} directory
 * @param {number} timestamp
 * @param {number} [fileid]
 * @returns {Promise<Blob>}
 */
export async function extractFrameBlob(filename, directory, timestamp, fileid = 0) {
	const response = await fetch(
		OC.generateUrl("/apps/hyperviewer/api/frame"),
		{
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				requesttoken: OC.requestToken
			},
			body: JSON.stringify({
				filename,
				directory,
				timestamp,
				fileid
			})
		}
	);

	if (!response.ok) {
		const errorText = await response.text();
		throw new Error(
			`Frame extraction failed: ${response.status} ${response.statusText} - ${errorText}`
		);
	}

	const contentType = response.headers.get("Content-Type");
	if (contentType && contentType.startsWith("image/")) {
		return await response.blob();
	}

	const text = await response.text();
	try {
		const data = JSON.parse(text);
		throw new Error(
			`Unexpected response while extracting frame: ${data.error || text}`
		);
	} catch (e) {
		throw new Error(
			`Unexpected non-image response while extracting frame: ${text.substring(0, 200)}`
		);
	}
}
