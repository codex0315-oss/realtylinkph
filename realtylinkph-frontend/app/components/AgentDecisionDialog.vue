<script setup lang="ts">
import type { AgentProfile } from '~/types'

/**
 * Approve or reject an agent application (admin Agents page).
 *
 * Approve asks for three quick confirmations first, so nobody becomes an agent
 * from one stray click. Reject needs a reason the applicant will read: preset
 * chips add common ones, and the admin can write their own.
 */
const props = defineProps<{ open: boolean; mode: 'approve' | 'reject'; application: AgentProfile | null }>()
const emit = defineEmits<{ close: []; done: [mode: 'approve' | 'reject' | 'hold'] }>()

const { approveAgent, rejectAgent } = useAgent()
const api = useApi()
const toast = useToast()

const sending = ref(false)
const failed = ref(false)
const checks = reactive({ prc: false, face: false, valid: false })
const reason = ref('')
const REASON_MIN = 10

/* "Check later": PRC's lookup is down, so neither approve nor reject yet. */
const holding = ref(false)
const holdNote = ref('')
const HOLD_DEFAULT = "PRC's verification site was down, so the license couldn't be checked yet."
async function hold() {
  if (!props.application || holdNote.value.trim().length < 5 || sending.value) return
  sending.value = true
  failed.value = false
  try {
    await api.post(`/admin/agent-applications/${props.application.id}/hold`, { note: holdNote.value.trim() })
    toast.success(`${first.value}'s application is on hold. It stays in Pending.`)
    emit('done', 'hold')
  } catch {
    failed.value = true
  } finally {
    sending.value = false
  }
}

watch(() => props.open, (o) => {
  if (!o) return
  Object.assign(checks, { prc: false, face: false, valid: false })
  reason.value = ''
  failed.value = false
  sending.value = false
  holding.value = false
  holdNote.value = HOLD_DEFAULT
})

const name = computed(() => props.application?.user?.name ?? 'The applicant')
const first = computed(() => name.value.split(' ')[0])
const isBroker = computed(() => props.application?.applicant_type === 'broker')
const docName = computed(() => (isBroker.value ? 'PRC license' : 'accreditation'))

const CHECKS = computed(() => [
  { key: 'prc' as const,   label: `I looked up the ${isBroker.value ? 'PRC number' : 'accreditation'} on the official site` },
  { key: 'face' as const,  label: `The selfie matches the photo on the ${docName.value}` },
  { key: 'valid' as const, label: `The ${docName.value} is still valid and readable` },
])
const PRESETS = computed(() => [
  'The document photo is too blurry to read.',
  `The name on the ${docName.value} doesn't match the account name.`,
  `The ${docName.value} has expired.`,
  `The selfie doesn't match the photo on the ${docName.value}.`,
  `This isn't a ${isBroker.value ? 'PRC broker license' : 'valid accreditation document'}.`,
  `The ${isBroker.value ? 'PRC number' : 'accreditation number'} couldn't be found on the official site.`,
])
function addPreset(p: string) {
  if (reason.value.includes(p)) return
  reason.value = reason.value.trim() ? `${reason.value.trim()} ${p}` : p
}

const ready = computed(() => props.mode === 'approve'
  ? checks.prc && checks.face && checks.valid
  : reason.value.trim().length >= REASON_MIN)

async function submit() {
  if (!props.application || !ready.value || sending.value) return
  sending.value = true
  failed.value = false
  const ok = props.mode === 'approve'
    ? await approveAgent(props.application.id)
    : await rejectAgent(props.application.id, reason.value.trim())
  sending.value = false
  if (!ok) { failed.value = true; return }
  toast.success(props.mode === 'approve' ? `${name.value} is now a verified agent` : `Application rejected. ${first.value} has been told why`)
  emit('done', props.mode)
}

