<script setup lang="ts">
/**
 * Global toast stack, driven by useToast(). Bottom-centre on desktop; on a
 * phone it spans the width above the bottom edge. Each toast is a dark card
 * with a coloured icon, an optional second line and button, a close cross,
 * and a thin line counting down until it goes.
 */
const { toasts, dismiss } = useToast()

const SKIN = {
  success: { tile: 'bg-emerald-500/20 text-emerald-300', bar: 'bg-emerald-400', path: 'M4.5 12.75l6 6 9-13.5' },
  error:   { tile: 'bg-red-500/20 text-red-300', bar: 'bg-red-400', path: 'M12 9v3.75m0 3.75h.008v.008H12v-.008zM21 12a9 9 0 11-18 0 9 9 0 0118 0z' },
  info:    { tile: 'bg-brand-gold/20 text-brand-gold', bar: 'bg-brand-gold', path: 'M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z' },
} as const

function run(id: number, action: () => void) {
  action()
  dismiss(id)
}
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-x-3 bottom-[max(1rem,env(safe-area-inset-bottom))] sm:inset-x-auto sm:left-1/2 sm:-translate-x-1/2 sm:bottom-6
             z-[120] flex flex-col items-stretch sm:items-center gap-2 pointer-events-none"
      aria-live="polite"
    >
      <TransitionGroup name="toast">
        <div
          v-for="t in toasts"
          :key="t.id"
          :role="t.type === 'error' ? 'alert' : 'status'"
          class="toast pointer-events-auto relative overflow-hidden w-full sm:w-auto sm:min-w-[20rem] sm:max-w-[28rem]
                 flex items-start gap-3 rounded-2xl pl-3 pr-2 py-3
                 bg-[#0B1A35] text-white ring-1 ring-white/10 shadow-[0_18px_44px_-12px_rgba(6,14,31,0.6)]"
        >
          <span class="h-8 w-8 flex-none rounded-xl flex items-center justify-center" :class="SKIN[t.type].tile">
            <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="SKIN[t.type].path" /></svg>
          </span>

          <div class="min-w-0 flex-1 py-0.5">
            <p class="text-sm font-semibold leading-snug">{{ t.message }}</p>
            <p v-if="t.description" class="mt-0.5 text-xs leading-relaxed text-white/60">{{ t.description }}</p>
          </div>

          <button
            v-if="t.action"
            type="button"
            class="flex-none self-center rounded-full px-3 py-1.5 text-xs font-bold text-brand-navy bg-brand-gold hover:bg-brand-gold-light transition"
            @click="run(t.id, t.action.run)"
          >
            {{ t.action.label }}
          </button>
          <button
            type="button"
            class="flex-none h-7 w-7 rounded-full flex items-center justify-center text-white/45 hover:text-white hover:bg-white/10 transition"
            aria-label="Dismiss"
            @click="dismiss(t.id)"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>

          <!-- Time left -->
          <span class="toast-bar absolute left-0 bottom-0 h-0.5 w-full origin-left" :class="SKIN[t.type].bar" :style="{ animationDuration: `${t.ms}ms` }" aria-hidden="true" />
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.toast-enter-active { transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.25s ease; }
.toast-leave-active { transition: transform 0.2s ease, opacity 0.2s ease; position: absolute; }
.toast-enter-from { opacity: 0; transform: translateY(16px) scale(0.97); }
.toast-leave-to { opacity: 0; transform: translateY(8px) scale(0.97); }
.toast-move { transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); }

.toast-bar { animation: toast-countdown linear forwards; opacity: 0.7; }
@keyframes toast-countdown { from { transform: scaleX(1); } to { transform: scaleX(0); } }

@media (prefers-reduced-motion: reduce) {
  .toast-enter-active, .toast-leave-active, .toast-move { transition: none; }
  .toast-bar { animation: none; }
}
</style>
