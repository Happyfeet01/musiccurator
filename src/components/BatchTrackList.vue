<script setup lang="ts">
import { computed } from 'vue'
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
					<small>Choose several tracks and let MusicCurator check them sequentially. This preview never writes tags or moves files.</small>
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
					<small>High-confidence matches are selected automatically. Everything else stays manual.</small>
				</div>
				<NcButton :disabled="batch.running.value" @click="batch.clearResults">Clear preview</NcButton>
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
						<strong>{{ statusLabel(row.item?.status || 'queued') }}</strong>
						<small v-if="row.item?.autoSelected">Auto selected &gt; {{ AUTO_ACCEPT_SCORE }}%</small>
						<small v-else-if="row.item?.status === 'review'">Not selected automatically</small>
					</div>

					<div v-if="row.item?.best" class="result-candidate">
						<strong>{{ row.item.best.title || row.track!.title || row.track!.filename }}</strong>
						<span>{{ candidateSummary(row.item.best) }}</span>
						<small>{{ row.item.best.source || 'Metadata' }} · {{ row.item.best.score }}%</small>
						<small v-if="providerSummary(row.item.providers)" class="provider-copy">{{ providerSummary(row.item.providers) }}</small>
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
.batch-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.batch-hint { display: flex; flex-wrap: wrap; gap: 6px 18px; font-size: 12px; }
.batch-progress { display: grid; gap: 7px; }
.batch-progress progress { width: 100%; height: 10px; accent-color: var(--color-primary-element); }
.batch-summary { display: flex; flex-wrap: wrap; gap: 6px; }
.batch-summary span { padding: 4px 8px; border-radius: 999px; background: var(--color-background-dark); font-size: 12px; }
.batch-summary [data-state="matched"] { color: var(--color-success-text); }
.batch-summary [data-state="review"] { color: var(--color-warning-text); }
.batch-summary [data-state="error"] { color: var(--color-error-text); }
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
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }

@media (max-width: 900px) {
	.batch-result { grid-template-columns: minmax(0, 1fr) 130px; }
	.result-candidate { grid-column: 1 / -1; }
}

@media (max-width: 640px) {
	.batch-heading, .batch-results-heading { align-items: stretch; flex-direction: column; }
	.selection-count { align-self: flex-start; }
	.batch-actions :deep(button) { flex: 1 1 auto; }
	.track-row { grid-template-columns: 30px minmax(0, 1fr); }
	.track-status { grid-column: 2; justify-self: start; margin-bottom: 4px; }
	.batch-result { grid-template-columns: 1fr; }
	.result-candidate { grid-column: auto; }
}
</style>
