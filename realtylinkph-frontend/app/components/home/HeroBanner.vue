<script setup lang="ts">
import type { Property } from '~/types'

/**
 * Centred hero: headline with one gold word, the search pill, a few quick
 * links, then a fan of real listing photos — the listings ARE the picture.
 *
 * Sits on the same ruled-grid ground as Browse, so the navbar can stay
 * transparent over it with its normal theme colours (see TheNavbar).
 *
 * The fan takes up to five published listings that have a photo. With fewer
 * than three it falls back to the stock interiors in /public so the hero never
 * looks empty on a fresh database.
 */
const props = defineProps<{ properties: Property[] }>()

interface Slide { key: string; src: string; to?: string; price?: string; place?: string }

function shortPrice(p: Property): string {
  const n = Number(p.price)
  const amount = n >= 1_000_000
    ? `₱${(n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1)}M`
    : n >= 1_000 ? `₱${(n / 1_000).toFixed(0)}K` : `₱${n.toLocaleString('en-PH')}`
  return p.offer_type === 'rent' ? `${amount}/mo` : amount
}

function placeOf(address: string): string {
  const parts = address.split(',').map(s => s.trim()).filter(Boolean)
  return parts.find(s => /\bcity\b/i.test(s)) ?? parts[parts.length - 1] ?? ''
}

const FALLBACK = ['/pics1.jpeg', '/pics2.jpeg', '/background.jpg', '/pics3.jpeg', '/pics4.jpeg']

const slides = computed<Slide[]>(() => {
  const real = props.properties
    .filter(p => p.photos?.length && !p.photos[0]!.is_360)
    .slice(0, 5)
    .map(p => ({
      key:   `p${p.id}`,
      src:   p.photos![0]!.thumb_url ?? p.photos![0]!.url,
      to:    `/properties/${p.id}`,
      price: shortPrice(p),
      place: placeOf(p.address),
    }))
  if (real.length >= 3) return real
  return FALLBACK.map(src => ({ key: src, src }))
})

/*
 * The fan is a carousel: every card sits in a slot (−2 … 2, 0 = the big
 * centre one) and every few seconds each card glides one slot to the left,
 * re-tilting and re-sizing on the way (the slot styles are in the CSS below,
 * per breakpoint). The card leaving the far left fades out while it travels
 * round to the far right, so it never sweeps across the front.
 *
 * Phones show the centre card with the two neighbours peeking in, and can be
 * swiped. It pauses under the mouse and while the tab is hidden, and never
 * moves by itself for reduced-motion users.
 */
const SLOTS: Record<number, number[]> = { 3: [-1, 0, 1], 4: [-1, 0, 1, 2], 5: [-2, -1, 0, 1, 2] }
const offset = ref(0)
const wrapping = ref<string | null>(null)
let wrapTimer: ReturnType<typeof setTimeout> | null = null

const placed = computed(() => {
  const s = slides.value.slice(0, 5)
  const slots = SLOTS[s.length]!
  return s.map((slide, i) => {
    const slot = slots[(((i - offset.value) % s.length) + s.length) % s.length]!
    return { slide, slot, center: slot === 0 }
  })
})
const firstCenter = SLOTS[5]!.indexOf(0)

function step(dir: 1 | -1) {
  const items = placed.value
  const ends = SLOTS[items.length]!
  // The card about to jump from one end of the fan to the other.
  const from = dir === 1 ? ends[0] : ends[ends.length - 1]
  wrapping.value = items.find(it => it.slot === from)?.slide.key ?? null
  if (wrapTimer) clearTimeout(wrapTimer)
  wrapTimer = setTimeout(() => { wrapping.value = null }, 900)
  offset.value += dir
}
function goTo(slot: number) {
  for (let k = 0; k < Math.abs(slot); k++) setTimeout(() => step(slot > 0 ? 1 : -1), k * 180)
  restartTimer()
}

/* Auto-advance. */
const ROTATE_MS = 2000
const paused = ref(false)
let rotateTimer: ReturnType<typeof setInterval> | null = null
function restartTimer() {
  if (rotateTimer) clearInterval(rotateTimer)
  if (reduceMotion) return
  rotateTimer = setInterval(() => {
    if (!paused.value && !document.hidden) step(1)
  }, ROTATE_MS)
}

