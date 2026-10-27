<script setup lang="ts">
import type { Property } from '~/types'
import type { AiChatMessage } from '~/composables/useRealtyAi'

/**
 * The listings under a RealtyLink AI reply, shared by the dashboard panel and
 * the public full-screen chat. Each card opens the listing, shows how far the
 * places the buyer asked about are, and can be saved or booked from the chat.
 */
const props = defineProps<{
  message: AiChatMessage
  /** Only the latest reply offers "Show more". */
  latest?: boolean
  busy?: boolean
}>()
const emit = defineEmits<{ navigate: []; more: [] }>()

const authStore    = useAuthStore()
const propertyHref = usePropertyHref()
const toast        = useToast()
const { toggleFavorite } = useFavorite()
const { markSaved }      = useAiThread()

const saving = ref<number | null>(null)

const PLACE: Record<string, string> = {
  school: 'School', hospital: 'Hospital', clinic: 'Clinic', pharmacy: 'Pharmacy',
  mall: 'Mall', grocery: 'Supermarket', market: 'Market', church: 'Church',
  transit: 'Transport', bank: 'Bank', police: 'Police', fire: 'Fire station',
}

function distance(m: number): string {
  return m < 1000 ? `${Math.round(m / 10) * 10} m` : `${(m / 1000).toFixed(1)} km`
}

function priceLabel(p: Property): string {
  const n = Number(p.price)
  const s = n >= 1_000_000 ? `₱${(n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1)}M`
          : n >= 1_000     ? `₱${(n / 1_000).toFixed(0)}K`
          : `₱${n.toLocaleString('en-PH')}`
  return p.offer_type === 'rent' ? `${s}/mo` : s
}

async function save(p: Property) {
  if (saving.value) return
  saving.value = p.id
  try {
    const on = await toggleFavorite(p.id)
    markSaved(p.id, on)
    toast.success(on ? 'Saved to your homes.' : 'Removed from saved homes.')
  } catch {
    toast.error('Couldn’t update your saved homes. Please try again.')
  } finally {
    saving.value = null
  }
}

const remaining = computed(() => props.latest ? (props.message.remaining ?? 0) : 0)
</script>

<template>
  <div v-if="message.properties?.length || remaining" class="mt-2 space-y-1.5">
    <div
      v-for="p in message.properties"
      :key="p.id"
      class="overflow-hidden rounded-xl bg-white ring-1 ring-brand-navy/[0.07] hover:ring-brand-gold dark:bg-white/[0.06] dark:ring-white/10 dark:hover:ring-brand-gold transition"
    >
      <NuxtLink :to="propertyHref(p.id)" class="group flex items-center gap-2.5 p-1.5 pr-3" @click="emit('navigate')">
        <span class="h-11 w-14 flex-none overflow-hidden rounded-lg bg-brand-navy/5 dark:bg-white/10">
          <img v-if="p.photos?.length" :src="p.photos[0]?.thumb_url ?? p.photos[0]?.url" alt="" class="h-full w-full object-cover" loading="lazy" />
        </span>
        <span class="min-w-0 flex-1">
          <span class="block truncate text-[0.8125rem] font-semibold text-brand-navy dark:text-white group-hover:text-brand-gold-deep dark:group-hover:text-brand-gold transition">{{ p.title }}</span>
          <span class="block truncate text-[0.6875rem] text-brand-navy/50 dark:text-white/45">
            <template v-if="p.bedrooms">{{ p.bedrooms }} bd · </template><template v-if="p.bathrooms">{{ p.bathrooms }} ba · </template>{{ p.address }}
          </span>
        </span>
        <span class="flex-none text-[0.8125rem] font-bold text-brand-gold-deep dark:text-brand-gold tabular-nums">{{ priceLabel(p) }}</span>
      </NuxtLink>

      <!-- How far the places they asked about are -->
      <div v-if="message.nearby?.[p.id]?.length" class="flex flex-wrap gap-1 px-2 pb-1.5">
        <span
          v-for="f in message.nearby[p.id]"
          :key="f.kind"
          class="inline-flex max-w-full items-center gap-1 rounded-full px-2 py-0.5 text-[0.6875rem] font-medium bg-brand-gold/10 text-brand-navy/80 dark:bg-brand-gold/15 dark:text-white/85"
          :title="`Nearest ${PLACE[f.kind]?.toLowerCase() ?? f.kind}: ${f.name}, about ${distance(f.distance_m)} away (straight line)`"
        >
          <svg class="h-3 w-3 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
          <span class="truncate">{{ f.name }}</span>
          <span class="flex-none font-bold tabular-nums text-brand-gold-deep dark:text-brand-gold">{{ distance(f.distance_m) }}</span>
        </span>
      </div>

      <!-- Quick actions -->
      <div v-if="authStore.isBuyer" class="flex border-t border-brand-navy/[0.06] dark:border-white/10 text-[0.75rem] font-semibold">
        <button
          type="button"
          class="flex flex-1 items-center justify-center gap-1.5 py-2 transition disabled:opacity-50"
          :class="p.is_favorited
            ? 'text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10'
            : 'text-brand-navy/70 hover:text-brand-navy hover:bg-brand-navy/[0.04] dark:text-white/70 dark:hover:text-white dark:hover:bg-white/[0.06]'"
          :disabled="saving === p.id"
          :aria-pressed="!!p.is_favorited"
          :aria-label="p.is_favorited ? `Remove ${p.title} from saved homes` : `Save ${p.title}`"
          @click="save(p)"
        >
          <svg class="h-3.5 w-3.5" :fill="p.is_favorited ? 'currentColor' : 'none'" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" /></svg>
          {{ p.is_favorited ? 'Saved' : 'Save' }}
        </button>
        <span class="w-px bg-brand-navy/[0.06] dark:bg-white/10" aria-hidden="true" />
        <NuxtLink
          :to="`${propertyHref(p.id)}?book=1`"
          class="flex flex-1 items-center justify-center gap-1.5 py-2 text-brand-navy/70 hover:text-brand-navy hover:bg-brand-navy/[0.04] dark:text-white/70 dark:hover:text-white dark:hover:bg-white/[0.06] transition"
          @click="emit('navigate')"
        >
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
          Book viewing
        </NuxtLink>
      </div>
    </div>

    <button
      v-if="remaining"
      type="button"
      class="w-full flex items-center justify-center gap-1.5 rounded-xl py-2 text-[0.8125rem] font-semibold transition
             text-brand-navy border border-dashed border-brand-navy/25 hover:border-brand-gold hover:bg-brand-gold/10
             dark:text-white dark:border-white/25 dark:hover:border-brand-gold disabled:opacity-50"
      :disabled="busy"
      @click="emit('more')"
    >
      Show {{ remaining }} more {{ remaining === 1 ? 'match' : 'matches' }}
      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
    </button>
  </div>
</template>
