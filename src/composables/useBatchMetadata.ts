import { computed, ref } from 'vue'

export type BatchSuggestion = {
	id: string
	title: string
	artist: string
	album: string
	albumArtist: string
	track: string
	year: string
	genre: string
	releaseId: string
	releaseGroupId: string
	score: number
	source?: string
	sourceUrl?: string
	artworkUrl?: string
	autoAccept?: boolean
	querySource?: string
	inputConflict?: boolean
	fingerprintConfirmed?: boolean
	fingerprintConflict?: boolean
}

export type BatchProviderStatus = {
	name: string
	configured: boolean
	attempted: boolean
	ok: boolean
	results: number
	message: string
	durationMs: number
}

export type BatchSearchResponse = {
	results: BatchSuggestion[]
	providers: BatchProviderStatus[]
}

export type BatchPreviewItem = {
	path: string
	status: 'queued' | 'searching' | 'matched' | 'review' | 'unmatched' | 'error'
	best: BatchSuggestion | null
	selected: BatchSuggestion | null
	autoSelected: boolean
	providers: BatchProviderStatus[]
	error: string
}

export type BatchTrack = {
	path: string
	title: string
	artist: string
	album: string
	albumArtist: string
	filename: string
}

type SearchTrack = (path: string) => Promise<BatchSearchResponse>

const PROVIDER_DELAY_MS = 1100
export const AUTO_ACCEPT_SCORE = 90

function sleep(ms: number): Promise<void> {
	return new Promise((resolve) => window.setTimeout(resolve, ms))
}

function bestCandidate(results: BatchSuggestion[]): BatchSuggestion | null {
	if (results.length === 0) return null
	return [...results].sort((a, b) => b.score - a.score)[0] ?? null
}

function isAutoAccepted(best: BatchSuggestion | null): boolean {
	return best !== null
		&& best.score > AUTO_ACCEPT_SCORE
		&& best.autoAccept !== false
}

function resultStatus(best: BatchSuggestion | null): BatchPreviewItem['status'] {
	if (!best) return 'unmatched'
	if (isAutoAccepted(best)) return 'matched'
	return 'review'
}

export function useBatchMetadata(searchTrack: SearchTrack) {
	const selectedPaths = ref<string[]>([])
	const items = ref<Record<string, BatchPreviewItem>>({})
	const running = ref(false)
	const processed = ref(0)
	const total = ref(0)
	const cancelRequested = ref(false)

	const selectedCount = computed(() => selectedPaths.value.length)
	const progress = computed(() => total.value > 0 ? Math.round((processed.value / total.value) * 100) : 0)
	const matchedCount = computed(() => Object.values(items.value).filter((item) => item.status === 'matched').length)
	const reviewCount = computed(() => Object.values(items.value).filter((item) => item.status === 'review').length)
	const unmatchedCount = computed(() => Object.values(items.value).filter((item) => item.status === 'unmatched').length)
	const errorCount = computed(() => Object.values(items.value).filter((item) => item.status === 'error').length)

	function isSelected(path: string): boolean {
		return selectedPaths.value.includes(path)
	}

	function toggle(path: string): void {
		selectedPaths.value = isSelected(path)
			? selectedPaths.value.filter((item) => item !== path)
			: [...selectedPaths.value, path]
	}

	function replaceSelection(paths: string[]): void {
		selectedPaths.value = [...new Set(paths)]
	}

	function addSelection(paths: string[]): void {
		replaceSelection([...selectedPaths.value, ...paths])
	}

	function clearSelection(): void {
		selectedPaths.value = []
	}

	function clearResults(): void {
		items.value = {}
		processed.value = 0
		total.value = 0
		cancelRequested.value = false
	}

	function selectAlbum(track: BatchTrack, tracks: BatchTrack[]): void {
		if (!track.album) return
		const albumArtist = track.albumArtist || track.artist
		addSelection(tracks
			.filter((candidate) => candidate.album === track.album && (candidate.albumArtist || candidate.artist) === albumArtist)
			.map((candidate) => candidate.path))
	}

	function selectFolder(track: BatchTrack, tracks: BatchTrack[]): void {
		const slash = track.path.lastIndexOf('/')
		const folder = slash > 0 ? track.path.slice(0, slash) : '/'
		addSelection(tracks
			.filter((candidate) => {
				const candidateSlash = candidate.path.lastIndexOf('/')
				const candidateFolder = candidateSlash > 0 ? candidate.path.slice(0, candidateSlash) : '/'
				return candidateFolder === folder
			})
			.map((candidate) => candidate.path))
	}

	function cancel(): void {
		cancelRequested.value = true
	}

	async function run(tracks: BatchTrack[]): Promise<void> {
		if (running.value || selectedPaths.value.length === 0) return

		const queue = selectedPaths.value
			.map((path) => tracks.find((track) => track.path === path))
			.filter((track): track is BatchTrack => Boolean(track))

		running.value = true
		cancelRequested.value = false
		processed.value = 0
		total.value = queue.length

		const nextItems: Record<string, BatchPreviewItem> = { ...items.value }
		for (const track of queue) {
			nextItems[track.path] = {
				path: track.path,
				status: 'queued',
				best: null,
				selected: null,
				autoSelected: false,
				providers: [],
				error: '',
			}
		}
		items.value = { ...nextItems }

		try {
			for (let index = 0; index < queue.length; index += 1) {
				if (cancelRequested.value) break
				const track = queue[index]
				items.value = {
					...items.value,
					[track.path]: { ...items.value[track.path], status: 'searching' },
				}

				try {
					const response = await searchTrack(track.path)
					const best = bestCandidate(response.results)
					const autoSelected = isAutoAccepted(best)
					items.value = {
						...items.value,
						[track.path]: {
							path: track.path,
							status: resultStatus(best),
							best,
							selected: autoSelected ? best : null,
							autoSelected,
							providers: response.providers ?? [],
							error: '',
						},
					}
				} catch (error) {
					items.value = {
						...items.value,
						[track.path]: {
							path: track.path,
							status: 'error',
							best: null,
							selected: null,
							autoSelected: false,
							providers: [],
							error: error instanceof Error ? error.message : String(error),
						},
					}
				}

				processed.value += 1
				if (!cancelRequested.value && index < queue.length - 1) {
					await sleep(PROVIDER_DELAY_MS)
				}
			}
		} finally {
			running.value = false
		}
	}

	return {
		selectedPaths,
		items,
		running,
		processed,
		total,
		progress,
		selectedCount,
		matchedCount,
		reviewCount,
		unmatchedCount,
		errorCount,
		isSelected,
		toggle,
		replaceSelection,
		addSelection,
		clearSelection,
		clearResults,
		selectAlbum,
		selectFolder,
		run,
		cancel,
	}
}
