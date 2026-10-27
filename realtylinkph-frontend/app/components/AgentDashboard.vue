<script setup lang="ts">
import type { Appointment, Property } from '~/types'

/*
 * The agent overview, in the same shape as the buyer's:
 *   1. greeting + what needs doing today;
 *   2. the next viewing (confirm it right here) beside RealtyLink AI;
 *   3. the counters, each with one line of context;
 *   4. how the listings are performing, and the newest inquiries;
 *   5. the viewing schedule beside the agent's reputation.
 */
const authStore  = useAuthStore()
const convStore  = useConversationStore()
const toast      = useToast()
const { properties, fetchMyListings }                    = useProperty()
const { inquiries, fetchInquiries, unreadCount }         = useInquiry()
const { appointments, fetchMyAppointments, confirm }     = useAppointment()
const { reviews, averageRating, fetchAgentReviews }      = useReview()
const { panel, open: openPanel, openInquiries }          = useDashPanel()
const docked = computed(() => !!panel.value)

await Promise.allSettled([
  fetchMyListings(),
  fetchInquiries(),
  fetchMyAppointments(),
  authStore.user ? fetchAgentReviews(authStore.user.id) : Promise.resolve(),
])

/* ── Listings ── */
const published  = computed(() => properties.value.filter(p => p.status === 'published'))
const drafts     = computed(() => properties.value.filter(p => p.status === 'draft'))
const totalViews = computed(() => properties.value.reduce((s, p) => s + (p.views ?? 0), 0))
const topListings = computed(() => [...properties.value].sort((a, b) => (b.views ?? 0) - (a.views ?? 0)).slice(0, 5))
const maxViews    = computed(() => Math.max(1, ...topListings.value.map(p => p.views ?? 0)))

/* ── Viewings ── */
const upcoming = computed(() =>
  appointments.value
    .filter(a => (a.status === 'pending' || a.status === 'confirmed') && +new Date(a.preferred_datetime) >= Date.now() - 36e5)
    .sort((a, b) => +new Date(a.preferred_datetime) - +new Date(b.preferred_datetime)),
)
const toConfirm = computed(() => upcoming.value.filter(a => a.status === 'pending'))
const next = computed<Appointment | undefined>(() => upcoming.value[0])
const nextPhoto = computed(() => {
  const photos = next.value?.property?.photos ?? []
  const p = photos.find(ph => !ph.is_360) ?? photos[0]
  return p ? (p.url ?? p.thumb_url) : null
})
function when(iso: string) {
  const d = new Date(iso)
  return {
    month: d.toLocaleDateString('en-PH', { month: 'short' }),
    day:   d.getDate(),
    date:  d.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric' }),
    short: d.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' }),
    time:  d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }),
    rel:   relativeDay(d),
  }
}
function relativeDay(d: Date): string {
  const start = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime()
  const days = Math.round((start(d) - start(new Date())) / 86_400_000)
  if (days <= 0) return 'Today'
  if (days === 1) return 'Tomorrow'
  if (days < 7) return `In ${days} days`
  const weeks = Math.round(days / 7)
  return `In ${weeks} week${weeks === 1 ? '' : 's'}`
}
const nextWhen = computed(() => (next.value ? when(next.value.preferred_datetime) : null))

const confirming = ref<number | null>(null)
async function confirmViewing(a: Appointment) {
  confirming.value = a.id
  if (await confirm(a.id)) {
    toast.success('Viewing confirmed. The buyer has been notified')
    await fetchMyAppointments()
  } else toast.error('Couldn’t confirm it. Please try again.')
  confirming.value = null
}
const directionsHref = computed(() => {
  const p = next.value?.property
  if (!p) return null
  const q = p.lat && p.lng ? `${p.lat},${p.lng}` : p.address
  return `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(q)}`
})

