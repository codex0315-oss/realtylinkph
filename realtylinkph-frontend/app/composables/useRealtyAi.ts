import type { ApiResponse, Property } from '~/types'

export interface AiChatMessage {
  role: 'user' | 'assistant'
  content: string
  properties?: Property[]
}

interface AiChatResponse {
  reply: string
  criteria: Record<string, unknown>
  properties: Property[]
}

export const useRealtyAi = () => {
  const api     = useApi()
  const loading = ref(false)

  /** Send the conversation; returns the AI reply + recommended real listings. */
  async function chat(history: AiChatMessage[]): Promise<AiChatResponse | null> {
    loading.value = true
    try {
      const messages = history.slice(-20).map(m => ({ role: m.role, content: m.content }))
      const res = await api.post<ApiResponse<AiChatResponse>>('/ai/chat', { messages })
      return res.data
    } catch {
      return null
    } finally {
      loading.value = false
    }
  }

  return { chat, loading }
}
