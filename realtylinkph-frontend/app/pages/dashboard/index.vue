<script setup lang="ts">
import type { Appointment, Property } from '~/types'

definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()

// Admins have their own panel; agents get the agent overview (below).
if (authStore.isAdmin) {
  await navigateTo('/admin')
}

const { getFeatured }                       = useProperty()
const { appointments, fetchMyAppointments } = useAppointment()
const { favorites, fetchFavorites }         = useFavorite()
const { fetchConversations }                = useConversation()
const convStore                             = useConversationStore()
const notifStore                            = useNotificationStore()
const { panel, open: openPanel }            = useDashPanel()
const { send: askAi }                       = useAiThread()
const { items: recent, load: loadRecent }   = useRecentlyViewed()
const propertyHref                          = usePropertyHref()

const recommended = ref<Property[]>([])
const loadingRec  = ref(true)

if (authStore.isBuyer) {
  const results = await Promise.allSettled([
    getFeatured(),
    fetchMyAppointments(),
    fetchFavorites(),
    fetchConversations(),
  ])
  if (results[0].status === 'fulfilled') recommended.value = results[0].value as Property[]
  loadingRec.value = false
}
onMounted(loadRecent)

/*
 * Page order follows what a buyer needs, in that order:
 *   1. anything time-bound (the next viewing) and a way to start (the AI);
 *   2. the counters, each with one line of context;
 *   3. their own shortlist and what they were just looking at;
 *   4. discovery (recommended).
 * Sections with nothing in them drop out instead of showing empty boxes.
 */

/* ── Viewings ── */
const upcoming = computed(() =>
  appointments.value
    .filter(a => (a.status === 'pending' || a.status === 'confirmed') && +new Date(a.preferred_datetime) >= Date.now())
    .sort((a, b) => +new Date(a.preferred_datetime) - +new Date(b.preferred_datetime)),
)
const next = computed<Appointment | undefined>(() => upcoming.value[0])

const nextPhoto = computed(() => {
  const photos = next.value?.property?.photos ?? []
  const p = photos.find(ph => !ph.is_360) ?? photos[0]
  return p ? (p.url ?? p.thumb_url) : null
})
const nextWhen = computed(() => {
  if (!next.value) return null
  const d = new Date(next.value.preferred_datetime)
  return {
    month: d.toLocaleDateString('en-PH', { month: 'short' }),
    day:   d.getDate(),
    date:  d.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric' }),
    time:  d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }),
    rel:   relativeDay(d),
  }
})
function relativeDay(d: Date): string {
  const start = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime()
  const days = Math.round((start(d) - start(new Date())) / 86_400_000)
  if (days <= 0) return 'Today'
  if (days === 1) return 'Tomorrow'
  if (days < 7) return `In ${days} days`
  const weeks = Math.round(days / 7)
  return `In ${weeks} week${weeks === 1 ? '' : 's'}`
}
const VIEWING_STEPS = [
  { t: 'Pick a time',           d: 'On any listing, choose a slot that suits you.' },
  { t: 'Agent confirms',        d: 'You get a notification as soon as they do.' },
  { t: 'It’s in your calendar', d: 'Synced to Google Calendar if you connect it.' },
]
/* Opens the chat about the viewing's property in the Messages panel. */
const { openConversation } = useConversation()
const { openChat } = useDashPanel()
async function messageAgent() {
  if (!next.value) return
  const conv = await openConversation(next.value.property_id)
  if (conv) openChat(conv.id)
  else openPanel('messages')
}
const directionsHref = computed(() => {
  const p = next.value?.property
  if (!p) return null
  const q = p.lat && p.lng ? `${p.lat},${p.lng}` : p.address
  return `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(q)}`
})

/* ── Greeting + one-line status ── */
const firstName = computed(() => authStore.user?.name?.split(' ')[0] ?? '')
const greeting = computed(() => {
  const h = new Date().getHours()
  return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening'
})
const today = new Date().toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric' })

