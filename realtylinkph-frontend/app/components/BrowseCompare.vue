<script setup lang="ts">
import type { Property } from '~/types'
import { COMPARE_MAX } from '~/composables/useCompare'

/**
 * Compare, in two parts:
 *  - a tray that rises from the bottom of Browse once something is picked,
 *    with the picks as thumbnails and one "Compare" action;
 *  - a dialog laying the picks side by side. Each row marks the best value
 *    (lowest price, lowest price per m², most rooms, largest area) in gold, so
 *    the answer to "which is the better deal" is visible without reading.
 */
const { picks, remove, clear } = useCompare()
const propertyHref = usePropertyHref()
const open = ref(false)

watch(() => picks.value.length, (n) => { if (n < 2) open.value = false })

watch(open, (on) => {
  if (import.meta.client) document.documentElement.style.overflow = on ? 'hidden' : ''
})
function onKey(e: KeyboardEvent) { if (e.key === 'Escape') open.value = false }
onMounted(() => document.addEventListener('keydown', onKey))
onUnmounted(() => {
  document.removeEventListener('keydown', onKey)
  if (import.meta.client) document.documentElement.style.overflow = ''
})

const TYPE_LABEL: Record<string, string> = {
  condo: 'Condo', house: 'House & Lot', apartment: 'Apartment', commercial: 'Commercial', lot: 'Lot',
}

function photoOf(p: Property) {
  const ph = p.photos?.find(x => !x.is_360) ?? p.photos?.[0]
  return ph ? (ph.thumb_url ?? ph.url) : null
}
function peso(n: number): string {
  return `₱${Math.round(n).toLocaleString('en-PH')}`
}
const area = (p: Property) => Number(p.floor_area) || Number(p.lot_area) || 0
const perSqm = (p: Property) => (area(p) ? Number(p.price) / area(p) : 0)
const has360 = (p: Property) => !!p.photos?.some(x => x.is_360)
function calm(t: string): string {
  const letters = t.replace(/[^A-Za-z]/g, '')
  return letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
}

interface Row {
  label: string
  value: (p: Property) => string
  /** Numeric score per listing; the best one (by `best`) gets the gold mark. */
  score?: (p: Property) => number
  best?: 'min' | 'max'
}
const ROWS: Row[] = [
  { label: 'Price',        value: p => peso(Number(p.price)) + (p.offer_type === 'rent' ? '/mo' : ''), score: p => Number(p.price), best: 'min' },
  { label: 'Price per m²', value: p => (perSqm(p) ? peso(perSqm(p)) : '—'), score: perSqm, best: 'min' },
  { label: 'Type',         value: p => `${TYPE_LABEL[p.type] ?? p.type} · ${p.offer_type === 'rent' ? 'For rent' : 'For sale'}` },
  { label: 'Bedrooms',     value: p => (p.bedrooms ? String(p.bedrooms) : '—'), score: p => p.bedrooms ?? 0, best: 'max' },
  { label: 'Bathrooms',    value: p => (p.bathrooms ? String(p.bathrooms) : '—'), score: p => p.bathrooms ?? 0, best: 'max' },
  { label: 'Floor area',   value: p => (Number(p.floor_area) ? `${Number(p.floor_area).toLocaleString('en-PH')} m²` : '—'), score: p => Number(p.floor_area) || 0, best: 'max' },
  { label: 'Lot area',     value: p => (Number(p.lot_area) ? `${Number(p.lot_area).toLocaleString('en-PH')} m²` : '—'), score: p => Number(p.lot_area) || 0, best: 'max' },
  { label: '360° tour',    value: p => (has360(p) ? 'Yes' : 'No') },
  { label: 'Location',     value: p => p.address },
  { label: 'Agent',        value: p => p.agent?.name ?? '—' },
]

