<script setup lang="ts">
import type { FeaturedReview } from '~/types'

/**
 * Real reviews, not placeholder people. Fed by GET /reviews/featured: visible,
 * 4★+, with written text, one per buyer. The payload carries a short display
 * name ("Maria S.") and never the reviewer's contact details.
 *
 * The page hides this section entirely when there are no reviews to show.
 * Cards sit in a snap strip — three across on desktop, swipeable on a phone.
 * Fewer cards than fit are centred (only when they fit: a centred strip that
 * overflows would cut its first card off on the left).
 */
const props = defineProps<{ reviews: FeaturedReview[] }>()

function initials(name: string): string {
  return name.split(/\s+/).map(s => s[0] ?? '').join('').slice(0, 2).toUpperCase()
}

const strip = ref<HTMLElement | null>(null)
const atStart = ref(true)
const atEnd = ref(false)
const overflowing = ref(false)

function measure() {
  const el = strip.value
  if (!el) return
  overflowing.value = el.scrollWidth > el.clientWidth + 4
  atStart.value = el.scrollLeft <= 4
  atEnd.value = el.scrollLeft + el.clientWidth >= el.scrollWidth - 4
}
function page(direction: 1 | -1) {
  strip.value?.scrollBy({ left: direction * strip.value.clientWidth * 0.9, behavior: 'smooth' })
}

let ro: ResizeObserver | null = null
onMounted(() => {
  measure()
  ro = new ResizeObserver(measure)
  if (strip.value) ro.observe(strip.value)
})
onUnmounted(() => ro?.disconnect())
watch(() => props.reviews.length, () => nextTick(measure))
</script>

<template>
  <section
    id="testimonials"
    class="relative bg-white dark:bg-brand-navy-deep py-20 lg:py-24 transition-colors duration-500 scroll-mt-20"
  >
    <div class="max-w-content mx-auto px-4 sm:px-6">
      <HomeSectionHeading
        v-reveal
        align="center"
        eyebrow="Reviews"
        title="What buyers say."
        accent="buyers"
        lead="Written by people who actually dealt with the agent — only they can leave a review."
      />

      <div
        ref="strip"
        v-reveal="{ delay: 120 }"
        class="mt-12 flex gap-5 overflow-x-auto snap-x snap-mandatory scroll-smooth pt-1 pb-6 -mx-4 px-4 sm:mx-0 sm:px-0 no-scrollbar"
        :class="reviews.length === 1 ? 'justify-center' : reviews.length === 2 ? 'sm:justify-center' : ''"
        @scroll.passive="measure"
      >
        <figure
          v-for="r in reviews"
          :key="r.id"
          class="snap-start flex-none w-[85%] sm:w-[calc((100%-1.25rem)/2)] lg:w-[calc((100%-2*1.25rem)/3)]
                 relative flex flex-col rounded-[1.75rem] p-7 bg-brand-cream dark:bg-white/[0.04]
                 ring-1 ring-brand-navy/[0.06] dark:ring-white/10"
        >
          <span class="absolute top-5 right-7 font-heading text-7xl leading-none text-brand-gold/25 select-none" aria-hidden="true">“</span>

          <AppRating :value="r.rating" size="sm" :readonly="true" />

          <blockquote class="mt-5 flex-1 text-[0.9375rem] leading-relaxed text-brand-navy/85 dark:text-white/80 line-clamp-6">
            “{{ r.review_text }}”
          </blockquote>

          <figcaption class="mt-6 pt-5 border-t border-brand-navy/[0.08] dark:border-white/10 flex items-center gap-3">
            <img
              v-if="r.buyer_avatar"
              :src="r.buyer_avatar"
              alt=""
              loading="lazy"
              class="h-10 w-10 flex-none rounded-full object-cover"
            />
            <span
              v-else
              class="h-10 w-10 flex-none rounded-full bg-brand-navy dark:bg-brand-gold text-brand-gold dark:text-brand-navy flex items-center justify-center text-sm font-bold"
            >
              {{ initials(r.buyer_name) }}
            </span>
            <div class="min-w-0">
              <p class="flex items-center gap-1.5 text-sm font-semibold text-brand-navy dark:text-white">
                {{ r.buyer_name }}
                <span
                  v-if="r.is_verified"
                  class="inline-flex items-center gap-0.5 rounded-full bg-emerald-500/10 px-1.5 py-0.5 text-[0.625rem] font-bold text-emerald-700 dark:text-emerald-300"
                  title="This review rests on a viewing that took place"
                >
                  <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.58l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
                  Viewed
                </span>
              </p>
              <p v-if="r.agent_name" class="truncate text-xs text-brand-text-secondary dark:text-white/50">
                Reviewed
                <NuxtLink :to="`/agents/${r.agent_id}`" class="font-medium text-brand-gold-deep dark:text-brand-gold hover:underline">{{ r.agent_name }}</NuxtLink>
              </p>
            </div>
          </figcaption>
        </figure>
      </div>

      <div v-if="overflowing" class="mt-4 flex justify-center gap-2">
        <button
          v-for="d in ([-1, 1] as const)"
          :key="d"
          type="button"
          class="h-10 w-10 rounded-full border border-gray-200 dark:border-white/15 text-brand-navy dark:text-white flex items-center justify-center
                 hover:border-brand-gold transition disabled:opacity-30 disabled:cursor-not-allowed"
          :disabled="d === -1 ? atStart : atEnd"
          :aria-label="d === -1 ? 'Previous reviews' : 'Next reviews'"
          @click="page(d)"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" :d="d === -1 ? 'M15 19l-7-7 7-7' : 'M9 5l7 7-7 7'" />
          </svg>
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
</style>
