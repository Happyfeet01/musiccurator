<script setup lang="ts">
import type { ReviewStatus, TrackProgress } from '../composables/useTrackProgress.ts'

import { getCanonicalLocale } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { useTrackProgress } from '../composables/useTrackProgress.ts'
import { t } from '../l10n.ts'

const props = defineProps<{ track: { path: string, reviewStatus?: ReviewStatus, reviewUpdatedAt?: number } }>()
const emit = defineEmits<{ updated: [path: string, progress: TrackProgress] }>()
const review = useTrackProgress()
const currentStatus = computed(() => review.progress.value?.reviewStatus || props.track.reviewStatus || 'pending')

watch(() => props.track, () => {
	review.reset()
	void review.load(props.track.path)
}, { immediate: true })
onBeforeUnmount(review.reset)

async function setStatus(status: ReviewStatus): Promise<void> {
	const path = props.track.path
	const result = await review.setStatus(path, status)
	if (result) {
		emit('updated', path, result)
	}
}

function actionLabel(action: string): string {
	switch (action) {
		case 'marked_done': return t('musiccurator', 'Marked as done')
		case 'reopened': return t('musiccurator', 'Reopened for review')
		case 'metadata_saved': return t('musiccurator', 'Metadata saved to file')
		case 'metadata_undone': return t('musiccurator', 'Metadata write undone')
		case 'file_moved': return t('musiccurator', 'File moved')
		default: return t('musiccurator', 'Review activity')
	}
}

function eventDate(timestamp: number): string {
	return new Date(timestamp * 1000).toLocaleString(getCanonicalLocale())
}
</script>

<template>
	<section class="review-progress" :aria-label="t('musiccurator', 'Review progress')">
		<div class="review-heading">
			<h3>{{ t('musiccurator', 'Review progress') }}</h3>
			<strong>{{ currentStatus === 'done' ? t('musiccurator', 'Done') : t('musiccurator', 'Pending') }}</strong>
			<NcButton :disabled="review.loading.value" @click="setStatus(currentStatus === 'done' ? 'pending' : 'done')">
				{{ currentStatus === 'done' ? t('musiccurator', 'Reopen for review') : t('musiccurator', 'Mark as done') }}
			</NcButton>
		</div>
		<p>{{ t('musiccurator', 'Mark a track as done after reviewing it. Scanning and metadata previews do not complete it. Saving tags, undoing a write or moving the file reopens it.') }}</p>
		<p v-if="review.loading.value" role="status">
			{{ t('musiccurator', 'Loading review history…') }}
		</p>
		<p v-if="review.error.value" role="alert">
			{{ review.error.value }}
		</p>
		<NcButton v-if="review.error.value" :disabled="review.loading.value" @click="review.load(track.path)">
			{{ t('musiccurator', 'Retry') }}
		</NcButton>
		<details v-if="review.progress.value">
			<summary>{{ t('musiccurator', 'Review history (latest 50 actions)') }}</summary>
			<p v-if="review.progress.value.history.length === 0">
				{{ t('musiccurator', 'No review actions recorded yet.') }}
			</p>
			<ol v-else>
				<li v-for="event in review.progress.value.history" :key="event.id">
					<strong>{{ actionLabel(event.action) }}</strong> · {{ eventDate(event.timestamp) }}
				</li>
			</ol>
		</details>
	</section>
</template>

<style scoped>
.review-progress { margin: 16px 0; padding: 16px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); }
.review-heading { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.review-heading h3 { margin: 0; }
.review-progress summary { cursor: pointer; }
.review-progress li { margin: 8px 0; }
[role="alert"] { color: var(--color-error-text); }
</style>
