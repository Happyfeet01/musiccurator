<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcContent from '@nextcloud/vue/components/NcContent'

declare global {
	interface Window {
		OC: {
			generateUrl: (path: string) => string
			requestToken: string
		}
	}
}

type Section = 'library' | 'albums' | 'playlists' | 'changes' | 'settings'
type OperationMode = 'metadata' | 'organize'

type Track = {
	path: string
	filename: string
	extension: string
	mime: string
	size: number
	mtime: number
	title: string
	artist: string
	album: string
	albumArtist: string
	track: string
	year: string
	genre: string
	tagged: boolean
	status: string
}

type Playlist = {
	path: string
	name: string
}

type MusicBrainzSuggestion = {
	id: string
	title: string
	artist: string
	album: string
	albumArtist: string
	track: string
	year: string
	releaseId: string
	releaseGroupId: string
	score: number
	source?: string
	sourceUrl?: string
}

type ProviderStatus = {
	name: string
	configured: boolean
	attempted: boolean
	ok: boolean
	results: number
	message: string
	durationMs: number
}

type Change = {
	type: string
	source: string
	target: string
	timestamp: number
}

type Settings = {
	libraryPath: string
	libraryConfigured?: boolean
	musicBrainzEnabled: boolean
	acoustIdConfigured: boolean
	acoustIdUserConfigured: boolean
	discogsConfigured: boolean
	lastFmConfigured: boolean
}

type ScanResponse = {
	libraryPath: string
	tracks: Track[]
	playlists: Playlist[]
	stats: {
		tracks: number
		needsReview: number
		albums: number
		playlists: number
	}
	truncated: boolean
	durationMs?: number
}

const TRACK_PAGE_SIZE = 100
const ALBUM_PAGE_SIZE = 96

const section = ref<Section>('library')
const mobileMenuOpen = ref(false)
const onlyNeedsAttention = ref(false)
const loading = ref(false)
const musicBrainzLoading = ref(false)
const message = ref('')
const error = ref('')
const tracks = ref<Track[]>([])
const playlists = ref<Playlist[]>([])
const changes = ref<Change[]>([])
const selectedTrack = ref<Track | null>(null)
const suggestions = ref<MusicBrainzSuggestion[]>([])
const selectedSuggestion = ref<MusicBrainzSuggestion | null>(null)
const providerStatuses = ref<ProviderStatus[]>([])
const proposedPath = ref('')
const operationMode = ref<OperationMode>('organize')
const hasScanned = ref(false)
const truncated = ref(false)
const lastScannedPath = ref('')
const scanDurationMs = ref<number | null>(null)
const trackLimit = ref(TRACK_PAGE_SIZE)
const albumLimit = ref(ALBUM_PAGE_SIZE)
const stats = ref({ tracks: 0, needsReview: 0, albums: 0, playlists: 0 })

const settings = ref<Settings>({
	libraryPath: '',
	libraryConfigured: false,
	musicBrainzEnabled: true,
	acoustIdConfigured: false,
	acoustIdUserConfigured: false,
	discogsConfigured: false,
	lastFmConfigured: false,
})
const acoustIdKey = ref('')
const acoustIdUserKey = ref('')
const discogsToken = ref('')
const lastFmKey = ref('')

const folderPickerOpen = ref(false)
const folderPath = ref('/')
const folderParent = ref('/')
const folderEntries = ref<Array<{ name: string, path: string }>>([])

const useTitle = ref(true)
const useArtist = ref(true)
const useAlbum = ref(true)
const useTrack = ref(true)
const useYear = ref(true)

const navigation: Array<{ id: Section, label: string }> = [
	{ id: 'library', label: 'Library' },
	{ id: 'albums', label: 'Albums' },
	{ id: 'playlists', label: 'Playlists' },
	{ id: 'changes', label: 'Changes' },
	{ id: 'settings', label: 'Settings' },
]

const activeSectionLabel = computed(() => navigation.find((item) => item.id === section.value)?.label ?? 'Library')

const visibleTracks = computed(() => onlyNeedsAttention.value
	? tracks.value.filter((track) => track.status === 'Needs tags')
	: tracks.value)

const renderedTracks = computed(() => visibleTracks.value.slice(0, trackLimit.value))

const albumGroups = computed(() => {
	const groups = new Map<string, { artist: string, album: string, tracks: Track[] }>()
	for (const track of tracks.value) {
		if (!track.album) {
			continue
		}
		const artist = track.albumArtist || track.artist || 'Unknown Artist'
		const key = `${artist}\u0000${track.album}`
		if (!groups.has(key)) {
			groups.set(key, { artist, album: track.album, tracks: [] })
		}
		groups.get(key)?.tracks.push(track)
	}
	return [...groups.values()].sort((a, b) => `${a.artist} ${a.album}`.localeCompare(`${b.artist} ${b.album}`))
})

const renderedAlbums = computed(() => albumGroups.value.slice(0, albumLimit.value))
const scanPathMismatch = computed(() => hasScanned.value
	&& lastScannedPath.value !== ''
	&& lastScannedPath.value !== settings.value.libraryPath)
const selectedTrackIsPlaylistLike = computed(() => selectedTrack.value !== null && isPlaylistLikePath(selectedTrack.value.path))
const activeProviderNames = computed(() => {
	const providers: string[] = []
	if (settings.value.musicBrainzEnabled) providers.push('MusicBrainz')
	if (settings.value.discogsConfigured) providers.push('Discogs')
	if (settings.value.lastFmConfigured) providers.push('Last.fm')
	if (settings.value.acoustIdConfigured) providers.push('AcoustID')
	return providers
})

