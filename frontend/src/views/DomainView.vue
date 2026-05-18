<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDomain } from '@/composables/useDomain.js'
import { useCatalog } from '@/composables/useCatalog.js'
import { useAuthStore } from '@/stores/useAuthStore.js'
import GlossaryTooltip from '@/components/GlossaryTooltip.vue'

const auth   = useAuthStore()
const route  = useRoute()
const router = useRouter()
const {
  domains, domain, assets, processes, scopedControls, controlsMeta,
  loading, error,
  loadDomains, createDomain, loadDomain,
  loadAssets, createAsset, updateAsset, deleteAsset,
  loadProcesses, createProcess, updateProcess, deleteProcess,
  loadScopedControls, applyTailoring, generateProfile,
} = useDomain()

const { catalogs, loadCatalogs } = useCatalog()

const canWrite  = computed(() => ['admin', 'isb', 'fachverantwortlich'].includes(auth.role))
const canManage = computed(() => ['admin', 'isb'].includes(auth.role))

// ── Domain selection ──────────────────────────────────────────────────────
const selectedDomainId = ref(null)
const activeTab        = ref('overview')   // overview | assets | processes | controls

async function selectDomain(id) {
  selectedDomainId.value = id
  activeTab.value = 'overview'
  // Update URL so the sidebar can show context-sensitive links (e.g. Grundschutzcheck)
  if (route.path !== `/verbund/${id}`) {
    router.replace(`/verbund/${id}`)
  }
  await Promise.all([loadDomain(id), loadAssets(id), loadProcesses(id)])
  await loadScopedControls(id)
}

// ── Controls search + pagination ─────────────────────────────────────────
const controlSearch = ref('')
const controlPage   = ref(1)
let searchTimer = null
watch(controlSearch, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    controlPage.value = 1
    if (selectedDomainId.value) loadScopedControls(selectedDomainId.value, { search: controlSearch.value, page: 1 })
  }, 300)
})
function controlsGoTo(page) {
  controlPage.value = page
  loadScopedControls(selectedDomainId.value, { search: controlSearch.value, page })
}

// ── Parameter helpers ─────────────────────────────────────────────────────

function resolveParams(text, parametersJson) {
  if (!text) return text
  const values = JSON.parse(parametersJson || '{}')
  return text.replace(/\{\{\s*insert:\s*param,\s*([\w.\-]+)\s*\}\}/g, (_, id) => {
    return values[id] ?? `[${id}]`
  })
}

// ── Tailoring panel ───────────────────────────────────────────────────────
const tailoringControl = ref(null)
const tailoringForm    = ref({ parameters: {}, prefix: '', suffix: '', excluded: false, exclusion_reason: '' })
const tailoringError   = ref(null)
const tailoringSaving  = ref(false)

function openTailoring(control) {
  const savedParams = JSON.parse(control.parameters_json || '{}')
  const labels      = JSON.parse(control.param_labels_json || '{}')
  const tailoring   = JSON.parse(control.tailoring_json  || '{}')

  // Seed every param found in description (from labels), then overlay saved values
  const allParamIds = Object.keys(labels)
  const params = Object.fromEntries(allParamIds.map(id => [id, savedParams[id] ?? '']))

  tailoringControl.value = control
  tailoringForm.value = {
    parameters:       params,
    prefix:           tailoring.prefix           ?? '',
    suffix:           tailoring.suffix           ?? '',
    excluded:         tailoring.excluded         ?? false,
    exclusion_reason: tailoring.exclusion_reason ?? '',
  }
  tailoringError.value = null
}

async function saveTailoring() {
  tailoringSaving.value = true
  tailoringError.value  = null
  const res = await applyTailoring(selectedDomainId.value, {
    control_id_str:  tailoringControl.value.control_id_str,
    ...tailoringForm.value,
  })
  tailoringSaving.value = false
  if (res?.success) tailoringControl.value = null
  else tailoringError.value = res?.error ?? 'Fehler beim Speichern'
}

// ── Profile export ────────────────────────────────────────────────────────
const exportingProfile = ref(false)

async function exportProfile() {
  exportingProfile.value = true
  const res = await generateProfile(selectedDomainId.value)
  exportingProfile.value = false
  if (res?.success) {
    const blob = new Blob([JSON.stringify(res.data.profile, null, 2)], { type: 'application/json' })
    const url  = URL.createObjectURL(blob)
    const a    = document.createElement('a')
    a.href     = url
    a.download = `${domain.value?.name ?? 'Verbund'}_Profile.json`
    a.click()
    URL.revokeObjectURL(url)
  }
}

// ── Inline asset form ─────────────────────────────────────────────────────
const showAssetForm  = ref(false)
const editingAssetId = ref(null)
const assetForm      = ref({ name: '', asset_type: '', description: '', protection_need_c: 'normal', protection_need_i: 'normal', protection_need_a: 'normal' })
const assetError     = ref(null)
const assetSaving    = ref(false)

function startEditAsset(a) {
  editingAssetId.value = a.id
  assetForm.value = { name: a.name, asset_type: a.asset_type ?? '', description: a.description ?? '', protection_need_c: a.protection_need_c, protection_need_i: a.protection_need_i, protection_need_a: a.protection_need_a }
  assetError.value = null
  showAssetForm.value = true
}

