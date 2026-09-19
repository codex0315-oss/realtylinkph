<script setup lang="ts">
import type { Property } from '~/types'
import { PROPERTY_TYPES } from '~/types'

interface Props {
  property: Property
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
    if (favorited.value) toast.success('Saved')
    else toast.info('Removed from saved')
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

const specs = computed(() => {
  const p = props.property
  const out: string[] = []
  if (p.bedrooms)   out.push(`${p.bedrooms} bed${p.bedrooms === 1 ? '' : 's'}`)
  if (p.bathrooms)  out.push(`${p.bathrooms} bath${p.bathrooms === 1 ? '' : 's'}`)
  if (p.floor_area) out.push(`${p.floor_area} m²`)
  else if (p.lot_area) out.push(`${p.lot_area} m² lot`)
  return out.join(' · ')
})
</script>

<template>
  <!--
    Borderless, photo-first: the rounded photo sits on the page and the text
    below it is unboxed. The old bordered white card put a frame around every
    listing, which is what made a row of them read as a template.
  -->
  <NuxtLink :to="propertyHref(property.id)" class="group block">
    <!-- Photo -->
    <div class="relative aspect-[4/3] rounded-2xl bg-gray-100 dark:bg-white/5 overflow-hidden">
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

      <!-- One badge only. "Verified" sat on every card, so it distinguished
           nothing — that promise lives in the top bar and on the detail page.
           A 360° tour is the one thing only some listings have. -->
      <div v-if="has360" class="absolute top-3 left-3">
        <span class="flex items-center gap-1 bg-black/55 backdrop-blur-sm text-white text-[0.625rem] font-bold px-2.5 py-1 rounded-full ring-1 ring-white/20">
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

    <!-- Three fixed lines, so a row of cards ends level -->
    <div class="pt-3">
      <p class="font-semibold text-brand-navy dark:text-white text-[0.9375rem] leading-snug truncate">{{ headline }}</p>
      <p class="text-sm text-brand-navy/55 dark:text-white/45 truncate" :title="subtitle">{{ subtitle }}</p>
      <p class="text-sm mt-1">
        <span class="font-bold text-brand-navy dark:text-white">{{ displayPrice }}</span>
        <span v-if="specs" class="text-brand-navy/55 dark:text-white/45"> · {{ specs }}</span>
      </p>
    </div>
  </NuxtLink>
</template>
