<script setup lang="ts">
/**
 * Slim trust bar above the main navbar.
 *
 * Deliberately carries only claims that are true of the platform — no phone
 * number or social links, because none are configured. Add them here when they
 * exist rather than shipping placeholders.
 */
const badges = [
  {
    label: 'PRC-verified agents only',
    path: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
  },
  {
    label: 'Every listing reviewed',
    path: 'M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z',
  },
  {
    label: 'Free to browse and enquire',
    path: 'M21 12a9 9 0 11-18 0 9 9 0 0118 0z M9 12l2 2 4-4',
  },
]
</script>

<template>
  <div class="hidden md:block bg-brand-navy dark:bg-[#03070F] text-white/70 transition-colors duration-500">
    <div class="max-w-content mx-auto px-4 h-9 flex items-center justify-between text-[0.6875rem]">
      <ul class="flex items-center gap-6">
        <li v-for="b in badges" :key="b.label" class="flex items-center gap-1.5">
          <svg class="h-3.5 w-3.5 text-brand-gold flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" :d="b.path" />
          </svg>
          <span class="font-medium tracking-wide">{{ b.label }}</span>
        </li>
      </ul>

      <NuxtLink
        to="/agents"
        class="flex items-center gap-1.5 font-semibold tracking-wide text-brand-gold hover:text-brand-gold-light transition-colors"
      >
        Browse verified agents
        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
        </svg>
      </NuxtLink>
    </div>
  </div>
</template>
