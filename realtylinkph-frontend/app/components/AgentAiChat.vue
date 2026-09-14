<script setup lang="ts">
import type { AgentAiMessage, AgentListingProposal } from '~/composables/useAgentAi'

defineProps<{ closable?: boolean }>()
const emit = defineEmits<{ close: [] }>()

const { chat, loading } = useAgentAi()
const { createProperty, error: createError } = useProperty()
const createErrorMsg = ref('')

const messages   = ref<AgentAiMessage[]>([])
const input      = ref('')
const threadEl   = ref<HTMLElement | null>(null)
const fileInput  = ref<HTMLInputElement | null>(null)
const mascotOk   = ref(true)

const attachedFile    = ref<File | null>(null)
const attachedPreview = ref('')

const creating  = ref(false)
const createdAt = ref<Set<number>>(new Set()) // message indexes whose draft was created

const suggestions = [
  'Write a description for a 3BR house in Quezon City',
  'Create a listing: condo in BGC, ₱8M, 2BR 2BA, 60sqm',
  'Which of my listings has the most views?',
  'Suggest a price for a 120sqm lot in Cebu',
]

function scrollToBottom() {
  nextTick(() => { if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight })
}

function onFile(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  attachedFile.value = file
  const reader = new FileReader()
  reader.onload = ev => { attachedPreview.value = ev.target?.result as string }
  reader.readAsDataURL(file)
}
function clearAttachment() {
  attachedFile.value = null
  attachedPreview.value = ''
  if (fileInput.value) fileInput.value.value = ''
}

async function send(text?: string) {
  const content = (text ?? input.value).trim()
  if ((!content && !attachedFile.value) || loading.value) return

  messages.value.push({
    role: 'user',
    content: content || 'Write a description for this property photo.',
    image: attachedPreview.value || undefined,
  })

  const fileToSend = attachedFile.value
  input.value = ''
  clearAttachment()
  scrollToBottom()

  const res = await chat(messages.value, fileToSend)
  messages.value.push({
    role: 'assistant',
    content: res?.reply ?? "Sorry, I'm having trouble right now. Please try again.",
    proposal: res?.listing_proposal ?? null,
  })
  scrollToBottom()
}

async function createDraft(proposal: AgentListingProposal, msgIndex: number) {
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
    createdAt.value.add(msgIndex)
    emit('close')
    await navigateTo(`/dashboard/listings/${prop.id}/edit`)
  } else {
    createErrorMsg.value = createError.value || 'Could not create the draft. Restart the dev server and make sure you are a verified agent, then try again.'
  }
}

