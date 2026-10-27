<script setup lang="ts">
import type { SupportMessage, SupportTicket } from '~/composables/useSupport'

/**
 * Help & support chat for buyers and agents, styled like Messages. RealtyLink
 * AI answers first; when it can't help (or the person taps "Talk to a person")
 * the RealtyLink PH team takes over in the same thread. Screenshots can be
 * attached. Team replies are picked up by polling while the page is open.
 * Pass `viewing` to show a past (resolved) request read-only instead.
 */
const props = defineProps<{ viewing?: SupportTicket | null }>()
const emit = defineEmits<{
  loaded: [past: SupportTicket[], active: SupportTicket | null]
  /** Phones: back to the list of requests. */
  list: []
  /** Leave a past request and return to the live chat. */
  close: []
}>()

const authStore = useAuthStore()
const toast = useToast()
const { fetchSupport, send, escalate, rate } = useSupport()

const active = ref<SupportTicket | null>(null)
const past = ref<SupportTicket[]>([])
const loaded = ref(false)
const sending = ref(false)
const draft = ref('')
const files = ref<File[]>([])
const previews = ref<string[]>([])
const showEmoji = ref(false)
const threadEl = ref<HTMLElement | null>(null)
const fileEl = ref<HTMLInputElement | null>(null)

const firstName = computed(() => authStore.user?.name?.split(' ')[0] ?? '')
const shown = computed(() => props.viewing ?? active.value)

async function refresh(scroll = false) {
  const res = await fetchSupport()
  if (!res) return
  const before = active.value?.messages?.length ?? 0
  active.value = res.active
  past.value = res.past
  loaded.value = true
  useState('support-unread', () => 0).value = 0   // opening the chat reads the replies
  emit('loaded', res.past, res.active)
  if (scroll || (active.value?.messages?.length ?? 0) > before) scrollToBottom()
}

let poll: ReturnType<typeof setInterval> | null = null
onMounted(async () => {
  await refresh(true)
  // New replies from the team show up without a reload.
  poll = setInterval(() => { if (!sending.value && document.visibilityState === 'visible') refresh() }, 6000)
})
onUnmounted(() => { if (poll) clearInterval(poll) })
watch(() => props.viewing, () => scrollToBottom())

function scrollToBottom() {
  nextTick(() => { if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight })
}

/* ── Attach screenshots (up to 3, 5 MB each) ── */
function pickFiles(e: Event) {
  const picked = Array.from((e.target as HTMLInputElement).files ?? [])
  for (const f of picked) {
    if (files.value.length >= 3) { toast.error('Up to 3 screenshots per message.'); break }
    if (!f.type.startsWith('image/')) { toast.error('Only images can be attached.'); continue }
    if (f.size > 5 * 1024 * 1024) { toast.error(`${f.name} is over 5 MB.`); continue }
    files.value.push(f)
    previews.value.push(URL.createObjectURL(f))
  }
  if (fileEl.value) fileEl.value.value = ''
}
function removeFile(i: number) {
  URL.revokeObjectURL(previews.value[i]!)
  files.value.splice(i, 1)
  previews.value.splice(i, 1)
}

async function submit(text?: string) {
  const body = (text ?? draft.value).trim()
  if ((!body && !files.value.length) || sending.value) return
  sending.value = true
  showEmoji.value = false
  const imgs = [...files.value]
  draft.value = ''
  previews.value.forEach(u => URL.revokeObjectURL(u))
  files.value = []
  previews.value = []
  scrollToBottom()
  const ticket = await send(body, imgs)
  sending.value = false
  if (!ticket) { toast.error('Couldn’t send. Please try again.'); draft.value = body; return }
  active.value = ticket
  emit('loaded', past.value, ticket)
  scrollToBottom()
}
function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit() }
}

