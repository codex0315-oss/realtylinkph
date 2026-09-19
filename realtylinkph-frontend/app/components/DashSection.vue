<script setup lang="ts">
/**
 * A titled block on the dashboard overviews.
 *
 * The old overviews wrapped every list in an identical `bg-white rounded-2xl
 * border p-6` card with a bold heading inside it, so "Your Listings", "Recent
 * Inquiries" and "Upcoming Viewings" all carried the same visual weight and the
 * page read as a wall of boxes. Here the label sits *outside* the panel as a
 * quiet eyebrow, which leaves the content itself as the only thing with weight.
 */
interface Props {
  title: string
  actionLabel?: string
  actionHref?: string
  /** Off for content that brings its own cards (e.g. a property grid). */
  framed?: boolean
}
withDefaults(defineProps<Props>(), { framed: true })
</script>

<template>
  <!--
    `h-full flex flex-col` + `flex-1` on the panel below: as a grid item the
    section already stretches to the row height, but the panel inside kept its
    natural height, so two sections side by side ended up ragged. This pushes
    the stretch down to the panel itself.
  -->
  <section class="h-full flex flex-col">
    <div class="flex items-center justify-between gap-4 mb-3">
      <h2 class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">
        {{ title }}
      </h2>
      <NuxtLink
        v-if="actionHref"
        :to="actionHref"
        class="group flex items-center gap-1 text-xs font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline flex-shrink-0"
      >
        {{ actionLabel ?? 'View all' }}
        <svg class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
        </svg>
      </NuxtLink>
    </div>

    <div
      class="flex-1 flex flex-col"
      :class="framed
        ? 'rounded-2xl border border-gray-200 dark:border-white/[0.08] bg-white dark:bg-[#0B1A35] overflow-hidden'
        : ''"
    >
      <slot />
    </div>
  </section>
</template>
