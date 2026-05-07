import.meta.glob([
  '../images/**',
  '../fonts/**',
]);

// Tracking foundation — ładowane jak najwcześniej, żeby consent default
// (denied) został ustawiony zanim cokolwiek wystartuje npTrack().
import { setConsentDefault } from './lib/track.js';
setConsentDefault();

import './components/slider.js';
import './components/dynamic-content.js';
import './components/appointment-widget.js';
import './custom-accordion.js';
import './mega-menu.js';
import './tabs.js';
// Defer chat widget load until the main thread is idle — creates a separate Vite chunk
// that doesn't block critical rendering. Falls back to 1s timeout on unsupported browsers.
if ('requestIdleCallback' in window) {
    requestIdleCallback(() => import('./components/ai-chat.js'), { timeout: 3000 });
} else {
    setTimeout(() => import('./components/ai-chat.js'), 1000);
}
