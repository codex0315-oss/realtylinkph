<script setup lang="ts">
definePageMeta({ layout: 'default' })

useHead({ title: 'Browse listings · RealtyLink PH' })

/*
 * Follows the theme like every other page. The hero (ruled grid, search pill,
 * type chips) lives in PropertyBrowser so the dashboard's embedded copy gets
 * the same search; this page only supplies the heading.
 *
 * Note kept in script, not the template: template comments are rendered into the
 * shipped HTML.
 */
</script>

<template>
  <div class="min-h-screen bg-white dark:bg-brand-navy-deep transition-colors duration-500">
    <PropertyBrowser>
      <template #heading>
        <h1 class="font-heading font-semibold tracking-[-0.03em] leading-[1.05] text-[2.5rem] sm:text-5xl lg:text-6xl text-brand-navy dark:text-white">
          Properties <em class="italic font-medium text-gold-metal pr-[0.08em]">on RealtyLink</em>
        </h1>
        <p class="mt-4 mx-auto max-w-xl text-base sm:text-lg leading-relaxed text-brand-text-secondary dark:text-white/60">
          Listed by PRC-verified agents across the Philippines. Message one to arrange a viewing.
        </p>
      </template>
    </PropertyBrowser>
  </div>
</template>
