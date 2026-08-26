import { createApp } from 'vue'
import App from './App.vue'

const nativeFetch = window.fetch.bind(window)

function inputUrl(input: RequestInfo | URL): string {
	if (typeof input === 'string') {
		return input
	}
	if (input instanceof URL) {
		return input.toString()
	}
	return input.url
}

function isMusicCuratorRequest(url: string): boolean {
	return url.includes('/apps/musiccurator/')
}

function isClientLogRequest(url: string): boolean {
	return url.includes('/apps/musiccurator/api/client-error')
}

async function reportFrontendFailure(url: string, status: number, message: string): Promise<void> {
	try {
		const reportUrl = window.OC.generateUrl('/apps/musiccurator/api/client-error')
		const parsed = new URL(url, window.location.origin)
		const body = new URLSearchParams({
			operation: `${parsed.pathname}${parsed.search}`,
			status: String(status),
			message,
			path: parsed.pathname,
		})

		await nativeFetch(reportUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
				'OCS-APIRequest': 'true',
			},
			body,
		})
	} catch {
		// Logging must never hide the original frontend error.
	}
}

async function musicCuratorFetch(input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> {
	const url = inputUrl(input)
	if (!isMusicCuratorRequest(url)) {
		return nativeFetch(input, init)
	}

	const headers = new Headers(init.headers ?? (input instanceof Request ? input.headers : undefined))
	// Nextcloud accepts OCS-APIRequest for authenticated same-origin data
	// requests as an alternative to a CSRF token. This is more robust than
	// relying on a global token that can become stale after session refreshes.
	headers.set('OCS-APIRequest', 'true')

	const requestInit: RequestInit = {
		...init,
		headers,
		credentials: init.credentials ?? 'same-origin',
	}

	try {
		const response = await nativeFetch(url, requestInit)
		if (!response.ok && !isClientLogRequest(url)) {
			const body = await response.clone().text().catch(() => '')
			await reportFrontendFailure(url, response.status, body || response.statusText || 'HTTP request failed')
		}
		return response
	} catch (error) {
		if (!isClientLogRequest(url)) {
			await reportFrontendFailure(url, 0, String(error))
		}
		throw error
	}
}

window.fetch = musicCuratorFetch

const app = createApp(App)
app.mount('#musiccurator')
