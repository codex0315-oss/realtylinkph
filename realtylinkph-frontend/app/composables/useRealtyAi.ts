import type { ApiResponse, Property } from '~/types'
import type { ThemeMode } from '~/composables/useTheme'

/** The nearest place of a kind the buyer asked to be near (straight-line). */
export interface AiNearbyFact {
  kind: string
  name: string
  distance_m: number
}

/**
 * Something the AI does in the app instead of searching. The chat shows a card
 * for it; the card's own state (undone, done) is kept here so it survives a
 * reload. Passwords are never stored: the password card holds only `done`.
 */
export type AiAction =
  | { type: 'theme'; mode: ThemeMode; previous?: ThemeMode; undone?: boolean }
  | { type: 'gcal_connect' }
  | { type: 'gcal_disconnect'; done?: boolean }
  | { type: 'password'; can_change: boolean; done?: boolean }
  | { type: 'open_page'; page: string; label: string; href: string }
  | { type: 'capabilities' }

export interface AiChatMessage {
  role: 'user' | 'assistant'
  content: string
  properties?: Property[]
  /** Keyed by listing id. */
  nearby?: Record<string, AiNearbyFact[]>
  /** Matches for this search not shown yet ("Show more"). */
  remaining?: number
  action?: AiAction
}

interface AiChatResponse {
  reply: string
  criteria: Record<string, unknown>
  mode: 'search' | 'saved' | 'action'
  action?: AiAction
  properties: Property[]
  nearby: Record<string, AiNearbyFact[]>
  total: number
  remaining: number
}

export const useRealtyAi = () => {
  const api     = useApi()
  const loading = ref(false)

  /**
   * Send the conversation; returns the AI reply + recommended real listings.
   * `shownIds` lets "show me more" skip what the chat already showed.
   */
  async function chat(history: AiChatMessage[], shownIds: number[] = []): Promise<AiChatResponse | null> {
    loading.value = true
    try {
      const messages = history.slice(-20).map(m => ({ role: m.role, content: m.content }))
      const res = await api.post<ApiResponse<AiChatResponse>>('/ai/chat', { messages, shown_ids: shownIds.slice(-100) })
      return res.data
    } catch {
      return null
    } finally {
      loading.value = false
    }
  }

  return { chat, loading }
}
