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

  /**
   * "Remember me", chosen on the sign-in form before the token arrives. Kept in
   * sessionStorage so it survives the round trip to Google and back.
   *
   * Not ticked (the default) → the token lives in sessionStorage and the session
   * ends when the browser closes — safe on a shared or internet-café computer.
   * Ticked → localStorage, so it survives closing the browser until the token
   * expires (30 days, Sanctum) or they sign out.
   */
  function setRemember(on: boolean): void {
    if (!import.meta.client) return
    try {
      if (on) sessionStorage.setItem('rl-remember', '1')
      else sessionStorage.removeItem('rl-remember')
    } catch { /* storage blocked: falls back to a browser-session sign-in */ }
  }

  function setToken(t: string): void {
    token.value = t
    if (!import.meta.client) return
    try {
      const remember = sessionStorage.getItem('rl-remember') === '1'
      sessionStorage.removeItem('rl-remember')
      sessionStorage.removeItem('auth_token')
      localStorage.removeItem('auth_token')
      ;(remember ? localStorage : sessionStorage).setItem('auth_token', t)
    } catch { /* storage blocked: signed in for this page only */ }
  }

  function clearAuth(): void {
    user.value  = null
    token.value = null
    if (import.meta.client) {
      try {
        sessionStorage.removeItem('auth_token')
        localStorage.removeItem('auth_token')
      } catch { /* nothing stored */ }
    }
  }

  function initFromStorage(): void {
    if (import.meta.client) {
      try {
        const stored = sessionStorage.getItem('auth_token') ?? localStorage.getItem('auth_token')
        if (stored) token.value = stored
      } catch { /* storage blocked: start signed out */ }
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
    setRemember,
    clearAuth,
    initFromStorage,
  }
})
