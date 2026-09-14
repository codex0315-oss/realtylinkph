<script setup lang="ts">
const steps = [
  {
    num:      '1',
    title:    'Search Property',
    desc:     'Browse verified listings that match your needs and location.',
    iconPath: 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z',
  },
  {
    num:      '2',
    title:    'Connect With Agent',
    desc:     'Get in touch with licensed and verified agents instantly.',
    iconPath: 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
  },
  {
    num:      '3',
    title:    'Secure Your Property',
    desc:     'Close the deal with confidence and complete peace of mind.',
    iconPath: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
  },
]

/* Draw the connector line only once the section is on screen. */
const sectionEl = ref<HTMLElement | null>(null)
const drawn = ref(false)
let observer: IntersectionObserver | null = null

onMounted(() => {
  if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
    drawn.value = true
    return
  }
  observer = new IntersectionObserver((entries) => {
    for (const e of entries) {
      if (e.isIntersecting) { drawn.value = true; observer?.disconnect() }
    }
  }, { threshold: 0.35 })
  if (sectionEl.value) observer.observe(sectionEl.value)
})
onUnmounted(() => observer?.disconnect())
</script>

<template>
  <section
    id="how-it-works"
    ref="sectionEl"
    class="bg-brand-navy dark:bg-[#050B18] py-20 relative overflow-hidden transition-colors duration-500 scroll-mt-24"
  >
    <!-- Subtle dot grid texture -->
    <div class="absolute inset-0 bg-grid-gold opacity-[0.04] pointer-events-none" />

    <!-- Ambient glow -->
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 h-[380px] w-[760px] rounded-full bg-brand-gold/[0.06] blur-[120px] pointer-events-none" />

    <div class="relative max-w-content mx-auto px-6">
      <!-- Header -->
      <div v-reveal class="flex items-center justify-center gap-4 mb-16">
        <div class="flex-1 max-w-[80px] h-px bg-brand-gold/30" />
        <h2 class="font-display text-2xl md:text-3xl font-bold text-white text-center whitespace-nowrap">
          How RealtyLinkPH Works
        </h2>
        <div class="flex-1 max-w-[80px] h-px bg-brand-gold/30" />
      </div>

      <!-- Steps -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-10 md:gap-4 items-start relative">
        <!-- Connector line that draws itself in -->
        <div
          class="hidden md:block absolute top-10 left-[calc(16.67%+2rem)] right-[calc(16.67%+2rem)] h-px z-0 origin-left
                 border-t-2 border-dashed border-brand-gold/25 transition-transform duration-[1400ms] ease-out"
          :class="drawn ? 'scale-x-100' : 'scale-x-0'"
          aria-hidden="true"
        />

        <!-- Travelling pulse: rides the same track from step 1, past 2, to 3,
             then fades and sets off from 1 again. Starts after the line has
             finished drawing, and is removed entirely for reduced-motion. -->
        <div
          v-if="drawn"
          class="hidden md:block motion-reduce:hidden absolute top-10 left-[calc(16.67%+2rem)] right-[calc(16.67%+2rem)] h-px z-0 pointer-events-none"
          aria-hidden="true"
        >
          <span class="how-comet" />
        </div>

        <div
          v-for="(step, i) in steps"
          :key="step.num"
          v-reveal="{ delay: 200 + i * 180 }"
          class="text-center relative z-10 group"
        >
          <!-- Circle icon with step number badge -->
          <div class="relative inline-flex mb-6">
            <!-- pulsing halo -->
            <span class="absolute inset-0 rounded-full bg-brand-gold/20 animate-pulse-ring pointer-events-none" aria-hidden="true" />

            <div class="relative w-20 h-20 rounded-full border-2 border-brand-gold/50 bg-brand-navy dark:bg-[#050B18] flex items-center justify-center shadow-lg
                        group-hover:border-brand-gold group-hover:scale-110 group-hover:shadow-[0_0_40px_rgba(212,175,55,0.35)]
                        transition-all duration-400">
              <svg class="h-8 w-8 text-brand-gold transition-transform duration-400 group-hover:scale-110" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" :d="step.iconPath" />
              </svg>
            </div>

            <span class="absolute -top-1 -left-1 w-6 h-6 bg-brand-gold rounded-full flex items-center justify-center text-[11px] font-black text-brand-navy shadow-md z-10">
              {{ step.num }}
            </span>
          </div>

          <h3 class="font-bold text-brand-gold text-base">{{ step.title }}</h3>
          <p class="mt-2 text-sm text-white/55 leading-relaxed max-w-xs mx-auto">{{ step.desc }}</p>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
/*
 * The comet is a short gold bar with a soft tail. Its head (right edge) starts
 * exactly at the track's left end — `translateX(-100%)` tucks the body behind
 * the first icon — and `left` runs 0 → 100% so the head lands on the far end.
 * Opacity ramps at both ends so it never pops in or snaps back visibly.
 */
.how-comet {
  position: absolute;
  top: 50%;
  left: 0;
  width: 7rem;
  height: 3px;
  margin-top: -1.5px;
  border-radius: 9999px;
  background: linear-gradient(90deg, transparent 0%, rgba(212, 175, 55, 0.85) 60%, #E8C547 100%);
  box-shadow: 0 0 14px 2px rgba(212, 175, 55, 0.45);
  transform: translateX(-100%);
  opacity: 0;
  animation: how-comet-travel 4.8s cubic-bezier(0.45, 0, 0.25, 1) 1.4s infinite;
}

@keyframes how-comet-travel {
  0%   { left: 0;    opacity: 0; }
  6%   { opacity: 1; }
  90%  { opacity: 1; }
  100% { left: 100%; opacity: 0; }
}
</style>
