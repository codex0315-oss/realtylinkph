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

  /**
   * Optimistic: the bubble is on screen before the request leaves. On success
   * the server row replaces it; on failure it stays with a "Not sent" state
   * and can be retried (which reuses the same bubble).
   */
  async function sendMessage(conversationId: number, body: string, retryClientId?: string): Promise<Message | null> {
    const me = useAuthStore().user?.id ?? 0
    let clientId = retryClientId
    if (clientId) {
      const m = conversationStore.activeMessages.find(m => m.client_id === clientId)
      if (m) m.local_status = 'sending'
    } else {
      clientId = conversationStore.addPending(conversationId, me, body).client_id!
    }

    sending.value = true
    try {
      const res = await api.post<ApiResponse<Message>>(
        `/conversations/${conversationId}/messages`,
        { body }
      )
      conversationStore.resolvePending(clientId, res.data)
      return res.data
    } catch (e) {
      error.value = extractError(e)
      conversationStore.failPending(clientId)
      return null
    } finally {
      sending.value = false
    }
  }

  function discardFailed(clientId: string): void {
    conversationStore.removePending(clientId)
  }

  /** Tell the server (and, via it, the sender) that we received these messages. */
  async function markDelivered(conversationId: number): Promise<void> {
    try {
      await api.post(`/conversations/${conversationId}/delivered`)
    } catch {
      // Non-critical
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
    discardFailed,
    markDelivered,
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
