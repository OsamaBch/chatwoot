/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        // Brand
        coral: { DEFAULT: '#FA5B4E', 600: '#EA4638', 700: '#D23A2D' },
        charcoal: { DEFAULT: '#1F1B1C', 700: '#2B2526' },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'Avenir', 'Helvetica', 'Arial', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
