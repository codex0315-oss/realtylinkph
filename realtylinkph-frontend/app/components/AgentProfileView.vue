<script setup lang="ts">
import type { Appointment, ReviewEligibility } from '~/types'

// Shared agent profile body — rendered by the public /agents/[id] page (default
// layout) AND the in-account /dashboard/agents/[id] page (dashboard layout), so
// a logged-in buyer stays inside their account chrome.
const route   = useRoute()
const agentId = Number(route.params.id)
const authStore = useAuthStore()

const { profile, loading: agentLoading, fetchAgent } = useAgent()
const { properties, loading: propLoading, fetchProperties } = useProperty()
const { reviews, averageRating, loading: revLoading, error: reviewError, fetchAgentReviews, submitReview, updateReview, fetchEligibility } = useReview()

/*
 * Who may rate: a buyer who actually dealt with this agent — a viewing that
 * took place (verified), a viewing the agent cancelled / never answered, or
 * a chat the agent replied in. The server decides; we only show the reason.
 */
const NONE: ReviewEligibility = { eligible: false, basis: null, verified: false, appointments: [], conversation_id: null, review: null }
const eligibility = ref<ReviewEligibility>(NONE)
const reviewable  = computed(() => eligibility.value.appointments)
async function loadReviewable() {
  eligibility.value = authStore.isAuthenticated && authStore.isBuyer
    ? await fetchEligibility(agentId)
    : NONE
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
const canRate    = computed(() => eligibility.value.eligible)
const myReview   = computed(() => eligibility.value.review)
const agentFirst = computed(() => (profile.value?.name ?? 'this agent').split(' ')[0])
/** Why the buyer may rate — shown in the prompt and the modal. */
const basisText = computed(() => {
  const e = eligibility.value
  const a = e.appointments[0]
  const when = a ? new Date(a.preferred_datetime).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) : ''
  switch (e.basis) {
    case 'viewing':         return `You viewed ${a?.property?.title ?? 'a property'} with ${agentFirst.value} on ${when}.`
    case 'agent_cancelled': return `Your viewing on ${when} was cancelled or never confirmed by ${agentFirst.value} — you can rate that experience.`
    case 'conversation':    return `You've chatted with ${agentFirst.value}. Rate how they handled your enquiry.`
    default:                return ''
  }
})
const showRate   = ref(false)
const submitting = ref(false)
const rateError  = ref('')
const rateForm   = reactive({ appointment_id: null as number | null, rating: 0, review_text: '', tags: [] as string[] })

const STANDOUT_TAGS = ['On time', 'Knowledgeable', 'Honest', 'Responsive', 'Friendly', 'Patient']
const RATING_WORDS = ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent']
/* The rate window walks through form → sending → sent (or error, text kept). */
const rateStep  = ref<'form' | 'sending' | 'sent' | 'error'>('form')
const rateHover = ref(0)
const rateWasEdit = ref(false)
watch(showRate, (on) => {
  if (import.meta.client) document.documentElement.style.overflow = on ? 'hidden' : ''
})
onUnmounted(() => { if (import.meta.client) document.documentElement.style.overflow = '' })
function closeRate() { if (rateStep.value !== 'sending') showRate.value = false }
function onRateKey(e: KeyboardEvent) { if (e.key === 'Escape' && showRate.value) closeRate() }
onMounted(() => document.addEventListener('keydown', onRateKey))
onUnmounted(() => document.removeEventListener('keydown', onRateKey))
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
  const mine = myReview.value
  // An existing review is re-opened for editing, with its tag line split back out.
  const firstLine = mine?.review_text?.split('\n')[0] ?? ''
  const hasTags   = firstLine.startsWith('👍 ')
  rateForm.appointment_id = reviewable.value[0]?.id ?? null
  rateForm.rating         = mine?.rating ?? 0
  rateForm.review_text    = mine ? (hasTags ? mine.review_text!.split('\n').slice(1).join('\n') : mine.review_text ?? '') : ''
  // '👍' is two UTF-16 units, so slice past it and trim the space that follows.
  rateForm.tags           = hasTags ? firstLine.slice(2).split('·').map(t => t.trim()).filter(t => STANDOUT_TAGS.includes(t)) : []
  rateError.value         = ''
  rateStep.value          = 'form'
  rateHover.value         = 0
  showRate.value          = true
}

