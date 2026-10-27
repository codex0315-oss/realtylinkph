<script setup lang="ts">
import type { Property } from '~/types'

/**
 * The "Compare" tick laid over a property card's photo (Browse, Saved).
 * Picks go into the shared useCompare() list; BrowseCompare shows the tray.
 */
const props = defineProps<{ property: Property }>()
const compare = useCompare()
const on = computed(() => compare.has(props.property.id))
const blocked = computed(() => !on.value && compare.full.value)
</script>

<template>
  <button
    type="button"
    class="inline-flex items-center gap-1.5 rounded-full pl-1.5 pr-3 py-1 text-[0.6875rem] font-bold backdrop-blur-sm transition disabled:cursor-not-allowed"
    :class="on
      ? 'bg-brand-gold text-brand-navy shadow-[0_4px_12px_rgba(0,0,0,0.3)]'
      : 'bg-black/45 text-white ring-1 ring-white/25 hover:bg-black/65 disabled:opacity-50'"
    :disabled="blocked"
    :aria-pressed="on"
    :title="blocked ? 'You can compare up to 3 homes' : undefined"
    @click.stop.prevent="compare.toggle(property)"
  >
    <span
      class="h-4 w-4 rounded-[5px] flex items-center justify-center"
      :class="on ? 'bg-brand-navy text-brand-gold' : 'border-[1.5px] border-white/85'"
    >
      <svg v-if="on" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
    </span>
    Compare
  </button>
</template>
