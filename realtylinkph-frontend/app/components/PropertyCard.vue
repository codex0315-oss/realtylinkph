<script setup lang="ts">
import type { Property } from '~/types'
import { PROPERTY_TYPES } from '~/types'

interface Props {
  property: Property
  /** Skip the saved/removed toast; the parent shows its own (Saved's Undo). */
  quietToggle?: boolean
}

const props = defineProps<Props>()
const emit = defineEmits<{ toggle: [payload: { id: number; favorited: boolean }] }>()

const authStore = useAuthStore()
const authModal = useAuthModal()
const { toggleFavorite } = useFavorite()
const toast = useToast()
const propertyHref = usePropertyHref()

const currentPhoto = ref(0)
const photos = computed(() => props.property.photos ?? [])
const has360 = computed(() => photos.value.some(p => p.is_360))

const favorited = ref(props.property.is_favorited ?? false)
const favLoading = ref(false)

watch(() => props.property.is_favorited, (v) => { favorited.value = v ?? false })

async function onToggleFavorite(e: MouseEvent) {
  e.preventDefault()
  if (!authStore.isAuthenticated) {
    authModal.open('login')
    return
  }
  if (favLoading.value) return
  favLoading.value = true
  const prev = favorited.value
  favorited.value = !prev // optimistic
  try {
    favorited.value = await toggleFavorite(props.property.id)
    if (!props.quietToggle) {
      if (favorited.value) toast.success('Saved')
      else toast.info('Removed from saved')
    }
    emit('toggle', { id: props.property.id, favorited: favorited.value })
  } catch {
    favorited.value = prev // revert on error
    toast.error('Could not update saved listings')
  } finally {
    favLoading.value = false
  }
}

const displayPrice = computed(() => {
  const n = Number(props.property.price)
  const amount = n >= 1_000_000
    ? `₱${(n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1)}M`
    : n >= 1_000
      ? `₱${(n / 1_000).toFixed(0)}K`
      : `₱${n.toLocaleString('en-PH')}`
  // A rent figure without "/mo" next to a sale price is genuinely misleading.
  return props.property.offer_type === 'rent' ? `${amount}/mo` : amount
})

function prevPhoto(e: MouseEvent) {
  e.preventDefault()
  currentPhoto.value = (currentPhoto.value - 1 + photos.value.length) % photos.value.length
}
function nextPhoto(e: MouseEvent) {
  e.preventDefault()
  currentPhoto.value = (currentPhoto.value + 1) % photos.value.length
}

/*
 * Card copy, Airbnb-style: a short generated headline that's always the same
 * shape ("Condo in Cebu City"), the agent's own title demoted to a quiet
 * second line, then specs and price. Agent titles are free text and often
 * shouty ALL CAPS, so they can't carry the headline — they truncated into
 * "RARE PENTHOUSE LOFT…" and every card ended at a different height.
 */
const city = computed(() => {
  const parts = (props.property.address ?? '').split(',').map(s => s.trim()).filter(Boolean)
  if (!parts.length) return 'the Philippines'
  return parts.find(p => /\bcity\b/i.test(p)) ?? parts[parts.length - 1]!
})

const headline = computed(() => {
  const type = PROPERTY_TYPES[props.property.type] ?? 'Property'
  return `${type} in ${city.value}`
})

/** Agent titles are often ALL CAPS; soften them so they read as a subtitle. */
const subtitle = computed(() => {
  const t = (props.property.title ?? '').trim()
  if (!t) return ''
  const letters = t.replace(/[^A-Za-z]/g, '')
  const shouty = letters.length > 3 && letters === letters.toUpperCase()
  return shouty
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
})

const offerLabel = computed(() => (props.property.offer_type === 'rent' ? 'For Rent' : 'For Sale'))

