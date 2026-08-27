<script setup lang="ts">
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import {
	AUTO_ACCEPT_SCORE,
	useBatchMetadata,
	type BatchProviderStatus,
	type BatchSearchResponse,
	type BatchSuggestion,
} from '../composables/useBatchMetadata'

declare global {
	interface Window {
		OC: {
			generateUrl: (path: string) => string
			requestToken: string
		}
	}
}

type Track = {
	path: string
	filename: string
	title: string
	artist: string
	album: string
	albumArtist: string
	genre?: string
	status: string
}

type TagWriteResponse = {
	written: boolean
	path: string
	fields: string[]
	bytes: number
	backupId: string
	message?: string
}

const props = defineProps<{
	shownTracks: Track[]
	allTracks: Track[]
	selectedPath: string
	activeProviderNames: string[]
	hasMore: boolean
	remaining: number
}>()

const emit = defineEmits<{
	selectTrack: [track: Track]
	loadMore: []
}>()

const bulkWriting = ref(false)
const writingPath = ref('')
const writeMessage = ref('')
const writeError = ref('')
const writtenPaths = ref<Record<string, boolean>>({})

function apiUrl(path: string, query?: Record<string, string>): string {
	const url = new URL(window.OC.generateUrl(`/apps/musiccurator${path}`), window.location.origin)
	for (const [key, value] of Object.entries(query ?? {})) {
		url.searchParams.set(key, value)
	}
	return url.toString()
}

async function searchTrack(path: string): Promise<BatchSearchResponse> {
	const response = await fetch(apiUrl('/api/metadata', { path }), {
		headers: { Accept: 'application/json' },
		credentials: 'same-origin',
	})
	const data = await response.json().catch(() => ({})) as Partial<BatchSearchResponse> & { message?: string }
	if (!response.ok) {
		throw new Error(data.message || `Metadata lookup failed with HTTP ${response.status}`)
	}
	return {
		results: Array.isArray(data.results) ? data.results : [],
		providers: Array.isArray(data.providers) ? data.providers : [],
	}
}

const batch = useBatchMetadata(searchTrack)

const currentTrack = computed(() => props.allTracks.find((track) => track.path === props.selectedPath) ?? null)
const batchRows = computed(() => batch.selectedPaths.value.map((path) => ({
	track: props.allTracks.find((track) => track.path === path) ?? null,
	item: batch.items.value[path] ?? null,
})).filter((row) => row.track !== null))
const hasResults = computed(() => Object.keys(batch.items.value).length > 0)
const canSelectAlbum = computed(() => Boolean(currentTrack.value?.album))
const canSearch = computed(() => batch.selectedCount.value > 0 && props.activeProviderNames.length > 0 && !batch.running.value)
const autoWriteRows = computed(() => batchRows.value.filter((row) => row.item?.autoSelected
	&& row.item.selected !== null
	&& isMp3(row.track!.filename)
	&& !writtenPaths.value[row.track!.path]))

function selectAllShown(): void {
	batch.addSelection(props.shownTracks.map((track) => track.path))
}

function selectCurrentAlbum(): void {
	if (currentTrack.value) {
		batch.selectAlbum(currentTrack.value, props.allTracks)
	}
}

function selectCurrentFolder(): void {
	if (currentTrack.value) {
		batch.selectFolder(currentTrack.value, props.allTracks)
	}
}

function isMp3(filename: string): boolean {
	return filename.toLowerCase().endsWith('.mp3')
}

