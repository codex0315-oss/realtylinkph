<script setup lang="ts">
/**
 * RealtyLink AI as a docked dashboard panel, the third sibling of the
 * Messages and Notifications panels: same header, same thread ground, same
 * composer, so it reads as part of the dashboard rather than a widget.
 *
 * The conversation is the shared one from useAiThread, so a question asked
 * from the overview's AI card (or the public full-screen chat) continues here.
 */
const emit = defineEmits<{ close: [] }>()

const authStore    = useAuthStore()
const { messages, loading, send, reset, updateAction } = useAiThread()

const draft    = ref('')
const threadEl = ref<HTMLElement | null>(null)
const inputEl  = ref<HTMLTextAreaElement | null>(null)

const firstName = computed(() => authStore.user?.name?.split(' ')[0] ?? '')

/* Starters cover the four things buyers actually ask first. */
const STARTERS = [
  { label: 'Condos under ₱3M in Cebu',        icon: 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z' },
  { label: 'What can I afford on ₱25k a month?', icon: 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z' },
  { label: '2-bedroom house near a school',    icon: 'M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5' },
  { label: 'Compare my saved homes',           icon: 'M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z' },
  { label: 'What should I ask at a viewing?',  icon: 'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z' },
]

function scrollToBottom(smooth = true) {
  nextTick(() => threadEl.value?.scrollTo({ top: threadEl.value.scrollHeight, behavior: smooth ? 'smooth' : 'auto' }))
}
watch(() => [messages.value.length, loading.value], () => scrollToBottom())
onMounted(() => {
  if (messages.value.length) scrollToBottom(false)
  inputEl.value?.focus({ preventScroll: true })
})

function autosize() {
  const el = inputEl.value
  if (!el) return
  el.style.height = 'auto'
  el.style.height = `${Math.min(el.scrollHeight, 140)}px`
}

async function submit(text?: string) {
  const content = (text ?? draft.value).trim()
  if (!content || loading.value) return
  draft.value = ''
  nextTick(autosize)
  await send(content)
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit() }
}

function newChat() {
  reset()
  nextTick(() => inputEl.value?.focus())
}
</script>

<template>
  <div class="flex h-full flex-col overflow-hidden">
    <!-- Header -->
    <div class="flex items-center gap-3 px-5 pt-5 pb-4 border-b border-brand-navy/[0.06] dark:border-white/10">
      <span class="relative flex-none">
        <img src="/ai/avatar-96.webp" alt="" class="h-11 w-11 rounded-full object-cover bg-white ring-2 ring-brand-gold/70" />
        <span class="absolute bottom-0 right-0 h-3 w-3 rounded-full bg-emerald-500 ring-2 ring-white dark:ring-[#0b1f45]" aria-hidden="true" />
      </span>
      <div class="min-w-0 flex-1">
        <h2 class="font-heading text-xl font-semibold leading-tight text-brand-navy dark:text-white">RealtyLink AI</h2>
        <p class="text-xs text-brand-navy/55 dark:text-white/50">Searches live listings from verified agents</p>
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
      <button type="button" class="h-9 w-9 flex-none rounded-full flex items-center justify-center text-brand-navy/55 hover:text-brand-navy hover:bg-brand-navy/[0.06] dark:text-white/55 dark:hover:text-white dark:hover:bg-white/10 transition" aria-label="Close RealtyLink AI" @click="emit('close')">
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
            {{ firstName ? `Hi ${firstName}, what` : 'What' }} are you looking for?
          </p>
          <p class="mt-1.5 max-w-[17rem] text-sm leading-relaxed text-brand-navy/60 dark:text-white/55">
            Tell me a budget, an area, the kind of home or what it should be near. I can also switch the theme, connect Google Calendar or help with your password.
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
            @click="submit(s.label)"
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
          <div v-if="m.role === 'user'" class="flex justify-end">
            <div class="max-w-[85%] rounded-2xl rounded-br-md px-3.5 py-2 text-sm leading-relaxed whitespace-pre-wrap break-words bg-brand-navy text-white dark:bg-brand-gold dark:text-brand-navy">{{ m.content }}</div>
          </div>

          <div v-else class="flex items-start gap-2">
            <img src="/ai/avatar-96.webp" alt="" class="mt-0.5 h-7 w-7 flex-none rounded-full object-cover bg-white ring-1 ring-brand-gold/50" />
            <div class="min-w-0 max-w-[88%]">
              <div class="rounded-2xl rounded-bl-md px-3.5 py-2 text-sm leading-relaxed whitespace-pre-wrap break-words bg-white text-brand-navy ring-1 ring-brand-navy/[0.06] dark:bg-white/[0.08] dark:text-white dark:ring-white/10">{{ m.content }}</div>

              <!-- Something it did: theme, calendar, password, page -->
              <AiActionCard v-if="m.action" :action="m.action" @update="p => updateAction(i, p)" />

              <!-- Listings it found -->
              <AiListingCards :message="m" :latest="i === messages.length - 1" :busy="loading" @more="submit('Show me more options')" />
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

    <!-- Composer -->
    <form class="flex items-end gap-2 px-3 pt-3 border-t border-brand-navy/[0.06] dark:border-white/10" @submit.prevent="submit()">
      <textarea
        ref="inputEl"
        v-model="draft"
        rows="1"
        maxlength="1000"
        placeholder="Ask about homes, prices, areas…"
        aria-label="Ask RealtyLink AI"
        class="flex-1 resize-none rounded-2xl border-0 bg-brand-navy/[0.05] dark:bg-white/[0.07] px-4 py-2.5 text-sm text-brand-navy dark:text-white placeholder-brand-navy/45 dark:placeholder-white/40 focus:ring-2 focus:ring-brand-gold"
        @input="autosize"
        @keydown="onKeydown"
      />
      <button
        type="submit"
        class="flex-none h-10 w-10 rounded-full bg-brand-gold text-brand-navy flex items-center justify-center shadow-[0_4px_14px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light disabled:opacity-40 disabled:shadow-none transition"
        :disabled="!draft.trim() || loading"
        aria-label="Send"
      >
        <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
      </button>
    </form>
    <p class="px-4 pt-1.5 pb-2.5 text-[0.625rem] text-brand-navy/40 dark:text-white/35">AI can make mistakes. Always check listing details with the agent.</p>
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
