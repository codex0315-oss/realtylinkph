<script setup lang="ts">
import type { ApiResponse, AgentReview, PaginatedResponse } from '~/types'

definePageMeta({ layout: 'dashboard' })

/*
 * Review moderation, top to bottom:
 *   header → All / Visible / Hidden tabs → search, star filter, sort
 *   → review cards (as buyers write them: stars, tags, note, the listing)
 *     beside the platform's ratings and the agents with the lowest average.
 * Hiding needs a reason (HideReviewDialog), which the reviewer is told and
 * the hidden card shows; showing again asks first.
 */
const authStore = useAuthStore()
if (!authStore.isAdmin) {
  throw createError({ statusCode: 403, statusMessage: 'Forbidden' })
}

const api = useApi()
const ask = useConfirm()
const toast = useToast()
const { toggleVisibility } = useReview()

type Tab = '' | '1' | '0'
type Stars = '' | '5' | '4' | '3' | 'low'
type Sort = 'newest' | 'oldest' | 'lowest'

const reviews    = ref<AgentReview[]>([])
const pagination = ref<PaginatedResponse<AgentReview>['meta'] | null>(null)
const loading    = ref(false)
const page       = ref(1)
const tab        = ref<Tab>('')
const stars      = ref<Stars>('')
const search     = ref('')
const sort       = ref<Sort>('newest')

async function load() {
  loading.value = true
  try {
    const params: Record<string, unknown> = { page: page.value }
    if (tab.value) params.visible = tab.value
    if (stars.value) params.stars = stars.value
    if (search.value.trim()) params.search = search.value.trim()
    if (sort.value !== 'newest') params.sort = sort.value
    const res = await api.get<PaginatedResponse<AgentReview>>('/admin/reviews', params)
    reviews.value = res.data
    pagination.value = res.meta
  } catch {
    reviews.value = []
  } finally {
    loading.value = false
  }
}

interface Stats {
  counts: { all: number; visible: number; hidden: number }
  average: number
  spread: Record<'1' | '2' | '3' | '4' | '5', number>
  lowest_agents: Array<{ id: number; name: string; avatar: string | null; rating: number; count: number }>
}
const stats = ref<Stats | null>(null)
async function loadStats() {
  try {
    stats.value = (await api.get<ApiResponse<Stats>>('/admin/reviews/stats')).data
  } catch { /* the page still works without it */ }
}
await Promise.all([load(), loadStats()])

const TABS = computed(() => [
  { key: '' as Tab,  label: 'All',     count: stats.value?.counts.all ?? 0 },
  { key: '1' as Tab, label: 'Visible', count: stats.value?.counts.visible ?? 0 },
  { key: '0' as Tab, label: 'Hidden',  count: stats.value?.counts.hidden ?? 0 },
])
const STAR_FILTERS: Array<{ value: Stars; label: string }> = [
  { value: '', label: 'All' }, { value: '5', label: '5★' }, { value: '4', label: '4★' }, { value: '3', label: '3★' }, { value: 'low', label: '2★ & below' },
]
const SORTS: Array<{ value: Sort; label: string }> = [
  { value: 'newest', label: 'Newest' }, { value: 'oldest', label: 'Oldest' }, { value: 'lowest', label: 'Lowest rating first' },
]

function setTab(t: Tab) { tab.value = t; page.value = 1; load() }
function goPage(n: number) { page.value = n; load() }
watch([stars, sort], () => { page.value = 1; load() })
let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; load() }, 300)
})
function resetFilters() { search.value = ''; stars.value = ''; sort.value = 'newest'; setTab('') }

/* ── Review text: "👍 tag · tag" line → chips, the rest → note ── */
function parts(r: AgentReview) {
  const lines = (r.review_text ?? '').split(/\r?\n/)
  const hasTags = (lines[0] ?? '').startsWith('👍')
  return {
    tags: hasTags ? lines[0]!.slice(2).split('·').map(t => t.trim()).filter(Boolean) : [],
    note: (hasTags ? lines.slice(1) : lines).join('\n').trim(),
  }
}
function calm(t?: string | null): string {
  if (!t) return ''
  const letters = t.replace(/[^A-Za-z]/g, '')
  return letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
}
const date = (iso?: string | null) => (iso ? new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) : '')
const pct = (n: number) => (stats.value?.counts.visible ? Math.round((n / stats.value.counts.visible) * 100) : 0)

