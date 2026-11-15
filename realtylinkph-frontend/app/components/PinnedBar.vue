<script setup lang="ts">
import type { Message } from '~/types'

/**
 * "Pinned messages" bar at the top of a chat, like Messenger: the latest pin
 * and a count; tap to see them all, jump to one, or unpin it.
 */
const props = defineProps<{ pinned: Message[]; meId?: number; otherName?: string }>()
const emit = defineEmits<{ jump: [id: number]; unpin: [id: number] }>()

const open = ref(false)
const root = ref<HTMLElement | null>(null)
const latest = computed(() => props.pinned[0] ?? null)
const by = (m: Message) => (m.pinned_by_id === props.meId ? 'You' : (props.otherName ?? 'They'))
function onDocClick(e: MouseEvent) { if (open.value && root.value && !root.value.contains(e.target as Node)) open.value = false }
onMounted(() => document.addEventListener('click', onDocClick))
onUnmounted(() => document.removeEventListener('click', onDocClick))
</script>

<template>
  <div v-if="latest" ref="root" class="relative border-b border-brand-navy/[0.06] dark:border-white/10">
    <button
      type="button"
      class="flex w-full items-center gap-2.5 px-4 py-2 text-left bg-brand-gold/[0.07] hover:bg-brand-gold/[0.12] dark:bg-brand-gold/[0.08] transition"
      :aria-expanded="open"
      @click.stop="open = !open"
    >
      <svg class="h-4 w-4 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 3.75l3.75 3.75-2.25.75-3 3 .75 4.5-1.5 1.5-3.75-3.75L6 18l-.75-.75 4.5-4.5L6 9l1.5-1.5 4.5.75 3-3 .75-2.25z" /></svg>
      <span class="min-w-0 flex-1">
        <span class="block text-[0.6875rem] font-bold uppercase tracking-wide text-brand-gold-deep dark:text-brand-gold">
          Pinned message<template v-if="pinned.length > 1">s · {{ pinned.length }}</template>
        </span>
        <span class="block truncate text-sm text-brand-navy dark:text-white">{{ latest.body }}</span>
      </span>
      <svg class="h-4 w-4 flex-none text-brand-navy/40 dark:text-white/40 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
    </button>
    <Transition name="pin">
      <ul v-if="open" class="absolute inset-x-0 top-full z-30 max-h-72 overflow-y-auto bg-[#ffffff] dark:bg-[#0B1A35] shadow-[0_14px_36px_-12px_rgba(11,42,92,0.45)] ring-1 ring-brand-navy/10 dark:ring-white/10 divide-y divide-brand-navy/[0.06] dark:divide-white/[0.08]">
        <li v-for="m in pinned" :key="m.id" class="flex items-start gap-3 px-4 py-2.5">
          <button type="button" class="min-w-0 flex-1 text-left" @click.stop="open = false; emit('jump', m.id)">
            <span class="block text-sm text-brand-navy dark:text-white line-clamp-2">{{ m.body }}</span>
            <span class="mt-0.5 block text-[0.6875rem] text-brand-navy/45 dark:text-white/45">Pinned by {{ by(m) }}</span>
          </button>
          <button type="button" class="flex-none rounded-full px-2.5 py-1 text-xs font-semibold text-brand-navy/60 ring-1 ring-brand-navy/15 hover:text-red-600 hover:ring-red-300 dark:text-white/60 dark:ring-white/20 transition" @click.stop="emit('unpin', m.id)">Unpin</button>
        </li>
      </ul>
    </Transition>
  </div>
</template>

<style scoped>
.pin-enter-active, .pin-leave-active { transition: opacity 0.12s ease, transform 0.12s ease; }
.pin-enter-from, .pin-leave-to { opacity: 0; transform: translateY(-4px); }
</style>
