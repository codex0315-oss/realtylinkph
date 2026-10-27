<script setup lang="ts">
import type { AgentProfile, VerificationStatus } from '~/types'

definePageMeta({ layout: 'dashboard' })

/*
 * Agent applications, as a review queue:
 *   header → Pending / Approved / Rejected tabs
 *   → the queue (left: oldest first, with how long each has waited)
 *   → the selected application (right): who, documents, RealtyLink AI's
 *     advisory check, official links, and the decision.
 * Approving or rejecting moves straight on to the next in line.
 */
const authStore = useAuthStore()
if (!authStore.isAdmin) {
  throw createError({ statusCode: 403, statusMessage: 'Forbidden' })
}

const { fetchApplications } = useAgent()
const toast = useToast()

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

/* ── Fit the review to the window (desktop) ──
 * The queue and the review panel take exactly the height left below the
 * header, so an application can be reviewed without scrolling the page; the
 * AI assessment (and a long queue) scroll inside their own boxes instead.
 * Measured rather than guessed, since the header's height varies. */
const paneEl = ref<HTMLElement | null>(null)
const paneHeight = ref<string | undefined>()
function fitPane() {
  if (!paneEl.value || window.innerWidth < 1024) { paneHeight.value = undefined; return }
  const rect = paneEl.value.getBoundingClientRect()
  const top = rect.top + window.scrollY
  // Whatever the layout puts below the pane (its bottom padding) must fit too.
  const below = Math.max(0, document.documentElement.scrollHeight - (rect.bottom + window.scrollY))
  // A floor keeps very short windows usable; there the page scrolls a little.
  paneHeight.value = `${Math.max(340, Math.floor(window.innerHeight - top - below))}px`
}
onMounted(() => { nextTick(fitPane); window.addEventListener('resize', fitPane) })
onUnmounted(() => window.removeEventListener('resize', fitPane))
watch([tab, () => items.value.length], () => nextTick(fitPane))

