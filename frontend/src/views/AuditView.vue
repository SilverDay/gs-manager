<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/useAuthStore.js'
import { useApiClient } from '@/composables/useApi.js'

const route    = useRoute()
const auth     = useAuthStore()
const api      = useApiClient()
const domainId = computed(() => Number(route.params.id))

const canWrite = computed(() =>
  !['fachverantwortlich', 'management', 'readonly'].includes(auth.role)
)

// ── Plans ─────────────────────────────────────────────────────────────────

const plans        = ref([])
const selectedPlan = ref(null)
const showCreateForm = ref(false)
const planLoading  = ref(false)
const planError    = ref(null)

const newPlan = ref({
  title: '', assessor_name: '', assessor_org: '', assessor_email: '',
  period_start: '', period_end: '', methodology: '', status: 'draft',
})
const planSaving = ref(false)
const planSaveError = ref(null)

async function loadPlans() {
  planLoading.value = true
  planError.value   = null
  try {
    const res = await api.get(`/api/domains/${domainId.value}/assessments`)
    if (res?.success) {
      plans.value = res.data.plans ?? []
      if (plans.value.length > 0 && !selectedPlan.value) {
        await selectPlan(plans.value[0])
      }
    } else {
      planError.value = res?.error ?? 'Fehler beim Laden der Prüfpläne.'
    }
  } finally {
    planLoading.value = false
  }
}

async function selectPlan(plan) {
  selectedPlan.value = plan
  showCreateForm.value = false
  await loadFindings()
}

async function savePlan() {
  if (!newPlan.value.title.trim()) {
    planSaveError.value = 'Titel ist ein Pflichtfeld.'
    return
  }
  planSaving.value    = true
  planSaveError.value = null
  try {
    const res = await api.post(`/api/domains/${domainId.value}/assessments`, newPlan.value)
    if (res?.success) {
      plans.value.unshift(res.data.plan)
      await selectPlan(res.data.plan)
      resetNewPlan()
    } else {
      planSaveError.value = res?.error ?? 'Fehler beim Erstellen des Prüfplans.'
    }
  } finally {
    planSaving.value = false
  }
}

function resetNewPlan() {
  newPlan.value = {
    title: '', assessor_name: '', assessor_org: '', assessor_email: '',
    period_start: '', period_end: '', methodology: '', status: 'draft',
  }
}

// ── Findings ──────────────────────────────────────────────────────────────

const findings        = ref([])
const findingsTotal   = ref(0)
const findingsSummary = ref({ satisfied: 0, not_satisfied: 0, partial: 0, not_assessed: 0, total: 0 })
const findingsLoading = ref(false)
const filterResult    = ref('')
const filterSearch    = ref('')
const findingsPage    = ref(1)

const selectedFinding = ref(null)
const form = ref({
  method: '', result: '', observation: '', risk_statement: '',
})
const saving    = ref(false)
const saveError = ref(null)
const savedAt   = ref(null)

async function loadFindings(resetPage = true) {
  if (!selectedPlan.value) return
  if (resetPage) findingsPage.value = 1
  findingsLoading.value = true
  try {
    const params = new URLSearchParams({
      page: String(findingsPage.value),
      per_page: '100',
      ...(filterResult.value ? { result: filterResult.value } : {}),
      ...(filterSearch.value ? { search: filterSearch.value } : {}),
    })
    const res = await api.get(`/api/assessments/${selectedPlan.value.id}/findings?${params}`)
    if (res?.success) {
      findings.value        = res.data.items ?? []
      findingsTotal.value   = res.data.meta?.total ?? 0
      findingsSummary.value = res.data.summary ?? findingsSummary.value
    }
  } finally {
    findingsLoading.value = false
  }
}

function selectFinding(f) {
  selectedFinding.value = f
  form.value = {
    method:         f.method         ?? '',
    result:         f.result         ?? 'not_assessed',
    observation:    f.observation    ?? '',
    risk_statement: f.risk_statement ?? '',
  }
  saveError.value = null
  savedAt.value   = null
}

