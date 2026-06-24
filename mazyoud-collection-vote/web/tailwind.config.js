/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        keep: '#16a34a',
        skip: '#dc2626',
        super: '#f59e0b',
      },
      boxShadow: {
        card: '0 10px 40px rgba(0,0,0,0.25)',
      },
    },
  },
  plugins: [],
};
