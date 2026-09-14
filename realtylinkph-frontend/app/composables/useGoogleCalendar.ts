import type { ApiResponse } from '~/types'

export const useGoogleCalendar = () => {
  const api = useApi()

  /** Kick off the OAuth flow — redirects the browser to Google. Returns false if it couldn't start. */
  async function connect(): Promise<boolean> {
    try {
      const res = await api.get<ApiResponse<{ url: string }>>('/google/redirect')
      if (res.data?.url) {
        window.location.href = res.data.url
        return true
      }
      return false
    } catch {
      return false
    }
  }

  /** Exchange the authorization code returned by Google for tokens. */
  async function exchangeCode(code: string): Promise<boolean> {
    try {
      await api.post('/google/callback', { code })
      return true
    } catch {
      return false
    }
  }

  async function disconnect(): Promise<boolean> {
    try {
      await api.del('/google/disconnect')
      return true
    } catch {
      return false
    }
  }

  return { connect, exchangeCode, disconnect }
}
