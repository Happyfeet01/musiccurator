import { createApp } from 'vue'
import App from './App.vue'

const requestToken = document.head.dataset.requesttoken
if (requestToken && window.OC) {
	window.OC.requestToken = requestToken
}

const app = createApp(App)
app.mount('#musiccurator')
