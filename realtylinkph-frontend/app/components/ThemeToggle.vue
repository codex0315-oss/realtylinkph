<script setup lang="ts">
/**
 * Light / dark switch for the public chrome.
 *
 * Guests can use this freely — `setTheme` writes to localStorage immediately and
 * only calls the API when someone is signed in, so the preference applies either
 * way. Three-way "system" selection lives on the profile page; here we simply
 * flip to the opposite of whatever is currently showing.
 */
/**
 * `onDark` forces the light-on-dark treatment for surfaces that are dark
 * regardless of theme. Leave it unset and colours follow the `dark:` variant,
 * which is correct from the first paint.
 */
interface Props {
  onDark?: boolean
}
withDefaults(defineProps<Props>(), { onDark: false })

const { theme, resolve, setTheme } = useTheme()

const isDark = ref(false)

function sync() {
  isDark.value = resolve(theme.value) === 'dark'
}

onMounted(() => {
  sync()
  const mq = window.matchMedia?.('(prefers-color-scheme: dark)')
  mq?.addEventListener('change', sync)
})

watch(theme, sync)

async function toggle() {
  await setTheme(isDark.value ? 'light' : 'dark')
  sync()
}
</script>

<template>
  <button
    type="button"
    class="relative h-10 w-10 rounded-xl flex items-center justify-center overflow-hidden
           transition-colors duration-200 group"
    :class="onDark
      ? 'text-white/70 hover:text-brand-gold hover:bg-white/10'
      : 'text-brand-navy/60 hover:text-brand-gold hover:bg-brand-navy/5 dark:text-white/70 dark:hover:text-brand-gold dark:hover:bg-white/10'"
    :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
    :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
    @click="toggle"
  >
    <!-- Sun -->
    <svg
      class="absolute h-5 w-5 transition-all duration-500 ease-out"
      :class="isDark ? 'opacity-0 rotate-90 scale-50' : 'opacity-100 rotate-0 scale-100'"
      fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"
    >
      <circle cx="12" cy="12" r="4" />
      <path stroke-linecap="round" d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32l1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41m11.32-11.32l1.41-1.41" />
    </svg>

    <!-- Moon -->
    <svg
      class="absolute h-5 w-5 transition-all duration-500 ease-out"
      :class="isDark ? 'opacity-100 rotate-0 scale-100' : 'opacity-0 -rotate-90 scale-50'"
      fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"
    >
      <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
    </svg>
  </button>
</template>
