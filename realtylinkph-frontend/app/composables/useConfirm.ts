export type ConfirmTone = 'danger' | 'warning' | 'primary'

export interface ConfirmOptions {
  title: string
  /** Main explanatory line. Supports plain text only. */
  message?: string
  /** Bullet points spelling out exactly what will happen. */
  consequences?: string[]
  confirmLabel?: string
  cancelLabel?: string
  tone?: ConfirmTone
  /** Extra line rendered in a tinted strip under the message (e.g. the listing title). */
  subject?: string
}

interface ConfirmState extends ConfirmOptions {
  open: boolean
}

const EMPTY: ConfirmState = { open: false, title: '' }

/**
 * Promise-based replacement for `window.confirm`.
 *
 *   const confirm = useConfirm()
 *   if (!await confirm({ title: 'Delete this listing?', tone: 'danger' })) return
 *
 * A single dialog instance lives in `ConfirmDialog.vue`, mounted once in app.vue.
 * The resolver is module-scoped rather than stored in `useState` because
 * functions are not serialisable — and the dialog can only ever be opened by a
 * client-side interaction, so there is no SSR request-leak concern.
 */
let resolver: ((value: boolean) => void) | null = null

export const useConfirmState = () => useState<ConfirmState>('confirm-dialog', () => ({ ...EMPTY }))

export const useConfirm = () => {
  const state = useConfirmState()

  function ask(options: ConfirmOptions): Promise<boolean> {
    if (!import.meta.client) return Promise.resolve(false)

    // A second call while one is open resolves the first as cancelled.
    resolver?.(false)

    state.value = {
      open: true,
      tone: 'primary',
      confirmLabel: 'Confirm',
      cancelLabel: 'Cancel',
      ...options,
    }

    return new Promise<boolean>((resolve) => { resolver = resolve })
  }

  function settle(result: boolean): void {
    state.value = { ...state.value, open: false }
    resolver?.(result)
    resolver = null
  }

  return Object.assign(ask, {
    accept: () => settle(true),
    cancel: () => settle(false),
    state,
  })
}
