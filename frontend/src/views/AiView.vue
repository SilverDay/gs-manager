<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useAuthStore } from '@/stores/useAuthStore.js'
import { useApiClient, useApi } from '@/composables/useApi.js'

const auth = useAuthStore()
const api  = useApiClient()
const canUse = computed(() => !['management', 'readonly'].includes(auth.role))

// ─── Tabs ──────────────────────────────────────────────────────

const TABS = [
  {
    id: 'explain',
    label: 'Control erklären',
    icon: '💡',
    endpoint: '/api/ai/explain',
    fields: [
      { key: 'control_id',    label: 'Anforderungs-ID',   placeholder: 'z.B. PERS.3.1' },
      { key: 'control_title', label: 'Titel',              placeholder: 'z.B. Sicherheitsschulung' },
      { key: 'description',   label: 'Beschreibung',       placeholder: 'OSCAL-Beschreibungstext …', multiline: true },
    ],
  },
  {
    id: 'suggest',
    label: 'Umsetzungsvorschlag',
    icon: '🔧',
    endpoint: '/api/ai/suggest-implementation',
    fields: [
      { key: 'control_id',    label: 'Anforderungs-ID',   placeholder: 'z.B. PERS.3.1' },
      { key: 'control_title', label: 'Titel',              placeholder: 'z.B. Sicherheitsschulung' },
      { key: 'description',   label: 'Beschreibung',       placeholder: 'OSCAL-Beschreibungstext …', multiline: true },
      { key: 'industry',      label: 'Branche (optional)', placeholder: 'z.B. Produktion, Handel …', optional: true },
      { key: 'org_size',      label: 'Mitarbeitende (opt.)', placeholder: 'z.B. 120',                optional: true },
    ],
  },
  {
    id: 'risk',
    label: 'Risikoanalyse',
    icon: '⚠️',
    endpoint: '/api/ai/risk-analysis',
    fields: [
      { key: 'control_id',    label: 'Anforderungs-ID',   placeholder: 'z.B. PERS.3.1' },
      { key: 'control_title', label: 'Titel',              placeholder: 'z.B. Sicherheitsschulung' },
      { key: 'description',   label: 'Beschreibung',       placeholder: 'OSCAL-Beschreibungstext …', multiline: true },
    ],
  },
  {
    id: 'audit',
    label: 'Audit-Befundvorschlag',
    icon: '🔍',
    endpoint: '/api/ai/audit-finding',
    fields: [
      { key: 'control_id',                   label: 'Anforderungs-ID',          placeholder: 'z.B. PERS.3.1' },
      { key: 'implementation_status',        label: 'Umsetzungsstatus',          placeholder: 'implemented / partial / not_started' },
      { key: 'implementation_description',   label: 'Umsetzungsbeschreibung',    placeholder: 'Wie ist die Anforderung umgesetzt?', multiline: true },
    ],
  },
  {
    id: 'remediation',
    label: 'Sanierungsvorschlag',
    icon: '🛠️',
    endpoint: '/api/ai/remediation-plan',
    fields: [
      { key: 'title',       label: 'Titel der Feststellung', placeholder: 'z.B. Fehlende Passwortrichtlinie' },
      { key: 'description', label: 'Beschreibung',           placeholder: 'Was wurde festgestellt?', multiline: true },
      { key: 'deadline',    label: 'Deadline (optional)',     placeholder: 'JJJJ-MM-TT',              optional: true },
    ],
  },
  {
    id: 'maturity',
    label: 'Reifegrad-Analyse',
    icon: '📊',
    endpoint: '/api/ai/maturity-analysis',
    fields: [
      { key: 'control_id',    label: 'Anforderungs-ID', placeholder: 'z.B. PERS.3.1' },
      { key: 'control_title', label: 'Titel',           placeholder: 'z.B. Sicherheitsschulung' },
    ],
  },
  {
    id: 'map2023',
    label: 'Mapping auf GS 2023',
    icon: '🗺️',
    endpoint: '/api/ai/map-edition-2023',
    fields: [
      { key: 'control_id',    label: 'Anforderungs-ID', placeholder: 'z.B. PERS.3.1' },
      { key: 'control_title', label: 'Titel',           placeholder: 'z.B. Sicherheitsschulung' },
    ],
  },
]

const activeTab  = ref(TABS[0].id)
const inputs     = ref({})    // { [tabId]: { [fieldKey]: string } }
const responses  = ref({})    // { [tabId]: { text, cached, provider, model } }
const loading    = ref({})    // { [tabId]: bool }
const errors     = ref({})    // { [tabId]: string }

// ─── Control selector ───────────────────────────────────────────
const domains           = ref([])
const selectorDomain    = ref('')
const domainControls    = ref([])
const selectorControl   = ref('')   // currently applied control_id_str
const loadingControls   = ref(false)
const controlQuery      = ref('')   // combobox input text
const controlDropOpen   = ref(false)
const controlHighlight  = ref(-1)

