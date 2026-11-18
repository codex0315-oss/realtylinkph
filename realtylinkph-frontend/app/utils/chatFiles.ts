/**
 * Chat attachments, checked in the browser before uploading. The server checks
 * again from the file's content (App\Support\ChatFiles); these are the same
 * rules, so people get the answer instantly instead of after an upload.
 */
export type ChatFileKind = 'image' | 'video' | 'file'

export const CHAT_MAX_FILES = 5
export const CHAT_MAX_TOTAL = 50 * 1024 * 1024
export const CHAT_MAX_BYTES: Record<ChatFileKind, number> = {
  image: 10 * 1024 * 1024,
  video: 25 * 1024 * 1024,
  file: 10 * 1024 * 1024,
}
export const CHAT_ACCEPT = 'image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm,.pdf,.doc,.docx,.xls,.xlsx'

const DOC_EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx']

export function chatFileKind(f: File): ChatFileKind | null {
  const t = (f.type || '').toLowerCase()
  if (['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(t)) return 'image'
  if (['video/mp4', 'video/quicktime', 'video/webm'].includes(t)) return 'video'
  const ext = (f.name.split('.').pop() || '').toLowerCase()
  if (DOC_EXT.includes(ext)) return 'file'
  return null
}

export function formatBytes(n: number): string {
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`
  return `${(n / 1024 / 1024).toFixed(n < 10 * 1024 * 1024 ? 1 : 0)} MB`
}

/** Which of `adding` can join `current`, and a message for each one that can't. */
export function checkChatFiles(current: File[], adding: File[]): { ok: File[]; errors: string[] } {
  const ok: File[] = []
  const errors: string[] = []
  let total = current.reduce((s, f) => s + f.size, 0)
  for (const f of adding) {
    const kind = chatFileKind(f)
    if (!kind) { errors.push(`“${f.name}” can’t be sent. Send photos, videos, or PDF, Word or Excel files.`); continue }
    if (current.length + ok.length >= CHAT_MAX_FILES) { errors.push(`You can send up to ${CHAT_MAX_FILES} files at a time.`); break }
    if (f.size > CHAT_MAX_BYTES[kind]) {
      const what = kind === 'video' ? 'Videos' : kind === 'image' ? 'Photos' : 'Files'
      errors.push(`“${f.name}” is too big. ${what} can be up to ${CHAT_MAX_BYTES[kind] / 1024 / 1024} MB.${kind === 'video' ? ' For a longer tour, share a YouTube or Google Drive link.' : ''}`)
      continue
    }
    if (total + f.size > CHAT_MAX_TOTAL) { errors.push('These files are too big together. Send up to 50 MB per message.'); break }
    total += f.size
    ok.push(f)
  }
  return { ok, errors }
}
