<script setup lang="ts">
import type { AgentReview, Appointment } from '~/types'

/**
 * The buyer's History (agents keep their own view in the page).
 *
 *   header with a one-line summary
 *   → "Waiting for your review": viewings the buyer can still rate
 *   → tabs All / Completed / Cancelled / Past, newest first, grouped by month
 *   Each card: what happened (and, if cancelled, who cancelled and why),
 *   with View listing and Book again.
 */
const props = defineProps<{ appointments: Appointment[]; reviews?: AgentReview[] }>()
const emit = defineEmits<{ rate: [appt: Appointment]; edit: [review: AgentReview] }>()

/* One review per agent: a past viewing with an agent you've already
   reviewed offers your stars + Edit instead of Rate. */
const reviewByAgent = computed(() => new Map((props.reviews ?? []).map(r => [r.agent_id, r])))

const authStore = useAuthStore()
const propertyHref = usePropertyHref()

const at = (a: Appointment) => +new Date(a.preferred_datetime)
const isPast = (a: Appointment) => at(a) < Date.now()

/* History = finished: marked done, cancelled, or past its time. */
const items = computed(() =>
  props.appointments
    .filter(a => a.status === 'completed' || a.status === 'cancelled' || isPast(a))
    .sort((a, b) => at(b) - at(a)),
)
type Tab = 'all' | 'completed' | 'cancelled' | 'past'
const kindOf = (a: Appointment): Exclude<Tab, 'all'> =>
  a.status === 'completed' ? 'completed' : a.status === 'cancelled' ? 'cancelled' : 'past'
const counts = computed(() => ({
  all: items.value.length,
  completed: items.value.filter(a => kindOf(a) === 'completed').length,
  cancelled: items.value.filter(a => kindOf(a) === 'cancelled').length,
  past: items.value.filter(a => kindOf(a) === 'past').length,
}))
const TABS: Array<{ key: Tab; label: string }> = [
  { key: 'all', label: 'All' }, { key: 'completed', label: 'Completed' },
  { key: 'cancelled', label: 'Cancelled' }, { key: 'past', label: 'Past' },
]
const tab = ref<Tab>('all')

const toReview = computed(() => items.value.filter(a => a.can_review && !reviewByAgent.value.get(a.agent_id)))

