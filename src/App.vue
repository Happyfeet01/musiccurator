<script setup lang="ts">
import { computed, ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcContent from '@nextcloud/vue/components/NcContent'

type Section = 'library' | 'albums' | 'playlists' | 'changes' | 'settings'

const section = ref<Section>('library')
const onlyUnmatched = ref(false)
const applyTitle = ref(true)
const applyArtist = ref(true)
const applyAlbum = ref(false)
const applyTrack = ref(true)
const applyYear = ref(true)
const applyCover = ref(true)

const navigation: Array<{ id: Section, label: string }> = [
	{ id: 'library', label: 'Library' },
	{ id: 'albums', label: 'Albums' },
	{ id: 'playlists', label: 'Playlists' },
	{ id: 'changes', label: 'Changes' },
	{ id: 'settings', label: 'Settings' },
]

const tracks = [
	{ title: 'Enjoy The Silence', artist: 'Depeche mode', album: 'Greatest Hits', status: 'Review', confidence: 98 },
	{ title: 'Personal Jesus', artist: 'Depeche Mode', album: 'Violator', status: 'Matched', confidence: 100 },
	{ title: 'Track 03', artist: 'Unknown Artist', album: 'YouTube downloads', status: 'Unmatched', confidence: 0 },
]

const visibleTracks = computed(() => onlyUnmatched.value
	? tracks.filter((track) => track.status !== 'Matched')
	: tracks)
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
						@click="section = item.id" />
				</NcAppNavigationList>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<main class="musiccurator-shell">
				<section v-if="section === 'library'" class="page">
					<header class="hero glass-panel">
						<div>
							<p class="eyebrow">Music library manager</p>
							<h1>Curate your library without losing control.</h1>
							<p class="hero-copy">
								Compare your current audio tags with provider suggestions, approve changes field by field,
								and preview every rename or move before MusicCurator touches a file.
							</p>
						</div>
						<div class="hero-actions">
							<NcButton type="primary">Scan library</NcButton>
							<NcButton>Choose music folder</NcButton>
						</div>
					</header>

					<div class="stats-grid">
						<article class="stat-card glass-panel">
							<span>Tracks</span>
							<strong>1,284</strong>
							<small>in /Music</small>
						</article>
						<article class="stat-card glass-panel">
							<span>Needs review</span>
							<strong>37</strong>
							<small>metadata conflicts</small>
						</article>
						<article class="stat-card glass-panel">
							<span>Unmatched</span>
							<strong>12</strong>
							<small>fingerprint candidates</small>
						</article>
						<article class="stat-card glass-panel">
							<span>Pending moves</span>
							<strong>8</strong>
							<small>nothing applied yet</small>
						</article>
					</div>

					<section class="glass-panel library-panel">
						<div class="section-heading">
							<div>
								<p class="eyebrow">Review queue</p>
								<h2>Library</h2>
							</div>
							<NcCheckboxRadioSwitch v-model="onlyUnmatched" type="switch">
								Only items needing attention
							</NcCheckboxRadioSwitch>
						</div>

						<div class="track-list">
							<button
								v-for="track in visibleTracks"
								:key="`${track.artist}-${track.title}`"
								class="track-row"
								type="button">
								<span class="track-art" aria-hidden="true">♪</span>
								<span class="track-main">
									<strong>{{ track.title }}</strong>
									<small>{{ track.artist }} · {{ track.album }}</small>
								</span>
								<span class="track-status" :data-status="track.status.toLowerCase()">
									{{ track.status }}
								</span>
								<span class="confidence">{{ track.confidence ? `${track.confidence}%` : '—' }}</span>
							</button>
						</div>
					</section>

					<section class="glass-panel compare-panel">
						<div class="section-heading">
							<div>
								<p class="eyebrow">Selected track</p>
								<h2>Enjoy The Silence.mp3</h2>
							</div>
							<span class="match-badge">98% MusicBrainz match</span>
						</div>

						<div class="metadata-grid metadata-header" aria-hidden="true">
							<span>Field</span>
							<span>Current file</span>
							<span>MusicBrainz suggestion</span>
							<span>Use</span>
						</div>

						<div class="metadata-grid">
							<strong>Title</strong>
							<span>Enjoy The Silence</span>
							<span class="suggestion">Enjoy the Silence</span>
							<NcCheckboxRadioSwitch v-model="applyTitle" aria-label="Use suggested title" />

							<strong>Artist</strong>
							<span>Depeche mode</span>
							<span class="suggestion">Depeche Mode</span>
							<NcCheckboxRadioSwitch v-model="applyArtist" aria-label="Use suggested artist" />

							<strong>Album</strong>
							<span>Greatest Hits</span>
							<span class="suggestion">Violator</span>
							<NcCheckboxRadioSwitch v-model="applyAlbum" aria-label="Use suggested album" />

							<strong>Track</strong>
							<span>03</span>
							<span class="suggestion">06</span>
							<NcCheckboxRadioSwitch v-model="applyTrack" aria-label="Use suggested track number" />

							<strong>Year</strong>
							<span>—</span>
							<span class="suggestion">1990</span>
							<NcCheckboxRadioSwitch v-model="applyYear" aria-label="Use suggested year" />

							<strong>Cover</strong>
							<span>Embedded artwork</span>
							<span class="suggestion">Cover Art Archive</span>
							<NcCheckboxRadioSwitch v-model="applyCover" aria-label="Use suggested cover" />
						</div>

						<div class="move-preview">
							<div>
								<span class="label">Current path</span>
								<code>/Music/Unsorted/Enjoy The Silence.mp3</code>
							</div>
							<span class="arrow" aria-hidden="true">→</span>
							<div>
								<span class="label">Proposed path</span>
								<code>/Music/Depeche Mode/Violator/06 - Enjoy the Silence.mp3</code>
							</div>
						</div>

						<div class="review-actions">
							<NcButton>Keep original</NcButton>
							<NcButton>Preview changes</NcButton>
							<NcButton type="primary">Approve selected fields</NcButton>
						</div>
					</section>
				</section>

				<section v-else-if="section === 'albums'" class="page placeholder-page glass-panel">
					<p class="eyebrow">Albums</p>
					<h1>Album completeness comes next.</h1>
					<p>MusicCurator will compare local tracks with release tracklists and highlight missing songs without automatically moving anything.</p>
				</section>

				<section v-else-if="section === 'playlists'" class="page placeholder-page glass-panel">
					<p class="eyebrow">Playlists</p>
					<h1>Keep playlists intact while organizing files.</h1>
					<p>Playlist mode will support virtual playlists, copied playlist folders, and path updates after approved file moves.</p>
				</section>

				<section v-else-if="section === 'changes'" class="page placeholder-page glass-panel">
					<p class="eyebrow">Dry run first</p>
					<h1>Every write operation will be reviewable.</h1>
					<p>Tag writes, renames, moves and playlist updates will appear here before they are applied, with an audit trail for completed batches.</p>
				</section>

				<section v-else class="page placeholder-page glass-panel">
					<p class="eyebrow">Personal settings</p>
					<h1>Providers belong to the user.</h1>
					<p>MusicBrainz, AcoustID, Discogs and other provider credentials will be configurable per Nextcloud user. Secrets will never be stored in frontend code.</p>
				</section>
			</main>
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
.musiccurator-shell {
	box-sizing: border-box;
	min-height: 100%;
	padding: 24px;
	background: transparent;
}

.page {
	max-width: 1380px;
	margin: 0 auto;
}

.glass-panel {
	border: 1px solid color-mix(in srgb, var(--color-border) 72%, transparent);
	border-radius: var(--border-radius-large);
	background: color-mix(in srgb, var(--color-main-background) 82%, transparent);
	box-shadow: 0 10px 32px rgb(0 0 0 / 8%);
	backdrop-filter: blur(18px) saturate(135%);
}

.hero {
	display: flex;
	align-items: flex-end;
	justify-content: space-between;
	gap: 32px;
	padding: 32px;
}

.hero h1,
.placeholder-page h1 {
	max-width: 820px;
	margin: 4px 0 12px;
	font-size: clamp(28px, 3vw, 44px);
	line-height: 1.08;
}

.hero-copy {
	max-width: 760px;
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 16px;
	line-height: 1.55;
}

.eyebrow {
	margin: 0;
	color: var(--color-primary-element);
	font-size: 12px;
	font-weight: 700;
	letter-spacing: .08em;
	text-transform: uppercase;
}

.hero-actions,
.review-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.stats-grid {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 12px;
	margin: 12px 0;
}

.stat-card {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 20px;
}

.stat-card span,
.stat-card small {
	color: var(--color-text-maxcontrast);
}

.stat-card strong {
	font-size: 30px;
	font-variant-numeric: tabular-nums;
}

.library-panel,
.compare-panel {
	padding: 24px;
}

.compare-panel {
	margin-top: 12px;
}

.section-heading {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	margin-bottom: 18px;
}

.section-heading h2 {
	margin: 2px 0 0;
	font-size: 24px;
}

.track-list {
	display: grid;
	gap: 6px;
}

.track-row {
	display: grid;
	grid-template-columns: 44px minmax(0, 1fr) auto 56px;
	align-items: center;
	gap: 12px;
	width: 100%;
	padding: 10px 12px;
	border: 0;
	border-radius: var(--border-radius-large);
	background: transparent;
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
}

.track-row:hover,
.track-row:focus-visible {
	background: var(--color-background-hover);
}

.track-art {
	display: grid;
	width: 40px;
	height: 40px;
	place-items: center;
	border-radius: 12px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 20px;
}

.track-main {
	display: flex;
	min-width: 0;
	flex-direction: column;
}

.track-main small {
	overflow: hidden;
	color: var(--color-text-maxcontrast);
	text-overflow: ellipsis;
	white-space: nowrap;
}

.track-status,
.match-badge {
	padding: 5px 10px;
	border-radius: 999px;
	background: var(--color-background-dark);
	font-size: 12px;
	font-weight: 600;
}

.track-status[data-status="unmatched"] {
	color: var(--color-error-text);
}

.track-status[data-status="matched"] {
	color: var(--color-success-text);
}

.confidence {
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	text-align: end;
}

.metadata-grid {
	display: grid;
	grid-template-columns: 130px minmax(160px, 1fr) minmax(160px, 1fr) 56px;
	align-items: center;
	gap: 0;
	border-top: 1px solid var(--color-border);
}

.metadata-grid > * {
	min-width: 0;
	padding: 12px;
}

.metadata-header {
	border-top: 0;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-weight: 700;
	text-transform: uppercase;
}

.suggestion {
	border-radius: var(--border-radius);
	background: color-mix(in srgb, var(--color-primary-element-light) 55%, transparent);
}

.move-preview {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
	align-items: center;
	gap: 18px;
	margin: 24px 0;
	padding: 18px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
}

.move-preview > div {
	display: grid;
	gap: 6px;
	min-width: 0;
}

.move-preview code {
	overflow-wrap: anywhere;
}

.label {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-weight: 600;
}

.arrow {
	font-size: 24px;
}

.review-actions {
	justify-content: flex-end;
}

.placeholder-page {
	padding: 40px;
}

.placeholder-page p:last-child {
	max-width: 720px;
	color: var(--color-text-maxcontrast);
	font-size: 16px;
	line-height: 1.6;
}

@media (max-width: 900px) {
	.musiccurator-shell {
		padding: 12px;
	}

	.hero {
		align-items: stretch;
		flex-direction: column;
		padding: 22px;
	}

	.stats-grid {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	.metadata-grid {
		grid-template-columns: 92px 1fr 1fr 44px;
		font-size: 13px;
	}
}

@media (max-width: 640px) {
	.stats-grid {
		grid-template-columns: 1fr 1fr;
	}

	.section-heading {
		align-items: flex-start;
		flex-direction: column;
	}

	.track-row {
		grid-template-columns: 40px minmax(0, 1fr) auto;
	}

	.confidence {
		display: none;
	}

	.metadata-header {
		display: none;
	}

	.metadata-grid:not(.metadata-header) {
		display: grid;
		grid-template-columns: 96px 1fr 44px;
	}

	.metadata-grid:not(.metadata-header) > :nth-child(4n + 3) {
		grid-column: 2;
		padding-top: 0;
	}

	.metadata-grid:not(.metadata-header) > :nth-child(4n + 4) {
		grid-column: 3;
		grid-row: span 2;
	}

	.move-preview {
		grid-template-columns: 1fr;
	}

	.arrow {
		transform: rotate(90deg);
		text-align: center;
	}

	.review-actions {
		justify-content: stretch;
	}
}
</style>
