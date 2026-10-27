<script setup lang="ts">
import type { Property } from '~/types'
import type { Nearby, NearbyKind } from '~/composables/useNearby'
import { NEARBY_ICON, formatDistance } from '~/composables/useNearby'

/**
 * Listing page: "How far from me?" (road time from the visitor's location,
 * asked only on tap) and "What's nearby", the closest hospitals, groceries,
 * churches, schools, transport and so on, each with drive and walk minutes.
 */
const props = defineProps<{ property: Property }>()
const { fetchNearby, travelFromHere } = useNearby()

const data = ref<Nearby | null>(null)
const loading = ref(true)
onMounted(async () => {
  data.value = await fetchNearby(props.property.id)
  loading.value = false
})

/* ── Groups ── */
const GROUPS: Array<{ key: '' | NearbyKind['group']; label: string }> = [
  { key: '', label: 'All' },
  { key: 'health', label: 'Health' },
  { key: 'shopping', label: 'Shopping' },
  { key: 'faith', label: 'Faith & schools' },
  { key: 'transport', label: 'Transport & services' },
]
const group = ref<'' | NearbyKind['group']>('')
const kinds = computed(() => (data.value?.kinds ?? []).filter(k => !group.value || k.group === group.value))
const present = computed(() => new Set((data.value?.kinds ?? []).map(k => k.group)))

/* One-line highlight: the essentials within a 10-minute drive. */
const within10 = computed(() => (data.value?.kinds ?? []).filter(k => (k.items[0]?.drive_min ?? 99) <= 10).length)

/* ── From me ── */
const fromMe = ref<{ drive_min: number; distance_km: number } | null>(null)
const fromMeError = ref('')
const locating = ref(false)
async function howFar() {
  locating.value = true
  fromMeError.value = ''
  try { fromMe.value = await travelFromHere(props.property.id) }
  catch (e) { fromMeError.value = (e as Error).message }
  finally { locating.value = false }
}
const directionsHref = computed(() => {
  const p = props.property
  return `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(p.lat && p.lng ? `${p.lat},${p.lng}` : p.address)}`
})
function hm(min: number): string {
  if (min < 60) return `${min} min`
  const h = Math.floor(min / 60), m = min % 60
  return m ? `${h} hr ${m} min` : `${h} hr`
}
</script>

