<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  term:        { type: String, required: true },
  explanation: { type: String, required: true },
  // 'center' | 'left' | 'right'  — controls horizontal anchor of the popup
  align: { type: String, default: 'center' },
})

const visible = ref(false)

const popupClass = computed(() => {
  if (props.align === 'right')  return 'right-0'
  if (props.align === 'left')   return 'left-0'
  return 'left-1/2 -translate-x-1/2'
})

const arrowClass = computed(() => {
  if (props.align === 'right')  return 'right-4'
  if (props.align === 'left')   return 'left-4'
  return 'left-1/2 -translate-x-1/2'
})
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
        class="absolute z-50 bottom-full mb-2 w-80 px-4 py-3 bg-gray-900 text-white text-sm rounded-lg shadow-lg"
        :class="popupClass"
      >
        <div class="font-semibold mb-1">{{ term }}</div>
        <div class="text-gray-300 text-xs leading-relaxed">{{ explanation }}</div>
        <div
          class="absolute top-full w-2 h-2 bg-gray-900 rotate-45"
          :class="arrowClass"
        ></div>
      </div>
    </Transition>
  </span>
</template>
