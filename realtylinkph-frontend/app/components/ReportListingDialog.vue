<script setup lang="ts">
import type { Property } from '~/types'

/** "Report this listing": tells the admins something is wrong. RealtyLink AI's flags and these reports land in the same queue. */
const props = defineProps<{ open: boolean; property: Property | null }>()
const emit = defineEmits<{ close: [] }>()

const api = useApi()
const toast = useToast()

type Reason = 'sold' | 'wrong_details' | 'scam' | 'photos' | 'other'
const REASONS: Array<{ key: Reason; label: string; hint: string }> = [
  { key: 'sold', label: 'Already sold or not available', hint: 'The agent said it is gone, or it was sold elsewhere' },
  { key: 'wrong_details', label: 'Wrong price or details', hint: 'The price, size or location is not what is shown' },
  { key: 'scam', label: 'Looks fake or like a scam', hint: 'For example, asked to send money before a viewing' },
  { key: 'photos', label: "Photos don't match", hint: 'The photos show a different property' },
  { key: 'other', label: 'Something else', hint: 'Tell us in a few words' },
]
const reason = ref<Reason | null>(null)
const note = ref('')
const sending = ref(false)
const failed = ref<string | null>(null)

watch(() => props.open, (o) => {
  if (!o) return
  reason.value = null
  note.value = ''
  failed.value = null
  sending.value = false
})

const ready = computed(() => !!reason.value && (reason.value !== 'other' || note.value.trim().length >= 5))

async function submit() {
  if (!ready.value || !props.property || sending.value) return
  sending.value = true
  failed.value = null
  try {
    await api.post(`/properties/${props.property.id}/report`, { reason: reason.value, note: note.value.trim() || null })
    toast.success('Thanks for reporting', { description: 'Our team will check this listing.' })
    emit('close')
  } catch (e: unknown) {
    const d = (e as { data?: { message?: string; errors?: Record<string, string[]> } })?.data
    failed.value = d?.errors ? Object.values(d.errors).flat()[0] ?? d.message ?? 'That didn’t go through.' : d?.message ?? 'That didn’t go through. Please try again.'
  } finally {
    sending.value = false
  }
}
function close() { if (!sending.value) emit('close') }
</script>

<template>
  <AppDialog :open="open && !!property" title="Report this listing" subtitle="Only our team sees your report. The agent isn't told who sent it." tone="warning" icon="warning" :busy="sending" size="md" @close="close">
    <form v-if="property" @submit.prevent="submit">
      <p class="truncate text-sm font-semibold text-brand-navy dark:text-white">{{ property.title }}</p>
      <p class="mt-4 text-sm font-semibold text-brand-navy dark:text-white">What's wrong?</p>
      <div class="mt-2 space-y-2" role="radiogroup" aria-label="What's wrong?">
        <button
          v-for="r in REASONS"
          :key="r.key"
          type="button"
          role="radio"
          :aria-checked="reason === r.key"
          class="w-full rounded-2xl p-3 text-left ring-1 transition"
          :class="reason === r.key ? 'ring-amber-400/60 bg-amber-500/[0.06]' : 'ring-brand-navy/10 hover:ring-brand-gold dark:ring-white/10'"
          @click="reason = r.key"
        >
          <span class="block text-sm font-semibold text-brand-navy dark:text-white">{{ r.label }}</span>
          <span class="block text-xs text-brand-navy/60 dark:text-white/55">{{ r.hint }}</span>
        </button>
      </div>
      <label for="rl-note" class="mt-4 mb-1.5 block text-sm font-semibold text-brand-navy dark:text-white">
        Details <span class="font-normal text-brand-navy/50 dark:text-white/45">{{ reason === 'other' ? '(required)' : '(optional)' }}</span>
      </label>
      <textarea id="rl-note" v-model="note" rows="3" maxlength="500" placeholder="Anything that helps our team check it" :class="[DIALOG_FIELD, 'resize-none']" />
      <p v-if="failed" class="mt-4 rounded-2xl bg-red-500/[0.08] px-4 py-3 text-sm text-red-700 ring-1 ring-red-500/20 dark:text-red-300" role="alert">{{ failed }}</p>
    </form>
    <template #footer>
      <button type="button" :class="DIALOG_BTN.cancel" :disabled="sending" @click="close">Cancel</button>
      <button type="button" :class="DIALOG_BTN.warning" :disabled="!ready || sending" @click="submit">{{ sending ? 'Sending…' : 'Send report' }}</button>
    </template>
  </AppDialog>
</template>
