<script setup lang="ts">
import type { Property, PaginatedResponse } from '~/types'

definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()
if (!authStore.isAdmin) {
  throw createError({ statusCode: 403, statusMessage: 'Forbidden' })
}

const api = useApi()
const ask   = useConfirm()
const toast = useToast()

const properties = ref<Property[]>([])
const pagination = ref<PaginatedResponse<Property>['meta'] | null>(null)
const loading    = ref(false)
const page       = ref(1)

async function load() {
  loading.value = true
  try {
    const res = await api.get<PaginatedResponse<Property>>('/admin/properties', { page: page.value })
    properties.value = res?.data ?? []
    pagination.value = res?.meta ?? null
  } catch {
    properties.value = []
  } finally {
    loading.value = false
  }
}

/* ── Unpublish with a reason ──
 * Taking a listing down used to be a single silent click. The agent is now
 * told why: the reason is required, stored on the listing (shown on their
 * My Listings until they re-publish) and pushed to them as a notification.
 */
const unpubTarget  = ref<Property | null>(null)
const unpubReason  = ref('')
const unpubError   = ref('')
const unpubSending = ref(false)
const REASON_MIN   = 10

function openUnpublish(prop: Property) {
  unpubTarget.value = prop
  unpubReason.value = ''
  unpubError.value  = ''
}

async function confirmUnpublish() {
  if (!unpubTarget.value) return
  const reason = unpubReason.value.trim()
  if (reason.length < REASON_MIN) {
    unpubError.value = `Please give a reason the agent can act on (at least ${REASON_MIN} characters).`
    return
  }
  unpubSending.value = true
  unpubError.value   = ''
  try {
    await api.post(`/admin/properties/${unpubTarget.value.id}/unpublish`, { reason })
    toast.success(`Unpublished — ${unpubTarget.value.agent?.name ?? 'the agent'} has been notified`)
    unpubTarget.value = null
    await load()
  } catch (e: unknown) {
    const data = (e as { data?: { message?: string; errors?: Record<string, string[]> } })?.data
    unpubError.value = data?.errors ? (Object.values(data.errors).flat()[0] ?? '') : (data?.message ?? 'Could not unpublish this listing.')
  } finally {
    unpubSending.value = false
  }
}

async function remove(prop: Property) {
  const ok = await ask({
    title:   'Delete this listing permanently?',
    message: `This removes ${prop.agent?.name ?? 'the agent'}'s listing and every photo attached to it.`,
    subject: prop.title,
    consequences: [
      'The agent is not notified automatically',
      'Its inquiries and viewing history go with it',
      'To take it down without deleting, use Unpublish',
    ],
    confirmLabel: 'Delete permanently',
    tone: 'danger',
  })
  if (!ok) return

  try {
    await api.del(`/admin/properties/${prop.id}`)
    toast.success('Listing deleted')
    await load()
  } catch {
    toast.error('Could not delete this listing')
  }
}

async function goPage(n: number) {
  page.value = n
  await load()
}

// ── "Why featured?" (grounded AI explanation) ──
interface Factor { key: string; label: string; score: number; max: number; notes: string[] }
interface Breakdown { eligible: boolean; total: number; gate_failures: string[]; factors: Factor[] }

const explainOpen    = ref(false)
const explainLoading = ref(false)
const explainTitle   = ref('')
const explainData    = ref<{ breakdown: Breakdown; explanation: string } | null>(null)

async function explainFeatured(prop: Property) {
  explainTitle.value   = prop.title
  explainData.value    = null
  explainOpen.value    = true
  explainLoading.value = true
  try {
    const res = await api.get<{ data: { breakdown: Breakdown; explanation: string } }>(`/admin/properties/${prop.id}/featured-explain`)
    explainData.value = res.data
  } catch {
    explainData.value = null
  } finally {
    explainLoading.value = false
  }
}

await load()
</script>

