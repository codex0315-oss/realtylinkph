<script setup lang="ts">
/**
 * Four-up reassurance row directly beneath the hero search panel.
 * Top padding clears the panel, which overlaps into this section.
 */
const items = [
  {
    title: 'Find the perfect home',
    desc:  'Browse verified listings that match exactly what you need.',
    path:  'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
  },
  {
    title: 'Licensed agents',
    desc:  'Every agent passes PRC credential review before they can list.',
    path:  'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
  },
  {
    title: 'Book viewings online',
    desc:  'Pick a real time slot and it syncs to both calendars.',
    path:  'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
  },
  {
    title: 'Reviews you can trust',
    desc:  'Only buyers who actually attended a viewing can leave one.',
    path:  'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118L2.98 10.1c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z',
  },
]
</script>

<template>
  <section class="relative bg-white dark:bg-[#071021] py-16 transition-colors duration-500 overflow-hidden">
    <div class="absolute inset-0 bg-grid-lines mask-radial-fade pointer-events-none" />
    <div class="absolute inset-0 bg-grid-lines-lg mask-radial-fade pointer-events-none" />

    <div class="relative max-w-content mx-auto px-6">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-5">
        <!--
          Cards are translucent rather than solid so the ruled grid behind the
          section reads straight through them — the box defines the group without
          punching a hole in the background.
        -->
        <div
          v-for="(f, i) in items"
          :key="f.title"
          v-reveal="{ delay: i * 90 }"
          class="group flex items-start gap-4 rounded-2xl p-5 backdrop-blur-[2px]
                 bg-white/55 dark:bg-white/[0.04]
                 border border-brand-navy/10 dark:border-white/10
                 hover:bg-white/85 dark:hover:bg-white/[0.07]
                 hover:border-brand-gold/45
                 hover:shadow-[0_14px_38px_rgba(8,21,47,0.10)] dark:hover:shadow-[0_14px_38px_rgba(0,0,0,0.45)]
                 hover:-translate-y-1
                 transition-all duration-300"
        >
          <div
            class="h-12 w-12 rounded-2xl flex items-center justify-center flex-shrink-0 transition-all duration-300
                   bg-brand-navy dark:bg-brand-gold/15 border border-brand-navy dark:border-brand-gold/25
                   group-hover:bg-brand-gold group-hover:border-brand-gold group-hover:scale-105"
          >
            <svg
              class="h-5 w-5 text-white dark:text-brand-gold group-hover:text-brand-navy transition-colors duration-300"
              fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"
            >
              <path stroke-linecap="round" stroke-linejoin="round" :d="f.path" />
            </svg>
          </div>
          <div class="min-w-0">
            <h3 class="font-bold text-sm text-brand-navy dark:text-white">{{ f.title }}</h3>
            <p class="text-xs text-brand-text-secondary dark:text-white/55 mt-1.5 leading-relaxed">{{ f.desc }}</p>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
