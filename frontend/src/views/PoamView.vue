<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/useAuthStore.js'
import { useApiClient } from '@/composables/useApi.js'

const route    = useRoute()
const auth     = useAuthStore()
const api      = useApiClient()
const domainId = computed(() => Number(route.params.id))

const canGenerate = computed(() =>
  ['admin', 'isb'].includes(auth.role)
)

const canWrite = computed(() =>
  !['auditor', 'management', 'readonly'].includes(auth.role)
)

// ── Plans (for generate selector) ────────────────────────────────────────

const plans       = ref([])
const selectedPlanId = ref(null)
const plansLoading   = ref(false)

async function loadPlans() {
  plansLoading.value = true
  try {
    const res = await api.get(`/api/domains/${domainId.value}/assessments`)
    if (res?.success) plans.value = res.data.plans ?? []
    if (plans.value.length > 0 && !selectedPlanId.value) {
      selectedPlanId.value = plans.value[0].id
    }
  } finally {
    plansLoading.value = false
  }
}

// ── Items ─────────────────────────────────────────────────────────────────

const items        = ref([])
const itemsTotal   = ref(0)
const summary      = ref({ open: 0, in_progress: 0, completed: 0, verified: 0, accepted: 0, total: 0 })
const itemsLoading = ref(false)
const filterStatus   = ref('')
const filterPriority = ref('')
const filterSearch   = ref('')

const selectedItem = ref(null)
const form = ref({
  title: '', description: '', priority: 'medium', status: 'open',
  responsible_user_id: '', deadline: '', completion_date: '',
  deviation_justification: '', milestones_json: '',
})
const saving    = ref(false)
const saveError = ref(null)
const savedAt   = ref(null)

async function loadItems() {
  itemsLoading.value = true
  try {
    const params = new URLSearchParams({
      per_page: '200',
      ...(filterStatus.value   ? { status:   filterStatus.value }   : {}),
      ...(filterPriority.value ? { priority: filterPriority.value } : {}),
      ...(filterSearch.value   ? { search:   filterSearch.value }   : {}),
    })
    const res = await api.get(`/api/domains/${domainId.value}/poam?${params}`)
    if (res?.success) {
      items.value      = res.data.items   ?? []
      itemsTotal.value = res.data.meta?.total ?? 0
      summary.value    = res.data.summary ?? summary.value
    }
  } finally {
    itemsLoading.value = false
  }
}

function selectItem(item) {
  selectedItem.value = item
  form.value = {
    title:                  item.title                  ?? '',
    description:            item.description            ?? '',
    priority:               item.priority               ?? 'medium',
    status:                 item.status                 ?? 'open',
    responsible_user_id:    item.responsible_user_id    ?? '',
    deadline:               item.deadline               ?? '',
    completion_date:        item.completion_date        ?? '',
    deviation_justification: item.deviation_justification ?? '',
    milestones_json:        item.milestones_json        ?? '',
  }
  saveError.value = null
  savedAt.value   = null
}

async function saveItem() {
  if (!selectedItem.value) return
  saving.value    = true
  saveError.value = null
  savedAt.value   = null

  // Send null for empty optional fields to clear them
  const payload = {
    ...form.value,
    responsible_user_id: form.value.responsible_user_id ? Number(form.value.responsible_user_id) : null,
    deadline:            form.value.deadline        || null,
    completion_date:     form.value.completion_date || null,
    milestones_json:     form.value.milestones_json || null,
  }

  try {
    const res = await api.put(`/api/poam/${selectedItem.value.id}`, payload)
    if (res?.success) {
      savedAt.value = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
      const idx = items.value.findIndex(i => i.id === selectedItem.value.id)
      if (idx !== -1) items.value[idx] = res.data.item
      selectedItem.value = res.data.item
      await loadItems()
    } else {
      saveError.value = res?.error ?? 'Fehler beim Speichern.'
    }
  } finally {
    saving.value = false
  }
}

// ── Generate ──────────────────────────────────────────────────────────────

const generating    = ref(false)
const generateError = ref(null)
const showGeneratePanel = ref(false)

async function generateItems() {
  if (!selectedPlanId.value) return
  generating.value    = true
  generateError.value = null
  try {
    const res = await api.post(`/api/domains/${domainId.value}/poam/generate`, {
      plan_id: selectedPlanId.value,
    })
    if (res?.success) {
      showGeneratePanel.value = false
      await loadItems()
    } else {
      generateError.value = res?.error ?? 'Fehler beim Generieren.'
    }
  } finally {
    generating.value = false
  }
}

// ── Export ────────────────────────────────────────────────────────────────

function downloadExport() {
  window.location.href = `/api/domains/${domainId.value}/poam/export`
}

// ── Users (for responsible picker) ───────────────────────────────────────

