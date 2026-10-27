<script setup lang="ts">
import type { Conversation, Message } from '~/types'

/**
 * Messages, as a docked side panel (see layouts/dashboard): the chat list, and
 * a quick-reply thread inside the panel so a buyer or agent can answer without
 * leaving the page they're on.
 *
 * The thread uses the same store, send path and realtime listeners as the
 * full Messages page (optimistic send, delivered/seen ticks, typing and AI
 * typing). On the Messages page itself the panel never opens a thread — it
 * hands the chat to the page (?c=), so the two never hold the same thread.
 */
const emit = defineEmits<{ close: [] }>()

const authStore = useAuthStore()
const convStore = useConversationStore()
const route = useRoute()
const { fetchConversations, fetchMessages, sendMessage, markRead, loading } = useConversation()
const {
  listenToConversation, stopListeningToConversation,
  listenForReceipts, listenForAiTyping, listenForTyping, whisperTyping, whisperSeen,
} = useEcho()

const me = computed(() => authStore.user?.id)
const supportUnread = useState('support-unread', () => 0)   // kept fresh by the dashboard layout
const onMessagesPage = computed(() => route.path === '/dashboard/messages')

/* ── List ────────────────────────────────────────────────────────────── */
const search = ref('')
/* Shared with the dashboard, so "Inquiries" links can open this tab directly. */
const { messagesTab: tab } = useDashPanel()
if (!authStore.isAgent && tab.value === 'inquiries') tab.value = 'all'
const TABS = computed(() => (authStore.isAgent ? (['all', 'unread', 'inquiries'] as const) : (['all', 'unread'] as const)))
function other(c: Conversation) { return me.value === c.buyer_id ? c.agent : c.buyer }

const list = computed(() => {
  const q = search.value.trim().toLowerCase()
  return [...convStore.conversations]
    .filter(c => tab.value === 'all' || c.unread_count)
    .filter(c => !q
      || (other(c)?.name ?? '').toLowerCase().includes(q)
      || (c.property?.title ?? '').toLowerCase().includes(q)
      || (c.latest_message?.body ?? '').toLowerCase().includes(q))
    .sort((a, b) => (b.last_message_at ?? '').localeCompare(a.last_message_at ?? ''))
})

function when(iso?: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  const mins = Math.floor((Date.now() - d.getTime()) / 60_000)
  if (mins < 1) return 'now'
  if (mins < 60) return `${mins}m`
  if (mins < 60 * 24) return `${Math.floor(mins / 60)}h`
  if (mins < 60 * 24 * 7) return d.toLocaleDateString('en-PH', { weekday: 'short' })
  return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
}
function preview(c: Conversation): string {
  const m = c.latest_message
  if (!m) return 'Say hello 👋'
  return `${m.sender_id === me.value ? 'You: ' : ''}${m.body}`
}

/* ── Inquiries (agents): questions sent from a listing's contact form ── */
const { inquiries, unreadCount: inquiryUnread, fetchInquiries, markRead: markInquiryRead, loading: inquiriesLoading } = useInquiry()
const inquiryList = computed(() => {
  const q = search.value.trim().toLowerCase()
  return [...inquiries.value]
    .filter(i => !q || `${i.name} ${i.message} ${i.property?.title ?? ''}`.toLowerCase().includes(q))
    .sort((a, b) => +new Date(b.created_at) - +new Date(a.created_at))
})
const inqId = ref<number | null>(null)
const inquiry = computed(() => inquiries.value.find(i => i.id === inqId.value) ?? null)
function openInquiry(id: number) {
  inqId.value = id
  const i = inquiries.value.find(x => x.id === id)
  if (i && !i.is_read) markInquiryRead(id)
}
/* A signed-in buyer who already chats with you about this listing can be answered in that chat. */
const inquiryChat = computed(() => {
  const i = inquiry.value
  if (!i?.user_id) return null
  return convStore.conversations.find(c => c.buyer_id === i.user_id && c.property_id === i.property_id)
    ?? convStore.conversations.find(c => c.buyer_id === i.user_id) ?? null
})
function replyInChat() {
  const c = inquiryChat.value
  if (!c) return
  inqId.value = null
  openThread(c)
}
function fullDate(iso: string) {
  return new Date(iso).toLocaleString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}

/* ── Thread ──────────────────────────────────────────────────────────── */
const openId = ref<number | null>(null)
const thread = computed(() => convStore.conversations.find(c => c.id === openId.value) ?? null)
const threadEl = ref<HTMLElement | null>(null)
const draft = ref('')
const inputEl = ref<HTMLTextAreaElement | null>(null)
const otherTyping = ref(false)
const aiTyping = ref(false)
let typingClear: ReturnType<typeof setTimeout> | undefined
let aiClear: ReturnType<typeof setTimeout> | undefined

