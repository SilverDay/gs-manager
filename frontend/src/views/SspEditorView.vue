<script setup>
import { ref, computed, watch, onMounted } from 'vue'

function resolveParams(text, parametersJson) {
  if (!text) return text
  const values = JSON.parse(parametersJson || '{}')
  return text.replace(/\{\{\s*insert:\s*param,\s*([\w.\-]+)\s*\}\}/g, (_, id) => {
    return values[id] ?? `[${id}]`
  })
}
import { useRoute } from 'vue-router'
import { useImplementation } from '@/composables/useImplementation.js'
import { useDomain } from '@/composables/useDomain.js'
import { useApi } from '@/composables/useApi.js'

const route    = useRoute()
const domainId = computed(() => Number(route.params.id))

const {
  implementations, progress, meta, loading, saving, error,
  fetchImplementations, updateImplementation, uploadEvidence,
  exportSsp, importSsp,
} = useImplementation()

const { domain, assets, loadDomain, loadAssets } = useDomain()

const users = ref([])

// Filters
const filterStatus = ref('')
const filterAsset  = ref('')
const filterSearch = ref('')

// Selection + form
const selected  = ref(null)
const form      = ref({})
const savedAt   = ref(null)
const saveError = ref(null)

// Import/export
const importing   = ref(false)
const importError = ref(null)
const exporting   = ref(false)

// Evidence
const uploadingEvidence = ref(false)
const evidenceError     = ref(null)

// ── Data loading ──────────────────────────────────────────────────────────

async function loadAll() {
  await Promise.all([
    loadDomain(domainId.value),
    loadAssets(domainId.value),
    loadUsers(),
    fetchImplementations(domainId.value, activeFilters.value),
  ])
}

async function loadUsers() {
  const { execute } = useApi('/api/admin/users')
  const res = await execute()
  if (res?.success) users.value = res.data.users ?? []
}

onMounted(loadAll)
watch(domainId, loadAll)

// ── Filtering ─────────────────────────────────────────────────────────────

const activeFilters = computed(() => ({
  status:   filterStatus.value,
  asset_id: filterAsset.value,
  search:   filterSearch.value,
}))

let filterTimer = null
function triggerFilter() {
  if (filterTimer) clearTimeout(filterTimer)
  filterTimer = setTimeout(() => {
    selected.value = null
    fetchImplementations(domainId.value, activeFilters.value)
  }, 300)
}

watch([filterStatus, filterAsset, filterSearch], triggerFilter)

// ── Control selection ─────────────────────────────────────────────────────

function selectControl(impl) {
  selected.value = impl
  form.value = {
    status:              impl.status              ?? 'not_started',
    maturity_level:      Number(impl.maturity_level ?? 0),
    description:         impl.description         ?? '',
    asset_ids:           impl.asset_ids           ? [...impl.asset_ids] : [],
    responsible_user_id: impl.responsible_user_id  ?? '',
    target_date:         impl.target_date          ?? '',
    completion_date:     impl.completion_date      ?? '',
  }
  savedAt.value   = null
  saveError.value = null
  evidenceError.value = null
}

// ── Auto-save ─────────────────────────────────────────────────────────────

async function saveField() {
  if (!selected.value) return
  saveError.value = null
  const res = await updateImplementation(selected.value.id, { ...form.value })
  if (res?.success) {
    savedAt.value = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
    await fetchImplementations(domainId.value, activeFilters.value)
    const fresh = implementations.value.find(i => i.id === selected.value.id)
    if (fresh) { selected.value = fresh }
  } else {
    saveError.value = res?.error ?? 'Speichern fehlgeschlagen'
  }
}

// ── Evidence ──────────────────────────────────────────────────────────────

const evidenceFileIds = computed(() => {
  if (!selected.value) return []
  try { return JSON.parse(selected.value.evidence_json ?? '[]') } catch { return [] }
})

async function handleEvidenceUpload(event) {
  const file = event.target.files?.[0]
  if (!file || !selected.value) return
  uploadingEvidence.value = true
  evidenceError.value     = null
  const res = await uploadEvidence(selected.value.id, file)
  uploadingEvidence.value = false
  if (res?.success) {
    await fetchImplementations(domainId.value, activeFilters.value)
    const fresh = implementations.value.find(i => i.id === selected.value.id)
    if (fresh) { selected.value = fresh }
  } else {
    evidenceError.value = res?.error ?? 'Upload fehlgeschlagen'
  }
  event.target.value = ''
}

// ── Export / Import ───────────────────────────────────────────────────────

