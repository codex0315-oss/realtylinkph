<script setup lang="ts">
import type { Conversation, Message, User } from '~/types'

definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()
const convStore = useConversationStore()
const { fetchConversations, openConversation, fetchMessages, sendMessage, markRead, deleteConversation, loading } = useConversation()
const ask   = useConfirm()
const toast = useToast()
const route  = useRoute()
const router = useRouter()
const propertyHref = usePropertyHref()
const {
  listenToConversation, stopListeningToConversation,
  whisperTyping, listenForTyping,
  whisperSeen, listenForSeen,
  onNotificationType, listenForAiTyping,
} = useEcho()

await fetchConversations()

/*
 * The open thread gets its messages over the conversation channel. A message
 * in any *other* thread only reaches us as a `new_message` notification, so
 * refresh the list on that — it re-orders, updates the preview, and bumps the
 * unread count without a reload.
 */
let stopNewMessage: (() => void) | null = null
onMounted(() => {
  stopNewMessage = onNotificationType('new_message', () => { fetchConversations() })
})

// Arriving from "Message Agent" (?agent=&property=) — open/create that conversation.
onMounted(async () => {
  const agentId    = Number(route.query.agent)
  const propertyId = Number(route.query.property)
  if (!agentId || !propertyId) return
  const conv = await openConversation(propertyId)
  if (conv) {
    await fetchConversations()
    await openConv(conv.id)
  }
  router.replace({ query: {} })
})

const newMessage = ref('')
const sending    = ref(false)
const search     = ref('')
const otherTyping = ref(false)

/*
 * RealtyLink AI stands in while the agent is offline. Its bubbles are styled
 * and labelled distinctly so neither party can mistake them for the agent.
 */
const aiTyping = ref(false)
let aiTypingClear: ReturnType<typeof setTimeout>
function isAi(m: Message) { return !!m.is_ai }
/** Whose name goes in "replying while … is away" — the agent, from either seat. */
const agentName = computed(() => convStore.active?.agent?.name ?? 'the agent')
const aiLabel = computed(() =>
  authStore.isAgent ? 'RealtyLink AI · replied while you were away' : `RealtyLink AI · replying while ${agentName.value} is away`,
)
const showEmoji   = ref(false)
const threadEl    = ref<HTMLElement | null>(null)

/* Local-only message reactions (not persisted). */
const reactions = reactive<Record<number, string[]>>({})
const reactionChoices = ['🔥', '❤️', '👍', '😂', '😮', '🙏']
const hoveredMsg = ref<number | null>(null)
const reactingMsg = ref<number | null>(null)

function addReaction(msgId: number, emoji: string) {
  const list = reactions[msgId] ?? (reactions[msgId] = [])
  if (!list.includes(emoji)) list.push(emoji)
  reactingMsg.value = null
}

/* ── Helpers ── */
const me = computed(() => authStore.user?.id)

function other(conv: Conversation | null): User | undefined {
  if (!conv) return undefined
  return me.value === conv.buyer_id ? conv.agent : conv.buyer
}

const activeOther = computed(() => other(convStore.active))

/* Profile link for a chat participant — only agents have a public profile. */
const agentHref = useAgentHref()
function profileHref(u: User | undefined): string | null {
  return u && u.role_type === 'agent' ? agentHref(u.id) : null
}
const headerProfileHref = computed(() => profileHref(activeOther.value))

/* ── Presence (online / last-seen) of the active conversation's other user ── */
const { fetchPresence } = usePresence()
const otherPresence = ref<import('~/composables/usePresence').Presence | null>(null)
const presenceNow   = ref(Date.now())
async function refreshPresence() {
  const uid = activeOther.value?.id
  otherPresence.value = uid ? await fetchPresence(uid) : null
}
let presenceTimer: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  presenceTimer = setInterval(() => { presenceNow.value = Date.now(); refreshPresence() }, 30_000)
})
onBeforeUnmount(() => { if (presenceTimer) clearInterval(presenceTimer) })

