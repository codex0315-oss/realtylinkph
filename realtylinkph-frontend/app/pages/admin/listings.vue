<script setup lang="ts">
import type { ApiResponse, Property, PaginatedResponse } from '~/types'

definePageMeta({ layout: 'dashboard' })

/*
 * Listings moderation, top to bottom:
 *   header → status tabs with counts → search, type, sale/rent, sort
 *   → rows (click one for the details drawer) beside a side panel of
 *     listings that need a look and the agents with the most listings.
 * Unpublish and delete both go through ListingModerationDialog, which makes
 * the admin give the agent a reason.
 */
const authStore = useAuthStore()
if (!authStore.isAdmin) {
  throw createError({ statusCode: 403, statusMessage: 'Forbidden' })
}

const api = useApi()

type Tab = '' | 'published' | 'unpublished' | 'draft' | 'sold'
type Sort = 'newest' | 'price_desc' | 'price_asc' | 'views' | 'featured'

const properties = ref<Property[]>([])
const pagination = ref<PaginatedResponse<Property>['meta'] | null>(null)
const loading    = ref(false)
const page       = ref(1)
const tab        = ref<Tab>('')
const search     = ref('')
const type       = ref('')
const offer      = ref<'' | 'sale' | 'rent'>('')
const sort       = ref<Sort>('newest')

async function load() {
  loading.value = true
  try {
    const params: Record<string, unknown> = { page: page.value }
    if (tab.value) params.status = tab.value
    if (search.value.trim()) params.search = search.value.trim()
    if (type.value) params.type = type.value
    if (offer.value) params.offer_type = offer.value
    if (sort.value !== 'newest') params.sort = sort.value
    const res = await api.get<PaginatedResponse<Property>>('/admin/properties', params)
    properties.value = res?.data ?? []
    pagination.value = res?.meta ?? null
  } catch {
    properties.value = []
  } finally {
    loading.value = false
  }
}

/* ── Tab counts + side panel ── */
interface Stats {
  counts: Record<'all' | 'published' | 'unpublished' | 'draft' | 'sold', number>
  flagged: Array<{ id: number; title: string; agent: string | null; flags: string[] }>
  flagged_total: number
  top_agents: Array<{ id: number; name: string; avatar: string | null; count: number }>
}
const stats = ref<Stats | null>(null)
async function loadStats() {
  try {
    const res = await api.get<ApiResponse<Stats>>('/admin/properties/stats')
    stats.value = res.data
  } catch { /* the page still works without it */ }
}
await Promise.all([load(), loadStats()])

const TABS: Array<{ key: Tab; label: string; count: () => number }> = [
  { key: '',            label: 'All',         count: () => stats.value?.counts.all ?? 0 },
  { key: 'published',   label: 'Published',   count: () => stats.value?.counts.published ?? 0 },
  { key: 'unpublished', label: 'Unpublished', count: () => stats.value?.counts.unpublished ?? 0 },
  { key: 'draft',       label: 'Drafts',      count: () => stats.value?.counts.draft ?? 0 },
  { key: 'sold',        label: 'Sold',        count: () => stats.value?.counts.sold ?? 0 },
]
const TYPES = [
  { value: '', label: 'All types' }, { value: 'house', label: 'House' }, { value: 'condo', label: 'Condo' },
  { value: 'lot', label: 'Lot' }, { value: 'commercial', label: 'Commercial' }, { value: 'apartment', label: 'Apartment' },
]
const SORTS: Array<{ value: Sort; label: string }> = [
  { value: 'newest', label: 'Newest' }, { value: 'price_desc', label: 'Price: high to low' }, { value: 'price_asc', label: 'Price: low to high' },
  { value: 'views', label: 'Most viewed' }, { value: 'featured', label: 'Featured score' },
]

function setTab(t: Tab) { tab.value = t; page.value = 1; load() }
function goPage(n: number) { page.value = n; load() }
watch([type, offer, sort], () => { page.value = 1; load() })
let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; load() }, 300)
})
function resetFilters() { search.value = ''; type.value = ''; offer.value = ''; sort.value = 'newest'; setTab('') }