async function handleExport() {
  exporting.value = true
  await exportSsp(domainId.value, domain.value?.name ?? 'SSP')
  exporting.value = false
}

async function handleImport(event) {
  const file = event.target.files?.[0]
  if (!file) return
  importing.value   = true
  importError.value = null
  const res = await importSsp(domainId.value, file)
  importing.value = false
  if (res?.success) {
    await fetchImplementations(domainId.value, activeFilters.value)
  } else {
    importError.value = res?.error ?? 'Import fehlgeschlagen'
  }
  event.target.value = ''
}

// ── Status display helpers ────────────────────────────────────────────────

const STATUS_LABELS = {
  not_started:    'Nicht begonnen',
  planned:        'Geplant',
  partial:        'Teilweise',
  implemented:    'Umgesetzt',
  not_applicable: 'Entfällt',
}

const STATUS_CLASSES = {
  not_started:    'bg-gray-100 text-gray-600',
  planned:        'bg-blue-100 text-blue-700',
  partial:        'bg-yellow-100 text-yellow-700',
  implemented:    'bg-green-100 text-green-700',
  not_applicable: 'bg-gray-100 text-gray-400',
}

const STATUS_SELECT_CLASSES = {
  not_started:    'border-gray-300',
  planned:        'border-blue-300',
  partial:        'border-yellow-300',
  implemented:    'border-green-400',
  not_applicable: 'border-gray-200',
}

const progressPercent = computed(() => {
  if (!progress.value?.total) return 0
  return Math.round(((progress.value.implemented + progress.value.partial) / progress.value.total) * 100)
})

function userInitials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
}
</script>