/* ── Greeting + what needs doing ── */
const firstName = computed(() => authStore.user?.name?.split(' ')[0] ?? '')
const greeting = computed(() => {
  const h = new Date().getHours()
  return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening'
})
const today = new Date().toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric' })
const plural = (n: number, w: string) => `${n} ${w}${n === 1 ? '' : 's'}`
const statusLine = computed(() => {
  const parts: string[] = []
  if (toConfirm.value.length) parts.push(`${plural(toConfirm.value.length, 'viewing')} to confirm`)
  if (unreadCount.value)      parts.push(`${unreadCount.value} new ${unreadCount.value === 1 ? 'inquiry' : 'inquiries'}`)
  if (convStore.totalUnread)  parts.push(plural(convStore.totalUnread, 'unread message'))
  if (!parts.length) return "You're all caught up. Nothing is waiting on you."
  const list = parts.length > 1 ? `${parts.slice(0, -1).join(', ')} and ${parts.at(-1)}` : parts[0]
  return `You have ${list}.`
})

/* ── Counters ── */
const stats = computed(() => [
  {
    label: 'Active listings',
    value: published.value.length,
    href:  '/dashboard/listings',
    hint:  drafts.value.length ? `${plural(drafts.value.length, 'draft')} not published` : 'All published',
  },
  {
    label: 'Total views',
    value: totalViews.value.toLocaleString('en-PH'),
    href:  '/dashboard/listings',
    hint:  topListings.value[0]?.views ? `Most: ${calm(topListings.value[0].title)}` : 'Views appear as buyers browse',
  },
  {
    label: 'New inquiries',
    value: unreadCount.value,
    onSelect: openInquiries,
    hint:  unreadCount.value ? 'Waiting for your reply' : 'All answered',
    accent: unreadCount.value > 0,
  },
  {
    label: 'Upcoming viewings',
    value: upcoming.value.length,
    href:  '/dashboard/appointments',
    hint:  toConfirm.value.length ? `${toConfirm.value.length} to confirm` : nextWhen.value ? `Next: ${nextWhen.value.rel.toLowerCase()}, ${nextWhen.value.time}` : 'None booked yet',
    accent: toConfirm.value.length > 0,
  },
])

const recentInquiries = computed(() =>
  [...inquiries.value].sort((a, b) => +new Date(b.created_at) - +new Date(a.created_at)).slice(0, 5),
)

/* ── Reputation ── */
const visibleReviews = computed(() => reviews.value.filter(r => r.is_visible !== false))
const latestReview = computed(() =>
  [...visibleReviews.value].filter(r => r.review_text).sort((a, b) => +new Date(b.created_at) - +new Date(a.created_at))[0],
)
const STAR = 'M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z'

/* ── RealtyLink AI card ── */
const AI_PROMPTS = [
  'Which viewing requests need my answer?',
  'Check my listings for fixes',
  'Draft a listing: 2BR condo in Cebu, ₱5M, 60 sqm',
]
const aiPrompt = useState<string | null>('agent-ai-prompt', () => null)
function ask(prompt?: string) {
  openPanel('assistant')
  if (prompt) aiPrompt.value = prompt
}

/* ── Helpers ── */
/** Agent titles are often ALL CAPS; soften them like PropertyCard does. */
function calm(t: string): string {
  const letters = t.replace(/[^A-Za-z]/g, '')
  return letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
}
function priceLabel(p: Property): string {
  const n = Number(p.price)
  const s = n >= 1_000_000 ? `₱${(n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1)}M`
          : n >= 1_000     ? `₱${(n / 1_000).toFixed(0)}K`
          : `₱${n.toLocaleString('en-PH')}`
  return p.offer_type === 'rent' ? `${s}/mo` : s
}
function thumb(p?: Property) {
  const ph = p?.photos?.find(x => !x.is_360) ?? p?.photos?.[0]
  return ph ? (ph.thumb_url ?? ph.url) : null
}
function ago(iso: string): string {
  const m = Math.round((Date.now() - +new Date(iso)) / 60_000)
  if (m < 60) return m < 1 ? 'Just now' : `${m}m ago`
  const h = Math.round(m / 60)
  if (h < 24) return `${h}h ago`
  const d = Math.round(h / 24)
  return d === 1 ? 'Yesterday' : d < 7 ? `${d}d ago` : new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
}
const STATUS_PILL: Record<string, string> = {
  published: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
  draft:     'bg-amber-500/10 text-amber-700 dark:text-amber-400',
  sold:      'bg-brand-navy/[0.07] text-brand-navy/70 dark:bg-white/10 dark:text-white/70',
}
const roleLabel = computed(() => (authStore.isVerifiedAgent ? 'Verified agent' : 'Agent'))
</script>

