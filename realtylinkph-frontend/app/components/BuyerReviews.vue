<script setup lang="ts">
import type { AgentReview, Appointment } from '~/types'

/**
 * The buyer's Reviews page (agents keep their own "Reviews & Ratings").
 *
 *   heading with a one-line summary
 *   → "Waiting for your review": viewings they can still rate
 *   → filter tabs All / 5★ / 4★ / 3★ & below
 *   → the reviews they've written, each with Edit (same window as Rate).
 */
const { reviews, loading, fetchMyReviews } = useReview()
const { appointments, fetchMyAppointments } = useAppointment()
const agentHref = useAgentHref()

await Promise.all([fetchMyReviews(), fetchMyAppointments()])

/* Viewings that can still be rated (the server's can_review flag). */
const toReview = computed(() =>
  appointments.value
    // one review per agent: skip agents you've already reviewed
    .filter(a => a.can_review && !reviews.value.some(r => r.agent_id === a.agent_id))
    .sort((a, b) => +new Date(b.preferred_datetime) - +new Date(a.preferred_datetime)),
)

/* ── Summary + filter ── */
const avg = computed(() =>
  reviews.value.length ? Math.round((reviews.value.reduce((s, r) => s + r.rating, 0) / reviews.value.length) * 10) / 10 : 0,
)
type Tab = 'all' | '5' | '4' | 'low'
const tab = ref<Tab>('all')
const counts = computed(() => ({
  all: reviews.value.length,
  5: reviews.value.filter(r => r.rating === 5).length,
  4: reviews.value.filter(r => r.rating === 4).length,
  low: reviews.value.filter(r => r.rating <= 3).length,
}))
const TABS: Array<{ key: Tab; label: string }> = [
  { key: 'all', label: 'All' }, { key: '5', label: '5 ★' }, { key: '4', label: '4 ★' }, { key: 'low', label: '3 ★ & below' },
]
/* Side panel: how your ratings spread from 5★ down to 1★. */
const spread = computed(() => [5, 4, 3, 2, 1].map((n) => {
  const count = reviews.value.filter(r => r.rating === n).length
  return { n, count, pct: reviews.value.length ? Math.round((count / reviews.value.length) * 100) : 0 }
}))
const TIPS = [
  'Say what the agent did well, or what could be better',
  'Mention punctuality and how they answered your questions',
  'Keep it about the agent and the viewing, not the price',
  'Changed your mind later? You can edit it any time',
]
const shown = computed(() => reviews.value.filter(r =>
  tab.value === 'all' || (tab.value === 'low' ? r.rating <= 3 : r.rating === Number(tab.value))))

