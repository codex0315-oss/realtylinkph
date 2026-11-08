import type { ApiResponse, LoginRequest, RegisterRequest } from '~/types'

/** An admin who has entered their password and now owes the emailed code. */
export interface TwoFactorChallenge { challenge: string; email: string; expires_in: number }

export const useAuth = () => {
  const authStore = useAuthStore()
  const router    = useRouter()
  const api       = useApi()

  const loading = ref(false)
  const error   = ref<string | null>(null)
  // Shared with the sign-in modal and the Google callback page.
  const twoFactor = useState<TwoFactorChallenge | null>('admin-two-factor', () => null)

  // Role-aware landing after auth: admins → admin area, agents → their dashboard, buyers → appointments.
  function landingFor(): string {
    if (authStore.isAdmin) return '/admin'
    if (authStore.isAgent) return '/dashboard'
    return '/dashboard/appointments'
  }

  async function login(credentials: LoginRequest): Promise<void> {
    loading.value = true
    error.value   = null
    try {
      const res = await api.post<ApiResponse<{ token?: string; user?: import('~/types').User; two_factor?: TwoFactorChallenge }>>(
        '/login', { ...credentials, device_token: readDeviceKey() }
      )
      // Admins: the password was right, now they owe the emailed code.
      if (res.data.two_factor) {
        twoFactor.value = res.data.two_factor
        return
      }
      authStore.setToken(res.data.token!)
      authStore.setUser(res.data.user!)
      await router.push(landingFor())
    } catch (e: unknown) {
      error.value = extractApiError(e)
    } finally {
      loading.value = false
    }
  }

  /** Second step of an admin sign-in: the 6-digit code from the email. */
  async function verifyTwoFactor(code: string, remember = false): Promise<boolean> {
    if (!twoFactor.value) return false
    loading.value = true
    error.value   = null
    try {
      const res = await api.post<ApiResponse<{ token: string; user: import('~/types').User; device_token?: string | null }>>(
        '/login/two-factor', { challenge: twoFactor.value.challenge, code, remember }
      )
      if (res.data.device_token) saveDeviceKey(res.data.device_token)
      twoFactor.value = null
      authStore.setToken(res.data.token)
      authStore.setUser(res.data.user)
      await router.push(landingFor())
      return true
    } catch (e: unknown) {
      error.value = extractApiError(e)
      // An expired or used-up challenge can't be retried: send them back to the password.
      const code = (e as { data?: { errors?: { code?: string } } })?.data?.errors?.code
      if (code === 'expired' || code === 'locked') twoFactor.value = null
      return false
    } finally {
      loading.value = false
    }
  }

  /** Email a fresh code. Returns an error message, or null when it was sent. */
  async function resendTwoFactor(): Promise<string | null> {
    if (!twoFactor.value) return 'Sign in again to get a code.'
    try {
      await api.post('/login/two-factor/resend', { challenge: twoFactor.value.challenge })
      return null
    } catch (e: unknown) {
      return extractApiError(e)
    }
  }

  function cancelTwoFactor() {
    twoFactor.value = null
    error.value = null
  }

  async function register(payload: RegisterRequest): Promise<void> {
    loading.value = true
    error.value   = null
    try {
      const res = await api.post<ApiResponse<{ token: string; user: import('~/types').User }>>(
        '/register', payload
      )
      authStore.setToken(res.data.token)
      authStore.setUser(res.data.user)
      await router.push('/dashboard/appointments')
    } catch (e: unknown) {
      error.value = extractApiError(e)
    } finally {
      loading.value = false
    }
  }

  async function logout(): Promise<void> {
    try {
      await api.post('/logout')
    } finally {
      authStore.clearAuth()
      await router.push('/')
    }
  }

  async function fetchMe(): Promise<void> {
    if (!authStore.token) return
    try {
      const res = await api.get<ApiResponse<import('~/types').User>>('/me')
      authStore.setUser(res.data)
    } catch (e: unknown) {
      // Only sign out when the server actually REJECTS the token (401/403).
      // Network blips, timeouts, or 5xx (common on flaky Wi-Fi) must NOT log the
      // user out — otherwise a transient failure on load drops a valid session.
      const status = httpStatus(e)
      if (status === 401 || status === 403) {
        authStore.clearAuth()
      }
    }
  }

  /**
   * Ask for a reset link. Resolves true when the email was accepted.
   * The API deliberately reports throttling (429) separately so the user knows
   * to wait rather than assuming the address was wrong.
   */
  async function forgotPassword(email: string): Promise<{ ok: boolean; message: string }> {
    loading.value = true
    error.value   = null
    try {
      const res = await api.post<ApiResponse<null>>('/forgot-password', { email })
      return { ok: true, message: res.message || 'Check your inbox for the reset link.' }
    } catch (e: unknown) {
      const message = extractApiError(e)
      error.value = message
      return { ok: false, message }
    } finally {
      loading.value = false
    }
  }

  /**
   * Complete the reset using the token from the emailed link.
   * On success the API also revokes every existing session for that account.
   */
  async function resetPassword(payload: {
    token: string
    email: string
    password: string
    password_confirmation: string
  }): Promise<{ ok: boolean; message: string }> {
    loading.value = true
    error.value   = null
    try {
      const res = await api.post<ApiResponse<null>>('/reset-password', payload)
      return { ok: true, message: res.message || 'Password reset successfully.' }
    } catch (e: unknown) {
      const message = extractApiError(e)
      error.value = message
      return { ok: false, message }
    } finally {
      loading.value = false
    }
  }

  return { login, verifyTwoFactor, resendTwoFactor, cancelTwoFactor, twoFactor, register, logout, fetchMe, forgotPassword, resetPassword, loading, error }
}

/** Pull the HTTP status off an ofetch FetchError (covers its various shapes). */
function httpStatus(e: unknown): number | undefined {
  if (typeof e === 'object' && e !== null) {
    const err = e as { status?: number; statusCode?: number; response?: { status?: number } }
    return err.status ?? err.statusCode ?? err.response?.status
  }
  return undefined
}

function extractApiError(e: unknown): string {
  if (typeof e === 'object' && e !== null) {
    const data = (e as { data?: { message?: string; errors?: Record<string, string[]> } }).data
    // Prefer the specific field validation message over the generic "Validation failed."
    if (data?.errors) {
      const first = Object.values(data.errors).flat().filter(Boolean)[0]
      if (first) return first
    }
    if (data?.message) return data.message
  }
  return 'Something went wrong. Please try again.'
}
