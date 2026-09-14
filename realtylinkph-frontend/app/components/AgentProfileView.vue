<script setup lang="ts">
import type { Appointment } from '~/types'

// Shared agent profile body — rendered by the public /agents/[id] page (default
// layout) AND the in-account /dashboard/agents/[id] page (dashboard layout), so
// a logged-in buyer stays inside their account chrome.
const route   = useRoute()
const agentId = Number(route.params.id)
const authStore = useAuthStore()

const { profile, loading: agentLoading, fetchAgent } = useAgent()
const { properties, loading: propLoading, fetchProperties } = useProperty()
const { reviews, averageRating, loading: revLoading, error: reviewError, fetchAgentReviews, submitReview, fetchReviewableAppointments } = useReview()

const reviewable = ref<Appointment[]>([])
async function loadReviewable() {
  reviewable.value = authStore.isAuthenticated && authStore.isBuyer
    ? await fetchReviewableAppointments(agentId)
    : []
}

await Promise.all([
  fetchAgent(agentId),
  fetchProperties({ agent_id: agentId, page: 1 }),
  fetchAgentReviews(agentId),
  loadReviewable(),
])

const activeTab = ref<'listings' | 'reviews'>('listings')

// The auth session restores on the client, so re-check eligibility after mount.
onMounted(loadReviewable)

// ── Rating ───────────────────────────────────────────────────────────────────
const canRate    = computed(() => reviewable.value.length > 0)
const showRate   = ref(false)
const submitting = ref(false)
const rateError  = ref('')
const rateForm   = reactive({ appointment_id: null as number | null, rating: 0, review_text: '', tags: [] as string[] })

const STANDOUT_TAGS = ['On time', 'Knowledgeable', 'Honest', 'Responsive', 'Friendly']
function toggleTag(t: string) {
  const i = rateForm.tags.indexOf(t)
  if (i >= 0) rateForm.tags.splice(i, 1)
  else rateForm.tags.push(t)
}

function apptLabel(a: Appointment): string {
  const d = new Date(a.preferred_datetime).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
  return `${a.property?.title ?? 'Viewing'} — ${d}`
}

function openRate() {
  rateForm.appointment_id = reviewable.value[0]?.id ?? null
  rateForm.rating         = 0
  rateForm.review_text    = ''
  rateForm.tags           = []
  rateError.value         = ''
  showRate.value          = true
}

async function submitRating() {
  if (!rateForm.appointment_id) { rateError.value = 'Please choose which viewing to review.'; return }
  if (!rateForm.rating)         { rateError.value = 'Please tap a star rating.'; return }

  submitting.value = true
  rateError.value  = ''
  const tagLine = rateForm.tags.length ? `👍 ${rateForm.tags.join(' · ')}` : ''
  const body    = [tagLine, rateForm.review_text.trim()].filter(Boolean).join('\n')
  const res = await submitReview({
    appointment_id: rateForm.appointment_id,
    rating:         rateForm.rating,
    review_text:    body || undefined,
  })
  submitting.value = false

  if (res) {
    showRate.value = false
    await Promise.all([fetchAgentReviews(agentId), loadReviewable()])
    activeTab.value = 'reviews'
  } else {
    rateError.value = reviewError.value || 'Could not submit your review. Please try again.'
  }
}

/**
 * Go back to wherever they came from. A shared link opens with no history to
 * return to, so fall back to Browse — the right landing spot for both a
 * signed-in buyer (dashboard browse) and a guest (public listings).
 */
const router = useRouter()
function goBack() {
  if (import.meta.client && window.history.length > 1) router.back()
  else router.push(authStore.isAuthenticated ? '/dashboard/browse' : '/properties')
}
</script>

