/**
 * Selector de clienta para "Nuevo turno": busca por nombre o teléfono
 * (GET /panel/clients?q=, debounce 300 ms) o arma una clienta nueva en línea.
 *
 * `value` puede ser:
 *   - null                      → nada elegido (se muestra el buscador)
 *   - clienta existente { id > 0, firstName, lastName, phone, … } (mjto_client_public)
 *   - borrador nuevo { id: 0, isNew: true, firstName, lastName, phone, email, locality }
 * `onChange(value)` recibe una de esas tres formas. clientPayload(value) la
 * traduce al body de POST /panel/appointments (client_id | client:{…}).
 */
import { useEffect, useRef, useState } from 'react';
import { Search, UserPlus, UserRound, X } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { cn, dateLabel, fullName, initials, phoneDigits, pluralize, prettyPhone } from '@/lib/format';
import { Button, FieldRow, Input, Spinner } from '@/ui';

/** Borrador de clienta nueva; `seed` puede traer nombre/teléfono desde lo que se buscó. */
export function newClientDraft(seed = {}) {
  return {
    id: 0,
    isNew: true,
    firstName: '',
    lastName: '',
    phone: '',
    email: '',
    locality: '',
    ...seed,
  };
}

/** Semilla desde el texto buscado: si son dígitos es teléfono, si no nombre (+ apellido). */
function seedFromQuery(q) {
  const term = String(q || '').trim();
  if (!term) return {};
  if (/^[\d\s+()\-.]+$/.test(term)) return { phone: term };
  const parts = term.split(/\s+/);
  return { firstName: parts[0] || '', lastName: parts.slice(1).join(' ') };
}

/** Body para la API: { client_id } o { client: { first_name, … } }. null si no hay clienta. */
export function clientPayload(value) {
  if (!value) return null;
  if (value.id > 0 && !value.isNew) return { client_id: Number(value.id) };
  return {
    client: {
      first_name: String(value.firstName || '').trim(),
      last_name: String(value.lastName || '').trim(),
      phone: String(value.phone || '').trim(),
      email: String(value.email || '').trim(),
      locality: String(value.locality || '').trim(),
    },
  };
}

/** Error de validación del borrador (nombre y teléfono obligatorios) o ''. */
export function validateClientDraft(value) {
  if (!value) return 'Elegí una clienta o cargá una nueva.';
  if (value.id > 0 && !value.isNew) return '';
  if (!String(value.firstName || '').trim()) return 'Falta el nombre de la clienta.';
  if (phoneDigits(value.phone).length < 6) return 'El teléfono tiene que tener al menos 6 dígitos.';
  return '';
}

function Avatar({ name, className }) {
  return (
    <span className={cn('grid size-10 shrink-0 place-items-center rounded-full bg-accent-soft text-sm font-bold text-accent', className)} aria-hidden="true">
      {initials(name)}
    </span>
  );
}

/**
 * @param {{ value: object|null, onChange: (value) => void, allowNew?: boolean, label?: string, placeholder?: string,
 *   autoFocus?: boolean, error?: string, disabled?: boolean, className? }} props
 */
