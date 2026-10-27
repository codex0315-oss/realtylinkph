<script setup lang="ts">
import type { PropertyType, OfferType, PropertySort } from '~/types'

interface Props {
  /** When embedded (e.g. inside the dashboard) skip the page hero + backgrounds. */
  embedded?: boolean
}
const props = withDefaults(defineProps<Props>(), { embedded: false })

/*
 * Browse, top to bottom:
 *   hero (heading slot + the same search pill as the homepage + type chips)
 *   → toolbar (result count, Filters panel, sort, list/map)
 *   → active-filter chips → results grid (+ map) → pagination.
 *
 * Embedded in the dashboard it trades the hero for a page header with a live
 * summary ("6 condos for sale in Cebu City"), and a toolbar that sticks under
 * the top bar: type chips + Filters / Sort / Grid-Map, then one-tap quick
 * filters. Location moves into the Filters panel. Cards get a Compare toggle,
 * and the end of the results offers RealtyLink AI with the current filters.
 *
 * The URL is the source of truth. The search pill pushes new query params;
 * the Filters panel, chips and sort write them with `replace`. A watcher on
 * the query reloads only when the filters it describes actually changed, so
 * our own writes never trigger a second request.
 *
 * Changing filters doesn't blank the page: the current cards dim while the
 * next set loads, then the new set rises in (PropertyGrid `stagger`).
 * Skeletons only appear when there's nothing to show yet.
 */
const route  = useRoute()
const router = useRouter()
const { properties, pagination, loading, fetchProperties } = useProperty()

interface Filters {
  search: string
  type: PropertyType | ''
  offer_type: OfferType | ''
  min_price: number | undefined
  max_price: number | undefined
  bedrooms: number | undefined
  has_360: boolean
  sort: PropertySort
}
const FILTER_KEYS = ['search', 'type', 'offer_type', 'min_price', 'max_price', 'bedrooms', 'has_360', 'sort'] as const

const TYPES: Array<{ value: PropertyType | ''; label: string }> = [
  { value: '',           label: 'All types' },
  { value: 'condo',      label: 'Condo' },
  { value: 'house',      label: 'House & Lot' },
  { value: 'apartment',  label: 'Apartment' },
  { value: 'commercial', label: 'Commercial' },
  { value: 'lot',        label: 'Lot' },
]
const SORTS: Array<{ value: PropertySort; label: string }> = [
  { value: 'newest',     label: 'Newest first' },
  { value: 'price_asc',  label: 'Price: low to high' },
  { value: 'price_desc', label: 'Price: high to low' },
]

function num(v: unknown): number | undefined {
  const n = typeof v === 'string' && v !== '' ? Number(v) : NaN
  return Number.isFinite(n) && n > 0 ? n : undefined
}
function fromQuery(q: typeof route.query): Filters {
  const str = (v: unknown) => (typeof v === 'string' ? v : '')
  const type = str(q.type)
  const offer = str(q.offer_type)
  const sort = str(q.sort)
  return {
    search:     str(q.search),
    type:       TYPES.some(t => t.value === type) ? (type as PropertyType | '') : '',
    offer_type: offer === 'sale' || offer === 'rent' ? offer : '',
    min_price:  num(q.min_price),
    max_price:  num(q.max_price),
    bedrooms:   num(q.bedrooms),
    has_360:    q.has_360 === '1',
    sort:       SORTS.some(s => s.value === sort) ? (sort as PropertySort) : 'newest',
  }
}

const filters = reactive<Filters>(fromQuery(route.query))
const page = ref(Number(route.query.page ?? 1))

/*
 * Map/List lives in the URL (`?view=map`), not component state. A buyer who
 * opens the map, taps a pin, reads the listing and presses Back should land
 * on the map they were exploring.
 */
const showMap = computed<boolean>({
  get: () => route.query.view === 'map',
  set: (on) => {
    const query = { ...route.query }
    if (on) query.view = 'map'
    else delete query.view
    router.replace({ query })
  },
})

/** Filter values worth keeping in the URL — drops blanks and the default sort. */
function queryFromFilters(): Record<string, string> {
  const q: Record<string, string> = {}
  for (const k of FILTER_KEYS) {
    const v = filters[k]
    if (v === undefined || v === '' || v === false || (k === 'sort' && v === 'newest')) continue
    q[k] = v === true ? '1' : String(v)
  }
  if (showMap.value) q.view = 'map'
  return q
}

async function load() {
  await fetchProperties({
    page:       page.value,
    search:     filters.search || undefined,
    type:       filters.type || undefined,
    offer_type: filters.offer_type || undefined,
    min_price:  filters.min_price,
    max_price:  filters.max_price,
    bedrooms:   filters.bedrooms,
    has_360:    filters.has_360 ? 1 : undefined,
    sort:       filters.sort === 'newest' ? undefined : filters.sort,
  })
}

async function applyFilters() {
  page.value = 1
  router.replace({ query: queryFromFilters() })
  await load()
}
function setFilters(patch: Partial<Filters>) {
  Object.assign(filters, patch)
  applyFilters()
}
/* ── Sort menu ─────────────────────────────────────────────────────────── */
const sortOpen = ref(false)
const sortActive = ref(0)
const sortEl = ref<HTMLElement | null>(null)
const sortBtn = ref<HTMLButtonElement | null>(null)
const sortList = ref<HTMLElement | null>(null)
const sortLabel = computed(() => SORTS.find(s => s.value === filters.sort)!.label)

/* Opening starts the highlight on the current choice and moves focus into
   the list, so arrows / Enter / Esc work straight away. */
watch(sortOpen, (on) => {
  if (!on) return
  panelOpen.value = false
  sortActive.value = Math.max(0, SORTS.findIndex(s => s.value === filters.sort))
  nextTick(() => sortList.value?.focus())
})
function openSort() { sortOpen.value = true }
function pickSort(sort: PropertySort) {
  sortOpen.value = false
  sortBtn.value?.focus()
  if (sort !== filters.sort) setFilters({ sort })
}