/* ── Hide / show ── */
const hideTarget = ref<AgentReview | null>(null)
async function refresh() { await Promise.all([load(), loadStats()]) }
async function onHidden() { hideTarget.value = null; await refresh() }
async function showAgain(r: AgentReview) {
  const ok = await ask({
    title: 'Show this review again?',
    message: `It goes back on ${r.agent?.name ?? 'the agent'}'s profile and counts toward their rating.`,
    subject: `${r.buyer?.name ?? 'Reviewer'} → ${r.agent?.name ?? 'Agent'} · ${r.rating}★`,
    consequences: ['Visible to everyone again', 'The hide reason is cleared', 'Logged in Activity'],
    confirmLabel: 'Show review',
  })
  if (!ok) return
  if (await toggleVisibility(r.id)) {
    toast.success('Review is visible again')
    await refresh()
  } else {
    toast.error('Couldn’t show the review. Please try again.')
  }
}

const STAR = 'M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z'
</script>

<template>
  <div class="max-w-6xl mx-auto">
    <!-- ── Header ── -->
    <header class="mb-6">
      <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Manage</p>
      <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">Reviews</h1>
      <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
        <span class="font-semibold text-brand-navy dark:text-white">{{ stats?.counts.all ?? 0 }}</span> reviews of agents<template v-if="stats?.counts.hidden">
          · <span class="font-semibold text-brand-navy dark:text-white">{{ stats.counts.hidden }}</span> hidden</template>.
        Hide anything abusive, personal or fake; the reviewer is told why.
      </p>
    </header>

    <!-- ── Tabs ── -->
    <div class="mb-4 inline-flex items-center gap-1 rounded-full p-1 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10" role="tablist">
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
        <span class="rounded-full px-1.5 text-xs tabular-nums" :class="tab === t.key ? 'bg-white/20 dark:bg-[#0B2A5C]/15' : 'opacity-60'">{{ t.count }}</span>
      </button>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]">
      <div class="min-w-0">
        <!-- ── Search + stars + sort ── -->
        <div class="mb-4 flex flex-wrap items-center gap-2.5">
          <div class="relative min-w-[13rem] flex-1">
            <svg class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-brand-navy/40 dark:text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
            <input
              v-model="search"
              type="search"
              placeholder="Search reviewer or agent"
              aria-label="Search reviews"
              class="w-full rounded-full border-0 bg-white dark:bg-white/[0.06] pl-10 pr-4 py-2.5 text-sm text-brand-navy dark:text-white placeholder-brand-navy/40 dark:placeholder-white/35 ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold"
            />
          </div>
          <div class="inline-flex items-center gap-0.5 rounded-full p-1 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10" role="radiogroup" aria-label="Stars">
            <button
              v-for="s in STAR_FILTERS"
              :key="s.value"
              type="button"
              role="radio"
              :aria-checked="stars === s.value"
              class="whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold transition"
              :class="stars === s.value ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C]' : 'text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white'"
              @click="stars = s.value"
            >{{ s.label }}</button>
          </div>
          <label class="relative inline-flex items-center">
            <span class="sr-only">Sort reviews</span>
            <select v-model="sort" class="appearance-none bg-none rounded-full border-0 bg-white dark:bg-white/[0.06] py-2.5 pl-4 pr-9 text-sm font-semibold text-brand-navy dark:text-white ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold cursor-pointer">
              <option v-for="o in SORTS" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
            <svg class="pointer-events-none absolute right-3 h-4 w-4 text-brand-navy/50 dark:text-white/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
          </label>
        </div>

        <!-- ── Reviews ── -->
        <div v-if="loading && !reviews.length" class="space-y-3">
          <AppSkeleton v-for="i in 3" :key="i" width="100%" height="160px" rounded="lg" />
        </div>

        <div v-else-if="!reviews.length" class="rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] px-6 py-14 text-center">
          <p class="font-display text-lg font-bold text-brand-navy dark:text-white">No reviews match that</p>
          <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/55">Try another search, or clear the filters.</p>
          <button type="button" class="mt-4 rounded-full px-4 py-2 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20" @click="resetFilters">Show all reviews</button>
        </div>

        <ul v-else class="space-y-4" :class="loading ? 'opacity-60 transition-opacity' : ''">
          <li
            v-for="r in reviews"
            :key="r.id"
            class="rounded-3xl p-5 ring-1 transition"
            :class="r.is_visible
              ? 'bg-white ring-brand-navy/[0.07] dark:bg-[#0B1A35] dark:ring-white/[0.08]'
              : 'bg-white ring-red-300/50 dark:bg-[#0B1A35] dark:ring-red-400/25'"
          >
            <!-- Who reviewed whom -->
            <div class="flex flex-wrap items-start gap-3">
              <AppAvatar :name="r.buyer?.name" :src="r.buyer?.avatar" size="md" />
              <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-x-1.5 text-sm text-brand-navy dark:text-white">
                  <span class="font-semibold">{{ r.buyer?.name ?? 'Unknown' }}</span>
                  <span class="text-brand-navy/40 dark:text-white/40">reviewed</span>
                  <span class="inline-flex items-center gap-1 font-semibold">
                    <AppAvatar :name="r.agent?.name" :src="r.agent?.avatar" size="xs" />{{ r.agent?.name ?? 'Unknown agent' }}
                  </span>
                </p>
                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                  <span class="inline-flex gap-0.5" :aria-label="`${r.rating} out of 5 stars`">
                    <svg v-for="n in 5" :key="n" class="h-4 w-4" :class="n <= r.rating ? 'text-brand-gold' : 'text-brand-navy/15 dark:text-white/15'" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
                  </span>
                  <span class="text-xs text-brand-navy/50 dark:text-white/45">{{ date(r.created_at) }}<template v-if="r.is_edited"> · edited</template></span>
                </div>
              </div>
              <div class="flex flex-wrap items-center gap-1.5">
                <span v-if="r.is_verified" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold bg-emerald-500/10 text-emerald-700 dark:text-emerald-400">
                  <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                  Verified viewing
                </span>
                <span class="rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold" :class="r.is_visible ? 'bg-brand-navy/[0.06] text-brand-navy/70 dark:bg-white/10 dark:text-white/70' : 'bg-red-500/10 text-red-600 dark:text-red-400'">
                  {{ r.is_visible ? 'Visible' : 'Hidden' }}
                </span>
              </div>
            </div>

            <!-- What they said -->
            <div v-if="parts(r).tags.length" class="mt-3 flex flex-wrap gap-1.5">
              <span v-for="t in parts(r).tags" :key="t" class="rounded-full px-2.5 py-1 text-[0.6875rem] font-semibold bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold">{{ t }}</span>
            </div>
            <p v-if="parts(r).note" class="mt-3 whitespace-pre-wrap text-sm leading-relaxed text-brand-navy/80 dark:text-white/75">“{{ parts(r).note }}”</p>

            <!-- About which listing -->
            <a
              v-if="r.property_title"
              :href="r.property_id ? `/properties/${r.property_id}` : undefined"
              target="_blank"
              rel="noopener"
              class="mt-3 flex items-center gap-3 rounded-2xl p-2 pr-3 bg-brand-cream/80 ring-1 ring-brand-navy/[0.05] hover:ring-brand-gold dark:bg-white/[0.04] dark:ring-white/[0.06] transition"
            >
              <span class="h-10 w-12 flex-none overflow-hidden rounded-lg bg-brand-navy/5 dark:bg-white/10">
                <img v-if="r.property_photo" :src="r.property_photo" alt="" class="h-full w-full object-cover" loading="lazy" />
              </span>
              <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-brand-navy dark:text-white">{{ calm(r.property_title) }}</span>
                <span v-if="r.viewed_at" class="block text-xs text-brand-navy/50 dark:text-white/45">Viewed {{ date(r.viewed_at) }}</span>
              </span>
            </a>
            <p v-else class="mt-3 text-xs text-brand-navy/50 dark:text-white/45">Based on a chat with the agent, not a viewing.</p>

            <!-- Hidden: why, by whom -->
            <div v-if="!r.is_visible" class="mt-3 rounded-2xl bg-red-500/[0.06] ring-1 ring-red-400/25 px-4 py-3">
              <p class="text-xs font-bold uppercase tracking-wide text-red-600 dark:text-red-400">
                Hidden<template v-if="r.hidden_by"> by {{ r.hidden_by }}</template><template v-if="r.hidden_at"> · {{ date(r.hidden_at) }}</template>
              </p>
              <p class="mt-1 text-sm text-brand-navy/80 dark:text-white/75">{{ r.hidden_reason ? `“${r.hidden_reason}”` : 'No reason was recorded (hidden before reasons were required).' }}</p>
            </div>

            <div class="mt-4 flex justify-end">
              <button
                v-if="r.is_visible"
                type="button"
                class="rounded-full px-4 py-1.5 text-xs font-semibold text-red-600 ring-1 ring-red-300 hover:bg-red-500/10 dark:text-red-400 dark:ring-red-400/40 transition"
                @click="hideTarget = r"
              >Hide…</button>
              <button
                v-else
                type="button"
                class="rounded-full bg-brand-gold px-4 py-1.5 text-xs font-bold text-[#0B2A5C] shadow-[0_4px_14px_rgba(212,165,74,0.4)] hover:bg-brand-gold-light transition"
                @click="showAgain(r)"
              >Show again</button>
            </div>
          </li>
        </ul>

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
          <p class="relative text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold">Platform rating</p>
          <template v-if="stats?.counts.visible">
            <div class="relative mt-2 flex items-end gap-3">
              <span class="font-display text-4xl font-bold leading-none tabular-nums">{{ stats.average.toFixed(1) }}</span>
              <span class="pb-0.5">
                <span class="flex gap-0.5" :aria-label="`${stats.average.toFixed(1)} out of 5 on average`">
                  <svg v-for="n in 5" :key="n" class="h-4 w-4" :class="n <= Math.round(stats.average) ? 'text-brand-gold' : 'text-white/20'" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
                </span>
                <span class="block text-xs text-white/60">from {{ stats.counts.visible }} visible {{ stats.counts.visible === 1 ? 'review' : 'reviews' }}</span>
              </span>
            </div>
            <ul class="relative mt-4 space-y-1.5">
              <li v-for="n in [5, 4, 3, 2, 1]" :key="n">
                <button type="button" class="flex w-full items-center gap-2.5 text-xs hover:text-brand-gold transition-colors" :title="`Show ${n}★ reviews`" @click="stars = n >= 3 ? (String(n) as Stars) : 'low'">
                  <span class="w-6 flex-none text-left tabular-nums text-white/70">{{ n }} ★</span>
                  <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-white/10">
                    <span class="block h-full rounded-full bg-brand-gold transition-[width] duration-700" :style="{ width: `${pct(stats.spread[String(n) as '1'])}%` }" />
                  </span>
                  <span class="w-5 flex-none text-right tabular-nums text-white/70">{{ stats.spread[String(n) as '1'] }}</span>
                </button>
              </li>
            </ul>
          </template>
          <p v-else class="relative mt-2 text-sm text-white/70">No visible reviews yet.</p>
        </section>

        <section class="rounded-3xl p-5 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]">
          <p class="text-sm font-bold text-brand-navy dark:text-white">Lowest rated agents</p>
          <p class="text-xs text-brand-navy/50 dark:text-white/45">Worth a look; tap one to see their reviews</p>
          <ul v-if="stats?.lowest_agents.length" class="mt-3 space-y-1">
            <li v-for="a in stats.lowest_agents" :key="a.id">
              <button type="button" class="group flex w-full items-center gap-3 rounded-xl p-1.5 -mx-1.5 text-left hover:bg-brand-navy/[0.04] dark:hover:bg-white/[0.05] transition" @click="search = a.name">
                <AppAvatar :name="a.name" :src="a.avatar" size="sm" />
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-sm font-semibold text-brand-navy dark:text-white group-hover:text-brand-gold-deep dark:group-hover:text-brand-gold transition-colors">{{ a.name }}</span>
                  <span class="block text-xs text-brand-navy/50 dark:text-white/45">{{ a.count }} {{ a.count === 1 ? 'review' : 'reviews' }}</span>
                </span>
                <span class="flex-none rounded-full px-2 py-0.5 text-xs font-bold tabular-nums"
                      :class="a.rating < 3 ? 'bg-red-500/10 text-red-600 dark:text-red-400' : a.rating < 4 ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'">{{ a.rating.toFixed(1) }}★</span>
              </button>
            </li>
          </ul>
          <p v-else class="mt-3 text-sm text-brand-navy/55 dark:text-white/50">No ratings yet.</p>
        </section>
      </aside>
    </div>

    <HideReviewDialog :open="!!hideTarget" :review="hideTarget" @close="hideTarget = null" @done="onHidden" />
  </div>
</template>