const filteredConversations = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return convStore.conversations
  return convStore.conversations.filter(c => (other(c)?.name ?? '').toLowerCase().includes(q))
})

function isMine(m: Message) {
  return m.sender_id === me.value
}

function timeOf(iso: string) {
  return new Date(iso).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' })
}

function dateLabel(d: Date) {
  const today = new Date()
  const yest  = new Date(); yest.setDate(today.getDate() - 1)
  if (d.toDateString() === today.toDateString()) return 'Today'
  if (d.toDateString() === yest.toDateString())  return 'Yesterday'
  return d.toLocaleDateString('en-PH', { month: 'long', day: 'numeric' })
}

/* Feed = messages interleaved with date separators, with grouping flags. */
type FeedItem =
  | { kind: 'date'; id: string; label: string }
  | { kind: 'msg'; id: number; message: Message; showMeta: boolean }

const feed = computed<FeedItem[]>(() => {
  const items: FeedItem[] = []
  let lastDay = ''
  let lastSender: number | null = null
  for (const m of convStore.activeMessages) {
    const d = new Date(m.created_at)
    const day = d.toDateString()
    if (day !== lastDay) {
      items.push({ kind: 'date', id: 'd' + day, label: dateLabel(d) })
      lastDay = day
      lastSender = null
    }
    items.push({ kind: 'msg', id: m.id, message: m, showMeta: lastSender !== m.sender_id })
    lastSender = m.sender_id
  }
  return items
})

