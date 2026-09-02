/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./index.html", "./src/**/*.{js,ts,jsx,tsx}"],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        primary: '#1751a5',   // main blue
        dark: '#151515',      // black background
        secondary: '#2b83fa', // hover/secondary blue
        white: '#ffffff',     // white text/background
      },
      fontFamily: {
        sans: ['Poppins', 'system-ui', 'Avenir', 'Helvetica', 'Arial', 'sans-serif'],
      },
    },
  },
  plugins: [],
}