<script setup lang="ts">
import type { User } from '~/types'

/**
 * Create another administrator: form → creating → done.
 *
 * The browser must never fill this form with the signed-in admin's own saved
 * login (it looks like a sign-in form to a password manager), so the fields
 * use non-login names, `new-password`, and stay read-only until focused.
 *
 * The done screen offers the new admin's sign-in details to copy for the
 * handover. The password is held only while this dialog is open.
 */
const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: []; created: [user: User] }>()

const { createAdmin, error } = useAdmin()
const toast = useToast()

type Step = 'form' | 'sending' | 'done'
const step = ref<Step>('form')
const form = reactive({ name: '', email: '', password: '', password_confirmation: '' })
const showPw = ref(false)
const unlocked = reactive({ name: false, email: false, password: false, confirm: false })
const fieldError = ref<string | null>(null)
const created = ref<User | null>(null)

watch(() => props.open, (o) => {
  if (!o) return
  step.value = 'form'
  Object.assign(form, { name: '', email: '', password: '', password_confirmation: '' })
  Object.assign(unlocked, { name: false, email: false, password: false, confirm: false })
  showPw.value = false
  fieldError.value = null
  created.value = null
})

const checks = computed(() => [
  { ok: form.password.length >= 8, label: '8+ characters' },
  { ok: /[a-z]/i.test(form.password) && /\d/.test(form.password), label: 'Letters and numbers' },
  { ok: !!form.password && form.password === form.password_confirmation, label: 'Both match' },
])
const emailOk = computed(() => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim()))
const ready = computed(() => form.name.trim().length > 1 && emailOk.value && checks.value.every(c => c.ok))

/** 14 characters from an unambiguous set, always with a letter and a digit. */
function generate() {
  const letters = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ'
  const digits = '23456789'
  const all = letters + digits + '!@#$%&*'
  const rnd = (n: number) => { const a = new Uint32Array(1); crypto.getRandomValues(a); return a[0]! % n }
  let pw = ''
  do {
    pw = Array.from({ length: 14 }, () => all[rnd(all.length)]).join('')
  } while (!/[a-z]/i.test(pw) || !/\d/.test(pw))
  form.password = pw
  form.password_confirmation = pw
  unlocked.password = unlocked.confirm = true
  showPw.value = true
}

async function submit() {
  if (!ready.value || step.value === 'sending') return
  step.value = 'sending'
  fieldError.value = null
  const user = await createAdmin({ name: form.name.trim(), email: form.email.trim(), password: form.password, password_confirmation: form.password_confirmation })
  if (user) {
    created.value = user
    step.value = 'done'
    emit('created', user)
  } else {
    fieldError.value = error.value || 'Could not create the admin.'
    step.value = 'form'
  }
}

async function copyDetails() {
  const text = `RealtyLink PH admin account\nSign in: ${window.location.origin}/?auth=login\nEmail: ${form.email.trim()}\nPassword: ${form.password}\nPlease change your password after you sign in.`
  try {
    await navigator.clipboard.writeText(text)
    toast.success('Sign-in details copied')
  } catch {
    toast.error('Couldn’t copy. Select and copy the details instead.')
  }
}

function close() {
  if (step.value === 'sending') return
  emit('close')
}
function onKey(e: KeyboardEvent) { if (e.key === 'Escape' && props.open) close() }
onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))

const POWERS = [
  'Approve or reject agent applications',
  'Unpublish listings and hide reviews',
  'Delete accounts and create other admins',
]
const field = 'w-full rounded-xl border-0 bg-brand-navy/[0.04] dark:bg-white/[0.06] px-4 py-3 text-sm text-brand-navy dark:text-white placeholder-brand-navy/35 dark:placeholder-white/35 ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold transition'
const label = 'mb-1.5 block text-sm font-semibold text-brand-navy dark:text-white'
</script>