async function writeCandidate(track: Track, candidate: BatchSuggestion): Promise<void> {
	writingPath.value = track.path
	writeError.value = ''
	try {
		const body = new URLSearchParams({
			path: track.path,
			title: candidate.title || '',
			artist: candidate.artist || '',
			album: candidate.album || '',
			albumArtist: candidate.albumArtist || '',
			track: candidate.track || '',
			year: candidate.year || '',
			genre: '',
			useTitle: candidate.title ? '1' : '0',
			useArtist: candidate.artist ? '1' : '0',
			useAlbum: candidate.album ? '1' : '0',
			useAlbumArtist: candidate.albumArtist ? '1' : '0',
			useTrack: candidate.track ? '1' : '0',
			useYear: candidate.year ? '1' : '0',
			useGenre: '0',
		})
		const response = await fetch(apiUrl('/api/tags/write'), {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
				requesttoken: window.OC.requestToken,
			},
			body,
		})
		const data = await response.json().catch(() => ({})) as TagWriteResponse
		if (!response.ok) {
			throw new Error(data.message || `Metadata write failed with HTTP ${response.status}`)
		}
		writtenPaths.value = { ...writtenPaths.value, [track.path]: true }
	} finally {
		writingPath.value = ''
	}
}

async function writeOne(track: Track, candidate: BatchSuggestion): Promise<void> {
	if (!isMp3(track.filename)) {
		writeError.value = 'Experimental tag writing currently supports MP3 files only.'
		return
	}
	if (!window.confirm(`Write this metadata into the MP3 file?\n\n${track.filename}\n\n${candidate.artist || 'Unknown artist'} — ${candidate.title || track.title || track.filename}\n${candidate.album || 'No album'}\n\nA rollback record for the changed fields will be stored.`)) {
		return
	}
	writeMessage.value = ''
	writeError.value = ''
	try {
		await writeCandidate(track, candidate)
		writeMessage.value = `Metadata saved in ${track.filename}. Run Scan library to refresh the displayed current tags.`
	} catch (error) {
		writeError.value = error instanceof Error ? error.message : String(error)
	}
}

async function writeAutoSelected(): Promise<void> {
	const rows = autoWriteRows.value
	if (rows.length === 0 || bulkWriting.value) return
	if (!window.confirm(`Write the ${rows.length} auto-selected high-confidence matches into their MP3 files?\n\nOnly matches above ${AUTO_ACCEPT_SCORE}% are included. Review items are NOT written. A rollback record is stored for every file.`)) {
		return
	}

	bulkWriting.value = true
	writeMessage.value = ''
	writeError.value = ''
	let written = 0
	try {
		for (const row of rows) {
			if (!row.item?.selected) continue
			try {
				await writeCandidate(row.track!, row.item.selected)
				written += 1
			} catch (error) {
				writeError.value = `Stopped after ${written} file${written === 1 ? '' : 's'}: ${error instanceof Error ? error.message : String(error)}`
				break
			}
		}
		if (written > 0) {
			writeMessage.value = `${written} MP3 file${written === 1 ? '' : 's'} updated. Run Scan library to refresh the displayed current tags.`
		}
	} finally {
		bulkWriting.value = false
	}
}

function statusLabel(status: string): string {
	return status === 'matched'
		? 'High confidence'
		: status === 'review'
			? 'Needs review'
			: status === 'unmatched'
				? 'No match'
				: status === 'searching'
					? 'Searching…'
					: status === 'queued'
						? 'Queued'
						: 'Error'
}

function providerSummary(providers: BatchProviderStatus[]): string {
	const ok = providers.filter((provider) => provider.ok && provider.attempted)
	if (ok.length === 0) return ''
	return ok.map((provider) => `${provider.name}: ${provider.results}`).join(' · ')
}

function candidateSummary(candidate: BatchSuggestion): string {
	const artist = candidate.artist || candidate.albumArtist || 'Unknown artist'
	const album = candidate.album ? ` · ${candidate.album}` : ''
	return `${artist}${album}`
}
</script>

