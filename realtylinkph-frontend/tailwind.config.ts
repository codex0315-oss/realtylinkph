import type { Config } from 'tailwindcss'
import forms from '@tailwindcss/forms'

export default {
  darkMode: 'class',
  content: [
    './app/**/*.{vue,js,ts,jsx,tsx}',
    './components/**/*.{vue,js,ts,jsx,tsx}',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          navy:           '#08152F',
          'navy-mid':     '#10264D',
          'navy-light':   '#2E6DA4',
          'navy-deep':    '#060E1F',
          gold:           '#D4AF37',
          'gold-light':   '#E8C547',
          // Darker gold for accent *text* on light grounds — #D4AF37 on cream
          // fails contrast. Only for type, never for fills.
          'gold-deep':    '#8A6A11',
          silver:         '#D4D4D4',
          'silver-light': '#F0F0F0',
          white:          '#FFFFFF',
          cream:          '#F7F5F0',
          border:         '#E5E7EB',
          'text-primary':   '#08152F',
          'text-secondary': '#6B7280',
          'text-light':     '#9CA3AF',
        },
      },
      fontFamily: {
        sans:     ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        // Headings use a modern geometric sans (Sora) instead of a serif.
        // `playfair` is kept as an alias so existing `font-playfair` usages update too.
        display:  ['Sora', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        playfair: ['Sora', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
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
        content: '1280px',
      },
    },
  },
  plugins: [forms],
} satisfies Config
