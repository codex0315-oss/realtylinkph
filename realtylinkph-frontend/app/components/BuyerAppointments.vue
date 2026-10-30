<script setup lang="ts">
import type { Appointment } from '~/types'

/**
 * The buyer's Appointments page (agents keep their own view in the page).
 *
 *   Next viewing hero with a live countdown
 *   → tabs: Upcoming / Waiting for agent / Needs follow-up
 *   → those viewings on a day-by-day timeline. Each card tracks where the
 *     viewing stands (Requested → Confirmed → Viewing day → Done) and offers
 *     Directions, Message agent, Add to calendar and Cancel.
 *   Beside it: what to bring, what to ask, and the 24-hour cancellation rule.
 */
const props = defineProps<{ appointments: Appointment[] }>()
const emit = defineEmits<{ cancel: [appt: Appointment] }>()

const authStore = useAuthStore()
const propertyHref = usePropertyHref()
const toast = useToast()
const { openConversation } = useConversation()
const { openChat } = useDashPanel()

/* A clock for the countdowns; ticks every 30 s. */
const now = ref(Date.now())
let tick: ReturnType<typeof setInterval> | undefined
onMounted(() => { tick = setInterval(() => { now.value = Date.now() }, 30_000) })
onUnmounted(() => clearInterval(tick))

const at = (a: Appointment) => +new Date(a.preferred_datetime)
const asc = (a: Appointment, b: Appointment) => at(a) - at(b)

/* ── Tabs ── */
type Tab = 'upcoming' | 'waiting' | 'followup'
const lists = computed<Record<Tab, Appointment[]>>(() => ({
  upcoming: props.appointments.filter(a => a.status === 'confirmed' && at(a) >= now.value).sort(asc),
  waiting:  props.appointments.filter(a => a.status === 'pending' && at(a) >= now.value).sort(asc),
  // The time has passed but the agent hasn't marked it done (or never confirmed).
  followup: props.appointments.filter(a => (a.status === 'confirmed' || a.status === 'pending') && at(a) < now.value).sort((a, b) => at(b) - at(a)),
}))
const TABS: Array<{ key: Tab; label: string; hint: string }> = [
  { key: 'upcoming', label: 'Upcoming',          hint: 'Confirmed by the agent' },
  { key: 'waiting',  label: 'Waiting for agent', hint: 'The agent hasn’t confirmed yet' },
  { key: 'followup', label: 'Needs follow-up',   hint: 'The time has passed. The agent marks it done, then it moves to History.' },
]
const tab = ref<Tab>(lists.value.upcoming.length ? 'upcoming' : lists.value.waiting.length ? 'waiting' : 'followup')
const total = computed(() => lists.value.upcoming.length + lists.value.waiting.length + lists.value.followup.length)

/* ── Next viewing ── */
const next = computed(() => lists.value.upcoming[0] ?? lists.value.waiting[0] ?? null)
function countdown(a: Appointment): string {
  const mins = Math.round((at(a) - now.value) / 60_000)
  if (mins <= 0) return 'Happening now'
  if (mins < 60) return `in ${mins} min`
  const hours = Math.floor(mins / 60)
  if (hours < 24) return `in ${hours} hr${hours === 1 ? '' : 's'}${mins % 60 ? ` ${mins % 60} min` : ''}`
  const days = Math.floor(hours / 24)
  return `in ${days} day${days === 1 ? '' : 's'}${hours % 24 ? `, ${hours % 24} hr${hours % 24 === 1 ? '' : 's'}` : ''}`
}

/* ── Timeline grouped by day ── */
function dayKey(a: Appointment) { return new Date(a.preferred_datetime).toDateString() }
function dayLabel(d: Date): string {
  const today = new Date(now.value)
  const tomorrow = new Date(today); tomorrow.setDate(today.getDate() + 1)
  const yesterday = new Date(today); yesterday.setDate(today.getDate() - 1)
  if (d.toDateString() === today.toDateString()) return 'Today'
  if (d.toDateString() === tomorrow.toDateString()) return 'Tomorrow'
  if (d.toDateString() === yesterday.toDateString()) return 'Yesterday'
  return d.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric' })
}
const days = computed(() => {
  const out: Array<{ key: string; label: string; items: Appointment[] }> = []
  for (const a of lists.value[tab.value]) {
    const k = dayKey(a)
    const last = out[out.length - 1]
    if (last?.key === k) last.items.push(a)
    else out.push({ key: k, label: dayLabel(new Date(a.preferred_datetime)), items: [a] })
  }
  return out
})

