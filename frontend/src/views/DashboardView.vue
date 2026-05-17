<script setup>
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useApi } from '@/composables/useApi.js'
import { useAuthStore } from '@/stores/useAuthStore.js'

const auth   = useAuthStore()
const router = useRouter()
const { data: dashboard, loading, execute } = useApi('/api/dashboard')

onMounted(() => execute())

// ── Lifecycle step detection ──────────────────────────────────────────────────

const STEPS = [
  { label: 'Katalog',    icon: '📚' },
  { label: 'Verbund',    icon: '🏢' },
  { label: 'Tailoring',  icon: '✂️' },
  { label: 'Grdschtz',   icon: '✅' },
  { label: 'Audit',      icon: '🔍' },
  { label: 'Sanierung',  icon: '🔧' },
]

function domainStep(d) {
  if (d.scoped_controls_count === 0) {
    return { stepIndex: 2, label: 'Tailoring ausstehend', action: 'tailoring', route: `/verbund/${d.id}` }
  }
  if (d.impl_total === 0 || d.impl_progress.not_started === d.impl_progress.total) {
    return { stepIndex: 3, label: 'Grundschutzcheck starten', action: 'grundschutzcheck', route: `/verbund/${d.id}/grundschutzcheck` }
  }
  if (d.impl_progress.not_started > 0) {
    return {
      stepIndex: 3,
      label: `Grundschutzcheck (${d.impl_progress.not_started} offen)`,
      action: 'grundschutzcheck',
      route: `/verbund/${d.id}/grundschutzcheck`,
    }
  }
  return { stepIndex: 4, label: 'Audit vorbereiten', action: null, route: null }
}

function implPercent(d) {
  const total = d.impl_progress.total
  if (total === 0) return 0
  return Math.round(((d.impl_progress.implemented + d.impl_progress.partial) / total) * 100)
}

// ── Computed states ───────────────────────────────────────────────────────────

