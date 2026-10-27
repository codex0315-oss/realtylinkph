<script setup lang="ts">
import type { SupportTicket } from '~/composables/useSupport'

definePageMeta({ layout: 'dashboard', middleware: 'auth' })

/*
 * Help & support (buyers and agents), laid out like Messages: requests on the
 * left (the live chat with RealtyLink Support, then past requests), the chat on
 * the right. A past request opens read-only in the same pane.
 */
const { fetchTicket } = useSupport()

const past = ref<SupportTicket[]>([])
const active = ref<SupportTicket | null>(null)
function onLoaded(p: SupportTicket[], a: SupportTicket | null) { past.value = p; active.value = a }

const viewing = ref<SupportTicket | null>(null)
/* Phones show one pane at a time: the chat by default, the list on request. */
const listOpen = ref(false)

function openLive() { viewing.value = null; listOpen.value = false }
async function openPast(t: SupportTicket) {
  viewing.value = await fetchTicket(t.id)
  listOpen.value = false
}

const timeOf = (iso?: string | null) => (iso ? new Date(iso).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' }) : '')
const day = (iso?: string | null) => (iso ? new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) : '')
const livePreview = computed(() => {
  const last = active.value?.messages?.at(-1)
  if (!last) return 'Ask us anything, we reply right away'
  return (last.sender_type === 'user' ? 'You: ' : '') + (last.body || 'Screenshot')
})
</script>

<template>
  <div class="max-w-6xl mx-auto">
  <header class="mb-6">
    <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">We're here to help</p>
    <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">Help & support</h1>
    <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">RealtyLink AI answers right away; our team takes over whenever you need a person.</p>
  </header>

  <div
    class="flex rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] overflow-hidden shadow-[0_18px_40px_-28px_rgba(11,42,92,0.35)]"
    style="height: calc(100dvh - 16rem); min-height: 24rem"
  >
    <!-- ════════ Requests ════════ -->
    <div
      class="w-full lg:w-80 flex-shrink-0 flex-col border-r border-gray-100 dark:border-white/10"
      :class="listOpen ? 'flex' : 'hidden lg:flex'"
    >
      <div class="flex-1 overflow-y-auto px-2.5 pt-4 pb-3">
        <p class="px-2.5 pb-2 text-[0.625rem] font-bold uppercase tracking-[0.15em] text-gray-400 dark:text-white/30">Current chat</p>
        <button
          type="button"
          class="w-full text-left p-2.5 rounded-xl flex items-center gap-3 transition-colors mb-0.5"
          :class="!viewing ? 'bg-brand-gold/10' : 'hover:bg-gray-50 dark:hover:bg-white/5'"
          @click="openLive"
        >
          <span class="relative flex-shrink-0">
            <img src="/ai/avatar-96.webp" alt="" class="h-10 w-10 rounded-full object-cover bg-white ring-2 ring-brand-gold/60" />
            <span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white dark:border-[#0B1A35]" :class="active?.status === 'open' ? 'bg-amber-500' : 'bg-emerald-500'" />
          </span>
          <span class="flex-1 min-w-0">
            <span class="flex items-center justify-between gap-2">
              <span class="text-sm font-semibold truncate text-brand-navy dark:text-white">RealtyLink Support</span>
              <span class="text-[0.625rem] text-gray-400 flex-shrink-0">{{ timeOf(active?.last_message_at) }}</span>
            </span>
            <span class="block mt-0.5 text-xs truncate text-gray-400 dark:text-white/40">{{ livePreview }}</span>
          </span>
        </button>

        <p class="px-2.5 pt-4 pb-2 text-[0.625rem] font-bold uppercase tracking-[0.15em] text-gray-400 dark:text-white/30">Past requests</p>
        <p v-if="!past.length" class="px-2.5 text-xs text-gray-400 dark:text-white/40">Requests our team resolves are kept here.</p>
        <button
          v-for="t in past"
          :key="t.id"
          type="button"
          class="w-full text-left p-2.5 rounded-xl flex items-center gap-3 transition-colors mb-0.5"
          :class="viewing?.id === t.id ? 'bg-brand-gold/10' : 'hover:bg-gray-50 dark:hover:bg-white/5'"
          @click="openPast(t)"
        >
          <span class="h-10 w-10 flex-shrink-0 rounded-full flex items-center justify-center bg-gray-100 dark:bg-white/10 text-emerald-600 dark:text-emerald-400">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
          </span>
          <span class="flex-1 min-w-0">
            <span class="flex items-center justify-between gap-2">
              <span class="text-sm font-semibold truncate text-brand-navy dark:text-white/90">{{ t.last_message?.body || 'Support request' }}</span>
              <span class="text-[0.625rem] text-gray-400 flex-shrink-0">{{ day(t.resolved_at) }}</span>
            </span>
            <span class="block mt-0.5 text-xs truncate text-gray-400 dark:text-white/40">
              Resolved<template v-if="t.resolved_by"> by {{ t.resolved_by }}</template><template v-if="t.rating"> · {{ t.rating === 'up' ? '👍' : '👎' }}</template>
            </span>
          </span>
        </button>

        <div class="mt-4 px-1">
          <RatePlatformCard />
        </div>
      </div>
    </div>

    <!-- ════════ Chat ════════ -->
    <div class="flex-1 min-w-0 flex-col" :class="listOpen ? 'hidden lg:flex' : 'flex'">
      <SupportChat :viewing="viewing" @loaded="onLoaded" @list="listOpen = true" @close="openLive" />
    </div>
  </div>
  </div>
</template>
