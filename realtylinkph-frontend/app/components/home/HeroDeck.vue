<script setup lang="ts">
import type { Property } from '~/types'

/**
 * Hero property deck.
 *
 * All four cards are on screen at once, stacked with depth. Every couple of
 * seconds the front card recedes and the one behind steps forward, so a new
 * card "arrives" rather than an image swapping inside a fixed frame.
 *
 * Cards are translucent with a backdrop blur, so the cream ground and its ruled
 * grid read through them instead of being covered by a solid block.
 *
 * Real published listings take priority; with an empty catalogue it falls back
 * to sample properties, flagged so they never read as live inventory.
 */
const props = withDefaults(defineProps<{
  properties?: Property[]
  interval?: number
}>(), {
  properties: () => [],
  interval: 2000,
})

const propertyHref = usePropertyHref()

interface Card {
  id: string
  image: string
  title: string
  address: string
  price: number
  offer: 'sale' | 'rent'
  href: string | null
  mock: boolean
}

const SAMPLE_CARDS: Card[] = [
  {
    id: 's1',
    image: '/pics4.jpeg',                       // bedroom, blue accents, study desk
    title: 'Fully Furnished 1BR with Study',
    address: 'Cebu Business Park, Cebu City',
    price: 6_800_000, offer: 'sale', href: null, mock: true,
  },
  {
    id: 's2',
    image: '/pics3.jpeg',                       // bedroom with window seat + city view
    title: '2BR Corner Unit with Skyline Views',
    address: 'Bonifacio Global City, Taguig',
    price: 21_800_000, offer: 'sale', href: null, mock: true,
  },
  {
    id: 's3',
    image: '/pics2.jpeg',                       // studio with kitchenette
    title: 'Modern Studio, Move-In Ready',
    address: 'Salcedo Village, Makati City',
    price: 28_000, offer: 'rent', href: null, mock: true,
  },
  {
    id: 's4',
    image: '/pics1.jpeg',                       // green terraced residences
    title: 'Garden Terrace Residences',
    address: 'Tagaytay, Cavite',
    price: 12_500_000, offer: 'sale', href: null, mock: true,
  },
]

const realCards = computed<Card[]>(() =>
  props.properties
    .filter(p => p.photos?.some(ph => !ph.is_360))
    .slice(0, 5)
    .map(p => ({
      id: `p-${p.id}`,
      image: p.photos!.find(ph => !ph.is_360)!.url,
      title: p.title,
      address: p.address,
      price: Number(p.price),
      offer: (p.offer_type === 'rent' ? 'rent' : 'sale') as 'sale' | 'rent',
      href: propertyHref(p.id),
      mock: false,
    })),
)

const cards    = computed<Card[]>(() => (realCards.value.length ? realCards.value : SAMPLE_CARDS))
const multiple = computed(() => cards.value.length > 1)

const front  = ref(0)
const paused = ref(false)
let timer: ReturnType<typeof setInterval> | null = null
let reduced = false

/** How many steps back in the stack this card currently sits. */
function depth(i: number): number {
  const n = cards.value.length
  return (i - front.value + n) % n
}

/**
 * Depth 0 is the face card. Each step back shrinks, lifts and rotates a little.
 * The last position is fully transparent, so a card leaving the front reads as
 * receding out of the deck rather than sliding backwards through it.
 */
function cardStyle(i: number) {
  const d = depth(i)
  const n = cards.value.length
  const isLast = d === n - 1 && n > 2

  // Offsets kept small: the hero section clips overflow, so a deep stack would
  // have its back cards cut off at the column edge.
  return {
    transform: `translate3d(${d * 12}px, ${d * -10}px, 0) scale(${1 - d * 0.045}) rotate(${d * 1.2}deg)`,
    opacity: String(isLast ? 0 : Math.max(0.4, 1 - d * 0.26)),
    zIndex: String(50 - d),
    pointerEvents: d === 0 ? 'auto' : 'none',
  } as Record<string, string>
}

function advance() { front.value = (front.value + 1) % cards.value.length }
function go(i: number) { front.value = i }

function start() {
  stop()
  if (!multiple.value || reduced) return
  timer = setInterval(() => { if (!paused.value) advance() }, props.interval)
}
function stop() { if (timer) { clearInterval(timer); timer = null } }

onMounted(() => {
  reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
  start()
})
onUnmounted(stop)

watch(() => cards.value.map(c => c.id).join('|'), () => { front.value = 0; start() })