<template>
  <div class="max-w-content mx-auto px-4 py-8">
    <!--
      Back link. On a phone this page has no other way out: the dashboard
      sidebar is behind the hamburger and the public header is a logo, so a
      buyer who tapped through from a listing was stranded on browser-back.
      Uses history when there is one, and falls back to Browse otherwise
      (e.g. the page was opened from a shared link).
    -->
    <button
      type="button"
      class="group inline-flex items-center gap-1.5 mb-5 -ml-1 px-2 py-1.5 rounded-lg text-sm font-semibold
             text-brand-navy/70 hover:text-brand-navy hover:bg-brand-navy/5
             dark:text-white/70 dark:hover:text-white dark:hover:bg-white/10 transition-colors"
      @click="goBack"
    >
      <svg class="h-4 w-4 transition-transform group-hover:-translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
      </svg>
      Back
    </button>

    <div v-if="agentLoading" class="space-y-4">
      <AppSkeleton width="100%" height="120px" />
      <AppSkeleton width="40%" height="24px" />
    </div>

    <template v-else-if="profile?.id">
      <!-- Agent header -->
      <div class="card p-6 flex flex-col sm:flex-row items-start gap-5 dark:bg-[#10264D] dark:border-white/10">
        <AppAvatar :name="profile.name" :src="profile.avatar" size="xl" />

        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-3 flex-wrap">
            <h1 class="font-playfair text-2xl font-bold text-brand-navy dark:text-white">{{ profile.name }}</h1>
            <AppBadge v-if="profile.agent_profile?.status === 'approved'" variant="success">Verified Agent</AppBadge>
          </div>

          <div class="flex items-center gap-4 mt-2 text-sm text-brand-text-secondary dark:text-white/60 flex-wrap">
            <span class="flex items-center gap-1">
              <AppRating :value="averageRating" size="sm" readonly />
              {{ averageRating }} ({{ reviews.length }} reviews)
            </span>
            <span v-if="profile.agent_profile?.prc_number">PRC: {{ profile.agent_profile.prc_number }}</span>
          </div>
        </div>

        <!-- Rate this agent -->
        <AppButton v-if="canRate" variant="primary" @click="openRate">★ Rate this agent</AppButton>
      </div>

      <!-- Tabs -->
      <div class="flex gap-1 mt-6 border-b border-brand-silver dark:border-white/10">
        <button
          class="px-5 py-3 text-sm font-medium transition-colors border-b-2 -mb-px"
          :class="activeTab === 'listings' ? 'border-brand-navy text-brand-navy dark:border-brand-gold dark:text-white' : 'border-transparent text-brand-text-secondary hover:text-brand-navy dark:text-white/50 dark:hover:text-white'"
          @click="activeTab = 'listings'"
        >
          Listings ({{ properties.length }})
        </button>
        <button
          class="px-5 py-3 text-sm font-medium transition-colors border-b-2 -mb-px"
          :class="activeTab === 'reviews' ? 'border-brand-navy text-brand-navy dark:border-brand-gold dark:text-white' : 'border-transparent text-brand-text-secondary hover:text-brand-navy dark:text-white/50 dark:hover:text-white'"
          @click="activeTab = 'reviews'"
        >
          Reviews ({{ reviews.length }})
        </button>
      </div>

      <!-- Listings tab -->
      <div v-if="activeTab === 'listings'" class="mt-6">
        <PropertyGrid :properties="properties" :loading="propLoading" :skeleton-count="4" />
        <p v-if="!propLoading && !properties.length" class="text-center text-brand-text-secondary dark:text-white/50 py-8">
          No active listings yet.
        </p>
      </div>

      <!-- Reviews tab -->
      <div v-else class="mt-6 space-y-4">
        <!-- Rate prompt / eligibility hint -->
        <div v-if="canRate" class="card p-4 flex items-center justify-between gap-3 bg-brand-gold/5 border-brand-gold/30 dark:bg-brand-gold/10">
          <p class="text-sm text-brand-navy dark:text-white font-medium">You had a viewing with this agent — share your experience.</p>
          <AppButton variant="primary" size="sm" @click="openRate">★ Rate this agent</AppButton>
        </div>
        <p v-else-if="authStore.isBuyer" class="text-xs text-brand-text-light dark:text-white/40">
          You can rate this agent after a confirmed viewing with them.
        </p>

        <div v-if="revLoading">
          <AppSkeleton v-for="i in 3" :key="i" width="100%" height="80px" class="mb-3" />
        </div>

        <p v-else-if="!reviews.length" class="text-center text-brand-text-secondary dark:text-white/50 py-8">
          No reviews yet.
        </p>

        <div v-else>
          <div
            v-for="review in reviews"
            :key="review.id"
            class="card p-5 dark:bg-[#10264D] dark:border-white/10"
          >
            <div class="flex items-start gap-3">
              <AppAvatar :name="review.buyer?.name" :src="review.buyer?.avatar" size="sm" />
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-sm font-semibold text-brand-text-primary dark:text-white">{{ review.buyer?.name ?? 'Anonymous' }}</span>
                  <AppRating :value="review.rating" size="sm" readonly />
                </div>
                <p v-if="review.review_text" class="text-sm text-brand-text-secondary dark:text-white/70 mt-1">{{ review.review_text }}</p>
                <p class="text-xs text-brand-text-light dark:text-white/40 mt-1">
                  {{ new Date(review.created_at).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }) }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <div v-else class="text-center py-16 text-brand-text-secondary dark:text-white/50">Agent not found.</div>

    <!-- Rate modal -->
    <AppModal :open="showRate" title="Rate this agent" @close="showRate = false">
      <div class="p-6 space-y-4">
        <!-- which viewing -->
        <div v-if="reviewable.length > 1">
          <label class="text-sm font-medium text-brand-text-primary">Which viewing?</label>
          <select v-model="rateForm.appointment_id" class="input-field mt-1">
            <option v-for="a in reviewable" :key="a.id" :value="a.id">{{ apptLabel(a) }}</option>
          </select>
        </div>
        <p v-else-if="reviewable.length === 1" class="text-sm text-brand-text-secondary">
          Reviewing your viewing: <span class="font-medium text-brand-navy">{{ apptLabel(reviewable[0]!) }}</span>
        </p>

        <!-- stars -->
        <div>
          <label class="text-sm font-medium text-brand-text-primary block mb-1">Your rating <span class="text-red-500">*</span></label>
          <AppRating :value="rateForm.rating" :readonly="false" size="lg" @update:value="rateForm.rating = $event" />
        </div>

        <!-- what stood out chips -->
        <div>
          <label class="text-sm font-medium text-brand-text-primary block mb-1.5">What stood out?</label>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="t in STANDOUT_TAGS"
              :key="t"
              type="button"
              class="text-xs font-semibold px-3 py-1.5 rounded-full border transition-colors"
              :class="rateForm.tags.includes(t)
                ? 'bg-brand-gold/15 border-brand-gold text-brand-navy'
                : 'border-gray-200 text-gray-500 hover:border-brand-gold/50'"
              @click="toggleTag(t)"
            >{{ t }}</button>
          </div>
        </div>

        <!-- text -->
        <div>
          <label class="text-sm font-medium text-brand-text-primary">Review (optional)</label>
          <textarea
            v-model="rateForm.review_text"
            rows="4"
            maxlength="2000"
            placeholder="How was your experience with this agent?"
            class="input-field mt-1 resize-none"
          />
        </div>

        <p v-if="rateError" class="text-sm text-red-500">{{ rateError }}</p>

        <div class="flex gap-3">
          <AppButton variant="ghost" full-width @click="showRate = false">Cancel</AppButton>
          <AppButton variant="primary" full-width :disabled="submitting" @click="submitRating">
            {{ submitting ? 'Submitting…' : 'Submit rating' }}
          </AppButton>
        </div>
      </div>
    </AppModal>
  </div>
</template>
