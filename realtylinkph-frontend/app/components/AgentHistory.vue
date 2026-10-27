<script setup lang="ts">
import type { AgentReview, Appointment, Property } from '~/types'

/**
 * The agent's History, in the same shape as the buyer's:
 *   header with a one-line summary
 *   → tabs All / Completed / Cancelled / Past / Sold listings
 *   → viewings newest first, grouped by month: who cancelled and why, a
 *     "Mark as done" for confirmed ones that were never closed, and the
 *     buyer's rating when they left one;
 *   → sold listings, each with Edit and Re-list.
 */
const props = defineProps<{ appointments: Appointment[]; inventory: Property[]; reviews?: AgentReview[] }>()
const emit = defineEmits<{ relist: [prop: Property]; changed: [] }>()

const authStore = useAuthStore()
const toast = useToast()
const propertyHref = usePropertyHref()
const { complete } = useAppointment()

/* The review a buyer left you, if any, so a past viewing can show it. */
const reviewByBuyer = computed(() => new Map((props.reviews ?? []).map(r => [r.buyer_id, r])))

const at = (a: Appointment) => +new Date(a.preferred_datetime)
const isPast = (a: Appointment) => at(a) < Date.now()

/* History = finished: marked done, cancelled, or past its time. */
const items = computed(() =>
  props.appointments
    .filter(a => a.status === 'completed' || a.status === 'cancelled' || isPast(a))
    .sort((a, b) => at(b) - at(a)),
)
type Kind = 'completed' | 'cancelled' | 'past'
type Tab = 'all' | Kind | 'sold'
const kindOf = (a: Appointment): Kind =>
  a.status === 'completed' ? 'completed' : a.status === 'cancelled' ? 'cancelled' : 'past'
const counts = computed(() => ({
  all: items.value.length,
  completed: items.value.filter(a => kindOf(a) === 'completed').length,
  cancelled: items.value.filter(a => kindOf(a) === 'cancelled').length,
  past: items.value.filter(a => kindOf(a) === 'past').length,
  sold: props.inventory.length,
}))
const TABS: Array<{ key: Tab; label: string }> = [
  { key: 'all', label: 'All viewings' }, { key: 'completed', label: 'Completed' },
  { key: 'cancelled', label: 'Cancelled' }, { key: 'past', label: 'Not closed' },
  { key: 'sold', label: 'Sold listings' },
]
const route = useRoute()
const tab = ref<Tab>(route.query.tab === 'sold' && props.inventory.length ? 'sold' : 'all')

/* Confirmed viewings whose time has passed but were never marked done. */
const toClose = computed(() => items.value.filter(a => a.status === 'confirmed' && isPast(a)))

const months = computed(() => {
  const out: Array<{ key: string; label: string; items: Appointment[] }> = []
  if (tab.value === 'sold') return out
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
    c.past ? `${c.past} not closed` : '',
  ].filter(Boolean)
  const v = parts.length ? `${plural(c.all, 'viewing')}: ${parts.join(', ')}` : ''
  const s = c.sold ? `${plural(c.sold, 'sold listing')}` : ''
  return [v, s].filter(Boolean).join(' · ')
})

