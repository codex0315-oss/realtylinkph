<script setup lang="ts">
import type { PropertyType, OfferType } from '~/types'
import { PROPERTY_TYPES } from '~/types'

interface Props {
  /** When embedded (e.g. inside the dashboard) skip the page background + max-width wrapper. */
  embedded?: boolean
}
withDefaults(defineProps<Props>(), { embedded: false })

const route  = useRoute()
const router = useRouter()
const { properties, pagination, loading, fetchProperties } = useProperty()

const filters = reactive({
  search:     (route.query.search as string) ?? '',
  type:       (route.query.type as PropertyType | '') ?? '',
  offer_type: (route.query.offer_type as OfferType | '') ?? '',
  min_price:  route.query.min_price ? Number(route.query.min_price) : undefined,
  max_price: route.query.max_price ? Number(route.query.max_price) : undefined,
  bedrooms:  route.query.bedrooms  ? Number(route.query.bedrooms)  : undefined,
})

// Proxy so the Beds <select> can map "" → undefined while keeping the number type.
const bedroomsModel = computed<string | number>({
  get: () => filters.bedrooms ?? '',
  set: (v) => { filters.bedrooms = v === '' ? undefined : Number(v) },
})

const page    = ref(Number(route.query.page ?? 1))
const showMap = ref(false)

async function load() {
  await fetchProperties({
    page:       page.value,
    search:     filters.search    || undefined,
    type:       (filters.type as PropertyType) || undefined,
    offer_type: (filters.offer_type as OfferType) || undefined,
    min_price:  filters.min_price || undefined,
    max_price: filters.max_price || undefined,
    bedrooms:  filters.bedrooms  || undefined,
  })
}

async function applyFilters() {
  page.value = 1
  await load()
  router.replace({ query: Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== undefined && v !== '')) })
}

async function clearFilters() {
  Object.assign(filters, { search: '', type: '', offer_type: '', min_price: undefined, max_price: undefined, bedrooms: undefined })
  await applyFilters()
}

