<script setup lang="ts">
import type { Appointment } from '~/types'

/**
 * Cancelling a viewing now needs a reason, and the person cancelling is told
 * up front whether this one counts against them. Nobody should discover the
 * rule by being locked out afterwards.
 */
const props = defineProps<{
  open: boolean
  appointment: Appointment | null
  /** The canceller is the agent (changes the reason list and the wording). */
  isAgent: boolean
  sending?: boolean
}>()

const emit = defineEmits<{
  close: []
  confirm: [payload: { code: string; note: string }]
}>()

/* Mirrors Appointment::BUYER_REASONS / AGENT_REASONS on the server. */
const BUYER_REASONS: Array<[string, string]> = [
  ['schedule_conflict',    'Schedule conflict'],
  ['found_another',        'Found another property'],
  ['no_longer_interested', 'No longer interested'],
  ['agent_unresponsive',   'Agent was unresponsive'],
  ['emergency',            'Emergency'],
  ['other',                'Other'],
]
const AGENT_REASONS: Array<[string, string]> = [
  ['property_unavailable', 'Property is no longer available'],
  ['schedule_conflict',    'Schedule conflict'],
  ['double_booked',        'Double booked'],
  ['buyer_unresponsive',   'Buyer was unresponsive'],
  ['emergency',            'Emergency'],
  ['other',                'Other'],
]
const reasons = computed(() => (props.isAgent ? AGENT_REASONS : BUYER_REASONS))

const code = ref('')
const note = ref('')
const touched = ref(false)

/** Only a confirmed viewing inside the 24h window counts. */
const isLate = computed(() => props.appointment?.cancel_is_late === true)
/** An agent turning down a request that was never confirmed isn't "cancelling". */
const isDecline = computed(() => props.isAgent && props.appointment?.status === 'pending')
const otherParty = computed(() => (props.isAgent ? 'The buyer' : 'The agent'))

const noteRequired = computed(() => code.value === 'other')
const canSubmit = computed(() => !!code.value && (!noteRequired.value || note.value.trim().length > 0))

watch(() => props.open, (open) => {
  if (open) { code.value = ''; note.value = ''; touched.value = false }
})

function submit() {
  touched.value = true
  if (!canSubmit.value) return
  emit('confirm', { code: code.value, note: note.value.trim() })
}

function fmtWhen(iso?: string) {
  if (!iso) return ''
  return new Date(iso).toLocaleString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <AppModal :open="open" :title="isDecline ? 'Decline this request' : 'Cancel this viewing'" @close="emit('close')">
    <div v-if="appointment" class="p-6 space-y-4">
      <!-- What's being cancelled -->
      <div class="rounded-xl bg-gray-50 dark:bg-white/5 border border-gray-200 dark:border-white/10 p-3">
        <p class="text-sm font-semibold text-brand-navy dark:text-white truncate">
          {{ appointment.property?.title ?? `Property #${appointment.property_id}` }}
        </p>
        <p class="text-xs text-brand-text-secondary dark:text-white/50 mt-0.5">
          {{ fmtWhen(appointment.preferred_datetime) }}
        </p>
      </div>

      <!-- The consequence, stated before they act -->
      <div
        v-if="isLate"
        class="rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-500/10 dark:border-amber-500/30 px-4 py-3"
      >
        <p class="text-sm font-bold text-amber-900 dark:text-amber-300 flex items-center gap-1.5">
          <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" /></svg>
          This is a late cancellation
        </p>
        <p class="text-xs text-amber-800 dark:text-amber-200/80 mt-1 leading-relaxed">
          The viewing is confirmed and less than 24 hours away, so it counts toward your
          reliability. Five in 30 days pauses booking for a week.
        </p>
      </div>
      <p v-else class="text-sm text-brand-text-secondary dark:text-white/55">
        {{ otherParty }} will be notified straight away, the slot is released, and it's removed
        from both Google Calendars.
        <span v-if="!isDecline" class="block mt-1">This one does <span class="font-semibold">not</span> count against your reliability.</span>
      </p>

      <!-- Reason -->
      <div>
        <label class="text-sm font-medium text-brand-text-primary dark:text-white block mb-1.5">
          Reason <span class="text-red-500">*</span>
        </label>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
          <button
            v-for="[value, label] in reasons"
            :key="value"
            type="button"
            class="text-left text-sm px-3 py-2.5 rounded-xl border transition-colors"
            :class="code === value
              ? 'border-brand-gold bg-brand-gold/10 text-brand-navy dark:text-white font-semibold'
              : 'border-gray-200 dark:border-white/15 text-brand-navy/75 dark:text-white/70 hover:border-brand-gold/50'"
            @click="code = value"
          >{{ label }}</button>
        </div>
        <p v-if="touched && !code" class="text-xs text-red-600 dark:text-red-400 mt-1.5">
          Please choose a reason for cancelling.
        </p>
      </div>

      <!-- Detail -->
      <div>
        <label class="text-sm font-medium text-brand-text-primary dark:text-white block mb-1">
          {{ noteRequired ? 'Please explain' : 'Anything to add?' }}
          <span v-if="noteRequired" class="text-red-500">*</span>
          <span v-else class="text-xs font-normal text-brand-text-secondary dark:text-white/40">(optional)</span>
        </label>
        <textarea
          v-model="note"
          rows="3"
          maxlength="500"
          class="input-field resize-none"
          :placeholder="isAgent ? 'This is shared with the buyer.' : 'This is shared with the agent.'"
        />
        <p v-if="touched && noteRequired && !note.trim()" class="text-xs text-red-600 dark:text-red-400 mt-1.5">
          Please describe the reason when choosing "Other".
        </p>
      </div>

      <div class="flex gap-3 pt-1">
        <AppButton variant="ghost" full-width :disabled="sending" @click="emit('close')">Keep it</AppButton>
        <AppButton variant="danger" full-width :disabled="sending || !canSubmit" @click="submit">
          {{ sending ? 'Cancelling…' : (isDecline ? 'Decline request' : 'Cancel viewing') }}
        </AppButton>
      </div>
    </div>
  </AppModal>
</template>
