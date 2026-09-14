<script setup lang="ts">
interface Props {
  variant?: 'default' | 'success' | 'warning' | 'error' | 'gold' | 'navy'
  size?: 'sm' | 'md'
}

withDefaults(defineProps<Props>(), {
  variant: 'default',
  size:    'md',
})

const variantClasses: Record<string, string> = {
  default: 'bg-brand-silver-light text-brand-text-secondary',
  success: 'bg-green-100 text-green-800',
  warning: 'bg-yellow-100 text-yellow-800',
  error:   'bg-red-100 text-red-800',
  gold:    'bg-brand-gold/20 text-brand-gold',
  navy:    'bg-brand-navy text-white',
}

const sizeClasses: Record<string, string> = {
  sm: 'px-2 py-0.5 text-xs',
  md: 'px-2.5 py-1 text-xs',
}
</script>

<template>
  <span
    class="inline-flex items-center gap-1 font-semibold rounded-full"
    :class="[variantClasses[variant!], sizeClasses[size!]]"
  >
    <slot />
  </span>
</template>