<template>
	<div class="batch-shell">
		<section class="batch-toolbar" aria-label="Batch metadata preview controls">
			<div class="batch-heading">
				<div>
					<strong>Batch metadata preview</strong>
					<small>Choose several tracks and let MusicCurator check them sequentially. Searching is still a preview; writing requires a separate confirmation below.</small>
				</div>
				<span class="selection-count">{{ batch.selectedCount.value }} selected</span>
			</div>

			<div class="batch-actions">
				<NcButton :disabled="batch.running.value || shownTracks.length === 0" @click="selectAllShown">Select all shown</NcButton>
				<NcButton :disabled="batch.running.value || !canSelectAlbum" @click="selectCurrentAlbum">Select current album</NcButton>
				<NcButton :disabled="batch.running.value || !currentTrack" @click="selectCurrentFolder">Select current folder</NcButton>
				<NcButton :disabled="batch.running.value || batch.selectedCount.value === 0" @click="batch.clearSelection">Clear selection</NcButton>
				<NcButton
					type="primary"
					:disabled="!canSearch"
					@click="batch.run(allTracks)">
					Search metadata for selected
				</NcButton>
				<NcButton v-if="batch.running.value" @click="batch.cancel">Stop after current track</NcButton>
			</div>

			<div class="batch-hint">
				<span>Providers: {{ activeProviderNames.length ? activeProviderNames.join(', ') : 'none configured' }}</span>
				<span>Best result &gt; {{ AUTO_ACCEPT_SCORE }}% is auto-selected for the preview.</span>
			</div>

			<div v-if="batch.running.value || batch.total.value > 0" class="batch-progress">
				<div class="progress-copy">
					<strong>{{ batch.processed.value }} / {{ batch.total.value }} processed</strong>
					<span>{{ batch.progress.value }}%</span>
				</div>
				<progress :value="batch.processed.value" :max="Math.max(batch.total.value, 1)" />
				<div class="batch-summary">
					<span data-state="matched">{{ batch.matchedCount.value }} auto-selected</span>
					<span data-state="review">{{ batch.reviewCount.value }} review</span>
					<span data-state="unmatched">{{ batch.unmatchedCount.value }} no match</span>
					<span v-if="batch.errorCount.value" data-state="error">{{ batch.errorCount.value }} errors</span>
				</div>
			</div>
		</section>

		<div class="track-list">
			<div
				v-for="track in shownTracks"
				:key="track.path"
				class="track-row"
				:class="{ selected: selectedPath === track.path, checked: batch.isSelected(track.path) }">
				<label class="track-check" :title="`Select ${track.title || track.filename} for batch preview`">
					<input
						type="checkbox"
						:checked="batch.isSelected(track.path)"
						:disabled="batch.running.value"
						@change="batch.toggle(track.path)">
					<span class="sr-only">Select for batch preview</span>
				</label>
				<button class="track-open" type="button" @click="emit('selectTrack', track)">
					<span class="track-art" aria-hidden="true">♪</span>
					<span class="track-main">
						<strong>{{ track.title || track.filename }}</strong>
						<small>{{ track.artist || 'Unknown artist' }} · {{ track.album || track.path }}</small>
					</span>
				</button>
				<span class="track-status" :data-status="track.status.toLowerCase().replaceAll(' ', '-')">{{ track.status }}</span>
			</div>

			<div v-if="hasMore" class="load-more-row">
				<NcButton @click="emit('loadMore')">Show {{ Math.min(100, remaining) }} more</NcButton>
			</div>
		</div>

		<section v-if="hasResults" class="batch-results" aria-label="Batch metadata preview results">
			<div class="batch-results-heading">
				<div>
					<strong>Batch review</strong>
					<small>High-confidence matches are selected automatically. Review items stay manual and are never included in bulk writes.</small>
				</div>
				<div class="batch-write-actions">
					<NcButton
						v-if="autoWriteRows.length"
						type="primary"
						:disabled="batch.running.value || bulkWriting || writingPath !== ''"
						@click="writeAutoSelected">
						{{ bulkWriting ? 'Writing MP3 tags…' : `Write ${autoWriteRows.length} auto-selected to MP3` }}
					</NcButton>
					<NcButton :disabled="batch.running.value || bulkWriting" @click="batch.clearResults">Clear preview</NcButton>
				</div>
			</div>

			<div v-if="writeMessage" class="write-notice success">{{ writeMessage }}</div>
			<div v-if="writeError" class="write-notice error">{{ writeError }}</div>
			<div class="write-warning">
				<strong>Experimental write mode:</strong> currently MP3 only. Title, artist, album, album artist, track number and year can be written. Genre support comes next. Audio is stream-copied by ffmpeg without re-encoding, and MusicCurator stores rollback values for every changed field.
			</div>

			<div class="batch-result-list">
				<article
					v-for="row in batchRows"
					:key="row.track!.path"
					class="batch-result"
					:data-state="row.item?.status || 'queued'">
					<div class="result-track">
						<strong>{{ row.track!.title || row.track!.filename }}</strong>
						<small>{{ row.track!.artist || 'Unknown artist' }} · {{ row.track!.path }}</small>
					</div>

					<div class="result-state">
						<strong>{{ writtenPaths[row.track!.path] ? 'Saved to MP3' : statusLabel(row.item?.status || 'queued') }}</strong>
						<small v-if="writtenPaths[row.track!.path]">Rollback record stored</small>
						<small v-else-if="row.item?.autoSelected">Auto selected &gt; {{ AUTO_ACCEPT_SCORE }}%</small>
						<small v-else-if="row.item?.status === 'review'">Not selected automatically</small>
					</div>

					<div v-if="row.item?.best" class="result-candidate">
						<strong>{{ row.item.best.title || row.track!.title || row.track!.filename }}</strong>
						<span>{{ candidateSummary(row.item.best) }}</span>
						<small>{{ row.item.best.source || 'Metadata' }} · {{ row.item.best.score }}%</small>
						<small v-if="providerSummary(row.item.providers)" class="provider-copy">{{ providerSummary(row.item.providers) }}</small>
						<div v-if="isMp3(row.track!.filename) && !writtenPaths[row.track!.path]" class="row-write-action">
							<NcButton
								:disabled="bulkWriting || writingPath !== ''"
								@click="writeOne(row.track!, row.item.best)">
								{{ writingPath === row.track!.path ? 'Writing…' : 'Write this suggestion to MP3' }}
							</NcButton>
						</div>
					</div>
					<div v-else-if="row.item?.status === 'error'" class="result-candidate error-copy">
						<strong>Lookup failed</strong>
						<small>{{ row.item.error }}</small>
					</div>
					<div v-else-if="row.item?.status === 'unmatched'" class="result-candidate">
						<strong>No candidate found</strong>
						<small>{{ providerSummary(row.item.providers) || 'Configured providers returned no usable match.' }}</small>
					</div>
					<div v-else class="result-candidate">
						<strong>{{ statusLabel(row.item?.status || 'queued') }}</strong>
						<small>Waiting for metadata providers.</small>
					</div>
				</article>
			</div>
		</section>
	</div>
