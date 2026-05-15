<script setup>
import { ref, watch, onMounted, computed } from 'vue'
import { useCatalog } from '@/composables/useCatalog.js'
import { useAuthStore } from '@/stores/useAuthStore.js'
import GlossaryTooltip from '@/components/GlossaryTooltip.vue'

const auth = useAuthStore()
const {
  catalogs, controls, control, meta,
  loading, error,
  loadCatalogs, importFromJson, importFromUrl,
  loadControls, loadControl,
} = useCatalog()

const canImport = computed(() => ['admin', 'isb'].includes(auth.role))

// ── Catalog selection ─────────────────────────────────────────────────────
const selectedCatalogId = ref(null)

async function selectCatalog(id) {
  selectedCatalogId.value = id
  control.value = null
  search.value  = ''
  page.value    = 1
  await loadControls(id, { page: 1, perPage: perPage.value })
}

// ── Controls search & pagination ──────────────────────────────────────────
const search   = ref('')
const page     = ref(1)
const perPage  = ref(25)
let searchTimer = null

watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    if (selectedCatalogId.value) {
      loadControls(selectedCatalogId.value, { page: 1, perPage: perPage.value, search: search.value })
    }
  }, 300)
})

async function goToPage(p) {
  page.value = p
  await loadControls(selectedCatalogId.value, { page: p, perPage: perPage.value, search: search.value })
}

// ── Control detail ─────────────────────────────────────────────────────────
async function openControl(controlId) {
  await loadControl(selectedCatalogId.value, controlId)
}

function closeDetail() {
  control.value = null
}

// ── Import modal ──────────────────────────────────────────────────────────
const showImport   = ref(false)
const importTab    = ref('json')
const importJson   = ref('')
const importUrl    = ref('')
const importName   = ref('')
const importError  = ref(null)
const importing    = ref(false)

async function submitImport() {
  importing.value = true
  importError.value = null
  let res
  if (importTab.value === 'json') {
    res = await importFromJson(importJson.value, importName.value)
  } else {
    res = await importFromUrl(importUrl.value, importName.value)
  }
  importing.value = false
  if (res?.success) {
    showImport.value = false
    importJson.value = ''
    importUrl.value  = ''
    importName.value = ''
  } else {
    importError.value = res?.error ?? 'Import fehlgeschlagen'
  }
}

onMounted(loadCatalogs)
</script>

