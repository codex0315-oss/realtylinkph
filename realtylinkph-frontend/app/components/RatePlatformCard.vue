<script setup lang="ts">
import type { ApiResponse } from '~/types'

/**
 * "Rate RealtyLink PH": 1–5 stars and an optional comment, one per person.
 * Shows their current rating with Edit once they've given one.
 */
const api = useApi()
const toast = useToast()

interface Mine { rating: number; comment: string | null; updated_at: string }
const mine = ref<Mine | null>(null)
const editing = ref(false)
const rating = ref(0)
const hover = ref(0)
const comment = ref('')
const saving = ref(false)

const WORDS = ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent']
const shown = computed(() => hover.value || rating.value)

onMounted(async () => {
  try { mine.value = (await api.get<ApiResponse<Mine | null>>('/platform-rating')).data } catch { /* card still works */ }
  editing.value = !mine.value
})

function edit() {
  rating.value = mine.value?.rating ?? 0
  comment.value = mine.value?.comment ?? ''
  editing.value = true
}

async function save() {
  if (!rating.value || saving.value) return
  saving.value = true
  try {
    mine.value = (await api.put<ApiResponse<Mine>>('/platform-rating', { rating: rating.value, comment: comment.value.trim() || null })).data
    editing.value = false
    toast.success('Thanks for rating RealtyLink PH!')
  } catch {
    toast.error('Couldn’t save your rating. Please try again.')
  } finally {
    saving.value = false
  }
}

const STAR = 'M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z'
</script>

<template>
  <!-- A navy outline in light mode; dark mode keeps the soft standard edge -->
  <section class="rounded-3xl p-5 bg-white dark:bg-[#0B1A35] border-2 border-brand-navy/80 dark:border dark:border-white/[0.08] shadow-[0_14px_30px_-24px_rgba(11,42,92,0.5)]">
    <p class="text-sm font-bold text-brand-navy dark:text-white">Rate RealtyLink PH</p>

    <!-- Already rated -->
    <template v-if="mine && !editing">
      <div class="mt-2 flex items-center gap-2">
        <span class="flex gap-0.5" :aria-label="`You rated ${mine.rating} out of 5`">
          <svg v-for="n in 5" :key="n" class="h-5 w-5" :class="n <= mine.rating ? 'text-brand-gold' : 'text-brand-navy/15 dark:text-white/15'" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
        </span>
        <span class="text-sm font-semibold text-brand-navy dark:text-white">{{ WORDS[mine.rating] }}</span>
      </div>
      <p v-if="mine.comment" class="mt-2 text-sm text-brand-navy/70 dark:text-white/65">“{{ mine.comment }}”</p>
      <div class="mt-3 flex items-center justify-between gap-2">
        <span class="text-xs text-brand-navy/45 dark:text-white/40">Thanks for your rating!</span>
        <button type="button" class="text-xs font-semibold text-brand-gold-deep hover:text-brand-navy dark:text-brand-gold dark:hover:text-white" @click="edit">Edit</button>
      </div>
    </template>

    <!-- Rate / edit -->
    <form v-else class="mt-1" @submit.prevent="save">
      <p class="text-xs text-brand-navy/55 dark:text-white/50">How is RealtyLink PH working for you?</p>
      <div class="mt-3 flex items-center gap-2">
        <div class="flex gap-1" role="radiogroup" aria-label="Your rating" @mouseleave="hover = 0">
          <button
            v-for="n in 5"
            :key="n"
            type="button"
            role="radio"
            :aria-checked="rating === n"
            :aria-label="`${n} star${n === 1 ? '' : 's'}, ${WORDS[n]}`"
            class="p-0.5 transition-transform hover:scale-110 active:scale-90"
            @mouseenter="hover = n"
            @click="rating = n"
          >
            <svg class="h-7 w-7 transition-colors" :class="n <= shown ? 'text-brand-gold' : 'text-brand-navy/15 dark:text-white/15'" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
          </button>
        </div>
        <span class="text-sm font-semibold text-brand-navy dark:text-white">{{ WORDS[shown] }}</span>
      </div>
      <label for="rp-comment" class="sr-only">Comment (optional)</label>
      <textarea
        id="rp-comment"
        v-model="comment"
        rows="3"
        maxlength="1000"
        placeholder="Anything you'd like to tell us? (optional)"
        class="mt-3 w-full resize-none rounded-xl border-0 bg-brand-navy/[0.04] dark:bg-white/[0.06] px-3 py-2 text-sm text-brand-navy dark:text-white placeholder-brand-navy/40 dark:placeholder-white/35 ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold"
      />
      <div class="mt-2 flex items-center justify-end gap-2">
        <button v-if="mine" type="button" class="rounded-full px-3 py-1.5 text-xs font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20" @click="editing = false">Cancel</button>
        <button type="submit" class="rounded-full bg-brand-gold px-4 py-1.5 text-xs font-bold text-[#0B2A5C] shadow-[0_4px_14px_rgba(212,165,74,0.4)] hover:bg-brand-gold-light disabled:opacity-50 disabled:shadow-none" :disabled="!rating || saving">
          {{ saving ? 'Saving…' : mine ? 'Update rating' : 'Submit rating' }}
        </button>
      </div>
    </form>
  </section>
</template>