/* ── Review text: "👍 tag · tag" line → chips, the rest → note ── */
function parts(r: AgentReview) {
  const lines = (r.review_text ?? '').split('\n')
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
const date = (iso?: string | null) => (iso ? new Date(iso).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' }) : '')
function thumb(a: Appointment) {
  const ps = a.property?.photos ?? []
  const p = ps.find(x => !x.is_360) ?? ps[0]
  return p ? (p.thumb_url ?? p.url) : null
}

/* ── Rate / edit (one window) ── */
const dialogOpen = ref(false)
const rateAppt = ref<Appointment | null>(null)
const editReview = ref<AgentReview | null>(null)
function rate(a: Appointment) { editReview.value = null; rateAppt.value = a; dialogOpen.value = true }
function edit(r: AgentReview) { rateAppt.value = null; editReview.value = r; dialogOpen.value = true }
async function refresh() { await Promise.all([fetchMyReviews(), fetchMyAppointments()]) }

const STAR = 'M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z'
</script>

<template>
  <div>
    <!-- ── Heading ── -->
    <header class="flex flex-wrap items-end justify-between gap-4 mb-6">
      <div class="min-w-0">
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Your feedback</p>
        <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">Reviews</h1>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
          <template v-if="reviews.length">
            <span class="font-semibold text-brand-navy dark:text-white">{{ reviews.length }}</span> {{ reviews.length === 1 ? 'review' : 'reviews' }} written
            · you give <span class="font-semibold text-brand-navy dark:text-white">{{ avg.toFixed(1) }} ★</span> on average.
          </template>
          <template v-else>Reviews you leave for agents are kept here, and you can edit them any time.</template>
        </p>
      </div>
      <DashCta to="/dashboard/history">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        Past viewings
      </DashCta>
    </header>

    <!-- Main column + side panel, the same split as Appointments -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]">
      <div class="min-w-0">
        <!-- ── Waiting for your review ── -->
        <section v-if="toReview.length" class="rev-rise relative mb-7 overflow-hidden rounded-3xl p-5 sm:p-6 bg-brand-navy text-white ring-1 ring-brand-gold/25">
          <div class="absolute inset-0 bg-grid-gold opacity-[0.06] pointer-events-none" />
          <p class="relative text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold">Waiting for your review</p>
          <p class="relative mt-1 font-heading text-xl font-semibold">How did {{ toReview.length === 1 ? 'it' : 'they' }} go?</p>
          <p class="relative text-sm text-white/65">A quick rating helps other buyers pick a good agent.</p>
          <ul class="relative mt-4 space-y-2">
            <li v-for="a in toReview" :key="a.id" class="flex items-center gap-3 rounded-2xl p-2 pr-3 bg-white/[0.07] ring-1 ring-white/10">
              <span class="h-12 w-14 flex-none overflow-hidden rounded-xl bg-white/10">
                <img v-if="thumb(a)" :src="thumb(a)!" alt="" class="h-full w-full object-cover" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold">{{ a.agent?.name ?? 'Your agent' }}</span>
                <span class="block truncate text-xs text-white/60">{{ calm(a.property?.title) }} · {{ date(a.preferred_datetime) }}</span>
              </span>
              <button type="button" class="flex-none inline-flex items-center gap-1.5 rounded-full bg-brand-gold px-4 py-2 text-xs font-bold text-brand-navy shadow-[0_4px_14px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light transition" @click="rate(a)">
                <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
                Rate agent
              </button>
            </li>
          </ul>
        </section>

        <!-- ── Tabs ── -->
        <div v-if="reviews.length" class="mb-6 -mx-1 px-1 flex overflow-x-auto no-scrollbar">
          <div class="inline-flex items-center gap-1 rounded-full p-1 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10" role="tablist">
            <button
              v-for="t in TABS"
              :key="t.key"
              type="button"
              role="tab"
              :aria-selected="tab === t.key"
              :disabled="t.key !== 'all' && !counts[t.key]"
              class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3.5 py-1.5 text-sm font-semibold transition disabled:opacity-40"
              :class="tab === t.key ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-brand-navy' : 'text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white'"
              @click="tab = t.key"
            >
              {{ t.label }}
              <span class="text-xs tabular-nums" :class="tab === t.key ? 'opacity-75' : 'opacity-50'">{{ counts[t.key] }}</span>
            </button>
          </div>
        </div>

        <!-- ── Reviews ── -->
        <div v-if="loading && !reviews.length" class="space-y-3">
          <AppSkeleton v-for="i in 3" :key="i" width="100%" height="140px" rounded="lg" />
        </div>
        <ul v-else-if="shown.length" class="space-y-4">
          <li
            v-for="r in shown"
            :key="r.id"
            class="flex flex-col rounded-3xl p-5 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] hover:ring-brand-gold/50 transition"
          >
            <!-- Agent + stars -->
            <div class="flex items-start gap-3">
              <NuxtLink :to="agentHref(r.agent_id)" class="flex-none">
                <AppAvatar :name="r.agent?.name" :src="r.agent?.avatar" size="md" />
              </NuxtLink>
              <div class="min-w-0 flex-1">
                <NuxtLink :to="agentHref(r.agent_id)" class="flex items-center gap-1.5 truncate font-semibold text-brand-navy dark:text-white hover:text-brand-gold-deep dark:hover:text-brand-gold transition-colors">
                  {{ r.agent?.name ?? 'Agent' }}
                  <AppVerifiedTick v-if="r.agent?.agent_profile?.status === 'approved'" size="xs" />
                </NuxtLink>
                <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                  <span class="inline-flex gap-0.5" :aria-label="`${r.rating} out of 5 stars`">
                    <svg v-for="n in 5" :key="n" class="h-4 w-4" :class="n <= r.rating ? 'text-brand-gold' : 'text-brand-navy/15 dark:text-white/15'" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
                  </span>
                  <span class="text-xs text-brand-navy/50 dark:text-white/45">{{ date(r.updated_at ?? r.created_at) }}<template v-if="r.is_edited"> · edited</template></span>
                </div>
              </div>
              <span v-if="r.is_verified" class="flex-none inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold bg-emerald-500/10 text-emerald-700 dark:text-emerald-400">
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                Verified viewing
              </span>
            </div>

            <!-- What it was about -->
            <NuxtLink
              v-if="r.property_title"
              :to="r.property_id ? `/dashboard/properties/${r.property_id}` : '#'"
              class="mt-4 flex items-center gap-3 rounded-2xl p-2 pr-3 bg-brand-cream/80 ring-1 ring-brand-navy/[0.05] hover:ring-brand-gold dark:bg-white/[0.04] dark:ring-white/[0.06] transition"
            >
              <span class="h-10 w-12 flex-none overflow-hidden rounded-lg bg-brand-navy/5 dark:bg-white/10">
                <img v-if="r.property_photo" :src="r.property_photo" alt="" class="h-full w-full object-cover" loading="lazy" />
              </span>
              <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-brand-navy dark:text-white">{{ calm(r.property_title) }}</span>
                <span v-if="r.viewed_at" class="block text-xs text-brand-navy/50 dark:text-white/45">Viewed {{ date(r.viewed_at) }}</span>
              </span>
            </NuxtLink>
            <p v-else class="mt-4 text-xs text-brand-navy/50 dark:text-white/45">About your chat with the agent</p>

            <!-- Tags + note -->
            <div v-if="parts(r).tags.length" class="mt-3 flex flex-wrap gap-1.5">
              <span v-for="t in parts(r).tags" :key="t" class="rounded-full px-2.5 py-1 text-[0.6875rem] font-semibold bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold">{{ t }}</span>
            </div>
            <p v-if="parts(r).note" class="mt-3 text-sm leading-relaxed text-brand-navy/80 dark:text-white/75 whitespace-pre-wrap line-clamp-4">“{{ parts(r).note }}”</p>

            <!-- The agent's reply -->
            <div v-if="r.agent_reply" class="mt-4 flex gap-3 rounded-2xl bg-brand-navy/[0.03] dark:bg-white/[0.04] p-4 border-l-[3px] border-brand-gold">
              <AppAvatar :name="r.agent?.name" :src="r.agent?.avatar" size="xs" class="flex-none" />
              <div class="min-w-0">
                <p class="text-xs font-bold text-brand-navy dark:text-white">{{ r.agent?.name ?? 'The agent' }} replied <span class="font-normal text-brand-navy/45 dark:text-white/40">· {{ date(r.agent_replied_at) }}</span></p>
                <p class="mt-1 text-sm leading-relaxed text-brand-navy/80 dark:text-white/75 whitespace-pre-wrap">{{ r.agent_reply }}</p>
              </div>
            </div>

            <div class="mt-auto pt-4 flex items-center justify-between gap-2">
              <span v-if="!r.is_visible" class="min-w-0 text-xs font-semibold text-amber-700 dark:text-amber-400">
                Hidden by an admin<template v-if="r.hidden_reason">: <span class="font-normal">{{ r.hidden_reason }}</span></template>
              </span>
              <span v-else class="text-xs text-brand-navy/45 dark:text-white/40">Public on the agent's profile</span>
              <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-xs font-bold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold transition"
                @click="edit(r)"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" /></svg>
                Edit
              </button>
            </div>
          </li>
        </ul>

        <!-- ── Empty ── -->
        <div v-else-if="!reviews.length" class="relative overflow-hidden rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] px-6 py-16 text-center">
          <div class="absolute -top-16 left-1/2 -translate-x-1/2 h-48 w-80 rounded-full bg-brand-gold/10 blur-3xl pointer-events-none" />
          <span class="relative mx-auto mb-5 h-16 w-16 rounded-2xl bg-brand-gold/15 flex items-center justify-center text-brand-gold-deep dark:text-brand-gold">
            <svg class="h-8 w-8" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
          </span>
          <p class="relative font-display text-xl font-bold text-brand-navy dark:text-white">No reviews yet</p>
          <p class="relative mx-auto mt-2 max-w-sm text-sm text-brand-text-secondary dark:text-white/55 leading-relaxed">
            After a viewing, you can rate the agent. Your reviews show up here and on their profile.
          </p>
          <NuxtLink to="/dashboard/appointments" class="relative mt-6 inline-flex items-center rounded-full bg-brand-navy px-5 py-2.5 text-sm font-bold text-white dark:bg-brand-gold dark:text-brand-navy hover:-translate-y-0.5 transition">
            See your viewings
          </NuxtLink>
        </div>
      </div>

      <!-- ── Side panel ── -->
      <!-- Starts level with the first card: below the tabs row (4rem: the
           pill's 2.5rem plus its 1.5rem margin) unless the "Waiting for your
           review" box is first, which already lines up with it. -->
      <aside class="space-y-4 lg:sticky lg:top-24 lg:self-start" :class="{ 'lg:mt-16': reviews.length && !toReview.length }">
        <section class="relative overflow-hidden rounded-3xl p-5 bg-brand-navy text-white ring-1 ring-brand-gold/25">
          <div class="absolute inset-0 bg-grid-gold opacity-[0.06] pointer-events-none" />
          <p class="relative text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold">Your ratings</p>
          <template v-if="reviews.length">
            <div class="relative mt-2 flex items-end gap-3">
              <span class="font-display text-4xl font-bold leading-none tabular-nums">{{ avg.toFixed(1) }}</span>
              <span class="pb-0.5">
                <span class="flex gap-0.5" :aria-label="`${avg.toFixed(1)} out of 5 on average`">
                  <svg v-for="n in 5" :key="n" class="h-4 w-4" :class="n <= Math.round(avg) ? 'text-brand-gold' : 'text-white/20'" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
                </span>
                <span class="block text-xs text-white/60">from {{ reviews.length }} {{ reviews.length === 1 ? 'review' : 'reviews' }}</span>
              </span>
            </div>
            <ul class="relative mt-4 space-y-1.5">
              <li v-for="s in spread" :key="s.n" class="flex items-center gap-2.5 text-xs">
                <span class="w-6 flex-none tabular-nums text-white/70">{{ s.n }} ★</span>
                <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-white/10">
                  <span class="block h-full rounded-full bg-brand-gold transition-[width] duration-700" :style="{ width: `${s.pct}%` }" />
                </span>
                <span class="w-4 flex-none text-right tabular-nums text-white/70">{{ s.count }}</span>
              </li>
            </ul>
          </template>
          <p v-else class="relative mt-2 text-sm leading-relaxed text-white/70">
            Once you rate an agent, your average and how your stars spread show up here.
          </p>
        </section>

        <section class="rounded-3xl p-5 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]">
          <p class="flex items-center gap-2 text-sm font-bold text-brand-navy dark:text-white">
            <svg class="h-4 w-4 text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" /></svg>
            Writing a helpful review
          </p>
          <ul class="mt-3 space-y-2">
            <li v-for="t in TIPS" :key="t" class="flex items-start gap-2 text-xs leading-relaxed text-brand-text-secondary dark:text-white/60">
              <svg class="mt-0.5 h-3.5 w-3.5 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
              {{ t }}
            </li>
          </ul>
          <p class="mt-3 rounded-xl bg-brand-cream/80 dark:bg-white/[0.04] px-3 py-2 text-xs text-brand-navy/70 dark:text-white/60">
            Reviews are public on the agent's profile, so other buyers can see them.
          </p>
        </section>
      </aside>
    </div>

    <RateAgentDialog :open="dialogOpen" :appointment="rateAppt" :review="editReview" @close="dialogOpen = false" @rated="refresh" />
  </div>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
.rev-rise { animation: rev-rise 0.5s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes rev-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .rev-rise { animation: none; } }
</style>
