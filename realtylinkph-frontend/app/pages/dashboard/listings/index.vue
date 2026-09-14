<script setup lang="ts">
import type { Property } from '~/types'

definePageMeta({ layout: 'dashboard' })

const { properties, loading, fetchMyListings, deleteProperty, publishProperty, unpublishProperty, markSold } = useProperty()
const confirm = useConfirm()
const toast   = useToast()

await fetchMyListings()

const busy = ref<number | null>(null)

async function remove(prop: Property) {
  const ok = await confirm({
    title:   'Delete this listing?',
    message: 'The listing and all of its photos will be permanently removed. This cannot be undone.',
    subject: prop.title,
    consequences: [
      'Buyers will no longer see it anywhere on the site',
      'Its inquiries and viewing history are removed with it',
      'To hide it temporarily instead, use Unpublish',
    ],
    confirmLabel: 'Delete listing',
    tone: 'danger',
  })
  if (!ok) return

  busy.value = prop.id
  const deleted = await deleteProperty(prop.id)
  busy.value = null

  if (deleted) {
    toast.success('Listing deleted')
    await fetchMyListings()
  } else {
    toast.error('Could not delete this listing')
  }
}

async function sell(prop: Property) {
  const ok = await confirm({
    title:   'Mark this listing as sold?',
    message: 'Use this once the deal has closed.',
    subject: prop.title,
    consequences: [
      'It is hidden from buyers and removed from search',
      'It moves to your Inventory, under History',
      'You can re-list it at any time',
    ],
    confirmLabel: 'Mark as sold',
    tone: 'primary',
  })
  if (!ok) return

  busy.value = prop.id
  const sold = await markSold(prop.id)
  busy.value = null

  if (sold) {
    toast.success('Moved to your Inventory')
    await fetchMyListings()
  } else {
    toast.error('Could not update this listing')
  }
}
async function publish(id: number) {
  busy.value = id
  const ok = await publishProperty(id)
  if (ok) await fetchMyListings()
  busy.value = null
}
async function unpublish(id: number) {
  busy.value = id
  const ok = await unpublishProperty(id)
  if (ok) await fetchMyListings()
  busy.value = null
}

function priceLabel(p: Property): string {
  const n = Number(p.price)
  if (n >= 1_000_000) return `₱${(n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1)}M`
  if (n >= 1_000)     return `₱${(n / 1_000).toFixed(0)}K`
  return `₱${n.toLocaleString('en-PH')}`
}

const publishedCount = computed(() => properties.value.filter(p => p.status === 'published').length)
</script>

