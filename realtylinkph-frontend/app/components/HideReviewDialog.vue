<script setup lang="ts">
import type { AgentReview } from '~/types'

/**
 * Hide a review (admin Reviews page). A reason is required: it's sent to the
 * reviewer, shown on the hidden card, and kept in Activity. Preset chips add
 * the common ones.
 */
const props = defineProps<{ open: boolean; review: AgentReview | null }>()
const emit = defineEmits<{ close: []; done: [] }>()

const { toggleVisibility } = useReview()
const toast = useToast()

const reason = ref('')
const sending = ref(false)
const failed = ref(false)
const REASON_MIN = 10

watch(() => props.open, (o) => {
  if (!o) return
  reason.value = ''
  failed.value = false
  sending.value = false
})

const reviewer = computed(() => props.review?.buyer?.name ?? 'The reviewer')
const first = computed(() => reviewer.value.split(' ')[0])
const PRESETS = [
  'It contains offensive or abusive language.',
  'It shares personal information (phone, address or email).',
  "It's not about the agent's service.",
  'It looks like spam or a fake review.',
  "The reviewer has a conflict of interest with this agent.",
]
function addPreset(p: string) {
  if (reason.value.includes(p)) return
  reason.value = reason.value.trim() ? `${reason.value.trim()} ${p}` : p
}

async function submit() {
  if (!props.review || reason.value.trim().length < REASON_MIN || sending.value) return
  sending.value = true
  failed.value = false
  const ok = await toggleVisibility(props.review.id, reason.value.trim())
  sending.value = false
  if (!ok) { failed.value = true; return }
  toast.success(`Review hidden. ${first.value} has been told why`)
  emit('done')
}

function close() { if (!sending.value) emit('close') }
function onKey(e: KeyboardEvent) { if (e.key === 'Escape' && props.open) close() }
onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <Transition name="hr-backdrop">
      <div v-if="open && review" class="fixed inset-0 z-[95] flex items-end sm:items-center justify-center sm:p-4 bg-brand-navy-deep/60 backdrop-blur-sm" @click.self="close">
        <Transition name="hr-panel" appear>
          <form
            v-if="open"
            role="dialog"
            aria-modal="true"
            aria-labelledby="hr-title"
            class="relative w-full sm:max-w-lg max-h-[92vh] overflow-y-auto rounded-t-[1.75rem] sm:rounded-[1.75rem] p-6 sm:p-7
                   bg-white dark:bg-[#0d2550] ring-1 ring-brand-navy/[0.06] dark:ring-white/10 shadow-[0_40px_100px_-30px_rgba(6,24,58,0.6)]"
            @submit.prevent="submit"
          >
            <button
              v-if="!sending"
              type="button"
              class="absolute top-4 right-4 h-9 w-9 rounded-full flex items-center justify-center text-brand-navy/60 hover:text-brand-navy hover:bg-brand-navy/5 dark:text-white/60 dark:hover:text-white dark:hover:bg-white/10 transition"
              aria-label="Close"
              @click="close"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>

            <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-red-600 dark:text-red-400">Hide review</p>
            <h2 id="hr-title" class="mt-1 pr-10 font-heading text-2xl font-semibold tracking-[-0.01em] text-brand-navy dark:text-white">Why hide this review?</h2>

            <!-- Which review -->
            <div class="mt-5 rounded-2xl p-3 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.05] dark:ring-white/10">
              <div class="flex items-center gap-3">
                <AppAvatar :name="review.buyer?.name" :src="review.buyer?.avatar" size="sm" />
                <p class="min-w-0 flex-1 truncate text-sm text-brand-navy dark:text-white">
                  <span class="font-semibold">{{ reviewer }}</span>
                  <span class="text-brand-navy/50 dark:text-white/50"> → {{ review.agent?.name ?? 'Agent' }}</span>
                </p>
                <span class="flex-none text-sm font-bold text-brand-gold-deep dark:text-brand-gold">{{ review.rating }}★</span>
              </div>
            </div>

            <ul class="mt-4 space-y-1 text-xs text-brand-navy/65 dark:text-white/60">
              <li class="flex gap-2"><span class="text-red-500">•</span>It's taken off {{ review.agent?.name ?? 'the agent' }}'s profile and their rating right away.</li>
              <li class="flex gap-2"><span class="text-red-500">•</span>{{ first }} is told why. You can show it again any time.</li>
            </ul>

            <p class="mt-5 text-sm font-semibold text-brand-navy dark:text-white">Common reasons <span class="font-normal text-brand-navy/50 dark:text-white/45">(tap to add)</span></p>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <button
                v-for="p in PRESETS"
                :key="p"
                type="button"
                class="rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition"
                :class="reason.includes(p)
                  ? 'bg-red-500/10 text-red-700 ring-red-400/40 dark:text-red-300'
                  : 'text-brand-navy/75 ring-brand-navy/10 hover:ring-red-400/60 dark:text-white/70 dark:ring-white/15'"
                @click="addPreset(p)"
              >{{ p }}</button>
            </div>

            <label for="hr-reason" class="mt-4 mb-1.5 block text-sm font-semibold text-brand-navy dark:text-white">Reason <span class="text-red-500">*</span></label>
            <textarea
              id="hr-reason"
              v-model="reason"
              rows="3"
              maxlength="500"
              placeholder="What's wrong with this review?"
              class="w-full resize-none rounded-xl border-0 bg-brand-navy/[0.04] dark:bg-white/[0.06] px-4 py-3 text-sm text-brand-navy dark:text-white placeholder-brand-navy/35 dark:placeholder-white/35 ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold"
            />
            <div class="mt-1 flex items-start justify-between gap-3">
              <p class="text-xs text-brand-navy/55 dark:text-white/50">Sent to {{ reviewer }}, shown on the hidden review, and kept in Activity.</p>
              <span class="flex-none text-xs tabular-nums" :class="reason.trim().length >= REASON_MIN ? 'text-emerald-600 dark:text-emerald-400' : 'text-brand-navy/40 dark:text-white/35'">{{ reason.trim().length }}/500</span>
            </div>

            <p v-if="failed" class="mt-4 rounded-xl bg-red-500/10 px-4 py-3 text-sm text-red-600 dark:text-red-400" role="alert">That didn't go through. Please try again.</p>

            <div class="mt-6 flex flex-col-reverse sm:flex-row gap-3">
              <button type="button" class="flex-1 rounded-full px-5 py-3 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold transition" :disabled="sending" @click="close">Cancel</button>
              <button
                type="submit"
                class="flex-1 inline-flex items-center justify-center gap-2 rounded-full bg-red-600 px-5 py-3 text-sm font-bold text-white shadow-[0_4px_14px_rgba(220,38,38,0.35)] hover:bg-red-700 transition disabled:opacity-50 disabled:shadow-none"
                :disabled="reason.trim().length < REASON_MIN || sending"
              >
                <svg v-if="sending" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" /><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                {{ sending ? 'Hiding…' : 'Hide and notify' }}
              </button>
            </div>
          </form>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.hr-backdrop-enter-active, .hr-backdrop-leave-active { transition: opacity 0.22s ease; }
.hr-backdrop-enter-from, .hr-backdrop-leave-to { opacity: 0; }
.hr-panel-enter-active { transition: opacity 0.25s ease, transform 0.35s cubic-bezier(0.22, 1, 0.36, 1); }
.hr-panel-enter-from { opacity: 0; transform: translateY(24px) scale(0.98); }
@media (prefers-reduced-motion: reduce) { .hr-panel-enter-active { transition: none; } }
</style>