async function submitAsset() {
  assetSaving.value = true
  assetError.value  = null
  const res = editingAssetId.value
    ? await updateAsset(selectedDomainId.value, editingAssetId.value, assetForm.value)
    : await createAsset(selectedDomainId.value, assetForm.value)
  assetSaving.value = false
  if (res?.success) {
    showAssetForm.value = false
    editingAssetId.value = null
    assetForm.value = { name: '', asset_type: '', description: '', protection_need_c: 'normal', protection_need_i: 'normal', protection_need_a: 'normal' }
  } else {
    assetError.value = res?.error ?? 'Fehler beim Speichern'
  }
}

async function confirmDeleteAsset(id) {
  if (!window.confirm('Zielobjekt wirklich löschen?')) return
  await deleteAsset(selectedDomainId.value, id)
}

// ── Inline process form ───────────────────────────────────────────────────
const showProcessForm   = ref(false)
const editingProcessId  = ref(null)
const processForm       = ref({ name: '', description: '', criticality: 'medium' })
const processError      = ref(null)
const processSaving     = ref(false)

function startEditProcess(p) {
  editingProcessId.value = p.id
  processForm.value = { name: p.name, description: p.description ?? '', criticality: p.criticality }
  processError.value = null
  showProcessForm.value = true
}

async function submitProcess() {
  processSaving.value = true
  processError.value  = null
  const res = editingProcessId.value
    ? await updateProcess(selectedDomainId.value, editingProcessId.value, processForm.value)
    : await createProcess(selectedDomainId.value, processForm.value)
  processSaving.value = false
  if (res?.success) {
    showProcessForm.value = false
    editingProcessId.value = null
    processForm.value = { name: '', description: '', criticality: 'medium' }
  } else {
    processError.value = res?.error ?? 'Fehler beim Speichern'
  }
}

async function confirmDeleteProcess(id) {
  if (!window.confirm('Geschäftsprozess wirklich löschen?')) return
  await deleteProcess(selectedDomainId.value, id)
}

// ── 5-step wizard ─────────────────────────────────────────────────────────
const showWizard  = ref(false)
const wizardStep  = ref(1)
const wizardError = ref(null)
const wizardBusy  = ref(false)

const wizardSteps = ['Metadaten', 'Geschäftsprozesse', 'Zielobjekte', 'ISMS-Typ & Katalog', 'Bestätigung']

// Step 1
const wMeta = ref({ name: '', description: '', branche: '', zweck: '' })
// Step 2 — processes
const wProcesses = ref([])
const wProcForm  = ref({ name: '', criticality: 'medium' })
function addWizardProcess() {
  if (!wProcForm.value.name.trim()) return
  wProcesses.value.push({ ...wProcForm.value })
  wProcForm.value = { name: '', criticality: 'medium' }
}
function removeWizardProcess(i) { wProcesses.value.splice(i, 1) }

// Step 3 — assets
const wAssets   = ref([])
const wAssetForm = ref({ name: '', asset_type: 'it-systeme', protection_need_c: 'normal', protection_need_i: 'normal', protection_need_a: 'normal' })
function addWizardAsset() {
  if (!wAssetForm.value.name.trim()) return
  wAssets.value.push({ ...wAssetForm.value })
  wAssetForm.value = { name: '', asset_type: 'it-systeme', protection_need_c: 'normal', protection_need_i: 'normal', protection_need_a: 'normal' }
}
function removeWizardAsset(i) { wAssets.value.splice(i, 1) }

// Step 4
const wIsmsType  = ref('standard')
const wCatalogId = ref(null)

// Step can proceed?
const canNext = computed(() => {
  if (wizardStep.value === 1) return wMeta.value.name.trim() !== ''
  if (wizardStep.value === 4) return wCatalogId.value !== null
  return true
})

function openWizard() {
  wizardStep.value = 1
  wizardError.value = null
  wMeta.value = { name: '', description: '', branche: '', zweck: '' }
  wProcesses.value = []
  wAssets.value = []
  wIsmsType.value = 'standard'
  wCatalogId.value = catalogs.value[0]?.id ?? null
  showWizard.value = true
}

async function submitWizard() {
  wizardBusy.value  = true
  wizardError.value = null

  try {
    const res = await createDomain({
      ...wMeta.value,
      isms_type:  wIsmsType.value,
      catalog_id: wCatalogId.value,
    })

    if (!res?.success) {
      wizardError.value = res?.error ?? 'Fehler beim Anlegen'
      return
    }

    const newId = res.data.domain.id

    await Promise.all([
      ...wProcesses.value.map(p => createProcess(newId, p)),
      ...wAssets.value.map(a => createAsset(newId, a)),
    ])

    showWizard.value = false
    await selectDomain(newId)
  } catch {
    wizardError.value = 'Unerwarteter Fehler. Bitte erneut versuchen.'
  } finally {
    wizardBusy.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadDomains(), loadCatalogs()])
  // Auto-select domain when navigated directly to /verbund/:id
  const idParam = route.params.id
  if (idParam) {
    await selectDomain(Number(idParam))
  }
})
</script>

