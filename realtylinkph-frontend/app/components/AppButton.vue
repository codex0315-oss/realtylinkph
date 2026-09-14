<script setup lang="ts">
interface Props {
  variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'danger'
  size?: 'sm' | 'md' | 'lg'
  loading?: boolean
  disabled?: boolean
  type?: 'button' | 'submit' | 'reset'
  fullWidth?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  variant:   'primary',
  size:      'md',
  loading:   false,
  disabled:  false,
  type:      'button',
  fullWidth: false,
})

const classes = computed(() => {
  const base = 'inline-flex items-center justify-center font-semibold transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed rounded-button'

  const variants = {
    primary:   'bg-brand-gold text-brand-navy hover:bg-brand-gold-light focus:ring-brand-gold',
    secondary: 'bg-brand-navy text-white hover:bg-brand-navy-mid focus:ring-brand-navy',
    outline:   'border-2 border-brand-navy text-brand-navy hover:bg-brand-navy hover:text-white focus:ring-brand-navy',
    ghost:     'text-brand-navy hover:bg-brand-navy/10 focus:ring-brand-navy',
    danger:    'bg-red-600 text-white hover:bg-red-700 focus:ring-red-600',
  }

  const sizes = {
    sm: 'px-3 py-1.5 text-sm gap-1.5',
    md: 'px-5 py-2.5 text-sm gap-2',
    lg: 'px-7 py-3 text-base gap-2',
  }

  return [base, variants[props.variant], sizes[props.size], props.fullWidth ? 'w-full' : '']
})
</script>

<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :class="classes"
  >
    <svg
      v-if="loading"
      class="animate-spin h-4 w-4"
      xmlns="http://www.w3.org/2000/svg"
      fill="none"
      viewBox="0 0 24 24"
    >
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
    </svg>
    <slot />
  </button>
</template>
