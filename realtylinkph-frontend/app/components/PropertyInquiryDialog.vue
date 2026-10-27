<script setup lang="ts">
import type { Property } from '~/types'

/**
 * Send-an-inquiry, as one dialog that walks through its own states:
 *
 *   form → sending → sent        (or → error → back to the form, text intact)
 *
 * The form says who the message goes to (listing + agent), validates as you
 * go instead of only on submit, and offers one-tap starter messages. Sending
 * swaps the dialog to a short "sending" state, then a confirmation that
 * repeats what was sent, to whom, and what happens next — so nobody is left
 * wondering whether the button worked.
 *
 * A guest's name / email / phone are remembered in this browser (and only
 * here) so a second inquiry doesn't mean typing them again.
 */
const props = defineProps<{ open: boolean; property: Property }>()
const emit = defineEmits<{ close: [] }>()

const authStore = useAuthStore()
const { open: openAuth } = useAuthModal()
const { submitInquiry, error: apiError } = useInquiry()

type Step = 'form' | 'sending' | 'sent' | 'error'
const step = ref<Step>('form')

const MAX = 2000
const form = reactive({ name: '', email: '', phone: '', message: '' })
const touched = reactive({ name: false, email: false, message: false })
const guest = computed(() => !authStore.isAuthenticated)

const QUICK = [
  { label: 'Is it available?',   text: "Hi! Is this property still available? I'd like to know more." },
  { label: 'Financing options',  text: 'Hi! Could you share the financing or payment options for this property?' },
  { label: 'Schedule a viewing', text: "Hi! I'd like to see this property in person. What times work for you?" },
  { label: 'Price negotiable?',  text: 'Hi! Is the price negotiable? I am seriously considering this property.' },
]

/* ── Remembered guest details ────────────────────────────────────────── */
const STORE_KEY = 'rl-inquiry-contact'
function loadContact() {
  try {
    const saved = JSON.parse(localStorage.getItem(STORE_KEY) ?? 'null') as Partial<typeof form> | null
    if (saved) { form.name ||= saved.name ?? ''; form.email ||= saved.email ?? ''; form.phone ||= saved.phone ?? '' }
  } catch { /* storage blocked — just start empty */ }
}
function saveContact() {
  try { localStorage.setItem(STORE_KEY, JSON.stringify({ name: form.name, email: form.email, phone: form.phone })) } catch { /* ignore */ }
}

/* ── Validation (shown once a field has been left, or on submit) ────── */
const errors = computed(() => ({
  name:    guest.value && !form.name.trim() ? 'Please enter your name.' : '',
  email:   !guest.value ? '' : !form.email.trim() ? 'We need an email so the agent can reply.'
         : !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim()) ? 'That email doesn’t look right.' : '',
  message: form.message.trim().length < 10 ? 'Write a short message (at least 10 characters).' : '',
}))
const valid = computed(() => !errors.value.name && !errors.value.email && !errors.value.message)
const showErr = (k: keyof typeof touched) => touched[k] && errors.value[k]

/* ── Open / close ────────────────────────────────────────────────────── */
watch(() => props.open, (on) => {
  if (!import.meta.client) return
  document.documentElement.style.overflow = on ? 'hidden' : ''
  if (!on) return
  // A finished inquiry starts fresh next time; a failed one keeps its text.
  if (step.value === 'sent') { form.message = ''; Object.assign(touched, { name: false, email: false, message: false }) }
  step.value = 'form'
  if (guest.value) loadContact()
  // First empty field: name for a new guest, the message once details are remembered.
  nextTick(() => {
    const target = guest.value && !form.name ? 'inq-name' : guest.value && !form.email ? 'inq-email' : 'inq-message'
    document.getElementById(target)?.focus()
  })
})
onUnmounted(() => { if (import.meta.client) document.documentElement.style.overflow = '' })

function close() {
  if (step.value === 'sending') return   // don't drop a request mid-flight
  emit('close')
}
function onKey(e: KeyboardEvent) { if (e.key === 'Escape' && props.open) close() }
onMounted(() => document.addEventListener('keydown', onKey))
onUnmounted(() => document.removeEventListener('keydown', onKey))

function pickQuick(text: string) {
  form.message = text
  touched.message = true
}