function apiUrl(path: string, query?: Record<string, string>): string {
	const url = new URL(window.OC.generateUrl(`/apps/musiccurator${path}`), window.location.origin)
	for (const [key, value] of Object.entries(query ?? {})) {
		url.searchParams.set(key, value)
	}
	return url.toString()
}

async function apiRequest<T>(path: string, options: RequestInit = {}, query?: Record<string, string>): Promise<T> {
	const headers = new Headers(options.headers)
	headers.set('Accept', 'application/json')
	if (options.method && options.method !== 'GET') {
		headers.set('requesttoken', window.OC.requestToken)
	}
	const response = await fetch(apiUrl(path, query), { ...options, headers })
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(data.message || `Request failed with HTTP ${response.status}`)
	}
	return data as T
}

function formBody(values: Record<string, string>): URLSearchParams {
	return new URLSearchParams(values)
}

function switchSection(target: Section): void {
	section.value = target
	mobileMenuOpen.value = false
	if (target === 'library') {
		trackLimit.value = TRACK_PAGE_SIZE
	}
	if (target === 'albums') {
		albumLimit.value = ALBUM_PAGE_SIZE
	}
	window.requestAnimationFrame(() => window.scrollTo({ top: 0, behavior: 'auto' }))
}

async function loadSettings(): Promise<void> {
	try {
		settings.value = await apiRequest<Settings>('/api/settings')
	} catch (e) {
		setError(e)
	}
}

async function saveSettings(showSuccessMessage = true): Promise<boolean> {
	loading.value = true
	clearNotice()
	try {
		settings.value = await apiRequest<Settings>('/api/settings', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
			body: formBody({
				libraryPath: settings.value.libraryPath,
				musicBrainzEnabled: settings.value.musicBrainzEnabled ? '1' : '0',
				acoustIdKey: acoustIdKey.value,
				acoustIdUserKey: acoustIdUserKey.value,
				discogsToken: discogsToken.value,
				lastFmKey: lastFmKey.value,
			}),
		})
		acoustIdKey.value = ''
		acoustIdUserKey.value = ''
		discogsToken.value = ''
		lastFmKey.value = ''
		if (showSuccessMessage) {
			message.value = 'Personal MusicCurator settings saved.'
		}
		return true
	} catch (e) {
		setError(e)
		return false
	} finally {
		loading.value = false
	}
}

async function scanLibrary(): Promise<void> {
	if (!settings.value.libraryPath.trim()) {
		error.value = 'Choose a music folder first.'
		return
	}
	if (settings.value.libraryPath === '/' && !window.confirm('The selected folder is your Nextcloud root. This may scan thousands of files and take a while. Continue?')) {
		return
	}

	loading.value = true
	clearNotice()
	let firstTrack: Track | null = null
	try {
		const data = await apiRequest<ScanResponse>('/api/library/scan', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
			body: formBody({ libraryPath: settings.value.libraryPath }),
		})
		tracks.value = data.tracks
		playlists.value = data.playlists
		stats.value = data.stats
		settings.value.libraryPath = data.libraryPath
		settings.value.libraryConfigured = true
		lastScannedPath.value = data.libraryPath
		scanDurationMs.value = data.durationMs ?? null
		truncated.value = data.truncated
		hasScanned.value = true
		trackLimit.value = TRACK_PAGE_SIZE
		albumLimit.value = ALBUM_PAGE_SIZE
		firstTrack = tracks.value[0] ?? null
		selectedTrack.value = null
		resetMatch()
		const duration = scanDurationMs.value !== null ? ` in ${(scanDurationMs.value / 1000).toFixed(1)} s` : ''
		message.value = `${data.tracks.length} audio files scanned from ${data.libraryPath}${duration}.`
	} catch (e) {
		setError(e)
	} finally {
		loading.value = false
		if (firstTrack) {
			selectTrack(firstTrack, false)
		}
	}
}

function selectTrack(track: Track, clearMessages = true): void {
	selectedTrack.value = { ...track }
	operationMode.value = isPlaylistLikePath(track.path) ? 'metadata' : 'organize'
	resetMatch()
	if (clearMessages) {
		clearNotice()
	}
}

async function searchMusicBrainz(): Promise<void> {
	const track = selectedTrack.value
	if (!track || musicBrainzLoading.value) {
		return
	}
	musicBrainzLoading.value = true
	clearNotice()
	resetMatch()
	try {
		const data = await apiRequest<{ results: MusicBrainzSuggestion[], providers: ProviderStatus[] }>('/api/metadata', {}, { path: track.path })
		if (selectedTrack.value?.path !== track.path) {
			return
		}
		suggestions.value = data.results
		providerStatuses.value = data.providers ?? []
		selectedSuggestion.value = data.results[0] ?? null
		if (selectedSuggestion.value) {
			await previewMove()
			message.value = `${data.results.length} metadata candidate${data.results.length === 1 ? '' : 's'} found for ${track.title || track.filename}.`
		} else {
			message.value = `No metadata candidates were found for ${track.title || track.filename}. Check the provider status below.`
		}
	} catch (e) {
		setError(e)
	} finally {
		musicBrainzLoading.value = false
	}
}

async function chooseSuggestion(suggestion: MusicBrainzSuggestion): Promise<void> {
	selectedSuggestion.value = suggestion
	await previewMove()
}

async function setOperationMode(mode: OperationMode): Promise<void> {
	operationMode.value = mode
	await previewMove()
}

