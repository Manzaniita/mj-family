import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-react';
import { cn } from '@/lib/format';
import { Portal } from './Portal';

const ToastContext = createContext(null);

const icons = {
  success: CheckCircle2,
  error: XCircle,
  warning: AlertTriangle,
  info: Info,
};

const iconTone = {
  success: 'text-success',
  error: 'text-danger',
  warning: 'text-warning',
  info: 'text-info',
};

let seq = 0;

function ToastItem({ t, onDismiss }) {
  const Icon = icons[t.tone] || Info;
  return (
    <div
      role={t.tone === 'error' ? 'alert' : 'status'}
      data-state={t.state}
      className={cn(
        'mjto-toast flex w-full max-w-md items-center gap-3 rounded-2xl bg-ink px-4 py-3 text-bg shadow-float',
        `mjto-toast--${t.tone}`
      )}
    >
      <Icon size={20} className={cn('shrink-0', iconTone[t.tone] || 'text-bg')} aria-hidden="true" />
      <p className="min-w-0 flex-1 text-sm font-medium leading-snug">{t.message}</p>
      {t.action && (
        <button
          type="button"
          onClick={() => {
            t.action.onClick?.();
            onDismiss(t.id);
          }}
          className="shrink-0 rounded-full px-2.5 py-1 text-sm font-semibold text-accent-strong hover:bg-bg/10 dark:text-accent"
        >
          {t.action.label}
        </button>
      )}
      <button type="button" onClick={() => onDismiss(t.id)} className="-mr-1 grid size-7 shrink-0 place-items-center rounded-full opacity-60 hover:bg-bg/10 hover:opacity-100" aria-label="Cerrar">
        <X size={15} aria-hidden="true" />
      </button>
    </div>
  );
}

/**
 * Proveedor de avisos. Envolver la app: <ToastProvider>…</ToastProvider>.
 * @param {{ duration?: number (ms, default 3500), max?: number, children }} props
 */
export function ToastProvider({ duration = 3500, max = 3, children }) {
  const [items, setItems] = useState([]);
  const timers = useRef(new Map());

  const remove = useCallback((id) => {
    setItems((list) => list.filter((t) => t.id !== id));
    const tm = timers.current.get(id);
    if (tm) {
      clearTimeout(tm.hide);
      clearTimeout(tm.remove);
      timers.current.delete(id);
    }
  }, []);

  const dismiss = useCallback(
    (id) => {
      setItems((list) => list.map((t) => (t.id === id ? { ...t, state: 'closed' } : t)));
      const tm = timers.current.get(id) || {};
      clearTimeout(tm.hide);
      tm.remove = setTimeout(() => remove(id), 240);
      timers.current.set(id, tm);
    },
    [remove]
  );

  const push = useCallback(
    (message, { tone = 'info', duration: ms, action, id: forcedId } = {}) => {
      const text = message instanceof Error ? message.message : String(message ?? '');
      if (!text) return null;
      seq += 1;
      const id = forcedId || `t${seq}`;
      setItems((list) => {
        const next = [...list.filter((t) => t.id !== id), { id, message: text, tone, action, state: 'open' }];
        return next.slice(-max);
      });
      const wait = ms === undefined ? (tone === 'error' ? Math.max(duration, 5000) : duration) : ms;
      if (wait > 0) {
        const hide = setTimeout(() => dismiss(id), wait);
        timers.current.set(id, { hide });
      }
      return id;
    },
    [dismiss, duration, max]
  );

  useEffect(() => () => timers.current.forEach((tm) => {
    clearTimeout(tm.hide);
    clearTimeout(tm.remove);
  }), []);

  const api = useMemo(
    () => ({
      toast: push,
      success: (m, o) => push(m, { ...o, tone: 'success' }),
      error: (m, o) => push(m, { ...o, tone: 'error' }),
      warning: (m, o) => push(m, { ...o, tone: 'warning' }),
      info: (m, o) => push(m, { ...o, tone: 'info' }),
      dismiss,
    }),
    [push, dismiss]
  );

  return (
    <ToastContext.Provider value={api}>
      {children}
      <Portal>
        <div className="mjto-toasts" aria-live="polite" aria-relevant="additions">
          {items.map((t) => (
            <ToastItem key={t.id} t={t} onDismiss={dismiss} />
          ))}
        </div>
      </Portal>
    </ToastContext.Provider>
  );
}

/**
 * @returns {{ toast(message, opts?), success(m), error(m|Error), warning(m), info(m), dismiss(id) }}
 * Fuera del proveedor devuelve un stub que no hace nada (no rompe).
 */
export function useToast() {
  const ctx = useContext(ToastContext);
  if (ctx) return ctx;
  const noop = () => null;
  return { toast: noop, success: noop, error: noop, warning: noop, info: noop, dismiss: noop };
}