const hasCatalog = computed(() => (dashboard.value?.catalogs_count ?? 0) > 0)
const hasDomains = computed(() => (dashboard.value?.domains_count  ?? 0) > 0)
const domains    = computed(() => dashboard.value?.domains ?? [])
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold text-gray-900 mb-1">Dashboard</h1>
    <p class="text-sm text-gray-500 mb-8">
      Willkommen, {{ auth.displayName }}. Hier sehen Sie den ISMS-Fortschritt auf einen Blick.
    </p>

    <!-- Loading -->
    <div v-if="loading" class="text-gray-400 text-sm">Daten werden geladen …</div>

    <template v-else-if="dashboard">

      <!-- ─── State A: No catalog yet ─────────────────────────────────────── -->
      <div v-if="!hasCatalog" class="max-w-2xl">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-8 text-center">
          <div class="text-5xl mb-4">📚</div>
          <h2 class="text-xl font-semibold text-gray-900 mb-2">Willkommen im GS++ Manager</h2>
          <p class="text-gray-500 mb-6 text-sm">
            Um loszulegen, importieren Sie zunächst den BSI Grundschutz++-Katalog.
            Er enthält alle Sicherheitsanforderungen, die Ihrer ISMS-Dokumentation zugrunde liegen.
          </p>
          <button
            class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-700 text-white px-6 py-3 rounded-lg font-medium transition-colors"
            @click="router.push('/kataloge')"
          >
            <span>📥</span> Katalog importieren
          </button>

          <!-- Journey preview -->
          <div class="mt-8 flex items-center justify-center gap-2">
            <div
              v-for="(step, i) in STEPS"
              :key="i"
              class="flex items-center gap-2"
            >
              <div class="flex flex-col items-center">
                <div
                  class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold"
                  :class="i === 0 ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-400'"
                >
                  {{ i + 1 }}
                </div>
                <span class="text-xs text-gray-400 mt-1 w-14 text-center leading-tight">{{ step.label }}</span>
              </div>
              <div v-if="i < STEPS.length - 1" class="w-6 h-px bg-gray-200 mb-4"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- ─── State B: Catalog exists, no domain yet ──────────────────────── -->
      <div v-else-if="!hasDomains" class="max-w-2xl">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-8 text-center">
          <div class="text-5xl mb-4">🏢</div>
          <div class="inline-flex items-center gap-2 bg-green-50 text-green-700 text-xs font-medium px-3 py-1 rounded-full mb-4">
            ✅ Katalog importiert
          </div>
          <h2 class="text-xl font-semibold text-gray-900 mb-2">Informationsverbund anlegen</h2>
          <p class="text-gray-500 mb-6 text-sm">
            Legen Sie jetzt Ihren ersten Informationsverbund an — den Geltungsbereich Ihres ISMS.
            Sie definieren dabei Unternehmensgröße, Assets und Schutzbedarf.
          </p>
          <button
            class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-700 text-white px-6 py-3 rounded-lg font-medium transition-colors"
            @click="router.push('/verbund')"
          >
            <span>➕</span> Informationsverbund anlegen
          </button>

          <!-- Journey preview -->
          <div class="mt-8 flex items-center justify-center gap-2">
            <div
              v-for="(step, i) in STEPS"
              :key="i"
              class="flex items-center gap-2"
            >
              <div class="flex flex-col items-center">
                <div
                  class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold"
                  :class="i === 0 ? 'bg-green-500 text-white' : i === 1 ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-400'"
                >
                  <span v-if="i === 0">✓</span>
                  <span v-else>{{ i + 1 }}</span>
                </div>
                <span class="text-xs text-gray-400 mt-1 w-14 text-center leading-tight">{{ step.label }}</span>
              </div>
              <div v-if="i < STEPS.length - 1" class="w-6 h-px bg-gray-200 mb-4"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- ─── State C: Domains exist — per-domain cards ──────────────────── -->
      <div v-else>
        <!-- Global summary row -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <div class="text-2xl font-bold text-gray-900">{{ dashboard.catalogs_count }}</div>
            <div class="text-xs text-gray-500 mt-1">Kataloge</div>
          </div>
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <div class="text-2xl font-bold text-gray-900">{{ dashboard.domains_count }}</div>
            <div class="text-xs text-gray-500 mt-1">Informationsverbünde</div>
          </div>
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <div class="text-2xl font-bold text-primary-600">
              {{ domains.reduce((s, d) => s + d.impl_progress.implemented, 0) }}
            </div>
            <div class="text-xs text-gray-500 mt-1">Anforderungen umgesetzt</div>
          </div>
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <div class="text-2xl font-bold text-orange-500">
              {{ domains.reduce((s, d) => s + d.impl_progress.not_started, 0) }}
            </div>
            <div class="text-xs text-gray-500 mt-1">Noch offen</div>
          </div>
        </div>

        <!-- Domain lifecycle cards -->
        <div class="space-y-4">
          <div
            v-for="d in domains"
            :key="d.id"
            class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6"
          >
            <!-- Domain header -->
            <div class="flex items-start justify-between gap-4 mb-5">
              <div>
                <h2 class="text-lg font-semibold text-gray-900">{{ d.name }}</h2>
                <span class="inline-block text-xs text-gray-500 bg-gray-100 px-2 py-0.5 rounded mt-1">
                  {{ d.isms_type === 'standard' ? 'Standard-ISMS' : d.isms_type === 'enhanced' ? 'Erweitertes ISMS' : 'Basis-ISMS' }}
                </span>
              </div>
              <button
                class="shrink-0 text-sm text-primary-600 hover:text-primary-800 font-medium transition-colors"
                @click="router.push(`/verbund/${d.id}`)"
              >
                Öffnen →
              </button>
            </div>

            <!-- Stepper -->
            <div class="flex items-start gap-1 mb-5">
              <template v-for="(step, i) in STEPS" :key="i">
                <div class="flex flex-col items-center flex-1">
                  <div
                    class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition-colors"
                    :class="{
                      'bg-green-500 text-white':   i < domainStep(d).stepIndex,
                      'bg-primary-600 text-white': i === domainStep(d).stepIndex,
                      'bg-gray-100 text-gray-400': i > domainStep(d).stepIndex,
                    }"
                  >
                    <span v-if="i < domainStep(d).stepIndex">✓</span>
                    <span v-else>{{ step.icon }}</span>
                  </div>
                  <span
                    class="text-xs mt-1 text-center leading-tight w-14"
                    :class="{
                      'text-green-600 font-medium': i < domainStep(d).stepIndex,
                      'text-primary-700 font-semibold': i === domainStep(d).stepIndex,
                      'text-gray-400': i > domainStep(d).stepIndex,
                    }"
                  >{{ step.label }}</span>
                  <span
                    v-if="i > domainStep(d).stepIndex + 1"
                    class="text-xs text-gray-300 text-center leading-tight w-14"
                  >kommt bald</span>
                </div>
                <div
                  v-if="i < STEPS.length - 1"
                  class="flex-none w-4 h-px mt-4 transition-colors"
                  :class="i < domainStep(d).stepIndex ? 'bg-green-400' : 'bg-gray-200'"
                ></div>
              </template>
            </div>

            <!-- Progress bar (only shown if Grundschutzcheck has started) -->
            <div v-if="d.impl_progress.total > 0" class="mb-4">
              <div class="flex justify-between text-xs text-gray-500 mb-1">
                <span>Grundschutzcheck</span>
                <span>{{ implPercent(d) }}% abgeschlossen</span>
              </div>
              <div class="h-2 bg-gray-100 rounded-full overflow-hidden flex">
                <div
                  class="h-full bg-status-implemented transition-all duration-500"
                  :style="{ width: (d.impl_progress.implemented / d.impl_progress.total * 100) + '%' }"
                ></div>
                <div
                  class="h-full bg-status-partial transition-all duration-500"
                  :style="{ width: (d.impl_progress.partial / d.impl_progress.total * 100) + '%' }"
                ></div>
                <div
                  class="h-full bg-status-planned transition-all duration-500"
                  :style="{ width: (d.impl_progress.planned / d.impl_progress.total * 100) + '%' }"
                ></div>
              </div>
            </div>

            <!-- Next action -->
            <div class="flex items-center justify-between">
              <div class="text-sm text-gray-600">
                <span class="font-medium">Nächster Schritt:</span> {{ domainStep(d).label }}
              </div>
              <button
                v-if="domainStep(d).route"
                class="inline-flex items-center gap-1.5 bg-primary-600 hover:bg-primary-700 text-white text-sm px-4 py-2 rounded-lg font-medium transition-colors"
                @click="router.push(domainStep(d).route)"
              >
                {{ domainStep(d).label }} →
              </button>
            </div>
          </div>
        </div>

        <!-- Add another domain (non-management roles only) -->
        <button
          v-if="!['management', 'readonly', 'auditor'].includes(auth.role)"
          class="mt-4 w-full border-2 border-dashed border-gray-200 hover:border-primary-400 rounded-2xl py-4 text-sm text-gray-400 hover:text-primary-600 transition-colors"
          @click="router.push('/verbund')"
        >
          + Weiteren Informationsverbund anlegen
        </button>
      </div>

    </template>
  </div>
</template>
