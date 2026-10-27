import type { ConfirmIcon, ConfirmOptions, ConfirmTone } from '~/composables/useConfirm'
import type { Property } from '~/types'

/** The preview card for a listing: its cover photo, title, price and place. */
export function listingPreview(p: Pick<Property, 'title' | 'price' | 'offer_type' | 'address'> & { photos?: Property['photos'] }): NonNullable<ConfirmOptions['preview']> {
  const cover = p.photos?.find(ph => !ph.is_360) ?? p.photos?.[0]
  const n = Number(p.price)
  const price = n > 0
    ? `₱${n >= 1_000_000 ? `${+(n / 1_000_000).toFixed(2)}M` : n >= 10_000 ? `${Math.round(n / 1_000)}K` : n.toLocaleString('en-PH')}${p.offer_type === 'rent' ? '/mo' : ''}`
    : 'No price yet'
  const place = (p.address ?? '').split(',').map(s => s.trim()).filter(Boolean).slice(-2).join(', ')
  // Agent titles are often ALL CAPS; soften them like the listing cards do.
  const t = p.title || 'Untitled listing'
  const letters = t.replace(/[^A-Za-z]/g, '')
  const title = letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
  return { image: cover?.thumb_url ?? cover?.url ?? null, title, meta: [price, place].filter(Boolean).join(' · ') }
}

/**
 * Shared look for every dialog (AppDialog, ConfirmDialog and the custom ones
 * built on AppDialog): the icon tile per tone, the icon paths, and the footer
 * buttons. Phones stack the buttons full width; desktop puts them in a row.
 */
export const DIALOG_TONE: Record<ConfirmTone, { tile: string; accent: string }> = {
  danger:  { tile: 'bg-red-500/10 text-red-600 ring-red-500/20 dark:text-red-400', accent: 'text-red-500 dark:text-red-400' },
  warning: { tile: 'bg-amber-500/10 text-amber-600 ring-amber-500/25 dark:text-amber-400', accent: 'text-amber-500' },
  primary: { tile: 'bg-brand-gold/15 text-brand-gold-deep ring-brand-gold/30 dark:text-brand-gold', accent: 'text-brand-gold-deep dark:text-brand-gold' },
}

export const DIALOG_ICON: Record<ConfirmIcon, string> = {
  trash:    'M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0',
  publish:  'M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418',
  hide:     'M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88',
  sold:     'M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z M6 6h.008v.008H6V6z',
  relist:   'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99',
  leave:    'M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9',
  photo:    'M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z',
  calendar: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
  chat:     'M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z',
  check:    'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  warning:  'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
  bell:     'M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0',
  user:     'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
}

/** The icon a tone shows when the caller doesn't pick one. */
export const DEFAULT_ICON: Record<ConfirmTone, ConfirmIcon> = { danger: 'warning', warning: 'warning', primary: 'check' }

const BTN = 'inline-flex items-center justify-center gap-2 rounded-full px-5 py-3 sm:py-2.5 text-sm font-bold transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed disabled:shadow-none disabled:translate-y-0'
export const DIALOG_BTN = {
  cancel:  `${BTN} font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:bg-brand-navy/[0.05] dark:text-white dark:ring-white/20 dark:hover:bg-white/10`,
  primary: `${BTN} bg-brand-gold text-brand-navy shadow-[0_6px_20px_rgba(212,165,74,0.4)] hover:bg-brand-gold-light hover:-translate-y-0.5`,
  danger:  `${BTN} bg-red-600 text-white shadow-[0_6px_20px_rgba(220,38,38,0.35)] hover:bg-red-700 hover:-translate-y-0.5`,
  warning: `${BTN} bg-amber-500 text-white shadow-[0_6px_20px_rgba(245,158,11,0.35)] hover:bg-amber-600 hover:-translate-y-0.5`,
} as const

export const DIALOG_FIELD =
  'w-full rounded-2xl border-0 px-4 py-3 text-sm text-brand-navy dark:text-white bg-brand-navy/[0.04] dark:bg-white/[0.06] ' +
  'ring-1 ring-inset ring-brand-navy/10 dark:ring-white/10 placeholder-brand-navy/40 dark:placeholder-white/35 ' +
  'focus:ring-2 focus:ring-brand-gold transition'
