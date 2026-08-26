import { fetchRequestToken, getRequestToken } from '@nextcloud/auth'
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

function compatibilityUrl(url: string): string {
	// Keep the development frontend compatible with the original scan route.
	// The backend exposes both URLs, but /api/library/scan existed from the
	// first functional build and is therefore also safe with stale route caches.
	return url.replace('/apps/musiccurator/api/library/scan-selected', '/apps/musiccurator/api/library/scan')
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
			},
			body,
		})
	} catch {
		// Logging must never hide the original frontend error.
	}
}

async function freshRequestToken(forceRefresh = false): Promise<string> {
	if (!forceRefresh) {
		const current = getRequestToken()
		if (current) {
			return current
		}
	}

	return fetchRequestToken()
}

async function musicCuratorFetch(input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> {
	const originalUrl = inputUrl(input)
	if (!isMusicCuratorRequest(originalUrl) || isClientLogRequest(originalUrl)) {
		return nativeFetch(input, init)
	}

	const url = compatibilityUrl(originalUrl)
	const method = (init.method ?? (input instanceof Request ? input.method : 'GET')).toUpperCase()
	const headers = new Headers(init.headers ?? (input instanceof Request ? input.headers : undefined))
	let requestInit: RequestInit = { ...init, headers, credentials: init.credentials ?? 'same-origin' }

	if (method !== 'GET' && method !== 'HEAD') {
		try {
			headers.set('requesttoken', await freshRequestToken())
		} catch (error) {
			await reportFrontendFailure(url, 0, `Could not obtain CSRF token: ${String(error)}`)
		}
	}

	const execute = () => nativeFetch(url, requestInit)

	try {
		let response = await execute()

		if (method !== 'GET' && method !== 'HEAD' && !response.ok) {
			const errorText = await response.clone().text().catch(() => '')
			if (errorText.includes('CSRF check failed')) {
				try {
					headers.set('requesttoken', await freshRequestToken(true))
					requestInit = { ...requestInit, headers }
					response = await execute()
				} catch (error) {
					await reportFrontendFailure(url, response.status, `CSRF token refresh failed: ${String(error)}`)
				}
			}
		}

		if (!response.ok) {
			const body = await response.clone().text().catch(() => '')
			await reportFrontendFailure(url, response.status, body || response.statusText || 'HTTP request failed')
		}

		return response
	} catch (error) {
		await reportFrontendFailure(url, 0, String(error))
		throw error
	}
}

window.fetch = musicCuratorFetch

void (async () => {
	try {
		window.OC.requestToken = await freshRequestToken()
	} catch {
		// Individual requests will retry token acquisition and report failures.
	}

	const app = createApp(App)
	app.mount('#musiccurator')
})()
