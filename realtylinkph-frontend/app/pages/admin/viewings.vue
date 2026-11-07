<script setup lang="ts">
definePageMeta({ layout: 'dashboard' })

/*
 * Admin → Viewings: who is cancelling late or not turning up, and what to do
 * about it. Warn, pause, lift a pause, or waive a single strike. Every action
 * tells the person (in the app and by email) and is kept in Activity.
 * The numbers come from the same rules the booking uses, so this page and the
 * lockout can never disagree.
 */
const authStore = useAuthStore()
if (!authStore.isAdmin) {
  throw createError({ statusCode: 403, statusMessage: 'Forbidden' })
}

interface Row {
  id: number; name: string; email: string; avatar: string | null; role_type: 'buyer' | 'agent'
  strikes: number; strike_limit: number; window_days: number
  locked_until: string | null; locked_by: 'admin' | 'strikes' | null
  reliability: { kept: number; missed: number; rate: number | null; has_enough: boolean }
  last_incident_at: string | null; warnings: number
}
interface Incident {
  appointment_id: number; kind: 'late_cancellation' | 'no_show'; weight: number; property: string | null
  slot: string | null; occurred_at: string | null; reason: string | null; reported_by: string | null
  other_party: string | null; waived: boolean; waived_at: string | null; waived_by: string | null; counts_now: boolean
}
interface Sanction {
  id: number; type: 'warning' | 'pause' | 'lift' | 'waive'; reason: string; days: number | null
  until: string | null; lifted_at: string | null; admin: string | null; property: string | null; created_at: string
}
type Tab = 'flagged' | 'paused' | 'all'

const api = useApi()
const toast = useToast()

const tab = ref<Tab>('flagged')
const role = ref('')
const search = ref('')
const page = ref(1)
const rows = ref<Row[]>([])
const counts = ref({ flagged: 0, paused: 0, all: 0 })
const meta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const res = await api.get<{ data: Row[]; meta: typeof meta.value; counts: typeof counts.value }>('/admin/viewings', {
      filter: tab.value, role: role.value || undefined, search: search.value || undefined, page: page.value,
    })
    rows.value = res.data
    meta.value = res.meta
    counts.value = res.counts
  } catch {
    toast.error('Couldn’t load the list. Please try again.')
  } finally {
    loading.value = false
  }
}
await load()

const TABS: Array<{ key: Tab; label: string }> = [
  { key: 'flagged', label: 'Needs attention' },
  { key: 'paused', label: 'Paused' },
  { key: 'all', label: 'Everyone' },
]
const ROLES = [{ key: '', label: 'All' }, { key: 'buyer', label: 'Buyers' }, { key: 'agent', label: 'Agents' }]
function setTab(t: Tab) { if (t === tab.value) return; tab.value = t; page.value = 1; load() }
function setRole(r: string) { role.value = r; page.value = 1; load() }
let timer: ReturnType<typeof setTimeout> | undefined
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => { page.value = 1; load() }, 300) })

/* ── Formatting ── */
const fmt = (iso: string | null, long = false) => iso
  ? new Date(iso).toLocaleString('en-PH', long
    ? { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }
    : { month: 'short', day: 'numeric' })
  : ''
const roleBadge: Record<string, string> = {
  agent: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
  buyer: 'bg-blue-500/10 text-blue-700 dark:text-blue-300',
}
function pipClass(r: Row, i: number): string {
  if (i >= r.strikes) return 'bg-brand-navy/10 dark:bg-white/15'
  return r.strikes >= r.strike_limit ? 'bg-red-500' : r.strikes >= 3 ? 'bg-orange-500' : 'bg-amber-400'
}

/* ── Drawer ── */
const open = ref<Row | null>(null)
const incidents = ref<Incident[]>([])
const sanctions = ref<Sanction[]>([])
const pauseDays = ref<number[]>([3, 7, 14, 30])
const detailLoading = ref(false)

