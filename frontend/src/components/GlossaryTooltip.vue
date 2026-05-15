<script setup>
import { ref } from 'vue'

const props = defineProps({
  term: { type: String, required: true },
  explanation: { type: String, required: true },
})

const visible = ref(false)
</script>

<template>
  <span
    class="glossary-term relative"
    @mouseenter="visible = true"
    @mouseleave="visible = false"
    @focus="visible = true"
    @blur="visible = false"
    tabindex="0"
  >
    <slot>{{ term }}</slot>

    <Transition
      enter-active-class="transition ease-out duration-150"
      enter-from-class="opacity-0 translate-y-1"
      enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition ease-in duration-100"
      leave-from-class="opacity-100 translate-y-0"
      leave-to-class="opacity-0 translate-y-1"
    >
      <div
        v-if="visible"
        class="absolute z-50 bottom-full left-1/2 -translate-x-1/2 mb-2 w-72 px-4 py-3 bg-gray-900 text-white text-sm rounded-lg shadow-lg"
      >
        <div class="font-semibold mb-1">{{ term }}</div>
        <div class="text-gray-300 text-xs leading-relaxed">{{ explanation }}</div>
        <div class="absolute left-1/2 -translate-x-1/2 top-full w-2 h-2 bg-gray-900 rotate-45"></div>
      </div>
    </Transition>
  </span>
</template>
