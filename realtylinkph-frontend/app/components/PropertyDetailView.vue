<script setup lang="ts">
import type { PaginatedResponse, Property } from '~/types'
import { PROPERTY_TYPES } from '~/types'

// Shared property-detail view. Rendered by the public page (/properties/[id],
// for guests / ghost buyers) AND the in-account page (/dashboard/properties/[id]).
const route = useRoute()
const id    = Number(route.params.id)

const authStore = useAuthStore()
const { property, loading, error, fetchProperty }      = useProperty()
const { open: openAuth }                               = useAuthModal()
const agentHref                                        = useAgentHref()

await fetchProperty(id)

/* ── Message agent ── In the dashboard the chat opens in the docked Messages
   panel (starting the conversation if this is the first message). */
const NuxtLinkCmp = resolveComponent('NuxtLink')
const { openConversation } = useConversation()
const { openChat } = useDashPanel()
const chatOpening = ref(false)
async function messageAgent() {
  if (chatOpening.value) return
  chatOpening.value = true
  const conv = await openConversation(id)
  chatOpening.value = false
  if (conv) openChat(conv.id)
  else toast.error('Couldn’t open the chat. Please try again.')
}

const showInquiry = ref(false)
const showBooking = ref(false)
/* ?book=1 (Saved's "Book viewing") opens the booking dialog on arrival. */
onMounted(() => {
  if (route.query.book === '1' && authStore.isBuyer && property.value?.status === 'published') showBooking.value = true
})
const showTour    = ref(false)

const galleryPhotos = computed(() => property.value?.photos?.filter(p => !p.is_360) ?? [])
const panoramas     = computed(() => property.value?.photos?.filter(p => p.is_360) ?? [])
const activePano    = ref(0)

/* Warm the tour once the page is idle: the viewer's code (its own lazy chunk)
   and the panorama it opens on, so "360° Virtual Tour" opens straight away
   instead of starting every download on the click. The listing itself still
   loads first; failures just mean the tour loads on open, as before. */
function warmPano(i: number) {
  const p = panoramas.value[i]
  if (!p) return
  import('~/components/Property360Viewer.client.vue').catch(() => { /* loads on open instead */ })
  fetchPanorama(p.preview_url ?? p.url)
}
onMounted(() => {
  if (!panoramas.value.length) return
  const warm = () => warmPano(0)
  if ('requestIdleCallback' in window) window.requestIdleCallback(warm, { timeout: 3000 })
  else setTimeout(warm, 1500)
})

/* ── Lightbox ── */
const lightboxOpen  = ref(false)
const lightboxIndex = ref(0)
function openLightbox(i: number) { lightboxIndex.value = i; lightboxOpen.value = true }
function prevPhoto() { lightboxIndex.value = (lightboxIndex.value - 1 + galleryPhotos.value.length) % galleryPhotos.value.length }
function nextPhoto() { lightboxIndex.value = (lightboxIndex.value + 1) % galleryPhotos.value.length }

/* Booking (pick → review → sent) lives in ScheduleViewingDialog. */

/* The inquiry flow (form → sending → sent / error) lives in PropertyInquiryDialog. */
function openInquiry() {
  showInquiry.value = true
}

const offerLabel  = computed(() => (property.value?.offer_type === 'rent' ? 'For Rent' : 'For Sale'))
const displayPrice = computed(() => property.value ? `₱${Number(property.value.price).toLocaleString('en-PH')}` : '')

const isVerifiedAgent = computed(() => property.value?.agent?.agent_profile?.status === 'approved')

/* ── Back link ───────────────────────────────────────────────────────────
 * Names and returns to wherever the visitor came from ("Back to Saved homes",
 * "Back to History"…), stepping back in history so filters and scroll
 * survive. Opened with no in-app history, it's "Back to browse" (the public
 * page's /properties, or the dashboard's Browse). */
const router = useRouter()
const inDashboard = computed(() => route.path.startsWith('/dashboard'))
const browseHref = computed(() => (inDashboard.value ? '/dashboard/browse' : '/properties'))

/*
 * Layout rules. In the dashboard the page gets less than the screen (sidebar + a
 * docked panel), so it follows the room it really has (cw-*, see tailwind.config).
 * Public pages render on the server, where nothing can be measured, so they keep
 * the screen rules. Written out in full so Tailwind finds every class.
 */