async function saveFinding() {
  if (!selectedFinding.value) return
  saving.value    = true
  saveError.value = null
  savedAt.value   = null
  try {
    const res = await api.put(`/api/findings/${selectedFinding.value.id}`, form.value)
    if (res?.success) {
      savedAt.value = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
      // Update item in list
      const idx = findings.value.findIndex(f => f.id === selectedFinding.value.id)
      if (idx !== -1) findings.value[idx] = res.data.finding
      selectedFinding.value = res.data.finding
      // Refresh summary
      const sumRes = await api.get(`/api/assessments/${selectedPlan.value.id}/findings?per_page=1`)
      if (sumRes?.success) findingsSummary.value = sumRes.data.summary ?? findingsSummary.value
    } else {
      saveError.value = res?.error ?? 'Fehler beim Speichern.'
    }
  } finally {
    saving.value = false
  }
}

// ── Methods checkboxes ────────────────────────────────────────────────────

const METHOD_OPTIONS = [
  { value: 'examine',   label: 'Prüfen (Examine)' },
  { value: 'interview', label: 'Befragen (Interview)' },
  { value: 'test',      label: 'Testen (Test)' },
]

const selectedMethods = computed({
  get() {
    return form.value.method ? form.value.method.split(',').map(m => m.trim()).filter(Boolean) : []
  },
  set(arr) {
    form.value.method = arr.join(',')
  },
})

function toggleMethod(value) {
  const arr = selectedMethods.value.slice()
  const idx = arr.indexOf(value)
  if (idx === -1) arr.push(value)
  else arr.splice(idx, 1)
  selectedMethods.value = arr
}

// ── Exports ───────────────────────────────────────────────────────────────

function downloadExport(type) {
  if (!selectedPlan.value) return
  window.location.href = `/api/assessments/${selectedPlan.value.id}/export/${type}`
}

// ── Filter debounce ───────────────────────────────────────────────────────

let filterTimer = null
function triggerFilter() {
  if (filterTimer) clearTimeout(filterTimer)
  filterTimer = setTimeout(() => {
    selectedFinding.value = null
    loadFindings()
  }, 300)
}

watch([filterResult, filterSearch], triggerFilter)
watch(domainId, () => {
  plans.value        = []
  selectedPlan.value = null
  findings.value     = []
  loadPlans()
})
onMounted(loadPlans)

// ── Helpers ───────────────────────────────────────────────────────────────

const progressPct = computed(() => {
  const { satisfied, total } = findingsSummary.value
  return total > 0 ? Math.round((satisfied / total) * 100) : 0
})

const STATUS_LABELS = {
  draft:     'Entwurf',
  active:    'Aktiv',
  completed: 'Abgeschlossen',
}

const STATUS_BADGE_CLS = {
  draft:     'bg-gray-100 text-gray-700',
  active:    'bg-blue-100 text-blue-700',
  completed: 'bg-green-100 text-green-700',
}

const RESULT_LABELS = {
  satisfied:     'Erfüllt',
  not_satisfied: 'Nicht erfüllt',
  partial:       'Teilweise',
  not_assessed:  'Nicht geprüft',
}

const RESULT_BADGE_CLS = {
  satisfied:     'bg-green-100 text-green-700',
  not_satisfied: 'bg-red-100 text-red-700',
  partial:       'bg-yellow-100 text-yellow-700',
  not_assessed:  'bg-gray-100 text-gray-500',
}

function resultBadgeCls(result) {
  return RESULT_BADGE_CLS[result] ?? 'bg-gray-100 text-gray-500'
}
</script>

