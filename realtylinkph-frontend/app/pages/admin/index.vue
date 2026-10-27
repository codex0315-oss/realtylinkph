<script setup lang="ts">
import type { ApiResponse, PaginatedResponse } from '~/types'
import type { DashStat } from '~/components/DashStatStrip.vue'

definePageMeta({ layout: 'dashboard' })

/*
 * The admin's home page, top to bottom:
 *   greeting + who's online → "Needs your attention" (applications, hidden
 *   reviews, unpublished listings, and the oldest applications to review)
 *   → platform numbers with this week's change → recent admin activity
 *   beside quick actions.
 */
const authStore = useAuthStore()
if (!authStore.isAdmin) throw createError({ statusCode: 403, statusMessage: 'Forbidden' })

const api = useApi()
const { overview, fetchOverview } = useAdmin()

interface AdminAction { id: number; action: string; label: string; admin_name: string; subject_label: string | null; created_at: string }
const activity = ref<AdminAction[]>([])
const activityLoaded = ref(false)
async function fetchActivity() {
  try {
    const res = await api.get<PaginatedResponse<AdminAction>>('/admin/actions')
    activity.value = res.data.slice(0, 6)
  } catch { activity.value = [] }
  activityLoaded.value = true
}
/* How buyers and agents rate RealtyLink PH (side panel). */
interface PlatformRatings {
  count: number
  average: number | null
  spread: Record<number, number>
  buyers: { average: number | null; count: number }
  agents: { average: number | null; count: number }
  latest: Array<{ rating: number; comment: string; name: string; role: string }>
}
const platform = ref<PlatformRatings | null>(null)
async function fetchPlatform() {
  try { platform.value = (await api.get<ApiResponse<PlatformRatings>>('/admin/platform-ratings')).data } catch { /* optional */ }
}

await Promise.all([fetchOverview(), fetchActivity(), fetchPlatform()])

/* ── Header ── */
const firstName = computed(() => authStore.user?.name?.split(' ')[0] ?? '')
const greeting = computed(() => {
  const h = new Date().getHours()
  return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening'
})
const today = new Date().toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric' })

