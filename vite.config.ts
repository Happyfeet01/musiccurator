import vue from '@vitejs/plugin-vue'
import browserslistToEsbuild from 'browserslist-to-esbuild'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import license from 'rollup-plugin-license'
import { defineConfig } from 'vite'

const metadata = readFileSync('appinfo/info.xml', 'utf8')
const appName = /<id>([^<]+)<\/id>/.exec(metadata)![1]
const appVersion = /<version>([^<]+)<\/version>/.exec(metadata)![1]

// No Node crypto/polyfill bundle is needed by this browser-only application.
export default defineConfig({
	plugins: [
		vue(),
		license({ thirdParty: { output: { file: 'js/musiccurator-main.mjs.license' } } }),
	],
	define: {
		appName: JSON.stringify(appName),
		appVersion: JSON.stringify(appVersion),
		__VUE_OPTIONS_API__: true,
		__VUE_PROD_DEVTOOLS__: false,
		__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: false,
	},
	experimental: {
		renderBuiltUrl(filename, { hostType }) {
			if (hostType === 'css') return `../${filename}`
			return { runtime: `window.OC.filePath(${JSON.stringify(appName)}, '', ${JSON.stringify(filename)})` }
		},
	},
	build: {
		outDir: '.',
		emptyOutDir: false,
		cssCodeSplit: false,
		sourcemap: true,
		target: browserslistToEsbuild(),
		rollupOptions: {
			input: { main: resolve('src/main.ts') },
			output: {
				entryFileNames: 'js/musiccurator-[name].mjs',
				chunkFileNames: 'js/[name]-[hash].chunk.mjs',
				assetFileNames: (asset) => asset.names.some(name => name.endsWith('.css'))
					? 'css/musiccurator-main.css'
					: 'img/[name]-[hash][extname]',
			},
		},
	},
})
