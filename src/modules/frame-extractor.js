// Utilities for extracting a video frame from the server

/**
 * Call backend API to extract a frame image for a given file and timestamp.
 * Returns a Blob (image/*) on success, throws Error on failure.
 *
 * @param {string} filename
 * @param {string} directory
 * @param {number} timestamp
 * @returns {Promise<Blob>}
 */
export async function extractFrameBlob(filename, directory, timestamp) {
	const response = await fetch(
		OC.generateUrl("/apps/hyperviewer/api/extract-frame"),
		{
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				requesttoken: OC.requestToken
			},
			body: JSON.stringify({ filename, directory, timestamp })
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

	// Not an image, try to parse error message
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
