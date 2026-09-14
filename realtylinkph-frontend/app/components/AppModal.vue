<script setup lang="ts">
interface Props {
  open: boolean
  title?: string
  size?: 'sm' | 'md' | 'lg' | 'xl'
}

const props = withDefaults(defineProps<Props>(), {
  size: 'md',
})

const emit = defineEmits<{ close: [] }>()

const sizeClasses: Record<string, string> = {
  sm: 'max-w-sm',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
  xl: 'max-w-4xl',
}

function onBackdrop(e: MouseEvent) {
  if (e.target === e.currentTarget) emit('close')
}

onMounted(() => {
  const handler = (e: KeyboardEvent) => { if (e.key === 'Escape') emit('close') }
  document.addEventListener('keydown', handler)
  onUnmounted(() => document.removeEventListener('keydown', handler))
})
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
        @click="onBackdrop"
      >
        <div
          class="bg-white rounded-card shadow-card w-full overflow-hidden"
          :class="sizeClasses[size]"
        >
          <div v-if="title" class="flex items-center justify-between px-6 py-4 border-b border-brand-silver">
            <h3 class="text-lg font-semibold text-brand-text-primary">{{ title }}</h3>
            <button class="text-brand-text-secondary hover:text-brand-text-primary transition-colors" @click="emit('close')">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <slot />
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-enter-active, .modal-leave-active { transition: opacity 0.2s ease; }
.modal-enter-from, .modal-leave-to { opacity: 0; }
.modal-enter-active .bg-white, .modal-leave-active .bg-white { transition: transform 0.2s ease; }
.modal-enter-from .bg-white { transform: scale(0.95); }
</style>
