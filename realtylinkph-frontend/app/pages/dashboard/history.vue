<script setup lang="ts">
import type { Appointment, Property, User } from '~/types'

definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()
const { appointments, loading, fetchMyAppointments } = useAppointment()
const { properties: inventory, loading: invLoading, fetchInventory, relistProperty } = useProperty()
const { submitReview, error: reviewError } = useReview()
const toast = useToast()
const ask   = useConfirm()
const propertyHref = usePropertyHref()

const tab = ref<'viewings' | 'inventory'>('viewings')

await fetchMyAppointments()
if (authStore.isAgent) await fetchInventory()

/* ── Rate the agent (from a completed/confirmed viewing) ── */
const STANDOUT_TAGS = ['On time', 'Knowledgeable', 'Honest', 'Responsive', 'Friendly']
const rateOpen   = ref(false)
const rateAppt   = ref<Appointment | null>(null)
const submitting = ref(false)
const rateError  = ref('')
const rateForm   = reactive({ rating: 0, review_text: '', tags: [] as string[] })

function toggleTag(t: string) {
  const i = rateForm.tags.indexOf(t)
  if (i >= 0) rateForm.tags.splice(i, 1)
  else rateForm.tags.push(t)
}
function openRate(a: Appointment) {
  rateAppt.value = a
  rateForm.rating = 0
  rateForm.review_text = ''
  rateForm.tags = []
  rateError.value = ''
  rateOpen.value = true
}
async function submitRating() {
  if (!rateAppt.value) return
  if (!rateForm.rating) { rateError.value = 'Please tap a star rating.'; return }
  submitting.value = true
  rateError.value = ''
  const tagLine = rateForm.tags.length ? `👍 ${rateForm.tags.join(' · ')}` : ''
  const body = [tagLine, rateForm.review_text.trim()].filter(Boolean).join('\n')
  const res = await submitReview({ appointment_id: rateAppt.value.id, rating: rateForm.rating, review_text: body || undefined })
  submitting.value = false
  if (res) {
    rateOpen.value = false
    toast.success('Thanks for rating!')
    await fetchMyAppointments()
  } else {
    rateError.value = reviewError.value || 'Could not submit your review. Please try again.'
  }
}

