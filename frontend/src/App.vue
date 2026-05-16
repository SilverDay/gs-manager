<script setup>
import { useAuthStore } from '@/stores/useAuthStore.js'
import LayoutSidebar from '@/components/LayoutSidebar.vue'

const auth = useAuthStore()
</script>

<template>
  <div class="min-h-screen">
    <!-- Authenticated layout with sidebar -->
    <div v-if="auth.isAuthenticated" class="flex">
      <LayoutSidebar />
      <main class="flex-1 ml-64 p-6 overflow-x-hidden min-w-0">
        <router-view />
      </main>
    </div>

    <!-- Public pages (login) -->
    <div v-else>
      <router-view />
    </div>
  </div>
</template>
