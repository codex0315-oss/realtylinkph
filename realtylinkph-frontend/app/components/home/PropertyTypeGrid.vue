<script setup lang="ts">
import type { PropertyType } from '~/types'

const router = useRouter()

const types: Array<{ type: PropertyType; label: string; iconPath: string }> = [
  {
    type:     'condo',
    label:    'Condo',
    iconPath: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
  },
  {
    type:     'house',
    label:    'House & Lot',
    iconPath: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
  },
  {
    type:     'apartment',
    label:    'Apartment',
    iconPath: 'M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z',
  },
  {
    type:     'commercial',
    label:    'Commercial',
    iconPath: 'M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z',
  },
  {
    type:     'lot',
    label:    'Lot',
    iconPath: 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  },
]

function browse(type: PropertyType) {
  router.push({ path: '/properties', query: { type } })
}
</script>

<template>
  <section class="relative bg-brand-cream dark:bg-brand-navy-deep border-t border-gray-200 dark:border-white/10 py-20 transition-colors duration-500 overflow-hidden">
    <div class="absolute inset-0 bg-grid-lines mask-radial-fade pointer-events-none" />
    <div class="absolute inset-0 bg-grid-lines-lg mask-radial-fade pointer-events-none" />

    <div class="relative max-w-content mx-auto px-6">
      <div v-reveal class="text-center mb-10">
        <div class="flex items-center justify-center gap-3 mb-3">
          <div class="h-px w-8 bg-brand-gold/50" />
          <span class="eyebrow">Explore Categories</span>
          <div class="h-px w-8 bg-brand-gold/50" />
        </div>
        <h2 class="font-display text-3xl md:text-4xl font-bold text-brand-navy dark:text-white leading-tight">
          Browse by Property Type
        </h2>
        <p class="mt-3 text-sm text-brand-text-secondary dark:text-white/55">
          Find the perfect space, whatever you're looking for.
        </p>
      </div>

      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
        <button
          v-for="(item, i) in types"
          :key="item.type"
          v-reveal="{ delay: i * 90, variant: 'zoom' }"
          class="group relative overflow-hidden bg-white dark:bg-brand-navy-mid rounded-2xl
                 border border-gray-200 dark:border-white/10 p-6 text-center
                 hover:shadow-[0_18px_44px_rgba(8,21,47,0.15)] dark:hover:shadow-[0_18px_44px_rgba(0,0,0,0.55)]
                 hover:border-brand-gold hover:-translate-y-1.5
                 transition-all duration-300 cursor-pointer"
          @click="browse(item.type)"
        >
          <!-- gold sweep from the bottom on hover -->
          <span
            class="absolute inset-x-0 bottom-0 h-0 bg-gradient-to-t from-brand-gold/10 to-transparent
                   group-hover:h-full transition-[height] duration-400 ease-out pointer-events-none"
            aria-hidden="true"
          />

          <div class="relative h-14 w-14 mx-auto rounded-full bg-brand-gold/10 border border-brand-gold/20 flex items-center justify-center
                      group-hover:bg-brand-gold/25 group-hover:scale-110 group-hover:rotate-[6deg] transition-all duration-300">
            <svg class="h-7 w-7 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.4">
              <path stroke-linecap="round" stroke-linejoin="round" :d="item.iconPath" />
            </svg>
          </div>

          <span class="relative block font-semibold text-brand-navy dark:text-white text-sm mt-4 group-hover:text-brand-gold transition-colors duration-300">
            {{ item.label }}
          </span>

          <span class="relative mt-1.5 inline-flex items-center gap-1 text-[0.625rem] font-bold uppercase tracking-wider text-brand-gold
                       opacity-0 group-hover:opacity-100 translate-y-1 group-hover:translate-y-0 transition-all duration-300">
            Browse
            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M9 5l7 7-7 7" />
            </svg>
          </span>
        </button>
      </div>
    </div>
  </section>
</template>
