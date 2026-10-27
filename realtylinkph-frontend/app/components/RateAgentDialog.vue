<script setup lang="ts">
import type { AgentReview, Appointment } from '~/types'

/**
 * Rate the agent after a viewing, in the same style as Send inquiry and
 * Schedule a viewing:  form → sending → thanks   (or → error, text kept).
 *
 * Pass `review` instead of `appointment` to edit an existing review: the
 * form opens prefilled and saves with an update.
 */
const props = defineProps<{ open: boolean; appointment?: Appointment | null; review?: AgentReview | null }>()
const emit = defineEmits<{ close: []; rated: [] }>()

const { submitReview, updateReview, error: reviewError } = useReview()
const isEdit = computed(() => !!props.review)

type Step = 'form' | 'sending' | 'sent' | 'error'
const step = ref<Step>('form')
const rating = ref(0)
const hover = ref(0)
const tags = ref<string[]>([])
const note = ref('')
const touched = ref(false)

const TAGS = ['On time', 'Knowledgeable', 'Honest', 'Responsive', 'Friendly', 'Patient']
const WORDS = ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent']

watch(() => props.open, (on) => {
  if (!import.meta.client) return
  document.documentElement.style.overflow = on ? 'hidden' : ''
  if (!on) return
  if (step.value !== 'error') {
    const r = props.review
    // An existing review: split the "👍 tag · tag" line back out of the text.
    const lines = (r?.review_text ?? '').split(/\r?\n/)
    const first = lines[0] ?? ''
    const hasTags = first.startsWith('👍')
    rating.value = r?.rating ?? 0
    tags.value = hasTags ? first.slice(2).split('·').map(t => t.trim()).filter(t => TAGS.includes(t)) : []
    note.value = r ? (hasTags ? lines.slice(1).join('\n') : r.review_text ?? '') : ''
  }
  touched.value = false
  step.value = 'form'
})
onUnmounted(() => { if (import.meta.client) document.documentElement.style.overflow = '' })

function close() {
  if (step.value === 'sending') return
  emit('close')
}
function onKey(e: KeyboardEvent) { if (e.key === 'Escape' && props.open) close() }
onMounted(() => document.addEventListener('keydown', onKey))
onUnmounted(() => document.removeEventListener('keydown', onKey))

function toggleTag(t: string) {
  tags.value = tags.value.includes(t) ? tags.value.filter(x => x !== t) : [...tags.value, t]
}

async function send() {
  touched.value = true
  if (!rating.value || (!props.appointment && !props.review)) return
  step.value = 'sending'
  const started = Date.now()
  // Same body format as before: the tag line first, then the note.
  const tagLine = tags.value.length ? `👍 ${tags.value.join(' · ')}` : ''
  const body = [tagLine, note.value.trim()].filter(Boolean).join('\n')
  const res = props.review
    ? await updateReview(props.review.id, { rating: rating.value, review_text: body || undefined })
    : await submitReview({ appointment_id: props.appointment!.id, rating: rating.value, review_text: body || undefined })
  const wait = 700 - (Date.now() - started)
  if (wait > 0) await new Promise(r => setTimeout(r, wait))
  if (res) { step.value = 'sent'; emit('rated') }
  else step.value = 'error'
}

const a = computed(() => props.appointment)
/* What the card at the top shows, from the viewing or from the review. */
const subject = computed(() => ({
  agent: a.value?.agent ?? props.review?.agent ?? null,
  title: a.value?.property?.title ?? props.review?.property_title ?? null,
  date: a.value?.preferred_datetime ?? props.review?.viewed_at ?? null,
}))
const photo = computed(() => {
  if (props.review && !a.value) return props.review.property_photo ?? null
  const p = a.value?.property?.photos?.find(x => !x.is_360) ?? a.value?.property?.photos?.[0]
  return p ? (p.thumb_url ?? p.url) : null
})
function calm(t?: string): string {
  if (!t) return 'this property'
  const letters = t.replace(/[^A-Za-z]/g, '')
  return letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
}
const when = computed(() => (subject.value.date ? new Date(subject.value.date).toLocaleDateString('en-PH', { month: 'long', day: 'numeric' }) : ''))
const shown = computed(() => hover.value || rating.value)
</script>

