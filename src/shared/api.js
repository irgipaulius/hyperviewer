import axios from '@nextcloud/axios'

export async function apiGet(url, params = {}) {
	const response = await axios.get(url, { params })
	if (typeof response.data === 'string') {
		throw new Error('Unexpected HTML response — session or permissions issue')
	}
	return response.data
}

export async function apiPost(url, data = {}) {
	const response = await axios.post(url, data)
	if (typeof response.data === 'string') {
		throw new Error('Unexpected HTML response')
	}
	return response.data
}

export async function apiDelete(url) {
	const response = await axios.delete(url)
	if (typeof response.data === 'string') {
		throw new Error('Unexpected HTML response')
	}
	return response.data
}

export async function fetchJson(url, options = {}) {
	const response = await fetch(url, options)
	const ct = response.headers.get('Content-Type') || ''
	if (!response.ok) {
		const text = await response.text()
		throw new Error(text.slice(0, 200) || `HTTP ${response.status}`)
	}
	if (!ct.includes('application/json')) {
		throw new Error('Expected JSON response')
	}
	return response.json()
}
