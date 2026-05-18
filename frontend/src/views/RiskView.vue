<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useApiClient } from '@/composables/useApi.js'
import { useAuthStore } from '@/stores/useAuthStore.js'

const route  = useRoute()
const auth   = useAuthStore()
const api    = useApiClient()
const domainId = computed(() => route.params.id)

// ── State ─────────────────────────────────────────────────────────────────────

const activeTab     = ref('list')          // 'list' | 'heatmap'
const risks         = ref([])
const heatmapData   = ref({ risks: [], cells: {} })
const assets        = ref([])
const scopedControls= ref([])
const tenantUsers   = ref([])
const loading       = ref(false)
const saving        = ref(false)
const saveError     = ref('')
const selected      = ref(null)            // selected risk for edit
const isCreating    = ref(false)           // true = new risk form

const filterSearch    = ref('')
const filterLevel     = ref('')
const filterTreatment = ref('')

const filterActive  = ref(false)
let   filterTimer   = null

// Control search for linking
const controlSearch    = ref('')
const controlSearchRes = ref([])

// ── Form model ────────────────────────────────────────────────────────────────

const emptyForm = () => ({
  title: '', description: '', asset_id: '', owner_user_id: '',
  likelihood: 'medium', impact: 'medium', treatment: 'mitigate',
  acceptance_justification: '',
})

const form = ref(emptyForm())

// ── Computed ──────────────────────────────────────────────────────────────────

const canWrite = computed(() =>
  !['auditor', 'management', 'readonly'].includes(auth.role)
)

const computedLevel = computed(() => {
  return calcLevel(form.value.likelihood, form.value.impact)
})

const requiresJustification = computed(() => form.value.treatment === 'accept')

const filteredControls = computed(() => {
  const q = controlSearch.value.toLowerCase()
  if (!q) return scopedControls.value.slice(0, 20)
  return scopedControls.value
    .filter(c => c.control_id_str.toLowerCase().includes(q) || c.title.toLowerCase().includes(q))
    .slice(0, 20)
})

// ── Risk level helpers ────────────────────────────────────────────────────────

const LIKELIHOOD_MAP = { very_low: 1, low: 2, medium: 3, high: 4, very_high: 5 }
const IMPACT_MAP     = { negligible: 1, low: 2, medium: 3, high: 4, critical: 5 }

function calcLevel(likelihood, impact) {
  const score = (LIKELIHOOD_MAP[likelihood] ?? 3) * (IMPACT_MAP[impact] ?? 3)
  if (score >= 17) return 'critical'
  if (score >= 10) return 'high'
  if (score >= 5)  return 'medium'
  return 'low'
}

const LEVEL_LABELS = { low: 'Niedrig', medium: 'Mittel', high: 'Hoch', critical: 'Kritisch' }
const LEVEL_CLASSES = {
  low:      'bg-green-100 text-green-800',
  medium:   'bg-yellow-100 text-yellow-800',
  high:     'bg-orange-100 text-orange-800',
  critical: 'bg-red-100 text-red-800',
}
const LEVEL_CELL_BG = {
  low:      'bg-green-100 hover:bg-green-200',
  medium:   'bg-yellow-100 hover:bg-yellow-200',
  high:     'bg-orange-100 hover:bg-orange-200',
  critical: 'bg-red-100 hover:bg-red-200',
}
const TREATMENT_LABELS = { mitigate: 'Mindern', accept: 'Akzeptieren', transfer: 'Übertragen', avoid: 'Vermeiden' }

const LIKELIHOODS = [
  { value: 'very_low',  label: 'Sehr gering' },
  { value: 'low',       label: 'Gering' },
  { value: 'medium',    label: 'Mittel' },
  { value: 'high',      label: 'Hoch' },
  { value: 'very_high', label: 'Sehr hoch' },
]

const IMPACTS = [
  { value: 'negligible', label: 'Vernachlässigbar' },
  { value: 'low',        label: 'Gering' },
  { value: 'medium',     label: 'Mittel' },
  { value: 'high',       label: 'Hoch' },
  { value: 'critical',   label: 'Kritisch' },
]

// Heatmap axis order (Y = likelihood top-down from high to low)
const HEATMAP_LIKELIHOODS = [...LIKELIHOODS].reverse()
const HEATMAP_IMPACTS     = IMPACTS

// ── Data fetching ──────────────────────────────────────────────────────────────

