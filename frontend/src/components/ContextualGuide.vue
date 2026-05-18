<script setup>
import { ref, computed } from 'vue'
import { useRoute } from 'vue-router'

const route     = useRoute()
const collapsed = ref(false)
const dismissed = ref(localStorage.getItem('guide_dismissed') === '1')

function dismiss() {
  dismissed.value = true
  localStorage.setItem('guide_dismissed', '1')
}

function showGuide() {
  dismissed.value = false
  localStorage.removeItem('guide_dismissed')
}

// Per-route guide content
const GUIDES = {
  Dashboard: {
    title: 'Dashboard',
    steps: [
      'Hier sehen Sie einen Überblick über den aktuellen Stand Ihres ISMS.',
      'Starten Sie mit dem Anlegen eines Informationsverbunds unter "Domain-Verbund".',
    ],
  },
  Domains: {
    title: 'Informationsverbund anlegen',
    steps: [
      'Klicken Sie auf "+ Neuer Informationsverbund" und folgen Sie dem 5-Schritte-Assistenten.',
      'Definieren Sie Ihren Geltungsbereich: Welche IT-Systeme, Prozesse und Räume gehören dazu?',
      'Wählen Sie ISMS-Typ und Katalog — der Typ bestimmt, welche Anforderungen gelten.',
    ],
  },
  DomainDetail: {
    title: 'Informationsverbund konfigurieren',
    steps: [
      'Zielobjekte: Erfassen Sie alle IT-Systeme, Netze, Räume und Personal in Ihrem Scope.',
      'Geschäftsprozesse: Welche Prozesse müssen abgesichert werden? Wie kritisch sind sie?',
      'Anforderungen: Passen Sie die Anforderungen per Tailoring an Ihren Verbund an — schließen Sie nicht anwendbare Anforderungen mit Begründung aus.',
      'Sobald das Tailoring abgeschlossen ist, geht es weiter zum Grundschutzcheck.',
    ],
  },
  SspEditor: {
    title: 'Grundschutzcheck',
    steps: [
      'Wählen Sie links eine Anforderung aus.',
      'Lesen Sie den Anforderungstext, um zu verstehen, was gefordert wird.',
      'Setzen Sie den Status: Wie ist die Anforderung in Ihrem Unternehmen umgesetzt?',
      'Beschreiben Sie im Freitext, wie die Umsetzung konkret aussieht.',
      'Weisen Sie einen Verantwortlichen zu und setzen Sie ein Zieldatum.',
      'Laden Sie bei Bedarf Nachweisdokumente hoch.',
    ],
  },
  Risks: {
    title: 'Risikoanalyse',
    steps: [
      'Identifizieren Sie Risiken für Ihren Informationsverbund.',
      'Bewerten Sie jedes Risiko nach Eintrittswahrscheinlichkeit und Auswirkung.',
      'Legen Sie eine Risikostrategie fest: akzeptieren, mitigieren, transferieren oder vermeiden.',
    ],
  },
  Audit: {
    title: 'Audit',
    steps: [
      'Dokumentieren Sie Audit-Feststellungen zu einzelnen Anforderungen.',
      'Jede Feststellung erhält einen Schweregrad und eine Empfehlung.',
      'Kritische Feststellungen sollten umgehend in Maßnahmen (POAM) überführt werden.',
    ],
  },
  Poam: {
    title: 'Maßnahmenplan (POAM)',
    steps: [
      'Hier verwalten Sie offene Maßnahmen zur Schließung von Feststellungen.',
      'Weisen Sie jeder Maßnahme einen Verantwortlichen und eine Deadline zu.',
      'Aktualisieren Sie den Status regelmäßig, um den Fortschritt zu verfolgen.',
    ],
  },
  Catalogs: {
    title: 'Anforderungskataloge',
    steps: [
      'Importieren Sie den BSI Grundschutz++ Katalog als OSCAL-JSON.',
      'Ein Katalog muss vorhanden sein, bevor Sie einen Informationsverbund anlegen können.',
    ],
  },
  AiAssistant: {
    title: 'KI-Assistent',
    steps: [
      'Wählen Sie oben eine Funktion aus: Erklärung, Umsetzungsvorschlag, Risikoanalyse u.v.m.',
      'Nutzen Sie die "Schnellauswahl": Wählen Sie Verbund und Anforderung — die Felder werden automatisch befüllt.',
      'Klicken Sie auf "KI fragen" und erhalten Sie kontextbezogene Unterstützung.',
    ],
  },
}

const guide = computed(() => GUIDES[route.name] ?? null)
</script>

<template>
  <!-- Floating re-enable button when fully dismissed -->
  <button
    v-if="dismissed"
    @click="showGuide"
    class="fixed bottom-4 left-4 z-40 w-8 h-8 rounded-full bg-primary-600 text-white shadow-lg hover:bg-primary-700 transition-colors flex items-center justify-center text-sm"
    title="Hilfe einblenden"
  >?</button>

  <!-- Guide panel -->
  <div
    v-else-if="guide"
    class="fixed bottom-4 left-4 z-40 w-72 bg-white border border-gray-200 rounded-xl shadow-lg text-sm overflow-hidden"
  >
    <!-- Header -->
    <div class="flex items-center justify-between px-4 py-2.5 bg-primary-50 border-b border-primary-100">
      <div class="flex items-center gap-2 min-w-0">
        <span class="text-primary-600 font-bold text-xs shrink-0">Hilfe</span>
        <span class="text-xs font-semibold text-primary-800 truncate">{{ guide.title }}</span>
      </div>
      <div class="flex items-center gap-1 shrink-0">
        <button
          @click="collapsed = !collapsed"
          class="w-5 h-5 flex items-center justify-center text-primary-400 hover:text-primary-700 transition-colors rounded"
          :title="collapsed ? 'Ausklappen' : 'Einklappen'"
        >
          <svg class="w-3.5 h-3.5 transition-transform" :class="collapsed ? '' : 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
          </svg>
        </button>
        <button
          @click="dismiss"
          class="w-5 h-5 flex items-center justify-center text-primary-400 hover:text-red-500 transition-colors rounded"
          title="Hilfe dauerhaft ausblenden"
        >
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- Steps (collapsible) -->
    <div v-if="!collapsed" class="px-4 py-3 space-y-2">
      <div
        v-for="(step, i) in guide.steps"
        :key="i"
        class="flex gap-2.5"
      >
        <span class="shrink-0 w-4 h-4 rounded-full bg-primary-100 text-primary-700 text-xs font-bold flex items-center justify-center mt-0.5">{{ i + 1 }}</span>
        <p class="text-xs text-gray-600 leading-relaxed">{{ step }}</p>
      </div>
    </div>
  </div>
</template>