async function previewMove(): Promise<void> {
	if (!selectedTrack.value || !selectedSuggestion.value) {
		proposedPath.value = ''
		return
	}

	if (operationMode.value === 'metadata') {
		proposedPath.value = selectedTrack.value.path
		return
	}

	try {
		const suggestion = selectedSuggestion.value
		const data = await apiRequest<{ source: string, target: string }>('/api/library/preview-move', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
			body: formBody({
				source: selectedTrack.value.path,
				title: useTitle.value ? suggestion.title : '',
				artist: useArtist.value ? (suggestion.albumArtist || suggestion.artist) : '',
				album: useAlbum.value ? suggestion.album : '',
				track: useTrack.value ? suggestion.track : '',
			}),
		})
		proposedPath.value = data.target
	} catch (e) {
		setError(e)
	}
}

async function moveSelectedTrack(): Promise<void> {
	if (operationMode.value !== 'organize' || !selectedTrack.value || !proposedPath.value) {
		return
	}
	if (!window.confirm(`Move this file?\n\n${selectedTrack.value.path}\n→\n${proposedPath.value}`)) {
		return
	}
	loading.value = true
	clearNotice()
	try {
		await apiRequest('/api/library/move', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
			body: formBody({ source: selectedTrack.value.path, target: proposedPath.value }),
		})
		message.value = 'File moved through the Nextcloud Files API.'
		await loadChanges()
		await scanLibrary()
	} catch (e) {
		setError(e)
	} finally {
		loading.value = false
	}
}

async function loadChanges(): Promise<void> {
	try {
		const data = await apiRequest<{ changes: Change[] }>('/api/changes')
		changes.value = data.changes
	} catch (e) {
		setError(e)
	}
}

async function openFolderPicker(): Promise<void> {
	folderPickerOpen.value = true
	clearNotice()
	const startPath = settings.value.libraryPath.trim() || '/'
	try {
		await browseFolder(startPath)
	} catch {
		await browseFolder('/')
	}
}

async function browseFolder(path: string): Promise<void> {
	clearNotice()
	try {
		const data = await apiRequest<{ path: string, parent: string, folders: Array<{ name: string, path: string }> }>('/api/folders', {}, { path })
		folderPath.value = data.path
		folderParent.value = data.parent
		folderEntries.value = data.folders
	} catch (e) {
		setError(e)
		throw e
	}
}

async function selectCurrentFolder(): Promise<void> {
	const previousPath = settings.value.libraryPath
	settings.value.libraryPath = folderPath.value
	const saved = await saveSettings(false)
	if (!saved) {
		settings.value.libraryPath = previousPath
		return
	}

	folderPickerOpen.value = false
	hasScanned.value = false
	lastScannedPath.value = ''
	scanDurationMs.value = null
	tracks.value = []
	playlists.value = []
	selectedTrack.value = null
	stats.value = { tracks: 0, needsReview: 0, albums: 0, playlists: 0 }
	message.value = `Music folder set to ${settings.value.libraryPath}. Ready to scan.`
}

function resetMatch(clearSuggestions = true): void {
	if (clearSuggestions) {
		suggestions.value = []
		selectedSuggestion.value = null
		providerStatuses.value = []
	}
	proposedPath.value = ''
	useTitle.value = true
	useArtist.value = true
	useAlbum.value = true
	useTrack.value = true
	useYear.value = true
}

