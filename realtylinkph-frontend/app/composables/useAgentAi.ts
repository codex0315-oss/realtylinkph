import type { ApiResponse, PropertyType, OfferType } from '~/types'
import { useApi } from '~/composables/useApi'

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

export interface AgentAiMessage {
  role: 'user' | 'assistant'
  content: string
  image?: string                          // preview data URL (user turns with a photo)
  proposal?: AgentListingProposal | null  // assistant's listing proposal, if any
}

/** RealtyLink AI — agent companion (listing assistant, multimodal). */
export const useAgentAi = () => {
  const api = useApi()
  const loading = ref(false)

  async function chat(history: AgentAiMessage[], imageFile?: File | null) {
    loading.value = true
    try {
      const fd = new FormData()
      fd.append('messages', JSON.stringify(history.map(m => ({ role: m.role, content: m.content }))))
      if (imageFile) fd.append('image', imageFile)
      const res = await api.postForm<ApiResponse<{ reply: string; listing_proposal: AgentListingProposal | null }>>(
        '/ai/agent-chat', fd,
      )
      return res.data
    } catch {
      return null
    } finally {
      loading.value = false
    }
  }

  return { chat, loading }
}
