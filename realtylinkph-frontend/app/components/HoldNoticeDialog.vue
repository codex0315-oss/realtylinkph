<script setup lang="ts">
import type { AgentProfile, HoldReason } from '~/types'

/**
 * Put agent applications on hold — or resume their review — with a notice to
 * each applicant (in-app + email). Works for one application or many: pass the
 * picked ones in `applications`, or `all` with how many that covers.
 *
 * A hold is a pause, not a decision: the application stays in Pending and can
 * still be approved or rejected. The message is pre-written and editable.
 */
const props = defineProps<{
  open: boolean
  mode: 'hold' | 'resume'
  applications: AgentProfile[]
  /** Every pending (hold) / every held (resume) application, instead of `applications`. */
  all?: boolean
  allCount?: number
}>()
const emit = defineEmits<{ close: []; done: [] }>()

const api = useApi()
const toast = useToast()

const REASONS: Array<{ key: HoldReason; label: string; message: string }> = [
  { key: 'prc_unavailable', label: 'PRC website unavailable', message: "PRC's license verification website is currently unavailable, so we can't confirm licenses right now. Your application is safe and still in line, and there's nothing you need to do. We'll continue your review as soon as PRC is back online." },
  { key: 'documents', label: 'Need a closer look at documents', message: "We need a closer look at your documents before we can continue your review. Your application is safe and still in line. We'll contact you if we need anything from you." },
  { key: 'other', label: 'Other', message: "Your application review is paused for a short while. Your application is safe and still in line, and there's nothing you need to do." },
]
const RESUME_MESSAGE = "Good news: we're reviewing your agent application again. We'll let you know as soon as a decision is made."
const MIN = 10

const reason = ref<HoldReason>('prc_unavailable')
const message = ref('')
const note = ref('')
const sending = ref(false)
const failed = ref<string | null>(null)

watch(() => props.open, (o) => {
  if (!o) return
  reason.value = 'prc_unavailable'
  message.value = props.mode === 'hold' ? REASONS[0]!.message : RESUME_MESSAGE
  note.value = ''
  failed.value = null
  sending.value = false
})

/* Picking a reason swaps in its message — unless the admin has written their own. */
function pickReason(key: HoldReason) {
  const wasPreset = REASONS.some(r => r.message === message.value.trim())
  reason.value = key
  if (wasPreset || !message.value.trim()) message.value = REASONS.find(r => r.key === key)!.message
}

const count = computed(() => (props.all ? props.allCount ?? 0 : props.applications.length))
const single = computed(() => (!props.all && props.applications.length === 1 ? props.applications[0]! : null))
const who = computed(() => single.value?.user?.name?.split(' ')[0] ?? null)
const plural = (n: number) => `${n} applicant${n === 1 ? '' : 's'}`

const title = computed(() => {
  if (props.mode === 'hold') return who.value ? `Put ${who.value}'s application on hold?` : `Put ${plural(count.value)} on hold?`
  return who.value ? `Resume ${who.value}'s review?` : `Resume review for ${plural(count.value)}?`
})
const ready = computed(() => message.value.trim().length >= MIN && count.value > 0)

async function submit() {
  if (!ready.value || sending.value) return
  sending.value = true
  failed.value = null
  const body: Record<string, unknown> = { message: message.value.trim() }
  if (props.mode === 'hold') { body.reason = reason.value; if (note.value.trim()) body.note = note.value.trim() }
  try {
    if (single.value) {
      if (props.mode === 'hold') await api.post(`/admin/agent-applications/${single.value.id}/hold`, body)
      else await api.del(`/admin/agent-applications/${single.value.id}/hold`, body)
      toast.success(props.mode === 'hold' ? `On hold. ${who.value} has been told by email and in the app.` : `Review resumed. ${who.value} has been told.`)
    } else {
      const res = await api.post<{ data: { held?: number; resumed?: number; skipped: number } }>(
        `/admin/agent-applications/${props.mode === 'hold' ? 'hold' : 'resume'}`,
        props.all ? { ...body, all: true } : { ...body, ids: props.applications.map(a => a.id) },
      )
      const done = res.data.held ?? res.data.resumed ?? 0
      const skipped = res.data.skipped ? ` ${res.data.skipped} skipped (${props.mode === 'hold' ? 'already on hold' : 'not on hold'}).` : ''
      toast.success(`${props.mode === 'hold' ? 'On hold' : 'Review resumed'}: ${plural(done)} notified.${skipped}`)
    }
    emit('done')
  } catch (e: unknown) {
    failed.value = (e as { data?: { message?: string } })?.data?.message ?? "That didn't go through. Please try again."
  } finally {
    sending.value = false
  }
}

function close() { if (!sending.value) emit('close') }
</script>

