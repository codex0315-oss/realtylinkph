<script setup lang="ts">
/**
 * Four promises, each one something the platform actually enforces — no
 * marketing claims the code can't back up:
 *  - agents need an admin-approved PRC application before they can list
 *  - publish is refused without a title, price, address and a photo
 *  - chat is real-time with sent / delivered / seen receipts
 *  - RealtyLink AI answers in chat while the agent is offline
 */
const items = [
  {
    title: 'PRC-verified agents',
    desc:  'Every agent’s licence is checked by our team before they can post.',
    path:  'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
  },
  {
    title: 'Complete listings',
    desc:  'No listing goes live without photos, a price and an address.',
    path:  'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
  },
  {
    title: 'Talk to agents directly',
    desc:  'Real-time chat — see the moment your message is read.',
    path:  'M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155',
  },
  {
    title: 'RealtyLink AI',
    desc:  'Answers your questions while the agent is away.',
    path:  'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.456-2.456L14.25 6l1.035-.259a3.375 3.375 0 002.456-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z',
  },
]
</script>

<template>
  <section class="bg-white dark:bg-brand-navy-deep border-b border-gray-100 dark:border-white/10 transition-colors duration-500">
    <ul class="max-w-content mx-auto px-6 py-12 lg:py-14 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-y-10 lg:divide-x divide-gray-200 dark:divide-white/10">
      <li
        v-for="(item, i) in items"
        :key="item.title"
        v-reveal="{ delay: i * 70 }"
        class="flex flex-col items-center text-center px-6"
      >
        <span class="h-14 w-14 rounded-full flex items-center justify-center bg-brand-gold-soft/50 text-brand-gold-deep ring-1 ring-brand-gold/25 dark:bg-brand-gold/10 dark:text-brand-gold">
          <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" :d="item.path" />
          </svg>
        </span>
        <h3 class="mt-4 font-sans font-semibold text-[0.9375rem] text-brand-navy dark:text-white">{{ item.title }}</h3>
        <p class="mt-1.5 max-w-[16rem] text-sm leading-relaxed text-brand-text-secondary dark:text-white/55">{{ item.desc }}</p>
      </li>
    </ul>
  </section>
</template>