function scrollToBottom() {
  nextTick(() => { if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight })
}

async function openThread(c: Conversation) {
  if (onMessagesPage.value) {
    emit('close')
    await navigateTo(`/dashboard/messages?c=${c.id}`)
    return
  }
  closeThread()
  openId.value = c.id
  convStore.setActive(c.id)
  convStore.clearUnread(c.id)   // badge clears on open; markRead below tells the server
  await fetchMessages(c.id)
  scrollToBottom()
  await markRead(c.id)
  whisperSeen(c.id)

  listenToConversation(c.id, (msg: Message) => {
    convStore.addMessage(msg)
    otherTyping.value = false
    if (msg.is_ai) { aiTyping.value = false; clearTimeout(aiClear) }
    if (msg.sender_id !== me.value) { markRead(c.id); whisperSeen(c.id) }
    scrollToBottom()
  })
  listenForReceipts(c.id, (e) => {
    if (e.by_user_id !== me.value) convStore.applyReceipt(e.kind, e.message_ids, e.at)
  })
  listenForAiTyping(c.id, () => {
    aiTyping.value = true
    clearTimeout(aiClear)
    aiClear = setTimeout(() => { aiTyping.value = false }, 20_000)
    scrollToBottom()
  })
  listenForTyping(c.id, (userId) => {
    if (userId === me.value) return
    otherTyping.value = true
    clearTimeout(typingClear)
    typingClear = setTimeout(() => { otherTyping.value = false }, 2500)
  })
  nextTick(() => inputEl.value?.focus())
}

function closeThread() {
  if (openId.value) stopListeningToConversation(openId.value)
  if (convStore.activeId === openId.value) convStore.activeId = null
  openId.value = null
  otherTyping.value = false
  aiTyping.value = false
  draft.value = ''
}
onUnmounted(closeThread)

/* Leaving for the Messages page hands the thread over. */
watch(onMessagesPage, (on) => { if (on) closeThread() })

let lastWhisper = 0
function onType() {
  autosize()
  const now = Date.now()
  if (openId.value && now - lastWhisper > 1200) { lastWhisper = now; whisperTyping(openId.value) }
}
function autosize() {
  const el = inputEl.value
  if (!el) return
  el.style.height = 'auto'
  el.style.height = `${Math.min(el.scrollHeight, 120)}px`
}
async function send() {
  const body = draft.value.trim()
  if (!body || !openId.value) return
  draft.value = ''
  nextTick(autosize)
  const p = sendMessage(openId.value, body)
  scrollToBottom()
  await p
}
function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send() }
}
function retry(m: Message) {
  if (m.client_id && openId.value) sendMessage(openId.value, m.body, m.client_id)
}

/* Feed with day separators. */
function dayLabel(d: Date) {
  const today = new Date(); const y = new Date(); y.setDate(today.getDate() - 1)
  if (d.toDateString() === today.toDateString()) return 'Today'
  if (d.toDateString() === y.toDateString()) return 'Yesterday'
  return d.toLocaleDateString('en-PH', { month: 'long', day: 'numeric' })
}
const feed = computed(() => {
  const out: Array<{ kind: 'day'; id: string; label: string } | { kind: 'msg'; id: number; m: Message; first: boolean }> = []
  let lastDay = ''
  let lastSender: number | null = null
  for (const m of openId.value ? convStore.activeMessages : []) {
    const d = new Date(m.created_at)
    if (d.toDateString() !== lastDay) { out.push({ kind: 'day', id: `d${d.toDateString()}`, label: dayLabel(d) }); lastDay = d.toDateString(); lastSender = null }
    out.push({ kind: 'msg', id: m.id, m, first: lastSender !== m.sender_id })
    lastSender = m.sender_id
  }
  return out
})
function clock(iso: string) {
  return new Date(iso).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })
}
type Tick = 'sending' | 'failed' | 'sent' | 'delivered' | 'seen'
function tickOf(m: Message): Tick {
  if (m.local_status === 'failed') return 'failed'
  if (m.local_status === 'sending') return 'sending'
  if (m.read_at) return 'seen'
  if (m.delivered_at) return 'delivered'
  return 'sent'
}

/* Fresh lists each time the panel opens. */
onMounted(() => { fetchConversations(); if (authStore.isAgent) fetchInquiries() })

