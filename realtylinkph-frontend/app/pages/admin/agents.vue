<script setup lang="ts">
import type { AgentProfile } from '~/types'

definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()
if (!authStore.isAdmin) {
  throw createError({ statusCode: 403, statusMessage: 'Forbidden' })
}

const { fetchPendingAgents, approveAgent, rejectAgent } = useAgent()

const agents  = ref<AgentProfile[]>([])
const loading = ref(false)
const rejectReason = ref('')
const rejectTarget = ref<number | null>(null)

async function load() {
  loading.value = true
  agents.value  = await fetchPendingAgents()
  loading.value = false
}

/* ── AI assessment arrives asynchronously ──
 * The pre-check is a queued job, so a freshly submitted application shows up
 * here before its assessment exists. While any card is still waiting, refetch
 * quietly every few seconds so the text appears without a manual reload; the
 * polling stops on its own once nothing is pending. */
const hasAiPanel = (a: AgentProfile) => !!a.ai_comment || !!a.ai_pending
const anyAiPending = computed(() => agents.value.some(a => a.ai_pending))
let aiPoll: ReturnType<typeof setInterval> | null = null

watch(anyAiPending, (pending) => {
  if (pending && !aiPoll) {
    aiPoll = setInterval(async () => { agents.value = await fetchPendingAgents() }, 5000)
  } else if (!pending && aiPoll) {
    clearInterval(aiPoll)
    aiPoll = null
  }
}, { immediate: true })

onUnmounted(() => { if (aiPoll) clearInterval(aiPoll) })

async function approve(userId: number) {
  const ok = await approveAgent(userId)
  if (ok) await load()
}

async function reject() {
  if (!rejectTarget.value) return
  const ok = await rejectAgent(rejectTarget.value, rejectReason.value)
  if (ok) {
    rejectTarget.value = null
    rejectReason.value = ''
    await load()
  }
}

/* ── Document review ──
 * The uploads used to be four text links that opened in new tabs, so an admin
 * had to juggle windows to compare the selfie against the ID. They're shown
 * inline now — selfie first, then the identity document beside it — with a
 * lightbox for full size. PDFs get a tile that opens the file directly.
 */
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

await load()
</script>

