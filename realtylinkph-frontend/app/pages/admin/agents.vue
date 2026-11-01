<script setup lang="ts">
import type { AgentProfile, VerificationStatus } from '~/types'

definePageMeta({ layout: 'dashboard' })

/*
 * Agent applications, as a review queue:
 *   header → Pending / Approved / Rejected tabs
 *   → the queue (left: oldest first, with how long each has waited)
 *   → the selected application (right), summary first, then evidence:
 *       who + the decision → RealtyLink AI at a glance (confidence, red flags)
 *       → selfie and ID photo side by side → the admin's checklist
 *       → the full AI report → official links.
 * Approving or rejecting moves straight on to the next in line.
 * Layout follows the room <main> really has (cw-*), not the screen, so it
 * holds up with the sidebar and a panel open.
 */
const authStore = useAuthStore()
if (!authStore.isAdmin) {
  throw createError({ statusCode: 403, statusMessage: 'Forbidden' })
}

const { fetchApplications } = useAgent()
const toast = useToast()
const ask = useConfirm()

const tab = ref<VerificationStatus>('pending')
const items = ref<AgentProfile[]>([])
const counts = ref<Record<VerificationStatus, number>>({ pending: 0, approved: 0, rejected: 0 })
const loading = ref(false)
const selectedId = ref<number | null>(null)

async function load(quiet = false) {
  if (!quiet) loading.value = true
  const res = await fetchApplications(tab.value)
  if (res) {
    items.value = res.items
    counts.value = res.counts
  }
  // Keep the selection while it's still in the list; otherwise the first one.
  if (!items.value.some(a => a.id === selectedId.value)) selectedId.value = items.value[0]?.id ?? null
  loading.value = false
}
await load()

function setTab(t: VerificationStatus) {
  if (t === tab.value) return
  tab.value = t
  selectedId.value = null
  load()
}

const selected = computed(() => items.value.find(a => a.id === selectedId.value) ?? null)

