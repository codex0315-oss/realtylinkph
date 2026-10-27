<script setup lang="ts">
/**
 * The PRC-verified mark: a metallic-gold seal with a white check. One shape
 * everywhere an agent is shown as verified, so buyers learn to recognise it.
 *
 * The scalloped seal (not a plain circle) reads as a badge rather than a
 * "done" tick. The bronze drop shadow lifts it off light and dark grounds.
 */
interface Props {
  size?: 'xs' | 'sm' | 'md' | 'lg'
  /** Accessible name; pass '' when a visible label sits right next to it. */
  label?: string
}
const props = withDefaults(defineProps<Props>(), { size: 'sm', label: 'PRC-verified agent' })

const box = { xs: 'h-3.5 w-3.5', sm: 'h-4 w-4', md: 'h-5 w-5', lg: 'h-6 w-6' }
const uid = `vt-${useId()}`
</script>

<template>
  <svg
    :class="box[props.size]"
    class="flex-none drop-shadow-[0_1px_2px_rgba(133,96,26,0.45)]"
    viewBox="0 0 24 24"
    :role="label ? 'img' : undefined"
    :aria-label="label || undefined"
    :aria-hidden="label ? undefined : 'true'"
  >
    <title v-if="label">{{ label }}</title>
    <defs>
      <linearGradient :id="uid" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0" stop-color="#F1D78F" />
        <stop offset="0.45" stop-color="#D4A54A" />
        <stop offset="1" stop-color="#A8772A" />
      </linearGradient>
    </defs>
    <path
      :fill="`url(#${uid})`"
      d="M12 1.5l2.47 1.8 3.05-.02.94 2.9 2.48 1.78-.95 2.9.95 2.9-2.48 1.79-.94 2.9-3.05-.02L12 22.5l-2.47-1.8-3.05.02-.94-2.9-2.48-1.78.95-2.9-.95-2.9 2.48-1.79.94-2.9 3.05.02L12 1.5z"
    />
    <path
      d="M7.6 12.3l3 3 5.8-6.2"
      fill="none"
      stroke="#fff"
      stroke-width="2.4"
      stroke-linecap="round"
      stroke-linejoin="round"
      style="paint-order: stroke"
    />
  </svg>
</template>
