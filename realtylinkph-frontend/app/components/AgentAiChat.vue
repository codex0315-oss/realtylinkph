<script setup lang="ts">
import type { AgentListingProposal } from '~/composables/useAgentAi'

/**
 * RealtyLink AI for agents, docked in the dashboard like the buyer's panel:
 * same header, thread ground and composer. Beyond writing descriptions and
 * drafting listings it answers from the agent's own data (cards), prepares
 * tasks to approve, checks prices and listings, and drafts replies.
 *
 * The thread lives in useAgentAi, so a prompt from the dashboard's AI card
 * (`agent-ai-prompt`) continues here and a reload keeps the conversation.
 */
defineProps<{ closable?: boolean }>()
const emit = defineEmits<{ close: [] }>()

const authStore = useAuthStore()
const { messages, loading, send, patch, patchCard, patchAction, reset } = useAgentAi()
const { createProperty, error: createError } = useProperty()
const pending = useState<string | null>('agent-ai-prompt', () => null)

const draft     = ref('')
const threadEl  = ref<HTMLElement | null>(null)
const inputEl   = ref<HTMLTextAreaElement | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const attachedFile    = ref<File | null>(null)
const attachedPreview = ref('')

const firstName = computed(() => authStore.user?.name?.split(' ')[0] ?? '')

