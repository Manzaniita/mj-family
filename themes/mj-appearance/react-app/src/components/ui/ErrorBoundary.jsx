import { Component } from 'react';
import { reportClientError } from '@/lib/errors';
import { t } from '@/lib/copy';

/**
 * Red de seguridad: un error de JavaScript en una pantalla no puede dejar la
 * tienda en blanco. Se atrapa acá, se avisa (consola + endpoint del tema, que
 * lo escribe en el registro de WooCommerce) y se muestra una salida clara.
 *
 * Dos niveles:
 *   - `scope="page"` (dentro del Layout): falla la página, pero el header, el
 *     carrito y el menú siguen funcionando. Se reinicia solo al navegar.
 *   - `scope="app"` (en main.jsx): falló el marco entero; queda una pantalla
 *     mínima con "Recargar", sin depender de nada más que el CSS.
 */
export class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, info) {
    console.error('[mj] Error en la tienda.', error, info?.componentStack);
    reportClientError(error, { componentStack: info?.componentStack, scope: this.props.scope || 'page' });
  }

  componentDidUpdate(prevProps) {
    // Cambió la ruta: la pantalla nueva arranca limpia.
    if (this.state.error && prevProps.resetKey !== this.props.resetKey) {
      this.setState({ error: null }); // eslint-disable-line react/no-did-update-set-state
    }
  }

  render() {
    if (!this.state.error) return this.props.children;
    const reload = () => window.location.reload();
    const home = () => { window.location.href = '/'; };
    const isApp = this.props.scope === 'app';
    return (
      <div
        role="alert"
        className={isApp ? 'grid min-h-screen place-items-center bg-bg px-4 text-ink' : 'mx-auto max-w-7xl px-4 py-24 md:px-8'}
        style={isApp ? { fontFamily: 'Inter, system-ui, sans-serif' } : undefined}
      >
        <div className="mj-error mx-auto max-w-md rounded-(--mj-radius-2xl) border border-line bg-surface p-10 text-center">
          <span className="mx-auto grid size-14 place-items-center rounded-full bg-danger/10 text-danger" aria-hidden="true">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
              <path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
            </svg>
          </span>
          <h1 className="display mt-6 text-2xl">{t('ui_error_title')}</h1>
          <p className="mt-3 text-sm leading-relaxed text-ink-soft">
            {t('ui_error_text')}
          </p>
          <div className="mt-8 flex flex-wrap justify-center gap-3">
            <button
              type="button"
              onClick={reload}
              className="mj-btn mj-btn--primary inline-flex h-11 items-center justify-center rounded-(--mj-radius-btn) bg-accent px-6 text-sm font-medium text-white"
            >
              {t('ui_error_reload')}
            </button>
            <button
              type="button"
              onClick={home}
              className="mj-btn mj-btn--secondary inline-flex h-11 items-center justify-center rounded-(--mj-radius-btn) border border-line px-6 text-sm font-medium text-ink"
            >
              {t('ui_go_home')}
            </button>
          </div>
        </div>
      </div>
    );
  }
}
