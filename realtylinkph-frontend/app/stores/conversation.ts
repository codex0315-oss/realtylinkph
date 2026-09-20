import { defineStore } from 'pinia'
import type { Conversation, Message } from '~/types'

export const useConversationStore = defineStore('conversation', () => {
  const conversations    = ref<Conversation[]>([])
  const activeId         = ref<number | null>(null)
  const activeMessages   = ref<Message[]>([])

  const active = computed(
    () => conversations.value.find(c => c.id === activeId.value) ?? null
  )

  function setConversations(list: Conversation[]): void {
    conversations.value = list
  }

  function setActive(id: number): void {
    activeId.value = id
    activeMessages.value = []
  }

  function setMessages(msgs: Message[]): void {
    activeMessages.value = msgs
  }

  function addMessage(msg: Message): void {
    if (msg.conversation_id === activeId.value) {
      // Safety net: the same message can arrive from the send response AND the
      // socket (or a reconnect replay). Never render one id twice.
      if (!activeMessages.value.some(m => m.id === msg.id)) {
        activeMessages.value.push(msg)
      }
    }

    const conv = conversations.value.find(c => c.id === msg.conversation_id)
    if (conv) {
      conv.latest_message = msg
      conv.last_message_at = msg.created_at
    }
  }

  /* ── Optimistic sending ──
   * The bubble goes on screen the instant the user presses Enter, with a
   * negative temporary id, and is swapped for the server's row when the API
   * answers. Before this the thread waited a full round trip (≈1 s from the
   * Philippines to Oregon) before showing anything, which read as "lag". */
  let tempSeq = 0

  function addPending(conversationId: number, senderId: number, body: string): Message {
    const msg: Message = {
      id: -(++tempSeq),
      client_id: `c${Date.now()}-${tempSeq}`,
      conversation_id: conversationId,
      sender_id: senderId,
      body,
      is_read: false,
      delivered_at: null,
      read_at: null,
      created_at: new Date().toISOString(),
      local_status: 'sending',
    }
    if (conversationId === activeId.value) activeMessages.value.push(msg)
    return msg
  }

  /** The API confirmed: replace the optimistic bubble with the real message. */
  function resolvePending(clientId: string, real: Message): void {
    const i = activeMessages.value.findIndex(m => m.client_id === clientId)
    if (i === -1) { addMessage(real); return }
    if (activeMessages.value.some(m => m.id === real.id)) {
      activeMessages.value.splice(i, 1)          // socket echo beat us to it
    } else {
      activeMessages.value.splice(i, 1, real)
    }
    const conv = conversations.value.find(c => c.id === real.conversation_id)
    if (conv) { conv.latest_message = real; conv.last_message_at = real.created_at }
  }

  function failPending(clientId: string): void {
    const m = activeMessages.value.find(m => m.client_id === clientId)
    if (m) m.local_status = 'failed'
  }

  function removePending(clientId: string): void {
    activeMessages.value = activeMessages.value.filter(m => m.client_id !== clientId)
  }

  /** Apply a delivered/read receipt from the other party to our messages. */
  function applyReceipt(kind: 'delivered' | 'read', ids: number[], at: string): void {
    const set = new Set(ids)
    for (const m of activeMessages.value) {
      if (!set.has(m.id)) continue
      if (!m.delivered_at) m.delivered_at = at
      if (kind === 'read') { m.read_at = at; m.is_read = true }
    }
  }

  function decrementUnread(conversationId: number): void {
    const conv = conversations.value.find(c => c.id === conversationId)
    if (conv && conv.unread_count) {
      conv.unread_count = Math.max(0, conv.unread_count - 1)
    }
  }

  /** Drop a conversation from the list; clears the thread if it was open. */
  function removeConversation(conversationId: number): void {
    conversations.value = conversations.value.filter(c => c.id !== conversationId)
    if (activeId.value === conversationId) {
      activeId.value = null
      activeMessages.value = []
    }
  }

  return {
    conversations,
    activeId,
    activeMessages,
    active,
    setConversations,
    setActive,
    setMessages,
    addMessage,
    addPending,
    resolvePending,
    failPending,
    removePending,
    applyReceipt,
    decrementUnread,
    removeConversation,
  }
})
