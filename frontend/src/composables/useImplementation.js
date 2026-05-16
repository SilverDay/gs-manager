import { ref } from 'vue'
import { useApi } from './useApi.js'

export function useImplementation() {
  const implementations = ref([])
  const progress        = ref(null)
  const meta            = ref(null)
  const loading         = ref(false)
  const saving          = ref(false)
  const error           = ref(null)

  let saveTimer = null

  // ── Load ─────────────────────────────────────────────────────────────────

  async function fetchImplementations(domainId, filters = {}) {
    loading.value = true
    error.value   = null

    const params = new URLSearchParams({ page: 1, per_page: 200 })
    if (filters.status)   params.set('status',   filters.status)
    if (filters.asset_id) params.set('asset_id', filters.asset_id)
    if (filters.search)   params.set('search',   filters.search)

    const { execute } = useApi(`/api/domains/${domainId}/implementations?${params}`)
    const res = await execute()

    if (res?.success) {
      implementations.value = res.data.items     ?? []
      progress.value        = res.data.progress  ?? null
      meta.value            = res.data.meta      ?? null
    } else {
      error.value = res?.error ?? 'Fehler beim Laden'
    }

    loading.value = false
    return res
  }

  // ── Update (debounced) ───────────────────────────────────────────────────

  function updateImplementation(implId, fields) {
    // Optimistic local update
    const idx = implementations.value.findIndex(i => i.id === implId)
    if (idx !== -1) {
      implementations.value[idx] = { ...implementations.value[idx], ...fields }
    }

    if (saveTimer) clearTimeout(saveTimer)
    return new Promise((resolve) => {
      saveTimer = setTimeout(async () => {
        saving.value = true
        const { execute } = useApi(`/api/implementations/${implId}`)
        const res = await execute({ method: 'PUT', body: fields })
        saving.value = false
        if (res?.success && res.data?.implementation) {
          const freshIdx = implementations.value.findIndex(i => i.id === implId)
          if (freshIdx !== -1) {
            implementations.value[freshIdx] = res.data.implementation
          }
        }
        resolve(res)
      }, 500)
    })
  }

  // ── Evidence upload ──────────────────────────────────────────────────────

  async function uploadEvidence(implId, file) {
    const token = await getCsrfToken()
    const form  = new FormData()
    form.append('file', file)

    const res = await fetch(`/api/implementations/${implId}/evidence`, {
      method:      'POST',
      credentials: 'include',
      headers:     token ? { 'X-CSRF-Token': token } : {},
      body:        form,
    })
    return res.json()
  }

  // ── SSP export (triggers browser download) ───────────────────────────────

  async function exportSsp(domainId, domainName) {
    const res = await fetch(`/api/domains/${domainId}/ssp/export`, {
      credentials: 'include',
    })
    if (!res.ok) {
      const json = await res.json().catch(() => ({}))
      return { success: false, error: json.error || 'Export fehlgeschlagen' }
    }

    const blob = await res.blob()
    const url  = URL.createObjectURL(blob)
    const a    = document.createElement('a')
    a.href     = url
    a.download = (domainName || 'SSP') + '_SSP-edited.json'
    a.click()
    URL.revokeObjectURL(url)
    return { success: true }
  }

  // ── SSP import ───────────────────────────────────────────────────────────

  async function importSsp(domainId, file) {
    const token = await getCsrfToken()
    const text  = await file.text()

    const res = await fetch(`/api/domains/${domainId}/ssp/import`, {
      method:      'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        ...(token ? { 'X-CSRF-Token': token } : {}),
      },
      body: text,
    })
    return res.json()
  }

  // ── Generate SSP rows ────────────────────────────────────────────────────

  async function generateSsp(domainId) {
    const { execute } = useApi(`/api/domains/${domainId}/generate-ssp`)
    return execute({ method: 'POST' })
  }

  return {
    implementations, progress, meta, loading, saving, error,
    fetchImplementations,
    updateImplementation,
    uploadEvidence,
    exportSsp,
    importSsp,
    generateSsp,
  }
}

// ── helpers ──────────────────────────────────────────────────────────────────

async function getCsrfToken() {
  try {
    const res  = await fetch('/api/auth/csrf-token', { credentials: 'include' })
    const json = await res.json()
    return json.data?.csrf_token ?? null
  } catch {
    return null
  }
}