function clearAll() {
  setFilters({ search: '', type: '', offer_type: '', min_price: undefined, max_price: undefined, bedrooms: undefined, has_360: false })
}

/* The search pill (and Back/Forward) change the URL; follow it. */
watch(
  () => FILTER_KEYS.map(k => route.query[k]),
  () => {
    const next = fromQuery(route.query)
    if (FILTER_KEYS.every(k => next[k] === filters[k])) return
    Object.assign(filters, next)
    page.value = 1
    load()
  },
)

const resultsEl = ref<HTMLElement | null>(null)
async function goPage(n: number) {
  page.value = n
  await load()
  const top = (resultsEl.value?.getBoundingClientRect().top ?? 0) + window.scrollY - 110
  window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' })
}

await load()

/* ── Filters panel (draft until Apply) ─────────────────────────────────── */
const panelOpen = ref(false)
const panelEl = ref<HTMLElement | null>(null)
const draft = reactive({
  // Location (title / address text). Only the embedded panel shows a field
  // for it; on the public page the search pill owns it.
  search: '',
  // Embedded only: type and 360° live in the panel there (no chips row).
  type: '' as PropertyType | '',
  has_360: false,
  offer_type: '' as OfferType | '',
  min_price: undefined as number | undefined,
  max_price: undefined as number | undefined,
  bedrooms: undefined as number | undefined,
})
function openPanel() {
  Object.assign(draft, {
    search: filters.search,
    type: filters.type, has_360: filters.has_360,
    offer_type: filters.offer_type, min_price: filters.min_price,
    max_price: filters.max_price, bedrooms: filters.bedrooms,
  })
  sortOpen.value = false
  panelOpen.value = true
  if (props.embedded) loadCities()
}
function applyPanel() {
  const lo = draft.min_price, hi = draft.max_price
  // A reversed range is almost always the two boxes typed the wrong way round.
  const swap = lo !== undefined && hi !== undefined && lo > hi
  const { type, has_360, ...rest } = draft
  setFilters({
    ...rest,
    ...(props.embedded ? { type, has_360 } : {}),   // public page: chips own these
    search: draft.search.trim(),
    min_price: swap ? hi : lo, max_price: swap ? lo : hi,
  })
  panelOpen.value = false
}
function resetDraft() {
  Object.assign(draft, { offer_type: '', min_price: undefined, max_price: undefined, bedrooms: undefined })
  if (props.embedded) Object.assign(draft, { search: '', type: '', has_360: false })
}

/* Busiest cities, offered as one-tap picks under the Location field. */
const api = useApi()
const cities = ref<Array<{ name: string; count: number }>>([])
async function loadCities() {
  if (cities.value.length) return
  try {
    const res = await api.get<{ data: Array<{ name: string; count: number }> }>('/properties/cities')
    cities.value = res.data.slice(0, 6)
  } catch { /* optional */ }
}
function priceModel(key: 'min_price' | 'max_price') {
  return computed<string>({
    get: () => (draft[key] ? draft[key]!.toLocaleString('en-PH') : ''),
    set: (v) => { const d = v.replace(/\D/g, '').slice(0, 12); draft[key] = d ? Number(d) : undefined },
  })
}
const minModel = priceModel('min_price')
const maxModel = priceModel('max_price')

const panelCount = computed(() =>
  [
    ...(props.embedded ? [filters.search, filters.type, filters.has_360 || ''] : []),
    filters.offer_type, filters.min_price, filters.max_price, filters.bedrooms,
  ].filter(v => v !== undefined && v !== '').length,
)

function onDocPointer(e: PointerEvent) {
  const t = e.target as Node
  if (panelOpen.value && panelEl.value && !panelEl.value.contains(t)) panelOpen.value = false
  if (sortOpen.value && sortEl.value && !sortEl.value.contains(t)) sortOpen.value = false
}
function onKey(e: KeyboardEvent) {
  if (e.key !== 'Escape') return
  if (sortOpen.value) { sortOpen.value = false; sortBtn.value?.focus() }
  panelOpen.value = false
}
onMounted(() => {
  document.addEventListener('pointerdown', onDocPointer)
  document.addEventListener('keydown', onKey)
})
onUnmounted(() => {
  document.removeEventListener('pointerdown', onDocPointer)
  document.removeEventListener('keydown', onKey)
})

/* ── Active-filter chips ───────────────────────────────────────────────── */
function peso(n: number): string {
  if (n >= 1_000_000) return `₱${+(n / 1_000_000).toFixed(1)}M`
  if (n >= 1_000) return `₱${+(n / 1_000).toFixed(0)}K`
  return `₱${n.toLocaleString('en-PH')}`
}
const chips = computed(() => {
  const out: Array<{ key: string; label: string; clear: Partial<Filters> }> = []
  if (filters.search) out.push({ key: 'search', label: `“${filters.search}”`, clear: { search: '' } })
  if (filters.type) out.push({ key: 'type', label: TYPES.find(t => t.value === filters.type)!.label, clear: { type: '' } })
  if (filters.offer_type) out.push({ key: 'offer', label: filters.offer_type === 'rent' ? 'For rent' : 'For sale', clear: { offer_type: '' } })
  const { min_price: lo, max_price: hi } = filters
  if (lo || hi) {
    out.push({
      key: 'price',
      label: lo && hi ? `${peso(lo)} – ${peso(hi)}` : hi ? `Up to ${peso(hi)}` : `From ${peso(lo!)}`,
      clear: { min_price: undefined, max_price: undefined },
    })
  }
  if (filters.bedrooms) out.push({ key: 'beds', label: `${filters.bedrooms}+ beds`, clear: { bedrooms: undefined } })
  if (filters.has_360) out.push({ key: '360', label: '360° tour', clear: { has_360: false } })
  return out
})

/* "Under ₱3M" shortcut in the Filters panel. */
const UNDER = 3_000_000
const anyFilter = computed(() => chips.value.length > 0)