const controlSuggestions = computed(() => {
  const q = controlQuery.value.trim().toLowerCase()
  if (!q) return domainControls.value.slice(0, 8)
  return domainControls.value
    .filter(c => c.control_id_str.toLowerCase().includes(q) || c.title.toLowerCase().includes(q))
    .slice(0, 10)
})

function selectSuggestion(c) {
  selectorControl.value = c.control_id_str
  controlQuery.value    = `${c.control_id_str} – ${c.title}`
  controlDropOpen.value = false
  controlHighlight.value = -1
}

function onControlInput() {
  selectorControl.value  = ''
  controlDropOpen.value  = true
  controlHighlight.value = -1
}

function onControlKeydown(e) {
  if (!controlDropOpen.value) { controlDropOpen.value = true; return }
  const len = controlSuggestions.value.length
  if (e.key === 'ArrowDown') { e.preventDefault(); controlHighlight.value = (controlHighlight.value + 1) % len }
  else if (e.key === 'ArrowUp') { e.preventDefault(); controlHighlight.value = (controlHighlight.value - 1 + len) % len }
  else if (e.key === 'Enter' && controlHighlight.value >= 0) { e.preventDefault(); selectSuggestion(controlSuggestions.value[controlHighlight.value]) }
  else if (e.key === 'Escape') { controlDropOpen.value = false }
}

function onControlBlur() {
  setTimeout(() => { controlDropOpen.value = false }, 150)
}

onBeforeUnmount(() => { controlDropOpen.value = false })

const CONTROL_TABS = new Set(['explain', 'suggest', 'risk', 'maturity', 'map2023'])
const activeTabHasSelector = computed(() => CONTROL_TABS.has(activeTab.value))

onMounted(async () => {
  const { execute } = useApi('/api/domains')
  const res = await execute()
  if (res?.success) domains.value = res.data.domains ?? []
})

watch(selectorDomain, async (id) => {
  selectorControl.value  = ''
  controlQuery.value     = ''
  controlDropOpen.value  = false
  domainControls.value   = []
  if (!id) return
  loadingControls.value = true
  const all = []
  let page = 1
  while (true) {
    const { execute } = useApi(`/api/domains/${id}/scoped-controls?per_page=100&page=${page}`)
    const res = await execute()
    if (!res?.success) break
    all.push(...(res.data.items ?? []))
    if (page >= (res.data.meta?.last_page ?? 1)) break
    page++
  }
  domainControls.value  = all
  loadingControls.value = false
})

function applyControlSelector() {
  const ctrl = domainControls.value.find(c => c.control_id_str === selectorControl.value)
  if (!ctrl) return
  const tab    = activeTab.value
  const tabObj = TABS.find(t => t.id === tab)
  if (!inputs.value[tab]) inputs.value[tab] = {}
  inputs.value[tab].control_id    = ctrl.control_id_str
  inputs.value[tab].control_title = ctrl.title
  if (tabObj?.fields.some(f => f.key === 'description')) {
    inputs.value[tab].description = ctrl.description ?? ''
  }
}

function tabInputs(tabId) {
  if (!inputs.value[tabId]) inputs.value[tabId] = {}
  return inputs.value[tabId]
}

function activeTabObj() {
  return TABS.find(t => t.id === activeTab.value) ?? TABS[0]
}