function isPlaylistLikePath(path: string): boolean {
	return /(^|\/)(playlists?|playlisten)(?:\s|\(|\/|$)/i.test(path)
}

function clearNotice(): void {
	message.value = ''
	error.value = ''
}

function setError(value: unknown): void {
	error.value = value instanceof Error ? value.message : String(value)
}

function currentValue(field: keyof Pick<Track, 'title' | 'artist' | 'album' | 'track' | 'year'>): string {
	return selectedTrack.value?.[field] || '—'
}

function suggestedValue(field: keyof Pick<MusicBrainzSuggestion, 'title' | 'artist' | 'album' | 'track' | 'year'>): string {
	return selectedSuggestion.value?.[field] || '—'
}

function statusKey(status: string): string {
	return status.toLowerCase().replaceAll(' ', '-')
}

function formatDate(timestamp: number): string {
	return new Date(timestamp * 1000).toLocaleString()
}

onMounted(async () => {
	await Promise.all([loadSettings(), loadChanges()])
})
</script>

<template>
	<NcContent app-name="musiccurator">
		<NcAppNavigation aria-label="MusicCurator navigation">
			<template #list>
				<NcAppNavigationList>
					<NcAppNavigationItem
						v-for="item in navigation"
						:key="item.id"
						:name="item.label"
						:active="section === item.id"
						@click="switchSection(item.id)" />
				</NcAppNavigationList>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<main class="musiccurator-shell">
				<nav class="mobile-section-navigation" aria-label="MusicCurator mobile navigation">
					<button
						class="mobile-menu-trigger"
						type="button"
						:aria-expanded="mobileMenuOpen"
						@click="mobileMenuOpen = !mobileMenuOpen">
						<span aria-hidden="true">☰</span>
						<span>{{ activeSectionLabel }}</span>
					</button>
					<div v-if="mobileMenuOpen" class="mobile-menu glass-panel">
						<button
							v-for="item in navigation"
							:key="item.id"
							type="button"
							:class="{ active: section === item.id }"
							@click="switchSection(item.id)">
							{{ item.label }}
						</button>
					</div>
				</nav>

				<div v-if="message" class="notice notice-success">{{ message }}</div>
				<div v-if="error" class="notice notice-error">{{ error }}</div>
				<div v-if="scanPathMismatch" class="notice notice-warning">
					The displayed results were scanned from <strong>{{ lastScannedPath }}</strong>, while your configured library is now <strong>{{ settings.libraryPath }}</strong>. Scan again to refresh the view.
				</div>

				<section v-if="section === 'library'" class="page">
					<header class="hero glass-panel">
						<div>
							<p class="eyebrow">Music library manager</p>
							<h1>Your real Nextcloud music library.</h1>
							<p class="hero-copy">
								Scan audio files, inspect the tags already stored in them, compare them with configured metadata providers,
								and preview every file move before anything changes.
							</p>
							<p class="path-summary"><strong>Configured library:</strong> {{ settings.libraryPath || 'Not configured' }}</p>
						</div>
						<div class="hero-actions">
							<NcButton type="primary" :disabled="loading || !settings.libraryPath" @click="scanLibrary">
								{{ loading ? 'Working…' : 'Scan library' }}
							</NcButton>
							<NcButton :disabled="loading" @click="openFolderPicker">Choose music folder</NcButton>
						</div>
					</header>

					<section v-if="folderPickerOpen" class="folder-picker glass-panel">
						<div class="section-heading compact-heading">
							<div>
								<p class="eyebrow">Nextcloud files</p>
								<h2>{{ folderPath }}</h2>
							</div>
							<div class="hero-actions">
								<NcButton v-if="folderPath !== '/'" :disabled="loading" @click="browseFolder(folderParent)">Up</NcButton>
								<NcButton :disabled="loading" @click="folderPickerOpen = false">Cancel</NcButton>
								<NcButton type="primary" :disabled="loading" @click="selectCurrentFolder">Use this folder</NcButton>
							</div>
						</div>
						<div v-if="folderEntries.length" class="folder-grid">
							<button v-for="folder in folderEntries" :key="folder.path" class="folder-row" type="button" @click="browseFolder(folder.path)">
								<span aria-hidden="true">📁</span>
								<strong>{{ folder.name }}</strong>
							</button>
						</div>
						<p v-else class="muted">No subfolders here. You can still select this folder.</p>
					</section>

					<div class="stats-grid">
						<article class="stat-card glass-panel">
							<span>Tracks</span>
							<strong>{{ hasScanned ? stats.tracks : '—' }}</strong>
							<small>{{ hasScanned ? `last scan: ${lastScannedPath}` : `configured: ${settings.libraryPath || 'none'}` }}</small>
						</article>
						<article class="stat-card glass-panel">
							<span>Needs tags</span>
							<strong>{{ hasScanned ? stats.needsReview : '—' }}</strong>
							<small>missing title or artist</small>
						</article>
						<article class="stat-card glass-panel">
							<span>Albums</span>
							<strong>{{ hasScanned ? stats.albums : '—' }}</strong>
							<small>from embedded tags</small>
						</article>
						<article class="stat-card glass-panel">
							<span>Playlists</span>
							<strong>{{ hasScanned ? stats.playlists : '—' }}</strong>
							<small>.m3u / .m3u8 found</small>
						</article>
					</div>

					<div v-if="truncated" class="notice">The scan stopped after 5,000 audio files for this development build.</div>

					<section class="glass-panel library-panel">
						<div class="section-heading">
							<div>
								<p class="eyebrow">Library</p>
								<h2>{{ hasScanned ? `${tracks.length} audio files` : 'Ready to scan' }}</h2>
								<small v-if="hasScanned && visibleTracks.length > renderedTracks.length" class="muted">Showing {{ renderedTracks.length }} of {{ visibleTracks.length }} for a faster interface.</small>
							</div>
							<NcCheckboxRadioSwitch v-model="onlyNeedsAttention" type="switch" :disabled="!hasScanned" @update:model-value="trackLimit = TRACK_PAGE_SIZE">
								Only items needing attention
							</NcCheckboxRadioSwitch>
						</div>

						<div v-if="!hasScanned" class="empty-state">
							<strong>No demo data.</strong>
							<span>Select your music folder and scan it to load the real files from your Nextcloud account.</span>
						</div>
						<div v-else-if="visibleTracks.length === 0" class="empty-state">
							<strong>Nothing to review.</strong>
							<span>No files match the current filter.</span>
						</div>
						<div v-else class="track-list">
							<button
								v-for="track in renderedTracks"
								:key="track.path"
								class="track-row"
								:class="{ selected: selectedTrack?.path === track.path }"
								type="button"
								@click="selectTrack(track)">
								<span class="track-art" aria-hidden="true">♪</span>
								<span class="track-main">
									<strong>{{ track.title || track.filename }}</strong>
									<small>{{ track.artist || 'Unknown artist' }} · {{ track.album || track.path }}</small>
								</span>
								<span class="track-status" :data-status="statusKey(track.status)">{{ track.status }}</span>
							</button>
							<div v-if="renderedTracks.length < visibleTracks.length" class="load-more-row">
								<NcButton @click="trackLimit += TRACK_PAGE_SIZE">Show {{ Math.min(TRACK_PAGE_SIZE, visibleTracks.length - renderedTracks.length) }} more</NcButton>
							</div>
						</div>
					</section>

					<section v-if="selectedTrack" :key="selectedTrack.path" class="glass-panel compare-panel">
						<div class="section-heading">
							<div>
								<p class="eyebrow">Selected track</p>
								<h2>{{ selectedTrack.filename }}</h2>
								<small class="muted">{{ selectedTrack.path }}</small>
								<small class="provider-hint">Will search: {{ activeProviderNames.length ? activeProviderNames.join(', ') : 'no provider configured' }}</small>
							</div>
							<div class="hero-actions">
								<span v-if="selectedSuggestion" class="match-badge">{{ selectedSuggestion.score }}% · {{ selectedSuggestion.source || 'Metadata' }}</span>
								<NcButton :disabled="musicBrainzLoading || activeProviderNames.length === 0" @click="searchMusicBrainz">
									{{ musicBrainzLoading ? 'Searching…' : 'Search metadata' }}
								</NcButton>
							</div>
						</div>

						<div v-if="providerStatuses.length" class="provider-status-grid">
							<div v-for="provider in providerStatuses" :key="provider.name" class="provider-status" :data-ok="provider.ok ? 'yes' : 'no'">
								<strong>{{ provider.name }}</strong>
								<small v-if="provider.ok">{{ provider.results }} result{{ provider.results === 1 ? '' : 's' }} · {{ provider.durationMs }} ms</small>
								<small v-else>{{ provider.message || (provider.configured ? 'Provider unavailable' : 'Not configured') }}</small>
							</div>
						</div>

						<div v-if="suggestions.length > 1" class="candidate-list">
							<button
								v-for="candidate in suggestions"
								:key="`${candidate.source}-${candidate.id}-${candidate.releaseId}`"
								type="button"
								class="candidate"
								:class="{ selected: selectedSuggestion?.source === candidate.source && selectedSuggestion?.id === candidate.id && selectedSuggestion?.releaseId === candidate.releaseId }"
								@click="chooseSuggestion(candidate)">
								<strong>{{ candidate.title || selectedTrack.title || selectedTrack.filename }}</strong>
								<span>{{ candidate.artist || 'Unknown artist' }} · {{ candidate.album || 'Unknown release' }}</span>
								<small>{{ candidate.source || 'Metadata' }} · {{ candidate.score }}%</small>
							</button>
						</div>

						<div class="metadata-grid metadata-header" aria-hidden="true">
							<span>Field</span><span>Current file</span><span>Metadata suggestion</span><span>Use</span>
						</div>
						<div class="metadata-grid">
							<strong>Title</strong><span>{{ currentValue('title') }}</span><span class="suggestion">{{ suggestedValue('title') }}</span><NcCheckboxRadioSwitch v-model="useTitle" :disabled="!selectedSuggestion" @update:model-value="previewMove" />
							<strong>Artist</strong><span>{{ currentValue('artist') }}</span><span class="suggestion">{{ suggestedValue('artist') }}</span><NcCheckboxRadioSwitch v-model="useArtist" :disabled="!selectedSuggestion" @update:model-value="previewMove" />
							<strong>Album</strong><span>{{ currentValue('album') }}</span><span class="suggestion">{{ suggestedValue('album') }}</span><NcCheckboxRadioSwitch v-model="useAlbum" :disabled="!selectedSuggestion" @update:model-value="previewMove" />
							<strong>Track</strong><span>{{ currentValue('track') }}</span><span class="suggestion">{{ suggestedValue('track') }}</span><NcCheckboxRadioSwitch v-model="useTrack" :disabled="!selectedSuggestion" @update:model-value="previewMove" />
							<strong>Year</strong><span>{{ currentValue('year') }}</span><span class="suggestion">{{ suggestedValue('year') }}</span><NcCheckboxRadioSwitch v-model="useYear" :disabled="!selectedSuggestion" />
							<strong>Genre</strong><span>{{ selectedTrack.genre || '—' }}</span><span class="suggestion">—</span><span />
						</div>

						<div v-if="selectedSuggestion" class="operation-mode-panel">
							<div class="operation-mode-heading">
								<div>
									<strong>What should MusicCurator do?</strong>
									<small v-if="selectedTrackIsPlaylistLike" class="muted">Playlist-like folder detected — keeping the file in place is the safe default.</small>
								</div>
							</div>
							<div class="operation-mode-options">
								<label class="operation-option" :class="{ selected: operationMode === 'metadata' }">
									<input :checked="operationMode === 'metadata'" type="radio" name="operation-mode" value="metadata" @change="setOperationMode('metadata')">
									<span><strong>Metadata only</strong><small>Keep the file exactly where it is.</small></span>
								</label>
								<label class="operation-option" :class="{ selected: operationMode === 'organize' }">
									<input :checked="operationMode === 'organize'" type="radio" name="operation-mode" value="organize" @change="setOperationMode('organize')">
									<span><strong>Metadata + organize file</strong><small>Preview a move into an artist/album hierarchy.</small></span>
								</label>
							</div>
						</div>

						<div class="read-only-note">
							<strong>Safe development mode:</strong> embedded tags are currently read-only. Metadata-only mode already guarantees that MusicCurator will not move the file. Actual tag writing will be enabled only after the write path has dedicated backup, review and rollback tests.
						</div>

						<div v-if="selectedSuggestion && operationMode === 'metadata'" class="location-preserved-note">
							<strong>File location will be preserved.</strong>
							<span>{{ selectedTrack.path }}</span>
							<small>Only the checked metadata fields are intended to change once safe tag writing is enabled.</small>
						</div>

						<div v-if="proposedPath && operationMode === 'organize'" class="move-preview">
							<div><span class="label">Current path</span><code>{{ selectedTrack.path }}</code></div>
							<span class="arrow" aria-hidden="true">→</span>
							<div><span class="label">Proposed path</span><code>{{ proposedPath }}</code></div>
						</div>
						<div v-if="operationMode === 'organize'" class="review-actions">
							<NcButton v-if="selectedSuggestion" @click="previewMove">Refresh preview</NcButton>
							<NcButton v-if="proposedPath" type="primary" :disabled="loading" @click="moveSelectedTrack">Move file</NcButton>
						</div>
					</section>
				</section>

				<section v-else-if="section === 'albums'" class="page">
					<header class="subhero glass-panel">
						<p class="eyebrow">Albums</p>
						<h1>Albums found in your tags</h1>
						<p class="muted">This view is generated from the latest library scan. Release matching and completeness checks come next.</p>
					</header>
					<div v-if="!hasScanned" class="glass-panel empty-state spaced">Scan your library first.</div>
					<template v-else>
						<div class="result-summary">Showing {{ renderedAlbums.length }} of {{ albumGroups.length }} albums.</div>
						<div class="album-grid">
							<article v-for="album in renderedAlbums" :key="`${album.artist}-${album.album}`" class="album-card glass-panel">
								<span class="album-art" aria-hidden="true">♫</span>
								<div><strong>{{ album.album }}</strong><small>{{ album.artist }}</small></div>
								<b>{{ album.tracks.length }}</b>
							</article>
						</div>
						<div v-if="renderedAlbums.length < albumGroups.length" class="load-more-row spaced">
							<NcButton @click="albumLimit += ALBUM_PAGE_SIZE">Show {{ Math.min(ALBUM_PAGE_SIZE, albumGroups.length - renderedAlbums.length) }} more albums</NcButton>
						</div>
					</template>
				</section>

				<section v-else-if="section === 'playlists'" class="page">
					<header class="subhero glass-panel"><p class="eyebrow">Playlists</p><h1>Playlist files</h1><p class="muted">MusicCurator detects .m3u and .m3u8 files without altering them.</p></header>
					<div v-if="!hasScanned" class="glass-panel empty-state spaced">Scan your library first.</div>
					<div v-else-if="playlists.length === 0" class="glass-panel empty-state spaced">No playlist files found in the selected library.</div>
					<div v-else class="simple-list glass-panel">
						<div v-for="playlist in playlists" :key="playlist.path" class="simple-row"><strong>{{ playlist.name }}</strong><small>{{ playlist.path }}</small></div>
					</div>
				</section>

				<section v-else-if="section === 'changes'" class="page">
					<header class="subhero glass-panel"><p class="eyebrow">Changes</p><h1>Recent file operations</h1><p class="muted">Only changes performed by MusicCurator are listed here.</p></header>
					<div v-if="changes.length === 0" class="glass-panel empty-state spaced">No MusicCurator file moves yet.</div>
					<div v-else class="simple-list glass-panel">
						<div v-for="change in changes" :key="`${change.timestamp}-${change.source}`" class="change-row">
							<div><strong>{{ change.type }}</strong><small>{{ formatDate(change.timestamp) }}</small></div>
							<code>{{ change.source }}</code><span>→</span><code>{{ change.target }}</code>
						</div>
					</div>
				</section>

				<section v-else class="page settings-page">
					<header class="subhero glass-panel"><p class="eyebrow">Personal settings</p><h1>Your library and providers</h1><p class="muted">These settings belong to the currently signed-in Nextcloud user.</p></header>
					<section class="glass-panel settings-card">
						<h2>Music library</h2>
						<p class="path-summary"><strong>Configured path:</strong> {{ settings.libraryPath || 'Not configured' }}</p>
						<p v-if="lastScannedPath" class="path-summary"><strong>Last scanned path:</strong> {{ lastScannedPath }}</p>
						<label class="field-label" for="library-path">Nextcloud music folder</label>
						<div class="field-row">
							<input id="library-path" v-model="settings.libraryPath" class="text-input" type="text" placeholder="/Musik">
							<NcButton :disabled="loading" @click="openFolderPicker">Browse</NcButton>
							<NcButton type="primary" :disabled="loading || !settings.libraryPath.trim()" @click="saveSettings()">Save folder</NcButton>
						</div>
					</section>
					<section class="glass-panel settings-card">
						<h2>Metadata providers</h2>
						<NcCheckboxRadioSwitch v-model="settings.musicBrainzEnabled" type="switch">Use MusicBrainz</NcCheckboxRadioSwitch>
						<p class="muted">MusicBrainz needs no personal API key. Discogs, Last.fm and AcoustID become active automatically after their credentials are saved. Provider failures no longer block results from the others.</p>
						<div class="provider-grid">
							<label><span>AcoustID client/API key</span><input v-model="acoustIdKey" class="text-input" type="password" :placeholder="settings.acoustIdConfigured ? 'Configured — enter a new value to replace' : 'Optional'" /><small class="muted">Used for fingerprint lookup. Requires fpcalc/Chromaprint on the Nextcloud server.</small></label>
							<label><span>AcoustID user key</span><input v-model="acoustIdUserKey" class="text-input" type="password" :placeholder="settings.acoustIdUserConfigured ? 'Configured — enter a new value to replace' : 'Optional'" /><small class="muted">Reserved for future fingerprint submissions; not required for lookup.</small></label>
							<label><span>Discogs personal token</span><input v-model="discogsToken" class="text-input" type="password" :placeholder="settings.discogsConfigured ? 'Configured — enter a new value to replace' : 'Optional'" /><small class="muted">Adds release, compilation and track-list candidates.</small></label>
							<label><span>Last.fm API key</span><input v-model="lastFmKey" class="text-input" type="password" :placeholder="settings.lastFmConfigured ? 'Configured — enter a new value to replace' : 'Optional'" /><small class="muted">Adds track and artist fallback results.</small></label>
						</div>
						<div class="review-actions"><NcButton type="primary" :disabled="loading" @click="saveSettings()">Save personal settings</NcButton></div>
					</section>
				</section>
			</main>
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
.musiccurator-shell { box-sizing: border-box; min-height: 100%; padding: 24px; background: transparent; }
.page { max-width: 1380px; margin: 0 auto; }
.glass-panel { border: 1px solid color-mix(in srgb, var(--color-border) 72%, transparent); border-radius: var(--border-radius-large); background: color-mix(in srgb, var(--color-main-background) 82%, transparent); box-shadow: 0 10px 32px rgb(0 0 0 / 8%); backdrop-filter: blur(18px) saturate(135%); }
.hero { display: flex; align-items: flex-end; justify-content: space-between; gap: 32px; padding: 32px; }
.hero h1, .subhero h1 { max-width: 820px; margin: 4px 0 12px; font-size: clamp(28px, 3vw, 44px); line-height: 1.08; }
.subhero { padding: 28px 32px; margin-bottom: 12px; }
.hero-copy { max-width: 760px; margin: 0; color: var(--color-text-maxcontrast); font-size: 16px; line-height: 1.55; }
.path-summary { margin: 12px 0 0; color: var(--color-text-maxcontrast); overflow-wrap: anywhere; }
.eyebrow { margin: 0; color: var(--color-primary-element); font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.muted { color: var(--color-text-maxcontrast); }
.hero-actions, .review-actions, .field-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.notice { max-width: 1380px; margin: 0 auto 12px; padding: 12px 16px; border-radius: var(--border-radius-large); background: var(--color-background-dark); }
.notice-success { color: var(--color-success-text); }
.notice-error { color: var(--color-error-text); }
.notice-warning { color: var(--color-warning-text); }
.mobile-section-navigation { display: none; }
.stats-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin: 12px 0; }
.stat-card { display: flex; flex-direction: column; gap: 4px; min-width: 0; padding: 20px; }
.stat-card span, .stat-card small { color: var(--color-text-maxcontrast); overflow-wrap: anywhere; }
.stat-card strong { font-size: 30px; font-variant-numeric: tabular-nums; }
.library-panel, .compare-panel, .folder-picker, .settings-card { padding: 24px; }
.compare-panel, .folder-picker, .settings-card { margin-top: 12px; }
.section-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
.compact-heading { margin-bottom: 10px; }
.section-heading h2, .settings-card h2 { margin: 2px 0 0; font-size: 24px; }
.provider-hint { display: block; margin-top: 6px; color: var(--color-text-maxcontrast); }
.folder-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; }
.folder-row { display: flex; align-items: center; gap: 10px; padding: 12px; border: 0; border-radius: var(--border-radius-large); background: var(--color-background-dark); color: var(--color-main-text); text-align: start; cursor: pointer; }
.folder-row:hover { background: var(--color-background-hover); }
.track-list { display: grid; gap: 6px; }
.track-row { display: grid; grid-template-columns: 44px minmax(0, 1fr) auto; align-items: center; gap: 12px; width: 100%; padding: 10px 12px; border: 0; border-radius: var(--border-radius-large); background: transparent; color: var(--color-main-text); text-align: start; cursor: pointer; }
.track-row:hover, .track-row:focus-visible, .track-row.selected { background: var(--color-background-hover); }
.track-art { display: grid; width: 40px; height: 40px; place-items: center; border-radius: 12px; background: var(--color-primary-element-light); color: var(--color-primary-element-light-text); font-size: 20px; }
.track-main { display: flex; min-width: 0; flex-direction: column; }
.track-main small { overflow: hidden; color: var(--color-text-maxcontrast); text-overflow: ellipsis; white-space: nowrap; }
.track-status, .match-badge { padding: 5px 10px; border-radius: 999px; background: var(--color-background-dark); font-size: 12px; font-weight: 600; white-space: nowrap; }
.track-status[data-status="needs-tags"] { color: var(--color-warning-text); }
.load-more-row { display: flex; justify-content: center; padding: 16px 0 4px; }
.result-summary { max-width: 1380px; margin: 0 auto 12px; color: var(--color-text-maxcontrast); }
.provider-status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; margin: 0 0 18px; }
.provider-status { display: grid; gap: 2px; padding: 10px 12px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-background-dark); }
.provider-status small { color: var(--color-text-maxcontrast); overflow-wrap: anywhere; }
.provider-status[data-ok="yes"] strong { color: var(--color-success-text); }
.provider-status[data-ok="no"] strong { color: var(--color-warning-text); }
.metadata-grid { display: grid; grid-template-columns: 130px minmax(160px, 1fr) minmax(160px, 1fr) 56px; align-items: center; gap: 0; border-top: 1px solid var(--color-border); }
.metadata-grid > * { min-width: 0; padding: 12px; }
.metadata-header { border-top: 0; color: var(--color-text-maxcontrast); font-size: 12px; font-weight: 700; text-transform: uppercase; }
.suggestion { border-radius: var(--border-radius); background: color-mix(in srgb, var(--color-primary-element-light) 55%, transparent); }
.candidate-list { display: grid; gap: 6px; margin-bottom: 18px; }
.candidate { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 2px 12px; padding: 10px 12px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); background: transparent; color: var(--color-main-text); text-align: start; cursor: pointer; }
.candidate span { grid-column: 1; color: var(--color-text-maxcontrast); }
.candidate small { grid-column: 2; grid-row: 1 / span 2; align-self: center; }
.candidate.selected, .candidate:hover { background: var(--color-background-hover); border-color: var(--color-primary-element); }
.operation-mode-panel { display: grid; gap: 12px; margin: 18px 0; padding: 16px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); background: color-mix(in srgb, var(--color-main-background) 72%, transparent); }
.operation-mode-heading > div { display: grid; gap: 4px; }
.operation-mode-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.operation-option { display: flex; align-items: flex-start; gap: 10px; padding: 14px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-background-dark); cursor: pointer; }
.operation-option.selected { border-color: var(--color-primary-element); background: color-mix(in srgb, var(--color-primary-element-light) 45%, var(--color-background-dark)); }
.operation-option input { margin-top: 3px; }
.operation-option span { display: grid; gap: 3px; }
.operation-option small { color: var(--color-text-maxcontrast); }
.read-only-note { margin: 18px 0; padding: 14px 16px; border-radius: var(--border-radius-large); background: var(--color-background-dark); color: var(--color-text-maxcontrast); }
.location-preserved-note { display: grid; gap: 5px; margin: 18px 0; padding: 16px; border-radius: var(--border-radius-large); background: color-mix(in srgb, var(--color-success) 10%, var(--color-background-dark)); }
.location-preserved-note span { overflow-wrap: anywhere; font-family: var(--font-monospace, monospace); }
.location-preserved-note small { color: var(--color-text-maxcontrast); }
.move-preview { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: 18px; margin: 24px 0; padding: 18px; border-radius: var(--border-radius-large); background: var(--color-background-dark); }
.move-preview > div { display: grid; gap: 6px; min-width: 0; }
.move-preview code, .change-row code { overflow-wrap: anywhere; }
.label { color: var(--color-text-maxcontrast); font-size: 12px; font-weight: 600; }
.arrow { font-size: 24px; }
.review-actions { justify-content: flex-end; }
.empty-state { display: grid; gap: 6px; padding: 32px; color: var(--color-text-maxcontrast); text-align: center; }
.empty-state strong { color: var(--color-main-text); font-size: 18px; }
.spaced { margin-top: 12px; }
.album-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
.album-card { display: grid; grid-template-columns: 52px minmax(0, 1fr) auto; align-items: center; gap: 12px; min-width: 0; padding: 16px; }
.album-card div { display: grid; min-width: 0; }
.album-card strong, .album-card small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.album-card small { color: var(--color-text-maxcontrast); }
.album-art { display: grid; width: 52px; height: 52px; place-items: center; border-radius: 16px; background: var(--color-primary-element-light); font-size: 24px; }
.simple-list { padding: 8px; }
.simple-row { display: grid; gap: 2px; padding: 12px; border-bottom: 1px solid var(--color-border); }
.simple-row:last-child { border-bottom: 0; }
.simple-row small { color: var(--color-text-maxcontrast); }
.change-row { display: grid; grid-template-columns: 160px minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: 12px; padding: 12px; border-bottom: 1px solid var(--color-border); }
.change-row:last-child { border-bottom: 0; }
.change-row > div { display: grid; }
.change-row small { color: var(--color-text-maxcontrast); }
.settings-page { padding-bottom: 24px; }
.field-label, .provider-grid label { display: grid; gap: 6px; font-weight: 600; }
.text-input { box-sizing: border-box; width: 100%; min-height: 44px; flex: 1 1 340px; padding: 8px 12px; border: 1px solid var(--color-border-maxcontrast); border-radius: var(--border-radius-large); background: var(--color-main-background); color: var(--color-main-text); }
.text-input:focus { border-color: var(--color-primary-element); outline: 2px solid color-mix(in srgb, var(--color-primary-element) 28%, transparent); }
.provider-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin: 18px 0; }