<template>
  <div class="space-y-9 max-w-6xl mx-auto">

    <!-- ── Greeting ── -->
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div class="min-w-0">
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">{{ today }}</p>
        <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] text-brand-navy dark:text-white leading-tight">
          {{ greeting }}<template v-if="firstName">, {{ firstName }}</template>
        </h1>
        <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-brand-text-secondary dark:text-white/55">
          <span>{{ statusLine }}</span>
        </p>
        <p class="mt-2 flex flex-wrap items-center gap-2 text-xs">
          <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 font-semibold text-emerald-700 dark:text-emerald-400">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            {{ roleLabel }}
          </span>
          <NuxtLink v-if="visibleReviews.length" to="/dashboard/reviews" class="inline-flex items-center gap-1 rounded-full bg-brand-gold/15 px-2.5 py-1 font-semibold text-brand-gold-deep dark:text-brand-gold hover:bg-brand-gold/25 transition">
            <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
            {{ averageRating.toFixed(1) }} · {{ plural(visibleReviews.length, 'review') }}
          </NuxtLink>
        </p>
      </div>

      <DashCta to="/dashboard/listings/new">
        <svg class="h-4 w-4 transition-transform duration-300 group-hover:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
        </svg>
        New listing
      </DashCta>
    </header>

    <!-- ── Next viewing + RealtyLink AI ── -->
    <!-- With a side panel docked the column is ~360px narrower: hold the two-up row back a breakpoint. -->
    <div class="grid grid-cols-1 gap-5" :class="docked ? '2xl:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]' : 'lg:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]'">
      <!-- Next viewing -->
      <article
        v-if="next && nextWhen"
        class="dash-rise overflow-hidden rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]
               shadow-[0_24px_50px_-34px_rgba(11,42,92,0.45)] flex flex-col sm:flex-row"
      >
        <NuxtLink :to="`/dashboard/listings/${next.property_id}/edit`" class="group relative block sm:w-[42%] aspect-[16/10] sm:aspect-auto sm:min-h-[15rem] flex-none overflow-hidden bg-brand-navy/10">
          <img v-if="nextPhoto" :src="nextPhoto" alt="" class="absolute inset-0 h-full w-full object-cover transition-transform duration-700 group-hover:scale-105" />
          <div class="absolute inset-0 bg-gradient-to-t from-black/45 via-transparent to-transparent" />
          <!-- bg-[#ffffff], not bg-white: the dark-mode shim repaints bg-white navy -->
          <span class="absolute top-3 left-3 w-14 overflow-hidden rounded-xl bg-[#ffffff] text-center shadow-[0_8px_20px_rgba(0,0,0,0.25)]">
            <span class="block bg-brand-gold py-0.5 text-[0.625rem] font-bold uppercase tracking-wider text-brand-navy">{{ nextWhen.month }}</span>
            <span class="block py-1 font-display text-xl font-bold leading-none text-[#0B2A5C]">{{ nextWhen.day }}</span>
          </span>
        </NuxtLink>

        <div class="flex-1 min-w-0 p-5 sm:p-6 flex flex-col">
          <div class="flex items-center gap-2">
            <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">Next viewing</p>
            <span
              class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold"
              :class="next.status === 'confirmed' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400'"
            >
              <span class="h-1.5 w-1.5 rounded-full" :class="next.status === 'confirmed' ? 'bg-emerald-500' : 'bg-amber-500'" />
              {{ next.status === 'confirmed' ? 'Confirmed' : 'Needs your confirmation' }}
            </span>
          </div>

          <p class="mt-2 font-display text-xl sm:text-[1.375rem] font-bold leading-snug text-brand-navy dark:text-white">{{ nextWhen.date }}</p>
          <p class="text-sm font-semibold text-brand-gold-deep dark:text-brand-gold">{{ nextWhen.time }} · {{ nextWhen.rel }}</p>

          <div class="mt-4 min-w-0">
            <p class="truncate text-[0.9375rem] font-semibold text-brand-navy dark:text-white">{{ next.property?.title ? calm(next.property.title) : 'Property viewing' }}</p>
            <p class="mt-0.5 truncate text-xs text-brand-text-secondary dark:text-white/50">{{ next.property?.address }}</p>
          </div>

          <div v-if="next.buyer" class="mt-3 flex items-center gap-2">
            <AppAvatar :name="next.buyer.name" :src="next.buyer.avatar" size="xs" />
            <span class="text-xs text-brand-navy/70 dark:text-white/65">with <span class="font-semibold text-brand-navy dark:text-white">{{ next.buyer.name }}</span></span>
          </div>

          <div class="mt-auto pt-5 flex flex-wrap items-center gap-2">
            <button
              v-if="next.status === 'pending'"
              type="button"
              class="inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold bg-brand-gold text-[#0B2A5C] shadow-[0_4px_14px_rgba(212,165,74,0.4)] hover:-translate-y-0.5 transition disabled:opacity-60"
              :disabled="confirming === next.id"
              @click="confirmViewing(next)"
            >
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
              {{ confirming === next.id ? 'Confirming…' : 'Confirm viewing' }}
            </button>
            <a
              v-if="directionsHref"
              :href="directionsHref"
              target="_blank"
              rel="noopener"
              class="inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold transition"
              :class="next.status === 'pending'
                ? 'text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20'
                : 'bg-brand-navy text-white hover:-translate-y-0.5 hover:shadow-md dark:bg-brand-gold dark:text-brand-navy'"
            >Directions</a>
            <NuxtLink to="/dashboard/appointments" class="ml-auto text-xs font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline underline-offset-4">
              <template v-if="upcoming.length > 1">+{{ upcoming.length - 1 }} more · </template>All viewings
            </NuxtLink>
          </div>
        </div>
      </article>

      <!-- No viewing yet -->
      <article
        v-else
        class="dash-rise relative overflow-hidden rounded-3xl p-6 sm:p-7 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] flex flex-col"
      >
        <div class="absolute -right-10 -top-10 h-44 w-44 rounded-full bg-brand-gold/10 blur-2xl pointer-events-none" />
        <div class="relative flex items-start gap-4">
          <span class="h-12 w-12 flex-none rounded-2xl flex items-center justify-center bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
          </span>
          <div class="min-w-0">
            <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">Next viewing</p>
            <h2 class="mt-1 font-display text-xl font-bold text-brand-navy dark:text-white">No viewings booked yet</h2>
            <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/55">Buyers book from your listings. Here's how to get more requests.</p>
          </div>
        </div>
        <ol class="relative mt-6 grid grid-cols-1 sm:grid-cols-3 gap-3">
          <li
            v-for="(s, i) in [
              { t: 'Add great photos', d: 'Listings with clear photos and a 360° view get booked more.' },
              { t: 'Reply quickly', d: 'Answer inquiries and messages within the day.' },
              { t: 'Confirm fast', d: 'Confirm requests here as soon as they come in.' },
            ]"
            :key="s.t"
            class="rounded-2xl p-4 bg-brand-cream/70 ring-1 ring-brand-navy/[0.05] dark:bg-white/[0.04] dark:ring-white/[0.06]"
          >
            <span class="h-6 w-6 rounded-full flex items-center justify-center text-[0.6875rem] font-bold bg-brand-navy text-white dark:bg-brand-gold dark:text-brand-navy">{{ i + 1 }}</span>
            <p class="mt-2.5 text-sm font-semibold text-brand-navy dark:text-white">{{ s.t }}</p>
            <p class="mt-0.5 text-xs leading-relaxed text-brand-navy/55 dark:text-white/50">{{ s.d }}</p>
          </li>
        </ol>
        <div class="relative mt-auto pt-6 flex flex-wrap gap-2">
          <NuxtLink to="/dashboard/listings" class="inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold bg-brand-navy text-white hover:-translate-y-0.5 hover:shadow-md dark:bg-brand-gold dark:text-brand-navy transition">Improve my listings</NuxtLink>
          <NuxtLink to="/dashboard/calendar" class="inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 transition">Open calendar</NuxtLink>
        </div>
      </article>

      <!-- RealtyLink AI -->
      <article class="dash-rise relative overflow-hidden rounded-3xl p-6 bg-brand-navy text-white ring-1 ring-brand-gold/25 shadow-[0_24px_50px_-30px_rgba(11,42,92,0.7)]" style="animation-delay: 80ms">
        <div class="absolute inset-0 bg-grid-gold opacity-[0.07] pointer-events-none" />
        <div class="absolute -right-16 -top-16 h-56 w-56 rounded-full bg-brand-gold/20 blur-[70px] pointer-events-none" />
        <img src="/ai/mascot-320.webp" alt="" class="absolute right-3 top-3 h-24 w-24 object-contain drop-shadow-[0_10px_20px_rgba(0,0,0,0.35)] ai-float pointer-events-none" />

        <div class="relative">
          <p class="flex items-center gap-1.5 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold">
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400" aria-hidden="true" />
            RealtyLink AI
          </p>
          <h2 class="mt-2 pr-24 font-heading text-[1.5rem] font-semibold leading-tight">Your listing assistant</h2>
          <p class="mt-1.5 pr-20 text-sm leading-relaxed text-white/65">Drafts listings, writes descriptions from a photo and reads your numbers.</p>

          <div class="mt-5 space-y-2">
            <button
              v-for="p in AI_PROMPTS"
              :key="p"
              type="button"
              class="group w-full flex items-center justify-between gap-3 rounded-xl px-3.5 py-2.5 text-left text-sm font-medium
                     bg-white/[0.07] ring-1 ring-white/10 hover:bg-white/[0.12] hover:ring-brand-gold/70 transition"
              @click="ask(p)"
            >
              <span class="truncate">{{ p }}</span>
              <svg class="h-4 w-4 flex-none text-white/40 group-hover:text-brand-gold group-hover:translate-x-0.5 transition" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
            </button>
          </div>

          <button
            type="button"
            class="mt-3 w-full flex items-center gap-2.5 rounded-full bg-[#ffffff] px-4 py-2.5 text-left text-sm text-[#0B2A5C]/50 hover:ring-2 hover:ring-brand-gold transition"
            @click="ask()"
          >
            <svg class="h-4 w-4 flex-none text-brand-gold-deep" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
            Describe a property, or attach a photo…
          </button>
        </div>
      </article>
    </div>

    <!-- ── Counters ── -->
    <DashStatStrip :stats="stats" />

    <!-- ── Listing performance + inquiries ── -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <DashSection title="Listing performance" action-label="Manage all" action-href="/dashboard/listings">
        <div v-if="topListings.length" class="divide-y divide-gray-100 dark:divide-white/[0.06]">
          <NuxtLink
            v-for="prop in topListings"
            :key="prop.id"
            :to="`/dashboard/listings/${prop.id}/edit`"
            class="group flex items-center gap-3 px-5 py-3.5 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.04]"
          >
            <span class="h-12 w-16 rounded-xl bg-gray-100 dark:bg-white/[0.06] overflow-hidden flex-shrink-0">
              <img v-if="thumb(prop)" :src="thumb(prop)!" alt="" loading="lazy" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105" />
            </span>
            <span class="flex-1 min-w-0">
              <span class="flex items-center gap-2">
                <span class="truncate text-sm font-semibold text-brand-navy dark:text-white group-hover:text-brand-gold-deep dark:group-hover:text-brand-gold transition-colors">{{ calm(prop.title) }}</span>
              </span>
              <span class="mt-1.5 flex items-center gap-2">
                <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-brand-navy/[0.06] dark:bg-white/10">
                  <span class="block h-full rounded-full bg-gradient-to-r from-brand-gold-deep to-brand-gold" :style="{ width: `${Math.max(4, ((prop.views ?? 0) / maxViews) * 100)}%` }" />
                </span>
                <span class="w-16 flex-none text-right text-[0.6875rem] tabular-nums text-brand-navy/50 dark:text-white/45">{{ plural(prop.views ?? 0, 'view') }}</span>
              </span>
            </span>
            <span class="flex flex-col items-end gap-1 flex-shrink-0">
              <span class="text-sm font-bold text-brand-navy dark:text-white tabular-nums">{{ priceLabel(prop) }}</span>
              <span class="rounded-full px-2 py-0.5 text-[0.625rem] font-bold capitalize" :class="STATUS_PILL[prop.status]">{{ prop.status }}</span>
            </span>
          </NuxtLink>
        </div>
        <div v-else class="flex-1 flex flex-col items-center justify-center px-5 py-12 text-center">
          <p class="text-sm text-brand-navy/60 dark:text-white/50">You haven't listed a property yet.</p>
          <NuxtLink to="/dashboard/listings/new" class="mt-4 inline-flex items-center gap-2 text-sm font-semibold px-4 py-2 rounded-xl border border-brand-gold/40 text-brand-gold-deep dark:text-brand-gold hover:bg-brand-gold/10 transition-colors">Create your first listing</NuxtLink>
        </div>
      </DashSection>

      <DashSection title="Recent inquiries" action-href="/dashboard?panel=inquiries">
        <div v-if="recentInquiries.length" class="divide-y divide-gray-100 dark:divide-white/[0.06]">
          <NuxtLink
            v-for="inq in recentInquiries"
            :key="inq.id"
            to="/dashboard?panel=inquiries"
            class="flex items-start gap-3 px-5 py-3.5 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.04]"
          >
            <span class="relative flex-shrink-0">
              <AppAvatar :name="inq.name" size="sm" />
              <span v-if="!inq.is_read" class="absolute -top-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-brand-gold ring-2 ring-white dark:ring-[#0B1A35]" aria-label="New" />
            </span>
            <span class="flex-1 min-w-0">
              <span class="flex items-center gap-2">
                <span class="truncate text-sm text-brand-navy dark:text-white" :class="inq.is_read ? 'font-medium' : 'font-bold'">{{ inq.name }}</span>
                <span class="ml-auto text-[0.6875rem] text-brand-navy/40 dark:text-white/35 flex-shrink-0">{{ ago(inq.created_at) }}</span>
              </span>
              <span class="mt-0.5 block truncate text-xs font-medium text-brand-gold-deep dark:text-brand-gold">{{ inq.property?.title ? calm(inq.property.title) : `Property #${inq.property_id}` }}</span>
              <span class="mt-0.5 block truncate text-xs text-brand-navy/50 dark:text-white/45">{{ inq.message }}</span>
            </span>
          </NuxtLink>
        </div>
        <div v-else class="flex-1 flex flex-col items-center justify-center px-5 py-12 text-center">
          <p class="text-sm text-brand-navy/60 dark:text-white/50">No inquiries yet.</p>
          <p class="mt-1 text-xs text-brand-navy/40 dark:text-white/35">Questions from buyers on your listings arrive here.</p>
        </div>
      </DashSection>
    </div>

    <!-- ── Schedule + reputation ── -->
    <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)] gap-6">
      <DashSection title="Viewing schedule" action-href="/dashboard/appointments">
        <div v-if="upcoming.length" class="divide-y divide-gray-100 dark:divide-white/[0.06]">
          <div v-for="appt in upcoming.slice(0, 5)" :key="appt.id" class="flex items-center gap-3 px-5 py-3.5">
            <span class="w-12 flex-none overflow-hidden rounded-xl text-center ring-1 ring-brand-navy/10 dark:ring-white/10">
              <span class="block bg-brand-navy py-0.5 text-[0.5625rem] font-bold uppercase tracking-wider text-brand-gold dark:bg-brand-gold dark:text-brand-navy">{{ when(appt.preferred_datetime).month }}</span>
              <span class="block py-1 text-base font-bold leading-none text-brand-navy dark:text-white">{{ when(appt.preferred_datetime).day }}</span>
            </span>
            <span class="flex-1 min-w-0">
              <span class="block truncate text-sm font-semibold text-brand-navy dark:text-white">{{ appt.property?.title ? calm(appt.property.title) : `Property #${appt.property_id}` }}</span>
              <span class="mt-0.5 block truncate text-xs text-brand-navy/50 dark:text-white/45">{{ when(appt.preferred_datetime).time }}<template v-if="appt.buyer?.name"> · {{ appt.buyer.name }}</template></span>
            </span>
            <button
              v-if="appt.status === 'pending'"
              type="button"
              class="flex-shrink-0 rounded-full px-3 py-1.5 text-[0.6875rem] font-bold bg-brand-gold text-[#0B2A5C] hover:bg-brand-gold-light transition disabled:opacity-60"
              :disabled="confirming === appt.id"
              @click="confirmViewing(appt)"
            >{{ confirming === appt.id ? 'Confirming…' : 'Confirm' }}</button>
            <span v-else class="flex-shrink-0 inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-1 text-[0.625rem] font-bold text-emerald-700 dark:text-emerald-400">
              <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" /> Confirmed
            </span>
          </div>
        </div>
        <div v-else class="flex-1 flex flex-col items-center justify-center px-5 py-12 text-center">
          <p class="text-sm text-brand-navy/60 dark:text-white/50">Nothing scheduled.</p>
          <p class="mt-1 text-xs text-brand-navy/40 dark:text-white/35">Viewings buyers book on your listings show up here.</p>
        </div>
      </DashSection>

      <DashSection title="Your reputation" action-label="All reviews" action-href="/dashboard/reviews">
        <div class="flex-1 flex flex-col p-5">
          <template v-if="visibleReviews.length">
            <div class="flex items-end gap-3">
              <span class="font-display text-[2.5rem] font-bold leading-none text-brand-navy dark:text-white tabular-nums">{{ averageRating.toFixed(1) }}</span>
              <span class="pb-1">
                <span class="flex gap-0.5" :aria-label="`${averageRating.toFixed(1)} out of 5`">
                  <svg v-for="n in 5" :key="n" class="h-4 w-4" :class="n <= Math.round(averageRating) ? 'text-brand-gold' : 'text-brand-navy/15 dark:text-white/15'" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
                </span>
                <span class="mt-0.5 block text-xs text-brand-navy/50 dark:text-white/45">from {{ plural(visibleReviews.length, 'review') }}</span>
              </span>
            </div>
            <figure v-if="latestReview" class="mt-4 rounded-2xl bg-brand-cream/70 dark:bg-white/[0.04] p-4 ring-1 ring-brand-navy/[0.05] dark:ring-white/[0.06]">
              <blockquote class="line-clamp-3 text-sm leading-relaxed text-brand-navy/80 dark:text-white/75">“{{ latestReview.review_text }}”</blockquote>
              <figcaption class="mt-2 flex items-center gap-2 text-xs text-brand-navy/50 dark:text-white/45">
                <AppAvatar :name="latestReview.buyer?.name" :src="latestReview.buyer?.avatar" size="xs" />
                {{ latestReview.buyer?.name ?? 'A buyer' }} · {{ ago(latestReview.created_at) }}
              </figcaption>
            </figure>
          </template>
          <div v-else class="flex-1 flex flex-col items-center justify-center py-6 text-center">
            <span class="flex gap-0.5"><svg v-for="n in 5" :key="n" class="h-5 w-5 text-brand-navy/15 dark:text-white/15" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg></span>
            <p class="mt-3 text-sm text-brand-navy/60 dark:text-white/50">No reviews yet.</p>
            <p class="mt-1 text-xs text-brand-navy/40 dark:text-white/35">Buyers can rate you after a viewing or a chat.</p>
          </div>
        </div>
      </DashSection>
    </div>
  </div>
</template>

<style scoped>
.dash-rise { animation: dash-rise 0.5s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes dash-rise {
  from { opacity: 0; transform: translateY(10px); }
  to   { opacity: 1; transform: none; }
}
.ai-float { animation: ai-float 4s ease-in-out infinite; }
@keyframes ai-float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }

@media (prefers-reduced-motion: reduce) {
  .dash-rise, .ai-float { animation: none; }
}
</style>
