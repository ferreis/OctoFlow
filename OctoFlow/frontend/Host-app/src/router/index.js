import { createRouter, createWebHistory } from 'vue-router'
import { normalizeFinanceSectionName } from './viewRouting'

const appRoutes = [
  {
    path: '/',
    redirect: { name: 'dashboard' },
  },
  {
    path: '/dashboard',
    name: 'dashboard',
  },
  {
    path: '/tasks',
    name: 'tasks',
  },
  {
    path: '/test',
    name: 'test',
  },
  {
    path: '/finance',
    name: 'finance',
  },
  {
    path: '/finance/:section',
    name: 'finance-section',
  },
  {
    path: '/profile',
    name: 'profile',
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: { name: 'dashboard' },
  },
]

const appRouter = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: appRoutes,
})

appRouter.beforeEach((to) => {
  if (to.name !== 'finance-section') {
    return true
  }

  const normalizedSectionName = normalizeFinanceSectionName(to.params.section)
  if (normalizedSectionName === to.params.section) {
    return true
  }

  return {
    name: normalizedSectionName === 'accounts' ? 'finance' : 'finance-section',
    params: normalizedSectionName === 'accounts'
      ? {}
      : { section: normalizedSectionName },
    query: to.query,
    hash: to.hash,
  }
})

export default appRouter