function peso(n: number): string {
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
        <svg v-else class="h-5 w-5 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
      </div>
      <div class="min-w-0">
        <p class="text-sm font-bold text-brand-navy dark:text-white flex items-center gap-1.5">
          RealtyLink AI
          <span class="text-[9px] font-bold uppercase tracking-wide bg-brand-gold/15 text-brand-gold px-1.5 py-0.5 rounded">Agent</span>
        </p>
        <p class="text-[11px] text-gray-400 dark:text-white/40">Your listing assistant</p>
      </div>

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
      <!-- Welcome -->
      <div v-if="!messages.length" class="h-full flex flex-col items-center justify-center text-center px-4">
        <div class="h-28 w-28 mb-4 flex items-center justify-center">
          <img v-if="mascotOk" src="/realtylink-ai1.png" alt="RealtyLink AI" class="w-full h-full object-contain drop-shadow-xl" @error="mascotOk = false" />
        </div>
        <h2 class="font-playfair text-2xl font-bold text-brand-navy dark:text-white">Hi! I'm your listing assistant 🏠</h2>
        <p class="text-sm text-gray-500 dark:text-white/50 mt-2 max-w-md">
          Attach a property photo and I'll write the description, draft a full listing from a few details, or answer questions about your listings.
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
          <div class="max-w-[80%] space-y-2">
            <img v-if="m.image" :src="m.image" class="rounded-2xl rounded-br-md max-h-48 ml-auto border border-gray-200 dark:border-white/10" />
            <div class="px-4 py-2.5 text-sm leading-relaxed rounded-2xl rounded-br-md text-white shadow-sm" style="background: linear-gradient(135deg, #10264D 0%, #08152F 100%)">{{ m.content }}</div>
          </div>
        </div>

        <!-- Assistant -->
        <div v-else class="flex items-start gap-2.5">
          <div class="h-8 w-8 rounded-full bg-brand-gold/10 flex items-center justify-center overflow-hidden flex-shrink-0 mt-0.5">
            <img v-if="mascotOk" src="/realtylink-ai1.png" alt="" class="w-full h-full object-contain" @error="mascotOk = false" />
          </div>
          <div class="max-w-[82%] min-w-0">
            <div class="px-4 py-2.5 text-sm leading-relaxed rounded-2xl rounded-bl-md bg-gray-100 dark:bg-white/10 text-brand-navy dark:text-white whitespace-pre-wrap">{{ m.content }}</div>

            <!-- Listing proposal card -->
            <div v-if="m.proposal" class="mt-2 rounded-2xl border border-brand-gold/30 bg-brand-gold/5 p-4">
              <div class="flex items-center gap-1.5 mb-2">
                <svg class="h-4 w-4 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
                <p class="text-xs font-bold text-brand-navy dark:text-white uppercase tracking-wide">Draft listing</p>
              </div>
              <p class="text-sm font-bold text-brand-navy dark:text-white">{{ m.proposal.title }}</p>
              <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-600 dark:text-white/60 mt-1">
                <span class="text-[10px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded" :class="m.proposal.offer_type === 'rent' ? 'bg-blue-100 text-blue-700' : 'bg-emerald-100 text-emerald-700'">{{ m.proposal.offer_type === 'rent' ? 'For Rent' : 'For Sale' }}</span>
                <span class="capitalize">{{ m.proposal.type }}</span>
                <span class="font-semibold text-brand-gold">{{ peso(m.proposal.price) }}</span>
                <span v-if="m.proposal.bedrooms">{{ m.proposal.bedrooms }}BR</span>
                <span v-if="m.proposal.bathrooms">{{ m.proposal.bathrooms }}BA</span>
                <span v-if="m.proposal.floor_area">{{ m.proposal.floor_area }}sqm</span>
              </div>
              <p class="text-xs text-gray-400 mt-1">{{ m.proposal.address }}</p>
              <p class="text-xs text-gray-600 dark:text-white/60 mt-2 line-clamp-3 whitespace-pre-wrap">{{ m.proposal.description }}</p>

              <button
                v-if="!createdAt.has(i)"
                :disabled="creating"
                class="mt-3 w-full inline-flex items-center justify-center gap-2 bg-brand-gold text-brand-navy font-bold text-xs py-2.5 rounded-xl hover:-translate-y-0.5 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                @click="createDraft(m.proposal, i)"
              >
                <svg v-if="creating" class="animate-spin h-3.5 w-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                {{ creating ? 'Creating draft…' : 'Create draft & add photos' }}
              </button>
              <p v-else class="mt-3 text-xs font-semibold text-emerald-600 text-center">Draft created ✓</p>
              <p v-if="createErrorMsg" class="mt-2 text-xs text-red-500 text-center">{{ createErrorMsg }}</p>
            </div>
          </div>
        </div>
      </template>

      <!-- Thinking -->
      <div v-if="loading" class="flex items-start gap-2.5">
        <div class="h-8 w-8 rounded-full bg-brand-gold/10 flex items-center justify-center overflow-hidden flex-shrink-0">
          <img v-if="mascotOk" src="/realtylink-ai1.png" alt="" class="w-full h-full object-contain" @error="mascotOk = false" />
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
      <!-- Attachment preview -->
      <div v-if="attachedPreview" class="mb-2 inline-flex items-center gap-2 bg-gray-100 dark:bg-white/5 rounded-xl p-1.5 pr-3">
        <img :src="attachedPreview" class="h-10 w-10 rounded-lg object-cover" />
        <span class="text-xs text-gray-500 dark:text-white/50">Photo attached</span>
        <button class="text-gray-400 hover:text-red-500" @click="clearAttachment">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
      </div>

      <div class="flex items-center gap-2 bg-gray-50 dark:bg-white/5 rounded-2xl px-2 py-1.5 max-w-3xl mx-auto w-full">
        <input ref="fileInput" type="file" accept="image/*" class="sr-only" @change="onFile" />
        <button
          class="h-9 w-9 rounded-full flex items-center justify-center text-gray-400 hover:text-brand-gold hover:bg-gray-100 dark:hover:bg-white/10 transition-colors flex-shrink-0"
          title="Attach a property photo"
          @click="fileInput?.click()"
        >
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
        </button>
        <input
          v-model="input"
          type="text"
          placeholder="Describe a property, or attach a photo…"
          class="flex-1 bg-transparent text-sm text-brand-navy dark:text-white placeholder-gray-400 outline-none py-2 px-2"
          :disabled="loading"
          @keydown.enter.prevent="send()"
        />
        <button
          class="h-9 w-9 rounded-full flex items-center justify-center transition-all disabled:opacity-40 flex-shrink-0"
          style="background: linear-gradient(135deg, #D4AF37 0%, #c9a227 100%)"
          :disabled="loading || (!input.trim() && !attachedFile)"
          @click="send()"
        >
          <svg class="h-4 w-4 text-brand-navy" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" /></svg>
        </button>
      </div>
      <p class="text-[10px] text-gray-400 dark:text-white/30 text-center mt-1.5">RealtyLink AI can make mistakes — review drafts before publishing.</p>
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