<template>
  <div class="flex flex-col h-full">

    <!-- Header -->
    <div class="flex items-center justify-between mb-5">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Grundschutzcheck</h1>
        <p v-if="domain" class="text-sm text-gray-500 mt-0.5">{{ domain.name }}</p>
      </div>
      <div class="flex items-center gap-3 flex-wrap">
        <!-- Import -->
        <label class="cursor-pointer">
          <input type="file" accept=".json" class="hidden" @change="handleImport" />
          <span
            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium border border-gray-300 rounded-lg bg-white hover:bg-gray-50 transition-colors"
            :class="importing ? 'opacity-60 pointer-events-none' : ''"
          >
            <svg v-if="importing" class="w-4 h-4 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            SSP importieren
          </span>
        </label>
        <p v-if="importError" class="text-xs text-red-600">{{ importError }}</p>

        <!-- Export -->
        <button
          @click="handleExport"
          :disabled="exporting"
          class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium bg-primary-600 text-white rounded-lg hover:bg-primary-700 disabled:opacity-50 transition-colors"
        >
          <svg v-if="exporting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
          </svg>
          <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
          </svg>
          SSP exportieren
        </button>
      </div>
    </div>

    <!-- Progress bar -->
    <div v-if="progress && progress.total > 0" class="mb-4">
      <div class="flex items-center justify-between text-xs text-gray-500 mb-1">
        <span>Umsetzungsfortschritt</span>
        <span class="font-medium">{{ progressPercent }}% ({{ progress.implemented + progress.partial }}/{{ progress.total }})</span>
      </div>
      <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden flex">
        <div class="bg-status-implemented transition-all duration-300"
             :style="{ width: (progress.implemented / progress.total * 100) + '%' }" />
        <div class="bg-status-partial transition-all duration-300"
             :style="{ width: (progress.partial / progress.total * 100) + '%' }" />
        <div class="bg-status-planned transition-all duration-300"
             :style="{ width: (progress.planned / progress.total * 100) + '%' }" />
      </div>
      <div class="flex gap-4 mt-1.5 flex-wrap">
        <span class="text-xs text-gray-500"><span class="inline-block w-2 h-2 rounded-full bg-status-implemented mr-1"></span>Umgesetzt: {{ progress.implemented }}</span>
        <span class="text-xs text-gray-500"><span class="inline-block w-2 h-2 rounded-full bg-status-partial mr-1"></span>Teilweise: {{ progress.partial }}</span>
        <span class="text-xs text-gray-500"><span class="inline-block w-2 h-2 rounded-full bg-status-planned mr-1"></span>Geplant: {{ progress.planned }}</span>
        <span class="text-xs text-gray-500"><span class="inline-block w-2 h-2 rounded-full bg-gray-300 mr-1"></span>Offen: {{ progress.not_started }}</span>
        <span class="text-xs text-gray-500"><span class="inline-block w-2 h-2 rounded-full bg-gray-200 mr-1"></span>Entfällt: {{ progress.not_applicable }}</span>
      </div>
    </div>

    <!-- Filter bar -->
    <div class="flex items-center gap-3 mb-4 flex-wrap">
      <input
        v-model="filterSearch"
        type="search"
        placeholder="ID oder Titel suchen…"
        class="w-52 px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-400 focus:border-primary-400"
      />
      <select v-model="filterStatus" class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-400">
        <option value="">Alle Status</option>
        <option v-for="(label, val) in STATUS_LABELS" :key="val" :value="val">{{ label }}</option>
      </select>
      <select v-model="filterAsset" class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-400">
        <option value="">Alle Zielobjekte</option>
        <option v-for="a in assets" :key="a.id" :value="String(a.id)">{{ a.name }}</option>
      </select>
    </div>

    <!-- Two-column layout -->
    <div class="flex gap-5 flex-1 min-h-0">

      <!-- Left: control list -->
      <div class="w-80 flex-shrink-0 flex flex-col min-h-0">
        <div v-if="loading" class="flex-1 flex items-center justify-center text-sm text-gray-400">
          <svg class="w-4 h-4 animate-spin mr-2" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
          </svg>
          Lade…
        </div>
        <div v-else-if="error" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
          {{ error }}
        </div>
        <div v-else-if="!implementations.length" class="flex-1 flex items-center justify-center text-sm text-gray-400 text-center border border-dashed border-gray-200 rounded-lg p-6">
          Keine Anforderungen gefunden.<br>
          Bitte zuerst ein Tailoring durchführen.
        </div>
        <div v-else class="flex-1 overflow-y-auto border border-gray-200 rounded-lg divide-y divide-gray-100">
          <button
            v-for="impl in implementations"
            :key="impl.id"
            @click="selectControl(impl)"
            class="w-full text-left px-3 py-2.5 hover:bg-gray-50 transition-colors"
            :class="selected?.id === impl.id ? 'bg-primary-50 border-l-2 border-l-primary-500' : 'border-l-2 border-l-transparent'"
          >
            <div class="flex items-center gap-1.5 flex-wrap">
              <span class="font-mono text-xs font-semibold text-gray-500 shrink-0">{{ impl.control_id_str }}</span>
              <span class="text-xs px-1.5 py-0.5 rounded-full font-medium shrink-0"
                    :class="STATUS_CLASSES[impl.status] ?? STATUS_CLASSES.not_started">
                {{ STATUS_LABELS[impl.status] ?? impl.status }}
              </span>
              <div v-if="impl.responsible_name"
                   class="ml-auto shrink-0 w-5 h-5 rounded-full bg-primary-100 text-primary-700 text-xs font-bold flex items-center justify-center"
                   :title="impl.responsible_name">
                {{ userInitials(impl.responsible_name) }}
              </div>
            </div>
            <p class="text-sm text-gray-800 mt-0.5 leading-snug">{{ impl.control_title }}</p>
            <p v-if="impl.control_description" class="text-xs text-gray-400 mt-0.5 line-clamp-2 leading-snug">{{ resolveParams(impl.control_description, impl.control_parameters_json) }}</p>
          </button>
        </div>
        <p v-if="meta" class="text-xs text-gray-400 mt-1.5 text-right">{{ meta.total }} Anforderungen</p>
      </div>

      <!-- Right: detail / edit -->
      <div class="flex-1 min-w-0">
        <div v-if="!selected"
             class="h-full flex items-center justify-center text-sm text-gray-400 border border-dashed border-gray-200 rounded-lg">
          Anforderung auswählen, um Details zu bearbeiten
        </div>

        <div v-else class="border border-gray-200 rounded-lg h-full overflow-y-auto">
          <!-- Panel header -->
          <div class="sticky top-0 z-10 px-5 py-3 border-b border-gray-100 bg-gray-50 flex items-start justify-between gap-4">
            <div class="min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-mono text-xs font-bold text-gray-500">{{ selected.control_id_str }}</span>
                <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                      :class="STATUS_CLASSES[form.status] ?? STATUS_CLASSES.not_started">
                  {{ STATUS_LABELS[form.status] ?? form.status }}
                </span>
              </div>
              <h2 class="text-base font-semibold text-gray-900 mt-0.5">{{ selected.control_title }}</h2>
            </div>
            <div class="shrink-0 text-xs min-w-[80px] text-right">
              <span v-if="saving" class="flex items-center gap-1 text-gray-400 justify-end">
                <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                </svg>
                Speichern…
              </span>
              <span v-else-if="saveError" class="text-red-500">{{ saveError }}</span>
              <span v-else-if="savedAt" class="text-green-600">&#10003; {{ savedAt }}</span>
            </div>
          </div>

          <!-- Requirement text (always visible) -->
          <div v-if="selected.control_description" class="border-b border-gray-100 px-5 py-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Anforderungstext</p>
            <p class="text-sm text-gray-700 leading-relaxed whitespace-pre-line bg-blue-50 border border-blue-100 rounded-lg px-4 py-3">{{ resolveParams(selected.control_description, selected.control_parameters_json) }}</p>
          </div>

          <!-- Form body -->
          <div class="px-5 py-5 space-y-5">

            <!-- Status + Reifegrad -->
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Status</label>
                <select v-model="form.status" @change="saveField"
                        class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-primary-400 focus:outline-none"
                        :class="STATUS_SELECT_CLASSES[form.status] ?? 'border-gray-300'">
                  <option v-for="(label, val) in STATUS_LABELS" :key="val" :value="val">{{ label }}</option>
                </select>
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Reifegrad (0–5)</label>
                <input v-model.number="form.maturity_level" type="number" min="0" max="5"
                       @change="saveField"
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-400 focus:outline-none" />
              </div>
            </div>

            <!-- Umsetzungsbeschreibung -->
            <div>
              <label class="block text-xs font-semibold text-gray-600 mb-1">Umsetzungsbeschreibung</label>
              <textarea v-model="form.description" rows="5" @blur="saveField"
                        placeholder="Wie ist diese Anforderung in Ihrem Unternehmen umgesetzt?"
                        class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-400 focus:outline-none resize-none" />
            </div>

            <!-- Verantwortlich -->
            <div>
              <label class="block text-xs font-semibold text-gray-600 mb-1">Verantwortlich</label>
              <select v-model="form.responsible_user_id" @change="saveField"
                      class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-400 focus:outline-none">
                <option value="">— Nicht zugewiesen —</option>
                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.display_name }} ({{ u.role }})</option>
              </select>
            </div>

            <!-- Zielobjekte (multi) -->
            <div v-if="assets.length">
              <label class="block text-xs font-semibold text-gray-600 mb-1">Zielobjekte</label>
              <div class="border border-gray-200 rounded-lg divide-y divide-gray-100 max-h-36 overflow-y-auto">
                <label v-for="a in assets" :key="a.id"
                       class="flex items-center gap-2.5 px-3 py-2 hover:bg-gray-50 cursor-pointer text-sm text-gray-800">
                  <input type="checkbox"
                         :value="a.id"
                         v-model="form.asset_ids"
                         @change="saveField"
                         class="w-3.5 h-3.5 rounded border-gray-300 text-primary-600 focus:ring-primary-400" />
                  {{ a.name }}
                </label>
              </div>
            </div>

            <!-- Daten -->
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Zieldatum</label>
                <input v-model="form.target_date" type="date" @change="saveField"
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-400 focus:outline-none" />
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Abschlussdatum</label>
                <input v-model="form.completion_date" type="date" @change="saveField"
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-400 focus:outline-none" />
              </div>
            </div>

            <!-- Nachweise -->
            <div>
              <label class="block text-xs font-semibold text-gray-600 mb-2">Nachweise</label>
              <div v-if="evidenceFileIds.length" class="space-y-1.5 mb-3">
                <div v-for="fid in evidenceFileIds" :key="fid"
                     class="flex items-center gap-2 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm text-gray-700">
                  <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 0119 9.414V19a2 2 0 01-2 2z"/>
                  </svg>
                  <span class="text-xs">Nachweis #{{ fid }}</span>
                </div>
              </div>
              <p v-else class="text-xs text-gray-400 mb-2">Noch kein Nachweis hochgeladen.</p>

              <label class="cursor-pointer">
                <input type="file" class="hidden" @change="handleEvidenceUpload" />
                <span class="inline-flex items-center gap-2 px-3 py-2 text-xs font-medium border border-gray-300 rounded-lg bg-white hover:bg-gray-50 transition-colors"
                      :class="uploadingEvidence ? 'opacity-60 pointer-events-none' : ''">
                  <svg v-if="uploadingEvidence" class="w-3.5 h-3.5 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                  </svg>
                  <svg v-else class="w-3.5 h-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                  </svg>
                  {{ uploadingEvidence ? 'Hochladen…' : 'Nachweis hochladen' }}
                </span>
              </label>
              <p v-if="evidenceError" class="mt-1.5 text-xs text-red-600">{{ evidenceError }}</p>
              <p class="mt-1 text-xs text-gray-400">Max. 10 MB · PDF, Word, Excel, PNG, JPG, TXT</p>
            </div>

          </div>
        </div>
      </div>

    </div>
  </div>
</template>
