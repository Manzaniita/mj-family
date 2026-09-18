import { useEffect } from 'react';
import { BrowserRouter, Link, Navigate, NavLink, Route, Routes, useLocation } from 'react-router-dom';
import { BellRing, CalendarCheck, CalendarDays, Clock, Coins, ExternalLink, HandCoins, LogOut, MoreHorizontal, Users, Wallet, WifiOff } from 'lucide-react';
import { BASE_PATH, HOME_URL, SITE_NAME, THEME } from '@/lib/config';
import { cn, initials, longDateLabel } from '@/lib/format';
import { Button, EmptyState, Spinner, ToastProvider } from '@/ui';
import { PanelProvider, usePanel } from './store';
import Hoy from './views/Hoy';
import Turnos from './views/Turnos';
import Clientas from './views/Clientas';
import Ficha from './views/Ficha';
import VisitaEditor from './views/VisitaEditor';
import Caja from './views/Caja';
import Mas from './views/Mas';

/**
 * App del panel: proveedores (toasts + store) → router con basename
 * (/turnos) → Shell (topbar, nav inferior en mobile / lateral en desktop) → rutas.
 */
export default function PanelApp() {
  return (
    <div className="mjto-panel min-h-dvh">
      <ToastProvider>
        <PanelProvider>
          <BrowserRouter basename={BASE_PATH || '/'}>
            <ScrollToTop />
            <Shell>
              <PanelRoutes />
            </Shell>
          </BrowserRouter>
        </PanelProvider>
      </ToastProvider>
    </div>
  );
}

