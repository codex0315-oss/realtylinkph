import type { ApiResponse, User } from '~/types'

export type SupportStatus = 'ai' | 'open' | 'in_progress' | 'resolved'
export type SupportSender = 'user' | 'ai' | 'admin' | 'system'

export interface SupportMessage {
  id: number
  sender_type: SupportSender
  sender_name: string | null
  /** An AI reply sent while the team was away (after they'd joined the chat). */
  stand_in?: boolean
  body: string | null
  attachments: string[]
  read_at: string | null
  created_at: string
}

export interface SupportTicket {
  id: number
  status: SupportStatus
  category: string | null
  /** Admin only. */
  summary?: string | null
  suggested_reply?: string | null
  rating: 'up' | 'down' | null
  escalated_at: string | null
  resolved_at: string | null
  resolved_by: string | null
  last_message_at: string | null
  created_at: string
  /** Any admin online right now (sent with the messages). */
  team_online?: boolean
  user?: User
  unread?: number
  last_message?: { sender_type: SupportSender; body: string } | null
  messages?: SupportMessage[]
}

export const SUPPORT_CATEGORIES: Record<string, string> = {
  bug: 'Something broken', account: 'Account', verification: 'Verification', listing: 'Listing',
  viewing: 'Viewing', payment: 'Payment', other: 'Other',
}

/** Help & support for buyers and agents: RealtyLink AI first, then the team. */
export const useSupport = () => {
  const api = useApi()

  async function fetchSupport(): Promise<{ active: SupportTicket | null; past: SupportTicket[]; unread: number } | null> {
    try {
      return (await api.get<ApiResponse<{ active: SupportTicket | null; past: SupportTicket[]; unread: number }>>('/support')).data
    } catch {
      return null
    }
  }

  async function fetchTicket(id: number): Promise<SupportTicket | null> {
    try {
      return (await api.get<ApiResponse<SupportTicket>>(`/support/tickets/${id}`)).data
    } catch {
      return null
    }
  }

  /** Text and/or up to 3 screenshots. Returns the active ticket with the AI's answer added. */
  async function send(body: string, images: File[] = []): Promise<SupportTicket | null> {
    const fd = new FormData()
    if (body.trim()) fd.append('body', body.trim())
    images.forEach(f => fd.append('images[]', f))
    try {
      return (await api.postForm<ApiResponse<SupportTicket>>('/support/messages', fd)).data
    } catch {
      return null
    }
  }

  async function escalate(): Promise<SupportTicket | null> {
    try {
      return (await api.post<ApiResponse<SupportTicket>>('/support/escalate')).data
    } catch {
      return null
    }
  }

  async function rate(id: number, rating: 'up' | 'down'): Promise<boolean> {
    try {
      await api.post(`/support/tickets/${id}/rate`, { rating })
      return true
    } catch {
      return false
    }
  }

  async function fetchUnread(): Promise<number> {
    try {
      return (await api.get<ApiResponse<{ unread: number }>>('/support/unread')).data.unread
    } catch {
      return 0
    }
  }

  return { fetchSupport, fetchTicket, send, escalate, rate, fetchUnread }
}
