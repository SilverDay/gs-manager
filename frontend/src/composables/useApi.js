import { ref } from 'vue'

const BASE_URL = import.meta.env.VITE_API_URL || ''
let csrfToken = null

/**
 * Fetch CSRF token from server
 */
async function ensureCsrfToken() {
  if (csrfToken) return csrfToken

  const res = await fetch(`${BASE_URL}/api/auth/csrf-token`, {
    credentials: 'include',
  })
  const data = await res.json()
  csrfToken = data.data?.csrf_token
  return csrfToken
}

/**
 * Central API fetch wrapper
 *
 * @param {string} path - API path (e.g. '/api/catalogs')
 * @param {object} options - fetch options
 * @returns {{ data, loading, error, execute }}
 */
export function useApi(path, options = {}) {
  const data = ref(null)
  const loading = ref(false)
  const error = ref(null)

  async function execute(overrideOptions = {}) {
    loading.value = true
    error.value = null

    try {
      const method = (overrideOptions.method || options.method || 'GET').toUpperCase()
      const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) }

      // Add CSRF token for state-changing requests
      if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
        const token = await ensureCsrfToken()
        if (token) {
          headers['X-CSRF-Token'] = token
        }
      }

      const fetchOptions = {
        method,
        headers,
        credentials: 'include',
        ...overrideOptions,
      }

      if (overrideOptions.body) {
        fetchOptions.body = typeof overrideOptions.body === 'string'
          ? overrideOptions.body
          : JSON.stringify(overrideOptions.body)
      }

      const res = await fetch(`${BASE_URL}${path}`, fetchOptions)
      const json = await res.json()

      if (!res.ok || !json.success) {
        error.value = json.error || `Fehler ${res.status}`

        // On 401 → soft-navigate to login (lazy imports avoid circular deps)
        if (res.status === 401) {
          const [{ router }, { useAuthStore }] = await Promise.all([
            import('@/router.js'),
            import('@/stores/useAuthStore.js'),
          ])
          useAuthStore().user = null
          router.push('/login')
        }

        // On 403 CSRF → refresh token and hint to retry
        if (res.status === 403 && json.error?.includes('CSRF')) {
          csrfToken = null
        }

        return json
      }

      data.value = json.data
      return json
    } catch (err) {
      error.value = 'Netzwerkfehler. Bitte Verbindung prüfen.'
      return null
    } finally {
      loading.value = false
    }
  }

  return { data, loading, error, execute }
}

/**
 * Reset CSRF token (call on logout)
 */
export function resetCsrf() {
  csrfToken = null
}