const users = ref([])
async function loadUsers() {
  const res = await api.get('/api/admin/users')
  if (res?.success) users.value = res.data.users ?? []
}

// ── Filters debounce ──────────────────────────────────────────────────────

let filterTimer = null
function triggerFilter() {
  if (filterTimer) clearTimeout(filterTimer)
  filterTimer = setTimeout(() => {
    selectedItem.value = null
    loadItems()
  }, 300)
}

watch([filterStatus, filterPriority, filterSearch], triggerFilter)
watch(domainId, () => {
  items.value        = []
  selectedItem.value = null
  plans.value        = []
  selectedPlanId.value = null
  loadAll()
})

async function loadAll() {
  await Promise.all([loadPlans(), loadItems(), loadUsers()])
}

onMounted(loadAll)

// ── Display helpers ───────────────────────────────────────────────────────

const STATUS_LABELS = {
  open:        'Offen',
  in_progress: 'In Bearbeitung',
  completed:   'Abgeschlossen',
  verified:    'Verifiziert',
  accepted:    'Akzeptiert',
}

const STATUS_BADGE_CLS = {
  open:        'bg-yellow-100 text-yellow-700',
  in_progress: 'bg-blue-100 text-blue-700',
  completed:   'bg-green-100 text-green-700',
  verified:    'bg-emerald-100 text-emerald-800',
  accepted:    'bg-orange-100 text-orange-700',
}

const PRIORITY_LABELS = {
  high:   'Hoch',
  medium: 'Mittel',
  low:    'Niedrig',
}

const PRIORITY_BADGE_CLS = {
  high:   'bg-red-100 text-red-700',
  medium: 'bg-yellow-100 text-yellow-700',
  low:    'bg-gray-100 text-gray-600',
}

const ESCALATION_CLS = {
  overdue: 'text-red-500',
  warning: 'text-yellow-500',
  ok:      'text-green-500',
  none:    'text-gray-300',
}

const ESCALATION_ICON = {
  overdue: '🔴',
  warning: '🟡',
  ok:      '🟢',
  none:    '',
}

const showCompletionDate = computed(() =>
  ['completed', 'verified'].includes(form.value.status)
)

const completionProgress = computed(() => {
  const { completed, verified, accepted, total } = summary.value
  return total > 0 ? Math.round(((completed + verified + accepted) / total) * 100) : 0
})
</script>