/* ── Card helpers ── */
const META: Record<Kind, { label: string; chip: string; dot: string }> = {
  completed: { label: 'Completed', chip: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400', dot: 'bg-emerald-500' },
  cancelled: { label: 'Cancelled', chip: 'bg-red-500/10 text-red-600 dark:text-red-400', dot: 'bg-red-500' },
  past:      { label: 'Not closed', chip: 'bg-amber-500/10 text-amber-700 dark:text-amber-400', dot: 'bg-amber-500' },
}
function pastNote(a: Appointment): string {
  return a.status === 'pending'
    ? 'You didn’t confirm this request before its time.'
    : 'This viewing was confirmed but never marked as done.'
}
function cancelNote(a: Appointment): string {
  const who = a.cancelled_by_id === authStore.user?.id ? 'You cancelled' : `${a.buyer?.name ?? 'The buyer'} cancelled`
  const why = a.cancel_reason ? `: ${a.cancel_reason}` : ''
  return `${who}${why}${a.late_cancellation ? ' (late cancellation)' : ''}.`
}
function thumb(p?: Property) {
  const ps = p?.photos ?? []
  const ph = ps.find(x => !x.is_360) ?? ps[0]
  return ph ? (ph.thumb_url ?? ph.url) : null
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
function priceLabel(p: Property): string {
  const n = Number(p.price)
  const s = n >= 1_000_000 ? `₱${(n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1)}M`
          : n >= 1_000     ? `₱${(n / 1_000).toFixed(0)}K`
          : `₱${n.toLocaleString('en-PH')}`
  return p.offer_type === 'rent' ? `${s}/mo` : s
}
const soldDate = (p: Property) => (p.sold_at ? new Date(p.sold_at).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' }) : '')

/* Close a confirmed viewing that happened but was never marked done. */
const closing = ref<number | null>(null)
async function markDone(a: Appointment) {
  closing.value = a.id
  if (await complete(a.id)) { toast.success('Marked as done. The buyer can now rate you'); emit('changed') }
  else toast.error('Couldn’t update this viewing. Please try again.')
  closing.value = null
}

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
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Schedule</p>
        <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">History</h1>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
          {{ summary ? `${summary}.` : 'Finished and cancelled viewings, and listings you’ve sold, are kept here.' }}
        </p>
      </div>
      <DashCta to="/dashboard/appointments">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
        Upcoming viewings
      </DashCta>
    </header>

    <!-- ── Not closed: confirmed viewings never marked done ── -->
    <section
      v-if="toClose.length"
      class="hist-rise relative mb-7 overflow-hidden rounded-3xl p-5 sm:p-6 bg-brand-navy text-white ring-1 ring-brand-gold/25"
    >
      <div class="absolute inset-0 bg-grid-gold opacity-[0.06] pointer-events-none" />
      <div class="relative flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold">Needs closing</p>
          <p class="mt-1 font-heading text-xl font-semibold">{{ plural(toClose.length, 'viewing') }} {{ toClose.length === 1 ? 'isn’t' : 'aren’t' }} closed yet</p>
          <p class="text-sm text-white/65">Mark a viewing as done so the buyer can rate you.</p>
        </div>
        <button type="button" class="rounded-full bg-brand-gold px-4 py-2 text-xs font-bold text-[#0B2A5C] shadow-[0_4px_14px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light transition" @click="tab = 'past'">Show them</button>
      </div>
    </section>

    <!-- ── Tabs ── -->
    <div v-if="items.length || inventory.length" class="mb-6 -mx-1 px-1 overflow-x-auto no-scrollbar">
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

    <!-- ════════ Viewings, by month ════════ -->
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
                <span class="block py-0.5 text-[0.625rem] font-bold uppercase tracking-wider" :class="kindOf(a) === 'cancelled' ? 'bg-red-500/15 text-red-700' : kindOf(a) === 'completed' ? 'bg-emerald-500/15 text-emerald-800' : 'bg-amber-500/15 text-amber-800'">{{ mon(a) }}</span>
                <span class="block py-1.5 font-display text-xl font-bold leading-none text-[#0B2A5C]">{{ day(a) }}</span>
              </span>

              <NuxtLink :to="propertyHref(a.property_id)" class="h-[4.5rem] w-24 flex-none overflow-hidden rounded-xl bg-brand-navy/5 dark:bg-white/10" :class="kindOf(a) === 'cancelled' ? 'grayscale-[60%] opacity-80' : ''">
                <img v-if="thumb(a.property)" :src="thumb(a.property)!" alt="" loading="lazy" class="h-full w-full object-cover" />
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
                  <AppAvatar :name="a.buyer?.name" :src="a.buyer?.avatar" size="xs" />
                  <span class="font-semibold text-brand-navy dark:text-white">{{ a.buyer?.name ?? 'Buyer' }}</span>
                </p>
                <p v-if="a.notes" class="mt-1.5 line-clamp-2 text-xs italic text-brand-navy/50 dark:text-white/45">“{{ a.notes }}”</p>

                <!-- What happened -->
                <p v-if="kindOf(a) === 'cancelled'" class="mt-2.5 rounded-xl bg-red-500/[0.06] px-3 py-2 text-xs text-red-700 dark:text-red-300">{{ cancelNote(a) }}</p>
                <p v-else-if="kindOf(a) === 'past'" class="mt-2.5 rounded-xl bg-amber-500/[0.08] px-3 py-2 text-xs text-amber-800 dark:text-amber-300">{{ pastNote(a) }}</p>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                  <NuxtLink :to="propertyHref(a.property_id)" :class="btnGhost">View listing</NuxtLink>
                  <button
                    v-if="a.status === 'confirmed' && kindOf(a) === 'past'"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full bg-brand-gold px-3.5 py-1.5 text-xs font-bold text-[#0B2A5C] hover:bg-brand-gold-light transition disabled:opacity-60"
                    :disabled="closing === a.id"
                    @click="markDone(a)"
                  >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    {{ closing === a.id ? 'Saving…' : 'Mark as done' }}
                  </button>
                  <!-- The buyer's rating of you, when they left one -->
                  <NuxtLink
                    v-if="reviewByBuyer.get(a.buyer_id)"
                    to="/dashboard/reviews"
                    class="ml-auto inline-flex items-center gap-1.5 text-xs text-brand-navy/55 hover:text-brand-navy dark:text-white/50 dark:hover:text-white"
                    :aria-label="`${a.buyer?.name ?? 'The buyer'} rated you ${reviewByBuyer.get(a.buyer_id)!.rating} stars`"
                  >
                    Rated you
                    <span class="inline-flex gap-px">
                      <svg v-for="n in 5" :key="n" class="h-3.5 w-3.5" :class="n <= reviewByBuyer.get(a.buyer_id)!.rating ? 'text-brand-gold' : 'text-brand-navy/15 dark:text-white/15'" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
                    </span>
                    <span v-if="!reviewByBuyer.get(a.buyer_id)!.agent_reply" class="font-semibold text-brand-gold-deep dark:text-brand-gold">· Reply</span>
                  </NuxtLink>
                </div>
              </div>
            </div>
          </li>
        </ul>
      </section>
    </div>

    <!-- ════════ Sold listings ════════ -->
    <div v-else-if="tab === 'sold' && inventory.length" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <article
        v-for="prop in inventory"
        :key="prop.id"
        class="flex gap-4 rounded-2xl p-4 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] hover:ring-brand-gold/50 transition"
      >
        <span class="relative h-24 w-28 flex-none overflow-hidden rounded-xl bg-brand-navy/5 dark:bg-white/10">
          <img v-if="thumb(prop)" :src="thumb(prop)!" :alt="prop.title" loading="lazy" class="h-full w-full object-cover" />
          <span class="absolute top-1.5 left-1.5 rounded-full bg-brand-navy px-2 py-0.5 text-[0.5625rem] font-bold uppercase tracking-wide text-brand-gold">{{ prop.offer_type === 'rent' ? 'Rented' : 'Sold' }}</span>
        </span>
        <div class="min-w-0 flex-1 flex flex-col">
          <p class="font-display text-lg font-bold text-brand-gold-deep dark:text-brand-gold tabular-nums">{{ priceLabel(prop) }}</p>
          <p class="line-clamp-1 font-semibold text-brand-navy dark:text-white">{{ calm(prop.title) }}</p>
          <p class="line-clamp-1 text-xs text-brand-navy/50 dark:text-white/45">{{ prop.address }}</p>
          <p v-if="prop.sold_at" class="mt-0.5 text-[0.6875rem] text-brand-navy/45 dark:text-white/40">{{ prop.offer_type === 'rent' ? 'Rented' : 'Sold' }} {{ soldDate(prop) }}</p>
          <div class="mt-auto flex items-center gap-2 pt-2">
            <NuxtLink :to="`/dashboard/listings/${prop.id}/edit`" :class="btnGhost">Edit</NuxtLink>
            <button
              type="button"
              class="inline-flex items-center gap-1.5 rounded-full bg-brand-gold px-3.5 py-1.5 text-xs font-bold text-[#0B2A5C] hover:bg-brand-gold-light transition"
              @click="emit('relist', prop)"
            >
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
              Re-list
            </button>
          </div>
        </div>
      </article>
    </div>

    <!-- ── Empty ── -->
    <div v-else-if="!items.length && !inventory.length" class="relative overflow-hidden rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] px-6 py-16 text-center">
      <div class="absolute -top-16 left-1/2 -translate-x-1/2 h-48 w-80 rounded-full bg-brand-gold/10 blur-3xl pointer-events-none" />
      <span class="relative mx-auto mb-5 h-16 w-16 rounded-2xl bg-brand-gold/15 flex items-center justify-center text-brand-gold-deep dark:text-brand-gold">
        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
      </span>
      <p class="relative font-display text-xl font-bold text-brand-navy dark:text-white">No history yet</p>
      <p class="relative mx-auto mt-2 max-w-sm text-sm text-brand-text-secondary dark:text-white/55 leading-relaxed">
        Viewings move here once they're done or cancelled, and listings you mark as sold land here so you can re-list them later.
      </p>
      <NuxtLink to="/dashboard/appointments" class="relative mt-6 inline-flex items-center rounded-full bg-brand-navy px-5 py-2.5 text-sm font-bold text-white dark:bg-brand-gold dark:text-brand-navy hover:-translate-y-0.5 transition">
        See upcoming viewings
      </NuxtLink>
    </div>
    <p v-else class="rounded-3xl border border-dashed border-brand-navy/15 dark:border-white/15 px-6 py-12 text-center text-sm text-brand-navy/55 dark:text-white/50">
      <template v-if="tab === 'sold'">No sold listings yet. Use <span class="font-semibold">Mark as sold</span> on a listing once the deal closes.</template>
      <template v-else>No viewings yet.</template>
    </p>
  </div>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
.hist-rise { animation: hist-rise 0.5s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes hist-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .hist-rise { animation: none; } }
</style>