function PanelRoutes() {
  const { cajaEnabled } = usePanel();
  return (
    <Routes>
      <Route path="/" element={<Hoy />} />
      <Route path="/turnos" element={<Turnos />} />
      <Route path="/clientas" element={<Clientas />} />
      <Route path="/clientas/:id" element={<Ficha />} />
      <Route path="/clientas/:id/visitas/:visitId" element={<VisitaEditor />} />
      <Route path="/caja" element={cajaEnabled ? <Caja /> : <Navigate to="/" replace />} />
      <Route path="/mas" element={<Mas />} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}

function ScrollToTop() {
  const { pathname } = useLocation();
  useEffect(() => {
    try {
      window.scrollTo(0, 0);
    } catch (e) {
      /* entornos sin scroll (tests) */
    }
  }, [pathname]);
  return null;
}

/* ------------------------------------------------------------------------- */

/** Ítems de navegación (los contadores salen del bootstrap). */
function useNavItems() {
  const store = usePanel();
  const c = store.counts;
  return [
    { to: '/', end: true, label: 'Hoy', icon: CalendarCheck, badge: c.today },
    { to: '/turnos', label: 'Turnos', icon: CalendarDays, badge: c.pending + c.awaitingDeposit },
    { to: '/clientas', label: 'Clientas', icon: Users },
    store.cajaEnabled ? { to: '/caja', label: 'Caja', icon: Wallet } : null,
    // Opiniones esperando revisión (1.3): el contador va en "Más", donde se moderan.
    { to: '/mas', label: 'Más', icon: MoreHorizontal, badge: c.pendingReviews },
  ].filter(Boolean);
}

function Shell({ children }) {
  const store = usePanel();

  if (!store.ready) {
    return store.loading || !store.error ? <BootScreen /> : <BootError error={store.error} onRetry={store.reload} />;
  }

  return (
    <div className="mjto-shell relative flex min-h-dvh">
      <div className="mjto-ambient" aria-hidden="true" />
      <Sidebar />
      <div className="relative z-10 flex min-w-0 flex-1 flex-col lg:pl-(--mjto-sidebar-w)">
        <Topbar />
        <main className="mjto-main mx-auto w-full max-w-5xl flex-1 px-4 pt-4 pb-[calc(var(--mjto-nav-h)+env(safe-area-inset-bottom)+1.5rem)] sm:px-6 lg:px-8 lg:pt-6 lg:pb-12">
          {children}
        </main>
      </div>
      <BottomNav />
    </div>
  );
}

/* ------------------------------ Marca ----------------------------------- */

function Brand({ size = 'md', withDate = false }) {
  const store = usePanel();
  const name = store.business.name || SITE_NAME || 'Turnos';
  const logo = THEME.logo;
  const big = size === 'lg';
  return (
    <div className="mjto-brand flex min-w-0 items-center gap-3">
      {logo ? (
        <img src={logo} alt="" className={cn('shrink-0 rounded-xl object-contain', big ? 'size-11' : 'size-9')} />
      ) : (
        <span
          className={cn(
            'grid shrink-0 place-items-center rounded-xl bg-accent font-display font-bold text-on-accent shadow-glow',
            big ? 'size-11 text-lg' : 'size-9 text-sm'
          )}
          aria-hidden="true"
        >
          {initials(name)}
        </span>
      )}
      <div className="min-w-0">
        <p className={cn('truncate font-display font-bold leading-tight tracking-tight text-ink', big ? 'text-lg' : 'text-[15px]')}>{name}</p>
        {withDate ? (
          <p className="truncate text-xs capitalize text-ink-soft">{longDateLabel(store.today)}</p>
        ) : (
          <p className="truncate text-[11px] font-semibold uppercase tracking-[0.14em] text-ink-faint">Turnos</p>
        )}
      </div>
    </div>
  );
}

/* ------------------------------ Topbar ---------------------------------- */

function CounterPill({ icon: Icon, count, label, tone, to }) {
  if (!count) return null;
  const tones = {
    warning: 'bg-warning-soft text-warning',
    violet: 'bg-violet-soft text-violet',
    info: 'bg-info-soft text-info',
    danger: 'bg-danger-soft text-danger',
  };
  return (
    <Link
      to={to}
      title={label}
      aria-label={`${count} ${label}`}
      className={cn('mjto-counter inline-flex h-8 items-center gap-1.5 rounded-full px-2.5 text-xs font-bold mjto-tabular transition-transform active:scale-95', tones[tone])}
    >
      <Icon size={14} aria-hidden="true" />
      <span>{count}</span>
      <span className="hidden sm:inline">{label}</span>
    </Link>
  );
}

function Topbar() {
  const store = usePanel();
  const c = store.counts;
  return (
    <header className="mjto-topbar mjto-glass mjto-hairline-b sticky top-0 z-30">
      <div className="mx-auto flex h-(--mjto-topbar-h) w-full max-w-5xl items-center gap-3 px-4 sm:px-6 lg:px-8">
        <div className="min-w-0 flex-1 lg:hidden">
          <Brand withDate />
        </div>
        <p className="hidden min-w-0 flex-1 truncate text-sm capitalize text-ink-soft lg:block">{longDateLabel(store.today, { year: true })}</p>
        <div className="flex shrink-0 items-center gap-1.5">
          <CounterPill icon={Clock} count={c.pending} label={store.statusLabel('pending')} tone="warning" to="/turnos?estado=pending" />
          <CounterPill icon={Coins} count={c.awaitingDeposit} label={store.statusLabel('awaiting_deposit')} tone="violet" to="/turnos?estado=awaiting_deposit" />
          <CounterPill icon={BellRing} count={c.depositNotified} label="Avisaron seña" tone="info" to="/turnos?estado=awaiting_deposit&aviso=1" />
          <CounterPill icon={HandCoins} count={c.refundRequests} label="Pidieron devolución" tone="danger" to="/turnos?estado=cancelled&devolucion=1" />
        </div>
        <div className="hidden shrink-0 items-center gap-1 lg:flex">
          <a href={store.urls.site || HOME_URL} className="inline-flex h-9 items-center gap-1.5 rounded-full px-3 text-sm font-medium text-ink-soft transition-colors hover:bg-surface-2 hover:text-ink">
            <ExternalLink size={15} aria-hidden="true" />
            Ver sitio
          </a>
          {store.urls.logout && (
            <a href={store.urls.logout} className="inline-flex h-9 items-center gap-1.5 rounded-full px-3 text-sm font-medium text-ink-soft transition-colors hover:bg-surface-2 hover:text-ink">
              <LogOut size={15} aria-hidden="true" />
              Salir
            </a>
          )}
        </div>
      </div>
    </header>
  );
}

/* ------------------------------ Sidebar --------------------------------- */

function Sidebar() {
  const store = usePanel();
  const items = useNavItems();
  return (
    <aside className="mjto-sidebar fixed inset-y-0 left-0 z-20 hidden w-(--mjto-sidebar-w) flex-col border-r border-line bg-surface lg:flex">
      <div className="px-5 pb-4 pt-6">
        <Brand size="lg" />
      </div>
      <nav className="flex-1 px-3" aria-label="Secciones">
        <ul className="flex flex-col gap-1">
          {items.map(({ to, end, label, icon: Icon, badge }) => (
            <li key={to}>
              <NavLink
                to={to}
                end={end}
                className={({ isActive }) =>
                  cn(
                    'mjto-nav__item group flex h-11 items-center gap-3 rounded-2xl px-3.5 text-[15px] font-semibold transition-colors',
                    isActive ? 'mjto-nav__item--active bg-accent-soft text-accent' : 'text-ink-soft hover:bg-surface-2 hover:text-ink'
                  )
                }
              >
                <Icon size={20} className="shrink-0" aria-hidden="true" />
                <span className="flex-1">{label}</span>
                {badge > 0 && (
                  <span className="inline-grid min-w-6 place-items-center rounded-full bg-accent px-1.5 text-[11px] font-bold leading-6 text-on-accent mjto-tabular">{badge}</span>
                )}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
      <div className="mjto-hairline-t flex flex-col gap-1 px-3 py-4">
        <p className="truncate px-3.5 pb-2 text-xs text-ink-faint">{store.user.name}</p>
        <a href={store.urls.site || HOME_URL} className="flex h-10 items-center gap-3 rounded-2xl px-3.5 text-sm font-medium text-ink-soft transition-colors hover:bg-surface-2 hover:text-ink">
          <ExternalLink size={17} aria-hidden="true" />
          Ver sitio
        </a>
        {store.urls.logout && (
          <a href={store.urls.logout} className="flex h-10 items-center gap-3 rounded-2xl px-3.5 text-sm font-medium text-ink-soft transition-colors hover:bg-surface-2 hover:text-ink">
            <LogOut size={17} aria-hidden="true" />
            Salir
          </a>
        )}
      </div>
    </aside>
  );
}

/* ----------------------------- Nav inferior ------------------------------ */

function BottomNav() {
  const items = useNavItems();
  return (
    <nav className="mjto-bottom-nav mjto-glass mjto-hairline-t fixed inset-x-0 bottom-0 z-30 lg:hidden" aria-label="Secciones">
      <ul className="mjto-safe-bottom grid h-[calc(var(--mjto-nav-h)+env(safe-area-inset-bottom))]" style={{ gridTemplateColumns: `repeat(${items.length}, minmax(0, 1fr))` }}>
        {items.map(({ to, end, label, icon: Icon, badge }) => (
          <li key={to} className="min-w-0">
            <NavLink
              to={to}
              end={end}
              className={({ isActive }) =>
                cn(
                  'mjto-bottom-nav__item relative flex h-full flex-col items-center justify-center gap-1 text-[11px] font-semibold transition-colors active:scale-95',
                  isActive ? 'mjto-bottom-nav__item--active text-accent' : 'text-ink-faint'
                )
              }
            >
              {({ isActive }) => (
                <>
                  <span className={cn('relative grid h-7 w-12 place-items-center rounded-full transition-colors', isActive && 'bg-accent-soft')}>
                    <Icon size={21} strokeWidth={isActive ? 2.4 : 2} aria-hidden="true" />
                    {badge > 0 && (
                      <span className="absolute -right-0.5 -top-1 inline-grid min-w-4.5 place-items-center rounded-full bg-accent px-1 text-[10px] font-bold leading-[18px] text-on-accent mjto-tabular ring-2 ring-bg">
                        {badge > 99 ? '99+' : badge}
                      </span>
                    )}
                  </span>
                  <span className="truncate">{label}</span>
                </>
              )}
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  );
}

/* ------------------------------ Arranque -------------------------------- */

function BootScreen() {
  const name = SITE_NAME || 'Turnos';
  return (
    <div className="mjto-boot flex min-h-dvh flex-col items-center justify-center gap-4 px-6 text-center">
      <span className="grid size-14 place-items-center rounded-2xl bg-accent font-display text-xl font-bold text-on-accent shadow-glow" aria-hidden="true">
        {initials(name)}
      </span>
      <Spinner size={22} label="Cargando el panel" />
      <p className="text-sm text-ink-faint">Cargando el panel…</p>
    </div>
  );
}

function BootError({ error, onRetry }) {
  const message = error?.message || 'No se pudo cargar el panel.';
  const status = error?.status;
  const sessionGone = status === 401 || status === 403;
  return (
    <div className="mjto-boot flex min-h-dvh items-center justify-center px-6">
      <EmptyState
        icon={WifiOff}
        title={sessionGone ? 'Tu sesión venció' : 'No se pudo cargar el panel'}
        text={message}
        action={
          <>
            {sessionGone ? (
              <Button href={window.location.href}>Volver a ingresar</Button>
            ) : (
              <Button onClick={onRetry}>Reintentar</Button>
            )}
            <Button variant="ghost" href={HOME_URL}>
              Ver sitio
            </Button>
          </>
        }
      />
    </div>
  );
}
