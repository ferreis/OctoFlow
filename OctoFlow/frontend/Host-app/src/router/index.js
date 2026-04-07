import { createRouter, createWebHistory } from 'vue-router'
import DashboardScreen from '../components/screens/DashboardScreen.vue'
import ProfileScreen from '../components/screens/ProfileScreen.vue'
import TasksScreen from '../components/screens/TasksScreen.vue'
import TestScreen from '../components/screens/TestScreen.vue'
import AppLayoutView from '../views/AppLayoutView.vue'
import AuthEntryView from '../views/AuthEntryView.vue'
import AuthGooglePasswordSetupView from '../views/AuthGooglePasswordSetupView.vue'
import FinanceLayout from '../views/finance/FinanceLayout.vue'
import { useSessionStore } from '../stores/sessionStore'

const appRoutes = [
  {
    path: '/',
    redirect: { name: 'dashboard' },
  },
  {
    path: '/auth/login',
    name: 'auth-login',
    component: AuthEntryView,
    meta: {
      guestOnly: true,
      authMode: 'login',
    },
  },
  {
    path: '/auth/register',
    name: 'auth-register',
    component: AuthEntryView,
    meta: {
      guestOnly: true,
      authMode: 'register',
    },
  },
  {
    path: '/auth/google-password-setup',
    name: 'auth-google-password-setup',
    component: AuthGooglePasswordSetupView,
    meta: {
      requiresAuth: true,
    },
  },
  {
    path: '/app',
    component: AppLayoutView,
    meta: {
      requiresAuth: true,
      requiresPasswordSetupComplete: true,
    },
    children: [
      {
        path: 'dashboard',
        name: 'dashboard',
        component: DashboardScreen,
      },
      {
        path: 'tasks',
        name: 'tasks',
        component: TasksScreen,
      },
      {
        path: 'test',
        name: 'test',
        component: TestScreen,
      },
      {
        path: 'finance',
        component: FinanceLayout,
        children: [
          {
            path: '',
            name: 'finance',
            redirect: { name: 'finance-accounts' },
          },
          {
            path: 'accounts',
            name: 'finance-accounts',
            component: () => import('../views/finance/FinanceAccountsView.vue'),
          },
          {
            path: 'banks',
            name: 'finance-banks',
            component: () => import('../views/finance/FinanceBanksView.vue'),
          },
          {
            path: 'investments',
            name: 'finance-investments',
            component: () => import('../views/finance/FinanceInvestmentsView.vue'),
          },
          {
            path: 'settings',
            name: 'finance-settings',
            component: () => import('../views/finance/FinanceSettingsView.vue'),
          },
          {
            path: 'reports',
            name: 'finance-reports',
            component: () => import('../views/finance/FinanceReportsView.vue'),
          },
        ],
      },
      {
        path: 'profile',
        name: 'profile',
        component: ProfileScreen,
      },
    ],
  },
  // ─── Redirects legados ───
  {
    path: '/dashboard',
    redirect: { name: 'dashboard' },
  },
  {
    path: '/tasks',
    redirect: { name: 'tasks' },
  },
  {
    path: '/test',
    redirect: { name: 'test' },
  },
  {
    path: '/finance',
    redirect: { name: 'finance-accounts' },
  },
  {
    path: '/finance/:section',
    redirect: (to) => {
      const sectionMap = {
        accounts: 'finance-accounts',
        banks: 'finance-banks',
        investments: 'finance-investments',
        settings: 'finance-settings',
        currencies: 'finance-settings',
        reports: 'finance-reports',
        debts: 'finance-accounts',
      }
      const routeName = sectionMap[to.params.section] || 'finance-accounts'
      return { name: routeName }
    },
  },
  {
    path: '/profile',
    redirect: { name: 'profile' },
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

appRouter.beforeEach(async (to) => {
  const sessionStore = useSessionStore()

  await sessionStore.ensureInitialized()

  const mustBeAuthenticated = to.matched.some((record) => record.meta.requiresAuth === true)
  const guestOnlyRoute = to.matched.some((record) => record.meta.guestOnly === true)
  const mustFinishGooglePasswordSetup = to.matched.some((record) => record.meta.requiresPasswordSetupComplete === true)

  if (mustBeAuthenticated && !sessionStore.isAuthenticated) {
    return {
      name: 'auth-login',
      query: {
        redirect: to.fullPath,
      },
    }
  }

  if (guestOnlyRoute && sessionStore.isAuthenticated) {
    if (sessionStore.requiresGooglePasswordSetup) {
      return { name: 'auth-google-password-setup' }
    }

    const redirectPath = typeof to.query.redirect === 'string' ? to.query.redirect.trim() : ''
    if (redirectPath !== '') {
      return redirectPath
    }

    return { name: 'dashboard' }
  }

  if (to.name === 'auth-google-password-setup') {
    if (!sessionStore.isAuthenticated) {
      return { name: 'auth-login' }
    }

    if (!sessionStore.requiresGooglePasswordSetup) {
      return { name: 'dashboard' }
    }
  }

  if (mustFinishGooglePasswordSetup && sessionStore.requiresGooglePasswordSetup) {
    return { name: 'auth-google-password-setup' }
  }

  return true
})

export default appRouter