/* ── Status tracker ── */
const STEPS = ['Requested', 'Agent confirmed', 'Viewing day', 'Done']
function stepOf(a: Appointment): number {
  if (a.status === 'completed') return 3
  if (a.status !== 'confirmed') return 0
  const viewDay = new Date(a.preferred_datetime); viewDay.setHours(0, 0, 0, 0)
  return now.value >= +viewDay ? 2 : 1
}

/* ── Helpers ── */
function photoOf(a: Appointment) {
  const ps = a.property?.photos ?? []
  const p = ps.find(x => !x.is_360) ?? ps[0]
  return p ? (p.url ?? p.thumb_url) : null
}
function thumbOf(a: Appointment) {
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
const time = (a: Appointment) => new Date(a.preferred_datetime).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })
const longDate = (a: Appointment) => new Date(a.preferred_datetime).toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric' })
const month = (a: Appointment) => new Date(a.preferred_datetime).toLocaleDateString('en-PH', { month: 'short' })
const dayNum = (a: Appointment) => new Date(a.preferred_datetime).getDate()

function directions(a: Appointment): string | null {
  const p = a.property
  if (!p) return null
  const q = p.lat && p.lng ? `${p.lat},${p.lng}` : p.address
  return `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(q)}`
}

const opening = ref<number | null>(null)
async function messageAgent(a: Appointment) {
  opening.value = a.id
  const conv = await openConversation(a.property_id)
  opening.value = null
  if (conv) openChat(conv.id)
  else toast.error('Couldn’t open the chat. Please try again.')
}

/* Google Calendar already has it when the buyer connected it and the server
   created the event (that happens once the agent confirms). */
const synced = (a: Appointment) => !!authStore.user?.has_gcal && !!a.gcal_event_id

function addToCalendar(a: Appointment) {
  downloadViewingIcs({
    uid: `viewing-${a.id}`,
    title: calm(a.property?.title),
    address: a.property?.address ?? '',
    start: new Date(a.preferred_datetime),
    agentName: a.agent?.name,
    url: `${window.location.origin}${propertyHref(a.property_id)}`,
    pending: a.status === 'pending',
  })
  toast.success('Calendar file downloaded')
}

const btnGhost =
  'inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-bold transition ' +
  'text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold disabled:opacity-50'
const ICON = {
  map: 'M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z',
  chat: 'M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z',
  cal:  'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-9-6h.008v.008H12v-.008zM12 15h.008v.008H12V15zm0 2.25h.008v.008H12v-.008zM9.75 15h.008v.008H9.75V15zm0 2.25h.008v.008H9.75v-.008zM7.5 15h.008v.008H7.5V15zm0 2.25h.008v.008H7.5v-.008zm6.75-4.5h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V15zm0 2.25h.008v.008h-.008v-.008zm2.25-4.5h.008v.008H16.5v-.008zm0 2.25h.008v.008H16.5V15z',
  pin:  'M15 10.5a3 3 0 11-6 0 3 3 0 016 0z M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z',
}
</script>

