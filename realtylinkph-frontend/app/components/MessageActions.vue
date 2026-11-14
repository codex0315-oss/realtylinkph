<script setup lang="ts">
/**
 * Wraps one chat bubble with Messenger-style actions:
 *   - desktop: a "⋯" button appears beside the bubble on hover;
 *   - phone: press and hold the bubble (or right-click) to open the menu.
 * Your own messages: "Unsend for everyone" and "Remove for you".
 * Someone else's message: "Remove for you" only.
 * The parent does the work (and the confirm dialog) on the emitted events.
 */
const props = withDefaults(defineProps<{ canUnsend?: boolean; side?: 'left' | 'right'; disabled?: boolean }>(), { canUnsend: false, side: 'left', disabled: false })
const emit = defineEmits<{ unsend: []; remove: [] }>()

const open = ref(false)
const root = ref<HTMLElement | null>(null)
let pressTimer: ReturnType<typeof setTimeout> | undefined

function onPointerDown(e: PointerEvent) {
  if (props.disabled || e.pointerType === 'mouse') return
  clearTimeout(pressTimer)
  pressTimer = setTimeout(() => { open.value = true; navigator.vibrate?.(15) }, 500)
}
function cancelPress() { clearTimeout(pressTimer) }
function onContextMenu(e: MouseEvent) {
  if (props.disabled) return
  e.preventDefault()
  open.value = true
}
function choose(kind: 'unsend' | 'remove') {
  open.value = false
  kind === 'unsend' ? emit('unsend') : emit('remove')
}
function onDocClick(e: MouseEvent) {
  if (open.value && root.value && !root.value.contains(e.target as Node)) open.value = false
}
function onKey(e: KeyboardEvent) { if (e.key === 'Escape') open.value = false }
onMounted(() => { document.addEventListener('click', onDocClick); document.addEventListener('keydown', onKey) })
onUnmounted(() => { document.removeEventListener('click', onDocClick); document.removeEventListener('keydown', onKey); clearTimeout(pressTimer) })
</script>

<template>
  <div
    ref="root"
    class="group/act relative flex items-center gap-1"
    :class="side === 'right' ? 'flex-row-reverse' : 'flex-row'"
    @pointerdown="onPointerDown"
    @pointerup="cancelPress"
    @pointerleave="cancelPress"
    @pointercancel="cancelPress"
    @contextmenu="onContextMenu"
  >
    <slot />
    <div v-if="!disabled" class="relative flex-none self-center">
      <button
        type="button"
        class="h-7 w-7 rounded-full flex items-center justify-center text-brand-navy/45 hover:text-brand-navy hover:bg-brand-navy/[0.06] dark:text-white/45 dark:hover:text-white dark:hover:bg-white/10 transition
               opacity-0 group-hover/act:opacity-100 focus-visible:opacity-100 [@media(hover:none)]:hidden"
        :class="open ? '!opacity-100' : ''"
        aria-label="Message options"
        title="More"
        @click.stop="open = !open"
      >
        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></svg>
      </button>
    </div>
    <Transition name="act">
      <div
        v-if="open"
        role="menu"
        class="absolute z-30 top-full mt-1 w-52 overflow-hidden rounded-xl bg-[#ffffff] dark:bg-[#0B1A35] ring-1 ring-brand-navy/10 dark:ring-white/10 shadow-[0_14px_36px_-12px_rgba(11,42,92,0.45)] py-1"
        :class="side === 'right' ? 'right-0' : 'left-0'"
      >
        <button v-if="canUnsend" type="button" role="menuitem" class="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-left text-sm font-semibold text-red-600 hover:bg-red-500/[0.07] dark:text-red-400" @click.stop="choose('unsend')">
          <svg class="h-4 w-4 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" /></svg>
          Unsend for everyone
        </button>
        <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-left text-sm font-medium text-brand-navy hover:bg-brand-navy/[0.05] dark:text-white dark:hover:bg-white/[0.06]" @click.stop="choose('remove')">
          <svg class="h-4 w-4 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
          Remove for you
        </button>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.act-enter-active, .act-leave-active { transition: opacity 0.12s ease, transform 0.12s ease; }
.act-enter-from, .act-leave-to { opacity: 0; transform: translateY(-4px) scale(0.98); }
</style>
