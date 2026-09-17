import type { ApiResponse, AgentProfile, Agent } from '~/types'
// Explicit, like useAgentAi/useGoogleAuth: the dev server once served this file
// with the `useApi` auto-import missing (a stale transform), which took down
// every admin page with "useApi is not defined".
import { useApi } from '~/composables/useApi'

export const useAgent = () => {
  const api = useApi()

  const profile = ref<Agent | null>(null)
  const loading = ref(false)
  const error   = ref<string | null>(null)

  async function fetchAgent(userId: number): Promise<void> {
    loading.value = true
    error.value   = null
    try {
      const res = await api.get<ApiResponse<Agent>>(`/agents/${userId}`)
      profile.value = res.data
    } catch (e) {
      error.value = extractError(e)
    } finally {
      loading.value = false
    }
  }

  async function submitVerification(
    form: FormData,
    onProgress: (percent: number) => void = () => {},
  ): Promise<AgentProfile | null> {
    loading.value = true
    error.value   = null
    try {
      const res = await api.postFormWithProgress<ApiResponse<AgentProfile>>('/agent/verify', form, onProgress)
      return res.data
    } catch (e) {
      error.value = extractError(e)
      return null
    } finally {
      loading.value = false
    }
  }

  async function fetchTopAgents(limit = 4): Promise<Agent[]> {
    try {
      const res = await api.get<ApiResponse<Agent[]>>('/agents', { per_page: limit, status: 'approved' })
      return res.data
    } catch {
      return []
    }
  }

  async function fetchPendingAgents(): Promise<AgentProfile[]> {
    try {
      const res = await api.get<ApiResponse<AgentProfile[]>>('/admin/pending-agents')
      return res.data
    } catch {
      return []
    }
  }

  // `profileId` is the AgentProfile id (route binds AgentProfile, not the user).
  async function approveAgent(profileId: number): Promise<boolean> {
    try {
      await api.post(`/admin/agent-applications/${profileId}/review`, { action: 'approve' })
      return true
    } catch {
      return false
    }
  }

  async function rejectAgent(profileId: number, reason: string): Promise<boolean> {
    try {
      await api.post(`/admin/agent-applications/${profileId}/review`, { action: 'reject', reason })
      return true
    } catch {
      return false
    }
  }

  return {
    profile,
    loading,
    error,
    fetchAgent,
    fetchTopAgents,
    submitVerification,
    fetchPendingAgents,
    approveAgent,
    rejectAgent,
  }
}

function extractError(e: unknown): string {
  if (typeof e === 'object' && e !== null) {
    const data = (e as { data?: { message?: string; errors?: Record<string, string[]> } }).data
    // Prefer the field message: the API wraps validation failures in a generic
    // "Validation failed.", which told an applicant nothing about which of
    // their four uploads was the problem.
    if (data?.errors) {
      const first = Object.values(data.errors).flat().filter(Boolean)[0]
      if (first) return first
    }
    if (data?.message) return data.message
  }
  return 'Something went wrong.'
}
