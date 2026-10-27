<script setup lang="ts">
/**
 * "Pick your path" — the two kinds of people who arrive here, each with one
 * clear next step. Buyers go straight to Browse; agents go to verification
 * (or the sign-up modal first, since registration is buyer-only and an agent
 * upgrades from inside the dashboard).
 */
const authStore = useAuthStore()
const authModal = useAuthModal()
const router    = useRouter()

function becomeAgent() {
  if (authStore.isAuthenticated) router.push('/dashboard/verify')
  else authModal.open('register')
}

const buyerPoints = ['Only PRC-verified agents', 'Book viewings online', '360° photo tours']
const agentPoints = ['One-time licence check', 'Unlimited listings', 'Calendar-synced viewings']
</script>

<template>
  <section class="bg-white dark:bg-brand-navy-deep py-20 lg:py-24 transition-colors duration-500">
    <div class="max-w-content mx-auto px-4 sm:px-6">
      <HomeSectionHeading
        v-reveal
        align="center"
        title="Pick your path."
        accent="path"
        lead="Whether you’re looking for a place or helping people find one, there’s a clear way in."
      />

      <div class="mt-12 grid grid-cols-1 md:grid-cols-2 gap-5 lg:gap-6">
        <!-- Buyers & renters: the solid, primary card -->
        <div
          v-reveal="{ variant: 'left' }"
          v-spotlight
          class="relative overflow-hidden rounded-[2rem] p-8 sm:p-10 lg:p-12 bg-brand-navy text-white
                 ring-2 ring-brand-gold
                 shadow-[0_30px_60px_-30px_rgba(11,42,92,0.6)]"
        >
          <div class="absolute inset-0 bg-grid-gold opacity-[0.06] pointer-events-none" />
          <div class="absolute -right-16 -bottom-20 h-64 w-64 rounded-full bg-brand-gold/20 blur-[90px] pointer-events-none" />

          <div class="relative">
            <span class="h-12 w-12 rounded-2xl bg-white/10 ring-1 ring-white/15 flex items-center justify-center text-brand-gold">
              <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
              </svg>
            </span>
            <h3 class="mt-8 font-heading text-3xl lg:text-4xl font-semibold tracking-tight">For buyers & renters</h3>
            <p class="mt-3 max-w-md text-white/70 leading-relaxed">
              Browse homes, condos and lots, message the agent directly, and book a viewing that lands in your calendar.
            </p>
            <ul class="mt-6 flex flex-wrap gap-2">
              <li v-for="p in buyerPoints" :key="p" class="rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-white/85">{{ p }}</li>
            </ul>
            <NuxtLink
              to="/properties"
              class="mt-9 inline-flex items-center gap-2 rounded-full bg-brand-gold px-6 py-3 text-sm font-bold text-brand-navy
                     shadow-[0_4px_16px_rgba(212,165,74,0.4)] hover:bg-brand-gold-light hover:-translate-y-0.5 transition-all"
            >
              Browse homes
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
            </NuxtLink>
          </div>
        </div>

        <!-- Agents: the quiet card -->
        <div
          v-reveal="{ variant: 'right' }"
          v-spotlight
          class="relative overflow-hidden rounded-[2rem] p-8 sm:p-10 lg:p-12
                 bg-brand-cream dark:bg-white/[0.04] ring-2 ring-brand-navy dark:ring-brand-gold"
        >
          <span class="h-12 w-12 rounded-2xl bg-white dark:bg-brand-navy-mid ring-1 ring-brand-navy/10 dark:ring-white/10 flex items-center justify-center text-brand-gold-deep dark:text-brand-gold">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zm6-10.125a1.875 1.875 0 11-3.75 0 1.875 1.875 0 013.75 0zm1.294 6.336a6.721 6.721 0 01-3.17.789 6.721 6.721 0 01-3.168-.789 3.376 3.376 0 016.338 0z" />
            </svg>
          </span>
          <h3 class="mt-8 font-heading text-3xl lg:text-4xl font-semibold tracking-tight text-brand-navy dark:text-white">For agents & brokers</h3>
          <p class="mt-3 max-w-md text-brand-text-secondary dark:text-white/65 leading-relaxed">
            Get verified once with your PRC licence, then list properties and talk to serious buyers in real time.
          </p>
          <ul class="mt-6 flex flex-wrap gap-2">
            <li v-for="p in agentPoints" :key="p" class="rounded-full bg-white dark:bg-white/[0.06] ring-1 ring-brand-navy/[0.06] dark:ring-white/10 px-3 py-1 text-xs font-medium text-brand-navy/80 dark:text-white/75">{{ p }}</li>
          </ul>
          <button
            type="button"
            class="mt-9 inline-flex items-center gap-2 rounded-full bg-brand-navy dark:bg-white px-6 py-3 text-sm font-bold text-white dark:text-brand-navy
                   hover:-translate-y-0.5 hover:shadow-lg transition-all"
            @click="becomeAgent"
          >
            Become a verified agent
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
          </button>
        </div>
      </div>
    </div>
  </section>
</template>
