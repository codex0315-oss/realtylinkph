<script setup lang="ts">
const authStore = useAuthStore()
const authModal = useAuthModal()
const router    = useRouter()

function listProperty() {
  if (authStore.isAuthenticated) router.push('/dashboard/listings/new')
  else authModal.open('register')
}
</script>

<template>
  <section class="py-16 lg:py-20 px-4 sm:px-6 bg-white dark:bg-brand-navy-deep transition-colors duration-500">
    <div
      v-reveal="{ variant: 'zoom' }"
      class="group relative overflow-hidden rounded-[2rem] bg-brand-navy dark:bg-[#040E24] max-w-content mx-auto
             ring-2 ring-brand-navy dark:ring-brand-gold
             shadow-[0_30px_70px_-30px_rgba(11,42,92,0.6)] dark:shadow-[0_24px_70px_rgba(0,0,0,0.6)]"
    >
      <!-- Property photo on the right; the banner colour fades over it from
           the left so the copy stays readable. Slow zoom on hover. -->
      <img
        src="/background.jpg"
        alt=""
        loading="lazy"
        decoding="async"
        class="absolute inset-y-0 right-0 h-full w-full md:w-[68%] object-cover object-[65%_center]
               motion-safe:transition-transform motion-safe:duration-[2000ms] ease-out motion-safe:group-hover:scale-105"
      />
      <div class="cta-fade absolute inset-0 pointer-events-none" />
      <div class="absolute inset-0 bg-grid-gold opacity-[0.06] pointer-events-none" />
      <div class="absolute -top-24 -left-20 h-72 w-[30rem] max-w-full rounded-full bg-brand-gold/20 blur-[110px] pointer-events-none" />

      <div class="relative px-6 sm:px-12 lg:px-16 py-16 sm:py-20 lg:py-24 text-left">
        <h2 class="max-w-xl font-heading text-[2.25rem] sm:text-5xl lg:text-[3.5rem] font-semibold tracking-[-0.02em] text-white leading-[1.08]">
          Your next <em class="italic font-medium text-gold-metal-bright pr-[0.08em]">home</em><br class="hidden sm:block"> starts here.
        </h2>
        <p class="mt-5 max-w-md text-white/75 leading-relaxed">
          Browse for free and talk to a verified agent today — or get verified yourself and start listing.
        </p>

        <div class="mt-9 flex flex-col sm:flex-row items-center sm:items-start justify-start gap-3">
          <NuxtLink
            to="/properties"
            class="group relative overflow-hidden w-full sm:w-auto text-center rounded-full bg-brand-gold px-8 py-3.5 text-sm font-bold text-brand-navy
                   shadow-[0_4px_20px_rgba(212,165,74,0.4)] hover:shadow-[0_8px_34px_rgba(212,165,74,0.65)]
                   hover:-translate-y-0.5 transition-all duration-200"
          >
            <span class="relative z-10">Browse listings</span>
            <span class="absolute inset-0 -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-out bg-gradient-to-r from-transparent via-white/50 to-transparent" aria-hidden="true" />
          </NuxtLink>
          <button
            type="button"
            class="w-full sm:w-auto rounded-full border border-white/60 px-8 py-3.5 text-sm font-bold text-white
                   hover:bg-white hover:text-brand-navy hover:-translate-y-0.5 transition-all duration-200"
            @click="listProperty"
          >
            List your property
          </button>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
/* Solid banner colour behind the copy, clearing towards the photo. On phones
   the copy sits over the photo, so the whole banner keeps a heavy tint. */
.cta-fade {
  --fade: 11, 42, 92;
  background: rgba(var(--fade), 0.82);
}
@media (min-width: 768px) {
  .cta-fade {
    background: linear-gradient(90deg,
      rgb(var(--fade)) 0%, rgb(var(--fade)) 34%,
      rgba(var(--fade), 0.7) 52%, rgba(var(--fade), 0.15) 78%, rgba(var(--fade), 0) 100%);
  }
}
</style>