function close() { if (!sending.value) emit('close') }
function onKey(e: KeyboardEvent) { if (e.key === 'Escape' && props.open) close() }
onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <Transition name="ad-backdrop">
      <div v-if="open && application" class="fixed inset-0 z-[95] flex items-end sm:items-center justify-center sm:p-4 bg-brand-navy-deep/60 backdrop-blur-sm" @click.self="close">
        <Transition name="ad-panel" appear>
          <form
            v-if="open"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ad-title"
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

            <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em]" :class="mode === 'approve' ? 'text-brand-gold-deep dark:text-brand-gold' : 'text-red-600 dark:text-red-400'">
              {{ mode === 'approve' ? 'Approve application' : 'Reject application' }}
            </p>
            <h2 id="ad-title" class="mt-1 pr-10 font-heading text-2xl font-semibold tracking-[-0.01em] text-brand-navy dark:text-white">
              {{ mode === 'approve' ? `Make ${first} a verified agent?` : `Why isn't ${first} approved?` }}
            </h2>

            <!-- Who -->
            <div class="mt-5 flex items-center gap-3 rounded-2xl p-3 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.05] dark:ring-white/10">
              <AppAvatar :name="application.user?.name" :src="application.user?.avatar" size="md" />
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-brand-navy dark:text-white">{{ name }}</p>
                <p class="truncate text-xs text-brand-navy/55 dark:text-white/55">
                  {{ isBroker ? 'Broker' : 'Salesperson' }} · {{ isBroker ? 'PRC' : 'Accreditation' }} no. {{ application.prc_number }}
                </p>
              </div>
            </div>

            <!-- Approve: three confirmations -->
            <template v-if="mode === 'approve'">
              <p class="mt-5 text-sm font-semibold text-brand-navy dark:text-white">Before you approve, confirm:</p>
              <ul class="mt-2 space-y-2">
                <li v-for="c in CHECKS" :key="c.key">
                  <label class="flex items-start gap-3 rounded-xl p-3 ring-1 cursor-pointer transition"
                         :class="checks[c.key] ? 'ring-emerald-500/40 bg-emerald-500/[0.06]' : 'ring-brand-navy/10 hover:ring-brand-gold dark:ring-white/10'">
                    <input v-model="checks[c.key]" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-brand-navy/30 text-emerald-600 focus:ring-brand-gold" />
                    <span class="text-sm text-brand-navy/85 dark:text-white/80">{{ c.label }}</span>
                  </label>
                </li>
              </ul>
              <p class="mt-3 text-xs text-brand-navy/55 dark:text-white/50">
                {{ first }} can post listings right away and gets an email. The RealtyLink AI check is advice only; this decision is yours.
              </p>

              <!-- Tip: the new PRC ID's QR code -->
              <p class="mt-3 flex gap-2 rounded-xl bg-brand-gold/[0.08] px-3 py-2 text-xs text-brand-navy/75 dark:text-white/70">
                <svg class="mt-0.5 h-4 w-4 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5zM13.5 13.5h.75v.75h-.75v-.75zM13.5 19.5h.75v.75h-.75v-.75zM19.5 13.5h.75v.75h-.75v-.75zM19.5 19.5h.75v.75h-.75v-.75zM16.5 16.5h.75v.75h-.75v-.75z" /></svg>
                <span>New PRC IDs carry a QR code. If the card photo shows it clearly, the PRC verification site can read it, a quick way to confirm the card is genuine.</span>
              </p>

              <!-- PRC's site down: hold instead of guessing -->
              <div class="mt-3 rounded-xl ring-1 ring-amber-400/30 bg-amber-500/[0.06] px-3 py-2.5">
                <div class="flex items-start justify-between gap-3">
                  <p class="text-xs text-brand-navy/75 dark:text-white/70">
                    <span class="font-semibold text-amber-800 dark:text-amber-300">PRC's site down?</span>
                    Try the backup link under "Verify against". If neither works, don't guess: put it on hold and check later.
                  </p>
                  <button v-if="!holding" type="button" class="flex-none rounded-full px-3 py-1 text-xs font-semibold text-amber-800 ring-1 ring-amber-400/50 hover:bg-amber-500/10 dark:text-amber-300" @click="holding = true">Check later</button>
                </div>
                <div v-if="holding" class="mt-2">
                  <label for="ad-hold" class="sr-only">Note for other admins</label>
                  <textarea
                    id="ad-hold"
                    v-model="holdNote"
                    rows="2"
                    maxlength="500"
                    class="w-full resize-none rounded-lg border-0 bg-white dark:bg-white/[0.06] px-3 py-2 text-xs text-brand-navy dark:text-white ring-1 ring-amber-400/40 focus:ring-2 focus:ring-brand-gold"
                  />
                  <div class="mt-1.5 flex items-center justify-between gap-2">
                    <p class="text-[0.6875rem] text-brand-navy/50 dark:text-white/45">Only admins see this. It stays in Pending.</p>
                    <button type="button" class="rounded-full bg-amber-500 px-3 py-1 text-xs font-bold text-white hover:bg-amber-600 disabled:opacity-50" :disabled="holdNote.trim().length < 5 || sending" @click="hold">Put on hold</button>
                  </div>
                </div>
              </div>
            </template>

            <!-- Reject: reason -->
            <template v-else>
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
              <label for="ad-reason" class="mt-4 mb-1.5 block text-sm font-semibold text-brand-navy dark:text-white">Reason <span class="text-red-500">*</span></label>
              <textarea
                id="ad-reason"
                v-model="reason"
                rows="4"
                maxlength="1000"
                placeholder="Tell them what to fix so their next application goes through."
                class="w-full resize-none rounded-xl border-0 bg-brand-navy/[0.04] dark:bg-white/[0.06] px-4 py-3 text-sm text-brand-navy dark:text-white placeholder-brand-navy/35 dark:placeholder-white/35 ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold"
              />
              <div class="mt-1 flex items-start justify-between gap-3">
                <p class="text-xs text-brand-navy/55 dark:text-white/50">{{ first }} sees this by email and in the app, and can apply again after 12 hours.</p>
                <span class="flex-none text-xs tabular-nums" :class="reason.trim().length >= REASON_MIN ? 'text-emerald-600 dark:text-emerald-400' : 'text-brand-navy/40 dark:text-white/35'">{{ reason.trim().length }}/1000</span>
              </div>
            </template>

            <p v-if="failed" class="mt-4 rounded-xl bg-red-500/10 px-4 py-3 text-sm text-red-600 dark:text-red-400" role="alert">That didn't go through. Please try again.</p>

            <div class="mt-6 flex flex-col-reverse sm:flex-row gap-3">
              <button type="button" class="flex-1 rounded-full px-5 py-3 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold transition" :disabled="sending" @click="close">Cancel</button>
              <button
                type="submit"
                class="flex-1 inline-flex items-center justify-center gap-2 rounded-full px-5 py-3 text-sm font-bold transition disabled:opacity-50 disabled:shadow-none"
                :class="mode === 'approve'
                  ? 'bg-brand-gold text-[#0B2A5C] shadow-[0_4px_14px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light'
                  : 'bg-red-600 text-white shadow-[0_4px_14px_rgba(220,38,38,0.35)] hover:bg-red-700'"
                :disabled="!ready || sending"
              >
                <svg v-if="sending" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" /><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                {{ sending ? (mode === 'approve' ? 'Approving…' : 'Rejecting…') : (mode === 'approve' ? 'Approve agent' : 'Reject and notify') }}
              </button>
            </div>
          </form>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.ad-backdrop-enter-active, .ad-backdrop-leave-active { transition: opacity 0.22s ease; }
.ad-backdrop-enter-from, .ad-backdrop-leave-to { opacity: 0; }
.ad-panel-enter-active { transition: opacity 0.25s ease, transform 0.35s cubic-bezier(0.22, 1, 0.36, 1); }
.ad-panel-enter-from { opacity: 0; transform: translateY(24px) scale(0.98); }
@media (prefers-reduced-motion: reduce) { .ad-panel-enter-active { transition: none; } }
</style>