const reviewEl = ref<HTMLElement | null>(null)
function select(a: AgentProfile) {
  selectedId.value = a.id
  // When the review sits under the queue, bring it into view.
  const main = reviewEl.value?.closest('main')
  if (import.meta.client && !main?.classList.contains('cw-lg')) nextTick(() => reviewEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
}

/* ── AI assessment arrives asynchronously ──
 * The pre-check is a queued job, so a freshly submitted application shows up
 * here before its assessment exists. While any is still waiting, refetch
 * quietly every few seconds; the polling stops once nothing is pending. */
const anyAiPending = computed(() => items.value.some(a => a.ai_pending))
let aiPoll: ReturnType<typeof setInterval> | null = null
watch(anyAiPending, (pending) => {
  if (pending && !aiPoll) aiPoll = setInterval(() => load(true), 5000)
  else if (!pending && aiPoll) { clearInterval(aiPoll); aiPoll = null }
}, { immediate: true })
onUnmounted(() => { if (aiPoll) clearInterval(aiPoll) })

/* ── Labels ── */
const isBroker = (a: AgentProfile) => a.applicant_type === 'broker'
const typeLabel = (a: AgentProfile) => (isBroker(a) ? 'Broker' : a.applicant_type === 'salesperson' ? 'Salesperson' : 'Agent')
const numberLabel = (a: AgentProfile) => (isBroker(a) ? 'PRC no.' : 'Accreditation no.')
function daysSince(iso?: string | null): number {
  return iso ? Math.max(0, Math.floor((Date.now() - +new Date(iso)) / 86400000)) : 0
}
function waited(iso?: string | null): string {
  const d = daysSince(iso)
  return d === 0 ? 'Applied today' : d === 1 ? 'Applied yesterday' : `Applied ${d} days ago`
}
const longDate = (iso?: string | null) => (iso ? new Date(iso).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' }) : '')
const shortDate = (iso?: string | null) => (iso ? new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) : '')

const oldestWait = computed(() => (tab.value === 'pending' && items.value[0] ? daysSince(items.value[0].created_at) : 0))

async function copyNumber(a: AgentProfile) {
  try {
    await navigator.clipboard.writeText(a.prc_number)
    toast.success(`${isBroker(a) ? 'PRC' : 'Accreditation'} number copied`)
  } catch {
    toast.error('Couldn’t copy. Select the number instead.')
  }
}

const TABS: Array<{ key: VerificationStatus; label: string }> = [
  { key: 'pending', label: 'Pending' }, { key: 'approved', label: 'Approved' }, { key: 'rejected', label: 'Rejected' },
]

/* ── Documents ── */
interface Doc { key: string; label: string; url: string; isPdf: boolean }
const isPdf = (url: string) => /\.pdf(\?|$)/i.test(url)
function docsFor(agent: AgentProfile): Doc[] {
  const list: Array<[string, string, string | null | undefined]> = [
    ['face_image',        'Live selfie',   agent.face_image],
    ['license_doc',       'License card',  agent.license_doc],
    ['valid_id',          'Valid ID',      agent.valid_id],
    ['accreditation_doc', 'Accreditation', agent.accreditation_doc],
  ]
  return list
    .filter((d): d is [string, string, string] => !!d[2])
    .map(([key, label, url]) => ({ key, label, url, isPdf: isPdf(url) }))
}
/* The two to hold side by side: the selfie, and the document carrying the
 * applicant's photo (a broker's license card; a salesperson's valid ID). */
const docs = computed(() => (selected.value ? docsFor(selected.value) : []))
const selfie = computed(() => docs.value.find(d => d.key === 'face_image') ?? null)
const idDoc = computed(() => docs.value.find(d => d.key === 'license_doc') ?? docs.value.find(d => d.key === 'valid_id') ?? docs.value.find(d => d.key !== 'face_image') ?? null)
const otherDocs = computed(() => docs.value.filter(d => d !== selfie.value && d !== idDoc.value))
const pair = computed(() => [selfie.value, idDoc.value].filter((d): d is Doc => !!d))

/* ── RealtyLink AI, read for the summary ──
 * The assessment always uses the same headings (see AgentCredentialReference
 * on the server): Details read, Match with application, Photo match,
 * Concerns, Confidence. Split it into sections, then read each for a verdict.
 * Rendered with text interpolation only — never v-html — so model output
 * can't inject anything. */
interface AiSection { title: string; lines: Array<{ bullet: boolean; text: string }> }
function aiSections(raw: string): AiSection[] {
  const out: AiSection[] = []
  for (const l of raw.split('\n')) {
    const s = l.trim()
    if (!s) continue
    // "**Heading**", "**Heading:** text" or "**Confidence** — High, because …"
    const h = s.match(/^\*\*(.+?)\*\*:?\s*(?:[—–-]\s*)?(.*)$/)
    if (h) {
      out.push({ title: h[1]!.replace(/:$/, '').trim(), lines: [] })
      if (h[2]) out[out.length - 1]!.lines.push({ bullet: false, text: h[2].replace(/\*\*/g, '') })
      continue
    }
    if (!out.length) out.push({ title: 'Notes', lines: [] })
    const b = s.match(/^[*•-]\s+(.+)$/)
    out[out.length - 1]!.lines.push({ bullet: !!b, text: (b ? b[1]! : s).replace(/\*\*/g, '') })
  }
  return out
}
const sections = computed(() => (selected.value?.ai_comment ? aiSections(selected.value.ai_comment) : []))
const sectionText = (name: RegExp) => sections.value.filter(s => name.test(s.title)).flatMap(s => s.lines.map(l => l.text)).join(' ')

type FlagState = 'ok' | 'bad' | 'unknown'
interface Flag { key: string; label: string; state: FlagState; detail: string }
const NOT_SEEN = /could not verify|not legible|unable to|can(?:no|')t (?:see|read|tell)|unclear|not visible/i
const confidence = computed<'High' | 'Medium' | 'Low' | null>(() => {
  const m = sectionText(/confidence/i).match(/\b(High|Medium|Low)\b/i)
  return m ? (m[1]!.charAt(0).toUpperCase() + m[1]!.slice(1).toLowerCase()) as 'High' | 'Medium' | 'Low' : null
})
const flags = computed<Flag[]>(() => {
  if (!sections.value.length) return []
  const photo = sectionText(/photo|face/i)
  const match = sectionText(/match with/i)
  const concerns = sectionText(/concern|red flag/i)
  const list: Flag[] = []
  if (photo) list.push({
    key: 'photo', detail: photo,
    ...(/dissimilar|\bnot\b[^.]{0,30}\bsame\b|different (?:person|people)|does not (?:match|resemble)|doesn't (?:match|resemble)|no resemblance/i.test(photo)
      ? { label: 'Photo doesn’t match', state: 'bad' as const }
      : NOT_SEEN.test(photo) ? { label: 'Photo not checked', state: 'unknown' as const }
        : /similar|same person|consistent|resembl|match/i.test(photo) ? { label: 'Photo matches', state: 'ok' as const }
          : { label: 'Photo: see report', state: 'unknown' as const }),
  })
  if (match) list.push({
    key: 'match', detail: match,
    ...(/CONCERN|does not match|doesn't match|mismatch|differ|incorrect/i.test(match)
      ? { label: 'Name or number differs', state: 'bad' as const }
      : NOT_SEEN.test(match) ? { label: 'Name and number not checked', state: 'unknown' as const }
        : /match|CONFIRMED|consistent|same/i.test(match) ? { label: 'Name and number match', state: 'ok' as const }
          : { label: 'Details: see report', state: 'unknown' as const }),
  })
  if (concerns) {
    const none = /^(none|no concerns?|n\/a|nothing)\b/i.test(concerns.trim())
    const n = sections.value.find(s => /concern|red flag/i.test(s.title))?.lines.filter(l => l.bullet).length ?? 0
    list.push({ key: 'concerns', detail: concerns, label: none ? 'No concerns raised' : n > 1 ? `${n} concerns raised` : 'A concern was raised', state: none ? 'ok' : 'bad' })
  }
  // Red flags first, then what couldn't be checked, then what's fine.
  const rank = { bad: 0, unknown: 1, ok: 2 }
  return list.sort((a, b) => rank[a.state] - rank[b.state])
})
const redFlags = computed(() => flags.value.filter(f => f.state === 'bad'))
const isConcern = (title: string) => /concern|red flag/i.test(title)

/* ── The admin's own checks ──
 * A reminder, not a gate: Approve still works, but asks once more if a box
 * is unticked or the AI flagged something. Reset for each application. */
const checks = reactive({ photo: false, details: false, prc: false })
watch(selectedId, () => { checks.photo = false; checks.details = false; checks.prc = false })
const CHECKS = computed(() => selected.value
  ? [
      { key: 'photo' as const, label: 'The selfie looks like the photo on the ' + (idDoc.value?.label.toLowerCase() ?? 'ID') },
      { key: 'details' as const, label: 'The name and number on the document match the application' },
      { key: 'prc' as const, label: `The ${isBroker(selected.value) ? 'PRC' : 'accreditation'} number checks out on the official site` },
    ]
  : [])

/* ── Decide ── */
const decision = ref<'approve' | 'reject' | null>(null)
async function startApprove() {
  const open = CHECKS.value.filter(c => !checks[c.key]).map(c => `Not ticked: ${c.label.charAt(0).toLowerCase()}${c.label.slice(1)}`)
  const flagged = redFlags.value.map(f => `RealtyLink AI: ${f.label.toLowerCase()}`)
  if (open.length || flagged.length) {
    const ok = await ask({
      title: 'Approve anyway?',
      message: 'Some checks are still open. You can approve now or go back and look first.',
      consequences: [...flagged, ...open],
      confirmLabel: 'Approve anyway',
      cancelLabel: 'Go back',
      tone: 'warning',
      icon: 'warning',
    })
    if (!ok) return
  }
  decision.value = 'approve'
}
async function onDecided() {
  decision.value = null
  // The decided one leaves the Pending list; the next in line is selected.
  const index = items.value.findIndex(a => a.id === selectedId.value)
  await load(true)
  selectedId.value = items.value[Math.min(index, items.value.length - 1)]?.id ?? null
}

/* "Check later" is set from the approve dialog; taken off here. */
const api = useApi()
async function releaseHold(a: AgentProfile) {
  try {
    await api.del(`/admin/agent-applications/${a.id}/hold`)
    toast.success('Hold removed')
    await load(true)
  } catch {
    toast.error('Couldn’t remove the hold. Please try again.')
  }
}

/* ── Document viewer ──
 * Full screen, with zoom. "Compare" puts the selfie and the ID photo side by
 * side at the same zoom. PDFs show in the viewer too (the browser renders
 * them), with "Open original" as the way out if a browser won't. */
const viewer = ref<{ docs: Doc[]; index: number; name: string; compare: boolean } | null>(null)
const zoom = ref(1)
const ZOOMS = [1, 1.5, 2, 3]
function openDoc(doc: Doc) {
  const list = docs.value
  viewer.value = { docs: list, index: Math.max(0, list.indexOf(doc)), name: selected.value?.user?.name ?? 'Applicant', compare: false }
  zoom.value = 1
}
function openCompare() {
  if (!selfie.value || !idDoc.value) return
  viewer.value = { docs: docs.value, index: docs.value.indexOf(idDoc.value), name: selected.value?.user?.name ?? 'Applicant', compare: true }
  zoom.value = 1
}
const viewerDoc = computed(() => viewer.value?.docs[viewer.value.index] ?? null)
const comparePair = computed(() => (viewer.value?.compare && selfie.value && idDoc.value ? [selfie.value, idDoc.value] : []))
function viewerStep(delta: number) {
  if (!viewer.value || viewer.value.compare) return
  const n = viewer.value.docs.length
  viewer.value.index = (viewer.value.index + delta + n) % n
  zoom.value = 1
}
function zoomBy(step: number) {
  const i = ZOOMS.indexOf(zoom.value)
  zoom.value = ZOOMS[Math.min(ZOOMS.length - 1, Math.max(0, (i < 0 ? 0 : i) + step))]!
}
const toggleZoom = () => { zoom.value = zoom.value === 1 ? 2 : 1 }
const imgStyle = computed(() => (zoom.value > 1 ? { width: `${zoom.value * 100}%`, maxWidth: 'none', maxHeight: 'none' } : undefined))
function onViewerKey(e: KeyboardEvent) {
  if (!viewer.value) return
  if (e.key === 'ArrowRight') viewerStep(1)
  if (e.key === 'ArrowLeft')  viewerStep(-1)
  if (e.key === '+' || e.key === '=') zoomBy(1)
  if (e.key === '-') zoomBy(-1)
  if (e.key === 'Escape')     viewer.value = null
}
onMounted(() => window.addEventListener('keydown', onViewerKey))
onUnmounted(() => window.removeEventListener('keydown', onViewerKey))

const FLAG_STYLE: Record<FlagState, string> = {
  bad: 'bg-red-500/10 text-red-700 ring-red-400/30 dark:text-red-300',
  unknown: 'bg-amber-500/10 text-amber-800 ring-amber-400/30 dark:text-amber-300',
  ok: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300',
}
const CONF_STYLE: Record<string, string> = {
  High: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
  Medium: 'bg-amber-500/15 text-amber-800 dark:text-amber-300',
  Low: 'bg-red-500/15 text-red-700 dark:text-red-300',
}
</script>

<template>
  <div class="page-shell">
    <!-- ── Header, with the tabs beside it to save height ── -->
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
      <header>
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Manage</p>
        <h1 class="mt-1.5 font-display text-2xl cw-sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">Agent applications</h1>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
          <template v-if="counts.pending">
            <span class="font-semibold text-brand-navy dark:text-white">{{ counts.pending }}</span> waiting for a decision<template v-if="oldestWait > 1">
              · the oldest has waited <span class="font-semibold text-brand-navy dark:text-white">{{ oldestWait }} days</span></template>.
          </template>
          <template v-else>No applications waiting. You're all caught up.</template>
        </p>
      </header>

      <div class="inline-flex items-center gap-1 rounded-full p-1 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10" role="tablist">
        <button
          v-for="t in TABS"
          :key="t.key"
          type="button"
          role="tab"
          :aria-selected="tab === t.key"
          class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-semibold transition"
          :class="tab === t.key ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C]' : 'text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white'"
          @click="setTab(t.key)"
        >
          {{ t.label }}
          <span class="rounded-full px-1.5 text-xs tabular-nums"
                :class="tab === t.key ? 'bg-white/20 dark:bg-[#0B2A5C]/15' : t.key === 'pending' && counts.pending ? 'bg-brand-gold/20 text-brand-gold-deep dark:text-brand-gold' : 'opacity-60'">{{ counts[t.key] }}</span>
        </button>
      </div>
    </div>

    <div v-if="loading" class="grid grid-cols-1 gap-6 cw-lg:grid-cols-[17rem_minmax(0,1fr)]">
      <div class="space-y-2"><AppSkeleton v-for="i in 3" :key="i" width="100%" height="72px" rounded="lg" /></div>
      <AppSkeleton width="100%" height="420px" rounded="lg" />
    </div>

    <!-- ── Nothing here ── -->
    <div v-else-if="!items.length" class="relative overflow-hidden rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] px-6 py-16 text-center">
      <div class="absolute -top-16 left-1/2 -translate-x-1/2 h-48 w-80 rounded-full bg-brand-gold/10 blur-3xl pointer-events-none" />
      <span class="relative mx-auto mb-5 h-16 w-16 rounded-2xl bg-brand-gold/15 flex items-center justify-center text-brand-gold-deep dark:text-brand-gold">
        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>
      </span>
      <p class="relative font-display text-xl font-bold text-brand-navy dark:text-white">
        {{ tab === 'pending' ? 'All caught up' : tab === 'approved' ? 'No approved agents yet' : 'Nothing rejected' }}
      </p>
      <p class="relative mx-auto mt-2 max-w-sm text-sm text-brand-text-secondary dark:text-white/55 leading-relaxed">
        {{ tab === 'pending' ? 'New applications show up here as soon as someone applies. RealtyLink AI checks their documents first.' : 'Decisions you make on the Pending tab are kept here.' }}
      </p>
    </div>

    <div v-else class="grid grid-cols-1 items-start gap-6 cw-lg:grid-cols-[17rem_minmax(0,1fr)]">
      <!-- ── Queue: stays in view beside the review on wide layouts ── -->
      <ul class="space-y-2 cw-lg:sticky cw-lg:top-24 cw-lg:max-h-[calc(100vh-8rem)] cw-lg:overflow-y-auto cw-lg:p-0.5" :aria-label="`${tab} applications`">
        <li v-for="a in items" :key="a.id">
          <button
            type="button"
            class="w-full flex items-center gap-3 rounded-2xl p-3 text-left ring-1 transition"
            :class="a.id === selectedId
              ? 'bg-white ring-2 ring-brand-gold shadow-[0_10px_30px_-18px_rgba(212,165,74,0.8)] dark:bg-[#0B1A35]'
              : 'bg-white ring-brand-navy/[0.07] hover:ring-brand-gold/60 dark:bg-[#0B1A35] dark:ring-white/[0.08]'"
            :aria-current="a.id === selectedId ? 'true' : undefined"
            @click="select(a)"
          >
            <AppAvatar :name="a.user?.name" :src="a.user?.avatar" size="md" />
            <span class="min-w-0 flex-1">
              <span class="flex items-center gap-1.5">
                <span class="truncate text-sm font-semibold text-brand-navy dark:text-white">{{ a.user?.name ?? 'Applicant' }}</span>
                <span class="flex-none rounded-full px-1.5 py-0.5 text-[0.5625rem] font-bold uppercase"
                      :class="isBroker(a) ? 'bg-brand-navy/[0.08] text-brand-navy dark:bg-white/10 dark:text-white' : 'bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold'">{{ typeLabel(a) }}</span>
              </span>
              <span v-if="tab === 'pending'" class="mt-0.5 flex items-center gap-1.5 text-xs" :class="daysSince(a.created_at) > 3 ? 'text-amber-700 dark:text-amber-400 font-semibold' : 'text-brand-navy/50 dark:text-white/45'">
                {{ waited(a.created_at) }}
              </span>
              <span v-else class="mt-0.5 block truncate text-xs text-brand-navy/50 dark:text-white/45">
                {{ tab === 'approved' ? 'Approved' : 'Rejected' }} {{ shortDate(a.reviewed_at) }}<template v-if="a.reviewed_by"> · {{ a.reviewed_by }}</template>
              </span>
              <span v-if="tab === 'pending' && a.hold_note" class="mt-1 inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-[0.6875rem] font-semibold text-amber-700 dark:text-amber-400" :title="a.hold_note">On hold · check later</span>
              <span v-else-if="tab === 'pending'" class="mt-1 flex items-center gap-1 text-[0.6875rem]" :class="a.ai_pending ? 'text-brand-navy/45 dark:text-white/40' : 'text-emerald-700 dark:text-emerald-400'">
                <span v-if="a.ai_pending" class="h-2.5 w-2.5 rounded-full border-2 border-brand-gold/30 border-t-brand-gold animate-spin" aria-hidden="true" />
                <svg v-else class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                {{ a.ai_pending ? 'AI checking documents…' : 'AI check ready' }}
              </span>
            </span>
          </button>
        </li>
      </ul>

      <!-- ── Review ── -->
      <section v-if="selected" ref="reviewEl" class="min-w-0 scroll-mt-24 space-y-5 rounded-3xl p-5 cw-md:p-6 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]">
        <!-- 1. Who, and the decision -->
        <div class="flex flex-wrap items-start justify-between gap-4">
          <!-- Wide enough that, when room is short, the buttons move to their own row instead of squeezing the name -->
          <div class="flex min-w-[min(100%,21rem)] flex-1 items-start gap-3.5">
            <AppAvatar :name="selected.user?.name" :src="selected.user?.avatar" size="lg" />
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-display text-xl font-bold leading-tight text-brand-navy dark:text-white">{{ selected.user?.name }}</h2>
                <span class="rounded-full px-2 py-0.5 text-[0.625rem] font-bold uppercase"
                      :class="isBroker(selected) ? 'bg-brand-navy/[0.08] text-brand-navy dark:bg-white/10 dark:text-white' : 'bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold'">{{ typeLabel(selected) }}</span>
              </div>
              <p class="truncate text-sm text-brand-navy/60 dark:text-white/55">{{ selected.user?.email }}</p>
              <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-brand-cream dark:bg-white/[0.06] pl-3 pr-0.5 py-0.5 ring-1 ring-brand-navy/[0.06] dark:ring-white/10">
                  <span class="text-brand-navy/55 dark:text-white/50">{{ numberLabel(selected) }}</span>
                  <span class="font-semibold tabular-nums text-brand-navy dark:text-white">{{ selected.prc_number }}</span>
                  <button type="button" class="h-7 w-7 rounded-full flex items-center justify-center text-brand-navy/50 hover:text-brand-navy hover:bg-brand-navy/[0.06] dark:text-white/50 dark:hover:text-white dark:hover:bg-white/10" :aria-label="`Copy ${numberLabel(selected)}`" title="Copy" @click="copyNumber(selected)">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75" /></svg>
                  </button>
                </span>
                <span class="whitespace-nowrap rounded-full px-2.5 py-1" :class="tab === 'pending' && daysSince(selected.created_at) > 3 ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400 font-semibold' : 'bg-brand-navy/[0.05] text-brand-navy/60 dark:bg-white/[0.06] dark:text-white/55'">
                  {{ waited(selected.created_at) }} · {{ longDate(selected.created_at) }}
                </span>
                <span v-if="selected.supervising_broker" class="rounded-full px-2.5 py-1 bg-brand-navy/[0.05] text-brand-navy/60 dark:bg-white/[0.06] dark:text-white/55">Broker: {{ selected.supervising_broker }}</span>
              </div>
            </div>
          </div>

          <div v-if="tab === 'pending'" class="flex flex-wrap items-center gap-2">
            <a
              v-if="selected.verification_links?.[0]"
              :href="selected.verification_links[0].url"
              target="_blank"
              rel="noopener"
              class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold transition"
              :title="selected.verification_links[0].note"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
              Verify on {{ isBroker(selected) ? 'PRC' : 'site' }}
            </a>
            <button type="button" class="whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold text-red-600 ring-1 ring-red-300 hover:bg-red-500/10 dark:text-red-400 dark:ring-red-400/40 transition" @click="decision = 'reject'">Reject</button>
            <button type="button" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-brand-gold px-4 py-2 text-sm font-bold text-[#0B2A5C] shadow-[0_4px_14px_rgba(212,165,74,0.45)] transition" @click="startApprove">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
              Approve
            </button>
          </div>
        </div>

        <!-- On hold: the license couldn't be checked yet -->
        <div v-if="tab === 'pending' && selected.hold_note" class="flex flex-wrap items-start justify-between gap-3 rounded-2xl bg-amber-500/[0.07] ring-1 ring-amber-400/30 px-4 py-3">
          <div class="min-w-0">
            <p class="text-xs font-bold uppercase tracking-wide text-amber-700 dark:text-amber-400">
              On hold<template v-if="selected.held_by"> · {{ selected.held_by }}</template><template v-if="selected.held_at"> · {{ shortDate(selected.held_at) }}</template>
            </p>
            <p class="mt-0.5 text-sm text-brand-navy/80 dark:text-white/75">“{{ selected.hold_note }}”</p>
          </div>
          <button type="button" class="flex-none rounded-full px-3 py-1.5 text-xs font-semibold text-amber-800 ring-1 ring-amber-400/50 hover:bg-amber-500/10 dark:text-amber-300" @click="releaseHold(selected)">Remove hold</button>
        </div>

        <!-- How it was decided (history tabs) -->
        <div v-if="tab !== 'pending'" class="rounded-2xl px-4 py-3 ring-1" :class="tab === 'approved' ? 'bg-emerald-500/[0.06] ring-emerald-500/25' : 'bg-red-500/[0.05] ring-red-400/25'">
          <p class="text-sm font-semibold" :class="tab === 'approved' ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'">
            {{ tab === 'approved' ? 'Approved' : 'Rejected' }} on {{ longDate(selected.reviewed_at) }}<template v-if="selected.reviewed_by"> by {{ selected.reviewed_by }}</template>
          </p>
          <p v-if="tab === 'rejected' && selected.admin_note" class="mt-1 text-sm text-brand-navy/80 dark:text-white/75">“{{ selected.admin_note }}”</p>
          <p v-if="tab === 'rejected' && selected.reapply_at" class="mt-1 text-xs text-brand-navy/55 dark:text-white/50">Can apply again from {{ longDate(selected.reapply_at) }}.</p>
        </div>

        <!-- 2. RealtyLink AI at a glance -->
        <div class="rounded-2xl ring-1 ring-brand-gold/30 bg-brand-gold/[0.06] dark:bg-brand-gold/10 p-4">
          <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <img src="/ai/avatar-96.webp" alt="" class="h-8 w-8 flex-none rounded-full object-cover bg-white ring-1 ring-brand-gold/60" />
            <p class="text-sm font-bold text-brand-navy dark:text-white">RealtyLink AI check</p>
            <span v-if="confidence" class="rounded-full px-2.5 py-0.5 text-xs font-bold" :class="CONF_STYLE[confidence]">{{ confidence }} confidence</span>
            <span class="text-xs text-brand-navy/50 dark:text-white/45">Advice only. You decide.</span>
          </div>
          <div v-if="!selected.ai_comment" class="mt-3 flex items-center gap-3 text-sm text-brand-navy/70 dark:text-white/70">
            <span class="relative h-5 w-5 flex-none">
              <span class="absolute inset-0 rounded-full border-2 border-brand-gold/20" />
              <span class="absolute inset-0 rounded-full border-t-2 border-brand-gold animate-spin" />
            </span>
            {{ selected.ai_pending ? 'Reviewing the documents. Usually under a minute; this updates on its own.' : 'No AI check on file for this application.' }}
          </div>
          <ul v-else-if="flags.length" class="mt-3 flex flex-wrap gap-2">
            <li v-for="f in flags" :key="f.key" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-semibold ring-1" :class="FLAG_STYLE[f.state]" :title="f.detail">
              <svg v-if="f.state === 'ok'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
              <svg v-else-if="f.state === 'bad'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
              <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              {{ f.label }}
            </li>
          </ul>
          <p v-if="redFlags.length" class="mt-2.5 text-sm text-red-700 dark:text-red-300">
            <span class="font-semibold">Look closely:</span> {{ redFlags[0]!.detail }}
          </p>
        </div>

        <!-- 3. Identity: selfie and ID photo side by side -->
        <div>
          <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">Compare the photos</p>
            <button v-if="selfie && idDoc" type="button" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 transition" @click="openCompare">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" /></svg>
              Compare full screen
            </button>
          </div>
          <p v-if="!docs.length" class="rounded-2xl bg-brand-cream/70 dark:bg-white/[0.04] px-4 py-6 text-center text-sm text-brand-navy/55 dark:text-white/50">No documents on file.</p>
          <div v-else class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(13rem,1fr))]">
            <button
              v-for="doc in pair"
              :key="doc.key"
              type="button"
              class="group relative overflow-hidden rounded-2xl ring-1 ring-brand-navy/[0.08] dark:ring-white/10 bg-[#0B1A35] hover:ring-brand-gold transition"
              :title="`View ${doc.label} full screen`"
              @click="openDoc(doc)"
            >
              <div class="relative aspect-[4/3]">
                <img v-if="!doc.isPdf" :src="doc.url" :alt="doc.label" class="absolute inset-0 h-full w-full object-contain" loading="lazy" />
                <div v-else class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 text-white/60">
                  <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                  <span class="text-xs font-bold tracking-wide">PDF · tap to open</span>
                </div>
              </div>
              <span class="absolute left-2.5 top-2.5 rounded-full bg-[#06183A]/80 px-2.5 py-1 text-xs font-semibold text-white backdrop-blur-sm">{{ doc.label }}</span>
              <span class="absolute bottom-2.5 right-2.5 inline-flex items-center gap-1 rounded-full bg-black/55 px-2.5 py-1 text-[0.6875rem] font-semibold text-white opacity-80 group-hover:opacity-100 transition" aria-hidden="true">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607zM10.5 7.5v6m3-3h-6" /></svg>
                Zoom
              </span>
            </button>
          </div>
          <!-- Anything else they sent (e.g. a salesperson's accreditation) -->
          <div v-if="otherDocs.length" class="mt-3 flex flex-wrap gap-2">
            <button v-for="doc in otherDocs" :key="doc.key" type="button" class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/10 hover:ring-brand-gold dark:text-white dark:ring-white/15 transition" @click="openDoc(doc)">
              <svg class="h-4 w-4 text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
              {{ doc.label }}<span v-if="doc.isPdf" class="text-xs font-normal text-brand-navy/50 dark:text-white/45">PDF</span>
            </button>
          </div>
        </div>

        <!-- 4. The admin's own checks (a reminder, not a gate) -->
        <div v-if="tab === 'pending'" class="rounded-2xl bg-brand-cream/60 dark:bg-white/[0.04] ring-1 ring-brand-navy/[0.06] dark:ring-white/[0.08] p-4">
          <p class="text-sm font-bold text-brand-navy dark:text-white">Your checks</p>
          <ul class="mt-2 space-y-1">
            <li v-for="c in CHECKS" :key="c.key">
              <label class="flex cursor-pointer items-start gap-3 rounded-xl px-2 py-2 hover:bg-white/70 dark:hover:bg-white/[0.04]">
                <input v-model="checks[c.key]" type="checkbox" class="mt-0.5 h-5 w-5 flex-none rounded border-brand-navy/25 focus:ring-brand-gold dark:border-white/25 dark:bg-white/10
                              checked:!bg-brand-navy dark:checked:!bg-emerald-500 checked:!border-transparent" />
                <span class="text-sm text-brand-navy/80 dark:text-white/75">
                  {{ c.label }}
                  <a v-if="c.key === 'prc' && selected.verification_links?.[0]" :href="selected.verification_links[0].url" target="_blank" rel="noopener" class="ml-1 font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline underline-offset-4" @click.stop>Open site</a>
                </span>
              </label>
            </li>
          </ul>
        </div>

        <!-- 5. The full AI report, in readable sections -->
        <details v-if="sections.length" class="group rounded-2xl ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]" :open="redFlags.length > 0">
          <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-bold text-brand-navy dark:text-white">
            Full AI report
            <svg class="h-4 w-4 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
          </summary>
          <div class="grid gap-3 px-4 pb-4 [grid-template-columns:repeat(auto-fit,minmax(15rem,1fr))]">
            <div
              v-for="s in sections"
              :key="s.title"
              class="rounded-xl p-3.5 text-sm leading-relaxed ring-1"
              :class="isConcern(s.title) && !/^(none|no concerns?)/i.test(s.lines[0]?.text ?? '') ? 'bg-red-500/[0.05] ring-red-400/25' : 'bg-brand-cream/50 ring-brand-navy/[0.05] dark:bg-white/[0.03] dark:ring-white/[0.06]'"
            >
              <p class="font-bold text-brand-navy dark:text-white">{{ s.title }}</p>
              <template v-for="(l, li) in s.lines" :key="li">
                <p v-if="l.bullet" class="mt-1 flex gap-2 text-brand-navy/75 dark:text-white/70"><span class="text-brand-gold flex-shrink-0">•</span><span>{{ l.text }}</span></p>
                <p v-else class="mt-1 text-brand-navy/75 dark:text-white/70">{{ l.text }}</p>
              </template>
            </div>
          </div>
        </details>

        <!-- 6. Official sources, fixed on the server (the AI never makes a link) -->
        <div v-if="selected.verification_links?.length" class="flex flex-wrap items-center gap-2 border-t border-brand-navy/[0.07] dark:border-white/[0.08] pt-4">
          <span class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">Verify against</span>
          <a
            v-for="l in selected.verification_links"
            :key="l.url"
            :href="l.url"
            target="_blank"
            rel="noopener"
            :title="l.note"
            class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-xs font-semibold text-brand-navy ring-1 ring-brand-navy/10 hover:ring-brand-gold hover:text-brand-gold-deep dark:text-white dark:ring-white/15 dark:hover:text-brand-gold transition"
          >
            {{ l.label }}
            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
          </a>
        </div>
      </section>
    </div>

    <AgentDecisionDialog :open="!!decision" :mode="decision ?? 'approve'" :application="selected" @close="decision = null" @done="onDecided" />

    <!-- ── Document viewer: zoom, and side-by-side compare ── -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="viewer && viewerDoc" class="fixed inset-0 z-[60] flex flex-col bg-black/90 backdrop-blur-sm" role="dialog" aria-modal="true" :aria-label="viewer.compare ? 'Compare photos' : viewerDoc.label">
          <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-white">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold">{{ viewer.compare ? 'Selfie vs ' + (idDoc?.label ?? 'ID') : viewerDoc.label }}</p>
              <p class="truncate text-xs text-white/60">{{ viewer.name }}<template v-if="!viewer.compare"> · {{ viewer.index + 1 }} / {{ viewer.docs.length }}</template></p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <button v-if="selfie && idDoc" type="button" class="rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition" :class="viewer.compare ? 'bg-brand-gold text-[#0B2A5C] ring-brand-gold' : 'text-white/85 ring-white/25 hover:text-white'" @click="viewer.compare ? (viewer.compare = false) : openCompare()">Compare</button>
              <div class="flex items-center rounded-full ring-1 ring-white/25">
                <button type="button" class="h-8 w-8 rounded-full text-lg leading-none hover:bg-white/10 disabled:opacity-30" aria-label="Zoom out" :disabled="zoom === ZOOMS[0]" @click="zoomBy(-1)">−</button>
                <span class="w-12 text-center text-xs tabular-nums">{{ Math.round(zoom * 100) }}%</span>
                <button type="button" class="h-8 w-8 rounded-full text-lg leading-none hover:bg-white/10 disabled:opacity-30" aria-label="Zoom in" :disabled="zoom === ZOOMS[ZOOMS.length - 1]" @click="zoomBy(1)">+</button>
              </div>
              <a v-if="!viewer.compare" :href="viewerDoc.url" target="_blank" rel="noopener" class="rounded-full px-3 py-1.5 text-xs font-semibold text-white/85 ring-1 ring-white/25 hover:text-white transition">Open original</a>
              <button type="button" class="h-9 w-9 rounded-full flex items-center justify-center hover:bg-white/10" aria-label="Close" @click="viewer = null">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
              </button>
            </div>
          </div>

          <!-- Compare: the selfie and the ID photo, same zoom, each scrolls on its own -->
          <div v-if="viewer.compare" class="grid min-h-0 flex-1 grid-cols-1 gap-3 px-3 pb-4 sm:grid-cols-2">
            <div v-for="d in comparePair" :key="d.key" class="relative flex min-h-0 flex-col rounded-xl bg-white/[0.04] ring-1 ring-white/10">
              <p class="px-3 py-2 text-xs font-semibold text-white/80">{{ d.label }}</p>
              <div class="flex min-h-0 flex-1 overflow-auto" :class="zoom === 1 ? 'items-center justify-center' : ''">
                <iframe v-if="d.isPdf" :src="d.url" :title="d.label" class="h-full w-full bg-white" />
                <img v-else :src="d.url" :alt="d.label" class="m-auto max-h-full max-w-full object-contain" :class="zoom === 1 ? 'cursor-zoom-in' : 'cursor-zoom-out'" :style="imgStyle" @dblclick="toggleZoom" @click="toggleZoom" />
              </div>
            </div>
          </div>

          <!-- One document -->
          <template v-else>
            <div class="flex min-h-0 flex-1 overflow-auto px-14 pb-4" :class="zoom === 1 ? 'items-center justify-center' : ''" @click.self="viewer = null">
              <iframe v-if="viewerDoc.isPdf" :src="viewerDoc.url" :title="viewerDoc.label" class="h-full w-full max-w-4xl rounded-lg bg-white" />
              <img v-else :src="viewerDoc.url" :alt="viewerDoc.label" class="m-auto max-h-full max-w-full rounded-lg object-contain shadow-2xl" :class="zoom === 1 ? 'cursor-zoom-in' : 'cursor-zoom-out'" :style="imgStyle" @click="toggleZoom" />
            </div>
            <button v-if="viewer.docs.length > 1" type="button" class="absolute left-3 top-1/2 -translate-y-1/2 h-11 w-11 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center" aria-label="Previous document" @click="viewerStep(-1)">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
            </button>
            <button v-if="viewer.docs.length > 1" type="button" class="absolute right-3 top-1/2 -translate-y-1/2 h-11 w-11 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center" aria-label="Next document" @click="viewerStep(1)">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
            </button>
            <div v-if="viewer.docs.length > 1" class="flex justify-center gap-2 px-5 pb-5">
              <button
                v-for="(d, i) in viewer.docs"
                :key="d.key"
                type="button"
                class="h-14 w-20 overflow-hidden rounded-lg border-2 transition-all"
                :class="i === viewer.index ? 'border-brand-gold' : 'border-transparent opacity-60 hover:opacity-100'"
                :title="d.label"
                @click="viewer.index = i; zoom = 1"
              >
                <img v-if="!d.isPdf" :src="d.url" :alt="d.label" class="h-full w-full object-cover" />
                <div v-else class="h-full w-full bg-white/10 text-white/60 text-[0.625rem] font-bold flex items-center justify-center">PDF</div>
              </button>
            </div>
          </template>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
