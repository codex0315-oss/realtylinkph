<script setup lang="ts">
import type { AiChatMessage } from '~/composables/useRealtyAi'
import type { Property } from '~/types'

// `closable` shows an X in the header (used by the full-screen bubble overlay).
defineProps<{ closable?: boolean }>()
const emit = defineEmits<{ close: [] }>()

const { chat, loading } = useRealtyAi()
const propertyHref = usePropertyHref()

const messages  = ref<AiChatMessage[]>([])
const input     = ref('')
const threadEl  = ref<HTMLElement | null>(null)
const mascotOk  = ref(true)

const suggestions = [
  'Find me a condo under ₱3M',
  '2-bedroom house in Cebu',
  'What can I afford on ₱25k/month?',
  'Best areas for first-time buyers?',
]

function scrollToBottom() {
  nextTick(() => { if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight })
}

async function send(text?: string) {
  const content = (text ?? input.value).trim()
  if (!content || loading.value) return

  messages.value.push({ role: 'user', content })
  input.value = ''
  scrollToBottom()

  const res = await chat(messages.value)
  messages.value.push({
    role: 'assistant',
    content: res?.reply ?? "Sorry, I'm having trouble right now. Please try again.",
    properties: res?.properties ?? [],
  })
  scrollToBottom()
}

function priceLabel(p: Property): string {
  const n = Number(p.price)
  if (n >= 1_000_000) return `₱${(n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1)}M`
  if (n >= 1_000)     return `₱${(n / 1_000).toFixed(0)}K`
  return `₱${n.toLocaleString('en-PH')}`
}
</script>