/* ── Embedded: one-line summary of what's on screen ────────────────────── */
const NOUN: Record<string, [string, string]> = {
  '': ['home', 'homes'], condo: ['condo', 'condos'], house: ['house & lot', 'houses & lots'],
  apartment: ['apartment', 'apartments'], commercial: ['commercial space', 'commercial spaces'], lot: ['lot', 'lots'],
}
/** "condos for sale in Cebu City under ₱3M" — `count` picks singular/plural. */
function describe(count?: number): string {
  const parts = [NOUN[filters.type]![count === 1 ? 0 : 1]]
  if (filters.offer_type) parts.push(filters.offer_type === 'rent' ? 'for rent' : 'for sale')
  if (filters.search) parts.push(`in ${filters.search}`)
  const { min_price: lo, max_price: hi } = filters
  if (lo && hi) parts.push(`between ${peso(lo)} and ${peso(hi)}`)
  else if (hi) parts.push(`under ${peso(hi)}`)
  else if (lo) parts.push(`from ${peso(lo)}`)
  const withs = [filters.bedrooms ? `${filters.bedrooms}+ bedrooms` : '', filters.has_360 ? 'a 360° tour' : ''].filter(Boolean)
  if (withs.length) parts.push(`with ${withs.join(' and ')}`)
  return parts.join(' ')
}
const summary = computed(() => {
  if (loading.value && !properties.value.length) return { count: '', rest: 'Searching…' }
  const n = pagination.value?.total ?? properties.value.length
  const order = filters.sort === 'newest' ? 'newest first' : sortLabel.value.toLowerCase()
  return { count: n.toLocaleString('en-PH'), rest: `${describe(n)}, ${order}.` }
})

/* ── Embedded: sticky toolbar gets a surface once it's actually stuck ──── */
const stickSentinel = ref<HTMLElement | null>(null)
const stuck = ref(false)
let stickObserver: IntersectionObserver | null = null
onMounted(() => {
  if (!props.embedded || !stickSentinel.value) return
  // The dashboard top bar is 5rem (80px) tall; the toolbar sticks right under it.
  stickObserver = new IntersectionObserver(([e]) => { stuck.value = !e!.isIntersecting }, { rootMargin: '-81px 0px 0px 0px' })
  stickObserver.observe(stickSentinel.value)
})
onUnmounted(() => stickObserver?.disconnect())

/* ── Embedded: compare + RealtyLink AI ─────────────────────────────────── */
const { open: openDashPanel } = useDashPanel()
const { send: askAi } = useAiThread()
function askAiWithFilters() {
  const prompt = anyFilter.value
    ? `Find me ${describe()}.`
    : 'Help me find a home that fits my budget and needs.'
  openDashPanel('assistant')
  askAi(prompt)
}
const atEnd = computed(() => !pagination.value || page.value >= pagination.value.last_page)

/* ── Styling ───────────────────────────────────────────────────────────── */
const toolBtn =
  'inline-flex items-center gap-2 h-10 rounded-full px-4 text-sm font-semibold transition-all ' +
  'bg-white dark:bg-white/[0.06] text-brand-navy dark:text-white ring-1 ring-brand-navy/10 dark:ring-white/10 ' +
  'hover:ring-brand-gold hover:shadow-[0_6px_18px_-8px_rgba(11,42,92,0.35)]'
const panelLabel = 'block text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-brand-navy/50 dark:text-white/45 mb-2'
const segBtn = (on: boolean) =>
  'flex-1 rounded-full px-3 py-2 text-sm font-semibold transition-colors ' +
  (on
    ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-brand-navy'
    : 'text-brand-navy/70 hover:text-brand-navy dark:text-white/60 dark:hover:text-white')
</script>

