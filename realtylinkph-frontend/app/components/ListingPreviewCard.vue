<script setup lang="ts">
/**
 * How a listing will look to buyers, from what's typed so far. Shared by the
 * New and Edit listing pages: it sits beside the form when there's room, and
 * opens from a Preview button (in a dialog) when there isn't.
 */
defineProps<{
  image?: string | null
  offer: string
  price: string
  hasPrice: boolean
  title?: string
  address?: string
  chips: string[]
  tour?: boolean
  views?: number | null
  noImage?: string
}>()
</script>

<template>
  <section class="overflow-hidden rounded-3xl bg-white dark:bg-[#0B1A35] border-2 border-brand-navy/80 dark:border-brand-gold/70">
    <div class="relative aspect-[16/10] bg-brand-navy/5 dark:bg-white/[0.06]">
      <img v-if="image" :src="image" alt="" class="h-full w-full object-cover" />
      <div v-else class="h-full w-full flex flex-col items-center justify-center gap-1 text-brand-navy/35 dark:text-white/35">
        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" /></svg>
        <span class="text-xs font-semibold">{{ noImage ?? 'Your cover photo' }}</span>
      </div>
      <span class="absolute top-3 left-3 rounded-full bg-[#ffffff]/95 px-2.5 py-1 text-[0.625rem] font-bold uppercase tracking-wide text-[#0B2A5C] shadow-sm">{{ offer === 'rent' ? 'For rent' : 'For sale' }}</span>
      <span v-if="tour" class="absolute bottom-3 left-3 rounded-full bg-brand-navy px-2 py-0.5 text-[0.625rem] font-bold text-brand-gold">360° tour</span>
      <span v-if="views" class="absolute bottom-3 right-3 rounded-full bg-black/55 px-2 py-0.5 text-[0.625rem] font-semibold text-white">{{ views }} views</span>
    </div>
    <div class="p-4">
      <p class="text-[0.625rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">Preview</p>
      <p class="mt-1 font-display text-lg font-bold tabular-nums" :class="hasPrice ? 'text-brand-gold-deep dark:text-brand-gold' : 'text-brand-navy/30 dark:text-white/30'">{{ price }}</p>
      <p class="line-clamp-2 font-semibold" :class="title ? 'text-brand-navy dark:text-white' : 'italic text-brand-navy/35 dark:text-white/35'">{{ title || 'Your listing title' }}</p>
      <p class="mt-0.5 truncate text-xs text-brand-navy/50 dark:text-white/45">{{ address || 'Address' }}</p>
      <div class="mt-3 flex flex-wrap gap-1.5 text-[0.6875rem] font-semibold text-brand-navy/70 dark:text-white/65">
        <span v-for="c in chips" :key="c" class="rounded-full bg-brand-navy/[0.05] dark:bg-white/[0.07] px-2.5 py-1">{{ c }}</span>
      </div>
    </div>
  </section>
</template>
