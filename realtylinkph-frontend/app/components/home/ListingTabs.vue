<script setup lang="ts">
import type { Property } from '~/types'

/**
 * One listings section instead of a stack of shelves. The server-grouped rows
 * (PropertyService::homeRows — city rows, then newest / for sale / for rent /
 * by type) become tabs over a single grid, so a small catalogue no longer
 * shows the same four cards three times in a row.
 */
interface HomeRow { key: string; title: string; href: string; properties: Property[] }

const props = defineProps<{ rows: HomeRow[]; pending: boolean }>()

const MAX_TABS  = 5
const PER_GRID  = 8

const tabs = computed(() => props.rows.slice(0, MAX_TABS))
const activeKey = ref('')
const active = computed(() => tabs.value.find(t => t.key === activeKey.value) ?? tabs.value[0])
/* Whole rows of four only — five listings would leave one card stranded. */
const cards  = computed(() => {
  const all = active.value?.properties ?? []
  const n = all.length >= PER_GRID ? PER_GRID : all.length >= 4 ? 4 : all.length
  return all.slice(0, n)
})

/* "Homes in Cebu City" reads long as a tab — show just the city. */
function tabLabel(title: string): string {
  return title.replace(/^Homes in /, '')
}
</script>

<template>
  <section class="bg-brand-cream dark:bg-brand-navy-deep py-20 lg:py-24 border-y border-brand-navy/[0.04] dark:border-white/5 transition-colors duration-500">
    <div class="max-w-content mx-auto px-4 sm:px-6">
      <HomeSectionHeading
        v-reveal
        align="center"
        title="Explore homes on the market."
        accent="homes"
        lead="Fresh listings from verified agents, updated as they publish."
      />

      <!-- Tabs -->
      <div v-if="tabs.length > 1" class="mt-10 -mx-4 px-4 overflow-x-auto no-scrollbar">
        <div role="tablist" class="mx-auto w-max flex gap-1 rounded-full p-1 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.08] dark:ring-white/10 shadow-sm">
          <button
            v-for="t in tabs"
            :key="t.key"
            type="button"
            role="tab"
            :aria-selected="active?.key === t.key"
            class="whitespace-nowrap rounded-full px-4 sm:px-5 py-2 text-sm font-semibold transition-colors"
            :class="active?.key === t.key
              ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-brand-navy'
              : 'text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white'"
            @click="activeKey = t.key"
          >
            {{ tabLabel(t.title) }}
          </button>
        </div>
      </div>

      <!-- Grid -->
      <div class="mt-10">
        <div v-if="pending" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
          <AppSkeleton v-for="j in 4" :key="j" width="100%" height="340px" rounded="xl" />
        </div>

        <Transition v-else-if="cards.length" name="tab-fade" mode="out-in">
          <div :key="active?.key" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
            <div v-for="(p, i) in cards" :key="p.id" :class="i >= 4 ? 'hidden sm:block' : ''">
              <PropertyCard :property="p" />
            </div>
          </div>
        </Transition>

        <div v-else class="rounded-3xl border border-dashed border-gray-300 dark:border-white/15 bg-white/60 dark:bg-white/[0.03] text-center py-16 px-6">
          <p class="font-heading text-2xl font-semibold text-brand-navy dark:text-white">No listings yet</p>
          <p class="mt-2 text-sm text-brand-text-secondary dark:text-white/55">
            Verified agents are adding properties — check back soon.
          </p>
        </div>
      </div>

      <div class="mt-12 text-center">
        <NuxtLink
          :to="active?.href ?? '/properties'"
          class="inline-flex items-center gap-2 rounded-full bg-brand-navy dark:bg-brand-gold px-7 py-3 text-sm font-bold text-white dark:text-brand-navy
                 dark:hover:bg-brand-gold-light dark:shadow-[0_4px_16px_rgba(212,165,74,0.35)]
                 hover:-translate-y-0.5 hover:shadow-lg transition-all"
        >
          {{ active && tabs.length > 1 ? `See all · ${tabLabel(active.title)}` : 'Browse all listings' }}
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
        </NuxtLink>
      </div>
    </div>
  </section>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }

.tab-fade-enter-active, .tab-fade-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.tab-fade-enter-from { opacity: 0; transform: translateY(8px); }
.tab-fade-leave-to   { opacity: 0; }
@media (prefers-reduced-motion: reduce) {
  .tab-fade-enter-active, .tab-fade-leave-active { transition: none; }
}
</style>
