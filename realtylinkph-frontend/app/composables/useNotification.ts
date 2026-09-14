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
    markAllRead,
    clear:         notifStore.clear,
  }
}