<template>
  <Teleport to="body">
    <Transition name="ca-backdrop">
      <div v-if="open" class="fixed inset-0 z-[95] flex items-end sm:items-center justify-center sm:p-4 bg-brand-navy-deep/60 backdrop-blur-sm" @click.self="close">
        <Transition name="ca-panel" appear>
          <div
            v-if="open"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ca-title"
            class="relative w-full sm:max-w-lg max-h-[92vh] overflow-y-auto rounded-t-[1.75rem] sm:rounded-[1.75rem]
                   bg-white dark:bg-[#0d2550] ring-1 ring-brand-navy/[0.06] dark:ring-white/10 shadow-[0_40px_100px_-30px_rgba(6,24,58,0.6)]"
          >
            <button
              v-if="step !== 'sending'"
              type="button"
              class="absolute top-4 right-4 z-10 h-9 w-9 rounded-full flex items-center justify-center text-brand-navy/60 hover:text-brand-navy hover:bg-brand-navy/5 dark:text-white/60 dark:hover:text-white dark:hover:bg-white/10 transition"
              aria-label="Close"
              @click="close"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>

            <Transition name="ca-step" mode="out-in">
              <!-- ░░ Form ░░ -->
              <form v-if="step !== 'done'" key="form" class="p-6 sm:p-7" autocomplete="off" novalidate @submit.prevent="submit">
                <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold-deep dark:text-brand-gold">New administrator</p>
                <h2 id="ca-title" class="mt-1 pr-10 font-heading text-2xl font-semibold tracking-[-0.01em] text-brand-navy dark:text-white">Create an admin account</h2>
                <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/60">Useful for sharing the work, or handing over before you step down.</p>

                <!-- What they'll be able to do -->
                <div class="mt-5 rounded-2xl p-4 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.05] dark:ring-white/10">
                  <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.12em] text-brand-navy/60 dark:text-white/55">
                    <svg class="h-4 w-4 text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>
                    Full admin access
                  </p>
                  <ul class="mt-2 space-y-1">
                    <li v-for="p in POWERS" :key="p" class="flex items-start gap-2 text-sm text-brand-navy/80 dark:text-white/75">
                      <span class="mt-2 h-1 w-1 flex-none rounded-full bg-brand-gold" aria-hidden="true" />{{ p }}
                    </li>
                  </ul>
                  <p class="mt-2 text-xs text-brand-navy/55 dark:text-white/50">Everything they do is recorded in Activity.</p>
                </div>

                <div class="mt-5 space-y-4">
                  <div>
                    <label for="ca-name" :class="label">Full name <span class="text-red-500">*</span></label>
                    <input
                      id="ca-name" v-model="form.name" name="new-admin-full-name" type="text" :class="field"
                      placeholder="e.g. Maria Santos" autocomplete="off" :readonly="!unlocked.name" @focus="unlocked.name = true"
                    />
                  </div>
                  <div>
                    <label for="ca-email" :class="label">Email <span class="text-red-500">*</span></label>
                    <input
                      id="ca-email" v-model="form.email" name="new-admin-contact" type="email" inputmode="email" :class="field"
                      placeholder="their.work@email.com" autocomplete="off" :readonly="!unlocked.email" @focus="unlocked.email = true"
                    />
                    <p v-if="form.email && !emailOk" class="mt-1 text-xs text-red-600 dark:text-red-400">Enter a valid email address.</p>
                  </div>

                  <div>
                    <div class="mb-1.5 flex items-center justify-between gap-2">
                      <label for="ca-pw" class="text-sm font-semibold text-brand-navy dark:text-white">Password <span class="text-red-500">*</span></label>
                      <button type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-gold-deep hover:text-brand-navy dark:text-brand-gold dark:hover:text-white transition" @click="generate">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
                        Generate strong password
                      </button>
                    </div>
                    <div class="relative">
                      <input
                        id="ca-pw" v-model="form.password" name="new-admin-secret" :type="showPw ? 'text' : 'password'" :class="[field, 'pr-12 font-mono tracking-wide']"
                        placeholder="At least 8 characters" autocomplete="new-password" :readonly="!unlocked.password" @focus="unlocked.password = true"
                      />
                      <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 h-8 w-8 rounded-full flex items-center justify-center text-brand-navy/50 hover:text-brand-navy dark:text-white/50 dark:hover:text-white" :aria-label="showPw ? 'Hide password' : 'Show password'" @click="showPw = !showPw">
                        <svg v-if="showPw" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                        <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                      </button>
                    </div>
                  </div>
                  <div>
                    <label for="ca-pw2" :class="label">Confirm password <span class="text-red-500">*</span></label>
                    <input
                      id="ca-pw2" v-model="form.password_confirmation" name="new-admin-secret-again" :type="showPw ? 'text' : 'password'" :class="[field, 'font-mono tracking-wide']"
                      placeholder="Type it again" autocomplete="new-password" :readonly="!unlocked.confirm" @focus="unlocked.confirm = true"
                    />
                    <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                      <li v-for="c in checks" :key="c.label" class="flex items-center gap-1 text-xs font-medium" :class="c.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-brand-navy/45 dark:text-white/40'">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="c.ok ? 'M4.5 12.75l6 6 9-13.5' : 'M12 12h.01'" /></svg>
                        {{ c.label }}
                      </li>
                    </ul>
                  </div>
                </div>

                <p v-if="fieldError" class="mt-4 rounded-xl bg-red-500/10 px-4 py-3 text-sm text-red-600 dark:text-red-400" role="alert">{{ fieldError }}</p>

                <div class="mt-6 flex flex-col-reverse sm:flex-row gap-3">
                  <button type="button" class="flex-1 rounded-full px-5 py-3 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold transition" :disabled="step === 'sending'" @click="close">Cancel</button>
                  <button
                    type="submit"
                    class="flex-1 inline-flex items-center justify-center gap-2 rounded-full bg-brand-gold px-5 py-3 text-sm font-bold text-[#0B2A5C] shadow-[0_4px_14px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light disabled:opacity-50 disabled:shadow-none transition"
                    :disabled="!ready || step === 'sending'"
                  >
                    <svg v-if="step === 'sending'" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" /><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                    {{ step === 'sending' ? 'Creating…' : 'Create admin' }}
                  </button>
                </div>
              </form>

              <!-- ░░ Done ░░ -->
              <div v-else key="done" class="p-6 sm:p-7 text-center" role="status" aria-live="polite">
                <div class="relative mx-auto mt-2 h-20 w-20">
                  <span class="absolute inset-0 rounded-full bg-emerald-500/15 ca-pop" aria-hidden="true" />
                  <svg class="absolute inset-0 h-20 w-20 text-emerald-500" viewBox="0 0 80 80" fill="none" aria-hidden="true">
                    <circle cx="40" cy="40" r="30" stroke="currentColor" stroke-width="3" class="ca-ring" />
                    <path d="M27 41l9 9 18-19" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" class="ca-check" />
                  </svg>
                </div>
                <h2 id="ca-title" class="mt-5 font-heading text-2xl font-semibold text-brand-navy dark:text-white">Admin account created</h2>
                <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/60">{{ created?.name }} can sign in now. Share these details privately.</p>

                <dl class="mt-5 space-y-2.5 rounded-2xl bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.05] dark:ring-white/10 p-4 text-left text-sm">
                  <div class="flex justify-between gap-3">
                    <dt class="text-brand-navy/55 dark:text-white/50">Email</dt>
                    <dd class="font-semibold text-brand-navy dark:text-white break-all text-right">{{ created?.email }}</dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="text-brand-navy/55 dark:text-white/50">Password</dt>
                    <dd class="font-mono font-semibold text-brand-navy dark:text-white break-all text-right">{{ showPw ? form.password : '•'.repeat(Math.min(form.password.length, 14)) }}</dd>
                  </div>
                </dl>
                <p class="mt-2 text-xs text-brand-navy/50 dark:text-white/45">Ask them to change the password after their first sign-in.</p>

                <div class="mt-6 flex flex-col-reverse sm:flex-row gap-3">
                  <button type="button" class="flex-1 rounded-full px-5 py-3 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold transition" @click="close">Done</button>
                  <button type="button" class="flex-1 inline-flex items-center justify-center gap-2 rounded-full bg-brand-gold px-5 py-3 text-sm font-bold text-[#0B2A5C] shadow-[0_4px_14px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light transition" @click="copyDetails">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" /></svg>
                    Copy sign-in details
                  </button>
                </div>
              </div>
            </Transition>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.ca-backdrop-enter-active, .ca-backdrop-leave-active { transition: opacity 0.22s ease; }
