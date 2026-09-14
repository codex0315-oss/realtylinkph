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
    decrementUnread,
    removeConversation,
  }
})
