<script setup lang="ts">
/**
 * Live figures as a bento row: a wide featured tile for listings, then
 * agents, cities and the free-to-browse promise. Every figure is a live count
 * from /properties/stats — never a typed-in marketing number — and a count
 * that failed to load is simply left out.
 *
 * The numbers count up once when the row scrolls into view. The server renders
 * the final figures (so no-JS and crawlers see real numbers); the client only
 * resets them to 0 while they're still off-screen, then animates. Reduced
 * motion skips the animation entirely.
 */
interface Props {
  listings: number | null
  agents: number | null
  cities: number | null
}
const props = defineProps<Props>()

interface Tile { key: string; target: number | null; text?: string; label: string; hint: string; icon: string }

const ICON = {
  agent: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
  city:  'M15 10.5a3 3 0 11-6 0 3 3 0 016 0z M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z',
  free:  'M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z',
}

const side = computed<Tile[]>(() => [
  props.agents !== null ? { key: 'agents', target: props.agents, label: 'PRC-verified agents', hint: 'Licence-checked by our admins', icon: ICON.agent } : null,
  props.cities !== null ? { key: 'cities', target: props.cities, label: props.cities === 1 ? 'City covered' : 'Cities covered', hint: 'And growing as agents join', icon: ICON.city } : null,
  { key: 'free', target: null, text: '₱0', label: 'To browse and enquire', hint: 'No account needed to look', icon: ICON.free },
].filter((t): t is Tile => t !== null))

/* ── Count-up ─────────────────────────────────────────────────────────── */
const rowEl = ref<HTMLElement | null>(null)
const progress = ref(1)          // 0 → 1; 1 on the server = final figures
let observer: IntersectionObserver | null = null
let raf = 0

function shown(target: number | null): string {
  if (target === null) return ''
  return Math.round(target * progress.value).toLocaleString('en-PH')
}

function run() {
  const start = performance.now()
  const DURATION = 1400
  const tick = (now: number) => {
    const t = Math.min(1, (now - start) / DURATION)
    progress.value = 1 - Math.pow(1 - t, 3)      // ease-out cubic
    if (t < 1) raf = requestAnimationFrame(tick)
  }
  raf = requestAnimationFrame(tick)
}

onMounted(() => {
  if (!rowEl.value || window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return
  const r = rowEl.value.getBoundingClientRect()
  if (r.top < window.innerHeight && r.bottom > 0) return   // already on screen: don't flash 0
  progress.value = 0
  observer = new IntersectionObserver((entries) => {
    if (entries.some(e => e.isIntersecting)) { observer?.disconnect(); run() }
  }, { threshold: 0.35 })
  observer.observe(rowEl.value)
})
onUnmounted(() => { observer?.disconnect(); cancelAnimationFrame(raf) })
</script>

<template>
  <section class="bg-white dark:bg-brand-navy-deep py-20 lg:py-24 transition-colors duration-500">
    <div class="max-w-content mx-auto px-4 sm:px-6">
      <HomeSectionHeading
        v-reveal
        align="center"
        title="Real agents. Real listings."
        accent="Real listings."
        lead="Live numbers from the platform — every agent counted here passed a licence check."
      />

      <dl ref="rowEl" class="mt-12 grid grid-cols-2 lg:grid-cols-5 gap-4 lg:gap-5">
        <!-- Featured: live listings -->
        <div
          v-if="listings !== null"
          v-reveal
          v-spotlight
          class="relative overflow-hidden col-span-2 rounded-[2rem] p-7 sm:p-9 min-h-[15rem] flex flex-col
                 bg-brand-navy text-white ring-2 ring-brand-gold
                 shadow-[0_30px_60px_-28px_rgba(11,42,92,0.7)]"
        >
          <div class="absolute inset-0 bg-grid-gold opacity-[0.07] pointer-events-none" />
          <div class="absolute -right-10 -top-16 h-56 w-56 rounded-full bg-brand-gold/25 blur-[80px] pointer-events-none" />

          <span class="relative inline-flex w-max items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white/85 ring-1 ring-white/15">
            <span class="relative flex h-2 w-2">
              <span class="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75 animate-ping motion-reduce:hidden" />
              <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-400" />
            </span>
            Live on RealtyLink
          </span>

          <div class="relative mt-auto pt-8">
            <dt class="sr-only">Live listings</dt>
            <dd class="font-heading text-7xl sm:text-8xl font-semibold tracking-tight leading-none text-gold-metal-bright tabular-nums">
              {{ shown(listings) }}
            </dd>
            <dd class="mt-3 flex flex-wrap items-end justify-between gap-3">
              <span>
                <span class="block text-lg font-semibold">Live listings</span>
                <span class="block text-sm text-white/60">Published, complete, and from verified agents</span>
              </span>
              <NuxtLink
                to="/properties"
                class="group inline-flex items-center gap-1.5 rounded-full bg-brand-gold px-4 py-2 text-xs font-bold text-brand-navy hover:bg-brand-gold-light transition"
              >
                Browse them
                <svg class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
              </NuxtLink>
            </dd>
          </div>
        </div>

        <!-- The rest -->
        <div
          v-for="(t, i) in side"
          :key="t.key"
          v-reveal="{ delay: (i + 1) * 80 }"
          v-spotlight
          class="group relative overflow-hidden rounded-[2rem] p-6 sm:p-7 min-h-[15rem] flex flex-col
                 bg-brand-cream ring-2 ring-brand-navy dark:bg-white/[0.04] dark:ring-brand-gold
                 hover:-translate-y-1 hover:ring-brand-gold dark:hover:ring-brand-gold-light
                 dark:hover:shadow-[0_22px_44px_-22px_rgba(0,0,0,0.6),0_0_22px_-6px_rgba(212,165,74,0.5)] transition-all duration-300"
          :class="t.key === 'free' && side.length % 2 === 1 ? 'col-span-2 lg:col-span-1' : ''"
        >
          <!-- Oversized watermark icon -->
          <svg class="absolute -right-6 -bottom-6 h-32 w-32 text-brand-gold/10 dark:text-brand-gold/[0.07] transition-transform duration-500 group-hover:scale-110 group-hover:-rotate-6"
               fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" :d="t.icon" />
          </svg>

          <span class="relative h-11 w-11 rounded-2xl flex items-center justify-center bg-white dark:bg-brand-navy-mid ring-1 ring-brand-navy/[0.08] dark:ring-white/10 text-brand-gold-deep dark:text-brand-gold">
            <AppVerifiedTick v-if="t.key === 'agents'" size="lg" label="" />
            <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="t.icon" />
            </svg>
          </span>

          <div class="relative mt-auto pt-6">
            <dt class="sr-only">{{ t.label }}</dt>
            <dd class="font-heading text-5xl sm:text-6xl font-semibold tracking-tight leading-none text-gold-metal tabular-nums">
              {{ t.text ?? shown(t.target) }}
            </dd>
            <dd class="mt-3 text-sm font-semibold text-brand-navy dark:text-white">{{ t.label }}</dd>
            <dd class="mt-0.5 text-xs text-brand-text-secondary dark:text-white/50">{{ t.hint }}</dd>
          </div>
        </div>
      </dl>
    </div>
  </section>
</template>