<template>
  <AppDialog
    :open="open && count > 0"
    :title="title"
    :subtitle="mode === 'hold' ? 'They stay in Pending. You can still approve or reject.' : 'They’ll get a “good news” notice.'"
    :tone="mode === 'hold' ? 'warning' : 'primary'"
    :icon="mode === 'hold' ? 'bell' : 'check'"
    :busy="sending"
    size="md"
    @close="close"
  >
    <form @submit.prevent="submit">
      <!-- Who -->
      <div v-if="single" class="flex items-center gap-3 rounded-2xl p-3 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.05] dark:ring-white/10">
        <AppAvatar :name="single.user?.name" :src="single.user?.avatar" size="md" />
        <div class="min-w-0">
          <p class="truncate text-sm font-semibold text-brand-navy dark:text-white">{{ single.user?.name }}</p>
          <p class="truncate text-xs text-brand-navy/55 dark:text-white/55">{{ single.user?.email }}</p>
        </div>
      </div>
      <div v-else class="flex items-center gap-3 rounded-2xl p-3 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.05] dark:ring-white/10">
        <div class="flex -space-x-2">
          <AppAvatar v-for="a in applications.slice(0, 4)" :key="a.id" :name="a.user?.name" :src="a.user?.avatar" size="sm" class="ring-2 ring-white dark:ring-[#0B1A35]" />
        </div>
        <p class="min-w-0 text-sm text-brand-navy/70 dark:text-white/65">
          <span class="font-semibold text-brand-navy dark:text-white">{{ plural(count) }}</span> will be notified
        </p>
      </div>

      <!-- Reason (hold only) -->
      <template v-if="mode === 'hold'">
        <p class="mt-5 text-sm font-semibold text-brand-navy dark:text-white">Reason</p>
        <div class="mt-2 flex flex-wrap gap-1.5" role="radiogroup" aria-label="Reason">
          <button
            v-for="r in REASONS"
            :key="r.key"
            type="button"
            role="radio"
            :aria-checked="reason === r.key"
            class="rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition"
            :class="reason === r.key
              ? 'bg-amber-500/15 text-amber-800 ring-amber-400/50 dark:text-amber-300'
              : 'text-brand-navy/75 ring-brand-navy/10 hover:ring-amber-400/60 dark:text-white/70 dark:ring-white/15'"
            @click="pickReason(r.key)"
          >{{ r.label }}</button>
        </div>
      </template>

      <!-- Message -->
      <label for="hn-message" class="mt-5 mb-1.5 block text-sm font-semibold text-brand-navy dark:text-white">
        Message to {{ who ?? 'applicants' }} <span class="text-red-500">*</span>
      </label>
      <textarea id="hn-message" v-model="message" rows="5" maxlength="1000" :class="[DIALOG_FIELD, 'resize-none']" />
      <div class="mt-1 flex items-start justify-between gap-3">
        <p class="flex items-center gap-1.5 text-xs text-brand-navy/55 dark:text-white/50">
          <svg class="h-3.5 w-3.5 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
          Sent by email and as an in-app notification.
        </p>
        <span class="flex-none text-xs tabular-nums" :class="message.trim().length >= MIN ? 'text-emerald-600 dark:text-emerald-400' : 'text-brand-navy/40 dark:text-white/35'">{{ message.trim().length }}/1000</span>
      </div>

      <!-- Admins' own note (hold only, optional) -->
      <template v-if="mode === 'hold'">
        <label for="hn-note" class="mt-4 mb-1.5 block text-sm font-semibold text-brand-navy dark:text-white">
          Note for other admins <span class="font-normal text-brand-navy/50 dark:text-white/45">(optional, applicants don't see it)</span>
        </label>
        <input id="hn-note" v-model="note" type="text" maxlength="500" placeholder="e.g. Tried both PRC sites at 2 PM, both down" :class="DIALOG_FIELD" />
      </template>

      <p v-if="failed" class="mt-4 rounded-2xl bg-red-500/[0.08] px-4 py-3 text-sm text-red-700 ring-1 ring-red-500/20 dark:text-red-300" role="alert">{{ failed }}</p>
    </form>

    <template #footer>
      <button type="button" :class="DIALOG_BTN.cancel" :disabled="sending" @click="close">Cancel</button>
      <button type="button" :class="mode === 'hold' ? DIALOG_BTN.warning : DIALOG_BTN.primary" :disabled="!ready || sending" @click="submit">
        <svg v-if="sending" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" /><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
        <template v-if="sending">Sending…</template>
        <template v-else>{{ mode === 'hold' ? (count > 1 ? `Hold and notify ${count}` : 'Hold and notify') : (count > 1 ? `Resume and notify ${count}` : 'Resume and notify') }}</template>
      </button>
    </template>
  </AppDialog>
</template>
