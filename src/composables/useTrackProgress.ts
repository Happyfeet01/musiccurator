import { ref } from 'vue'
import { t } from '../l10n.ts'

export type ReviewStatus = 'pending' | 'done'
export type ReviewEvent = { id: number, action: string, timestamp: number }
export type TrackProgress = { reviewStatus: ReviewStatus, reviewUpdatedAt: number, history: ReviewEvent[] }

export function filterReviewTracks<T extends { reviewStatus?: ReviewStatus }>(tracks: T[], filter: 'all' | ReviewStatus): T[] {
	return filter === 'all' ? tracks : tracks.filter((track) => (track.reviewStatus || 'pending') === filter)
}

export function useTrackProgress(request: typeof fetch = (...args) => fetch(...args)) {
	const progress = ref<TrackProgress | null>(null)
	const loading = ref(false)
	const error = ref('')
	let generation = 0

	async function run(path: string, status?: ReviewStatus): Promise<TrackProgress | null> {
		const current = ++generation
		loading.value = true
		error.value = ''
		try {
			const url = new URL(window.OC.generateUrl('/apps/musiccurator/api/library/progress'), window.location.origin)
			url.searchParams.set('path', path)
			const options: RequestInit = { credentials: 'same-origin', headers: { Accept: 'application/json' } }
			if (status !== undefined) {
				options.method = 'POST'
				options.headers = { ...options.headers, 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', requesttoken: window.OC.requestToken }
				options.body = new URLSearchParams({ path, status })
			}
			const response = await request(url.toString(), options)
			const data = await response.json().catch(() => ({}))
			if (!response.ok) {
				throw new Error(data.message || t('musiccurator', 'Could not load or save review progress. Check the Nextcloud log and try again.'))
			}
			if (!['pending', 'done'].includes(data.reviewStatus) || !Number.isFinite(data.reviewUpdatedAt) || !Array.isArray(data.history)
				|| data.history.some((event: ReviewEvent) => !event || !Number.isInteger(event.id) || !Number.isFinite(event.timestamp) || typeof event.action !== 'string')) {
				throw new Error(t('musiccurator', 'The server returned invalid review progress. Please reload the app.'))
			}
			if (current !== generation) {
				return null
			}
			progress.value = data as TrackProgress
			return progress.value
		} catch (e) {
			if (current === generation) {
				error.value = e instanceof Error ? e.message : String(e)
			}
			return null
		} finally {
			if (current === generation) {
				loading.value = false
			}
		}
	}

	function reset(): void {
		++generation
		progress.value = null
		loading.value = false
		error.value = ''
	}

	return { progress, loading, error, load: (path: string) => run(path), setStatus: (path: string, status: ReviewStatus) => loading.value ? Promise.resolve(null) : run(path, status), reset }
}
