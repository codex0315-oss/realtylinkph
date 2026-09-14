<script setup lang="ts">
const authStore = useAuthStore()
const authModal = useAuthModal()
const router    = useRouter()

function listProperty() {
  if (authStore.isAuthenticated) router.push('/dashboard/listings/new')
  else authModal.open('register')
}

function becomeAgent() {
  if (authStore.isAuthenticated) router.push('/dashboard/verify')
  else authModal.open('register')
}
</script>

<template>
  <section class="py-12 px-4 lg:px-8 bg-brand-cream dark:bg-brand-navy-deep transition-colors duration-500">
    <div
      v-reveal="{ variant: 'zoom' }"
      class="relative overflow-hidden rounded-3xl bg-brand-navy dark:bg-[#050B18] max-w-content mx-auto
             shadow-[0_24px_70px_rgba(8,21,47,0.28)] dark:shadow-[0_24px_70px_rgba(0,0,0,0.6)]"
    >
      <!-- Background photo -->
      <div class="absolute inset-0">
        <img
          src="/background.jpg"
          alt=""
          aria-hidden="true"
          loading="lazy"
          decoding="async"
          class="w-full h-full object-cover object-center opacity-40 dark:opacity-25 scale-105"
        />
        <div class="absolute inset-0 bg-gradient-to-r from-brand-navy via-brand-navy/90 to-brand-navy/60
                    dark:from-[#050B18] dark:via-[#050B18]/92 dark:to-[#050B18]/70" />
        <div class="absolute inset-0 bg-grid-gold opacity-[0.05] pointer-events-none" />
        <!-- ambient glow -->
        <div class="absolute -right-20 -top-20 h-72 w-72 rounded-full bg-brand-gold/15 blur-[100px] animate-float-slow pointer-events-none" />
      </div>

      <!-- Content -->
      <div class="relative px-8 sm:px-12 py-16 flex flex-col md:flex-row items-center justify-between gap-10">
        <!-- Left -->
        <div class="flex items-start gap-5">
          <div class="relative h-14 w-14 rounded-full bg-brand-gold/20 border border-brand-gold/30 flex items-center justify-center flex-shrink-0">
            <span class="absolute inset-0 rounded-full bg-brand-gold/25 animate-pulse-ring" aria-hidden="true" />
            <svg class="relative h-7 w-7 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
          </div>
          <div>
            <h2 class="font-display text-2xl md:text-3xl font-bold text-white leading-snug">
              Ready to <span class="text-gold-gradient animate-shimmer">List</span> Your Property?
            </h2>
            <p class="mt-3 text-white/60 text-sm leading-relaxed max-w-md">
              Join RealtyLinkPH today and connect with thousands of potential buyers and renters across the Philippines.
            </p>
          </div>
        </div>

        <!-- Right -->
        <div class="flex flex-col sm:flex-row gap-3 flex-shrink-0 w-full sm:w-auto">
          <button
            type="button"
            class="group relative overflow-hidden bg-brand-gold text-brand-navy font-bold rounded-xl px-7 py-3.5 text-sm
                   shadow-[0_4px_20px_rgba(212,175,55,0.4)] hover:shadow-[0_8px_34px_rgba(212,175,55,0.65)]
                   hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200 whitespace-nowrap text-center"
            @click="listProperty"
          >
            <span class="relative z-10">List Your Property</span>
            <span class="absolute inset-0 -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-out bg-gradient-to-r from-transparent via-white/50 to-transparent" aria-hidden="true" />
          </button>
          <button
            type="button"
            class="border border-white/70 text-white font-bold rounded-xl px-7 py-3.5 text-sm
                   hover:bg-white hover:text-brand-navy hover:-translate-y-0.5 active:translate-y-0
                   transition-all duration-200 whitespace-nowrap text-center"
            @click="becomeAgent"
          >
            Become an Agent
          </button>
        </div>
      </div>
    </div>
  </section>
</template>
