/** @type {import('tailwindcss').Config} */

// Design tokens are hex CSS variables in index.css. Tailwind v3 cannot apply
// opacity modifiers (e.g. `bg-counts/15`) to a plain `var(...)` colour, so resolve
// them through color-mix — this keeps index.css as the single source of truth.
const withAlpha =
  (variable) =>
  ({ opacityValue }) => {
    const value = Number(opacityValue)

    // No modifier (or a non-numeric token like `var(--tw-text-opacity)`) → plain token.
    if (opacityValue === undefined || Number.isNaN(value)) {
      return `var(${variable})`
    }

    const percent = value <= 1 ? value * 100 : value

    return `color-mix(in srgb, var(${variable}) ${percent}%, transparent)`
  }

export default {
  darkMode: 'class',
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        background: withAlpha('--background'),
        foreground: withAlpha('--foreground'),
        card: { DEFAULT: withAlpha('--card'), foreground: withAlpha('--card-foreground') },
        popover: { DEFAULT: withAlpha('--popover'), foreground: withAlpha('--popover-foreground') },
        primary: { DEFAULT: withAlpha('--primary'), foreground: withAlpha('--primary-foreground') },
        secondary: {
          DEFAULT: withAlpha('--secondary'),
          foreground: withAlpha('--secondary-foreground'),
        },
        muted: { DEFAULT: withAlpha('--muted'), foreground: withAlpha('--muted-foreground') },
        accent: { DEFAULT: withAlpha('--accent'), foreground: withAlpha('--accent-foreground') },
        destructive: {
          DEFAULT: withAlpha('--destructive'),
          foreground: withAlpha('--destructive-foreground'),
        },
        border: withAlpha('--border'),
        input: withAlpha('--input'),
        ring: withAlpha('--ring'),
        sidebar: withAlpha('--sidebar'),
        switch: withAlpha('--switch'),
        // Data-domain mapping (fixed): blue=counts, green=people, purple=activity, orange=cost
        counts: withAlpha('--domain-counts'),
        people: withAlpha('--domain-people'),
        activity: withAlpha('--domain-activity'),
        cost: withAlpha('--domain-cost'),
        // Brand logo text
        'logo-brand': withAlpha('--logo-brand'),
        // Qualification rating (hot / warm / cold)
        rating: {
          hot: withAlpha('--rating-hot'),
          warm: withAlpha('--rating-warm'),
          cold: withAlpha('--rating-cold'),
        },
      },
      borderRadius: {
        lg: 'var(--radius)',
        md: 'calc(var(--radius) - 2px)',
        sm: 'calc(var(--radius) - 4px)',
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'Segoe UI', 'Roboto', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