<template>
  <div :class="embedded ? '' : 'pb-20'">
    <!-- ── Embedded: page header with a live summary ── -->
    <header v-if="embedded" class="mb-5 browse-rise">
      <div class="min-w-0">
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Browse</p>
        <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">
          Find your next home
        </h1>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55" aria-live="polite">
          <span v-if="summary.count" class="font-semibold text-brand-navy dark:text-white tabular-nums">{{ summary.count }}</span>
          {{ summary.rest }}
          <span v-if="loading && properties.length" class="ml-1 inline-block h-3 w-3 align-middle rounded-full border-2 border-brand-gold border-t-transparent animate-spin" aria-hidden="true" />
        </p>
      </div>
    </header>

    <!-- ── Hero: heading, search pill, type chips ── -->
    <!-- relative z-20, no overflow clip: the search pill's panels hang below
         the hero and must paint over the toolbar and cards. -->
    <section
      v-if="!embedded"
      class="relative z-20"
      :class="embedded
        ? 'mb-6'
        : 'bg-brand-cream dark:bg-brand-navy-deep border-b border-brand-navy/[0.06] dark:border-white/[0.06] transition-colors duration-500'"
    >
      <template v-if="!embedded">
        <div class="absolute inset-0 -z-10 bg-grid-lines mask-radial-fade pointer-events-none" />
        <div class="absolute inset-0 -z-10 bg-grid-lines-lg mask-radial-fade pointer-events-none" />
        <div class="absolute inset-0 -z-10 overflow-hidden pointer-events-none">
          <div class="absolute -top-28 left-1/2 -translate-x-1/2 h-80 w-[min(52rem,100%)] rounded-full bg-brand-gold/15 dark:bg-brand-gold/[0.07] blur-[110px]" />
        </div>
      </template>

      <div :class="embedded ? '' : 'max-w-content mx-auto px-4 sm:px-6 pt-28 md:pt-32 pb-10 md:pb-12 text-center'">
        <div v-if="$slots.heading && !embedded" class="browse-rise">
          <slot name="heading" />
        </div>

        <!-- z-30: the search panels drop over the chips and results below. -->
        <div class="relative z-30 mx-auto w-full max-w-3xl text-left browse-rise" :class="embedded ? '' : 'mt-8'" style="animation-delay: 80ms">
          <HomeSearchBar :to="route.path" />
        </div>

        <!-- Type chips: one tap, applied immediately -->
        <div class="mt-5 -mx-4 px-4 overflow-x-auto no-scrollbar browse-rise" style="animation-delay: 150ms">
          <div class="flex w-max gap-2" :class="embedded ? '' : 'mx-auto'" role="radiogroup" aria-label="Property type">
            <button
              v-for="t in TYPES"
              :key="t.value || 'all'"
              type="button"
              role="radio"
              :aria-checked="filters.type === t.value"
              class="whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold ring-1 transition-all duration-200 active:scale-95"
              :class="filters.type === t.value
                ? 'bg-brand-navy text-white ring-brand-navy shadow-[0_6px_16px_-6px_rgba(11,42,92,0.5)] dark:bg-brand-gold dark:text-brand-navy dark:ring-brand-gold'
                : 'bg-white/80 dark:bg-white/[0.05] text-brand-navy/75 dark:text-white/70 ring-brand-navy/10 dark:ring-white/10 hover:ring-brand-gold hover:-translate-y-0.5'"
              @click="setFilters({ type: t.value })"
            >
              {{ t.label }}
            </button>
          </div>
        </div>
      </div>
    </section>

    <!-- ── Results ── -->
    <div ref="resultsEl" :class="embedded ? '' : 'max-w-content mx-auto px-4 sm:px-6 pt-8'">
      <!-- Embedded: the toolbar sticks under the dashboard top bar (5rem) and
           only takes a surface once it's stuck. Not on phones, where its
           wrapped rows would cover a quarter of the screen. No backdrop-filter here — it
           would trap the Filters bottom sheet (position: fixed) inside it. -->
      <div v-if="embedded" ref="stickSentinel" class="h-px" aria-hidden="true" />
      <div
        :class="embedded
          ? ['sm:sticky sm:top-20 z-20 -mx-5 sm:-mx-8 px-5 sm:px-8 py-3 rounded-b-3xl transition-[background-color,box-shadow] duration-200',
             stuck ? 'bg-gray-50 dark:bg-[#06183A] shadow-[0_14px_28px_-22px_rgba(11,42,92,0.55)] dark:shadow-[0_14px_28px_-20px_rgba(0,0,0,0.8)]' : '']
          : ''"
      >
      <!-- Toolbar -->
      <div class="flex flex-wrap items-center gap-3">
        <!-- Embedded: type and quick filters live in the Filters panel; the
             controls sit on the left, Ask RealtyLink AI on the right. -->
        <p v-if="!embedded" class="text-sm text-brand-text-secondary dark:text-white/55 mr-auto" aria-live="polite">
          <template v-if="loading && !properties.length">Searching…</template>
          <template v-else-if="pagination">
            <span class="font-heading text-xl font-semibold text-brand-navy dark:text-white tabular-nums">{{ pagination.total.toLocaleString('en-PH') }}</span>
            {{ pagination.total === 1 ? 'property' : 'properties' }}
            <span v-if="loading" class="ml-1 inline-block h-3 w-3 align-middle rounded-full border-2 border-brand-gold border-t-transparent animate-spin" aria-hidden="true" />
          </template>
        </p>

        <!-- Filters -->
        <div ref="panelEl" class="relative">
          <button type="button" :class="toolBtn" :aria-expanded="panelOpen" aria-haspopup="dialog" @click="panelOpen ? (panelOpen = false) : openPanel()">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />
            </svg>
            Filters
            <span v-if="panelCount" class="h-5 min-w-[1.25rem] px-1 rounded-full bg-brand-gold text-brand-navy text-[0.6875rem] font-bold flex items-center justify-center">{{ panelCount }}</span>
          </button>

          <!-- Phone: a bottom sheet over a dim backdrop. sm+: a popover. -->
          <Transition name="fade">
            <!-- Tapping the dimmed backdrop closes the sheet. (It sits inside
                 panelEl, so the outside-click handler doesn't see it.) -->
            <div v-if="panelOpen" class="fixed inset-0 z-40 bg-black/30 sm:hidden" aria-hidden="true" @click="panelOpen = false" />
          </Transition>
          <Transition name="fold">
            <div
              v-if="panelOpen"
              role="dialog"
              aria-label="Filters"
              class="fixed z-50 inset-x-3 bottom-3 max-h-[85vh] overflow-y-auto sm:absolute sm:inset-x-auto sm:bottom-auto sm:top-full sm:mt-2
                     rounded-3xl p-5 bg-white dark:bg-brand-navy-mid ring-1 ring-brand-navy/[0.06] dark:ring-white/10
                     shadow-[0_24px_60px_-20px_rgba(11,42,92,0.45)] dark:shadow-[0_24px_60px_-16px_rgba(0,0,0,0.7)]"
              :class="embedded
                ? 'origin-bottom sm:origin-top-left sm:left-0 sm:w-[min(44rem,calc(100vw-3rem))] sm:max-h-[calc(100vh-11rem)]'
                : 'origin-bottom sm:origin-top-left sm:left-0 sm:w-[22rem] sm:max-h-none sm:overflow-visible'"
            >
              <!-- Header: title + close -->
              <div class="-mt-1 mb-4 flex items-center justify-between">
                <p class="font-heading text-lg font-semibold text-brand-navy dark:text-white">Filters</p>
                <button
                  type="button"
                  class="-mr-1.5 h-9 w-9 rounded-full flex items-center justify-center text-brand-navy/60 hover:text-brand-navy hover:bg-brand-navy/5 dark:text-white/60 dark:hover:text-white dark:hover:bg-white/10 transition"
                  aria-label="Close filters"
                  @click="panelOpen = false"
                >
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
              </div>
              <!-- Embedded: a wide two-column panel, so everything fits without
                   scrolling. Left: where + what. Right: budget + size. -->
              <div :class="embedded ? 'sm:grid sm:grid-cols-2 sm:gap-x-8' : ''">
              <!-- Location (embedded only: the public page has its search pill) -->
              <div v-if="embedded">
                <label for="browse-location" :class="panelLabel">Location</label>
                <div class="flex items-center gap-2 rounded-2xl px-3.5 py-2.5 ring-1 ring-brand-navy/10 dark:ring-white/10 focus-within:ring-2 focus-within:ring-brand-gold">
                  <svg class="h-4 w-4 flex-none text-brand-navy/45 dark:text-white/45" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                  <input
                    id="browse-location"
                    v-model="draft.search"
                    type="search"
                    placeholder="City, barangay or building"
                    class="w-full border-0 bg-transparent p-0 text-sm text-brand-navy dark:text-white focus:ring-0 placeholder-brand-navy/40 dark:placeholder-white/40"
                    @keydown.enter.prevent="applyPanel"
                  />
                </div>
                <div v-if="cities.length" class="mt-2 flex flex-wrap gap-1.5">
                  <button
                    v-for="c in cities"
                    :key="c.name"
                    type="button"
                    class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 transition-colors"
                    :class="draft.search === c.name
                      ? 'bg-brand-navy text-white ring-brand-navy dark:bg-brand-gold dark:text-brand-navy dark:ring-brand-gold'
                      : 'text-brand-navy/70 ring-brand-navy/15 hover:ring-brand-gold dark:text-white/70 dark:ring-white/15'"
                    @click="draft.search = draft.search === c.name ? '' : c.name"
                  >
                    {{ c.name }} <span class="opacity-60">{{ c.count }}</span>
                  </button>
                </div>

                <span :class="[panelLabel, 'mt-5']">Property type</span>
                <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Property type">
                  <button
                    v-for="t in TYPES"
                    :key="t.value || 'all'"
                    type="button"
                    role="radio"
                    :aria-checked="draft.type === t.value"
                    class="rounded-full px-3 py-1.5 text-sm font-semibold ring-1 transition-colors"
                    :class="draft.type === t.value
                      ? 'bg-brand-navy text-white ring-brand-navy dark:bg-brand-gold dark:text-brand-navy dark:ring-brand-gold'
                      : 'text-brand-navy/75 ring-brand-navy/15 hover:ring-brand-gold dark:text-white/75 dark:ring-white/15'"
                    @click="draft.type = t.value"
                  >{{ t.label }}</button>
                </div>
              <!-- 360° tour (embedded; the public page has no such filter) -->
              <button
                v-if="embedded"
                type="button"
                role="switch"
                :aria-checked="draft.has_360"
                class="mt-5 w-full flex items-center justify-between gap-3 rounded-2xl px-3.5 py-2.5 ring-1 transition-colors text-left"
                :class="draft.has_360 ? 'ring-brand-gold bg-brand-gold/10' : 'ring-brand-navy/10 dark:ring-white/10 hover:ring-brand-gold'"
                @click="draft.has_360 = !draft.has_360"
              >
                <span>
                  <span class="block text-sm font-semibold text-brand-navy dark:text-white">Has a 360° virtual tour</span>
                  <span class="block text-xs text-brand-navy/50 dark:text-white/45">Walk through it before you visit</span>
                </span>
                <span class="relative h-6 w-11 flex-none rounded-full transition-colors" :class="draft.has_360 ? 'bg-brand-gold' : 'bg-brand-navy/15 dark:bg-white/15'">
                  <span class="absolute top-0.5 h-5 w-5 rounded-full bg-[#ffffff] shadow transition-all" :class="draft.has_360 ? 'left-[1.375rem]' : 'left-0.5'" />
                </span>
              </button>
              </div>

              <div :class="embedded ? 'mt-5 sm:mt-0 sm:pl-8 sm:border-l sm:border-brand-navy/[0.07] dark:sm:border-white/10' : ''">
              <span :class="panelLabel">Buy or rent</span>
              <div class="flex gap-1 rounded-full p-1 bg-brand-navy/[0.05] dark:bg-white/[0.06]">
                <button type="button" :class="segBtn(draft.offer_type === '')" @click="draft.offer_type = ''">Either</button>
                <button type="button" :class="segBtn(draft.offer_type === 'sale')" @click="draft.offer_type = 'sale'">Buy</button>
                <button type="button" :class="segBtn(draft.offer_type === 'rent')" @click="draft.offer_type = 'rent'">Rent</button>
              </div>

              <div class="mt-5 mb-2 flex items-center justify-between">
                <span :class="[panelLabel, '!mb-0']">Price range</span>
                <!-- What used to be the "Under ₱3M" quick chip -->
                <button
                  v-if="embedded"
                  type="button"
                  class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 transition-colors"
                  :class="draft.max_price === UNDER && !draft.min_price
                    ? 'bg-brand-gold text-brand-navy ring-brand-gold'
                    : 'text-brand-gold-deep ring-brand-gold/40 hover:bg-brand-gold/10 dark:text-brand-gold'"
                  @click="draft.max_price === UNDER && !draft.min_price ? (draft.max_price = undefined) : Object.assign(draft, { min_price: undefined, max_price: UNDER })"
                >Under ₱3M</button>
              </div>
              <div class="flex items-center gap-2">
                <label class="flex-1 flex items-center gap-1 rounded-2xl px-3.5 py-2.5 ring-1 ring-brand-navy/10 dark:ring-white/10 focus-within:ring-2 focus-within:ring-brand-gold">
                  <span class="text-sm text-brand-navy/45 dark:text-white/45">₱</span>
                  <input v-model="minModel" inputmode="numeric" placeholder="Min" aria-label="Minimum price"
                         class="w-full border-0 bg-transparent p-0 text-sm text-brand-navy dark:text-white focus:ring-0 placeholder-brand-navy/40 dark:placeholder-white/40" />
                </label>
                <span class="text-brand-navy/30 dark:text-white/30">–</span>
                <label class="flex-1 flex items-center gap-1 rounded-2xl px-3.5 py-2.5 ring-1 ring-brand-navy/10 dark:ring-white/10 focus-within:ring-2 focus-within:ring-brand-gold">
                  <span class="text-sm text-brand-navy/45 dark:text-white/45">₱</span>
                  <input v-model="maxModel" inputmode="numeric" placeholder="Max" aria-label="Maximum price"
                         class="w-full border-0 bg-transparent p-0 text-sm text-brand-navy dark:text-white focus:ring-0 placeholder-brand-navy/40 dark:placeholder-white/40" />
                </label>
              </div>

              <span :class="[panelLabel, 'mt-5']">Bedrooms</span>
              <div class="flex flex-wrap" :class="embedded ? 'gap-1.5' : 'gap-2'">
                <button
                  v-for="n in [undefined, 1, 2, 3, 4, 5]"
                  :key="n ?? 'any'"
                  type="button"
                  class="h-9 rounded-full text-sm font-semibold ring-1 transition-colors"
                  :class="[embedded ? 'min-w-[2.6rem] px-2.5' : 'min-w-[3rem] px-3', draft.bedrooms === n
                    ? 'bg-brand-navy text-white ring-brand-navy dark:bg-brand-gold dark:text-brand-navy dark:ring-brand-gold'
                    : 'text-brand-navy/75 ring-brand-navy/15 hover:ring-brand-gold dark:text-white/75 dark:ring-white/15']"
                  @click="draft.bedrooms = n"
                >
                  {{ n === undefined ? 'Any' : `${n}+` }}
                </button>
              </div>


              </div>
              </div>

              <div class="flex items-center justify-between gap-3" :class="embedded ? 'mt-5 pt-4 border-t border-brand-navy/[0.07] dark:border-white/10' : 'mt-6'">
                <button type="button" class="text-sm font-semibold text-brand-navy/60 hover:text-brand-navy dark:text-white/55 dark:hover:text-white underline-offset-4 hover:underline" @click="resetDraft">
                  Reset
                </button>
                <button
                  type="button"
                  class="rounded-full bg-brand-gold px-6 py-2.5 text-sm font-bold text-brand-navy shadow-[0_6px_18px_rgba(212,165,74,0.4)] hover:bg-brand-gold-light transition"
                  @click="applyPanel"
                >
                  Show results
                </button>
              </div>
            </div>
          </Transition>
        </div>

        <!-- Sort. A custom menu, not a native <select>: Chrome draws the
             native option list itself, and in dark mode it inherited white
             text onto its own white popup — the choices were invisible. -->
        <div ref="sortEl" class="relative">
          <button
            ref="sortBtn"
            type="button"
            :class="toolBtn"
            aria-haspopup="listbox"
            :aria-expanded="sortOpen"
            @click="sortOpen = !sortOpen"
            @keydown.down.prevent="openSort"
            @keydown.up.prevent="openSort"
          >
            <span class="sr-only">Sort by:</span>
            {{ sortLabel }}
            <svg class="h-4 w-4 text-brand-navy/50 dark:text-white/50 transition-transform duration-200" :class="sortOpen ? 'rotate-180' : ''"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
          </button>

          <Transition name="sheet">
            <ul
              v-if="sortOpen"
              ref="sortList"
              role="listbox"
              tabindex="-1"
              aria-label="Sort by"
              :aria-activedescendant="`sort-${sortActive}`"
              class="absolute z-50 right-0 sm:right-auto sm:left-0 top-full mt-2 w-56 rounded-2xl p-1.5 outline-none
                     bg-white dark:bg-brand-navy-mid ring-1 ring-brand-navy/[0.06] dark:ring-white/10
                     shadow-[0_24px_60px_-20px_rgba(11,42,92,0.45)] dark:shadow-[0_24px_60px_-16px_rgba(0,0,0,0.7)]"
              @keydown.down.prevent="sortActive = (sortActive + 1) % SORTS.length"
              @keydown.up.prevent="sortActive = (sortActive - 1 + SORTS.length) % SORTS.length"
              @keydown.enter.prevent="pickSort(SORTS[sortActive]!.value)"
              @keydown.space.prevent="pickSort(SORTS[sortActive]!.value)"
              @keydown.tab="sortOpen = false"
            >
              <li
                v-for="(s, i) in SORTS"
                :id="`sort-${i}`"
                :key="s.value"
                role="option"
                :aria-selected="filters.sort === s.value"
                class="flex items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold cursor-pointer transition-colors"
                :class="[
                  i === sortActive ? 'bg-brand-navy/[0.05] dark:bg-white/[0.08]' : '',
                  filters.sort === s.value ? 'text-brand-gold-deep dark:text-brand-gold' : 'text-brand-navy/80 dark:text-white/80',
                ]"
                @mouseenter="sortActive = i"
                @click="pickSort(s.value)"
              >
                {{ s.label }}
                <svg v-if="filters.sort === s.value" class="h-4 w-4 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
              </li>
            </ul>
          </Transition>
        </div>

        <!-- List / Map -->
        <div class="inline-flex items-center rounded-full p-1 bg-white dark:bg-white/[0.06] ring-1 ring-brand-navy/10 dark:ring-white/10">
          <button
            v-for="v in [false, true]"
            :key="String(v)"
            type="button"
            class="flex items-center gap-1.5 h-8 px-3.5 rounded-full text-xs font-semibold transition-all"
            :class="showMap === v ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-brand-navy' : 'text-brand-navy/60 dark:text-white/55 hover:text-brand-navy dark:hover:text-white'"
            :aria-pressed="showMap === v"
            @click="showMap = v"
          >
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path v-if="!v" stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
              <path v-else stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z" />
            </svg>
            {{ v ? 'Map' : 'Grid' }}
          </button>
        </div>

        <!-- Embedded: Ask RealtyLink AI on the right of the controls -->
        <button
          v-if="embedded"
          type="button"
          class="ml-auto group inline-flex items-center gap-2 h-10 rounded-full pl-1.5 pr-4 text-sm font-semibold transition
                 text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold bg-white
                 dark:text-white dark:ring-white/15 dark:bg-white/[0.06] dark:hover:ring-brand-gold"
          @click="askAiWithFilters"
        >
          <img src="/ai/avatar-96.webp" alt="" class="h-7 w-7 rounded-full object-cover ring-1 ring-brand-gold/60" />
          Ask RealtyLink AI
        </button>
      </div>

      <!-- Embedded: what's applied, each removable (the filters themselves
           live in the Filters panel) -->
      <TransitionGroup v-if="embedded && chips.length" tag="div" name="chip" class="mt-3 flex flex-wrap items-center gap-2">
        <button
          v-for="c in chips"
          :key="c.key"
          type="button"
          class="group inline-flex items-center gap-1.5 whitespace-nowrap rounded-full pl-3 pr-2 py-1.5 text-xs font-semibold
                 bg-brand-gold/15 text-brand-gold-deep ring-1 ring-brand-gold/30 dark:text-brand-gold hover:bg-brand-gold/25 transition-colors"
          :aria-label="`Remove filter ${c.label}`"
          @click="setFilters(c.clear)"
        >
          {{ c.key === 'search' ? filters.search : c.label }}
          <svg class="h-3.5 w-3.5 opacity-60 group-hover:opacity-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
        <button key="clear-all" type="button" class="px-2 text-xs font-semibold text-brand-navy/55 hover:text-brand-navy dark:text-white/50 dark:hover:text-white underline-offset-4 hover:underline" @click="clearAll">
          Clear all
        </button>
      </TransitionGroup>
      </div><!-- /sticky toolbar -->

      <!-- Active filters -->
      <TransitionGroup v-if="!embedded && chips.length" tag="div" name="chip" class="mt-4 flex flex-wrap items-center gap-2">
        <button
          v-for="c in chips"
          :key="c.key"
          type="button"
          class="group inline-flex items-center gap-1.5 rounded-full pl-3.5 pr-2 py-1.5 text-xs font-semibold
                 bg-brand-gold/15 text-brand-gold-deep ring-1 ring-brand-gold/30 dark:text-brand-gold hover:bg-brand-gold/25 transition-colors"
          :aria-label="`Remove filter ${c.label}`"
          @click="setFilters(c.clear)"
        >
          {{ c.label }}
          <svg class="h-3.5 w-3.5 opacity-60 group-hover:opacity-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
        <button key="clear-all" type="button" class="px-2 text-xs font-semibold text-brand-navy/55 hover:text-brand-navy dark:text-white/50 dark:hover:text-white underline-offset-4 hover:underline" @click="clearAll">
          Clear all
        </button>
      </TransitionGroup>

      <!-- Results + map. Desktop: side by side, map sticky. Phone: Map replaces
           the list at a height you can actually pan around in. -->
      <div class="mt-6 flex gap-5">
        <div
          :class="[showMap ? 'hidden lg:block lg:w-1/2 min-w-0' : 'w-full', loading && properties.length ? 'opacity-50 pointer-events-none' : '']"
          class="transition-opacity duration-200"
        >
          <PropertyGrid
            :properties="properties"
            :loading="loading && !properties.length"
            :skeleton-count="showMap ? 4 : 8"
            :dense="showMap"
            stagger
          >
            <!-- Compare toggle, pinned to the photo's bottom-left corner -->
            <template v-if="embedded" #overlay="{ property: p }">
              <div class="pointer-events-none absolute inset-x-[2px] top-[2px] aspect-[4/3]">
                <CompareToggle :property="p" class="pointer-events-auto absolute bottom-3 left-3" />
              </div>
            </template>
          </PropertyGrid>

          <!-- End of results: hand off to RealtyLink AI with the same filters -->
          <div
            v-if="embedded && !loading && properties.length && atEnd"
            class="mt-8 flex flex-wrap items-center gap-x-4 gap-y-3 rounded-3xl p-5 sm:pr-6 bg-brand-navy text-white ring-1 ring-brand-gold/25 relative overflow-hidden"
          >
            <!-- flex-wrap, not a breakpoint: the column it sits in can be narrow
                 even on a wide screen (map open, side panel docked). The text
                 keeps at least 15rem; when that doesn't fit beside the button,
                 the button drops to its own full-width row. -->
            <div class="absolute inset-0 bg-grid-gold opacity-[0.06] pointer-events-none" />
            <div class="relative flex min-w-[15rem] flex-[1_1_15rem] items-center gap-4">
              <img src="/ai/mascot-320.webp" alt="" class="h-14 w-14 flex-none object-contain drop-shadow-[0_8px_16px_rgba(0,0,0,0.35)]" />
              <div class="min-w-0">
                <p class="font-heading text-lg font-semibold leading-snug">Not quite what you're looking for?</p>
                <p class="mt-0.5 text-sm text-white/65">Describe your ideal home and RealtyLink AI will search with your current filters as a starting point.</p>
              </div>
            </div>
            <button
              type="button"
              class="relative flex-[1_0_auto] sm:flex-none rounded-full bg-brand-gold px-5 py-2.5 text-sm font-bold text-brand-navy shadow-[0_4px_14px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light hover:-translate-y-0.5 transition"
              @click="askAiWithFilters"
            >
              Ask RealtyLink AI
            </button>
          </div>

          <!-- Empty state -->
          <div v-if="!loading && !properties.length" class="flex flex-col items-center justify-center py-20 text-center browse-rise">
            <div class="relative h-20 w-20 rounded-full bg-brand-gold/10 flex items-center justify-center mb-5">
              <span class="absolute inset-0 rounded-full bg-brand-gold/15 animate-pulse-ring motion-reduce:hidden" aria-hidden="true" />
              <svg class="h-9 w-9 text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
              </svg>
            </div>
            <p class="font-heading text-2xl font-semibold text-brand-navy dark:text-white">No properties match</p>
            <p class="mt-2 max-w-sm text-sm text-brand-text-secondary dark:text-white/55">Try a different city, widen the price range, or clear a filter or two.</p>
            <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
              <button v-if="chips.length" type="button" class="rounded-full bg-brand-navy px-6 py-2.5 text-sm font-bold text-white dark:bg-white dark:text-brand-navy hover:-translate-y-0.5 transition" @click="clearAll">
                Clear all filters
              </button>
              <button
                v-if="embedded"
                type="button"
                class="inline-flex items-center gap-2 rounded-full pl-1.5 pr-5 py-1.5 text-sm font-bold bg-brand-gold text-brand-navy shadow-[0_4px_14px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light hover:-translate-y-0.5 transition"
                @click="askAiWithFilters"
              >
                <img src="/ai/avatar-96.webp" alt="" class="h-7 w-7 rounded-full object-cover" />
                Ask RealtyLink AI to search
              </button>
            </div>
          </div>
        </div>

        <!-- Map panel -->
        <div v-if="showMap" class="w-full lg:w-1/2 flex-shrink-0">
          <div
            class="relative h-[65vh] lg:sticky rounded-3xl overflow-hidden ring-1 ring-brand-navy/10 dark:ring-white/10"
            :class="embedded ? 'lg:top-[14rem] lg:h-[calc(100vh-15rem)]' : 'lg:h-[calc(100vh-7rem)] lg:top-24'"
          >
            <!-- Phones/tablets: the map replaces the list, so offer the way back
                 right on it. (Desktop shows both side by side.) -->
            <button
              type="button"
              class="lg:hidden absolute bottom-5 left-1/2 -translate-x-1/2 z-[500] inline-flex items-center gap-2 rounded-full px-5 py-3 text-sm font-bold
                     bg-brand-navy text-white ring-1 ring-brand-gold/40 shadow-[0_12px_30px_-8px_rgba(6,24,58,0.65)] active:scale-95 transition"
              @click="showMap = false"
            >
              <svg class="h-4 w-4 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
              Show list<template v-if="pagination"> ({{ pagination.total }})</template>
            </button>
            <ClientOnly>
              <PropertyMap :properties="properties" />
              <template #fallback>
                <div class="w-full h-full bg-gray-100 dark:bg-white/5 animate-pulse" />
              </template>
            </ClientOnly>
          </div>
        </div>
      </div>

      <!-- Pagination — outside the split so it's reachable in map mode too;
           the map only plots the current page, so paging matters there. -->
      <nav v-if="pagination && pagination.last_page > 1" class="flex items-center justify-center gap-2 mt-12" aria-label="Pages">
        <button
          type="button"
          class="h-10 w-10 rounded-full ring-1 ring-brand-navy/10 dark:ring-white/10 bg-white dark:bg-white/[0.06] text-brand-navy dark:text-white flex items-center justify-center hover:ring-brand-gold transition disabled:opacity-40 disabled:cursor-not-allowed"
          :disabled="page <= 1"
          aria-label="Previous page"
          @click="goPage(page - 1)"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
        </button>
        <button
          v-for="p in pagination.last_page"
          :key="p"
          type="button"
          class="h-10 w-10 rounded-full text-sm font-semibold transition"
          :class="p === page
            ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-brand-navy shadow-[0_6px_16px_-6px_rgba(11,42,92,0.5)]'
            : 'bg-white dark:bg-white/[0.06] text-brand-navy/70 dark:text-white/65 ring-1 ring-brand-navy/10 dark:ring-white/10 hover:ring-brand-gold'"
          :aria-current="p === page ? 'page' : undefined"
          @click="goPage(p)"
        >
          {{ p }}
        </button>
        <button
          type="button"
          class="h-10 w-10 rounded-full ring-1 ring-brand-navy/10 dark:ring-white/10 bg-white dark:bg-white/[0.06] text-brand-navy dark:text-white flex items-center justify-center hover:ring-brand-gold transition disabled:opacity-40 disabled:cursor-not-allowed"
          :disabled="page >= pagination.last_page"
          aria-label="Next page"
          @click="goPage(page + 1)"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
        </button>
      </nav>

      <!-- Compare tray + side-by-side dialog -->
      <BrowseCompare v-if="embedded" />
    </div>
  </div>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }

