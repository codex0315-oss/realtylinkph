<script setup lang="ts">
import type { Appointment, BlockedDate } from '~/types'

definePageMeta({ layout: 'dashboard' })

/*
 * The agent's Calendar, a page they open often:
 *   heading with what's waiting → "Coming up" (the next viewings, one tap away)
 *   → the month: each day shows its viewings and whether it's blocked
 *   → the chosen day: its viewings first, then availability (block a whole
 *     day, a time range, or a quick preset; remove a block).
 */
const { fetchMyBlockedDates, blockDate, unblockDate } = useAvailability()
const { appointments, fetchMyAppointments, confirm } = useAppointment()
const toast = useToast()
const ask = useConfirm()
const propertyHref = usePropertyHref()

const blockedDates = ref<BlockedDate[]>([])
const loading      = ref(false)
const busy         = ref(false)

// ── date helpers (local, no UTC drift) ───────────────────────────────────────
const pad = (n: number) => String(n).padStart(2, '0')
const toISO = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`

const now      = new Date()
const todayISO = toISO(now)

const viewYear  = ref(now.getFullYear())
const viewMonth = ref(now.getMonth())          // 0–11
const selectedISO = ref(todayISO)
const blockReason = ref('')

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const MONTHS   = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']

async function load(quiet = false) {
  if (!quiet) loading.value = true
  const [b] = await Promise.all([fetchMyBlockedDates(), fetchMyAppointments()])
  blockedDates.value = b
  loading.value = false
}
await load()

// ── lookups keyed by ISO date ────────────────────────────────────────────────
// A day can have a whole-day block (no start_time) and/or several time-range blocks.
const blocksByDate = computed<Record<string, BlockedDate[]>>(() => {
  const m: Record<string, BlockedDate[]> = {}
  for (const b of blockedDates.value) (m[b.date] ??= []).push(b)
  return m
})
function dayFullBlock(iso: string): BlockedDate | undefined {
  return blocksByDate.value[iso]?.find(b => !b.start_time)
}
function dayTimeBlocks(iso: string): BlockedDate[] {
  return (blocksByDate.value[iso] ?? [])
    .filter(b => b.start_time)
    .sort((a, b) => (a.start_time ?? '').localeCompare(b.start_time ?? ''))
}

const viewingsMap = computed<Record<string, Appointment[]>>(() => {
  const m: Record<string, Appointment[]> = {}
  for (const a of appointments.value) {
    if (a.status === 'cancelled') continue
    const iso = toISO(new Date(a.preferred_datetime))
    ;(m[iso] ??= []).push(a)
  }
  for (const iso in m) m[iso]!.sort((x, y) => +new Date(x.preferred_datetime) - +new Date(y.preferred_datetime))
  return m
})

/* ── Coming up: the next open viewings ── */
const upcoming = computed(() =>
  appointments.value
    .filter(a => (a.status === 'pending' || a.status === 'confirmed') && +new Date(a.preferred_datetime) >= Date.now() - 36e5)
    .sort((a, b) => +new Date(a.preferred_datetime) - +new Date(b.preferred_datetime)),
)
const pendingCount = computed(() => upcoming.value.filter(a => a.status === 'pending').length)

// ── month grid (6 rows × 7 days) ─────────────────────────────────────────────
const monthLabel = computed(() => `${MONTHS[viewMonth.value]} ${viewYear.value}`)
const monthPrefix = computed(() => `${viewYear.value}-${pad(viewMonth.value + 1)}-`)
const monthViewings = computed(() => Object.entries(viewingsMap.value).filter(([iso]) => iso.startsWith(monthPrefix.value)).reduce((s, [, v]) => s + v.length, 0))
const monthBlockedDays = computed(() => Object.keys(blocksByDate.value).filter(iso => iso.startsWith(monthPrefix.value) && iso >= todayISO).length)

interface Cell { iso: string; day: number; inMonth: boolean; isToday: boolean; isPast: boolean }
/* Only as many week rows as the month needs (4–6), so the grid stays short. */
const weeks = computed(() => {
  const firstWeekday = new Date(viewYear.value, viewMonth.value, 1).getDay()
  const days = new Date(viewYear.value, viewMonth.value + 1, 0).getDate()
  return Math.ceil((firstWeekday + days) / 7)
})
const cells = computed<Cell[]>(() => {
  const firstWeekday = new Date(viewYear.value, viewMonth.value, 1).getDay()
  const out: Cell[] = []
  for (let i = 0; i < weeks.value * 7; i++) {
    const d = new Date(viewYear.value, viewMonth.value, i - firstWeekday + 1)
    const iso = toISO(d)
    out.push({ iso, day: d.getDate(), inMonth: d.getMonth() === viewMonth.value, isToday: iso === todayISO, isPast: iso < todayISO })
  }
  return out
})

function prevMonth() {
  if (viewMonth.value === 0) { viewMonth.value = 11; viewYear.value-- }
  else viewMonth.value--
}
function nextMonth() {
  if (viewMonth.value === 11) { viewMonth.value = 0; viewYear.value++ }
  else viewMonth.value++
}
function goToday() {
  viewYear.value  = now.getFullYear()
  viewMonth.value = now.getMonth()
  selectedISO.value = todayISO
}
function selectISO(iso: string) {
  selectedISO.value = iso
  blockReason.value = ''
  const d = new Date(iso + 'T00:00:00')
  viewYear.value = d.getFullYear(); viewMonth.value = d.getMonth()
}

// ── selected-day state ───────────────────────────────────────────────────────
const selectedDate       = computed(() => new Date(selectedISO.value + 'T00:00:00'))
const selectedFullBlock  = computed(() => dayFullBlock(selectedISO.value))
const selectedTimeBlocks = computed(() => dayTimeBlocks(selectedISO.value))
const selectedViewings   = computed(() => viewingsMap.value[selectedISO.value] ?? [])
const selectedIsPast     = computed(() => selectedISO.value < todayISO)
const dayStatus = computed(() => {
  if (selectedIsPast.value) return { label: 'Past', cls: 'bg-brand-navy/[0.06] text-brand-navy/60 dark:bg-white/10 dark:text-white/60' }
  if (selectedFullBlock.value) return { label: 'Blocked all day', cls: 'bg-red-500/10 text-red-600 dark:text-red-400' }
  if (selectedTimeBlocks.value.length) return { label: 'Partly blocked', cls: 'bg-amber-500/10 text-amber-700 dark:text-amber-400' }
  return { label: 'Open for viewings', cls: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' }
})

const fmtTime = (iso: string) => new Date(iso).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })
const fmtShort = (iso: string) => new Date(iso).toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' })
/** "13:00" → "1:00 PM" */
function hm(t?: string | null): string {
  if (!t) return ''
  const [h, m] = t.split(':').map(Number)
  const d = new Date(); d.setHours(h ?? 0, m ?? 0)
  return d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })
}
function relDay(iso: string): string {
  const d = toISO(new Date(iso))
  if (d === todayISO) return 'Today'
  const t = new Date(now); t.setDate(t.getDate() + 1)
  if (d === toISO(t)) return 'Tomorrow'
  return fmtShort(iso)
}
function calm(t?: string): string {
  if (!t) return 'Property viewing'
  const letters = t.replace(/[^A-Za-z]/g, '')
  return letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
}
function thumb(a: Appointment) {
  const ps = a.property?.photos ?? []
  const p = ps.find(x => !x.is_360) ?? ps[0]
  return p ? (p.thumb_url ?? p.url) : null
}

/* ── Fit the month to the window (desktop), so changing months is ‹ › rather
   than scrolling. The rows share whatever height is left. ── */
const monthEl = ref<HTMLElement | null>(null)
const monthHeight = ref<string | undefined>()
function fitMonth() {
  if (!monthEl.value || window.innerWidth < 1024) { monthHeight.value = undefined; return }
  const top = monthEl.value.getBoundingClientRect().top + window.scrollY
  monthHeight.value = `${Math.max(380, Math.floor(window.innerHeight - top - 24))}px`
}
onMounted(() => { nextTick(fitMonth); window.addEventListener('resize', fitMonth) })
onUnmounted(() => window.removeEventListener('resize', fitMonth))
/* The "Coming up" row above can appear or go once data changes. */
watch(() => upcoming.value.length > 0, () => nextTick(fitMonth))

// ── blocking ─────────────────────────────────────────────────────────────────
const blockStart = ref('')
const blockEnd   = ref('')
const PRESETS = [
  { label: 'Morning',   start: '08:00', end: '12:00' },
  { label: 'Afternoon', start: '13:00', end: '17:00' },
  { label: 'Evening',   start: '17:00', end: '20:00' },
]
const rangeValid = computed(() => !!blockStart.value && !!blockEnd.value && blockEnd.value > blockStart.value)

async function blockWholeDay() {
  if (selectedViewings.value.some(v => v.status !== 'completed')) {
    const ok = await ask({
      title: 'Block a day with viewings?',
      message: 'Blocking stops new bookings on this day. Viewings already booked stay as they are, so cancel or reschedule them yourself if you can’t make it.',
      confirmLabel: 'Block the day',
      tone: 'warning',
      icon: 'calendar',
    })
    if (!ok) return
  }
  busy.value = true
  if (await blockDate(selectedISO.value, blockReason.value || undefined)) {
    blockReason.value = ''
    toast.success('Day blocked. Buyers can’t book it now')
    await load(true)
  } else toast.error('Couldn’t block this day.')
  busy.value = false
}
async function blockTime() {
  if (!rangeValid.value) return
  busy.value = true
  if (await blockDate(selectedISO.value, blockReason.value || undefined, blockStart.value, blockEnd.value)) {
    toast.success(`${hm(blockStart.value)} – ${hm(blockEnd.value)} blocked`)
    blockStart.value = ''; blockEnd.value = ''; blockReason.value = ''
    await load(true)
  } else toast.error('Couldn’t block that time.')
  busy.value = false
}
async function unblock(id: number) {
  busy.value = true
  if (await unblockDate(id)) { toast.success('Unblocked'); await load(true) }
  busy.value = false
}

/* Confirm a pending request right here. */
const confirming = ref<number | null>(null)
async function confirmViewing(a: Appointment) {
  confirming.value = a.id
  if (await confirm(a.id)) { toast.success('Viewing confirmed. The buyer has been notified'); await load(true) }
  else toast.error('Couldn’t confirm it. Please try again.')
  confirming.value = null
}

const STATUS: Record<string, { label: string; chip: string; dot: string; pill: string }> = {
  pending:   { label: 'Pending',   chip: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',     dot: 'bg-amber-500',   pill: 'bg-amber-500/15 text-amber-800 dark:text-amber-300' },
  confirmed: { label: 'Confirmed', chip: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400', dot: 'bg-emerald-500', pill: 'bg-emerald-500/15 text-emerald-800 dark:text-emerald-300' },
  completed: { label: 'Done',      chip: 'bg-brand-navy/[0.06] text-brand-navy/60 dark:bg-white/10 dark:text-white/60', dot: 'bg-gray-400', pill: 'bg-brand-navy/[0.06] text-brand-navy/60 dark:bg-white/10 dark:text-white/60' },
}

/* `[color-scheme:dark]` makes the native time picker render dark in dark mode. */
const fieldClass =
  'w-full rounded-xl border-0 px-3 py-2.5 text-sm text-brand-navy dark:text-white bg-brand-navy/[0.04] dark:bg-white/[0.06] ' +
  'ring-1 ring-brand-navy/10 dark:ring-white/10 placeholder-brand-navy/40 dark:placeholder-white/35 focus:ring-2 focus:ring-brand-gold dark:[color-scheme:dark]'
</script>

<template>
  <div class="max-w-6xl mx-auto">
    <!-- ── Heading ── -->
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div class="min-w-0">
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Schedule</p>
        <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">Calendar</h1>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
          <template v-if="upcoming.length">
            <span class="font-semibold text-brand-navy dark:text-white">{{ upcoming.length }}</span> upcoming {{ upcoming.length === 1 ? 'viewing' : 'viewings' }}<template v-if="pendingCount"> · <span class="font-semibold text-amber-700 dark:text-amber-400">{{ pendingCount }}</span> waiting for you to confirm</template>.
          </template>
          <template v-else>No viewings coming up. Pick a day to block time you're not available.</template>
        </p>
      </div>
      <DashCta to="/dashboard/appointments">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
        All appointments
      </DashCta>
    </header>

    <div v-if="loading" class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_22rem] gap-6">
      <AppSkeleton width="100%" height="560px" rounded="lg" />
      <AppSkeleton width="100%" height="560px" rounded="lg" />
    </div>

    <template v-else>
      <!-- ── Coming up ── -->
      <section v-if="upcoming.length" class="mb-6">
        <h2 class="mb-3 flex items-center gap-2 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/50 dark:text-white/45">
          Coming up
          <span class="h-px flex-1 bg-brand-navy/[0.08] dark:bg-white/10" />
        </h2>
        <div class="-mx-1 px-1 flex gap-3 overflow-x-auto no-scrollbar pb-1">
          <button
            v-for="a in upcoming.slice(0, 6)"
            :key="a.id"
            type="button"
            class="group flex w-64 flex-none items-center gap-3 rounded-2xl p-2.5 pr-3 text-left bg-white dark:bg-[#0B1A35] ring-1 transition"
            :class="toISO(new Date(a.preferred_datetime)) === selectedISO ? 'ring-2 ring-brand-gold' : 'ring-brand-navy/[0.07] dark:ring-white/[0.08] hover:ring-brand-gold/60'"
            @click="selectISO(toISO(new Date(a.preferred_datetime)))"
          >
            <span class="h-14 w-14 flex-none overflow-hidden rounded-xl bg-brand-navy/5 dark:bg-white/10">
              <img v-if="thumb(a)" :src="thumb(a)!" alt="" loading="lazy" class="h-full w-full object-cover" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="flex items-center gap-1.5 text-xs font-bold text-brand-navy dark:text-white">
                <span class="h-1.5 w-1.5 flex-none rounded-full" :class="STATUS[a.status]?.dot" />
                {{ relDay(a.preferred_datetime) }} · {{ fmtTime(a.preferred_datetime) }}
              </span>
              <span class="mt-0.5 block truncate text-sm font-semibold text-brand-navy dark:text-white">{{ calm(a.property?.title) }}</span>
              <span class="block truncate text-xs text-brand-navy/50 dark:text-white/45">{{ a.buyer?.name ?? 'Buyer' }} · {{ STATUS[a.status]?.label }}</span>
            </span>
          </button>
        </div>
      </section>

      <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_22rem] gap-6 items-start">
        <!-- ═══════════ Month ═══════════ -->
        <section
          ref="monthEl"
          class="flex flex-col rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] p-4 sm:p-5"
          :style="monthHeight ? { height: monthHeight } : undefined"
        >
          <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 class="font-display text-xl font-bold text-brand-navy dark:text-white">{{ monthLabel }}</h2>
              <p class="text-xs text-brand-navy/50 dark:text-white/45">
                {{ monthViewings }} {{ monthViewings === 1 ? 'viewing' : 'viewings' }}<template v-if="monthBlockedDays"> · {{ monthBlockedDays }} {{ monthBlockedDays === 1 ? 'day' : 'days' }} with blocks</template>
              </p>
            </div>
            <div class="flex items-center gap-1 rounded-full p-1 bg-brand-navy/[0.04] dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.06] dark:ring-white/10">
              <button type="button" class="h-8 w-8 flex items-center justify-center rounded-full text-brand-navy/60 hover:text-brand-navy hover:bg-white dark:text-white/60 dark:hover:text-white dark:hover:bg-white/10 transition" aria-label="Previous month" @click="prevMonth">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
              </button>
              <button type="button" class="rounded-full px-3.5 py-1.5 text-xs font-bold text-brand-navy hover:bg-white dark:text-white dark:hover:bg-white/10 transition" @click="goToday">Today</button>
              <button type="button" class="h-8 w-8 flex items-center justify-center rounded-full text-brand-navy/60 hover:text-brand-navy hover:bg-white dark:text-white/60 dark:hover:text-white dark:hover:bg-white/10 transition" aria-label="Next month" @click="nextMonth">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
              </button>
            </div>
          </div>

          <!-- Weekdays -->
          <div class="grid grid-cols-7 gap-1.5 mb-1.5">
            <div v-for="w in WEEKDAYS" :key="w" class="py-1 text-center text-[0.6875rem] font-bold uppercase tracking-wider text-brand-navy/45 dark:text-white/40">{{ w }}</div>
          </div>

          <!-- Days -->
          <div class="grid grid-cols-7 gap-1.5 lg:flex-1 lg:min-h-0" :style="{ gridTemplateRows: `repeat(${weeks}, minmax(0, 1fr))` }">
            <button
              v-for="c in cells"
              :key="c.iso"
              type="button"
              class="relative flex min-h-[3.5rem] sm:min-h-[5.75rem] lg:min-h-0 flex-col overflow-hidden rounded-2xl p-1.5 sm:p-2 text-left ring-1 transition"
              :class="[
                c.iso === selectedISO
                  ? 'ring-2 ring-brand-navy dark:ring-brand-gold bg-brand-navy/[0.03] dark:bg-white/[0.06]'
                  : dayFullBlock(c.iso)
                    ? 'ring-red-500/15 day-blocked'
                    : dayTimeBlocks(c.iso).length
                      ? 'ring-amber-500/20 bg-amber-500/[0.05]'
                      : 'ring-brand-navy/[0.05] dark:ring-white/[0.06] hover:ring-brand-gold/60 hover:bg-brand-gold/[0.04]',
                !c.inMonth ? 'opacity-35' : c.isPast && !c.isToday ? 'opacity-60' : '',
              ]"
              :aria-label="`${c.iso}${viewingsMap[c.iso]?.length ? `, ${viewingsMap[c.iso]!.length} viewings` : ''}${dayFullBlock(c.iso) ? ', blocked' : ''}`"
              @click="selectISO(c.iso)"
            >
              <span class="flex items-center justify-between gap-1">
                <span
                  class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold"
                  :class="c.isToday ? 'bg-brand-gold text-[#0B2A5C]' : dayFullBlock(c.iso) ? 'text-red-600 dark:text-red-400' : 'text-brand-navy dark:text-white'"
                >{{ c.day }}</span>
                <!-- Dots always fit, however short the day box is -->
                <span v-if="viewingsMap[c.iso]?.length" class="mr-auto flex gap-0.5" aria-hidden="true">
                  <span v-for="v in viewingsMap[c.iso]!.slice(0, 3)" :key="v.id" class="h-1.5 w-1.5 rounded-full" :class="STATUS[v.status]?.dot ?? 'bg-gray-400'" />
                </span>
                <svg v-if="dayFullBlock(c.iso)" class="h-3.5 w-3.5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
                <svg v-else-if="dayTimeBlocks(c.iso).length" class="h-3.5 w-3.5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              </span>

              <!-- Desktop: what's on the day -->
              <span class="mt-1 hidden sm:flex flex-col gap-1 min-w-0">
                <span v-if="dayFullBlock(c.iso)" class="truncate rounded-md bg-red-500/10 px-1.5 py-0.5 text-[0.625rem] font-semibold text-red-700 dark:text-red-300">Blocked</span>
                <span
                  v-for="v in (viewingsMap[c.iso] ?? []).slice(0, 2)"
                  :key="v.id"
                  class="truncate rounded-md px-1.5 py-0.5 text-[0.625rem] font-semibold"
                  :class="STATUS[v.status]?.pill ?? STATUS.completed!.pill"
                >{{ fmtTime(v.preferred_datetime) }} {{ v.buyer?.name?.split(' ')[0] ?? '' }}</span>
                <span v-if="(viewingsMap[c.iso]?.length ?? 0) > 2" class="text-[0.625rem] font-semibold text-brand-navy/50 dark:text-white/45">+{{ viewingsMap[c.iso]!.length - 2 }} more</span>
                <span v-if="!dayFullBlock(c.iso) && dayTimeBlocks(c.iso).length" class="truncate rounded-md bg-amber-500/10 px-1.5 py-0.5 text-[0.625rem] font-semibold text-amber-800 dark:text-amber-300">Busy {{ hm(dayTimeBlocks(c.iso)[0]!.start_time) }}{{ dayTimeBlocks(c.iso).length > 1 ? ` +${dayTimeBlocks(c.iso).length - 1}` : '' }}</span>
              </span>
            </button>
          </div>

          <!-- Legend -->
          <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-brand-navy/[0.06] dark:border-white/10 pt-3 text-[0.6875rem] text-brand-navy/60 dark:text-white/55">
            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500" /> Confirmed</span>
            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-amber-500" /> Pending</span>
            <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded day-blocked ring-1 ring-red-500/20" /> Blocked day</span>
            <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded bg-amber-500/15 ring-1 ring-amber-500/25" /> Some hours blocked</span>
            <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-full bg-brand-gold" /> Today</span>
          </div>
        </section>

        <!-- ═══════════ Selected day ═══════════ -->
        <aside class="space-y-4 lg:sticky lg:top-24">
          <section class="rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] p-5">
            <div class="flex items-center gap-3">
              <!-- bg-[#ffffff]: the dark-mode shim repaints bg-white navy -->
              <span class="w-14 flex-none overflow-hidden rounded-xl text-center bg-[#ffffff] ring-1 ring-brand-navy/10 shadow-sm">
                <span class="block bg-brand-gold py-0.5 text-[0.625rem] font-bold uppercase tracking-wider text-[#0B2A5C]">{{ MONTHS[selectedDate.getMonth()]!.slice(0, 3) }}</span>
                <span class="block py-1 font-display text-2xl font-bold leading-none text-[#0B2A5C]">{{ selectedDate.getDate() }}</span>
              </span>
              <div class="min-w-0">
                <p class="font-semibold text-brand-navy dark:text-white">{{ selectedDate.toLocaleDateString('en-PH', { weekday: 'long' }) }}<template v-if="selectedISO === todayISO"> · Today</template></p>
                <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold" :class="dayStatus.cls">{{ dayStatus.label }}</span>
              </div>
            </div>

            <!-- Viewings that day -->
            <p class="mt-5 mb-2 text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-brand-navy/50 dark:text-white/45">
              Viewings <span class="opacity-60">{{ selectedViewings.length }}</span>
            </p>
            <p v-if="!selectedViewings.length" class="rounded-2xl bg-brand-cream/70 dark:bg-white/[0.04] px-4 py-3 text-xs text-brand-navy/55 dark:text-white/50">
              Nothing booked{{ selectedIsPast ? '' : ' yet' }}.
            </p>
            <ul v-else class="space-y-2 max-h-72 overflow-y-auto pr-0.5">
              <li v-for="v in selectedViewings" :key="v.id" class="rounded-2xl p-2.5 ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]">
                <div class="flex items-center gap-3">
                  <NuxtLink :to="propertyHref(v.property_id)" class="h-12 w-12 flex-none overflow-hidden rounded-xl bg-brand-navy/5 dark:bg-white/10">
                    <img v-if="thumb(v)" :src="thumb(v)!" alt="" class="h-full w-full object-cover" />
                  </NuxtLink>
                  <div class="min-w-0 flex-1">
                    <p class="flex items-center justify-between gap-2">
                      <span class="text-sm font-bold text-brand-navy dark:text-white">{{ fmtTime(v.preferred_datetime) }}</span>
                      <span class="rounded-full px-2 py-0.5 text-[0.625rem] font-bold" :class="STATUS[v.status]?.chip">{{ STATUS[v.status]?.label ?? v.status }}</span>
                    </p>
                    <p class="truncate text-xs font-medium text-brand-navy/80 dark:text-white/75">{{ calm(v.property?.title) }}</p>
                    <p class="truncate text-[0.6875rem] text-brand-navy/50 dark:text-white/45">{{ v.buyer?.name ?? 'Buyer' }}</p>
                  </div>
                </div>
                <button
                  v-if="v.status === 'pending' && !selectedIsPast"
                  type="button"
                  class="mt-2 w-full rounded-full bg-brand-gold py-1.5 text-xs font-bold text-[#0B2A5C] hover:bg-brand-gold-light transition disabled:opacity-60"
                  :disabled="confirming === v.id"
                  @click="confirmViewing(v)"
                >{{ confirming === v.id ? 'Confirming…' : 'Confirm viewing' }}</button>
              </li>
            </ul>
          </section>

          <!-- Availability -->
          <section class="rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] p-5">
            <p class="flex items-center gap-2 text-sm font-bold text-brand-navy dark:text-white">
              <svg class="h-4 w-4 text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
              Availability
            </p>
            <p class="mt-0.5 text-xs text-brand-navy/50 dark:text-white/45">Buyers can't book blocked days or hours.</p>

            <p v-if="selectedIsPast" class="mt-4 rounded-2xl bg-brand-cream/70 dark:bg-white/[0.04] px-4 py-3 text-xs text-brand-navy/55 dark:text-white/50">This day has passed.</p>

            <!-- Whole day blocked -->
            <div v-else-if="selectedFullBlock" class="mt-4 rounded-2xl bg-red-500/[0.06] ring-1 ring-red-500/20 p-4">
              <p class="text-sm font-semibold text-red-700 dark:text-red-300">Blocked all day</p>
              <p v-if="selectedFullBlock.reason" class="mt-0.5 text-xs text-red-700/80 dark:text-red-300/80">{{ selectedFullBlock.reason }}</p>
              <button type="button" :disabled="busy" class="mt-3 w-full rounded-full py-2 text-xs font-bold text-red-600 ring-1 ring-red-300 hover:bg-red-500/10 dark:text-red-400 dark:ring-red-400/40 transition disabled:opacity-50" @click="unblock(selectedFullBlock.id)">Unblock this day</button>
            </div>

            <template v-else>
              <!-- Existing time blocks -->
              <ul v-if="selectedTimeBlocks.length" class="mt-4 space-y-1.5">
                <li v-for="tb in selectedTimeBlocks" :key="tb.id" class="flex items-center justify-between gap-2 rounded-xl bg-amber-500/[0.08] px-3 py-2">
                  <span class="min-w-0">
                    <span class="block text-xs font-bold text-amber-800 dark:text-amber-300">{{ hm(tb.start_time) }} – {{ hm(tb.end_time) }}</span>
                    <span v-if="tb.reason" class="block truncate text-[0.6875rem] text-amber-800/70 dark:text-amber-300/70">{{ tb.reason }}</span>
                  </span>
                  <button type="button" :disabled="busy" class="flex-none rounded-full px-2.5 py-1 text-[0.6875rem] font-semibold text-red-600 hover:bg-red-500/10 dark:text-red-400 disabled:opacity-50" @click="unblock(tb.id)">Remove</button>
                </li>
              </ul>

              <!-- Block hours -->
              <p class="mt-4 mb-1.5 text-xs font-semibold text-brand-navy dark:text-white">Block some hours</p>
              <div class="flex flex-wrap gap-1.5">
                <button
                  v-for="p in PRESETS"
                  :key="p.label"
                  type="button"
                  class="rounded-full px-3 py-1 text-[0.6875rem] font-semibold ring-1 transition"
                  :class="blockStart === p.start && blockEnd === p.end ? 'bg-brand-gold/15 ring-brand-gold text-brand-navy dark:text-white' : 'ring-brand-navy/15 text-brand-navy/70 hover:ring-brand-gold dark:ring-white/20 dark:text-white/70'"
                  @click="blockStart = p.start; blockEnd = p.end"
                >{{ p.label }}</button>
              </div>
              <div class="mt-2 flex items-center gap-2">
                <input v-model="blockStart" type="time" aria-label="From" :class="fieldClass" />
                <span class="text-xs text-brand-navy/45 dark:text-white/40">to</span>
                <input v-model="blockEnd" type="time" aria-label="Until" :class="fieldClass" />
              </div>
              <p v-if="blockStart && blockEnd && !rangeValid" class="mt-1 text-[0.6875rem] text-red-600 dark:text-red-400">The end time must be after the start time.</p>

              <input v-model="blockReason" type="text" maxlength="120" placeholder="Reason (optional), e.g. out of town" :class="[fieldClass, 'mt-2']" />

              <div class="mt-3 grid grid-cols-2 gap-2">
                <button
                  type="button"
                  :disabled="busy || !rangeValid"
                  class="rounded-full py-2.5 text-xs font-bold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 transition disabled:opacity-40"
                  @click="blockTime"
                >Block hours</button>
                <button
                  type="button"
                  :disabled="busy"
                  class="rounded-full bg-brand-navy py-2.5 text-xs font-bold text-white hover:-translate-y-px dark:bg-brand-gold dark:text-[#0B2A5C] transition disabled:opacity-50"
                  @click="blockWholeDay"
                >Block whole day</button>
              </div>
            </template>
          </section>
        </aside>
      </div>
    </template>
  </div>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
/* Blocked days: soft red diagonal stripes, readable in both themes. */
.day-blocked {
  background-image: repeating-linear-gradient(135deg, rgba(239, 68, 68, 0.09) 0 6px, transparent 6px 12px);
}
</style>
