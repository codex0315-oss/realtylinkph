import type { Agent } from './agent'

export type PropertyType = 'house' | 'condo' | 'lot' | 'commercial' | 'apartment'
export type PropertyStatus = 'draft' | 'published' | 'sold'
export type OfferType = 'sale' | 'rent'

export const OFFER_TYPES: Record<OfferType, string> = {
  sale: 'For Sale',
  rent: 'For Rent',
}

export interface PropertyPhoto {
  id: number
  property_id: number
  url: string
  /** Card-sized copy (≤640 px). The API falls back to `url` for older photos. */
  thumb_url: string
  is_360: boolean
  sort_order: number
}

export interface Property {
  id: number
  agent_id: number
  title: string
  description: string | null
  price: string
  type: PropertyType
  offer_type: OfferType
  bedrooms: number
  bathrooms: number
  floor_area: string | null
  lot_area: string | null
  address: string
  lat: string | null
  lng: string | null
  status: PropertyStatus
  /** Owner/admin only. False for a draft the wizard saved half-filled — needs title, price, address and a photo before Publish. */
  is_complete?: boolean
  sold_at?: string | null
  featured_score?: number
  is_featured?: boolean
  /** Set when an admin took the listing down; cleared when the agent re-publishes. Owner/admin only. */
  unpublish_reason?: string | null
  unpublished_at?: string | null
  price_flagged?: boolean
  views?: number
  is_favorited?: boolean
  created_at: string
  updated_at: string
  photos?: PropertyPhoto[]
  agent?: Agent
}

export const PROPERTY_TYPES: Record<PropertyType, string> = {
  house:      'House',
  condo:      'Condo',
  lot:        'Lot',
  commercial: 'Commercial',
  apartment:  'Apartment',
}

export interface PropertyFilters {
  type?: PropertyType
  offer_type?: OfferType
  min_price?: number
  max_price?: number
  bedrooms?: number
  search?: string
  lat?: number
  lng?: number
  radius?: number
  agent_id?: number
  page?: number
}

/**
 * Creates a DRAFT, so everything is optional — the wizard saves as the agent
 * goes. Completeness (title, price, address, a photo) is enforced at publish.
 */
export interface CreatePropertyRequest {
  title?: string
  description?: string | null
  price?: number
  type?: PropertyType
  offer_type?: OfferType
  bedrooms?: number
  bathrooms?: number
  floor_area?: number
  lot_area?: number
  address?: string
  lat?: number
  lng?: number
}

export type UpdatePropertyRequest = Partial<CreatePropertyRequest>
