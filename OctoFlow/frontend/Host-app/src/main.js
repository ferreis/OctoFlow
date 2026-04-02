import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import appRouter from './router'

const appInstance = createApp(App)

appInstance.use(createPinia())
appInstance.use(appRouter)
appInstance.mount('#app')
