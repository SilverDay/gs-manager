<script setup>
import { ref } from 'vue'

defineProps({
  term:        { type: String, required: true },
  explanation: { type: String, required: true },
})

const visible    = ref(false)
const triggerRef = ref(null)
const tipStyle   = ref({})
const arrowStyle = ref({})
const arrowDown  = ref(true)   // true → arrow at bottom of popup (popup is above trigger)

const TIP_W = 320   // matches w-80
const GAP   = 8     // px between trigger edge and tooltip edge

function show() {
  const el = triggerRef.value
  if (!el) return
  const r = el.getBoundingClientRect()

  // Centre tooltip over trigger, clamped to viewport
  const rawLeft = r.left + r.width / 2 - TIP_W / 2
  const left    = Math.max(8, Math.min(rawLeft, window.innerWidth - TIP_W - 8))

  // Arrow: points at the horizontal centre of the trigger
  const arrowLeft = Math.max(12, Math.min(r.left + r.width / 2 - left - 4, TIP_W - 20))

  // Prefer above; fall back to below when too close to the top
  const above = r.top > 160

  tipStyle.value = {
    left:   left + 'px',
    top:    above ? 'auto'                                   : (r.bottom + GAP) + 'px',
    bottom: above ? (window.innerHeight - r.top + GAP) + 'px' : 'auto',
  }
  arrowStyle.value = { left: arrowLeft + 'px' }
  arrowDown.value  = above

  visible.value = true
}

function hide() {
  visible.value = false
}
</script>

<template>
  <span
    ref="triggerRef"
    class="glossary-term"
    @mouseenter="show"
    @mouseleave="hide"
    @focus="show"
    @blur="hide"
    tabindex="0"
  >
    <slot>{{ term }}</slot>
  </span>

  <Teleport to="body">
    <Transition
      enter-active-class="transition ease-out duration-150"
      enter-from-class="opacity-0 scale-95"
      enter-to-class="opacity-100 scale-100"
      leave-active-class="transition ease-in duration-100"
      leave-from-class="opacity-100 scale-100"
      leave-to-class="opacity-0 scale-95"
    >
      <div
        v-if="visible"
        class="fixed z-[9999] w-80 px-4 py-3 bg-gray-900 text-white text-sm rounded-lg shadow-xl pointer-events-none"
        :style="tipStyle"
      >
        <div class="font-semibold mb-1">{{ term }}</div>
        <div class="text-gray-300 text-xs leading-relaxed">{{ explanation }}</div>

        <!-- Caret pointing toward the trigger -->
        <div
          class="absolute w-2 h-2 bg-gray-900 rotate-45"
          :class="arrowDown ? 'top-full -mt-1' : 'bottom-full mb-[-1px]'"
          :style="arrowStyle"
        ></div>
      </div>
    </Transition>
  </Teleport>
</template>
