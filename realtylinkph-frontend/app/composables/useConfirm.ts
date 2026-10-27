export type ConfirmTone = 'danger' | 'warning' | 'primary'

/** Icons the dialog can show instead of the tone's default. */
export type ConfirmIcon = 'trash' | 'publish' | 'hide' | 'sold' | 'relist' | 'leave' | 'photo' | 'calendar' | 'chat' | 'check' | 'warning' | 'bell' | 'user'

export interface ConfirmOptions {
  title: string
  /** Main explanatory line. Supports plain text only. */
  message?: string
  /** Bullet points spelling out exactly what will happen. */
  consequences?: string[]
  confirmLabel?: string
  cancelLabel?: string
  tone?: ConfirmTone
  icon?: ConfirmIcon
  /** Extra line rendered in a tinted strip under the message (e.g. the listing title). */
  subject?: string
  /** A card showing what the action is about: a listing's photo, title and price. */
  preview?: { image?: string | null; title: string; meta?: string }
  /**
   * The work itself. When given, Confirm runs it while the dialog stays open
   * with a spinner, and closes only on success. Returning `false` or an error
   * message keeps it open and shows that message, so the person can retry.
   */
  action?: () => Promise<boolean | string | void> | boolean | string | void
  /** Button text while `action` runs, e.g. "Deleting…". */
  busyLabel?: string
  /** For what can't be undone: the word to type before Confirm unlocks. */
  typeToConfirm?: string
}

interface ConfirmState extends ConfirmOptions {
  open: boolean
  busy?: boolean
  error?: string | null
}

const EMPTY: ConfirmState = { open: false, title: '' }

/**
 * Promise-based replacement for `window.confirm`.
 *
 *   const confirm = useConfirm()
 *   if (!await confirm({ title: 'Delete this listing?', tone: 'danger' })) return
 *
 * or, letting the dialog run the work and show progress and errors itself:
 *
 *   await confirm({ title: 'Delete this listing?', tone: 'danger', action: () => deleteProperty(id) })
 *
 * A single dialog instance lives in `ConfirmDialog.vue`, mounted once in app.vue.
 * The resolver and action are module-scoped rather than stored in `useState`
 * because functions are not serialisable — and the dialog can only ever be
 * opened by a client-side interaction, so there is no SSR request-leak concern.
 */
let resolver: ((value: boolean) => void) | null = null
let pendingAction: ConfirmOptions['action'] | null = null

export const useConfirmState = () => useState<ConfirmState>('confirm-dialog', () => ({ ...EMPTY }))

export const useConfirm = () => {
  const state = useConfirmState()

  function ask(options: ConfirmOptions): Promise<boolean> {
    if (!import.meta.client) return Promise.resolve(false)

    // A second call while one is open resolves the first as cancelled.
    resolver?.(false)

    const { action, ...rest } = options
    pendingAction = action ?? null
    state.value = {
      open: true,
      tone: 'primary',
      confirmLabel: 'Confirm',
      cancelLabel: 'Cancel',
      busy: false,
      error: null,
      ...rest,
    }

    return new Promise<boolean>((resolve) => { resolver = resolve })
  }

  function settle(result: boolean): void {
    state.value = { ...state.value, open: false, busy: false }
    pendingAction = null
    resolver?.(result)
    resolver = null
  }

  async function accept(): Promise<void> {
    if (state.value.busy) return
    if (!pendingAction) { settle(true); return }

    state.value = { ...state.value, busy: true, error: null }
    let result: boolean | string | void
    try {
      result = await pendingAction()
    } catch {
      result = false
    }
    if (result === false || typeof result === 'string') {
      state.value = {
        ...state.value,
        busy: false,
        error: typeof result === 'string' && result ? result : 'That didn’t go through. Please try again.',
      }
      return
    }
    settle(true)
  }

  return Object.assign(ask, {
    accept,
    cancel: () => { if (!state.value.busy) settle(false) },
    state,
  })
}