/* ── Display helpers ── */
function calm(t?: string | null): string {
  if (!t) return ''
  const letters = t.replace(/[^A-Za-z]/g, '')
  return letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
}
function thumb(p: Property) {
  const ph = p.photos?.find(x => !x.is_360) ?? p.photos?.[0]
  return ph ? (ph.thumb_url ?? ph.url) : null
}
function price(p: Property): string {
  const n = Number(p.price)
  const s = n >= 1_000_000 ? `₱${(n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1)}M`
          : n >= 1_000     ? `₱${(n / 1_000).toFixed(0)}K`
          : `₱${n.toLocaleString('en-PH')}`
  return p.offer_type === 'rent' ? `${s}/mo` : s
}
const fullPrice = (p: Property) => `₱${Number(p.price).toLocaleString('en-PH')}${p.offer_type === 'rent' ? ' / month' : ''}`
const typeName = (t?: string | null) => (t ? t.charAt(0).toUpperCase() + t.slice(1) : 'Property')
const city = (a?: string | null) => (a ? a.split(',').map(s => s.trim()).filter(Boolean).slice(-1)[0] ?? a : '')
const listedOn = (iso?: string | null) => (iso ? new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) : '')

function statusOf(p: Property): { label: string; cls: string } {
  if (p.status === 'published') return { label: 'Published', cls: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' }
  if (p.status === 'sold') return { label: 'Sold', cls: 'bg-brand-navy/[0.08] text-brand-navy dark:bg-white/10 dark:text-white' }
  if (p.unpublish_reason) return { label: 'Unpublished', cls: 'bg-amber-500/10 text-amber-700 dark:text-amber-400' }
  return { label: 'Draft', cls: 'bg-gray-500/10 text-gray-600 dark:text-white/55' }
}

/* ── Row ⋯ menu ── */
const menuFor = ref<number | null>(null)
function toggleMenu(id: number) { menuFor.value = menuFor.value === id ? null : id }
function closeMenu() { menuFor.value = null }
onMounted(() => document.addEventListener('click', closeMenu))
onUnmounted(() => document.removeEventListener('click', closeMenu))

/* ── Details drawer ── */
const drawer = ref<Property | null>(null)
const drawerPhoto = ref(0)
function openDetails(p: Property) { closeMenu(); drawer.value = p; drawerPhoto.value = 0; explainFor.value = null }
/* The side panel lists flagged listings by id: fetch the listing to show it. */
async function openById(id: number) {
  const known = properties.value.find(p => p.id === id)
  if (known) return openDetails(known)
  try {
    const res = await api.get<ApiResponse<Property>>(`/properties/${id}`)
    openDetails(res.data)
  } catch { /* gone in the meantime */ }
}
const drawerPhotos = computed(() => (drawer.value?.photos ?? []).filter(p => !p.is_360))
function preview(p: Property) { window.open(`/properties/${p.id}`, '_blank', 'noopener') }
function onKey(e: KeyboardEvent) { if (e.key === 'Escape') { drawer.value = null; closeMenu() } }
onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))

/* ── Unpublish / delete ── */
const moderate = ref<{ mode: 'unpublish' | 'delete'; property: Property } | null>(null)
function openModeration(mode: 'unpublish' | 'delete', p: Property) {
  closeMenu()
  // The dialog sits above the drawer, so the drawer can stay open behind it.
  moderate.value = { mode, property: p }
}
async function onModerated(mode: 'unpublish' | 'delete') {
  const id = moderate.value?.property.id
  moderate.value = null
  if (mode === 'delete' && drawer.value?.id === id) drawer.value = null
  await Promise.all([load(), loadStats()])
  if (mode === 'unpublish' && drawer.value?.id === id) drawer.value = properties.value.find(p => p.id === id) ?? null
}

/* ── "Why featured?" (grounded AI explanation), shown inside the drawer ── */
interface Factor { key: string; label: string; score: number; max: number; notes: string[] }
interface Breakdown { eligible: boolean; total: number; gate_failures: string[]; factors: Factor[] }

const explainFor     = ref<number | null>(null)
const explainLoading = ref(false)
const explainData    = ref<{ breakdown: Breakdown; explanation: string } | null>(null)

