import { useCallback, useEffect, useRef, useState } from 'react';
import { monthOf, shiftMonth, monthRange } from '@/components/turnos/Calendar';

/**
 * Disponibilidad del mes visible (GET /availability), cacheada por clave +
 * mes. La usan el wizard de reserva (clave = servicio) y el reprogramado de
 * un turno (clave = token).
 *
 *  - active: solo pide cuando el paso del calendario está a la vista.
 *  - cacheKey: 'loQueSea:YYYY-MM' (null = nada que pedir).
 *  - month / setMonth: mes visible ('YYYY-MM').
 *  - load(from, to): promesa con { days: { 'YYYY-MM-DD': [slots] } }.
 *  - minDate / maxDate: ISO; el pedido se recorta a ese rango.
 *  - hasSelection: con un día elegido no se salta de mes solo.
 *
 * Si el mes no tiene días libres (y no hay nada elegido) avanza solo hasta 3
 * meses, para no mostrar una grilla vacía. stopHops() lo corta (la clienta
 * navegó a mano) y resetHops() lo vuelve a habilitar.
 */
export function useMonthAvailability({ active, cacheKey, month, setMonth, load, minDate, maxDate, hasSelection }) {
  const [avail, setAvail] = useState({}); // { 'clave:YYYY-MM': { 'YYYY-MM-DD': [slots] } }
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [tick, setTick] = useState(0); // "Reintentar" tras un error al cargar el mes
  const hops = useRef(0);
  const loadRef = useRef(load);
  loadRef.current = load;

  const days = cacheKey ? avail[cacheKey] ?? null : null;

  useEffect(() => {
    if (!active || !cacheKey || avail[cacheKey]) return undefined;
    const { from, to } = monthRange(month);
    const f = from < minDate ? minDate : from;
    const t = to > maxDate ? maxDate : to;
    if (f > t) {
      setAvail((prev) => ({ ...prev, [cacheKey]: {} }));
      return undefined;
    }
    let live = true;
    setLoading(true);
    setError('');
    loadRef.current(f, t).then(
      (res) => {
        if (!live) return;
        const map = res?.days && typeof res.days === 'object' && !Array.isArray(res.days) ? res.days : {};
        setLoading(false);
        setAvail((prev) => ({ ...prev, [cacheKey]: map }));
      },
      (err) => {
        if (!live) return;
        setLoading(false);
        setError(err?.message || '');
      }
    );
    return () => {
      live = false;
    };
  }, [active, cacheKey, month, avail, minDate, maxDate, tick]);

  useEffect(() => {
    if (!active || !days || hasSelection || loading) return;
    const hasDays = Object.values(days).some((list) => Array.isArray(list) && list.length > 0);
    if (hasDays) return;
    const next = shiftMonth(month, 1);
    if (hops.current < 3 && next <= monthOf(maxDate)) {
      hops.current += 1;
      setMonth(next);
    }
  }, [active, days, hasSelection, loading, month, maxDate, setMonth]);

  const retry = useCallback(() => {
    setError('');
    setTick((n) => n + 1);
  }, []);

  /** Descarta el mes cacheado (p. ej. el horario se ocupó) para volver a pedirlo. */
  const invalidate = useCallback((key) => {
    setAvail((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  }, []);

  /** Descarta todo lo cacheado y el error. */
  const clear = useCallback(() => {
    setAvail({});
    setError('');
  }, []);

  const resetHops = useCallback(() => {
    hops.current = 0;
  }, []);
  const stopHops = useCallback(() => {
    hops.current = 3;
  }, []);

  const monthHasDays = !!days && Object.values(days).some((list) => Array.isArray(list) && list.length > 0);

  return { days, loading, error, retry, invalidate, clear, resetHops, stopHops, monthHasDays };
}
