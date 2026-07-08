import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import appRouter from './router'

const pinia = createPinia()

const appInstance = createApp(App)

appInstance.use(pinia)
appInstance.use(appRouter)
appInstance.mount('#app')

export { pinia }