/* Spec chips with icons. Areas drop trailing ".00" — "52.00 m²" is noise. */
const ICON = {
  bed:  'M2.25 18.75V12a2.25 2.25 0 012.25-2.25h15A2.25 2.25 0 0121.75 12v6.75M2.25 18.75h19.5M2.25 18.75v1.5m19.5-1.5v1.5M5.25 9.75V7.5A1.5 1.5 0 016.75 6h3.75A1.5 1.5 0 0112 7.5v2.25m0 0V7.5A1.5 1.5 0 0113.5 6h3.75a1.5 1.5 0 011.5 1.5v2.25',
  bath: 'M3.75 12h16.5v2.25a4.5 4.5 0 01-4.5 4.5h-7.5a4.5 4.5 0 01-4.5-4.5V12zM6 12V5.625A1.875 1.875 0 017.875 3.75h.375A1.875 1.875 0 0110.125 5.625M7.5 18.75L6.75 21m10.5-2.25l.75 2.25',
  area: 'M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15',
}
const area = (v: string | number) => `${Number(v).toLocaleString('en-PH', { maximumFractionDigits: 1 })} m²`

const specs = computed(() => {
  const p = props.property
  const out: Array<{ icon: string; text: string; label: string }> = []
  if (p.bedrooms)   out.push({ icon: ICON.bed,  text: String(p.bedrooms),  label: `${p.bedrooms} bedroom${p.bedrooms === 1 ? '' : 's'}` })
  if (p.bathrooms)  out.push({ icon: ICON.bath, text: String(p.bathrooms), label: `${p.bathrooms} bathroom${p.bathrooms === 1 ? '' : 's'}` })
  if (p.floor_area) out.push({ icon: ICON.area, text: area(p.floor_area),  label: 'Floor area' })
  else if (p.lot_area) out.push({ icon: ICON.area, text: `${area(p.lot_area)} lot`, label: 'Lot area' })
  return out
})
</script>

