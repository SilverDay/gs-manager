import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import './style.css'

// Routes
const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/LoginView.vue'),
    meta: { public: true },
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
    path: '/verbund/:id/grundschutzcheck',
    name: 'SspEditor',
    component: () => import('@/views/SspEditorView.vue'),
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

const router = createRouter({
  history: createWebHistory(),
  routes,
})

// Navigation guard: redirect to login if not authenticated
router.beforeEach(async (to, from) => {
  if (to.meta.public) return true

  const { useAuthStore } = await import('@/stores/useAuthStore.js')
  const auth = useAuthStore()

  if (!auth.isAuthenticated) {
    try {
      await auth.fetchUser()
    } catch {
      return { name: 'Login' }
    }
  }

  return true
})

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.mount('#app')
