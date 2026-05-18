<script setup>
import { useRoute } from 'vue-router'
import LayoutSidebar from '@/components/LayoutSidebar.vue'
import ContextualGuide from '@/components/ContextualGuide.vue'

const route = useRoute()
</script>

<template>
  <div class="min-h-screen">
    <!-- Authenticated layout — shown when current route is protected -->
    <div v-if="!route.meta.public" class="flex">
      <LayoutSidebar />
      <main class="flex-1 ml-64 p-6 overflow-x-hidden min-w-0 h-screen overflow-y-auto">
        <router-view />
      </main>
      <ContextualGuide />
    </div>

    <!-- Public pages (login, password reset) -->
    <div v-else>
      <router-view />
    </div>
  </div>
</template>
