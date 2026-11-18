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
    if (id !== activeId.value) pinned.value = []
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

  function addPending(conversationId: number, senderId: number, body: string, attachments: Message['attachments'] = []): Message {
    const msg: Message = {
      attachments,
      upload_progress: attachments?.length ? 0 : undefined,
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

  /** Pinned messages of the open chat (for the "Pinned messages" bar), newest pin first. */
  const pinned = ref<Message[]>([])
  function setPinned(list: Message[]): void { pinned.value = list }

  /** A message was edited, pinned or unpinned (by us, or the other side live). */
  function applyUpdate(u: Partial<Message> & { id: number; conversation_id: number }): void {
    const patch = { body: u.body ?? undefined, edited: u.edited, edited_at: u.edited_at, pinned_at: u.pinned_at ?? null, pinned_by_id: u.pinned_by_id ?? null }
    for (const m of activeMessages.value) {
      if (m.id === u.id) Object.assign(m, Object.fromEntries(Object.entries(patch).filter(([, v]) => v !== undefined)))
    }
    const conv = conversations.value.find(c => c.id === u.conversation_id)
    if (conv?.latest_message?.id === u.id && u.body) conv.latest_message = { ...conv.latest_message, body: u.body, edited: u.edited }
    if (u.conversation_id === activeId.value) {
      const rest = pinned.value.filter(m => m.id !== u.id)
      if (u.pinned_at) {
        const full = activeMessages.value.find(m => m.id === u.id) ?? (u as Message)
        rest.unshift({ ...full, ...u } as Message)
      } else {
        // Edited but still pinned: keep its place with the new text.
        const was = pinned.value.find(m => m.id === u.id)
        if (was && u.pinned_at === undefined) rest.unshift({ ...was, body: u.body ?? was.body })
      }
      pinned.value = rest.sort((a, b) => +new Date(b.pinned_at ?? 0) - +new Date(a.pinned_at ?? 0))
    }
  }

  /** A message was unsent for everyone (by us, or the other side live). */
  function applyUnsent(id: number, conversationId: number): void {
    for (const m of activeMessages.value) {
      if (m.id === id) { m.unsent = true; m.body = ''; m.pinned_at = null; m.attachments = []; m.attachment_summary = null }
    }
    pinned.value = pinned.value.filter(m => m.id !== id)
    const conv = conversations.value.find(c => c.id === conversationId)
    if (conv?.latest_message?.id === id) conv.latest_message = { ...conv.latest_message, unsent: true, body: '' }
  }

  /** "Remove for you": drop it from this user's view; the list preview falls back to the previous message. */
  function hideMessage(id: number, conversationId: number): void {
    activeMessages.value = activeMessages.value.filter(m => m.id !== id)
    const conv = conversations.value.find(c => c.id === conversationId)
    if (conv?.latest_message?.id === id && conversationId === activeId.value) {
      conv.latest_message = activeMessages.value[activeMessages.value.length - 1] ?? undefined
    }
  }

  /** Unread messages across every conversation — the Messages icon's badge. */
  const totalUnread = computed(() => conversations.value.reduce((n, c) => n + (c.unread_count ?? 0), 0))

  /** The thread was opened and marked read on the server — mirror it here. */
  function clearUnread(conversationId: number): void {
    const conv = conversations.value.find(c => c.id === conversationId)
    if (conv) conv.unread_count = 0
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
    totalUnread,
    clearUnread,
    setConversations,
    setActive,
    setMessages,
    addMessage,
    addPending,
    resolvePending,
    failPending,
    removePending,
    applyReceipt,
    applyUnsent,
    applyUpdate,
    pinned,
    setPinned,
    hideMessage,
    decrementUnread,
    removeConversation,
  }
})