const L = computed(() => inDashboard.value
  ? {
      shell:  'page-shell',
      gallery: 'h-[17rem] cw-sm:h-[26rem] cw-lg:h-[32rem]',
      grid:   'cw-lg:grid-cols-3 gap-6 cw-lg:gap-8',
      span2:  'cw-lg:col-span-2',
      aside:  'cw-lg:col-start-3 cw-lg:row-start-1 cw-lg:row-span-3 cw-lg:sticky cw-lg:top-24',
    }
  : {
      shell:  'max-w-content mx-auto',
      gallery: 'h-[17rem] sm:h-[26rem] lg:h-[32rem]',
      grid:   'lg:grid-cols-3 gap-6 lg:gap-8',
      span2:  'lg:col-span-2',
      aside:  'lg:col-start-3 lg:row-start-1 lg:row-span-3 lg:sticky lg:top-24',
    })

const ORIGINS: Array<[RegExp, string]> = [
  [/^\/(dashboard\/browse|properties)(\?|$)/, 'browse'],
  [/^\/dashboard\/saved/, 'Saved homes'],
  [/^\/dashboard\/history/, 'History'],
  [/^\/dashboard\/appointments/, 'Appointments'],
  [/^\/dashboard\/reviews/, 'Reviews'],
  [/^\/(dashboard\/)?agents\//, 'the agent'],
  [/^\/(dashboard\/)?properties\/\d/, 'the previous listing'],
  [/^\/dashboard\/?(\?|$)/, 'dashboard'],
  [/^\/?(\?|$)/, 'home'],
]
const origin = ref<string | null>(null)   // null: no in-app history, or a page not listed
function readOrigin() {
  const back = window.history.state?.back as string | null | undefined
  origin.value = back ? (ORIGINS.find(([re]) => re.test(back))?.[1] ?? 'back') : null
}
onMounted(readOrigin)
watch(() => route.fullPath, () => nextTick(readOrigin))   // "Similar homes" → another listing
const backLabel = computed(() =>
  origin.value === null ? 'Back to browse' : origin.value === 'back' ? 'Back' : `Back to ${origin.value}`)

function goBack() {
  if (origin.value !== null) router.back()
  else router.push(browseHref.value)
}

/* ── Gallery slider ──────────────────────────────────────────────────────
 * One large photo with prev/next, a counter, swipe on touch, arrow keys when
 * focused, and a thumbnail strip. Clicking the photo opens the lightbox. */
const slide = ref(0)
const slideDir = ref<'next' | 'prev'>('next')
const thumbStrip = ref<HTMLElement | null>(null)
function goSlide(i: number) {
  const n = galleryPhotos.value.length
  if (!n) return
  const next = (i + n) % n
  slideDir.value = next > slide.value || (slide.value === n - 1 && next === 0) ? 'next' : 'prev'
  slide.value = next
  // Keep the active thumbnail in view without moving the page.
  nextTick(() => {
    const el = thumbStrip.value?.children[next] as HTMLElement | undefined
    const strip = thumbStrip.value
    if (el && strip) strip.scrollTo({ left: el.offsetLeft - strip.clientWidth / 2 + el.clientWidth / 2, behavior: 'smooth' })
  })
}
let touchX: number | null = null
function onTouchStart(e: TouchEvent) { touchX = e.touches[0]?.clientX ?? null }
function onTouchEnd(e: TouchEvent) {
  if (touchX === null) return
  const dx = (e.changedTouches[0]?.clientX ?? touchX) - touchX
  if (Math.abs(dx) > 40) goSlide(slide.value + (dx < 0 ? 1 : -1))
  touchX = null
}

/* Lightbox keys: ← → to page, Esc to close. */
function onLightboxKey(e: KeyboardEvent) {
  if (!lightboxOpen.value) return
  if (e.key === 'ArrowLeft') prevPhoto()
  else if (e.key === 'ArrowRight') nextPhoto()
  else if (e.key === 'Escape') lightboxOpen.value = false
}
onMounted(() => window.addEventListener('keydown', onLightboxKey))
onUnmounted(() => window.removeEventListener('keydown', onLightboxKey))

/* ── Save + share ────────────────────────────────────────────────────────── */
const toast = useToast()
const { toggleFavorite } = useFavorite()
const saved = ref(property.value?.is_favorited ?? false)
const saving = ref(false)
async function toggleSave() {
  if (!authStore.isAuthenticated) { openAuth('login'); return }
  if (saving.value || !property.value) return
  saving.value = true
  const prev = saved.value
  saved.value = !prev // optimistic
  try {
    saved.value = await toggleFavorite(property.value.id)
    toast.success(saved.value ? 'Saved' : 'Removed from saved')
  } catch {
    saved.value = prev
    toast.error('Could not update saved listings')
  } finally {
    saving.value = false
  }
}
async function share() {
  if (!property.value) return
  // Always share the public page — the dashboard copy needs an account.
  const url = `${window.location.origin}/properties/${property.value.id}`
  try {
    if (navigator.share) await navigator.share({ title: property.value.title, url })
    else { await navigator.clipboard.writeText(url); toast.success('Link copied') }
  } catch { /* the share sheet was dismissed */ }
}

/* ── Owner, listed date, long descriptions ──────────────────────────────── */
const isOwner = computed(() => !!authStore.user && authStore.user.id === property.value?.agent_id)

const listedAgo = computed(() => {
  const t = property.value?.created_at ? new Date(property.value.created_at).getTime() : NaN
  if (!Number.isFinite(t)) return ''
  const days = Math.floor((Date.now() - t) / 86_400_000)
  if (days < 1) return 'Today'
  if (days === 1) return 'Yesterday'
  if (days < 30) return `${days} days ago`
  const months = Math.floor(days / 30)
  return months < 12 ? `${months} mo ago` : `${Math.floor(months / 12)} yr ago`
})

const DESC_CLAMP = 480
const descOpen = ref(false)
const descLong = computed(() => (property.value?.description?.length ?? 0) > DESC_CLAMP)

/* Spec tiles — only the facts this listing actually has. */
const facts = computed(() => {
  const p = property.value
  if (!p) return []
  return [
    { show: !!p.bedrooms,   label: 'Bedrooms',   value: String(p.bedrooms),               icon: 'M2 17h20M4 17V9a2 2 0 012-2h12a2 2 0 012 2v8M4 11h16' },
    { show: !!p.bathrooms,  label: 'Bathrooms',  value: String(p.bathrooms),              icon: 'M4 12h16v3a4 4 0 01-4 4H8a4 4 0 01-4-4v-3zM6 12V6a2 2 0 012-2 2 2 0 012 2' },
    { show: !!p.floor_area, label: 'Floor area', value: `${Number(p.floor_area)} sqm`,    icon: 'M4 8V4h4M4 16v4h4M20 8V4h-4M20 16v4h-4' },
    { show: !!p.lot_area,   label: 'Lot area',   value: `${Number(p.lot_area)} sqm`,      icon: 'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l5.447 2.724A1 1 0 0021 19.382V8.618a1 1 0 00-1.447-.894L15 4m0 13V4m0 0L9 7' },
    { show: true,           label: 'Type',       value: PROPERTY_TYPES[p.type],           icon: 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75' },
    { show: !!listedAgo.value, label: 'Listed',  value: listedAgo.value,                  icon: 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z' },
  ].filter(f => f.show)
})

/* ── More in this city ──────────────────────────────────────────────────
 * Loaded after the page is up (never delays the listing itself); hidden when
 * there's nothing else nearby. */
const api = useApi()
const similar = ref<Property[]>([])
const cityName = computed(() => {
  const parts = (property.value?.address ?? '').split(',').map(s => s.trim()).filter(Boolean)
  return parts.find(s => /\bcity\b/i.test(s)) ?? parts[parts.length - 1] ?? ''
})
/* Feeds "Recently viewed" on the buyer dashboard (signed-in buyers only). */
const { record: recordView } = useRecentlyViewed()
onMounted(() => {
  if (property.value && authStore.isBuyer) recordView(property.value)
})

onMounted(async () => {
  if (!cityName.value) return
  try {
    const res = await api.get<PaginatedResponse<Property>>('/properties', { search: cityName.value })
    similar.value = res.data.filter(p => p.id !== id).slice(0, 4)
  } catch { /* optional section */ }
})
</script>

<template>
  <div :class="L.shell">
    <div v-if="loading" class="space-y-4">
      <AppSkeleton width="100%" height="460px" rounded="lg" />
      <AppSkeleton width="60%" height="32px" />
      <AppSkeleton :lines="4" />
    </div>

    <div v-else-if="error" class="text-center py-16 text-red-500">{{ error }}</div>

    <template v-else-if="property">
      <!-- ░░ Back to wherever they came from ░░ -->
      <a
        :href="browseHref"
        class="detail-rise group inline-flex items-center gap-2 text-sm font-semibold text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white transition-colors"
        @click.prevent="goBack"
      >
        <span class="h-8 w-8 rounded-full flex items-center justify-center ring-1 ring-brand-navy/10 dark:ring-white/15 bg-white/70 dark:bg-white/[0.05] group-hover:-translate-x-0.5 transition-transform">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
        </span>
        {{ backLabel }}
      </a>

      <!-- ░░ Gallery: one large slide + thumbnail strip ░░ -->
      <div v-if="galleryPhotos.length" class="mt-5 detail-rise" style="animation-delay: 60ms">
        <div
          :class="L.gallery"
          class="relative overflow-hidden rounded-[1.75rem] bg-brand-navy/5 dark:bg-white/5
                 shadow-[0_30px_60px_-34px_rgba(11,42,92,0.55)] ring-1 ring-brand-navy/[0.06] dark:ring-white/10 group/gal
                 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold"
          tabindex="0"
          role="region"
          aria-roledescription="carousel"
          :aria-label="`Photos of ${property.title}`"
          @keydown.left.prevent="goSlide(slide - 1)"
          @keydown.right.prevent="goSlide(slide + 1)"
          @touchstart.passive="onTouchStart"
          @touchend="onTouchEnd"
        >
          <Transition :name="slideDir === 'next' ? 'slide-next' : 'slide-prev'">
            <button
              :key="galleryPhotos[slide]!.id"
              type="button"
              class="absolute inset-0 cursor-zoom-in"
              :aria-label="`Open photo ${slide + 1} full screen`"
              @click="openLightbox(slide)"
            >
              <img
                :src="galleryPhotos[slide]!.url"
                :alt="`${property.title} — photo ${slide + 1}`"
                class="h-full w-full object-cover transition-transform duration-[1.2s] ease-out group-hover/gal:scale-[1.02]"
                :fetchpriority="slide === 0 ? 'high' : undefined"
              />
            </button>
          </Transition>

          <!-- Soft fades so the controls read on any photo -->
          <div class="pointer-events-none absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-black/35 to-transparent" />
          <div class="pointer-events-none absolute inset-x-0 bottom-0 h-28 bg-gradient-to-t from-black/45 to-transparent" />

          <!-- Prev / next: always on touch screens, on hover with a mouse -->
          <template v-if="galleryPhotos.length > 1">
            <button
              type="button"
              class="absolute left-3 sm:left-4 top-1/2 -translate-y-1/2 h-11 w-11 rounded-full flex items-center justify-center
                     bg-white/90 text-brand-navy shadow-lg backdrop-blur hover:bg-white dark:bg-brand-navy/75 dark:text-white dark:ring-1 dark:ring-white/20 dark:hover:bg-brand-navy hover:scale-105 transition
                     [@media(hover:hover)]:opacity-0 group-hover/gal:opacity-100 focus-visible:opacity-100"
              aria-label="Previous photo"
              @click="goSlide(slide - 1)"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
            </button>
            <button
              type="button"
              class="absolute right-3 sm:right-4 top-1/2 -translate-y-1/2 h-11 w-11 rounded-full flex items-center justify-center
                     bg-white/90 text-brand-navy shadow-lg backdrop-blur hover:bg-white dark:bg-brand-navy/75 dark:text-white dark:ring-1 dark:ring-white/20 dark:hover:bg-brand-navy hover:scale-105 transition
                     [@media(hover:hover)]:opacity-0 group-hover/gal:opacity-100 focus-visible:opacity-100"
              aria-label="Next photo"
              @click="goSlide(slide + 1)"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
            </button>
          </template>

          <!-- Top right: save + share -->
          <div class="absolute top-3 right-3 sm:top-4 sm:right-4 flex gap-2">
            <button
              type="button"
              class="h-10 w-10 rounded-full flex items-center justify-center bg-white/90 shadow-lg backdrop-blur hover:bg-white dark:bg-brand-navy/75 dark:ring-1 dark:ring-white/20 dark:hover:bg-brand-navy hover:scale-105 active:scale-95 transition"
              :class="saved ? 'text-red-500' : 'text-brand-navy dark:text-white'"
              :aria-pressed="saved"
              :aria-label="saved ? 'Remove from saved' : 'Save listing'"
              @click="toggleSave"
            >
              <svg class="h-5 w-5 transition-transform" :class="saved ? 'scale-110' : ''" :fill="saved ? 'currentColor' : 'none'" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" />
              </svg>
            </button>
            <button
              type="button"
              class="h-10 w-10 rounded-full flex items-center justify-center bg-white/90 text-brand-navy shadow-lg backdrop-blur hover:bg-white dark:bg-brand-navy/75 dark:text-white dark:ring-1 dark:ring-white/20 dark:hover:bg-brand-navy hover:scale-105 active:scale-95 transition"
              aria-label="Share listing"
              @click="share"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z" />
              </svg>
            </button>
          </div>

          <!-- Bottom left: 360° tour -->
          <button
            v-if="panoramas.length"
            type="button"
            class="absolute bottom-3 left-3 sm:bottom-4 sm:left-4 inline-flex items-center gap-2 rounded-full bg-brand-gold pl-2 pr-4 py-2 text-sm font-bold text-brand-navy
                   shadow-[0_8px_24px_rgba(212,165,74,0.5)] hover:bg-brand-gold-light hover:-translate-y-0.5 transition"
            @mouseenter="warmPano(activePano)"
            @focus="warmPano(activePano)"
            @click="showTour = true"
          >
            <span class="relative h-7 w-7 rounded-full bg-brand-navy/10 flex items-center justify-center">
              <span class="absolute inset-0 rounded-full bg-white/40 animate-pulse-ring motion-reduce:hidden" aria-hidden="true" />
              <svg class="relative h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0zM3 12h18M12 3a15 15 0 010 18M12 3a15 15 0 000 18" /></svg>
            </span>
            360° Virtual Tour
          </button>

          <!-- Bottom right: counter -->
          <span
            v-if="galleryPhotos.length > 1"
            class="absolute bottom-3 right-3 sm:bottom-4 sm:right-4 rounded-full bg-black/55 px-3 py-1 text-xs font-bold text-white tabular-nums backdrop-blur"
            aria-live="polite"
          >
            {{ slide + 1 }} / {{ galleryPhotos.length }}
          </span>
        </div>

        <!-- Thumbnails -->
        <div v-if="galleryPhotos.length > 1" ref="thumbStrip" class="mt-3 flex gap-2.5 overflow-x-auto no-scrollbar scroll-smooth pb-1">
          <button
            v-for="(photo, i) in galleryPhotos"
            :key="photo.id"
            type="button"
            class="relative h-16 w-24 sm:h-20 sm:w-28 flex-none overflow-hidden rounded-xl transition-all duration-200"
            :class="i === slide
              ? 'ring-2 ring-brand-gold ring-offset-2 ring-offset-white dark:ring-offset-brand-navy-deep'
              : 'opacity-60 hover:opacity-100'"
            :aria-label="`Show photo ${i + 1}`"
            :aria-current="i === slide ? 'true' : undefined"
            @click="goSlide(i)"
          >
            <img :src="photo.thumb_url ?? photo.url" alt="" loading="lazy" class="h-full w-full object-cover" />
          </button>
        </div>
      </div>
      <div v-else class="mt-5 aspect-[16/7] rounded-[1.75rem] bg-brand-navy/5 dark:bg-white/5 flex flex-col items-center justify-center gap-2 ring-1 ring-brand-navy/[0.06] dark:ring-white/10">
        <svg class="h-10 w-10 text-brand-navy/25 dark:text-white/25" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" /></svg>
        <p class="text-brand-text-secondary dark:text-white/50 text-sm">No photos yet</p>
      </div>

      <!-- ░░ Body ░░
           Grid so the action card can sit second on a phone (right after the
           title, where the price and buttons are needed) yet hold the whole
           right column on desktop. -->
      <div class="mt-8 grid grid-cols-1 items-start" :class="L.grid">

        <!-- Title, price, facts -->
        <section class="detail-card detail-rise" :class="L.span2" style="animation-delay: 120ms">
          <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span
                  class="text-[0.6875rem] font-bold uppercase tracking-wide px-2.5 py-1 rounded-full"
                  :class="property.offer_type === 'rent' ? 'bg-brand-navy text-white dark:bg-white dark:text-brand-navy' : 'bg-brand-gold text-brand-navy'"
                >{{ offerLabel }}</span>
                <span class="text-[0.6875rem] font-bold uppercase tracking-wide px-2.5 py-1 rounded-full bg-brand-navy/[0.06] text-brand-navy/80 dark:bg-white/10 dark:text-white/80">{{ PROPERTY_TYPES[property.type] }}</span>
                <span v-if="panoramas.length" class="text-[0.6875rem] font-bold uppercase tracking-wide px-2.5 py-1 rounded-full bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold">360° tour</span>
              </div>
              <h1 class="mt-3 font-heading text-[1.875rem] sm:text-4xl font-semibold tracking-[-0.02em] leading-[1.1] text-brand-navy dark:text-white">{{ property.title }}</h1>
              <a href="#location" class="mt-2 inline-flex items-start gap-1.5 text-sm text-brand-text-secondary dark:text-white/60 hover:text-brand-gold-deep dark:hover:text-brand-gold transition-colors">
                <svg class="mt-0.5 h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                {{ property.address }}
              </a>
            </div>
            <div class="sm:text-right">
              <p class="font-heading text-[2rem] sm:text-[2.5rem] leading-none font-semibold text-brand-navy dark:text-white tabular-nums">{{ displayPrice }}</p>
              <p class="mt-1 text-xs text-brand-text-secondary dark:text-white/50">{{ property.offer_type === 'rent' ? 'per month' : 'asking price' }}</p>
            </div>
          </div>

          <dl v-if="facts.length" class="mt-6 grid grid-cols-2 sm:grid-cols-3 gap-3">
            <div
              v-for="f in facts"
              :key="f.label"
              v-spotlight
              class="relative overflow-hidden rounded-2xl px-4 py-3.5 bg-brand-cream dark:bg-white/[0.04] ring-1 ring-brand-navy/[0.05] dark:ring-white/10
                     hover:ring-brand-gold/50 transition-colors"
            >
              <dt class="flex items-center gap-1.5 text-[0.6875rem] font-bold uppercase tracking-wide text-brand-navy/50 dark:text-white/45">
                <svg class="h-4 w-4 text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="f.icon" /></svg>
                {{ f.label }}
              </dt>
              <dd class="mt-1 text-[0.9375rem] font-semibold text-brand-navy dark:text-white tabular-nums">{{ f.value }}</dd>
            </div>
          </dl>
        </section>

        <!-- ░░ Action card (2nd on phones; whole right column on desktop) ░░ -->
        <aside class="detail-rise" :class="L.aside" style="animation-delay: 180ms">
          <div class="detail-card !p-0 overflow-hidden">
            <!-- Owner -->
            <div v-if="isOwner" class="p-6">
              <p class="font-heading text-xl font-semibold text-brand-navy dark:text-white">This is your listing</p>
              <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/55">Buyers see your name, verified badge and contact details here.</p>
              <NuxtLink
                :to="`/dashboard/listings/${property.id}/edit`"
                class="mt-5 w-full inline-flex items-center justify-center gap-2 rounded-full bg-brand-navy px-5 py-3 text-sm font-bold text-white dark:bg-white dark:text-brand-navy hover:-translate-y-0.5 hover:shadow-lg transition"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                Edit listing
              </NuxtLink>
              <NuxtLink to="/dashboard/listings" class="mt-2.5 w-full inline-flex items-center justify-center rounded-full px-5 py-3 text-sm font-semibold text-brand-navy/75 ring-1 ring-brand-navy/15 dark:text-white/75 dark:ring-white/15 hover:ring-brand-gold transition">
                Manage my listings
              </NuxtLink>
            </div>

            <!-- Buyers / guests -->
            <div v-else class="p-6">
              <p class="text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-brand-navy/45 dark:text-white/40">Interested?</p>
              <p class="mt-1 font-heading text-xl font-semibold text-brand-navy dark:text-white">
                {{ property.offer_type === 'rent' ? 'See it before you rent' : 'See it before you buy' }}
              </p>

              <div class="mt-5 space-y-2.5">
                <template v-if="authStore.isAuthenticated && authStore.isBuyer">
                  <button
                    type="button"
                    class="w-full inline-flex items-center justify-center gap-2 rounded-full bg-brand-gold px-5 py-3 text-sm font-bold text-brand-navy
                           shadow-[0_6px_18px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light hover:-translate-y-0.5 transition"
                    @click="showBooking = true"
                  >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                    Schedule a viewing
                  </button>
                  <!-- Inside the dashboard: open the chat in the docked Messages
                       panel. On the public page: go to the Messages page. -->
                  <component
                    :is="inDashboard ? 'button' : NuxtLinkCmp"
                    v-if="property.agent"
                    :type="inDashboard ? 'button' : undefined"
                    :to="inDashboard ? undefined : `/dashboard/messages?agent=${property.agent.id}&property=${id}`"
                    :disabled="chatOpening || undefined"
                    class="w-full inline-flex items-center justify-center gap-2 rounded-full px-5 py-3 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 dark:text-white dark:ring-white/20 hover:ring-brand-gold transition disabled:opacity-60"
                    @click="inDashboard && messageAgent()"
                  >
                    <span v-if="chatOpening" class="h-4 w-4 rounded-full border-2 border-brand-gold border-t-transparent animate-spin" aria-hidden="true" />
                    <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                    Message agent
                  </component>
                </template>
                <template v-else-if="!authStore.isAuthenticated">
                  <button
                    type="button"
                    class="w-full inline-flex items-center justify-center gap-2 rounded-full bg-brand-gold px-5 py-3 text-sm font-bold text-brand-navy
                           shadow-[0_6px_18px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light hover:-translate-y-0.5 transition"
                    @click="openInquiry"
                  >
                    Send an inquiry
                  </button>
                  <button
                    type="button"
                    class="w-full inline-flex items-center justify-center rounded-full px-5 py-3 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 dark:text-white dark:ring-white/20 hover:ring-brand-gold transition"
                    @click="openAuth('login')"
                  >
                    Sign in to schedule a viewing
                  </button>
                </template>
                <p v-else class="text-sm text-brand-text-secondary dark:text-white/55">Viewings and messages are for buyer accounts.</p>
              </div>
            </div>

            <!-- Listed by -->
            <div v-if="property.agent" class="bg-brand-cream/70 dark:bg-white/[0.03] border-t border-brand-navy/[0.06] dark:border-white/10">
              <!-- The agent's cover as a strip, so the card carries their brand -->
              <AgentCover :src="property.agent.cover_url" :position="property.agent.cover_position ?? 50" compact class="h-16">
                <p class="absolute top-2.5 left-6 text-[0.625rem] font-bold uppercase tracking-[0.16em] text-white/90 drop-shadow">Listed by</p>
              </AgentCover>
              <div class="px-6 pb-5">
              <NuxtLink :to="agentHref(property.agent.id)" class="group flex items-end gap-3 -mt-5">
                <span class="relative flex-none rounded-full ring-4 ring-white dark:ring-[#0d2550]">
                  <AppAvatar :name="property.agent.name" :src="property.agent.avatar" size="md" />
                  <span v-if="isVerifiedAgent" class="absolute -bottom-1 -right-1 rounded-full bg-white dark:bg-brand-navy-mid p-px"><AppVerifiedTick size="sm" /></span>
                </span>
                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-semibold text-brand-navy dark:text-white truncate group-hover:text-brand-gold-deep dark:group-hover:text-brand-gold transition-colors">{{ property.agent.name }}</span>
                  <span v-if="isVerifiedAgent" class="block text-xs font-semibold text-brand-gold-deep dark:text-brand-gold">PRC-verified agent</span>
                </span>
                <svg class="h-4 w-4 text-brand-navy/35 dark:text-white/35 group-hover:translate-x-0.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
              </NuxtLink>

              <div v-if="authStore.isAuthenticated" class="mt-4 space-y-2">
                <a v-if="property.agent.email" :href="`mailto:${property.agent.email}`" class="flex items-center gap-2 text-xs text-brand-navy/80 dark:text-white/75 hover:text-brand-gold-deep dark:hover:text-brand-gold transition-colors">
                  <svg class="h-3.5 w-3.5 flex-shrink-0 text-brand-navy/40 dark:text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                  <span class="truncate">{{ property.agent.email }}</span>
                </a>
                <a v-if="property.agent.phone" :href="`tel:${property.agent.phone}`" class="flex items-center gap-2 text-xs text-brand-navy/80 dark:text-white/75 hover:text-brand-gold-deep dark:hover:text-brand-gold transition-colors">
                  <svg class="h-3.5 w-3.5 flex-shrink-0 text-brand-navy/40 dark:text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
                  {{ property.agent.phone }}
                </a>
                <p v-if="!property.agent.email && !property.agent.phone" class="text-xs text-brand-navy/45 dark:text-white/40">No contact details provided.</p>
              </div>
              <button v-else type="button" class="mt-4 inline-flex items-center gap-1.5 text-xs font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline" @click="openAuth('login')">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                Sign in to view contact details
              </button>
              </div>
            </div>
          </div>
        </aside>

        <!-- About -->
        <section v-if="property.description" v-reveal class="detail-card" :class="L.span2">
          <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">About this property</h2>
          <div class="relative mt-3">
            <p
              class="text-[0.9375rem] text-brand-text-secondary dark:text-white/70 leading-relaxed whitespace-pre-line"
              :class="descLong && !descOpen ? 'line-clamp-6' : ''"
            >{{ property.description }}</p>
            <div v-if="descLong && !descOpen" class="pointer-events-none absolute inset-x-0 bottom-0 h-10 bg-gradient-to-t from-white dark:from-[#0d2550] to-transparent" />
          </div>
          <button
            v-if="descLong"
            type="button"
            class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline underline-offset-4"
            :aria-expanded="descOpen"
            @click="descOpen = !descOpen"
          >
            {{ descOpen ? 'Show less' : 'Read more' }}
            <svg class="h-4 w-4 transition-transform" :class="descOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
          </button>
        </section>

        <!-- Location -->
        <section id="location" v-reveal class="detail-card scroll-mt-28" :class="L.span2">
          <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">Location</h2>
          <p class="mt-1 mb-4 text-sm text-brand-text-secondary dark:text-white/60">{{ property.address }}</p>
          <div class="overflow-hidden rounded-2xl ring-1 ring-brand-navy/[0.06] dark:ring-white/10">
            <PropertyLocationMap :lat="property.lat" :lng="property.lng" :address="property.address" />
          </div>
        </section>

        <!-- Getting around: from me + nearby essentials -->
        <section v-if="property.status === 'published' && property.lat && property.lng" id="nearby" v-reveal class="detail-card scroll-mt-28" :class="L.span2">
          <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">Getting around</h2>
          <p class="mt-1 mb-5 text-sm text-brand-text-secondary dark:text-white/60">How close this home is to you and to everyday essentials.</p>
          <ClientOnly>
            <NearbyEssentials :property="property" />
          </ClientOnly>
        </section>
      </div>

      <!-- ░░ More in this city ░░ -->
      <section v-if="similar.length" v-reveal class="mt-14">
        <div class="flex items-end justify-between gap-4 mb-5">
          <h2 class="font-heading text-2xl sm:text-3xl font-semibold tracking-[-0.015em] text-brand-navy dark:text-white">
            More in <em class="italic font-medium text-gold-metal pr-[0.08em]">{{ cityName }}</em>
          </h2>
          <NuxtLink
            :to="{ path: browseHref, query: { search: cityName } }"
            class="group inline-flex flex-none items-center gap-1.5 text-sm font-semibold text-brand-navy hover:text-brand-gold-deep dark:text-white dark:hover:text-brand-gold"
          >
            See all
            <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
          </NuxtLink>
        </div>
        <PropertyGrid :properties="similar" stagger />
      </section>
    </template>

    <!-- Inquiry: form → sending → sent / error, all in one dialog -->
    <PropertyInquiryDialog v-if="property" :open="showInquiry" :property="property" @close="showInquiry = false" />

    <!-- Schedule a viewing: pick → review → sent / error, all in one dialog -->
    <ScheduleViewingDialog v-if="property" :open="showBooking" :property="property" @close="showBooking = false" />

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
            <!-- Opens on the light preview (if any), then swaps in the full original -->
            <LazyProperty360Viewer
              v-if="panoramas[activePano]"
              :src="panoramas[activePano].preview_url ?? panoramas[activePano].url"
              :full-src="panoramas[activePano].preview_url ? panoramas[activePano].url : null"
              class="!h-full !rounded-xl"
            />
          </div>
          <div v-if="panoramas.length > 1" class="flex gap-2 mt-3 overflow-x-auto justify-center">
            <button v-for="(pano, i) in panoramas" :key="pano.id" type="button" class="h-14 w-24 flex-shrink-0 rounded-md overflow-hidden border-2 transition-colors" :class="i === activePano ? 'border-brand-gold' : 'border-white/20'" @mouseenter="warmPano(i)" @click="activePano = i">
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

/* One surface for every block on the page. */
.detail-card {
  @apply rounded-[1.75rem] bg-white dark:bg-[#0d2550] p-6 sm:p-7
         ring-1 ring-brand-navy/[0.06] dark:ring-white/10
         shadow-[0_18px_44px_-30px_rgba(11,42,92,0.45)];
}

/* Quick, once-only rise for what's on screen at load (back link, gallery,
   title, action card). Lower blocks use v-reveal as they scroll in. */
.detail-rise { animation: detail-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes detail-rise {
  from { opacity: 0; transform: translateY(12px); }
  to   { opacity: 1; transform: none; }
}

/* Gallery slides: a short crossfade with a nudge in the travel direction. */
.slide-next-enter-active, .slide-next-leave-active,
.slide-prev-enter-active, .slide-prev-leave-active { transition: opacity 0.45s ease, transform 0.55s cubic-bezier(0.22, 1, 0.36, 1); }
.slide-next-enter-from { opacity: 0; transform: translateX(3%); }
.slide-next-leave-to   { opacity: 0; transform: translateX(-3%); }
.slide-prev-enter-from { opacity: 0; transform: translateX(-3%); }
.slide-prev-leave-to   { opacity: 0; transform: translateX(3%); }

.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }

@media (prefers-reduced-motion: reduce) {
  .detail-rise { animation: none; }
  .slide-next-enter-active, .slide-next-leave-active,
  .slide-prev-enter-active, .slide-prev-leave-active { transition: none; }
}
</style>
