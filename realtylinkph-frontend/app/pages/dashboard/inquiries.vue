<script setup lang="ts">
definePageMeta({ layout: 'dashboard' })

const { inquiries, loading, unreadCount, fetchInquiries, markRead } = useInquiry()
const propertyHref = usePropertyHref()

await fetchInquiries()

const filter = ref<'all' | 'unread'>('all')
const shown = computed(() =>
  filter.value === 'unread' ? inquiries.value.filter(i => !i.is_read) : inquiries.value,
)

function fmt(d: string) {
  return new Date(d).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <!-- Header — same shape as the other dashboard pages -->
    <header class="flex flex-wrap items-end justify-between gap-4 mb-8">
      <div class="min-w-0">
        <h1 class="font-display text-2xl sm:text-[1.75rem] font-bold text-brand-navy dark:text-white leading-tight">Inquiries</h1>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/50">Messages from buyers interested in your listings.</p>
      </div>
      <div class="flex items-center gap-1 rounded-xl p-1 bg-gray-100 dark:bg-white/[0.06]">
        <button
          class="px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors"
          :class="filter === 'all'
            ? 'bg-white text-brand-navy shadow-sm dark:bg-white/10 dark:text-white'
            : 'text-brand-navy/55 hover:text-brand-navy dark:text-white/50 dark:hover:text-white'"
          @click="filter = 'all'"
        >All</button>
        <button
          class="px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors flex items-center gap-1.5"
          :class="filter === 'unread'
            ? 'bg-white text-brand-navy shadow-sm dark:bg-white/10 dark:text-white'
            : 'text-brand-navy/55 hover:text-brand-navy dark:text-white/50 dark:hover:text-white'"
          @click="filter = 'unread'"
        >
          Unread
          <span v-if="unreadCount" class="bg-brand-gold text-brand-navy text-[0.625rem] font-bold px-1.5 rounded-full">{{ unreadCount }}</span>
        </button>
      </div>
    </header>

    <!-- Loading -->
    <div v-if="loading" class="space-y-3">
      <AppSkeleton v-for="i in 3" :key="i" width="100%" height="110px" />
    </div>

    <!-- Empty — sits directly on the page, no card, matching My Listings -->
    <div v-else-if="!shown.length" class="text-center py-24">
      <div class="h-14 w-14 rounded-2xl bg-brand-gold/10 dark:bg-brand-gold/15 flex items-center justify-center mx-auto mb-5">
        <svg class="h-7 w-7 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
      </div>
      <p class="text-lg font-bold text-brand-navy dark:text-white">
        {{ filter === 'unread' ? 'All caught up' : 'No inquiries yet' }}
      </p>
      <p class="mx-auto mt-2 max-w-sm text-sm text-brand-text-secondary dark:text-white/50 leading-relaxed">
        {{ filter === 'unread'
          ? 'You have no unread inquiries.'
          : 'When a buyer sends a message about one of your listings, it shows up here.' }}
      </p>
    </div>

    <!-- List -->
    <div v-else class="space-y-3">
      <div
        v-for="inq in shown"
        :key="inq.id"
        class="bg-white rounded-2xl border p-5 transition-colors"
        :class="inq.is_read ? 'border-gray-200' : 'border-brand-gold/40 bg-brand-gold/[0.03]'"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <p class="font-bold text-brand-navy">{{ inq.name }}</p>
              <span v-if="!inq.is_read" class="h-2 w-2 rounded-full bg-brand-gold" />
              <span v-if="inq.is_ghost_buyer" class="text-[0.625rem] font-bold uppercase tracking-wide bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded">Guest</span>
            </div>
            <NuxtLink v-if="inq.property" :to="propertyHref(inq.property_id)" class="text-xs text-brand-gold hover:underline">
              {{ inq.property.title }}
            </NuxtLink>
            <p v-else class="text-xs text-gray-400">Property #{{ inq.property_id }}</p>
          </div>
          <span class="text-[0.6875rem] text-gray-400 flex-shrink-0">{{ fmt(inq.created_at) }}</span>
        </div>

        <p class="text-sm text-gray-700 mt-3 whitespace-pre-wrap leading-relaxed">{{ inq.message }}</p>

        <div class="flex items-center gap-3 mt-4 pt-3 border-t border-gray-100">
          <a v-if="inq.email" :href="`mailto:${inq.email}`" class="text-xs font-semibold text-brand-navy hover:text-brand-gold inline-flex items-center gap-1.5">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
            {{ inq.email }}
          </a>
          <a v-if="inq.phone" :href="`tel:${inq.phone}`" class="text-xs font-semibold text-brand-navy hover:text-brand-gold inline-flex items-center gap-1.5">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
            {{ inq.phone }}
          </a>
          <button
            v-if="!inq.is_read"
            class="ml-auto text-xs font-semibold text-brand-gold hover:underline"
            @click="markRead(inq.id)"
          >
            Mark as read
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
