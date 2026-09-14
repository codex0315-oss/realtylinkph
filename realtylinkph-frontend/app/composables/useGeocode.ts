/** Address → coordinates via Geoapify (Philippines-biased). */
export function useGeocode() {
  const config = useRuntimeConfig()

  async function geocode(address: string): Promise<{ lat: number; lng: number } | null> {
    const key = config.public.geoapifyKey as string
    if (!key || !address?.trim()) return null
    try {
      const res = await $fetch<{ features?: Array<{ geometry?: { coordinates?: [number, number] } }> }>(
        'https://api.geoapify.com/v1/geocode/search',
        { params: { text: address, apiKey: key, limit: 1, filter: 'countrycode:ph' } },
      )
      const coords = res?.features?.[0]?.geometry?.coordinates
      if (coords && coords.length === 2) {
        const [lng, lat] = coords // GeoJSON is [lng, lat]
        return { lat, lng }
      }
      return null
    } catch {
      return null
    }
  }

  return { geocode }
}