/** Index of the winning listing for a row, or -1 when there's no clear winner. */
function bestIndex(row: Row): number {
  if (!row.score || !row.best) return -1
  const scores = picks.value.map(row.score)
  const valid = scores.filter(s => s > 0)
  if (valid.length < 2) return -1
  const target = row.best === 'min' ? Math.min(...valid) : Math.max(...valid)
  if (scores.filter(s => s === target).length > 1) return -1   // a tie isn't a win
  return scores.indexOf(target)
}
</script>

<template>
  <!-- ── Tray ── -->
  <Transition name="tray">
    <div
      v-if="picks.length"
      class="sticky bottom-5 z-30 mx-auto mt-8 w-full max-w-xl"
      role="region"
      aria-label="Compare listings"
    >
      <div class="flex items-center gap-3 rounded-full p-2 pl-3 bg-brand-navy text-white ring-1 ring-brand-gold/40
                  shadow-[0_20px_50px_-15px_rgba(6,24,58,0.7)] dark:bg-[#0d2550]">
        <div class="flex -space-x-2">
          <span v-for="p in picks" :key="p.id" class="relative">
            <img v-if="photoOf(p)" :src="photoOf(p)!" alt="" class="h-9 w-9 rounded-full object-cover ring-2 ring-brand-navy dark:ring-[#0d2550]" />
            <span v-else class="block h-9 w-9 rounded-full bg-white/10 ring-2 ring-brand-navy" />
          </span>
          <span
            v-for="i in (COMPARE_MAX - picks.length)"
            :key="`empty-${i}`"
            class="h-9 w-9 rounded-full border-2 border-dashed border-white/25 bg-brand-navy dark:bg-[#0d2550]"
            aria-hidden="true"
          />
        </div>
        <p class="min-w-0 flex-1 text-sm leading-tight">
          <span class="font-semibold">{{ picks.length }} of {{ COMPARE_MAX }} selected</span>
          <span class="block text-xs text-white/55">{{ picks.length < 2 ? 'Pick one more to compare' : 'Ready to compare' }}</span>
        </p>
        <button type="button" class="px-2 text-xs font-semibold text-white/60 hover:text-white underline-offset-4 hover:underline" @click="clear">Clear</button>
        <button
          type="button"
          class="rounded-full bg-brand-gold px-5 py-2.5 text-sm font-bold text-brand-navy shadow-[0_4px_14px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light disabled:opacity-40 disabled:shadow-none transition"
          :disabled="picks.length < 2"
          @click="open = true"
        >
          Compare
        </button>
      </div>
    </div>
  </Transition>

  <!-- ── Dialog ── -->
  <Teleport to="body">
    <Transition name="cmp">
      <div v-if="open" class="fixed inset-0 z-[95] flex items-end sm:items-center justify-center sm:p-6 bg-brand-navy-deep/60 backdrop-blur-sm" @click.self="open = false">
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="cmp-title"
          class="cmp-panel relative w-full sm:max-w-4xl max-h-[92vh] flex flex-col overflow-hidden rounded-t-[1.75rem] sm:rounded-[1.75rem]
                 bg-[#ffffff] dark:bg-[#0d2550] ring-1 ring-brand-navy/[0.06] dark:ring-white/10 shadow-[0_40px_100px_-30px_rgba(6,24,58,0.6)]"
        >
          <div class="flex items-center justify-between gap-4 px-6 pt-6 pb-4 border-b border-brand-navy/[0.06] dark:border-white/10">
            <div>
              <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold-deep dark:text-brand-gold">Compare</p>
              <h2 id="cmp-title" class="font-heading text-2xl font-semibold text-[#0B2A5C] dark:text-white">Side by side</h2>
            </div>
            <div class="flex items-center gap-3">
              <span class="hidden sm:inline-flex items-center gap-1.5 text-xs text-brand-navy/55 dark:text-white/50">
                <span class="h-2 w-2 rounded-full bg-brand-gold" aria-hidden="true" /> Best in row
              </span>
              <button type="button" class="h-9 w-9 rounded-full flex items-center justify-center text-brand-navy/60 hover:text-brand-navy hover:bg-brand-navy/5 dark:text-white/60 dark:hover:text-white dark:hover:bg-white/10 transition" aria-label="Close" @click="open = false">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
              </button>
            </div>
          </div>

          <div class="flex-1 overflow-auto">
            <!-- table-fixed: equal columns whatever the photos' sizes -->
            <table class="w-full min-w-[36rem] table-fixed border-separate border-spacing-0 text-sm">
              <colgroup>
                <col class="w-32 sm:w-40" />
                <col v-for="p in picks" :key="p.id" />
              </colgroup>
              <thead>
                <tr>
                  <th class="sticky left-0 z-10 bg-[#ffffff] dark:bg-[#0d2550]" />
                  <th v-for="p in picks" :key="p.id" class="p-4 align-top text-left font-normal">
                    <div class="relative overflow-hidden rounded-2xl aspect-[16/10] bg-brand-navy/5 dark:bg-white/10">
                      <img v-if="photoOf(p)" :src="photoOf(p)!" alt="" class="absolute inset-0 h-full w-full object-cover" />
                      <button
                        type="button"
                        class="absolute top-2 right-2 h-7 w-7 rounded-full flex items-center justify-center bg-black/45 text-white hover:bg-black/65 backdrop-blur-sm transition"
                        :aria-label="`Remove ${p.title} from comparison`"
                        @click="remove(p.id)"
                      >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                      </button>
                    </div>
                    <p class="mt-3 line-clamp-2 font-semibold leading-snug text-[#0B2A5C] dark:text-white">{{ calm(p.title) }}</p>
                    <NuxtLink :to="propertyHref(p.id)" class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline underline-offset-4" @click="open = false">
                      View listing
                      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                    </NuxtLink>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, r) in ROWS" :key="row.label" :class="r % 2 === 0 ? 'bg-brand-cream/70 dark:bg-white/[0.03]' : ''">
                  <th scope="row" class="sticky left-0 z-10 px-6 py-3 text-left text-xs font-bold uppercase tracking-wide text-brand-navy/50 dark:text-white/45"
                      :class="r % 2 === 0 ? 'bg-[#faf7f0] dark:bg-[#12295a]' : 'bg-[#ffffff] dark:bg-[#0d2550]'">
                    {{ row.label }}
                  </th>
                  <td
                    v-for="(p, i) in picks"
                    :key="p.id"
                    class="px-4 py-3 align-top"
                    :class="bestIndex(row) === i ? 'font-bold text-brand-gold-deep dark:text-brand-gold' : 'text-[#0B2A5C]/85 dark:text-white/80'"
                  >
                    <span class="inline-flex items-start gap-1.5">
                      <span v-if="bestIndex(row) === i" class="mt-1.5 h-2 w-2 flex-none rounded-full bg-brand-gold" aria-hidden="true" />
                      <span :class="row.label === 'Location' ? 'line-clamp-2' : ''">{{ row.value(p) }}</span>
                      <span v-if="bestIndex(row) === i" class="sr-only">(best)</span>
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.tray-enter-active, .tray-leave-active { transition: opacity 0.2s ease, transform 0.3s cubic-bezier(0.22, 1, 0.36, 1); }
.tray-enter-from, .tray-leave-to { opacity: 0; transform: translateY(16px); }

.cmp-enter-active, .cmp-leave-active { transition: opacity 0.2s ease; }
.cmp-enter-from, .cmp-leave-to { opacity: 0; }
.cmp-enter-active .cmp-panel { transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1); }
.cmp-enter-from .cmp-panel { transform: translateY(20px) scale(0.98); }

@media (prefers-reduced-motion: reduce) {
  .tray-enter-active, .tray-leave-active, .cmp-enter-active, .cmp-enter-active .cmp-panel { transition: none; }
}
</style>