/* ── Send ────────────────────────────────────────────────────────────── */
const sentTo = ref('')
async function send() {
  Object.assign(touched, { name: true, email: true, message: true })
  if (!valid.value) return
  step.value = 'sending'
  const started = Date.now()
  const ok = await submitInquiry({
    property_id: props.property.id,
    name:    guest.value ? form.name.trim()  : undefined,
    email:   guest.value ? form.email.trim() : undefined,
    phone:   guest.value ? form.phone.trim() || undefined : undefined,
    message: form.message.trim(),
  })
  // Hold the sending state for a beat so it reads as a step, not a flicker.
  const wait = 700 - (Date.now() - started)
  if (wait > 0) await new Promise(r => setTimeout(r, wait))
  if (ok) {
    sentTo.value = guest.value ? form.email.trim() : (authStore.user?.email ?? '')
    if (guest.value) saveContact()
    step.value = 'sent'
  } else {
    step.value = 'error'
  }
}

/* ── Display helpers ─────────────────────────────────────────────────── */
const photo = computed(() => props.property.photos?.find(p => !p.is_360) ?? props.property.photos?.[0])
const price = computed(() => {
  const n = Number(props.property.price)
  const s = `₱${n.toLocaleString('en-PH')}`
  return props.property.offer_type === 'rent' ? `${s}/mo` : s
})
const agentName = computed(() => props.property.agent?.name ?? 'the agent')
const verified = computed(() => props.property.agent?.agent_profile?.status === 'approved')

const field =
  'w-full rounded-2xl border-0 px-4 py-3 text-[0.9375rem] text-brand-navy dark:text-white ' +
  'bg-brand-navy/[0.04] dark:bg-white/[0.06] ring-1 ring-inset placeholder-brand-navy/40 dark:placeholder-white/35 ' +
  'focus:bg-white dark:focus:bg-white/[0.09] focus:ring-2 focus:ring-brand-gold dark:focus:ring-brand-gold ' +
  'focus:shadow-[0_0_0_4px_rgba(212,165,74,0.15)] transition'
const ringFor = (k: keyof typeof touched) => (showErr(k) ? 'ring-red-400 dark:ring-red-400/70' : 'ring-brand-navy/10 dark:ring-white/10')
const label = 'mb-1.5 flex items-center justify-between text-sm font-semibold text-brand-navy dark:text-white/90'
const star  = 'font-bold text-red-500'
</script>

