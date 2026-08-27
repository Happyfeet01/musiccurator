<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'

declare global {
	interface Window {
		OC: {
			generateUrl: (path: string) => string
			requestToken: string
		}
	}
}

type AiProvider = 'off' | 'openai' | 'mistral' | 'ollama'

type SettingsResponse = {
	aiProvider?: AiProvider
	openAiConfigured?: boolean
	mistralConfigured?: boolean
	openAiModel?: string
	mistralModel?: string
	ollamaModel?: string
	ollamaUrl?: string
}

type Classification = {
	groupType: 'album' | 'compilation' | 'playlist' | 'mixed' | 'unknown'
	confidence: number
	recommendedMode: 'metadata' | 'organize'
	reason: string
	provider: string
	model: string
	durationMs: number
	path: string
	stats?: {
		tracks?: number
		distinctArtists?: number
		distinctAlbums?: number
		playlistLikePath?: boolean
	}
}

type FolderEntry = {
	name: string
	path: string
}

type FolderResponse = {
	path: string
	parent: string
	folders: FolderEntry[]
	message?: string
}

const props = defineProps<{
	libraryPath: string
	selectedTrackPath: string
}>()

const provider = ref<AiProvider>('off')
const openAiKey = ref('')
const mistralKey = ref('')
const openAiConfigured = ref(false)
const mistralConfigured = ref(false)
const openAiModel = ref('gpt-5.6-luna')
const mistralModel = ref('mistral-small-latest')
const ollamaModel = ref('')
const ollamaUrl = ref('http://127.0.0.1:11434/api')
const saving = ref(false)
const testing = ref(false)
const message = ref('')
const error = ref('')
const classification = ref<Classification | null>(null)

const selectedFolder = ref('')
const folderPickerOpen = ref(false)
const folderLoading = ref(false)
const folderPath = ref('/')
const folderParent = ref('/')
const folderEntries = ref<FolderEntry[]>([])

const automaticFolder = computed(() => {
	const path = props.selectedTrackPath.trim()
	if (path !== '') {
		const slash = path.lastIndexOf('/')
		return slash > 0 ? path.slice(0, slash) : '/'
	}
	return props.libraryPath || '/'
})

const testFolder = computed(() => selectedFolder.value || automaticFolder.value)

const providerReady = computed(() => {
	if (provider.value === 'openai') return openAiConfigured.value || openAiKey.value.trim() !== ''
	if (provider.value === 'mistral') return mistralConfigured.value || mistralKey.value.trim() !== ''
	if (provider.value === 'ollama') return ollamaModel.value.trim() !== ''
	return false
})

const canBrowseUp = computed(() => {
	const library = normalizePath(props.libraryPath || '/')
	const current = normalizePath(folderPath.value)
	return current !== library && (library === '/' || current.startsWith(`${library}/`))
})

function apiUrl(path: string, query?: Record<string, string>): string {
	const url = new URL(window.OC.generateUrl(`/apps/musiccurator${path}`), window.location.origin)
	for (const [key, value] of Object.entries(query ?? {})) {
		url.searchParams.set(key, value)
	}
	return url.toString()
}

function normalizePath(path: string): string {
	const segments = path.replaceAll('\\', '/').split('/').map((part) => part.trim()).filter(Boolean)
	return segments.length === 0 ? '/' : `/${segments.join('/')}`
}

function isInsideLibrary(path: string): boolean {
	const library = normalizePath(props.libraryPath || '/')
	const candidate = normalizePath(path)
	return library === '/' || candidate === library || candidate.startsWith(`${library}/`)
}

