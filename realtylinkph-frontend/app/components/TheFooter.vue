<script setup lang="ts">
const year  = new Date().getFullYear()
const email = ref('')

/* NOTE: there is no newsletter endpoint on the API yet — this field is
   presentational. Deliberately not showing a "Subscribed!" confirmation,
   because nothing is actually stored. */

const showTop = ref(false)
function onScroll() {
  showTop.value = window.scrollY > 600
}
onMounted(() => {
  window.addEventListener('scroll', onScroll, { passive: true })
  onScroll()
})
onUnmounted(() => window.removeEventListener('scroll', onScroll))

function toTop() {
  const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
  window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' })
}

const socials = [
  { label: 'Facebook',  path: 'M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z' },
  { label: 'Instagram', path: 'M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37zm1.5-4.87h.01M6.5 19.5h11a3 3 0 003-3v-11a3 3 0 00-3-3h-11a3 3 0 00-3 3v11a3 3 0 003 3z' },
  { label: 'Twitter',   path: 'M23 3a10.9 10.9 0 01-3.14 1.53 4.48 4.48 0 00-7.86 3v1A10.66 10.66 0 013 4s-4 9 5 13a11.64 11.64 0 01-7 2c9 5 20 0 20-11.5a4.5 4.5 0 00-.08-.83A7.72 7.72 0 0023 3z' },
  { label: 'LinkedIn',  path: 'M16 8a6 6 0 016 6v7h-4v-7a2 2 0 00-2-2 2 2 0 00-2 2v7h-4v-7a6 6 0 016-6zM2 9h4v12H2z M4 6a2 2 0 100-4 2 2 0 000 4z' },
]

const columns = [
  {
    title: 'Property',
    links: [
      { label: 'Buy',                 href: '/properties?type=house' },
      { label: 'Rent',                href: '/properties?type=apartment' },
      { label: 'Sell',                href: '/dashboard/listings/new' },
      { label: 'All Properties',      href: '/properties' },
    ],
  },
  {
    title: 'Company',
    links: [
      { label: 'About Us',   href: '#about' },
      { label: 'Careers',    href: '#' },
      { label: 'Blog',       href: '#' },
      { label: 'Contact Us', href: '#' },
    ],
  },
  {
    title: 'Support',
    links: [
      { label: 'Help Center',    href: '#' },
      { label: 'FAQs',           href: '#' },
      { label: 'Terms of Use',   href: '#' },
      { label: 'Privacy Policy', href: '#' },
    ],
  },
]
</script>

<template>
  <footer class="relative bg-brand-navy dark:bg-[#03070F] text-white overflow-hidden transition-colors duration-500">
    <!-- gold hairline + texture -->
    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-brand-gold/40 to-transparent" />
    <div class="absolute inset-0 bg-grid-gold opacity-[0.03] pointer-events-none" />
    <div class="absolute -bottom-32 left-1/4 h-80 w-80 rounded-full bg-brand-gold/[0.07] blur-[110px] pointer-events-none" />

    <div class="relative max-w-content mx-auto px-6 py-16">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-10">

        <!-- Brand col -->
        <div v-reveal class="lg:col-span-1">
          <AppLogo :on-dark="true" size="md" />
          <p class="mt-4 text-sm text-white/50 leading-relaxed">
            Your trusted partner in finding verified properties across the Philippines.
          </p>
          <div class="flex items-center gap-3 mt-6">
            <a
              v-for="social in socials"
              :key="social.label"
              href="#"
              :aria-label="social.label"
              class="h-9 w-9 rounded-full bg-white/10 hover:bg-brand-gold hover:text-brand-navy text-white/60
                     flex items-center justify-center transition-all duration-200 hover:-translate-y-1"
            >
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="social.path" />
              </svg>
            </a>
          </div>
        </div>

        <!-- Link columns -->
        <div v-for="(col, ci) in columns" :key="col.title" v-reveal="{ delay: 80 + ci * 80 }">
          <h4 class="text-sm font-bold text-white mb-4 uppercase tracking-wider">{{ col.title }}</h4>
          <ul class="space-y-2.5">
            <li v-for="link in col.links" :key="link.label">
              <NuxtLink
                v-if="link.href.startsWith('/')"
                :to="link.href"
                class="group inline-flex items-center gap-1.5 text-sm text-white/50 hover:text-brand-gold transition-colors"
              >
                <span class="h-px w-0 group-hover:w-3 bg-brand-gold transition-all duration-300" />
                {{ link.label }}
              </NuxtLink>
              <a
                v-else
                :href="link.href"
                class="group inline-flex items-center gap-1.5 text-sm text-white/50 hover:text-brand-gold transition-colors"
              >
                <span class="h-px w-0 group-hover:w-3 bg-brand-gold transition-all duration-300" />
                {{ link.label }}
              </a>
            </li>
          </ul>
        </div>

        <!-- Newsletter -->
        <div v-reveal="{ delay: 320 }">
          <h4 class="text-sm font-bold text-white mb-4 uppercase tracking-wider">Subscribe to our newsletter</h4>
          <p class="text-xs text-white/50 leading-relaxed mb-4">
            Get the latest property listings and real estate updates.
          </p>
          <div class="flex gap-2">
            <input
              v-model="email"
              type="email"
              placeholder="Enter your email"
              aria-label="Email address"
              class="flex-1 min-w-0 text-xs bg-white/10 border border-white/20 rounded-lg px-3 py-2.5
                     text-white placeholder-white/30 outline-none focus:border-brand-gold/60 focus:bg-white/[0.14]
                     transition-colors"
            />
            <button
              class="group flex-shrink-0 h-10 w-10 bg-brand-gold rounded-lg flex items-center justify-center
                     hover:shadow-[0_4px_18px_rgba(212,175,55,0.5)] hover:-translate-y-0.5 transition-all duration-200"
              aria-label="Subscribe"
              @click="email = ''"
            >
              <svg class="h-4 w-4 text-brand-navy transition-transform duration-200 group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
              </svg>
            </button>
          </div>
        </div>
      </div>

      <!-- Bottom bar -->
      <div class="mt-14 pt-6 border-t border-white/10 flex flex-col sm:flex-row items-center justify-between gap-3">
        <p class="text-xs text-white/30">
          &copy; {{ year }} RealtyLink PH. All rights reserved.
        </p>
        <p class="text-xs text-white/25">
          Verified listings · Licensed agents · Philippines
        </p>
      </div>
    </div>

    <!-- Back to top -->
    <Transition name="fade-up">
      <button
        v-if="showTop"
        class="fixed bottom-6 left-6 z-30 h-11 w-11 rounded-full bg-brand-gold text-brand-navy
               flex items-center justify-center shadow-[0_6px_24px_rgba(212,175,55,0.45)]
               hover:-translate-y-1 hover:shadow-[0_10px_32px_rgba(212,175,55,0.6)] transition-all duration-200"
        aria-label="Back to top"
        @click="toTop"
      >
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7m-7-7v18" />
        </svg>
      </button>
    </Transition>
  </footer>
</template>

<style scoped>
.fade-up-enter-active, .fade-up-leave-active { transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1); }
.fade-up-enter-from, .fade-up-leave-to { opacity: 0; transform: translateY(12px) scale(0.9); }
</style>
