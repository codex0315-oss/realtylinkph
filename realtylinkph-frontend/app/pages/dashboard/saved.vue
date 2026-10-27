<script setup lang="ts">
import type { Property } from '~/types'

definePageMeta({ layout: 'dashboard' })

/*
 * Saved, top to bottom:
 *   header (count + a one-line summary of the shortlist) → filter / sort bar
 *   → the available homes (Compare + Book viewing on each card)
 *   → "No longer available" (sold or taken down), each with Remove.
 * Un-saving drops the card with an Undo toast instead of losing it for good.
 */
const { favorites, loading, fetchFavorites, toggleFavorite } = useFavorite()
const toast = useToast()
const compare = useCompare()
const propertyHref = usePropertyHref()

await fetchFavorites()

/* ── Available vs gone ── */
const available   = computed(() => favorites.value.filter(p => p.status === 'published'))
const unavailable = computed(() => favorites.value.filter(p => p.status !== 'published'))

/* ── Filter + sort ── */
type Offer = 'all' | 'sale' | 'rent'
type Sort = 'newest' | 'price_asc' | 'price_desc'
const offer = ref<Offer>('all')
const sort = ref<Sort>('newest')
const counts = computed(() => ({
  all:  available.value.length,
  sale: available.value.filter(p => p.offer_type !== 'rent').length,
  rent: available.value.filter(p => p.offer_type === 'rent').length,
}))
const OFFERS: Array<{ value: Offer; label: string }> = [
  { value: 'all', label: 'All' }, { value: 'sale', label: 'For sale' }, { value: 'rent', label: 'For rent' },
]
const SORTS: Array<{ value: Sort; label: string }> = [
  { value: 'newest', label: 'Newest' }, { value: 'price_asc', label: 'Price ↑' }, { value: 'price_desc', label: 'Price ↓' },
]
const shown = computed(() => {
  const list = available.value.filter(p =>
    offer.value === 'all' || (offer.value === 'rent' ? p.offer_type === 'rent' : p.offer_type !== 'rent'))
  return [...list].sort((a, b) =>
    sort.value === 'price_asc'  ? Number(a.price) - Number(b.price)
    : sort.value === 'price_desc' ? Number(b.price) - Number(a.price)
    : +new Date(b.created_at) - +new Date(a.created_at))
})

/* ── Remove with Undo ── */
function dropLocal(p: Property) {
  favorites.value = favorites.value.filter(x => x.id !== p.id)
  compare.remove(p.id)
}
function offerUndo(p: Property, index: number) {
  toast.withAction('Removed from Saved', 'Undo', async () => {
    try {
      const on = await toggleFavorite(p.id)
      if (!on) return
      const list = [...favorites.value]
      list.splice(Math.min(index, list.length), 0, { ...p, is_favorited: true })
      favorites.value = list
    } catch { toast.error('Couldn’t restore it. Please save it again from the listing.') }
  })
}
/* The card already un-saved it on the server; take it off the page. */
function onToggle(payload: { id: number; favorited: boolean }) {
  if (payload.favorited) return
  const index = favorites.value.findIndex(p => p.id === payload.id)
  const p = favorites.value[index]
  if (!p) return
  dropLocal(p)
  offerUndo(p, index)
}
/* "No longer available" rows remove through the API directly. */
const removing = ref<number | null>(null)
async function removeGone(p: Property) {
  removing.value = p.id
  try {
    const on = await toggleFavorite(p.id)
    // Already un-saved in another tab, so that toggle re-saved it: undo it.
    if (on) await toggleFavorite(p.id)
    const index = favorites.value.findIndex(x => x.id === p.id)
    dropLocal(p)
    offerUndo(p, index)
  } catch {
    toast.error('Couldn’t remove it. Please try again.')
  } finally {
    removing.value = null
  }
}

function photoOf(p: Property) {
  const ph = p.photos?.find(x => !x.is_360) ?? p.photos?.[0]
  return ph ? (ph.thumb_url ?? ph.url) : null
}
function calm(t: string): string {
  const letters = t.replace(/[^A-Za-z]/g, '')
  return letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
}

