import type { ApiResponse, PropertyType, OfferType } from '~/types'
import type { AiAction } from '~/composables/useRealtyAi'
import { useApi } from '~/composables/useApi'
import { hidePasswords } from '~/composables/useAiThread'

export interface AgentListingProposal {
  title: string
  type: PropertyType
  offer_type: OfferType
  price: number
  bedrooms: number
  bathrooms: number
  floor_area: number | null
  address: string
  description: string
}

/* ── Cards the agent assistant answers with (built from their real data) ── */

export interface AgentAiListing {
  id: number
  title: string
  status: 'draft' | 'published' | 'sold'
  type: PropertyType
  offer_type: OfferType
  price: number
  address: string
  views: number
  inquiries: number | null
  photos: number
  has_360: boolean
  thumb: string | null
}
export interface AgentAiViewing { id: number; status: 'pending' | 'confirmed'; at: string; buyer: string; listing: string | null; notes: string | null; confirmed?: boolean }
export interface AgentAiInquiry { id: number; name: string; email: string; phone: string | null; message: string; is_read: boolean; listing: string | null; created_at: string }
export interface AgentAiReview { id: number; rating: number; text: string | null; buyer: string; replied: boolean; created_at: string }
export interface AgentAiIssue { code: string; label: string; tip: string; fix: { label: string; href?: string; prompt?: string } }
export type AgentTaskState = 'ready' | 'working' | 'done' | 'cancelled' | 'failed'

export type AgentAiCard =
  | { type: 'listings'; items: AgentAiListing[]; total: number; link: string; pick?: { task: string; label: string } }
  | { type: 'viewings'; items: AgentAiViewing[]; total: number; link: string }
  | { type: 'inquiries'; items: AgentAiInquiry[]; total: number; link: string }
  | { type: 'reviews'; items: AgentAiReview[]; total: number; average: number; link: string }
  | {
      type: 'price_check'; listing: AgentAiListing | null; kind: string; area_name: string | null; size: number | null; unit: string
      count: number; median: number; min: number; max: number; median_sqm: number | null; low: number; high: number
      verdict: 'above' | 'below' | 'in_line' | null; diff_pct: number | null
    }
  | { type: 'listing_check'; items: Array<{ listing: AgentAiListing; issues: AgentAiIssue[] }> }
  | {
      type: 'task'; task: 'confirm_viewing' | 'block_day' | 'block_hours' | 'publish' | 'unpublish' | 'mark_sold'
      title: string; lines: Array<{ label: string; value: string }>; cta: string; args: Record<string, string | number | null>
      warning: string | null; tone: 'primary' | 'danger'; state: AgentTaskState; error?: string
    }
  | {
      type: 'draft'; kind: 'chat' | 'inquiry' | 'review'; text: string; target_id: number; to: string; signed: string
      about?: string | null; quote?: string | null; email?: string; subject?: string; rating?: number; used?: boolean
    }
  | { type: 'action_link'; label: string; href: string }

export interface AgentAiMessage {
  role: 'user' | 'assistant'
  content: string
  image?: string                          // preview data URL (user turns with a photo; not kept on reload)
  proposal?: AgentListingProposal | null  // assistant's listing proposal, if any
  created?: boolean                       // that proposal was turned into a draft
  action?: AiAction | null                // account action: theme, calendar, password, page
  card?: AgentAiCard | null               // their data, a task, a check or a draft
}

interface AgentAiResponse {
  intent: string
  reply: string
  listing_proposal: AgentListingProposal | null
  action: AiAction | null
  card: AgentAiCard | null
}

const STORE_KEY = 'rl-agent-ai-thread'

/**
 * RealtyLink AI for agents: the conversation, shared by the docked panel and
 * the dashboard's AI card, and kept for the browser session like the buyer's.
 */
export const useAgentAi = () => {
  const api      = useApi()
  const messages = useState<AgentAiMessage[]>('agent-ai-thread', () => [])
  const loading  = useState('agent-ai-loading', () => false)
  const restored = useState('agent-ai-restored', () => false)
  const toast    = useToast()
  const { theme, setTheme } = useTheme()

  if (import.meta.client && !restored.value) {
    restored.value = true
    try {
      const saved = JSON.parse(sessionStorage.getItem(STORE_KEY) ?? 'null')
      if (Array.isArray(saved) && !messages.value.length) messages.value = saved
    } catch { /* storage blocked: start empty */ }
  }

  function persist() {
    // Photos are too big for session storage; the text of the turn stays.
    const slim = messages.value.slice(-30).map(({ image: _image, ...m }) => m)
    try { sessionStorage.setItem(STORE_KEY, JSON.stringify(slim)) } catch { /* ignore */ }
  }

  async function send(text: string, imageFile?: File | null, imagePreview?: string) {
    let content = text.trim() || (imageFile ? 'Write a description for this property photo.' : '')
    if (!content || loading.value) return

    const safe = hidePasswords(content)
    if (safe !== content) {
      content = safe
      toast.error('Please don’t type passwords in the chat. I hid it. Use the secure form instead.')
    }

    messages.value = [...messages.value, { role: 'user', content, image: imagePreview || undefined }]
    persist()

    loading.value = true
    let res: AgentAiResponse | null = null
    try {
      const fd = new FormData()
      fd.append('messages', JSON.stringify(messages.value.slice(-30).map(m => ({ role: m.role, content: m.content }))))
      if (imageFile) fd.append('image', imageFile)
      res = (await api.postForm<ApiResponse<AgentAiResponse>>('/ai/agent-chat', fd)).data
    } catch { /* shown as the apology below */ }
    loading.value = false

    let action = res?.action ?? null
    // "Turn on dark mode" happens at once; the card offers Undo.
    if (action?.type === 'theme') {
      action = { ...action, previous: theme.value }
      await setTheme(action.mode)
    }

    messages.value = [...messages.value, {
      role: 'assistant',
      content: res?.reply ?? "Sorry, I'm having trouble right now. Please try again.",
      proposal: res?.listing_proposal ?? null,
      action,
      card: res?.card ?? null,
    }]
    persist()
  }

  /** A card changed (task approved, viewing confirmed, draft used…). */
  function patch(index: number, change: Partial<AgentAiMessage>) {
    messages.value = messages.value.map((m, i) => (i === index ? { ...m, ...change } : m))
    persist()
  }
  function patchCard(index: number, change: Record<string, unknown>) {
    const m = messages.value[index]
    if (m?.card) patch(index, { card: { ...m.card, ...change } as AgentAiCard })
  }
  function patchAction(index: number, change: Partial<AiAction>) {
    const m = messages.value[index]
    if (m?.action) patch(index, { action: { ...m.action, ...change } as AiAction })
  }

  function reset() {
    messages.value = []
    try { sessionStorage.removeItem(STORE_KEY) } catch { /* ignore */ }
  }

  return { messages, loading, send, patch, patchCard, patchAction, reset }
}