/* One quick rise on arrival — heading, search, chips in turn. */
.browse-rise { animation: browse-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes browse-rise {
  from { opacity: 0; transform: translateY(12px); }
  to   { opacity: 1; transform: none; }
}

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.sheet-enter-active, .sheet-leave-active { transition: opacity 0.18s ease, transform 0.22s cubic-bezier(0.22, 1, 0.36, 1); }
.sheet-enter-from, .sheet-leave-to { opacity: 0; transform: translateY(10px) scale(0.98); }

/* Filters panel grows out of the Filters button and folds back into it
   (transform-origin is set on the panel: top-right on desktop, bottom on phones). */
.fold-enter-active { transition: opacity 0.22s ease, transform 0.3s cubic-bezier(0.22, 1, 0.36, 1); }
.fold-leave-active { transition: opacity 0.2s ease, transform 0.25s cubic-bezier(0.55, 0, 0.75, 0.2); }
.fold-enter-from, .fold-leave-to { opacity: 0; transform: scale(0.88) translateY(-6px); }
@media (max-width: 639px) {
  .fold-enter-from, .fold-leave-to { transform: translateY(24px) scale(0.98); }
}

.chip-enter-active, .chip-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.chip-enter-from, .chip-leave-to { opacity: 0; transform: scale(0.9); }
.chip-leave-active { position: absolute; }

@media (prefers-reduced-motion: reduce) {
  .browse-rise { animation: none; }
  .fade-enter-active, .fade-leave-active, .sheet-enter-active, .sheet-leave-active,
  .chip-enter-active, .chip-leave-active, .fold-enter-active, .fold-leave-active { transition: none; }
}
</style>