async function explainFeatured(prop: Property) {
  explainFor.value     = prop.id
  explainData.value    = null
  explainLoading.value = true
  try {
    const res = await api.get<{ data: { breakdown: Breakdown; explanation: string } }>(`/admin/properties/${prop.id}/featured-explain`)
    explainData.value = res.data
  } catch {
    explainData.value = null
  } finally {
    explainLoading.value = false
  }
}

const selectCls = 'appearance-none bg-none rounded-full border-0 bg-white dark:bg-white/[0.06] py-2.5 pl-4 pr-9 text-sm font-semibold text-brand-navy dark:text-white ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold cursor-pointer'
</script>

<template>
  <div class="max-w-6xl mx-auto">
    <!-- ── Header ── -->
    <header class="mb-6">
      <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Manage</p>
      <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">Listings</h1>
      <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
        <span class="font-semibold text-brand-navy dark:text-white">{{ stats?.counts.published ?? 0 }}</span> live of
        <span class="font-semibold text-brand-navy dark:text-white">{{ stats?.counts.all ?? 0 }}</span> listings<template v-if="stats?.flagged_total">
          · <span class="font-semibold text-amber-700 dark:text-amber-400">{{ stats.flagged_total }}</span> need a look</template>.
        Click a listing to see it in full.
      </p>
    </header>

    <!-- ── Status tabs ── -->
    <div class="mb-4 -mx-1 px-1 overflow-x-auto no-scrollbar">
      <div class="inline-flex items-center gap-1 rounded-full p-1 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10" role="tablist">
        <button
          v-for="t in TABS"
          :key="t.key"
          type="button"
          role="tab"
          :aria-selected="tab === t.key"
          class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-semibold transition"
          :class="tab === t.key ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C]' : 'text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white'"
          @click="setTab(t.key)"
        >
          {{ t.label }}
          <span class="rounded-full px-1.5 text-xs tabular-nums"
                :class="tab === t.key ? 'bg-white/20 dark:bg-[#0B2A5C]/15' : t.key === 'unpublished' && t.count() ? 'bg-amber-500/15 text-amber-700 dark:text-amber-400' : 'opacity-60'">{{ t.count() }}</span>
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]">
      <div class="min-w-0">
        <!-- ── Search + filters ── -->
        <div class="mb-4 flex flex-wrap items-center gap-2.5">
          <div class="relative min-w-[13rem] flex-1">
            <svg class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-brand-navy/40 dark:text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
            <input
              v-model="search"
              type="search"
              placeholder="Search title, address or agent"
              aria-label="Search listings"
              class="w-full rounded-full border-0 bg-white dark:bg-white/[0.06] pl-10 pr-4 py-2.5 text-sm text-brand-navy dark:text-white placeholder-brand-navy/40 dark:placeholder-white/35 ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold"
            />
          </div>
          <div class="inline-flex items-center gap-0.5 rounded-full p-1 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10" role="radiogroup" aria-label="Sale or rent">
            <button
              v-for="o in ([['', 'All'], ['sale', 'Sale'], ['rent', 'Rent']] as const)"
              :key="o[0]"
              type="button"
              role="radio"
              :aria-checked="offer === o[0]"
              class="rounded-full px-3 py-1.5 text-xs font-semibold transition"
              :class="offer === o[0] ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C]' : 'text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white'"
              @click="offer = o[0]"
            >{{ o[1] }}</button>
          </div>
          <label class="relative inline-flex items-center">
            <span class="sr-only">Property type</span>
            <select v-model="type" :class="selectCls">
              <option v-for="t in TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
            <svg class="pointer-events-none absolute right-3 h-4 w-4 text-brand-navy/50 dark:text-white/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
          </label>
          <label class="relative inline-flex items-center">
            <span class="sr-only">Sort listings</span>
            <select v-model="sort" :class="selectCls">
              <option v-for="s in SORTS" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
            <svg class="pointer-events-none absolute right-3 h-4 w-4 text-brand-navy/50 dark:text-white/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
          </label>
        </div>

        <!-- ── Rows ── -->
        <div class="rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]">
          <div v-if="loading && !properties.length" class="p-4 space-y-2">
            <AppSkeleton v-for="i in 5" :key="i" width="100%" height="68px" />
          </div>

          <div v-else-if="!properties.length" class="px-6 py-14 text-center">
            <p class="font-display text-lg font-bold text-brand-navy dark:text-white">No listings match that</p>
            <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/55">Try another search, or clear the filters.</p>
            <button type="button" class="mt-4 rounded-full px-4 py-2 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20" @click="resetFilters">Show all listings</button>
          </div>

          <ul v-else :class="loading ? 'opacity-60 transition-opacity' : ''">
            <li
              v-for="p in properties"
              :key="p.id"
              class="group relative grid grid-cols-[4.5rem_minmax(0,1fr)_2.25rem] md:grid-cols-[4.5rem_minmax(0,1fr)_6.5rem_6.5rem_2.25rem] items-center gap-x-4 px-4 py-3
                     border-b border-brand-navy/[0.05] dark:border-white/[0.06] last:border-0 last:rounded-b-3xl first:rounded-t-3xl cursor-pointer
                     hover:bg-brand-navy/[0.025] dark:hover:bg-white/[0.03] transition-colors"
              @click="openDetails(p)"
            >
              <span class="h-14 w-[4.5rem] overflow-hidden rounded-xl bg-brand-navy/5 dark:bg-white/10">
                <img v-if="thumb(p)" :src="thumb(p)!" alt="" class="h-full w-full object-cover" loading="lazy" />
              </span>

              <div class="min-w-0">
                <p class="truncate font-semibold text-brand-navy dark:text-white group-hover:text-brand-gold-deep dark:group-hover:text-brand-gold transition-colors">{{ calm(p.title) }}</p>
                <p class="truncate text-xs text-brand-navy/55 dark:text-white/50">
                  {{ typeName(p.type) }} · {{ p.offer_type === 'rent' ? 'For rent' : 'For sale' }}<template v-if="city(p.address)"> · {{ city(p.address) }}</template> · {{ p.agent?.name ?? 'Unknown agent' }}
                </p>
                <p class="mt-1 flex flex-wrap items-center gap-1.5 text-[0.6875rem]">
                  <span class="md:hidden rounded-full px-2 py-0.5 font-semibold" :class="statusOf(p).cls">{{ statusOf(p).label }}</span>
                  <span class="md:hidden font-bold text-brand-gold-deep dark:text-brand-gold">{{ price(p) }}</span>
                  <span class="text-brand-navy/45 dark:text-white/40">{{ (p.views ?? 0).toLocaleString('en-PH') }} views</span>
                  <span v-if="(p.featured_score ?? 0) > 0" class="rounded-full px-2 py-0.5 font-semibold bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold" title="Featured score (0–100)">★ {{ p.featured_score }}</span>
                  <span v-if="p.status === 'draft' && p.unpublish_reason" class="truncate text-amber-700 dark:text-amber-400" :title="p.unpublish_reason">“{{ p.unpublish_reason }}”</span>
                </p>
              </div>

              <span class="hidden md:block text-sm font-bold tabular-nums text-brand-navy dark:text-white">{{ price(p) }}</span>
              <span class="hidden md:block"><span class="rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold" :class="statusOf(p).cls">{{ statusOf(p).label }}</span></span>

              <!-- ⋯ actions -->
              <div class="relative justify-self-end" @click.stop>
                <button
                  type="button"
                  class="h-9 w-9 rounded-full flex items-center justify-center text-brand-navy/55 hover:text-brand-navy hover:bg-brand-navy/[0.06] dark:text-white/55 dark:hover:text-white dark:hover:bg-white/10 transition"
                  :aria-label="`Actions for ${calm(p.title)}`"
                  :aria-expanded="menuFor === p.id"
                  @click="toggleMenu(p.id)"
                >
                  <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></svg>
                </button>
                <Transition name="menu">
                  <div v-if="menuFor === p.id" class="absolute right-0 top-full z-20 mt-1 w-52 overflow-hidden rounded-2xl bg-white dark:bg-[#0F2347] ring-1 ring-brand-navy/10 dark:ring-white/10 shadow-[0_18px_40px_-16px_rgba(11,42,92,0.45)] py-1.5" role="menu">
                    <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 px-4 py-2 text-sm font-medium text-brand-navy hover:bg-brand-navy/[0.05] dark:text-white dark:hover:bg-white/[0.06]" @click="openDetails(p)">View details</button>
                    <button v-if="p.status === 'published'" type="button" role="menuitem" class="flex w-full items-center gap-2.5 px-4 py-2 text-sm font-medium text-brand-navy hover:bg-brand-navy/[0.05] dark:text-white dark:hover:bg-white/[0.06]" @click="closeMenu(); preview(p)">Preview listing ↗</button>
                    <button v-if="p.status === 'published'" type="button" role="menuitem" class="flex w-full items-center gap-2.5 px-4 py-2 text-sm font-medium text-amber-700 hover:bg-amber-500/10 dark:text-amber-400" @click="openModeration('unpublish', p)">Unpublish…</button>
                    <div class="my-1 h-px bg-brand-navy/[0.06] dark:bg-white/[0.08]" />
                    <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-500/10 dark:text-red-400" @click="openModeration('delete', p)">Delete…</button>
                  </div>
                </Transition>
              </div>
            </li>
          </ul>
        </div>

        <!-- Pagination -->
        <div v-if="pagination && pagination.last_page > 1" class="flex items-center justify-center gap-3 mt-5">
          <button type="button" :disabled="pagination.current_page <= 1" class="h-9 w-9 flex items-center justify-center rounded-full ring-1 ring-brand-navy/15 text-brand-navy hover:ring-brand-gold dark:text-white dark:ring-white/20 disabled:opacity-40 disabled:cursor-not-allowed" aria-label="Previous page" @click="goPage(pagination.current_page - 1)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
          </button>
          <span class="text-sm text-brand-navy/60 dark:text-white/55">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
          <button type="button" :disabled="pagination.current_page >= pagination.last_page" class="h-9 w-9 flex items-center justify-center rounded-full ring-1 ring-brand-navy/15 text-brand-navy hover:ring-brand-gold dark:text-white dark:ring-white/20 disabled:opacity-40 disabled:cursor-not-allowed" aria-label="Next page" @click="goPage(pagination.current_page + 1)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
          </button>
        </div>
      </div>

      <!-- ── Side panel ── -->
      <aside class="space-y-4 lg:sticky lg:top-24 lg:self-start">
        <section class="relative overflow-hidden rounded-3xl p-5 bg-brand-navy text-white ring-1 ring-brand-gold/25">
          <div class="absolute inset-0 bg-grid-gold opacity-[0.06] pointer-events-none" />
          <p class="relative text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold">Needs a look</p>
          <p class="relative mt-1 text-sm text-white/70">
            <template v-if="stats?.flagged_total"><span class="font-semibold text-white">{{ stats.flagged_total }}</span> live {{ stats.flagged_total === 1 ? 'listing is' : 'listings are' }} missing something buyers rely on.</template>
            <template v-else>Every live listing has photos, a map pin and a proper description.</template>
          </p>
          <ul v-if="stats?.flagged.length" class="relative mt-3 space-y-1.5">
            <li v-for="f in stats.flagged" :key="f.id">
              <button type="button" class="group w-full rounded-xl p-2 -mx-2 text-left hover:bg-white/[0.07] transition" @click="openById(f.id)">
                <span class="block truncate text-sm font-semibold group-hover:text-brand-gold transition-colors">{{ calm(f.title) }}</span>
                <span class="mt-1 flex flex-wrap gap-1">
                  <span v-for="flag in f.flags" :key="flag" class="rounded-full bg-amber-400/15 px-2 py-0.5 text-[0.625rem] font-semibold text-amber-300">{{ flag }}</span>
                </span>
              </button>
            </li>
          </ul>
        </section>

        <section class="rounded-3xl p-5 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]">
          <p class="text-sm font-bold text-brand-navy dark:text-white">Most listings</p>
          <p class="text-xs text-brand-navy/50 dark:text-white/45">Agents with the most live listings</p>
          <ul v-if="stats?.top_agents.length" class="mt-3 space-y-1">
            <li v-for="(a, i) in stats.top_agents" :key="a.id">
              <button type="button" class="group flex w-full items-center gap-3 rounded-xl p-1.5 -mx-1.5 text-left hover:bg-brand-navy/[0.04] dark:hover:bg-white/[0.05] transition" :title="`Show ${a.name}'s listings`" @click="search = a.name">
                <span class="w-4 text-center text-xs font-bold tabular-nums text-brand-navy/40 dark:text-white/35">{{ i + 1 }}</span>
                <AppAvatar :name="a.name" :src="a.avatar" size="sm" />
                <span class="min-w-0 flex-1 truncate text-sm font-semibold text-brand-navy dark:text-white group-hover:text-brand-gold-deep dark:group-hover:text-brand-gold transition-colors">{{ a.name }}</span>
                <span class="flex-none rounded-full bg-brand-gold/15 px-2 py-0.5 text-xs font-bold tabular-nums text-brand-gold-deep dark:text-brand-gold">{{ a.count }}</span>
              </button>
            </li>
          </ul>
          <p v-else class="mt-3 text-sm text-brand-navy/55 dark:text-white/50">No live listings yet.</p>
        </section>
      </aside>
    </div>

    <!-- ── Details drawer ── -->
    <Teleport to="body">
      <Transition name="drawer">
        <div v-if="drawer" class="fixed inset-0 z-[70] flex justify-end" role="dialog" aria-modal="true" :aria-label="`Details for ${calm(drawer.title)}`">
          <div class="absolute inset-0 bg-[#06183A]/50 backdrop-blur-sm" @click="drawer = null" />
          <aside class="drawer-panel relative flex h-full w-full max-w-md flex-col overflow-hidden bg-[#ffffff] dark:bg-[#0B1A35] shadow-2xl">
            <button type="button" class="absolute right-3 top-3 z-10 h-9 w-9 rounded-full flex items-center justify-center bg-black/40 text-white hover:bg-black/60 backdrop-blur-sm transition" aria-label="Close details" @click="drawer = null">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>

            <div class="flex-1 overflow-y-auto">
              <!-- Photos -->
              <div class="relative aspect-[16/10] bg-brand-navy/5 dark:bg-white/5">
                <img v-if="drawerPhotos[drawerPhoto]" :src="drawerPhotos[drawerPhoto]!.url" alt="" class="h-full w-full object-cover" />
                <div v-else class="absolute inset-0 flex items-center justify-center text-sm text-brand-navy/45 dark:text-white/40">No photos</div>
                <span class="absolute left-3 top-3 rounded-full px-2.5 py-1 text-[0.6875rem] font-semibold backdrop-blur-sm" :class="statusOf(drawer).cls">{{ statusOf(drawer).label }}</span>
              </div>
              <div v-if="drawerPhotos.length > 1" class="flex gap-1.5 overflow-x-auto px-4 pt-3 no-scrollbar">
                <button
                  v-for="(ph, i) in drawerPhotos"
                  :key="ph.id"
                  type="button"
                  class="h-12 w-16 flex-none overflow-hidden rounded-lg ring-2 transition"
                  :class="i === drawerPhoto ? 'ring-brand-gold' : 'ring-transparent opacity-70 hover:opacity-100'"
                  :aria-label="`Photo ${i + 1}`"
                  @click="drawerPhoto = i"
                >
                  <img :src="ph.thumb_url ?? ph.url" alt="" class="h-full w-full object-cover" />
                </button>
              </div>

              <div class="px-6 pb-6 pt-4">
                <p class="text-xl font-bold text-brand-gold-deep dark:text-brand-gold tabular-nums">{{ fullPrice(drawer) }}</p>
                <h2 class="mt-0.5 font-display text-lg font-bold leading-snug text-brand-navy dark:text-white">{{ calm(drawer.title) }}</h2>
                <p class="mt-0.5 text-sm text-brand-navy/60 dark:text-white/55">{{ drawer.address }}</p>

                <!-- Taken down: why -->
                <div v-if="drawer.status === 'draft' && drawer.unpublish_reason" class="mt-4 rounded-2xl bg-amber-500/[0.08] ring-1 ring-amber-400/30 p-3">
                  <p class="text-xs font-bold uppercase tracking-wide text-amber-700 dark:text-amber-400">Unpublished by an admin</p>
                  <p class="mt-1 text-sm text-brand-navy/80 dark:text-white/75">“{{ drawer.unpublish_reason }}”</p>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
                  <div class="rounded-xl bg-brand-cream/70 dark:bg-white/[0.04] px-3 py-2"><dt class="text-[0.625rem] font-bold uppercase tracking-wide text-brand-navy/45 dark:text-white/40">Type</dt><dd class="font-semibold text-brand-navy dark:text-white">{{ typeName(drawer.type) }} · {{ drawer.offer_type === 'rent' ? 'Rent' : 'Sale' }}</dd></div>
                  <div class="rounded-xl bg-brand-cream/70 dark:bg-white/[0.04] px-3 py-2"><dt class="text-[0.625rem] font-bold uppercase tracking-wide text-brand-navy/45 dark:text-white/40">Rooms</dt><dd class="font-semibold text-brand-navy dark:text-white">{{ drawer.bedrooms ?? 0 }} bd · {{ drawer.bathrooms ?? 0 }} ba</dd></div>
                  <div class="rounded-xl bg-brand-cream/70 dark:bg-white/[0.04] px-3 py-2"><dt class="text-[0.625rem] font-bold uppercase tracking-wide text-brand-navy/45 dark:text-white/40">Size</dt><dd class="font-semibold text-brand-navy dark:text-white">{{ Number(drawer.floor_area) ? `${Number(drawer.floor_area)} m²` : Number(drawer.lot_area) ? `${Number(drawer.lot_area)} m² lot` : '—' }}</dd></div>
                  <div class="rounded-xl bg-brand-cream/70 dark:bg-white/[0.04] px-3 py-2"><dt class="text-[0.625rem] font-bold uppercase tracking-wide text-brand-navy/45 dark:text-white/40">Views</dt><dd class="font-semibold tabular-nums text-brand-navy dark:text-white">{{ (drawer.views ?? 0).toLocaleString('en-PH') }}</dd></div>
                  <div class="col-span-2 rounded-xl bg-brand-cream/70 dark:bg-white/[0.04] px-3 py-2"><dt class="text-[0.625rem] font-bold uppercase tracking-wide text-brand-navy/45 dark:text-white/40">Listed</dt><dd class="font-semibold text-brand-navy dark:text-white">{{ listedOn(drawer.created_at) }}</dd></div>
                </dl>

                <!-- Featured score, with the "why" opening right here -->
                <div class="mt-4 rounded-2xl p-4 ring-1 ring-brand-navy/[0.08] dark:ring-white/10">
                  <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-bold text-brand-navy dark:text-white">Featured score</p>
                    <button v-if="(drawer.featured_score ?? 0) > 0 && explainFor !== drawer.id" type="button" class="text-xs font-semibold text-brand-gold-deep hover:text-brand-navy dark:text-brand-gold dark:hover:text-white" @click="explainFeatured(drawer)">Why featured?</button>
                  </div>
                  <div class="mt-2 flex items-center gap-3">
                    <span class="text-lg font-bold tabular-nums text-brand-navy dark:text-white">{{ drawer.featured_score ?? 0 }}<span class="text-xs font-semibold text-brand-navy/45 dark:text-white/40">/100</span></span>
                    <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-brand-navy/10 dark:bg-white/10"><span class="block h-full rounded-full bg-brand-gold" :style="{ width: `${Math.min(100, drawer.featured_score ?? 0)}%` }" /></span>
                  </div>

                  <div v-if="explainFor === drawer.id" class="mt-4">
                    <div v-if="explainLoading" class="flex items-center gap-2 text-sm text-brand-navy/60 dark:text-white/55">
                      <span class="h-4 w-4 rounded-full border-2 border-brand-gold/30 border-t-brand-gold animate-spin" aria-hidden="true" />
                      RealtyLink AI is reading the score…
                    </div>
                    <template v-else-if="explainData">
                      <div class="rounded-xl bg-brand-gold/[0.07] ring-1 ring-brand-gold/30 p-3">
                        <p class="mb-1 flex items-center gap-1.5 text-[0.625rem] font-bold uppercase tracking-wide text-brand-gold-deep dark:text-brand-gold">
                          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
                          RealtyLink AI
                          <span class="ml-auto rounded-full px-2 py-0.5 normal-case tracking-normal" :class="explainData.breakdown.eligible ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-red-500/10 text-red-600 dark:text-red-400'">{{ explainData.breakdown.eligible ? 'Eligible' : 'Not eligible' }}</span>
                        </p>
                        <p class="text-sm leading-relaxed text-brand-navy dark:text-white/85">{{ explainData.explanation }}</p>
                      </div>
                      <p v-if="explainData.breakdown.gate_failures.length" class="mt-2 text-xs text-red-500">Blocked by: {{ explainData.breakdown.gate_failures.join(' · ') }}</p>
                      <div class="mt-3 space-y-2.5">
                        <div v-for="f in explainData.breakdown.factors" :key="f.key">
                          <div class="flex items-center justify-between text-xs">
                            <span class="font-semibold text-brand-navy dark:text-white">{{ f.label }}</span>
                            <span class="tabular-nums text-brand-navy/50 dark:text-white/45">{{ f.score }}/{{ f.max }}</span>
                          </div>
                          <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-brand-navy/10 dark:bg-white/10">
                            <div class="h-full rounded-full bg-brand-gold" :style="{ width: `${Math.min(100, (f.score / f.max) * 100)}%` }" />
                          </div>
                          <p class="mt-1 text-[0.6875rem] text-brand-navy/45 dark:text-white/40">{{ f.notes.join(' · ') }}</p>
                        </div>
                      </div>
                    </template>
                    <p v-else class="text-sm text-brand-navy/50 dark:text-white/45">Couldn't load the explanation.</p>
                  </div>
                </div>

                <!-- Agent -->
                <div v-if="drawer.agent" class="mt-4 flex items-center gap-3 rounded-2xl p-3 ring-1 ring-brand-navy/[0.08] dark:ring-white/10">
                  <AppAvatar :name="drawer.agent.name" :src="drawer.agent.avatar" size="md" />
                  <div class="min-w-0 flex-1">
                    <p class="text-[0.625rem] font-bold uppercase tracking-wide text-brand-navy/45 dark:text-white/40">Listed by</p>
                    <p class="truncate text-sm font-semibold text-brand-navy dark:text-white">{{ drawer.agent.name }}</p>
                  </div>
                  <button type="button" class="flex-none text-xs font-semibold text-brand-gold-deep hover:text-brand-navy dark:text-brand-gold dark:hover:text-white" @click="search = drawer.agent!.name; drawer = null">Their listings</button>
                </div>

                <p v-if="drawer.description" class="mt-4 whitespace-pre-line text-sm leading-relaxed text-brand-navy/75 dark:text-white/70 line-clamp-[8]">{{ drawer.description }}</p>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex-none flex flex-wrap gap-2 border-t border-brand-navy/[0.07] dark:border-white/[0.08] px-6 py-4">
              <button v-if="drawer.status === 'published'" type="button" class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-full px-4 py-2.5 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 transition" @click="preview(drawer)">
                Preview
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
              </button>
              <button v-if="drawer.status === 'published'" type="button" class="flex-1 rounded-full px-4 py-2.5 text-sm font-semibold text-amber-700 ring-1 ring-amber-300 hover:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-400/40 transition" @click="openModeration('unpublish', drawer)">Unpublish</button>
              <button type="button" class="flex-1 rounded-full px-4 py-2.5 text-sm font-semibold text-red-600 ring-1 ring-red-300 hover:bg-red-500/10 dark:text-red-400 dark:ring-red-400/40 transition" @click="openModeration('delete', drawer)">Delete</button>
            </div>
          </aside>
        </div>
      </Transition>
    </Teleport>

    <ListingModerationDialog
      :open="!!moderate"
      :mode="moderate?.mode ?? 'unpublish'"
      :property="moderate?.property ?? null"
      @close="moderate = null"
      @done="onModerated"
    />

  </div>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }

.menu-enter-active, .menu-leave-active { transition: opacity 0.12s ease, transform 0.12s ease; }
.menu-enter-from, .menu-leave-to { opacity: 0; transform: translateY(-4px) scale(0.98); }

.drawer-enter-active, .drawer-leave-active { transition: opacity 0.25s ease; }
.drawer-enter-active .drawer-panel, .drawer-leave-active .drawer-panel { transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1); }
.drawer-enter-from, .drawer-leave-to { opacity: 0; }
.drawer-enter-from .drawer-panel, .drawer-leave-to .drawer-panel { transform: translateX(100%); }
@media (prefers-reduced-motion: reduce) {
  .drawer-enter-active .drawer-panel, .drawer-leave-active .drawer-panel { transition: none; }
}
</style>
