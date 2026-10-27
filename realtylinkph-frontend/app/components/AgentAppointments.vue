<script setup lang="ts">
import type { Appointment } from '~/types'

/**
 * The agent's Appointments page, in the same shape as the buyer's:
 *   Next viewing hero with a live countdown (Confirm right there if pending)
 *   → tabs: Needs your response / Upcoming / Needs closing
 *   → those viewings on a day-by-day timeline. Each card tracks where the
 *     viewing stands and offers Confirm / Decline, Directions, Message buyer,
 *     Mark as done and Cancel.
 *   Full width, no side panel.
 */
const props = defineProps<{ appointments: Appointment[]; busyId?: number | null }>()
const emit = defineEmits<{ confirm: [appt: Appointment]; complete: [appt: Appointment]; cancel: [appt: Appointment] }>()

const authStore = useAuthStore()
const propertyHref = usePropertyHref()
const convStore = useConversationStore()
const { fetchConversations } = useConversation()
const { openChat, open: openPanel } = useDashPanel()

/* A clock for the countdowns; ticks every 30 s. */
const now = ref(Date.now())
let tick: ReturnType<typeof setInterval> | undefined
onMounted(() => { tick = setInterval(() => { now.value = Date.now() }, 30_000) })
onUnmounted(() => clearInterval(tick))

const at = (a: Appointment) => +new Date(a.preferred_datetime)
const asc = (a: Appointment, b: Appointment) => at(a) - at(b)

/* ── Tabs ── */
type Tab = 'requests' | 'upcoming' | 'closing'
const lists = computed<Record<Tab, Appointment[]>>(() => ({
  requests: props.appointments.filter(a => a.status === 'pending' && at(a) >= now.value).sort(asc),
  upcoming: props.appointments.filter(a => a.status === 'confirmed' && at(a) >= now.value).sort(asc),
  // The time has passed but it was never marked done (or never answered).
  closing:  props.appointments.filter(a => (a.status === 'confirmed' || a.status === 'pending') && at(a) < now.value).sort((a, b) => at(b) - at(a)),
}))
const TABS: Array<{ key: Tab; label: string; hint: string }> = [
  { key: 'requests', label: 'Needs your response', hint: 'Buyers asked for these times. Confirm or decline them.' },
  { key: 'upcoming', label: 'Upcoming',            hint: 'Confirmed viewings, soonest first' },
  { key: 'closing',  label: 'Needs closing',       hint: 'The time has passed. Mark it done so the buyer can rate you, and it moves to History.' },
]
const tab = ref<Tab>(lists.value.requests.length ? 'requests' : lists.value.upcoming.length ? 'upcoming' : 'closing')
const total = computed(() => lists.value.requests.length + lists.value.upcoming.length + lists.value.closing.length)

/* ── Next viewing ── */
const next = computed(() => [...lists.value.upcoming, ...lists.value.requests].sort(asc)[0] ?? null)
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
    const d = new Date(a.preferred_datetime)
    const k = d.toDateString()
    const last = out[out.length - 1]
    if (last?.key === k) last.items.push(a)
    else out.push({ key: k, label: dayLabel(d), items: [a] })
  }
  return out
})

/* ── Status tracker ── */
const STEPS = ['Requested', 'You confirmed', 'Viewing day', 'Done']
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
const synced = (a: Appointment) => !!authStore.user?.has_gcal && !!a.gcal_event_id

/* Open the chat with this buyer about this listing, if there is one. */
async function messageBuyer(a: Appointment) {
  if (!convStore.conversations.length) await fetchConversations()
  const conv = convStore.conversations.find(c => c.buyer_id === a.buyer_id && c.property_id === a.property_id)
    ?? convStore.conversations.find(c => c.buyer_id === a.buyer_id)
  if (conv) openChat(conv.id)
  else openPanel('messages')
}


const btnGhost =
  'inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-bold transition ' +
  'text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold disabled:opacity-50'
const btnGold =
  'inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-xs font-bold transition ' +
  'bg-brand-gold text-[#0B2A5C] shadow-[0_4px_14px_rgba(212,165,74,0.4)] hover:bg-brand-gold-light disabled:opacity-60'
const ICON = {
  map: 'M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z',
  chat: 'M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z',
  cal:  'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
  pin:  'M15 10.5a3 3 0 11-6 0 3 3 0 016 0z M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z',
  check: 'M4.5 12.75l6 6 9-13.5',
}
</script>