<template>
  <div class="max-w-6xl">
    <!-- Header — same shape as the overview pages -->
    <header class="flex flex-wrap items-end justify-between gap-4 mb-8">
      <div class="min-w-0">
        <h1 class="font-display text-2xl sm:text-[28px] font-bold text-brand-navy dark:text-white leading-tight">
          My Listings
        </h1>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/50">
          <template v-if="properties.length">
            {{ properties.length }} total ·
            <span class="text-emerald-600 dark:text-emerald-400 font-medium">{{ publishedCount }} published</span>
          </template>
          <template v-else>Manage the properties you've listed on RealtyLink PH.</template>
        </p>
      </div>
      <DashCta to="/dashboard/listings/new">
        <svg class="h-4 w-4 transition-transform duration-300 group-hover:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
        </svg>
        New listing
      </DashCta>
    </header>

    <!-- Loading -->
    <div v-if="loading" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
      <AppSkeleton v-for="i in 6" :key="i" width="100%" height="320px" rounded="lg" />
    </div>

    <!-- Empty — sits directly on the page, no card around it. The gold "New
         listing" above is the primary action, so the one here is deliberately
         the quieter outlined style rather than a second gold button. -->
    <div v-else-if="!properties.length" class="text-center py-24">
      <div class="h-14 w-14 rounded-2xl bg-brand-gold/10 dark:bg-brand-gold/15 flex items-center justify-center mx-auto mb-5">
        <svg class="h-7 w-7 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
      </div>
      <p class="text-lg font-bold text-brand-navy dark:text-white">No listings yet</p>
      <p class="mx-auto mt-2 max-w-sm text-sm text-brand-text-secondary dark:text-white/50 leading-relaxed">
        Create your first property listing to start receiving inquiries from buyers.
      </p>
      <NuxtLink
        to="/dashboard/listings/new"
        class="mt-6 inline-flex items-center gap-2 rounded-xl border border-brand-gold/40 px-5 py-2.5 text-sm font-semibold
               text-brand-gold-deep dark:text-brand-gold hover:bg-brand-gold/10 transition-colors"
      >
        Create your first listing
      </NuxtLink>
    </div>

    <!-- Card grid -->
    <div v-else class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
      <div
        v-for="prop in properties"
        :key="prop.id"
        class="group bg-white rounded-2xl border border-gray-200 overflow-hidden hover:shadow-[0_12px_36px_rgba(8,21,47,0.12)] transition-all duration-200 flex flex-col"
      >
        <!-- Cover -->
        <NuxtLink :to="`/dashboard/listings/${prop.id}/edit`" class="relative block aspect-video bg-brand-silver-light overflow-hidden">
          <img v-if="prop.photos?.[0]" :src="prop.photos[0].url" :alt="prop.title" class="h-full w-full object-cover group-hover:scale-[1.04] transition-transform duration-300" />
          <div v-else class="h-full w-full flex items-center justify-center text-gray-300">
            <svg class="h-9 w-9" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.3" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M4 6h16v12H4z" /></svg>
          </div>

          <span class="absolute top-2.5 left-2.5 text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full shadow-sm" :class="prop.offer_type === 'rent' ? 'bg-blue-600 text-white' : 'bg-emerald-600 text-white'">{{ prop.offer_type === 'rent' ? 'For Rent' : 'For Sale' }}</span>
          <span class="absolute top-2.5 right-2.5 text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full shadow-sm" :class="prop.status === 'published' ? 'bg-white/90 text-emerald-700' : 'bg-black/60 text-white'">{{ prop.status }}</span>
          <span class="absolute bottom-2.5 left-2.5 inline-flex items-center gap-1 text-[10px] font-semibold bg-black/55 text-white px-2 py-0.5 rounded-full">
            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
            {{ prop.views ?? 0 }}
          </span>
        </NuxtLink>

        <!-- Body -->
        <div class="p-4 flex-1 flex flex-col">
          <p class="text-lg font-bold text-brand-gold">{{ priceLabel(prop) }}</p>
          <NuxtLink :to="`/dashboard/listings/${prop.id}/edit`" class="font-semibold text-brand-navy line-clamp-1 hover:text-brand-gold transition-colors mt-0.5">{{ prop.title }}</NuxtLink>
          <p class="text-xs text-gray-400 line-clamp-1 mt-0.5">{{ prop.address }}</p>
          <p class="text-xs text-gray-500 mt-2 flex items-center gap-3">
            <span v-if="prop.bedrooms">{{ prop.bedrooms }} bd</span>
            <span v-if="prop.bathrooms">{{ prop.bathrooms }} ba</span>
            <span v-if="prop.floor_area">{{ prop.floor_area }} sqm</span>
          </p>

          <!-- Admin take-down: the reason stays here until the agent re-publishes -->
          <div
            v-if="prop.status === 'draft' && prop.unpublish_reason"
            class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 leading-relaxed"
          >
            <p class="font-bold mb-0.5 flex items-center gap-1.5">
              <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" /></svg>
              Unpublished by admin
            </p>
            <p>{{ prop.unpublish_reason }}</p>
            <p class="mt-1 text-amber-700">Fix the issue, then publish again.</p>
          </div>

          <!-- Actions -->
          <div class="flex items-center gap-2 mt-4 pt-3 border-t border-gray-100">
            <NuxtLink :to="`/dashboard/listings/${prop.id}/edit`" class="flex-1 text-center text-xs font-semibold text-brand-navy border border-gray-200 rounded-lg py-2 hover:border-brand-gold transition-colors">Edit</NuxtLink>
            <button
              v-if="prop.status === 'draft'"
              :disabled="busy === prop.id"
              class="flex-1 text-xs font-semibold text-brand-navy bg-brand-gold rounded-lg py-2 hover:-translate-y-0.5 transition-all disabled:opacity-50"
              @click="publish(prop.id)"
            >Publish</button>
            <button
              v-else
              :disabled="busy === prop.id"
              class="flex-1 text-xs font-semibold text-gray-500 border border-gray-200 rounded-lg py-2 hover:border-gray-300 transition-colors disabled:opacity-50"
              @click="unpublish(prop.id)"
            >Unpublish</button>
            <button
              :disabled="busy === prop.id"
              class="h-8 w-8 flex items-center justify-center rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 transition-colors disabled:opacity-50"
              title="Delete"
              @click="remove(prop)"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
            </button>
          </div>

          <!-- Mark as sold (published listings) -->
          <button
            v-if="prop.status === 'published'"
            :disabled="busy === prop.id"
            class="w-full mt-2 inline-flex items-center justify-center gap-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg py-2 hover:bg-emerald-100 transition-colors disabled:opacity-50"
            @click="sell(prop)"
          >
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M5 13l4 4L19 7" /></svg>
            Mark as Sold
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