const pill = (on: boolean) =>
  'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3.5 py-1.5 text-sm font-semibold transition ' +
  (on
    ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-brand-navy'
    : 'text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white')
</script>

<template>
  <div class="max-w-6xl mx-auto">

    <!-- ── Header ── -->
    <header class="flex flex-wrap items-end justify-between gap-4 mb-6">
      <div class="min-w-0">
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Your shortlist</p>
        <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">
          Saved homes
        </h1>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
          <template v-if="favorites.length">
            <span class="font-semibold text-brand-navy dark:text-white">{{ favorites.length }}</span>
            {{ favorites.length === 1 ? 'home' : 'homes' }} you’ve hearted.
            <template v-if="available.length > 1">Pick two or three to compare side by side.</template>
          </template>
          <template v-else>Homes you heart are kept here.</template>
        </p>
      </div>
      <DashCta to="/dashboard/browse">
        <svg class="h-4 w-4 transition-transform duration-300 group-hover:scale-110" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        Find more homes
      </DashCta>
    </header>

    <!-- ── Filter + sort ── -->
    <div v-if="available.length > 1" class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <div class="inline-flex items-center gap-1 rounded-full p-1 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10" role="radiogroup" aria-label="Show">
        <button
          v-for="o in OFFERS"
          :key="o.value"
          type="button"
          role="radio"
          :aria-checked="offer === o.value"
          :class="pill(offer === o.value)"
          :disabled="o.value !== 'all' && !counts[o.value]"
          @click="offer = o.value"
        >
          {{ o.label }}
          <span class="text-xs tabular-nums" :class="offer === o.value ? 'opacity-75' : 'opacity-50'">{{ counts[o.value] }}</span>
        </button>
      </div>
      <div class="inline-flex items-center gap-1 rounded-full p-1 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10" role="radiogroup" aria-label="Sort">
        <span class="pl-2.5 pr-1 text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-brand-navy/40 dark:text-white/35">Sort</span>
        <button
          v-for="s in SORTS"
          :key="s.value"
          type="button"
          role="radio"
          :aria-checked="sort === s.value"
          :class="pill(sort === s.value)"
          @click="sort = s.value"
        >
          {{ s.label }}
        </button>
      </div>
    </div>

    <!-- ── Available homes ── -->
    <PropertyGrid
      v-if="loading || shown.length"
      :properties="shown"
      :loading="loading"
      :skeleton-count="4"
      stagger
      quiet-toggle
      @toggle="onToggle"
    >
      <template #overlay="{ property: p }">
        <!-- One row, so the two can never overlap; "viewing" drops on narrow cards. -->
        <div class="saved-actions pointer-events-none absolute inset-x-[2px] top-[2px] aspect-[4/3]">
          <div class="absolute inset-x-3 bottom-3 flex items-center justify-between gap-2">
            <CompareToggle :property="p" class="pointer-events-auto flex-none" />
            <NuxtLink
              :to="`${propertyHref(p.id)}?book=1`"
              class="pointer-events-auto inline-flex flex-none items-center gap-1 rounded-full px-3 py-1 text-[0.6875rem] font-bold whitespace-nowrap
                     bg-brand-gold text-brand-navy shadow-[0_4px_12px_rgba(0,0,0,0.3)] hover:bg-brand-gold-light transition"
              aria-label="Book a viewing"
              @click.stop
            >
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
              Book<span class="saved-viewing">&nbsp;viewing</span>
            </NuxtLink>
          </div>
        </div>
      </template>
    </PropertyGrid>

    <!-- Filter left nothing -->
    <div
      v-else-if="available.length"
      class="rounded-2xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] px-6 py-12 text-center"
    >
      <p class="font-semibold text-brand-navy dark:text-white">No saved homes {{ offer === 'rent' ? 'for rent' : 'for sale' }}</p>
      <button type="button" class="mt-3 text-sm font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline underline-offset-4" @click="offer = 'all'">
        Show all saved homes
      </button>
    </div>

    <!-- ── Empty ── -->
    <div v-if="!loading && !favorites.length" class="relative overflow-hidden rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] px-6 py-16 text-center">
      <div class="absolute -top-16 left-1/2 -translate-x-1/2 h-48 w-80 rounded-full bg-brand-gold/10 blur-3xl pointer-events-none" />
      <div class="relative mx-auto mb-5 h-16 w-16 rounded-2xl bg-brand-gold/15 flex items-center justify-center">
        <svg class="h-8 w-8 text-brand-gold-deep dark:text-brand-gold saved-beat" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M11.645 20.91l-.007-.003-.022-.012a15.247 15.247 0 01-.383-.218 25.18 25.18 0 01-4.244-3.17C4.688 15.36 2.25 12.174 2.25 8.25 2.25 5.322 4.714 3 7.688 3A5.5 5.5 0 0112 5.052 5.5 5.5 0 0116.313 3c2.973 0 5.437 2.322 5.437 5.25 0 3.925-2.438 7.111-4.739 9.256a25.175 25.175 0 01-4.244 3.17 15.247 15.247 0 01-.383.219l-.022.012-.007.004-.003.001a.752.752 0 01-.704 0l-.003-.001z" />
        </svg>
      </div>
      <p class="relative font-display text-xl font-bold text-brand-navy dark:text-white">Start your shortlist</p>
      <p class="relative mx-auto mt-2 max-w-sm text-sm text-brand-text-secondary dark:text-white/55 leading-relaxed">
        Tap the heart on any listing to keep it here. Then compare your favourites side by side and book a viewing in a tap.
      </p>
      <NuxtLink to="/dashboard/browse" class="relative mt-6 inline-flex items-center gap-1.5 rounded-full bg-brand-navy px-5 py-2.5 text-sm font-bold text-white dark:bg-brand-gold dark:text-brand-navy hover:-translate-y-0.5 transition">
        Browse listings
      </NuxtLink>
    </div>

    <!-- ── No longer available ── -->
    <section v-if="unavailable.length" class="mt-12">
      <h2 class="mb-3 text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">No longer available</h2>
      <ul class="space-y-2">
        <li
          v-for="p in unavailable"
          :key="p.id"
          class="flex items-center gap-3 rounded-2xl p-2 pr-3 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]"
        >
          <span class="relative h-14 w-20 flex-none overflow-hidden rounded-xl bg-brand-navy/5 dark:bg-white/10">
            <img v-if="photoOf(p)" :src="photoOf(p)!" alt="" class="h-full w-full object-cover grayscale opacity-70" loading="lazy" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-semibold text-brand-navy/70 dark:text-white/70 line-through decoration-brand-navy/30 dark:decoration-white/30">{{ calm(p.title) }}</span>
            <span class="mt-0.5 inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold"
                  :class="p.status === 'sold' ? 'bg-brand-navy/10 text-brand-navy dark:bg-white/10 dark:text-white/80' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400'">
              {{ p.status === 'sold' ? 'Sold' : 'No longer listed' }}
            </span>
          </span>
          <button
            type="button"
            class="flex-none rounded-full px-3.5 py-1.5 text-xs font-bold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-red-400 hover:text-red-600
                   dark:text-white dark:ring-white/20 dark:hover:text-red-400 transition disabled:opacity-50"
            :disabled="removing === p.id"
            @click="removeGone(p)"
          >
            {{ removing === p.id ? 'Removing…' : 'Remove' }}
          </button>
        </li>
      </ul>
    </section>

    <!-- Compare tray + dialog (shared with Browse) -->
    <BrowseCompare />
  </div>
</template>

<style scoped>
.saved-beat { animation: saved-beat 2.4s ease-in-out infinite; }
@keyframes saved-beat {
  0%, 60%, 100% { transform: scale(1); }
  30% { transform: scale(1.12); }
}
@media (prefers-reduced-motion: reduce) { .saved-beat { animation: none; } }

/* Compare + "Book viewing" need ~240px side by side; below that it's "Book". */
.saved-actions { container-type: inline-size; }
@container (max-width: 15rem) { .saved-viewing { display: none; } }
</style>
