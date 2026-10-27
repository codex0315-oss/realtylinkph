import type { ApiResponse, Notification } from '~/types'

export const useNotification = () => {
  const notifStore = useNotificationStore()
  const api        = useApi()

  async function fetchNotifications(): Promise<void> {
    try {
      const res = await api.get<ApiResponse<Notification[]>>('/notifications')
      notifStore.setNotifications(res.data)
    } catch {
      // ignore — keep whatever is already in the store
    }
  }

  async function markRead(id: string): Promise<void> {
    notifStore.markRead(id) // optimistic
    try {
      await api.post(`/notifications/${id}/read`)
    } catch {
      // ignore
    }
  }

  async function markUnread(id: string): Promise<void> {
    notifStore.markUnread(id) // optimistic
    try {
      await api.post(`/notifications/${id}/unread`)
    } catch {
      notifStore.markRead(id)
    }
  }

  /** Optimistic delete; put it back if the server refuses. */
  async function remove(id: string): Promise<boolean> {
    const removed = notifStore.remove(id)
    try {
      await api.del(`/notifications/${id}`)
      return true
    } catch {
      if (removed) notifStore.setNotifications([removed, ...notifStore.notifications].sort((a, b) => b.created_at.localeCompare(a.created_at)))
      return false
    }
  }

  async function clearRead(): Promise<number> {
    const removed = notifStore.removeRead()
    try {
      await api.del('/notifications/clear-read')
      return removed.length
    } catch {
      notifStore.setNotifications([...removed, ...notifStore.notifications].sort((a, b) => b.created_at.localeCompare(a.created_at)))
      return 0
    }
  }

  async function markAllRead(): Promise<void> {
    notifStore.markAllRead() // optimistic
    try {
      await api.post('/notifications/read-all')
    } catch {
      // ignore
    }
  }

  return {
    notifications: notifStore.notifications,
    unreadCount:   notifStore.unreadCount,
    fetchNotifications,
    markRead,
    markUnread,
    remove,
    clearRead,
    markAllRead,
    clear:         notifStore.clear,
  }
}
