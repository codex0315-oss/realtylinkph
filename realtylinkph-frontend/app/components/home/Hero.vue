<script setup lang="ts">
import type { Property } from '~/types'

/**
 * Split hero: copy on the left over a light ruled ground, rotating listing
 * imagery on the right.
 *
 * The overlapping search panel that used to sit at the bottom was removed — it
 * translated outside the section and got clipped by `overflow-hidden`, and the
 * space reserved for it left a visible gap. Search lives on /properties.
 */
withDefaults(defineProps<{ properties?: Property[] }>(), {
  properties: () => [],
})

const entered = ref(false)
onMounted(() => requestAnimationFrame(() => { entered.value = true }))

const enterClass = computed(() =>
  entered.value ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-5',
)
const stagger = (i: number) => ({ transitionDelay: `${80 + i * 90}ms` })
</script>

<template>
  <section class="relative bg-brand-cream dark:bg-brand-navy-deep transition-colors duration-500 overflow-hidden">
    <!-- Ruled grid, faded at the edges so it reads as texture rather than a table -->
    <div class="absolute inset-0 bg-grid-lines mask-radial-fade pointer-events-none" />
    <div class="absolute inset-0 bg-grid-lines-lg mask-radial-fade pointer-events-none" />

    <!-- Soft ambient wash, keeps the light ground from reading as flat white -->
    <div class="absolute -top-40 -left-32 h-[520px] w-[520px] rounded-full bg-brand-gold/10 dark:bg-brand-gold/[0.07] blur-[130px] pointer-events-none" />
    <div class="absolute top-1/3 right-0 h-[420px] w-[420px] rounded-full bg-brand-navy-light/10 dark:bg-brand-navy-light/[0.08] blur-[120px] pointer-events-none" />

    <!-- pt clears the fixed header (trust bar + navbar) -->
    <div class="relative max-w-content mx-auto px-6 pt-32 lg:pt-36 pb-20 lg:pb-24">
      <!-- items-start, not items-center: centring the shorter text column against
           the taller image pushed the copy down and broke the top line. -->
      <div class="grid lg:grid-cols-2 gap-10 lg:gap-14 items-start">

        <!-- ═══ Left: copy ═══ -->
        <div class="order-2 lg:order-1 lg:pl-6 xl:pl-12">
          <div
            class="inline-flex items-center gap-2 rounded-full px-3.5 py-1.5 mb-4 transition-all duration-700 ease-out
                   bg-white dark:bg-white/5 border border-brand-navy/10 dark:border-white/10 shadow-sm"
            :class="enterClass"
            :style="stagger(0)"
          >
            <span class="relative flex h-1.5 w-1.5">
              <span class="absolute inline-flex h-full w-full rounded-full bg-brand-gold animate-pulse-ring" />
              <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-brand-gold" />
            </span>
            <span class="text-[0.625rem] font-bold uppercase tracking-[0.2em] text-brand-navy/70 dark:text-white/60">
              Verified listings · Licensed agents
            </span>
          </div>

          <h1
            class="font-display font-bold text-brand-navy dark:text-white leading-[1.08] transition-all duration-700 ease-out"
            style="font-size: clamp(2.25rem, 4.2vw, 3.4rem)"
            :class="enterClass"
            :style="stagger(1)"
          >
            Find Your<br />
            Perfect <span class="text-gold-gradient animate-shimmer">Home</span>
          </h1>

          <p
            class="mt-4 text-base leading-relaxed text-brand-text-secondary dark:text-white/60 max-w-md transition-all duration-700 ease-out"
            :class="enterClass"
            :style="stagger(2)"
          >
            Browse properties from PRC-licensed agents across the Philippines —
            every listing reviewed, every agent verified.
          </p>

          <div
            class="mt-6 flex flex-wrap items-center gap-3 transition-all duration-700 ease-out"
            :class="enterClass"
            :style="stagger(3)"
          >
            <NuxtLink
              to="/properties"
              class="group relative overflow-hidden inline-flex items-center gap-2 bg-brand-navy dark:bg-brand-gold
                     text-white dark:text-brand-navy font-bold text-sm px-6 py-3.5 rounded-xl
                     shadow-[0_6px_22px_rgba(8,21,47,0.22)] hover:shadow-[0_10px_32px_rgba(8,21,47,0.32)]
                     hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200"
            >
              <span class="relative z-10">Explore Properties</span>
              <svg class="relative z-10 h-4 w-4 group-hover:translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
              </svg>
              <span class="absolute inset-0 -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-out bg-gradient-to-r from-transparent via-white/25 to-transparent" aria-hidden="true" />
            </NuxtLink>

            <a
              href="#how-it-works"
              class="inline-flex items-center gap-2.5 font-bold text-sm px-6 py-3.5 rounded-xl transition-all duration-200
                     bg-white dark:bg-white/5 text-brand-navy dark:text-white
                     border border-brand-navy/10 dark:border-white/15
                     hover:border-brand-gold hover:-translate-y-0.5"
            >
              <span class="h-7 w-7 rounded-full bg-brand-gold/15 flex items-center justify-center flex-shrink-0">
                <svg class="h-3 w-3 text-brand-gold ml-0.5" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M8 5v14l11-7z" />
                </svg>
              </span>
              How It Works
            </a>
          </div>

          <!-- Inline proof points -->
          <div
            class="mt-7 flex flex-wrap items-center gap-x-7 gap-y-3 transition-all duration-700 ease-out"
            :class="enterClass"
            :style="stagger(4)"
          >
            <div v-for="s in [
              { value: 500, suffix: '+', label: 'Active listings' },
              { value: 100, suffix: '+', label: 'Verified agents' },
              { value: 17,  suffix: '',  label: 'Regions covered' },
            ]" :key="s.label">
              <p class="text-xl font-bold text-brand-navy dark:text-white leading-none">
                <CountUp :to="s.value" :suffix="s.suffix" />
              </p>
              <p class="text-[0.6875rem] text-brand-text-secondary dark:text-white/45 mt-1">{{ s.label }}</p>
            </div>
          </div>
        </div>

        <!-- ═══ Right: slideshow ═══ -->
        <div
          class="order-1 lg:order-2 h-[280px] sm:h-[360px] lg:h-[440px] transition-all duration-700 ease-out"
          :class="entered ? 'opacity-100 translate-y-0 scale-100' : 'opacity-0 translate-y-6 scale-[0.97]'"
          :style="stagger(1)"
        >
          <!--
            The deck supplies its own depth (offset, scale, per-card shadow), so
            the old static gold frame and wrapper shadow were removed — they
            fought the stack. Only the ambient bloom stays, to bleed colour into
            the cream so the cards sit in the section rather than on it.
          -->
          <div class="relative w-full h-full">
            <div class="absolute -inset-6 rounded-[40px] bg-brand-navy/5 dark:bg-brand-gold/[0.06] blur-3xl pointer-events-none" />
            <HomeHeroDeck :properties="properties" class="relative" />
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
