<script setup lang="ts">
/**
 * What the platform actually does, as an even grid of tiles. Each one is
 * something the code enforces or ships — no claims it can't back up:
 *  - agents need an admin-approved PRC application (with a live selfie)
 *  - publish is refused without a title, price, address and a photo
 *  - chat is real-time with sent / delivered / seen receipts
 *  - confirmed viewings sync to Google Calendar for both sides
 *  - reviews only from buyers who dealt with the agent
 *
 * Carries `id="about"` — the navbar's "About" link scrolls here.
 */
const features = [
  {
    title: 'PRC-verified agents',
    desc:  'PRC licence and a live selfie, reviewed by our admin team.',
    path:  'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
  },
  {
    title: 'Complete listings',
    desc:  'Nothing goes live without photos, a price and an address.',
    path:  'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z',
  },
  {
    title: '360° photo tours',
    desc:  'Look around the room before you book a viewing.',
    path:  'M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418',
  },
  {
    title: 'Real-time chat',
    desc:  'Message the agent and see the moment it’s read.',
    path:  'M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155',
  },
  {
    title: 'Google Calendar sync',
    desc:  'Confirmed viewings land in your calendar and the agent’s.',
    path:  'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-9-6h.008v.008H12v-.008zM12 15h.008v.008H12V15zm0 2.25h.008v.008H12v-.008zM9.75 15h.008v.008H9.75V15zm0 2.25h.008v.008H9.75v-.008zM7.5 15h.008v.008H7.5V15zm0 2.25h.008v.008H7.5v-.008zm6.75-4.5h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V15zm0 2.25h.008v.008h-.008v-.008zm2.25-4.5h.008v.008H16.5v-.008zm0 2.25h.008v.008H16.5V15z',
  },
  {
    title: 'RealtyLink AI',
    desc:  'Ask about any listing, any time — even when the agent is away.',
    path:  'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.456-2.456L14.25 6l1.035-.259a3.375 3.375 0 002.456-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z',
  },
  {
    title: 'Map search',
    desc:  'See exactly where each place is before you go.',
    path:  'M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z',
  },
  {
    title: 'Honest reviews',
    desc:  'Only buyers who actually dealt with an agent can rate them.',
    path:  'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z',
  },
]
</script>

<template>
  <section id="about" class="bg-brand-cream dark:bg-brand-navy-deep py-20 lg:py-24 scroll-mt-24 border-y border-brand-navy/[0.04] dark:border-white/5 transition-colors duration-500">
    <div class="max-w-content mx-auto px-4 sm:px-6">
      <HomeSectionHeading
        v-reveal
        align="center"
        eyebrow="Why RealtyLink PH"
        title="A safer way to find a home."
        accent="safer"
        lead="Buying or renting is a big decision. Everything here is built so you only deal with licensed professionals — and can see that for yourself."
      />

      <ul class="mt-12 grid grid-cols-1 min-[30rem]:grid-cols-2 lg:grid-cols-4 gap-4">
        <li
          v-for="(f, i) in features"
          :key="f.title"
          v-reveal="{ delay: (i % 4) * 70 }"
          v-spotlight
          class="group relative overflow-hidden rounded-[1.5rem] p-6 bg-white dark:bg-white/[0.04] ring-2 ring-brand-navy dark:ring-brand-gold
                 hover:ring-brand-gold dark:hover:ring-brand-gold-light hover:-translate-y-1 hover:shadow-[0_22px_44px_-24px_rgba(11,42,92,0.35)]
                 dark:hover:shadow-[0_22px_44px_-22px_rgba(0,0,0,0.6),0_0_22px_-6px_rgba(212,165,74,0.5)]
                 transition-all duration-300"
        >
          <span class="h-11 w-11 rounded-xl flex items-center justify-center bg-brand-gold-soft/50 text-brand-gold-deep ring-1 ring-brand-gold/25
                       dark:bg-brand-gold/10 dark:text-brand-gold
                       group-hover:bg-brand-navy group-hover:text-brand-gold group-hover:ring-brand-navy transition-colors duration-300">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="f.path" />
            </svg>
          </span>
          <h3 class="mt-5 font-semibold text-brand-navy dark:text-white">{{ f.title }}</h3>
          <p class="mt-1.5 text-sm leading-relaxed text-brand-text-secondary dark:text-white/55">{{ f.desc }}</p>
        </li>
      </ul>
    </div>
  </section>
</template>