/* ── Viewings history ── */
function isPast(a: Appointment): boolean {
  return new Date(a.preferred_datetime).getTime() < Date.now()
}
function otherParty(a: Appointment): User | undefined {
  return authStore.user?.id === a.agent_id ? a.buyer : a.agent
}
function fmtDateTime(iso: string): string {
  return new Date(iso).toLocaleString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const byDate = (a: Appointment, b: Appointment) =>
  +new Date(b.preferred_datetime) - +new Date(a.preferred_datetime)

// History = everything finished: completed (marked done) + cancelled + past viewings.
const historyItems = computed(() =>
  appointments.value.filter(a => a.status === 'completed' || a.status === 'cancelled' || isPast(a)),
)

const groups = computed(() => [
  {
    key: 'completed',
    label: 'Completed',
    items: historyItems.value.filter(a => a.status === 'completed').sort(byDate),
    dot: 'bg-emerald-500',
  },
  {
    key: 'cancelled',
    label: 'Cancelled',
    items: historyItems.value.filter(a => a.status === 'cancelled').sort(byDate),
    dot: 'bg-red-500',
  },
  {
    key: 'expired',
    label: 'Past & expired',
    items: historyItems.value.filter(a => a.status !== 'cancelled' && a.status !== 'completed' && isPast(a)).sort(byDate),
    dot: 'bg-gray-400',
  },
].filter(g => g.items.length))

const statusBadge: Record<string, string> = {
  pending:   'bg-amber-100 text-amber-700',
  confirmed: 'bg-emerald-100 text-emerald-700',
  completed: 'bg-emerald-100 text-emerald-700',
  cancelled: 'bg-red-100 text-red-600',
}

/* ── Inventory (sold/rented listings) ── */
const busy = ref<number | null>(null)
function priceLabel(p: Property): string {
  const n = Number(p.price)
  if (n >= 1_000_000) return `₱${(n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1)}M`
  if (n >= 1_000)     return `₱${(n / 1_000).toFixed(0)}K`
  return `₱${n.toLocaleString('en-PH')}`
}
function soldDate(p: Property): string {
  return p.sold_at ? new Date(p.sold_at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) : ''
}
async function relist(prop: Property) {
  const ok = await ask({
    title:   'Re-list this property?',
    message: 'It comes back as a draft so you can review the details before it goes live again.',
    subject: prop.title,
    consequences: [
      'It returns to My Listings as a draft',
      'Buyers will not see it until you publish it',
      'We will open the edit form so you can update the price',
    ],
    confirmLabel: 'Re-list as draft',
    tone: 'primary',
  })
  if (!ok) return

  busy.value = prop.id
  const done = await relistProperty(prop.id)
  busy.value = null

  if (done) {
    toast.success('Back in your listings as a draft')
    await navigateTo(`/dashboard/listings/${prop.id}/edit`)
  } else {
    toast.error('Could not re-list this property')
  }
}
</script>

<template>
  <div class="max-w-4xl">
    <!-- Header — same shape as the other dashboard pages -->
    <header class="mb-8">
      <h1 class="font-display text-2xl sm:text-[28px] font-bold text-brand-navy dark:text-white leading-tight">History</h1>
      <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/50">
        {{ tab === 'inventory' ? 'Your sold listings — re-list them anytime to find a new buyer or renter.' : 'Your cancelled and past property viewings.' }}
      </p>
    </header>

    <!-- Tabs (agents only) -->
    <div v-if="authStore.isAgent" class="flex gap-1 mb-6 border-b border-gray-200 dark:border-white/10">
      <button
        class="px-4 py-2.5 text-sm font-semibold transition-colors border-b-2 -mb-px"
        :class="tab === 'viewings'
          ? 'border-brand-gold text-brand-navy dark:text-white'
          : 'border-transparent text-brand-navy/45 hover:text-brand-navy dark:text-white/45 dark:hover:text-white'"
        @click="tab = 'viewings'"
      >Viewings</button>
      <button
        class="px-4 py-2.5 text-sm font-semibold transition-colors border-b-2 -mb-px inline-flex items-center gap-1.5"
        :class="tab === 'inventory'
          ? 'border-brand-gold text-brand-navy dark:text-white'
          : 'border-transparent text-brand-navy/45 hover:text-brand-navy dark:text-white/45 dark:hover:text-white'"
        @click="tab = 'inventory'"
      >
        Inventory
        <span v-if="inventory.length" class="text-[10px] font-bold bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold rounded-full px-1.5 py-0.5">{{ inventory.length }}</span>
      </button>
    </div>

    <!-- ════════ Viewings ════════ -->
    <template v-if="tab === 'viewings'">
      <div v-if="loading" class="space-y-3">
        <AppSkeleton v-for="i in 4" :key="i" width="100%" height="96px" rounded="lg" />
      </div>

      <!-- Empty — sits directly on the page, no card, matching My Listings -->
      <div v-else-if="!historyItems.length" class="text-center py-24">
        <div class="h-14 w-14 rounded-2xl bg-brand-gold/10 dark:bg-brand-gold/15 flex items-center justify-center mx-auto mb-5">
          <svg class="h-7 w-7 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        </div>
        <p class="text-lg font-bold text-brand-navy dark:text-white">No history yet</p>
        <p class="mx-auto mt-2 max-w-sm text-sm text-brand-text-secondary dark:text-white/50 leading-relaxed">
          Cancelled and past viewings will appear here.
        </p>
        <NuxtLink to="/dashboard/appointments" class="mt-5 inline-block text-sm font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline">
          Go to appointments →
        </NuxtLink>
      </div>

      <div v-else class="space-y-8">
        <section v-for="g in groups" :key="g.key">
          <div class="flex items-center gap-2 mb-3">
            <span class="h-2 w-2 rounded-full" :class="g.dot" />
            <h2 class="text-sm font-bold text-brand-navy uppercase tracking-wide">{{ g.label }}</h2>
            <span class="text-xs text-gray-400">({{ g.items.length }})</span>
          </div>

          <div class="space-y-3">
            <div
              v-for="appt in g.items"
              :key="appt.id"
              class="bg-white rounded-2xl border border-gray-200 p-4 flex gap-4"
              :class="{ 'opacity-70': appt.status === 'cancelled' }"
            >
              <!-- Property thumb (click → details) -->
              <NuxtLink :to="propertyHref(appt.property_id)" class="h-16 w-24 rounded-lg overflow-hidden flex-shrink-0 bg-gray-100">
                <img v-if="appt.property?.photos?.[0]" :src="appt.property.photos[0].thumb_url ?? appt.property.photos[0].url" :alt="appt.property?.title" class="h-full w-full object-cover" />
                <div v-else class="h-full w-full flex items-center justify-center text-gray-300">
                  <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.3" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M4 6h16v12H4z" /></svg>
                </div>
              </NuxtLink>

              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <NuxtLink :to="propertyHref(appt.property_id)" class="font-semibold text-brand-navy hover:text-brand-gold transition-colors line-clamp-1">{{ appt.property?.title ?? `Property #${appt.property_id}` }}</NuxtLink>
                  <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded-full capitalize flex-shrink-0" :class="statusBadge[appt.status]">{{ appt.status }}</span>
                </div>
                <p class="text-xs text-gray-500 mt-1 flex items-center gap-1.5">
                  <svg class="h-3.5 w-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                  {{ fmtDateTime(appt.preferred_datetime) }}
                </p>
                <p class="text-xs text-gray-500 mt-0.5 flex items-center gap-1.5">
                  <svg class="h-3.5 w-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                  {{ authStore.isAgent ? 'Buyer' : 'Agent' }}: {{ otherParty(appt)?.name ?? '—' }}
                </p>
                <p v-if="appt.notes" class="text-xs text-gray-400 mt-1 italic line-clamp-2">“{{ appt.notes }}”</p>
              </div>

              <!-- Buyer can rate the agent once the viewing is confirmed/completed -->
              <div v-if="appt.can_review && !authStore.isAgent" class="flex-shrink-0 flex items-center">
                <button
                  class="text-xs font-bold text-brand-navy bg-brand-gold rounded-lg px-3 py-2 hover:-translate-y-0.5 transition-all inline-flex items-center gap-1.5"
                  @click="openRate(appt)"
                >
                  <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" /></svg>
                  Rate agent
                </button>
              </div>
            </div>
          </div>
        </section>
      </div>
    </template>

    <!-- ════════ Inventory ════════ -->
    <template v-else>
      <div v-if="invLoading" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <AppSkeleton v-for="i in 4" :key="i" width="100%" height="120px" rounded="lg" />
      </div>

      <!-- Empty — sits directly on the page, no card, matching My Listings -->
      <div v-else-if="!inventory.length" class="text-center py-24">
        <div class="h-14 w-14 rounded-2xl bg-brand-gold/10 dark:bg-brand-gold/15 flex items-center justify-center mx-auto mb-5">
          <svg class="h-7 w-7 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
        </div>
        <p class="text-lg font-bold text-brand-navy dark:text-white">No sold listings yet</p>
        <p class="mx-auto mt-2 max-w-sm text-sm text-brand-text-secondary dark:text-white/50 leading-relaxed">
          When you mark a listing as Sold, it lands here so you can re-list it later.
        </p>
        <NuxtLink to="/dashboard/listings" class="mt-5 inline-block text-sm font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline">
          Go to my listings →
        </NuxtLink>
      </div>

      <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div v-for="prop in inventory" :key="prop.id" class="bg-white rounded-2xl border border-gray-200 p-4 flex gap-4">
          <div class="relative h-20 w-28 rounded-lg overflow-hidden flex-shrink-0 bg-gray-100">
            <img v-if="prop.photos?.[0]" :src="prop.photos[0].thumb_url ?? prop.photos[0].url" :alt="prop.title" class="h-full w-full object-cover" />
            <div v-else class="h-full w-full flex items-center justify-center text-gray-300">
              <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.3" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M4 6h16v12H4z" /></svg>
            </div>
            <span class="absolute top-1.5 left-1.5 text-[9px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded-full bg-brand-navy text-white">Sold</span>
          </div>

          <div class="flex-1 min-w-0 flex flex-col">
            <p class="text-sm font-bold text-brand-gold">{{ priceLabel(prop) }}</p>
            <p class="font-semibold text-brand-navy line-clamp-1">{{ prop.title }}</p>
            <p class="text-xs text-gray-400 line-clamp-1">{{ prop.address }}</p>
            <p v-if="prop.sold_at" class="text-[11px] text-gray-400 mt-0.5">Sold {{ soldDate(prop) }}</p>

            <div class="flex items-center gap-2 mt-auto pt-2">
              <NuxtLink :to="`/dashboard/listings/${prop.id}/edit`" class="text-xs font-semibold text-brand-navy border border-gray-200 rounded-lg px-3 py-1.5 hover:border-brand-gold transition-colors">Edit</NuxtLink>
              <button
                :disabled="busy === prop.id"
                class="text-xs font-bold text-brand-navy bg-brand-gold rounded-lg px-3 py-1.5 hover:-translate-y-0.5 transition-all disabled:opacity-50 inline-flex items-center gap-1.5"
                @click="relist(prop)"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                Re-list
              </button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Rate agent modal -->
    <AppModal :open="rateOpen" title="Rate this agent" @close="rateOpen = false">
      <div class="p-6 space-y-4">
        <p v-if="rateAppt" class="text-sm text-brand-text-secondary">
          Your viewing of <span class="font-medium text-brand-navy">{{ rateAppt.property?.title ?? 'this property' }}</span>
          with <span class="font-medium text-brand-navy">{{ otherParty(rateAppt)?.name ?? 'the agent' }}</span>.
        </p>

        <div>
          <label class="text-sm font-medium text-brand-text-primary block mb-1">Your rating <span class="text-red-500">*</span></label>
          <AppRating :value="rateForm.rating" :readonly="false" size="lg" @update:value="rateForm.rating = $event" />
        </div>

        <div>
          <label class="text-sm font-medium text-brand-text-primary block mb-1.5">What stood out?</label>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="t in STANDOUT_TAGS"
              :key="t"
              type="button"
              class="text-xs font-semibold px-3 py-1.5 rounded-full border transition-colors"
              :class="rateForm.tags.includes(t) ? 'bg-brand-gold/15 border-brand-gold text-brand-navy' : 'border-gray-200 text-gray-500 hover:border-brand-gold/50'"
              @click="toggleTag(t)"
            >{{ t }}</button>
          </div>
        </div>

        <div>
          <label class="text-sm font-medium text-brand-text-primary">Review (optional)</label>
          <textarea v-model="rateForm.review_text" rows="4" maxlength="2000" placeholder="How was your experience with this agent?" class="input-field mt-1 resize-none" />
        </div>

        <p v-if="rateError" class="text-sm text-red-500">{{ rateError }}</p>

        <div class="flex gap-3">
          <AppButton variant="ghost" full-width @click="rateOpen = false">Cancel</AppButton>
          <AppButton variant="primary" full-width :disabled="submitting" @click="submitRating">
            {{ submitting ? 'Submitting…' : 'Submit rating' }}
          </AppButton>
        </div>
      </div>
    </AppModal>
  </div>
</template>