function onEnter(e: PointerEvent) { if (e.pointerType === 'mouse') paused.value = true }
function onLeave() { paused.value = false }

/* Swipe (touch, pen or a mouse drag). A swipe never opens the listing. */
let startX = 0
let startY = 0
let swiped = false
function onDown(e: PointerEvent) { startX = e.clientX; startY = e.clientY; swiped = false }
function onUp(e: PointerEvent) {
  const dx = e.clientX - startX
  const dy = e.clientY - startY
  if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
    swiped = true
    step(dx < 0 ? 1 : -1)
    restartTimer()
  }
}
/*
 * Only the centre card opens its listing; a side card comes to the centre.
 * Caught on the way down (capture), before the card's link can navigate.
 */
function onCardClick(e: MouseEvent) {
  const card = (e.target as HTMLElement).closest<HTMLElement>('[data-slot]')
  const slot = Number(card?.dataset.slot ?? 0)
  if (swiped || slot !== 0) {
    e.preventDefault()
    e.stopPropagation()
    if (!swiped) goTo(slot)
  }
  swiped = false
}

/*
 * The accent word cycles place → home → condo → lot. It starts (and, on the
 * server, stays) on "place", pauses while the tab is hidden, and doesn't move
 * at all for reduced-motion users. Screen readers get the fixed sentence.
 */
const WORDS = ['place', 'home', 'condo', 'lot']
const wordIndex = ref(0)
let wordTimer: ReturnType<typeof setInterval> | null = null

/*
 * Pointer parallax on the photo fan: the fan tilts a few degrees toward the
 * cursor and the outer cards drift further than the centre one. Mouse only,
 * eased by a CSS transition, and off for reduced motion.
 */
const tilt = reactive({ x: 0, y: 0 })
let reduceMotion = true

function onPointer(e: PointerEvent) {
  if (reduceMotion || e.pointerType !== 'mouse') return
  tilt.x = (e.clientX / window.innerWidth) * 2 - 1
  tilt.y = (e.clientY / window.innerHeight) * 2 - 1
}
function resetTilt() { tilt.x = 0; tilt.y = 0 }

const fanStyle = computed(() => ({
  transform: `perspective(1400px) rotateY(${tilt.x * 4}deg) rotateX(${-tilt.y * 2.5}deg)`,
}))
function cardShift(slot: number) {
  const depth = slot * 7                  // −14 … 14 px from outer-left to outer-right
  return { translate: `${tilt.x * depth}px ${tilt.y * Math.abs(depth) * 0.4}px` }
}

onMounted(() => {
  reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
  if (reduceMotion) return
  wordTimer = setInterval(() => {
    if (!document.hidden) wordIndex.value = (wordIndex.value + 1) % WORDS.length
  }, 2600)
  restartTimer()
})
onUnmounted(() => {
  if (wordTimer) clearInterval(wordTimer)
  if (rotateTimer) clearInterval(rotateTimer)
  if (wrapTimer) clearTimeout(wrapTimer)
})

/* A real listing links to its page; a fallback photo is just a picture. */
const NuxtLinkCmp = resolveComponent('NuxtLink')
</script>

