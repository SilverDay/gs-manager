<script setup>
import { ref, onMounted } from 'vue'
import { useApiClient } from '@/composables/useApi.js'

const api = useApiClient()

const activeTab = ref('users')

// Users list
const users = ref([])
const usersLoading = ref(false)

// Create user form
const newUser = ref({ email: '', display_name: '', role: 'readonly', password: '' })
const createMsg = ref('')
const createErr = ref('')
const createLoading = ref(false)
const showCreateForm = ref(false)

// Edit user modal
const editUser = ref(null)
const editMsg = ref('')
const editErr = ref('')
const editLoading = ref(false)

// Reset password
const resetUserId = ref(null)
const resetPassword = ref('')
const resetMsg = ref('')
const resetErr = ref('')
const resetLoading = ref(false)

// Settings
const settings = ref({})
const settingsMsg = ref('')
const settingsErr = ref('')
const settingsLoading = ref(false)
const smtpTestTo = ref('')
const smtpTestMsg = ref('')
const smtpTestErr = ref('')
const smtpTestLoading = ref(false)

const roles = ['admin', 'isb', 'fachverantwortlich', 'auditor', 'management', 'readonly']
const roleLabels = {
  admin: 'Administrator',
  isb: 'ISB',
  fachverantwortlich: 'Fachverantwortlich',
  auditor: 'Auditor',
  management: 'Geschäftsleitung',
  readonly: 'Lesezugriff',
}

async function loadUsers() {
  usersLoading.value = true
  const res = await api.get('/api/admin/users')
  if (res.success) users.value = res.data.users
  usersLoading.value = false
}

async function createUser() {
  createMsg.value = ''
  createErr.value = ''
  createLoading.value = true
  const res = await api.post('/api/admin/users', newUser.value)
  if (res.success) {
    createMsg.value = res.data.message
    newUser.value = { email: '', display_name: '', role: 'readonly', password: '' }
    showCreateForm.value = false
    await loadUsers()
  } else {
    createErr.value = res.error
  }
  createLoading.value = false
}

function openEdit(user) {
  editUser.value = { ...user }
  editMsg.value = ''
  editErr.value = ''
  resetUserId.value = null
  resetPassword.value = ''
  resetMsg.value = ''
  resetErr.value = ''
}

async function saveUser() {
  editMsg.value = ''
  editErr.value = ''
  editLoading.value = true
  const res = await api.put(`/api/admin/users/${editUser.value.id}`, {
    display_name: editUser.value.display_name,
    role: editUser.value.role,
    is_active: editUser.value.is_active,
  })
  if (res.success) {
    editMsg.value = res.data.message
    await loadUsers()
  } else {
    editErr.value = res.error
  }
  editLoading.value = false
}

async function resetUserPassword() {
  resetMsg.value = ''
  resetErr.value = ''
  if (!resetPassword.value) { resetErr.value = 'Passwort eingeben.'; return }
  resetLoading.value = true
  const res = await api.post(`/api/admin/users/${editUser.value.id}/reset-password`, {
    new_password: resetPassword.value,
  })
  if (res.success) {
    resetMsg.value = res.data.message
    resetPassword.value = ''
  } else {
    resetErr.value = res.error
  }
  resetLoading.value = false
}

async function loadSettings() {
  const res = await api.get('/api/admin/settings')
  if (res.success) settings.value = { ...res.data.settings }
}

async function saveSettings() {
  settingsMsg.value = ''
  settingsErr.value = ''
  settingsLoading.value = true
  const res = await api.put('/api/admin/settings', settings.value)
  if (res.success) {
    settingsMsg.value = res.data.message
    await loadSettings()
  } else {
    settingsErr.value = res.error
  }
  settingsLoading.value = false
}

async function testSmtp() {
  smtpTestMsg.value = ''
  smtpTestErr.value = ''
  smtpTestLoading.value = true
  const res = await api.post('/api/admin/settings/smtp/test', { to: smtpTestTo.value })
  if (res.success) smtpTestMsg.value = res.data.message
  else smtpTestErr.value = res.error
  smtpTestLoading.value = false
}

onMounted(() => {
  loadUsers()
  loadSettings()
})
</script>

