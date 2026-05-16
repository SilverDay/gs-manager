<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useApiClient } from '@/composables/useApi.js'

const api = useApiClient()
const route = useRoute()
const router = useRouter()

// Detect mode from URL: ?token=... → confirm mode, otherwise request mode
const token = computed(() => route.query.token || '')
const isConfirmMode = computed(() => token.value !== '')

// Request form
const requestEmail = ref('')
const requestMsg = ref('')
const requestErr = ref('')
const requestLoading = ref(false)
const requestDone = ref(false)

// Confirm form
const newPassword = ref('')
const newPasswordConfirm = ref('')
const confirmMsg = ref('')
const confirmErr = ref('')
const confirmLoading = ref(false)
const confirmDone = ref(false)

async function requestReset() {
  requestMsg.value = ''
  requestErr.value = ''
  requestLoading.value = true
  const res = await api.post('/api/auth/password-reset/request', { email: requestEmail.value })
  // Always show the generic message (no enumeration)
  requestMsg.value = res?.data?.message ?? res?.message ?? 'Anfrage gesendet.'
  requestDone.value = true
  requestLoading.value = false
}

async function confirmReset() {
  confirmMsg.value = ''
  confirmErr.value = ''

  if (newPassword.value !== newPasswordConfirm.value) {
    confirmErr.value = 'Passwörter stimmen nicht überein.'
    return
  }
  if (newPassword.value.length < 12) {
    confirmErr.value = 'Das Passwort muss mindestens 12 Zeichen lang sein.'
    return
  }

  confirmLoading.value = true
  const res = await api.post('/api/auth/password-reset/confirm', {
    token: token.value,
    new_password: newPassword.value,
    new_password_confirm: newPasswordConfirm.value,
  })
  if (res?.success) {
    confirmMsg.value = res.data.message
    confirmDone.value = true
  } else {
    confirmErr.value = res?.error ?? 'Unbekannter Fehler.'
  }
  confirmLoading.value = false
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-100">
    <div class="w-full max-w-md">
      <div class="bg-white rounded-xl shadow-lg p-8">
        <div class="text-center mb-6">
          <h1 class="text-xl font-bold text-gray-900">Passwort zurücksetzen</h1>
          <p class="text-sm text-gray-500 mt-1">GS++ Manager</p>
        </div>

        <!-- Request mode -->
        <div v-if="!isConfirmMode">
          <div v-if="requestDone" class="p-4 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700 text-center">
            {{ requestMsg }}
          </div>
          <div v-else>
            <div v-if="requestErr" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">{{ requestErr }}</div>
            <p class="text-sm text-gray-600 mb-4">
              Geben Sie Ihre E-Mail-Adresse ein. Falls ein Konto existiert, erhalten Sie einen Rücksetz-Link.
            </p>
            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">E-Mail-Adresse</label>
                <input v-model="requestEmail" type="email" @keyup.enter="requestReset"
                  class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500"
                  placeholder="name@unternehmen.de" />
              </div>
              <button @click="requestReset" :disabled="requestLoading || !requestEmail"
                class="w-full py-2.5 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 disabled:opacity-50">
                {{ requestLoading ? 'Senden …' : 'Rücksetz-Link anfordern' }}
              </button>
            </div>
          </div>
        </div>

        <!-- Confirm mode -->
        <div v-else>
          <div v-if="confirmDone" class="text-center">
            <div class="p-4 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700 mb-4">
              {{ confirmMsg }}
            </div>
            <button @click="router.push('/login')"
              class="px-4 py-2 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700">
              Zur Anmeldung
            </button>
          </div>
          <div v-else>
            <div v-if="confirmErr" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">{{ confirmErr }}</div>
            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Neues Passwort <span class="text-gray-400">(min. 12 Zeichen)</span></label>
                <input v-model="newPassword" type="password"
                  class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Passwort bestätigen</label>
                <input v-model="newPasswordConfirm" type="password" @keyup.enter="confirmReset"
                  class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500" />
              </div>
              <button @click="confirmReset" :disabled="confirmLoading || !newPassword || !newPasswordConfirm"
                class="w-full py-2.5 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 disabled:opacity-50">
                {{ confirmLoading ? 'Speichern …' : 'Neues Passwort setzen' }}
              </button>
            </div>
          </div>
        </div>

        <div class="mt-6 text-center">
          <router-link to="/login" class="text-sm text-primary-600 hover:underline">Zurück zur Anmeldung</router-link>
        </div>
      </div>
    </div>
  </div>
</template>
