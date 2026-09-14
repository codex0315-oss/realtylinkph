<script setup lang="ts">
/* NOTE: presentational figures — not wired to live platform counts. */
const stats = [
  { value: 500, suffix: '+', label: 'Active Listings',   iconPath: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' },
  { value: 100, suffix: '+', label: 'Verified Agents',   iconPath: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z' },
  { value: 50,  suffix: '+', label: 'Properties Listed', iconPath: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4' },
  { value: 17,  suffix: '',  label: 'Regions Covered',   iconPath: 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z' },
]
</script>

<template>
  <section class="relative bg-[#0a1520] dark:bg-[#03070F] py-12 overflow-hidden transition-colors duration-500">
    <!-- gold hairlines top and bottom -->
    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-brand-gold/40 to-transparent" />
    <div class="absolute inset-x-0 bottom-0 h-px bg-gradient-to-r from-transparent via-brand-gold/25 to-transparent" />

    <div class="relative max-w-content mx-auto px-6">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-8 md:gap-6">
        <div
          v-for="(stat, i) in stats"
          :key="stat.label"
          v-reveal="{ delay: i * 110 }"
          class="flex items-center gap-4 group"
        >
          <div class="h-12 w-12 rounded-xl bg-brand-gold/10 border border-brand-gold/15 flex items-center justify-center flex-shrink-0
                      group-hover:bg-brand-gold/20 group-hover:scale-110 transition-all duration-300">
            <svg class="h-6 w-6 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" :d="stat.iconPath" />
            </svg>
          </div>
          <div>
            <p class="text-2xl font-bold text-brand-gold leading-none">
              <CountUp :to="stat.value" :suffix="stat.suffix" />
            </p>
            <p class="text-xs text-white/50 mt-1.5">{{ stat.label }}</p>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
