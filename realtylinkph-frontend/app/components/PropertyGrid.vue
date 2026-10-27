<script setup lang="ts">
import type { Property } from '~/types'

interface Props {
  properties: Property[]
  loading?: boolean
  skeletonCount?: number
  dense?: boolean
  /**
   * Cards rise in one after another whenever a new result set arrives
   * (Browse). Pure CSS, capped at ~0.4 s for the last card, and skipped for
   * reduced motion — results are never held back waiting on an animation.
   */
  stagger?: boolean
  /** Passed to each card: no saved/removed toast (the page shows its own). */
  quietToggle?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  loading:       false,
  skeletonCount: 8,
  dense:         false,
  stagger:       false,
  quietToggle:   false,
})

defineEmits<{ toggle: [payload: { id: number; favorited: boolean }] }>()

/* Columns follow the grid's own width (container query below), not the
   window's: a docked panel or the map can take a third of the screen, and
   window breakpoints then gave either 450px cards (fixed 2 columns) or 175px
   ones whose photo buttons overlapped (4 columns beside the AI panel). */
const gridClass = computed(() => ['grid grid-cols-1 pgrid', props.dense ? 'gap-5' : 'gap-6'])

/* A new array = a new result set; bumping the batch re-keys the cards so the
   entrance replays for it (and only for it). */
const batch = ref(0)
watch(() => props.properties, () => { batch.value++ })
</script>

<template>
  <div class="pgrid-wrap">
    <div :class="gridClass">
      <template v-if="loading">
        <PropertySkeleton v-for="i in skeletonCount" :key="i" />
      </template>
      <template v-else-if="stagger">
        <div
          v-for="(p, i) in properties"
          :key="`${batch}-${p.id}`"
          class="relative grid-card-in"
          :style="{ animationDelay: `${Math.min(i, 8) * 50}ms` }"
        >
          <PropertyCard :property="p" :quiet-toggle="quietToggle" @toggle="$emit('toggle', $event)" />
          <!-- Extra controls laid over a card (Browse's Compare toggle) -->
          <slot name="overlay" :property="p" />
        </div>
      </template>
      <template v-else>
        <PropertyCard v-for="p in properties" :key="p.id" :property="p" :quiet-toggle="quietToggle" @toggle="$emit('toggle', $event)" />
      </template>
    </div>
  </div>
</template>

<style scoped>
/* Cards stay ~230–360px wide whatever sits beside the grid. */
.pgrid-wrap { container-type: inline-size; }
@container (min-width: 34rem) { .pgrid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@container (min-width: 46rem) { .pgrid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@container (min-width: 66rem) { .pgrid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }

.grid-card-in {
  animation: grid-card-in 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
}
@keyframes grid-card-in {
  from { opacity: 0; transform: translateY(14px); }
  to   { opacity: 1; transform: none; }
}
@media (prefers-reduced-motion: reduce) {
  .grid-card-in { animation: none; }
}
</style>