async function openRow(r: Row) {
  open.value = r
  mode.value = null
  await loadDetail()
}
async function loadDetail() {
  if (!open.value) return
  detailLoading.value = true
  try {
    const res = await api.get<{ data: { user: Row; incidents: Incident[]; sanctions: Sanction[]; pause_days: number[] } }>(`/admin/viewings/${open.value.id}`)
    open.value = res.data.user
    incidents.value = res.data.incidents
    sanctions.value = res.data.sanctions
    pauseDays.value = res.data.pause_days
  } catch {
    toast.error('Couldn’t load this record.')
  } finally {
    detailLoading.value = false
  }
}
function closeDrawer() { open.value = null; mode.value = null }

/* ── Actions: warn / pause / lift, one form at a time ── */
const mode = ref<null | 'warn' | 'pause' | 'lift'>(null)
const message = ref('')
const days = ref(7)
const sending = ref(false)
const failed = ref('')
const MIN = 10

const WARN_PRESETS = ['Please keep to the viewing times you confirm.', 'A late cancellation or no-show wastes the other person’s day.']
const PAUSE_PRESETS = ['Repeated late cancellations.', 'Reported no-shows.']
function pick(p: string) { if (!message.value.includes(p)) message.value = message.value.trim() ? `${message.value.trim()} ${p}` : p }
function startMode(m: 'warn' | 'pause' | 'lift') { mode.value = m; message.value = ''; days.value = 7; failed.value = '' }
const needsText = computed(() => mode.value === 'warn' || mode.value === 'pause')
const canSend = computed(() => !sending.value && (!needsText.value || message.value.trim().length >= MIN))

async function sendAction() {
  if (!open.value || !mode.value || !canSend.value) return
  const m = mode.value
  const body = m === 'warn' ? { message: message.value.trim() }
    : m === 'pause' ? { days: days.value, reason: message.value.trim() }
      : { reason: message.value.trim() || undefined }
  sending.value = true
  failed.value = ''
  try {
    await api.post(`/admin/viewings/${open.value.id}/${m}`, body)
    const first = open.value.name.split(' ')[0]
    toast.success(m === 'warn' ? `Warning sent to ${first}` : m === 'pause' ? `${first}’s viewings are paused for ${days.value} days` : `Pause lifted. ${first} has been told`)
    mode.value = null
    await Promise.all([loadDetail(), load()])
  } catch (e) {
    failed.value = (e as { data?: { message?: string } })?.data?.message ?? 'That didn’t go through. Please try again.'
  } finally {
    sending.value = false
  }
}

/* ── Waive one strike ── */
const waiveFor = ref<Incident | null>(null)
const waiveReason = ref('')
const waiving = ref(false)
const waiveFailed = ref(false)
function startWaive(i: Incident) { waiveFor.value = i; waiveReason.value = ''; waiveFailed.value = false }
async function submitWaive() {
  if (!waiveFor.value || waiveReason.value.trim().length < MIN || waiving.value) return
  waiving.value = true
  waiveFailed.value = false
  try {
    await api.post(`/admin/viewings/appointments/${waiveFor.value.appointment_id}/waive`, { reason: waiveReason.value.trim() })
    toast.success(`Strike removed. ${open.value?.name.split(' ')[0] ?? 'They'} have been told`)
    waiveFor.value = null
    await Promise.all([loadDetail(), load()])
  } catch {
    waiveFailed.value = true
  } finally {
    waiving.value = false
  }
}

const SANCTION_LABEL: Record<Sanction['type'], string> = { warning: 'Warning sent', pause: 'Paused', lift: 'Pause lifted', waive: 'Strike waived' }
const SANCTION_DOT: Record<Sanction['type'], string> = { warning: 'bg-amber-500', pause: 'bg-red-500', lift: 'bg-emerald-500', waive: 'bg-emerald-500' }

const fieldClass = 'w-full resize-none rounded-xl border-0 bg-brand-navy/[0.04] dark:bg-white/[0.06] px-4 py-3 text-sm text-brand-navy dark:text-white placeholder-brand-navy/35 dark:placeholder-white/35 ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold'
</script>

