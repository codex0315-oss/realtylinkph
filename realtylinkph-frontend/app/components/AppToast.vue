<script setup lang="ts">
// Global toast stack — bottom-center. Driven by useToast().
const { toasts, dismiss } = useToast()

const skin: Record<string, string> = {
  success: 'bg-emerald-600',
  info:    'bg-brand-navy',
  error:   'bg-red-600',
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[100] flex flex-col items-center gap-2 pointer-events-none">
      <TransitionGroup name="toast">
        <button
          v-for="t in toasts"
          :key="t.id"
          type="button"
          class="pointer-events-auto inline-flex items-center gap-2 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-[0_8px_30px_rgba(0,0,0,0.25)]"
          :class="skin[t.type]"
          @click="dismiss(t.id)"
        >
          <svg v-if="t.type === 'success'" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
          <svg v-else-if="t.type === 'error'" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" /></svg>
          <svg v-else class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          {{ t.message }}
        </button>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.toast-enter-active, .toast-leave-active { transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1); }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateY(12px); }
</style>
