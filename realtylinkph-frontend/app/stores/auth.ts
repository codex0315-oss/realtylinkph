import { defineStore } from 'pinia'
import type { User, UserRole } from '~/types'

export const useAuthStore = defineStore('auth', () => {
  const user  = ref<User | null>(null)
  const token = ref<string | null>(null)

  const isAuthenticated = computed(() => !!token.value)
  const isAgent         = computed(() => user.value?.role_type === 'agent')
  const isAdmin         = computed(() => ['admin', 'super_admin'].includes(user.value?.role_type ?? ''))
  const isBuyer         = computed(() => ['buyer', 'ghost_buyer'].includes(user.value?.role_type ?? ''))
  const isVerifiedAgent = computed(
    () => user.value?.role_type === 'agent' && user.value?.agent_profile?.status === 'approved'
  )

  function setUser(u: User): void {
    user.value = u
  }

  function setToken(t: string): void {
    token.value = t
    if (import.meta.client) {
      // sessionStorage (not localStorage) → the session lives only until the
      // browser/tab is fully closed, so a fresh browser requires logging in again.
      sessionStorage.setItem('auth_token', t)
    }
  }

  function clearAuth(): void {
    user.value  = null
    token.value = null
    if (import.meta.client) {
      sessionStorage.removeItem('auth_token')
    }
  }

  function initFromStorage(): void {
    if (import.meta.client) {
      // Migrate away from any old persistent token so stale logins don't linger.
      localStorage.removeItem('auth_token')

      const stored = sessionStorage.getItem('auth_token')
      if (stored) token.value = stored
    }
  }

  return {
    user,
    token,
    isAuthenticated,
    isAgent,
    isAdmin,
    isBuyer,
    isVerifiedAgent,
    setUser,
    setToken,
    clearAuth,
    initFromStorage,
  }
})
