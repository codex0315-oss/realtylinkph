<script setup lang="ts">
import type { ApiResponse, AdminAction, PaginatedResponse } from '~/types'

definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()
if (!authStore.isAdmin) {
  throw createError({ statusCode: 403, statusMessage: 'Forbidden' })
}

const api = useApi()

/*
 * The audit trail. Every administrator action — approving an agent, taking a
 * listing down, hiding a review, deleting a user — is written server-side at
 * the moment it happens, with who did it and why. This page only reads:
 *   header → area tabs → search, admin, time range
 *   → a timeline grouped by day, beside this week's numbers and who's active.
 */
type Area = '' | 'agents' | 'listings' | 'reviews' | 'users'

const rows       = ref<AdminAction[]>([])
const pagination = ref<PaginatedResponse<AdminAction>['meta'] | null>(null)
const loading    = ref(false)
const page       = ref(1)
const area       = ref<Area>('')
const search     = ref('')
const adminId    = ref('')
const days       = ref('')

async function load() {
  loading.value = true
  try {
    const res = await api.get<PaginatedResponse<AdminAction>>('/admin/actions', {
      page: page.value,
      ...(area.value ? { area: area.value } : {}),
      ...(adminId.value ? { admin_id: adminId.value } : {}),
      ...(days.value ? { days: days.value } : {}),
      ...(search.value.trim() ? { search: search.value.trim() } : {}),
    })
    rows.value       = res.data
    pagination.value = res.meta
  } catch {
    rows.value = []
  } finally {
    loading.value = false
  }
}

interface Stats {
  total: number
  week: number
  areas: Record<Exclude<Area, ''>, number>
  admins: Array<{ id: number | null; name: string; count: number }>
  admin_list: Array<{ id: number; name: string }>
}
const stats = ref<Stats | null>(null)
async function loadStats() {
  try {
    stats.value = (await api.get<ApiResponse<Stats>>('/admin/actions/stats')).data
  } catch { /* the page still works without it */ }
}
await Promise.all([load(), loadStats()])

/* ── Filters ── */
const AREAS: Array<{ key: Area; label: string }> = [
  { key: '', label: 'All' }, { key: 'agents', label: 'Agents' }, { key: 'listings', label: 'Listings' },
  { key: 'reviews', label: 'Reviews' }, { key: 'users', label: 'Users' },
]
const RANGES = [{ value: '', label: 'All time' }, { value: '7', label: 'Last 7 days' }, { value: '30', label: 'Last 30 days' }]
function setArea(a: Area) { area.value = a; page.value = 1; load() }
function goPage(p: number) { page.value = p; load() }
watch([adminId, days], () => { page.value = 1; load() })
let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; load() }, 300)
})
const filtered = computed(() => !!(area.value || adminId.value || days.value || search.value.trim()))
function resetFilters() { search.value = ''; adminId.value = ''; days.value = ''; setArea('') }

/* ── Look of each action ── */
const ICON = {
  agent:   'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
  listing: 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
  review:  'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z',
  user:    'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
} as const
function iconFor(action: string): string {
  if (action.startsWith('agent.')) return ICON.agent
  if (action.startsWith('listing.')) return ICON.listing
  if (action.startsWith('review.')) return ICON.review
  return ICON.user
}
/* Green for things restored or granted, red for removals, amber for takedowns. */
function toneFor(action: string): string {
  if (/\.(approved|shown|created)$/.test(action)) return 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-400'
  if (/\.(deleted|rejected)$/.test(action)) return 'bg-red-500/10 text-red-600 ring-red-400/25 dark:text-red-400'
  return 'bg-amber-500/10 text-amber-700 ring-amber-400/30 dark:text-amber-400'
}

/* ── Timeline: grouped by day ── */
const groups = computed(() => {
  const out: Array<{ key: string; label: string; items: AdminAction[] }> = []
  for (const a of rows.value) {
    const d = new Date(a.created_at)
    const key = d.toDateString()
    let g = out.find(x => x.key === key)
    if (!g) { g = { key, label: dayLabel(d), items: [] }; out.push(g) }
    g.items.push(a)
  }
  return out
})
function dayLabel(d: Date): string {
  const today = new Date()
  const yesterday = new Date(Date.now() - 86400000)
  if (d.toDateString() === today.toDateString()) return 'Today'
  if (d.toDateString() === yesterday.toDateString()) return 'Yesterday'
  return d.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' })
}
const time = (iso: string) => new Date(iso).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })

/* The reason gets its own quote; any other recorded details expand below. */
const reasonOf = (a: AdminAction) => (typeof a.details?.reason === 'string' ? a.details.reason : '')
const DETAIL_LABEL: Record<string, string> = { agent: 'Agent', role: 'Role', status: 'Status', applicant_type: 'Applied as' }
function extraDetails(a: AdminAction): Array<[string, string]> {
  return Object.entries(a.details ?? {})
    .filter(([k, v]) => k !== 'reason' && v !== null && v !== '')
    .map(([k, v]) => [DETAIL_LABEL[k] ?? k.replace(/_/g, ' '), String(v)])
}
const open = ref<Set<number>>(new Set())
function toggle(id: number) {
  const next = new Set(open.value)
  next.has(id) ? next.delete(id) : next.add(id)
  open.value = next
}