<template>
  <div>
    <!-- ── Header ── -->
    <header class="flex flex-wrap items-end justify-between gap-4 mb-6">
      <div class="min-w-0">
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Your viewings</p>
        <h1 class="mt-1.5 font-display text-2xl cw-sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">Appointments</h1>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
          <template v-if="total">
            <span class="font-semibold text-brand-navy dark:text-white">{{ lists.upcoming.length }}</span> confirmed,
            <span class="font-semibold text-brand-navy dark:text-white">{{ lists.waiting.length }}</span> waiting for the agent.
            Past ones move to <NuxtLink to="/dashboard/history" class="font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline underline-offset-4">History</NuxtLink>.
          </template>
          <template v-else>Viewings you book show up here, from request to visit.</template>
        </p>
      </div>
      <DashCta to="/dashboard/browse">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
        Book a viewing
      </DashCta>
    </header>

    <slot name="banners" />

    <div class="grid grid-cols-1 gap-6 cw-lg:grid-cols-[minmax(0,1fr)_19rem]">
      <div class="min-w-0 space-y-6">
        <!-- ── Next viewing ── -->
        <article
          v-if="next"
          class="appt-rise overflow-hidden rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]
                 shadow-[0_24px_50px_-34px_rgba(11,42,92,0.45)] flex flex-col cw-sm:flex-row"
        >
          <NuxtLink :to="propertyHref(next.property_id)" class="group relative block cw-sm:w-[40%] aspect-[16/10] cw-sm:aspect-auto cw-sm:min-h-[14rem] flex-none overflow-hidden bg-brand-navy/10">
            <img v-if="photoOf(next)" :src="photoOf(next)!" alt="" class="absolute inset-0 h-full w-full object-cover transition-transform duration-700 group-hover:scale-105" />
            <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-black/10 to-transparent" />
            <span class="absolute bottom-3 left-3 rounded-full bg-black/45 backdrop-blur-sm px-3 py-1 text-xs font-bold text-white ring-1 ring-white/20">
              {{ countdown(next) }}
            </span>
          </NuxtLink>
          <div class="flex-1 min-w-0 p-5 cw-sm:p-6 flex flex-col">
            <div class="flex flex-wrap items-center gap-2">
              <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">Next viewing</p>
              <span
                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold"
                :class="next.status === 'confirmed' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400'"
              >
                <span class="h-1.5 w-1.5 rounded-full" :class="next.status === 'confirmed' ? 'bg-emerald-500' : 'bg-amber-500'" />
                {{ next.status === 'confirmed' ? 'Confirmed' : 'Waiting for agent' }}
              </span>
            </div>
            <p class="mt-2 font-display text-xl cw-sm:text-[1.375rem] font-bold leading-snug text-brand-navy dark:text-white">{{ longDate(next) }}</p>
            <p class="text-sm font-semibold text-brand-gold-deep dark:text-brand-gold">{{ time(next) }}</p>
            <NuxtLink :to="propertyHref(next.property_id)" class="mt-3 block truncate text-[0.9375rem] font-semibold text-brand-navy dark:text-white hover:text-brand-gold-deep dark:hover:text-brand-gold transition-colors">
              {{ calm(next.property?.title) }}
            </NuxtLink>
            <p class="mt-0.5 flex items-center gap-1 truncate text-xs text-brand-text-secondary dark:text-white/50">
              <svg class="h-3.5 w-3.5 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.pin" /></svg>
              <span class="truncate">{{ next.property?.address }}</span>
            </p>
            <div class="mt-auto pt-5 flex flex-wrap items-center gap-2">
              <a v-if="directions(next)" :href="directions(next)!" target="_blank" rel="noopener"
                 class="inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold bg-brand-navy text-white hover:-translate-y-0.5 hover:shadow-md dark:bg-brand-gold dark:text-brand-navy transition">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.map" /></svg>
                Directions
              </a>
              <button type="button" :class="btnGhost" :disabled="opening === next.id" @click="messageAgent(next)">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.chat" /></svg>
                Message agent
              </button>
              <span v-if="synced(next)" class="inline-flex items-center gap-1.5 px-1 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                In your Google Calendar
              </span>
              <button v-else type="button" :class="btnGhost" @click="addToCalendar(next)">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.cal" /></svg>
                Add to calendar
              </button>
            </div>
          </div>
        </article>

        <!-- ── Tabs ── -->
        <div v-if="total" class="-mx-1 overflow-x-auto no-scrollbar px-1">
          <div class="inline-flex items-center gap-1 rounded-full p-1 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10" role="tablist">
            <button
              v-for="t in TABS"
              :key="t.key"
              type="button"
              role="tab"
              :aria-selected="tab === t.key"
              class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3.5 py-1.5 text-sm font-semibold transition"
              :class="tab === t.key
                ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-brand-navy'
                : 'text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white'"
              @click="tab = t.key"
            >
              {{ t.label }}
              <span
                class="min-w-[1.25rem] h-5 px-1.5 rounded-full text-[0.6875rem] font-bold flex items-center justify-center"
                :class="tab === t.key ? 'bg-white/20 dark:bg-brand-navy/15' : t.key === 'followup' && lists.followup.length ? 'bg-amber-500/15 text-amber-700 dark:text-amber-400' : 'bg-brand-navy/[0.06] dark:bg-white/10'"
              >{{ lists[t.key].length }}</span>
            </button>
          </div>
          <p class="mt-2 text-xs text-brand-navy/50 dark:text-white/45">{{ TABS.find(t => t.key === tab)!.hint }}</p>
        </div>

        <!-- ── Timeline ── -->
        <div v-if="days.length" class="space-y-7">
          <section v-for="d in days" :key="d.key">
            <h2 class="mb-3 flex items-center gap-2 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/50 dark:text-white/45">
              {{ d.label }}
              <span class="h-px flex-1 bg-brand-navy/[0.08] dark:bg-white/10" />
            </h2>

            <ol class="relative space-y-4 pl-[4.5rem] cw-sm:pl-20">
              <!-- the timeline spine -->
              <span class="absolute left-[1.75rem] cw-sm:left-8 top-2 bottom-2 w-px bg-gradient-to-b from-brand-gold/60 via-brand-navy/15 to-transparent dark:via-white/15" aria-hidden="true" />

              <li v-for="a in d.items" :key="a.id" class="relative">
                <!-- date leaf on the spine -->
                <span class="absolute -left-[4.5rem] cw-sm:-left-20 top-0 w-14 overflow-hidden rounded-xl text-center bg-[#ffffff] ring-1 ring-brand-navy/10 shadow-[0_6px_14px_-8px_rgba(11,42,92,0.4)] dark:ring-white/10">
                  <span class="block bg-brand-gold py-0.5 text-[0.625rem] font-bold uppercase tracking-wider text-[#0B2A5C]">{{ month(a) }}</span>
                  <span class="block pt-1 font-display text-lg font-bold leading-none text-[#0B2A5C]">{{ dayNum(a) }}</span>
                  <span class="block pb-1 pt-0.5 text-[0.5625rem] font-semibold text-[#0B2A5C]/55">{{ time(a) }}</span>
                </span>

                <article class="rounded-2xl p-4 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] hover:ring-brand-gold/50 transition">
                  <div class="flex gap-3.5">
                    <NuxtLink :to="propertyHref(a.property_id)" class="h-16 w-20 cw-sm:h-[4.5rem] cw-sm:w-24 flex-none overflow-hidden rounded-xl bg-brand-navy/5 dark:bg-white/10">
                      <img v-if="thumbOf(a)" :src="thumbOf(a)!" alt="" loading="lazy" class="h-full w-full object-cover" />
                    </NuxtLink>
                    <div class="min-w-0 flex-1">
                      <div class="flex items-start justify-between gap-2">
                        <NuxtLink :to="propertyHref(a.property_id)" class="line-clamp-1 font-semibold text-brand-navy dark:text-white hover:text-brand-gold-deep dark:hover:text-brand-gold transition-colors">
                          {{ calm(a.property?.title) }}
                        </NuxtLink>
                        <span v-if="next && a.id === next.id" class="flex-none rounded-full bg-brand-gold/15 px-2 py-0.5 text-[0.625rem] font-bold uppercase tracking-wide text-brand-gold-deep dark:text-brand-gold">Next</span>
                      </div>
                      <p class="mt-0.5 flex items-center gap-1 truncate text-xs text-brand-text-secondary dark:text-white/50">
                        <svg class="h-3.5 w-3.5 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.pin" /></svg>
                        <span class="truncate">{{ a.property?.address }}</span>
                      </p>
                      <p class="mt-1.5 flex items-center gap-1.5 text-xs text-brand-navy/70 dark:text-white/65">
                        <AppAvatar :name="a.agent?.name" :src="a.agent?.avatar" size="xs" />
                        <span class="font-semibold text-brand-navy dark:text-white">{{ a.agent?.name ?? 'Agent' }}</span>
                        <span
                          v-if="a.agent?.reliability?.has_enough"
                          class="rounded-full px-1.5 py-0.5 text-[0.625rem] font-bold"
                          :class="(a.agent.reliability.rate ?? 100) >= 80 ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400'"
                          :title="`Kept ${a.agent.reliability.kept} of ${a.agent.reliability.kept + a.agent.reliability.missed} confirmed viewings`"
                        >{{ a.agent.reliability.rate }}% kept</span>
                      </p>
                    </div>
                  </div>

                  <!-- Where it stands -->
                  <ol class="mt-4 grid grid-cols-4 gap-1.5" :aria-label="`Status: ${STEPS[stepOf(a)]}`">
                    <li v-for="(s, i) in STEPS" :key="s" class="min-w-0">
                      <span
                        class="block h-1.5 rounded-full transition-colors"
                        :class="i < stepOf(a) ? 'bg-emerald-500' : i === stepOf(a) ? (a.status === 'pending' ? 'bg-amber-500 appt-pulse' : 'bg-brand-gold appt-pulse') : 'bg-brand-navy/10 dark:bg-white/10'"
                      />
                      <span
                        class="mt-1.5 block truncate text-[0.6875rem]"
                        :class="i === stepOf(a) ? 'font-bold text-brand-navy dark:text-white' : i < stepOf(a) ? 'font-medium text-brand-navy/60 dark:text-white/55' : 'text-brand-navy/35 dark:text-white/30'"
                      >{{ i === 0 && a.status === 'pending' && at(a) >= now ? 'Waiting for agent' : s }}</span>
                    </li>
                  </ol>

                  <p v-if="a.notes" class="mt-3 rounded-xl bg-brand-cream/80 dark:bg-white/[0.04] px-3 py-2 text-xs italic text-brand-navy/65 dark:text-white/55 line-clamp-2">“{{ a.notes }}”</p>

                  <div class="mt-4 flex flex-wrap items-center gap-2">
                    <a v-if="directions(a)" :href="directions(a)!" target="_blank" rel="noopener" :class="btnGhost">
                      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.map" /></svg>
                      Directions
                    </a>
                    <button type="button" :class="btnGhost" :disabled="opening === a.id" @click="messageAgent(a)">
                      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.chat" /></svg>
                      Message agent
                    </button>
                    <template v-if="at(a) >= now">
                      <span v-if="synced(a)" class="inline-flex items-center gap-1 px-1 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        In Google Calendar
                      </span>
                      <button v-else type="button" :class="btnGhost" @click="addToCalendar(a)">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.cal" /></svg>
                        Add to calendar
                      </button>
                    </template>
                    <button
                      type="button"
                      class="ml-auto inline-flex items-center rounded-full px-3.5 py-1.5 text-xs font-bold text-red-600 hover:bg-red-500/10 dark:text-red-400 transition"
                      @click="emit('cancel', a)"
                    >
                      Cancel viewing
                    </button>
                  </div>
                </article>
              </li>
            </ol>
          </section>
        </div>

        <!-- Tab with nothing in it -->
        <div v-else-if="total" class="rounded-2xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] px-6 py-10 text-center">
          <p class="font-semibold text-brand-navy dark:text-white">
            {{ tab === 'upcoming' ? 'Nothing confirmed yet' : tab === 'waiting' ? 'No requests waiting' : 'All caught up' }}
          </p>
          <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/50">
            {{ tab === 'upcoming' ? 'Viewings appear here once the agent confirms them.' : tab === 'waiting' ? 'Every request has an answer from the agent.' : 'No past viewings are waiting on the agent.' }}
          </p>
        </div>

        <!-- ── Nothing booked ── -->
        <div v-if="!total" class="relative overflow-hidden rounded-3xl p-6 cw-sm:p-8 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]">
          <div class="absolute -right-10 -top-10 h-44 w-44 rounded-full bg-brand-gold/10 blur-2xl pointer-events-none" />
          <!-- Icon beside the heading, as on the overview's "Next viewing" card -->
          <div class="relative flex items-center gap-4">
            <span class="h-12 w-12 flex-none rounded-2xl flex items-center justify-center bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold">
              <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.cal" /></svg>
            </span>
            <div class="min-w-0">
              <h2 class="font-display text-xl font-bold text-brand-navy dark:text-white">No viewings booked yet</h2>
              <p class="mt-1 max-w-md text-sm text-brand-text-secondary dark:text-white/55">Seeing a place in person takes three steps.</p>
            </div>
          </div>
          <ol class="relative mt-5 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(11rem,1fr))]">
            <li v-for="(s, i) in [['Pick a time', 'On any listing, choose a slot that suits you.'], ['Agent confirms', 'You get a notification as soon as they do.'], ['Go see it', 'Directions and a calendar reminder are here.']]" :key="i"
                class="rounded-2xl p-4 bg-brand-cream/70 ring-1 ring-brand-navy/[0.05] dark:bg-white/[0.04] dark:ring-white/[0.06]">
              <span class="h-6 w-6 rounded-full flex items-center justify-center text-[0.6875rem] font-bold bg-brand-navy text-white dark:bg-brand-gold dark:text-brand-navy">{{ i + 1 }}</span>
              <p class="mt-2.5 text-sm font-semibold text-brand-navy dark:text-white">{{ s[0] }}</p>
              <p class="mt-0.5 text-xs leading-relaxed text-brand-navy/55 dark:text-white/50">{{ s[1] }}</p>
            </li>
          </ol>
          <div class="relative mt-6 flex flex-wrap gap-2">
            <NuxtLink to="/dashboard/browse" class="inline-flex items-center rounded-full px-4 py-2 text-xs font-bold bg-brand-navy text-white hover:-translate-y-0.5 dark:bg-brand-gold dark:text-brand-navy transition">Find a home to view</NuxtLink>
            <NuxtLink to="/dashboard/saved" :class="btnGhost">Book from your saved homes</NuxtLink>
          </div>
        </div>
      </div>

      <!-- ── Side: tips + policy ── -->
      <aside class="space-y-4 cw-lg:sticky cw-lg:top-24 cw-lg:self-start">
        <section class="rounded-3xl p-5 bg-brand-navy text-white ring-1 ring-brand-gold/25 relative overflow-hidden">
          <div class="absolute inset-0 bg-grid-gold opacity-[0.06] pointer-events-none" />
          <p class="relative text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold">Before you go</p>
          <ul class="relative mt-3 space-y-2.5 text-sm">
            <li v-for="t in ['Bring a valid ID', 'Arrive 10 minutes early', 'Take photos and notes as you walk through', 'Check water pressure, outlets and signal']" :key="t" class="flex items-start gap-2.5">
              <svg class="mt-0.5 h-4 w-4 flex-none text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
              <span class="text-white/85">{{ t }}</span>
            </li>
          </ul>
          <p class="relative mt-4 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold">Ask the agent</p>
          <ul class="relative mt-2 space-y-1.5 text-sm text-white/75">
            <li>• What’s included in the price?</li>
            <li>• Monthly dues and other fees?</li>
            <li>• Is the title clean and ready to transfer?</li>
            <li>• How soon can I move in?</li>
          </ul>
        </section>

        <section class="rounded-3xl p-5 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]">
          <p class="flex items-center gap-2 text-sm font-bold text-brand-navy dark:text-white">
            <svg class="h-4 w-4 text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            Cancelling
          </p>
          <p class="mt-2 text-xs leading-relaxed text-brand-text-secondary dark:text-white/55">
            Cancel at least <span class="font-semibold text-brand-navy dark:text-white">24 hours</span> before the viewing and it never counts against you.
            Five late cancellations in 30 days pause booking for a while.
          </p>
          <p v-if="authStore.user?.reliability?.rate != null" class="mt-3 rounded-xl bg-brand-cream/80 dark:bg-white/[0.04] px-3 py-2 text-xs text-brand-navy/70 dark:text-white/60">
            You’ve kept <span class="font-bold text-brand-navy dark:text-white">{{ authStore.user.reliability.rate }}%</span> of your confirmed viewings.
          </p>
        </section>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }

.appt-rise { animation: appt-rise 0.5s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes appt-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }

/* The current step glows softly. */
.appt-pulse { animation: appt-pulse 2s ease-in-out infinite; }
@keyframes appt-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.55; } }

@media (prefers-reduced-motion: reduce) {
  .appt-rise, .appt-pulse { animation: none; }
}
</style>
