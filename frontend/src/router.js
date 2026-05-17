import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/LoginView.vue'),
    meta: { public: true },
  },
  {
    path: '/passwort-zuruecksetzen',
    name: 'PasswordReset',
    component: () => import('@/views/PasswordResetView.vue'),
    meta: { public: true },
  },
  {
    path: '/profil',
    name: 'Profile',
    component: () => import('@/views/ProfileView.vue'),
  },
  {
    path: '/admin',
    name: 'Admin',
    component: () => import('@/views/AdminView.vue'),
  },
  {
    path: '/',
    name: 'Dashboard',
    component: () => import('@/views/DashboardView.vue'),
  },
  {
    path: '/kataloge',
    name: 'Catalogs',
    component: () => import('@/views/CatalogView.vue'),
  },
  {
    path: '/verbund',
    name: 'Domains',
    component: () => import('@/views/DomainView.vue'),
  },
  {
    path: '/verbund/:id',
    name: 'DomainDetail',
    component: () => import('@/views/DomainView.vue'),
  },
  {
    path: '/verbund/:id/grundschutzcheck',
    name: 'SspEditor',
    component: () => import('@/views/SspEditorView.vue'),
  },
  {
    path: '/verbund/:id/risiken',
    name: 'Risks',
    component: () => import('@/views/RiskView.vue'),
  },
  {
    path: '/verbund/:id/audit',
    name: 'Audit',
    component: () => import('@/views/AuditView.vue'),
  },
  {
    path: '/verbund/:id/massnahmen',
    name: 'Poam',
    component: () => import('@/views/PoamView.vue'),
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const { useAuthStore } = await import('@/stores/useAuthStore.js')
  const auth = useAuthStore()

  if (to.meta.public) {
    // Already logged in → skip the login page
    if (auth.isAuthenticated) return '/'
    return true
  }

  if (auth.isAuthenticated) return true

  try {
    await auth.fetchUser()
    return true
  } catch {
    return '/login'
  }
})
