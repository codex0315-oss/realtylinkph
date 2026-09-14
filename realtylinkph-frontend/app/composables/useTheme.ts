import type { ApiResponse, User } from '~/types'

export type ThemeMode = 'light' | 'dark' | 'system'

export const useTheme = () => {
  const authStore = useAuthStore()
  const api       = useApi()

  const theme = useState<ThemeMode>('app-theme', () => {
    if (import.meta.client) {
      const stored = localStorage.getItem('rl-theme') as ThemeMode | null
      if (stored) return stored
    }
    return (authStore.user?.theme as ThemeMode) ?? 'light'
  })

  function resolve(mode: ThemeMode): 'light' | 'dark' {
    if (mode === 'system') {
      return import.meta.client && window.matchMedia('(prefers-color-scheme: dark)').matches
        ? 'dark'
        : 'light'
    }
    return mode === 'dark' ? 'dark' : 'light'
  }

  function apply(): void {
    if (!import.meta.client) return
    document.documentElement.classList.toggle('dark', resolve(theme.value) === 'dark')
  }

  async function setTheme(mode: ThemeMode, persist = true): Promise<void> {
    theme.value = mode
    if (import.meta.client) localStorage.setItem('rl-theme', mode)
    apply()

    if (persist && authStore.isAuthenticated) {
      try {
        const res = await api.put<ApiResponse<User>>('/me', { theme: mode })
        authStore.setUser(res.data)
      } catch {
        // ignore — local preference still applies
      }
    }
  }

  return { theme, apply, resolve, setTheme }
}
