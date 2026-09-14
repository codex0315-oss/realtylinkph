<script setup lang="ts">
definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()
const { reviews, averageRating, loading, fetchAgentReviews } = useReview()

if (authStore.user) await fetchAgentReviews(authStore.user.id)

// Rating distribution 5★ → 1★
const distribution = computed(() => {
  const counts = [0, 0, 0, 0, 0] // index 0 = 1★ … index 4 = 5★
  reviews.value.forEach(r => { if (r.rating >= 1 && r.rating <= 5) counts[r.rating - 1]++ })
  return [5, 4, 3, 2, 1].map(star => ({
    star,
    count: counts[star - 1] ?? 0,
    pct: reviews.value.length ? Math.round(((counts[star - 1] ?? 0) / reviews.value.length) * 100) : 0,
  }))
})

function fmt(d: string) {
  return new Date(d).toLocaleDateString('en-PH', { dateStyle: 'medium' })
}
</script>

<template>
  <div class="max-w-4xl">
    <!-- Header — same shape as the other dashboard pages -->
    <header class="mb-8">
      <h1 class="font-display text-2xl sm:text-[28px] font-bold text-brand-navy dark:text-white leading-tight">
        Reviews & Ratings
      </h1>
      <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/50">
        Feedback from buyers you've met for viewings.
      </p>
    </header>

    <div v-if="loading" class="space-y-3">
      <AppSkeleton width="100%" height="140px" />
      <AppSkeleton v-for="i in 3" :key="i" width="100%" height="90px" />
    </div>

    <!-- Empty — sits directly on the page, no card, matching My Listings. The
         empty state used to live *inside* the summary card, so the card drew
         itself with nothing to summarise. -->
    <div v-else-if="!reviews.length" class="text-center py-24">
      <div class="h-14 w-14 rounded-2xl bg-brand-gold/10 dark:bg-brand-gold/15 flex items-center justify-center mx-auto mb-5">
        <svg class="h-7 w-7 text-brand-gold" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" /></svg>
      </div>
      <p class="text-lg font-bold text-brand-navy dark:text-white">No reviews yet</p>
      <p class="mx-auto mt-2 max-w-sm text-sm text-brand-text-secondary dark:text-white/50 leading-relaxed">
        Buyers can leave a review after a completed viewing. They'll show up here.
      </p>
    </div>

    <template v-else>
      <!-- Summary — only rendered when there is something to summarise -->
      <div class="rounded-2xl border border-gray-200 dark:border-white/[0.08] bg-white dark:bg-[#0B1A35] p-6 mb-5">
        <div class="flex flex-col sm:flex-row gap-6 sm:items-center">
          <div class="text-center sm:border-r sm:border-gray-100 dark:sm:border-white/[0.08] sm:pr-8">
            <p class="text-5xl font-bold text-brand-navy dark:text-white leading-none tabular-nums">{{ averageRating.toFixed(1) }}</p>
            <div class="flex items-center justify-center gap-0.5 mt-2">
              <svg v-for="s in 5" :key="s" class="h-4 w-4" :class="s <= Math.round(averageRating) ? 'text-brand-gold' : 'text-gray-200 dark:text-white/15'" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" /></svg>
            </div>
            <p class="text-xs text-brand-navy/45 dark:text-white/40 mt-1.5">{{ reviews.length }} review{{ reviews.length === 1 ? '' : 's' }}</p>
          </div>

          <div class="flex-1 space-y-1.5">
            <div v-for="row in distribution" :key="row.star" class="flex items-center gap-3">
              <span class="text-xs text-brand-navy/55 dark:text-white/50 w-8 flex-shrink-0">{{ row.star }} ★</span>
              <div class="flex-1 h-2 rounded-full bg-gray-100 dark:bg-white/[0.08] overflow-hidden">
                <div class="h-full bg-brand-gold rounded-full transition-all" :style="{ width: row.pct + '%' }" />
              </div>
              <span class="text-xs text-brand-navy/45 dark:text-white/40 w-6 text-right flex-shrink-0 tabular-nums">{{ row.count }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Review list -->
      <div class="space-y-3">
        <div v-for="r in reviews" :key="r.id" class="rounded-2xl border border-gray-200 dark:border-white/[0.08] bg-white dark:bg-[#0B1A35] p-5">
          <div class="flex items-start justify-between gap-3">
            <div class="flex items-center gap-3">
              <AppAvatar :name="r.buyer?.name" :src="r.buyer?.avatar" size="sm" />
              <div>
                <p class="text-sm font-semibold text-brand-navy dark:text-white">{{ r.buyer?.name ?? 'Buyer' }}</p>
                <div class="flex items-center gap-0.5 mt-0.5">
                  <svg v-for="s in 5" :key="s" class="h-3.5 w-3.5" :class="s <= r.rating ? 'text-brand-gold' : 'text-gray-200 dark:text-white/15'" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" /></svg>
                </div>
              </div>
            </div>
            <span class="text-[11px] text-brand-navy/45 dark:text-white/40 flex-shrink-0">{{ fmt(r.created_at) }}</span>
          </div>
          <p v-if="r.review_text" class="text-sm text-brand-navy/80 dark:text-white/75 mt-3 leading-relaxed whitespace-pre-wrap">{{ r.review_text }}</p>
        </div>
      </div>
    </template>
  </div>
</template>