/* ── Open straight into a chat ("Message agent" on a listing) ───────── */
const { chatTarget, chatDraft } = useDashPanel()
watch(chatTarget, async (id) => {
  if (!id) return
  chatTarget.value = null
  const handed = chatDraft.value
  chatDraft.value = null
  let c = convStore.conversations.find(x => x.id === id)
  if (!c) { await fetchConversations(); c = convStore.conversations.find(x => x.id === id) }
  if (c && openId.value !== c.id) await openThread(c)
  if (c && handed) {
    draft.value = handed
    nextTick(() => { autosize(); inputEl.value?.focus() })
  }
}, { immediate: true })

/* ── Delete (from this user's inbox only), always confirmed ─────────── */
const confirm = useConfirm()
const toast = useToast()
const { deleteConversation } = useConversation()
async function removeChat(c: Conversation) {
  const name = other(c)?.name ?? 'this person'
  const ok = await confirm({
    title: 'Delete this conversation?',
    message: 'It will be removed from your inbox.',
    subject: `${name}${c.property?.title ? ` · ${c.property.title}` : ''}`,
    consequences: [
      `${name} keeps their copy; this only clears it from your side`,
      'If either of you sends a new message, it comes back',
    ],
    confirmLabel: 'Delete conversation',
    busyLabel: 'Deleting…',
    tone: 'danger',
    icon: 'chat',
    action: async () => (await deleteConversation(c.id)) || 'Couldn’t delete the conversation. Please try again.',
  })
  if (!ok) return
  if (openId.value === c.id) closeThread()
  toast.success('Conversation deleted')
}
</script>

