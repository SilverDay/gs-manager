<script setup>
import { useAuthStore } from '@/stores/useAuthStore.js'
import { useRouter } from 'vue-router'

const auth = useAuthStore()
const router = useRouter()

const navigation = [
  { name: 'Dashboard',           path: '/',         icon: '📊', roles: ['admin','isb','fachverantwortlich','auditor','management','readonly'] },
  { name: 'Kataloge',            path: '/kataloge', icon: '📚', roles: ['admin','isb'] },
  { name: 'Informationsverbund', path: '/verbund',  icon: '🏢', roles: ['admin','isb','fachverantwortlich'] },
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
        :class="$route.path === item.path
          ? 'bg-primary-700 text-white'
          : 'text-gray-300 hover:bg-gray-800 hover:text-white'"
      >
        <span class="mr-3 text-lg">{{ item.icon }}</span>
        {{ item.name }}
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