.ca-backdrop-enter-from, .ca-backdrop-leave-to { opacity: 0; }
.ca-panel-enter-active { transition: opacity 0.25s ease, transform 0.35s cubic-bezier(0.22, 1, 0.36, 1); }
.ca-panel-enter-from { opacity: 0; transform: translateY(24px) scale(0.98); }
.ca-step-enter-active, .ca-step-leave-active { transition: opacity 0.2s ease, transform 0.25s cubic-bezier(0.22, 1, 0.36, 1); }
.ca-step-enter-from { opacity: 0; transform: translateY(10px); }
.ca-step-leave-to   { opacity: 0; transform: translateY(-6px); }
.ca-ring  { stroke-dasharray: 189; stroke-dashoffset: 189; animation: ca-draw 0.5s ease-out forwards; }
.ca-check { stroke-dasharray: 44;  stroke-dashoffset: 44;  animation: ca-draw 0.35s 0.4s ease-out forwards; }
@keyframes ca-draw { to { stroke-dashoffset: 0; } }
.ca-pop { animation: ca-pop 0.6s 0.55s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes ca-pop { from { transform: scale(0.6); opacity: 0; } to { transform: scale(1); opacity: 1; } }
@media (prefers-reduced-motion: reduce) {
  .ca-ring, .ca-check { animation: none; stroke-dashoffset: 0; }
  .ca-panel-enter-active, .ca-step-enter-active, .ca-step-leave-active { transition: none; }
}
</style>
