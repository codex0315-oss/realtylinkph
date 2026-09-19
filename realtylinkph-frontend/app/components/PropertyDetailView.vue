<script setup lang="ts">
import { PROPERTY_TYPES } from '~/types'

// Shared property-detail view. Rendered by the public page (/properties/[id],
// for guests / ghost buyers) AND the in-account page (/dashboard/properties/[id]).
const route = useRoute()
const id    = Number(route.params.id)

const authStore = useAuthStore()
const { property, loading, error, fetchProperty }      = useProperty()
const { submitInquiry, loading: inqLoading, error: inqError } = useInquiry()
const { book, loading: bookLoading, error: bookError } = useAppointment()
const { fetchSlots, fetchUnavailableDates }            = useAvailability()
const { open: openAuth }                               = useAuthModal()
const agentHref                                        = useAgentHref()

await fetchProperty(id)

const showInquiry = ref(false)
const showBooking = ref(false)
const showTour    = ref(false)

const galleryPhotos = computed(() => property.value?.photos?.filter(p => !p.is_360) ?? [])
const panoramas     = computed(() => property.value?.photos?.filter(p => p.is_360) ?? [])
const activePano    = ref(0)

/* ── Lightbox ── */
const lightboxOpen  = ref(false)
const lightboxIndex = ref(0)
function openLightbox(i: number) { lightboxIndex.value = i; lightboxOpen.value = true }
function prevPhoto() { lightboxIndex.value = (lightboxIndex.value - 1 + galleryPhotos.value.length) % galleryPhotos.value.length }
function nextPhoto() { lightboxIndex.value = (lightboxIndex.value + 1) % galleryPhotos.value.length }

const inquiryForm  = reactive({ name: '', email: '', phone: '', message: '' })
const bookingForm  = reactive({ notes: '' })

// Quick-message chips for the inquiry form.
const INQUIRY_QUICK = [
  { label: 'Is it available?', text: "Hi! Is this property still available? I'd like to know more." },
  { label: 'Financing',        text: 'Hi! Could you share the financing / payment options for this property?' },
  { label: 'Schedule a viewing', text: "Hi! I'd like to schedule a viewing. What times are available?" },
]
const bookingError = ref('')

