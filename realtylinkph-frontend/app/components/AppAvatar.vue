<script setup lang="ts">
interface Props {
  src?: string | null
  name?: string
  size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl'
}

const props = withDefaults(defineProps<Props>(), {
  size: 'md',
})

const sizeClasses: Record<string, string> = {
  xs: 'h-6 w-6 text-xs',
  sm: 'h-8 w-8 text-sm',
  md: 'h-10 w-10 text-sm',
  lg: 'h-12 w-12 text-base',
  xl: 'h-16 w-16 text-lg',
}

const initials = computed(() => {
  if (!props.name) return '?'
  return props.name
    .split(' ')
    .slice(0, 2)
    .map(w => w[0])
    .join('')
    .toUpperCase()
})

/*
 * A src that 404s (a deleted upload, a stale Google photo URL) rendered as the
 * browser's broken-image glyph. Fall back to the initials instead, and retry
 * when the src changes so a new upload isn't stuck on the fallback.
 */
const failed = ref(false)
watch(() => props.src, () => { failed.value = false })
const showImage = computed(() => !!props.src && !failed.value)

const bgColor = computed(() => {
  if (!props.name) return 'bg-brand-silver'
  const colors = [
    'bg-brand-navy', 'bg-brand-gold', 'bg-blue-600', 'bg-emerald-600',
    'bg-purple-600', 'bg-rose-600', 'bg-amber-600',
  ]
  const idx = props.name.charCodeAt(0) % colors.length
  return colors[idx]
})
</script>

<template>
  <div
    class="rounded-full overflow-hidden flex items-center justify-center font-semibold text-white flex-shrink-0"
    :class="[sizeClasses[size!], showImage ? '' : bgColor]"
  >
    <img v-if="showImage" :src="src!" :alt="name" class="h-full w-full object-cover" @error="failed = true" />
    <span v-else>{{ initials }}</span>
  </div>
</template>
