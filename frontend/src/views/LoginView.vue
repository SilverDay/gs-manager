<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/useAuthStore.js'

const auth = useAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

async function handleLogin() {
  error.value = ''
  loading.value = true

  const result = await auth.login(email.value, password.value)

  if (result.success) {
    router.push('/')
  } else {
    error.value = result.error
  }

  loading.value = false
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-100">
    <div class="w-full max-w-md">
      <div class="bg-white rounded-xl shadow-lg p-8">
        <!-- Logo -->
        <div class="text-center mb-8">
          <h1 class="text-2xl font-bold text-gray-900">GS++ Manager</h1>
          <p class="text-sm text-gray-500 mt-1">KMU Compliance Manager für BSI Grundschutz++</p>
        </div>

        <!-- Error -->
        <div v-if="error" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
          {{ error }}
        </div>

        <!-- Form -->
        <div class="space-y-4">
          <div>
            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">E-Mail</label>
            <input
              id="email"
              v-model="email"
              type="email"
              autocomplete="email"
              required
              class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-sm"
              placeholder="name@unternehmen.de"
              @change="email = $event.target.value"
              @keyup.enter="handleLogin"
            />
          </div>

          <div>
            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Passwort</label>
            <input
              id="password"
              v-model="password"
              type="password"
              autocomplete="current-password"
              required
              class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-sm"
              @change="password = $event.target.value"
              @keyup.enter="handleLogin"
            />
          </div>

          <button
            @click="handleLogin"
            :disabled="loading || !email || !password"
            class="w-full py-2.5 px-4 bg-primary-600 text-white font-medium rounded-lg hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors text-sm"
          >
            <span v-if="loading">Anmelden …</span>
            <span v-else>Anmelden</span>
          </button>

          <div class="text-center">
            <router-link to="/passwort-zuruecksetzen" class="text-sm text-primary-600 hover:underline">
              Passwort vergessen?
            </router-link>
          </div>
        </div>
      </div>

      <p class="text-center text-xs text-gray-400 mt-6">
        SilverDay Media · Grundschutz++ KMU Compliance Manager
      </p>
    </div>
  </div>
</template>