async function submitRating() {
  if (!rateForm.rating) { rateError.value = 'Please tap a star rating.'; return }

  submitting.value = true
  rateError.value  = ''
  rateStep.value   = 'sending'
  rateWasEdit.value = !!myReview.value
  const started    = Date.now()
  const tagLine = rateForm.tags.length ? `👍 ${rateForm.tags.join(' · ')}` : ''
  const body    = [tagLine, rateForm.review_text.trim()].filter(Boolean).join('\n')
  const res = myReview.value
    ? await updateReview(myReview.value.id, { rating: rateForm.rating, review_text: body || undefined })
    : await submitReview({
        agent_id:       agentId,
        appointment_id: rateForm.appointment_id ?? undefined,
        rating:         rateForm.rating,
        review_text:    body || undefined,
      })
  const wait = 700 - (Date.now() - started)
  if (wait > 0) await new Promise(r => setTimeout(r, wait))
  submitting.value = false

  if (res) {
    rateStep.value = 'sent'
    await Promise.all([fetchAgentReviews(agentId), loadReviewable()])
    activeTab.value = 'reviews'
  } else {
    rateError.value = reviewError.value || 'Could not submit your review. Please try again.'
    rateStep.value = 'error'
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

// ── Header facts ─────────────────────────────────────────────────────────────
const { open: openAuth } = useAuthModal()
const toast = useToast()

const isVerified = computed(() => profile.value?.agent_profile?.status === 'approved')
const roleLabel = computed(() =>
  profile.value?.agent_profile?.applicant_type === 'broker' ? 'Licensed Real Estate Broker' : 'Real Estate Salesperson',
)
const verifiedSince = computed(() => {
  const d = profile.value?.agent_profile?.reviewed_at
  return d ? new Date(d).toLocaleDateString('en-PH', { month: 'short', year: 'numeric' }) : ''
})
// Server counts first (they cover every page); the loaded lists as a fallback.
const listingTotal = computed(() => profile.value?.listing_count ?? properties.value.length)
const reviewTotal  = computed(() => profile.value?.review_count ?? reviews.value.length)
const rating       = computed(() => Number(profile.value?.average_rating ?? averageRating.value) || 0)

const initials = computed(() => (profile.value?.name ?? '?').split(/\s+/).map(s => s[0] ?? '').join('').slice(0, 2).toUpperCase())

/* Buyers message in-app; guests are asked to sign in; agents/admins see neither. */
const canMessage = computed(() => authStore.isAuthenticated && authStore.isBuyer)
const showSignInToMessage = computed(() => !authStore.isAuthenticated)

async function shareProfile() {
  const url = `${window.location.origin}/agents/${agentId}`
  try {
    if (navigator.share) await navigator.share({ title: profile.value?.name ?? 'Agent', url })
    else { await navigator.clipboard.writeText(url); toast.success('Profile link copied') }
  } catch { /* share sheet dismissed */ }
}

// ── Reviews: tags, distribution, dates ───────────────────────────────────────
/** A review's first line may be "👍 On time · Honest" from the rating form. */
function splitReview(text: string | null | undefined): { tags: string[]; body: string } {
  if (!text) return { tags: [], body: '' }
  const [first = '', ...rest] = text.split('\n')
  if (!first.startsWith('👍')) return { tags: [], body: text }
  return {
    tags: first.replace(/^👍\s*/u, '').split('·').map(s => s.trim()).filter(Boolean),
    body: rest.join('\n').trim(),
  }
}

/* "Known for" — the standout tags buyers picked most often. */
const knownFor = computed(() => {
  const counts = new Map<string, number>()
  for (const r of reviews.value) for (const t of splitReview(r.review_text).tags) counts.set(t, (counts.get(t) ?? 0) + 1)
  return [...counts.entries()].sort((a, b) => b[1] - a[1]).slice(0, 4)
})

const distribution = computed(() => {
  const n = reviews.value.length
  return [5, 4, 3, 2, 1].map((stars) => {
    const count = reviews.value.filter(r => r.rating === stars).length
    return { stars, count, pct: n ? Math.round((count / n) * 100) : 0 }
  })
})

function ago(iso: string): string {
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000)
  if (days < 1) return 'Today'
  if (days === 1) return 'Yesterday'
  if (days < 30) return `${days} days ago`
  if (days < 365) return `${Math.floor(days / 30)} mo ago`
  return new Date(iso).toLocaleDateString('en-PH', { month: 'short', year: 'numeric' })
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
      class="prof-rise group inline-flex items-center gap-2 text-sm font-semibold text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white transition-colors"
      @click="goBack"
    >
      <span class="h-8 w-8 rounded-full flex items-center justify-center ring-1 ring-brand-navy/10 dark:ring-white/15 bg-white/70 dark:bg-white/[0.05] group-hover:-translate-x-0.5 transition-transform">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
      </span>
      Back
    </button>

    <div v-if="agentLoading" class="mt-5 space-y-4">
      <AppSkeleton width="100%" height="260px" rounded="lg" />
      <AppSkeleton width="40%" height="24px" />
    </div>

    <template v-else-if="profile?.id">
      <!-- ░░ Profile header ░░ -->
      <section class="prof-card prof-rise mt-5 !p-0 overflow-hidden" style="animation-delay: 60ms">
        <!-- Cover: the agent's photo, or the default navy banner -->
        <AgentCover
          :src="profile.cover_url"
          :position="profile.cover_position ?? 50"
          :class="profile.cover_url ? 'h-44 sm:h-60 lg:h-72' : 'h-32 sm:h-40'"
        >
          <button
            type="button"
            class="absolute top-4 right-4 inline-flex items-center gap-1.5 rounded-full bg-black/35 px-3.5 py-2 text-xs font-semibold text-white ring-1 ring-white/25 backdrop-blur-md hover:bg-black/50 transition"
            @click="shareProfile"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z" /></svg>
            Share
          </button>
        </AgentCover>

        <div class="px-6 sm:px-8 pb-7">
          <div class="flex flex-col sm:flex-row sm:items-end gap-4 sm:gap-6">
            <!-- Avatar, overlapping the cover -->
            <div class="relative -mt-14 sm:-mt-16 w-max">
              <img
                v-if="profile.avatar"
                :src="profile.avatar"
                :alt="profile.name"
                class="h-28 w-28 sm:h-32 sm:w-32 rounded-full object-cover ring-[5px] ring-white dark:ring-[#0d2550] shadow-xl"
              />
              <span
                v-else
                class="h-28 w-28 sm:h-32 sm:w-32 rounded-full ring-[5px] ring-white dark:ring-[#0d2550] shadow-xl bg-gold-metal flex items-center justify-center font-heading text-4xl font-semibold text-brand-navy"
                aria-hidden="true"
              >{{ initials }}</span>
              <span v-if="isVerified" class="absolute bottom-1 right-1 rounded-full bg-white dark:bg-[#0d2550] p-0.5 shadow-md">
                <AppVerifiedTick size="lg" />
              </span>
            </div>

            <div class="min-w-0 flex-1 sm:pb-1">
              <h1 class="font-heading text-3xl sm:text-4xl font-semibold tracking-[-0.02em] text-brand-navy dark:text-white leading-tight">{{ profile.name }}</h1>
              <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-brand-text-secondary dark:text-white/60">
                <span>{{ roleLabel }}</span>
                <span v-if="profile.agent_profile?.prc_number" class="inline-flex items-center gap-1">
                  <span class="text-brand-navy/25 dark:text-white/25">·</span>
                  PRC No. <span class="font-semibold text-brand-navy dark:text-white tabular-nums">{{ profile.agent_profile.prc_number }}</span>
                </span>
              </p>
              <span
                v-if="isVerified"
                class="mt-3 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold bg-brand-gold/15 text-brand-gold-deep ring-1 ring-brand-gold/40 dark:text-brand-gold"
              >
                <AppVerifiedTick size="sm" label="" />
                PRC-verified agent<template v-if="verifiedSince"> · since {{ verifiedSince }}</template>
              </span>
            </div>

            <!-- Actions -->
            <div class="flex flex-wrap gap-2.5 sm:pb-1">
              <NuxtLink
                v-if="canMessage"
                :to="`/dashboard/messages?agent=${profile.id}`"
                class="inline-flex items-center gap-2 rounded-full bg-brand-gold px-5 py-2.5 text-sm font-bold text-brand-navy shadow-[0_6px_18px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light hover:-translate-y-0.5 transition"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                Message
              </NuxtLink>
              <button
                v-else-if="showSignInToMessage"
                type="button"
                class="inline-flex items-center gap-2 rounded-full bg-brand-gold px-5 py-2.5 text-sm font-bold text-brand-navy shadow-[0_6px_18px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light hover:-translate-y-0.5 transition"
                @click="openAuth('login')"
              >
                Sign in to message
              </button>
              <button
                v-if="canRate"
                type="button"
                class="inline-flex items-center gap-2 rounded-full px-5 py-2.5 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 dark:text-white dark:ring-white/20 hover:ring-brand-gold transition"
                @click="openRate"
              >
                {{ myReview ? '✎ Edit your review' : '★ Rate this agent' }}
              </button>
            </div>
          </div>

          <!-- Stats -->
          <dl class="mt-7 grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div v-spotlight class="prof-stat">
              <dt class="prof-stat-label">Rating</dt>
              <dd class="mt-1 flex items-baseline gap-1.5">
                <span class="font-heading text-3xl font-semibold text-brand-navy dark:text-white tabular-nums">{{ reviewTotal ? rating.toFixed(1) : '—' }}</span>
                <svg v-if="reviewTotal" class="h-5 w-5 text-brand-gold self-center" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" /></svg>
              </dd>
              <dd class="prof-stat-hint">{{ reviewTotal ? `${reviewTotal} ${reviewTotal === 1 ? 'review' : 'reviews'}` : 'No reviews yet' }}</dd>
            </div>
            <div v-spotlight class="prof-stat">
              <dt class="prof-stat-label">Listings</dt>
              <dd class="mt-1 font-heading text-3xl font-semibold text-brand-navy dark:text-white tabular-nums">{{ listingTotal }}</dd>
              <dd class="prof-stat-hint">Active right now</dd>
            </div>
            <!-- Viewing reliability: an agent is a business, so how reliably they
                 honour confirmed viewings is fair for a buyer to see. Shown only
                 once there are enough viewings to mean anything. -->
            <div v-spotlight class="prof-stat">
              <dt class="prof-stat-label">Viewings kept</dt>
              <template v-if="profile.reliability?.has_enough">
                <dd
                  class="mt-1 font-heading text-3xl font-semibold tabular-nums"
                  :class="(profile.reliability.rate ?? 100) >= 80 ? 'text-emerald-600 dark:text-emerald-300' : 'text-amber-600 dark:text-amber-300'"
                >{{ profile.reliability.rate ?? 100 }}%</dd>
                <dd class="prof-stat-hint">{{ profile.reliability.kept }} of {{ profile.reliability.kept + profile.reliability.missed }} confirmed</dd>
              </template>
              <template v-else>
                <dd class="mt-1 font-heading text-3xl font-semibold text-brand-navy/35 dark:text-white/35">—</dd>
                <dd class="prof-stat-hint">Not enough viewings yet</dd>
              </template>
            </div>
            <div v-spotlight class="prof-stat">
              <dt class="prof-stat-label">On RealtyLink</dt>
              <dd class="mt-1 font-heading text-3xl font-semibold text-brand-navy dark:text-white">{{ verifiedSince || '—' }}</dd>
              <dd class="prof-stat-hint">{{ isVerified ? 'Licence checked by our admins' : 'Verification pending' }}</dd>
            </div>
          </dl>

          <!-- Known for -->
          <div v-if="knownFor.length" class="mt-5 flex flex-wrap items-center gap-2">
            <span class="text-xs font-bold uppercase tracking-[0.14em] text-brand-navy/45 dark:text-white/40 mr-1">Buyers say</span>
            <span
              v-for="[tag, n] in knownFor"
              :key="tag"
              class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold bg-brand-navy/[0.05] text-brand-navy/80 dark:bg-white/[0.06] dark:text-white/80"
            >
              👍 {{ tag }} <span class="text-brand-navy/45 dark:text-white/45 tabular-nums">{{ n }}</span>
            </span>
          </div>
        </div>
      </section>

      <!-- ░░ Tabs ░░ -->
      <div class="prof-rise mt-8 flex justify-center sm:justify-start" style="animation-delay: 120ms">
        <div role="tablist" class="relative inline-flex rounded-full p-1 bg-white dark:bg-white/[0.06] ring-1 ring-brand-navy/10 dark:ring-white/10 shadow-sm">
          <!-- Sliding pill behind the active tab -->
          <span
            class="absolute top-1 bottom-1 left-1 w-[calc(50%-0.25rem)] rounded-full bg-brand-navy dark:bg-brand-gold transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
            :class="activeTab === 'reviews' ? 'translate-x-full' : 'translate-x-0'"
            aria-hidden="true"
          />
          <button
            v-for="t in (['listings', 'reviews'] as const)"
            :key="t"
            type="button"
            role="tab"
            :aria-selected="activeTab === t"
            class="relative z-10 w-36 sm:w-40 rounded-full py-2.5 text-sm font-semibold transition-colors duration-300"
            :class="activeTab === t ? 'text-white dark:text-brand-navy' : 'text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white'"
            @click="activeTab = t"
          >
            {{ t === 'listings' ? 'Listings' : 'Reviews' }}
            <span class="ml-1 tabular-nums opacity-70">{{ t === 'listings' ? listingTotal : reviewTotal }}</span>
          </button>
        </div>
      </div>

      <Transition name="tab-swap" mode="out-in">
        <!-- Listings -->
        <div v-if="activeTab === 'listings'" key="listings" class="mt-6">
          <PropertyGrid :properties="properties" :loading="propLoading" :skeleton-count="4" stagger />
          <div v-if="!propLoading && !properties.length" class="prof-card text-center py-14">
            <p class="font-heading text-2xl font-semibold text-brand-navy dark:text-white">No active listings yet</p>
            <p class="mt-2 text-sm text-brand-text-secondary dark:text-white/55">New properties from {{ agentFirst }} will show up here.</p>
          </div>
        </div>

        <!-- Reviews -->
        <div v-else key="reviews" class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
          <!-- Summary -->
          <aside class="prof-card lg:sticky lg:top-24">
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-navy/45 dark:text-white/40">Overall rating</p>
            <div class="mt-2 flex items-end gap-3">
              <span class="font-heading text-6xl font-semibold leading-none text-brand-navy dark:text-white tabular-nums">{{ reviews.length ? rating.toFixed(1) : '—' }}</span>
              <div class="pb-1">
                <AppRating :value="rating" size="sm" readonly />
                <p class="mt-0.5 text-xs text-brand-text-secondary dark:text-white/50">{{ reviewTotal }} {{ reviewTotal === 1 ? 'review' : 'reviews' }}</p>
              </div>
            </div>

            <ul class="mt-5 space-y-2">
              <li v-for="d in distribution" :key="d.stars" class="flex items-center gap-3 text-xs">
                <span class="w-6 font-semibold text-brand-navy/70 dark:text-white/65 tabular-nums">{{ d.stars }}★</span>
                <span class="flex-1 h-2 rounded-full bg-brand-navy/[0.07] dark:bg-white/10 overflow-hidden">
                  <span class="block h-full rounded-full bg-gold-metal prof-bar" :style="{ width: `${d.pct}%` }" />
                </span>
                <span class="w-6 text-right text-brand-navy/50 dark:text-white/45 tabular-nums">{{ d.count }}</span>
              </li>
            </ul>

            <!-- Rate prompt / eligibility hint -->
            <div v-if="canRate" class="mt-6 rounded-2xl p-4 bg-brand-gold/10 ring-1 ring-brand-gold/30">
              <p class="text-sm font-medium text-brand-navy dark:text-white">
                {{ myReview ? 'You reviewed this agent — you can update it any time.' : basisText }}
              </p>
              <button
                type="button"
                class="mt-3 w-full rounded-full bg-brand-gold px-4 py-2.5 text-sm font-bold text-brand-navy hover:bg-brand-gold-light transition"
                @click="openRate"
              >
                {{ myReview ? '✎ Edit your review' : '★ Rate this agent' }}
              </button>
            </div>
            <p v-else-if="authStore.isBuyer" class="mt-6 text-xs text-brand-text-secondary dark:text-white/45">
              You can rate this agent after a viewing with them, or once they've replied to you in chat.
            </p>
            <p v-else class="mt-6 text-xs text-brand-text-secondary dark:text-white/45">
              Only buyers who actually dealt with {{ agentFirst }} can leave a review.
            </p>
          </aside>

          <!-- List -->
          <div class="lg:col-span-2 space-y-4">
            <template v-if="revLoading">
              <AppSkeleton v-for="i in 3" :key="i" width="100%" height="110px" rounded="lg" />
            </template>

            <div v-else-if="!reviews.length" class="prof-card text-center py-14">
              <p class="font-heading text-2xl font-semibold text-brand-navy dark:text-white">No reviews yet</p>
              <p class="mt-2 text-sm text-brand-text-secondary dark:text-white/55">Be the first once you've viewed a property with {{ agentFirst }}.</p>
            </div>

            <template v-else>
              <article
                v-for="(review, i) in reviews"
                :key="review.id"
                class="prof-card prof-review"
                :style="{ animationDelay: `${Math.min(i, 6) * 60}ms` }"
              >
                <div class="flex items-start gap-4">
                  <AppAvatar :name="review.buyer?.name" :src="review.buyer?.avatar" size="md" />
                  <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                      <p class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-brand-navy dark:text-white">{{ review.buyer?.name ?? 'A RealtyLink buyer' }}</span>
                        <span
                          v-if="review.is_verified"
                          class="inline-flex items-center gap-1 text-[0.625rem] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
                          :title="review.property_title ? `Viewed ${review.property_title}` : 'Based on a viewing that took place'"
                        >
                          <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                          Verified viewing
                        </span>
                      </p>
                      <span class="text-xs text-brand-text-secondary dark:text-white/45">{{ ago(review.created_at) }}<span v-if="review.is_edited"> · edited</span></span>
                    </div>
                    <AppRating class="mt-1.5" :value="review.rating" size="sm" readonly />
                    <div v-if="splitReview(review.review_text).tags.length" class="mt-3 flex flex-wrap gap-1.5">
                      <span
                        v-for="t in splitReview(review.review_text).tags"
                        :key="t"
                        class="rounded-full px-2.5 py-0.5 text-[0.6875rem] font-semibold bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold"
                      >{{ t }}</span>
                    </div>
                    <p v-if="splitReview(review.review_text).body" class="mt-2.5 text-[0.9375rem] leading-relaxed text-brand-navy/80 dark:text-white/75 whitespace-pre-line">
                      {{ splitReview(review.review_text).body }}
                    </p>
                    <p v-if="review.property_title" class="mt-3 inline-flex items-center gap-1.5 text-xs text-brand-text-secondary dark:text-white/45">
                      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75" /></svg>
                      {{ review.property_title }}
                    </p>

                    <!-- The agent's reply -->
                    <div v-if="review.agent_reply" class="mt-4 flex gap-3 rounded-2xl bg-brand-navy/[0.03] dark:bg-white/[0.04] p-4 border-l-[3px] border-brand-gold">
                      <AppAvatar :name="profile.name" :src="profile.avatar" size="xs" class="flex-none" />
                      <div class="min-w-0">
                        <p class="text-xs font-bold text-brand-navy dark:text-white">Reply from {{ profile.name }} <span v-if="review.agent_replied_at" class="font-normal text-brand-navy/45 dark:text-white/40">· {{ ago(review.agent_replied_at) }}</span></p>
                        <p class="mt-1 text-sm leading-relaxed text-brand-navy/80 dark:text-white/75 whitespace-pre-line">{{ review.agent_reply }}</p>
                      </div>
                    </div>
                  </div>
                </div>
              </article>
            </template>
          </div>
        </div>
      </Transition>
    </template>

    <div v-else class="prof-card mt-6 text-center py-16">
      <p class="font-heading text-2xl font-semibold text-brand-navy dark:text-white">Agent not found</p>
      <p class="mt-2 text-sm text-brand-text-secondary dark:text-white/55">This profile may have been removed or isn't verified yet.</p>
    </div>

    <!-- Rate / edit review: form → sending → sent / error (same style as the
         History rate window and the other dialogs) -->
    <Teleport to="body">
      <Transition name="rv-backdrop">
        <div v-if="showRate && profile" class="fixed inset-0 z-[95] flex items-end sm:items-center justify-center sm:p-4 bg-brand-navy-deep/60 backdrop-blur-sm" @click.self="closeRate">
          <Transition name="rv-panel" appear>
            <div
              v-if="showRate"
              role="dialog"
              aria-modal="true"
              aria-labelledby="rv-title"
              class="relative w-full sm:max-w-lg max-h-[92vh] overflow-y-auto rounded-t-[1.75rem] sm:rounded-[1.75rem]
                     bg-white dark:bg-[#0d2550] ring-1 ring-brand-navy/[0.06] dark:ring-white/10 shadow-[0_40px_100px_-30px_rgba(6,24,58,0.6)]"
            >
              <button
                v-if="rateStep !== 'sending'"
                type="button"
                class="absolute top-4 right-4 z-10 h-9 w-9 rounded-full flex items-center justify-center text-brand-navy/60 hover:text-brand-navy hover:bg-brand-navy/5 dark:text-white/60 dark:hover:text-white dark:hover:bg-white/10 transition"
                aria-label="Close"
                @click="closeRate"
              >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
              </button>

              <Transition name="rv-step" mode="out-in">
                <!-- ░░ Form ░░ -->
                <div v-if="rateStep === 'form'" key="form" class="p-6 sm:p-7">
                  <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold-deep dark:text-brand-gold">{{ myReview ? 'Edit your review' : 'Rate this agent' }}</p>
                  <h2 id="rv-title" class="mt-1 pr-10 font-heading text-2xl font-semibold tracking-[-0.01em] text-brand-navy dark:text-white">
                    How was {{ agentFirst }}?
                  </h2>

                  <!-- Who -->
                  <div class="mt-5 flex items-center gap-3 rounded-2xl p-3 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.05] dark:ring-white/10">
                    <AppAvatar :name="profile.name" :src="profile.avatar" size="md" />
                    <div class="min-w-0 flex-1">
                      <p class="flex items-center gap-1.5 truncate text-sm font-semibold text-brand-navy dark:text-white">
                        {{ profile.name }} <AppVerifiedTick size="xs" />
                      </p>
                      <p v-if="reviewable.length <= 1 && basisText" class="text-xs text-brand-text-secondary dark:text-white/55 line-clamp-2">{{ basisText }}</p>
                    </div>
                  </div>

                  <!-- Which viewing (choice cards, not a native select) -->
                  <div v-if="reviewable.length > 1" class="mt-5">
                    <p class="mb-2 text-sm font-semibold text-brand-navy dark:text-white/90">Which viewing?</p>
                    <div class="space-y-2" role="radiogroup" aria-label="Which viewing">
                      <button
                        v-for="a in reviewable"
                        :key="a.id"
                        type="button"
                        role="radio"
                        :aria-checked="rateForm.appointment_id === a.id"
                        class="w-full flex items-center gap-3 rounded-2xl p-2 pr-3 text-left ring-1 transition"
                        :class="rateForm.appointment_id === a.id ? 'ring-2 ring-brand-gold bg-brand-gold/[0.08]' : 'ring-brand-navy/10 hover:ring-brand-gold dark:ring-white/10'"
                        @click="rateForm.appointment_id = a.id"
                      >
                        <img v-if="a.property?.photos?.[0]" :src="a.property.photos[0].thumb_url ?? a.property.photos[0].url" alt="" class="h-10 w-12 flex-none rounded-lg object-cover" />
                        <span class="min-w-0 flex-1">
                          <span class="block truncate text-sm font-semibold text-brand-navy dark:text-white">{{ a.property?.title ?? 'Viewing' }}</span>
                          <span class="block text-xs text-brand-navy/50 dark:text-white/45">{{ new Date(a.preferred_datetime).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' }) }}</span>
                        </span>
                        <span class="h-5 w-5 flex-none rounded-full flex items-center justify-center" :class="rateForm.appointment_id === a.id ? 'bg-brand-gold text-brand-navy' : 'ring-1 ring-brand-navy/20 dark:ring-white/25'">
                          <svg v-if="rateForm.appointment_id === a.id" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        </span>
                      </button>
                    </div>
                  </div>

                  <!-- Stars -->
                  <div class="mt-6 text-center">
                    <div class="inline-flex gap-1.5" role="radiogroup" aria-label="Rating" @mouseleave="rateHover = 0">
                      <button
                        v-for="n in 5"
                        :key="n"
                        type="button"
                        role="radio"
                        :aria-checked="rateForm.rating === n"
                        :aria-label="`${n} star${n === 1 ? '' : 's'}, ${RATING_WORDS[n]}`"
                        class="p-1 transition-transform active:scale-90 hover:scale-110"
                        @mouseenter="rateHover = n"
                        @click="rateForm.rating = n; rateError = ''"
                      >
                        <svg class="h-10 w-10 transition-colors" :class="n <= (rateHover || rateForm.rating) ? 'text-brand-gold' : 'text-brand-navy/15 dark:text-white/15'" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                          <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                        </svg>
                      </button>
                    </div>
                    <p class="mt-1 h-5 text-sm font-semibold" :class="(rateHover || rateForm.rating) ? 'text-brand-navy dark:text-white' : rateError ? 'text-red-500' : 'text-brand-navy/40 dark:text-white/40'">
                      {{ (rateHover || rateForm.rating) ? RATING_WORDS[rateHover || rateForm.rating] : rateError ? 'Please tap a star rating' : 'Tap a star' }}
                    </p>
                  </div>

                  <!-- Tags -->
                  <p class="mt-5 mb-2 text-sm font-semibold text-brand-navy dark:text-white/90">What stood out?</p>
                  <div class="flex flex-wrap gap-1.5">
                    <button
                      v-for="t in STANDOUT_TAGS"
                      :key="t"
                      type="button"
                      :aria-pressed="rateForm.tags.includes(t)"
                      class="rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition-all active:scale-95"
                      :class="rateForm.tags.includes(t)
                        ? 'bg-brand-navy text-white ring-brand-navy dark:bg-brand-gold dark:text-brand-navy dark:ring-brand-gold'
                        : 'text-brand-navy/70 ring-brand-navy/15 hover:ring-brand-gold dark:text-white/70 dark:ring-white/15'"
                      @click="toggleTag(t)"
                    >{{ t }}</button>
                  </div>

                  <!-- Note -->
                  <label for="rv-note" class="mt-5 mb-1.5 flex items-center justify-between text-sm font-semibold text-brand-navy dark:text-white/90">
                    Tell others more <span class="text-xs font-medium text-brand-navy/40 dark:text-white/40">{{ rateForm.review_text.length }}/2000</span>
                  </label>
                  <textarea
                    id="rv-note"
                    v-model="rateForm.review_text"
                    rows="3"
                    maxlength="2000"
                    placeholder="What went well, or what could be better?"
                    class="w-full resize-none rounded-2xl border-0 px-4 py-3 text-[0.9375rem] text-brand-navy dark:text-white bg-brand-navy/[0.04] dark:bg-white/[0.06]
                           ring-1 ring-inset ring-brand-navy/10 dark:ring-white/10 placeholder-brand-navy/40 dark:placeholder-white/35
                           focus:bg-white dark:focus:bg-white/[0.09] focus:ring-2 focus:ring-brand-gold dark:focus:ring-brand-gold transition"
                  />
                  <p class="mt-2 text-xs text-brand-navy/45 dark:text-white/40">Your review and name appear on this profile. Your email and phone never do.</p>

                  <div class="mt-6 flex flex-col-reverse sm:flex-row gap-2.5">
                    <button type="button" class="sm:flex-1 rounded-full px-5 py-3 text-sm font-semibold text-brand-navy/75 ring-1 ring-brand-navy/20 hover:ring-2 hover:ring-brand-gold dark:text-white/75 dark:ring-white/25 dark:hover:ring-brand-gold transition" @click="closeRate">
                      Cancel
                    </button>
                    <button
                      type="button"
                      class="sm:flex-[1.4] rounded-full bg-brand-gold px-5 py-3 text-sm font-bold text-brand-navy shadow-[0_6px_18px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light hover:-translate-y-0.5 transition"
                      @click="submitRating"
                    >{{ myReview ? 'Save changes' : 'Submit review' }}</button>
                  </div>
                </div>

                <!-- ░░ Sending ░░ -->
                <div v-else-if="rateStep === 'sending'" key="sending" class="px-6 py-14 text-center" role="status" aria-live="polite">
                  <div class="relative mx-auto h-20 w-20">
                    <span class="absolute inset-0 rounded-full border-[3px] border-brand-gold/25 border-t-brand-gold animate-spin motion-reduce:animate-none" aria-hidden="true" />
                    <span class="absolute inset-0 flex items-center justify-center text-brand-gold-deep dark:text-brand-gold">
                      <svg class="h-8 w-8" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" /></svg>
                    </span>
                  </div>
                  <p class="mt-6 font-heading text-xl font-semibold text-brand-navy dark:text-white">{{ rateWasEdit ? 'Saving your review…' : 'Posting your review…' }}</p>
                </div>

                <!-- ░░ Done ░░ -->
                <div v-else-if="rateStep === 'sent'" key="sent" class="p-6 sm:p-7 text-center" role="status" aria-live="polite">
                  <div class="relative mx-auto mt-2 h-20 w-20">
                    <span class="absolute inset-0 rounded-full bg-emerald-500/15 rv-pop" aria-hidden="true" />
                    <svg class="absolute inset-0 h-20 w-20 text-emerald-500" viewBox="0 0 80 80" fill="none" aria-hidden="true">
                      <circle cx="40" cy="40" r="30" stroke="currentColor" stroke-width="3" class="rv-ring" />
                      <path d="M27 41l9 9 18-19" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" class="rv-check" />
                    </svg>
                  </div>
                  <h2 id="rv-title" class="mt-5 font-heading text-2xl font-semibold text-brand-navy dark:text-white">{{ rateWasEdit ? 'Review updated' : 'Thanks for your review' }}</h2>
                  <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/60">It helps other buyers choose a good agent.</p>
                  <div class="mt-4 inline-flex gap-0.5" :aria-label="`Your rating: ${rateForm.rating} stars`">
                    <svg v-for="n in 5" :key="n" class="h-5 w-5" :class="n <= rateForm.rating ? 'text-brand-gold' : 'text-brand-navy/15 dark:text-white/15'" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" /></svg>
                  </div>
                  <button type="button" class="mt-6 w-full rounded-full bg-brand-navy px-5 py-3 text-sm font-bold text-white dark:bg-brand-gold dark:text-brand-navy hover:-translate-y-0.5 hover:shadow-lg transition" @click="showRate = false">
                    See it on the profile
                  </button>
                </div>

                <!-- ░░ Error ░░ -->
                <div v-else key="error" class="p-6 sm:p-7 text-center" role="alert">
                  <div class="mx-auto mt-2 h-20 w-20 rounded-full bg-red-500/10 flex items-center justify-center">
                    <svg class="h-9 w-9 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                  </div>
                  <h2 id="rv-title" class="mt-5 font-heading text-2xl font-semibold text-brand-navy dark:text-white">Review not saved</h2>
                  <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/60">{{ rateError }}</p>
                  <p class="mt-1 text-xs text-brand-navy/45 dark:text-white/40">Your rating and note are still here.</p>
                  <div class="mt-6 flex flex-col-reverse sm:flex-row gap-2.5">
                    <button type="button" class="sm:flex-1 rounded-full px-5 py-3 text-sm font-semibold text-brand-navy/70 ring-1 ring-brand-navy/20 dark:text-white/65 dark:ring-white/25 transition" @click="closeRate">Close</button>
                    <button type="button" class="sm:flex-1 rounded-full bg-brand-gold px-5 py-3 text-sm font-bold text-brand-navy hover:bg-brand-gold-light transition" @click="rateStep = 'form'; rateError = ''">Try again</button>
                  </div>
                </div>
              </Transition>
            </div>
          </Transition>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
/* One surface for every block on the page (matches the listing page). */
.prof-card {
  @apply rounded-[1.75rem] bg-white dark:bg-[#0d2550] p-6 sm:p-7
         ring-1 ring-brand-navy/[0.06] dark:ring-white/10
         shadow-[0_18px_44px_-30px_rgba(11,42,92,0.45)];
}
.prof-stat {
  @apply relative overflow-hidden rounded-2xl px-4 py-3.5
         bg-brand-cream dark:bg-white/[0.04] ring-1 ring-brand-navy/[0.05] dark:ring-white/10
         hover:ring-brand-gold/50 transition-colors;
}
.prof-stat-label { @apply text-[0.6875rem] font-bold uppercase tracking-wide text-brand-navy/50 dark:text-white/45; }
.prof-stat-hint  { @apply mt-0.5 text-xs text-brand-text-secondary dark:text-white/50; }

/* Once-only rise for what's on screen at load; reviews rise in turn. */
.prof-rise, .prof-review { animation: prof-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes prof-rise {
  from { opacity: 0; transform: translateY(12px); }
  to   { opacity: 1; transform: none; }
}

/* Rating bars grow from zero when the Reviews tab opens. */
.prof-bar { animation: prof-bar 0.8s 0.15s cubic-bezier(0.22, 1, 0.36, 1) both; transform-origin: left; }
@keyframes prof-bar { from { transform: scaleX(0); } to { transform: scaleX(1); } }

.tab-swap-enter-active, .tab-swap-leave-active { transition: opacity 0.2s ease, transform 0.25s cubic-bezier(0.22, 1, 0.36, 1); }
.tab-swap-enter-from { opacity: 0; transform: translateY(8px); }
.tab-swap-leave-to   { opacity: 0; }

@media (prefers-reduced-motion: reduce) {
  .prof-rise, .prof-review, .prof-bar { animation: none; }
  .tab-swap-enter-active, .tab-swap-leave-active { transition: none; }
}
.rv-backdrop-enter-active, .rv-backdrop-leave-active { transition: opacity 0.22s ease; }
.rv-backdrop-enter-from, .rv-backdrop-leave-to { opacity: 0; }
.rv-panel-enter-active { transition: opacity 0.25s ease, transform 0.35s cubic-bezier(0.22, 1, 0.36, 1); }
.rv-panel-enter-from { opacity: 0; transform: translateY(24px) scale(0.98); }
.rv-step-enter-active, .rv-step-leave-active { transition: opacity 0.2s ease, transform 0.25s cubic-bezier(0.22, 1, 0.36, 1); }
.rv-step-enter-from { opacity: 0; transform: translateY(10px); }
.rv-step-leave-to   { opacity: 0; transform: translateY(-6px); }
.rv-ring  { stroke-dasharray: 189; stroke-dashoffset: 189; animation: rv-draw 0.5s ease-out forwards; }
.rv-check { stroke-dasharray: 44;  stroke-dashoffset: 44;  animation: rv-draw 0.35s 0.4s ease-out forwards; }
@keyframes rv-draw { to { stroke-dashoffset: 0; } }
.rv-pop { animation: rv-pop 0.6s 0.55s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes rv-pop { from { transform: scale(0.6); opacity: 0; } to { transform: scale(1); opacity: 1; } }
@media (prefers-reduced-motion: reduce) {
  .rv-pop { animation: none; }
  .rv-ring, .rv-check { animation: none; stroke-dashoffset: 0; }
  .rv-panel-enter-active, .rv-step-enter-active, .rv-step-leave-active { transition: none; }
}
</style>
