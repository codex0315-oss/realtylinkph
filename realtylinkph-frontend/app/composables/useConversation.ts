import type { ApiResponse, Conversation, Message, PaginatedResponse } from '~/types'

export const useConversation = () => {
  const api               = useApi()
  const conversationStore = useConversationStore()

  const loading = ref(false)
  const error   = ref<string | null>(null)
  const sending = ref(false)

  async function fetchConversations(): Promise<void> {
    loading.value = true
    try {
      const res = await api.get<PaginatedResponse<Conversation>>('/conversations')
      conversationStore.setConversations(res.data)
    } catch (e) {
      error.value = extractError(e)
    } finally {
      loading.value = false
    }
  }

  async function openConversation(propertyId: number): Promise<Conversation | null> {
    try {
      // Backend derives the agent from the property; no body needed.
      const res = await api.post<ApiResponse<Conversation>>(`/properties/${propertyId}/conversations`)
      return res.data
    } catch (e) {
      error.value = extractError(e)
      return null
    }
  }

  async function fetchMessages(conversationId: number, page = 1): Promise<void> {
    loading.value = true
    try {
      const res = await api.get<PaginatedResponse<Message>>(
        `/conversations/${conversationId}/messages`,
        { page }
      )
      // Messages are returned oldest-first; API may return newest-first so reverse if needed
      conversationStore.setMessages([...res.data].reverse())
    } catch (e) {
      error.value = extractError(e)
    } finally {
      loading.value = false
    }
  }

  async function sendMessage(conversationId: number, body: string): Promise<Message | null> {
    sending.value = true
    try {
      const res = await api.post<ApiResponse<Message>>(
        `/conversations/${conversationId}/messages`,
        { body }
      )
      conversationStore.addMessage(res.data)
      return res.data
    } catch (e) {
      error.value = extractError(e)
      return null
    } finally {
      sending.value = false
    }
  }

  async function markRead(conversationId: number): Promise<void> {
    try {
      await api.post(`/conversations/${conversationId}/read`)
    } catch {
      // Non-critical
    }
  }

  /**
   * Remove a conversation from *this* user's inbox. The other participant
   * keeps it, and a new message from either side brings it back.
   */
  async function deleteConversation(conversationId: number): Promise<boolean> {
    try {
      await api.del(`/conversations/${conversationId}`)
      conversationStore.removeConversation(conversationId)
      return true
    } catch (e) {
      error.value = extractError(e)
      return false
    }
  }

  return {
    loading,
    error,
    sending,
    fetchConversations,
    openConversation,
    fetchMessages,
    sendMessage,
    markRead,
    deleteConversation,
  }
}

function extractError(e: unknown): string {
  if (typeof e === 'object' && e !== null) {
    const data = (e as { data?: { message?: string } }).data
    if (data?.message) return data.message
  }
  return 'Something went wrong.'
}