<template>
  <div>
    <!-- ── Header ── -->
    <header class="flex flex-wrap items-end justify-between gap-4 mb-6">
      <div class="min-w-0">
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Schedule</p>
        <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">Appointments</h1>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
          <template v-if="total">
            <span class="font-semibold text-amber-700 dark:text-amber-400">{{ lists.requests.length }}</span> waiting for your answer,
            <span class="font-semibold text-brand-navy dark:text-white">{{ lists.upcoming.length }}</span> confirmed.
            Finished ones move to <NuxtLink to="/dashboard/history" class="font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline underline-offset-4">History</NuxtLink>.
          </template>
          <template v-else>Viewings buyers book on your listings show up here, from request to visit.</template>
        </p>
      </div>
      <DashCta to="/dashboard/calendar">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.cal" /></svg>
        Open calendar
      </DashCta>
    </header>

    <slot name="banners" />

    <div>
      <div class="min-w-0 space-y-6">
        <!-- ── Next viewing ── -->
        <article
          v-if="next"
          class="appt-rise overflow-hidden rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]
                 shadow-[0_24px_50px_-34px_rgba(11,42,92,0.45)] flex flex-col sm:flex-row"
        >
          <NuxtLink :to="propertyHref(next.property_id)" class="group relative block sm:w-[40%] aspect-[16/10] sm:aspect-auto sm:min-h-[14rem] flex-none overflow-hidden bg-brand-navy/10">
            <img v-if="photoOf(next)" :src="photoOf(next)!" alt="" class="absolute inset-0 h-full w-full object-cover transition-transform duration-700 group-hover:scale-105" />
            <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-black/10 to-transparent" />
            <span class="absolute bottom-3 left-3 rounded-full bg-black/45 backdrop-blur-sm px-3 py-1 text-xs font-bold text-white ring-1 ring-white/20">{{ countdown(next) }}</span>
          </NuxtLink>
          <div class="flex-1 min-w-0 p-5 sm:p-6 flex flex-col">
            <div class="flex flex-wrap items-center gap-2">
              <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">Next viewing</p>
              <span
                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold"
                :class="next.status === 'confirmed' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400'"
              >
                <span class="h-1.5 w-1.5 rounded-full" :class="next.status === 'confirmed' ? 'bg-emerald-500' : 'bg-amber-500'" />
                {{ next.status === 'confirmed' ? 'Confirmed' : 'Needs your confirmation' }}
              </span>
            </div>
            <p class="mt-2 font-display text-xl sm:text-[1.375rem] font-bold leading-snug text-brand-navy dark:text-white">{{ longDate(next) }}</p>
            <p class="text-sm font-semibold text-brand-gold-deep dark:text-brand-gold">{{ time(next) }}</p>
            <NuxtLink :to="propertyHref(next.property_id)" class="mt-3 block truncate text-[0.9375rem] font-semibold text-brand-navy dark:text-white hover:text-brand-gold-deep dark:hover:text-brand-gold transition-colors">{{ calm(next.property?.title) }}</NuxtLink>
            <p class="mt-1.5 flex items-center gap-1.5 text-xs text-brand-navy/70 dark:text-white/65">
              <AppAvatar :name="next.buyer?.name" :src="next.buyer?.avatar" size="xs" />
              with <span class="font-semibold text-brand-navy dark:text-white">{{ next.buyer?.name ?? 'a buyer' }}</span>
            </p>
            <div class="mt-auto pt-5 flex flex-wrap items-center gap-2">
              <button v-if="next.status === 'pending'" type="button" :class="btnGold" :disabled="busyId === next.id" @click="emit('confirm', next)">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.check" /></svg>
                Confirm viewing
              </button>
              <a v-if="directions(next)" :href="directions(next)!" target="_blank" rel="noopener"
                 :class="next.status === 'pending' ? btnGhost : 'inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold bg-brand-navy text-white hover:-translate-y-0.5 hover:shadow-md dark:bg-brand-gold dark:text-brand-navy transition'">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.map" /></svg>
                Directions
              </a>
              <button type="button" :class="btnGhost" @click="messageBuyer(next)">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.chat" /></svg>
                Message buyer
              </button>
              <span v-if="synced(next)" class="inline-flex items-center gap-1.5 px-1 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.check" /></svg>
                In your Google Calendar
              </span>
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
                :class="tab === t.key ? 'bg-white/20 dark:bg-brand-navy/15' : t.key !== 'upcoming' && lists[t.key].length ? 'bg-amber-500/15 text-amber-700 dark:text-amber-400' : 'bg-brand-navy/[0.06] dark:bg-white/10'"
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

            <ol class="relative space-y-4 pl-[4.5rem] sm:pl-20">
              <span class="absolute left-[1.75rem] sm:left-8 top-2 bottom-2 w-px bg-gradient-to-b from-brand-gold/60 via-brand-navy/15 to-transparent dark:via-white/15" aria-hidden="true" />

              <li v-for="a in d.items" :key="a.id" class="relative">
                <!-- date leaf on the spine; bg-[#ffffff] since the dark-mode shim repaints bg-white -->
                <span class="absolute -left-[4.5rem] sm:-left-20 top-0 w-14 overflow-hidden rounded-xl text-center bg-[#ffffff] ring-1 ring-brand-navy/10 shadow-[0_6px_14px_-8px_rgba(11,42,92,0.4)] dark:ring-white/10">
                  <span class="block py-0.5 text-[0.625rem] font-bold uppercase tracking-wider" :class="a.status === 'pending' ? 'bg-amber-400 text-[#0B2A5C]' : 'bg-brand-gold text-[#0B2A5C]'">{{ month(a) }}</span>
                  <span class="block pt-1 font-display text-lg font-bold leading-none text-[#0B2A5C]">{{ dayNum(a) }}</span>
                  <span class="block pb-1 pt-0.5 text-[0.5625rem] font-semibold text-[#0B2A5C]/55">{{ time(a) }}</span>
                </span>

                <article
                  class="rounded-2xl p-4 bg-white dark:bg-[#0B1A35] ring-1 transition"
                  :class="a.status === 'pending' && at(a) >= now ? 'ring-amber-400/50 hover:ring-amber-400' : 'ring-brand-navy/[0.07] dark:ring-white/[0.08] hover:ring-brand-gold/50'"
                >
                  <div class="flex gap-3.5">
                    <NuxtLink :to="propertyHref(a.property_id)" class="h-16 w-20 sm:h-[4.5rem] sm:w-24 flex-none overflow-hidden rounded-xl bg-brand-navy/5 dark:bg-white/10">
                      <img v-if="thumbOf(a)" :src="thumbOf(a)!" alt="" loading="lazy" class="h-full w-full object-cover" />
                    </NuxtLink>
                    <div class="min-w-0 flex-1">
                      <div class="flex items-start justify-between gap-2">
                        <NuxtLink :to="propertyHref(a.property_id)" class="line-clamp-1 font-semibold text-brand-navy dark:text-white hover:text-brand-gold-deep dark:hover:text-brand-gold transition-colors">{{ calm(a.property?.title) }}</NuxtLink>
                        <span v-if="next && a.id === next.id" class="flex-none rounded-full bg-brand-gold/15 px-2 py-0.5 text-[0.625rem] font-bold uppercase tracking-wide text-brand-gold-deep dark:text-brand-gold">Next</span>
                      </div>
                      <p class="mt-0.5 flex items-center gap-1 truncate text-xs text-brand-text-secondary dark:text-white/50">
                        <svg class="h-3.5 w-3.5 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.pin" /></svg>
                        <span class="truncate">{{ a.property?.address }}</span>
                      </p>
                      <p class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs text-brand-navy/70 dark:text-white/65">
                        <AppAvatar :name="a.buyer?.name" :src="a.buyer?.avatar" size="xs" />
                        <span class="font-semibold text-brand-navy dark:text-white">{{ a.buyer?.name ?? 'Buyer' }}</span>
                        <!-- The buyer's track record, so a request can be judged before it's confirmed -->
                        <span
                          v-if="a.buyer?.reliability?.has_enough"
                          class="rounded-full px-1.5 py-0.5 text-[0.625rem] font-bold"
                          :class="(a.buyer.reliability.rate ?? 100) >= 80 ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400'"
                          :title="`Kept ${a.buyer.reliability.kept} of ${a.buyer.reliability.kept + a.buyer.reliability.missed} confirmed viewings`"
                        >{{ a.buyer.reliability.rate }}% kept</span>
                        <span v-else class="text-[0.625rem] text-brand-navy/40 dark:text-white/35">New to viewings</span>
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
                      >{{ i === 0 && a.status === 'pending' && at(a) >= now ? 'Waiting for you' : s }}</span>
                    </li>
                  </ol>

                  <p v-if="a.notes" class="mt-3 rounded-xl bg-brand-cream/80 dark:bg-white/[0.04] px-3 py-2 text-xs italic text-brand-navy/65 dark:text-white/55 line-clamp-2">“{{ a.notes }}”</p>
                  <p v-if="at(a) < now" class="mt-3 rounded-xl bg-amber-500/[0.08] px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                    {{ a.status === 'pending' ? 'This request was never answered and its time has passed. Decline it to clear it.' : 'Did the viewing happen? Mark it as done so the buyer can rate you.' }}
                  </p>

                  <div class="mt-4 flex flex-wrap items-center gap-2">
                    <!-- Pending, still ahead: answer it -->
                    <template v-if="a.status === 'pending' && at(a) >= now">
                      <button type="button" :class="btnGold" :disabled="busyId === a.id" @click="emit('confirm', a)">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.check" /></svg>
                        Confirm
                      </button>
                      <button type="button" :class="btnGhost" @click="messageBuyer(a)">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.chat" /></svg>
                        Suggest another time
                      </button>
                    </template>
                    <!-- Confirmed, time passed: close it -->
                    <button v-else-if="a.status === 'confirmed' && at(a) < now" type="button" :class="btnGold" :disabled="busyId === a.id" @click="emit('complete', a)">
                      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.check" /></svg>
                      Mark as done
                    </button>
                    <!-- Confirmed, ahead: get there -->
                    <template v-else-if="a.status === 'confirmed'">
                      <a v-if="directions(a)" :href="directions(a)!" target="_blank" rel="noopener" :class="btnGhost">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.map" /></svg>
                        Directions
                      </a>
                      <button type="button" :class="btnGhost" @click="messageBuyer(a)">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.chat" /></svg>
                        Message buyer
                      </button>
                      <span v-if="synced(a)" class="inline-flex items-center gap-1 px-1 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.check" /></svg>
                        In Google Calendar
                      </span>
                    </template>

                    <button
                      type="button"
                      class="ml-auto inline-flex items-center rounded-full px-3.5 py-1.5 text-xs font-bold text-red-600 hover:bg-red-500/10 dark:text-red-400 transition disabled:opacity-50"
                      :disabled="busyId === a.id"
                      @click="emit('cancel', a)"
                    >{{ a.status === 'pending' ? 'Decline' : 'Cancel viewing' }}</button>
                  </div>
                </article>
              </li>
            </ol>
          </section>
        </div>

        <!-- Tab with nothing in it -->
        <div v-else-if="total" class="rounded-2xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] px-6 py-10 text-center">
          <p class="font-semibold text-brand-navy dark:text-white">
            {{ tab === 'requests' ? 'No requests waiting' : tab === 'upcoming' ? 'Nothing confirmed yet' : 'All caught up' }}
          </p>
          <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/50">
            {{ tab === 'requests' ? 'You’ve answered every request.' : tab === 'upcoming' ? 'Confirmed viewings show up here.' : 'Every past viewing is closed.' }}
          </p>
        </div>

        <!-- ── Nothing booked ── -->
        <div v-if="!total" class="relative overflow-hidden rounded-3xl p-6 sm:p-8 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]">
          <div class="absolute -right-10 -top-10 h-44 w-44 rounded-full bg-brand-gold/10 blur-2xl pointer-events-none" />
          <div class="relative flex items-center gap-4">
            <span class="h-12 w-12 flex-none rounded-2xl flex items-center justify-center bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold">
              <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICON.cal" /></svg>
            </span>
            <div class="min-w-0">
              <h2 class="font-display text-xl font-bold text-brand-navy dark:text-white">No viewings booked right now</h2>
              <p class="mt-1 max-w-md text-sm text-brand-text-secondary dark:text-white/55">Buyers book from your listings. Here's how to get more requests.</p>
            </div>
          </div>
          <ol class="relative mt-5 grid grid-cols-1 sm:grid-cols-3 gap-3">
            <li v-for="(s, i) in [['Keep listings fresh', 'Clear photos and a 360° view get booked more.'], ['Keep your calendar right', 'Block the days you’re away so buyers pick times that work.'], ['Answer quickly', 'Confirm requests and reply to messages within the day.']]" :key="i"
                class="rounded-2xl p-4 bg-brand-cream/70 ring-1 ring-brand-navy/[0.05] dark:bg-white/[0.04] dark:ring-white/[0.06]">
              <span class="h-6 w-6 rounded-full flex items-center justify-center text-[0.6875rem] font-bold bg-brand-navy text-white dark:bg-brand-gold dark:text-brand-navy">{{ i + 1 }}</span>
              <p class="mt-2.5 text-sm font-semibold text-brand-navy dark:text-white">{{ s[0] }}</p>
              <p class="mt-0.5 text-xs leading-relaxed text-brand-navy/55 dark:text-white/50">{{ s[1] }}</p>
            </li>
          </ol>
          <div class="relative mt-6 flex flex-wrap gap-2">
            <NuxtLink to="/dashboard/listings" class="inline-flex items-center rounded-full px-4 py-2 text-xs font-bold bg-brand-navy text-white hover:-translate-y-0.5 dark:bg-brand-gold dark:text-brand-navy transition">Go to my listings</NuxtLink>
            <NuxtLink to="/dashboard/calendar" :class="btnGhost">Set my availability</NuxtLink>
            <NuxtLink to="/dashboard/history" :class="btnGhost">Past viewings</NuxtLink>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
.appt-rise { animation: appt-rise 0.5s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes appt-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
.appt-pulse { animation: appt-pulse 2s ease-in-out infinite; }
@keyframes appt-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.55; } }
@media (prefers-reduced-motion: reduce) { .appt-rise, .appt-pulse { animation: none; } }
</style>