async function fetchRisks() {
  loading.value = true
  try {
    const params = new URLSearchParams({ per_page: '200' })
    if (filterLevel.value)    params.set('risk_level', filterLevel.value)
    if (filterTreatment.value) params.set('treatment', filterTreatment.value)
    if (filterSearch.value)   params.set('search', filterSearch.value)

    const res = await api.get(`/api/domains/${domainId.value}/risks?${params}`)
    if (res.success) risks.value = res.data.items
  } finally {
    loading.value = false
  }
}

async function fetchHeatmap() {
  const res = await api.get(`/api/domains/${domainId.value}/dashboard/risks`)
  if (res.success) heatmapData.value = res.data
}

async function fetchAssets() {
  const res = await api.get(`/api/domains/${domainId.value}/assets`)
  if (res.success) assets.value = res.data.assets ?? []
}

async function fetchScopedControls() {
  const res = await api.get(`/api/domains/${domainId.value}/scoped-controls`)
  if (res.success) scopedControls.value = res.data.items ?? []
}

onMounted(async () => {
  await fetchRisks()
  fetchAssets()
  fetchScopedControls()
})

watch(activeTab, (tab) => {
  if (tab === 'heatmap') fetchHeatmap()
})

// ── Filters ────────────────────────────────────────────────────────────────────

function onFilterChange() {
  clearTimeout(filterTimer)
  filterTimer = setTimeout(fetchRisks, 300)
}

watch([filterSearch, filterLevel, filterTreatment], onFilterChange)

// ── Row selection ──────────────────────────────────────────────────────────────

function selectRisk(risk) {
  isCreating.value = false
  selected.value   = risk
  saveError.value  = ''
  form.value = {
    title:                    risk.title                    ?? '',
    description:              risk.description              ?? '',
    asset_id:                 risk.asset_id                 ?? '',
    owner_user_id:            risk.owner_user_id            ?? '',
    likelihood:               risk.likelihood               ?? 'medium',
    impact:                   risk.impact                   ?? 'medium',
    treatment:                risk.treatment                ?? 'mitigate',
    acceptance_justification: risk.acceptance_justification ?? '',
  }
  controlSearch.value = ''
}

function startCreate() {
  isCreating.value = true
  selected.value   = null
  saveError.value  = ''
  form.value       = emptyForm()
}

// ── Save / Delete ──────────────────────────────────────────────────────────────

async function save() {
  saving.value    = true
  saveError.value = ''

  try {
    let res
    if (isCreating.value) {
      res = await api.post(`/api/domains/${domainId.value}/risks`, form.value)
    } else {
      res = await api.put(`/api/risks/${selected.value.id}`, form.value)
    }

    if (res.success) {
      await fetchRisks()
      if (activeTab.value === 'heatmap') fetchHeatmap()
      selectRisk(res.data.risk)
      isCreating.value = false
    } else {
      saveError.value = res.error ?? 'Fehler beim Speichern.'
    }
  } finally {
    saving.value = false
  }
}

// ── Control linking ────────────────────────────────────────────────────────────

async function linkControl(controlId) {
  const res = await api.post(`/api/risks/${selected.value.id}/controls`, { scoped_control_id: controlId })
  if (res.success) {
    selected.value = res.data.risk
    controlSearch.value = ''
  }
}

async function unlinkControl(controlId) {
  const res = await api.delete(`/api/risks/${selected.value.id}/controls/${controlId}`)
  if (res.success) {
    selected.value = res.data.risk
  }
}

// ── Heatmap helpers ───────────────────────────────────────────────────────────

function cellCount(likelihood, impact) {
  return heatmapData.value.cells[`${likelihood}|${impact}`] ?? 0
}

function cellLevel(likelihood, impact) {
  return calcLevel(likelihood, impact)
}

function cellClick(likelihood, impact) {
  activeTab.value       = 'list'
  filterLevel.value     = ''
  filterTreatment.value = ''
  filterSearch.value    = ''
  nextTick(() => {
    const risk = heatmapData.value.risks.find(r => r.likelihood === likelihood && r.impact === impact)
    if (risk) {
      const match = risks.value.find(r => r.id === risk.id)
      if (match) selectRisk(match)
    }
  })
}
</script>