<template>
  <section
    class="relative isolate overflow-hidden bg-brand-cream dark:bg-brand-navy-deep transition-colors duration-500"
    @pointermove="onPointer"
    @pointerleave="resetTilt"
  >
    <div class="absolute inset-0 -z-10 bg-grid-lines mask-radial-fade pointer-events-none" />
    <div class="absolute inset-0 -z-10 bg-grid-lines-lg mask-radial-fade pointer-events-none" />
    <div class="absolute -top-24 left-1/2 -translate-x-1/2 -z-10 h-[28rem] w-[min(56rem,100%)] rounded-full bg-brand-gold/15 dark:bg-brand-gold/[0.07] blur-[120px] pointer-events-none" />

    <div class="max-w-content mx-auto px-4 sm:px-6 pt-28 lg:pt-32 text-center">
      <p class="inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-xs font-semibold
                bg-white/80 dark:bg-white/[0.06] text-brand-navy dark:text-white/85
                ring-1 ring-brand-navy/10 dark:ring-white/10 shadow-sm backdrop-blur">
        <AppVerifiedTick size="sm" label="" />
        Every agent PRC-verified
      </p>

      <h1 class="mt-6 mx-auto max-w-4xl font-heading font-semibold tracking-[-0.03em] leading-[1.02]
                 text-[2.75rem] sm:text-6xl lg:text-[5rem] text-brand-navy dark:text-white">
        <span class="sr-only">Find your place in the Philippines.</span>
        <span aria-hidden="true">
          <!-- Every word stacked in one grid cell, so the cell is as wide as
               the longest and the line never reflows as they swap. The margin
               stands in for the space, which the italic overhang would widen. -->
          Find your<span class="inline-grid align-baseline ml-[0.25em] text-left">
            <em
              v-for="(w, i) in WORDS"
              :key="w"
              class="hero-word col-start-1 row-start-1 italic font-medium text-gold-metal pr-[0.08em]"
              :class="i === wordIndex ? 'is-on' : ''"
            >{{ w }}</em>
          </span>
          <span class="block">in the Philippines.</span>
        </span>
      </h1>

      <p class="mt-6 mx-auto max-w-xl text-base sm:text-lg leading-relaxed text-brand-text-secondary dark:text-white/65">
        Homes, condos and lots from licensed agents. Chat with them directly,
        book a viewing, and see the place in 360°.
      </p>

      <!-- z-30: the search panels drop over the photo fan below. -->
      <div class="relative z-30 mt-9 mx-auto w-full max-w-3xl text-left">
        <HomeSearchBar />
      </div>

      <!-- Photo fan (a carousel; see the script) -->
      <div
        class="fan relative mt-10 lg:mt-12 mb-10 lg:mb-14 transition-transform duration-700 ease-out select-none"
        :style="fanStyle"
        aria-label="Recent listings"
        @pointerenter="onEnter"
        @pointerleave="onLeave"
        @pointerdown="onDown"
        @pointerup="onUp"
        @click.capture="onCardClick"
      >
        <component
          :is="item.slide.to ? NuxtLinkCmp : 'div'"
          v-for="(item, i) in placed"
          :key="item.slide.key"
          :to="item.slide.to"
          :aria-hidden="item.center ? undefined : 'true'"
          :tabindex="item.center ? undefined : -1"
          draggable="false"
          class="fan-card group aspect-[3/4] rounded-[1.75rem] overflow-hidden
                 ring-[3px] ring-brand-navy dark:ring-brand-gold
                 shadow-[0_30px_60px_-24px_rgba(11,42,92,0.45)] dark:shadow-[0_30px_60px_-20px_rgba(0,0,0,0.7)]
                 hover:shadow-[0_34px_64px_-22px_rgba(11,42,92,0.6)] dark:hover:shadow-[0_30px_60px_-20px_rgba(0,0,0,0.7),0_0_28px_-4px_rgba(212,165,74,0.55)]"
          :class="[`slot-${item.slot < 0 ? 'm' : 'p'}${Math.abs(item.slot)}`, { 'is-wrapping': wrapping === item.slide.key }]"
          :data-slot="item.slot"
          :style="cardShift(item.slot)"
        >
          <img
            :src="item.slide.src"
            alt=""
            :fetchpriority="i === firstCenter ? 'high' : undefined"
            decoding="async"
            draggable="false"
            class="h-full w-full object-cover transition-transform duration-700 group-hover:scale-105"
          />
          <template v-if="item.slide.price">
            <div class="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/70 to-transparent pointer-events-none" />
            <div class="absolute inset-x-3 bottom-3 flex items-end justify-between gap-2 text-left">
              <div class="min-w-0">
                <p class="font-heading text-lg lg:text-xl font-semibold text-white leading-none">{{ item.slide.price }}</p>
                <p class="mt-1 truncate text-[0.7rem] text-white/75">{{ item.slide.place }}</p>
              </div>
            </div>
          </template>
          <span
            v-if="item.slide.price"
            class="absolute top-3 left-3 inline-flex items-center gap-1.5 rounded-full pl-1.5 pr-3 py-1 text-[0.7rem] font-bold text-white
                   bg-brand-navy/85 ring-1 ring-brand-gold/60 backdrop-blur-md shadow-[0_4px_14px_rgba(0,0,0,0.35)]
                   transition-all duration-500"
            :class="item.center ? 'opacity-100 translate-y-0 delay-300' : 'opacity-0 -translate-y-1'"
          >
            <AppVerifiedTick size="sm" label="" />
            Verified agent
          </span>
        </component>
      </div>
    </div>
  </section>
