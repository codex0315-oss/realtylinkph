<script setup lang="ts">
import type { Appointment, BlockedDate } from '~/types'

definePageMeta({ layout: 'dashboard' })

const { fetchMyBlockedDates, blockDate, unblockDate } = useAvailability()
const { appointments, fetchMyAppointments } = useAppointment()
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

async function load() {
  loading.value = true
  blockedDates.value = await fetchMyBlockedDates()
  await fetchMyAppointments()
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
  for (const iso in m) m[iso].sort((x, y) => +new Date(x.preferred_datetime) - +new Date(y.preferred_datetime))
  return m
})

// ── month grid (6 rows × 7 days) ─────────────────────────────────────────────
const monthLabel = computed(() => `${MONTHS[viewMonth.value]} ${viewYear.value}`)

interface Cell { iso: string; day: number; inMonth: boolean; isToday: boolean }
const cells = computed<Cell[]>(() => {
  const firstWeekday = new Date(viewYear.value, viewMonth.value, 1).getDay()
  const out: Cell[] = []
  for (let i = 0; i < 42; i++) {
    const d = new Date(viewYear.value, viewMonth.value, i - firstWeekday + 1)
    out.push({ iso: toISO(d), day: d.getDate(), inMonth: d.getMonth() === viewMonth.value, isToday: toISO(d) === todayISO })
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
function selectDay(c: Cell) {
  selectedISO.value = c.iso
  blockReason.value = ''
  if (!c.inMonth) {           // jump months when clicking a spill-over day
    const d = new Date(c.iso + 'T00:00:00')
    viewYear.value = d.getFullYear(); viewMonth.value = d.getMonth()
  }
}

// ── selected-day state ───────────────────────────────────────────────────────
const selectedDate       = computed(() => new Date(selectedISO.value + 'T00:00:00'))
const selectedFullBlock  = computed(() => dayFullBlock(selectedISO.value))
const selectedTimeBlocks = computed(() => dayTimeBlocks(selectedISO.value))
const selectedViewings = computed(() => viewingsMap.value[selectedISO.value] ?? [])
const selectedIsPast   = computed(() => selectedISO.value < todayISO)

const fmtFull = (d: Date) => d.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
const fmtTime = (iso: string) => new Date(iso).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' })

// Viewings paginate inside the panel (no scrolling) — fit a few per page.
const PAGE_SIZE = 4
const viewingPage = ref(1)
watch(selectedISO, () => { viewingPage.value = 1 })
const totalViewingPages = computed(() => Math.max(1, Math.ceil(selectedViewings.value.length / PAGE_SIZE)))
const pagedViewings = computed(() => selectedViewings.value.slice((viewingPage.value - 1) * PAGE_SIZE, viewingPage.value * PAGE_SIZE))

const blockStart = ref('')
const blockEnd   = ref('')

async function block() {
  busy.value = true
  if (await blockDate(selectedISO.value, blockReason.value || undefined)) { blockReason.value = ''; await load() }
  busy.value = false
}
async function blockTime() {
  if (!blockStart.value || !blockEnd.value || blockEnd.value <= blockStart.value) return
  busy.value = true
  if (await blockDate(selectedISO.value, blockReason.value || undefined, blockStart.value, blockEnd.value)) {
    blockStart.value = ''; blockEnd.value = ''; blockReason.value = ''; await load()
  }
  busy.value = false
}
async function unblock(id: number) {
  busy.value = true
  if (await unblockDate(id)) await load()
  busy.value = false
}

const statusDot: Record<string, string> = { pending: 'bg-amber-500', confirmed: 'bg-emerald-500' }
const statusBadge: Record<string, string> = {
  pending:   'bg-amber-100 text-amber-700',
  confirmed: 'bg-emerald-100 text-emerald-700',
}

/*
 * The block-day inputs carried no background or text colour of their own.
 * Tailwind's preflight makes form controls inherit `color`, so in dark mode
 * they inherited near-white text onto the browser's default white background
 * — white on white, and anything typed was invisible.
 *
 * `[color-scheme:dark]` also makes the native <input type="time"> picker and
 * its clock icon render dark-themed instead of a white popup.
 */
const fieldClass =
  'text-xs rounded-lg border px-3 py-2 transition-colors focus:outline-none focus:border-brand-gold ' +
  'bg-white border-gray-200 text-brand-navy placeholder-gray-400 ' +
  'dark:bg-white/[0.06] dark:border-white/10 dark:text-white dark:placeholder-white/30 dark:[color-scheme:dark]'
</script>

<template>
  <div class="max-w-6xl mx-auto lg:h-[calc(100vh-9rem)] flex flex-col">
    <div class="mb-4 shrink-0">
      <h1 class="font-playfair text-2xl font-bold text-brand-navy">Calendar</h1>
      <p class="text-sm text-gray-500 mt-1">Your viewings and unavailable days. Click any day to block it or see what's scheduled.</p>
    </div>

    <div v-if="loading" class="grid lg:grid-cols-3 gap-6 flex-1 min-h-0">
      <AppSkeleton class="lg:col-span-2" width="100%" height="100%" rounded="lg" />
      <AppSkeleton width="100%" height="100%" rounded="lg" />
    </div>

    <div v-else class="grid lg:grid-cols-3 gap-6 flex-1 min-h-0">
      <!-- ═══════════ Calendar grid ═══════════ -->
      <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 p-5 flex flex-col min-h-0">
        <!-- Month nav -->
        <div class="flex items-center justify-between mb-4 shrink-0">
          <h2 class="text-lg font-bold text-brand-navy">{{ monthLabel }}</h2>
          <div class="flex items-center gap-1">
            <button class="text-xs font-semibold text-gray-500 hover:text-brand-navy px-3 py-1.5 rounded-lg hover:bg-gray-100 transition-colors" @click="goToday">Today</button>
            <button class="h-8 w-8 flex items-center justify-center rounded-lg text-gray-500 hover:text-brand-navy hover:bg-gray-100 transition-colors" aria-label="Previous month" @click="prevMonth">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
            </button>
            <button class="h-8 w-8 flex items-center justify-center rounded-lg text-gray-500 hover:text-brand-navy hover:bg-gray-100 transition-colors" aria-label="Next month" @click="nextMonth">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
            </button>
          </div>
        </div>

        <!-- Weekday header -->
        <div class="grid grid-cols-7 mb-1 shrink-0">
          <div v-for="w in WEEKDAYS" :key="w" class="text-center text-[0.6875rem] font-bold uppercase tracking-wide text-gray-400 py-1">{{ w }}</div>
        </div>

        <!-- Days -->
        <div class="grid grid-cols-7 grid-rows-6 gap-1 flex-1 min-h-0">
          <button
            v-for="c in cells"
            :key="c.iso"
            class="relative rounded-xl border text-left p-1.5 transition-all duration-150 flex flex-col h-full min-h-[44px] overflow-hidden"
            :class="[
              c.iso === selectedISO ? 'border-brand-navy ring-2 ring-brand-navy/15' : 'border-transparent hover:border-gray-200 hover:bg-gray-50',
              !c.inMonth ? 'opacity-35' : '',
              dayFullBlock(c.iso) ? 'bg-red-50' : (dayTimeBlocks(c.iso).length ? 'bg-amber-50' : ''),
            ]"
            @click="selectDay(c)"
          >
            <span
              class="text-xs font-semibold flex items-center justify-center h-6 w-6 rounded-full"
              :class="c.isToday ? 'bg-brand-gold text-brand-navy' : dayFullBlock(c.iso) ? 'text-red-500 line-through' : 'text-brand-navy'"
            >{{ c.day }}</span>

            <!-- viewing dots -->
            <div v-if="viewingsMap[c.iso]?.length" class="mt-auto flex items-center gap-0.5 flex-wrap">
              <span
                v-for="(v, i) in viewingsMap[c.iso].slice(0, 3)"
                :key="v.id"
                class="h-1.5 w-1.5 rounded-full"
                :class="statusDot[v.status] ?? 'bg-gray-400'"
              />
              <span v-if="viewingsMap[c.iso].length > 3" class="text-[0.5625rem] font-bold text-gray-400 leading-none">+{{ viewingsMap[c.iso].length - 3 }}</span>
            </div>

            <!-- blocked mark: full day (✕) or partial time block (clock) -->
            <svg v-if="dayFullBlock(c.iso)" class="absolute top-1.5 right-1.5 h-3 w-3 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" /></svg>
            <svg v-else-if="dayTimeBlocks(c.iso).length" class="absolute top-1.5 right-1.5 h-3 w-3 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          </button>
        </div>

        <!-- Legend -->
        <div class="flex items-center gap-4 mt-4 pt-3 border-t border-gray-100 text-[0.6875rem] text-gray-500 shrink-0">
          <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500" /> Confirmed</span>
          <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-amber-500" /> Pending</span>
          <span class="flex items-center gap-1.5"><svg class="h-3 w-3 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" /></svg> Blocked</span>
          <span class="flex items-center gap-1.5"><svg class="h-3 w-3 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg> Some times blocked</span>
          <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-full bg-brand-gold" /> Today</span>
        </div>
      </div>

      <!-- ═══════════ Day detail panel ═══════════ -->
      <div class="bg-white rounded-2xl border border-gray-200 p-5 flex flex-col min-h-0">
        <p class="text-[0.6875rem] font-bold uppercase tracking-wide text-gray-400">Selected day</p>
        <h3 class="text-base font-bold text-brand-navy mt-0.5">{{ fmtFull(selectedDate) }}</h3>

        <!-- Block status / action -->
        <div class="mt-4">
          <!-- Whole day blocked -->
          <div v-if="selectedFullBlock" class="rounded-xl bg-red-50 border border-red-100 p-3">
            <p class="text-sm font-semibold text-red-600 flex items-center gap-1.5">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
              Whole day blocked
            </p>
            <p v-if="selectedFullBlock.reason" class="text-xs text-red-500/80 mt-1">{{ selectedFullBlock.reason }}</p>
            <button :disabled="busy" class="mt-3 w-full text-xs font-semibold text-red-600 border border-red-200 rounded-lg py-2 hover:bg-red-100/50 transition-colors disabled:opacity-50" @click="unblock(selectedFullBlock.id)">Unblock this day</button>
          </div>

          <div v-else-if="selectedIsPast" class="rounded-xl bg-gray-50 border border-gray-100 p-3 text-xs text-gray-400">
            This day has already passed.
          </div>

          <!-- Available: block whole day or specific time ranges -->
          <div v-else class="space-y-3">
            <!-- Existing time blocks -->
            <div v-if="selectedTimeBlocks.length" class="space-y-1.5">
              <p class="text-[0.6875rem] font-bold uppercase tracking-wide text-gray-400">Blocked times</p>
              <div v-for="tb in selectedTimeBlocks" :key="tb.id" class="flex items-center justify-between gap-2 rounded-lg bg-amber-50 border border-amber-100 px-3 py-1.5">
                <span class="text-xs font-semibold text-amber-700">{{ tb.start_time }} – {{ tb.end_time }}</span>
                <button :disabled="busy" class="text-[0.6875rem] font-semibold text-red-500 hover:underline disabled:opacity-50" @click="unblock(tb.id)">Remove</button>
              </div>
            </div>

            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-3 space-y-2">
              <input
                v-model="blockReason"
                type="text"
                placeholder="Reason (optional) — out of town…"
                class="w-full"
                :class="fieldClass"
              />

              <!-- Block a time range -->
              <div class="flex items-center gap-2">
                <input v-model="blockStart" type="time" class="flex-1 min-w-0 !px-2" :class="fieldClass" />
                <span class="text-xs text-gray-400 dark:text-white/40">to</span>
                <input v-model="blockEnd" type="time" class="flex-1 min-w-0 !px-2" :class="fieldClass" />
              </div>
              <button
                :disabled="busy || !blockStart || !blockEnd || blockEnd <= blockStart"
                class="w-full text-xs font-bold rounded-lg py-2 transition-colors disabled:opacity-50
                       text-amber-700 bg-amber-100 hover:bg-amber-200
                       dark:text-amber-300 dark:bg-amber-500/15 dark:hover:bg-amber-500/25"
                @click="blockTime"
              >Block this time range</button>

              <div class="flex items-center gap-2 pt-1">
                <div class="h-px flex-1 bg-gray-100 dark:bg-white/10" /><span class="text-[0.625rem] text-gray-300 dark:text-white/30 uppercase">or</span><div class="h-px flex-1 bg-gray-100 dark:bg-white/10" />
              </div>

              <button :disabled="busy" class="w-full text-xs font-bold text-brand-navy bg-brand-gold rounded-lg py-2 hover:-translate-y-0.5 transition-all disabled:opacity-50" @click="block">Block the entire day</button>
            </div>
          </div>
        </div>

        <!-- Viewings that day (paginated, never scrolls) -->
        <div class="mt-5 flex-1 min-h-0 flex flex-col">
          <p class="text-[0.6875rem] font-bold uppercase tracking-wide text-gray-400 mb-2 shrink-0">
            Viewings <span class="text-gray-300">({{ selectedViewings.length }})</span>
          </p>
          <p v-if="!selectedViewings.length" class="text-xs text-gray-400">No viewings scheduled.</p>
          <div v-else class="space-y-2 flex-1 min-h-0 overflow-hidden">
            <NuxtLink
              v-for="v in pagedViewings"
              :key="v.id"
              :to="propertyHref(v.property_id)"
              class="block rounded-xl border border-gray-100 p-3 hover:border-brand-gold/40 hover:bg-gray-50 transition-colors"
            >
              <div class="flex items-center justify-between gap-2">
                <span class="text-sm font-bold text-brand-navy">{{ fmtTime(v.preferred_datetime) }}</span>
                <span class="text-[0.625rem] font-bold uppercase px-2 py-0.5 rounded-full capitalize" :class="statusBadge[v.status] ?? 'bg-gray-100 text-gray-500'">{{ v.status }}</span>
              </div>
              <p class="text-xs font-medium text-brand-navy line-clamp-1 mt-1">{{ v.property?.title ?? `Property #${v.property_id}` }}</p>
              <p class="text-[0.6875rem] text-gray-400 mt-0.5">Buyer: {{ v.buyer?.name ?? '—' }}</p>
            </NuxtLink>
          </div>

          <!-- Pager -->
          <div v-if="totalViewingPages > 1" class="flex items-center justify-between pt-3 mt-2 border-t border-gray-100 shrink-0">
            <button
              :disabled="viewingPage === 1"
              class="text-xs font-semibold text-gray-500 px-3 py-1.5 rounded-lg hover:bg-gray-100 disabled:opacity-40 disabled:hover:bg-transparent transition-colors"
              @click="viewingPage--"
            >‹ Prev</button>
            <span class="text-[0.6875rem] text-gray-400">Page {{ viewingPage }} of {{ totalViewingPages }}</span>
            <button
              :disabled="viewingPage === totalViewingPages"
              class="text-xs font-semibold text-gray-500 px-3 py-1.5 rounded-lg hover:bg-gray-100 disabled:opacity-40 disabled:hover:bg-transparent transition-colors"
              @click="viewingPage++"
            >Next ›</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