<template>
  <div class="flex flex-col h-full">

    <!-- ── Page header ───────────────────────────────────────────────────── -->
    <div class="px-6 py-4 border-b border-gray-200 bg-white flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-gray-900">Audit &amp; Prüfung</h1>
        <p class="text-sm text-gray-500 mt-0.5">Assessment Plans und Prüfbefunde verwalten</p>
      </div>
    </div>

    <!-- ── Error banner ──────────────────────────────────────────────────── -->
    <div v-if="planError" class="mx-6 mt-4 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
      {{ planError }}
    </div>

    <div class="flex-1 flex flex-col overflow-hidden px-6 py-4 gap-4">

      <!-- ── Section 1: Plan selector ──────────────────────────────────── -->
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="flex items-center gap-3 flex-wrap">
          <!-- Plan dropdown -->
          <div class="flex-1 min-w-48">
            <select
              v-if="plans.length > 0"
              :value="selectedPlan?.id ?? ''"
              @change="selectPlan(plans.find(p => p.id === Number($event.target.value)))"
              class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500"
            >
              <option v-for="p in plans" :key="p.id" :value="p.id">
                {{ p.title }} — {{ STATUS_LABELS[p.status] ?? p.status }}
              </option>
            </select>
            <p v-else-if="!planLoading" class="text-sm text-gray-400 italic">Noch kein Prüfplan vorhanden.</p>
            <p v-else class="text-sm text-gray-400">Lade Pläne…</p>
          </div>

          <!-- Create button -->
          <button
            v-if="canWrite"
            @click="showCreateForm = !showCreateForm"
            class="flex items-center gap-1.5 px-4 py-2 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-lg transition-colors"
          >
            <span>{{ showCreateForm ? '✕ Abbrechen' : '+ Neuer Prüfplan' }}</span>
          </button>
        </div>

        <!-- Plan metadata strip (when plan selected + form hidden) -->
        <div
          v-if="selectedPlan && !showCreateForm"
          class="mt-3 pt-3 border-t border-gray-100 flex flex-wrap gap-x-6 gap-y-1 text-xs text-gray-600"
        >
          <span v-if="selectedPlan.assessor_name">
            <span class="text-gray-400">Prüfer:</span> {{ selectedPlan.assessor_name }}
            <span v-if="selectedPlan.assessor_org"> ({{ selectedPlan.assessor_org }})</span>
          </span>
          <span v-if="selectedPlan.period_start || selectedPlan.period_end">
            <span class="text-gray-400">Zeitraum:</span>
            {{ selectedPlan.period_start ?? '?' }} – {{ selectedPlan.period_end ?? '?' }}
          </span>
          <span
            :class="['px-2 py-0.5 rounded-full font-medium', STATUS_BADGE_CLS[selectedPlan.status] ?? 'bg-gray-100 text-gray-600']"
          >
            {{ STATUS_LABELS[selectedPlan.status] ?? selectedPlan.status }}
          </span>
        </div>

        <!-- Create form -->
        <div v-if="showCreateForm" class="mt-4 pt-4 border-t border-gray-100">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="sm:col-span-2">
              <label class="block text-xs font-medium text-gray-700 mb-1">Titel *</label>
              <input
                v-model="newPlan.title"
                type="text"
                placeholder="z.B. Erstprüfung 2026"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500"
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Prüfer</label>
              <input v-model="newPlan.assessor_name" type="text" placeholder="Name des Prüfers"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Organisation</label>
              <input v-model="newPlan.assessor_org" type="text" placeholder="Organisation"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">E-Mail Prüfer</label>
              <input v-model="newPlan.assessor_email" type="email" placeholder="pruefer@example.de"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Status</label>
              <select v-model="newPlan.status"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option value="draft">Entwurf</option>
                <option value="active">Aktiv</option>
                <option value="completed">Abgeschlossen</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Prüfzeitraum von</label>
              <input v-model="newPlan.period_start" type="date"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Prüfzeitraum bis</label>
              <input v-model="newPlan.period_end" type="date"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
            </div>
            <div class="sm:col-span-2">
              <label class="block text-xs font-medium text-gray-700 mb-1">Methodik</label>
              <textarea v-model="newPlan.methodology" rows="2" placeholder="Beschreibung der Prüfmethodik…"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500 resize-none" />
            </div>
          </div>
          <p v-if="planSaveError" class="mt-2 text-xs text-red-600">{{ planSaveError }}</p>
          <div class="mt-3 flex justify-end gap-2">
            <button @click="showCreateForm = false; resetNewPlan()"
              class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 transition-colors">
              Abbrechen
            </button>
            <button
              @click="savePlan"
              :disabled="planSaving"
              class="px-4 py-2 text-sm bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white rounded-lg transition-colors"
            >
              {{ planSaving ? 'Speichern…' : 'Prüfplan erstellen' }}
            </button>
          </div>
        </div>
      </div>

      <!-- ── Section 2: Findings editor ──────────────────────────────────── -->
      <div v-if="selectedPlan" class="flex-1 flex flex-col min-h-0">

        <!-- Summary bar + exports -->
        <div class="bg-white rounded-xl border border-gray-200 p-3 mb-3 flex flex-wrap items-center gap-3">
          <div class="flex gap-2 flex-wrap">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">
              ✓ Erfüllt: {{ findingsSummary.satisfied }}
            </span>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700">
              ~ Teilweise: {{ findingsSummary.partial }}
            </span>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-700">
              ✗ Nicht erfüllt: {{ findingsSummary.not_satisfied }}
            </span>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
              – Nicht geprüft: {{ findingsSummary.not_assessed }}
            </span>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-primary-50 text-primary-700">
              {{ progressPct }}% abgeschlossen
            </span>
          </div>
          <div class="ml-auto flex gap-2">
            <button
              @click="downloadExport('ap')"
              class="px-3 py-1.5 text-xs border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors text-gray-700"
            >
              ↓ AP exportieren
            </button>
            <button
              @click="downloadExport('ar')"
              class="px-3 py-1.5 text-xs border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors text-gray-700"
            >
              ↓ AR exportieren
            </button>
          </div>
        </div>

        <!-- Two-column findings editor -->
        <div class="flex-1 flex gap-3 min-h-0">

          <!-- Left panel — findings list -->
          <div class="w-80 flex-shrink-0 bg-white rounded-xl border border-gray-200 flex flex-col">
            <!-- Filters -->
            <div class="p-3 border-b border-gray-100 space-y-2">
              <input
                v-model="filterSearch"
                type="text"
                placeholder="Anforderung suchen…"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-primary-500"
              />
              <select
                v-model="filterResult"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-primary-500"
              >
                <option value="">Alle Ergebnisse</option>
                <option value="satisfied">Erfüllt</option>
                <option value="not_satisfied">Nicht erfüllt</option>
                <option value="partial">Teilweise erfüllt</option>
                <option value="not_assessed">Nicht geprüft</option>
              </select>
            </div>

            <!-- Finding list -->
            <div class="flex-1 overflow-y-auto">
              <div v-if="findingsLoading" class="p-4 text-sm text-gray-400 text-center">
                Lade Befunde…
              </div>
              <div v-else-if="findings.length === 0" class="p-4 text-sm text-gray-400 text-center">
                Keine Anforderungen gefunden.
              </div>
              <button
                v-for="f in findings"
                :key="f.id"
                @click="selectFinding(f)"
                class="w-full text-left px-3 py-2.5 border-b border-gray-50 hover:bg-gray-50 transition-colors"
                :class="selectedFinding?.id === f.id ? 'bg-primary-50 border-primary-100' : ''"
              >
                <div class="flex items-center gap-2">
                  <span class="font-mono text-xs font-semibold text-gray-700 shrink-0">
                    {{ f.control_id_str }}
                  </span>
                  <span
                    :class="['ml-auto shrink-0 px-1.5 py-0.5 rounded text-xs font-medium', resultBadgeCls(f.result)]"
                  >
                    {{ RESULT_LABELS[f.result] ?? f.result }}
                  </span>
                </div>
                <p class="text-xs text-gray-500 mt-0.5 truncate">{{ f.control_title }}</p>
              </button>
            </div>
          </div>

          <!-- Right panel — finding form -->
          <div class="flex-1 bg-white rounded-xl border border-gray-200 flex flex-col min-w-0">
            <div v-if="!selectedFinding" class="flex-1 flex items-center justify-center text-gray-400 text-sm">
              Wählen Sie links eine Anforderung aus, um den Befund zu erfassen.
            </div>

            <template v-else>
              <!-- Header -->
              <div class="px-5 py-4 border-b border-gray-100">
                <div class="flex items-center gap-2">
                  <span class="font-mono text-sm font-bold text-primary-700">{{ selectedFinding.control_id_str }}</span>
                  <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', resultBadgeCls(selectedFinding.result)]">
                    {{ RESULT_LABELS[selectedFinding.result] ?? selectedFinding.result }}
                  </span>
                </div>
                <p class="text-sm text-gray-700 mt-0.5 font-medium">{{ selectedFinding.control_title }}</p>
              </div>

              <!-- Form body -->
              <div class="flex-1 overflow-y-auto px-5 py-4 space-y-5">

                <!-- Prüfmethode -->
                <div>
                  <label class="block text-xs font-semibold text-gray-700 mb-2">Prüfmethode</label>
                  <div class="flex gap-4">
                    <label
                      v-for="m in METHOD_OPTIONS"
                      :key="m.value"
                      class="flex items-center gap-1.5 text-sm text-gray-700 cursor-pointer"
                      :class="canWrite ? '' : 'opacity-60'"
                    >
                      <input
                        type="checkbox"
                        :value="m.value"
                        :checked="selectedMethods.includes(m.value)"
                        :disabled="!canWrite"
                        @change="canWrite && toggleMethod(m.value)"
                        class="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                      />
                      {{ m.label }}
                    </label>
                  </div>
                </div>

                <!-- Ergebnis -->
                <div>
                  <label class="block text-xs font-semibold text-gray-700 mb-1">Ergebnis</label>
                  <select
                    v-model="form.result"
                    :disabled="!canWrite"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:bg-gray-50 disabled:text-gray-500"
                  >
                    <option value="not_assessed">Nicht geprüft</option>
                    <option value="satisfied">Erfüllt</option>
                    <option value="partial">Teilweise erfüllt</option>
                    <option value="not_satisfied">Nicht erfüllt</option>
                  </select>
                </div>

                <!-- Beobachtung -->
                <div>
                  <label class="block text-xs font-semibold text-gray-700 mb-1">Beobachtung</label>
                  <textarea
                    v-model="form.observation"
                    :disabled="!canWrite"
                    rows="4"
                    placeholder="Beschreiben Sie die Prüfbeobachtungen…"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500 resize-none disabled:bg-gray-50 disabled:text-gray-500"
                  />
                </div>

                <!-- Risikobeschreibung (nur wenn nicht erfüllt) -->
                <div v-if="form.result !== 'satisfied' && form.result !== 'not_assessed'">
                  <label class="block text-xs font-semibold text-gray-700 mb-1">Risikobeschreibung</label>
                  <textarea
                    v-model="form.risk_statement"
                    :disabled="!canWrite"
                    rows="3"
                    placeholder="Beschreiben Sie das verbleibende Risiko…"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500 resize-none disabled:bg-gray-50 disabled:text-gray-500"
                  />
                </div>

              </div>

              <!-- Footer: Save + feedback -->
              <div v-if="canWrite" class="px-5 py-3 border-t border-gray-100 flex items-center justify-between">
                <div class="text-xs">
                  <span v-if="savedAt" class="text-green-600">✓ Gespeichert um {{ savedAt }}</span>
                  <span v-if="saveError" class="text-red-600">{{ saveError }}</span>
                </div>
                <button
                  @click="saveFinding"
                  :disabled="saving"
                  class="px-4 py-2 text-sm bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white rounded-lg transition-colors"
                >
                  {{ saving ? 'Speichern…' : 'Befund speichern' }}
                </button>
              </div>
            </template>
          </div>

        </div>
      </div>

      <!-- Empty state: no plan selected and no plans exist -->
      <div v-else-if="!planLoading && plans.length === 0 && !showCreateForm"
        class="flex-1 flex flex-col items-center justify-center text-center text-gray-400 py-16">
        <span class="text-4xl mb-4">🔍</span>
        <p class="text-base font-medium text-gray-600 mb-1">Noch kein Prüfplan vorhanden</p>
        <p class="text-sm text-gray-400 mb-4">
          Erstellen Sie einen Prüfplan, um die Anforderungen dieses Informationsverbunds zu prüfen.
        </p>
        <button
          v-if="canWrite"
          @click="showCreateForm = true"
          class="px-4 py-2 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-lg transition-colors"
        >
          + Neuer Prüfplan
        </button>
      </div>

    </div>
  </div>
</template>
