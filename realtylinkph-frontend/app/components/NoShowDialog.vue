<script setup lang="ts">
import type { Appointment } from '~/types'

/**
 * "They didn't show up." The reporter is told what it does before they send it:
 * two strikes for the other person, who is told straight away, and an admin
 * can undo it if the report is wrong. A short note is optional.
 */
const props = defineProps<{
  open: boolean
  appointment: Appointment | null
  /** The viewer is the agent (so the person who didn't come is the buyer). */
  isAgent: boolean
}>()
const emit = defineEmits<{ close: []; done: [] }>()

const { reportNoShow, error } = useAppointment()
const toast = useToast()

const note = ref('')
const sending = ref(false)
const failed = ref('')

watch(() => props.open, (o) => {
  if (o) { note.value = ''; failed.value = ''; sending.value = false }
})

const other = computed(() => (props.isAgent ? props.appointment?.buyer?.name : props.appointment?.agent?.name) ?? (props.isAgent ? 'The buyer' : 'The agent'))
const first = computed(() => other.value.split(' ')[0])

function fmtWhen(iso?: string) {
  if (!iso) return ''
  return new Date(iso).toLocaleString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

async function submit() {
  if (!props.appointment || sending.value) return
  sending.value = true
  failed.value = ''
  const ok = await reportNoShow(props.appointment.id, note.value.trim())
  sending.value = false
  if (!ok) { failed.value = error.value ?? 'That didn’t go through. Please try again.'; return }
  toast.success(`No-show reported. ${first.value} has been told`)
  emit('done')
}

function close() { if (!sending.value) emit('close') }
</script>

<template>
  <AppDialog
    :open="open && !!appointment"
    title="Report a no-show?"
    :subtitle="`${other} didn’t turn up to this viewing.`"
    tone="danger"
    icon="calendar"
    :busy="sending"
    size="md"
    @close="close"
  >
    <form v-if="appointment" class="space-y-4" @submit.prevent="submit">
      <div class="rounded-2xl bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.06] dark:ring-white/10 px-4 py-3">
        <p class="text-sm font-semibold text-brand-navy dark:text-white truncate">{{ appointment.property?.title ?? `Property #${appointment.property_id}` }}</p>
        <p class="text-xs text-brand-text-secondary dark:text-white/50 mt-0.5">{{ fmtWhen(appointment.preferred_datetime) }}</p>
      </div>

      <ul class="space-y-1.5 text-sm text-brand-navy/75 dark:text-white/70">
        <li class="flex gap-2"><span class="text-red-500">•</span>{{ first }} is told right away, in the app and by email.</li>
        <li class="flex gap-2"><span class="text-red-500">•</span>It counts as 2 strikes for them. Five in 30 days pauses booking for a week.</li>
        <li class="flex gap-2"><span class="text-red-500">•</span>If you got it wrong, an admin can remove it, so only report a real no-show.</li>
      </ul>

      <div>
        <label for="ns-note" class="mb-1 block text-sm font-medium text-brand-navy dark:text-white">
          Anything to add? <span class="text-xs font-normal text-brand-navy/50 dark:text-white/40">(optional)</span>
        </label>
        <textarea
          id="ns-note"
          v-model="note"
          rows="3"
          maxlength="500"
          :class="DIALOG_FIELD" class="resize-none"
          placeholder="For example: I waited 30 minutes and called twice."
        />
      </div>

      <p v-if="failed" class="rounded-2xl bg-red-500/[0.08] px-4 py-3 text-sm text-red-700 ring-1 ring-red-500/20 dark:text-red-300" role="alert">{{ failed }}</p>
    </form>

    <template #footer>
      <button type="button" :class="DIALOG_BTN.cancel" :disabled="sending" @click="close">Not now</button>
      <button type="button" :class="DIALOG_BTN.danger" :disabled="sending" @click="submit">
        <svg v-if="sending" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" /><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
        {{ sending ? 'Reporting…' : 'Report no-show' }}
      </button>
    </template>
  </AppDialog>
</template>
