<script setup lang="ts">
/**
 * Sanction an agent who games the platform (e.g. fake buyer accounts reviewing
 * themselves). In steps: warning → listing suspension (7/14/30 days) →
 * verification revoked. A suspension can be lifted early. The reason is
 * required: the agent reads it in the app and by email, and it is logged.
 */
interface AgentRef { id: number; name?: string | null; email?: string | null; avatar?: string | null }
type Kind = 'warning' | 'suspension' | 'revocation' | 'lift'

const props = defineProps<{ open: boolean; agent: AgentRef | null; suspendedUntil?: string | null; initial?: Kind }>()
const emit = defineEmits<{ close: []; done: [summary: unknown] }>()

const api = useApi()
const toast = useToast()

const kind = ref<Kind>('warning')
const days = ref<7 | 14 | 30>(7)
const reason = ref('')
const sending = ref(false)
const failed = ref<string | null>(null)
const MIN = 10

watch(() => props.open, (o) => {
  if (!o) return
  kind.value = props.initial ?? (props.suspendedUntil ? 'lift' : 'warning')
  days.value = 7
  reason.value = ''
  failed.value = null
  sending.value = false
})

const first = computed(() => (props.agent?.name ?? 'The agent').split(' ')[0])
const OPTIONS = computed(() => [
  { key: 'warning' as Kind, label: 'Warning', hint: 'A recorded notice. Nothing else changes.' },
  { key: 'suspension' as Kind, label: 'Suspend listings', hint: 'Listings taken down; no publishing for a set time.' },
  { key: 'revocation' as Kind, label: 'Revoke verification', hint: 'Back to a buyer account; can re-apply after 30 days.' },
  ...(props.suspendedUntil ? [{ key: 'lift' as Kind, label: 'Lift suspension', hint: 'Allow publishing again now.' }] : []),
])
const PRESETS = [
  'Reviews came from accounts using the same device and network as you.',
  'Several new accounts that only ever dealt with you left you 5-star reviews.',
  'Fake reviews were written to raise your rating.',
]
function addPreset(p: string) {
  if (reason.value.includes(p)) return
  reason.value = reason.value.trim() ? `${reason.value.trim()} ${p}` : p
}

const consequences = computed(() => ({
  warning: [`${first.value} is told by email and in the app`, 'Logged in Activity'],
  suspension: ['Published listings are taken down, with your reason shown on each', `No new or published listings for ${days.value} days`, 'Viewings and messages keep working', 'Ends by itself; you can lift it early'],
  revocation: ['The verified badge is removed and the account becomes a buyer account', 'All published listings are taken down', 'They can apply again after 30 days'],
  lift: [`${first.value} can publish listings again right away`, 'Logged in Activity'],
}[kind.value]))

const ready = computed(() => reason.value.trim().length >= MIN && !!props.agent)
const tone = computed(() => (kind.value === 'revocation' ? 'danger' : kind.value === 'lift' ? 'primary' : 'warning'))
const confirmLabel = computed(() => ({ warning: 'Send warning', suspension: `Suspend for ${days.value} days`, revocation: 'Revoke verification', lift: 'Lift suspension' }[kind.value]))

