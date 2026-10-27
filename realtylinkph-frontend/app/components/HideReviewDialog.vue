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
</script>

<template>
  <AppDialog
    :open="open && !!review"
    title="Why hide this review?"
    subtitle="Hide review: the reviewer is told why"
    tone="danger"
    icon="hide"
    :busy="sending"
    size="md"
    @close="close"
  >
          <form v-if="review" @submit.prevent="submit">
            <!-- Which review -->
            <div class="rounded-2xl p-3 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.05] dark:ring-white/10">
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

            <p v-if="failed" class="mt-4 rounded-2xl bg-red-500/[0.08] px-4 py-3 text-sm text-red-700 ring-1 ring-red-500/20 dark:text-red-300" role="alert">That didn't go through. Please try again.</p>
          </form>

    <template #footer>
      <button type="button" :class="DIALOG_BTN.cancel" :disabled="sending" @click="close">Cancel</button>
      <button type="button" :class="DIALOG_BTN.danger" :disabled="reason.trim().length < REASON_MIN || sending" @click="submit">
        <svg v-if="sending" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" /><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
        {{ sending ? 'Hiding…' : 'Hide and notify' }}
      </button>
    </template>
  </AppDialog>
</template>