</template>

<style scoped>
.batch-shell { display: grid; gap: 14px; }
.batch-toolbar { display: grid; gap: 12px; padding: 16px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); background: color-mix(in srgb, var(--color-main-background) 72%, transparent); }
.batch-heading, .batch-results-heading, .progress-copy { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.batch-heading > div, .batch-results-heading > div { display: grid; gap: 3px; }
.batch-heading small, .batch-results-heading small, .batch-hint, .provider-copy { color: var(--color-text-maxcontrast); }
.selection-count { padding: 5px 10px; border-radius: 999px; background: var(--color-background-dark); font-size: 12px; font-weight: 700; white-space: nowrap; }
.batch-actions, .batch-write-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.batch-hint { display: flex; flex-wrap: wrap; gap: 6px 18px; font-size: 12px; }
.batch-progress { display: grid; gap: 7px; }
.batch-progress progress { width: 100%; height: 10px; accent-color: var(--color-primary-element); }
.batch-summary { display: flex; flex-wrap: wrap; gap: 6px; }
.batch-summary span { padding: 4px 8px; border-radius: 999px; background: var(--color-background-dark); font-size: 12px; }
.batch-summary [data-state="matched"] { color: var(--color-success-text); }
.batch-summary [data-state="review"] { color: var(--color-warning-text); }
.batch-summary [data-state="error"] { color: var(--color-error-text); }
.write-notice, .write-warning { padding: 10px 12px; border-radius: var(--border-radius-large); background: var(--color-background-dark); }
.write-notice.success { color: var(--color-success-text); }
.write-notice.error { color: var(--color-error-text); }
.write-warning { color: var(--color-text-maxcontrast); font-size: 12px; line-height: 1.45; }
.write-warning strong { color: var(--color-warning-text); }
.track-list { display: grid; gap: 6px; }
.track-row { display: grid; grid-template-columns: 34px minmax(0, 1fr) auto; align-items: center; gap: 6px; width: 100%; padding: 4px 10px 4px 6px; border-radius: var(--border-radius-large); background: transparent; }
.track-row:hover, .track-row.selected { background: var(--color-background-hover); }
.track-row.checked { box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--color-primary-element) 55%, transparent); }
.track-check { display: grid; width: 32px; height: 44px; place-items: center; cursor: pointer; }
.track-check input { width: 18px; height: 18px; margin: 0; accent-color: var(--color-primary-element); }
.track-open { display: grid; grid-template-columns: 44px minmax(0, 1fr); align-items: center; gap: 12px; min-width: 0; padding: 6px 4px; border: 0; background: transparent; color: var(--color-main-text); text-align: start; cursor: pointer; }
.track-art { display: grid; width: 40px; height: 40px; place-items: center; border-radius: 12px; background: var(--color-primary-element-light); color: var(--color-primary-element-light-text); font-size: 20px; }
.track-main { display: flex; min-width: 0; flex-direction: column; }
.track-main strong, .track-main small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.track-main small { color: var(--color-text-maxcontrast); }
.track-status { padding: 5px 10px; border-radius: 999px; background: var(--color-background-dark); font-size: 12px; font-weight: 600; white-space: nowrap; }
.track-status[data-status="needs-tags"] { color: var(--color-warning-text); }
.load-more-row { display: flex; justify-content: center; padding: 12px 0 0; }
.batch-results { display: grid; gap: 10px; padding-top: 4px; }
.batch-result-list { display: grid; gap: 7px; }
.batch-result { display: grid; grid-template-columns: minmax(220px, 1fr) 145px minmax(260px, 1.2fr); gap: 12px; align-items: center; padding: 12px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-background-dark); }
.batch-result[data-state="matched"] { border-color: color-mix(in srgb, var(--color-success) 48%, var(--color-border)); }
.batch-result[data-state="review"] { border-color: color-mix(in srgb, var(--color-warning) 55%, var(--color-border)); }
.batch-result[data-state="error"] { border-color: color-mix(in srgb, var(--color-error) 50%, var(--color-border)); }
.result-track, .result-state, .result-candidate { display: grid; gap: 2px; min-width: 0; }
.result-track small, .result-state small, .result-candidate small, .result-candidate span { overflow: hidden; color: var(--color-text-maxcontrast); text-overflow: ellipsis; white-space: nowrap; }
.result-state strong { font-size: 13px; }
.batch-result[data-state="matched"] .result-state strong { color: var(--color-success-text); }
.batch-result[data-state="review"] .result-state strong { color: var(--color-warning-text); }
.batch-result[data-state="error"] .result-state strong, .error-copy { color: var(--color-error-text); }
.row-write-action { margin-top: 8px; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }

@media (max-width: 900px) {
	.batch-result { grid-template-columns: minmax(0, 1fr) 130px; }
	.result-candidate { grid-column: 1 / -1; }
}

@media (max-width: 640px) {
	.batch-heading, .batch-results-heading { align-items: stretch; flex-direction: column; }
	.selection-count { align-self: flex-start; }
	.batch-actions :deep(button), .batch-write-actions :deep(button) { flex: 1 1 auto; }
	.track-row { grid-template-columns: 30px minmax(0, 1fr); }
	.track-status { grid-column: 2; justify-self: start; margin-bottom: 4px; }
	.batch-result { grid-template-columns: 1fr; }
	.result-candidate { grid-column: auto; }
}
</style>