</template>

<style scoped>
/* Rotating accent word: the outgoing word lifts and blurs away, the next one
   rises into place. All words share one grid cell (see template). */
.hero-word {
  opacity: 0;
  transform: translateY(0.35em);
  filter: blur(6px);
  transition: opacity 0.5s ease, transform 0.6s cubic-bezier(0.22, 1, 0.36, 1), filter 0.5s ease;
}
.hero-word.is-on {
  opacity: 1;
  transform: none;
  filter: none;
}
@media (prefers-reduced-motion: reduce) {
  .hero-word { transition: none; }
}

/*
 * Photo-fan carousel. Every card is centred on the fan's midline and placed
 * by its slot: --x across, --y down, --r tilt, --s size (the centre card is
 * full size). Changing slot class is all a swap does; the transition animates
 * the glide. Hover lift goes through --lift so it doesn't fight the slot.
 */
.fan {
  --w: 13.75rem;                                  /* phone: centre card width */
  height: calc(var(--w) * 4 / 3 + 2.5rem);
  touch-action: pan-y;                            /* vertical scroll still works */
}
.fan-card {
  position: absolute;
  top: 0;
  left: 50%;
  width: var(--w);
  margin-left: calc(var(--w) / -2);
  transform: translate(var(--x), calc(var(--y) + var(--lift, 0px))) rotate(var(--r)) scale(var(--s));
  transition:
    transform 0.9s cubic-bezier(0.22, 1, 0.36, 1),
    opacity 0.5s ease,
    translate 0.6s ease-out,
    box-shadow 0.4s ease;
  -webkit-user-drag: none;
}
.fan-card:hover { --lift: -0.5rem; }
.fan-card.is-wrapping { opacity: 0 !important; transition-duration: 0.9s, 0.2s, 0.6s, 0.4s; }

/* Phone: the centre card with its two neighbours peeking in. */
.slot-p0 { --x: 0rem;      --y: 0rem;    --r: 0deg;  --s: 1;    z-index: 20; }
.slot-m1 { --x: -8.25rem;  --y: 0.75rem; --r: -4deg; --s: 0.78; z-index: 10; }
.slot-p1 { --x: 8.25rem;   --y: 0.75rem; --r: 4deg;  --s: 0.78; z-index: 10; }
.slot-m2 { --x: -14rem;    --y: 1.75rem; --r: -8deg; --s: 0.66; z-index: 0; opacity: 0; }
.slot-p2 { --x: 14rem;     --y: 1.75rem; --r: 8deg;  --s: 0.66; z-index: 0; opacity: 0; }

@media (min-width: 640px) {
  .fan { --w: 14rem; }
  .slot-m1 { --x: -11rem; --y: 0.5rem; --r: -3deg; --s: 0.79; }
  .slot-p1 { --x: 11rem;  --y: 0.5rem; --r: 3deg;  --s: 0.79; }
  .slot-m2 { --x: -18rem; }
  .slot-p2 { --x: 18rem; }
}
@media (min-width: 768px) {
  .slot-m2 { --x: -20rem; --y: 1.75rem; --r: -7deg; --s: 0.71; opacity: 1; }
  .slot-p2 { --x: 20rem;  --y: 1.75rem; --r: 7deg;  --s: 0.71; opacity: 1; }
}
@media (min-width: 1024px) {
  .fan { --w: 18rem; }
  .slot-m1 { --x: -14.5rem; --s: 0.83; }
  .slot-p1 { --x: 14.5rem;  --s: 0.83; }
  .slot-m2 { --x: -26.5rem; --s: 0.72; }
  .slot-p2 { --x: 26.5rem;  --s: 0.72; }
}

@media (prefers-reduced-motion: reduce) {
  .fan-card { transition: box-shadow 0.4s ease; }
}
</style>
