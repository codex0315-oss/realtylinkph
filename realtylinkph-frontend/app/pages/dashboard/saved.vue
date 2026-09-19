<script setup lang="ts">
definePageMeta({ layout: 'dashboard' })

const { favorites, loading, fetchFavorites } = useFavorite()

await fetchFavorites()

// Drop a card the moment it's un-hearted (no refresh needed).
function onToggle(payload: { id: number; favorited: boolean }) {
  if (!payload.favorited) {
    favorites.value = favorites.value.filter(p => p.id !== payload.id)
  }
}
</script>

<template>
  <div class="max-w-6xl mx-auto">

    <!-- Header — same shape as the other dashboard pages -->
    <header class="mb-8">
      <h1 class="font-display text-2xl sm:text-[1.75rem] font-bold text-brand-navy dark:text-white leading-tight">Saved</h1>
      <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/50">
        <template v-if="favorites.length">
          {{ favorites.length }} propert{{ favorites.length === 1 ? 'y' : 'ies' }} you've hearted.
        </template>
        <template v-else>Listings you heart are kept here.</template>
      </p>
    </header>

    <!-- Grid -->
    <PropertyGrid :properties="favorites" :loading="loading" :skeleton-count="8" @toggle="onToggle" />

    <!-- Empty — sits directly on the page, no card, matching My Listings -->
    <div v-if="!loading && !favorites.length" class="text-center py-24">
      <div class="h-14 w-14 rounded-2xl bg-brand-gold/10 dark:bg-brand-gold/15 flex items-center justify-center mx-auto mb-5">
        <svg class="h-7 w-7 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
        </svg>
      </div>
      <p class="text-lg font-bold text-brand-navy dark:text-white">No saved properties yet</p>
      <p class="mx-auto mt-2 max-w-sm text-sm text-brand-text-secondary dark:text-white/50 leading-relaxed">
        Tap the heart on any listing and it will be kept here.
      </p>
      <NuxtLink to="/dashboard/browse" class="mt-5 inline-block text-sm font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline">
        Browse listings →
      </NuxtLink>
    </div>

  </div>
</template>