<template>
  <div class="flex flex-col h-full">

    <!-- ── Page header ───────────────────────────────────────────────────── -->
    <div class="px-6 py-4 border-b border-gray-200 bg-white flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-gray-900">Maßnahmenplan (POA&amp;M)</h1>
        <p class="text-sm text-gray-500 mt-0.5">Offene Feststellungen nachverfolgen und beheben</p>
      </div>
    </div>

    <div class="flex-1 flex flex-col overflow-hidden px-6 py-4 gap-4">

      <!-- ── Summary bar + actions ──────────────────────────────────────── -->
      <div class="bg-white rounded-xl border border-gray-200 p-3 flex flex-wrap items-center gap-3">
        <div class="flex gap-2 flex-wrap">
          <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700">
            Offen: {{ summary.open }}
          </span>
          <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
            In Arbeit: {{ summary.in_progress }}
          </span>
          <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">
            Abgeschlossen: {{ summary.completed }}
          </span>
          <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">
            Verifiziert: {{ summary.verified }}
          </span>
          <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-orange-100 text-orange-700">
            Akzeptiert: {{ summary.accepted }}
          </span>
          <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-primary-50 text-primary-700">
            {{ completionProgress }}% erledigt
          </span>
        </div>
        <div class="ml-auto flex gap-2 flex-wrap">
          <!-- Generate panel toggle -->
          <button
            v-if="canGenerate"
            @click="showGeneratePanel = !showGeneratePanel"
            class="flex items-center gap-1.5 px-3 py-1.5 text-xs bg-primary-600 hover:bg-primary-700 text-white rounded-lg transition-colors"
          >
            {{ showGeneratePanel ? '✕ Abbrechen' : '+ Aus Prüfplan generieren' }}
          </button>
          <button
            @click="downloadExport"
            class="px-3 py-1.5 text-xs border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors text-gray-700"
          >
            ↓ POAM exportieren
          </button>
        </div>
      </div>

      <!-- Generate panel -->
      <div v-if="showGeneratePanel && canGenerate"
        class="bg-white rounded-xl border border-primary-200 p-4"
      >
        <p class="text-sm font-medium text-gray-700 mb-3">
          Prüfplan auswählen — alle nicht-erfüllten Befunde werden als Maßnahmen angelegt:
        </p>
        <div class="flex items-center gap-3">
          <select
            v-model="selectedPlanId"
            class="flex-1 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500"
          >
            <option v-if="plans.length === 0" :value="null" disabled>Kein Prüfplan vorhanden</option>
            <option v-for="p in plans" :key="p.id" :value="p.id">
              {{ p.title }}
            </option>
          </select>
          <button
            @click="generateItems"
            :disabled="generating || !selectedPlanId"
            class="px-4 py-2 text-sm bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white rounded-lg transition-colors"
          >
            {{ generating ? 'Generiere…' : 'Maßnahmen generieren' }}
          </button>
        </div>
        <p v-if="generateError" class="mt-2 text-xs text-red-600">{{ generateError }}</p>
      </div>

      <!-- ── Two-column editor ──────────────────────────────────────────── -->
      <div class="flex-1 flex gap-3 min-h-0">

        <!-- Left panel — item list -->
        <div class="w-80 flex-shrink-0 bg-white rounded-xl border border-gray-200 flex flex-col">
          <!-- Filters -->
          <div class="p-3 border-b border-gray-100 space-y-2">
            <input
              v-model="filterSearch"
              type="text"
              placeholder="Maßnahme suchen…"
              class="w-full text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-primary-500"
            />
            <div class="flex gap-2">
              <select v-model="filterStatus"
                class="flex-1 text-sm border border-gray-300 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option value="">Alle Status</option>
                <option value="open">Offen</option>
                <option value="in_progress">In Bearbeitung</option>
                <option value="completed">Abgeschlossen</option>
                <option value="verified">Verifiziert</option>
                <option value="accepted">Akzeptiert</option>
              </select>
              <select v-model="filterPriority"
                class="flex-1 text-sm border border-gray-300 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option value="">Alle Prio.</option>
                <option value="high">Hoch</option>
                <option value="medium">Mittel</option>
                <option value="low">Niedrig</option>
              </select>
            </div>
          </div>

          <!-- Item list -->
          <div class="flex-1 overflow-y-auto">
            <div v-if="itemsLoading" class="p-4 text-sm text-gray-400 text-center">Lade Maßnahmen…</div>
            <div v-else-if="items.length === 0" class="p-4 text-sm text-gray-400 text-center">
              Keine Maßnahmen vorhanden.<br />
              <span v-if="canGenerate" class="text-xs">Klicken Sie auf „Aus Prüfplan generieren".</span>
            </div>
            <button
              v-for="item in items"
              :key="item.id"
              @click="selectItem(item)"
              class="w-full text-left px-3 py-2.5 border-b border-gray-50 hover:bg-gray-50 transition-colors"
              :class="selectedItem?.id === item.id ? 'bg-primary-50 border-primary-100' : ''"
            >
              <div class="flex items-center gap-1.5">
                <span :class="['text-xs', ESCALATION_CLS[item.escalation_status] ?? 'text-gray-300']">
                  {{ ESCALATION_ICON[item.escalation_status] ?? '' }}
                </span>
                <span class="font-mono text-xs text-gray-500 shrink-0">
                  {{ item.control_id_str ?? '—' }}
                </span>
                <span :class="['ml-auto shrink-0 px-1.5 py-0.5 rounded text-xs font-medium',
                  PRIORITY_BADGE_CLS[item.priority] ?? 'bg-gray-100 text-gray-600']">
                  {{ PRIORITY_LABELS[item.priority] ?? item.priority }}
                </span>
              </div>
              <p class="text-xs text-gray-700 mt-0.5 truncate font-medium">{{ item.title }}</p>
              <div class="flex items-center gap-2 mt-0.5">
                <span :class="['px-1.5 py-0.5 rounded text-xs font-medium',
                  STATUS_BADGE_CLS[item.status] ?? 'bg-gray-100 text-gray-600']">
                  {{ STATUS_LABELS[item.status] ?? item.status }}
                </span>
                <span v-if="item.deadline" class="text-xs text-gray-400">bis {{ item.deadline }}</span>
              </div>
            </button>
          </div>
        </div>

        <!-- Right panel — item form -->
        <div class="flex-1 bg-white rounded-xl border border-gray-200 flex flex-col min-w-0">
          <div v-if="!selectedItem" class="flex-1 flex items-center justify-center text-gray-400 text-sm">
            Wählen Sie links eine Maßnahme aus.
          </div>

          <template v-else>
            <!-- Header -->
            <div class="px-5 py-4 border-b border-gray-100">
              <div class="flex items-center gap-2 flex-wrap">
                <span v-if="selectedItem.control_id_str"
                  class="font-mono text-sm font-bold text-primary-700">
                  {{ selectedItem.control_id_str }}
                </span>
                <span :class="['px-2 py-0.5 rounded-full text-xs font-medium',
                  STATUS_BADGE_CLS[selectedItem.status] ?? 'bg-gray-100 text-gray-600']">
                  {{ STATUS_LABELS[selectedItem.status] ?? selectedItem.status }}
                </span>
                <span v-if="selectedItem.escalation_status !== 'none'" class="text-sm">
                  {{ ESCALATION_ICON[selectedItem.escalation_status] }}
                </span>
              </div>
            </div>

            <!-- Form body -->
            <div class="flex-1 overflow-y-auto px-5 py-4 space-y-4">

              <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Titel</label>
                <input
                  v-model="form.title"
                  :disabled="!canWrite"
                  type="text"
                  class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:bg-gray-50 disabled:text-gray-500"
                />
              </div>

              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-semibold text-gray-700 mb-1">Priorität</label>
                  <select v-model="form.priority" :disabled="!canWrite"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:bg-gray-50 disabled:text-gray-500">
                    <option value="high">Hoch</option>
                    <option value="medium">Mittel</option>
                    <option value="low">Niedrig</option>
                  </select>
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-700 mb-1">Status</label>
                  <select v-model="form.status" :disabled="!canWrite"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:bg-gray-50 disabled:text-gray-500">
                    <option value="open">Offen</option>
                    <option value="in_progress">In Bearbeitung</option>
                    <option value="completed">Abgeschlossen</option>
                    <option value="verified">Verifiziert</option>
                    <option value="accepted">Akzeptiert</option>
                  </select>
                </div>
              </div>

              <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Verantwortliche Person</label>
                <select v-model="form.responsible_user_id" :disabled="!canWrite"
                  class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:bg-gray-50 disabled:text-gray-500">
                  <option value="">— Nicht zugewiesen —</option>
                  <option v-for="u in users" :key="u.id" :value="u.id">
                    {{ u.display_name }} ({{ u.role }})
                  </option>
                </select>
              </div>

              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-semibold text-gray-700 mb-1">
                    Deadline
                    <span v-if="selectedItem.escalation_status === 'overdue'"
                      class="ml-1 text-red-500 font-normal">🔴 überfällig</span>
                    <span v-else-if="selectedItem.escalation_status === 'warning'"
                      class="ml-1 text-yellow-500 font-normal">🟡 bald fällig</span>
                  </label>
                  <input
                    v-model="form.deadline"
                    type="date"
                    :disabled="!canWrite"
                    :class="[
                      'w-full text-sm border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:bg-gray-50 disabled:text-gray-500',
                      selectedItem.escalation_status === 'overdue' ? 'border-red-300 bg-red-50' :
                      selectedItem.escalation_status === 'warning'  ? 'border-yellow-300 bg-yellow-50' :
                      'border-gray-300'
                    ]"
                  />
                </div>
                <div v-if="showCompletionDate">
                  <label class="block text-xs font-semibold text-gray-700 mb-1">Abschlussdatum</label>
                  <input v-model="form.completion_date" type="date" :disabled="!canWrite"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:bg-gray-50 disabled:text-gray-500" />
                </div>
              </div>

              <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Beschreibung</label>
                <textarea v-model="form.description" :disabled="!canWrite" rows="3"
                  placeholder="Maßnahme beschreiben…"
                  class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500 resize-none disabled:bg-gray-50 disabled:text-gray-500" />
              </div>

              <!-- Deviation justification: only when status = accepted -->
              <div v-if="form.status === 'accepted'">
                <label class="block text-xs font-semibold text-gray-700 mb-1">
                  Begründung für Risikoakzeptanz <span class="text-red-500">*</span>
                </label>
                <textarea v-model="form.deviation_justification" :disabled="!canWrite" rows="3"
                  placeholder="Warum wird dieses Risiko akzeptiert?"
                  class="w-full text-sm border border-orange-300 bg-orange-50 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orange-400 resize-none disabled:opacity-60" />
              </div>

              <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">
                  Meilensteine (JSON)
                  <span class="text-gray-400 font-normal ml-1">z.B. [{"name":"Phase 1","target_date":"2026-06-30"}]</span>
                </label>
                <textarea v-model="form.milestones_json" :disabled="!canWrite" rows="3"
                  placeholder='[{"name": "Phase 1", "target_date": "2026-06-30"}]'
                  class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 font-mono focus:outline-none focus:ring-2 focus:ring-primary-500 resize-none disabled:bg-gray-50 disabled:text-gray-500" />
              </div>

            </div>

            <!-- Footer -->
            <div v-if="canWrite" class="px-5 py-3 border-t border-gray-100 flex items-center justify-between">
              <div class="text-xs">
                <span v-if="savedAt" class="text-green-600">✓ Gespeichert um {{ savedAt }}</span>
                <span v-if="saveError" class="text-red-600">{{ saveError }}</span>
              </div>
              <button
                @click="saveItem"
                :disabled="saving"
                class="px-4 py-2 text-sm bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white rounded-lg transition-colors"
              >
                {{ saving ? 'Speichern…' : 'Maßnahme speichern' }}
              </button>
            </div>
          </template>
        </div>
      </div>

    </div>
  </div>
</template>
