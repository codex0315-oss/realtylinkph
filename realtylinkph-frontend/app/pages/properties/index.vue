<script setup lang="ts">
definePageMeta({ layout: 'default' })

useHead({ title: 'Browse listings · RealtyLink PH' })

/*
 * This page used to force the dark palette with a hard-coded `dark` class on the
 * wrapper, so light mode never applied here regardless of the user's choice. It
 * now follows the theme like every other page, and carries the same ruled grid
 * as the landing page so the two read as one surface.
 *
 * Note kept in script, not the template: template comments are rendered into the
 * shipped HTML.
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

    <!-- ── Browse experience ──
         The heading goes into PropertyBrowser's `heading` slot so it sits
         beside the filters rather than above them. -->
    <div class="relative pt-28 md:pt-32">
      <PropertyBrowser>
        <template #heading>
          <div class="flex items-center gap-3 mb-3">
            <div class="h-px w-8 bg-brand-gold/50" />
            <span class="eyebrow">Every listing verified</span>
          </div>
          <h1 class="font-display text-3xl md:text-4xl font-bold text-brand-navy dark:text-white leading-tight">
            Browse Listings
          </h1>
          <p class="mt-2 text-sm text-brand-text-secondary dark:text-white/55">
            Verified listings from licensed agents across the Philippines.
          </p>
        </template>
      </PropertyBrowser>
    </div>
  </div>
</template>
