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
      // Rate limit: the bubble already says "Not sent"; say why, and how long to wait.
      const status = (e as { status?: number; statusCode?: number })?.status ?? (e as { statusCode?: number })?.statusCode
      if (status === 429) useToast().error(error.value)
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
      // Keep the list and the top-bar Messages badge in step without a refetch.
      conversationStore.clearUnread(conversationId)
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

  /** "Unsend for everyone": both people then see "… unsent a message". */
  async function unsendMessage(messageId: number, conversationId: number): Promise<boolean> {
    try {
      await api.post(`/messages/${messageId}/unsend`)
      conversationStore.applyUnsent(messageId, conversationId)
      return true
    } catch (e) {
      error.value = extractError(e)
      return false
    }
  }

  /** Edit your own message (no time limit, like Messenger). */
  async function editMessage(messageId: number, conversationId: number, body: string): Promise<boolean> {
    try {
      const res = await api.patch<ApiResponse<Message>>(`/messages/${messageId}`, { body })
      conversationStore.applyUpdate({ ...res.data, conversation_id: conversationId })
      return true
    } catch (e) {
      error.value = extractError(e)
      return false
    }
  }

  /** Pin or unpin any message in the chat; both people see it. */
  async function pinMessage(messageId: number, conversationId: number, pin: boolean): Promise<boolean> {
    try {
      const res = pin
        ? await api.post<ApiResponse<Message>>(`/messages/${messageId}/pin`)
        : await api.del<ApiResponse<Message>>(`/messages/${messageId}/pin`)
      conversationStore.applyUpdate({ ...res.data, conversation_id: conversationId })
      return true
    } catch (e) {
      error.value = extractError(e)
      return false
    }
  }

  /** The open chat's pinned messages, for the "Pinned messages" bar. */
  async function fetchPinned(conversationId: number): Promise<void> {
    try {
      const res = await api.get<ApiResponse<Message[]>>(`/conversations/${conversationId}/pinned`)
      if (conversationStore.activeId === conversationId) conversationStore.setPinned(res.data)
    } catch {
      /* the bar just stays empty */
    }
  }

  /** "Remove for you": hidden from this user only; the other person still sees it. */
  async function removeMessageForMe(messageId: number, conversationId: number): Promise<boolean> {
    try {
      await api.post(`/messages/${messageId}/hide`)
      conversationStore.hideMessage(messageId, conversationId)
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
    unsendMessage,
    editMessage,
    pinMessage,
    fetchPinned,
    removeMessageForMe,
  }
}

function extractError(e: unknown): string {
  if (typeof e === 'object' && e !== null) {
    const data = (e as { data?: { message?: string } }).data
    if (data?.message) return data.message
  }
  return 'Something went wrong.'
}
