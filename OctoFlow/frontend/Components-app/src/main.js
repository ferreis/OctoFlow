import { createApp } from 'vue'
import { createPinia } from 'pinia'
import appRouter from './router'
import './style.css'
import App from './App.vue'

const appInstance = createApp(App)
appInstance.use(createPinia())
appInstance.use(appRouter)
appInstance.mount('#app')