/* ── Needs your attention ── */
const att = computed(() => overview.value?.attention ?? { applications: 0, hidden_reviews: 0, unpublished: 0, support: 0 })
const toDo = computed(() => att.value.applications + att.value.support + att.value.hidden_reviews + att.value.unpublished)
const QUEUES = computed(() => [
  { label: 'Support tickets', hint: 'People waiting for a reply', count: att.value.support, href: '/admin/support', loud: true,
    icon: 'M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z' },
  { label: 'Agent applications', hint: 'Waiting for your decision', count: att.value.applications, href: '/admin/agents', loud: true,
    icon: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z' },
  { label: 'Hidden reviews', hint: 'Taken off agent profiles', count: att.value.hidden_reviews, href: '/admin/reviews', loud: false,
    icon: 'M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88' },
  { label: 'Unpublished listings', hint: 'Waiting on the agent to fix', count: att.value.unpublished, href: '/admin/listings', loud: false,
    icon: 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25' },
])

/* ── Numbers ── */
const stats = computed<DashStat[]>(() => {
  const n = overview.value?.numbers
  const week = (v?: number) => (v ? `+${v} this week` : 'None new this week')
  return [
    { label: 'Users',              value: n?.users.total ?? 0,    hint: week(n?.users.week),    href: '/admin/users' },
    { label: 'Approved agents',    value: n?.agents.total ?? 0,   hint: week(n?.agents.week),   href: '/admin/agents' },
    { label: 'Published listings', value: n?.listings.total ?? 0, hint: week(n?.listings.week), href: '/admin/listings' },
    { label: 'Viewings booked',    value: n?.viewings.total ?? 0, hint: week(n?.viewings.week) },
  ]
})

/* ── Activity ── */
function ago(iso: string): string {
  const m = Math.round((Date.now() - +new Date(iso)) / 60000)
  if (m < 1) return 'just now'
  if (m < 60) return `${m}m ago`
  const h = Math.round(m / 60)
  if (h < 24) return `${h}h ago`
  const d = Math.round(h / 24)
  return d < 7 ? `${d}d ago` : new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
}
function tone(action: string): string {
  if (action.endsWith('.approved') || action.endsWith('.shown') || action.endsWith('.created')) return 'bg-emerald-500'
  if (action.endsWith('.deleted') || action.endsWith('.rejected')) return 'bg-red-500'
  return 'bg-brand-gold'
}

const QUICK = [
  { label: 'Create an admin',     href: '/admin/users?create=1', icon: 'M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM3 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 019.374 21c-2.331 0-4.512-.645-6.374-1.766z' },
  { label: 'Review agents',       href: '/admin/agents',         icon: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z' },
  { label: 'Moderate listings',   href: '/admin/listings',       icon: 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25' },
  { label: 'Moderate reviews',    href: '/admin/reviews',        icon: 'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z' },
]
</script>

<template>
  <div class="max-w-6xl mx-auto">
    <!-- ── Header ── -->
    <header class="flex flex-wrap items-end justify-between gap-4 mb-6">
      <div class="min-w-0">
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Admin · {{ today }}</p>
        <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">
          {{ greeting }}{{ firstName ? `, ${firstName}` : '' }}
        </h1>
        <p class="mt-1.5 flex flex-wrap items-center gap-x-2 text-sm text-brand-text-secondary dark:text-white/55">
          <template v-if="toDo">
            <span><span class="font-semibold text-brand-navy dark:text-white">{{ toDo }}</span> {{ toDo === 1 ? 'thing needs' : 'things need' }} your attention.</span>
          </template>
          <span v-else>Everything is handled. Nice work.</span>
          <span v-if="overview?.online" class="inline-flex items-center gap-1.5">
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true" />{{ overview.online }} online now
          </span>
        </p>
      </div>
      <DashCta :to="att.applications ? '/admin/agents' : '/admin/users'">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="att.applications ? QUEUES.find(q => q.href === '/admin/agents')!.icon : 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z'" /></svg>
        {{ att.applications ? 'Review applications' : 'Manage users' }}
      </DashCta>
    </header>

    <!-- ── Needs your attention ── -->
    <section class="ov-rise relative overflow-hidden rounded-3xl p-5 sm:p-6 bg-brand-navy text-white ring-1 ring-brand-gold/25">
      <div class="absolute inset-0 bg-grid-gold opacity-[0.06] pointer-events-none" />
      <div class="absolute -right-16 -top-16 h-56 w-56 rounded-full bg-brand-gold/15 blur-3xl pointer-events-none" />
      <p class="relative text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold">Needs your attention</p>
      <p class="relative mt-1 font-heading text-xl font-semibold">
        {{ toDo ? 'A few things are waiting on you' : 'All clear for now' }}
      </p>

      <div class="relative mt-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
        <NuxtLink
          v-for="q in QUEUES"
          :key="q.label"
          :to="q.href"
          class="group flex items-center gap-3 rounded-2xl p-3.5 ring-1 transition hover:-translate-y-0.5"
          :class="q.count && q.loud ? 'bg-brand-gold/15 ring-brand-gold/50 hover:ring-brand-gold' : 'bg-white/[0.06] ring-white/10 hover:ring-brand-gold/60'"
        >
          <span class="h-10 w-10 flex-none rounded-xl flex items-center justify-center" :class="q.count && q.loud ? 'bg-brand-gold text-[#0B2A5C]' : 'bg-white/10 text-brand-gold'">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="q.icon" /></svg>
          </span>
          <span class="min-w-0 flex-1">
            <span class="block text-2xl font-bold leading-none tabular-nums" :class="q.count && q.loud ? 'text-brand-gold' : 'text-white'">{{ q.count }}</span>
            <span class="mt-1 block truncate text-sm font-semibold text-white/90">{{ q.label }}</span>
            <span class="block truncate text-xs text-white/55">{{ q.hint }}</span>
          </span>
          <svg class="h-4 w-4 flex-none text-white/40 group-hover:text-brand-gold group-hover:translate-x-0.5 transition" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
        </NuxtLink>
      </div>

      <!-- The oldest applications first: they've waited longest -->
      <div v-if="overview?.pending_applications.length" class="relative mt-5">
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-white/50">Waiting longest</p>
        <ul class="mt-2 space-y-2">
          <li v-for="p in overview.pending_applications" :key="p.id" class="flex items-center gap-3 rounded-2xl p-2 pr-3 bg-white/[0.06] ring-1 ring-white/10">
            <AppAvatar :name="p.name" :src="p.avatar" size="sm" />
            <span class="min-w-0 flex-1">
              <span class="block truncate text-sm font-semibold">{{ p.name }}</span>
              <span class="block truncate text-xs text-white/55">{{ p.applicant_type === 'broker' ? 'Broker' : 'Salesperson' }} · applied {{ ago(p.submitted_at) }}</span>
            </span>
            <NuxtLink to="/admin/agents" class="flex-none rounded-full bg-brand-gold px-4 py-1.5 text-xs font-bold text-[#0B2A5C] hover:bg-brand-gold-light transition">Review</NuxtLink>
          </li>
        </ul>
      </div>
    </section>

    <!-- ── Platform numbers ── -->
    <h2 class="mt-8 mb-3 text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Platform</h2>
    <DashStatStrip :stats="stats" />

    <!-- ── Activity + quick actions ── -->
    <div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]">
      <section class="rounded-3xl p-5 sm:p-6 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]">
        <div class="flex items-center justify-between gap-3">
          <h2 class="font-heading text-lg font-semibold text-brand-navy dark:text-white">Recent admin activity</h2>
          <NuxtLink to="/admin/activity" class="text-sm font-semibold text-brand-gold-deep hover:text-brand-navy dark:text-brand-gold dark:hover:text-white transition-colors">See all</NuxtLink>
        </div>
        <ol v-if="activity.length" class="mt-4 space-y-1">
          <li v-for="a in activity" :key="a.id" class="flex items-start gap-3 rounded-xl px-2 py-2.5 -mx-2 hover:bg-brand-navy/[0.03] dark:hover:bg-white/[0.04] transition">
            <span class="mt-1.5 h-2 w-2 flex-none rounded-full" :class="tone(a.action)" aria-hidden="true" />
            <span class="min-w-0 flex-1">
              <span class="block text-sm text-brand-navy dark:text-white">
                <span class="font-semibold">{{ a.label }}</span><template v-if="a.subject_label">: {{ a.subject_label }}</template>
              </span>
              <span class="block text-xs text-brand-navy/50 dark:text-white/45">by {{ a.admin_name }}</span>
            </span>
            <span class="flex-none text-xs tabular-nums text-brand-navy/45 dark:text-white/40">{{ ago(a.created_at) }}</span>
          </li>
        </ol>
        <p v-else-if="activityLoaded" class="mt-4 rounded-2xl bg-brand-cream/70 dark:bg-white/[0.04] px-4 py-6 text-center text-sm text-brand-text-secondary dark:text-white/55">
          Nothing yet. Approvals, unpublished listings and removed accounts show up here.
        </p>
      </section>

      <aside class="space-y-4 lg:sticky lg:top-24 lg:self-start">
        <!-- How buyers and agents rate RealtyLink PH -->
        <section class="relative overflow-hidden rounded-3xl p-5 bg-brand-navy text-white ring-1 ring-brand-gold/25">
          <div class="absolute inset-0 bg-grid-gold opacity-[0.06] pointer-events-none" />
          <p class="relative text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold">RealtyLink PH rating</p>
          <template v-if="platform?.count">
            <div class="relative mt-2 flex items-end gap-3">
              <span class="font-display text-4xl font-bold leading-none tabular-nums">{{ platform.average?.toFixed(1) }}</span>
              <span class="pb-0.5 text-xs text-white/60">from {{ platform.count }} {{ platform.count === 1 ? 'person' : 'people' }}</span>
            </div>
            <ul class="relative mt-3 space-y-1">
              <li v-for="n in [5, 4, 3, 2, 1]" :key="n" class="flex items-center gap-2 text-xs">
                <span class="w-6 tabular-nums text-white/70">{{ n }} ★</span>
                <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-white/10"><span class="block h-full rounded-full bg-brand-gold" :style="{ width: `${Math.round(((platform.spread[n] ?? 0) / platform.count) * 100)}%` }" /></span>
                <span class="w-4 text-right tabular-nums text-white/70">{{ platform.spread[n] ?? 0 }}</span>
              </li>
            </ul>
            <p class="relative mt-3 text-xs text-white/65">
              Buyers <span class="font-semibold text-white">{{ platform.buyers.average?.toFixed(1) ?? '–' }}★</span>
              · Agents <span class="font-semibold text-white">{{ platform.agents.average?.toFixed(1) ?? '–' }}★</span>
            </p>
            <ul v-if="platform.latest.length" class="relative mt-3 space-y-2 border-t border-white/10 pt-3">
              <li v-for="(c, i) in platform.latest.slice(0, 3)" :key="i" class="text-xs">
                <p class="text-white/80">“{{ c.comment }}”</p>
                <p class="mt-0.5 text-white/45">{{ c.name }} · {{ c.role === 'agent' ? 'Agent' : 'Buyer' }} · {{ c.rating }}★</p>
              </li>
            </ul>
          </template>
          <p v-else class="relative mt-2 text-sm text-white/70">No ratings yet. Buyers and agents can rate RealtyLink PH from Help & support.</p>
        </section>

        <section class="rounded-3xl p-5 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]">
          <p class="text-sm font-bold text-brand-navy dark:text-white">Quick actions</p>
          <ul class="mt-3 space-y-1">
            <li v-for="q in QUICK" :key="q.label">
              <NuxtLink :to="q.href" class="group flex items-center gap-3 rounded-xl p-2 -mx-2 hover:bg-brand-navy/[0.04] dark:hover:bg-white/[0.05] transition">
                <span class="h-8 w-8 flex-none rounded-lg flex items-center justify-center bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="q.icon" /></svg>
                </span>
                <span class="flex-1 text-sm font-semibold text-brand-navy dark:text-white">{{ q.label }}</span>
                <svg class="h-4 w-4 text-brand-navy/30 group-hover:text-brand-gold-deep dark:text-white/30 dark:group-hover:text-brand-gold transition" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
              </NuxtLink>
            </li>
          </ul>
        </section>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.ov-rise { animation: ov-rise 0.5s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes ov-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .ov-rise { animation: none; } }
</style>
