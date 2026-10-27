<script setup lang="ts">
import type { Conversation } from '~/types'

/**
 * The Messages icon beside the bell: chats live here, everything else about
 * the account stays in Notifications.
 *
 * The badge counts unread *messages* from the conversations themselves (the
 * source of truth), not from `new_message` notifications — so reading a
 * thread clears it, and it can't drift from the inbox. A live `new_message`
 * refreshes the list; opening a thread clears its count (useConversation
 * markRead → store.clearUnread).
 *
 * `v-model:open` lets the host close its other dropdowns when this opens.
 */
// The host's button styling (`class`) goes on the icon button, not the wrapper.
defineOptions({ inheritAttrs: false })

const open = defineModel<boolean>('open', { default: false })

const authStore = useAuthStore()
const convStore = useConversationStore()
const { fetchConversations } = useConversation()
const { onNotificationType } = useEcho()

const rootEl = ref<HTMLElement | null>(null)
const loaded = ref(false)

const me = computed(() => authStore.user?.id)
const recent = computed(() =>
  [...convStore.conversations]
    .sort((a, b) => (b.last_message_at ?? '').localeCompare(a.last_message_at ?? ''))
    .slice(0, 6),
)
const unread = computed(() => convStore.totalUnread)

/* A short nudge on the icon when a new message lands (count goes up). */
const nudge = ref(false)
let nudgeTimer: ReturnType<typeof setTimeout> | null = null
watch(unread, (now, before) => {
  if (!loaded.value || now <= (before ?? 0)) return
  nudge.value = false
  requestAnimationFrame(() => { nudge.value = true })
  if (nudgeTimer) clearTimeout(nudgeTimer)
  nudgeTimer = setTimeout(() => { nudge.value = false }, 700)
})
onUnmounted(() => { if (nudgeTimer) clearTimeout(nudgeTimer) })

function other(c: Conversation) {
  return me.value === c.buyer_id ? c.agent : c.buyer
}
function when(iso?: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  const mins = Math.floor((Date.now() - d.getTime()) / 60_000)
  if (mins < 1) return 'now'
  if (mins < 60) return `${mins}m`
  if (mins < 60 * 24) return `${Math.floor(mins / 60)}h`
  if (mins < 60 * 24 * 7) return `${Math.floor(mins / 1440)}d`
  return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
}
function preview(c: Conversation): string {
  const m = c.latest_message
  if (!m) return 'Start a conversation'
  const mine = m.sender_id === me.value
  return `${mine ? 'You: ' : ''}${m.body}`
}

async function refresh() {
  await fetchConversations()
  loaded.value = true
}

let stop: (() => void) | null = null
onMounted(() => {
  // Messages already loaded the list? Use it; otherwise fetch once for the badge.
  if (convStore.conversations.length) loaded.value = true
  else refresh()
  stop = onNotificationType('new_message', () => { refresh() })
  document.addEventListener('pointerdown', onDocPointer)
  document.addEventListener('keydown', onKey)
})
onUnmounted(() => {
  stop?.()
  document.removeEventListener('pointerdown', onDocPointer)
  document.removeEventListener('keydown', onKey)
})

function onDocPointer(e: PointerEvent) {
  if (open.value && rootEl.value && !rootEl.value.contains(e.target as Node)) open.value = false
}
function onKey(e: KeyboardEvent) { if (e.key === 'Escape') open.value = false }

function toggle() {
  open.value = !open.value
  if (open.value) refresh()   // fresh previews whenever it's opened
}
async function go(c: Conversation) {
  open.value = false
  await navigateTo(`/dashboard/messages?c=${c.id}`)
}
</script>

