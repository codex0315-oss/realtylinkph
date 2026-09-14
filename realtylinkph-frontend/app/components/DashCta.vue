<script setup lang="ts">
/**
 * The one primary call-to-action used in dashboard page headers.
 *
 * Extracted so "New listing" on the agent overview, "Browse listings" on the
 * buyer overview and "New listing" on My Listings are literally the same
 * element rather than three drifting copies. Slot the icon and label in; the
 * link carries `group`, so an icon can use `group-hover:` for its own motion.
 */
defineProps<{ to: string }>()
</script>

<template>
  <NuxtLink
    :to="to"
    class="group relative overflow-hidden inline-flex items-center flex-shrink-0
           rounded-xl px-5 py-2.5 text-sm font-bold text-brand-navy
           bg-gradient-to-b from-brand-gold-light to-brand-gold
           ring-1 ring-inset ring-white/25
           shadow-[0_4px_16px_rgba(212,175,55,0.35)]
           hover:shadow-[0_8px_28px_rgba(212,175,55,0.5)] hover:-translate-y-0.5
           active:translate-y-0 active:shadow-[0_2px_10px_rgba(212,175,55,0.35)]
           transition-all duration-200"
  >
    <!-- Light sweeps across on hover; purely decorative. -->
    <span
      class="pointer-events-none absolute inset-0 -translate-x-full skew-x-12
             bg-gradient-to-r from-transparent via-white/45 to-transparent
             transition-transform duration-700 ease-out group-hover:translate-x-full"
      aria-hidden="true"
    />
    <span class="relative flex items-center gap-2">
      <slot />
    </span>
  </NuxtLink>
</template>
