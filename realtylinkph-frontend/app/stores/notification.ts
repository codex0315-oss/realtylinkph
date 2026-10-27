import { defineStore } from 'pinia'
import type { Notification } from '~/types'

export const useNotificationStore = defineStore('notification', () => {
  const notifications = ref<Notification[]>([])
  const unreadCount   = computed(() => notifications.value.filter(n => !n.read_at).length)

  /*
   * The bell is for everything *about* the account — viewings, inquiries,
   * listings, reviews, verification. Chat messages have their own icon (see
   * MessagesMenu), counted from the conversations themselves, so they're kept
   * out of the bell instead of being counted twice.
   */
  const MESSAGE_TYPES = ['new_message']
  const bellNotifications = computed(() => notifications.value.filter(n => !MESSAGE_TYPES.includes(n.type)))
  const bellUnread        = computed(() => bellNotifications.value.filter(n => !n.read_at).length)

  function setNotifications(list: Notification[]): void {
    notifications.value = list
  }

  /* The live push now carries the server's id and time; older pushes didn't. */
  function addNotification(payload: { id?: string | null; type: string; payload: Record<string, unknown>; created_at?: string | null }): void {
    if (payload.id && notifications.value.some(n => n.id === payload.id)) return
    const notification: Notification = {
      id:         payload.id ?? crypto.randomUUID(),
      type:       payload.type,
      payload:    payload.payload,
      read_at:    null,
      created_at: payload.created_at ?? new Date().toISOString(),
    }
    notifications.value.unshift(notification)
  }

  function markRead(id: string): void {
    const n = notifications.value.find(n => n.id === id)
    if (n) n.read_at = new Date().toISOString()
  }

  function markUnread(id: string): void {
    const n = notifications.value.find(n => n.id === id)
    if (n) n.read_at = null
  }

  function remove(id: string): Notification | undefined {
    const i = notifications.value.findIndex(n => n.id === id)
    if (i < 0) return undefined
    return notifications.value.splice(i, 1)[0]
  }

  /** Drop read bell items; returns what was removed so a failed request can restore it. */
  function removeRead(): Notification[] {
    const gone = notifications.value.filter(n => n.read_at && !MESSAGE_TYPES.includes(n.type))
    notifications.value = notifications.value.filter(n => !gone.includes(n))
    return gone
  }

  function markAllRead(): void {
    const now = new Date().toISOString()
    notifications.value.forEach(n => {
      if (!n.read_at) n.read_at = now
    })
  }

  function clear(): void {
    notifications.value = []
  }

  return { notifications, unreadCount, bellNotifications, bellUnread, setNotifications, addNotification, markRead, markUnread, remove, removeRead, markAllRead, clear }
})