<template>
  <div class="h-full flex flex-col">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 mb-1">
          <GlossaryTooltip term="Informationsverbund" explanation="Der Geltungsbereich Ihres ISMS: alle Assets, Prozesse und Systeme, für die die Sicherheitsanforderungen gelten." />
        </h1>
        <p class="text-sm text-gray-500">Modellierung und Tailoring von ISMS-Geltungsbereichen</p>
      </div>
      <button v-if="canWrite" @click="openWizard"
        class="px-4 py-2 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 transition-colors">
        + Neuer Informationsverbund
      </button>
    </div>

    <!-- Body -->
    <div class="flex gap-6 flex-1 min-h-0">

      <!-- ── Sidebar ────────────────────────────────────────────────── -->
      <div class="w-72 flex-shrink-0 bg-white rounded-xl border border-gray-200 shadow-sm overflow-y-auto">
        <div class="p-4 border-b border-gray-100">
          <h2 class="text-sm font-semibold text-gray-700">Meine Verbünde</h2>
        </div>
        <div v-if="loading && domains.length === 0" class="p-4 text-sm text-gray-400">Laden …</div>
        <div v-else-if="domains.length === 0" class="p-6 text-center">
          <p class="text-sm text-gray-500 mb-3">Noch kein Informationsverbund angelegt.</p>
          <button v-if="canWrite" @click="openWizard" class="text-sm text-primary-600 hover:underline">Jetzt anlegen</button>
        </div>
        <ul v-else>
          <li v-for="d in domains" :key="d.id" @click="selectDomain(d.id)"
            class="px-4 py-3 cursor-pointer border-b border-gray-50 hover:bg-gray-50 transition-colors"
            :class="selectedDomainId === d.id ? 'bg-primary-50 border-l-2 border-l-primary-600' : ''">
            <p class="text-sm font-medium text-gray-900 truncate">{{ d.name }}</p>
            <div class="flex items-center gap-2 mt-0.5">
              <span class="text-xs px-1.5 py-0.5 rounded font-medium"
                :class="d.isms_type === 'enhanced' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'">
                {{ d.isms_type }}
              </span>
              <span class="text-xs text-gray-400">{{ d.control_count }} Anforderungen</span>
            </div>
          </li>
        </ul>
      </div>

      <!-- ── Main ───────────────────────────────────────────────────── -->
      <div class="flex-1 flex flex-col min-h-0">

        <!-- No domain selected -->
        <div v-if="!selectedDomainId"
          class="flex-1 flex items-center justify-center bg-white rounded-xl border border-gray-200 shadow-sm">
          <p class="text-sm text-gray-400">Wählen Sie links einen Informationsverbund aus.</p>
        </div>

        <!-- Domain detail -->
        <div v-else class="flex-1 flex flex-col min-h-0">

          <!-- Tabs -->
          <div class="flex gap-1 mb-4">
            <button v-for="tab in ['overview','assets','processes','controls']" :key="tab"
              @click="activeTab = tab"
              class="px-4 py-2 text-sm font-medium rounded-lg transition-colors"
              :class="activeTab === tab ? 'bg-primary-600 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50'">
              {{ { overview: 'Übersicht', assets: 'Zielobjekte', processes: 'Geschäftsprozesse', controls: 'Anforderungen' }[tab] }}
            </button>
          </div>

          <!-- ── Übersicht ─────────────────────────────────────────── -->
          <div v-if="activeTab === 'overview' && domain"
            class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-4">
            <div class="flex items-start justify-between">
              <div>
                <h2 class="text-xl font-bold text-gray-900">{{ domain.name }}</h2>
                <p v-if="domain.description" class="text-sm text-gray-500 mt-1">{{ domain.description }}</p>
              </div>
              <div class="flex items-center gap-2">
                <span class="text-xs px-2 py-1 rounded-full font-medium"
                  :class="domain.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'">
                  {{ domain.status }}
                </span>
                <button v-if="canManage" @click="exportProfile" :disabled="exportingProfile"
                  class="px-3 py-1.5 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 transition-colors">
                  {{ exportingProfile ? 'Exportiere …' : 'OSCAL Profil exportieren' }}
                </button>
              </div>
            </div>
            <div class="grid grid-cols-3 gap-4">
              <div class="bg-gray-50 rounded-lg p-4">
                <p class="text-xs text-gray-500 mb-1">ISMS-Typ</p>
                <p class="font-semibold text-gray-900 capitalize">{{ domain.isms_type }}</p>
              </div>
              <div class="bg-gray-50 rounded-lg p-4">
                <p class="text-xs text-gray-500 mb-1">Anforderungen im Scope</p>
                <p class="text-2xl font-bold text-primary-700">{{ domain.control_count }}</p>
              </div>
              <div class="bg-gray-50 rounded-lg p-4">
                <p class="text-xs text-gray-500 mb-1">Angelegt am</p>
                <p class="font-semibold text-gray-900">{{ new Date(domain.created_at).toLocaleDateString('de-DE') }}</p>
              </div>
            </div>
          </div>

          <!-- ── Zielobjekte ─────────────────────────────────────────── -->
          <div v-else-if="activeTab === 'assets'"
            class="flex-1 flex flex-col bg-white rounded-xl border border-gray-200 shadow-sm min-h-0">
            <div class="flex items-center justify-between p-4 border-b border-gray-100">
              <h3 class="text-sm font-semibold text-gray-700">Zielobjekte (Assets)</h3>
              <button v-if="canWrite" @click="showAssetForm = !showAssetForm"
                class="text-sm text-primary-600 hover:underline">+ Asset anlegen</button>
            </div>

            <!-- Inline form -->
            <div v-if="showAssetForm" class="p-4 bg-gray-50 border-b border-gray-100 space-y-3">
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Name *</label>
                  <input v-model="assetForm.name" type="text" placeholder="z.B. Kundendatenbank"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Typ</label>
                  <select v-model="assetForm.asset_type"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <option value="it-systeme">IT-Systeme</option>
                    <option value="netze">Netze & Kommunikation</option>
                    <option value="raeume">Räume & Infrastruktur</option>
                    <option value="personal">Personal</option>
                    <option value="sonstiges">Sonstiges</option>
                  </select>
                </div>
              </div>
              <div class="grid grid-cols-3 gap-3">
                <div v-for="(label, field) in { protection_need_c: 'Vertraulichkeit', protection_need_i: 'Integrität', protection_need_a: 'Verfügbarkeit' }" :key="field">
                  <label class="block text-xs font-medium text-gray-600 mb-1">{{ label }}</label>
                  <select v-model="assetForm[field]"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-1.5">
                    <option value="normal">Normal</option>
                    <option value="high">Hoch</option>
                  </select>
                </div>
              </div>
              <div v-if="assetError" class="text-xs text-red-600">{{ assetError }}</div>
              <div class="flex gap-2">
                <button @click="submitAsset" :disabled="assetSaving || !assetForm.name.trim()"
                  class="px-3 py-1.5 bg-primary-600 text-white text-sm rounded-lg hover:bg-primary-700 disabled:opacity-50">
                  {{ assetSaving ? 'Speichern …' : (editingAssetId ? 'Speichern' : 'Anlegen') }}
                </button>
                <button @click="showAssetForm = false; editingAssetId = null" class="px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900">Abbrechen</button>
              </div>
            </div>

            <div class="flex-1 overflow-y-auto">
              <div v-if="assets.length === 0" class="p-6 text-center text-sm text-gray-400">Keine Assets vorhanden.</div>
              <table v-else class="w-full text-sm">
                <thead class="bg-gray-50 sticky top-0">
                  <tr>
                    <th class="text-left px-4 py-2 text-xs font-semibold text-gray-500">Name</th>
                    <th class="text-left px-4 py-2 text-xs font-semibold text-gray-500">Typ</th>
                    <th class="text-center px-4 py-2 text-xs font-semibold text-gray-500">C</th>
                    <th class="text-center px-4 py-2 text-xs font-semibold text-gray-500">I</th>
                    <th class="text-center px-4 py-2 text-xs font-semibold text-gray-500">A</th>
                    <th v-if="canWrite" class="text-right px-4 py-2 text-xs font-semibold text-gray-500"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="a in assets" :key="a.id" class="border-t border-gray-50 hover:bg-gray-50">
                    <td class="px-4 py-2.5 font-medium text-gray-900">{{ a.name }}</td>
                    <td class="px-4 py-2.5 text-gray-500 text-xs">{{ a.asset_type ?? '—' }}</td>
                    <td v-for="field in ['protection_need_c','protection_need_i','protection_need_a']" :key="field" class="px-4 py-2.5 text-center">
                      <span class="inline-block text-xs px-1.5 py-0.5 rounded font-medium"
                        :class="a[field] === 'high' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-500'">
                        {{ a[field] === 'high' ? 'Hoch' : 'Normal' }}
                      </span>
                    </td>
                    <td v-if="canWrite" class="px-4 py-2.5 text-right whitespace-nowrap">
                      <button @click="startEditAsset(a)" class="text-xs text-primary-600 hover:underline mr-2">Bearbeiten</button>
                      <button @click="confirmDeleteAsset(a.id)" class="text-xs text-red-500 hover:underline">× Löschen</button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- ── Geschäftsprozesse ──────────────────────────────────── -->
          <div v-else-if="activeTab === 'processes'"
            class="flex-1 flex flex-col bg-white rounded-xl border border-gray-200 shadow-sm min-h-0">
            <div class="flex items-center justify-between p-4 border-b border-gray-100">
              <h3 class="text-sm font-semibold text-gray-700">Geschäftsprozesse</h3>
              <button v-if="canWrite" @click="showProcessForm = !showProcessForm"
                class="text-sm text-primary-600 hover:underline">+ Prozess anlegen</button>
            </div>

            <!-- Inline form -->
            <div v-if="showProcessForm" class="p-4 bg-gray-50 border-b border-gray-100 space-y-3">
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Name *</label>
                  <input v-model="processForm.name" type="text" placeholder="z.B. Rechnungsstellung"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Kritikalität</label>
                  <select v-model="processForm.criticality"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-1.5">
                    <option value="low">Niedrig</option>
                    <option value="medium">Mittel</option>
                    <option value="high">Hoch</option>
                    <option value="very_high">Sehr hoch</option>
                  </select>
                </div>
              </div>
              <div v-if="processError" class="text-xs text-red-600">{{ processError }}</div>
              <div class="flex gap-2">
                <button @click="submitProcess" :disabled="processSaving || !processForm.name.trim()"
                  class="px-3 py-1.5 bg-primary-600 text-white text-sm rounded-lg hover:bg-primary-700 disabled:opacity-50">
                  {{ processSaving ? 'Speichern …' : (editingProcessId ? 'Speichern' : 'Anlegen') }}
                </button>
                <button @click="showProcessForm = false; editingProcessId = null" class="px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900">Abbrechen</button>
              </div>
            </div>

            <div class="flex-1 overflow-y-auto">
              <div v-if="processes.length === 0" class="p-6 text-center text-sm text-gray-400">Keine Prozesse vorhanden.</div>
              <table v-else class="w-full text-sm">
                <thead class="bg-gray-50 sticky top-0">
                  <tr>
                    <th class="text-left px-4 py-2 text-xs font-semibold text-gray-500">Name</th>
                    <th class="text-left px-4 py-2 text-xs font-semibold text-gray-500">Kritikalität</th>
                    <th class="text-left px-4 py-2 text-xs font-semibold text-gray-500">Verknüpfte Assets</th>
                    <th v-if="canWrite" class="text-right px-4 py-2 text-xs font-semibold text-gray-500"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="p in processes" :key="p.id" class="border-t border-gray-50 hover:bg-gray-50">
                    <td class="px-4 py-2.5 font-medium text-gray-900">{{ p.name }}</td>
                    <td class="px-4 py-2.5">
                      <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                        :class="{
                          'bg-red-100 text-red-700':    p.criticality === 'very_high',
                          'bg-orange-100 text-orange-700': p.criticality === 'high',
                          'bg-yellow-100 text-yellow-700': p.criticality === 'medium',
                          'bg-gray-100 text-gray-600':  p.criticality === 'low',
                        }">
                        {{ { low: 'Niedrig', medium: 'Mittel', high: 'Hoch', very_high: 'Sehr hoch' }[p.criticality] }}
                      </span>
                    </td>
                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ p.linked_assets || '—' }}</td>
                    <td v-if="canWrite" class="px-4 py-2.5 text-right whitespace-nowrap">
                      <button @click="startEditProcess(p)" class="text-xs text-primary-600 hover:underline mr-2">Bearbeiten</button>
                      <button @click="confirmDeleteProcess(p.id)" class="text-xs text-red-500 hover:underline">× Löschen</button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- ── Anforderungen / Tailoring ──────────────────────────── -->
          <div v-else-if="activeTab === 'controls'" class="flex-1 flex gap-4 min-h-0">

            <!-- Controls list -->
            <div class="flex-1 flex flex-col bg-white rounded-xl border border-gray-200 shadow-sm min-h-0">
              <div class="p-4 border-b border-gray-100">
                <input v-model="controlSearch" type="search" placeholder="Anforderungen suchen …"
                  class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
              </div>
              <div v-if="loading" class="p-4 text-sm text-gray-400">Laden …</div>
              <div v-else-if="scopedControls.length === 0" class="p-6 text-center text-sm text-gray-400">Keine Anforderungen im Scope.</div>
              <div v-else class="flex-1 overflow-y-auto">
                <table class="w-full text-sm">
                  <thead class="bg-gray-50 sticky top-0">
                    <tr>
                      <th class="text-left px-4 py-2 text-xs font-semibold text-gray-500 w-24">ID</th>
                      <th class="text-left px-4 py-2 text-xs font-semibold text-gray-500">Titel</th>
                      <th class="text-center px-4 py-2 text-xs font-semibold text-gray-500 w-20">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="c in scopedControls" :key="c.id"
                      @click="openTailoring(c)"
                      class="border-t border-gray-50 hover:bg-gray-50 cursor-pointer transition-colors"
                      :class="tailoringControl?.id === c.id ? 'bg-primary-50' : ''">
                      <td class="px-4 py-2.5 font-mono text-xs text-primary-700 font-medium">{{ c.control_id_str }}</td>
                      <td class="px-4 py-2.5 text-gray-900">{{ c.title }}</td>
                      <td class="px-4 py-2.5 text-center">
                        <span v-if="JSON.parse(c.tailoring_json || '{}').excluded" class="text-xs px-1.5 py-0.5 bg-red-100 text-red-600 rounded">Ausgeschlossen</span>
                        <span v-else-if="Object.keys(JSON.parse(c.parameters_json || '{}')).length > 0" class="text-xs px-1.5 py-0.5 bg-blue-100 text-blue-600 rounded">Angepasst</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-if="controlsMeta" class="px-4 py-2 border-t border-gray-100 flex items-center justify-between text-xs text-gray-500">
                <span>{{ controlsMeta.total }} Anforderungen</span>
                <div v-if="controlsMeta.last_page > 1" class="flex items-center gap-1">
                  <button @click="controlsGoTo(controlPage - 1)" :disabled="controlPage <= 1"
                    class="px-2 py-1 rounded hover:bg-gray-100 disabled:opacity-30 disabled:cursor-not-allowed">‹</button>
                  <span>{{ controlPage }} / {{ controlsMeta.last_page }}</span>
                  <button @click="controlsGoTo(controlPage + 1)" :disabled="controlPage >= controlsMeta.last_page"
                    class="px-2 py-1 rounded hover:bg-gray-100 disabled:opacity-30 disabled:cursor-not-allowed">›</button>
                </div>
              </div>
            </div>

            <!-- Tailoring panel -->
            <div v-if="tailoringControl"
              class="w-96 flex-shrink-0 bg-white rounded-xl border border-gray-200 shadow-sm flex flex-col overflow-hidden">
              <div class="flex items-start justify-between p-4 border-b border-gray-100">
                <div class="min-w-0">
                  <span class="font-mono text-xs text-primary-700 font-semibold">{{ tailoringControl.control_id_str }}</span>
                  <h3 class="font-semibold text-gray-900 mt-0.5 leading-tight text-sm">{{ tailoringControl.title }}</h3>
                </div>
                <button @click="tailoringControl = null" class="text-gray-400 hover:text-gray-600 ml-2 shrink-0">✕</button>
              </div>

              <div class="flex-1 overflow-y-auto p-4 space-y-4">

                <!-- Base requirement text -->
                <div v-if="tailoringControl.description">
                  <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Basisanforderung</p>
                  <p class="text-xs text-gray-600 leading-relaxed bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 whitespace-pre-line">{{ resolveParams(tailoringControl.description, tailoringControl.parameters_json) }}</p>
                </div>

                <!-- Parameters -->
                <div v-if="Object.keys(tailoringForm.parameters).length > 0">
                  <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Parameter</p>
                  <div v-for="(val, key) in tailoringForm.parameters" :key="key" class="mb-2">
                    <label class="block text-xs text-gray-500 mb-1">
                      {{ JSON.parse(tailoringControl.param_labels_json || '{}')[key] || key }}
                      <span class="font-mono text-gray-400 ml-1">({{ key }})</span>
                    </label>
                    <input v-model="tailoringForm.parameters[key]" type="text"
                      class="w-full text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                  </div>
                </div>

                <!-- Prefix / Suffix with live preview -->
                <div>
                  <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Ergänzungen</p>
                  <div class="space-y-3">
                    <div>
                      <label class="block text-xs font-medium text-gray-600 mb-1">
                        Präambel
                        <span class="font-normal text-gray-400 ml-1">— wird dem Anforderungstext vorangestellt</span>
                      </label>
                      <textarea v-model="tailoringForm.prefix" rows="2"
                        placeholder="z.B. Zusätzlich zu den Basisanforderungen gilt: …"
                        class="w-full text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-primary-500 resize-none" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-gray-600 mb-1">
                        Ergänzung
                        <span class="font-normal text-gray-400 ml-1">— wird dem Anforderungstext nachgestellt</span>
                      </label>
                      <textarea v-model="tailoringForm.suffix" rows="2"
                        placeholder="z.B. Dies gilt insbesondere für …"
                        class="w-full text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-primary-500 resize-none" />
                    </div>

                    <!-- Live preview -->
                    <div v-if="tailoringForm.prefix || tailoringForm.suffix">
                      <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Vorschau</p>
                      <div class="text-xs text-gray-700 leading-relaxed bg-blue-50 border border-blue-100 rounded-lg px-3 py-2.5 space-y-1.5">
                        <p v-if="tailoringForm.prefix" class="text-blue-700 italic">{{ tailoringForm.prefix }}</p>
                        <p v-if="tailoringControl.description" class="text-gray-600">{{ resolveParams(tailoringControl.description, JSON.stringify(tailoringForm.parameters)) }}</p>
                        <p v-if="tailoringForm.suffix" class="text-blue-700 italic">{{ tailoringForm.suffix }}</p>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Exclusion -->
                <div>
                  <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" v-model="tailoringForm.excluded" class="rounded" />
                    <span class="text-sm text-gray-700 font-medium">Anforderung ausschließen</span>
                  </label>
                  <p class="text-xs text-gray-400 mt-1 ml-5">Ausgeschlossene Anforderungen erscheinen nicht im Grundschutzcheck.</p>
                  <div v-if="tailoringForm.excluded" class="mt-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Begründung <span class="text-red-500">*</span></label>
                    <textarea v-model="tailoringForm.exclusion_reason" rows="3"
                      placeholder="Warum ist diese Anforderung für Ihren Informationsverbund nicht anwendbar?"
                      class="w-full text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-primary-500 resize-none" />
                  </div>
                </div>

                <div v-if="tailoringError" class="text-xs text-red-600 bg-red-50 rounded p-2">{{ tailoringError }}</div>
              </div>

              <div class="p-4 border-t border-gray-100">
                <button v-if="canManage" @click="saveTailoring" :disabled="tailoringSaving"
                  class="w-full py-2 bg-primary-600 text-white text-sm font-medium rounded-lg hover:bg-primary-700 disabled:opacity-50 transition-colors">
                  {{ tailoringSaving ? 'Speichern …' : 'Tailoring speichern' }}
                </button>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- ── 5-Step Wizard ─────────────────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="showWizard"
        class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
        @click.self="showWizard = false">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl flex flex-col max-h-[90vh]">

          <!-- Wizard header + steps -->
          <div class="px-6 pt-5 pb-4 border-b border-gray-100">
            <div class="flex items-center justify-between mb-4">
              <h2 class="text-lg font-semibold text-gray-900">Neuer Informationsverbund</h2>
              <button @click="showWizard = false" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <!-- Step dots -->
            <div class="flex items-center gap-2">
              <template v-for="(label, i) in wizardSteps" :key="i">
                <div class="flex items-center gap-2">
                  <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition-colors"
                    :class="wizardStep === i+1 ? 'bg-primary-600 text-white' : wizardStep > i+1 ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-500'">
                    {{ wizardStep > i+1 ? '✓' : i+1 }}
                  </div>
                  <span class="text-xs text-gray-500 hidden sm:block">{{ label }}</span>
                </div>
                <div v-if="i < wizardSteps.length - 1" class="flex-1 h-px bg-gray-200 min-w-4"></div>
              </template>
            </div>
          </div>

          <!-- Step content -->
          <div class="flex-1 overflow-y-auto p-6">

            <!-- Step 1: Metadaten -->
            <div v-if="wizardStep === 1" class="space-y-4">
              <h3 class="font-semibold text-gray-900">Schritt 1: Metadaten</h3>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Name des Informationsverbunds <span class="text-red-500">*</span></label>
                <input v-model="wMeta.name" type="text" placeholder="z.B. Hauptniederlassung Berlin"
                  class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Beschreibung</label>
                <textarea v-model="wMeta.description" rows="3"
                  class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500 resize-none" />
              </div>
              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Branche</label>
                  <input v-model="wMeta.branche" type="text" placeholder="z.B. Gesundheitswesen"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Zweck der Organisation</label>
                  <input v-model="wMeta.zweck" type="text" placeholder="z.B. Patientenversorgung"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                </div>
              </div>
            </div>

            <!-- Step 2: Geschäftsprozesse -->
            <div v-if="wizardStep === 2" class="space-y-4">
              <h3 class="font-semibold text-gray-900">Schritt 2: Geschäftsprozesse</h3>
              <p class="text-sm text-gray-500">Erfassen Sie die wesentlichen Geschäftsprozesse Ihres Verbunds. Sie können auch später weitere hinzufügen.</p>
              <div class="flex gap-2">
                <input v-model="wProcForm.name" @keyup.enter="addWizardProcess" type="text" placeholder="Prozessname"
                  class="flex-1 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                <select v-model="wProcForm.criticality" class="text-sm border border-gray-300 rounded-lg px-3 py-2">
                  <option value="low">Niedrig</option>
                  <option value="medium">Mittel</option>
                  <option value="high">Hoch</option>
                  <option value="very_high">Sehr hoch</option>
                </select>
                <button @click="addWizardProcess"
                  class="px-3 py-2 bg-primary-600 text-white text-sm rounded-lg hover:bg-primary-700">+</button>
              </div>
              <ul class="space-y-1">
                <li v-for="(p, i) in wProcesses" :key="i"
                  class="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2 text-sm">
                  <span class="font-medium text-gray-900">{{ p.name }}</span>
                  <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-500">{{ p.criticality }}</span>
                    <button @click="removeWizardProcess(i)" class="text-gray-400 hover:text-red-500">✕</button>
                  </div>
                </li>
              </ul>
              <p v-if="wProcesses.length === 0" class="text-sm text-gray-400 text-center py-2">Noch keine Prozesse hinzugefügt (optional).</p>
            </div>

            <!-- Step 3: Assets -->
            <div v-if="wizardStep === 3" class="space-y-4">
              <h3 class="font-semibold text-gray-900">Schritt 3: Zielobjekte</h3>
              <p class="text-sm text-gray-500">Erfassen Sie die IT-Systeme, Netzwerke und Räume, die zum Verbund gehören.</p>
              <div class="grid grid-cols-2 gap-2">
                <input v-model="wAssetForm.name" @keyup.enter="addWizardAsset" type="text" placeholder="Asset-Name"
                  class="text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                <select v-model="wAssetForm.asset_type" class="text-sm border border-gray-300 rounded-lg px-3 py-2">
                  <option value="it-systeme">IT-Systeme</option>
                  <option value="netze">Netze</option>
                  <option value="raeume">Räume</option>
                  <option value="personal">Personal</option>
                  <option value="sonstiges">Sonstiges</option>
                </select>
              </div>
              <div class="grid grid-cols-3 gap-2">
                <div v-for="(label, field) in { protection_need_c: 'Vertraulichkeit', protection_need_i: 'Integrität', protection_need_a: 'Verfügbarkeit' }" :key="field">
                  <label class="block text-xs text-gray-500 mb-1">{{ label }}</label>
                  <select v-model="wAssetForm[field]" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-1.5">
                    <option value="normal">Normal</option>
                    <option value="high">Hoch</option>
                  </select>
                </div>
              </div>
              <button @click="addWizardAsset" :disabled="!wAssetForm.name.trim()"
                class="px-3 py-2 bg-primary-600 text-white text-sm rounded-lg hover:bg-primary-700 disabled:opacity-50">
                + Asset hinzufügen
              </button>
              <ul class="space-y-1">
                <li v-for="(a, i) in wAssets" :key="i"
                  class="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2 text-sm">
                  <span class="font-medium text-gray-900">{{ a.name }}</span>
                  <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-500">{{ a.asset_type }}</span>
                    <button @click="removeWizardAsset(i)" class="text-gray-400 hover:text-red-500">✕</button>
                  </div>
                </li>
              </ul>
              <p v-if="wAssets.length === 0" class="text-sm text-gray-400 text-center py-2">Noch keine Assets hinzugefügt (optional).</p>
            </div>

            <!-- Step 4: ISMS-Typ & Katalog -->
            <div v-if="wizardStep === 4" class="space-y-6">
              <h3 class="font-semibold text-gray-900">Schritt 4: ISMS-Typ & Katalog</h3>
              <div>
                <p class="text-sm font-medium text-gray-700 mb-3">
                  <GlossaryTooltip term="ISMS-Typ" explanation="Standard: Basis- und Standard-Anforderungen. Enhanced: zusätzlich erhöhte Anforderungen für schutzbedürftige Bereiche." />
                </p>
                <div class="grid grid-cols-2 gap-3">
                  <label class="cursor-pointer">
                    <div class="border-2 rounded-xl p-4 transition-colors"
                      :class="wIsmsType === 'standard' ? 'border-primary-600 bg-primary-50' : 'border-gray-200 hover:border-gray-300'">
                      <input type="radio" v-model="wIsmsType" value="standard" class="sr-only" />
                      <p class="font-semibold text-gray-900">Standard</p>
                      <p class="text-xs text-gray-500 mt-1">Basis- und Standard-Anforderungen</p>
                    </div>
                  </label>
                  <label class="cursor-pointer">
                    <div class="border-2 rounded-xl p-4 transition-colors"
                      :class="wIsmsType === 'enhanced' ? 'border-primary-600 bg-primary-50' : 'border-gray-200 hover:border-gray-300'">
                      <input type="radio" v-model="wIsmsType" value="enhanced" class="sr-only" />
                      <p class="font-semibold text-gray-900">Enhanced</p>
                      <p class="text-xs text-gray-500 mt-1">Inkl. erhöhte Anforderungen</p>
                    </div>
                  </label>
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Anforderungskatalog <span class="text-red-500">*</span></label>
                <div v-if="catalogs.length === 0" class="text-sm text-red-500">
                  Kein Katalog vorhanden. Bitte zuerst einen Katalog importieren.
                </div>
                <select v-else v-model="wCatalogId"
                  class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
                  <option v-for="c in catalogs" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <p class="text-xs text-gray-400 mt-1">
                  Die Anforderungen werden nach Anlegen automatisch aus dem Katalog geladen.
                </p>
              </div>
            </div>

            <!-- Step 5: Bestätigung -->
            <div v-if="wizardStep === 5" class="space-y-4">
              <h3 class="font-semibold text-gray-900">Schritt 5: Bestätigung</h3>

              <!-- Creating … spinner -->
              <div v-if="wizardBusy" class="flex flex-col items-center py-8 gap-3">
                <div class="w-10 h-10 border-4 border-primary-200 border-t-primary-600 rounded-full animate-spin"></div>
                <p class="text-sm text-gray-700 font-medium">Informationsverbund wird angelegt …</p>
                <p class="text-xs text-gray-400 text-center">Anforderungen werden aus dem Katalog geladen.<br>Das kann einen Moment dauern.</p>
              </div>

              <!-- Summary (hidden while creating) -->
              <template v-else>
                <div class="bg-gray-50 rounded-xl p-4 space-y-3 text-sm">
                  <div class="flex justify-between">
                    <span class="text-gray-500">Name</span>
                    <span class="font-medium text-gray-900">{{ wMeta.name }}</span>
                  </div>
                  <div v-if="wMeta.branche" class="flex justify-between">
                    <span class="text-gray-500">Branche</span>
                    <span class="text-gray-900">{{ wMeta.branche }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-gray-500">ISMS-Typ</span>
                    <span class="font-medium capitalize" :class="wIsmsType === 'enhanced' ? 'text-purple-700' : 'text-blue-700'">{{ wIsmsType }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-gray-500">Katalog</span>
                    <span class="text-gray-900">{{ catalogs.find(c => c.id === wCatalogId)?.name ?? '—' }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-gray-500">Geschäftsprozesse</span>
                    <span class="text-gray-900">{{ wProcesses.length }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-gray-500">Zielobjekte</span>
                    <span class="text-gray-900">{{ wAssets.length }}</span>
                  </div>
                </div>
                <div v-if="wizardError" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ wizardError }}</div>
              </template>
            </div>
          </div>

          <!-- Wizard footer -->
          <div class="flex justify-between px-6 py-4 border-t border-gray-100">
            <button v-if="wizardStep > 1" @click="wizardStep--"
              class="px-4 py-2 text-sm text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50">
              Zurück
            </button>
            <div v-else></div>
            <div class="flex gap-2">
              <button @click="showWizard = false" :disabled="wizardBusy"
                class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900 disabled:opacity-40 disabled:cursor-not-allowed">Abbrechen</button>
              <button v-if="wizardStep < 5" @click="wizardStep++" :disabled="!canNext"
                class="px-4 py-2 bg-primary-600 text-white text-sm font-medium rounded-lg hover:bg-primary-700 disabled:opacity-50 transition-colors">
                Weiter
              </button>
              <button v-else @click="submitWizard" :disabled="wizardBusy || !wCatalogId"
                class="flex items-center gap-2 px-4 py-2 bg-primary-600 text-white text-sm font-medium rounded-lg hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                <span v-if="wizardBusy" class="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin"></span>
                {{ wizardBusy ? 'Anlegen …' : 'Verbund anlegen' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

  </div>
</template>