<template>
  <div class="relative flex h-full flex-col overflow-hidden">
    <Transition :name="openId || inqId ? 'slide-in' : 'slide-out'" mode="out-in">
      <!-- ░░ List ░░ -->
      <div v-if="!openId && !inqId" key="list" class="flex h-full flex-col">
        <div class="flex items-center justify-between gap-3 px-5 pt-5 pb-3">
          <h2 class="font-heading text-2xl font-semibold text-brand-navy dark:text-white">Messages</h2>
          <button type="button" class="h-9 w-9 rounded-full flex items-center justify-center text-brand-navy/55 hover:text-brand-navy hover:bg-brand-navy/[0.06] dark:text-white/55 dark:hover:text-white dark:hover:bg-white/10 transition" aria-label="Close messages" @click="emit('close')">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </div>

        <div class="flex items-center gap-2 px-5 pb-3">
          <label class="flex-1 flex items-center gap-2 rounded-full bg-brand-navy/[0.05] dark:bg-white/[0.07] px-4 py-2.5 focus-within:ring-2 focus-within:ring-brand-gold transition">
            <svg class="h-4 w-4 flex-none text-brand-navy/45 dark:text-white/45" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
            <input v-model="search" type="search" :placeholder="tab === 'inquiries' ? 'Search inquiries' : 'Search messages'" aria-label="Search"
                   class="w-full border-0 bg-transparent p-0 text-sm text-brand-navy dark:text-white placeholder-brand-navy/45 dark:placeholder-white/40 focus:ring-0" />
          </label>
          <!-- Buyers start chats from a listing; agents reply to them. -->
          <NuxtLink
            v-if="authStore.isBuyer"
            to="/dashboard/browse"
            class="flex-none h-10 w-10 rounded-full bg-brand-gold text-brand-navy flex items-center justify-center shadow-[0_4px_14px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light hover:scale-105 transition"
            title="New chat — message an agent from any listing"
            aria-label="Start a new chat from a listing"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
          </NuxtLink>
        </div>

        <div class="flex gap-1 px-5 pb-3 border-b border-brand-navy/[0.06] dark:border-white/10" role="tablist">
          <button
            v-for="t in TABS"
            :key="t"
            type="button"
            role="tab"
            :aria-selected="tab === t"
            class="inline-flex items-center rounded-full px-3.5 py-1.5 text-sm font-semibold transition-colors"
            :class="tab === t ? 'bg-brand-navy text-white dark:bg-white dark:text-brand-navy' : 'text-brand-navy/65 hover:bg-brand-navy/5 dark:text-white/60 dark:hover:bg-white/10'"
            @click="tab = t"
          >
            {{ t === 'all' ? 'All chats' : t === 'unread' ? 'Unread' : 'Inquiries' }}
            <span v-if="t === 'unread' && convStore.totalUnread" class="ml-1 tabular-nums opacity-70">{{ convStore.totalUnread }}</span>
            <span v-if="t === 'inquiries' && inquiryUnread" class="ml-1.5 h-5 min-w-[1.25rem] px-1.5 rounded-full bg-brand-gold text-[0.625rem] font-bold text-[#0B2A5C] flex items-center justify-center">{{ inquiryUnread }}</span>
          </button>
        </div>

        <!-- ── Inquiries (agents) ── -->
        <div v-if="tab === 'inquiries'" class="flex-1 overflow-y-auto overscroll-contain px-2 py-2">
          <p class="px-3 pb-2 text-[0.6875rem] text-brand-navy/50 dark:text-white/45">Questions buyers sent from your listings' contact form.</p>
          <div v-if="inquiriesLoading && !inquiries.length" class="space-y-2 p-2">
            <div v-for="i in 3" :key="i" class="h-16 rounded-2xl bg-brand-navy/[0.05] dark:bg-white/[0.06] animate-pulse" />
          </div>
          <div v-else-if="!inquiryList.length" class="m-2 rounded-2xl bg-brand-cream dark:bg-white/[0.04] px-6 py-12 text-center">
            <span class="mx-auto mb-4 h-14 w-14 rounded-2xl flex items-center justify-center bg-white dark:bg-white/[0.06] ring-1 ring-brand-navy/[0.06] dark:ring-white/10 text-brand-gold-deep dark:text-brand-gold">
              <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 13.5h3.86a2.25 2.25 0 012.012 1.244l.256.512a2.25 2.25 0 002.013 1.244h3.218a2.25 2.25 0 002.013-1.244l.256-.512a2.25 2.25 0 012.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 00-2.15-1.588H6.911a2.25 2.25 0 00-2.15 1.588L2.35 13.177a2.25 2.25 0 00-.1.661z" /></svg>
            </span>
            <p class="font-semibold text-brand-navy dark:text-white">{{ search ? 'No inquiries match' : 'No inquiries yet' }}</p>
            <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/50 leading-relaxed">When a buyer asks about a listing through its contact form, it lands here.</p>
          </div>
          <button
            v-for="i in inquiryList"
            v-else
            :key="i.id"
            type="button"
            class="w-full text-left flex items-start gap-3 rounded-2xl px-3 py-3 transition-colors hover:bg-brand-navy/[0.035] dark:hover:bg-white/[0.04]"
            :class="!i.is_read ? 'bg-brand-gold/[0.07]' : ''"
            @click="openInquiry(i.id)"
          >
            <span class="relative flex-none">
              <AppAvatar :name="i.name" size="md" />
              <span v-if="!i.is_read" class="absolute -top-0.5 -right-0.5 h-3 w-3 rounded-full bg-brand-gold ring-2 ring-white dark:ring-[#0b1f45]" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="flex items-center justify-between gap-2">
                <span class="flex min-w-0 items-center gap-1.5">
                  <span class="truncate text-sm text-brand-navy dark:text-white" :class="i.is_read ? 'font-semibold' : 'font-bold'">{{ i.name }}</span>
                  <span v-if="i.is_ghost_buyer" class="flex-none rounded bg-brand-navy/[0.07] dark:bg-white/10 px-1.5 py-0.5 text-[0.5625rem] font-bold uppercase text-brand-navy/55 dark:text-white/55">Guest</span>
                </span>
                <span class="flex-none text-[0.6875rem]" :class="!i.is_read ? 'font-bold text-brand-gold-deep dark:text-brand-gold' : 'text-brand-navy/40 dark:text-white/40'">{{ when(i.created_at) }}</span>
              </span>
              <span class="block truncate text-[0.6875rem] text-brand-navy/45 dark:text-white/40">{{ i.property?.title ?? `Property #${i.property_id}` }}</span>
              <span class="mt-0.5 block truncate text-xs" :class="!i.is_read ? 'font-medium text-brand-navy dark:text-white' : 'text-brand-navy/55 dark:text-white/55'">{{ i.message }}</span>
            </span>
          </button>
        </div>

        <div v-else class="flex-1 overflow-y-auto overscroll-contain px-2 py-2">
          <!-- Pinned: Help & support (RealtyLink AI, then the team) -->
          <NuxtLink
            v-if="!search && tab === 'all'"
            to="/dashboard/support"
            class="mb-1 flex items-center gap-3 rounded-2xl px-3 py-3 ring-1 ring-brand-gold/30 bg-brand-gold/[0.06] hover:bg-brand-gold/[0.12] transition-colors"
            @click="emit('close')"
          >
            <img src="/ai/avatar-96.webp" alt="" class="h-11 w-11 flex-none rounded-full object-cover bg-white ring-2 ring-brand-gold/60" />
            <span class="min-w-0 flex-1">
              <span class="flex items-center gap-1.5 text-sm font-semibold text-brand-navy dark:text-white">
                RealtyLink Support
                <span class="rounded-full bg-brand-navy/[0.07] dark:bg-white/10 px-1.5 py-0.5 text-[0.5625rem] font-bold uppercase text-brand-navy/60 dark:text-white/60">Pinned</span>
              </span>
              <span class="block truncate text-xs text-brand-navy/55 dark:text-white/50">Something not working? Ask RealtyLink AI or our team.</span>
            </span>
            <span v-if="supportUnread" class="flex-none min-w-[1.25rem] h-5 rounded-full bg-brand-gold px-1.5 text-xs font-bold leading-5 text-center text-[#0B2A5C]">{{ supportUnread }}</span>
          </NuxtLink>

          <div v-if="loading && !convStore.conversations.length" class="space-y-2 p-2">
            <div v-for="i in 4" :key="i" class="flex items-center gap-3 animate-pulse p-2">
              <span class="h-11 w-11 rounded-full bg-brand-navy/[0.06] dark:bg-white/10" />
              <span class="flex-1 space-y-2"><span class="block h-3 w-1/2 rounded bg-brand-navy/[0.06] dark:bg-white/10" /><span class="block h-2.5 w-3/4 rounded bg-brand-navy/[0.06] dark:bg-white/10" /></span>
            </div>
          </div>

          <div v-else-if="!list.length" class="m-2 rounded-2xl bg-brand-cream dark:bg-white/[0.04] px-6 py-12 text-center">
            <span class="mx-auto mb-4 h-14 w-14 rounded-2xl flex items-center justify-center bg-white dark:bg-white/[0.06] ring-1 ring-brand-navy/[0.06] dark:ring-white/10 text-brand-gold-deep dark:text-brand-gold">
              <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" /></svg>
            </span>
            <p class="font-semibold text-brand-navy dark:text-white">
              {{ search ? 'No chats match' : tab === 'unread' ? 'No unread messages' : 'No messages yet' }}
            </p>
            <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/50 leading-relaxed">
              <template v-if="search">Try a name, a property or something they said.</template>
              <template v-else-if="tab === 'unread'">You’ve read everything.</template>
              <template v-else-if="authStore.isAgent">When buyers message you about a listing, the chat appears here.</template>
              <template v-else>Open any listing and tap “Message agent” to start a chat.</template>
            </p>
          </div>

          <div v-for="c in list" v-else :key="c.id" class="group relative">
          <button
            type="button"
            class="w-full text-left flex items-center gap-3 rounded-2xl px-3 py-3 transition-colors hover:bg-brand-navy/[0.035] dark:hover:bg-white/[0.04]"
            :class="c.unread_count ? 'bg-brand-gold/[0.07]' : ''"
            @click="openThread(c)"
          >
            <AppAvatar :name="other(c)?.name" :src="other(c)?.avatar" size="md" />
            <span class="min-w-0 flex-1">
              <span class="flex items-center justify-between gap-2">
                <span class="truncate text-sm text-brand-navy dark:text-white" :class="c.unread_count ? 'font-bold' : 'font-semibold'">{{ other(c)?.name ?? 'Unknown' }}</span>
                <span class="flex-none text-[0.6875rem]" :class="c.unread_count ? 'font-bold text-brand-gold-deep dark:text-brand-gold' : 'text-brand-navy/40 dark:text-white/40'">{{ when(c.last_message_at) }}</span>
              </span>
              <span v-if="c.property?.title" class="block truncate text-[0.6875rem] text-brand-navy/45 dark:text-white/40">{{ c.property.title }}</span>
              <span class="mt-0.5 flex items-center justify-between gap-2">
                <span class="truncate text-xs" :class="c.unread_count ? 'font-medium text-brand-navy dark:text-white' : 'text-brand-navy/55 dark:text-white/55'">
                  <span v-if="c.latest_message?.is_ai" class="font-semibold text-brand-gold-deep dark:text-brand-gold">AI: </span>{{ preview(c) }}
                </span>
                <span v-if="c.unread_count" class="flex-none h-5 min-w-[1.25rem] px-1.5 rounded-full bg-brand-gold text-[0.625rem] font-bold text-brand-navy flex items-center justify-center">{{ c.unread_count }}</span>
              </span>
            </span>
          </button>
          <!-- Delete: on hover/focus with a mouse; touch screens use the
               trash button in the open chat's header instead. -->
          <button
            type="button"
            class="absolute right-2.5 top-2.5 hidden [@media(hover:hover)]:flex h-8 w-8 items-center justify-center rounded-full
                   opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition
                   bg-[#ffffff] text-brand-navy/55 ring-1 ring-brand-navy/10 shadow-sm hover:text-red-600 hover:ring-red-300
                   dark:bg-[#12295a] dark:text-white/60 dark:ring-white/15 dark:hover:text-red-400 dark:hover:ring-red-400/50"
            :aria-label="`Delete conversation with ${other(c)?.name ?? 'this person'}`"
            title="Delete conversation"
            @click.stop="removeChat(c)"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
          </button>
          </div>
        </div>
      </div>

      <!-- ░░ One inquiry ░░ -->
      <div v-else-if="inqId && inquiry" key="inquiry" class="flex h-full flex-col">
        <div class="flex items-center gap-2 px-3 py-3 border-b border-brand-navy/[0.06] dark:border-white/10">
          <button type="button" class="h-9 w-9 flex-none rounded-full flex items-center justify-center text-brand-navy/60 hover:text-brand-navy hover:bg-brand-navy/[0.06] dark:text-white/60 dark:hover:text-white dark:hover:bg-white/10 transition" aria-label="Back to inquiries" @click="inqId = null">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
          </button>
          <AppAvatar :name="inquiry.name" size="sm" />
          <div class="min-w-0 flex-1">
            <p class="flex items-center gap-1.5 truncate text-sm font-bold text-brand-navy dark:text-white">
              {{ inquiry.name }}
              <span v-if="inquiry.is_ghost_buyer" class="rounded bg-brand-navy/[0.07] dark:bg-white/10 px-1.5 py-0.5 text-[0.5625rem] font-bold uppercase text-brand-navy/55 dark:text-white/55">Guest</span>
            </p>
            <p class="truncate text-[0.6875rem] text-brand-navy/45 dark:text-white/45">Inquiry · {{ fullDate(inquiry.created_at) }}</p>
          </div>
        </div>

        <div class="flex-1 overflow-y-auto overscroll-contain px-4 py-4 bg-brand-cream/60 dark:bg-black/10 space-y-4">
          <NuxtLink
            v-if="inquiry.property"
            :to="`/dashboard/listings/${inquiry.property_id}/edit`"
            class="flex items-center gap-3 rounded-2xl p-2 pr-3 bg-white dark:bg-white/[0.06] ring-1 ring-brand-navy/[0.07] dark:ring-white/10 hover:ring-brand-gold transition"
            @click="emit('close')"
          >
            <span v-if="inquiry.property.photos?.[0]" class="h-11 w-14 flex-none overflow-hidden rounded-xl bg-brand-navy/5 dark:bg-white/10">
              <img :src="inquiry.property.photos[0].thumb_url ?? inquiry.property.photos[0].url" alt="" class="h-full w-full object-cover" />
            </span>
            <span v-else class="h-11 w-11 flex-none rounded-xl flex items-center justify-center bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75" /></svg>
            </span>
            <span class="min-w-0">
              <span class="block text-[0.625rem] font-bold uppercase tracking-wide text-brand-navy/45 dark:text-white/40">About your listing</span>
              <span class="block truncate text-sm font-semibold text-brand-navy dark:text-white">{{ inquiry.property.title }}</span>
            </span>
          </NuxtLink>

          <div class="max-w-[92%] rounded-2xl rounded-bl-md px-3.5 py-2.5 text-sm leading-relaxed whitespace-pre-wrap break-words bg-white text-brand-navy ring-1 ring-brand-navy/[0.06] dark:bg-white/[0.08] dark:text-white dark:ring-white/10">{{ inquiry.message }}</div>

          <div class="rounded-2xl bg-white dark:bg-white/[0.06] ring-1 ring-brand-navy/[0.07] dark:ring-white/10 p-3 space-y-2">
            <p class="text-[0.6875rem] font-bold uppercase tracking-wide text-brand-navy/45 dark:text-white/40">Contact</p>
            <a v-if="inquiry.email" :href="`mailto:${inquiry.email}?subject=${encodeURIComponent(`Re: ${inquiry.property?.title ?? 'your inquiry'}`)}`" class="flex items-center gap-2 text-sm font-medium text-brand-navy hover:text-brand-gold-deep dark:text-white dark:hover:text-brand-gold">
              <svg class="h-4 w-4 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
              <span class="truncate">{{ inquiry.email }}</span>
            </a>
            <a v-if="inquiry.phone" :href="`tel:${inquiry.phone}`" class="flex items-center gap-2 text-sm font-medium text-brand-navy hover:text-brand-gold-deep dark:text-white dark:hover:text-brand-gold">
              <svg class="h-4 w-4 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>
              {{ inquiry.phone }}
            </a>
          </div>
        </div>

        <!-- Extra room below so the buttons sit clear of the window's bottom edge -->
        <div class="flex flex-wrap gap-2 px-3 pt-3 pb-6 border-t border-brand-navy/[0.06] dark:border-white/10">
          <button
            v-if="inquiryChat"
            type="button"
            class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-full bg-brand-gold px-4 py-2.5 text-xs font-bold text-[#0B2A5C] shadow-[0_4px_14px_rgba(212,165,74,0.4)] hover:bg-brand-gold-light transition"
            @click="replyInChat"
          >Reply in chat</button>
          <a
            v-if="inquiry.email"
            :href="`mailto:${inquiry.email}?subject=${encodeURIComponent(`Re: ${inquiry.property?.title ?? 'your inquiry'}`)}`"
            class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-full px-4 py-2.5 text-xs font-bold transition"
            :class="inquiryChat ? 'text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20' : 'bg-brand-gold text-[#0B2A5C] shadow-[0_4px_14px_rgba(212,165,74,0.4)] hover:bg-brand-gold-light'"
          >Reply by email</a>
          <a
            v-if="inquiry.phone"
            :href="`tel:${inquiry.phone}`"
            class="flex-1 inline-flex items-center justify-center rounded-full px-4 py-2.5 text-xs font-bold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 transition"
          >Call</a>
        </div>
      </div>

      <!-- ░░ Thread (quick reply) ░░ -->
      <div v-else key="thread" class="flex h-full flex-col">
        <div class="flex items-center gap-2 px-3 py-3 border-b border-brand-navy/[0.06] dark:border-white/10">
          <button type="button" class="h-9 w-9 flex-none rounded-full flex items-center justify-center text-brand-navy/60 hover:text-brand-navy hover:bg-brand-navy/[0.06] dark:text-white/60 dark:hover:text-white dark:hover:bg-white/10 transition" aria-label="Back to chats" @click="closeThread">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
          </button>
          <AppAvatar v-if="thread" :name="other(thread)?.name" :src="other(thread)?.avatar" size="sm" />
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-bold text-brand-navy dark:text-white">{{ thread ? other(thread)?.name : '' }}</p>
            <p class="truncate text-[0.6875rem]" :class="otherTyping || aiTyping ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-brand-navy/45 dark:text-white/45'">
              {{ aiTyping ? 'RealtyLink AI is replying…' : otherTyping ? 'typing…' : thread?.property?.title }}
            </p>
          </div>
          <button
            v-if="thread"
            type="button"
            class="h-9 w-9 flex-none rounded-full flex items-center justify-center text-brand-navy/60 hover:text-red-600 hover:bg-red-500/10 dark:text-white/60 dark:hover:text-red-400 transition"
            title="Delete conversation"
            aria-label="Delete conversation"
            @click="removeChat(thread)"
          >
            <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
          </button>
          <NuxtLink
            :to="`/dashboard/messages?c=${openId}`"
            class="h-9 w-9 flex-none rounded-full flex items-center justify-center text-brand-navy/60 hover:text-brand-navy hover:bg-brand-navy/[0.06] dark:text-white/60 dark:hover:text-white dark:hover:bg-white/10 transition"
            title="Open full chat"
            aria-label="Open full chat"
            @click="closeThread(); emit('close')"
          >
            <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" /></svg>
          </NuxtLink>
        </div>

        <div ref="threadEl" class="flex-1 overflow-y-auto overscroll-contain px-4 py-4 space-y-1 bg-brand-cream/60 dark:bg-black/10">
          <div v-if="loading && !convStore.activeMessages.length" class="py-10 text-center text-xs text-brand-navy/45 dark:text-white/45">Loading messages…</div>
          <div v-else-if="!convStore.activeMessages.length" class="h-full flex flex-col items-center justify-center text-center px-6">
            <AppAvatar v-if="thread" :name="other(thread)?.name" :src="other(thread)?.avatar" size="lg" />
            <p class="mt-3 font-semibold text-brand-navy dark:text-white">Start the conversation</p>
            <p class="mt-1 text-xs text-brand-navy/50 dark:text-white/50 leading-relaxed">
              Say hello to {{ thread ? other(thread)?.name : 'them' }}<template v-if="thread?.property?.title"> about {{ thread.property.title }}</template>.
            </p>
          </div>
          <template v-for="item in feed" :key="item.id">
            <p v-if="item.kind === 'day'" class="py-3 text-center text-[0.6875rem] font-semibold text-brand-navy/40 dark:text-white/40">{{ item.label }}</p>
            <div v-else class="flex flex-col" :class="[item.m.sender_id === me ? 'items-end' : 'items-start', item.first ? 'mt-2' : '']">
              <p v-if="item.m.is_ai && item.first" class="mb-1 text-[0.625rem] font-bold uppercase tracking-wide text-brand-gold-deep dark:text-brand-gold">RealtyLink AI</p>
              <div
                class="max-w-[82%] rounded-2xl px-3.5 py-2 text-sm leading-relaxed whitespace-pre-wrap break-words"
                :class="item.m.sender_id === me
                  ? 'bg-brand-navy text-white rounded-br-md dark:bg-brand-gold dark:text-brand-navy'
                  : item.m.is_ai
                    ? 'bg-brand-gold/15 text-brand-navy ring-1 ring-brand-gold/30 rounded-bl-md dark:text-white'
                    : 'bg-white text-brand-navy ring-1 ring-brand-navy/[0.06] rounded-bl-md dark:bg-white/[0.08] dark:text-white dark:ring-white/10'"
              >{{ item.m.body }}</div>
              <p class="mt-0.5 flex items-center gap-1 text-[0.625rem] text-brand-navy/40 dark:text-white/40">
                {{ clock(item.m.created_at) }}
                <template v-if="item.m.sender_id === me">
                  <span v-if="tickOf(item.m) === 'failed'" class="font-semibold text-red-500">
                    · Not sent · <button type="button" class="underline" @click="retry(item.m)">Retry</button>
                  </span>
                  <span v-else-if="tickOf(item.m) === 'sending'">· Sending…</span>
                  <span v-else :class="tickOf(item.m) === 'seen' ? 'text-sky-500' : ''" :title="tickOf(item.m)">
                    {{ tickOf(item.m) === 'sent' ? '✓' : '✓✓' }}
                  </span>
                </template>
              </p>
            </div>
          </template>
          <div v-if="otherTyping || aiTyping" class="mt-2 inline-flex gap-1 rounded-2xl bg-white dark:bg-white/[0.08] px-3.5 py-3 ring-1 ring-brand-navy/[0.06] dark:ring-white/10" aria-label="Typing">
            <span v-for="i in 3" :key="i" class="h-1.5 w-1.5 rounded-full bg-brand-navy/40 dark:bg-white/50 typing-dot" :style="{ animationDelay: `${(i - 1) * 0.15}s` }" />
          </div>
        </div>

        <form class="flex items-end gap-2 px-3 py-3 border-t border-brand-navy/[0.06] dark:border-white/10" @submit.prevent="send">
          <textarea
            ref="inputEl"
            v-model="draft"
            rows="1"
            maxlength="2000"
            placeholder="Write a reply…"
            aria-label="Write a reply"
            class="flex-1 resize-none rounded-2xl border-0 bg-brand-navy/[0.05] dark:bg-white/[0.07] px-4 py-2.5 text-sm text-brand-navy dark:text-white placeholder-brand-navy/45 dark:placeholder-white/40 focus:ring-2 focus:ring-brand-gold"
            @input="onType"
            @keydown="onKeydown"
          />
          <button
            type="submit"
            class="flex-none h-10 w-10 rounded-full bg-brand-gold text-brand-navy flex items-center justify-center shadow-[0_4px_14px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light disabled:opacity-40 disabled:shadow-none transition"
            :disabled="!draft.trim()"
            aria-label="Send"
          >
            <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
          </button>
        </form>
        <p class="px-4 pb-2 -mt-1 text-[0.625rem] text-brand-navy/35 dark:text-white/35">Enter to send · Shift + Enter for a new line</p>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.slide-in-enter-active, .slide-in-leave-active,
.slide-out-enter-active, .slide-out-leave-active { transition: opacity 0.18s ease, transform 0.22s cubic-bezier(0.22, 1, 0.36, 1); }
.slide-in-enter-from  { opacity: 0; transform: translateX(24px); }
.slide-in-leave-to    { opacity: 0; transform: translateX(-24px); }
.slide-out-enter-from { opacity: 0; transform: translateX(-24px); }
.slide-out-leave-to   { opacity: 0; transform: translateX(24px); }

.typing-dot { animation: typing 1s ease-in-out infinite; }
@keyframes typing { 0%, 60%, 100% { transform: translateY(0); opacity: 0.5; } 30% { transform: translateY(-3px); opacity: 1; } }

@media (prefers-reduced-motion: reduce) {
  .slide-in-enter-active, .slide-in-leave-active, .slide-out-enter-active, .slide-out-leave-active { transition: none; }
  .typing-dot { animation: none; }
}
</style>
