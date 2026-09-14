import type { ApiResponse, User } from '~/types'
import { useApi } from '~/composables/useApi'

/**
 * Which button the user pressed. Signing in requires an account that already
 * exists; only registering may create one. The value survives the trip to
 * Google in the OAuth `state` parameter.
 */
export type GoogleIntent = 'login' | 'register'

export interface GoogleSignInResult {
  ok: boolean
  /** True when the Google address has no RealtyLink PH account yet. */
  unregistered: boolean
  /** True when the server could not reach Google at all (network, not the user). */
  unreachable: boolean
  /** Server-supplied explanation, safe to show the user. */
  message: string
}

/** Google sign-in / sign-up (separate from Calendar connect). */
export const useGoogleAuth = () => {
  const api       = useApi()
  const authStore = useAuthStore()

  /** Kick off Google OAuth — redirects the browser to Google. Returns false if it couldn't start. */
  async function startGoogleSignIn(intent: GoogleIntent = 'login'): Promise<boolean> {
    try {
      const res = await api.get<ApiResponse<{ url: string }>>('/auth/google/redirect', { intent })
      if (res.data?.url) {
        window.location.href = res.data.url
        return true
      }
      return false
    } catch {
      return false
    }
  }

  /** Exchange the code Google returned, then log the user in. */
  async function completeGoogleSignIn(
    code: string,
    intent: GoogleIntent = 'login',
  ): Promise<GoogleSignInResult> {
    try {
      const res = await api.post<ApiResponse<{ token: string; user: User }>>(
        '/auth/google/callback', { code, intent }
      )
      authStore.setToken(res.data.token)
      authStore.setUser(res.data.user)
      return { ok: true, unregistered: false, unreachable: false, message: '' }
    } catch (e: unknown) {
      const data = (e as { data?: { message?: string; errors?: { code?: string } } })?.data
      return {
        ok: false,
        unregistered: data?.errors?.code === 'not_registered',
        unreachable:  data?.errors?.code === 'google_unreachable',
        message: data?.message ?? '',
      }
    }
  }

  return { startGoogleSignIn, completeGoogleSignIn }
}
