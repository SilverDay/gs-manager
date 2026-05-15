<script setup>
import { onMounted } from 'vue'
import { useApi } from '@/composables/useApi.js'
import { useAuthStore } from '@/stores/useAuthStore.js'
import GlossaryTooltip from '@/components/GlossaryTooltip.vue'

const auth = useAuthStore()
const { data: dashboard, loading, execute } = useApi('/api/dashboard')

onMounted(() => execute())
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold text-gray-900 mb-1">Dashboard</h1>
    <p class="text-sm text-gray-500 mb-8">
      Willkommen, {{ auth.displayName }}. Hier sehen Sie den Compliance-Status auf einen Blick.
    </p>

    <!-- Loading -->
    <div v-if="loading" class="text-gray-400 text-sm">Daten werden geladen …</div>

    <!-- KPI Cards -->
    <div v-else-if="dashboard" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="text-sm text-gray-500 mb-1">
          <GlossaryTooltip term="Informationsverbünde" explanation="Der Bereich Ihres Unternehmens, für den das Sicherheitskonzept gilt." />
        </div>
        <div class="text-3xl font-bold text-gray-900">{{ dashboard.domains_count }}</div>
      </div>

      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="text-sm text-gray-500 mb-1">
          <GlossaryTooltip term="Anforderungen gesamt" explanation="Die Gesamtzahl aller Sicherheitsanforderungen, die für Ihren Geltungsbereich gelten." />
        </div>
        <div class="text-3xl font-bold text-gray-900">{{ dashboard.controls_total }}</div>
      </div>

      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="text-sm text-gray-500 mb-1">Umgesetzt</div>
        <div class="text-3xl font-bold text-status-implemented">{{ dashboard.controls_implemented }}</div>
      </div>

      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="text-sm text-gray-500 mb-1">
          <GlossaryTooltip term="Offene Maßnahmen" explanation="Feststellungen aus der Prüfung, die noch bearbeitet werden müssen." />
        </div>
        <div class="text-3xl font-bold" :class="dashboard.poam_overdue > 0 ? 'text-red-600' : 'text-gray-900'">
          {{ dashboard.poam_open }}
          <span v-if="dashboard.poam_overdue > 0" class="text-sm font-normal text-red-500">
            ({{ dashboard.poam_overdue }} überfällig)
          </span>
        </div>
      </div>
    </div>

    <!-- Compliance Ampel -->
    <div v-if="dashboard" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">
        <GlossaryTooltip term="Compliance-Status" explanation="Wie weit ist Ihr Unternehmen bei der Umsetzung der Sicherheitsanforderungen?" />
      </h2>
      <div class="flex items-center gap-4">
        <div
          class="w-16 h-16 rounded-full flex items-center justify-center text-white font-bold text-lg"
          :class="{
            'bg-green-500': dashboard.compliance_percent >= 80,
            'bg-yellow-500': dashboard.compliance_percent >= 50 && dashboard.compliance_percent < 80,
            'bg-red-500': dashboard.compliance_percent < 50,
          }"
        >
          {{ Math.round(dashboard.compliance_percent) }}%
        </div>
        <div>
          <div class="w-64 bg-gray-200 rounded-full h-3">
            <div
              class="h-3 rounded-full transition-all duration-500"
              :class="{
                'bg-green-500': dashboard.compliance_percent >= 80,
                'bg-yellow-500': dashboard.compliance_percent >= 50 && dashboard.compliance_percent < 80,
                'bg-red-500': dashboard.compliance_percent < 50,
              }"
              :style="{ width: dashboard.compliance_percent + '%' }"
            ></div>
          </div>
          <p class="text-xs text-gray-500 mt-1">
            {{ dashboard.controls_implemented }} von {{ dashboard.controls_total }} Anforderungen umgesetzt
          </p>
        </div>
      </div>
    </div>
  </div>
</template>