<template>
  <div>
    <h1 class="font-playfair text-2xl font-bold text-brand-navy mb-6">Pending Agent Verifications</h1>

    <div v-if="loading" class="space-y-3">
      <AppSkeleton v-for="i in 3" :key="i" width="100%" height="80px" />
    </div>

    <div v-else-if="!agents.length" class="text-center py-12 card">
      <p class="text-brand-text-secondary">No pending applications.</p>
    </div>

    <div v-else class="space-y-3">
      <div v-for="agent in agents" :key="agent.id" class="card p-5">
        <div class="flex items-start gap-4 flex-wrap">
          <AppAvatar :name="agent.user?.name" :src="agent.user?.avatar" size="md" />
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <p class="font-semibold text-brand-text-primary">{{ agent.user?.name }}</p>
              <span
                class="text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full"
                :class="agent.applicant_type === 'broker' ? 'bg-brand-navy/10 text-brand-navy' : 'bg-brand-gold/15 text-brand-gold'"
              >
                {{ agent.applicant_type === 'broker' ? 'Broker' : agent.applicant_type === 'salesperson' ? 'Salesperson' : 'Agent' }}
              </span>
            </div>
            <p class="text-sm text-brand-text-secondary">{{ agent.user?.email }}</p>
            <p class="text-xs text-brand-text-secondary mt-0.5">
              {{ agent.applicant_type === 'broker' ? 'PRC' : 'Accreditation' }} No.: {{ agent.prc_number }}
            </p>
            <p v-if="agent.supervising_broker" class="text-xs text-brand-text-secondary">Supervising broker: {{ agent.supervising_broker }}</p>
          </div>
          <div class="flex gap-2">
            <AppButton variant="primary" size="sm" @click="approve(agent.id)">Approve</AppButton>
            <AppButton variant="danger"  size="sm" @click="rejectTarget = agent.id">Reject</AppButton>
          </div>
        </div>

        <!-- Documents on the left, AI assessment on the right — the assessment
             used to sit below, leaving the right half of the card empty. -->
        <!-- Both columns stretch to the row, so the boxes share a bottom edge.
             Beside an assessment the tiles grow to fill that height with the
             image *contained* — a license card must never be cropped — while
             without one they fall back to fixed 4:3 thumbnails, 4-up. -->
        <div class="mt-4 grid grid-cols-1 gap-4" :class="hasAiPanel(agent) ? 'lg:grid-cols-2 lg:items-stretch' : ''">

        <!-- Submitted documents — selfie first so it sits beside the ID -->
        <div class="flex flex-col">
          <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40 mb-2">
            Submitted documents
          </p>
          <div
            class="grid grid-cols-2 gap-3"
            :class="hasAiPanel(agent) ? 'flex-1 auto-rows-fr' : 'sm:grid-cols-4'"
          >
            <button
              v-for="(doc, i) in docsFor(agent)"
              :key="doc.key"
              type="button"
              class="group flex flex-col text-left rounded-xl overflow-hidden border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5
                     hover:border-brand-gold hover:shadow-[0_8px_24px_-8px_rgba(8,21,47,0.25)] transition-all"
              :title="doc.isPdf ? `Open ${doc.label} (PDF)` : `View ${doc.label}`"
              @click="openDoc(agent, i)"
            >
              <div
                class="relative bg-gray-100 dark:bg-white/5 overflow-hidden"
                :class="hasAiPanel(agent) ? 'flex-1 min-h-[160px]' : 'aspect-[4/3]'"
              >
                <img
                  v-if="!doc.isPdf"
                  :src="doc.url"
                  :alt="doc.label"
                  class="absolute inset-0 h-full w-full group-hover:scale-[1.03] transition-transform duration-300"
                  :class="hasAiPanel(agent) ? 'object-contain' : 'object-cover'"
                  loading="lazy"
                />
                <div v-else class="h-full w-full flex flex-col items-center justify-center gap-1.5 text-brand-navy/50 dark:text-white/50">
                  <svg class="h-9 w-9" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                  </svg>
                  <span class="text-[10px] font-bold tracking-wide">PDF</span>
                </div>
                <!-- expand hint -->
                <span class="absolute bottom-2 right-2 h-7 w-7 rounded-full bg-black/50 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                  </svg>
                </span>
              </div>
              <p class="px-3 py-2 text-xs font-semibold text-brand-navy dark:text-white/85">{{ doc.label }}</p>
            </button>
          </div>
        </div>

        <!-- RealtyLink AI assessment. Same eyebrow-then-box structure as the
             documents column, so the two boxes start on the same line. -->
        <div v-if="hasAiPanel(agent)" class="flex flex-col">
          <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40 mb-2">
            <svg class="h-3.5 w-3.5 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
            RealtyLink AI assessment
            <span class="normal-case tracking-normal font-medium text-gray-400">· advisory only — you decide</span>
          </p>
          <div class="flex-1 rounded-xl border border-brand-gold/25 bg-brand-gold/5 dark:bg-brand-gold/10 p-4">
          <!-- Queued job still running: the applicant's documents are being read. -->
          <div v-if="!agent.ai_comment" class="h-full min-h-[120px] flex flex-col items-center justify-center text-center gap-2">
            <span class="relative h-8 w-8">
              <span class="absolute inset-0 rounded-full border-2 border-brand-gold/20" />
              <span class="absolute inset-0 rounded-full border-t-2 border-brand-gold animate-spin" />
            </span>
            <p class="text-sm font-semibold text-brand-navy dark:text-white">RealtyLink AI is reviewing the documents…</p>
            <p class="text-xs text-gray-400 dark:text-white/40">Usually under a minute. This updates on its own.</p>
          </div>
          <!-- The model writes light markdown (**headings**, * bullets). Rendered
               as structure rather than shown with the raw asterisks. -->
          <div v-else class="text-sm text-brand-text-secondary dark:text-white/70 leading-relaxed space-y-1">
            <template v-for="(line, li) in aiLines(agent.ai_comment)" :key="li">
              <p v-if="line.kind === 'heading'" class="font-bold text-brand-navy dark:text-white pt-2 first:pt-0">{{ line.text }}</p>
              <p v-else-if="line.kind === 'bullet'" class="flex gap-2"><span class="text-brand-gold flex-shrink-0">•</span><span>{{ line.text }}</span></p>
              <p v-else-if="line.kind === 'text'">{{ line.text }}</p>
            </template>
          </div>
          </div>
        </div>

        </div>
      </div>
    </div>

    <!-- Reject modal -->
    <AppModal :open="!!rejectTarget" title="Reject Application" @close="rejectTarget = null">
      <div class="p-6 space-y-4">
        <AppInput v-model="rejectReason" label="Reason for rejection" placeholder="e.g. Documents unclear..." />
        <div class="flex gap-3">
          <AppButton variant="ghost" full-width @click="rejectTarget = null">Cancel</AppButton>
          <AppButton variant="danger" full-width @click="reject">Reject</AppButton>
        </div>
      </div>
    </AppModal>

    <!-- Document lightbox -->
    <Teleport to="body">
      <Transition name="fade">
        <div
          v-if="viewer && viewerDoc"
          class="fixed inset-0 z-[60] bg-black/85 backdrop-blur-sm flex flex-col"
          @click.self="viewer = null"
        >
          <!-- top bar -->
          <div class="flex items-center justify-between gap-4 px-5 py-3 text-white">
            <div class="min-w-0">
              <p class="text-sm font-semibold truncate">{{ viewerDoc.label }}</p>
              <p class="text-xs text-white/60 truncate">{{ viewer.name }} · {{ viewer.index + 1 }} / {{ viewer.docs.length }}</p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
              <a :href="viewerDoc.url" target="_blank" rel="noopener" class="text-xs font-semibold text-white/80 hover:text-white border border-white/25 rounded-lg px-3 py-1.5 transition-colors">
                Open original
              </a>
              <button type="button" class="h-9 w-9 rounded-full hover:bg-white/10 flex items-center justify-center" aria-label="Close" @click="viewer = null">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
              </button>
            </div>
          </div>

          <!-- image -->
          <div class="flex-1 min-h-0 flex items-center justify-center px-14 pb-6" @click.self="viewer = null">
            <img :src="viewerDoc.url" :alt="viewerDoc.label" class="max-h-full max-w-full object-contain rounded-lg shadow-2xl" />
          </div>

          <!-- prev / next -->
          <button
            v-if="viewer.docs.length > 1"
            type="button"
            class="absolute left-3 top-1/2 -translate-y-1/2 h-11 w-11 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center"
            aria-label="Previous document"
            @click="viewerStep(-1)"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
          </button>
          <button
            v-if="viewer.docs.length > 1"
            type="button"
            class="absolute right-3 top-1/2 -translate-y-1/2 h-11 w-11 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center"
            aria-label="Next document"
            @click="viewerStep(1)"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
          </button>

          <!-- thumbnail strip -->
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
              <div v-else class="h-full w-full bg-white/10 text-white/60 text-[10px] font-bold flex items-center justify-center">PDF</div>
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
