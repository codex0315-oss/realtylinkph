import type { AiAction, AiChatMessage } from '~/composables/useRealtyAi'

/**
 * The RealtyLink AI conversation, shared app-wide.
 *
 * One thread, whichever surface shows it: the dashboard's docked AI panel, the
 * "Ask RealtyLink AI" card on the overview (which sends a prompt and opens the
 * panel), and the full-screen chat on public pages. Kept for the browser
 * session, so a reload or a trip to another page doesn't wipe it.
 */
const STORE_KEY = 'rl-ai-thread'

/*
 * A password typed into the chat would be stored in the browser and sent to
 * the AI, so it's hidden before either happens. "is/to/:" + any word, or a
 * bare word with a digit or symbol ("password Abc12345"). The server hides
 * the same patterns again before anything reaches Gemini.
 */
const SECRET = /\b(password|passcode|passwd|pwd|pw)\b(\s*(?:\bis\b|\bto\b|\binto\b|\bas\b|:|=)\s*)(?!\[hidden\])("[^"]+"|'[^']+'|\S{4,})|\b(password|passcode|passwd|pwd|pw)\b(\s+)(?!\[hidden\])(?=\S*[\d!@#$%^&*_])(\S{6,})/giu

export function hidePasswords(text: string): string {
  return text.replace(SECRET, (_m, w1, s1, _v1, w2, s2) => `${w1 ?? w2}${s1 ?? s2}[hidden]`)
}

export function useAiThread() {
  const messages = useState<AiChatMessage[]>('ai-thread', () => [])
  const loading  = useState('ai-thread-loading', () => false)
  const restored = useState('ai-thread-restored', () => false)
  const { chat } = useRealtyAi()
  const { theme, setTheme } = useTheme()
  const toast = useToast()

  if (import.meta.client && !restored.value) {
    restored.value = true
    try {
      const saved = JSON.parse(sessionStorage.getItem(STORE_KEY) ?? 'null')
      if (Array.isArray(saved) && !messages.value.length) messages.value = saved
    } catch { /* storage blocked: start empty */ }
  }

  function persist() {
    try { sessionStorage.setItem(STORE_KEY, JSON.stringify(messages.value.slice(-30))) } catch { /* ignore */ }
  }

  async function send(text: string) {
    let content = text.trim()
    if (!content || loading.value) return

    const safe = hidePasswords(content)
    if (safe !== content) {
      content = safe
      toast.error('Please don’t type passwords in the chat. I hid it. Use the secure form instead.')
    }

    const shownIds = [...new Set(messages.value.flatMap(m => m.properties?.map(p => p.id) ?? []))]
    messages.value = [...messages.value, { role: 'user', content }]
    persist()

    loading.value = true
    const res = await chat(messages.value, shownIds)
    loading.value = false

    let action = res?.action
    // "Switch to dark mode" happens at once; the card offers Undo.
    if (action?.type === 'theme') {
      action = { ...action, previous: theme.value }
      await setTheme(action.mode)
    }

    messages.value = [...messages.value, {
      role: 'assistant',
      content: res?.reply ?? "Sorry, I'm having trouble right now. Please try again.",
      properties: res?.properties ?? [],
      nearby: res?.nearby ?? {},
      remaining: res?.remaining ?? 0,
      action,
    }]
    persist()
  }

  /** A card's Save button changed a listing: keep every copy in the thread in step. */
  function markSaved(id: number, saved: boolean) {
    messages.value = messages.value.map(m => m.properties?.some(p => p.id === id)
      ? { ...m, properties: m.properties.map(p => p.id === id ? { ...p, is_favorited: saved } : p) }
      : m)
    persist()
  }

  /** An action card finished (undone, disconnected, password changed). */
  function updateAction(index: number, patch: Partial<AiAction>) {
    const m = messages.value[index]
    if (!m?.action) return
    messages.value = messages.value.map((x, i) => i === index ? { ...x, action: { ...x.action, ...patch } as AiAction } : x)
    persist()
  }

  function reset() {
    messages.value = []
    try { sessionStorage.removeItem(STORE_KEY) } catch { /* ignore */ }
  }

  return { messages, loading, send, reset, markSaved, updateAction }
}
