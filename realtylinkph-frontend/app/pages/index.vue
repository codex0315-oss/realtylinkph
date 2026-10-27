<script setup lang="ts">
import type { ApiResponse, FeaturedReview, PaginatedResponse, Property } from '~/types'

definePageMeta({ layout: 'default' })

const api = useApi()

/*
 * Landing page, top to bottom:
 *   centred hero (search + a fan of real listing photos) → pick your path
 *   → tabbed listings → browse by type → why RealtyLink (feature tiles)
 *   → live stat tiles → how it works → real reviews → CTA.
 *
 * The listing tabs are server-grouped (PropertyService::homeRows): city rows
 * when a city has enough listings, then theme rows.
 *
 * One useAsyncData so everything is serialised into the payload and the
 * client hydrates without a mismatch. Every request is best-effort: a failed
 * count is left out of the tiles, and with no reviews the section is hidden.
 * If /properties/stats isn't there (an older backend), the two counts it
 * replaced are fetched the old way and only "cities" goes missing.
 */
interface HomeRow { key: string; title: string; href: string; properties: Property[] }
interface Stats { listings: number | null; agents: number | null; cities: number | null }

const { data: home, pending } = await useAsyncData(
  'home',
  async () => {
    const [rows, stats, reviews] = await Promise.all([
      api.get<ApiResponse<HomeRow[]>>('/properties/home-rows').then(r => r.data).catch(() => [] as HomeRow[]),
      api.get<ApiResponse<Stats>>('/properties/stats').then(r => r.data).catch(async (): Promise<Stats> => {
        const [listings, agents] = await Promise.all([
          api.get<PaginatedResponse<Property>>('/properties').then(r => r.meta.total).catch(() => null),
          api.get<PaginatedResponse<unknown>>('/agents', { per_page: 1 }).then(r => r.meta.total).catch(() => null),
        ])
        return { listings, agents, cities: null }
      }),
      api.get<ApiResponse<FeaturedReview[]>>('/reviews/featured').then(r => r.data).catch(() => [] as FeaturedReview[]),
    ])
    return { rows, stats, reviews }
  },
  {
    default: () => ({
      rows: [] as HomeRow[],
      stats: { listings: null, agents: null, cities: null } as Stats,
      reviews: [] as FeaturedReview[],
    }),
  },
)

/* Distinct listings across all rows, newest-first, for the hero photo fan. */
const heroListings = computed(() => {
  const seen = new Set<number>()
  return home.value.rows.flatMap(r => r.properties).filter(p => !seen.has(p.id) && seen.add(p.id))
})
</script>

<template>
  <div class="bg-white dark:bg-brand-navy-deep transition-colors duration-500">
    <HomeHeroBanner :properties="heroListings" />

    <HomePathCards />

    <HomeListingTabs :rows="home.rows" :pending="pending" />

    <HomePropertyTypeGrid />

    <HomeFeatureTiles />

    <HomeStatTiles v-bind="home.stats" />

    <HomeHowItWorksSection />

    <HomeTestimonialCarousel v-if="home.reviews.length" :reviews="home.reviews" />

    <HomeCtaBanner />
  </div>
</template>
