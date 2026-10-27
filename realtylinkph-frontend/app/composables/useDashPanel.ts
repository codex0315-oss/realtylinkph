/**
 * Which docked side panel the dashboard shows: the RealtyLink AI assistant,
 * Notifications, Messages, or none. Shared state so anything (top-bar icons,
 * a card on the overview) can open one.
 */
export type DashPanel = '' | 'assistant' | 'notifications' | 'messages'

/**
 * Right offset for things pinned to the bottom-right corner (the AI chat
 * bubbles): while a panel is docked on desktop (≥1024px, 24rem wide) they
 * step left to clear it instead of covering its footer.
 */
export function useDockRightOffset(base = '1.5rem') {
  const { panel } = useDashPanel()
  const wide = ref(false)
  onMounted(() => {
    const mq = window.matchMedia('(min-width: 1024px)')
    const sync = () => { wide.value = mq.matches }
    sync()
    mq.addEventListener('change', sync)
    onUnmounted(() => mq.removeEventListener('change', sync))
  })
  return computed(() => (panel.value && wide.value ? `calc(24rem + ${base})` : base))
}

export function useDashPanel() {
  const panel = useState<DashPanel>('dash-panel', () => '')
  function toggle(p: Exclude<DashPanel, ''>) { panel.value = panel.value === p ? '' : p }
  function close() { panel.value = '' }
  function open(p: Exclude<DashPanel, ''>) { panel.value = p }

  /* A chat for the Messages panel to open straight into (e.g. "Message agent"
     on a listing). The panel consumes it and resets it to null. */
  const chatTarget = useState<number | null>('dash-chat-target', () => null)
  /* Text to put in that chat's composer (a reply the AI drafted), unsent. */
  const chatDraft = useState<string | null>('dash-chat-draft', () => null)
  function openChat(conversationId: number, draft?: string) {
    chatDraft.value = draft ?? null
    chatTarget.value = conversationId
    panel.value = 'messages'
  }

  /* Which list the Messages panel shows. Agents also get "inquiries" there
     (questions sent from a listing's contact form), in place of a page. */
  const messagesTab = useState<'all' | 'unread' | 'inquiries'>('dash-messages-tab', () => 'all')
  function openInquiries() {
    messagesTab.value = 'inquiries'
    panel.value = 'messages'
  }

  return { panel, toggle, close, open, chatTarget, chatDraft, openChat, messagesTab, openInquiries }
}