function priceLabel(price: number, offer: string): string {
  const abbr = price >= 1_000_000
    ? `₱${(price / 1_000_000).toFixed(price % 1_000_000 === 0 ? 0 : 1)}M`
    : price >= 1_000
      ? `₱${(price / 1_000).toFixed(0)}K`
      : `₱${price.toLocaleString('en-PH')}`
  return offer === 'rent' ? `${abbr}/mo` : abbr
}
</script>

<template>
  <div
    class="relative w-full h-full"
    @mouseenter="paused = true"
    @mouseleave="paused = false"
  >
    <!-- Deck: every card is absolutely stacked; depth drives the transform. -->
    <div
      v-for="(c, i) in cards"
      :key="c.id"
      class="absolute inset-0 will-change-transform deck-card"
      :style="cardStyle(i)"
    >
      <component
        :is="c.href ? resolveComponent('NuxtLink') : 'div'"
        :to="c.href || undefined"
        class="group flex flex-col w-full h-full rounded-[26px] p-3
               bg-white/80 dark:bg-white/[0.07] backdrop-blur-md
               border border-white/70 dark:border-white/10
               ring-1 ring-brand-navy/5
               shadow-[0_14px_40px_-14px_rgba(8,21,47,0.22),0_34px_80px_-34px_rgba(8,21,47,0.18)]"
        :class="c.href ? '' : 'cursor-default'"
      >
        <!-- Photo -->
        <div class="relative flex-1 min-h-0 rounded-[18px] overflow-hidden bg-brand-navy/5">
          <img
            :src="c.image"
            :alt="c.mock ? 'Sample property photo' : c.title"
            class="w-full h-full object-cover transition-transform duration-700"
            :class="depth(i) === 0 ? 'group-hover:scale-105' : ''"
            :fetchpriority="i === 0 ? 'high' : 'auto'"
            :loading="i === 0 ? 'eager' : 'lazy'"
            decoding="async"
          />

          <div class="absolute top-3 left-3 flex items-center gap-2">
            <span
              class="text-[10px] font-bold uppercase tracking-[0.16em] px-2.5 py-1 rounded-full shadow-sm"
              :class="c.offer === 'rent' ? 'bg-blue-500 text-white' : 'bg-brand-gold text-brand-navy'"
            >
              {{ c.offer === 'rent' ? 'For Rent' : 'For Sale' }}
            </span>
            <span
              v-if="c.mock"
              class="text-[10px] font-bold uppercase tracking-[0.16em] px-2.5 py-1 rounded-full
                     bg-brand-navy/60 text-white/90 backdrop-blur-sm"
              title="Placeholder content — real listings replace these automatically"
            >
              Sample
            </span>
          </div>
        </div>

        <!-- Details -->
        <div class="flex items-end justify-between gap-4 px-2 pt-3.5 pb-1">
          <div class="min-w-0">
            <p
              class="text-sm font-bold text-brand-navy dark:text-white line-clamp-1"
              :class="c.href ? 'group-hover:text-brand-gold transition-colors' : ''"
            >
              {{ c.title }}
            </p>
            <p class="text-xs text-brand-text-secondary dark:text-white/50 mt-1 flex items-center gap-1.5 line-clamp-1">
              <svg class="h-3.5 w-3.5 flex-shrink-0 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
              {{ c.address }}
            </p>
          </div>
          <p class="text-xl sm:text-2xl font-bold text-brand-navy dark:text-brand-gold leading-none flex-shrink-0">
            {{ priceLabel(c.price, c.offer) }}
          </p>
        </div>
      </component>
    </div>

    <!-- Which card is in front -->
    <div v-if="multiple" class="absolute -bottom-7 left-0 flex gap-1.5 z-[60]">
      <button
        v-for="(c, i) in cards"
        :key="c.id"
        class="h-1.5 rounded-full transition-all duration-300"
        :class="depth(i) === 0
          ? 'w-6 bg-brand-gold'
          : 'w-1.5 bg-brand-navy/20 dark:bg-white/25 hover:bg-brand-navy/40 dark:hover:bg-white/50'"
        :aria-label="`Bring property ${i + 1} to front`"
        @click.prevent="go(i)"
      />
    </div>
  </div>
</template>

<style scoped>
.deck-card {
  transition:
    transform 0.75s cubic-bezier(0.22, 1, 0.36, 1),
    opacity 0.75s ease;
}

@media (prefers-reduced-motion: reduce) {
  .deck-card { transition: none; }
}
</style>