<template>
  <div class="flex flex-col h-full w-full bg-white dark:bg-[#10264D] overflow-hidden">
    <!-- Header -->
    <div class="flex items-center gap-3 px-5 py-3.5 border-b border-gray-100 dark:border-white/10">
      <div class="h-10 w-10 rounded-full bg-brand-navy/5 dark:bg-white/5 flex items-center justify-center overflow-hidden flex-shrink-0">
        <img v-if="mascotOk" src="/realtylink-ai1.png" alt="RealtyLink AI" class="w-full h-full object-contain" @error="mascotOk = false" />
        <svg v-else class="h-5 w-5 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.456-2.456L14.25 6l1.035-.259a3.375 3.375 0 002.456-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z" /></svg>
      </div>
      <div class="min-w-0">
        <p class="text-sm font-bold text-brand-navy dark:text-white flex items-center gap-1.5">
          RealtyLink AI
          <span class="text-[9px] font-bold uppercase tracking-wide bg-brand-gold/15 text-brand-gold px-1.5 py-0.5 rounded">Beta</span>
        </p>
        <p class="text-[11px] text-gray-400 dark:text-white/40">Your personal property finder</p>
      </div>

      <!-- Close (overlay only) -->
      <button
        v-if="closable"
        type="button"
        class="ml-auto h-9 w-9 rounded-full flex items-center justify-center text-gray-400 hover:text-brand-navy dark:hover:text-white hover:bg-gray-100 dark:hover:bg-white/10 transition-colors flex-shrink-0"
        aria-label="Close"
        @click="emit('close')"
      >
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
      </button>
    </div>

    <!-- Thread -->
    <div ref="threadEl" class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-5">

      <!-- Empty / welcome state -->
      <div v-if="!messages.length" class="h-full flex flex-col items-center justify-center text-center px-4">
        <div class="h-28 w-28 mb-4 flex items-center justify-center">
          <img v-if="mascotOk" src="/realtylink-ai1.png" alt="RealtyLink AI" class="w-full h-full object-contain drop-shadow-xl" @error="mascotOk = false" />
          <div v-else class="h-24 w-24 rounded-3xl bg-brand-gold/10 flex items-center justify-center">
            <svg class="h-12 w-12 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
          </div>
        </div>
        <h2 class="font-playfair text-2xl font-bold text-brand-navy dark:text-white">Hi! I'm RealtyLink AI 👋</h2>
        <p class="text-sm text-gray-500 dark:text-white/50 mt-2 max-w-md">
          Tell me your budget, preferred location, and what you're looking for — I'll find verified listings that fit and answer your questions.
        </p>
        <div class="flex flex-wrap items-center justify-center gap-2 mt-6 max-w-lg">
          <button
            v-for="s in suggestions"
            :key="s"
            class="text-xs font-medium px-3.5 py-2 rounded-full border border-gray-200 dark:border-white/10 text-brand-navy dark:text-white/80 hover:border-brand-gold hover:text-brand-gold transition-colors"
            @click="send(s)"
          >
            {{ s }}
          </button>
        </div>
      </div>

      <!-- Messages -->
      <template v-for="(m, i) in messages" :key="i">
        <!-- User -->
        <div v-if="m.role === 'user'" class="flex justify-end">
          <div class="max-w-[80%] px-4 py-2.5 text-sm leading-relaxed rounded-2xl rounded-br-md text-white shadow-sm" style="background: linear-gradient(135deg, #10264D 0%, #08152F 100%)">
            {{ m.content }}
          </div>
        </div>

        <!-- Assistant -->
        <div v-else class="flex items-start gap-2.5">
          <div class="h-8 w-8 rounded-full bg-brand-gold/10 flex items-center justify-center overflow-hidden flex-shrink-0 mt-0.5">
            <img v-if="mascotOk" src="/realtylink-ai1.png" alt="" class="w-full h-full object-contain" @error="mascotOk = false" />
            <svg v-else class="h-4 w-4 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
          </div>
          <div class="max-w-[82%] min-w-0">
            <div class="px-4 py-2.5 text-sm leading-relaxed rounded-2xl rounded-bl-md bg-gray-100 dark:bg-white/10 text-brand-navy dark:text-white whitespace-pre-wrap">{{ m.content }}</div>

            <!-- Recommended listings -->
            <div v-if="m.properties?.length" class="mt-2 space-y-2">
              <NuxtLink
                v-for="p in m.properties"
                :key="p.id"
                :to="propertyHref(p.id)"
                class="flex items-center gap-3 p-2 rounded-xl bg-white dark:bg-white/5 border border-gray-200 dark:border-white/10 hover:border-brand-gold hover:shadow-sm transition-all group"
                @click="emit('close')"
              >
                <div class="h-12 w-16 rounded-lg bg-gray-100 dark:bg-white/10 overflow-hidden flex-shrink-0">
                  <img v-if="p.photos?.length" :src="p.photos[0]?.thumb_url ?? p.photos[0]?.url" :alt="p.title" class="w-full h-full object-cover" />
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-semibold text-brand-navy dark:text-white truncate group-hover:text-brand-gold transition-colors">{{ p.title }}</p>
                  <p class="text-xs text-gray-400 truncate">{{ p.address }}</p>
                  <p class="text-xs text-gray-500 dark:text-white/50 mt-0.5">
                    <span v-if="p.bedrooms">{{ p.bedrooms }} bd · </span><span v-if="p.bathrooms">{{ p.bathrooms }} ba</span>
                  </p>
                </div>
                <span class="text-sm font-bold text-brand-gold flex-shrink-0">{{ priceLabel(p) }}</span>
              </NuxtLink>
            </div>
          </div>
        </div>
      </template>

      <!-- Thinking -->
      <div v-if="loading" class="flex items-start gap-2.5">
        <div class="h-8 w-8 rounded-full bg-brand-gold/10 flex items-center justify-center overflow-hidden flex-shrink-0">
          <img v-if="mascotOk" src="/realtylink-ai1.png" alt="" class="w-full h-full object-contain" @error="mascotOk = false" />
          <svg v-else class="h-4 w-4 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25z" /></svg>
        </div>
        <div class="bg-gray-100 dark:bg-white/10 rounded-2xl rounded-bl-md px-4 py-3">
          <span class="flex gap-1">
            <span class="h-1.5 w-1.5 rounded-full bg-gray-400 dark:bg-white/50 ai-dot" style="animation-delay:0ms" />
            <span class="h-1.5 w-1.5 rounded-full bg-gray-400 dark:bg-white/50 ai-dot" style="animation-delay:150ms" />
            <span class="h-1.5 w-1.5 rounded-full bg-gray-400 dark:bg-white/50 ai-dot" style="animation-delay:300ms" />
          </span>
        </div>
      </div>
    </div>

    <!-- Input -->
    <div class="px-4 py-3 border-t border-gray-100 dark:border-white/10">
      <div class="flex items-center gap-2 bg-gray-50 dark:bg-white/5 rounded-2xl px-2 py-1.5 max-w-3xl mx-auto w-full">
        <input
          v-model="input"
          type="text"
          placeholder="Ask RealtyLink AI to find your home…"
          class="flex-1 bg-transparent text-sm text-brand-navy dark:text-white placeholder-gray-400 outline-none py-2 px-2"
          :disabled="loading"
          @keydown.enter.prevent="send()"
        />
        <button
          class="h-9 w-9 rounded-full flex items-center justify-center transition-all disabled:opacity-40 flex-shrink-0"
          style="background: linear-gradient(135deg, #D4AF37 0%, #c9a227 100%)"
          :disabled="loading || !input.trim()"
          @click="send()"
        >
          <svg class="h-4 w-4 text-brand-navy" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" /></svg>
        </button>
      </div>
      <p class="text-[10px] text-gray-400 dark:text-white/30 text-center mt-1.5">RealtyLink AI can make mistakes — always verify listing details.</p>
    </div>
  </div>
</template>

<style scoped>
.ai-dot { animation: ai-bounce 1s infinite ease-in-out; }
@keyframes ai-bounce {
  0%, 60%, 100% { transform: translateY(0); opacity: 0.5; }
  30% { transform: translateY(-4px); opacity: 1; }
}
</style>