const unreadMessages = computed(() => convStore.totalUnread)
const plural = (n: number, w: string) => `${n} ${w}${n === 1 ? '' : 's'}`
const statusLine = computed(() => {
  const parts: string[] = []
  if (upcoming.value.length) parts.push(plural(upcoming.value.length, 'upcoming viewing'))
  if (unreadMessages.value)  parts.push(plural(unreadMessages.value, 'unread message'))
  if (notifStore.bellUnread) parts.push(plural(notifStore.bellUnread, 'new notification'))
  if (!parts.length) return "You're all caught up. Here's what's new from verified agents."
  const list = parts.length > 1 ? `${parts.slice(0, -1).join(', ')} and ${parts.at(-1)}` : parts[0]
  return `You have ${list}.`
})

/* ── Counters ── */
const agentsWithUnread = computed(() => convStore.conversations.filter(c => (c.unread_count ?? 0) > 0).length)
const stats = computed(() => [
  {
    label: 'Upcoming viewings',
    value: upcoming.value.length,
    href:  '/dashboard/appointments',
    hint:  nextWhen.value ? `Next: ${nextWhen.value.rel.toLowerCase()}, ${nextWhen.value.time}` : 'None booked yet',
    accent: upcoming.value.length > 0,
  },
  {
    label: 'Saved homes',
    value: favorites.value.length,
    href:  '/dashboard/saved',
    hint:  favorites.value.length ? 'Your shortlist' : 'Tap ♥ on a listing to save it',
  },
  {
    label: 'Unread messages',
    value: unreadMessages.value,
    onSelect: () => openPanel('messages'),
    hint:  unreadMessages.value ? `From ${plural(agentsWithUnread.value, 'agent')}` : 'All caught up',
    accent: unreadMessages.value > 0,
  },
  {
    label: 'Notifications',
    value: notifStore.bellUnread,
    onSelect: () => openPanel('notifications'),
    hint:  notifStore.bellUnread ? 'Unread updates' : 'Nothing new',
    accent: notifStore.bellUnread > 0,
  },
])

/* ── RealtyLink AI card ── */
const AI_PROMPTS = [
  'Condos under ₱3M in Cebu',
  'Homes near schools for a family',
  'Compare my saved homes',
]
function ask(prompt?: string) {
  openPanel('assistant')
  if (prompt) askAi(prompt)
}

/* ── Recently viewed ── */
/** Agent titles are often ALL CAPS; soften them like PropertyCard does. */
function calm(t: string): string {
  const letters = t.replace(/[^A-Za-z]/g, '')
  return letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
}
function ago(ts: number): string {
  const m = Math.round((Date.now() - ts) / 60_000)
  if (m < 1) return 'Just now'
  if (m < 60) return `${m} min ago`
  const h = Math.round(m / 60)
  if (h < 24) return `${h}h ago`
  const d = Math.round(h / 24)
  return d === 1 ? 'Yesterday' : `${d} days ago`
}
function shortPrice(price: string, offer: string): string {
  const n = Number(price)
  const s = n >= 1_000_000 ? `₱${(n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1)}M`
          : n >= 1_000     ? `₱${Math.round(n / 1_000)}K`
          : `₱${n.toLocaleString('en-PH')}`
  return offer === 'rent' ? `${s}/mo` : s
}

/* With a side panel docked the column is ~360px narrower: hold the two-up
   hero row back a breakpoint and keep property grids at two across. */
const docked = computed(() => !!panel.value)
</script>

