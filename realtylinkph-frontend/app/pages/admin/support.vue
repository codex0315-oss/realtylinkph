<script setup lang="ts">
import type { ApiResponse, PaginatedResponse } from '~/types'
import type { SupportMessage, SupportTicket } from '~/composables/useSupport'
import { SUPPORT_CATEGORIES } from '~/composables/useSupport'

definePageMeta({ layout: 'dashboard' })

/*
 * The support inbox. Tickets reach it when RealtyLink AI hands a conversation
 * over (or the person asks for someone). Left: the inbox (Open = waiting,
 * longest first). Right: the conversation with the AI's summary, a draft
 * reply to use or edit, quick replies, screenshots, and Resolve.
 * Laid out like Messages (one card, inbox | thread); polls for new messages.
 */
const authStore = useAuthStore()
if (!authStore.isAdmin) throw createError({ statusCode: 403, statusMessage: 'Forbidden' })

const api = useApi()
const toast = useToast()

type Tab = 'open' | 'in_progress' | 'resolved'
const tab = ref<Tab>('open')
const search = ref('')
const tickets = ref<SupportTicket[]>([])
const loading = ref(false)
const selectedId = ref<number | null>(null)
const ticket = ref<SupportTicket | null>(null)

interface Stats { counts: Record<Tab | 'ai', number>; ratings: { up: number; down: number }; by_category: Record<string, number> }
const stats = ref<Stats | null>(null)

async function loadList(quiet = false) {
  if (!quiet) loading.value = true
  try {
    const params: Record<string, unknown> = { status: tab.value }
    if (search.value.trim()) params.search = search.value.trim()
    tickets.value = (await api.get<PaginatedResponse<SupportTicket>>('/admin/support', params)).data
  } catch { tickets.value = [] }
  loading.value = false
  if (!tickets.value.some(t => t.id === selectedId.value)) select(tickets.value[0]?.id ?? null)
}
async function loadStats() {
  try { stats.value = (await api.get<ApiResponse<Stats>>('/admin/support/stats')).data } catch { /* optional */ }
}
async function loadTicket(quiet = false) {
  if (!selectedId.value) { ticket.value = null; return }
  try {
    const before = ticket.value?.id === selectedId.value ? ticket.value?.messages?.length ?? 0 : -1
    const t = (await api.get<ApiResponse<SupportTicket>>(`/admin/support/${selectedId.value}`)).data
    // Keep a draft being edited; take everything else.
    ticket.value = t
    if (!quiet || (t.messages?.length ?? 0) > before) scrollThread()
  } catch { ticket.value = null }
}
function select(id: number | null) {
  if (id === selectedId.value) return
  selectedId.value = id
  reply.value = ''
  files.value = []
  previews.value = []
  loadTicket()
}

function setTab(t: Tab) { tab.value = t; selectedId.value = null; loadList() }
let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadList(), 300) })

let poll: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  poll = setInterval(() => {
    if (document.visibilityState !== 'visible' || sending.value) return
    loadList(true); loadTicket(true); loadStats()
  }, 8000)
})
onUnmounted(() => { if (poll) clearInterval(poll) })

/* Phones show one pane at a time, like Messages: the inbox, or the open ticket. */
const mobileThread = ref(false)
function openTicket(id: number) { select(id); mobileThread.value = true }

const threadEl = ref<HTMLElement | null>(null)
function scrollThread() { nextTick(() => threadEl.value?.scrollTo({ top: threadEl.value.scrollHeight })) }

/* ── Reply ── */
const reply = ref('')
const files = ref<File[]>([])
const previews = ref<string[]>([])
const sending = ref(false)
const fileEl = ref<HTMLInputElement | null>(null)
function pickFiles(e: Event) {
  for (const f of Array.from((e.target as HTMLInputElement).files ?? [])) {
    if (files.value.length >= 3) break
    if (!f.type.startsWith('image/') || f.size > 5 * 1024 * 1024) { toast.error(`${f.name}: images up to 5 MB only.`); continue }
    files.value.push(f)
    previews.value.push(URL.createObjectURL(f))
  }
  if (fileEl.value) fileEl.value.value = ''
}
function removeFile(i: number) { URL.revokeObjectURL(previews.value[i]!); files.value.splice(i, 1); previews.value.splice(i, 1) }