<template>
  <div class="flex flex-col h-full">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Risikomanagement</h1>
        <p class="text-sm text-gray-500 mt-1">Risikoerfassung, -bewertung und Risikolandkarte</p>
      </div>

      <!-- Tabs -->
      <div class="flex gap-1 bg-gray-100 rounded-lg p-1">
        <button
          class="px-4 py-1.5 text-sm font-medium rounded-md transition-colors"
          :class="activeTab === 'list' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700'"
          @click="activeTab = 'list'"
        >Risikoliste</button>
        <button
          class="px-4 py-1.5 text-sm font-medium rounded-md transition-colors"
          :class="activeTab === 'heatmap' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700'"
          @click="activeTab = 'heatmap'"
        >Heatmap</button>
      </div>
    </div>

    <!-- ─── Tab 1: Risikoliste ──────────────────────────────────────────────── -->
    <div v-if="activeTab === 'list'" class="flex gap-6 flex-1 min-h-0">

      <!-- Left panel: list -->
      <div class="w-80 flex flex-col bg-white rounded-xl shadow-sm border border-gray-200">

        <!-- Filter bar -->
        <div class="p-3 border-b border-gray-100 space-y-2">
          <input
            v-model="filterSearch"
            type="text"
            placeholder="Suche …"
            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-primary-400"
          />
          <div class="flex gap-2">
            <select
              v-model="filterLevel"
              class="flex-1 text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none"
            >
              <option value="">Alle Level</option>
              <option value="low">Niedrig</option>
              <option value="medium">Mittel</option>
              <option value="high">Hoch</option>
              <option value="critical">Kritisch</option>
            </select>
            <select
              v-model="filterTreatment"
              class="flex-1 text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none"
            >
              <option value="">Alle Behandlungen</option>
              <option value="mitigate">Mindern</option>
              <option value="accept">Akzeptieren</option>
              <option value="transfer">Übertragen</option>
              <option value="avoid">Vermeiden</option>
            </select>
          </div>
        </div>

        <!-- New risk button -->
        <div v-if="canWrite" class="px-3 py-2 border-b border-gray-100">
          <button
            class="w-full text-sm bg-primary-600 hover:bg-primary-700 text-white py-2 rounded-lg font-medium transition-colors"
            @click="startCreate"
          >+ Neues Risiko</button>
        </div>

        <!-- List -->
        <div class="flex-1 overflow-y-auto">
          <div v-if="loading" class="p-4 text-sm text-gray-400">Lade …</div>
          <div v-else-if="risks.length === 0" class="p-4 text-sm text-gray-400">
            Noch keine Risiken erfasst.
          </div>
          <button
            v-for="risk in risks"
            :key="risk.id"
            class="w-full text-left px-4 py-3 border-b border-gray-50 hover:bg-gray-50 transition-colors"
            :class="selected?.id === risk.id ? 'bg-primary-50 border-l-2 border-l-primary-600' : ''"
            @click="selectRisk(risk)"
          >
            <div class="flex items-start justify-between gap-2">
              <span class="text-sm font-medium text-gray-900 leading-snug line-clamp-2">{{ risk.title }}</span>
              <span
                class="shrink-0 text-xs px-1.5 py-0.5 rounded font-medium"
                :class="LEVEL_CLASSES[risk.risk_level] ?? 'bg-gray-100 text-gray-600'"
              >{{ LEVEL_LABELS[risk.risk_level] ?? risk.risk_level }}</span>
            </div>
            <div class="flex items-center gap-2 mt-1">
              <span class="text-xs text-gray-400">{{ TREATMENT_LABELS[risk.treatment] ?? risk.treatment }}</span>
              <span v-if="risk.linked_controls_count > 0" class="text-xs text-gray-400">
                · {{ risk.linked_controls_count }} Anforderung{{ risk.linked_controls_count !== 1 ? 'en' : '' }}
              </span>
            </div>
          </button>
        </div>

        <!-- Footer -->
        <div class="px-4 py-2 border-t border-gray-100 text-xs text-gray-400">
          {{ risks.length }} Risiken
        </div>
      </div>

      <!-- Right panel: form -->
      <div class="flex-1 bg-white rounded-xl shadow-sm border border-gray-200 overflow-y-auto">

        <!-- Empty state -->
        <div v-if="!selected && !isCreating" class="flex flex-col items-center justify-center h-full text-center text-gray-400 p-8">
          <div class="text-4xl mb-3">⚠️</div>
          <p class="text-sm">Risiko auswählen oder neues Risiko anlegen.</p>
        </div>

        <!-- Form -->
        <div v-else class="p-6 space-y-5">

          <!-- Header -->
          <div class="flex items-start justify-between gap-4">
            <h2 class="text-lg font-semibold text-gray-900">
              {{ isCreating ? 'Neues Risiko' : 'Risiko bearbeiten' }}
            </h2>
            <!-- Computed level badge -->
            <span
              class="shrink-0 px-3 py-1 rounded-full text-sm font-semibold"
              :class="LEVEL_CLASSES[computedLevel]"
            >{{ LEVEL_LABELS[computedLevel] }}</span>
          </div>

          <!-- Title -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Titel <span class="text-red-500">*</span></label>
            <input
              v-model="form.title"
              :disabled="!canWrite"
              type="text"
              class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-primary-400 disabled:bg-gray-50"
            />
          </div>

          <!-- Description -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Beschreibung</label>
            <textarea
              v-model="form.description"
              :disabled="!canWrite"
              rows="3"
              class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-primary-400 disabled:bg-gray-50 resize-none"
            ></textarea>
          </div>

          <!-- Likelihood + Impact row -->
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Eintrittswahrscheinlichkeit</label>
              <select
                v-model="form.likelihood"
                :disabled="!canWrite"
                class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-primary-400 disabled:bg-gray-50"
              >
                <option v-for="l in LIKELIHOODS" :key="l.value" :value="l.value">{{ l.label }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Schadensausmaß</label>
              <select
                v-model="form.impact"
                :disabled="!canWrite"
                class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-primary-400 disabled:bg-gray-50"
              >
                <option v-for="i in IMPACTS" :key="i.value" :value="i.value">{{ i.label }}</option>
              </select>
            </div>
          </div>

          <!-- Treatment -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Risikobehandlung</label>
            <select
              v-model="form.treatment"
              :disabled="!canWrite"
              class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-primary-400 disabled:bg-gray-50"
            >
              <option value="mitigate">Mindern</option>
              <option value="accept">Akzeptieren</option>
              <option value="transfer">Übertragen</option>
              <option value="avoid">Vermeiden</option>
            </select>
          </div>

          <!-- Acceptance justification (only when treatment=accept) -->
          <div v-if="requiresJustification">
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Akzeptanzbegründung <span class="text-red-500">*</span>
            </label>
            <textarea
              v-model="form.acceptance_justification"
              :disabled="!canWrite"
              rows="3"
              placeholder="Begründung für die Risikoakzeptanz (z.B. Kosten-Nutzen-Abwägung) …"
              class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-primary-400 disabled:bg-gray-50 resize-none"
            ></textarea>
          </div>

          <!-- Asset + Owner row -->
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Betroffenes Asset</label>
              <select
                v-model="form.asset_id"
                :disabled="!canWrite"
                class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-primary-400 disabled:bg-gray-50"
              >
                <option value="">— systemweit —</option>
                <option v-for="a in assets" :key="a.id" :value="a.id">{{ a.name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Verantwortlich</label>
              <select
                v-model="form.owner_user_id"
                :disabled="!canWrite"
                class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-primary-400 disabled:bg-gray-50"
              >
                <option value="">—</option>
              </select>
            </div>
          </div>

          <!-- Linked controls (only in edit mode) -->
          <div v-if="!isCreating && selected">
            <label class="block text-sm font-medium text-gray-700 mb-2">Zugehörige Anforderungen</label>

            <!-- Existing chips -->
            <div class="flex flex-wrap gap-2 mb-2">
              <span
                v-for="ctrl in (selected.linked_controls ?? [])"
                :key="ctrl.id"
                class="inline-flex items-center gap-1 bg-primary-50 text-primary-800 text-xs px-2 py-1 rounded-full"
              >
                {{ ctrl.control_id_str }}
                <button
                  v-if="canWrite"
                  class="text-primary-400 hover:text-red-500 transition-colors"
                  @click="unlinkControl(ctrl.id)"
                >×</button>
              </span>
              <span v-if="!(selected.linked_controls?.length)" class="text-xs text-gray-400">
                Noch keine Anforderungen verknüpft.
              </span>
            </div>

            <!-- Search to link -->
            <div v-if="canWrite" class="relative">
              <input
                v-model="controlSearch"
                type="text"
                placeholder="Anforderung suchen und verknüpfen …"
                class="w-full text-sm border border-gray-200 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-primary-400"
              />
              <div v-if="controlSearch && filteredControls.length" class="absolute z-10 left-0 right-0 bg-white border border-gray-200 rounded-lg shadow-lg mt-1 max-h-48 overflow-y-auto">
                <button
                  v-for="ctrl in filteredControls"
                  :key="ctrl.id"
                  class="w-full text-left px-3 py-2 hover:bg-gray-50 text-sm"
                  @click="linkControl(ctrl.id)"
                >
                  <span class="font-mono text-xs text-primary-700 mr-2">{{ ctrl.control_id_str }}</span>
                  {{ ctrl.title }}
                </button>
              </div>
            </div>
          </div>

          <!-- Error -->
          <div v-if="saveError" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">
            {{ saveError }}
          </div>

          <!-- Save button -->
          <div v-if="canWrite" class="flex justify-end pt-2">
            <button
              :disabled="saving"
              class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white px-6 py-2 rounded-lg font-medium transition-colors text-sm"
              @click="save"
            >
              <span v-if="saving" class="inline-block w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
              {{ isCreating ? 'Anlegen' : 'Speichern' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── Tab 2: Heatmap ─────────────────────────────────────────────────── -->
    <div v-if="activeTab === 'heatmap'" class="flex-1">
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-base font-semibold text-gray-900 mb-6">
          Risikolandkarte — Schadensausmaß × Eintrittswahrscheinlichkeit
        </h2>

        <div class="flex gap-4">

          <!-- Y-axis label -->
          <div class="flex items-center justify-center" style="writing-mode: vertical-rl; transform: rotate(180deg);">
            <span class="text-xs text-gray-500 font-medium">Eintrittswahrscheinlichkeit</span>
          </div>

          <div class="flex flex-col gap-1 flex-1">
            <!-- Grid rows (likelihood high → low) -->
            <div
              v-for="l in HEATMAP_LIKELIHOODS"
              :key="l.value"
              class="flex gap-1 items-center"
            >
              <!-- Y-axis tick label -->
              <div class="w-24 text-right pr-2">
                <span class="text-xs text-gray-500">{{ l.label }}</span>
              </div>

              <!-- Cells -->
              <button
                v-for="i in HEATMAP_IMPACTS"
                :key="i.value"
                class="flex-1 h-16 rounded-lg flex items-center justify-center text-lg font-bold transition-colors cursor-pointer"
                :class="[
                  LEVEL_CELL_BG[cellLevel(l.value, i.value)],
                  cellCount(l.value, i.value) === 0 ? 'opacity-40' : ''
                ]"
                :title="`${l.label} × ${i.label}: ${cellCount(l.value, i.value)} Risiko(en)`"
                @click="cellClick(l.value, i.value)"
              >
                <span v-if="cellCount(l.value, i.value) > 0" class="text-sm font-bold">
                  {{ cellCount(l.value, i.value) }}
                </span>
                <span v-else class="text-gray-300 text-xs font-normal">–</span>
              </button>
            </div>

            <!-- X-axis labels -->
            <div class="flex gap-1 mt-1">
              <div class="w-24"></div>
              <div
                v-for="i in HEATMAP_IMPACTS"
                :key="i.value"
                class="flex-1 text-center"
              >
                <span class="text-xs text-gray-500">{{ i.label }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- X-axis label -->
        <div class="text-center mt-4">
          <span class="text-xs text-gray-500 font-medium">Schadensausmaß</span>
        </div>

        <!-- Legend -->
        <div class="flex gap-4 justify-center mt-6">
          <div
            v-for="(label, level) in LEVEL_LABELS"
            :key="level"
            class="flex items-center gap-1.5"
          >
            <div class="w-4 h-4 rounded" :class="LEVEL_CELL_BG[level]?.split(' ')[0]"></div>
            <span class="text-xs text-gray-600">{{ label }}</span>
          </div>
        </div>
      </div>

      <!-- Risk list below heatmap -->
      <div v-if="heatmapData.risks.length" class="mt-4 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Alle Risiken ({{ heatmapData.risks.length }})</h3>
        <div class="space-y-2">
          <div
            v-for="r in heatmapData.risks"
            :key="r.id"
            class="flex items-center gap-3 text-sm"
          >
            <span
              class="px-2 py-0.5 rounded text-xs font-medium shrink-0"
              :class="LEVEL_CLASSES[r.risk_level] ?? 'bg-gray-100 text-gray-600'"
            >{{ LEVEL_LABELS[r.risk_level] }}</span>
            <span class="text-gray-900 flex-1">{{ r.title }}</span>
            <span class="text-gray-400 text-xs shrink-0">{{ TREATMENT_LABELS[r.treatment] }}</span>
          </div>
        </div>
      </div>

      <div v-else class="mt-4 text-center text-gray-400 text-sm py-8">
        Noch keine Risiken vorhanden. Wechseln Sie zur Risikoliste, um Risiken anzulegen.
      </div>
    </div>

  </div>
</template>
