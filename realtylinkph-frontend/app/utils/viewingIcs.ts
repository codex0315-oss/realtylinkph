/**
 * Download a viewing as an .ics file — works with Google, Apple and Outlook
 * calendars alike. One hour long, with a reminder two hours before.
 */
export interface ViewingIcs {
  uid: string
  title: string
  address: string
  start: Date
  agentName?: string
  url: string
  pending?: boolean
}

export function downloadViewingIcs(v: ViewingIcs): void {
  const end = new Date(v.start.getTime() + 60 * 60_000)
  const stamp = (d: Date) => d.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '')
  const esc = (s: string) => s.replace(/\\/g, '\\\\').replace(/[,;]/g, m => `\\${m}`).replace(/\n/g, '\\n')
  const lines = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//RealtyLinkPH//Viewing//EN', 'BEGIN:VEVENT',
    `UID:${v.uid}@realtylinkph`, `DTSTAMP:${stamp(new Date())}`,
    `DTSTART:${stamp(v.start)}`, `DTEND:${stamp(end)}`,
    `SUMMARY:${esc(`Property viewing: ${v.title}`)}`,
    `LOCATION:${esc(v.address)}`,
    `DESCRIPTION:${esc(`With ${v.agentName ?? 'your agent'}${v.pending ? ' (waiting for the agent to confirm)' : ''}.\n${v.url}`)}`,
    `URL:${v.url}`,
    'BEGIN:VALARM', 'TRIGGER:-PT2H', 'ACTION:DISPLAY', 'DESCRIPTION:Property viewing in 2 hours', 'END:VALARM',
    'END:VEVENT', 'END:VCALENDAR',
  ]
  const blob = new Blob([lines.join('\r\n')], { type: 'text/calendar' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = `realtylink-viewing-${v.uid}.ics`
  link.click()
  setTimeout(() => URL.revokeObjectURL(link.href), 1000)
}