<template>
  <!--
    A soft white card: the photo bleeds to the card edges, details sit in a
    padded body, and the price is set apart on its own line so it can be
    scanned down a row. Hover lifts the card and turns the arrow gold.
  -->
  <NuxtLink
    :to="propertyHref(property.id)"
    class="group flex h-full flex-col overflow-hidden rounded-2xl bg-white dark:bg-brand-navy-mid
           border-2 border-brand-navy dark:border-brand-gold
           shadow-[0_1px_2px_rgba(11,42,92,0.04)] hover:shadow-[0_22px_44px_-22px_rgba(11,42,92,0.38)]
           dark:hover:shadow-[0_22px_44px_-22px_rgba(0,0,0,0.6),0_0_22px_-6px_rgba(212,165,74,0.5)]
           hover:-translate-y-1 transition-all duration-300"
  >
    <!-- Photo -->
    <div class="relative aspect-[4/3] bg-gray-100 dark:bg-white/5 overflow-hidden">
      <template v-if="photos.length">
        <img
          :src="photos[currentPhoto]?.thumb_url ?? photos[currentPhoto]?.url"
          :alt="property.title"
          loading="lazy"
          decoding="async"
          class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
        />
        <!-- Prev / next sit on the photo, not the page, so they must not follow
             the theme: a navy chevron on a white disc became white-on-white in
             dark mode once the global shim remapped `text-brand-navy`. Dark
             glass with a white icon reads on any photo in either mode. -->
        <template v-if="photos.length > 1">
          <button
            class="absolute left-2 top-1/2 -translate-y-1/2 h-8 w-8 flex items-center justify-center rounded-full
                   bg-black/45 hover:bg-black/65 backdrop-blur-sm text-white ring-1 ring-white/20
                   opacity-0 group-hover:opacity-100 transition-all shadow-md"
            aria-label="Previous photo"
            @click="prevPhoto"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
            </svg>
          </button>
          <button
            class="absolute right-2 top-1/2 -translate-y-1/2 h-8 w-8 flex items-center justify-center rounded-full
                   bg-black/45 hover:bg-black/65 backdrop-blur-sm text-white ring-1 ring-white/20
                   opacity-0 group-hover:opacity-100 transition-all shadow-md"
            aria-label="Next photo"
            @click="nextPhoto"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
            </svg>
          </button>
          <div class="absolute bottom-2 left-1/2 -translate-x-1/2 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
            <span v-for="(_, i) in photos.slice(0, 5)" :key="i" class="h-1.5 rounded-full transition-all" :class="i === currentPhoto ? 'w-4 bg-white' : 'w-1.5 bg-white/60'" />
          </div>
        </template>
      </template>
      <div v-else class="w-full h-full flex items-center justify-center">
        <svg class="h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
      </div>

      <!-- Sale/rent first (the one thing every buyer filters on), then 360°.
           "Verified" stays off the card: it's true of every listing, so it
           would distinguish nothing. -->
      <div class="absolute top-3 left-3 flex items-center gap-1.5">
        <span class="bg-brand-navy/90 backdrop-blur-sm text-white text-[0.6875rem] font-semibold px-2.5 py-1 rounded-full">
          {{ offerLabel }}
        </span>
        <span v-if="has360" class="flex items-center gap-1 bg-black/55 backdrop-blur-sm text-white text-[0.625rem] font-bold px-2.5 py-1 rounded-full ring-1 ring-white/20">
          <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12h18M12 3a15 15 0 010 18M12 3a15 15 0 000 18" /></svg>
          360°
        </span>
      </div>

      <!-- Heart. Always visible on touch, where there is no hover to reveal it. -->
      <button
        class="absolute top-3 right-3 h-8 w-8 rounded-full flex items-center justify-center transition-all
               bg-black/40 hover:bg-black/60 backdrop-blur-sm ring-1 ring-white/25"
        :class="favorited ? 'opacity-100' : 'opacity-100 md:opacity-0 md:group-hover:opacity-100'"
        :aria-label="favorited ? 'Remove from saved' : 'Save property'"
        @click="onToggleFavorite"
      >
        <svg
          class="h-4 w-4 transition-colors"
          :class="favorited ? 'text-red-500' : 'text-white'"
          :fill="favorited ? 'currentColor' : 'none'"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
        </svg>
      </button>
    </div>

    <!-- Fixed-height lines (each truncates), so a row of cards ends level -->
    <div class="flex flex-1 flex-col p-4">
      <p class="font-semibold text-brand-navy dark:text-white text-[0.9375rem] leading-snug truncate">{{ headline }}</p>
      <p class="mt-0.5 text-sm text-brand-text-secondary dark:text-white/50 truncate" :title="subtitle">{{ subtitle || ' ' }}</p>

      <ul class="mt-3 flex items-center gap-4 overflow-hidden whitespace-nowrap text-[0.8125rem] text-brand-navy/70 dark:text-white/65 min-h-[1.25rem]">
        <li v-for="s in specs" :key="s.label" class="flex items-center gap-1.5" :title="s.label">
          <svg class="h-4 w-4 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" :d="s.icon" />
          </svg>
          <span><span class="sr-only">{{ s.label }}: </span>{{ s.text }}</span>
        </li>
      </ul>

      <div class="mt-auto pt-4">
        <div class="flex items-center justify-between border-t border-gray-100 dark:border-white/10 pt-3">
          <p class="text-lg font-bold tracking-tight text-brand-navy dark:text-white">{{ displayPrice }}</p>
          <span class="h-8 w-8 rounded-full flex items-center justify-center text-brand-navy/60 dark:text-white/60 ring-1 ring-gray-200 dark:ring-white/15
                       group-hover:bg-brand-gold group-hover:text-brand-navy group-hover:ring-brand-gold transition-colors duration-300" aria-hidden="true">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
            </svg>
          </span>
        </div>
      </div>
    </div>
  </NuxtLink>
</template>
