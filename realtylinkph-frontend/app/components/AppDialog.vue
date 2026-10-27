<script setup lang="ts">
import type { ConfirmIcon, ConfirmTone } from '~/composables/useConfirm'

/**
 * The frame every dialog shares: confirmations, approvals, forms.
 *
 *   Desktop — centred card. Phone — a sheet that slides up from the bottom,
 *   with full-width buttons a thumb can reach.
 *
 * It owns the behaviour too: Escape and a backdrop tap close it (not while
 * `busy`), Tab stays inside, the page behind can't scroll, and focus lands on
 * an element marked `data-autofocus` (else the first field or button). Only
 * the topmost dialog reacts to keys, so a confirm opened over a form works.
 */
const props = withDefaults(defineProps<{
  open: boolean
  title: string
  subtitle?: string
  tone?: ConfirmTone
  icon?: ConfirmIcon | null
  /** Blocks closing while work is in flight. */
  busy?: boolean
  size?: 'sm' | 'md' | 'lg' | 'xl'
  labelledBy?: string
  /** Just a title bar: the slot lays out (and pads) its own content. */
  bare?: boolean
}>(), { tone: 'primary', size: 'sm', busy: false, subtitle: '', icon: undefined, labelledBy: undefined, bare: false })

const emit = defineEmits<{ close: [] }>()

const panel = ref<HTMLElement | null>(null)
const uid = `dlg-${Math.random().toString(36).slice(2, 9)}`
const titleId = computed(() => props.labelledBy ?? `${uid}-title`)
const iconPath = computed(() => (props.icon === null ? null : DIALOG_ICON[props.icon ?? DEFAULT_ICON[props.tone]]))
const WIDTH = { sm: 'sm:max-w-[28rem]', md: 'sm:max-w-[34rem]', lg: 'sm:max-w-[42rem]', xl: 'sm:max-w-[56rem]' } as const

function close() { if (!props.busy) emit('close') }

/* Stacking: only the top dialog handles keys; the page unlocks when the last one closes. */
const stack = useState<string[]>('app-dialog-stack', () => [])
function onKeydown(e: KeyboardEvent) {
  if (!props.open || stack.value[stack.value.length - 1] !== uid) return
  if (e.key === 'Escape') { e.preventDefault(); close(); return }
  if (e.key === 'Tab' && panel.value) {
    const items = panel.value.querySelectorAll<HTMLElement>('button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), a[href]')
    if (!items.length) return
    const first = items[0]!, last = items[items.length - 1]!
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus() }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus() }
  }
}

