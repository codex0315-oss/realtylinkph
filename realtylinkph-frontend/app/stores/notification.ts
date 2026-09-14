import { defineStore } from 'pinia'
import type { Notification } from '~/types'

export const useNotificationStore = defineStore('notification', () => {
  const notifications = ref<Notification[]>([])
  const unreadCount   = computed(() => notifications.value.filter(n => !n.read_at).length)

  function setNotifications(list: Notification[]): void {
    notifications.value = list
  }

  function addNotification(payload: { type: string; payload: Record<string, unknown> }): void {
    const notification: Notification = {
      id:         crypto.randomUUID(),
      type:       payload.type,
      payload:    payload.payload,
      read_at:    null,
      created_at: new Date().toISOString(),
    }
    notifications.value.unshift(notification)
  }

  function markRead(id: string): void {
    const n = notifications.value.find(n => n.id === id)
    if (n) n.read_at = new Date().toISOString()
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

  return { notifications, unreadCount, setNotifications, addNotification, markRead, markAllRead, clear }
})
