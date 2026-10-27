<script setup lang="ts">
import type { Property } from '~/types'

/**
 * Schedule a viewing, as one dialog that walks through its own states:
 *
 *   pick → review → sending → sent        (or → error → back to review)
 *
 * Pick: a strip of the next 14 days (the agent's blocked days greyed out,
 * part-blocked ones marked "Limited") and that day's open times grouped into
 * morning / afternoon / evening, plus an optional note.
 * Review: everything that's about to be sent, and the 24-hour cancellation
 * rule, before anything is booked. Sent: what happens next, with Add to
 * calendar and a way to the Appointments page.
 */
const props = defineProps<{ open: boolean; property: Property }>()
const emit = defineEmits<{ close: [] }>()

const { book, error: bookError, fetchMyAppointments } = useAppointment()
const { fetchSlots, fetchUnavailableDates } = useAvailability()
const propertyHref = usePropertyHref()
const toast = useToast()

type Step = 'pick' | 'review' | 'sending' | 'sent' | 'error'
const step = ref<Step>('pick')

/* ── Dates ── */
const days = computed(() => {
  const out: Date[] = []
  const today = new Date()
  for (let i = 0; i < 14; i++) { const d = new Date(today); d.setDate(today.getDate() + i); out.push(d) }
  return out
})
const dateKey = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const blocked = ref<Set<string>>(new Set())
const limited = ref<Set<string>>(new Set())
const isBlocked = (d: Date) => blocked.value.has(dateKey(d))
const isLimited = (d: Date) => limited.value.has(dateKey(d))
function relDay(i: number, d: Date) {
  return i === 0 ? 'Today' : i === 1 ? 'Tomorrow' : d.toLocaleDateString('en-PH', { weekday: 'short' })
}

const date = ref('')
const slot = ref('')
const slots = ref<string[]>([])
const loadingSlots = ref(false)
const note = ref('')
const MAX_NOTE = 500

async function pickDate(d: Date) {
  if (isBlocked(d)) return
  date.value = dateKey(d)
  slot.value = ''
  slots.value = []
  loadingSlots.value = true
  slots.value = await fetchSlots(props.property.agent_id, date.value)
  loadingSlots.value = false
}

/* ── Times, grouped ── */
function label(t: string): string {
  const [h, m] = t.split(':').map(Number)
  return `${((h ?? 0) % 12) || 12}:${String(m ?? 0).padStart(2, '0')} ${(h ?? 0) >= 12 ? 'PM' : 'AM'}`
}
const groups = computed(() => {
  const hour = (t: string) => Number(t.split(':')[0])
  return [
    { key: 'morning',   label: 'Morning',   icon: 'M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z', items: slots.value.filter(t => hour(t) < 12) },
    { key: 'afternoon', label: 'Afternoon', icon: 'M2.25 15.75h19.5M5.636 12.364A6.75 6.75 0 0118.364 12.364M12 3.75V6M4.5 7.5l1.5 1.5M19.5 7.5L18 9', items: slots.value.filter(t => hour(t) >= 12 && hour(t) < 17) },
    { key: 'evening',   label: 'Evening',   icon: 'M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z', items: slots.value.filter(t => hour(t) >= 17) },
  ].filter(g => g.items.length)
})

/* ── Open / close ── */
watch(() => props.open, async (on) => {
  if (!import.meta.client) return
  document.documentElement.style.overflow = on ? 'hidden' : ''
  if (!on) return
  if (step.value === 'sent') { date.value = ''; slot.value = ''; slots.value = []; note.value = '' }
  step.value = 'pick'
  const u = await fetchUnavailableDates(props.property.agent_id)
  blocked.value = new Set(u.blocked)
  limited.value = new Set(u.limited)
  if (!date.value) {
    const first = days.value.find(d => !isBlocked(d))
    if (first) pickDate(first)
  }
}, { immediate: true })
onUnmounted(() => { if (import.meta.client) document.documentElement.style.overflow = '' })

function close() {
  if (step.value === 'sending') return
  emit('close')
}
function onKey(e: KeyboardEvent) { if (e.key === 'Escape' && props.open) close() }
onMounted(() => document.addEventListener('keydown', onKey))
onUnmounted(() => document.removeEventListener('keydown', onKey))