async function load(): Promise<void> {
	try {
		const response = await fetch(apiUrl('/api/ai/settings'), {
			headers: { Accept: 'application/json' },
			credentials: 'same-origin',
		})
		const data = await response.json() as SettingsResponse & { message?: string }
		if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`)
		provider.value = data.aiProvider ?? 'off'
		openAiConfigured.value = Boolean(data.openAiConfigured)
		mistralConfigured.value = Boolean(data.mistralConfigured)
		openAiModel.value = data.openAiModel || 'gpt-5.6-luna'
		mistralModel.value = data.mistralModel || 'mistral-small-latest'
		ollamaModel.value = data.ollamaModel || ''
		ollamaUrl.value = data.ollamaUrl || 'http://127.0.0.1:11434/api'
	} catch (e) {
		error.value = e instanceof Error ? e.message : String(e)
	}
}

async function save(): Promise<boolean> {
	saving.value = true
	message.value = ''
	error.value = ''
	try {
		const body = new URLSearchParams({
			aiProvider: provider.value,
			openAiKey: openAiKey.value,
			mistralKey: mistralKey.value,
			openAiModel: openAiModel.value,
			mistralModel: mistralModel.value,
			ollamaModel: ollamaModel.value,
			ollamaUrl: ollamaUrl.value,
		})
		const response = await fetch(apiUrl('/api/ai/settings'), {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
				requesttoken: window.OC.requestToken,
			},
			body,
		})
		const data = await response.json() as SettingsResponse & { message?: string }
		if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`)
		provider.value = data.aiProvider ?? provider.value
		openAiConfigured.value = Boolean(data.openAiConfigured)
		mistralConfigured.value = Boolean(data.mistralConfigured)
		openAiModel.value = data.openAiModel || openAiModel.value
		mistralModel.value = data.mistralModel || mistralModel.value
		ollamaModel.value = data.ollamaModel ?? ollamaModel.value
		ollamaUrl.value = data.ollamaUrl || ollamaUrl.value
		openAiKey.value = ''
		mistralKey.value = ''
		message.value = 'AI advisor settings saved.'
		return true
	} catch (e) {
		error.value = e instanceof Error ? e.message : String(e)
		return false
	} finally {
		saving.value = false
	}
}

async function openFolderPicker(): Promise<void> {
	classification.value = null
	message.value = ''
	error.value = ''
	folderPickerOpen.value = true
	const fallback = props.libraryPath || '/'
	const start = selectedFolder.value || automaticFolder.value || fallback
	try {
		await browseFolder(isInsideLibrary(start) ? start : fallback)
	} catch {
		await browseFolder(fallback)
	}
}

async function browseFolder(path: string): Promise<void> {
	const target = normalizePath(path)
	if (!isInsideLibrary(target)) {
		error.value = 'AI Advisor folder selection is limited to your configured music library.'
		return
	}

	folderLoading.value = true
	error.value = ''
	try {
		const response = await fetch(apiUrl('/api/folders', { path: target }), {
			headers: { Accept: 'application/json' },
			credentials: 'same-origin',
		})
		const data = await response.json() as FolderResponse
		if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`)
		folderPath.value = data.path
		folderParent.value = data.parent
		folderEntries.value = data.folders ?? []
	} catch (e) {
		error.value = e instanceof Error ? e.message : String(e)
		throw e
	} finally {
		folderLoading.value = false
	}
}

function chooseCurrentFolder(): void {
	selectedFolder.value = normalizePath(folderPath.value)
	folderPickerOpen.value = false
	classification.value = null
	message.value = `AI test folder set to ${selectedFolder.value}.`
}

function useAutomaticFolder(): void {
	selectedFolder.value = ''
	folderPickerOpen.value = false
	classification.value = null
	message.value = `AI test folder reset to ${automaticFolder.value}.`
}

async function classify(): Promise<void> {
	classification.value = null
	message.value = ''
	error.value = ''
	if (provider.value === 'off') {
		error.value = 'Choose an AI provider first.'
		return
	}
	if (!providerReady.value) {
		error.value = 'The selected AI provider is not fully configured.'
		return
	}
	if (!(await save())) return

	testing.value = true
	message.value = ''
	try {
		const response = await fetch(apiUrl('/api/ai/classify-folder'), {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
				requesttoken: window.OC.requestToken,
			},
			body: new URLSearchParams({ path: testFolder.value }),
		})
		const data = await response.json() as Classification & { message?: string }
		if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`)
		classification.value = data
		message.value = 'AI classification completed. This is advice only; no files were changed.'
	} catch (e) {
		error.value = e instanceof Error ? e.message : String(e)
	} finally {
		testing.value = false
	}
}

onMounted(load)
</script>