<template>
  <div class="page-shell">
    <!-- ── Header ── -->
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
      <header>
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Manage</p>
        <h1 class="mt-1.5 font-display text-2xl cw-sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">Viewing behaviour</h1>
        <p class="mt-1.5 max-w-2xl text-sm text-brand-text-secondary dark:text-white/55">
          Late cancellations count as 1 strike and reported no-shows as 2. Five in 30 days pauses booking for a week.
          Warn, pause, lift or forgive here, and the person is told each time.
        </p>
      </header>

      <div class="inline-flex max-w-full items-center gap-0.5 cw-sm:gap-1 rounded-full p-1 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10" role="tablist">
        <button
          v-for="t in TABS"
          :key="t.key"
          type="button"
          role="tab"
          :aria-selected="tab === t.key"
          class="inline-flex items-center gap-1 cw-sm:gap-1.5 whitespace-nowrap rounded-full px-2.5 cw-sm:px-4 py-1.5 text-[0.8125rem] cw-sm:text-sm font-semibold transition"
          :class="tab === t.key ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C]' : 'text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white'"
          @click="setTab(t.key)"
        >
          {{ t.label }}
          <span class="rounded-full px-1.5 text-xs tabular-nums" :class="tab === t.key ? 'bg-white/20 dark:bg-[#0B2A5C]/15' : t.key === 'paused' && counts.paused ? 'bg-red-500/15 text-red-700 dark:text-red-400' : 'opacity-60'">{{ counts[t.key] }}</span>
        </button>
      </div>
    </div>

    <!-- ── Search + role ── -->
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <div class="relative min-w-[14rem] flex-1">
        <svg class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-brand-navy/40 dark:text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" /></svg>
        <input
          v-model="search"
          type="search"
          placeholder="Search by name or email"
          aria-label="Search people"
          class="w-full rounded-full border-0 bg-white dark:bg-white/[0.06] pl-10 pr-4 py-2.5 text-sm text-brand-navy dark:text-white placeholder-brand-navy/40 dark:placeholder-white/35 ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold"
        />
      </div>
      <div class="inline-flex items-center gap-1 rounded-full p-1 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10">
        <button
          v-for="r in ROLES"
          :key="r.key"
          type="button"
          class="rounded-full px-3.5 py-1.5 text-sm font-semibold transition"
          :class="role === r.key ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C]' : 'text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white'"
          @click="setRole(r.key)"
        >{{ r.label }}</button>
      </div>
    </div>

    <!-- ── List ── -->
    <div v-if="loading && !rows.length" class="space-y-2.5"><AppSkeleton v-for="i in 4" :key="i" width="100%" height="76px" rounded="lg" /></div>

    <div v-else-if="!rows.length" class="relative overflow-hidden rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] px-6 py-14 text-center">
      <span class="mx-auto mb-4 h-14 w-14 rounded-2xl bg-emerald-500/10 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
      </span>
      <p class="font-display text-xl font-bold text-brand-navy dark:text-white">{{ search ? 'Nobody matches that' : tab === 'paused' ? 'Nobody is paused' : tab === 'flagged' ? 'Everyone is keeping their viewings' : 'No viewing records yet' }}</p>
      <p class="mx-auto mt-2 max-w-sm text-sm text-brand-text-secondary dark:text-white/55">
        {{ search ? 'Try a different name or email.' : 'Late cancellations and reported no-shows show up here as they happen.' }}
      </p>
    </div>

    <ul v-else class="space-y-2.5" :class="loading ? 'opacity-60 transition-opacity' : ''">
      <li v-for="r in rows" :key="r.id">
        <button
          type="button"
          class="w-full grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3.5 gap-y-2.5 rounded-2xl p-3.5 text-left bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] hover:ring-brand-gold/60 transition cw-md:grid-cols-[auto_minmax(0,1fr)_13rem_10rem_12rem]"
          @click="openRow(r)"
        >
          <AppAvatar :name="r.name" :src="r.avatar" size="md" />

          <span class="min-w-0">
            <span class="flex flex-wrap items-center gap-1.5">
              <span class="truncate text-sm font-semibold text-brand-navy dark:text-white">{{ r.name }}</span>
              <span class="rounded-full px-2 py-0.5 text-[0.5625rem] font-bold uppercase" :class="roleBadge[r.role_type]">{{ r.role_type }}</span>
            </span>
            <span class="block truncate text-xs text-brand-navy/50 dark:text-white/45">{{ r.email }}</span>
          </span>

          <!-- Strikes -->
          <span class="col-start-2 cw-md:col-start-auto">
            <span class="flex items-center gap-1" :aria-label="`${r.strikes} of ${r.strike_limit} strikes`">
              <span v-for="i in r.strike_limit" :key="i" class="h-2 w-6 rounded-full" :class="pipClass(r, i - 1)" />
            </span>
            <span class="mt-1 block text-xs text-brand-navy/60 dark:text-white/55">
              <span class="font-semibold tabular-nums text-brand-navy dark:text-white">{{ r.strikes }}</span> of {{ r.strike_limit }} strikes
              <template v-if="r.last_incident_at"> · last {{ fmt(r.last_incident_at) }}</template>
            </span>
          </span>

          <!-- Reliability -->
          <span class="hidden cw-md:block text-xs text-brand-navy/60 dark:text-white/55">
            <template v-if="r.reliability.has_enough"><span class="text-base font-bold tabular-nums text-brand-navy dark:text-white">{{ r.reliability.rate }}%</span> reliable</template>
            <template v-else>Not enough viewings yet</template>
          </span>

          <!-- Status -->
          <span class="col-start-2 cw-md:col-start-auto cw-md:justify-self-end">
            <span v-if="r.locked_until" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-red-500/10 px-2.5 py-1 text-xs font-bold text-red-700 dark:text-red-300">
              <span class="h-1.5 w-1.5 rounded-full bg-red-500" />Paused · until {{ fmt(r.locked_until) }}
            </span>
            <span v-else-if="r.strikes >= 3" class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/10 px-2.5 py-1 text-xs font-bold text-amber-800 dark:text-amber-300">
              <span class="h-1.5 w-1.5 rounded-full bg-amber-500" />Close to limit
            </span>
            <span v-else class="text-xs text-brand-navy/40 dark:text-white/35">OK</span>
          </span>
        </button>
      </li>
    </ul>

    <!-- Pages -->
    <nav v-if="meta.last_page > 1" class="mt-5 flex items-center justify-center gap-2" aria-label="Pages">
      <button type="button" class="rounded-full px-4 py-2 text-sm font-semibold ring-1 ring-brand-navy/15 text-brand-navy dark:text-white dark:ring-white/20 disabled:opacity-40" :disabled="page <= 1" @click="page--; load()">Previous</button>
      <span class="text-sm tabular-nums text-brand-navy/60 dark:text-white/55">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
      <button type="button" class="rounded-full px-4 py-2 text-sm font-semibold ring-1 ring-brand-navy/15 text-brand-navy dark:text-white dark:ring-white/20 disabled:opacity-40" :disabled="page >= meta.last_page" @click="page++; load()">Next</button>
    </nav>

    <!-- ── Waive one strike ── -->
    <AppDialog
      :open="!!waiveFor"
      title="Waive this strike?"
      subtitle="It stops counting, and the person is told."
      tone="warning"
      icon="calendar"
      :busy="waiving"
      size="md"
      @close="waiveFor = null"
    >
      <form v-if="waiveFor" @submit.prevent="submitWaive">
        <div class="rounded-2xl p-3 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.05] dark:ring-white/10">
          <p class="truncate text-sm font-semibold text-brand-navy dark:text-white">{{ waiveFor.property ?? 'Viewing' }}</p>
          <p class="mt-0.5 text-xs text-brand-navy/55 dark:text-white/50">
            {{ waiveFor.kind === 'no_show' ? 'Reported no-show' : 'Late cancellation' }} · {{ fmt(waiveFor.occurred_at, true) }}
            <template v-if="waiveFor.reported_by"> · reported by {{ waiveFor.reported_by }}</template>
          </p>
          <p v-if="waiveFor.reason" class="mt-1.5 text-xs italic text-brand-navy/65 dark:text-white/60">“{{ waiveFor.reason }}”</p>
        </div>
        <label for="wv-reason" class="mb-1.5 mt-4 block text-sm font-semibold text-brand-navy dark:text-white">Why are you removing it? <span class="text-red-500">*</span></label>
        <textarea id="wv-reason" v-model="waiveReason" rows="3" maxlength="500" placeholder="For example: they showed proof of an emergency." :class="fieldClass" />
        <div class="mt-1 flex items-start justify-between gap-3">
          <p class="text-xs text-brand-navy/55 dark:text-white/50">Sent to the person and kept in Activity.</p>
          <span class="flex-none text-xs tabular-nums" :class="waiveReason.trim().length >= MIN ? 'text-emerald-600 dark:text-emerald-400' : 'text-brand-navy/40 dark:text-white/35'">{{ waiveReason.trim().length }}/500</span>
        </div>
        <p v-if="waiveFailed" class="mt-4 rounded-2xl bg-red-500/[0.08] px-4 py-3 text-sm text-red-700 ring-1 ring-red-500/20 dark:text-red-300" role="alert">That didn’t go through. Please try again.</p>
      </form>
      <template #footer>
        <button type="button" :class="DIALOG_BTN.cancel" :disabled="waiving" @click="waiveFor = null">Keep it</button>
        <button type="button" :class="DIALOG_BTN.primary" :disabled="waiveReason.trim().length < MIN || waiving" @click="submitWaive">{{ waiving ? 'Removing…' : 'Waive strike' }}</button>
      </template>
    </AppDialog>

    <!-- ── Person drawer ── -->
    <Teleport to="body">
      <Transition name="drawer">
        <div v-if="open" class="fixed inset-0 z-[70] flex justify-end" role="dialog" aria-modal="true" :aria-label="`Viewing record for ${open.name}`">
          <div class="absolute inset-0 bg-[#06183A]/50 backdrop-blur-sm" @click="closeDrawer" />

          <aside class="drawer-panel relative flex h-full w-full min-w-0 max-w-lg flex-col overflow-hidden bg-[#ffffff] dark:bg-[#0B1A35] shadow-2xl">
            <div class="flex items-start gap-3.5 border-b border-brand-navy/[0.07] dark:border-white/[0.08] px-5 py-4 pr-14">
              <AppAvatar :name="open.name" :src="open.avatar" size="lg" />
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <h2 class="truncate font-display text-lg font-bold text-brand-navy dark:text-white">{{ open.name }}</h2>
                  <span class="rounded-full px-2 py-0.5 text-[0.5625rem] font-bold uppercase" :class="roleBadge[open.role_type]">{{ open.role_type }}</span>
                </div>
                <p class="truncate text-sm text-brand-navy/55 dark:text-white/50">{{ open.email }}</p>
              </div>
              <button type="button" class="absolute right-3 top-3 h-9 w-9 rounded-full flex items-center justify-center text-brand-navy/60 hover:bg-brand-navy/[0.06] dark:text-white/60 dark:hover:bg-white/10 transition" aria-label="Close" @click="closeDrawer">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
              </button>
            </div>

            <div class="min-w-0 flex-1 space-y-5 overflow-y-auto overflow-x-hidden overscroll-contain px-5 py-5 dark:[color-scheme:dark]">
              <!-- Where they stand -->
              <div v-if="open.locked_until" class="rounded-2xl bg-red-500/[0.07] ring-1 ring-red-400/30 px-4 py-3">
                <p class="text-sm font-bold text-red-700 dark:text-red-300">Paused until {{ fmt(open.locked_until, true) }}</p>
                <p class="mt-0.5 text-xs text-red-700/80 dark:text-red-300/80">
                  {{ open.locked_by === 'admin' ? 'An admin paused this person.' : 'Automatic: five strikes in 30 days.' }}
                  {{ open.role_type === 'agent' ? 'Their listings take no new requests and they cannot confirm new ones.' : 'They cannot book new viewings.' }}
                </p>
              </div>

              <div class="grid grid-cols-3 gap-2 [&>*]:min-w-0">
                <div class="rounded-2xl bg-brand-cream/70 dark:bg-white/[0.05] px-2 py-3 text-center">
                  <p class="text-2xl font-bold tabular-nums text-brand-navy dark:text-white">{{ open.strikes }}<span class="text-sm font-semibold text-brand-navy/40 dark:text-white/40">/{{ open.strike_limit }}</span></p>
                  <p class="text-[0.6875rem] text-brand-navy/55 dark:text-white/50">strikes (30 days)</p>
                </div>
                <div class="rounded-2xl bg-brand-cream/70 dark:bg-white/[0.05] px-2 py-3 text-center">
                  <p class="text-2xl font-bold tabular-nums text-brand-navy dark:text-white">{{ open.reliability.has_enough ? open.reliability.rate + '%' : '–' }}</p>
                  <p class="text-[0.6875rem] text-brand-navy/55 dark:text-white/50">reliable</p>
                </div>
                <div class="rounded-2xl bg-brand-cream/70 dark:bg-white/[0.05] px-2 py-3 text-center">
                  <p class="text-2xl font-bold tabular-nums text-brand-navy dark:text-white">{{ open.warnings }}</p>
                  <p class="text-[0.6875rem] text-brand-navy/55 dark:text-white/50">warnings</p>
                </div>
              </div>

              <!-- Actions -->
              <section>
                <p class="mb-2 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">What would you like to do?</p>
                <div v-if="!mode" class="flex flex-wrap gap-2">
                  <button type="button" class="rounded-full px-4 py-2 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 transition" @click="startMode('warn')">Send a warning</button>
                  <button type="button" class="rounded-full px-4 py-2 text-sm font-semibold text-red-600 ring-1 ring-red-300 hover:bg-red-500/10 dark:text-red-400 dark:ring-red-400/40 transition" @click="startMode('pause')">Pause bookings</button>
                  <button v-if="open.locked_until" type="button" class="rounded-full bg-brand-navy px-4 py-2 text-sm font-bold text-white dark:bg-brand-gold dark:text-[#0B2A5C] transition" @click="startMode('lift')">Lift the pause</button>
                </div>

                <form v-else class="rounded-2xl p-4 ring-1 ring-brand-navy/10 dark:ring-white/10 bg-brand-cream/40 dark:bg-white/[0.03]" @submit.prevent="sendAction">
                  <p class="text-sm font-bold text-brand-navy dark:text-white">
                    {{ mode === 'warn' ? 'Send a warning' : mode === 'pause' ? 'Pause bookings' : 'Lift the pause' }}
                  </p>
                  <p class="mt-0.5 text-xs text-brand-navy/55 dark:text-white/50">
                    {{ mode === 'warn' ? 'They get this as a notification and an email.'
                      : mode === 'pause' ? (open.role_type === 'agent' ? 'Their listings stop taking new requests and they cannot confirm new ones.' : 'They cannot book new viewings. Confirmed ones are not affected.')
                        : 'They can book and confirm viewings again, and the automatic count starts fresh.' }}
                  </p>

                  <div v-if="mode === 'pause'" class="mt-3 flex flex-wrap gap-1.5" role="radiogroup" aria-label="Length of the pause">
                    <button
                      v-for="d in pauseDays"
                      :key="d"
                      type="button"
                      role="radio"
                      :aria-checked="days === d"
                      class="rounded-full px-3.5 py-1.5 text-sm font-semibold ring-1 transition"
                      :class="days === d ? 'bg-brand-navy text-white ring-brand-navy dark:bg-brand-gold dark:text-[#0B2A5C] dark:ring-brand-gold' : 'text-brand-navy/75 ring-brand-navy/15 hover:ring-brand-gold dark:text-white/70 dark:ring-white/20'"
                      @click="days = d"
                    >{{ d }} days</button>
                  </div>

                  <div v-if="mode !== 'lift'" class="mt-3 flex flex-wrap gap-1.5">
                    <button v-for="p in (mode === 'warn' ? WARN_PRESETS : PAUSE_PRESETS)" :key="p" type="button" class="rounded-full px-3 py-1 text-xs font-semibold ring-1 transition" :class="message.includes(p) ? 'bg-amber-500/10 text-amber-800 ring-amber-400/40 dark:text-amber-300' : 'text-brand-navy/70 ring-brand-navy/10 hover:ring-brand-gold dark:text-white/65 dark:ring-white/15'" @click="pick(p)">{{ p }}</button>
                  </div>

                  <label for="vw-text" class="mb-1.5 mt-3 block text-sm font-semibold text-brand-navy dark:text-white">
                    {{ mode === 'warn' ? 'Message' : 'Reason' }}
                    <span v-if="needsText" class="text-red-500">*</span>
                    <span v-else class="text-xs font-normal text-brand-navy/50 dark:text-white/45">(optional)</span>
                  </label>
                  <textarea id="vw-text" v-model="message" rows="3" maxlength="500" :class="fieldClass" :placeholder="mode === 'lift' ? 'For example: talked it through, fresh start.' : 'Be specific. The person reads this.'" />
                  <div v-if="needsText" class="mt-1 text-right text-xs tabular-nums" :class="message.trim().length >= MIN ? 'text-emerald-600 dark:text-emerald-400' : 'text-brand-navy/40 dark:text-white/35'">{{ message.trim().length }}/500</div>

                  <p v-if="failed" class="mt-3 rounded-2xl bg-red-500/[0.08] px-4 py-3 text-sm text-red-700 ring-1 ring-red-500/20 dark:text-red-300" role="alert">{{ failed }}</p>

                  <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="rounded-full px-4 py-2 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 dark:text-white dark:ring-white/20" :disabled="sending" @click="mode = null">Cancel</button>
                    <button type="submit" class="rounded-full px-5 py-2 text-sm font-bold transition disabled:opacity-50" :class="mode === 'pause' ? 'bg-red-600 text-white hover:bg-red-700' : 'bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C]'" :disabled="!canSend">
                      {{ sending ? 'Sending…' : mode === 'warn' ? 'Send warning' : mode === 'pause' ? `Pause for ${days} days` : 'Lift pause' }}
                    </button>
                  </div>
                </form>
              </section>

              <!-- Incidents -->
              <section>
                <p class="mb-2 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">Strikes</p>
                <div v-if="detailLoading && !incidents.length"><AppSkeleton width="100%" height="64px" rounded="lg" /></div>
                <p v-else-if="!incidents.length" class="rounded-2xl bg-brand-cream/60 dark:bg-white/[0.04] px-4 py-4 text-sm text-brand-navy/55 dark:text-white/50">No late cancellations or no-shows on record.</p>
                <ul v-else class="space-y-2.5">
                  <li v-for="i in incidents" :key="i.appointment_id" class="rounded-2xl p-3.5 ring-1" :class="i.waived ? 'ring-brand-navy/[0.07] dark:ring-white/[0.08] opacity-70' : 'ring-brand-navy/10 dark:ring-white/10'">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                      <span class="inline-flex items-center gap-1.5 text-sm font-bold" :class="i.kind === 'no_show' ? 'text-red-700 dark:text-red-300' : 'text-amber-800 dark:text-amber-300'">
                        <span class="h-2 w-2 rounded-full" :class="i.kind === 'no_show' ? 'bg-red-500' : 'bg-amber-500'" />
                        {{ i.kind === 'no_show' ? 'No-show' : 'Late cancellation' }}
                        <span class="rounded-full bg-brand-navy/[0.06] px-1.5 text-[0.6875rem] font-semibold text-brand-navy/60 dark:bg-white/10 dark:text-white/55">{{ i.weight }} {{ i.weight === 1 ? 'strike' : 'strikes' }}</span>
                      </span>
                      <span class="text-xs text-brand-navy/50 dark:text-white/45">{{ fmt(i.occurred_at, true) }}</span>
                    </div>
                    <p class="mt-1 truncate text-sm font-semibold text-brand-navy dark:text-white">{{ i.property ?? 'Viewing' }}</p>
                    <p class="text-xs text-brand-navy/55 dark:text-white/50">
                      Viewing {{ fmt(i.slot, true) }}<template v-if="i.other_party"> · with {{ i.other_party }}</template><template v-if="i.reported_by"> · reported by {{ i.reported_by }}</template>
                    </p>
                    <p v-if="i.reason" class="mt-1.5 text-xs italic text-brand-navy/65 dark:text-white/60">“{{ i.reason }}”</p>
                    <div class="mt-2.5 flex items-center justify-between gap-2">
                      <span v-if="i.waived" class="text-xs font-semibold text-emerald-700 dark:text-emerald-400">Waived<template v-if="i.waived_by"> by {{ i.waived_by }}</template></span>
                      <span v-else-if="!i.counts_now" class="text-xs text-brand-navy/45 dark:text-white/40">Older than 30 days. No longer counts.</span>
                      <span v-else class="text-xs text-brand-navy/55 dark:text-white/50">Counts toward the limit</span>
                      <button v-if="!i.waived" type="button" class="rounded-full px-3 py-1.5 text-xs font-bold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 transition" @click="startWaive(i)">Waive</button>
                    </div>
                  </li>
                </ul>
              </section>

              <!-- History of admin actions -->
              <section v-if="sanctions.length">
                <p class="mb-2 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">What admins have done</p>
                <ul class="space-y-3">
                  <li v-for="s in sanctions" :key="s.id" class="flex gap-3">
                    <span class="mt-1.5 h-2.5 w-2.5 flex-none rounded-full" :class="SANCTION_DOT[s.type]" />
                    <div class="min-w-0 text-sm">
                      <p class="font-semibold text-brand-navy dark:text-white">
                        {{ SANCTION_LABEL[s.type] }}<template v-if="s.type === 'pause' && s.days"> for {{ s.days }} days</template>
                        <span class="font-normal text-brand-navy/50 dark:text-white/45"> · {{ fmt(s.created_at, true) }}<template v-if="s.admin"> · {{ s.admin }}</template></span>
                      </p>
                      <p v-if="s.property" class="text-xs text-brand-navy/55 dark:text-white/50">{{ s.property }}</p>
                      <p class="mt-0.5 text-xs text-brand-navy/70 dark:text-white/65">{{ s.reason }}</p>
                      <p v-if="s.type === 'pause' && s.lifted_at" class="text-xs text-emerald-700 dark:text-emerald-400">Lifted {{ fmt(s.lifted_at, true) }}</p>
                    </div>
                  </li>
                </ul>
              </section>
            </div>
          </aside>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.drawer-enter-active, .drawer-leave-active { transition: opacity 0.25s ease; }
.drawer-enter-active .drawer-panel, .drawer-leave-active .drawer-panel { transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1); }
.drawer-enter-from, .drawer-leave-to { opacity: 0; }
.drawer-enter-from .drawer-panel, .drawer-leave-to .drawer-panel { transform: translateX(100%); }
@media (prefers-reduced-motion: reduce) {
  .drawer-enter-active .drawer-panel, .drawer-leave-active .drawer-panel { transition: none; }
}
</style>
