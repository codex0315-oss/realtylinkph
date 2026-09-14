<script setup lang="ts">
interface Props {
  value: number
  max?: number
  size?: 'sm' | 'md' | 'lg'
  readonly?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  max:      5,
  size:     'md',
  readonly: true,
})

const emit = defineEmits<{ 'update:value': [n: number] }>()

const hovered = ref(0)

const sizeClasses: Record<string, string> = { sm: 'h-3 w-3', md: 'h-5 w-5', lg: 'h-6 w-6' }

function starClass(i: number) {
  const filled = props.readonly ? i <= props.value : i <= (hovered.value || props.value)
  return filled ? 'text-brand-gold' : 'text-brand-silver'
}
</script>

<template>
  <div class="flex items-center gap-0.5">
    <button
      v-for="i in max"
      :key="i"
      :disabled="readonly"
      type="button"
      :class="[sizeClasses[size!], starClass(i), readonly ? 'cursor-default' : 'cursor-pointer hover:scale-110 transition-transform']"
      @mouseenter="!readonly && (hovered = i)"
      @mouseleave="!readonly && (hovered = 0)"
      @click="!readonly && emit('update:value', i)"
    >
      <svg viewBox="0 0 20 20" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
      </svg>
    </button>
  </div>
</template>
