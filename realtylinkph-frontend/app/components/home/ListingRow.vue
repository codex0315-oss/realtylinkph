<script setup lang="ts">
import type { Property } from '~/types'

/**
 * One horizontal shelf of listings — title with an arrow link, cards that
 * scroll sideways with snap points, and prev/next buttons that page by the
 * width of the visible strip. Arrows dim at either end and are hidden entirely
 * when everything already fits.
 */
const props = defineProps<{
  title: string
  href: string
  properties: Property[]
}>()

const strip = ref<HTMLElement | null>(null)
const atStart = ref(true)
const atEnd   = ref(false)
const overflowing = ref(false)

function measure() {
  const el = strip.value
  if (!el) return
  overflowing.value = el.scrollWidth > el.clientWidth + 4
  atStart.value = el.scrollLeft <= 4
  atEnd.value   = el.scrollLeft + el.clientWidth >= el.scrollWidth - 4
}

function page(direction: 1 | -1) {
  const el = strip.value
  if (!el) return
  el.scrollBy({ left: direction * el.clientWidth * 0.9, behavior: 'smooth' })
}

let ro: ResizeObserver | null = null
onMounted(() => {
  measure()
  ro = new ResizeObserver(measure)
  if (strip.value) ro.observe(strip.value)
})
onUnmounted(() => ro?.disconnect())
watch(() => props.properties.length, () => nextTick(measure))
</script>

<template>
  <section class="relative">
    <!-- Title row -->
    <div class="flex items-center justify-between gap-4 mb-3">
      <NuxtLink
        :to="href"
        class="group inline-flex items-center gap-2 font-display text-xl sm:text-[22px] font-bold text-brand-navy dark:text-white leading-tight"
      >
        {{ title }}
        <span class="h-6 w-6 rounded-full flex items-center justify-center text-brand-navy/60 dark:text-white/60 group-hover:bg-brand-navy/5 dark:group-hover:bg-white/10 group-hover:translate-x-0.5 transition-all">
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
        </span>
      </NuxtLink>

      <div v-if="overflowing" class="hidden sm:flex items-center gap-1.5">
        <button
          type="button"
          class="h-8 w-8 rounded-full border border-gray-200 dark:border-white/15 bg-white/80 dark:bg-white/5 text-brand-navy dark:text-white flex items-center justify-center transition-all hover:border-brand-gold disabled:opacity-30 disabled:cursor-not-allowed"
          :disabled="atStart"
          aria-label="Scroll left"
          @click="page(-1)"
        >
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
        </button>
        <button
          type="button"
          class="h-8 w-8 rounded-full border border-gray-200 dark:border-white/15 bg-white/80 dark:bg-white/5 text-brand-navy dark:text-white flex items-center justify-center transition-all hover:border-brand-gold disabled:opacity-30 disabled:cursor-not-allowed"
          :disabled="atEnd"
          aria-label="Scroll right"
          @click="page(1)"
        >
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
        </button>
      </div>
    </div>

    <!-- Cards. Negative margin + padding lets the strip bleed to the page edge
         on small screens while cards stay aligned with the title. -->
    <div
      ref="strip"
      class="flex gap-5 overflow-x-auto snap-x snap-mandatory scroll-smooth pb-2 -mx-6 px-6 sm:mx-0 sm:px-0 no-scrollbar"
      @scroll.passive="measure"
    >
      <div
        v-for="p in properties"
        :key="p.id"
        class="snap-start flex-none w-[230px] sm:w-[250px] lg:w-[calc((100%-4*1.25rem)/5)] lg:min-w-[230px]"
      >
        <PropertyCard :property="p" />
      </div>
    </div>
  </section>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
</style>