async function send() {
  if (!ticket.value || (!reply.value.trim() && !files.value.length) || sending.value) return
  sending.value = true
  const fd = new FormData()
  if (reply.value.trim()) fd.append('body', reply.value.trim())
  files.value.forEach(f => fd.append('images[]', f))
  try {
    ticket.value = (await api.postForm<ApiResponse<SupportTicket>>(`/admin/support/${ticket.value.id}/reply`, fd)).data
    reply.value = ''
    previews.value.forEach(u => URL.revokeObjectURL(u))
    files.value = []; previews.value = []
    toast.success('Reply sent')
    scrollThread()
    await Promise.all([loadList(true), loadStats()])
  } catch {
    toast.error('Couldn’t send. Please try again.')
  } finally {
    sending.value = false
  }
}
function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send() }
  // Tab on an empty box takes the AI suggestion shown as the placeholder.
  else if (e.key === 'Tab' && !e.shiftKey && !reply.value && ticket.value?.suggested_reply) { e.preventDefault(); useDraft() }
}

const suggesting = ref(false)
function useDraft() { if (ticket.value?.suggested_reply) reply.value = ticket.value.suggested_reply }
async function refreshDraft() {
  if (!ticket.value) return
  suggesting.value = true
  try {
    const t = (await api.post<ApiResponse<SupportTicket>>(`/admin/support/${ticket.value.id}/suggest`)).data
    ticket.value = { ...ticket.value, summary: t.summary, suggested_reply: t.suggested_reply }
  } catch { toast.error('RealtyLink AI couldn’t draft a reply just now.') }
  suggesting.value = false
}

async function setResolved(resolve: boolean) {
  if (!ticket.value) return
  try {
    ticket.value = (await api.post<ApiResponse<SupportTicket>>(`/admin/support/${ticket.value.id}/${resolve ? 'resolve' : 'reopen'}`)).data
    toast.success(resolve ? 'Resolved. They’ve been notified' : 'Reopened')
    await Promise.all([loadList(true), loadStats()])
  } catch { toast.error('That didn’t go through. Please try again.') }
}

/* ── Quick replies ── */
interface Quick { id: number; title: string; body: string }
const quick = ref<Quick[]>([])
const quickOpen = ref(false)
const managing = ref(false)
const newQuick = reactive({ title: '', body: '' })
async function loadQuick() { try { quick.value = (await api.get<ApiResponse<Quick[]>>('/admin/support/quick-replies')).data } catch { /* optional */ } }
onMounted(loadQuick)
function insertQuick(q: Quick) {
  const name = ticket.value?.user?.name?.split(' ')[0] ?? ''
  reply.value = (reply.value.trim() ? `${reply.value.trim()}\n\n` : '') + q.body.replace(/\{name\}/g, name)
  quickOpen.value = false
}
async function saveQuick() {
  if (!newQuick.title.trim() || !newQuick.body.trim()) return
  try {
    await api.post('/admin/support/quick-replies', { title: newQuick.title.trim(), body: newQuick.body.trim() })
    Object.assign(newQuick, { title: '', body: '' })
    await loadQuick()
  } catch { toast.error('Couldn’t save the quick reply.') }
}
async function deleteQuick(q: Quick) {
  try { await api.del(`/admin/support/quick-replies/${q.id}`); await loadQuick() } catch { toast.error('Couldn’t delete it.') }
}

/* ── Display ── */
const TABS: Array<{ key: Tab; label: string }> = [{ key: 'open', label: 'Open' }, { key: 'in_progress', label: 'In progress' }, { key: 'resolved', label: 'Resolved' }]
function ago(iso?: string | null): string {
  if (!iso) return ''
  const m = Math.round((Date.now() - +new Date(iso)) / 60000)
  if (m < 1) return 'just now'
  if (m < 60) return `${m}m ago`
  const h = Math.round(m / 60)
  if (h < 24) return `${h}h ago`
  return `${Math.round(h / 24)}d ago`
}
const timeOf = (iso: string) => new Date(iso).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' })
const roleName = (r?: string) => (r === 'agent' ? 'Agent' : r === 'buyer' ? 'Buyer' : r ?? '')