/* Month groups for the selected tab. */
const months = computed(() => {
  const out: Array<{ key: string; label: string; items: Appointment[] }> = []
  for (const a of items.value.filter(x => tab.value === 'all' || kindOf(x) === tab.value)) {
    const d = new Date(a.preferred_datetime)
    const key = `${d.getFullYear()}-${d.getMonth()}`
    const last = out[out.length - 1]
    if (last?.key === key) last.items.push(a)
    else out.push({ key, label: d.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' }), items: [a] })
  }
  return out
})

const plural = (n: number, w: string) => `${n} ${w}${n === 1 ? '' : 's'}`
const summary = computed(() => {
  const c = counts.value
  const parts = [
    c.completed ? `${c.completed} completed` : '',
    c.cancelled ? `${c.cancelled} cancelled` : '',
    c.past ? `${c.past} past` : '',
  ].filter(Boolean)
  return parts.length ? `${plural(c.all, 'viewing')}: ${parts.join(', ')}.` : ''
})

/* ── Card helpers ── */
const META: Record<Exclude<Tab, 'all'>, { label: string; chip: string; dot: string }> = {
  completed: { label: 'Completed', chip: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400', dot: 'bg-emerald-500' },
  cancelled: { label: 'Cancelled', chip: 'bg-red-500/10 text-red-600 dark:text-red-400', dot: 'bg-red-500' },
  past:      { label: 'Past',      chip: 'bg-brand-navy/[0.07] text-brand-navy/70 dark:bg-white/10 dark:text-white/70', dot: 'bg-gray-400' },
}
function pastNote(a: Appointment): string {
  return a.status === 'pending'
    ? 'The agent didn’t confirm this request in time.'
    : 'The agent hasn’t marked this viewing as done yet.'
}
function cancelNote(a: Appointment): string {
  const who = a.cancelled_by_id === authStore.user?.id ? 'You cancelled' : `${a.agent?.name ?? 'The agent'} cancelled`
  const why = a.cancel_reason ? `: ${a.cancel_reason}` : ''
  return `${who}${why}${a.late_cancellation ? ' (late cancellation)' : ''}.`
}
function thumb(a: Appointment) {
  const ps = a.property?.photos ?? []
  const p = ps.find(x => !x.is_360) ?? ps[0]
  return p ? (p.thumb_url ?? p.url) : null
}
function calm(t?: string): string {
  if (!t) return 'Property viewing'
  const letters = t.replace(/[^A-Za-z]/g, '')
  return letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
}
const day = (a: Appointment) => new Date(a.preferred_datetime).getDate()
const mon = (a: Appointment) => new Date(a.preferred_datetime).toLocaleDateString('en-PH', { month: 'short' })
const when = (a: Appointment) => new Date(a.preferred_datetime).toLocaleString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
const canBookAgain = (a: Appointment) => !a.property || a.property.status === 'published'

const btnGhost =
  'inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-bold transition ' +
  'text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold'
const STAR = 'M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z'
</script>

<template>
  <div>
    <!-- ── Header ── -->
    <header class="flex flex-wrap items-end justify-between gap-4 mb-6">
      <div class="min-w-0">
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Your past viewings</p>
        <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">History</h1>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
          {{ summary || 'Completed, cancelled and past viewings are kept here.' }}
        </p>
      </div>
      <DashCta to="/dashboard/appointments">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
        Upcoming viewings
      </DashCta>
    </header>

    <!-- ── Waiting for your review ── -->
    <section
      v-if="toReview.length"
      class="hist-rise relative mb-7 overflow-hidden rounded-3xl p-5 sm:p-6 bg-brand-navy text-white ring-1 ring-brand-gold/25"
    >
      <div class="absolute inset-0 bg-grid-gold opacity-[0.06] pointer-events-none" />
      <div class="relative flex flex-wrap items-center justify-between gap-2">
        <div>
          <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold">Waiting for your review</p>
          <p class="mt-1 font-heading text-xl font-semibold">How did {{ toReview.length === 1 ? 'it' : 'they' }} go?</p>
          <p class="text-sm text-white/65">A quick rating helps other buyers pick a good agent.</p>
        </div>
      </div>
      <ul class="relative mt-4 space-y-2">
        <li v-for="a in toReview" :key="a.id" class="flex items-center gap-3 rounded-2xl p-2 pr-3 bg-white/[0.07] ring-1 ring-white/10">
          <span class="h-12 w-14 flex-none overflow-hidden rounded-xl bg-white/10">
            <img v-if="thumb(a)" :src="thumb(a)!" alt="" class="h-full w-full object-cover" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-semibold">{{ a.agent?.name ?? 'Your agent' }}</span>
            <span class="block truncate text-xs text-white/60">{{ calm(a.property?.title) }} · {{ when(a) }}</span>
          </span>
          <button
            type="button"
            class="flex-none inline-flex items-center gap-1.5 rounded-full bg-brand-gold px-4 py-2 text-xs font-bold text-brand-navy shadow-[0_4px_14px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light transition"
            @click="emit('rate', a)"
          >
            <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
            Rate agent
          </button>
        </li>
      </ul>
    </section>

    <!-- ── Tabs ── -->
    <div v-if="items.length" class="mb-6 -mx-1 px-1 overflow-x-auto no-scrollbar">
      <div class="inline-flex items-center gap-1 rounded-full p-1 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10" role="tablist">
        <button
          v-for="t in TABS"
          :key="t.key"
          type="button"
          role="tab"
          :aria-selected="tab === t.key"
          :disabled="t.key !== 'all' && !counts[t.key]"
          class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3.5 py-1.5 text-sm font-semibold transition disabled:opacity-40"
          :class="tab === t.key
            ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-brand-navy'
            : 'text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white'"
          @click="tab = t.key"
        >
          {{ t.label }}
          <span class="text-xs tabular-nums" :class="tab === t.key ? 'opacity-75' : 'opacity-50'">{{ counts[t.key] }}</span>
        </button>
      </div>
    </div>

    <!-- ── List by month ── -->
    <div v-if="months.length" class="space-y-8">
      <section v-for="m in months" :key="m.key">
        <h2 class="mb-3 flex items-center gap-2 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/50 dark:text-white/45">
          {{ m.label }}
          <span class="h-px flex-1 bg-brand-navy/[0.08] dark:bg-white/10" />
        </h2>
        <ul class="space-y-3">
          <li
            v-for="a in m.items"
            :key="a.id"
            class="rounded-2xl p-4 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] hover:ring-brand-gold/50 transition"
          >
            <div class="flex gap-4">
              <!-- Date leaf -->
              <span class="hidden sm:block w-14 flex-none self-start overflow-hidden rounded-xl text-center bg-[#ffffff] ring-1 ring-brand-navy/10 dark:ring-white/10">
                <span class="block py-0.5 text-[0.625rem] font-bold uppercase tracking-wider" :class="kindOf(a) === 'cancelled' ? 'bg-red-500/15 text-red-700' : kindOf(a) === 'completed' ? 'bg-emerald-500/15 text-emerald-800' : 'bg-[#0B2A5C]/10 text-[#0B2A5C]/70'">{{ mon(a) }}</span>
                <span class="block py-1.5 font-display text-xl font-bold leading-none text-[#0B2A5C]">{{ day(a) }}</span>
              </span>

              <NuxtLink :to="propertyHref(a.property_id)" class="h-[4.5rem] w-24 flex-none overflow-hidden rounded-xl bg-brand-navy/5 dark:bg-white/10" :class="kindOf(a) === 'cancelled' ? 'grayscale-[60%] opacity-80' : ''">
                <img v-if="thumb(a)" :src="thumb(a)!" alt="" loading="lazy" class="h-full w-full object-cover" />
              </NuxtLink>

              <div class="min-w-0 flex-1">
                <div class="flex items-start justify-between gap-2">
                  <NuxtLink :to="propertyHref(a.property_id)" class="line-clamp-1 font-semibold text-brand-navy dark:text-white hover:text-brand-gold-deep dark:hover:text-brand-gold transition-colors">
                    {{ calm(a.property?.title) }}
                  </NuxtLink>
                  <span class="flex-none inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold" :class="META[kindOf(a)].chip">
                    <span class="h-1.5 w-1.5 rounded-full" :class="META[kindOf(a)].dot" />
                    {{ META[kindOf(a)].label }}
                  </span>
                </div>
                <p class="mt-0.5 text-xs text-brand-text-secondary dark:text-white/50">{{ when(a) }}</p>
                <p class="mt-1.5 flex items-center gap-1.5 text-xs text-brand-navy/70 dark:text-white/65">
                  <AppAvatar :name="a.agent?.name" :src="a.agent?.avatar" size="xs" />
                  <span class="font-semibold text-brand-navy dark:text-white">{{ a.agent?.name ?? 'Agent' }}</span>
                </p>

                <!-- What happened -->
                <p v-if="kindOf(a) === 'cancelled'" class="mt-2.5 rounded-xl bg-red-500/[0.06] px-3 py-2 text-xs text-red-700 dark:text-red-300">{{ cancelNote(a) }}</p>
                <p v-else-if="kindOf(a) === 'past'" class="mt-2.5 rounded-xl bg-brand-navy/[0.04] dark:bg-white/[0.05] px-3 py-2 text-xs text-brand-navy/65 dark:text-white/55">{{ pastNote(a) }}</p>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                  <NuxtLink :to="propertyHref(a.property_id)" :class="btnGhost">View listing</NuxtLink>
                  <NuxtLink v-if="canBookAgain(a)" :to="`${propertyHref(a.property_id)}?book=1`" :class="btnGhost">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                    Book again
                  </NuxtLink>
                  <span v-else class="text-xs text-brand-navy/45 dark:text-white/40">No longer listed</span>
                  <!-- Rate the agent, or show + edit the review you already left -->
                  <button
                    v-if="a.can_review && !reviewByAgent.get(a.agent_id)"
                    type="button"
                    class="ml-auto inline-flex items-center gap-1.5 rounded-full bg-brand-gold px-3.5 py-1.5 text-xs font-bold text-brand-navy hover:bg-brand-gold-light transition"
                    @click="emit('rate', a)"
                  >
                    <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
                    Rate agent
                  </button>
                  <div v-else-if="reviewByAgent.get(a.agent_id)" class="ml-auto flex items-center gap-2">
                    <span class="hidden sm:inline-flex items-center gap-1 text-xs text-brand-navy/55 dark:text-white/50" :aria-label="`You rated ${reviewByAgent.get(a.agent_id)!.rating} stars`">
                      You rated
                      <span class="inline-flex gap-px">
                        <svg v-for="n in 5" :key="n" class="h-3.5 w-3.5" :class="n <= reviewByAgent.get(a.agent_id)!.rating ? 'text-brand-gold' : 'text-brand-navy/15 dark:text-white/15'" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
                      </span>
                    </span>
                    <button
                      type="button"
                      class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-bold text-brand-navy ring-1 ring-brand-gold/60 hover:bg-brand-gold/10 dark:text-white dark:ring-brand-gold/50 transition"
                      @click="emit('edit', reviewByAgent.get(a.agent_id)!)"
                    >
                      <svg class="h-3.5 w-3.5 text-brand-gold-deep dark:text-brand-gold" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
                      Edit review
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </li>
        </ul>
      </section>
    </div>

    <!-- ── Empty ── -->
    <div v-if="!items.length" class="relative overflow-hidden rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] px-6 py-16 text-center">
      <div class="absolute -top-16 left-1/2 -translate-x-1/2 h-48 w-80 rounded-full bg-brand-gold/10 blur-3xl pointer-events-none" />
      <span class="relative mx-auto mb-5 h-16 w-16 rounded-2xl bg-brand-gold/15 flex items-center justify-center text-brand-gold-deep dark:text-brand-gold">
        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
      </span>
      <p class="relative font-display text-xl font-bold text-brand-navy dark:text-white">No history yet</p>
      <p class="relative mx-auto mt-2 max-w-sm text-sm text-brand-text-secondary dark:text-white/55 leading-relaxed">
        Once a viewing has happened or been cancelled, it moves here, and you can rate the agent.
      </p>
      <NuxtLink to="/dashboard/appointments" class="relative mt-6 inline-flex items-center rounded-full bg-brand-navy px-5 py-2.5 text-sm font-bold text-white dark:bg-brand-gold dark:text-brand-navy hover:-translate-y-0.5 transition">
        See upcoming viewings
      </NuxtLink>
    </div>
  </div>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
.hist-rise { animation: hist-rise 0.5s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes hist-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .hist-rise { animation: none; } }
</style>
