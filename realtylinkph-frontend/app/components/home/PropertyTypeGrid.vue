<script setup lang="ts">
import type { PropertyType } from '~/types'

const router = useRouter()

const types: Array<{ type: PropertyType; label: string; hint: string; iconPath: string }> = [
  {
    type:     'condo',
    label:    'Condo',
    hint:     'City living, amenities',
    iconPath: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
  },
  {
    type:     'house',
    label:    'House & Lot',
    hint:     'Room for the family',
    iconPath: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
  },
  {
    type:     'apartment',
    label:    'Apartment',
    hint:     'Easy, flexible renting',
    iconPath: 'M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z',
  },
  {
    type:     'commercial',
    label:    'Commercial',
    hint:     'Shops and offices',
    iconPath: 'M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z',
  },
  {
    type:     'lot',
    label:    'Lot',
    hint:     'Build your own',
    iconPath: 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  },
]

function browse(type: PropertyType) {
  router.push({ path: '/properties', query: { type } })
}
</script>

<template>
  <section class="bg-white dark:bg-brand-navy-deep py-20 lg:py-24 transition-colors duration-500">
    <div class="max-w-content mx-auto px-6">
      <HomeSectionHeading
        v-reveal
        align="center"
        title="Browse by property type."
        accent="property type"
        lead="Whatever you’re looking for, start with the kind of place you want."
      />

      <div class="mt-12 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
        <button
          v-for="(item, i) in types"
          :key="item.type"
          v-reveal="{ delay: i * 70 }"
          type="button"
          v-spotlight
          class="group relative overflow-hidden rounded-2xl p-6 text-left
                 bg-brand-cream dark:bg-white/[0.04]
                 ring-2 ring-brand-navy dark:ring-brand-gold hover:ring-brand-gold dark:hover:ring-brand-gold-light
                 hover:bg-white dark:hover:bg-white/[0.07]
                 hover:shadow-[0_22px_44px_-22px_rgba(11,42,92,0.35)] dark:hover:shadow-[0_22px_44px_-22px_rgba(0,0,0,0.6),0_0_22px_-6px_rgba(212,165,74,0.5)]
                 hover:-translate-y-1
                 transition-all duration-300"
          @click="browse(item.type)"
        >
          <span class="h-12 w-12 rounded-xl flex items-center justify-center bg-white dark:bg-brand-navy-mid text-brand-gold-deep dark:text-brand-gold
                       ring-1 ring-gray-200/80 dark:ring-white/10 group-hover:bg-brand-navy group-hover:text-brand-gold group-hover:ring-brand-navy transition-colors duration-300">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" :d="item.iconPath" />
            </svg>
          </span>

          <span class="mt-5 block font-semibold text-brand-navy dark:text-white">{{ item.label }}</span>
          <span class="mt-0.5 block text-sm text-brand-text-secondary dark:text-white/55">{{ item.hint }}</span>

          <svg class="absolute top-6 right-6 h-4 w-4 text-brand-navy/30 dark:text-white/30 group-hover:text-brand-gold-deep dark:group-hover:text-brand-gold group-hover:translate-x-0.5 transition-all"
               fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
          </svg>
        </button>
      </div>
    </div>
  </section>
</template>