<template>
	<section class="ai-panel glass-panel">
		<div class="ai-heading">
			<div>
				<p class="eyebrow">Experimental AI advisor</p>
				<h2>Classify music folders with an optional LLM</h2>
				<p class="muted">The first experiment sends filenames, existing tags and folder statistics only. Audio files are never uploaded to a cloud AI provider.</p>
			</div>
			<span class="preview-badge">Preview only</span>
		</div>

		<div v-if="message" class="ai-notice success">{{ message }}</div>
		<div v-if="error" class="ai-notice error">{{ error }}</div>

		<div class="ai-grid">
			<label>
				<span>AI provider</span>
				<select v-model="provider" class="text-input">
					<option value="off">Disabled</option>
					<option value="openai">OpenAI</option>
					<option value="mistral">Mistral</option>
					<option value="ollama">Ollama (local)</option>
				</select>
			</label>

			<template v-if="provider === 'openai'">
				<label><span>OpenAI API key</span><input v-model="openAiKey" class="text-input" type="password" :placeholder="openAiConfigured ? 'Configured — enter a new key to replace' : 'sk-…'"></label>
				<label><span>OpenAI model</span><input v-model="openAiModel" class="text-input" type="text" placeholder="gpt-5.6-luna"><small class="muted">The default favors lower-cost classification.</small></label>
			</template>

			<template v-else-if="provider === 'mistral'">
				<label><span>Mistral API key</span><input v-model="mistralKey" class="text-input" type="password" :placeholder="mistralConfigured ? 'Configured — enter a new key to replace' : 'API key'"></label>
				<label><span>Mistral model</span><input v-model="mistralModel" class="text-input" type="text" placeholder="mistral-small-latest"></label>
			</template>

			<template v-else-if="provider === 'ollama'">
				<label><span>Ollama model</span><input v-model="ollamaModel" class="text-input" type="text" placeholder="gemma4"><small class="muted">Use a model that is already installed in your local Ollama instance.</small></label>
				<label><span>Ollama API base URL</span><input v-model="ollamaUrl" class="text-input" type="text" placeholder="http://127.0.0.1:11434/api"><small class="muted">Development build currently accepts localhost only.</small></label>
			</template>
		</div>

		<div class="test-card">
			<div>
				<strong>Folder to test</strong>
				<code>{{ testFolder }}</code>
				<small class="muted">Choose any folder inside your configured music library, or keep the automatic folder from the selected library track.</small>
			</div>
			<div class="actions">
				<NcButton :disabled="folderLoading" @click="openFolderPicker">Choose folder</NcButton>
				<NcButton v-if="selectedFolder" :disabled="folderLoading" @click="useAutomaticFolder">Use automatic folder</NcButton>
				<NcButton :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save AI settings' }}</NcButton>
				<NcButton type="primary" :disabled="testing || saving || provider === 'off' || !providerReady" @click="classify">{{ testing ? 'Classifying…' : 'Classify folder' }}</NcButton>
			</div>
		</div>

		<div v-if="folderPickerOpen" class="folder-picker">
			<div class="folder-picker-heading">
				<div>
					<strong>Choose AI test folder</strong>
					<code>{{ folderPath }}</code>
				</div>
				<div class="actions">
					<NcButton v-if="canBrowseUp" :disabled="folderLoading" @click="browseFolder(folderParent)">Up</NcButton>
					<NcButton :disabled="folderLoading" @click="folderPickerOpen = false">Cancel</NcButton>
					<NcButton type="primary" :disabled="folderLoading" @click="chooseCurrentFolder">Use this folder</NcButton>
				</div>
			</div>
			<div v-if="folderLoading" class="folder-empty muted">Loading folders…</div>
			<div v-else-if="folderEntries.length" class="folder-list">
				<button v-for="folder in folderEntries" :key="folder.path" type="button" @click="browseFolder(folder.path)">
					<span aria-hidden="true">📁</span>
					<strong>{{ folder.name }}</strong>
				</button>
			</div>
			<div v-else class="folder-empty muted">No subfolders here. You can still use this folder.</div>
		</div>

		<article v-if="classification" class="result-card">
			<div class="result-title">
				<strong>{{ classification.groupType }}</strong>
				<span>{{ classification.confidence }}% confidence</span>
			</div>
			<p>{{ classification.reason }}</p>
			<div class="result-meta">
				<span><b>Recommendation:</b> {{ classification.recommendedMode === 'metadata' ? 'Metadata only' : 'Metadata + organize' }}</span>
				<span><b>Provider:</b> {{ classification.provider }} · {{ classification.model }}</span>
				<span><b>Time:</b> {{ classification.durationMs }} ms</span>
			</div>
			<div v-if="classification.stats" class="stats-row">
				<span>{{ classification.stats.tracks ?? '—' }} tracks</span>
				<span>{{ classification.stats.distinctArtists ?? '—' }} artists</span>
				<span>{{ classification.stats.distinctAlbums ?? '—' }} albums</span>
			</div>
		</article>
	</section>