<template>
  <div>
    <h1 class="font-playfair text-2xl font-bold text-brand-navy mb-6">All Listings</h1>

    <div v-if="loading" class="space-y-3">
      <AppSkeleton v-for="i in 5" :key="i" width="100%" height="72px" />
    </div>

    <div v-else-if="!properties.length" class="text-center py-12 card">
      <p class="text-brand-text-secondary">No listings found.</p>
    </div>

    <div v-else class="space-y-3">
      <div
        v-for="prop in properties"
        :key="prop.id"
        class="card p-4 flex items-center gap-4"
      >
        <div class="h-14 w-20 rounded-md overflow-hidden bg-brand-silver-light flex-shrink-0">
          <img v-if="prop.photos?.[0]" :src="prop.photos[0].url" :alt="prop.title" class="h-full w-full object-cover" />
        </div>

        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <NuxtLink :to="`/properties/${prop.id}`" class="font-semibold text-brand-text-primary hover:text-brand-gold truncate">
              {{ prop.title }}
            </NuxtLink>
            <AppBadge :variant="prop.status === 'published' ? 'success' : 'default'">{{ prop.status }}</AppBadge>
            <!-- Featured score (0 = not featurable) -->
            <span
              class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full"
              :class="(prop.featured_score ?? 0) > 0 ? 'bg-brand-gold/15 text-brand-gold' : 'bg-gray-100 text-gray-400'"
              title="Featured score (0–100)"
            >
              <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" /></svg>
              {{ prop.featured_score ?? 0 }}
            </span>
          </div>
          <p class="text-xs text-brand-text-secondary mt-0.5">
            ₱{{ Number(prop.price).toLocaleString('en-PH') }} · {{ prop.agent?.name ?? 'Unknown agent' }}
          </p>
          <p v-if="prop.status === 'draft' && prop.unpublish_reason" class="text-xs text-amber-800 mt-1 truncate" :title="prop.unpublish_reason">
            <span class="font-semibold">Unpublished by admin:</span> {{ prop.unpublish_reason }}
          </p>
        </div>

        <div class="flex items-center gap-2 flex-shrink-0">
          <AppButton
            v-if="(prop.featured_score ?? 0) > 0"
            variant="ghost"
            size="sm"
            @click="explainFeatured(prop)"
          >
            Why featured?
          </AppButton>
          <AppButton
            v-if="prop.status === 'published'"
            variant="outline"
            size="sm"
            @click="openUnpublish(prop)"
          >
            Unpublish
          </AppButton>
          <AppButton variant="danger" size="sm" @click="remove(prop)">Delete</AppButton>
        </div>
      </div>
    </div>

    <div v-if="pagination && pagination.last_page > 1" class="flex items-center justify-center gap-2 mt-6">
      <AppButton variant="outline" size="sm" :disabled="page <= 1" @click="goPage(page - 1)">Prev</AppButton>
      <span class="text-sm text-brand-text-secondary px-4">{{ pagination.current_page }} / {{ pagination.last_page }}</span>
      <AppButton variant="outline" size="sm" :disabled="page >= pagination.last_page" @click="goPage(page + 1)">Next</AppButton>
    </div>

    <!-- Unpublish — reason required, sent to the agent -->
    <AppModal :open="!!unpubTarget" title="Unpublish this listing" @close="unpubTarget = null">
      <div v-if="unpubTarget" class="p-6 space-y-4">
        <div class="flex items-center gap-3 rounded-xl bg-gray-50 border border-gray-200 p-3">
          <div class="h-12 w-16 rounded-md overflow-hidden bg-brand-silver-light flex-shrink-0">
            <img v-if="unpubTarget.photos?.[0]" :src="unpubTarget.photos[0].url" :alt="unpubTarget.title" class="h-full w-full object-cover" />
          </div>
          <div class="min-w-0">
            <p class="text-sm font-semibold text-brand-navy truncate">{{ unpubTarget.title }}</p>
            <p class="text-xs text-brand-text-secondary">Listed by {{ unpubTarget.agent?.name ?? 'Unknown agent' }}</p>
          </div>
        </div>

        <div>
          <label class="text-sm font-medium text-brand-text-primary block mb-1">
            Reason <span class="text-red-500">*</span>
          </label>
          <textarea
            v-model="unpubReason"
            rows="4"
            maxlength="500"
            class="input-field resize-none"
            placeholder="e.g. The photos don't match the address on the listing. Please upload photos of the actual unit and re-publish."
            autofocus
          />
          <div class="flex items-center justify-between mt-1">
            <p class="text-[11px] text-brand-text-secondary">
              This is sent to <span class="font-medium text-brand-navy">{{ unpubTarget.agent?.name ?? 'the agent' }}</span> and shown on their listing until they re-publish.
            </p>
            <span class="text-[11px] tabular-nums" :class="unpubReason.trim().length < REASON_MIN ? 'text-gray-400' : 'text-emerald-600'">
              {{ unpubReason.trim().length }}/500
            </span>
          </div>
        </div>

        <p v-if="unpubError" class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-xl px-4 py-3">{{ unpubError }}</p>

        <div class="flex gap-3">
          <AppButton variant="ghost" full-width :disabled="unpubSending" @click="unpubTarget = null">Cancel</AppButton>
          <AppButton variant="primary" full-width :disabled="unpubSending || unpubReason.trim().length < REASON_MIN" @click="confirmUnpublish">
            {{ unpubSending ? 'Unpublishing…' : 'Unpublish & notify agent' }}
          </AppButton>
        </div>
      </div>
    </AppModal>

    <!-- Why featured? — grounded AI explanation + factor breakdown -->
    <AppModal :open="explainOpen" title="Why is this featured?" @close="explainOpen = false">
      <div class="p-6">
        <p class="text-sm font-semibold text-brand-navy mb-3">{{ explainTitle }}</p>

        <div v-if="explainLoading" class="py-8 flex justify-center"><AppSpinner /></div>

        <div v-else-if="explainData">
          <!-- AI summary -->
          <div class="rounded-xl bg-brand-gold/5 border border-brand-gold/30 p-4 mb-4">
            <p class="text-[10px] font-bold uppercase tracking-wide text-brand-gold mb-1 flex items-center gap-1.5">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
              RealtyLink AI
            </p>
            <p class="text-sm text-brand-navy leading-relaxed">{{ explainData.explanation }}</p>
          </div>

          <!-- Score + factor breakdown -->
          <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-bold text-brand-navy">Score: {{ explainData.breakdown.total }}/100</span>
            <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded-full" :class="explainData.breakdown.eligible ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-600'">
              {{ explainData.breakdown.eligible ? 'Eligible' : 'Not eligible' }}
            </span>
          </div>

          <p v-if="explainData.breakdown.gate_failures.length" class="text-xs text-red-500 mb-3">
            Blocked by: {{ explainData.breakdown.gate_failures.join(' · ') }}
          </p>

          <div class="space-y-2.5">
            <div v-for="f in explainData.breakdown.factors" :key="f.key">
              <div class="flex items-center justify-between text-xs">
                <span class="font-semibold text-brand-navy">{{ f.label }}</span>
                <span class="text-gray-500">{{ f.score }}/{{ f.max }}</span>
              </div>
              <div class="h-1.5 rounded-full bg-gray-100 mt-1 overflow-hidden">
                <div class="h-full bg-brand-gold rounded-full" :style="{ width: `${Math.min(100, (f.score / f.max) * 100)}%` }" />
              </div>
              <p class="text-[11px] text-gray-400 mt-1">{{ f.notes.join(' · ') }}</p>
            </div>
          </div>
        </div>

        <p v-else class="text-sm text-gray-400 py-6 text-center">Could not load the explanation.</p>
      </div>
    </AppModal>
  </div>
</template>