async function talkToPerson() {
  if (sending.value) return
  sending.value = true
  const ticket = await escalate()
  sending.value = false
  if (ticket) { active.value = ticket; emit('loaded', past.value, ticket); scrollToBottom() } else toast.error('Couldn’t reach the team just now. Please try again.')
}

/* ── Rate the last resolved request ── */
const toRate = computed(() => {
  const t = past.value[0]
  return t && !t.rating && t.resolved_at && Date.now() - +new Date(t.resolved_at) < 14 * 86400000 && !active.value ? t : null
})
async function giveRating(r: 'up' | 'down') {
  if (!toRate.value) return
  if (await rate(toRate.value.id, r)) {
    toRate.value.rating = r
    toast.success('Thanks for the feedback!')
  }
}

/* The AI answers until an admin has replied, and stands in whenever the team is offline. */
const aiAnswers = computed(() => !active.value || active.value.status === 'ai' || active.value.status === 'open'
  || (active.value.status === 'in_progress' && active.value.team_online === false))
const STATUS = computed(() => {
  if (props.viewing) return { label: 'Past request · resolved', dot: 'bg-gray-300 dark:bg-gray-500' }
  switch (active.value?.status) {
    case 'open': return { label: 'With the team · waiting for a reply', dot: 'bg-amber-500' }
    case 'in_progress': return active.value.team_online === false
      ? { label: 'Team away · RealtyLink AI is helping', dot: 'bg-brand-gold' }
      : { label: 'Team online', dot: 'bg-emerald-500' }
    default: return { label: 'RealtyLink AI · replies right away', dot: 'bg-emerald-500' }
  }
})
const STARTERS = computed(() => authStore.isAgent
  ? ['How do I publish a listing?', 'A buyer didn’t show up to a viewing', 'My listing was unpublished', 'Report a problem']
  : ['How do I become an agent?', 'I can’t book a viewing', 'How do I cancel a viewing?', 'Report a problem'])

/* ── Feed: messages with date separators; avatar + name once per run of one sender ── */
const timeOf = (iso: string) => new Date(iso).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' })
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
  for (const m of shown.value?.messages ?? []) {
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
function label(m: SupportMessage) {
  if (m.sender_type === 'admin') return `${m.sender_name ?? 'RealtyLink PH'} · RealtyLink PH team`
  return m.stand_in ? 'RealtyLink AI · while the team is away' : 'RealtyLink AI'
}
const day = (iso?: string | null) => (iso ? new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) : '')
</script>

