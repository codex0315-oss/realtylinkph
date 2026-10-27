<script setup lang="ts">
/**
 * Split section: photo on one side, the reasons on the other, closed by a
 * row of figures. The figures are live counts from the API, never typed-in
 * marketing numbers — a panel can ask where "12K+" came from; these answer
 * themselves. A count that failed to load is simply left out.
 */
interface Props {
  listings: number | null
  agents: number | null
}
const props = defineProps<Props>()

/* Bound rather than a literal `src` so Vite's template asset rewriting leaves
   it alone — the literal was turned into an /_nuxt/@fs/C:/… URL that the dev
   server refuses. Files in public/ are served from the site root as-is. */
const photo = '/pics2.jpeg'

const points = [
  'Agents apply with their PRC credentials and a live selfie, and an admin reviews every application.',
  'Book a viewing straight from the listing, and sync it to your Google Calendar.',
  'Reviews come only from buyers who actually dealt with the agent.',
]

const stats = computed(() => [
  props.listings !== null ? { value: props.listings.toLocaleString('en-PH'), label: 'Live listings' } : null,
  props.agents   !== null ? { value: props.agents.toLocaleString('en-PH'),   label: 'PRC-verified agents' } : null,
  { value: '₱0', label: 'To browse and enquire' },
].filter((s): s is { value: string; label: string } => s !== null))
</script>

<template>
  <section id="about" class="bg-brand-cream dark:bg-brand-navy-deep py-20 lg:py-28 scroll-mt-24 transition-colors duration-500">
    <div class="max-w-content mx-auto px-6 grid grid-cols-1 lg:grid-cols-2 gap-14 lg:gap-20 items-center">

      <!-- Photo with a floating proof card -->
      <div v-reveal="{ variant: 'left' }" class="relative">
        <img
          :src="photo"
          alt="A warmly lit studio unit with a bed, dining area and kitchenette"
          loading="lazy"
          decoding="async"
          class="w-full aspect-[4/3] object-cover rounded-3xl shadow-[0_30px_60px_-30px_rgba(11,42,92,0.45)]"
        />
        <div class="absolute -bottom-6 right-4 sm:-right-6 flex items-center gap-3 rounded-2xl bg-white dark:bg-brand-navy-mid px-5 py-4
                    shadow-[0_20px_40px_-16px_rgba(11,42,92,0.35)] ring-1 ring-gray-100 dark:ring-white/10">
          <span class="h-10 w-10 flex-none rounded-full bg-brand-navy text-brand-gold flex items-center justify-center">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
            </svg>
          </span>
          <div>
            <p class="text-sm font-semibold text-brand-navy dark:text-white leading-tight">Licence-checked</p>
            <p class="text-xs text-brand-text-secondary dark:text-white/55">by our admin team</p>
          </div>
        </div>
      </div>

      <!-- Copy -->
      <div v-reveal="{ variant: 'right' }">
        <HomeSectionHeading
          eyebrow="Why RealtyLink PH"
          title="More than listings. A safer way to find a home."
          lead="Buying or renting is a big decision. We built RealtyLink PH so you only ever deal with licensed professionals — and can see that for yourself."
        />

        <ul class="mt-8 space-y-4">
          <li v-for="p in points" :key="p" class="flex gap-3">
            <span class="mt-0.5 h-5 w-5 flex-none rounded-full bg-brand-gold/20 text-brand-gold-deep dark:text-brand-gold flex items-center justify-center">
              <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
              </svg>
            </span>
            <span class="text-[0.9375rem] leading-relaxed text-brand-navy/80 dark:text-white/75">{{ p }}</span>
          </li>
        </ul>

        <dl class="mt-10 pt-8 border-t border-gray-200 dark:border-white/10 grid grid-cols-3 gap-4">
          <div v-for="s in stats" :key="s.label">
            <dt class="sr-only">{{ s.label }}</dt>
            <dd class="font-heading font-semibold text-3xl sm:text-4xl text-brand-navy dark:text-white tracking-tight">{{ s.value }}</dd>
            <dd class="mt-1 text-xs sm:text-sm text-brand-text-secondary dark:text-white/55">{{ s.label }}</dd>
          </div>
        </dl>
      </div>
    </div>
  </section>
</template>