const AREA_LABEL: Record<Exclude<Area, ''>, string> = { agents: 'Agent decisions', listings: 'Listing takedowns', reviews: 'Review moderation', users: 'Account changes' }
const selectCls = 'appearance-none bg-none rounded-full border-0 bg-white dark:bg-white/[0.06] py-2.5 pl-4 pr-9 text-sm font-semibold text-brand-navy dark:text-white ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold cursor-pointer'
</script>

<template>
  <div class="max-w-6xl mx-auto">
    <!-- ── Header ── -->
    <header class="mb-6">
      <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Manage</p>
      <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">Activity</h1>
      <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
        <span class="font-semibold text-brand-navy dark:text-white">{{ stats?.total ?? 0 }}</span> admin {{ stats?.total === 1 ? 'action' : 'actions' }} recorded<template v-if="stats?.week">
          · <span class="font-semibold text-brand-navy dark:text-white">{{ stats.week }}</span> this week</template>.
        Every decision is kept here with who made it and why.
      </p>
    </header>

    <!-- ── Area tabs ── -->
    <div class="mb-4 -mx-1 px-1 overflow-x-auto no-scrollbar">
      <div class="inline-flex items-center gap-1 rounded-full p-1 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10" role="tablist">
        <button
          v-for="t in AREAS"
          :key="t.key"
          type="button"
          role="tab"
          :aria-selected="area === t.key"
          class="whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-semibold transition"
          :class="area === t.key ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C]' : 'text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white'"
          @click="setArea(t.key)"
        >{{ t.label }}</button>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]">
      <div class="min-w-0">
        <!-- ── Search + who + when ── -->
        <div class="mb-5 flex flex-wrap items-center gap-2.5">
          <div class="relative min-w-[13rem] flex-1">
            <svg class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-brand-navy/40 dark:text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
            <input
              v-model="search"
              type="search"
              placeholder="Search a name, listing or admin"
              aria-label="Search activity"
              class="w-full rounded-full border-0 bg-white dark:bg-white/[0.06] pl-10 pr-4 py-2.5 text-sm text-brand-navy dark:text-white placeholder-brand-navy/40 dark:placeholder-white/35 ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold"
            />
          </div>
          <label class="relative inline-flex items-center">
            <span class="sr-only">Admin</span>
            <select v-model="adminId" :class="selectCls">
              <option value="">Every admin</option>
              <option v-for="a in stats?.admin_list ?? []" :key="a.id" :value="String(a.id)">{{ a.name }}</option>
            </select>
            <svg class="pointer-events-none absolute right-3 h-4 w-4 text-brand-navy/50 dark:text-white/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
          </label>
          <label class="relative inline-flex items-center">
            <span class="sr-only">Time range</span>
            <select v-model="days" :class="selectCls">
              <option v-for="r in RANGES" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
            <svg class="pointer-events-none absolute right-3 h-4 w-4 text-brand-navy/50 dark:text-white/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
          </label>
        </div>

        <!-- ── Timeline ── -->
        <div v-if="loading && !rows.length" class="space-y-3">
          <AppSkeleton v-for="i in 4" :key="i" width="100%" height="76px" rounded="lg" />
        </div>

        <div v-else-if="!rows.length" class="relative overflow-hidden rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] px-6 py-14 text-center">
          <div class="absolute -top-16 left-1/2 -translate-x-1/2 h-48 w-80 rounded-full bg-brand-gold/10 blur-3xl pointer-events-none" />
          <span class="relative mx-auto mb-5 h-14 w-14 rounded-2xl bg-brand-gold/15 flex items-center justify-center text-brand-gold-deep dark:text-brand-gold">
            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          </span>
          <p class="relative font-display text-lg font-bold text-brand-navy dark:text-white">{{ filtered ? 'Nothing matches that' : 'Nothing recorded yet' }}</p>
          <p class="relative mx-auto mt-1.5 max-w-sm text-sm text-brand-text-secondary dark:text-white/55">
            {{ filtered ? 'Try another search, or clear the filters.' : 'Approvals, takedowns, hidden reviews and removed accounts show up here the moment they happen.' }}
          </p>
          <button v-if="filtered" type="button" class="relative mt-4 rounded-full px-4 py-2 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20" @click="resetFilters">Show everything</button>
        </div>

        <div v-else class="space-y-6" :class="loading ? 'opacity-60 transition-opacity' : ''">
          <section v-for="g in groups" :key="g.key">
            <h2 class="mb-2 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">{{ g.label }}</h2>
            <ol class="relative rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]">
              <li v-for="a in g.items" :key="a.id" class="flex gap-3.5 px-4 sm:px-5 py-4 border-b border-brand-navy/[0.05] dark:border-white/[0.06] last:border-0">
                <span class="mt-0.5 h-9 w-9 flex-none rounded-xl flex items-center justify-center ring-1" :class="toneFor(a.action)">
                  <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="iconFor(a.action)" /></svg>
                </span>
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                    <p class="text-sm text-brand-navy dark:text-white">
                      <span class="font-semibold">{{ a.admin_name }}</span>
                      <span class="text-brand-navy/60 dark:text-white/60"> · {{ a.label.charAt(0).toLowerCase() + a.label.slice(1) }}</span>
                    </p>
                    <span class="flex-none text-xs tabular-nums text-brand-navy/45 dark:text-white/40">{{ time(a.created_at) }}</span>
                  </div>
                  <p v-if="a.subject_label" class="mt-0.5 truncate text-sm font-semibold text-brand-navy dark:text-white" :title="a.subject_label">{{ a.subject_label }}</p>
                  <p v-if="reasonOf(a)" class="mt-1.5 rounded-xl bg-brand-cream/80 dark:bg-white/[0.04] px-3 py-2 text-sm text-brand-navy/75 dark:text-white/70">“{{ reasonOf(a) }}”</p>

                  <template v-if="extraDetails(a).length">
                    <button type="button" class="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold text-brand-navy/50 hover:text-brand-navy dark:text-white/45 dark:hover:text-white" :aria-expanded="open.has(a.id)" @click="toggle(a.id)">
                      {{ open.has(a.id) ? 'Hide details' : 'Details' }}
                      <svg class="h-3 w-3 transition-transform" :class="open.has(a.id) ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <dl v-if="open.has(a.id)" class="mt-1.5 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                      <template v-for="[k, v] in extraDetails(a)" :key="k">
                        <dt class="capitalize text-brand-navy/50 dark:text-white/45">{{ k }}</dt>
                        <dd class="font-semibold text-brand-navy dark:text-white">{{ v }}</dd>
                      </template>
                      <template v-if="a.subject_type">
                        <dt class="text-brand-navy/50 dark:text-white/45">Record</dt>
                        <dd class="font-semibold text-brand-navy dark:text-white">{{ a.subject_type }} #{{ a.subject_id }}</dd>
                      </template>
                    </dl>
                  </template>
                </div>
              </li>
            </ol>
          </section>
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
          <p class="relative text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold">This week</p>
          <p class="relative mt-1 flex items-baseline gap-2">
            <span class="font-display text-4xl font-bold leading-none tabular-nums">{{ stats?.week ?? 0 }}</span>
            <span class="text-sm text-white/65">{{ stats?.week === 1 ? 'action' : 'actions' }} in the last 7 days</span>
          </p>
          <ul class="relative mt-4 space-y-1">
            <li v-for="(label, key) in AREA_LABEL" :key="key">
              <button type="button" class="flex w-full items-center justify-between gap-3 rounded-xl px-2 py-1.5 -mx-2 text-sm hover:bg-white/[0.07] transition" @click="days = '7'; setArea(key)">
                <span class="text-white/80">{{ label }}</span>
                <span class="tabular-nums font-semibold" :class="stats?.areas[key] ? 'text-brand-gold' : 'text-white/40'">{{ stats?.areas[key] ?? 0 }}</span>
              </button>
            </li>
          </ul>
        </section>

        <section class="rounded-3xl p-5 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]">
          <p class="text-sm font-bold text-brand-navy dark:text-white">Most active admins</p>
          <p class="text-xs text-brand-navy/50 dark:text-white/45">Last 30 days; tap one to see their actions</p>
          <ul v-if="stats?.admins.length" class="mt-3 space-y-1">
            <li v-for="a in stats.admins" :key="`${a.id}-${a.name}`">
              <button type="button" class="group flex w-full items-center gap-3 rounded-xl p-1.5 -mx-1.5 text-left hover:bg-brand-navy/[0.04] dark:hover:bg-white/[0.05] transition disabled:cursor-default" :disabled="!a.id" @click="a.id && (adminId = String(a.id))">
                <AppAvatar :name="a.name" size="sm" />
                <span class="min-w-0 flex-1 truncate text-sm font-semibold text-brand-navy dark:text-white group-hover:text-brand-gold-deep dark:group-hover:text-brand-gold transition-colors">{{ a.name }}</span>
                <span class="flex-none rounded-full bg-brand-gold/15 px-2 py-0.5 text-xs font-bold tabular-nums text-brand-gold-deep dark:text-brand-gold">{{ a.count }}</span>
              </button>
            </li>
          </ul>
          <p v-else class="mt-3 text-sm text-brand-navy/55 dark:text-white/50">No admin actions in the last 30 days.</p>
          <p class="mt-3 rounded-xl bg-brand-cream/80 dark:bg-white/[0.04] px-3 py-2 text-xs text-brand-navy/70 dark:text-white/60">
            Nothing in the app can edit or remove these entries, so they stay a reliable record of every decision.
          </p>
        </section>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
</style>