<template>
  <Teleport to="body">
    <Transition name="ra-backdrop">
      <div v-if="open && (a || review)" class="fixed inset-0 z-[95] flex items-end sm:items-center justify-center sm:p-4 bg-brand-navy-deep/60 backdrop-blur-sm" @click.self="close">
        <Transition name="ra-panel" appear>
          <div
            v-if="open"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ra-title"
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

            <Transition name="ra-step" mode="out-in">
              <!-- ░░ Form ░░ -->
              <div v-if="step === 'form'" key="form" class="p-6 sm:p-7">
                <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold-deep dark:text-brand-gold">{{ isEdit ? 'Edit your review' : 'Rate your agent' }}</p>
                <h2 id="ra-title" class="mt-1 pr-10 font-heading text-2xl font-semibold tracking-[-0.01em] text-brand-navy dark:text-white">{{ isEdit ? `How was ${(subject.agent?.name ?? 'your agent').split(' ')[0]}?` : 'How was your viewing?' }}</h2>

                <div class="mt-5 flex items-center gap-3 rounded-2xl p-2.5 pr-4 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.05] dark:ring-white/10">
                  <img v-if="photo" :src="photo" alt="" class="h-14 w-16 flex-none rounded-xl object-cover" />
                  <AppAvatar v-else :name="subject.agent?.name" :src="subject.agent?.avatar" size="md" />
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-brand-navy dark:text-white">{{ subject.title ? calm(subject.title) : 'Chat with the agent' }}</p>
                    <p class="mt-0.5 flex items-center gap-1.5 text-xs text-brand-text-secondary dark:text-white/55">
                      <AppAvatar :name="subject.agent?.name" :src="subject.agent?.avatar" size="xs" />
                      <span class="font-semibold text-brand-navy dark:text-white">{{ subject.agent?.name ?? 'Your agent' }}</span>
                      <template v-if="when">· viewed {{ when }}</template>
                    </p>
                  </div>
                </div>

                <!-- Stars -->
                <div class="mt-6 text-center">
                  <div class="inline-flex gap-1.5" role="radiogroup" aria-label="Rating" @mouseleave="hover = 0">
                    <button
                      v-for="n in 5"
                      :key="n"
                      type="button"
                      role="radio"
                      :aria-checked="rating === n"
                      :aria-label="`${n} star${n === 1 ? '' : 's'}, ${WORDS[n]}`"
                      class="p-1 transition-transform active:scale-90 hover:scale-110"
                      @mouseenter="hover = n"
                      @click="rating = n"
                    >
                      <svg class="h-10 w-10 transition-colors" :class="n <= shown ? 'text-brand-gold' : 'text-brand-navy/15 dark:text-white/15'" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                      </svg>
                    </button>
                  </div>
                  <p class="mt-1 h-5 text-sm font-semibold" :class="shown ? 'text-brand-navy dark:text-white' : touched ? 'text-red-500' : 'text-brand-navy/40 dark:text-white/40'">
                    {{ shown ? WORDS[shown] : touched ? 'Please tap a star rating' : 'Tap a star' }}
                  </p>
                </div>

                <!-- Tags -->
                <p class="mt-5 mb-2 text-sm font-semibold text-brand-navy dark:text-white/90">What stood out?</p>
                <div class="flex flex-wrap gap-1.5">
                  <button
                    v-for="t in TAGS"
                    :key="t"
                    type="button"
                    :aria-pressed="tags.includes(t)"
                    class="rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition-all active:scale-95"
                    :class="tags.includes(t)
                      ? 'bg-brand-navy text-white ring-brand-navy dark:bg-brand-gold dark:text-brand-navy dark:ring-brand-gold'
                      : 'text-brand-navy/70 ring-brand-navy/15 hover:ring-brand-gold dark:text-white/70 dark:ring-white/15'"
                    @click="toggleTag(t)"
                  >{{ t }}</button>
                </div>

                <!-- Note -->
                <label for="ra-note" class="mt-5 mb-1.5 flex items-center justify-between text-sm font-semibold text-brand-navy dark:text-white/90">
                  Tell others more <span class="text-xs font-medium text-brand-navy/40 dark:text-white/40">Optional</span>
                </label>
                <textarea
                  id="ra-note"
                  v-model="note"
                  rows="3"
                  maxlength="2000"
                  placeholder="What went well, or what could be better?"
                  class="w-full resize-none rounded-2xl border-0 px-4 py-3 text-[0.9375rem] text-brand-navy dark:text-white bg-brand-navy/[0.04] dark:bg-white/[0.06]
                         ring-1 ring-inset ring-brand-navy/10 dark:ring-white/10 placeholder-brand-navy/40 dark:placeholder-white/35
                         focus:bg-white dark:focus:bg-white/[0.09] focus:ring-2 focus:ring-brand-gold dark:focus:ring-brand-gold transition"
                />
                <p class="mt-2 text-xs text-brand-navy/45 dark:text-white/40">Your review and name appear on the agent’s public profile. Your email and phone never do.</p>

                <div class="mt-6 flex flex-col-reverse sm:flex-row gap-2.5">
                  <button type="button" class="sm:flex-1 rounded-full px-5 py-3 text-sm font-semibold text-brand-navy/75 ring-1 ring-brand-navy/20 hover:ring-2 hover:ring-brand-gold dark:text-white/75 dark:ring-white/25 dark:hover:ring-brand-gold transition" @click="close">
                    Not now
                  </button>
                  <button
                    type="button"
                    class="sm:flex-[1.4] rounded-full bg-brand-gold px-5 py-3 text-sm font-bold text-brand-navy shadow-[0_6px_18px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light hover:-translate-y-0.5 transition"
                    @click="send"
                  >{{ isEdit ? 'Save changes' : 'Submit review' }}</button>
                </div>
              </div>

              <!-- ░░ Sending ░░ -->
              <div v-else-if="step === 'sending'" key="sending" class="px-6 py-14 text-center" role="status" aria-live="polite">
                <div class="relative mx-auto h-20 w-20">
                  <span class="absolute inset-0 rounded-full border-[3px] border-brand-gold/25 border-t-brand-gold animate-spin motion-reduce:animate-none" aria-hidden="true" />
                  <span class="absolute inset-0 flex items-center justify-center text-brand-gold-deep dark:text-brand-gold">
                    <svg class="h-8 w-8" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" /></svg>
                  </span>
                </div>
                <p class="mt-6 font-heading text-xl font-semibold text-brand-navy dark:text-white">{{ isEdit ? 'Saving your review…' : 'Posting your review…' }}</p>
              </div>

              <!-- ░░ Thanks ░░ -->
              <div v-else-if="step === 'sent'" key="sent" class="p-6 sm:p-7 text-center" role="status" aria-live="polite">
                <div class="relative mx-auto mt-2 h-20 w-20">
                  <span class="absolute inset-0 rounded-full bg-emerald-500/15 ra-pop" aria-hidden="true" />
                  <svg class="absolute inset-0 h-20 w-20 text-emerald-500" viewBox="0 0 80 80" fill="none" aria-hidden="true">
                    <circle cx="40" cy="40" r="30" stroke="currentColor" stroke-width="3" class="ra-ring" />
                    <path d="M27 41l9 9 18-19" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" class="ra-check" />
                  </svg>
                </div>
                <h2 id="ra-title" class="mt-5 font-heading text-2xl font-semibold text-brand-navy dark:text-white">{{ isEdit ? 'Review updated' : 'Thanks for your review' }}</h2>
                <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/60">
                  It helps other buyers choose a good agent.
                </p>
                <div class="mt-4 inline-flex gap-0.5" :aria-label="`Your rating: ${rating} stars`">
                  <svg v-for="n in 5" :key="n" class="h-5 w-5" :class="n <= rating ? 'text-brand-gold' : 'text-brand-navy/15 dark:text-white/15'" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" /></svg>
                </div>
                <button type="button" class="mt-6 w-full rounded-full bg-brand-navy px-5 py-3 text-sm font-bold text-white dark:bg-brand-gold dark:text-brand-navy hover:-translate-y-0.5 hover:shadow-lg transition" @click="emit('close')">
                  Done
                </button>
              </div>

              <!-- ░░ Error ░░ -->
              <div v-else key="error" class="p-6 sm:p-7 text-center" role="alert">
                <div class="mx-auto mt-2 h-20 w-20 rounded-full bg-red-500/10 flex items-center justify-center">
                  <svg class="h-9 w-9 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                </div>
                <h2 id="ra-title" class="mt-5 font-heading text-2xl font-semibold text-brand-navy dark:text-white">Review not posted</h2>
                <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/60">{{ reviewError || 'Something went wrong on our side. Please try again.' }}</p>
                <p class="mt-1 text-xs text-brand-navy/45 dark:text-white/40">Your rating and note are still here.</p>
                <div class="mt-6 flex flex-col-reverse sm:flex-row gap-2.5">
                  <button type="button" class="sm:flex-1 rounded-full px-5 py-3 text-sm font-semibold text-brand-navy/70 ring-1 ring-brand-navy/20 dark:text-white/65 dark:ring-white/25 transition" @click="close">Close</button>
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
.ra-backdrop-enter-active, .ra-backdrop-leave-active { transition: opacity 0.22s ease; }
.ra-backdrop-enter-from, .ra-backdrop-leave-to { opacity: 0; }
.ra-panel-enter-active { transition: opacity 0.25s ease, transform 0.35s cubic-bezier(0.22, 1, 0.36, 1); }
.ra-panel-enter-from { opacity: 0; transform: translateY(24px) scale(0.98); }
.ra-step-enter-active, .ra-step-leave-active { transition: opacity 0.2s ease, transform 0.25s cubic-bezier(0.22, 1, 0.36, 1); }
.ra-step-enter-from { opacity: 0; transform: translateY(10px); }
.ra-step-leave-to   { opacity: 0; transform: translateY(-6px); }
.ra-ring  { stroke-dasharray: 189; stroke-dashoffset: 189; animation: ra-draw 0.5s ease-out forwards; }
.ra-check { stroke-dasharray: 44;  stroke-dashoffset: 44;  animation: ra-draw 0.35s 0.4s ease-out forwards; }
@keyframes ra-draw { to { stroke-dashoffset: 0; } }
.ra-pop { animation: ra-pop 0.6s 0.55s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes ra-pop { from { transform: scale(0.6); opacity: 0; } to { transform: scale(1); opacity: 1; } }
@media (prefers-reduced-motion: reduce) {
  .ra-pop { animation: none; }
  .ra-ring, .ra-check { animation: none; stroke-dashoffset: 0; }
  .ra-panel-enter-active, .ra-step-enter-active, .ra-step-leave-active { transition: none; }
}
</style>
