import { createRouter, createWebHistory } from 'vue-router'
import PreviewHomeView from '../views/PreviewHomeView.vue'

const appRouter = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'preview-home',
      component: PreviewHomeView,
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: { name: 'preview-home' },
    },
  ],
})

export default appRouter
