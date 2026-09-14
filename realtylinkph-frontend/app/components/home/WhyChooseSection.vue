<script setup lang="ts">
const features = [
  {
    title: 'Verified Listings',
    desc:  'All properties are verified by our team for your peace of mind.',
    iconPath: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
  },
  {
    title: 'Licensed Agents',
    desc:  'Connect with PRC-licensed and verified real estate agents.',
    iconPath: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
  },
  {
    title: 'Trusted Network',
    desc:  'Work with a growing network of trusted real estate professionals.',
    iconPath: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
  },
  {
    title: 'Secure Transactions',
    desc:  'We ensure every deal is transparent, documented, and fully secure.',
    iconPath: 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
  },
]

/* Subtle pointer-tracked tilt — adds depth without becoming a toy. */
const tilt = reactive<Record<number, string>>({})
let allowTilt = true
onMounted(() => {
  allowTilt = !(window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false)
})

function onMove(e: MouseEvent, i: number) {
  if (!allowTilt) return
  const el = e.currentTarget as HTMLElement
  const r  = el.getBoundingClientRect()
  const px = (e.clientX - r.left) / r.width  - 0.5
  const py = (e.clientY - r.top)  / r.height - 0.5
  tilt[i] = `perspective(800px) rotateX(${-py * 6}deg) rotateY(${px * 6}deg) translateY(-6px)`
}
function onLeave(i: number) {
  tilt[i] = ''
}
</script>

<template>
  <section id="about" class="relative bg-brand-cream dark:bg-brand-navy-deep py-20 transition-colors duration-500 scroll-mt-20 overflow-hidden">
    <div class="absolute inset-0 bg-grid-lines mask-radial-fade pointer-events-none" />
    <div class="absolute inset-0 bg-grid-lines-lg mask-radial-fade pointer-events-none" />

    <div class="relative max-w-content mx-auto px-6">
      <!-- Header -->
      <div v-reveal class="text-center mb-14">
        <div class="flex items-center justify-center gap-3 mb-3">
          <div class="h-px w-8 bg-brand-gold/50" />
          <span class="eyebrow whitespace-nowrap">Why Choose RealtyLinkPH</span>
          <div class="h-px w-8 bg-brand-gold/50" />
        </div>
        <h2 class="font-display text-3xl md:text-4xl font-bold text-brand-navy dark:text-white leading-tight">
          The Trusted Way to Find Property
        </h2>
        <p class="mt-3 text-sm text-brand-text-secondary dark:text-white/55 max-w-md mx-auto">
          Every listing and agent is verified, so you can browse, buy, and rent with total confidence.
        </p>
      </div>

      <!-- 4-column grid -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
        <div
          v-for="(f, i) in features"
          :key="f.title"
          v-reveal="{ delay: i * 110 }"
          class="group relative text-center bg-white dark:bg-brand-navy-mid rounded-2xl
                 border border-gray-100 dark:border-white/10 p-7
                 hover:border-brand-gold/40 hover:shadow-[0_18px_44px_rgba(8,21,47,0.13)]
                 dark:hover:shadow-[0_18px_44px_rgba(0,0,0,0.5)]
                 transition-[box-shadow,border-color,transform] duration-300 will-change-transform"
          :style="{ transform: tilt[i] }"
          @mousemove="onMove($event, i)"
          @mouseleave="onLeave(i)"
        >
          <!-- gold wash on hover -->
          <div class="absolute inset-0 rounded-2xl bg-gradient-to-b from-brand-gold/[0.07] to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 pointer-events-none" />

          <div class="relative w-16 h-16 rounded-full bg-brand-gold/10 border border-brand-gold/25 flex items-center justify-center mx-auto mb-5
                      group-hover:bg-brand-gold/20 group-hover:scale-110 transition-all duration-300">
            <svg class="h-8 w-8 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" :d="f.iconPath" />
            </svg>
          </div>
          <h3 class="relative font-bold text-brand-navy dark:text-white text-sm">{{ f.title }}</h3>
          <p class="relative mt-2 text-xs text-brand-text-secondary dark:text-white/55 leading-relaxed">{{ f.desc }}</p>
        </div>
      </div>
    </div>
  </section>
</template>