export function ClientPicker({ value, onChange, allowNew = true, label = 'Clienta', placeholder = 'Buscar por nombre o teléfono', autoFocus = false, error, disabled = false, className }) {
  const [q, setQ] = useState('');
  const [results, setResults] = useState([]);
  const [searching, setSearching] = useState(false);
  const [searched, setSearched] = useState(false);
  const listRef = useRef(null);

  // Búsqueda con debounce; se cancela la anterior si se sigue escribiendo.
  useEffect(() => {
    if (value) return undefined;
    const term = q.trim();
    if (!term) {
      setResults([]);
      setSearched(false);
      setSearching(false);
      return undefined;
    }
    const ctrl = new AbortController();
    const timer = setTimeout(async () => {
      setSearching(true);
      try {
        const res = await panelApi.listClients({ q: term, per_page: 8 }, { signal: ctrl.signal });
        if (ctrl.signal.aborted) return;
        setResults(Array.isArray(res?.items) ? res.items : []);
        setSearched(true);
      } catch (e) {
        if (ctrl.signal.aborted || e?.name === 'AbortError') return;
        setResults([]);
        setSearched(true);
      } finally {
        if (!ctrl.signal.aborted) setSearching(false);
      }
    }, 300);
    return () => {
      clearTimeout(timer);
      ctrl.abort();
    };
  }, [q, value]);

  const pick = (client) => {
    onChange?.(client);
    setQ('');
    setResults([]);
    setSearched(false);
  };

  const startNew = () => {
    onChange?.(newClientDraft(seedFromQuery(q)));
    setQ('');
    setResults([]);
    setSearched(false);
  };

  const clear = () => onChange?.(null);

  const onKeyDown = (e) => {
    // Flecha abajo desde el buscador entra a la lista; Enter con un solo resultado lo elige.
    if (e.key === 'ArrowDown' && results.length) {
      e.preventDefault();
      listRef.current?.querySelector('button')?.focus();
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (results.length === 1) pick(results[0]);
      else if (!results.length && searched && allowNew) startNew();
    }
  };

  /* --- Clienta existente elegida --- */
  if (value && value.id > 0 && !value.isNew) {
    const name = fullName(value);
    return (
      <div className={cn('mjto-client-picker', className)}>
        {label && <p className="mjto-field__label mb-1.5 text-[13px] font-semibold text-ink-soft">{label}</p>}
        <div className="mjto-client-picker__selected flex items-center gap-3 rounded-tile border border-line bg-surface-2 p-3">
          <Avatar name={name} />
          <div className="min-w-0 flex-1">
            <p className="truncate font-semibold text-ink">{name}</p>
            <p className="truncate text-sm text-ink-soft">
              {prettyPhone(value.phoneRaw || value.phone)}
              {value.locality ? ` · ${value.locality}` : ''}
            </p>
          </div>
          {!disabled && (
            <Button variant="ghost" size="sm" icon={X} iconOnly aria-label="Cambiar clienta" onClick={clear} />
          )}
        </div>
        {error && <p className="mjto-field__error mt-1.5 text-xs font-medium text-danger" role="alert">{error}</p>}
      </div>
    );
  }

  /* --- Clienta nueva en línea --- */
  if (value && value.isNew) {
    const set = (key) => (e) => onChange?.({ ...value, [key]: e.target.value });
    return (
      <div className={cn('mjto-client-picker', className)}>
        <div className="mb-2 flex items-center justify-between gap-3">
          {label && <p className="mjto-field__label text-[13px] font-semibold text-ink-soft">{label} · nueva</p>}
          {!disabled && (
            <button type="button" onClick={clear} className="text-xs font-semibold text-accent hover:underline">
              Buscar una existente
            </button>
          )}
        </div>
        <div className="mjto-client-picker__new flex flex-col gap-3 rounded-tile border border-line bg-surface-2 p-3">
          <FieldRow>
            <Input label="Nombre" required value={value.firstName} onChange={set('firstName')} autoComplete="off" autoFocus disabled={disabled} placeholder="Nombre" />
            <Input label="Apellido" value={value.lastName} onChange={set('lastName')} autoComplete="off" disabled={disabled} placeholder="Apellido" />
          </FieldRow>
          <FieldRow>
            <Input label="Teléfono / WhatsApp" required type="tel" inputMode="tel" value={value.phone} onChange={set('phone')} autoComplete="off" disabled={disabled} placeholder="11 1234-5678" />
            <Input label="Email" type="email" inputMode="email" value={value.email} onChange={set('email')} autoComplete="off" disabled={disabled} placeholder="nombre@email.com" />
          </FieldRow>
          <Input label="Localidad" value={value.locality} onChange={set('locality')} autoComplete="off" disabled={disabled} placeholder="Localidad" />
        </div>
        {error && <p className="mjto-field__error mt-1.5 text-xs font-medium text-danger" role="alert">{error}</p>}
      </div>
    );
  }

  /* --- Buscador --- */
  const term = q.trim();
  return (
    <div className={cn('mjto-client-picker', className)}>
      <Input
        label={label}
        icon={Search}
        type="search"
        value={q}
        onChange={(e) => setQ(e.target.value)}
        onKeyDown={onKeyDown}
        placeholder={placeholder}
        autoComplete="off"
        autoFocus={autoFocus}
        disabled={disabled}
        error={error}
        suffix={searching ? <Spinner size={16} label="Buscando" /> : null}
        aria-autocomplete="list"
      />
      {(results.length > 0 || (searched && term) || (allowNew && !term)) && (
        <div ref={listRef} className="mjto-client-picker__results mt-2 overflow-hidden rounded-tile border border-line bg-surface" role="listbox" aria-label="Resultados">
          {results.map((c) => {
            const name = fullName(c);
            return (
              <button
                key={c.id}
                type="button"
                role="option"
                aria-selected="false"
                onClick={() => pick(c)}
                className="flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-surface-2 focus-visible:bg-surface-2 focus-visible:outline-none"
              >
                <Avatar name={name} />
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-semibold text-ink">{name}</span>
                  <span className="block truncate text-sm text-ink-soft">
                    {prettyPhone(c.phoneRaw || c.phone)}
                    {c.locality ? ` · ${c.locality}` : ''}
                  </span>
                </span>
                <span className="hidden shrink-0 text-right text-xs text-ink-faint sm:block">
                  {c.visitsCount > 0 ? pluralize(c.visitsCount, 'visita') : 'Sin visitas'}
                  {c.lastVisit ? <span className="block">últ. {dateLabel(String(c.lastVisit).slice(0, 10))}</span> : null}
                </span>
              </button>
            );
          })}
          {searched && term && !results.length && (
            <p className="px-3 py-3 text-sm text-ink-soft">No encontré a nadie con «{term}».</p>
          )}
          {allowNew && !disabled && (
            <button
              type="button"
              onClick={startNew}
              className={cn(
                'flex w-full items-center gap-3 px-3 py-2.5 text-left text-sm font-semibold text-accent transition-colors hover:bg-accent-soft focus-visible:bg-accent-soft focus-visible:outline-none',
                (results.length > 0 || (searched && term)) && 'mjto-hairline-t'
              )}
            >
              <span className="grid size-10 shrink-0 place-items-center rounded-full bg-accent-soft" aria-hidden="true">
                <UserPlus size={18} />
              </span>
              {term ? `Nueva clienta «${term}»` : 'Nueva clienta'}
            </button>
          )}
          {!allowNew && !term && (
            <p className="flex items-center gap-2 px-3 py-3 text-sm text-ink-faint">
              <UserRound size={16} aria-hidden="true" />
              Escribí un nombre o un teléfono para buscar.
            </p>
          )}
        </div>
      )}
    </div>
  );
}

export default ClientPicker;