async function submit() {
  if (!ready.value || sending.value || !props.agent) return
  sending.value = true
  failed.value = null
  try {
    const res = await api.post<{ data: unknown; message: string }>(`/admin/users/${props.agent.id}/agent-sanctions`, {
      type: kind.value, reason: reason.value.trim(), ...(kind.value === 'suspension' ? { days: days.value } : {}),
    })
    toast.success(res.message ?? 'Done')
    emit('done', res.data)
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
  <AppDialog :open="open && !!agent" :title="`Take action on ${first}?`" subtitle="Agent integrity: the agent is told your reason" :tone="tone" icon="warning" :busy="sending" size="md" @close="close">
    <form v-if="agent" @submit.prevent="submit">
      <div class="flex items-center gap-3 rounded-2xl p-3 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.05] dark:ring-white/10">
        <AppAvatar :name="agent.name" :src="agent.avatar" size="md" />
        <div class="min-w-0">
          <p class="truncate text-sm font-semibold text-brand-navy dark:text-white">{{ agent.name }}</p>
          <p class="truncate text-xs text-brand-navy/55 dark:text-white/55">{{ agent.email }}<template v-if="suspendedUntil"> · suspended until {{ new Date(suspendedUntil).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) }}</template></p>
        </div>
      </div>

      <p class="mt-5 text-sm font-semibold text-brand-navy dark:text-white">Action</p>
      <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2" role="radiogroup" aria-label="Action">
        <button
          v-for="o in OPTIONS"
          :key="o.key"
          type="button"
          role="radio"
          :aria-checked="kind === o.key"
          class="rounded-2xl p-3 text-left ring-1 transition"
          :class="kind === o.key
            ? (o.key === 'revocation' ? 'ring-red-400/60 bg-red-500/[0.06]' : o.key === 'lift' ? 'ring-emerald-500/50 bg-emerald-500/[0.06]' : 'ring-amber-400/60 bg-amber-500/[0.06]')
            : 'ring-brand-navy/10 hover:ring-brand-gold dark:ring-white/10'"
          @click="kind = o.key"
        >
          <span class="block text-sm font-semibold text-brand-navy dark:text-white">{{ o.label }}</span>
          <span class="block text-xs text-brand-navy/60 dark:text-white/55">{{ o.hint }}</span>
        </button>
      </div>

      <div v-if="kind === 'suspension'" class="mt-4 flex flex-wrap items-center gap-1.5">
        <span class="mr-1 text-sm font-semibold text-brand-navy dark:text-white">For</span>
        <button v-for="d in [7, 14, 30] as const" :key="d" type="button" class="rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition"
                :class="days === d ? 'bg-amber-500/15 text-amber-800 ring-amber-400/50 dark:text-amber-300' : 'text-brand-navy/75 ring-brand-navy/10 hover:ring-amber-400/60 dark:text-white/70 dark:ring-white/15'"
                @click="days = d">{{ d }} days</button>
      </div>

      <template v-if="kind !== 'lift'">
        <p class="mt-5 text-sm font-semibold text-brand-navy dark:text-white">Common reasons <span class="font-normal text-brand-navy/50 dark:text-white/45">(tap to add)</span></p>
        <div class="mt-2 flex flex-wrap gap-1.5">
          <button v-for="p in PRESETS" :key="p" type="button" class="rounded-full px-3 py-1.5 text-left text-xs font-semibold ring-1 transition"
                  :class="reason.includes(p) ? 'bg-amber-500/10 text-amber-800 ring-amber-400/40 dark:text-amber-300' : 'text-brand-navy/75 ring-brand-navy/10 hover:ring-amber-400/60 dark:text-white/70 dark:ring-white/15'"
                  @click="addPreset(p)">{{ p }}</button>
        </div>
      </template>
      <label for="as-reason" class="mt-4 mb-1.5 block text-sm font-semibold text-brand-navy dark:text-white">Reason <span class="text-red-500">*</span></label>
      <textarea id="as-reason" v-model="reason" rows="3" maxlength="1000" :placeholder="kind === 'lift' ? 'Why are you lifting it early?' : 'What did the agent do? They will read this.'" :class="[DIALOG_FIELD, 'resize-none']" />

      <ul class="mt-3 space-y-1">
        <li v-for="c in consequences" :key="c" class="flex gap-2 text-xs text-brand-navy/70 dark:text-white/65">
          <span class="mt-1.5 h-1.5 w-1.5 flex-none rounded-full bg-brand-navy/30 dark:bg-white/30" aria-hidden="true" />{{ c }}
        </li>
      </ul>

      <p v-if="failed" class="mt-4 rounded-2xl bg-red-500/[0.08] px-4 py-3 text-sm text-red-700 ring-1 ring-red-500/20 dark:text-red-300" role="alert">{{ failed }}</p>
    </form>

    <template #footer>
      <button type="button" :class="DIALOG_BTN.cancel" :disabled="sending" @click="close">Cancel</button>
      <button type="button" :class="kind === 'revocation' ? DIALOG_BTN.danger : kind === 'lift' ? DIALOG_BTN.primary : DIALOG_BTN.warning" :disabled="!ready || sending" @click="submit">
        {{ sending ? 'Saving…' : confirmLabel }}
      </button>
    </template>
  </AppDialog>
</template>