/* ── Send ── */
const chosen = computed(() => (date.value && slot.value ? new Date(`${date.value}T${slot.value}`) : null))
const chosenDate = computed(() => chosen.value?.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric' }) ?? '')
const chosenTime = computed(() => (slot.value ? label(slot.value) : ''))
const hoursAway = computed(() => (chosen.value ? (chosen.value.getTime() - Date.now()) / 3_600_000 : 0))

async function send() {
  if (!date.value || !slot.value) return
  step.value = 'sending'
  const started = Date.now()
  const appt = await book({
    property_id:        props.property.id,
    agent_id:           props.property.agent_id,
    preferred_datetime: `${date.value} ${slot.value}`,
    notes:              note.value.trim() || undefined,
  })
  const wait = 700 - (Date.now() - started)
  if (wait > 0) await new Promise(r => setTimeout(r, wait))
  if (appt) {
    step.value = 'sent'
    fetchMyAppointments()   // the dashboard and Appointments page pick it up
  } else {
    step.value = 'error'
  }
}

function addToCalendar() {
  if (!chosen.value) return
  downloadViewingIcs({
    uid: `request-${props.property.id}-${date.value}-${slot.value.replace(':', '')}`,
    title: calm(props.property.title),
    address: props.property.address,
    start: chosen.value,
    agentName: props.property.agent?.name,
    url: `${window.location.origin}${propertyHref(props.property.id)}`,
    pending: true,
  })
  toast.success('Calendar file downloaded')
}

/* ── Display ── */
const photo = computed(() => {
  const p = props.property.photos?.find(x => !x.is_360) ?? props.property.photos?.[0]
  return p ? (p.thumb_url ?? p.url) : null
})
function calm(t: string): string {
  const letters = t.replace(/[^A-Za-z]/g, '')
  return letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
}
const verified = computed(() => props.property.agent?.agent_profile?.status === 'approved')
const eyebrow = 'text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40'
</script>

<template>
  <Teleport to="body">
    <Transition name="sv-backdrop">
      <div
        v-if="open"
        class="fixed inset-0 z-[95] flex items-end sm:items-center justify-center sm:p-4 bg-brand-navy-deep/60 backdrop-blur-sm"
        @click.self="close"
      >
        <Transition name="sv-panel" appear>
          <div
            v-if="open"
            role="dialog"
            aria-modal="true"
            aria-labelledby="sv-title"
            class="relative w-full sm:max-w-xl max-h-[92vh] overflow-y-auto rounded-t-[1.75rem] sm:rounded-[1.75rem]
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

            <Transition name="sv-step" mode="out-in">
              <!-- ░░ Pick ░░ -->
              <div v-if="step === 'pick'" key="pick" class="p-6 sm:p-7">
                <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold-deep dark:text-brand-gold">Step 1 of 2</p>
                <h2 id="sv-title" class="mt-1 pr-10 font-heading text-2xl font-semibold tracking-[-0.01em] text-brand-navy dark:text-white">Schedule a viewing</h2>

                <!-- Listing + agent -->
                <div class="mt-5 flex items-center gap-3 rounded-2xl p-2.5 pr-4 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.05] dark:ring-white/10">
                  <img v-if="photo" :src="photo" alt="" class="h-14 w-16 flex-none rounded-xl object-cover" />
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-brand-navy dark:text-white">{{ calm(property.title) }}</p>
                    <p class="truncate text-xs text-brand-text-secondary dark:text-white/55">{{ property.address }}</p>
                    <p class="mt-0.5 flex items-center gap-1 text-xs text-brand-text-secondary dark:text-white/55">
                      With <span class="font-semibold text-brand-navy dark:text-white">{{ property.agent?.name ?? 'the agent' }}</span>
                      <AppVerifiedTick v-if="verified" size="xs" />
                    </p>
                  </div>
                </div>

                <!-- Dates -->
                <div class="mt-6 flex items-center justify-between">
                  <p :class="eyebrow">Pick a day</p>
                  <p class="flex items-center gap-1.5 text-[0.6875rem] text-brand-navy/45 dark:text-white/40">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500" /> Limited times
                  </p>
                </div>
                <div class="mt-2.5 -mx-6 sm:-mx-7 px-6 sm:px-7 overflow-x-auto no-scrollbar">
                  <div class="flex w-max gap-2 pb-1" role="radiogroup" aria-label="Day">
                    <button
                      v-for="(d, i) in days"
                      :key="dateKey(d)"
                      type="button"
                      role="radio"
                      :aria-checked="date === dateKey(d)"
                      :disabled="isBlocked(d)"
                      :title="isBlocked(d) ? 'The agent is unavailable this day' : isLimited(d) ? 'Limited times this day' : undefined"
                      class="relative w-[4.25rem] flex-none rounded-2xl py-2.5 text-center ring-1 transition-all"
                      :class="isBlocked(d)
                        ? 'cursor-not-allowed ring-brand-navy/[0.06] bg-brand-navy/[0.03] text-brand-navy/25 dark:ring-white/[0.06] dark:text-white/20'
                        : date === dateKey(d)
                          ? 'bg-brand-navy text-white ring-brand-navy shadow-[0_8px_18px_-8px_rgba(11,42,92,0.6)] dark:bg-brand-gold dark:text-brand-navy dark:ring-brand-gold'
                          : 'text-brand-navy ring-brand-navy/15 hover:ring-brand-gold hover:-translate-y-0.5 dark:text-white dark:ring-white/15'"
                      @click="pickDate(d)"
                    >
                      <span class="block text-[0.625rem] font-bold uppercase tracking-wide opacity-70">{{ relDay(i, d) }}</span>
                      <span class="block mt-0.5 font-display text-xl font-bold leading-none" :class="isBlocked(d) ? 'line-through' : ''">{{ d.getDate() }}</span>
                      <span class="block mt-1 text-[0.625rem] font-semibold opacity-60">{{ d.toLocaleDateString('en-PH', { month: 'short' }) }}</span>
                      <span v-if="!isBlocked(d) && isLimited(d)" class="absolute top-1.5 right-1.5 h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true" />
                    </button>
                  </div>
                </div>

                <!-- Times -->
                <p :class="[eyebrow, 'mt-6']">Pick a time</p>
                <div class="mt-2.5 min-h-[7.5rem]">
                  <div v-if="loadingSlots" class="grid grid-cols-3 sm:grid-cols-4 gap-2">
                    <span v-for="i in 8" :key="i" class="h-10 rounded-xl bg-brand-navy/[0.05] dark:bg-white/[0.06] animate-pulse" />
                  </div>
                  <p v-else-if="!date" class="py-8 text-center text-sm text-brand-navy/45 dark:text-white/45">Pick a day to see open times.</p>
                  <div v-else-if="!groups.length" class="rounded-2xl bg-amber-500/[0.07] ring-1 ring-amber-500/20 px-4 py-6 text-center">
                    <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">No open times this day</p>
                    <p class="mt-0.5 text-xs text-amber-800/70 dark:text-amber-200/60">Try another day, or message the agent to ask.</p>
                  </div>
                  <div v-else class="space-y-3.5">
                    <div v-for="g in groups" :key="g.key">
                      <p class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-brand-navy/60 dark:text-white/55">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="g.icon" /></svg>
                        {{ g.label }}
                      </p>
                      <div class="grid grid-cols-3 sm:grid-cols-4 gap-2" role="radiogroup" :aria-label="`${g.label} times`">
                        <button
                          v-for="t in g.items"
                          :key="t"
                          type="button"
                          role="radio"
                          :aria-checked="slot === t"
                          class="h-10 rounded-xl text-sm font-semibold ring-1 transition-all active:scale-95"
                          :class="slot === t
                            ? 'bg-brand-gold text-brand-navy ring-brand-gold shadow-[0_6px_16px_-6px_rgba(212,165,74,0.8)]'
                            : 'text-brand-navy ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/15'"
                          @click="slot = t"
                        >{{ label(t) }}</button>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Note -->
                <label for="sv-note" class="mt-6 mb-1.5 flex items-center justify-between text-sm font-semibold text-brand-navy dark:text-white/90">
                  Note for the agent <span class="text-xs font-medium text-brand-navy/40 dark:text-white/40">Optional</span>
                </label>
                <textarea
                  id="sv-note"
                  v-model="note"
                  rows="2"
                  :maxlength="MAX_NOTE"
                  placeholder="e.g. Can we also see the parking slot?"
                  class="w-full resize-none rounded-2xl border-0 px-4 py-3 text-[0.9375rem] text-brand-navy dark:text-white bg-brand-navy/[0.04] dark:bg-white/[0.06]
                         ring-1 ring-inset ring-brand-navy/10 dark:ring-white/10 placeholder-brand-navy/40 dark:placeholder-white/35
                         focus:bg-white dark:focus:bg-white/[0.09] focus:ring-2 focus:ring-brand-gold dark:focus:ring-brand-gold transition"
                />

                <div class="mt-6 flex flex-col-reverse sm:flex-row gap-2.5">
                  <button type="button" class="sm:flex-1 rounded-full px-5 py-3 text-sm font-semibold text-brand-navy/75 ring-1 ring-brand-navy/20 hover:ring-2 hover:ring-brand-gold dark:text-white/75 dark:ring-white/25 dark:hover:ring-brand-gold transition" @click="close">
                    Cancel
                  </button>
                  <button
                    type="button"
                    class="group sm:flex-[1.4] inline-flex items-center justify-center gap-2 rounded-full bg-brand-gold px-5 py-3 text-sm font-bold text-brand-navy
                           shadow-[0_6px_18px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light hover:-translate-y-0.5 transition
                           disabled:opacity-40 disabled:shadow-none disabled:hover:translate-y-0 disabled:cursor-not-allowed"
                    :disabled="!slot"
                    @click="step = 'review'"
                  >
                    {{ slot ? `Review · ${chosenTime}` : 'Pick a time to continue' }}
                    <svg class="h-4 w-4 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                  </button>
                </div>
              </div>

              <!-- ░░ Review ░░ -->
              <div v-else-if="step === 'review'" key="review" class="p-6 sm:p-7">
                <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold-deep dark:text-brand-gold">Step 2 of 2</p>
                <h2 id="sv-title" class="mt-1 pr-10 font-heading text-2xl font-semibold tracking-[-0.01em] text-brand-navy dark:text-white">Confirm your viewing</h2>
                <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/55">Check the details before we send the request.</p>

                <div class="mt-5 overflow-hidden rounded-2xl ring-1 ring-brand-navy/[0.08] dark:ring-white/10">
                  <!-- When, as a ticket -->
                  <div class="flex items-center gap-4 p-4 bg-brand-navy text-white">
                    <span class="w-14 flex-none overflow-hidden rounded-xl bg-[#ffffff] text-center">
                      <span class="block bg-brand-gold py-0.5 text-[0.625rem] font-bold uppercase tracking-wider text-[#0B2A5C]">{{ chosen?.toLocaleDateString('en-PH', { month: 'short' }) }}</span>
                      <span class="block py-1 font-display text-xl font-bold leading-none text-[#0B2A5C]">{{ chosen?.getDate() }}</span>
                    </span>
                    <div class="min-w-0">
                      <p class="font-display text-lg font-bold leading-tight">{{ chosenDate }}</p>
                      <p class="text-sm font-semibold text-brand-gold">{{ chosenTime }} · about 1 hour</p>
                    </div>
                    <button type="button" class="ml-auto flex-none rounded-full px-3 py-1.5 text-xs font-bold text-white/80 ring-1 ring-white/25 hover:ring-brand-gold hover:text-white transition" @click="step = 'pick'">Change</button>
                  </div>

                  <dl class="divide-y divide-brand-navy/[0.06] dark:divide-white/10 text-sm">
                    <div class="flex items-center gap-3 px-4 py-3">
                      <img v-if="photo" :src="photo" alt="" class="h-10 w-12 flex-none rounded-lg object-cover" />
                      <div class="min-w-0">
                        <dt class="sr-only">Property</dt>
                        <dd class="truncate font-semibold text-brand-navy dark:text-white">{{ calm(property.title) }}</dd>
                        <dd class="truncate text-xs text-brand-text-secondary dark:text-white/50">{{ property.address }}</dd>
                      </div>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                      <dt class="text-brand-navy/55 dark:text-white/50">Agent</dt>
                      <dd class="flex items-center gap-1.5 font-semibold text-brand-navy dark:text-white">
                        <AppAvatar :name="property.agent?.name" :src="property.agent?.avatar" size="xs" />
                        {{ property.agent?.name ?? 'The agent' }}
                      </dd>
                    </div>
                    <div class="px-4 py-3">
                      <dt class="text-brand-navy/55 dark:text-white/50">Your note</dt>
                      <dd class="mt-0.5 text-brand-navy dark:text-white" :class="note.trim() ? 'italic' : 'text-brand-navy/40 dark:text-white/35'">
                        {{ note.trim() ? `“${note.trim()}”` : 'No note' }}
                      </dd>
                    </div>
                  </dl>
                </div>

                <!-- What booking means -->
                <ul class="mt-5 space-y-2.5 text-sm text-brand-navy/75 dark:text-white/70">
                  <li class="flex items-start gap-2.5">
                    <svg class="mt-0.5 h-4 w-4 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" /></svg>
                    This sends a request. {{ property.agent?.name ?? 'The agent' }} confirms it and you get a notification.
                  </li>
                  <li class="flex items-start gap-2.5">
                    <svg class="mt-0.5 h-4 w-4 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span>
                      Cancel at least <span class="font-semibold text-brand-navy dark:text-white">24 hours</span> ahead and it never counts against you.
                      <span v-if="hoursAway < 24" class="mt-1 block rounded-lg bg-amber-500/10 px-2.5 py-1.5 text-xs font-medium text-amber-800 dark:text-amber-300">
                        This viewing is less than 24 hours away, so any cancellation would count as late.
                      </span>
                    </span>
                  </li>
                </ul>

                <div class="mt-6 flex flex-col-reverse sm:flex-row gap-2.5">
                  <button type="button" class="sm:flex-1 rounded-full px-5 py-3 text-sm font-semibold text-brand-navy/75 ring-1 ring-brand-navy/20 hover:ring-2 hover:ring-brand-gold dark:text-white/75 dark:ring-white/25 dark:hover:ring-brand-gold transition" @click="step = 'pick'">
                    Back
                  </button>
                  <button
                    type="button"
                    class="group sm:flex-[1.4] inline-flex items-center justify-center gap-2 rounded-full bg-brand-gold px-5 py-3 text-sm font-bold text-brand-navy
                           shadow-[0_6px_18px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light hover:-translate-y-0.5 transition"
                    @click="send"
                  >
                    Send viewing request
                    <svg class="h-4 w-4 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
                  </button>
                </div>
              </div>

              <!-- ░░ Sending ░░ -->
              <div v-else-if="step === 'sending'" key="sending" class="px-6 py-14 sm:px-7 text-center" role="status" aria-live="polite">
                <div class="relative mx-auto h-20 w-20">
                  <span class="absolute inset-0 rounded-full bg-brand-gold/20 animate-pulse-ring motion-reduce:hidden" aria-hidden="true" />
                  <span class="absolute inset-0 rounded-full border-[3px] border-brand-gold/25 border-t-brand-gold animate-spin motion-reduce:animate-none" aria-hidden="true" />
                  <span class="absolute inset-0 flex items-center justify-center text-brand-gold-deep dark:text-brand-gold">
                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                  </span>
                </div>
                <p class="mt-6 font-heading text-xl font-semibold text-brand-navy dark:text-white">Booking your viewing…</p>
                <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/55">Sending it to {{ property.agent?.name ?? 'the agent' }}.</p>
              </div>

              <!-- ░░ Sent ░░ -->
              <div v-else-if="step === 'sent'" key="sent" class="p-6 sm:p-7 text-center" role="status" aria-live="polite">
                <div class="relative mx-auto mt-2 h-20 w-20">
                  <span class="absolute inset-0 rounded-full bg-emerald-500/15 sv-pop" aria-hidden="true" />
                  <svg class="absolute inset-0 h-20 w-20 text-emerald-500" viewBox="0 0 80 80" fill="none" aria-hidden="true">
                    <circle cx="40" cy="40" r="30" stroke="currentColor" stroke-width="3" class="sv-ring" />
                    <path d="M27 41l9 9 18-19" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" class="sv-check" />
                  </svg>
                </div>
                <h2 id="sv-title" class="mt-5 font-heading text-2xl font-semibold text-brand-navy dark:text-white">Viewing requested</h2>
                <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/60">
                  <span class="font-semibold text-brand-navy dark:text-white">{{ chosenDate }}, {{ chosenTime }}</span>
                  at {{ calm(property.title).replace(/[.!?]+$/, '') }}.
                </p>

                <ol class="mt-6 space-y-2.5 text-left">
                  <li class="flex items-start gap-3 text-sm text-brand-navy/80 dark:text-white/75">
                    <span class="mt-px h-5 w-5 flex-none rounded-full bg-emerald-500 text-white text-[0.6875rem] font-bold flex items-center justify-center">✓</span>
                    Your request is with {{ property.agent?.name ?? 'the agent' }}.
                  </li>
                  <li class="flex items-start gap-3 text-sm text-brand-navy/80 dark:text-white/75">
                    <span class="mt-px h-5 w-5 flex-none rounded-full bg-brand-gold text-brand-navy text-[0.6875rem] font-bold flex items-center justify-center">2</span>
                    You’ll get a notification when they confirm. Track it in Appointments.
                  </li>
                  <li class="flex items-start gap-3 text-sm text-brand-navy/80 dark:text-white/75">
                    <span class="mt-px h-5 w-5 flex-none rounded-full bg-brand-navy/10 dark:bg-white/10 text-brand-navy dark:text-white text-[0.6875rem] font-bold flex items-center justify-center">3</span>
                    On the day, open Appointments for directions.
                  </li>
                </ol>

                <div class="mt-6 flex flex-col sm:flex-row gap-2.5">
                  <button type="button" class="sm:flex-1 inline-flex items-center justify-center gap-1.5 rounded-full px-5 py-3 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-2 hover:ring-brand-gold dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold transition" @click="addToCalendar">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                    Add to calendar
                  </button>
                  <NuxtLink
                    to="/dashboard/appointments"
                    class="sm:flex-1 inline-flex items-center justify-center rounded-full bg-brand-navy px-5 py-3 text-sm font-bold text-white dark:bg-brand-gold dark:text-brand-navy hover:-translate-y-0.5 hover:shadow-lg transition"
                    @click="emit('close')"
                  >
                    View my appointments
                  </NuxtLink>
                </div>
                <button type="button" class="mt-3 text-xs font-semibold text-brand-navy/50 hover:text-brand-navy dark:text-white/45 dark:hover:text-white underline-offset-4 hover:underline" @click="emit('close')">Done</button>
              </div>

              <!-- ░░ Error ░░ -->
              <div v-else key="error" class="p-6 sm:p-7 text-center" role="alert">
                <div class="mx-auto mt-2 h-20 w-20 rounded-full bg-red-500/10 flex items-center justify-center sv-shake">
                  <svg class="h-9 w-9 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                </div>
                <h2 id="sv-title" class="mt-5 font-heading text-2xl font-semibold text-brand-navy dark:text-white">Couldn’t book this time</h2>
                <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/60">{{ bookError || 'Something went wrong on our side. Please try again.' }}</p>
                <div class="mt-6 flex flex-col-reverse sm:flex-row gap-2.5">
                  <button type="button" class="sm:flex-1 rounded-full px-5 py-3 text-sm font-semibold text-brand-navy/70 ring-1 ring-brand-navy/20 hover:ring-brand-gold dark:text-white/65 dark:ring-white/25 transition" @click="close">Close</button>
                  <button type="button" class="sm:flex-1 rounded-full bg-brand-gold px-5 py-3 text-sm font-bold text-brand-navy hover:bg-brand-gold-light transition" @click="step = 'pick'; date && pickDate(new Date(`${date}T00:00`))">Pick another time</button>
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
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }

.sv-backdrop-enter-active, .sv-backdrop-leave-active { transition: opacity 0.22s ease; }
.sv-backdrop-enter-from, .sv-backdrop-leave-to { opacity: 0; }
.sv-panel-enter-active { transition: opacity 0.25s ease, transform 0.35s cubic-bezier(0.22, 1, 0.36, 1); }
.sv-panel-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.sv-panel-enter-from, .sv-panel-leave-to { opacity: 0; transform: translateY(24px) scale(0.98); }
.sv-step-enter-active, .sv-step-leave-active { transition: opacity 0.2s ease, transform 0.25s cubic-bezier(0.22, 1, 0.36, 1); }
.sv-step-enter-from { opacity: 0; transform: translateX(14px); }
.sv-step-leave-to   { opacity: 0; transform: translateX(-10px); }

.sv-ring  { stroke-dasharray: 189; stroke-dashoffset: 189; animation: sv-draw 0.5s ease-out forwards; }
.sv-check { stroke-dasharray: 44;  stroke-dashoffset: 44;  animation: sv-draw 0.35s 0.4s ease-out forwards; }
@keyframes sv-draw { to { stroke-dashoffset: 0; } }
.sv-pop { animation: sv-pop 0.6s 0.55s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes sv-pop { from { transform: scale(0.6); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.sv-shake { animation: sv-shake 0.4s ease-in-out; }
@keyframes sv-shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-6px); } 75% { transform: translateX(6px); } }

@media (prefers-reduced-motion: reduce) {
  .sv-pop, .sv-shake { animation: none; }
  .sv-ring, .sv-check { animation: none; stroke-dashoffset: 0; }
  .sv-panel-enter-active, .sv-step-enter-active, .sv-step-leave-active { transition: none; }
}
</style>
