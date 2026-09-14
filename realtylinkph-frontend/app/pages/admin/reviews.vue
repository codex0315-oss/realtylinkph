<script setup lang="ts">
import type { AgentReview, PaginatedResponse } from '~/types'

definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()
if (!authStore.isAdmin) {
  throw createError({ statusCode: 403, statusMessage: 'Forbidden' })
}

const api = useApi()
const { toggleVisibility } = useReview()

const allReviews = ref<AgentReview[]>([])
const fetching   = ref(false)

async function load() {
  fetching.value = true
  try {
    const res = await api.get<PaginatedResponse<AgentReview>>('/admin/reviews')
    allReviews.value = res.data
  } finally {
    fetching.value = false
  }
}

async function toggle(id: number) {
  const ok = await toggleVisibility(id)
  if (ok) await load()
}

await load()
</script>

<template>
  <div>
    <h1 class="font-playfair text-2xl font-bold text-brand-navy mb-6">Reviews</h1>

    <div v-if="fetching" class="space-y-3">
      <AppSkeleton v-for="i in 4" :key="i" width="100%" height="72px" />
    </div>

    <div v-else-if="!allReviews.length" class="text-center py-12 card">
      <p class="text-brand-text-secondary">No reviews yet.</p>
    </div>

    <div v-else class="space-y-3">
      <div v-for="review in allReviews" :key="review.id" class="card p-4 flex items-start gap-4">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm font-semibold text-brand-text-primary">{{ review.buyer?.name ?? 'Unknown' }}</span>
            <span class="text-xs text-brand-text-secondary">→ {{ review.agent?.name ?? 'Unknown' }}</span>
            <AppRating :value="review.rating" size="sm" readonly />
            <AppBadge :variant="review.is_visible ? 'success' : 'error'">
              {{ review.is_visible ? 'Visible' : 'Hidden' }}
            </AppBadge>
          </div>
          <p v-if="review.review_text" class="text-sm text-brand-text-secondary mt-1">{{ review.review_text }}</p>
        </div>
        <AppButton
          :variant="review.is_visible ? 'danger' : 'primary'"
          size="sm"
          @click="toggle(review.id)"
        >
          {{ review.is_visible ? 'Hide' : 'Show' }}
        </AppButton>
      </div>
    </div>
  </div>
</template>