<template>
  <div>
    <!-- ── How far from me ── -->
    <div class="flex flex-col sm:flex-row sm:items-center gap-4 rounded-2xl p-4 bg-brand-navy text-white ring-1 ring-brand-gold/25 relative overflow-hidden">
      <div class="absolute inset-0 bg-grid-gold opacity-[0.06] pointer-events-none" />
      <span class="relative h-11 w-11 flex-none rounded-xl flex items-center justify-center bg-white/10 text-brand-gold">
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" /></svg>
      </span>
      <div class="relative min-w-0 flex-1" aria-live="polite">
        <template v-if="fromMe">
          <p class="font-display text-xl font-bold leading-tight">{{ hm(fromMe.drive_min) }} <span class="text-sm font-semibold text-white/70">drive from you</span></p>
          <p class="text-sm text-white/65">{{ fromMe.distance_km }} km by road · light traffic</p>
        </template>
        <template v-else>
          <p class="font-semibold">How far is this from you?</p>
          <p class="text-sm" :class="fromMeError ? 'text-amber-300' : 'text-white/65'">
            {{ fromMeError || 'Uses your location once to estimate the drive. We don’t save it.' }}
          </p>
        </template>
      </div>
      <div class="relative flex flex-wrap gap-2">
        <button
          v-if="!fromMe"
          type="button"
          class="inline-flex items-center gap-1.5 rounded-full bg-brand-gold px-4 py-2 text-sm font-bold text-brand-navy shadow-[0_4px_14px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light transition disabled:opacity-60"
          :disabled="locating"
          @click="howFar"
        >
          <span v-if="locating" class="h-4 w-4 rounded-full border-2 border-brand-navy border-t-transparent animate-spin" aria-hidden="true" />
          <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
          {{ locating ? 'Finding you…' : fromMeError ? 'Try again' : 'Use my location' }}
        </button>
        <a
          :href="directionsHref"
          target="_blank"
          rel="noopener"
          class="inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-bold text-white ring-1 ring-white/25 hover:ring-brand-gold transition"
        >
          Directions
        </a>
      </div>
    </div>

    <!-- ── What's nearby ── -->
    <div class="mt-7 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h3 class="font-heading text-lg font-semibold text-brand-navy dark:text-white">What’s nearby</h3>
        <p v-if="within10" class="mt-0.5 text-sm text-brand-text-secondary dark:text-white/55">
          <span class="font-semibold text-brand-navy dark:text-white">{{ within10 }} of {{ data?.kinds.length }}</span> everyday essentials are within a 10-minute drive.
        </p>
      </div>
    </div>

    <!-- Group filter -->
    <div v-if="data?.kinds.length" class="mt-3 -mx-1 px-1 overflow-x-auto no-scrollbar">
      <div class="flex w-max gap-1.5" role="radiogroup" aria-label="Show">
        <button
          v-for="g in GROUPS.filter(g => !g.key || present.has(g.key))"
          :key="g.key || 'all'"
          type="button"
          role="radio"
          :aria-checked="group === g.key"
          class="whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition"
          :class="group === g.key
            ? 'bg-brand-navy text-white ring-brand-navy dark:bg-brand-gold dark:text-brand-navy dark:ring-brand-gold'
            : 'text-brand-navy/70 ring-brand-navy/15 hover:ring-brand-gold dark:text-white/70 dark:ring-white/15'"
          @click="group = g.key"
        >{{ g.label }}</button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
      <span v-for="i in 4" :key="i" class="h-[5.5rem] rounded-2xl bg-brand-navy/[0.05] dark:bg-white/[0.06] animate-pulse" />
    </div>

    <!-- Nothing to show -->
    <p v-else-if="!data?.kinds.length" class="mt-4 rounded-2xl bg-brand-cream dark:bg-white/[0.04] px-4 py-6 text-center text-sm text-brand-navy/55 dark:text-white/50">
      {{ data?.available === false ? 'This listing has no map location yet, so nearby places can’t be shown.' : 'Nearby places couldn’t be loaded right now. Please try again later.' }}
    </p>

    <!-- Kinds -->
    <TransitionGroup v-else tag="ul" name="nb" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
      <li
        v-for="k in kinds"
        :key="k.key"
        class="rounded-2xl p-3.5 bg-brand-cream/70 ring-1 ring-brand-navy/[0.05] dark:bg-white/[0.04] dark:ring-white/[0.06]"
      >
        <div class="flex items-center gap-2.5">
          <span class="h-8 w-8 flex-none rounded-lg flex items-center justify-center bg-white text-brand-gold-deep ring-1 ring-brand-navy/[0.06] dark:bg-white/[0.08] dark:text-brand-gold dark:ring-white/10">
            <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="NEARBY_ICON[k.key]" /></svg>
          </span>
          <p class="text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-brand-navy/50 dark:text-white/45">{{ k.label }}</p>
        </div>
        <ul class="mt-2.5 space-y-2">
          <li v-for="(p, i) in k.items" :key="i" class="flex items-start justify-between gap-3">
            <span class="min-w-0">
              <span class="block truncate text-sm" :class="i === 0 ? 'font-semibold text-brand-navy dark:text-white' : 'text-brand-navy/70 dark:text-white/65'">{{ p.name }}</span>
              <span class="block text-[0.6875rem] text-brand-navy/45 dark:text-white/40">{{ formatDistance(p.distance_m) }}</span>
            </span>
            <span class="flex-none text-right text-xs tabular-nums">
              <span v-if="p.drive_min" class="block font-bold text-brand-navy dark:text-white">{{ p.drive_min }} min <span class="font-medium text-brand-navy/50 dark:text-white/45">drive</span></span>
              <span v-if="p.walk_min && p.walk_min <= 30" class="block text-brand-navy/55 dark:text-white/50">{{ p.walk_min }} min walk</span>
            </span>
          </li>
        </ul>
      </li>
    </TransitionGroup>

    <p v-if="data?.kinds.length" class="mt-3 text-[0.6875rem] text-brand-navy/40 dark:text-white/35">
      Times are by road in light traffic. Places from OpenStreetMap via Geoapify, so a few may be missing or out of date.
    </p>
  </div>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
.nb-enter-active, .nb-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.nb-enter-from, .nb-leave-to { opacity: 0; transform: scale(0.97); }
.nb-leave-active { position: absolute; }
@media (prefers-reduced-motion: reduce) { .nb-enter-active, .nb-leave-active { transition: none; } }
</style>