/* Thread feed: date separators; avatar + name once per run of one sender (as in Messages). */
function dateLabel(d: Date) {
  const today = new Date()
  const yest = new Date(); yest.setDate(today.getDate() - 1)
  if (d.toDateString() === today.toDateString()) return 'Today'
  if (d.toDateString() === yest.toDateString()) return 'Yesterday'
  return d.toLocaleDateString('en-PH', { month: 'long', day: 'numeric' })
}
type FeedItem =
  | { kind: 'date'; id: string; label: string }
  | { kind: 'msg'; id: number; message: SupportMessage; showMeta: boolean }
const feed = computed<FeedItem[]>(() => {
  const items: FeedItem[] = []
  let lastDay = ''
  let lastWho = ''
  for (const m of ticket.value?.messages ?? []) {
    const d = new Date(m.created_at)
    if (d.toDateString() !== lastDay) {
      items.push({ kind: 'date', id: 'd' + d.toDateString(), label: dateLabel(d) })
      lastDay = d.toDateString()
      lastWho = ''
    }
    const who = `${m.sender_type}:${m.sender_name ?? ''}:${m.stand_in ? 1 : 0}`
    items.push({ kind: 'msg', id: m.id, message: m, showMeta: who !== lastWho })
    lastWho = m.sender_type === 'system' ? '' : who
  }
  return items
})
function senderLabel(m: SupportMessage) {
  if (m.sender_type === 'admin') return `${m.sender_name ?? 'Team'} · team`
  return m.stand_in ? 'RealtyLink AI · while the team was away' : 'RealtyLink AI'
}

/* Load last: select() (run by loadList) clears the reply draft, so everything
   above must be declared first, or the page fails on "reply" before init. */
await Promise.all([loadList(), loadStats()])
</script>

