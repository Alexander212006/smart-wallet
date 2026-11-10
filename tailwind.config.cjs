module.exports = {
  content: ['./*.php', './includes/*.php', './assets/js/*.js'],
  theme: {
    extend: {
      fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'sans-serif'] },
      borderRadius: { xl: '0.625rem', '2xl': '0.875rem', '3xl': '1rem' },
      colors: {
        slate: { 50: '#f8f9fb', 100: '#f0f2f5', 200: '#e4e7ec', 300: '#cbd1db', 400: '#8590a2', 500: '#667085', 600: '#475467', 700: '#344054', 800: '#253044', 900: '#182230', 950: '#101828' },
        emerald: { 50: '#eff8f5', 100: '#dcefe7', 200: '#b7ddcc', 300: '#8bc5af', 400: '#5aa98c', 500: '#398c70', 600: '#28775e', 700: '#20634e', 800: '#1c5142', 900: '#194436', 950: '#102e26' },
        sky: { 50: '#f0f6f8', 100: '#dce9ef', 200: '#bdd7e3', 400: '#7bb5cd', 500: '#5d98b2', 600: '#3e7f9c', 700: '#32677f' },
        rose: { 50: '#fff3f3', 100: '#fce4e4', 200: '#f6caca', 300: '#edb3b3', 400: '#e29797', 500: '#d57777', 600: '#bb5555', 700: '#a44545', 800: '#8b3d3d', 900: '#783636', 950: '#482222' },
        amber: { 50: '#fffaef', 100: '#f9edc9', 200: '#eddaa5', 400: '#d9b768', 500: '#c59a40', 600: '#a47727', 700: '#896123', 800: '#755322', 900: '#624622' },
      },
      boxShadow: { sm: '0 1px 2px rgb(16 24 40 / 0.025)' },
    },
  },
  plugins: [],
};