<template>
  <Teleport to="body">
    <Transition name="inq-backdrop">
      <div
        v-if="open"
        class="fixed inset-0 z-[95] flex items-end sm:items-center justify-center sm:p-4 bg-brand-navy-deep/60 backdrop-blur-sm"
        @click.self="close"
      >
        <Transition name="inq-panel" appear>
          <div
            v-if="open"
            role="dialog"
            aria-modal="true"
            aria-labelledby="inq-title"
            class="relative w-full sm:max-w-lg max-h-[92vh] overflow-y-auto rounded-t-[1.75rem] sm:rounded-[1.75rem]
                   bg-white dark:bg-[#0d2550] ring-1 ring-brand-navy/[0.06] dark:ring-white/10
                   shadow-[0_40px_100px_-30px_rgba(6,24,58,0.6)] transition-[max-width] duration-300"
            :class="step === 'form' && 'lg:max-w-4xl'"
          >
            <!-- Close -->
            <button
              v-if="step !== 'sending'"
              type="button"
              class="absolute top-4 right-4 z-10 h-9 w-9 rounded-full flex items-center justify-center text-brand-navy/60 hover:text-brand-navy hover:bg-brand-navy/5 dark:text-white/60 dark:hover:text-white dark:hover:bg-white/10 transition"
              aria-label="Close"
              @click="close"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>

            <Transition name="inq-step" mode="out-in">
              <!-- ░░ Form ░░ -->
              <!-- Two columns from lg up (details | message + send) so the whole
                   form fits without scrolling; stacked below that. -->
              <form v-if="step === 'form'" key="form" class="p-6 sm:p-7 lg:p-8" novalidate @submit.prevent="send">
                <div class="lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)] lg:gap-8">
                <!-- Left: who it goes to + your details -->
                <div>
                <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold-deep dark:text-brand-gold">Send an inquiry</p>
                <h2 id="inq-title" class="mt-1 pr-10 lg:pr-0 font-heading text-2xl font-semibold tracking-[-0.01em] text-brand-navy dark:text-white">
                  Ask about this property
                </h2>
                <p class="mt-1 text-xs text-brand-text-secondary dark:text-white/50">
                  Fields marked <span class="font-bold text-red-500" aria-hidden="true">*</span> are required.
                </p>

                <!-- Who it goes to -->
                <div class="mt-5 flex items-center gap-3 rounded-2xl p-2.5 pr-4 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.05] dark:ring-white/10">
                  <img v-if="photo" :src="photo.thumb_url ?? photo.url" alt="" class="h-14 w-16 flex-none rounded-xl object-cover" />
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-brand-navy dark:text-white">{{ property.title }}</p>
                    <p class="text-xs font-semibold text-brand-gold-deep dark:text-brand-gold tabular-nums">{{ price }}</p>
                    <p class="mt-0.5 flex items-center gap-1 text-xs text-brand-text-secondary dark:text-white/55 truncate">
                      To {{ agentName }}
                      <AppVerifiedTick v-if="verified" size="xs" />
                    </p>
                  </div>
                </div>

                <div v-if="guest" class="mt-5 space-y-4">
                    <div>
                      <label for="inq-name" :class="label"><span>Your name <span :class="star" aria-hidden="true">*</span><span class="sr-only">(required)</span></span></label>
                      <input id="inq-name" v-model="form.name" autocomplete="name" placeholder="Juan Dela Cruz" aria-required="true"
                             :class="[field, ringFor('name')]" :aria-invalid="!!showErr('name')" @blur="touched.name = true" />
                      <p v-if="showErr('name')" class="mt-1.5 text-xs text-red-500">{{ errors.name }}</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-4">
                      <div>
                        <label for="inq-email" :class="label"><span>Email <span :class="star" aria-hidden="true">*</span><span class="sr-only">(required)</span></span></label>
                        <input id="inq-email" v-model="form.email" type="email" autocomplete="email" placeholder="you@example.com" aria-required="true"
                               :class="[field, ringFor('email')]" :aria-invalid="!!showErr('email')" @blur="touched.email = true" />
                        <p v-if="showErr('email')" class="mt-1.5 text-xs text-red-500">{{ errors.email }}</p>
                      </div>
                      <div>
                        <label for="inq-phone" :class="label">Phone <span class="text-xs font-medium text-brand-navy/40 dark:text-white/40">Optional</span></label>
                        <input id="inq-phone" v-model="form.phone" type="tel" autocomplete="tel" inputmode="tel" maxlength="20" placeholder="+63 9XX XXX XXXX"
                               :class="[field, 'ring-brand-navy/10 dark:ring-white/10']" />
                      </div>
                    </div>
                </div>
                </div>

                <!-- Right: message + send -->
                <div class="mt-5 lg:mt-0 lg:pt-10 flex flex-col">
                  <div class="flex flex-1 flex-col">
                    <label for="inq-message" :class="label">
                      <span>Message <span :class="star" aria-hidden="true">*</span><span class="sr-only">(required)</span></span>
                      <span class="text-xs font-medium tabular-nums" :class="form.message.length > MAX * 0.9 ? 'text-amber-600' : 'text-brand-navy/40 dark:text-white/40'">
                        {{ form.message.length }}/{{ MAX }}
                      </span>
                    </label>
                    <div class="mb-2 flex flex-wrap gap-1.5">
                      <button
                        v-for="q in QUICK"
                        :key="q.label"
                        type="button"
                        class="rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition-all active:scale-95"
                        :class="form.message === q.text
                          ? 'bg-brand-navy text-white ring-brand-navy dark:bg-brand-gold dark:text-brand-navy dark:ring-brand-gold'
                          : 'text-brand-navy/70 ring-brand-navy/15 hover:ring-brand-gold hover:text-brand-navy dark:text-white/70 dark:ring-white/15 dark:hover:text-white'"
                        @click="pickQuick(q.text)"
                      >{{ q.label }}</button>
                    </div>
                    <textarea
                      id="inq-message"
                      v-model="form.message"
                      rows="4"
                      :maxlength="MAX"
                      placeholder="Hi! I'm interested in this property…"
                      aria-required="true"
                      :class="[field, ringFor('message'), 'resize-none lg:flex-1 lg:min-h-[9rem]']"
                      :aria-invalid="!!showErr('message')"
                      @blur="touched.message = true"
                    />
                    <p v-if="showErr('message')" class="mt-1.5 text-xs text-red-500">{{ errors.message }}</p>
                  </div>

                <p class="mt-4 flex items-start gap-2 text-xs text-brand-text-secondary dark:text-white/50">
                  <svg class="mt-px h-4 w-4 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                  Only {{ agentName }} sees your details. We never share them or send you spam.
                </p>

                <div class="mt-5 flex flex-col-reverse sm:flex-row gap-2.5">
                  <button type="button" class="sm:flex-1 rounded-full px-5 py-3 text-sm font-semibold transition
                                               text-brand-navy/75 ring-1 ring-brand-navy/20 hover:text-brand-navy hover:bg-brand-navy/5
                                               dark:text-white/75 dark:ring-white/25 dark:hover:text-white dark:hover:bg-white/10
                                               hover:ring-2 hover:ring-brand-gold dark:hover:ring-brand-gold" @click="close">
                    Cancel
                  </button>
                  <button
                    type="submit"
                    class="group sm:flex-[1.4] inline-flex items-center justify-center gap-2 rounded-full bg-brand-gold px-5 py-3 text-sm font-bold text-brand-navy
                           shadow-[0_6px_18px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light hover:-translate-y-0.5 transition"
                  >
                    Send inquiry
                    <svg class="h-4 w-4 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
                  </button>
                </div>
                </div>
                </div>
              </form>

              <!-- ░░ Sending ░░ -->
              <div v-else-if="step === 'sending'" key="sending" class="px-6 py-14 sm:px-7 text-center" role="status" aria-live="polite">
                <div class="relative mx-auto h-20 w-20">
                  <span class="absolute inset-0 rounded-full bg-brand-gold/20 animate-pulse-ring motion-reduce:hidden" aria-hidden="true" />
                  <span class="absolute inset-0 rounded-full border-[3px] border-brand-gold/25 border-t-brand-gold animate-spin motion-reduce:animate-none" aria-hidden="true" />
                  <span class="absolute inset-0 flex items-center justify-center text-brand-gold-deep dark:text-brand-gold">
                    <svg class="h-8 w-8 inq-fly" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
                  </span>
                </div>
                <p class="mt-6 font-heading text-xl font-semibold text-brand-navy dark:text-white">Sending your inquiry…</p>
                <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/55">Delivering it to {{ agentName }}.</p>
              </div>

              <!-- ░░ Sent ░░ -->
              <div v-else-if="step === 'sent'" key="sent" class="p-6 sm:p-7 text-center" role="status" aria-live="polite">
                <div class="relative mx-auto mt-2 h-20 w-20">
                  <span class="absolute inset-0 rounded-full bg-emerald-500/15 inq-pop" aria-hidden="true" />
                  <svg class="absolute inset-0 h-20 w-20 text-emerald-500" viewBox="0 0 80 80" fill="none" aria-hidden="true">
                    <circle cx="40" cy="40" r="30" stroke="currentColor" stroke-width="3" class="inq-ring" />
                    <path d="M27 41l9 9 18-19" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" class="inq-check" />
                  </svg>
                </div>
                <h2 id="inq-title" class="mt-5 font-heading text-2xl font-semibold text-brand-navy dark:text-white">Inquiry sent</h2>
                <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/60">
                  {{ agentName }} has been notified<template v-if="sentTo"> and will reply to <span class="font-semibold text-brand-navy dark:text-white">{{ sentTo }}</span></template>.
                </p>

                <!-- What was sent -->
                <div class="mt-5 text-left rounded-2xl p-4 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.05] dark:ring-white/10">
                  <div class="flex items-center gap-3">
                    <img v-if="photo" :src="photo.thumb_url ?? photo.url" alt="" class="h-11 w-12 flex-none rounded-lg object-cover" />
                    <div class="min-w-0">
                      <p class="truncate text-sm font-semibold text-brand-navy dark:text-white">{{ property.title }}</p>
                      <p class="text-xs text-brand-text-secondary dark:text-white/50 tabular-nums">{{ price }}</p>
                    </div>
                  </div>
                  <p class="mt-3 pt-3 border-t border-brand-navy/[0.06] dark:border-white/10 text-sm italic leading-relaxed text-brand-navy/75 dark:text-white/70 line-clamp-3">“{{ form.message }}”</p>
                </div>

                <!-- What happens next -->
                <ol class="mt-5 space-y-2.5 text-left">
                  <li class="flex items-start gap-3 text-sm text-brand-navy/80 dark:text-white/75">
                    <span class="mt-px h-5 w-5 flex-none rounded-full bg-emerald-500 text-white text-[0.6875rem] font-bold flex items-center justify-center">✓</span>
                    The agent got your message just now.
                  </li>
                  <li class="flex items-start gap-3 text-sm text-brand-navy/80 dark:text-white/75">
                    <span class="mt-px h-5 w-5 flex-none rounded-full bg-brand-gold text-brand-navy text-[0.6875rem] font-bold flex items-center justify-center">2</span>
                    Watch your inbox{{ form.phone && guest ? ' and phone' : '' }} for their reply.
                  </li>
                  <li v-if="guest" class="flex items-start gap-3 text-sm text-brand-navy/80 dark:text-white/75">
                    <span class="mt-px h-5 w-5 flex-none rounded-full bg-brand-navy/10 dark:bg-white/10 text-brand-navy dark:text-white text-[0.6875rem] font-bold flex items-center justify-center">3</span>
                    Want to chat live or book a viewing? Create a free account.
                  </li>
                </ol>

                <div class="mt-6 flex flex-col-reverse sm:flex-row gap-2.5">
                  <button
                    v-if="guest"
                    type="button"
                    class="sm:flex-1 rounded-full px-5 py-3 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 dark:text-white dark:ring-white/20
                           hover:ring-2 hover:ring-brand-gold dark:hover:ring-brand-gold hover:-translate-y-0.5 transition"
                    @click="emit('close'); openAuth('register')"
                  >
                    Create account
                  </button>
                  <button
                    type="button"
                    class="sm:flex-1 rounded-full bg-brand-navy px-5 py-3 text-sm font-bold text-white dark:bg-white dark:text-brand-navy hover:-translate-y-0.5 hover:shadow-lg transition"
                    @click="emit('close')"
                  >
                    Done
                  </button>
                </div>
              </div>

              <!-- ░░ Error ░░ -->
              <div v-else key="error" class="p-6 sm:p-7 text-center" role="alert">
                <div class="mx-auto mt-2 h-20 w-20 rounded-full bg-red-500/10 flex items-center justify-center inq-shake">
                  <svg class="h-9 w-9 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                </div>
                <h2 id="inq-title" class="mt-5 font-heading text-2xl font-semibold text-brand-navy dark:text-white">Inquiry not sent</h2>
                <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/60">
                  {{ apiError || 'Something went wrong on our side. Please try again.' }}
                </p>
                <p class="mt-1 text-xs text-brand-navy/45 dark:text-white/40">Your message is still here — nothing was lost.</p>
                <div class="mt-6 flex flex-col-reverse sm:flex-row gap-2.5">
                  <button type="button" class="sm:flex-1 rounded-full px-5 py-3 text-sm font-semibold text-brand-navy/70 hover:bg-brand-navy/5 dark:text-white/65 dark:hover:bg-white/10 transition" @click="close">Close</button>
                  <button type="button" class="sm:flex-1 rounded-full bg-brand-gold px-5 py-3 text-sm font-bold text-brand-navy hover:bg-brand-gold-light transition" @click="step = 'form'">Try again</button>
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
.inq-backdrop-enter-active, .inq-backdrop-leave-active { transition: opacity 0.22s ease; }
.inq-backdrop-enter-from, .inq-backdrop-leave-to { opacity: 0; }

.inq-panel-enter-active { transition: opacity 0.25s ease, transform 0.35s cubic-bezier(0.22, 1, 0.36, 1); }
.inq-panel-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.inq-panel-enter-from, .inq-panel-leave-to { opacity: 0; transform: translateY(24px) scale(0.98); }

.inq-step-enter-active, .inq-step-leave-active { transition: opacity 0.2s ease, transform 0.25s cubic-bezier(0.22, 1, 0.36, 1); }
.inq-step-enter-from { opacity: 0; transform: translateY(10px); }
.inq-step-leave-to   { opacity: 0; transform: translateY(-6px); }

/* Sending: the paper plane bobs forward. */
.inq-fly { animation: inq-fly 1.1s ease-in-out infinite; }
@keyframes inq-fly {
  0%, 100% { transform: translate(0, 0); }
  50%      { transform: translate(3px, -3px); }
}

/* Sent: ring draws, then the check, then a soft pop behind. */
.inq-ring  { stroke-dasharray: 189; stroke-dashoffset: 189; animation: inq-draw 0.5s ease-out forwards; }
.inq-check { stroke-dasharray: 44;  stroke-dashoffset: 44;  animation: inq-draw 0.35s 0.4s ease-out forwards; }
@keyframes inq-draw { to { stroke-dashoffset: 0; } }
.inq-pop { animation: inq-pop 0.6s 0.55s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes inq-pop {
  from { transform: scale(0.6); opacity: 0; }
  to   { transform: scale(1);   opacity: 1; }
}

/* Error: one short shake. */
.inq-shake { animation: inq-shake 0.4s ease-in-out; }
@keyframes inq-shake {
  0%, 100% { transform: translateX(0); }
  25% { transform: translateX(-6px); }
  75% { transform: translateX(6px); }
}

@media (prefers-reduced-motion: reduce) {
  .inq-fly, .inq-pop, .inq-shake { animation: none; }
  .inq-ring, .inq-check { animation: none; stroke-dashoffset: 0; }
  .inq-panel-enter-active, .inq-step-enter-active, .inq-step-leave-active { transition: none; }
}
</style>
