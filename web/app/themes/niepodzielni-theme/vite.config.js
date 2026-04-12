import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin'
import { wordpressPlugin } from '@roots/vite-plugin';

// APP_URL — ustaw w pliku .env w katalogu motywu:
// APP_URL=http://twoja-domena.local
if (! process.env.APP_URL) {
  process.env.APP_URL = 'http://dev-niepodzielni.local';
}

export default defineConfig({
  // DODANA SEKCJA: Wymuszamy host 127.0.0.1 (IPv4)
  server: {
    host: '127.0.0.1',
    cors: true, // Przydaje się w środowiskach lokalnych, by przeglądarka nie blokowała skryptów
  },
  base: '/wp-content/themes/niepodzielni-theme/public/build/',
  plugins: [
    tailwindcss(),
    laravel({
      input: [
        'resources/css/app.css',
        'resources/js/app.js',
        'resources/js/bookero-init.js',
        'resources/js/psy-listing-atomic.js',
        'resources/js/events-listing.js',
        'resources/js/bk-shared-calendar.js',
        'resources/css/editor.css',
        'resources/js/editor.js',
        'resources/js/matchmaker.js',
      ],
      refresh: true,
    }),

    wordpressPlugin(),
  ],
  resolve: {
    alias: {
      '@scripts': '/resources/js',
      '@styles': '/resources/css',
      '@fonts': '/resources/fonts',
      '@images': '/resources/images',
    },
  },
})