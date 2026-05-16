import { ref } from 'vue'
import { useApi } from './useApi.js'

export function useCatalog() {
  const catalogs  = ref([])
  const controls  = ref([])
  const control   = ref(null)
  const meta      = ref(null)
  const loading   = ref(false)
  const error     = ref(null)

  // ── Catalog list ────────────────────────────────────────────────────────

  async function loadCatalogs() {
    loading.value = true
    error.value   = null
    const { execute } = useApi('/api/catalogs')
    const res = await execute()
    if (res?.success) catalogs.value = res.data.catalogs ?? []
    else error.value = res?.error ?? 'Fehler beim Laden der Kataloge'
    loading.value = false
  }

  // ── Import ───────────────────────────────────────────────────────────────

  async function importFromJson(jsonString, name = '') {
    const { execute } = useApi('/api/catalogs/import')
    const res = await execute({
      method: 'POST',
      body: { source: 'json', json: jsonString, name },
    })
    if (res?.success) await loadCatalogs()
    return res
  }

  async function importFromUrl(url, name = '') {
    const { execute } = useApi('/api/catalogs/import')
    const res = await execute({
      method: 'POST',
      body: { source: 'url', url, name },
    })
    if (res?.success) await loadCatalogs()
    return res
  }

  // ── Controls list ────────────────────────────────────────────────────────

  async function loadControls(catalogId, { page = 1, perPage = 25, search = '', groupId = '' } = {}) {
    loading.value = true
    error.value   = null
    controls.value = []

    const params = new URLSearchParams({ page, per_page: perPage })
    if (search)  params.set('search',   search)
    if (groupId) params.set('group_id', groupId)

    const { execute } = useApi(`/api/catalogs/${catalogId}/controls?${params}`)
    const res = await execute()
    if (res?.success) {
      controls.value = res.data.items ?? []
      meta.value     = res.data.meta  ?? null
    } else {
      error.value = res?.error ?? 'Fehler beim Laden der Controls'
    }
    loading.value = false
  }

  // ── Single control ────────────────────────────────────────────────────────

  async function loadControl(catalogId, controlId) {
    loading.value = true
    error.value   = null
    control.value = null

    const { execute } = useApi(`/api/catalogs/${catalogId}/controls/${controlId}`)
    const res = await execute()
    if (res?.success) control.value = res.data.control ?? null
    else error.value = res?.error ?? 'Control nicht gefunden'
    loading.value = false
  }

  // ── Update check ──────────────────────────────────────────────────────────

  async function checkUpdate(catalogId) {
    const { execute } = useApi(`/api/catalogs/${catalogId}/check-update`)
    return execute({ method: 'POST' })
  }

  // ── Catalog library ───────────────────────────────────────────────────────

  const library = ref([])

  async function loadLibrary() {
    const { execute } = useApi('/api/catalogs/library')
    const res = await execute()
    if (res?.success) library.value = res.data.sources ?? []
  }

  return {
    catalogs, controls, control, meta, library,
    loading, error,
    loadCatalogs, importFromJson, importFromUrl,
    loadControls, loadControl, checkUpdate, loadLibrary,
  }
}
