/**
 * Punto de entrada. Monta según window.MJTO.mode:
 *  - 'panel'   → <PanelApp/> en #mjto-panel (página propia del plugin)
 *  - 'booking' → <BookingWidget/> en cada .mjto-booking (shortcode [mj_turnos])
 *  - 'builder' → <FieldsBuilder/> en #mjto-fields-builder (Turnos → Ajustes)
 *
 * Todo el CSS está acotado a `.mjto-root`: cada contenedor recibe esa clase.
 */
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BOOT, MODE, PRESELECT, THEME, adoptHostTheme, applyTheme } from '@/lib/config';
import { RootProvider } from '@/ui/Portal';
import PanelApp from '@/panel/App';
import BookingWidget from '@/booking/BookingWidget';
import FieldsBuilder from '@/builder/FieldsBuilder';
import '@/styles/app.css';

const MOUNTED = 'mjtoMounted';

function render(el, node) {
  el.classList.add('mjto-root');
  el.dataset[MOUNTED] = '1';
  createRoot(el).render(
    <StrictMode>
      <RootProvider el={el}>{node}</RootProvider>
    </StrictMode>
  );
}

function mountPanel() {
  const el = document.getElementById('mjto-panel');
  if (!el || el.dataset[MOUNTED]) return;
  document.documentElement.classList.add('mjto-page');
  document.body.classList.add('mjto-page');
  applyTheme(document.documentElement, THEME);
  render(el, <PanelApp />);
}

function mountBooking() {
  document.querySelectorAll('.mjto-booking').forEach((el) => {
    if (el.dataset[MOUNTED]) return;
    adoptHostTheme(el);
    if (BOOT.theme) applyTheme(el, BOOT.theme, { setMode: !!BOOT.theme.mode });
    const preselect = el.dataset.servicio || el.dataset.preselect || PRESELECT;
    render(el, <BookingWidget preselect={preselect} />);
  });
}

function mountBuilder() {
  const el = document.getElementById('mjto-fields-builder');
  if (!el || el.dataset[MOUNTED]) return;
  // wp-admin es claro: modo día salvo que PHP mande otra cosa.
  applyTheme(el, { mode: 'light', ...(BOOT.theme || {}) });
  const kind = el.dataset.kind === 'ficha' ? 'ficha' : 'form';
  let target = null;
  // data-target acepta un id pelado ("mjto-ficha-json") o un selector ("#mjto-ficha-json").
  if (el.dataset.target) {
    target = document.getElementById(el.dataset.target.replace(/^#/, ''));
    if (!target) {
      try { target = document.querySelector(el.dataset.target); } catch { target = null; }
    }
  }
  if (!target) {
    const form = el.closest('form') || el.parentElement;
    target = form?.querySelector(`textarea[name="mjto_options[${kind === 'ficha' ? 'ficha_blocks_json' : 'form_fields_json'}]"]`) || form?.querySelector('textarea') || null;
  }
  render(el, <FieldsBuilder kind={kind} target={target} defaults={BOOT.defaults || null} />);
}

function boot() {
  if (MODE === 'booking') mountBooking();
  else if (MODE === 'builder') mountBuilder();
  else mountPanel();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}