<template>
  <!-- Agents get the agent overview; buyers get the buyer overview below. -->
  <AgentDashboard v-if="authStore.isAgent" />

  <div v-else class="space-y-9 max-w-6xl mx-auto">

    <!-- ── Greeting ── -->
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div class="min-w-0">
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">{{ today }}</p>
        <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] text-brand-navy dark:text-white leading-tight">
          {{ greeting }}<template v-if="firstName">, {{ firstName }}</template>
        </h1>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">{{ statusLine }}</p>
      </div>

      <DashCta to="/dashboard/browse">
        <svg class="h-4 w-4 transition-transform duration-300 group-hover:scale-110" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        Browse listings
      </DashCta>
    </header>

    <!-- ── Next viewing + Ask AI ── -->
    <div
      class="grid grid-cols-1 gap-5"
      :class="docked ? '2xl:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]' : 'lg:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]'"
    >
      <!-- Next viewing -->
      <article
        v-if="next && nextWhen"
        class="dash-rise overflow-hidden rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]
               shadow-[0_24px_50px_-34px_rgba(11,42,92,0.45)] flex flex-col sm:flex-row"
      >
        <NuxtLink :to="propertyHref(next.property_id)" class="group relative block sm:w-[42%] aspect-[16/10] sm:aspect-auto sm:min-h-[15rem] flex-none overflow-hidden bg-brand-navy/10">
          <img v-if="nextPhoto" :src="nextPhoto" alt="" class="absolute inset-0 h-full w-full object-cover transition-transform duration-700 group-hover:scale-105" />
          <div class="absolute inset-0 bg-gradient-to-t from-black/45 via-transparent to-transparent" />
          <!-- Calendar leaf -->
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
              :class="next.status === 'confirmed'
                ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                : 'bg-amber-500/10 text-amber-700 dark:text-amber-400'"
            >
              <span class="h-1.5 w-1.5 rounded-full" :class="next.status === 'confirmed' ? 'bg-emerald-500' : 'bg-amber-500'" />
              {{ next.status === 'confirmed' ? 'Confirmed' : 'Waiting for agent' }}
            </span>
          </div>

          <p class="mt-2 font-display text-xl sm:text-[1.375rem] font-bold leading-snug text-brand-navy dark:text-white">
            {{ nextWhen.date }}
          </p>
          <p class="text-sm font-semibold text-brand-gold-deep dark:text-brand-gold">{{ nextWhen.time }} · {{ nextWhen.rel }}</p>

          <div class="mt-4 min-w-0">
            <NuxtLink :to="propertyHref(next.property_id)" class="block truncate text-[0.9375rem] font-semibold text-brand-navy dark:text-white hover:text-brand-gold-deep dark:hover:text-brand-gold transition-colors">
              {{ next.property?.title ? calm(next.property.title) : 'Property viewing' }}
            </NuxtLink>
            <p class="mt-0.5 flex items-center gap-1 truncate text-xs text-brand-text-secondary dark:text-white/50">
              <svg class="h-3.5 w-3.5 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
              <span class="truncate">{{ next.property?.address }}</span>
            </p>
          </div>

          <div v-if="next.agent" class="mt-3 flex items-center gap-2">
            <AppAvatar :name="next.agent.name" :src="next.agent.avatar" size="xs" />
            <span class="text-xs text-brand-navy/70 dark:text-white/65">with <span class="font-semibold text-brand-navy dark:text-white">{{ next.agent.name }}</span></span>
          </div>

          <div class="mt-auto pt-5 flex flex-wrap items-center gap-2">
            <a
              v-if="directionsHref"
              :href="directionsHref"
              target="_blank"
              rel="noopener"
              class="inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold bg-brand-navy text-white hover:-translate-y-0.5 hover:shadow-md dark:bg-brand-gold dark:text-brand-navy transition"
            >
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z" /></svg>
              Directions
            </a>
            <button
              type="button"
              class="inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold transition"
              @click="messageAgent"
            >
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
              Message agent
            </button>
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
            <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/55">Seeing a place in person takes three steps.</p>
          </div>
        </div>

        <!-- How booking works, so the empty state teaches instead of just saying "nothing" -->
        <ol class="relative mt-6 grid grid-cols-1 sm:grid-cols-3 gap-3">
          <li v-for="(s, i) in VIEWING_STEPS"
              :key="s.t"
              class="rounded-2xl p-4 bg-brand-cream/70 ring-1 ring-brand-navy/[0.05] dark:bg-white/[0.04] dark:ring-white/[0.06]"
          >
            <span class="h-6 w-6 rounded-full flex items-center justify-center text-[0.6875rem] font-bold bg-brand-navy text-white dark:bg-brand-gold dark:text-brand-navy">{{ i + 1 }}</span>
            <p class="mt-2.5 text-sm font-semibold text-brand-navy dark:text-white">{{ s.t }}</p>
            <p class="mt-0.5 text-xs leading-relaxed text-brand-navy/55 dark:text-white/50">{{ s.d }}</p>
          </li>
        </ol>

        <div class="relative mt-auto pt-6 flex flex-wrap gap-2">
          <NuxtLink to="/dashboard/browse" class="inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold bg-brand-navy text-white hover:-translate-y-0.5 hover:shadow-md dark:bg-brand-gold dark:text-brand-navy transition">
            Find a home to view
          </NuxtLink>
          <NuxtLink v-if="favorites.length" to="/dashboard/saved" class="inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold transition">
            Book from your saved homes
          </NuxtLink>
        </div>
      </article>

      <!-- Ask RealtyLink AI -->
      <article class="dash-rise relative overflow-hidden rounded-3xl p-6 bg-brand-navy text-white ring-1 ring-brand-gold/25 shadow-[0_24px_50px_-30px_rgba(11,42,92,0.7)]" style="animation-delay: 80ms">
        <div class="absolute inset-0 bg-grid-gold opacity-[0.07] pointer-events-none" />
        <div class="absolute -right-16 -top-16 h-56 w-56 rounded-full bg-brand-gold/20 blur-[70px] pointer-events-none" />
        <img src="/ai/mascot-320.webp" alt="" class="absolute right-3 top-3 h-24 w-24 object-contain drop-shadow-[0_10px_20px_rgba(0,0,0,0.35)] ai-float pointer-events-none" />

        <div class="relative">
          <p class="flex items-center gap-1.5 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold">
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400" aria-hidden="true" />
            RealtyLink AI
          </p>
          <h2 class="mt-2 pr-24 font-heading text-[1.5rem] font-semibold leading-tight">Not sure where to start?</h2>
          <p class="mt-1.5 pr-20 text-sm leading-relaxed text-white/65">Ask in plain words. I'll search live listings for you.</p>

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
            Ask anything about buying or renting…
          </button>
        </div>
      </article>
    </div>

    <!-- ── Counters ── -->
    <DashStatStrip :stats="stats" />

    <!-- ── Saved homes ── -->
    <DashSection
      v-if="favorites.length"
      title="Saved homes"
      :action-label="`View all (${favorites.length})`"
      action-href="/dashboard/saved"
      :framed="false"
    >
      <PropertyGrid :properties="favorites.slice(0, docked ? 3 : 4)" :dense="docked" />
    </DashSection>

    <!-- ── Recently viewed ── -->
    <DashSection v-if="recent.length" title="Pick up where you left off" :framed="false">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" :class="docked ? '2xl:grid-cols-3' : 'xl:grid-cols-3'">
        <NuxtLink
          v-for="r in recent.slice(0, 6)"
          :key="r.id"
          :to="propertyHref(r.id)"
          class="group flex items-center gap-3 rounded-2xl p-2 pr-4 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]
                 hover:ring-brand-gold hover:-translate-y-0.5 hover:shadow-[0_14px_30px_-20px_rgba(11,42,92,0.45)] transition"
        >
          <span class="h-16 w-20 flex-none overflow-hidden rounded-xl bg-brand-navy/5 dark:bg-white/10">
            <img v-if="r.photo" :src="r.photo" alt="" loading="lazy" class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-semibold text-brand-navy dark:text-white">{{ calm(r.title) }}</span>
            <span class="block text-[0.8125rem] font-bold text-brand-gold-deep dark:text-brand-gold tabular-nums">{{ shortPrice(r.price, r.offer_type) }}</span>
            <span class="block truncate text-[0.6875rem] text-brand-navy/45 dark:text-white/40">Viewed {{ ago(r.viewedAt).toLowerCase() }}</span>
          </span>
        </NuxtLink>
      </div>
    </DashSection>

    <!-- ── Recommended ── -->
    <DashSection title="Recommended for you" action-href="/dashboard/browse" :framed="false">
      <PropertyGrid
        v-if="loadingRec || recommended.length"
        :properties="docked ? recommended.slice(0, 6) : recommended"
        :loading="loadingRec"
        :skeleton-count="4"
        :dense="docked"
      />

      <div
        v-else
        class="rounded-2xl border border-gray-200 dark:border-white/[0.08] bg-white dark:bg-[#0B1A35] px-5 py-12 text-center"
      >
        <p class="text-sm text-brand-navy/60 dark:text-white/50">No listings available yet.</p>
        <p class="mt-1 text-xs text-brand-navy/40 dark:text-white/35">
          New properties from verified agents will appear here.
        </p>
      </div>
    </DashSection>

  </div>
</template>

<style scoped>
/* Top row rises in once on load; reduced motion skips it. */
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