<template>
  <div class="max-w-6xl mx-auto">
  <header class="mb-6">
    <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Manage</p>
    <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">Support</h1>
    <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
      <template v-if="stats?.counts.open"><span class="font-semibold text-brand-navy dark:text-white">{{ stats.counts.open }}</span> waiting for a reply</template>
      <template v-else>No one is waiting</template>
      <template v-if="stats"> · RealtyLink AI solved <span class="font-semibold text-brand-navy dark:text-white">{{ stats.counts.ai }}</span> on its own</template><template v-if="stats && (stats.ratings.up + stats.ratings.down)"> · <span class="font-semibold text-brand-navy dark:text-white">{{ Math.round((stats.ratings.up / (stats.ratings.up + stats.ratings.down)) * 100) }}%</span> found the team's help useful</template>.
    </p>
  </header>

  <div
    class="flex rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] overflow-hidden shadow-[0_18px_40px_-28px_rgba(11,42,92,0.35)]"
    style="height: calc(100dvh - 16rem); min-height: 24rem"
  >
    <!-- ════════ Inbox ════════ -->
    <div
      class="w-full lg:w-80 flex-shrink-0 flex-col border-r border-gray-100 dark:border-white/10"
      :class="mobileThread && ticket ? 'hidden lg:flex' : 'flex'"
    >
      <div class="px-4 pt-4 pb-3">
        <!-- Tabs -->
        <div class="grid grid-cols-3 gap-1 rounded-xl bg-gray-50 dark:bg-white/5 p-1" role="tablist">
          <button
            v-for="t in TABS"
            :key="t.key"
            type="button"
            role="tab"
            :aria-selected="tab === t.key"
            class="inline-flex items-center justify-center gap-1 whitespace-nowrap rounded-lg px-2 py-1.5 text-xs font-semibold transition"
            :class="tab === t.key ? 'bg-white dark:bg-white/15 text-brand-navy dark:text-white shadow-sm' : 'text-gray-500 hover:text-brand-navy dark:text-white/50 dark:hover:text-white'"
            @click="setTab(t.key)"
          >
            {{ t.label }}
            <span class="tabular-nums" :class="t.key === 'open' && stats?.counts.open ? 'text-brand-gold-deep dark:text-brand-gold' : 'opacity-60'">{{ stats?.counts[t.key] ?? 0 }}</span>
          </button>
        </div>

        <!-- Search -->
        <div class="relative mt-3">
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
          <input
            v-model="search"
            type="search"
            placeholder="Search name, email or problem"
            aria-label="Search tickets"
            class="w-full pl-9 pr-4 py-2.5 text-sm rounded-xl bg-gray-50 dark:bg-white/5 dark:text-white border border-transparent focus:bg-white dark:focus:bg-white/10 focus:outline-none focus:ring-0 focus:border-brand-gold/40 transition-colors placeholder-gray-400"
          />
        </div>
      </div>

      <div class="flex-1 overflow-y-auto px-2.5 pb-3">
        <div v-if="loading" class="space-y-2 px-2.5 pt-2"><AppSkeleton v-for="i in 4" :key="i" width="100%" height="64px" /></div>

        <div v-else-if="!tickets.length" class="text-center px-4 py-14">
          <img src="/ai/avatar-96.webp" alt="" class="mx-auto h-12 w-12 rounded-full ring-2 ring-brand-gold/50" />
          <p class="mt-3 text-sm font-semibold text-brand-navy dark:text-white">{{ tab === 'open' ? 'Inbox zero' : 'Nothing here' }}</p>
          <p class="mt-1 text-xs text-gray-400 dark:text-white/40">{{ tab === 'open' ? 'RealtyLink AI hands conversations over when someone needs a person.' : 'Tickets move here as you work on them.' }}</p>
        </div>

        <button
          v-for="t in tickets"
          v-else
          :key="t.id"
          type="button"
          class="w-full text-left p-2.5 rounded-xl flex items-center gap-3 transition-colors mb-0.5"
          :class="t.id === selectedId ? 'bg-brand-gold/10' : 'hover:bg-gray-50 dark:hover:bg-white/5'"
          @click="openTicket(t.id)"
        >
          <AppAvatar :name="t.user?.name" :src="t.user?.avatar" size="md" class="flex-shrink-0" />
          <span class="flex-1 min-w-0">
            <span class="flex items-center justify-between gap-2">
              <span class="text-sm font-semibold truncate text-brand-navy dark:text-white">{{ t.user?.name ?? 'User' }}</span>
              <span class="text-[0.625rem] text-gray-400 flex-shrink-0">{{ ago(tab === 'open' ? t.escalated_at : t.last_message_at) }}</span>
            </span>
            <span class="flex items-center justify-between gap-2 mt-0.5">
              <span class="text-xs truncate" :class="t.unread ? 'text-brand-navy dark:text-white font-medium' : 'text-gray-400 dark:text-white/40'">
                <span class="text-gray-500 dark:text-white/50">{{ roleName(t.user?.role_type) }}<template v-if="t.category"> · {{ SUPPORT_CATEGORIES[t.category] ?? t.category }}</template>:</span>
                {{ t.summary || t.last_message?.body }}
              </span>
              <span v-if="t.unread" class="flex-shrink-0 bg-brand-gold text-brand-navy text-[0.625rem] font-bold rounded-full min-w-[18px] px-1 flex items-center justify-center" style="height:18px">{{ t.unread }}</span>
              <span v-else-if="t.rating" class="flex-shrink-0 text-[0.6875rem]">{{ t.rating === 'up' ? '👍' : '👎' }}</span>
            </span>
          </span>
        </button>
      </div>
    </div>

    <!-- ════════ Thread ════════ -->
    <div class="flex-1 min-w-0 flex-col" :class="mobileThread && ticket ? 'flex' : 'hidden lg:flex'">
      <!-- Empty -->
      <div v-if="!ticket" class="flex-1 flex flex-col items-center justify-center text-center px-6">
        <div class="h-16 w-16 rounded-2xl bg-brand-gold/10 flex items-center justify-center mb-4">
          <svg class="h-8 w-8 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg>
        </div>
        <p class="font-semibold text-brand-navy dark:text-white">{{ tickets.length ? 'Select a ticket' : 'No tickets here' }}</p>
        <p class="text-sm text-gray-400 mt-1">{{ tickets.length ? 'Choose a conversation from the left to reply.' : 'New requests show up here as RealtyLink AI hands them over.' }}</p>
      </div>

      <template v-else>
        <!-- Thread header -->
        <div class="flex items-center gap-3 px-5 py-3.5 border-b border-gray-100 dark:border-white/10">
          <button type="button" class="lg:hidden p-1.5 -ml-1 text-gray-500 dark:text-white/60 rounded-lg hover:bg-gray-100 dark:hover:bg-white/10" aria-label="Back to inbox" @click="mobileThread = false">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
          </button>
          <AppAvatar :name="ticket.user?.name" :src="ticket.user?.avatar" size="md" class="flex-shrink-0" />
          <div class="min-w-0 flex-1">
            <p class="flex items-center gap-2 text-sm font-bold text-brand-navy dark:text-white">
              <span class="truncate">{{ ticket.user?.name }}</span>
              <span class="flex-shrink-0 rounded-full bg-gray-100 dark:bg-white/10 px-2 py-0.5 text-[0.625rem] font-bold uppercase text-gray-500 dark:text-white/60">{{ roleName(ticket.user?.role_type) }}</span>
              <span v-if="ticket.category" class="hidden sm:inline flex-shrink-0 rounded-full bg-brand-gold/15 px-2 py-0.5 text-[0.625rem] font-bold uppercase text-brand-gold-deep dark:text-brand-gold">{{ SUPPORT_CATEGORIES[ticket.category] ?? ticket.category }}</span>
            </p>
            <p class="text-xs truncate text-gray-400 dark:text-white/40">{{ ticket.user?.email }}<template v-if="ticket.escalated_at"> · asked for help {{ ago(ticket.escalated_at) }}</template></p>
          </div>
          <button v-if="ticket.status !== 'resolved'" type="button" class="flex-shrink-0 inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-500/10 hover:bg-emerald-500/20 transition-colors" @click="setResolved(true)">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
            Resolve
          </button>
          <button v-else type="button" class="flex-shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-500 dark:text-white/60 bg-gray-50 dark:bg-white/5 hover:bg-gray-100 dark:hover:bg-white/10" @click="setResolved(false)">Reopen</button>
        </div>

        <!-- AI summary -->
        <div v-if="ticket.summary" class="flex items-center gap-2.5 border-b border-gray-100 dark:border-white/10 bg-brand-gold/[0.06] px-5 py-2">
          <img src="/ai/avatar-96.webp" alt="" class="h-6 w-6 flex-none rounded-full bg-white ring-1 ring-brand-gold/60" />
          <p class="line-clamp-2 text-xs text-brand-navy/80 dark:text-white/80" :title="ticket.summary"><span class="font-semibold text-brand-navy dark:text-white">AI summary:</span> {{ ticket.summary }}</p>
        </div>

        <!-- Messages -->
        <div
          ref="threadEl"
          class="flex-1 min-h-0 overflow-y-auto px-5 py-4 space-y-1"
          style="background-image: radial-gradient(rgba(212,165,74,0.04) 1px, transparent 1px); background-size: 22px 22px;"
        >
          <template v-for="item in feed" :key="item.id">
            <div v-if="item.kind === 'date'" class="flex items-center justify-center my-4">
              <span class="text-[0.6875rem] font-medium text-gray-400 dark:text-white/40 bg-gray-100 dark:bg-white/5 px-3 py-1 rounded-full">{{ item.label }}</span>
            </div>

            <div v-else-if="item.message.sender_type === 'system'" class="flex justify-center my-3">
              <span class="max-w-[85%] text-center text-[0.6875rem] text-gray-500 dark:text-white/50 bg-gray-100 dark:bg-white/5 px-3 py-1.5 rounded-2xl">{{ item.message.body }}</span>
            </div>

            <!-- The person on the left; the team and RealtyLink AI on our side -->
            <div
              v-else
              class="flex items-end gap-2.5"
              :class="[item.message.sender_type === 'user' ? 'flex-row' : 'flex-row-reverse', item.showMeta ? 'mt-3' : 'mt-0.5']"
            >
              <div class="w-8 flex-shrink-0">
                <template v-if="item.showMeta">
                  <AppAvatar v-if="item.message.sender_type === 'user'" :name="ticket.user?.name" :src="ticket.user?.avatar" size="sm" />
                  <img v-else-if="item.message.sender_type === 'ai'" src="/ai/avatar-96.webp" alt="RealtyLink AI" class="h-8 w-8 rounded-full object-cover bg-white ring-1 ring-brand-gold/50 shadow-sm" />
                </template>
              </div>
              <div class="max-w-[72%] flex flex-col" :class="item.message.sender_type === 'user' ? 'items-start' : 'items-end'">
                <p
                  v-if="item.showMeta && item.message.sender_type !== 'user'"
                  class="text-[0.625rem] font-semibold mb-1 mr-1"
                  :class="item.message.sender_type === 'ai' ? 'text-brand-gold-deep dark:text-brand-gold' : 'text-gray-500 dark:text-white/50'"
                >{{ senderLabel(item.message) }}</p>
                <div v-if="item.message.attachments.length" class="mb-1 flex flex-wrap gap-1.5" :class="item.message.sender_type === 'user' ? '' : 'justify-end'">
                  <a v-for="(src, i) in item.message.attachments" :key="i" :href="src" target="_blank" rel="noopener" class="block h-28 w-36 overflow-hidden rounded-xl border border-gray-200 dark:border-white/10 shadow-sm hover:border-brand-gold"><img :src="src" alt="Screenshot" class="h-full w-full object-cover" /></a>
                </div>
                <div
                  v-if="item.message.body"
                  class="px-4 py-2.5 text-sm leading-relaxed break-words whitespace-pre-line shadow-sm"
                  :class="item.message.sender_type === 'user'
                    ? 'bg-gray-100 dark:bg-white/10 text-brand-navy dark:text-white rounded-2xl rounded-bl-md'
                    : item.message.sender_type === 'ai'
                      ? 'bg-brand-gold/10 dark:bg-brand-gold/15 border border-brand-gold/30 text-brand-navy dark:text-white rounded-2xl rounded-br-md'
                      : 'text-white rounded-2xl rounded-br-md'"
                  :style="item.message.sender_type === 'admin' ? 'background: linear-gradient(135deg, #17417F 0%, #0B2A5C 100%)' : ''"
                >{{ item.message.body }}</div>
                <span class="text-[0.625rem] text-gray-400 dark:text-white/30 mt-1 px-1">{{ timeOf(item.message.created_at) }}</span>
              </div>
            </div>
          </template>
        </div>

        <!-- Reply -->
        <div v-if="ticket.status !== 'resolved'" class="px-4 py-3 border-t border-gray-100 dark:border-white/10">
          <div v-if="previews.length" class="mb-2 flex gap-2 px-1">
            <span v-for="(src, i) in previews" :key="src" class="relative h-14 w-16 overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
              <img :src="src" alt="" class="h-full w-full object-cover" />
              <button type="button" class="absolute right-0.5 top-0.5 h-5 w-5 rounded-full bg-black/60 text-white text-xs leading-5" :aria-label="`Remove screenshot ${i + 1}`" @click="removeFile(i)">×</button>
            </span>
          </div>

          <!-- Helpers -->
          <div class="mb-2 flex flex-wrap items-center gap-1.5">
            <button v-if="ticket.suggested_reply && !reply" type="button" class="inline-flex items-center gap-1.5 rounded-full bg-brand-gold/10 py-1 pl-1 pr-3 text-xs font-semibold text-brand-gold-deep border border-brand-gold/30 hover:bg-brand-gold/20 dark:text-brand-gold" @click="useDraft">
              <img src="/ai/avatar-96.webp" alt="" class="h-5 w-5 rounded-full bg-white" />
              Use AI suggestion
            </button>
            <div class="relative">
              <button type="button" class="rounded-full px-3 py-1 text-xs font-semibold text-gray-500 dark:text-white/60 bg-gray-50 dark:bg-white/5 border border-gray-100 dark:border-white/10 hover:border-brand-gold/40" :aria-expanded="quickOpen" @click="quickOpen = !quickOpen">Quick replies ▾</button>
              <div v-if="quickOpen" class="absolute bottom-full left-0 z-20 mb-2 w-72 rounded-2xl bg-white dark:bg-[#0F2347] border border-gray-200 dark:border-white/10 shadow-lg p-2">
                <p v-if="!quick.length" class="px-2 py-2 text-xs text-gray-400 dark:text-white/50">No quick replies yet.</p>
                <button v-for="q in quick" :key="q.id" type="button" class="block w-full rounded-xl px-3 py-2 text-left hover:bg-gray-50 dark:hover:bg-white/[0.06]" @click="insertQuick(q)">
                  <span class="block text-sm font-semibold text-brand-navy dark:text-white">{{ q.title }}</span>
                  <span class="block truncate text-xs text-gray-400 dark:text-white/50">{{ q.body }}</span>
                </button>
                <button type="button" class="mt-1 block w-full rounded-xl px-3 py-2 text-left text-xs font-semibold text-brand-gold-deep hover:bg-brand-gold/10 dark:text-brand-gold" @click="quickOpen = false; managing = true">Manage quick replies…</button>
              </div>
            </div>
            <button type="button" class="rounded-full px-3 py-1 text-xs font-semibold text-gray-500 dark:text-white/60 bg-gray-50 dark:bg-white/5 border border-gray-100 dark:border-white/10 hover:border-brand-gold/40 disabled:opacity-50" :disabled="suggesting" @click="refreshDraft">{{ suggesting ? 'Drafting…' : 'New AI draft' }}</button>
          </div>

          <!-- Input bar, as in Messages -->
          <div class="flex items-end gap-2 bg-gray-50 dark:bg-white/5 rounded-3xl px-2 py-1.5 border border-gray-100 dark:border-white/10 focus-within:border-brand-gold/40 transition-colors">
            <input ref="fileEl" type="file" accept="image/*" multiple class="sr-only" @change="pickFiles" />
            <button type="button" class="p-2 text-gray-400 hover:text-brand-gold transition-colors" title="Attach a screenshot" aria-label="Attach a screenshot" @click="fileEl?.click()">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
            </button>
            <textarea
              v-model="reply"
              rows="1"
              maxlength="2000"
              :placeholder="ticket.suggested_reply
                ? `AI suggestion (Tab to use): ${ticket.suggested_reply}`
                : `Reply to ${ticket.user?.name?.split(' ')[0] ?? 'them'}…  (Ctrl + Enter to send)`"
              aria-label="Reply"
              class="max-h-32 min-h-[2.25rem] flex-1 resize-none border-0 bg-transparent p-0 py-2 text-sm text-brand-navy dark:text-white placeholder-gray-400 focus:ring-0 outline-none [field-sizing:content]"
              @keydown="onKeydown"
            />
            <button
              type="button"
              class="mb-0.5 h-9 w-9 rounded-full flex items-center justify-center bg-brand-gold transition-all disabled:opacity-40 flex-shrink-0"
              :disabled="(!reply.trim() && !files.length) || sending"
              :title="sending ? 'Sending…' : 'Send reply (Ctrl + Enter)'"
              aria-label="Send reply"
              @click="send"
            >
              <svg class="h-4 w-4 rotate-90 text-[#0B2A5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" /></svg>
            </button>
          </div>
        </div>
        <p v-else class="px-5 py-3 border-t border-gray-100 dark:border-white/10 text-xs text-gray-500 dark:text-white/50">
          Resolved<template v-if="ticket.resolved_by"> by {{ ticket.resolved_by }}</template><template v-if="ticket.resolved_at"> {{ ago(ticket.resolved_at) }}</template><template v-if="ticket.rating"> · rated {{ ticket.rating === 'up' ? '👍 helpful' : '👎 not helpful' }}</template>.
        </p>
      </template>
    </div>

    <!-- Manage quick replies -->
    <AppModal :open="managing" title="Quick replies" @close="managing = false">
      <div class="space-y-4 p-6">
        <ul v-if="quick.length" class="space-y-2">
          <li v-for="q in quick" :key="q.id" class="flex items-start gap-3 rounded-xl bg-brand-cream/80 dark:bg-white/[0.05] p-3">
            <div class="min-w-0 flex-1">
              <p class="text-sm font-semibold text-brand-navy dark:text-white">{{ q.title }}</p>
              <p class="text-xs text-brand-navy/60 dark:text-white/55 whitespace-pre-line">{{ q.body }}</p>
            </div>
            <button type="button" class="flex-none text-xs font-semibold text-red-600 hover:underline dark:text-red-400" @click="deleteQuick(q)">Delete</button>
          </li>
        </ul>
        <div class="space-y-2">
          <input v-model="newQuick.title" maxlength="80" placeholder="Title, e.g. Verification takes up to 24 hours" class="w-full rounded-xl border-0 bg-brand-navy/[0.04] dark:bg-white/[0.06] px-3 py-2 text-sm text-brand-navy dark:text-white ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold" />
          <textarea v-model="newQuick.body" rows="3" maxlength="2000" placeholder="The reply. {name} becomes the person's first name." class="w-full resize-none rounded-xl border-0 bg-brand-navy/[0.04] dark:bg-white/[0.06] px-3 py-2 text-sm text-brand-navy dark:text-white ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold" />
          <button type="button" class="rounded-full bg-brand-gold px-4 py-2 text-sm font-bold text-[#0B2A5C] hover:bg-brand-gold-light disabled:opacity-50" :disabled="!newQuick.title.trim() || !newQuick.body.trim()" @click="saveQuick">Add quick reply</button>
        </div>
      </div>
    </AppModal>
  </div>
  </div>
</template>
