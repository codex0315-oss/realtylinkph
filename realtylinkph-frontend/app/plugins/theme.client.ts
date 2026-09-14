import type { ThemeMode } from '~/composables/useTheme'

/**
 * Applies the saved theme (light / dark / system) on app load and keeps it in
 * sync with the OS preference when set to "system".
 */
export default defineNuxtPlugin(() => {
  const { apply, theme } = useTheme()

  // useState's initializer runs on the server, so re-read the client's stored
  // preference here before applying.
  const stored = localStorage.getItem('rl-theme') as ThemeMode | null
  if (stored) theme.value = stored

  apply()

  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (theme.value === 'system') apply()
    })
  }
})
