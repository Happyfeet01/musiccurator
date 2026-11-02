<script setup lang="ts">
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'

const props = defineProps<{
	modelValue: string
	credential: string
	label: string
	configured: boolean
	disabled?: boolean
	hint?: string
	placeholder?: string
}>()
const emit = defineEmits<{
	'update:modelValue': [value: string]
	deleted: []
	busy: [value: boolean]
}>()
const deleting = ref(false)
const error = ref('')
const message = ref('')

async function deleteCredential(): Promise<void> {
	if (deleting.value || props.disabled) {
		return
	}
	deleting.value = true
	emit('busy', true)
	error.value = ''
	message.value = ''
	try {
		const response = await fetch(window.OC.generateUrl(`/apps/musiccurator/api/settings/credentials/${encodeURIComponent(props.credential)}`), {
			method: 'DELETE',
			credentials: 'same-origin',
			headers: { Accept: 'application/json', requesttoken: window.OC.requestToken },
		})
		if (!response.ok) {
			throw new Error('Could not delete the saved credential. Please try again.')
		}
		emit('update:modelValue', '')
		emit('deleted')
		message.value = 'Saved credential deleted from MusicCurator.'
	} catch {
		error.value = 'Could not delete the saved credential. Please try again.'
	} finally {
		deleting.value = false
		emit('busy', false)
	}
}
</script>

<template>
	<div class="credential-field">
		<label :for="`credential-${credential}`">{{ label }}</label>
		<input :id="`credential-${credential}`"
			:value="modelValue"
			class="text-input"
			type="password"
			autocomplete="new-password"
			:disabled="disabled || deleting"
			:placeholder="configured ? 'Configured — enter a new value to replace' : (placeholder || 'Optional')"
			@input="emit('update:modelValue', ($event.target as HTMLInputElement).value)">
		<small v-if="hint" class="muted">{{ hint }}</small>
		<NcButton v-if="configured"
			:disabled="disabled || deleting"
			:aria-label="`Delete saved ${label}`"
			@click="deleteCredential">
			{{ deleting ? 'Deleting…' : 'Delete saved credential' }}
		</NcButton>
		<small v-if="message" role="status">{{ message }}</small>
		<small v-if="error" role="alert">{{ error }}</small>
	</div>
</template>

<style scoped>
.credential-field { display: flex; flex-direction: column; gap: 8px; }
.credential-field label { font-weight: 600; }
.text-input { box-sizing: border-box; width: 100%; min-height: 44px; padding: 8px 12px; border: 1px solid var(--color-border-maxcontrast); border-radius: var(--border-radius-large); background: var(--color-main-background); color: var(--color-main-text); }
.text-input:focus { border-color: var(--color-primary-element); outline: 2px solid color-mix(in srgb, var(--color-primary-element) 28%, transparent); }
.muted { color: var(--color-text-maxcontrast); }
</style>