/* ── Schedule viewing ── */
const next14Days = computed(() => {
  const out: Date[] = []
  const today = new Date()
  for (let i = 0; i < 14; i++) { const d = new Date(today); d.setDate(today.getDate() + i); out.push(d) }
  return out
})
function dateKey(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// Agent's unavailable dates: fully-blocked (disabled) vs limited (fewer times).
const blockedSet = ref<Set<string>>(new Set())
const limitedSet = ref<Set<string>>(new Set())
const isDateBlocked = (d: Date) => blockedSet.value.has(dateKey(d))
const isDateLimited = (d: Date) => limitedSet.value.has(dateKey(d))
async function loadUnavailable() {
  if (!property.value?.agent_id) return
  const u = await fetchUnavailableDates(property.value.agent_id)
  blockedSet.value = new Set(u.blocked)
  limitedSet.value = new Set(u.limited)
}

const selectedDate = ref('')
const selectedSlot = ref('')
const slots        = ref<string[]>([])
const loadingSlots = ref(false)

async function pickDate(d: Date) {
  if (isDateBlocked(d)) return   // fully-blocked days aren't selectable
  selectedDate.value = dateKey(d)
  selectedSlot.value = ''
  slots.value = []
  if (!property.value?.agent_id) return
  loadingSlots.value = true
  slots.value = await fetchSlots(property.value.agent_id, selectedDate.value)
  loadingSlots.value = false
}
function slotLabel(t: string): string {
  const [h, m] = t.split(':').map(Number)
  const ampm = (h ?? 0) >= 12 ? 'PM' : 'AM'
  const h12 = ((h ?? 0) % 12) || 12
  return `${h12}:${String(m ?? 0).padStart(2, '0')} ${ampm}`
}

// On open: load the agent's unavailable dates, then pre-select the first open day.
watch(showBooking, async (open) => {
  if (!open) return
  await loadUnavailable()
  if (!selectedDate.value) {
    const firstOpen = next14Days.value.find(d => !isDateBlocked(d))
    if (firstOpen) pickDate(firstOpen)
  }
})

/*
 * Outcome of the last send, shown in its own modal once the form closes.
 * Success clears the form; failure keeps what was typed so "Try again"
 * reopens the form with everything still in place.
 */
const inquiryResult = ref<'success' | 'error' | null>(null)
const inquirySentTo = ref('')

function openInquiry() {
  inqError.value = null            // don't show a stale error from a previous attempt
  showInquiry.value = true
}

async function sendInquiry() {
  const ok = await submitInquiry({
    property_id: id,
    name:    inquiryForm.name    || undefined,
    email:   inquiryForm.email   || undefined,
    phone:   inquiryForm.phone   || undefined,
    message: inquiryForm.message,
  })
  inquirySentTo.value = inquiryForm.email
  showInquiry.value   = false
  inquiryResult.value = ok ? 'success' : 'error'
  if (ok) Object.assign(inquiryForm, { name: '', email: '', phone: '', message: '' })
}

function retryInquiry() {
  inquiryResult.value = null
  showInquiry.value   = true       // the inline banner still shows what went wrong
}

async function bookAppointment() {
  bookingError.value = ''
  if (!selectedDate.value || !selectedSlot.value) {
    bookingError.value = 'Please pick a date and an available time.'
    return
  }
  const appt = await book({
    property_id:        id,
    agent_id:           property.value!.agent_id,
    preferred_datetime: `${selectedDate.value} ${selectedSlot.value}`,
    notes:              bookingForm.notes || undefined,
  })
  if (appt) {
    showBooking.value = false
    selectedDate.value = ''; selectedSlot.value = ''; slots.value = []; bookingForm.notes = ''
  }
}

const offerLabel  = computed(() => (property.value?.offer_type === 'rent' ? 'For Rent' : 'For Sale'))
const displayPrice = computed(() => property.value ? `₱${Number(property.value.price).toLocaleString('en-PH')}` : '')

/*
 * Key facts as one inline row ("1 bed · 1 bath · 46 sqm floor"), the way
 * listing pages everywhere present them — not a card per number.
 */
const specs = computed(() => {
  const p = property.value
  if (!p) return []
  const plural = (n: number, one: string, many: string) => (n === 1 ? one : many)
  return [
    { show: !!p.bedrooms,   value: p.bedrooms,   label: plural(Number(p.bedrooms), 'bed', 'beds'),   icon: 'M2 17h20M4 17V9a2 2 0 012-2h12a2 2 0 012 2v8M4 11h16' },
    { show: !!p.bathrooms,  value: p.bathrooms,  label: plural(Number(p.bathrooms), 'bath', 'baths'), icon: 'M4 12h16v3a4 4 0 01-4 4H8a4 4 0 01-4-4v-3zM6 12V6a2 2 0 012-2 2 2 0 012 2' },
    { show: !!p.floor_area, value: p.floor_area, label: 'sqm floor', icon: 'M4 8V4h4M4 16v4h4M20 8V4h-4M20 16v4h-4' },
    { show: !!p.lot_area,   value: p.lot_area,   label: 'sqm lot',   icon: 'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l5.447 2.724A1 1 0 0021 19.382V8.618a1 1 0 00-1.447-.894L15 4m0 13V4m0 0L9 7' },
  ].filter(s => s.show)
})

/*
 * Gallery mosaic: one lead photo, then up to four thumbnails in a 2×2 block.
 * The thumbnail spans adapt so a listing with 2 or 3 extra photos still fills
 * the block instead of leaving an empty cell.
 */
const leadPhoto = computed(() => galleryPhotos.value[0])
const thumbs    = computed(() => galleryPhotos.value.slice(1, 5))
function thumbClass(i: number): string {
  const n = thumbs.value.length
  if (n === 1) return 'col-span-2 row-span-2'
  if (n === 2) return 'col-span-2'
  if (n === 3 && i === 2) return 'col-span-2'
  return ''
}
const isVerifiedAgent = computed(() => property.value?.agent?.agent_profile?.status === 'approved')
</script>

<template>
  <div class="max-w-content mx-auto">
    <div v-if="loading" class="space-y-4">
      <AppSkeleton width="100%" height="460px" rounded="lg" />
      <AppSkeleton width="60%" height="32px" />
      <AppSkeleton :lines="4" />
    </div>

    <div v-else-if="error" class="text-center py-16 text-red-500">{{ error }}</div>

    <template v-else-if="property">
      <!-- ░░ Gallery mosaic: lead photo + thumbnail block ░░ -->
      <div v-if="leadPhoto" class="relative">
        <div class="grid grid-cols-4 grid-rows-2 gap-2 sm:gap-3 h-[18.75rem] sm:h-[26.25rem] lg:h-[30rem]">
          <!-- Lead photo. On phones it's the only tile and takes the full width. -->
          <button
            type="button"
            class="relative row-span-2 rounded-2xl overflow-hidden group"
            :class="thumbs.length ? 'col-span-4 sm:col-span-2' : 'col-span-4'"
            @click="openLightbox(0)"
          >
            <img :src="leadPhoto.url" :alt="property.title" class="w-full h-full object-cover group-hover:scale-[1.03] transition-transform duration-500" />
          </button>

          <button
            v-for="(photo, i) in thumbs"
            :key="photo.id"
            type="button"
            class="hidden sm:block relative rounded-2xl overflow-hidden group"
            :class="thumbClass(i)"
            @click="openLightbox(i + 1)"
          >
            <img :src="photo.thumb_url ?? photo.url" :alt="`Photo ${i + 2}`" loading="lazy" class="w-full h-full object-cover group-hover:scale-[1.03] transition-transform duration-500" />
            <div v-if="i === thumbs.length - 1 && galleryPhotos.length > 5" class="absolute inset-0 bg-black/55 flex items-center justify-center text-white font-bold text-sm">
              +{{ galleryPhotos.length - 5 }} more
            </div>
          </button>
        </div>

        <!-- 360 tour chip on the lead photo -->
        <button
          v-if="panoramas.length"
          type="button"
          class="absolute top-3 left-3 inline-flex items-center gap-1.5 bg-black/55 backdrop-blur-sm text-white text-xs font-semibold px-3 py-1.5 rounded-full ring-1 ring-white/20 hover:bg-black/70 transition-colors"
          @click="showTour = true"
        >
          <svg class="h-3.5 w-3.5 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0zM3 12h18M12 3a15 15 0 010 18M12 3a15 15 0 000 18" /></svg>
          360° Virtual Tour
        </button>

        <!-- View all photos -->
        <button
          v-if="galleryPhotos.length > 1"
          type="button"
          class="absolute bottom-3 right-3 inline-flex items-center gap-1.5 bg-white/90 backdrop-blur-sm text-brand-navy text-xs font-semibold px-3 py-1.5 rounded-full shadow hover:bg-white transition-colors"
          @click="openLightbox(0)"
        >
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16M4 12h16M4 18h16" /></svg>
          All {{ galleryPhotos.length }} photos
        </button>
      </div>
      <div v-else class="aspect-[16/7] rounded-2xl bg-brand-silver-light dark:bg-white/5 flex items-center justify-center">
        <p class="text-brand-text-secondary dark:text-white/50 text-sm">No photos available</p>
      </div>

      <!-- ░░ Body ░░ -->
      <div class="grid lg:grid-cols-3 gap-8 lg:gap-10 mt-8">

        <!-- Left column: title, facts, description, map -->
        <div class="lg:col-span-2 divide-y divide-gray-200 dark:divide-white/10">

          <!-- Title block -->
          <section class="pb-7">
            <div class="flex items-center gap-2 mb-3">
              <span class="text-[0.625rem] font-bold uppercase tracking-wide px-2.5 py-1 rounded-full" :class="property.offer_type === 'rent' ? 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'">{{ offerLabel }}</span>
              <span class="text-[0.625rem] font-bold uppercase tracking-wide px-2.5 py-1 rounded-full bg-brand-navy/5 text-brand-navy dark:bg-white/10 dark:text-white/80">{{ PROPERTY_TYPES[property.type] }}</span>
            </div>
            <h1 class="font-display text-2xl md:text-[2.125rem] font-bold text-brand-navy dark:text-white leading-tight">{{ property.title }}</h1>
            <a href="#location" class="mt-2 inline-flex items-center gap-1.5 text-sm text-brand-text-secondary dark:text-white/60 hover:text-brand-gold transition-colors">
              <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
              {{ property.address }}
            </a>

            <!-- Price shows here only on small screens, where the action card
                 sits below the fold. On desktop it lives in the card alone. -->
            <p class="lg:hidden mt-4 text-2xl font-bold text-brand-navy dark:text-white tabular-nums">
              {{ displayPrice }}<span v-if="property.offer_type === 'rent'" class="text-sm font-medium text-brand-text-secondary dark:text-white/50"> / month</span>
            </p>

            <!-- Key facts, inline -->
            <div v-if="specs.length" class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-brand-text-secondary dark:text-white/60">
              <span v-for="s in specs" :key="s.label" class="inline-flex items-center gap-2">
                <svg class="h-[18px] w-[18px] text-brand-gold flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" :d="s.icon" /></svg>
                <span><span class="font-semibold text-brand-navy dark:text-white tabular-nums">{{ s.value }}</span> {{ s.label }}</span>
              </span>
            </div>
          </section>

          <!-- About -->
          <section v-if="property.description" class="py-7">
            <h2 class="font-display text-lg font-bold text-brand-navy dark:text-white mb-3">About this property</h2>
            <p class="text-[0.9375rem] text-brand-text-secondary dark:text-white/70 leading-relaxed whitespace-pre-line">{{ property.description }}</p>
          </section>

          <!-- Location -->
          <section id="location" class="py-7 scroll-mt-24">
            <h2 class="font-display text-lg font-bold text-brand-navy dark:text-white mb-1">Location</h2>
            <p class="text-sm text-brand-text-secondary dark:text-white/60 mb-4">{{ property.address }}</p>
            <PropertyLocationMap :lat="property.lat" :lng="property.lng" :address="property.address" />
          </section>
        </div>

        <!-- Right: sticky action card -->
        <aside class="lg:col-span-1">
          <div class="lg:sticky lg:top-24 rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-[#0B1A35] p-5 shadow-[0_12px_40px_-18px_rgba(8,21,47,0.25)]">
            <!-- Price -->
            <div class="flex items-baseline gap-1.5">
              <p class="text-[1.875rem] leading-none font-bold text-brand-navy dark:text-white tabular-nums">{{ displayPrice }}</p>
              <span v-if="property.offer_type === 'rent'" class="text-sm text-brand-text-secondary dark:text-white/50">/ month</span>
            </div>
            <p class="mt-1.5 text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-brand-navy/45 dark:text-white/40">{{ offerLabel }}</p>

            <!-- Actions: one primary, one secondary -->
            <div class="mt-5 space-y-2.5">
              <template v-if="authStore.isAuthenticated && authStore.isBuyer">
                <AppButton variant="primary" full-width @click="showBooking = true">Schedule Viewing</AppButton>
                <NuxtLink v-if="property.agent" :to="`/dashboard/messages?agent=${property.agent.id}&property=${id}`" class="btn-outline w-full text-center dark:border-white/20 dark:text-white dark:hover:bg-white/10">Message Agent</NuxtLink>
              </template>
              <template v-else-if="!authStore.isAuthenticated">
                <AppButton variant="primary" full-width @click="openInquiry">Send Inquiry</AppButton>
                <button type="button" class="btn-outline w-full text-center dark:border-white/20 dark:text-white dark:hover:bg-white/10" @click="openAuth('login')">Sign in to schedule a viewing</button>
              </template>

              <button
                v-if="panoramas.length"
                type="button"
                class="w-full inline-flex items-center justify-center gap-1.5 pt-1 text-xs font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline"
                @click="showTour = true"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0zM3 12h18M12 3a15 15 0 010 18M12 3a15 15 0 000 18" /></svg>
                Take the 360° virtual tour
              </button>
            </div>

            <!-- Listed by -->
            <div v-if="property.agent" class="mt-5 pt-5 border-t border-gray-200 dark:border-white/10">
              <p class="text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-brand-navy/45 dark:text-white/40 mb-3">Listed by</p>
              <div class="flex items-center gap-3">
                <AppAvatar :name="property.agent.name" :src="property.agent.avatar" size="md" />
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-brand-navy dark:text-white truncate">{{ property.agent.name }}</p>
                  <p v-if="isVerifiedAgent" class="inline-flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400">
                    <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-3.138-3.138 3.066 3.066 0 00-.806-1.946 3.066 3.066 0 010-4.438 3.066 3.066 0 00.806-1.946 3.066 3.066 0 013.138-3.138zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                    Verified agent
                  </p>
                </div>
              </div>

              <div v-if="authStore.isAuthenticated" class="mt-3 space-y-1.5">
                <a v-if="property.agent.email" :href="`mailto:${property.agent.email}`" class="flex items-center gap-2 text-xs text-brand-navy/80 dark:text-white/75 hover:text-brand-gold transition-colors">
                  <svg class="h-3.5 w-3.5 flex-shrink-0 text-brand-navy/40 dark:text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                  <span class="truncate">{{ property.agent.email }}</span>
                </a>
                <a v-if="property.agent.phone" :href="`tel:${property.agent.phone}`" class="flex items-center gap-2 text-xs text-brand-navy/80 dark:text-white/75 hover:text-brand-gold transition-colors">
                  <svg class="h-3.5 w-3.5 flex-shrink-0 text-brand-navy/40 dark:text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
                  {{ property.agent.phone }}
                </a>
                <p v-if="!property.agent.email && !property.agent.phone" class="text-xs text-brand-navy/45 dark:text-white/40">No contact details provided.</p>
              </div>
              <button v-else type="button" class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline" @click="openAuth('login')">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                Sign in to view contact details
              </button>

              <NuxtLink :to="agentHref(property.agent.id)" class="mt-3 block text-xs font-semibold text-brand-navy/70 dark:text-white/60 hover:text-brand-gold transition-colors">View full profile →</NuxtLink>
            </div>
          </div>
        </aside>
      </div>
    </template>

    <!-- Inquiry Modal -->
    <AppModal :open="showInquiry" title="Send Inquiry" @close="showInquiry = false">
      <form class="p-6 space-y-4" @submit.prevent="sendInquiry">
        <p class="text-sm text-brand-text-secondary">Leave your contact details and we'll connect you with the agent.</p>

        <!-- The API rejected the form. This used to be swallowed — the button
             stopped spinning and nothing happened — so surface it here. -->
        <p v-if="inqError" class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-xl px-4 py-3">
          {{ inqError }}
        </p>

        <!-- Name and email are required server-side; say so up front. -->
        <AppInput v-if="!authStore.isAuthenticated" v-model="inquiryForm.name"  label="Your name"  placeholder="Juan Dela Cruz"   required />
        <AppInput v-if="!authStore.isAuthenticated" v-model="inquiryForm.email" label="Email"      type="email" placeholder="you@example.com" required />
        <AppInput v-if="!authStore.isAuthenticated" v-model="inquiryForm.phone" label="Phone"      placeholder="+63 9XX XXX XXXX" />
        <div>
          <label class="text-sm font-medium text-brand-text-primary">Message <span class="text-red-500">*</span></label>
          <div class="flex flex-wrap gap-2 mt-1.5 mb-2">
            <button
              v-for="q in INQUIRY_QUICK"
              :key="q.label"
              type="button"
              class="text-xs font-semibold px-3 py-1.5 rounded-full border border-gray-200 text-gray-500 hover:border-brand-gold/60 hover:text-brand-navy transition-colors"
              @click="inquiryForm.message = q.text"
            >{{ q.label }}</button>
          </div>
          <textarea v-model="inquiryForm.message" rows="4" placeholder="I'm interested in this property..." class="input-field resize-none" required />
        </div>
        <div class="flex gap-3">
          <AppButton type="button" variant="ghost" full-width @click="showInquiry = false">Cancel</AppButton>
          <AppButton type="submit" variant="primary" full-width :loading="inqLoading">Send</AppButton>
        </div>
      </form>
    </AppModal>

    <!-- Inquiry result — one modal, two states -->
    <AppModal :open="inquiryResult !== null" size="sm" @close="inquiryResult = null">
      <div class="p-8 text-center">
        <!-- Success -->
        <template v-if="inquiryResult === 'success'">
          <div class="mx-auto mb-5 h-16 w-16 rounded-full bg-emerald-50 flex items-center justify-center">
            <svg class="h-8 w-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
          </div>
          <h3 class="font-display text-xl font-bold text-brand-navy">Inquiry sent</h3>
          <p class="mt-2 text-sm text-brand-text-secondary leading-relaxed">
            The agent has been notified and will get back to you
            <template v-if="inquirySentTo"> at <span class="font-medium text-brand-navy">{{ inquirySentTo }}</span></template>.
          </p>
          <AppButton variant="primary" full-width class="mt-6" @click="inquiryResult = null">Done</AppButton>
        </template>

        <!-- Failure -->
        <template v-else>
          <div class="mx-auto mb-5 h-16 w-16 rounded-full bg-red-50 flex items-center justify-center">
            <svg class="h-8 w-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </div>
          <h3 class="font-display text-xl font-bold text-brand-navy">Inquiry not sent</h3>
          <p class="mt-2 text-sm text-brand-text-secondary leading-relaxed">
            {{ inqError || 'Something went wrong on our side. Please try again.' }}
          </p>
          <div class="mt-6 flex gap-3">
            <AppButton variant="ghost" full-width @click="inquiryResult = null">Close</AppButton>
            <AppButton variant="primary" full-width @click="retryInquiry">Try again</AppButton>
          </div>
        </template>
      </div>
    </AppModal>

    <!-- Schedule Viewing Modal -->
    <AppModal :open="showBooking" title="Schedule a Viewing" size="lg" @close="showBooking = false">
      <form class="p-6" @submit.prevent="bookAppointment">
        <p v-if="bookingError || bookError" class="text-sm text-red-600 bg-red-50 px-4 py-3 rounded-md mb-4">{{ bookingError || bookError }}</p>

        <!-- Side-by-side: dates left, times right -->
        <div class="grid grid-cols-[125px_1fr] sm:grid-cols-[185px_1fr] gap-4 sm:gap-5">
          <!-- Dates -->
          <div class="min-w-0">
            <p class="text-[0.6875rem] font-bold uppercase tracking-wider text-gray-400 mb-2">Select a date</p>
            <div class="space-y-1.5 max-h-[18.75rem] overflow-y-auto pr-1">
              <button
                v-for="d in next14Days"
                :key="dateKey(d)"
                type="button"
                :disabled="isDateBlocked(d)"
                class="w-full flex items-center justify-between gap-2 px-3 py-2.5 rounded-xl border text-left transition-colors"
                :class="isDateBlocked(d)
                  ? 'border-gray-100 bg-gray-50 text-gray-300 cursor-not-allowed line-through'
                  : selectedDate === dateKey(d) ? 'bg-brand-navy text-white border-brand-navy' : 'border-gray-200 hover:border-brand-gold text-brand-navy'"
                :title="isDateBlocked(d) ? 'Agent is unavailable this day' : (isDateLimited(d) ? 'Limited availability' : '')"
                @click="pickDate(d)"
              >
                <span class="text-[0.6875rem] font-medium uppercase tracking-wide opacity-70">{{ d.toLocaleDateString('en-PH', { weekday: 'short' }) }}</span>
                <span class="flex items-center gap-1.5">
                  <span v-if="!isDateBlocked(d) && isDateLimited(d)" class="text-[0.5rem] font-bold uppercase tracking-wide text-amber-600 bg-amber-100 px-1.5 py-0.5 rounded-full">Limited</span>
                  <span class="text-sm font-bold">{{ d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) }}</span>
                </span>
              </button>
            </div>
          </div>

          <!-- Times -->
          <div class="min-w-0">
            <p class="text-[0.6875rem] font-bold uppercase tracking-wider text-gray-400 mb-2">Available times</p>
            <div class="max-h-[18.75rem] overflow-y-auto pr-1">
              <p v-if="!selectedDate" class="text-xs text-gray-400 text-center py-12">Pick a date to see times.</p>
              <p v-else-if="loadingSlots" class="text-xs text-gray-400 text-center py-12">Loading…</p>
              <div v-else-if="slots.length" class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                <button
                  v-for="t in slots"
                  :key="t"
                  type="button"
                  class="py-2 rounded-lg border text-xs font-semibold transition-colors"
                  :class="selectedSlot === t ? 'bg-brand-gold text-brand-navy border-brand-gold' : 'border-gray-200 text-brand-navy hover:border-brand-gold'"
                  @click="selectedSlot = t"
                >{{ slotLabel(t) }}</button>
              </div>
              <p v-else class="text-xs text-amber-600 text-center py-12">No available times — pick another date.</p>
            </div>
          </div>
        </div>

        <!-- Notes -->
        <div class="mt-4">
          <label class="text-sm font-medium text-brand-text-primary">Notes (optional)</label>
          <textarea v-model="bookingForm.notes" rows="2" placeholder="Any special requests or questions…" class="input-field mt-1 resize-none" />
        </div>

        <div class="flex gap-3 mt-5">
          <AppButton type="button" variant="ghost" full-width @click="showBooking = false">Cancel</AppButton>
          <AppButton type="submit" variant="primary" full-width :loading="bookLoading">Confirm Booking</AppButton>
        </div>
      </form>
    </AppModal>

    <!-- Lightbox -->
    <Teleport to="body">
      <Transition name="tour-fade">
        <div v-if="lightboxOpen" class="fixed inset-0 z-[100] bg-black/95 flex flex-col p-4 sm:p-8" @click.self="lightboxOpen = false">
          <div class="flex items-center justify-between mb-3 text-white">
            <span class="text-sm font-medium">{{ lightboxIndex + 1 }} / {{ galleryPhotos.length }}</span>
            <button type="button" class="h-9 w-9 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center" @click="lightboxOpen = false">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>
          <div class="flex-1 min-h-0 flex items-center justify-center relative">
            <button v-if="galleryPhotos.length > 1" type="button" class="absolute left-0 h-11 w-11 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white" @click.stop="prevPhoto">
              <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
            </button>
            <img :src="galleryPhotos[lightboxIndex]?.url" class="max-h-full max-w-full object-contain rounded-xl" />
            <button v-if="galleryPhotos.length > 1" type="button" class="absolute right-0 h-11 w-11 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white" @click.stop="nextPhoto">
              <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
            </button>
          </div>
          <div class="flex gap-2 mt-3 overflow-x-auto justify-center">
            <button v-for="(photo, i) in galleryPhotos" :key="photo.id" type="button" class="h-14 w-20 flex-shrink-0 rounded-md overflow-hidden border-2 transition-colors" :class="i === lightboxIndex ? 'border-brand-gold' : 'border-white/20'" @click="lightboxIndex = i">
              <img :src="photo.thumb_url ?? photo.url" class="h-full w-full object-cover" />
            </button>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- Virtual Tour Modal -->
    <Teleport to="body">
      <Transition name="tour-fade">
        <div v-if="showTour" class="fixed inset-0 z-[100] bg-black/90 flex flex-col p-4 sm:p-8" @click.self="showTour = false">
          <div class="flex items-center justify-between mb-3 text-white">
            <h3 class="font-bold flex items-center gap-2">
              <svg class="h-5 w-5 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0zM3 12h18M12 3a15 15 0 010 18M12 3a15 15 0 000 18" /></svg>
              360° Virtual Tour <span class="text-xs text-white/50 font-normal hidden sm:inline">· drag to look around</span>
            </h3>
            <button type="button" class="h-9 w-9 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white" @click="showTour = false">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>
          <div class="flex-1 min-h-0">
            <LazyProperty360Viewer v-if="panoramas[activePano]" :src="panoramas[activePano].url" class="!h-full !rounded-xl" />
          </div>
          <div v-if="panoramas.length > 1" class="flex gap-2 mt-3 overflow-x-auto justify-center">
            <button v-for="(pano, i) in panoramas" :key="pano.id" type="button" class="h-14 w-24 flex-shrink-0 rounded-md overflow-hidden border-2 transition-colors" :class="i === activePano ? 'border-brand-gold' : 'border-white/20'" @click="activePano = i">
              <img :src="pano.thumb_url ?? pano.url" class="h-full w-full object-cover" />
            </button>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.tour-fade-enter-active, .tour-fade-leave-active { transition: opacity .25s ease; }
.tour-fade-enter-from, .tour-fade-leave-to { opacity: 0; }
</style>
