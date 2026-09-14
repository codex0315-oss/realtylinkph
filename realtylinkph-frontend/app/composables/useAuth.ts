import type { ApiResponse, LoginRequest, RegisterRequest } from '~/types'

export const useAuth = () => {
  const authStore = useAuthStore()
  const router    = useRouter()
  const api       = useApi()

  const loading = ref(false)
  const error   = ref<string | null>(null)

  // Role-aware landing after auth: admins → admin area, agents → listings, buyers → appointments.
  function landingFor(): string {
    if (authStore.isAdmin) return '/admin/users'
    if (authStore.isAgent) return '/dashboard/listings'
    return '/dashboard/appointments'
  }

  async function login(credentials: LoginRequest): Promise<void> {
    loading.value = true
    error.value   = null
    try {
      const res = await api.post<ApiResponse<{ token: string; user: import('~/types').User }>>(
        '/login', credentials
      )
      authStore.setToken(res.data.token)
      authStore.setUser(res.data.user)
      await router.push(landingFor())
    } catch (e: unknown) {
      error.value = extractApiError(e)
    } finally {
      loading.value = false
    }
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

  return { login, register, logout, fetchMe, forgotPassword, resetPassword, loading, error }
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
