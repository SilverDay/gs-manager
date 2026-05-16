<script setup>
import { ref, onMounted } from 'vue'
import { useApiClient } from '@/composables/useApi.js'

const api = useApiClient()

const profile = ref(null)
const activeTab = ref('profile')

// Profile form
const displayName = ref('')
const email = ref('')
const profilePassword = ref('')
const profileMsg = ref('')
const profileErr = ref('')
const profileLoading = ref(false)

// Password form
const currentPassword = ref('')
const newPassword = ref('')
const newPasswordConfirm = ref('')
const passwordMsg = ref('')
const passwordErr = ref('')
const passwordLoading = ref(false)

// TOTP
const totpSecret = ref('')
const totpUri = ref('')
const totpCode = ref('')
const totpMsg = ref('')
const totpErr = ref('')
const totpLoading = ref(false)
const deleteTotpPassword = ref('')

// Sessions
const sessions = ref([])

async function loadProfile() {
  const res = await api.get('/api/profile')
  if (res.success) {
    profile.value = res.data
    displayName.value = res.data.display_name
    email.value = res.data.email
  }
}

async function loadSessions() {
  const res = await api.get('/api/profile/sessions')
  if (res.success) sessions.value = res.data.sessions
}

async function saveProfile() {
  profileMsg.value = ''
  profileErr.value = ''
  profileLoading.value = true
  const body = { display_name: displayName.value, email: email.value }
  if (email.value !== profile.value?.email) body.password = profilePassword.value
  const res = await api.put('/api/profile', body)
  if (res.success) {
    profileMsg.value = res.data.message
    profilePassword.value = ''
    await loadProfile()
  } else {
    profileErr.value = res.error
  }
  profileLoading.value = false
}

async function changePassword() {
  passwordMsg.value = ''
  passwordErr.value = ''
  passwordLoading.value = true
  const res = await api.post('/api/profile/change-password', {
    current_password: currentPassword.value,
    new_password: newPassword.value,
    new_password_confirm: newPasswordConfirm.value,
  })
  if (res.success) {
    passwordMsg.value = res.data.message
    currentPassword.value = ''
    newPassword.value = ''
    newPasswordConfirm.value = ''
  } else {
    passwordErr.value = res.error
  }
  passwordLoading.value = false
}

async function startTotpSetup() {
  totpErr.value = ''
  totpLoading.value = true
  const res = await api.post('/api/profile/totp/setup', {})
  if (res.success) {
    totpSecret.value = res.data.secret
    totpUri.value = res.data.otpauth_uri
  } else {
    totpErr.value = res.error
  }
  totpLoading.value = false
}

async function confirmTotp() {
  totpMsg.value = ''
  totpErr.value = ''
  totpLoading.value = true
  const res = await api.post('/api/profile/totp/confirm', { code: totpCode.value })
  if (res.success) {
    totpMsg.value = res.data.message
    totpSecret.value = ''
    totpUri.value = ''
    totpCode.value = ''
    await loadProfile()
  } else {
    totpErr.value = res.error
  }
  totpLoading.value = false
}

async function disableTotp() {
  totpMsg.value = ''
  totpErr.value = ''
  if (!deleteTotpPassword.value) { totpErr.value = 'Passwort erforderlich.'; return }
  totpLoading.value = true
  const res = await api.delete('/api/profile/totp', { password: deleteTotpPassword.value })
  if (res.success) {
    totpMsg.value = res.data.message
    deleteTotpPassword.value = ''
    await loadProfile()
  } else {
    totpErr.value = res.error
  }
  totpLoading.value = false
}

onMounted(() => {
  loadProfile()
  loadSessions()
})
</script>