async function submit() {
  const tab = activeTabObj()
  const body = { ...tabInputs(tab.id) }

  // Strip empty optional fields
  tab.fields.filter(f => f.optional).forEach(f => {
    if (!body[f.key]) delete body[f.key]
  })

  loading.value[tab.id] = true
  errors.value[tab.id]  = null

  try {
    const res = await api.post(tab.endpoint, body)
    if (res?.success) {
      responses.value[tab.id] = res.data
    } else {
      errors.value[tab.id] = res?.error ?? 'Unbekannter Fehler'
    }
  } catch (e) {
    errors.value[tab.id] = e?.message ?? 'Netzwerkfehler'
  } finally {
    loading.value[tab.id] = false
  }
}
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold text-gray-900 mb-1">KI-Assistent</h1>
    <p class="text-sm text-gray-500 mb-6">
      Lassen Sie sich von der KI bei der Umsetzung von Grundschutz++ unterstützen.
    </p>

    <!-- No-permission banner -->
    <div
      v-if="!canUse"
      class="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-xl p-4 text-sm"
    >
      Der KI-Assistent steht für Ihre Rolle nicht zur Verfügung.
    </div>

    <template v-else>
      <!-- Tab bar -->
      <div class="flex gap-1 mb-6 overflow-x-auto pb-1 border-b border-gray-200">
        <button
          v-for="tab in TABS"
          :key="tab.id"
          class="shrink-0 flex items-center gap-1.5 px-4 py-2 text-sm rounded-t-lg font-medium transition-colors whitespace-nowrap"
          :class="activeTab === tab.id
            ? 'bg-primary-600 text-white'
            : 'text-gray-500 hover:text-gray-800 hover:bg-gray-100'"
          @click="activeTab = tab.id"
        >
          <span>{{ tab.icon }}</span>
          {{ tab.label }}
        </button>
      </div>

      <!-- Active tab panel -->
      <template v-for="tab in TABS" :key="tab.id">
      <div
        v-if="activeTab === tab.id"
        class="max-w-3xl"
      >
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 space-y-4">

          <!-- Control quick-select (only for control-based tabs) -->
          <div v-if="activeTabHasSelector && domains.length > 0"
               class="bg-gray-50 border border-gray-200 rounded-xl p-4 space-y-3">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Schnellauswahl aus Informationsverbund</p>
            <div class="flex gap-2 flex-wrap">
              <select v-model="selectorDomain"
                      class="flex-1 min-w-0 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary-500 focus:outline-none bg-white">
                <option value="">Verbund wählen …</option>
                <option v-for="d in domains" :key="d.id" :value="String(d.id)">{{ d.name }}</option>
              </select>
              <div class="relative flex-1 min-w-0">
                <input
                  v-model="controlQuery"
                  type="text"
                  :disabled="!selectorDomain || loadingControls"
                  :placeholder="loadingControls ? 'Lade …' : 'Anforderung suchen …'"
                  class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary-500 focus:outline-none bg-white disabled:opacity-50"
                  @input="onControlInput"
                  @focus="controlDropOpen = true"
                  @blur="onControlBlur"
                  @keydown="onControlKeydown"
                  autocomplete="off"
                />
                <ul v-if="controlDropOpen && controlSuggestions.length"
                    class="absolute z-50 left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-64 overflow-y-auto text-sm">
                  <li v-for="(c, i) in controlSuggestions" :key="c.control_id_str"
                      @mousedown.prevent="selectSuggestion(c)"
                      class="px-3 py-2 cursor-pointer"
                      :class="i === controlHighlight ? 'bg-primary-50 text-primary-700' : 'hover:bg-gray-50'">
                    <span class="font-mono text-xs font-semibold text-primary-700 mr-2">{{ c.control_id_str }}</span>{{ c.title }}
                  </li>
                </ul>
                <p v-else-if="controlDropOpen && controlQuery && !loadingControls"
                   class="absolute z-50 left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-sm px-3 py-2 text-sm text-gray-400">
                  Keine Treffer
                </p>
              </div>
              <button :disabled="!selectorControl"
                      @click="applyControlSelector"
                      class="shrink-0 px-4 py-2 text-sm font-medium bg-primary-600 text-white rounded-lg hover:bg-primary-700 disabled:opacity-40 transition-colors">
                Felder befüllen
              </button>
            </div>
          </div>

          <!-- Input fields -->
          <div
            v-for="field in tab.fields"
            :key="field.key"
          >
            <label class="block text-sm font-medium text-gray-700 mb-1">
              {{ field.label }}
              <span v-if="field.optional" class="text-xs text-gray-400 font-normal">(optional)</span>
            </label>
            <textarea
              v-if="field.multiline"
              v-model="tabInputs(tab.id)[field.key]"
              :placeholder="field.placeholder"
              rows="4"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none resize-y"
            />
            <input
              v-else
              v-model="tabInputs(tab.id)[field.key]"
              :placeholder="field.placeholder"
              type="text"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
            />
          </div>

          <!-- Submit button -->
          <button
            class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-700 text-white px-5 py-2.5 rounded-lg font-medium text-sm transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="loading[tab.id]"
            @click="submit"
          >
            <span v-if="loading[tab.id]" class="animate-spin inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full" />
            <span v-else>🤖</span>
            KI fragen
          </button>

          <!-- Error -->
          <div
            v-if="errors[tab.id]"
            class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 text-sm"
          >
            {{ errors[tab.id] }}
          </div>

          <!-- Response -->
          <div v-if="responses[tab.id]" class="mt-2">
            <div class="flex items-center gap-2 mb-2">
              <span class="text-xs font-medium text-gray-500">Antwort</span>
              <span
                v-if="responses[tab.id].cached"
                class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded font-medium"
              >Aus Cache</span>
              <span
                v-else-if="responses[tab.id].provider"
                class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded"
              >{{ responses[tab.id].provider }} / {{ responses[tab.id].model }}</span>
            </div>
            <pre class="bg-gray-50 border border-gray-200 rounded-xl p-4 text-sm text-gray-800 whitespace-pre-wrap font-sans leading-relaxed">{{ responses[tab.id].response }}</pre>
          </div>

        </div>
      </div>
      </template>
    </template>
  </div>
</template>