<template>
  <div ref="rootEl" class="relative">
    <button
      type="button"
      class="relative p-2.5 rounded-xl transition-colors"
      v-bind="$attrs"
      :aria-label="unread ? `Messages, ${unread} unread` : 'Messages'"
      :aria-expanded="open"
      @click="toggle"
    >
      <!-- Two overlapping bubbles = conversations. Outline at rest; filled
           in gold while the menu is open, like a selected tab. -->
      <span class="relative block h-5 w-5" :class="nudge ? 'msg-nudge' : ''">
        <svg
          class="absolute inset-0 h-5 w-5 transition-all duration-200"
          :class="open ? 'opacity-0 scale-75' : 'opacity-100 scale-100'"
          fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"
        >
          <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
        </svg>
        <svg
          class="absolute inset-0 h-5 w-5 text-brand-gold-deep dark:text-brand-gold transition-all duration-200"
          :class="open ? 'opacity-100 scale-100' : 'opacity-0 scale-75'"
          fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"
        >
          <path d="M4.913 2.658c2.075-.27 4.19-.408 6.337-.408 2.147 0 4.262.139 6.337.408 1.922.25 3.291 1.861 3.405 3.727a4.403 4.403 0 00-1.032-.211 50.89 50.89 0 00-8.42 0c-2.358.196-4.04 2.19-4.04 4.434v4.286a4.47 4.47 0 002.433 3.984L7.28 21.53A.75.75 0 016 21v-4.03a48.527 48.527 0 01-1.087-.128C2.905 16.58 1.5 14.833 1.5 12.862V6.638c0-1.97 1.405-3.718 3.413-3.979z" />
          <path d="M15.75 7.5c-1.376 0-2.739.057-4.086.169C10.124 7.797 9 9.103 9 10.609v4.285c0 1.507 1.128 2.814 2.67 2.94 1.243.102 2.5.157 3.768.165l2.782 2.781a.75.75 0 001.28-.53v-2.39l.33-.026c1.542-.125 2.67-1.433 2.67-2.94v-4.286c0-1.505-1.125-2.811-2.664-2.94A49.392 49.392 0 0015.75 7.5z" />
        </svg>
      </span>
      <template v-if="unread > 0">
        <span class="absolute top-1.5 right-1.5 h-4 min-w-[1rem] px-1 bg-brand-gold rounded-full text-[0.5625rem] font-bold text-brand-navy flex items-center justify-center">
          {{ unread > 9 ? '9+' : unread }}
        </span>
        <span class="absolute top-1.5 right-1.5 h-4 w-4 rounded-full bg-brand-gold animate-pulse-ring pointer-events-none motion-reduce:hidden" aria-hidden="true" />
      </template>
    </button>

    <Transition name="msg-drop">
      <div
        v-if="open"
        class="absolute right-0 mt-2 w-[22rem] max-w-[calc(100vw-1.5rem)] z-50 overflow-hidden rounded-2xl
               bg-white dark:bg-[#0d2550] ring-1 ring-gray-100 dark:ring-white/10
               shadow-[0_12px_44px_rgba(0,0,0,0.18)]"
      >
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100 dark:border-white/10">
          <span class="text-sm font-bold text-brand-navy dark:text-white">
            Messages
            <span v-if="unread" class="ml-1.5 rounded-full bg-brand-gold/20 px-2 py-0.5 text-[0.625rem] font-bold text-brand-gold-deep dark:text-brand-gold">{{ unread }} new</span>
          </span>
          <NuxtLink to="/dashboard/messages" class="text-xs font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline" @click="open = false">
            Open inbox
          </NuxtLink>
        </div>

        <div class="max-h-96 overflow-y-auto">
          <!-- First load -->
          <div v-if="!loaded" class="p-4 space-y-3">
            <div v-for="i in 3" :key="i" class="flex items-center gap-3 animate-pulse">
              <span class="h-10 w-10 rounded-full bg-gray-100 dark:bg-white/10" />
              <span class="flex-1 space-y-1.5"><span class="block h-3 w-1/2 rounded bg-gray-100 dark:bg-white/10" /><span class="block h-2.5 w-3/4 rounded bg-gray-100 dark:bg-white/10" /></span>
            </div>
          </div>

          <div v-else-if="!recent.length" class="px-6 py-10 text-center">
            <p class="text-sm font-semibold text-brand-navy dark:text-white">No conversations yet</p>
            <p class="mt-1 text-xs text-gray-400 dark:text-white/45">
              {{ authStore.isAgent ? 'Buyers who message you about a listing will show up here.' : 'Message an agent from any listing to start a chat.' }}
            </p>
          </div>

          <button
            v-for="c in recent"
            v-else
            :key="c.id"
            type="button"
            class="w-full text-left px-4 py-3 flex items-center gap-3 border-b border-gray-50 dark:border-white/5 last:border-0 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.05]"
            :class="c.unread_count ? 'bg-brand-gold/[0.06]' : ''"
            @click="go(c)"
          >
            <AppAvatar :name="other(c)?.name" :src="other(c)?.avatar" size="md" />
            <span class="min-w-0 flex-1">
              <span class="flex items-center justify-between gap-2">
                <span class="truncate text-sm text-brand-navy dark:text-white" :class="c.unread_count ? 'font-bold' : 'font-semibold'">{{ other(c)?.name ?? 'Unknown' }}</span>
                <span class="flex-none text-[0.625rem]" :class="c.unread_count ? 'font-bold text-brand-gold-deep dark:text-brand-gold' : 'text-gray-400 dark:text-white/40'">{{ when(c.last_message_at) }}</span>
              </span>
              <span v-if="c.property?.title" class="block truncate text-[0.6875rem] text-gray-400 dark:text-white/40">{{ c.property.title }}</span>
              <span class="mt-0.5 flex items-center justify-between gap-2">
                <span class="truncate text-xs" :class="c.unread_count ? 'text-brand-navy dark:text-white font-medium' : 'text-gray-500 dark:text-white/55'">
                  <span v-if="c.latest_message?.is_ai" class="font-semibold text-brand-gold-deep dark:text-brand-gold">AI: </span>{{ preview(c) }}
                </span>
                <span v-if="c.unread_count" class="flex-none h-[1.125rem] min-w-[1.125rem] px-1 rounded-full bg-brand-gold text-[0.625rem] font-bold text-brand-navy flex items-center justify-center">{{ c.unread_count }}</span>
              </span>
            </span>
          </button>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.msg-drop-enter-active { transition: opacity 0.18s ease, transform 0.18s cubic-bezier(0.16, 1, 0.3, 1); }
.msg-drop-leave-active { transition: opacity 0.12s ease, transform 0.12s ease; }
.msg-drop-enter-from, .msg-drop-leave-to { opacity: 0; transform: scale(0.96) translateY(-6px); }

.msg-nudge { animation: msg-nudge 0.6s cubic-bezier(0.36, 0.07, 0.19, 0.97); }
@keyframes msg-nudge {
  0%, 100% { transform: rotate(0) scale(1); }
  20%      { transform: rotate(-12deg) scale(1.1); }
  45%      { transform: rotate(10deg) scale(1.1); }
  70%      { transform: rotate(-5deg); }
}
@media (prefers-reduced-motion: reduce) { .msg-nudge { animation: none; } }
</style>