<template>
  <div class="p-6 max-w-2xl">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Mein Profil</h1>

    <!-- Tabs -->
    <div class="flex border-b border-gray-200 mb-6">
      <button v-for="tab in [
        { id: 'profile', label: 'Profil' },
        { id: 'password', label: 'Passwort' },
        { id: 'totp', label: 'Zwei-Faktor' },
        { id: 'sessions', label: 'Sitzungen' },
      ]" :key="tab.id"
        @click="activeTab = tab.id"
        :class="activeTab === tab.id
          ? 'border-b-2 border-primary-600 text-primary-600'
          : 'text-gray-500 hover:text-gray-700'"
        class="px-4 py-2 text-sm font-medium -mb-px"
      >{{ tab.label }}</button>
    </div>

    <!-- Profile Tab -->
    <div v-if="activeTab === 'profile'">
      <div v-if="profileMsg" class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm">{{ profileMsg }}</div>
      <div v-if="profileErr" class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">{{ profileErr }}</div>

      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Anzeigename</label>
          <input v-model="displayName" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">E-Mail-Adresse</label>
          <input v-model="email" type="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500" />
        </div>
        <div v-if="email !== profile?.email">
          <label class="block text-sm font-medium text-gray-700 mb-1">Aktuelles Passwort (für E-Mail-Änderung erforderlich)</label>
          <input v-model="profilePassword" type="password" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500" />
        </div>
        <button @click="saveProfile" :disabled="profileLoading"
          class="px-4 py-2 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 disabled:opacity-50">
          {{ profileLoading ? 'Speichern …' : 'Änderungen speichern' }}
        </button>
      </div>

      <div v-if="profile" class="mt-6 p-4 bg-gray-50 rounded-lg text-sm text-gray-600 space-y-1">
        <div>Rolle: <span class="font-medium">{{ profile.role }}</span></div>
        <div>Letzter Login: <span class="font-medium">{{ profile.last_login_at ?? 'Noch nie' }}</span></div>
        <div>Mitglied seit: <span class="font-medium">{{ profile.created_at?.split('T')[0] }}</span></div>
      </div>
    </div>

    <!-- Password Tab -->
    <div v-if="activeTab === 'password'">
      <div v-if="passwordMsg" class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm">{{ passwordMsg }}</div>
      <div v-if="passwordErr" class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">{{ passwordErr }}</div>

      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Aktuelles Passwort</label>
          <input v-model="currentPassword" type="password" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Neues Passwort <span class="text-gray-400">(min. 12 Zeichen)</span></label>
          <input v-model="newPassword" type="password" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Neues Passwort bestätigen</label>
          <input v-model="newPasswordConfirm" type="password" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500" />
        </div>
        <button @click="changePassword" :disabled="passwordLoading || !currentPassword || !newPassword || !newPasswordConfirm"
          class="px-4 py-2 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 disabled:opacity-50">
          {{ passwordLoading ? 'Ändern …' : 'Passwort ändern' }}
        </button>
      </div>
    </div>

    <!-- TOTP Tab -->
    <div v-if="activeTab === 'totp'">
      <div v-if="totpMsg" class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm">{{ totpMsg }}</div>
      <div v-if="totpErr" class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">{{ totpErr }}</div>

      <!-- TOTP not yet enabled -->
      <div v-if="!profile?.totp_enabled">
        <p class="text-sm text-gray-600 mb-4">
          Die Zwei-Faktor-Authentifizierung fügt eine zusätzliche Sicherheitsebene zu Ihrem Konto hinzu.
          Sie benötigen eine Authenticator-App (z.B. Google Authenticator, Authy oder Bitwarden).
        </p>

        <!-- Setup phase: show secret + URI -->
        <div v-if="totpUri">
          <p class="text-sm font-medium text-gray-700 mb-2">Scannen Sie diesen Code mit Ihrer Authenticator-App:</p>
          <div class="p-3 bg-gray-50 border border-gray-200 rounded-lg font-mono text-xs break-all mb-4">
            {{ totpUri }}
          </div>
          <p class="text-sm text-gray-600 mb-2">Oder geben Sie diesen Schlüssel manuell ein:</p>
          <div class="p-3 bg-gray-100 rounded-lg font-mono text-sm tracking-widest mb-4">{{ totpSecret }}</div>

          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Bestätigungscode aus der App</label>
              <input v-model="totpCode" type="text" inputmode="numeric" maxlength="6" placeholder="123456"
                class="w-40 px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono tracking-widest focus:ring-2 focus:ring-primary-500" />
            </div>
            <button @click="confirmTotp" :disabled="totpLoading || totpCode.length !== 6"
              class="px-4 py-2 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 disabled:opacity-50">
              {{ totpLoading ? 'Bestätigen …' : 'TOTP aktivieren' }}
            </button>
          </div>
        </div>

        <button v-else @click="startTotpSetup" :disabled="totpLoading"
          class="px-4 py-2 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 disabled:opacity-50">
          {{ totpLoading ? 'Einrichten …' : 'Zwei-Faktor-Authentifizierung einrichten' }}
        </button>
      </div>

      <!-- TOTP is enabled -->
      <div v-else>
        <div class="flex items-center gap-2 mb-4">
          <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
          <span class="text-sm font-medium text-gray-700">Zwei-Faktor-Authentifizierung ist aktiv.</span>
        </div>
        <p class="text-sm text-gray-600 mb-4">Um TOTP zu deaktivieren, bestätigen Sie mit Ihrem Passwort.</p>
        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Aktuelles Passwort</label>
            <input v-model="deleteTotpPassword" type="password"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500" />
          </div>
          <button @click="disableTotp" :disabled="totpLoading || !deleteTotpPassword"
            class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 disabled:opacity-50">
            {{ totpLoading ? 'Deaktivieren …' : 'TOTP deaktivieren' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Sessions Tab -->
    <div v-if="activeTab === 'sessions'">
      <p class="text-sm text-gray-600 mb-4">Aktive Sitzungen für Ihr Konto:</p>
      <div v-for="s in sessions" :key="s.id" class="p-4 border border-gray-200 rounded-lg mb-3">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-sm font-medium text-gray-900">{{ s.ip_address }}</div>
            <div class="text-xs text-gray-500 mt-0.5">{{ s.user_agent }}</div>
            <div class="text-xs text-gray-400 mt-0.5">Letzte Aktivität: {{ s.last_activity }}</div>
          </div>
          <span v-if="s.current" class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium">
            Diese Sitzung
          </span>
        </div>
      </div>
    </div>
  </div>
</template>