const reviewEl = ref<HTMLElement | null>(null)
function select(a: AgentProfile) {
  selectedId.value = a.id
  // On narrow screens the review panel sits under the queue: bring it into view.
  if (import.meta.client && window.innerWidth < 1024) nextTick(() => reviewEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
}

/* ── AI assessment arrives asynchronously ──
 * The pre-check is a queued job, so a freshly submitted application shows up
 * here before its assessment exists. While any is still waiting, refetch
 * quietly every few seconds; the polling stops once nothing is pending. */
const hasAiPanel = (a: AgentProfile) => !!a.ai_comment || !!a.ai_pending
const anyAiPending = computed(() => items.value.some(a => a.ai_pending))
let aiPoll: ReturnType<typeof setInterval> | null = null
watch(anyAiPending, (pending) => {
  if (pending && !aiPoll) aiPoll = setInterval(() => load(true), 5000)
  else if (!pending && aiPoll) { clearInterval(aiPoll); aiPoll = null }
}, { immediate: true })
onUnmounted(() => { if (aiPoll) clearInterval(aiPoll) })

/* ── Decide ── */
const decision = ref<'approve' | 'reject' | null>(null)
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

/* ── Labels ── */
const isBroker = (a: AgentProfile) => a.applicant_type === 'broker'
const typeLabel = (a: AgentProfile) => (isBroker(a) ? 'Broker' : a.applicant_type === 'salesperson' ? 'Salesperson' : 'Agent')
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

/* ── Documents ──
 * Shown inline (selfie first, so it sits beside the ID), with a lightbox for
 * full size. PDFs get a tile that opens the file directly. */
interface Doc { key: string; label: string; url: string; isPdf: boolean }
const isPdf = (url: string) => /\.pdf(\?|$)/i.test(url)
function docsFor(agent: AgentProfile): Doc[] {
  const list: Array<[string, string, string | null | undefined]> = [
    ['face_image',        'Live selfie',   agent.face_image],
    ['license_doc',       'License card',  agent.license_doc],
    ['accreditation_doc', 'Accreditation', agent.accreditation_doc],
    ['valid_id',          'Valid ID',      agent.valid_id],
  ]
  return list
    .filter((d): d is [string, string, string] => !!d[2])
    .map(([key, label, url]) => ({ key, label, url, isPdf: isPdf(url) }))
}

/*
 * The assessment arrives as light markdown: "**Heading:**" lines and "* item"
 * bullets. Split it into typed lines and render each with the right element,
 * via text interpolation only — never v-html — so model output can't inject.
 */
type AiLine = { kind: 'heading' | 'bullet' | 'text'; text: string }
function aiLines(raw: string): AiLine[] {
  return raw.split('\n').flatMap((l): AiLine[] => {
    const s = l.trim()
    if (!s) return []
    const heading = s.match(/^\*\*(.+?)\*\*:?$/)
    if (heading) return [{ kind: 'heading', text: heading[1]!.replace(/:$/, '') }]
    const bullet = s.match(/^[*•-]\s+(.+)$/)
    if (bullet) return [{ kind: 'bullet', text: bullet[1]!.replace(/\*\*/g, '') }]
    return [{ kind: 'text', text: s.replace(/\*\*/g, '') }]
  })
}

const viewer = ref<{ docs: Doc[]; index: number; name: string } | null>(null)
function openDoc(agent: AgentProfile, index: number) {
  const docs = docsFor(agent)
  const doc  = docs[index]
  if (!doc) return
  if (doc.isPdf) { window.open(doc.url, '_blank', 'noopener'); return }
  viewer.value = { docs, index, name: agent.user?.name ?? 'Applicant' }
}
const viewerDoc = computed(() => viewer.value?.docs[viewer.value.index] ?? null)
function viewerStep(delta: number) {
  if (!viewer.value) return
  const n = viewer.value.docs.length
  let i = viewer.value.index
  // Skip PDFs — they can't be shown in the image viewer.
  for (let tries = 0; tries < n; tries++) {
    i = (i + delta + n) % n
    if (!viewer.value.docs[i]!.isPdf) { viewer.value.index = i; return }
  }
}
function onViewerKey(e: KeyboardEvent) {
  if (!viewer.value) return
  if (e.key === 'ArrowRight') viewerStep(1)
  if (e.key === 'ArrowLeft')  viewerStep(-1)
  if (e.key === 'Escape')     viewer.value = null
}
onMounted(() => window.addEventListener('keydown', onViewerKey))
onUnmounted(() => window.removeEventListener('keydown', onViewerKey))
</script>

<template>
  <div class="max-w-6xl mx-auto">
    <!-- ── Header, with the tabs beside it to save height ── -->
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
      <header>
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Manage</p>
        <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">Agent applications</h1>
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

    <div v-if="loading" class="grid grid-cols-1 gap-6 lg:grid-cols-[19rem_minmax(0,1fr)]">
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

    <!-- Desktop: exactly the height left in the window (fitPane), so nothing needs page scrolling. -->
    <div v-else ref="paneEl" class="grid grid-cols-1 gap-6 lg:grid-cols-[19rem_minmax(0,1fr)]" :style="paneHeight ? { height: paneHeight } : undefined">
      <!-- ── Queue ── -->
      <ul class="space-y-2 lg:h-full lg:overflow-y-auto lg:pr-1 lg:-mr-1 lg:p-0.5" :aria-label="`${tab} applications`">
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
      <section v-if="selected" ref="reviewEl" class="scroll-mt-24 flex flex-col rounded-3xl p-5 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] lg:h-full lg:min-h-0">
        <!-- Who + decision, one row -->
        <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
          <AppAvatar :name="selected.user?.name" :src="selected.user?.avatar" size="lg" />
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="font-display text-lg font-bold leading-tight text-brand-navy dark:text-white">{{ selected.user?.name }}</h2>
              <span class="rounded-full px-2 py-0.5 text-[0.625rem] font-bold uppercase"
                    :class="isBroker(selected) ? 'bg-brand-navy/[0.08] text-brand-navy dark:bg-white/10 dark:text-white' : 'bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold'">{{ typeLabel(selected) }}</span>
            </div>
            <p class="truncate text-xs text-brand-navy/60 dark:text-white/55">{{ selected.user?.email }}</p>
            <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
              <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-cream dark:bg-white/[0.06] pl-2.5 pr-0.5 py-0.5 ring-1 ring-brand-navy/[0.06] dark:ring-white/10">
                <span class="text-brand-navy/55 dark:text-white/50">{{ isBroker(selected) ? 'PRC no.' : 'Accreditation no.' }}</span>
                <span class="font-semibold tabular-nums text-brand-navy dark:text-white">{{ selected.prc_number }}</span>
                <button type="button" class="h-6 w-6 rounded-full flex items-center justify-center text-brand-navy/50 hover:text-brand-navy hover:bg-brand-navy/[0.06] dark:text-white/50 dark:hover:text-white dark:hover:bg-white/10" :aria-label="`Copy ${isBroker(selected) ? 'PRC' : 'accreditation'} number`" title="Copy" @click="copyNumber(selected)">
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75" /></svg>
                </button>
              </span>
              <span class="rounded-full px-2.5 py-1" :class="tab === 'pending' && daysSince(selected.created_at) > 3 ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400 font-semibold' : 'bg-brand-navy/[0.05] text-brand-navy/60 dark:bg-white/[0.06] dark:text-white/55'">
                {{ waited(selected.created_at) }} · {{ longDate(selected.created_at) }}
              </span>
              <span v-if="selected.supervising_broker" class="rounded-full px-2.5 py-1 bg-brand-navy/[0.05] text-brand-navy/60 dark:bg-white/[0.06] dark:text-white/55">Broker: {{ selected.supervising_broker }}</span>
            </div>
          </div>

          <div v-if="tab === 'pending'" class="flex flex-wrap items-center gap-2">
            <a
              v-if="selected.verification_links?.[0]"
              :href="selected.verification_links[0].url"
              target="_blank"
              rel="noopener"
              class="inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold transition"
              :title="selected.verification_links[0].note"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
              Verify on {{ isBroker(selected) ? 'PRC' : 'site' }}
            </a>
            <button type="button" class="rounded-full px-4 py-2 text-sm font-semibold text-red-600 ring-1 ring-red-300 hover:bg-red-500/10 dark:text-red-400 dark:ring-red-400/40 transition" @click="decision = 'reject'">Reject</button>
            <button type="button" class="inline-flex items-center gap-1.5 rounded-full bg-brand-gold px-4 py-2 text-sm font-bold text-[#0B2A5C] shadow-[0_4px_14px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light transition" @click="decision = 'approve'">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
              Approve
            </button>
          </div>
        </div>

        <!-- On hold: the license couldn't be checked yet -->
        <div v-if="tab === 'pending' && selected.hold_note" class="mt-4 flex flex-wrap items-start justify-between gap-3 rounded-2xl bg-amber-500/[0.07] ring-1 ring-amber-400/30 px-4 py-3">
          <div class="min-w-0">
            <p class="text-xs font-bold uppercase tracking-wide text-amber-700 dark:text-amber-400">
              On hold<template v-if="selected.held_by"> · {{ selected.held_by }}</template><template v-if="selected.held_at"> · {{ shortDate(selected.held_at) }}</template>
            </p>
            <p class="mt-0.5 text-sm text-brand-navy/80 dark:text-white/75">“{{ selected.hold_note }}”</p>
          </div>
          <button type="button" class="flex-none rounded-full px-3 py-1.5 text-xs font-semibold text-amber-800 ring-1 ring-amber-400/50 hover:bg-amber-500/10 dark:text-amber-300" @click="releaseHold(selected)">Remove hold</button>
        </div>

        <!-- How it was decided (history tabs) -->
        <div v-if="tab !== 'pending'" class="mt-4 rounded-2xl px-4 py-3 ring-1" :class="tab === 'approved' ? 'bg-emerald-500/[0.06] ring-emerald-500/25' : 'bg-red-500/[0.05] ring-red-400/25'">
          <p class="text-sm font-semibold" :class="tab === 'approved' ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'">
            {{ tab === 'approved' ? 'Approved' : 'Rejected' }} on {{ longDate(selected.reviewed_at) }}<template v-if="selected.reviewed_by"> by {{ selected.reviewed_by }}</template>
          </p>
          <p v-if="tab === 'rejected' && selected.admin_note" class="mt-1 text-sm text-brand-navy/80 dark:text-white/75">“{{ selected.admin_note }}”</p>
          <p v-if="tab === 'rejected' && selected.reapply_at" class="mt-1 text-xs text-brand-navy/55 dark:text-white/50">Can apply again from {{ longDate(selected.reapply_at) }}.</p>
        </div>

        <!-- Documents + RealtyLink AI: fill the rest of the panel -->
        <div class="mt-4 grid grid-cols-1 gap-4 lg:min-h-0 lg:flex-1 lg:overflow-y-auto xl:overflow-visible" :class="hasAiPanel(selected) ? 'xl:grid-cols-2' : ''">
          <div class="flex flex-col xl:min-h-0">
            <p class="mb-2 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">Submitted documents</p>
            <p v-if="!docsFor(selected).length" class="rounded-2xl bg-brand-cream/70 dark:bg-white/[0.04] px-4 py-6 text-center text-sm text-brand-navy/55 dark:text-white/50">No documents on file.</p>
            <!-- Wide screens: two documents stack and share the height; three or four go 2×2. -->
            <div
              v-else
              class="grid gap-3 grid-cols-2 xl:flex-1 xl:min-h-0"
              :class="[!hasAiPanel(selected) ? 'sm:grid-cols-4' : '', docsFor(selected).length <= 2 && hasAiPanel(selected) ? 'xl:grid-cols-1 xl:grid-rows-2' : 'xl:grid-rows-2']"
            >
              <button
                v-for="(doc, i) in docsFor(selected)"
                :key="doc.key"
                type="button"
                class="group flex flex-col text-left rounded-2xl overflow-hidden ring-1 ring-brand-navy/[0.08] dark:ring-white/10 bg-brand-cream/60 dark:bg-white/[0.04] xl:min-h-0
                       hover:ring-brand-gold hover:shadow-[0_8px_24px_-8px_rgba(11,42,92,0.25)] transition"
                :title="doc.isPdf ? `Open ${doc.label} (PDF)` : `View ${doc.label}`"
                @click="openDoc(selected, i)"
              >
                <div class="relative aspect-[4/3] overflow-hidden bg-brand-navy/[0.04] dark:bg-white/[0.04] xl:aspect-auto xl:flex-1 xl:min-h-0">
                  <img
                    v-if="!doc.isPdf"
                    :src="doc.url"
                    :alt="doc.label"
                    class="absolute inset-0 h-full w-full object-contain group-hover:scale-[1.03] transition-transform duration-300"
                    loading="lazy"
                  />
                  <div v-else class="h-full w-full flex flex-col items-center justify-center gap-1.5 text-brand-navy/50 dark:text-white/50">
                    <svg class="h-9 w-9" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                    <span class="text-[0.625rem] font-bold tracking-wide">PDF</span>
                  </div>
                  <span class="absolute bottom-2 right-2 h-7 w-7 rounded-full bg-black/50 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity" aria-hidden="true">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" /></svg>
                  </span>
                  <!-- Label laid over the photo, so a short window doesn't clip it -->
                  <span class="absolute left-2 top-2 rounded-full bg-[#06183A]/75 px-2.5 py-1 text-[0.6875rem] font-semibold text-white backdrop-blur-sm">{{ doc.label }}</span>
                </div>
              </button>
            </div>
          </div>

          <!-- RealtyLink AI: advisory only; scrolls in its own box -->
          <div v-if="hasAiPanel(selected)" class="flex flex-col xl:min-h-0">
            <p class="mb-2 flex items-center gap-2 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">
              <!-- RealtyLink AI's own face, as in the chat panel -->
              <img src="/ai/avatar-96.webp" alt="" class="h-6 w-6 flex-none rounded-full object-cover bg-white ring-1 ring-brand-gold/60" />
              RealtyLink AI check
              <span class="normal-case tracking-normal font-medium text-brand-navy/40 dark:text-white/35">· advice only, you decide</span>
            </p>
            <div class="max-h-[26rem] overflow-y-auto rounded-2xl ring-1 ring-brand-gold/30 bg-brand-gold/[0.06] dark:bg-brand-gold/10 p-4 xl:max-h-none xl:flex-1 xl:min-h-0">
              <div v-if="!selected.ai_comment" class="h-full min-h-[120px] flex flex-col items-center justify-center text-center gap-2">
                <span class="relative h-8 w-8">
                  <span class="absolute inset-0 rounded-full border-2 border-brand-gold/20" />
                  <span class="absolute inset-0 rounded-full border-t-2 border-brand-gold animate-spin" />
                </span>
                <p class="text-sm font-semibold text-brand-navy dark:text-white">RealtyLink AI is reviewing the documents…</p>
                <p class="text-xs text-brand-navy/50 dark:text-white/45">Usually under a minute. This updates on its own.</p>
              </div>
              <div v-else class="text-sm text-brand-navy/75 dark:text-white/75 leading-relaxed space-y-1">
                <template v-for="(line, li) in aiLines(selected.ai_comment)" :key="li">
                  <p v-if="line.kind === 'heading'" class="font-bold text-brand-navy dark:text-white pt-2 first:pt-0">{{ line.text }}</p>
                  <p v-else-if="line.kind === 'bullet'" class="flex gap-2"><span class="text-brand-gold flex-shrink-0">•</span><span>{{ line.text }}</span></p>
                  <p v-else>{{ line.text }}</p>
                </template>
              </div>
            </div>
          </div>
        </div>

        <!-- Official sources, fixed on the server (the AI never makes a link); one compact row. -->
        <div v-if="selected.verification_links?.length" class="mt-4 flex flex-wrap items-center gap-2 border-t border-brand-navy/[0.07] dark:border-white/[0.08] pt-3">
          <span class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">Verify against</span>
          <a
            v-for="l in selected.verification_links"
            :key="l.url"
            :href="l.url"
            target="_blank"
            rel="noopener"
            :title="l.note"
            class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold text-brand-navy ring-1 ring-brand-navy/10 hover:ring-brand-gold hover:text-brand-gold-deep dark:text-white dark:ring-white/15 dark:hover:text-brand-gold transition"
          >
            {{ l.label }}
            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
          </a>
        </div>
      </section>
    </div>

    <AgentDecisionDialog :open="!!decision" :mode="decision ?? 'approve'" :application="selected" @close="decision = null" @done="onDecided" />

    <!-- Document lightbox -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="viewer && viewerDoc" class="fixed inset-0 z-[60] bg-black/85 backdrop-blur-sm flex flex-col" @click.self="viewer = null">
          <div class="flex items-center justify-between gap-4 px-5 py-3 text-white">
            <div class="min-w-0">
              <p class="text-sm font-semibold truncate">{{ viewerDoc.label }}</p>
              <p class="text-xs text-white/60 truncate">{{ viewer.name }} · {{ viewer.index + 1 }} / {{ viewer.docs.length }}</p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
              <a :href="viewerDoc.url" target="_blank" rel="noopener" class="text-xs font-semibold text-white/80 hover:text-white ring-1 ring-white/25 rounded-full px-3 py-1.5 transition-colors">Open original</a>
              <button type="button" class="h-9 w-9 rounded-full hover:bg-white/10 flex items-center justify-center" aria-label="Close" @click="viewer = null">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
              </button>
            </div>
          </div>
          <div class="flex-1 min-h-0 flex items-center justify-center px-14 pb-6" @click.self="viewer = null">
            <img :src="viewerDoc.url" :alt="viewerDoc.label" class="max-h-full max-w-full object-contain rounded-lg shadow-2xl" />
          </div>
          <button v-if="viewer.docs.length > 1" type="button" class="absolute left-3 top-1/2 -translate-y-1/2 h-11 w-11 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center" aria-label="Previous document" @click="viewerStep(-1)">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
          </button>
          <button v-if="viewer.docs.length > 1" type="button" class="absolute right-3 top-1/2 -translate-y-1/2 h-11 w-11 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center" aria-label="Next document" @click="viewerStep(1)">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
          </button>
          <div v-if="viewer.docs.length > 1" class="flex justify-center gap-2 pb-5 px-5">
            <button
              v-for="(d, i) in viewer.docs"
              :key="d.key"
              type="button"
              class="h-14 w-20 rounded-lg overflow-hidden border-2 transition-all"
              :class="i === viewer.index ? 'border-brand-gold' : 'border-transparent opacity-60 hover:opacity-100'"
              :disabled="d.isPdf"
              :title="d.label"
              @click="!d.isPdf && (viewer.index = i)"
            >
              <img v-if="!d.isPdf" :src="d.url" :alt="d.label" class="h-full w-full object-cover" />
              <div v-else class="h-full w-full bg-white/10 text-white/60 text-[0.625rem] font-bold flex items-center justify-center">PDF</div>
            </button>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
