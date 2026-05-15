import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useApi, resetCsrf } from '@/composables/useApi.js'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)

  const isAuthenticated = computed(() => user.value !== null)
  const displayName = computed(() => user.value?.display_name || '')
  const role = computed(() => user.value?.role || '')

  async function login(email, password) {
    const { execute } = useApi('/api/auth/login', { method: 'POST' })
    const result = await execute({ body: { email, password } })

    if (result?.success) {
      user.value = result.data.user
      return { success: true }
    }

    return { success: false, error: result?.error || 'Anmeldung fehlgeschlagen' }
  }

  async function logout() {
    const { execute } = useApi('/api/auth/logout', { method: 'POST' })
    await execute()
    user.value = null
    resetCsrf()
  }

  async function fetchUser() {
    const { execute } = useApi('/api/auth/me')
    const result = await execute()

    if (result?.success) {
      user.value = result.data.user
    } else {
      user.value = null
      throw new Error('Not authenticated')
    }
  }

  return { user, isAuthenticated, displayName, role, login, logout, fetchUser }
})
