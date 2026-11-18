/**
 * Shared state for "Write it with AI" on the new and edit listing forms:
 * loading, a 30-second "try again" countdown after RealtyLink AI is busy, and
 * a note when only the details (not the photos) could be used.
 */
export function useAiDescription() {
  const aiLoading = ref(false)
  const aiError   = ref('')
  const aiNote    = ref('')
  const aiStyle   = ref<'plain' | 'emoji'>('plain')
  const waitLeft  = ref(0)
  let timer: ReturnType<typeof setInterval> | undefined

  function startWait(seconds = 30) {
    clearInterval(timer)
    waitLeft.value = seconds
    timer = setInterval(() => {
      waitLeft.value = Math.max(0, waitLeft.value - 1)
      if (waitLeft.value === 0) clearInterval(timer)
    }, 1000)
  }
  onUnmounted(() => clearInterval(timer))

  /** Runs the request; returns the description to put in the form, or null. */
  async function run(request: () => Promise<{ description: string; used_photos: boolean } | null>, hadPhotos: boolean): Promise<string | null> {
    if (aiLoading.value || waitLeft.value > 0) return null
    aiLoading.value = true
    aiError.value = ''
    aiNote.value = ''
    const res = await request()
    aiLoading.value = false
    if (res?.description) {
      if (hadPhotos && !res.used_photos) aiNote.value = 'Written from the details only. RealtyLink AI couldn’t read the photos right now; you can try again later for a photo-based description.'
      return res.description
    }
    aiError.value = 'RealtyLink AI is busy right now. Please try again in a moment.'
    startWait(30)
    return null
  }

  const buttonLabel = (idle: string) => (aiLoading.value ? 'Writing…' : waitLeft.value > 0 ? `Try again in ${waitLeft.value}s` : idle)

  return { aiLoading, aiError, aiNote, aiStyle, waitLeft, run, buttonLabel }
}
