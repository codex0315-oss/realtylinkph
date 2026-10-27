<script setup lang="ts">
// Public agent profile (guests, homepage directory, agents) — default layout.
definePageMeta({ layout: 'default' })

/*
 * Follows the visitor's theme like Browse and the listing page (it used to
 * force dark with a hard-coded `dark` class), on the same ruled grid.
 * Note kept in script: template comments ship in the HTML.
 */
</script>

<template>
  <div class="relative min-h-screen bg-brand-cream dark:bg-brand-navy-deep transition-colors duration-500">
    <div class="absolute inset-0 bg-grid-lines mask-radial-fade pointer-events-none" />
    <div class="absolute inset-0 bg-grid-lines-lg mask-radial-fade pointer-events-none" />
    <div class="relative pt-20 pb-16">
      <AgentProfileView />
    </div>
  </div>
</template>
