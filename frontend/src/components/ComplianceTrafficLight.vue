<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: {
    type: String,
    default: 'unknown',
    validator: (v) => ['green', 'yellow', 'red', 'unknown'].includes(v),
  },
})

const LABELS = {
  green:   'Compliance-Status: Gut (≥ 80 % umgesetzt, keine überfälligen Maßnahmen)',
  yellow:  'Compliance-Status: Verbesserungsbedarf (50–79 % oder überfällige Maßnahmen)',
  red:     'Compliance-Status: Kritisch (< 50 % umgesetzt)',
  unknown: 'Compliance-Status: Unbekannt (noch kein SSP generiert)',
}

const statusLabel = computed(() => LABELS[props.status] ?? LABELS.unknown)
</script>

<template>
  <div class="flex flex-col items-center gap-1" :title="statusLabel">
    <!-- Red (top) -->
    <div
      class="w-4 h-4 rounded-full border border-gray-600 transition-colors"
      :class="status === 'red' ? 'bg-red-500 shadow-[0_0_6px_2px_rgba(239,68,68,0.6)]' : 'bg-gray-700'"
    />
    <!-- Yellow (middle) -->
    <div
      class="w-4 h-4 rounded-full border border-gray-600 transition-colors"
      :class="status === 'yellow' ? 'bg-yellow-400 shadow-[0_0_6px_2px_rgba(251,191,36,0.6)]' : 'bg-gray-700'"
    />
    <!-- Green (bottom) -->
    <div
      class="w-4 h-4 rounded-full border border-gray-600 transition-colors"
      :class="status === 'green' ? 'bg-green-500 shadow-[0_0_6px_2px_rgba(34,197,94,0.6)]' : 'bg-gray-700'"
    />
  </div>
</template>