@media (max-width: 900px) {
	.musiccurator-shell { padding: 12px; }
	.mobile-section-navigation { position: relative; z-index: 30; display: block; max-width: 1380px; margin: 0 auto 12px; }
	.mobile-menu-trigger { display: flex; width: 100%; min-height: 44px; align-items: center; gap: 10px; padding: 10px 14px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); background: color-mix(in srgb, var(--color-main-background) 86%, transparent); color: var(--color-main-text); font-weight: 700; text-align: start; backdrop-filter: blur(18px); }
	.mobile-menu { position: absolute; top: calc(100% + 6px); right: 0; left: 0; display: grid; gap: 4px; padding: 8px; }
	.mobile-menu button { min-height: 44px; padding: 10px 14px; border: 0; border-radius: var(--border-radius-large); background: transparent; color: var(--color-main-text); text-align: start; }
	.mobile-menu button:hover, .mobile-menu button:focus-visible, .mobile-menu button.active { background: var(--color-background-hover); color: var(--color-primary-element); }
	.hero { align-items: stretch; flex-direction: column; padding: 22px; }
	.stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
	.metadata-grid { grid-template-columns: 92px 1fr 1fr 44px; font-size: 13px; }
	.change-row { grid-template-columns: 1fr; }
	.provider-grid, .operation-mode-options { grid-template-columns: 1fr; }
}

@media (max-width: 640px) {
	.stats-grid { grid-template-columns: 1fr 1fr; }
	.section-heading { align-items: flex-start; flex-direction: column; }
	.track-row { grid-template-columns: 40px minmax(0, 1fr); }
	.track-status { grid-column: 2; justify-self: start; }
	.metadata-header { display: none; }
	.metadata-grid:not(.metadata-header) { display: grid; grid-template-columns: 96px 1fr 44px; }
	.metadata-grid:not(.metadata-header) > :nth-child(4n + 3) { grid-column: 2; padding-top: 0; }
	.metadata-grid:not(.metadata-header) > :nth-child(4n + 4) { grid-column: 3; grid-row: span 2; }
	.move-preview { grid-template-columns: 1fr; }
	.arrow { transform: rotate(90deg); text-align: center; }
	.review-actions { justify-content: stretch; }
	.folder-grid { grid-template-columns: 1fr; }
	.library-panel, .compare-panel, .folder-picker, .settings-card { padding: 18px; }
}
</style>