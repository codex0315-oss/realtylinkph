import type { Config } from 'tailwindcss'
import forms from '@tailwindcss/forms'
import plugin from 'tailwindcss/plugin'

/*
 * Content-width variants (cw-sm … cw-2xl).
 *
 * Tailwind's sm:/md:/lg: look at the SCREEN. Inside the dashboard the page gets
 * much less than the screen: the sidebar takes 16rem and a docked Messages / AI /
 * Notifications panel another 24rem, so a 1366px laptop can leave a page only
 * ~45rem. A page that picks its columns from the screen then draws a wide layout
 * into a narrow space. These variants look at the space the page really has:
 * the dashboard layout measures <main> and sets cw-sm … cw-2xl on it (a class
 * per step reached, in rem so it scales with the big-monitor font scaling).
 *
 *   cw-sm 40rem · cw-md 48rem · cw-lg 64rem · cw-xl 80rem · cw-2xl 96rem
 *
 * Use them in place of sm:/md:/lg:/xl: for layout inside dashboard pages.
 */
const contentWidth = plugin(({ addVariant }) => {
  for (const step of ['sm', 'md', 'lg', 'xl', '2xl']) addVariant(`cw-${step}`, `.cw-${step} &`)
})

export default {
  darkMode: 'class',
  content: [
    './app/**/*.{vue,js,ts,jsx,tsx}',
    './components/**/*.{vue,js,ts,jsx,tsx}',
  ],
  theme: {
    extend: {
      colors: {
        /*
         * Sampled from the logo: the royal navy of the R's left stroke and the
         * warm mid-tone of its metallic curve — more saturated than the muted
         * #0A2240 / #BCA374 pair this replaced, but still on the logo. Avoid
         * #D4AF37: that flat yellow-gold reads as mustard next to the logo.
         * For a metallic accent use `bg-gold-metal` / `text-gold-metal`
         * (main.css), which run the logo's champagne → gold → bronze sweep.
         *
         * Use it 60-30-10: light surfaces carry the page, navy carries type and
         * structure, gold is the accent (primary buttons, active states, small
         * highlights). Gold FILLS take navy text (6.6:1); gold TEXT on a light
         * ground must use gold-deep — plain gold on white is only 2.4:1.
         */
        brand: {
          navy:           '#0B2A5C',
          'navy-mid':     '#17417F',
          'navy-light':   '#2F78C4',
          'navy-deep':    '#06183A',
          gold:           '#D4A54A',
          'gold-light':   '#E8C77A',   // hover state for gold fills
          'gold-soft':    '#F4E4BE',   // tints, hairlines, quiet backgrounds
          'gold-deep':    '#85601A',   // gold-coloured TEXT on light grounds (5.5:1)
          silver:         '#D4D4D4',
          'silver-light': '#F0F0F0',
          white:          '#FFFFFF',
          cream:          '#F8F7F4',
          border:         '#E5E7EB',
          'text-primary':   '#0B2A5C',
          'text-secondary': '#64748B',
          'text-light':     '#94A3B8',
        },
      },
      fontFamily: {
        sans:     ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        // Headings use a modern geometric sans (Sora) instead of a serif.
        // `playfair` is kept as an alias so existing `font-playfair` usages update too.
        display:  ['Sora', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        playfair: ['Sora', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        // Landing-page headlines: a refined serif that echoes the logo's R
        // monogram. Scoped to `font-heading` so the dashboard keeps Sora;
        // pointing `display` here instead would switch the whole app.
        heading:  ['Fraunces', 'Georgia', 'ui-serif', 'serif'],
      },
      boxShadow: {
        card:       '0 2px 12px rgba(0,0,0,0.08)',
        'card-hover': '0 8px 32px rgba(0,0,0,0.16)',
      },
      borderRadius: {
        card:   '12px',
        button: '8px',
      },
      maxWidth: {
        content: "var(--content-w)", // 80rem (1280px) → 88rem above 1536px; set in main.css, scales with the root font size
      },
    },
  },
  plugins: [forms, contentWidth],
} satisfies Config
