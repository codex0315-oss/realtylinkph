<script setup lang="ts">
import type { ApiResponse, Property } from '~/types'

definePageMeta({ layout: 'default' })

const api = useApi()

/*
 * Browse-first homepage. The old story-first page (hero card deck, feature
 * strip, "why choose") made an inquirer scroll past four sections before
 * seeing a listing. Now the search bar is the first thing on the page and
 * the listings are the second, Airbnb-style: horizontally scrolling rows
 * grouped by city where there are enough, and by theme otherwise. The server
 * decides the grouping (PropertyService::homeRows) so the page stays dumb.
 *
 * Single useAsyncData so the result is serialised into the payload and the
 * client hydrates without a mismatch.
 */
interface HomeRow { key: string; title: string; href: string; properties: Property[] }

const { data: rows, pending } = await useAsyncData(
  'home-rows',
  async () => (await api.get<ApiResponse<HomeRow[]>>('/properties/home-rows')).data,
  { default: () => [] as HomeRow[] },
)
</script>

<template>
  <div class="bg-white dark:bg-brand-navy-deep transition-colors duration-500">

    <!-- ── 1. Search + listings, on the ruled grid ── -->
    <section class="relative bg-brand-cream dark:bg-brand-navy-deep transition-colors duration-500 overflow-hidden">
      <div class="absolute inset-0 bg-grid-lines mask-radial-fade pointer-events-none" />
      <div class="absolute inset-0 bg-grid-lines-lg mask-radial-fade pointer-events-none" />
      <div class="absolute -top-32 -left-24 h-[420px] w-[420px] rounded-full bg-brand-gold/10 dark:bg-brand-gold/[0.06] blur-[120px] pointer-events-none" />
      <div class="absolute top-40 right-0 h-[360px] w-[360px] rounded-full bg-brand-navy-light/10 dark:bg-brand-navy-light/[0.07] blur-[110px] pointer-events-none" />

      <!-- Top padding clears the fixed navbar (36px trust bar + 80px nav) and
           then some, so the headline isn't crowded against it. -->
      <div class="relative max-w-content mx-auto px-6 pt-40 lg:pt-48 pb-16">

        <div v-reveal class="text-center max-w-2xl mx-auto mb-8">
          <!--
            The gap between the two lines is a margin, not line-height. Leading
            splits its extra space half above and half below each line, so
            raising it barely widens the actual gap — the descender of "your"
            kept touching the cap of "Philippines". A margin is guaranteed
            clearance between the two line boxes.
          -->
          <h1 class="font-display text-3xl sm:text-4xl lg:text-[2.75rem] font-bold text-brand-navy dark:text-white leading-[1.15] tracking-tight">
            <span class="block">Find your place in the</span>
            <span class="block mt-3 sm:mt-4 text-brand-gold-deep dark:text-brand-gold">Philippines</span>
          </h1>
          <p class="mt-3 text-[0.9375rem] text-brand-text-secondary dark:text-white/55">
            Every agent PRC-verified. Every listing reviewed before it goes live.
          </p>
        </div>

        <div v-reveal="{ delay: 80 }">
          <HomeSearchBar />
        </div>

        <!-- Listing rows -->
        <div class="mt-16 space-y-12">
          <!-- Skeleton shelf while the rows load -->
          <template v-if="pending">
            <div v-for="i in 2" :key="i">
              <div class="h-6 w-56 rounded bg-brand-navy/10 dark:bg-white/10 mb-4 animate-pulse" />
              <div class="flex gap-4 overflow-hidden">
                <AppSkeleton v-for="j in 6" :key="j" width="260px" height="300px" rounded="xl" />
              </div>
            </div>
          </template>

          <template v-else-if="rows.length">
            <div v-for="(row, i) in rows" :key="row.key" v-reveal="{ delay: Math.min(i, 3) * 80 }">
              <HomeListingRow :title="row.title" :href="row.href" :properties="row.properties" />
            </div>
          </template>

          <!-- Nothing published yet -->
          <div v-else class="text-center py-16">
            <p class="text-lg font-bold text-brand-navy dark:text-white">No listings yet</p>
            <p class="mt-2 text-sm text-brand-text-secondary dark:text-white/50">
              Verified agents are adding properties — check back soon.
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- ── 2. Browse by property type ── -->
    <HomePropertyTypeGrid />

    <!-- ── 3. How it works ── -->
    <HomeHowItWorksSection />

    <!-- ── 4. Testimonials ── -->
    <HomeTestimonialCarousel />

    <!-- ── 5. CTA ── -->
    <HomeCtaBanner />
  </div>
</template>
