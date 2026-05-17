<script setup>
import { computed } from 'vue'
import { useAuthStore } from '@/stores/useAuthStore.js'
import { useRouter, useRoute } from 'vue-router'

const auth  = useAuthStore()
const router = useRouter()
const route  = useRoute()

// When inside /verbund/:id/..., surface a direct Grundschutzcheck link
const currentDomainId = computed(() => {
  const m = route.path.match(/^\/verbund\/(\d+)/)
  return m ? m[1] : null
})

const navigation = [
  { name: 'Dashboard',           path: '/',              icon: '📊', roles: ['admin','isb','fachverantwortlich','auditor','management','readonly'] },
  { name: 'Kataloge',            path: '/kataloge',      icon: '📚', roles: ['admin','isb'] },
  { name: 'Informationsverbund', path: '/verbund',       icon: '🏢', roles: ['admin','isb','fachverantwortlich'] },
  { name: 'KI-Assistent',        path: '/ki-assistent',  icon: '🤖', roles: ['admin','isb','fachverantwortlich','auditor'] },
]

const bottomNavigation = [
  { name: 'Mein Profil',   path: '/profil', icon: '👤', roles: ['admin','isb','fachverantwortlich','auditor','management','readonly'] },
  { name: 'Administration', path: '/admin',  icon: '⚙️', roles: ['admin'] },
]

function visibleItems() {
  return navigation.filter(item => item.roles.includes(auth.role))
}

function visibleBottomItems() {
  return bottomNavigation.filter(item => item.roles.includes(auth.role))
}

async function handleLogout() {
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <aside class="fixed left-0 top-0 h-screen w-64 bg-gray-900 text-white flex flex-col">
    <!-- Logo -->
    <div class="px-6 py-5 border-b border-gray-700">
      <h1 class="text-lg font-bold">GS++ Manager</h1>
      <p class="text-xs text-gray-400 mt-1">KMU Compliance Tool</p>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
      <router-link
        v-for="item in visibleItems()"
        :key="item.path"
        :to="item.path"
        class="flex items-center px-3 py-2.5 text-sm rounded-lg transition-colors"
        :class="$route.path === item.path || (item.path !== '/' && $route.path.startsWith(item.path))
          ? 'bg-primary-700 text-white'
          : 'text-gray-300 hover:bg-gray-800 hover:text-white'"
      >
        <span class="mr-3 text-lg">{{ item.icon }}</span>
        {{ item.name }}
      </router-link>

      <!-- Grundschutzcheck sub-link: only shown when inside a domain -->
      <router-link
        v-if="currentDomainId && auth.role !== 'readonly'"
        :to="`/verbund/${currentDomainId}/grundschutzcheck`"
        class="flex items-center pl-9 pr-3 py-2 text-sm rounded-lg transition-colors"
        :class="$route.path.endsWith('/grundschutzcheck')
          ? 'bg-primary-700 text-white'
          : 'text-gray-400 hover:bg-gray-800 hover:text-white'"
      >
        <span class="mr-2 text-base">✅</span>
        Grundschutzcheck
      </router-link>

      <!-- Risiken sub-link: only shown when inside a domain -->
      <router-link
        v-if="currentDomainId"
        :to="`/verbund/${currentDomainId}/risiken`"
        class="flex items-center pl-9 pr-3 py-2 text-sm rounded-lg transition-colors"
        :class="$route.path.endsWith('/risiken')
          ? 'bg-primary-700 text-white'
          : 'text-gray-400 hover:bg-gray-800 hover:text-white'"
      >
        <span class="mr-2 text-base">⚠️</span>
        Risiken
      </router-link>

      <!-- Audit sub-link: only shown when inside a domain -->
      <router-link
        v-if="currentDomainId"
        :to="`/verbund/${currentDomainId}/audit`"
        class="flex items-center pl-9 pr-3 py-2 text-sm rounded-lg transition-colors"
        :class="$route.path.endsWith('/audit')
          ? 'bg-primary-700 text-white'
          : 'text-gray-400 hover:bg-gray-800 hover:text-white'"
      >
        <span class="mr-2 text-base">🔍</span>
        Audit
      </router-link>

      <!-- Maßnahmen sub-link: only shown when inside a domain -->
      <router-link
        v-if="currentDomainId"
        :to="`/verbund/${currentDomainId}/massnahmen`"
        class="flex items-center pl-9 pr-3 py-2 text-sm rounded-lg transition-colors"
        :class="$route.path.endsWith('/massnahmen')
          ? 'bg-primary-700 text-white'
          : 'text-gray-400 hover:bg-gray-800 hover:text-white'"
      >
        <span class="mr-2 text-base">📋</span>
        Maßnahmen
      </router-link>
    </nav>

    <!-- Bottom nav (Profile, Admin) -->
    <nav class="px-3 pb-2 space-y-1 border-t border-gray-700 pt-3">
      <router-link
        v-for="item in visibleBottomItems()"
        :key="item.path"
        :to="item.path"
        class="flex items-center px-3 py-2 text-sm rounded-lg transition-colors"
        :class="$route.path.startsWith(item.path)
          ? 'bg-primary-700 text-white'
          : 'text-gray-400 hover:bg-gray-800 hover:text-white'"
      >
        <span class="mr-3 text-base">{{ item.icon }}</span>
        {{ item.name }}
      </router-link>
    </nav>

    <!-- User -->
    <div class="px-4 py-4 border-t border-gray-700">
      <div class="text-sm font-medium">{{ auth.displayName }}</div>
      <div class="text-xs text-gray-400">{{ auth.role }}</div>
      <button
        @click="handleLogout"
        class="mt-3 w-full text-left text-sm text-gray-400 hover:text-white transition-colors"
      >
        Abmelden
      </button>
    </div>
  </aside>
</template>