</template>

<style scoped>
.ai-panel { margin-top: 12px; padding: 24px; }
.ai-heading { display: flex; justify-content: space-between; gap: 18px; align-items: flex-start; }
.ai-heading h2 { margin: 2px 0 8px; font-size: 24px; }
.eyebrow { margin: 0; color: var(--color-primary-element); font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.muted { color: var(--color-text-maxcontrast); }
.preview-badge { padding: 6px 10px; border-radius: 999px; background: var(--color-primary-element-light); color: var(--color-primary-element-light-text); font-size: 12px; font-weight: 700; white-space: nowrap; }
.ai-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-top: 18px; }
.ai-grid label { display: grid; gap: 6px; font-weight: 600; }
.ai-grid small { font-weight: 400; }
.text-input { box-sizing: border-box; width: 100%; min-height: 44px; padding: 8px 12px; border: 1px solid var(--color-border-maxcontrast); border-radius: var(--border-radius-large); background: var(--color-main-background); color: var(--color-main-text); }
.test-card { display: flex; justify-content: space-between; gap: 16px; align-items: flex-end; margin-top: 18px; padding: 16px; border-radius: var(--border-radius-large); background: var(--color-background-dark); }
.test-card > div:first-child { display: grid; gap: 4px; min-width: 0; }
.test-card code, .folder-picker code { overflow-wrap: anywhere; }
.actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
.ai-notice { margin-top: 12px; padding: 10px 12px; border-radius: var(--border-radius-large); background: var(--color-background-dark); }
.ai-notice.success { color: var(--color-success-text); }
.ai-notice.error { color: var(--color-error-text); }
.folder-picker { margin-top: 12px; padding: 16px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); background: color-mix(in srgb, var(--color-main-background) 78%, transparent); }
.folder-picker-heading { display: flex; justify-content: space-between; gap: 14px; align-items: flex-end; }
.folder-picker-heading > div:first-child { display: grid; gap: 4px; min-width: 0; }
.folder-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; margin-top: 14px; }
.folder-list button { display: flex; align-items: center; gap: 9px; min-width: 0; padding: 11px 12px; border: 0; border-radius: var(--border-radius-large); background: var(--color-background-dark); color: var(--color-main-text); text-align: start; cursor: pointer; }
.folder-list button:hover { background: var(--color-background-hover); }
.folder-list strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.folder-empty { padding: 18px 4px 4px; text-align: center; }
.result-card { margin-top: 18px; padding: 18px; border: 1px solid var(--color-primary-element); border-radius: var(--border-radius-large); background: color-mix(in srgb, var(--color-primary-element-light) 25%, transparent); }
.result-title { display: flex; justify-content: space-between; gap: 12px; align-items: center; }
.result-title strong { font-size: 22px; text-transform: capitalize; }
.result-title span { font-weight: 700; }
.result-meta, .stats-row { display: flex; flex-wrap: wrap; gap: 8px 16px; color: var(--color-text-maxcontrast); }
.stats-row { margin-top: 10px; }

@media (max-width: 720px) {
	.ai-panel { padding: 18px; }
	.ai-heading, .test-card, .folder-picker-heading { align-items: stretch; flex-direction: column; }
	.ai-grid, .folder-list { grid-template-columns: 1fr; }
	.actions { justify-content: stretch; }
	.result-title { align-items: flex-start; flex-direction: column; }
}
</style>