async function goPage(n: number) {
  page.value = n
  await load()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

await load()

const fieldClass =
  'w-full px-3.5 py-2.5 text-sm border border-gray-200 dark:border-white/10 rounded-xl bg-gray-50 dark:bg-white/5 dark:text-white focus:bg-white dark:focus:bg-white/10 focus:outline-none focus:ring-2 focus:ring-brand-gold/30 focus:border-brand-gold transition-colors'
const labelClass = 'block text-[10px] font-bold uppercase tracking-wide text-brand-navy/50 dark:text-white/40 mb-1.5'
</script>

<template>
  <!-- Standalone: no background of its own, so the page's grid shows through.
       Embedded (dashboard): no chrome at all, the layout provides it. -->
  <div :class="embedded ? '' : 'pb-16'">
    <div :class="embedded ? '' : 'max-w-content mx-auto px-6'">

      <!-- ── Page heading + filter bar, side by side ──
           The heading arrives as a slot so it can sit beside the filters rather
           than stacking above them, which hands the results grid the height back.
           With no slot supplied (the dashboard) the panel just spans the row. -->
      <div class="flex flex-col lg:flex-row lg:items-end gap-4 lg:gap-6 mb-5">

        <div v-if="$slots.heading" class="lg:w-[19rem] xl:w-[21rem] flex-shrink-0">
          <slot name="heading" />
        </div>

        <!-- Translucent so the page's ruled grid reads through the panel -->
        <div class="flex-1 min-w-0 bg-white/80 dark:bg-white/[0.06] backdrop-blur-md rounded-2xl
                    border border-white/70 dark:border-white/10 ring-1 ring-brand-navy/5
                    shadow-[0_10px_30px_-12px_rgba(8,21,47,0.18)] p-3 sm:p-4">
          <!-- Wraps to two tidy rows so the six controls still fit once the
               heading has taken its share of the width. -->
          <div class="grid grid-cols-2 lg:grid-cols-6 gap-3">
            <!-- Search -->
            <div class="col-span-2 lg:col-span-4">
              <label :class="labelClass">Location</label>
              <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400 dark:text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input
                  v-model="filters.search"
                  type="text"
                  placeholder="City, barangay, keyword..."
                  :class="fieldClass"
                  class="!pl-9"
                  @keydown.enter="applyFilters"
                />
              </div>
            </div>

            <!-- Property type -->
            <div class="col-span-1">
              <label :class="labelClass">Type</label>
              <select v-model="filters.type" :class="fieldClass">
                <option value="">All types</option>
                <option v-for="(label, key) in PROPERTY_TYPES" :key="key" :value="key">{{ label }}</option>
              </select>
            </div>

            <!-- Buy / Rent — the homepage search bar deep-links here with this set -->
            <div class="col-span-1">
              <label :class="labelClass">Buy / Rent</label>
              <select v-model="filters.offer_type" :class="fieldClass">
                <option value="">Both</option>
                <option value="sale">Buy</option>
                <option value="rent">Rent</option>
              </select>
            </div>

            <!-- Min price -->
            <div>
              <label :class="labelClass">Min ₱</label>
              <input v-model.number="filters.min_price" type="number" placeholder="0" :class="fieldClass" />
            </div>

            <!-- Max price -->
            <div>
              <label :class="labelClass">Max ₱</label>
              <input v-model.number="filters.max_price" type="number" placeholder="Any" :class="fieldClass" />
            </div>

            <!-- Bedrooms -->
            <div>
              <label :class="labelClass">Beds</label>
              <select v-model="bedroomsModel" :class="fieldClass">
                <option value="">Any</option>
                <option v-for="n in [1, 2, 3, 4, 5]" :key="n" :value="n">{{ n }}+</option>
              </select>
            </div>

            <!-- Search button — pinned to the bottom of its cell so it lines up
                 with the inputs, which each carry a label above them. -->
            <div class="flex items-end lg:col-span-3">
              <button
                class="w-full flex items-center justify-center gap-2 bg-brand-gold text-brand-navy font-bold text-sm px-4 py-2.5 rounded-xl hover:bg-brand-gold-light hover:-translate-y-0.5 transition-all shadow-[0_4px_14px_rgba(212,175,55,0.3)]"
                @click="applyFilters"
              >
                <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                Search
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Results header: count + Map/List toggle ── -->
      <div class="flex items-center justify-between mb-4">
        <p class="text-sm text-gray-500 dark:text-white/50">
          <template v-if="loading">Loading properties…</template>
          <template v-else-if="pagination">
            <span class="font-bold text-brand-navy dark:text-white">{{ pagination.total }}</span>
            propert{{ pagination.total === 1 ? 'y' : 'ies' }} found
          </template>
        </p>

        <div class="flex items-center gap-3">
          <button class="text-xs text-brand-gold hover:underline font-medium" @click="clearFilters">Clear filters</button>
          <div class="inline-flex items-center bg-white/80 dark:bg-white/[0.06] backdrop-blur-md border border-white/70 dark:border-white/10 ring-1 ring-brand-navy/5 rounded-xl p-1">
            <button
              class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all"
              :class="!showMap ? 'bg-brand-navy text-white' : 'text-gray-500 dark:text-white/50 hover:text-brand-navy dark:hover:text-white'"
              @click="showMap = false"
            >
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
              </svg>
              List
            </button>
            <button
              class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all"
              :class="showMap ? 'bg-brand-navy text-white' : 'text-gray-500 dark:text-white/50 hover:text-brand-navy dark:hover:text-white'"
              @click="showMap = true"
            >
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
              </svg>
              Map
            </button>
          </div>
        </div>
      </div>

      <!-- ── Split: results + map ── -->
      <div class="flex gap-5">
        <div :class="showMap ? 'w-full lg:w-1/2 min-w-0' : 'w-full'">
          <PropertyGrid :properties="properties" :loading="loading" :skeleton-count="showMap ? 4 : 8" :dense="showMap" />

          <!-- Empty state -->
          <div v-if="!loading && !properties.length" class="flex flex-col items-center justify-center py-20 text-center">
            <div class="h-16 w-16 rounded-full bg-gray-100 dark:bg-white/5 flex items-center justify-center mb-4">
              <svg class="h-8 w-8 text-gray-300 dark:text-white/25" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
              </svg>
            </div>
            <p class="font-semibold text-brand-navy dark:text-white text-base">No properties found</p>
            <p class="text-sm text-gray-400 dark:text-white/40 mt-1">Try adjusting your filters or search term</p>
            <button class="mt-5 text-sm font-semibold text-brand-gold hover:underline" @click="clearFilters">Clear all filters</button>
          </div>

          <!-- Pagination -->
          <div v-if="pagination && pagination.last_page > 1" class="flex items-center justify-center gap-2 mt-10">
            <button
              class="h-9 w-9 rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-[#10264D] text-brand-navy dark:text-white flex items-center justify-center hover:border-brand-gold hover:text-brand-gold transition-all disabled:opacity-40 disabled:cursor-not-allowed shadow-sm"
              :disabled="page <= 1"
              @click="goPage(page - 1)"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
              </svg>
            </button>

            <button
              v-for="p in pagination.last_page"
              :key="p"
              class="h-9 w-9 rounded-xl text-sm font-semibold transition-all shadow-sm"
              :class="p === page
                ? 'bg-brand-navy text-white border border-brand-navy'
                : 'bg-white dark:bg-[#10264D] text-gray-600 dark:text-white/60 border border-gray-200 dark:border-white/10 hover:border-brand-gold hover:text-brand-gold'"
              @click="goPage(p)"
            >
              {{ p }}
            </button>

            <button
              class="h-9 w-9 rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-[#10264D] text-brand-navy dark:text-white flex items-center justify-center hover:border-brand-gold hover:text-brand-gold transition-all disabled:opacity-40 disabled:cursor-not-allowed shadow-sm"
              :disabled="page >= pagination.last_page"
              @click="goPage(page + 1)"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
              </svg>
            </button>
          </div>
        </div>

        <!-- Map panel -->
        <div v-if="showMap" class="hidden lg:block lg:w-1/2 flex-shrink-0">
          <div class="sticky top-24" style="height: calc(100vh - 7rem)">
            <ClientOnly>
              <PropertyMap :properties="properties" />
              <template #fallback>
                <div class="w-full h-full rounded-2xl bg-gray-100 dark:bg-white/5 animate-pulse" />
              </template>
            </ClientOnly>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>