<template>
  <div class="p-6 max-w-4xl">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Administration</h1>

    <!-- Tabs -->
    <div class="flex border-b border-gray-200 mb-6">
      <button v-for="tab in [{ id: 'users', label: 'Benutzer' }, { id: 'settings', label: 'Einstellungen' }]"
        :key="tab.id" @click="activeTab = tab.id"
        :class="activeTab === tab.id ? 'border-b-2 border-primary-600 text-primary-600' : 'text-gray-500 hover:text-gray-700'"
        class="px-4 py-2 text-sm font-medium -mb-px">
        {{ tab.label }}
      </button>
    </div>

    <!-- Users Tab -->
    <div v-if="activeTab === 'users'">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-base font-semibold text-gray-800">Benutzerliste</h2>
        <button @click="showCreateForm = !showCreateForm"
          class="px-3 py-1.5 bg-primary-600 text-white text-sm rounded-lg hover:bg-primary-700">
          + Benutzer anlegen
        </button>
      </div>

      <!-- Create User Form -->
      <div v-if="showCreateForm" class="mb-6 p-4 border border-primary-200 bg-primary-50 rounded-lg">
        <h3 class="text-sm font-semibold text-gray-800 mb-3">Neuer Benutzer</h3>
        <div v-if="createMsg" class="mb-3 p-2 bg-green-100 text-green-700 rounded text-sm">{{ createMsg }}</div>
        <div v-if="createErr" class="mb-3 p-2 bg-red-100 text-red-700 rounded text-sm">{{ createErr }}</div>
        <div class="grid grid-cols-2 gap-3 mb-3">
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">E-Mail</label>
            <input v-model="newUser.email" type="email" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm focus:ring-1 focus:ring-primary-500" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Anzeigename</label>
            <input v-model="newUser.display_name" type="text" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm focus:ring-1 focus:ring-primary-500" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Rolle</label>
            <select v-model="newUser.role" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm focus:ring-1 focus:ring-primary-500">
              <option v-for="r in roles" :key="r" :value="r">{{ roleLabels[r] }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Passwort (min. 12 Zeichen)</label>
            <input v-model="newUser.password" type="password" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm focus:ring-1 focus:ring-primary-500" />
          </div>
        </div>
        <div class="flex gap-2">
          <button @click="createUser" :disabled="createLoading || !newUser.email || !newUser.password"
            class="px-3 py-1.5 bg-primary-600 text-white text-sm rounded hover:bg-primary-700 disabled:opacity-50">
            {{ createLoading ? 'Anlegen …' : 'Anlegen' }}
          </button>
          <button @click="showCreateForm = false" class="px-3 py-1.5 text-gray-600 text-sm rounded hover:bg-gray-100">
            Abbrechen
          </button>
        </div>
      </div>

      <!-- User Table -->
      <div v-if="usersLoading" class="text-sm text-gray-500">Lädt …</div>
      <table v-else class="w-full text-sm">
        <thead>
          <tr class="border-b border-gray-200 text-xs text-gray-500 uppercase">
            <th class="text-left pb-2 font-medium">Name</th>
            <th class="text-left pb-2 font-medium">E-Mail</th>
            <th class="text-left pb-2 font-medium">Rolle</th>
            <th class="text-left pb-2 font-medium">Status</th>
            <th class="text-left pb-2 font-medium">Letzter Login</th>
            <th class="pb-2"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in users" :key="u.id" class="border-b border-gray-100 hover:bg-gray-50">
            <td class="py-2.5 font-medium text-gray-900">{{ u.display_name }}</td>
            <td class="py-2.5 text-gray-600">{{ u.email }}</td>
            <td class="py-2.5">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                {{ roleLabels[u.role] ?? u.role }}
              </span>
            </td>
            <td class="py-2.5">
              <span :class="u.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                class="px-2 py-0.5 rounded-full text-xs font-medium">
                {{ u.is_active ? 'Aktiv' : 'Inaktiv' }}
              </span>
            </td>
            <td class="py-2.5 text-gray-500 text-xs">{{ u.last_login_at ?? '—' }}</td>
            <td class="py-2.5">
              <button @click="openEdit(u)" class="text-primary-600 hover:underline text-xs">Bearbeiten</button>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Edit User Panel -->
      <div v-if="editUser" class="mt-6 p-4 border border-gray-200 rounded-lg bg-gray-50">
        <h3 class="text-sm font-semibold text-gray-800 mb-3">Benutzer bearbeiten: {{ editUser.email }}</h3>
        <div v-if="editMsg" class="mb-3 p-2 bg-green-100 text-green-700 rounded text-sm">{{ editMsg }}</div>
        <div v-if="editErr" class="mb-3 p-2 bg-red-100 text-red-700 rounded text-sm">{{ editErr }}</div>
        <div class="grid grid-cols-2 gap-3 mb-3">
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Anzeigename</label>
            <input v-model="editUser.display_name" type="text" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Rolle</label>
            <select v-model="editUser.role" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm">
              <option v-for="r in roles" :key="r" :value="r">{{ roleLabels[r] }}</option>
            </select>
          </div>
          <div class="flex items-center gap-2 col-span-2">
            <input type="checkbox" :id="`active-${editUser.id}`" v-model="editUser.is_active" class="rounded" />
            <label :for="`active-${editUser.id}`" class="text-sm text-gray-700">Konto aktiv</label>
          </div>
        </div>
        <div class="flex gap-2 mb-4">
          <button @click="saveUser" :disabled="editLoading"
            class="px-3 py-1.5 bg-primary-600 text-white text-sm rounded hover:bg-primary-700 disabled:opacity-50">
            {{ editLoading ? 'Speichern …' : 'Speichern' }}
          </button>
          <button @click="editUser = null" class="px-3 py-1.5 text-gray-600 text-sm rounded hover:bg-gray-200">
            Schließen
          </button>
        </div>

        <!-- Password reset section -->
        <div class="border-t border-gray-200 pt-4">
          <h4 class="text-xs font-semibold text-gray-600 mb-2">Passwort zurücksetzen</h4>
          <div v-if="resetMsg" class="mb-2 p-2 bg-green-100 text-green-700 rounded text-xs">{{ resetMsg }}</div>
          <div v-if="resetErr" class="mb-2 p-2 bg-red-100 text-red-700 rounded text-xs">{{ resetErr }}</div>
          <div class="flex gap-2">
            <input v-model="resetPassword" type="password" placeholder="Neues Passwort (min. 12 Zeichen)"
              class="flex-1 px-2.5 py-1.5 border border-gray-300 rounded text-sm" />
            <button @click="resetUserPassword" :disabled="resetLoading || !resetPassword"
              class="px-3 py-1.5 bg-orange-500 text-white text-sm rounded hover:bg-orange-600 disabled:opacity-50">
              {{ resetLoading ? '…' : 'Setzen' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Settings Tab -->
    <div v-if="activeTab === 'settings'">
      <div v-if="settingsMsg" class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm">{{ settingsMsg }}</div>
      <div v-if="settingsErr" class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">{{ settingsErr }}</div>

      <div class="space-y-6">
        <!-- General -->
        <div>
          <h3 class="text-sm font-semibold text-gray-700 mb-3">Allgemein</h3>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Sprache</label>
              <select v-model="settings.language" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm">
                <option value="de">Deutsch</option>
                <option value="en">English</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Zeitzone</label>
              <input v-model="settings.timezone" type="text" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm" placeholder="Europe/Berlin" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Session-Timeout (Minuten)</label>
              <input v-model="settings.session_timeout" type="number" min="5" max="1440" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm" placeholder="30" />
            </div>
          </div>
        </div>

        <!-- SMTP -->
        <div>
          <h3 class="text-sm font-semibold text-gray-700 mb-3">SMTP-Konfiguration</h3>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">SMTP-Server</label>
              <input v-model="settings.smtp_host" type="text" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm" placeholder="smtp.beispiel.de" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Port</label>
              <input v-model="settings.smtp_port" type="number" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm" placeholder="587" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Verschlüsselung</label>
              <select v-model="settings.smtp_encryption" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm">
                <option value="starttls">STARTTLS (Port 587)</option>
                <option value="ssl">SSL/TLS (Port 465)</option>
                <option value="none">Keine</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Benutzername</label>
              <input v-model="settings.smtp_user" type="text" autocomplete="off" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Passwort</label>
              <input v-model="settings.smtp_pass" type="password" autocomplete="new-password" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm" placeholder="Leer lassen, um beizubehalten" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Absender-E-Mail</label>
              <input v-model="settings.smtp_from" type="email" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Absendername</label>
              <input v-model="settings.smtp_from_name" type="text" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm" placeholder="GS++ Manager" />
            </div>
          </div>

          <!-- Test email -->
          <div class="mt-4 p-3 bg-gray-50 border border-gray-200 rounded-lg">
            <h4 class="text-xs font-semibold text-gray-600 mb-2">Test-E-Mail senden</h4>
            <div v-if="smtpTestMsg" class="mb-2 p-2 bg-green-100 text-green-700 rounded text-xs">{{ smtpTestMsg }}</div>
            <div v-if="smtpTestErr" class="mb-2 p-2 bg-red-100 text-red-700 rounded text-xs">{{ smtpTestErr }}</div>
            <div class="flex gap-2">
              <input v-model="smtpTestTo" type="email" placeholder="empfaenger@beispiel.de"
                class="flex-1 px-2.5 py-1.5 border border-gray-300 rounded text-sm" />
              <button @click="testSmtp" :disabled="smtpTestLoading || !smtpTestTo"
                class="px-3 py-1.5 bg-gray-700 text-white text-sm rounded hover:bg-gray-800 disabled:opacity-50">
                {{ smtpTestLoading ? 'Senden …' : 'Testmail senden' }}
              </button>
            </div>
          </div>
        </div>

        <!-- ── KI-Assistent ────────────────────────────────────── -->
        <div class="bg-white rounded-xl border border-gray-200 p-5">
          <h3 class="text-sm font-semibold text-gray-700 mb-4">KI-Assistent</h3>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs text-gray-500 mb-1">KI-Anbieter</label>
              <select v-model="settings.ai_provider" class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm">
                <option value="">Kein KI-Anbieter</option>
                <option value="claude">Claude (Anthropic)</option>
                <option value="gemini">Google Gemini</option>
              </select>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">API-Schlüssel</label>
              <input
                v-model="settings.ai_api_key"
                type="password"
                autocomplete="new-password"
                class="w-full px-2.5 py-2 border border-gray-300 rounded text-sm"
                placeholder="Leer lassen, um beizubehalten"
              />
            </div>
          </div>
          <p class="text-xs text-gray-400 mt-2">
            Der API-Schlüssel wird verschlüsselt gespeichert und nie im Klartext übertragen.
          </p>
        </div>

        <button @click="saveSettings" :disabled="settingsLoading"
          class="px-4 py-2 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 disabled:opacity-50">
          {{ settingsLoading ? 'Speichern …' : 'Einstellungen speichern' }}
        </button>
      </div>
    </div>
  </div>
</template>