<template>
  <div class="flex h-full min-h-0 flex-col">
    <!-- Header -->
    <div class="flex items-center gap-3 px-5 py-3.5 border-b border-gray-100 dark:border-white/10">
      <button type="button" class="lg:hidden p-1.5 -ml-1 text-gray-500 dark:text-white/60 rounded-lg hover:bg-gray-100 dark:hover:bg-white/10" aria-label="All requests" @click="emit('list')">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
      </button>
      <div class="relative flex-shrink-0">
        <img src="/ai/avatar-96.webp" alt="" class="h-10 w-10 rounded-full object-cover bg-white ring-2 ring-brand-gold/60" />
        <span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white dark:border-[#0B1A35]" :class="STATUS.dot" />
      </div>
      <div class="min-w-0 flex-1">
        <p class="text-sm font-bold text-brand-navy dark:text-white truncate">RealtyLink Support</p>
        <p class="text-xs truncate">
          <span v-if="sending && aiAnswers && !viewing" class="text-brand-gold font-medium">typing…</span>
          <span v-else class="text-gray-400 dark:text-white/40">{{ STATUS.label }}</span>
        </p>
      </div>
      <button
        v-if="viewing"
        type="button"
        class="flex-shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-500 dark:text-white/60 bg-gray-50 dark:bg-white/5 hover:bg-gray-100 dark:hover:bg-white/10 transition-colors"
        @click="emit('close')"
      >Back to chat</button>
      <button
        v-else-if="loaded && active?.status === 'ai' && active.messages?.length"
        type="button"
        class="flex-shrink-0 inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-500 dark:text-white/60 bg-gray-50 dark:bg-white/5 hover:bg-brand-gold/10 hover:text-brand-gold-deep dark:hover:text-brand-gold transition-colors disabled:opacity-50"
        :disabled="sending"
        @click="talkToPerson"
      >
        <svg class="h-3.5 w-3.5 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
        Talk to a person
      </button>
    </div>

    <!-- Thread -->
    <div
      ref="threadEl"
      class="flex-1 min-h-0 overflow-y-auto overscroll-contain px-5 py-4 dark:[color-scheme:dark]"
      style="background-image: radial-gradient(rgba(212,165,74,0.04) 1px, transparent 1px); background-size: 22px 22px;"
      aria-live="polite"
    >
      <div v-if="!loaded" class="space-y-3"><AppSkeleton v-for="i in 3" :key="i" width="60%" height="44px" rounded="lg" /></div>

      <!-- Welcome -->
      <div v-else-if="!shown?.messages?.length" class="flex min-h-full flex-col items-center justify-center py-6 text-center">
        <img src="/ai/mascot-320.webp" alt="" class="h-24 w-24 object-contain drop-shadow-[0_12px_24px_rgba(11,42,92,0.25)]" />
        <p class="mt-3 font-semibold text-lg text-brand-navy dark:text-white">{{ firstName ? `Hi ${firstName}, how` : 'How' }} can we help?</p>
        <p class="mt-1 max-w-sm text-sm text-gray-400 dark:text-white/50">
          Ask RealtyLink AI anything about using RealtyLink PH. If it can't sort it out, our team takes over, right here.
        </p>

        <!-- Rate the last request -->
        <div v-if="toRate" class="mt-5 w-full max-w-sm rounded-2xl bg-gray-50 dark:bg-white/5 border border-gray-100 dark:border-white/10 p-4">
          <p class="text-sm font-semibold text-brand-navy dark:text-white">Your last request was resolved. Did that help?</p>
          <div class="mt-3 flex justify-center gap-2">
            <button type="button" class="rounded-full px-4 py-1.5 text-sm font-semibold ring-1 ring-emerald-400/50 text-emerald-700 hover:bg-emerald-500/10 dark:text-emerald-400" @click="giveRating('up')">👍 Yes</button>
            <button type="button" class="rounded-full px-4 py-1.5 text-sm font-semibold ring-1 ring-red-300 text-red-600 hover:bg-red-500/10 dark:text-red-400" @click="giveRating('down')">👎 Not really</button>
          </div>
        </div>

        <div class="mt-5 flex max-w-md flex-wrap justify-center gap-2">
          <button
            v-for="s in STARTERS"
            :key="s"
            type="button"
            class="rounded-full px-3.5 py-2 text-sm text-brand-navy dark:text-white bg-white dark:bg-white/5 border border-gray-200 dark:border-white/10 hover:border-brand-gold/60 hover:bg-brand-gold/5 shadow-sm transition-colors"
            @click="submit(s)"
          >{{ s }}</button>
        </div>
      </div>

      <!-- Conversation -->
      <div v-else class="space-y-1">
        <template v-for="item in feed" :key="item.id">
          <!-- Date separator -->
          <div v-if="item.kind === 'date'" class="flex items-center justify-center my-4">
            <span class="text-[0.6875rem] font-medium text-gray-400 dark:text-white/40 bg-gray-100 dark:bg-white/5 px-3 py-1 rounded-full">{{ item.label }}</span>
          </div>

          <!-- System note -->
          <div v-else-if="item.message.sender_type === 'system'" class="flex justify-center my-3">
            <span class="max-w-[85%] text-center text-[0.6875rem] text-gray-500 dark:text-white/50 bg-gray-100 dark:bg-white/5 px-3 py-1.5 rounded-2xl">{{ item.message.body }}</span>
          </div>

          <!-- Message -->
          <div
            v-else
            class="flex items-end gap-2.5"
            :class="[item.message.sender_type === 'user' ? 'flex-row-reverse' : 'flex-row', item.showMeta ? 'mt-3' : 'mt-0.5']"
          >
            <!-- avatar: incoming only, first of a run -->
            <div class="w-8 flex-shrink-0">
              <template v-if="item.showMeta && item.message.sender_type !== 'user'">
                <img v-if="item.message.sender_type === 'ai'" src="/ai/avatar-96.webp" alt="RealtyLink AI" class="h-8 w-8 rounded-full object-cover bg-white ring-1 ring-brand-gold/50 shadow-sm" />
                <span v-else class="h-8 w-8 rounded-full flex items-center justify-center bg-brand-navy text-[0.625rem] font-bold text-brand-gold ring-2 ring-brand-gold/50" title="RealtyLink PH team">RL</span>
              </template>
            </div>

            <div class="max-w-[72%] flex flex-col" :class="item.message.sender_type === 'user' ? 'items-end' : 'items-start'">
              <p
                v-if="item.showMeta && item.message.sender_type !== 'user'"
                class="text-[0.625rem] font-semibold mb-1 ml-1"
                :class="item.message.sender_type === 'ai' ? 'text-brand-gold-deep dark:text-brand-gold' : 'text-gray-500 dark:text-white/50'"
              >{{ label(item.message) }}</p>
              <div v-if="item.message.attachments.length" class="mb-1 flex flex-wrap gap-1.5" :class="item.message.sender_type === 'user' ? 'justify-end' : ''">
                <a v-for="(src, i) in item.message.attachments" :key="i" :href="src" target="_blank" rel="noopener" class="block h-24 w-28 overflow-hidden rounded-xl border border-gray-200 dark:border-white/10 shadow-sm"><img :src="src" alt="Screenshot" class="h-full w-full object-cover" /></a>
              </div>
              <div
                v-if="item.message.body"
                class="px-4 py-2.5 text-sm leading-relaxed break-words whitespace-pre-line shadow-sm"
                :class="item.message.sender_type === 'user'
                  ? 'text-white rounded-2xl rounded-br-md'
                  : item.message.sender_type === 'ai'
                    ? 'bg-brand-gold/10 dark:bg-brand-gold/15 border border-brand-gold/30 text-brand-navy dark:text-white rounded-2xl rounded-bl-md'
                    : 'bg-gray-100 dark:bg-white/10 text-brand-navy dark:text-white rounded-2xl rounded-bl-md'"
                :style="item.message.sender_type === 'user' ? 'background: linear-gradient(135deg, #17417F 0%, #0B2A5C 100%)' : ''"
              >{{ item.message.body }}</div>
              <span class="text-[0.625rem] text-gray-400 dark:text-white/30 mt-1 px-1">{{ timeOf(item.message.created_at) }}</span>
            </div>
          </div>
        </template>

        <!-- RealtyLink AI composing a reply -->
        <div v-if="sending && aiAnswers && !viewing" class="flex items-end gap-2.5 mt-3" aria-label="RealtyLink AI is replying">
          <img src="/ai/avatar-96.webp" alt="" class="h-8 w-8 flex-shrink-0 rounded-full object-cover bg-white ring-1 ring-brand-gold/50 shadow-sm" />
          <div>
            <p class="text-[0.625rem] font-semibold text-brand-gold-deep dark:text-brand-gold mb-1 ml-1">RealtyLink AI is typing…</p>
            <div class="bg-brand-gold/10 dark:bg-brand-gold/15 border border-brand-gold/30 rounded-2xl rounded-bl-md px-4 py-3">
              <span class="flex gap-1">
                <span v-for="d in 3" :key="d" class="h-1.5 w-1.5 rounded-full bg-brand-gold typing-dot" :style="{ animationDelay: `${(d - 1) * 150}ms` }" />
              </span>
            </div>
          </div>
        </div>

        <!-- Waiting on the team -->
        <div v-if="!viewing && active?.status === 'open'" class="flex justify-center mt-4">
          <span class="max-w-[85%] text-center text-[0.6875rem] text-amber-800 dark:text-amber-300 bg-amber-500/10 px-3 py-1.5 rounded-2xl">
            Our team has your request and will reply here. RealtyLink AI can still answer questions meanwhile.
          </span>
        </div>
      </div>
    </div>

    <!-- Past request: read-only footer -->
    <div v-if="viewing" class="px-5 py-3 border-t border-gray-100 dark:border-white/10 text-xs text-gray-500 dark:text-white/50">
      Resolved {{ day(viewing.resolved_at) }}<template v-if="viewing.resolved_by"> by {{ viewing.resolved_by }}</template><template v-if="viewing.rating"> · you said {{ viewing.rating === 'up' ? '👍 it helped' : '👎 it didn’t help' }}</template>
    </div>

    <!-- Composer -->
    <form v-else class="px-4 py-3 border-t border-gray-100 dark:border-white/10" @submit.prevent="submit()">
      <div v-if="showEmoji" class="flex gap-1.5 mb-2 px-1">
        <button v-for="e in ['😀','👍','🙏','😅','😕','❤️','🏡','✅']" :key="e" type="button" class="text-lg hover:scale-125 transition-transform" @click="draft += e">{{ e }}</button>
      </div>
      <div v-if="previews.length" class="mb-2 flex gap-2 px-1">
        <span v-for="(src, i) in previews" :key="src" class="relative h-14 w-16 overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
          <img :src="src" alt="" class="h-full w-full object-cover" />
          <button type="button" class="absolute right-0.5 top-0.5 h-5 w-5 rounded-full bg-black/60 text-white flex items-center justify-center" :aria-label="`Remove screenshot ${i + 1}`" @click="removeFile(i)">
            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </span>
      </div>
      <div class="flex items-center gap-2 bg-gray-50 dark:bg-white/5 rounded-full px-2 py-1.5 border border-gray-100 dark:border-white/10 focus-within:border-brand-gold/40 transition-colors">
        <button type="button" class="p-2 text-gray-400 hover:text-brand-gold transition-colors" aria-label="Emoji" @click="showEmoji = !showEmoji">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        </button>
        <input ref="fileEl" type="file" accept="image/*" multiple class="sr-only" @change="pickFiles" />
        <button type="button" class="p-2 text-gray-400 hover:text-brand-gold transition-colors" title="Attach a screenshot" aria-label="Attach a screenshot" @click="fileEl?.click()">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
        </button>
        <textarea
          v-model="draft"
          rows="1"
          maxlength="2000"
          :placeholder="active?.status === 'in_progress' ? (active.team_online === false ? 'Ask anything; RealtyLink AI helps until the team is back…' : 'Reply to the team…') : 'Type your question…'"
          aria-label="Message RealtyLink Support"
          class="max-h-28 flex-1 resize-none border-0 bg-transparent p-0 py-2 text-sm text-brand-navy dark:text-white placeholder-gray-400 focus:ring-0 outline-none"
          @keydown="onKeydown"
        />
        <button
          type="submit"
          class="h-9 w-9 rounded-full flex items-center justify-center bg-brand-gold transition-all disabled:opacity-40 flex-shrink-0"
          :disabled="(!draft.trim() && !files.length) || sending"
          aria-label="Send"
        >
          <svg class="h-4 w-4 rotate-90 text-[#0B2A5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" /></svg>
        </button>
      </div>
      <p class="mt-1.5 px-3 text-[0.625rem] text-gray-400 dark:text-white/30">Never share passwords here. RealtyLink PH never asks for payments in chat.</p>
    </form>
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
