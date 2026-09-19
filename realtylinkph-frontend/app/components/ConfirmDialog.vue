<script setup lang="ts">
import type { ConfirmTone } from '~/composables/useConfirm'

/**
 * Global confirmation dialog. Mounted once in app.vue and driven entirely by
 * `useConfirm()`. Replaces the browser's native `confirm()`, which cannot be
 * styled, cannot show consequences, and reads as an error to most people.
 */
const confirm = useConfirm()
const state = confirm.state

const panel = ref<HTMLElement | null>(null)
const cancelBtn  = ref<HTMLButtonElement | null>(null)
const confirmBtn = ref<HTMLButtonElement | null>(null)

const tone = computed<ConfirmTone>(() => state.value.tone ?? 'primary')

const TONES: Record<ConfirmTone, {
  ring: string; icon: string; button: string; path: string
}> = {
  danger: {
    ring:   'bg-red-50 dark:bg-red-500/10 border-red-200/70 dark:border-red-500/25 text-red-600 dark:text-red-400',
    icon:   'text-red-600 dark:text-red-400',
    button: 'bg-red-600 hover:bg-red-700 text-white shadow-[0_4px_16px_rgba(220,38,38,0.35)] hover:shadow-[0_6px_22px_rgba(220,38,38,0.5)]',
    path:   'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
  },
  warning: {
    ring:   'bg-amber-50 dark:bg-amber-500/10 border-amber-200/70 dark:border-amber-500/25 text-amber-600 dark:text-amber-400',
    icon:   'text-amber-600 dark:text-amber-400',
    button: 'bg-amber-500 hover:bg-amber-600 text-white shadow-[0_4px_16px_rgba(245,158,11,0.35)] hover:shadow-[0_6px_22px_rgba(245,158,11,0.5)]',
    path:   'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
  },
  primary: {
    ring:   'bg-brand-gold/10 border-brand-gold/30 text-brand-gold',
    icon:   'text-brand-gold',
    button: 'bg-brand-gold hover:bg-brand-gold-light text-brand-navy shadow-[0_4px_16px_rgba(212,175,55,0.4)] hover:shadow-[0_6px_22px_rgba(212,175,55,0.6)]',
    path:   'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  },
}

const t = computed(() => TONES[tone.value])

/* Destructive actions focus Cancel, so a stray Enter never deletes anything.
   Non-destructive actions focus Confirm, which is the likely intent. */
watch(() => state.value.open, async (open) => {
  if (!import.meta.client) return
  document.body.style.overflow = open ? 'hidden' : ''
  if (!open) return
  await nextTick()
  ;(tone.value === 'danger' ? cancelBtn.value : confirmBtn.value)?.focus()
})

function onKeydown(e: KeyboardEvent) {
  if (!state.value.open) return

  if (e.key === 'Escape') {
    e.preventDefault()
    confirm.cancel()
    return
  }

  // Keep tab focus inside the dialog.
  if (e.key === 'Tab' && panel.value) {
    const focusables = panel.value.querySelectorAll<HTMLElement>('button:not([disabled])')
    if (!focusables.length) return
    const first = focusables[0]!
    const last  = focusables[focusables.length - 1]!
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault(); last.focus()
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault(); first.focus()
    }
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown)
  if (import.meta.client) document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <Transition name="confirm-fade">
      <div
        v-if="state.open"
        class="fixed inset-0 z-[110] flex items-center justify-center p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="confirm-title"
        @click.self="confirm.cancel()"
      >
        <!-- Backdrop -->
        <div
          class="absolute inset-0"
          style="background: rgba(6,14,31,0.6); backdrop-filter: blur(8px);"
        />

        <!-- Panel -->
        <Transition name="confirm-pop">
          <div
            v-if="state.open"
            ref="panel"
            class="relative w-full max-w-[27.5rem] rounded-2xl overflow-hidden
                   bg-white dark:bg-brand-navy-mid
                   border border-gray-200 dark:border-white/10
                   shadow-[0_30px_80px_rgba(8,21,47,0.35)] dark:shadow-[0_30px_80px_rgba(0,0,0,0.7)]"
          >
            <div class="px-6 pt-6 pb-5">
              <!-- Icon -->
              <div
                class="h-12 w-12 rounded-full border flex items-center justify-center mb-4"
                :class="t.ring"
              >
                <svg class="h-6 w-6" :class="t.icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="t.path" />
                </svg>
              </div>

              <h2
                id="confirm-title"
                class="font-display text-lg font-bold text-brand-navy dark:text-white leading-snug"
              >
                {{ state.title }}
              </h2>

              <p
                v-if="state.message"
                class="mt-2 text-sm text-brand-text-secondary dark:text-white/60 leading-relaxed"
              >
                {{ state.message }}
              </p>

              <!-- Subject strip — e.g. the listing being acted on -->
              <div
                v-if="state.subject"
                class="mt-3 rounded-xl px-3.5 py-2.5 text-sm font-semibold truncate
                       bg-gray-50 dark:bg-white/5 border border-gray-100 dark:border-white/10
                       text-brand-navy dark:text-white"
              >
                {{ state.subject }}
              </div>

              <!-- Consequences -->
              <ul
                v-if="state.consequences?.length"
                class="mt-4 space-y-2"
              >
                <li
                  v-for="line in state.consequences"
                  :key="line"
                  class="flex items-start gap-2.5 text-sm text-brand-text-secondary dark:text-white/60"
                >
                  <svg class="h-4 w-4 mt-0.5 flex-shrink-0 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                  </svg>
                  <span>{{ line }}</span>
                </li>
              </ul>
            </div>

            <!-- Actions -->
            <div
              class="flex gap-3 px-6 py-4 border-t
                     bg-gray-50 dark:bg-white/[0.03] border-gray-100 dark:border-white/10"
            >
              <button
                ref="cancelBtn"
                type="button"
                class="flex-1 rounded-xl px-4 py-2.5 text-sm font-semibold transition-colors
                       border border-gray-300 dark:border-white/20
                       text-brand-navy dark:text-white/85
                       hover:bg-gray-100 dark:hover:bg-white/10"
                @click="confirm.cancel()"
              >
                {{ state.cancelLabel }}
              </button>
              <button
                ref="confirmBtn"
                type="button"
                class="flex-1 rounded-xl px-4 py-2.5 text-sm font-bold transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0"
                :class="t.button"
                @click="confirm.accept()"
              >
                {{ state.confirmLabel }}
              </button>
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.confirm-fade-enter-active, .confirm-fade-leave-active { transition: opacity 0.18s ease; }
.confirm-fade-enter-from, .confirm-fade-leave-to { opacity: 0; }

.confirm-pop-enter-active { transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1); }
.confirm-pop-leave-active { transition: all 0.14s ease; }
.confirm-pop-enter-from, .confirm-pop-leave-to { opacity: 0; transform: scale(0.94) translateY(10px); }

@media (prefers-reduced-motion: reduce) {
  .confirm-pop-enter-active, .confirm-pop-leave-active,
  .confirm-fade-enter-active, .confirm-fade-leave-active { transition: none; }
  .confirm-pop-enter-from, .confirm-pop-leave-to { transform: none; }
}
</style>