/* ── Read receipts ("Seen 3 mins ago") ── */
// Epoch ms of when the OTHER user last read our messages. Seeded from read_at on
// load (persists across refresh) and bumped live by their "seen" whisper.
const otherSeenAt = ref<number | null>(null)
function initSeen() {
  let max = 0
  for (const m of convStore.activeMessages) {
    if (m.sender_id === me.value && m.read_at) {
      const t = new Date(m.read_at).getTime()
      if (t > max) max = t
    }
  }
  otherSeenAt.value = max || null
}
function relAgo(ts: number, now: number): string {
  const mins = Math.floor(Math.max(0, now - ts) / 60_000)
  if (mins < 1)  return 'just now'
  if (mins < 60) return `${mins} ${mins === 1 ? 'min' : 'mins'} ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24)  return `${hrs} ${hrs === 1 ? 'hour' : 'hours'} ago`
  const days = Math.floor(hrs / 24)
  return `${days} ${days === 1 ? 'day' : 'days'} ago`
}
// Show "Seen" under my message only when it's the latest in the thread.
const showSeen = computed(() => {
  const arr = convStore.activeMessages
  if (!arr.length || !otherSeenAt.value) return false
  const last = arr[arr.length - 1]!
  return last.sender_id === me.value && new Date(last.created_at).getTime() <= otherSeenAt.value
})
const seenText = computed(() => showSeen.value ? `Seen ${relAgo(otherSeenAt.value!, presenceNow.value)}` : '')

/* ── Conversation open / send ── */
async function openConv(id: number) {
  if (convStore.activeId && convStore.activeId !== id) {
    stopListeningToConversation(convStore.activeId)
  }
  convStore.setActive(id)
  showEmoji.value = false
  await fetchMessages(id)
  initSeen()
  await markRead(id)
  whisperSeen(id)        // tell the other party we've read their messages
  refreshPresence()
  listenToConversation(id, (msg: Message) => {
    convStore.addMessage(msg)
    otherTyping.value = false
    if (msg.is_ai) { aiTyping.value = false; clearTimeout(aiTypingClear) }
    // We're looking at the thread → mark their new message read + notify them.
    if (msg.sender_id !== me.value) { markRead(id); whisperSeen(id) }
    scrollToBottom()
  })
  listenForAiTyping(id, () => {
    aiTyping.value = true
    clearTimeout(aiTypingClear)
    // Safety net: if the reply never lands (Gemini failed), don't show dots forever.
    aiTypingClear = setTimeout(() => { aiTyping.value = false }, 20_000)
    scrollToBottom()
  })
  listenForTyping(id, (userId) => {
    if (userId === me.value) return
    otherTyping.value = true
    clearTimeout(typingClear)
    typingClear = setTimeout(() => { otherTyping.value = false }, 2500)
  })
  listenForSeen(id, (userId) => {
    if (userId === me.value) return
    otherSeenAt.value = Date.now()
  })
  scrollToBottom()
}

let typingClear: ReturnType<typeof setTimeout>
let lastWhisper = 0
function onType() {
  const now = Date.now()
  if (convStore.activeId && now - lastWhisper > 1200) {
    lastWhisper = now
    whisperTyping(convStore.activeId)
  }
}

async function send() {
  if (!newMessage.value.trim() || !convStore.activeId) return
  sending.value = true
  await sendMessage(convStore.activeId, newMessage.value.trim())
  newMessage.value = ''
  sending.value = false
  showEmoji.value = false
  scrollToBottom()
}

function addEmoji(e: string) {
  newMessage.value += e
}

function scrollToBottom() {
  nextTick(() => {
    if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight
  })
}

function backToList() {
  if (convStore.activeId) stopListeningToConversation(convStore.activeId)
  convStore.activeId = null
}

/* ── Delete a conversation (this side only) ── */
async function confirmDelete(conv: Conversation) {
  const who = other(conv)?.name ?? 'the other person'
  const ok = await ask({
    title:   'Delete this conversation?',
    message: `It will be removed from your inbox.`,
    subject: `${who} · ${conv.property?.title ?? 'Listing'}`,
    consequences: [
      `${who} keeps their copy — this only clears it from your side`,
      'If either of you sends a new message, it reappears here',
    ],
    confirmLabel: 'Delete conversation',
    tone: 'danger',
  })
  if (!ok) return

  const wasActive = convStore.activeId === conv.id
  if (wasActive) stopListeningToConversation(conv.id)

  const done = await deleteConversation(conv.id)
  if (!done) { toast.error('Could not delete this conversation'); return }

  toast.success('Conversation deleted')
  // If the open thread was the one deleted, fall through to the next one.
  if (wasActive && convStore.conversations.length) await openConv(convStore.conversations[0]!.id)
}

watch(() => convStore.activeMessages.length, scrollToBottom)

onUnmounted(() => {
  if (convStore.activeId) stopListeningToConversation(convStore.activeId)
  stopNewMessage?.()
  clearTimeout(typingClear)
  clearTimeout(aiTypingClear)
})

if (convStore.conversations.length) {
  await openConv(convStore.conversations[0]!.id)
}
</script>

<template>
  <div
    class="flex rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-[#10264D] overflow-hidden shadow-sm"
    style="height: calc(100vh - 9rem)"
  >
    <!-- ════════ Conversation list ════════ -->
    <div
      class="w-full lg:w-80 flex-shrink-0 flex flex-col border-r border-gray-100 dark:border-white/10"
      :class="convStore.activeId ? 'hidden lg:flex' : 'flex'"
    >
      <!-- List header -->
      <div class="px-5 pt-5 pb-3">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-bold text-lg text-brand-navy dark:text-white">Messages</h2>
          <span class="text-[11px] font-semibold text-brand-gold bg-brand-gold/10 px-2 py-0.5 rounded-full">
            {{ convStore.conversations.length }}
          </span>
        </div>
        <!-- Search -->
        <div class="relative">
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          <input
            v-model="search"
            type="text"
            placeholder="Search messages"
            class="w-full pl-9 pr-4 py-2.5 text-sm rounded-xl bg-gray-50 dark:bg-white/5 dark:text-white border border-transparent focus:bg-white dark:focus:bg-white/10 focus:outline-none focus:border-brand-gold/40 transition-colors placeholder-gray-400"
          />
        </div>
      </div>

      <p class="px-5 pb-2 text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400 dark:text-white/30">All Messages</p>

      <!-- List -->
      <div class="flex-1 overflow-y-auto px-2.5 pb-3">
        <div v-if="loading && !convStore.conversations.length" class="space-y-2 px-2.5 pt-2">
          <AppSkeleton v-for="i in 5" :key="i" width="100%" height="64px" />
        </div>

        <div v-else-if="!convStore.conversations.length" class="text-center px-4 py-16">
          <div class="h-12 w-12 rounded-full bg-gray-100 dark:bg-white/5 flex items-center justify-center mx-auto mb-3">
            <svg class="h-6 w-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
            </svg>
          </div>
          <p class="text-sm text-gray-400">No conversations yet.</p>
        </div>

        <p v-else-if="!filteredConversations.length" class="text-sm text-gray-400 text-center py-10">No matches for “{{ search }}”.</p>

        <div
          v-for="conv in filteredConversations"
          :key="conv.id"
          role="button"
          tabindex="0"
          class="group w-full text-left p-2.5 rounded-xl flex items-center gap-3 transition-colors mb-0.5 cursor-pointer"
          :class="convStore.activeId === conv.id
            ? 'bg-brand-gold/10'
            : 'hover:bg-gray-50 dark:hover:bg-white/5'"
          @click="openConv(conv.id)"
          @keydown.enter="openConv(conv.id)"
        >
          <!-- Avatar → profile (agents only); stops the row's open-chat click -->
          <NuxtLink v-if="profileHref(other(conv))" :to="profileHref(other(conv))!" class="relative flex-shrink-0" title="View profile" @click.stop>
            <AppAvatar :name="other(conv)?.name" :src="other(conv)?.avatar" size="md" />
            <span v-if="other(conv)?.is_online" class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full bg-emerald-500 border-2 border-white dark:border-[#10264D]" />
          </NuxtLink>
          <div v-else class="relative flex-shrink-0">
            <AppAvatar :name="other(conv)?.name" :src="other(conv)?.avatar" size="md" />
            <span v-if="other(conv)?.is_online" class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full bg-emerald-500 border-2 border-white dark:border-[#10264D]" />
          </div>

          <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between gap-2">
              <p class="text-sm font-semibold truncate" :class="convStore.activeId === conv.id ? 'text-brand-navy dark:text-white' : 'text-brand-navy dark:text-white/90'">
                {{ other(conv)?.name ?? 'Unknown' }}
              </p>
              <span class="text-[10px] text-gray-400 flex-shrink-0">{{ conv.last_message_at ? timeOf(conv.last_message_at) : '' }}</span>
            </div>
            <div class="flex items-center justify-between gap-2 mt-0.5">
              <p class="text-xs truncate" :class="conv.unread_count ? 'text-brand-navy dark:text-white font-medium' : 'text-gray-400 dark:text-white/40'">
                <span v-if="conv.latest_message?.is_ai" class="text-brand-gold-deep dark:text-brand-gold font-semibold">AI:</span>
                {{ conv.latest_message?.body ?? 'Start a conversation' }}
              </p>
              <span v-if="conv.unread_count" class="flex-shrink-0 bg-brand-gold text-brand-navy text-[10px] font-bold rounded-full h-4.5 min-w-[18px] px-1 flex items-center justify-center" style="height:18px">
                {{ conv.unread_count }}
              </span>
            </div>
          </div>

          <!-- Quick "view profile" action (agents only) -->
          <NuxtLink
            v-if="profileHref(other(conv))"
            :to="profileHref(other(conv))!"
            class="flex-shrink-0 p-1.5 rounded-lg text-gray-300 hover:text-brand-gold hover:bg-brand-gold/10 transition-all lg:opacity-0 lg:group-hover:opacity-100"
            title="View profile"
            @click.stop
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
          </NuxtLink>

          <!-- Delete (shown on hover, like the profile action). Confirms first. -->
          <button
            type="button"
            class="flex-shrink-0 p-1.5 rounded-lg text-gray-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 transition-all lg:opacity-0 lg:group-hover:opacity-100"
            title="Delete conversation"
            aria-label="Delete conversation"
            @click.stop="confirmDelete(conv)"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
          </button>
        </div>
      </div>
    </div>

    <!-- ════════ Chat thread ════════ -->
    <div
      class="flex-1 flex-col min-w-0"
      :class="convStore.activeId ? 'flex' : 'hidden lg:flex'"
    >
      <!-- Empty -->
      <div v-if="!convStore.activeId" class="flex-1 flex flex-col items-center justify-center text-center px-6">
        <div class="h-16 w-16 rounded-2xl bg-brand-gold/10 flex items-center justify-center mb-4">
          <svg class="h-8 w-8 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
          </svg>
        </div>
        <p class="font-semibold text-brand-navy dark:text-white">Select a conversation</p>
        <p class="text-sm text-gray-400 mt-1">Choose a chat from the left to start messaging.</p>
      </div>

      <template v-else>
        <!-- Thread header -->
        <div class="flex items-center gap-3 px-5 py-3.5 border-b border-gray-100 dark:border-white/10">
          <button class="lg:hidden p-1.5 -ml-1 text-gray-500 dark:text-white/60 rounded-lg hover:bg-gray-100 dark:hover:bg-white/10" @click="backToList">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
          </button>
          <component
            :is="headerProfileHref ? 'NuxtLink' : 'div'"
            :to="headerProfileHref || undefined"
            class="flex items-center gap-3 min-w-0 -m-1 p-1 rounded-lg transition-colors"
            :class="headerProfileHref ? 'cursor-pointer hover:bg-gray-50 dark:hover:bg-white/5' : ''"
            :title="headerProfileHref ? 'View profile' : undefined"
          >
            <div class="relative flex-shrink-0">
              <AppAvatar :name="activeOther?.name" :src="activeOther?.avatar" size="md" />
              <span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white dark:border-[#10264D]" :class="otherPresence?.is_online ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-500'" />
            </div>
            <div class="min-w-0">
              <p class="text-sm font-bold text-brand-navy dark:text-white truncate" :class="headerProfileHref ? 'hover:text-brand-gold transition-colors' : ''">{{ activeOther?.name ?? 'Unknown' }}</p>
              <p class="text-xs truncate">
                <span v-if="otherTyping" class="text-brand-gold font-medium">typing…</span>
                <span v-else-if="otherPresence?.is_online" class="text-emerald-500">● <span class="text-gray-400 dark:text-white/40">Active now</span></span>
                <span v-else class="text-gray-400 dark:text-white/40">{{ lastSeenLabel(otherPresence, presenceNow) }}</span>
              </p>
            </div>
          </component>
          <!-- Property context -->
          <NuxtLink
            v-if="convStore.active?.property"
            :to="propertyHref(convStore.active.property.id)"
            class="ml-auto hidden sm:flex items-center gap-2 text-xs text-gray-500 dark:text-white/50 bg-gray-50 dark:bg-white/5 hover:bg-gray-100 dark:hover:bg-white/10 rounded-lg px-3 py-1.5 transition-colors max-w-[40%]"
          >
            <svg class="h-3.5 w-3.5 text-brand-gold flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
            <span class="truncate">{{ convStore.active.property.title }}</span>
          </NuxtLink>
        </div>

        <!-- Messages -->
        <div
          ref="threadEl"
          class="flex-1 overflow-y-auto px-5 py-4 space-y-1"
          style="background-image: radial-gradient(rgba(212,175,55,0.04) 1px, transparent 1px); background-size: 22px 22px;"
        >
          <template v-for="item in feed" :key="item.id">
            <!-- Date separator -->
            <div v-if="item.kind === 'date'" class="flex items-center justify-center my-4">
              <span class="text-[11px] font-medium text-gray-400 dark:text-white/40 bg-gray-100 dark:bg-white/5 px-3 py-1 rounded-full">{{ item.label }}</span>
            </div>

            <!-- Message -->
            <div
              v-else
              class="flex items-end gap-2.5"
              :class="[isMine(item.message) ? 'flex-row-reverse' : 'flex-row', item.showMeta ? 'mt-3' : 'mt-0.5']"
              @mouseenter="hoveredMsg = item.id"
              @mouseleave="hoveredMsg = null; reactingMsg = null"
            >
              <!-- avatar (incoming only, on first of group); a sparkle for the AI -->
              <div class="w-8 flex-shrink-0">
                <div
                  v-if="isAi(item.message) && item.showMeta"
                  class="h-8 w-8 rounded-full bg-gradient-to-br from-brand-gold-light to-brand-gold flex items-center justify-center shadow-sm"
                  title="RealtyLink AI"
                >
                  <svg class="h-4 w-4 text-brand-navy" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
                </div>
                <AppAvatar
                  v-else-if="!isMine(item.message) && item.showMeta"
                  :name="activeOther?.name"
                  :src="activeOther?.avatar"
                  size="sm"
                />
              </div>

              <div class="max-w-[72%] flex flex-col" :class="isMine(item.message) ? 'items-end' : 'items-start'">
                <!-- Who this is, when it's the AI — shown once per group, never hidden -->
                <p v-if="isAi(item.message) && item.showMeta" class="text-[10px] font-semibold text-brand-gold-deep dark:text-brand-gold mb-1 ml-1">
                  {{ aiLabel }}
                </p>
                <div class="relative group/msg">
                  <div
                    class="px-4 py-2.5 text-sm leading-relaxed break-words shadow-sm"
                    :class="isMine(item.message)
                      ? 'text-white rounded-2xl rounded-br-md'
                      : isAi(item.message)
                        ? 'bg-brand-gold/10 dark:bg-brand-gold/15 border border-brand-gold/30 text-brand-navy dark:text-white rounded-2xl rounded-bl-md whitespace-pre-line'
                        : 'bg-gray-100 dark:bg-white/10 text-brand-navy dark:text-white rounded-2xl rounded-bl-md'"
                    :style="isMine(item.message) ? 'background: linear-gradient(135deg, #10264D 0%, #08152F 100%)' : ''"
                  >
                    {{ item.message.body }}
                  </div>

                  <!-- react button on hover -->
                  <div
                    v-if="hoveredMsg === item.id"
                    class="absolute top-1/2 -translate-y-1/2"
                    :class="isMine(item.message) ? '-left-9' : '-right-9'"
                  >
                    <button
                      class="h-7 w-7 rounded-full bg-white dark:bg-[#0d1f3c] border border-gray-200 dark:border-white/10 shadow-sm flex items-center justify-center text-gray-400 hover:text-brand-gold transition-colors"
                      @click="reactingMsg = reactingMsg === item.id ? null : item.id"
                    >
                      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </button>
                    <!-- emoji picker -->
                    <div
                      v-if="reactingMsg === item.id"
                      class="absolute z-10 mt-1 flex gap-1 bg-white dark:bg-[#0d1f3c] border border-gray-200 dark:border-white/10 rounded-full px-2 py-1 shadow-lg"
                      :class="isMine(item.message) ? 'right-0' : 'left-0'"
                    >
                      <button v-for="e in reactionChoices" :key="e" class="text-base hover:scale-125 transition-transform" @click="addReaction(item.id, e)">{{ e }}</button>
                    </div>
                  </div>
                </div>

                <!-- reactions -->
                <div v-if="reactions[item.id]?.length" class="flex gap-1 mt-1">
                  <span v-for="e in reactions[item.id]" :key="e" class="text-xs bg-white dark:bg-white/10 border border-gray-200 dark:border-white/10 rounded-full px-1.5 py-0.5 shadow-sm">{{ e }}</span>
                </div>

                <!-- time -->
                <span class="text-[10px] text-gray-400 dark:text-white/30 mt-1 px-1">{{ timeOf(item.message.created_at) }}</span>
              </div>
            </div>
          </template>

          <!-- Seen receipt (under my latest message) -->
          <div v-if="seenText && !otherTyping" class="flex justify-end pr-1 mt-1">
            <span class="text-[10px] font-medium text-gray-400 dark:text-white/40 flex items-center gap-1">
              <svg class="h-3 w-3 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
              {{ seenText }}
            </span>
          </div>

          <!-- typing bubble -->
          <div v-if="otherTyping" class="flex items-end gap-2.5 mt-3">
            <div class="w-8 flex-shrink-0">
              <AppAvatar :name="activeOther?.name" :src="activeOther?.avatar" size="sm" />
            </div>
            <div class="bg-gray-100 dark:bg-white/10 rounded-2xl rounded-bl-md px-4 py-3">
              <span class="flex gap-1">
                <span class="h-1.5 w-1.5 rounded-full bg-gray-400 dark:bg-white/50 typing-dot" style="animation-delay:0ms" />
                <span class="h-1.5 w-1.5 rounded-full bg-gray-400 dark:bg-white/50 typing-dot" style="animation-delay:150ms" />
                <span class="h-1.5 w-1.5 rounded-full bg-gray-400 dark:bg-white/50 typing-dot" style="animation-delay:300ms" />
              </span>
            </div>
          </div>

          <!-- RealtyLink AI composing an auto-reply -->
          <div v-if="aiTyping" class="flex items-end gap-2.5 mt-3">
            <div class="w-8 flex-shrink-0">
              <div class="h-8 w-8 rounded-full bg-gradient-to-br from-brand-gold-light to-brand-gold flex items-center justify-center shadow-sm">
                <svg class="h-4 w-4 text-brand-navy" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
              </div>
            </div>
            <div>
              <p class="text-[10px] font-semibold text-brand-gold-deep dark:text-brand-gold mb-1 ml-1">RealtyLink AI is typing…</p>
              <div class="bg-brand-gold/10 dark:bg-brand-gold/15 border border-brand-gold/30 rounded-2xl rounded-bl-md px-4 py-3">
                <span class="flex gap-1">
                  <span class="h-1.5 w-1.5 rounded-full bg-brand-gold typing-dot" style="animation-delay:0ms" />
                  <span class="h-1.5 w-1.5 rounded-full bg-brand-gold typing-dot" style="animation-delay:150ms" />
                  <span class="h-1.5 w-1.5 rounded-full bg-brand-gold typing-dot" style="animation-delay:300ms" />
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Input -->
        <div class="px-4 py-3 border-t border-gray-100 dark:border-white/10">
          <div v-if="showEmoji" class="flex gap-1.5 mb-2 px-1">
            <button v-for="e in ['😀','😎','👍','🔥','❤️','🎉','🙏','🏡','✅','😂']" :key="e" class="text-lg hover:scale-125 transition-transform" @click="addEmoji(e)">{{ e }}</button>
          </div>
          <div class="flex items-center gap-2 bg-gray-50 dark:bg-white/5 rounded-full px-2 py-1.5 border border-gray-100 dark:border-white/10 focus-within:border-brand-gold/40 transition-colors">
            <button class="p-2 text-gray-400 hover:text-brand-gold transition-colors" @click="showEmoji = !showEmoji">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </button>
            <button class="p-2 text-gray-400 hover:text-brand-gold transition-colors" title="Attachments coming soon">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
            </button>
            <input
              v-model="newMessage"
              type="text"
              placeholder="Type your message…"
              class="flex-1 bg-transparent text-sm text-brand-navy dark:text-white placeholder-gray-400 outline-none py-2"
              @input="onType"
              @keydown.enter.prevent="send"
            />
            <button
              class="h-9 w-9 rounded-full flex items-center justify-center text-white transition-all disabled:opacity-40 flex-shrink-0"
              style="background: linear-gradient(135deg, #D4AF37 0%, #c9a227 100%)"
              :disabled="sending || !newMessage.trim()"
              @click="send"
            >
              <svg v-if="!sending" class="h-4 w-4 text-brand-navy" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" /></svg>
              <svg v-else class="animate-spin h-4 w-4 text-brand-navy" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
            </button>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.typing-dot {
  animation: typing-bounce 1s infinite ease-in-out;
}
@keyframes typing-bounce {
  0%, 60%, 100% { transform: translateY(0); opacity: 0.5; }
  30% { transform: translateY(-4px); opacity: 1; }
}
</style>
