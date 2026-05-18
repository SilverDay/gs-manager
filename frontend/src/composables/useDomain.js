import { ref } from 'vue'
import { useApi } from './useApi.js'

export function useDomain() {
  const domains         = ref([])
  const domain          = ref(null)
  const assets          = ref([])
  const processes       = ref([])
  const scopedControls  = ref([])
  const controlsMeta    = ref(null)
  const loading         = ref(false)
  const error           = ref(null)

  // ── Domains ──────────────────────────────────────────────────────────────

  async function loadDomains() {
    loading.value = true
    error.value   = null
    const { execute } = useApi('/api/domains')
    const res = await execute()
    if (res?.success) domains.value = res.data.domains ?? []
    else error.value = res?.error ?? 'Fehler beim Laden'
    loading.value = false
  }

  async function createDomain(data) {
    const { execute } = useApi('/api/domains')
    const res = await execute({ method: 'POST', body: data })
    if (res?.success) await loadDomains()
    return res
  }

  async function loadDomain(id) {
    loading.value = true
    error.value   = null
    const { execute } = useApi(`/api/domains/${id}`)
    const res = await execute()
    if (res?.success) domain.value = res.data.domain ?? null
    else error.value = res?.error ?? 'Verbund nicht gefunden'
    loading.value = false
  }

  async function updateDomain(id, data) {
    const { execute } = useApi(`/api/domains/${id}`)
    const res = await execute({ method: 'PUT', body: data })
    if (res?.success) await loadDomain(id)
    return res
  }

  // ── Assets ────────────────────────────────────────────────────────────────

  async function loadAssets(domainId) {
    loading.value = true
    const { execute } = useApi(`/api/domains/${domainId}/assets`)
    const res = await execute()
    if (res?.success) assets.value = res.data.assets ?? []
    else error.value = res?.error ?? 'Fehler beim Laden der Assets'
    loading.value = false
  }

  async function createAsset(domainId, data) {
    const { execute } = useApi(`/api/domains/${domainId}/assets`)
    const res = await execute({ method: 'POST', body: data })
    if (res?.success) await loadAssets(domainId)
    return res
  }

  async function updateAsset(domainId, assetId, data) {
    const { execute } = useApi(`/api/domains/${domainId}/assets/${assetId}`)
    const res = await execute({ method: 'PUT', body: data })
    if (res?.success) await loadAssets(domainId)
    return res
  }

  async function deleteAsset(domainId, assetId) {
    const { execute } = useApi(`/api/domains/${domainId}/assets/${assetId}`)
    const res = await execute({ method: 'DELETE' })
    if (res?.success) await loadAssets(domainId)
    return res
  }

  // ── Processes ─────────────────────────────────────────────────────────────

  async function loadProcesses(domainId) {
    loading.value = true
    const { execute } = useApi(`/api/domains/${domainId}/processes`)
    const res = await execute()
    if (res?.success) processes.value = res.data.processes ?? []
    else error.value = res?.error ?? 'Fehler beim Laden der Prozesse'
    loading.value = false
  }

  async function createProcess(domainId, data) {
    const { execute } = useApi(`/api/domains/${domainId}/processes`)
    const res = await execute({ method: 'POST', body: data })
    if (res?.success) await loadProcesses(domainId)
    return res
  }

  async function updateProcess(domainId, processId, data) {
    const { execute } = useApi(`/api/domains/${domainId}/processes/${processId}`)
    const res = await execute({ method: 'PUT', body: data })
    if (res?.success) await loadProcesses(domainId)
    return res
  }

  async function deleteProcess(domainId, processId) {
    const { execute } = useApi(`/api/domains/${domainId}/processes/${processId}`)
    const res = await execute({ method: 'DELETE' })
    if (res?.success) await loadProcesses(domainId)
    return res
  }

  // ── Scoped Controls ───────────────────────────────────────────────────────

  async function loadScopedControls(domainId, { search = '', page = 1, perPage = 50 } = {}) {
    loading.value = true
    const params = new URLSearchParams({ page, per_page: perPage })
    if (search) params.set('search', search)
    const { execute } = useApi(`/api/domains/${domainId}/scoped-controls?${params}`)
    const res = await execute()
    if (res?.success) {
      scopedControls.value = res.data.items ?? []
      controlsMeta.value   = res.data.meta  ?? null
    } else {
      error.value = res?.error ?? 'Fehler beim Laden der Anforderungen'
    }
    loading.value = false
  }

  async function applyTailoring(domainId, data) {
    const { execute } = useApi(`/api/domains/${domainId}/tailoring`)
    const res = await execute({ method: 'POST', body: data })
    if (res?.success) await loadScopedControls(domainId)
    return res
  }

  // ── Profile ───────────────────────────────────────────────────────────────

  async function generateProfile(domainId) {
    const { execute } = useApi(`/api/domains/${domainId}/generate-profile`)
    return execute({ method: 'POST' })
  }

  return {
    domains, domain, assets, processes, scopedControls, controlsMeta,
    loading, error,
    loadDomains, createDomain, loadDomain, updateDomain,
    loadAssets, createAsset, updateAsset, deleteAsset,
    loadProcesses, createProcess, updateProcess, deleteProcess,
    loadScopedControls, applyTailoring,
    generateProfile,
  }
}