<template>
  <div class="h-full flex flex-col">

    <!-- Page header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 mb-1">
          <GlossaryTooltip term="Katalog" explanation="Der BSI Grundschutz++ Anforderungskatalog enthält alle Sicherheitsanforderungen, aus denen Ihr Anforderungsprofil zusammengestellt wird." />
        </h1>
        <p class="text-sm text-gray-500">Importierte BSI Grundschutz++ Anwenderkataloge</p>
      </div>
      <button
        v-if="canImport"
        @click="showImport = true"
        class="px-4 py-2 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 transition-colors"
      >
        + Katalog importieren
      </button>
    </div>

    <!-- Main layout: sidebar + content -->
    <div class="flex gap-6 flex-1 min-h-0">

      <!-- ── Catalog sidebar ──────────────────────────────────────────── -->
      <div class="w-72 flex-shrink-0 bg-white rounded-xl border border-gray-200 shadow-sm overflow-y-auto">
        <div class="p-4 border-b border-gray-100">
          <h2 class="text-sm font-semibold text-gray-700">Meine Kataloge</h2>
        </div>

        <div v-if="loading && catalogs.length === 0" class="p-4 text-sm text-gray-400">Laden …</div>

        <div v-else-if="catalogs.length === 0" class="p-6 text-center">
          <p class="text-sm text-gray-500 mb-3">Noch kein Katalog importiert.</p>
          <button
            v-if="canImport"
            @click="showImport = true"
            class="text-sm text-primary-600 hover:underline"
          >Jetzt importieren</button>
        </div>

        <ul v-else>
          <li
            v-for="cat in catalogs"
            :key="cat.id"
            @click="selectCatalog(cat.id)"
            class="px-4 py-3 cursor-pointer border-b border-gray-50 hover:bg-gray-50 transition-colors"
            :class="selectedCatalogId === cat.id ? 'bg-primary-50 border-l-2 border-l-primary-600' : ''"
          >
            <p class="text-sm font-medium text-gray-900 truncate">{{ cat.name }}</p>
            <p class="text-xs text-gray-400 mt-0.5">
              Importiert: {{ new Date(cat.imported_at).toLocaleDateString('de-DE') }}
            </p>
          </li>
        </ul>
      </div>

      <!-- ── Controls browser ───────────────────────────────────────────── -->
      <div class="flex-1 flex flex-col min-w-0">

        <!-- No catalog selected -->
        <div
          v-if="!selectedCatalogId"
          class="flex-1 flex items-center justify-center bg-white rounded-xl border border-gray-200 shadow-sm"
        >
          <p class="text-sm text-gray-400">Wählen Sie links einen Katalog aus.</p>
        </div>

        <!-- Controls list -->
        <div v-else class="flex-1 flex gap-4 min-h-0">

          <!-- List panel -->
          <div class="flex-1 flex flex-col bg-white rounded-xl border border-gray-200 shadow-sm min-w-0">

            <!-- Search bar -->
            <div class="p-4 border-b border-gray-100">
              <input
                v-model="search"
                type="search"
                placeholder="Anforderungen suchen (ID, Titel, Text) …"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500"
              />
            </div>

            <div v-if="loading" class="p-4 text-sm text-gray-400">Lade Anforderungen …</div>
            <div v-else-if="controls.length === 0" class="p-6 text-center text-sm text-gray-400">
              Keine Anforderungen gefunden.
            </div>

            <!-- Controls table -->
            <div v-else class="flex-1 overflow-y-auto">
              <table class="w-full text-sm">
                <thead class="bg-gray-50 sticky top-0">
                  <tr>
                    <th class="text-left px-4 py-2 text-xs font-semibold text-gray-500 w-24">ID</th>
                    <th class="text-left px-4 py-2 text-xs font-semibold text-gray-500">Titel</th>
                    <th class="text-left px-4 py-2 text-xs font-semibold text-gray-500 w-28">Gruppe</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="c in controls"
                    :key="c.id"
                    @click="openControl(c.id)"
                    class="border-t border-gray-50 hover:bg-gray-50 cursor-pointer transition-colors"
                    :class="control?.id === c.id ? 'bg-primary-50' : ''"
                  >
                    <td class="px-4 py-2.5 font-mono text-xs text-primary-700 font-medium">{{ c.id }}</td>
                    <td class="px-4 py-2.5 text-gray-900">{{ c.title }}</td>
                    <td class="px-4 py-2.5 text-gray-500 text-xs">{{ c.group_title }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Pagination -->
            <div
              v-if="meta && meta.last_page > 1"
              class="flex items-center justify-between px-4 py-3 border-t border-gray-100 text-sm"
            >
              <span class="text-gray-500 text-xs">{{ meta.total }} Anforderungen</span>
              <div class="flex gap-1">
                <button
                  v-for="p in meta.last_page"
                  :key="p"
                  @click="goToPage(p)"
                  class="px-2.5 py-1 rounded text-xs"
                  :class="p === meta.page ? 'bg-primary-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                >{{ p }}</button>
              </div>
            </div>
          </div>

          <!-- Control detail panel -->
          <div
            v-if="control"
            class="w-96 flex-shrink-0 bg-white rounded-xl border border-gray-200 shadow-sm flex flex-col overflow-hidden"
          >
            <div class="flex items-start justify-between p-4 border-b border-gray-100">
              <div>
                <span class="font-mono text-xs text-primary-700 font-semibold">{{ control.id }}</span>
                <h3 class="font-semibold text-gray-900 mt-0.5 leading-tight">{{ control.title }}</h3>
              </div>
              <button @click="closeDetail" class="text-gray-400 hover:text-gray-600 ml-2 mt-0.5">✕</button>
            </div>

            <div class="flex-1 overflow-y-auto p-4 space-y-4">

              <div>
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Gruppe</p>
                <p class="text-sm text-gray-700">{{ control.group_title }} ({{ control.group_id }})</p>
              </div>

              <div v-if="control.props?.['requirement-type']">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Typ</p>
                <span
                  class="inline-block text-xs px-2 py-0.5 rounded-full font-medium"
                  :class="{
                    'bg-blue-100 text-blue-700':     control.props['requirement-type'] === 'basis',
                    'bg-indigo-100 text-indigo-700': control.props['requirement-type'] === 'standard',
                    'bg-purple-100 text-purple-700': control.props['requirement-type'] === 'elevated',
                  }"
                >{{ control.props['requirement-type'] }}</span>
              </div>

              <div>
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">
                  <GlossaryTooltip
                    term="Anforderungstext"
                    explanation="Der normative Text der Sicherheitsanforderung aus dem BSI Grundschutz++ Katalog."
                  />
                </p>
                <p class="text-sm text-gray-800 leading-relaxed whitespace-pre-wrap">{{ control.statement }}</p>
              </div>

              <div v-if="control.params?.length">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Parameter</p>
                <ul class="space-y-1">
                  <li
                    v-for="param in control.params"
                    :key="param.id"
                    class="text-xs text-gray-600 bg-gray-50 rounded px-2 py-1"
                  >
                    <span class="font-mono text-gray-400">{{ param.id }}</span>
                    <span v-if="param.label" class="ml-1">— {{ param.label }}</span>
                  </li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Import modal ────────────────────────────────────────────────── -->
    <Teleport to="body">
      <div
        v-if="showImport"
        class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
        @click.self="showImport = false"
      >
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg">

          <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h2 class="text-lg font-semibold text-gray-900">Katalog importieren</h2>
            <button @click="showImport = false" class="text-gray-400 hover:text-gray-600">✕</button>
          </div>

          <div class="p-6 space-y-4">

            <!-- Tabs -->
            <div class="flex gap-1 bg-gray-100 rounded-lg p-1">
              <button
                @click="importTab = 'json'"
                class="flex-1 py-1.5 text-sm font-medium rounded-md transition-colors"
                :class="importTab === 'json' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'"
              >JSON-Datei</button>
              <button
                @click="importTab = 'url'"
                class="flex-1 py-1.5 text-sm font-medium rounded-md transition-colors"
                :class="importTab === 'url' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'"
              >URL (GitHub)</button>
            </div>

            <!-- Name -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                Name <span class="text-gray-400 font-normal">(optional)</span>
              </label>
              <input
                v-model="importName"
                type="text"
                placeholder="z.B. BSI GS++ Anwenderkatalog 2026"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500"
              />
            </div>

            <div v-if="importTab === 'json'">
              <label class="block text-sm font-medium text-gray-700 mb-1">OSCAL Catalog JSON</label>
              <textarea
                v-model="importJson"
                rows="8"
                placeholder='{ "catalog": { "uuid": "...", "metadata": { ... }, "groups": [ ... ] } }'
                class="w-full text-xs font-mono border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500 resize-none"
              />
            </div>

            <div v-if="importTab === 'url'">
              <label class="block text-sm font-medium text-gray-700 mb-1">
                Quell-URL
                <span class="text-gray-400 font-normal text-xs ml-1">(HTTPS)</span>
              </label>
              <input
                v-model="importUrl"
                type="url"
                placeholder="https://raw.githubusercontent.com/BSI-Bund/..."
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500"
              />
              <p class="text-xs text-gray-400 mt-1">
                Die URL wird gespeichert und ermöglicht spätere Update-Prüfungen.
              </p>
            </div>

            <div v-if="importError" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">
              {{ importError }}
            </div>
          </div>

          <div class="flex justify-end gap-3 px-6 py-4 border-t border-gray-100">
            <button
              @click="showImport = false"
              class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900"
            >Abbrechen</button>
            <button
              @click="submitImport"
              :disabled="importing || (importTab === 'json' && !importJson) || (importTab === 'url' && !importUrl)"
              class="px-4 py-2 bg-primary-600 text-white text-sm font-medium rounded-lg hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              <span v-if="importing">Importieren …</span>
              <span v-else>Importieren</span>
            </button>
          </div>
        </div>
      </div>
    </Teleport>

  </div>
</template>