watch(() => props.open, async (open) => {
  if (!import.meta.client) return
  stack.value = open ? [...stack.value.filter(id => id !== uid), uid] : stack.value.filter(id => id !== uid)
  document.body.style.overflow = stack.value.length ? 'hidden' : ''
  if (!open) return
  await nextTick()
  const target = panel.value?.querySelector<HTMLElement>('[data-autofocus]')
    ?? panel.value?.querySelector<HTMLElement>('input, textarea, select, button')
  target?.focus({ preventScroll: true })
}, { immediate: true })

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown)
  if (!import.meta.client) return
  stack.value = stack.value.filter(id => id !== uid)
  if (!stack.value.length) document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <Transition name="dlg-fade">
      <div
        v-if="open"
        class="fixed inset-0 z-[110] flex items-end sm:items-center justify-center sm:p-4"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        @click.self="close"
      >
        <div class="absolute inset-0 bg-[#060e1f]/60 backdrop-blur-[6px]" aria-hidden="true" @click="close" />

        <Transition name="dlg-pop" appear>
          <div
            v-if="open"
            ref="panel"
            class="dlg-panel relative w-full max-h-[92dvh] flex flex-col overflow-hidden
                   rounded-t-[1.75rem] sm:rounded-3xl
                   bg-[#ffffff] dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.08] dark:ring-white/10
                   shadow-[0_-10px_40px_rgba(11,42,92,0.25)] sm:shadow-[0_30px_80px_rgba(11,42,92,0.35)] dark:shadow-[0_30px_80px_rgba(0,0,0,0.7)]"
            :class="WIDTH[size]"
          >
            <!-- Grab handle (phones) -->
            <span class="sm:hidden mx-auto mt-2.5 h-1.5 w-10 flex-none rounded-full bg-brand-navy/15 dark:bg-white/20" aria-hidden="true" />

            <!-- Bare: a title bar, then the caller's own content -->
            <template v-if="bare">
              <div v-if="title" class="flex-none flex items-center gap-3 px-5 sm:px-6 pt-3 sm:pt-5 pb-3 border-b border-brand-navy/[0.06] dark:border-white/[0.08]">
                <h2 :id="titleId" class="min-w-0 flex-1 font-heading text-lg font-semibold text-brand-navy dark:text-white">{{ title }}</h2>
                <button
                  type="button"
                  class="-mr-2 h-9 w-9 flex-none rounded-full flex items-center justify-center text-brand-navy/45 hover:text-brand-navy hover:bg-brand-navy/[0.06] dark:text-white/45 dark:hover:text-white dark:hover:bg-white/10 transition disabled:opacity-40"
                  :disabled="busy"
                  aria-label="Close"
                  @click="close"
                >
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
              </div>
              <div class="flex-1 overflow-y-auto overscroll-contain pb-[env(safe-area-inset-bottom)]">
                <slot />
              </div>
            </template>

            <div v-else class="flex-1 overflow-y-auto overscroll-contain px-5 sm:px-6 pt-4 sm:pt-6 pb-5">
              <div class="flex items-start gap-4">
                <span
                  v-if="iconPath"
                  class="h-12 w-12 flex-none rounded-2xl ring-1 flex items-center justify-center"
                  :class="DIALOG_TONE[tone].tile"
                >
                  <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="iconPath" /></svg>
                </span>
                <div class="min-w-0 flex-1 pt-0.5">
                  <h2 :id="titleId" class="font-heading text-xl font-semibold leading-snug text-brand-navy dark:text-white">{{ title }}</h2>
                  <p v-if="subtitle" class="mt-1 text-sm leading-relaxed text-brand-navy/60 dark:text-white/60">{{ subtitle }}</p>
                </div>
                <button
                  type="button"
                  class="hidden sm:flex -mr-2 -mt-1 h-9 w-9 flex-none rounded-full items-center justify-center text-brand-navy/45 hover:text-brand-navy hover:bg-brand-navy/[0.06] dark:text-white/45 dark:hover:text-white dark:hover:bg-white/10 transition disabled:opacity-40"
                  :disabled="busy"
                  aria-label="Close"
                  @click="close"
                >
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
              </div>

              <div v-if="$slots.default" class="mt-4">
                <slot />
              </div>
            </div>

            <!-- Footer: buttons stack full width on phones, primary on top -->
            <div
              v-if="$slots.footer"
              class="flex-none flex flex-col-reverse sm:flex-row sm:justify-end gap-2.5 sm:gap-3 px-5 sm:px-6 py-4
                     pb-[max(1rem,env(safe-area-inset-bottom))] sm:pb-4
                     border-t border-brand-navy/[0.06] dark:border-white/[0.08] bg-brand-cream/50 dark:bg-white/[0.02]"
            >
              <slot name="footer" />
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.dlg-fade-enter-active, .dlg-fade-leave-active { transition: opacity 0.2s ease; }
.dlg-fade-enter-from, .dlg-fade-leave-to { opacity: 0; }

/* Desktop pops in; phones slide the sheet up. */
.dlg-pop-enter-active { transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.2s ease; }
.dlg-pop-leave-active { transition: transform 0.18s ease, opacity 0.15s ease; }
.dlg-pop-enter-from, .dlg-pop-leave-to { opacity: 0; transform: translateY(100%); }
@media (min-width: 640px) {
  .dlg-pop-enter-from, .dlg-pop-leave-to { transform: scale(0.95) translateY(10px); }
}

@media (prefers-reduced-motion: reduce) {
  .dlg-pop-enter-active, .dlg-pop-leave-active, .dlg-fade-enter-active, .dlg-fade-leave-active { transition: none; }
}
</style>
