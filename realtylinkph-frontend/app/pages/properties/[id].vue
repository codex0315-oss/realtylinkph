<script setup lang="ts">
// Public property detail (guests / ghost buyers) — default layout.
definePageMeta({ layout: 'default' })

/*
 * This page used to force the dark palette with a hard-coded `dark` class and
 * a navy background, so a visitor in light mode clicking a listing from Browse
 * landed on a dark page under a light navbar. PropertyDetailView already
 * carries both palettes (it renders light inside the dashboard), so the page
 * now simply follows the theme and lays down the same ruled grid as the
 * landing page and Browse Listings.
 *
 * Note kept in script, not the template: template comments are rendered into
 * the shipped HTML.
 */
</script>

<template>
  <div class="relative min-h-screen bg-brand-cream dark:bg-brand-navy-deep transition-colors duration-500">
    <!-- Grid texture, faded at the edges -->
    <div class="absolute inset-0 bg-grid-lines mask-radial-fade pointer-events-none" />
    <div class="absolute inset-0 bg-grid-lines-lg mask-radial-fade pointer-events-none" />

    <!-- Ambient wash so the light ground isn't flat -->
    <div class="absolute -top-32 -left-24 h-[420px] w-[420px] rounded-full bg-brand-gold/10 dark:bg-brand-gold/[0.06] blur-[120px] pointer-events-none" />
    <div class="absolute top-40 right-0 h-[360px] w-[360px] rounded-full bg-brand-navy-light/10 dark:bg-brand-navy-light/[0.07] blur-[110px] pointer-events-none" />

    <!-- px + top padding clears the fixed navbar -->
    <div class="relative px-4 pt-36 pb-16">
      <PropertyDetailView />
    </div>
  </div>
</template>
