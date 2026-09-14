import type { Message } from '~/types'

export const useEcho = () => {
  const { $echo }   = useNuxtApp()
  const authStore   = useAuthStore()
  const notifStore  = useNotificationStore()

  type NotificationEvent = { type: string; payload: Record<string, unknown> }

  function listenForNotifications(onReceive?: () => void): void {
    if (!authStore.user || !$echo) return

    $echo
      .private(`notifications.${authStore.user.id}`)
      .listen('.notification.sent', (data: NotificationEvent) => {
        notifStore.addNotification(data) // instant feedback
        onReceive?.()                    // e.g. refetch the persisted list
      })
  }

  /**
   * Extra listener for one notification type on the same channel — lets a page
   * react (e.g. Messages refreshing its thread list on `new_message`) without
   * owning the subscription. Returns a function that removes just this listener.
   */
  function onNotificationType(type: string, cb: (payload: Record<string, unknown>) => void): () => void {
    if (!authStore.user || !$echo) return () => {}

    const channel = $echo.private(`notifications.${authStore.user.id}`)
    const handler = (data: NotificationEvent) => { if (data.type === type) cb(data.payload) }
    channel.listen('.notification.sent', handler)
    return () => channel.stopListening('.notification.sent', handler)
  }

  function listenToConversation(conversationId: number, onMessage: (msg: Message) => void): void {
    if (!$echo) return

    $echo
      .private(`conversation.${conversationId}`)
      .listen('.message.sent', (e: Message | { message: Message }) => {
        // MessageSent::broadcastWith() sends the message's fields directly, not
        // wrapped in { message }. This handler assumed the wrapper, so the first
        // live event to actually arrive crashed addMessage() with undefined.
        const msg = 'message' in e ? e.message : e
        if (msg && typeof msg.conversation_id === 'number') onMessage(msg)
      })
  }

  /** Server-sent: RealtyLink AI is composing an auto-reply on this thread. */
  function listenForAiTyping(conversationId: number, onTyping: () => void): void {
    $echo?.private(`conversation.${conversationId}`).listen('.ai.typing', () => onTyping())
  }

  /** Broadcast a client "typing" whisper to others on the conversation channel. */
  function whisperTyping(conversationId: number): void {
    try {
      $echo?.private(`conversation.${conversationId}`).whisper('typing', {
        userId: authStore.user?.id,
      })
    } catch {
      // client events may be disabled — degrade silently
    }
  }

  /** Listen for "typing" whispers from the other participant. */
  function listenForTyping(conversationId: number, onTyping: (userId: number) => void): void {
    try {
      $echo?.private(`conversation.${conversationId}`).listenForWhisper('typing', (e: { userId: number }) => {
        onTyping(e.userId)
      })
    } catch {
      // ignore
    }
  }

  /** Tell the other participant we've just read the conversation ("Seen"). */
  function whisperSeen(conversationId: number): void {
    try {
      $echo?.private(`conversation.${conversationId}`).whisper('seen', {
        userId: authStore.user?.id,
      })
    } catch {
      // client events may be disabled — degrade silently
    }
  }

  /** Listen for "seen" whispers from the other participant. */
  function listenForSeen(conversationId: number, onSeen: (userId: number) => void): void {
    try {
      $echo?.private(`conversation.${conversationId}`).listenForWhisper('seen', (e: { userId: number }) => {
        onSeen(e.userId)
      })
    } catch {
      // ignore
    }
  }

  function stopListeningToConversation(conversationId: number): void {
    $echo?.leave(`conversation.${conversationId}`)
  }

  /** Pass the id explicitly on logout — by then `authStore.user` is already null. */
  function stopNotifications(userId?: number): void {
    const id = userId ?? authStore.user?.id
    if (!id) return
    $echo?.leave(`notifications.${id}`)
  }

  return {
    listenForNotifications,
    onNotificationType,
    listenToConversation,
    listenForAiTyping,
    whisperTyping,
    listenForTyping,
    whisperSeen,
    listenForSeen,
    stopListeningToConversation,
    stopNotifications,
  }
}