/* One starter per kind of help; the photo one opens the picker. */
const STARTERS = [
  { label: 'Which viewing requests need my answer?', icon: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5' },
  { label: 'Check my listings for fixes',            icon: 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z' },
  { label: 'Is my listing priced right?',            icon: 'M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941' },
  { label: 'Draft a reply to my latest inquiry',     icon: 'M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z' },
  { label: 'Block tomorrow afternoon',               icon: 'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636' },
  { label: 'Write a description from a photo',       icon: 'M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z', photo: true },
]

function scrollToBottom(smooth = true) {
  nextTick(() => threadEl.value?.scrollTo({ top: threadEl.value.scrollHeight, behavior: smooth ? 'smooth' : 'auto' }))
}
watch(() => [messages.value.length, loading.value], () => { if (messages.value.length) scrollToBottom() })

function autosize() {
  const el = inputEl.value
  if (!el) return
  el.style.height = 'auto'
  el.style.height = `${Math.min(el.scrollHeight, 140)}px`
}

function onFile(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  attachedFile.value = file
  const reader = new FileReader()
  reader.onload = ev => { attachedPreview.value = ev.target?.result as string }
  reader.readAsDataURL(file)
  nextTick(() => inputEl.value?.focus())
}
function clearAttachment() {
  attachedFile.value = null
  attachedPreview.value = ''
  if (fileInput.value) fileInput.value.value = ''
}

async function submit(text?: string) {
  const content = (text ?? draft.value).trim()
  if ((!content && !attachedFile.value) || loading.value) return
  const file = attachedFile.value
  const preview = attachedPreview.value
  draft.value = ''
  clearAttachment()
  nextTick(autosize)
  await send(content, file, preview)
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit() }
}

function starter(s: typeof STARTERS[number]) {
  if (s.photo) fileInput.value?.click()
  else submit(s.label)
}

function newChat() {
  reset()
  clearAttachment()
  nextTick(() => inputEl.value?.focus())
}

/* Leaving for a page: on phones the panel covers it, so step aside. */
function onNavigate() {
  if (!window.matchMedia('(min-width: 1024px)').matches) emit('close')
}

function takePending() {
  if (!pending.value) return
  const p = pending.value
  pending.value = null
  submit(p)
}
onMounted(() => {
  if (messages.value.length) scrollToBottom(false)
  inputEl.value?.focus({ preventScroll: true })
  takePending()
})
watch(pending, takePending)

/* ── A proposed listing → a draft to add photos to ── */
const creating = ref(false)
const createErrorMsg = ref('')
async function createDraft(proposal: AgentListingProposal, index: number) {
  if (creating.value) return
  creating.value = true
  createErrorMsg.value = ''
  const prop = await createProperty({
    title:       proposal.title,
    description: proposal.description,
    price:       proposal.price,
    type:        proposal.type,
    offer_type:  proposal.offer_type,
    bedrooms:    proposal.bedrooms || undefined,
    bathrooms:   proposal.bathrooms || undefined,
    floor_area:  proposal.floor_area || undefined,
    address:     proposal.address,
  })
  creating.value = false
  if (prop) {
    patch(index, { created: true })
    onNavigate()
    await navigateTo(`/dashboard/listings/${prop.id}/edit`)
  } else {
    createErrorMsg.value = createError.value || 'Couldn’t create the draft. Please try again.'
  }
}

function peso(n: number): string {
  if (n >= 1_000_000) return `₱${(n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1)}M`
  if (n >= 1_000)     return `₱${(n / 1_000).toFixed(0)}K`
  return `₱${n.toLocaleString('en-PH')}`
}
</script>

<template>
  <div class="flex h-full w-full flex-col overflow-hidden">
    <!-- Header -->
    <div class="flex items-center gap-3 px-5 pt-5 pb-4 border-b border-brand-navy/[0.06] dark:border-white/10">
      <span class="relative flex-none">
        <img src="/ai/avatar-96.webp" alt="" class="h-11 w-11 rounded-full object-cover bg-white ring-2 ring-brand-gold/70" />
        <span class="absolute bottom-0 right-0 h-3 w-3 rounded-full bg-emerald-500 ring-2 ring-white dark:ring-[#0b1f45]" aria-hidden="true" />
      </span>
      <div class="min-w-0 flex-1">
        <h2 class="font-heading text-xl font-semibold leading-tight text-brand-navy dark:text-white flex items-center gap-2">
          RealtyLink AI
          <span class="rounded-md bg-brand-gold/15 px-1.5 py-0.5 text-[0.5625rem] font-bold uppercase tracking-wide text-brand-gold-deep dark:text-brand-gold">Agent</span>
        </h2>
        <p class="truncate text-xs text-brand-navy/55 dark:text-white/50">Works with your listings, viewings and inquiries</p>
      </div>
      <button
        v-if="messages.length"
        type="button"
        class="h-9 w-9 flex-none rounded-full flex items-center justify-center text-brand-navy/55 hover:text-brand-navy hover:bg-brand-navy/[0.06] dark:text-white/55 dark:hover:text-white dark:hover:bg-white/10 transition"
        title="New chat"
        aria-label="Start a new chat"
        @click="newChat"
      >
        <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
      </button>
      <button v-if="closable" type="button" class="h-9 w-9 flex-none rounded-full flex items-center justify-center text-brand-navy/55 hover:text-brand-navy hover:bg-brand-navy/[0.06] dark:text-white/55 dark:hover:text-white dark:hover:bg-white/10 transition" aria-label="Close RealtyLink AI" @click="emit('close')">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
      </button>
    </div>

    <!-- Thread -->
    <div ref="threadEl" class="flex-1 overflow-y-auto overscroll-contain bg-brand-cream/60 dark:bg-black/10" aria-live="polite">
      <!-- Welcome -->
      <div v-if="!messages.length" class="flex min-h-full flex-col px-5 py-6">
        <div class="flex flex-col items-center text-center">
          <img src="/ai/mascot-320.webp" alt="" class="h-28 w-28 object-contain drop-shadow-[0_12px_24px_rgba(11,42,92,0.25)] ai-float" />
          <p class="mt-3 font-heading text-xl font-semibold text-brand-navy dark:text-white">
            {{ firstName ? `Hi ${firstName}, what` : 'What' }} can I do for you?
          </p>
          <p class="mt-1.5 max-w-[19rem] text-sm leading-relaxed text-brand-navy/60 dark:text-white/55">
            I can show your viewings and inquiries, confirm or block times, check prices, draft replies and write listings. You approve anything I change.
          </p>
        </div>

        <p class="mt-6 mb-2 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/40 dark:text-white/40">Try asking</p>
        <div class="space-y-2">
          <button
            v-for="s in STARTERS"
            :key="s.label"
            type="button"
            class="group w-full flex items-center gap-3 rounded-2xl px-3.5 py-3 text-left text-sm font-medium transition
                   bg-white text-brand-navy ring-1 ring-brand-navy/[0.07] hover:ring-brand-gold hover:-translate-y-px
                   dark:bg-white/[0.06] dark:text-white dark:ring-white/10 dark:hover:ring-brand-gold"
            @click="starter(s)"
          >
            <span class="h-8 w-8 flex-none rounded-xl flex items-center justify-center bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold">
              <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="s.icon" /></svg>
            </span>
            <span class="flex-1">{{ s.label }}</span>
            <svg class="h-4 w-4 flex-none text-brand-navy/30 group-hover:text-brand-gold-deep dark:text-white/30 dark:group-hover:text-brand-gold transition" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
          </button>
        </div>
      </div>

      <!-- Conversation -->
      <div v-else class="px-4 py-4 space-y-4">
        <template v-for="(m, i) in messages" :key="i">
          <div v-if="m.role === 'user'" class="flex flex-col items-end gap-1.5">
            <img v-if="m.image" :src="m.image" alt="Attached photo" class="max-h-40 rounded-2xl rounded-br-md ring-1 ring-brand-navy/10 dark:ring-white/10" />
            <div class="max-w-[85%] rounded-2xl rounded-br-md px-3.5 py-2 text-sm leading-relaxed whitespace-pre-wrap break-words bg-brand-navy text-white dark:bg-brand-gold dark:text-brand-navy">{{ m.content }}</div>
          </div>

          <div v-else class="flex items-start gap-2">
            <img src="/ai/avatar-96.webp" alt="" class="mt-0.5 h-7 w-7 flex-none rounded-full object-cover bg-white ring-1 ring-brand-gold/50" />
            <div class="min-w-0 max-w-[88%] flex-1">
              <div class="inline-block max-w-full rounded-2xl rounded-bl-md px-3.5 py-2 text-sm leading-relaxed whitespace-pre-wrap break-words bg-white text-brand-navy ring-1 ring-brand-navy/[0.06] dark:bg-white/[0.08] dark:text-white dark:ring-white/10">{{ m.content }}</div>

              <!-- Account: theme, calendar, password, page -->
              <AiActionCard v-if="m.action" :action="m.action" @navigate="onNavigate" @update="p => patchAction(i, p)" />

              <!-- Their data, a task, a check or a draft -->
              <AgentAiCard v-if="m.card" :card="m.card" @ask="submit" @patch="c => patchCard(i, c)" @navigate="onNavigate" />

              <!-- A listing it proposes to create -->
              <div v-if="m.proposal" class="mt-2 rounded-2xl bg-white ring-1 ring-brand-gold/40 dark:bg-white/[0.06] p-3.5">
                <p class="text-[0.625rem] font-bold uppercase tracking-[0.14em] text-brand-gold-deep dark:text-brand-gold">Draft listing</p>
                <p class="mt-1 text-[0.8125rem] font-semibold text-brand-navy dark:text-white">{{ m.proposal.title }}</p>
                <div class="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[0.6875rem] text-brand-navy/60 dark:text-white/55">
                  <span class="rounded px-1.5 py-0.5 text-[0.5625rem] font-bold uppercase tracking-wide" :class="m.proposal.offer_type === 'rent' ? 'bg-sky-500/15 text-sky-700 dark:text-sky-300' : 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'">{{ m.proposal.offer_type === 'rent' ? 'For rent' : 'For sale' }}</span>
                  <span class="capitalize">{{ m.proposal.type }}</span>
                  <span class="font-semibold text-brand-navy dark:text-white">{{ peso(m.proposal.price) }}</span>
                  <span v-if="m.proposal.bedrooms">{{ m.proposal.bedrooms }} BR</span>
                  <span v-if="m.proposal.bathrooms">{{ m.proposal.bathrooms }} BA</span>
                  <span v-if="m.proposal.floor_area">{{ m.proposal.floor_area }} sqm</span>
                </div>
                <p class="mt-1 text-[0.6875rem] text-brand-navy/45 dark:text-white/40">{{ m.proposal.address }}</p>
                <p class="mt-2 line-clamp-3 whitespace-pre-wrap text-xs leading-relaxed text-brand-navy/70 dark:text-white/65">{{ m.proposal.description }}</p>
                <button
                  v-if="!m.created"
                  type="button"
                  class="mt-3 w-full inline-flex items-center justify-center gap-2 rounded-full bg-brand-gold py-2.5 text-xs font-bold text-brand-navy shadow-[0_4px_14px_rgba(212,165,74,0.4)] hover:bg-brand-gold-light disabled:opacity-50 transition"
                  :disabled="creating"
                  @click="createDraft(m.proposal, i)"
                >
                  {{ creating ? 'Creating draft…' : 'Create draft & add photos' }}
                </button>
                <p v-else class="mt-3 text-center text-xs font-semibold text-emerald-600 dark:text-emerald-400">Draft created ✓</p>
                <p v-if="createErrorMsg && !m.created" class="mt-2 text-center text-xs text-red-600 dark:text-red-400" role="alert">{{ createErrorMsg }}</p>
              </div>
            </div>
          </div>
        </template>

        <!-- Thinking -->
        <div v-if="loading" class="flex items-start gap-2" aria-label="RealtyLink AI is replying">
          <img src="/ai/avatar-96.webp" alt="" class="h-7 w-7 flex-none rounded-full object-cover bg-white ring-1 ring-brand-gold/50" />
          <div class="inline-flex gap-1 rounded-2xl rounded-bl-md bg-white dark:bg-white/[0.08] px-3.5 py-3 ring-1 ring-brand-navy/[0.06] dark:ring-white/10">
            <span v-for="d in 3" :key="d" class="h-1.5 w-1.5 rounded-full bg-brand-navy/40 dark:bg-white/50 typing-dot" :style="{ animationDelay: `${(d - 1) * 0.15}s` }" />
          </div>
        </div>
      </div>
    </div>

    <!-- Attached photo -->
    <div v-if="attachedPreview" class="flex items-center gap-2 px-4 pt-3">
      <img :src="attachedPreview" alt="" class="h-11 w-11 rounded-xl object-cover ring-1 ring-brand-navy/10 dark:ring-white/10" />
      <span class="text-xs text-brand-navy/60 dark:text-white/55">Photo attached</span>
      <button type="button" class="ml-1 h-7 w-7 rounded-full flex items-center justify-center text-brand-navy/45 hover:text-red-600 hover:bg-red-500/10 dark:text-white/45 transition" aria-label="Remove photo" @click="clearAttachment">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
      </button>
    </div>

    <!-- Composer -->
    <form class="flex items-end gap-2 px-3 pt-3 border-t border-brand-navy/[0.06] dark:border-white/10" :class="attachedPreview ? 'border-t-0 pt-2' : ''" @submit.prevent="submit()">
      <input ref="fileInput" type="file" accept="image/*" class="sr-only" tabindex="-1" @change="onFile" />
      <button
        type="button"
        class="flex-none h-10 w-10 rounded-full flex items-center justify-center text-brand-navy/50 hover:text-brand-gold-deep hover:bg-brand-navy/[0.06] dark:text-white/50 dark:hover:text-brand-gold dark:hover:bg-white/10 transition"
        title="Attach a property photo"
        aria-label="Attach a property photo"
        @click="fileInput?.click()"
      >
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" /></svg>
      </button>
      <textarea
        ref="inputEl"
        v-model="draft"
        rows="1"
        maxlength="1000"
        :placeholder="attachedPreview ? 'Add details, or just send…' : 'Ask about your listings, viewings…'"
        aria-label="Ask RealtyLink AI"
        class="flex-1 resize-none rounded-2xl border-0 bg-brand-navy/[0.05] dark:bg-white/[0.07] px-4 py-2.5 text-sm text-brand-navy dark:text-white placeholder-brand-navy/45 dark:placeholder-white/40 focus:ring-2 focus:ring-brand-gold"
        @input="autosize"
        @keydown="onKeydown"
      />
      <button
        type="submit"
        class="flex-none h-10 w-10 rounded-full bg-brand-gold text-brand-navy flex items-center justify-center shadow-[0_4px_14px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light disabled:opacity-40 disabled:shadow-none transition"
        :disabled="(!draft.trim() && !attachedFile) || loading"
        aria-label="Send"
      >
        <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
      </button>
    </form>
    <p class="px-4 pt-1.5 pb-2.5 text-[0.625rem] text-brand-navy/40 dark:text-white/35">AI can make mistakes. Check drafts before you send or publish.</p>
  </div>
</template>

<style scoped>
.typing-dot { animation: typing 1s ease-in-out infinite; }
@keyframes typing { 0%, 60%, 100% { transform: translateY(0); opacity: 0.5; } 30% { transform: translateY(-3px); opacity: 1; } }

/* The mascot idles with a slow bob on the welcome screen. */
.ai-float { animation: ai-float 4s ease-in-out infinite; }
@keyframes ai-float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }

@media (prefers-reduced-motion: reduce) {
  .typing-dot, .ai-float { animation: none; }
}
</style>
