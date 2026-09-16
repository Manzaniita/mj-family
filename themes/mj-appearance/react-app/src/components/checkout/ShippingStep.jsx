import { useEffect, useMemo, useRef, useState } from 'react';
import { Check, Loader2, AlertCircle, MapPin } from 'lucide-react';
import { Input, Select, Textarea } from '@/components/ui/Input';
import { useCart } from '@/store';
import * as wc from '@/lib/wc';
import { cn, formatPrice } from '@/lib/format';
import { IS_WP, WP } from '@/lib/config';
import {
  SHIPPING,
  shippingTexts,
  cpConfig,
  provinces,
  methodBySlug,
  addressField,
  extraFields,
  branchField,
  cpLooksValid,
  debounce,
  formFields,
} from '@/lib/envios';

/**
 * Paso de envío del checkout.
 *
 * Lo maneja el plugin MJ Envíos: qué se pregunta, en qué orden, con qué textos y
 * qué métodos existen sale todo de window.MJ.shipping, y los precios salen de
 * las tarifas del carrito del servidor. Acá no hay ni un correo ni un precio
 * escrito.
 *
 * El orden es el que menos escribe el cliente: primero el código postal (que
 * completa provincia y localidad solo), después las formas de envío con su
 * precio real, y al final los datos que pida la que eligió.
 */
export default function ShippingStep({ title, form, setForm, extra, setExtra }) {
  const texts = shippingTexts();
  const cpCfg = cpConfig();
  const states = useMemo(provinces, []);

  const cartShipping = useCart((s) => s.shipping);
  const serverShipping = useCart((s) => s.server?.shipping);
  const syncAddress = useCart((s) => s.syncAddress);
  const selectRate = useCart((s) => s.selectRate);
  const syncShipping = useCart((s) => s.syncShipping);

  const rates = cartShipping?.rates ?? [];
  const selected = rates.find((r) => r.selected) ?? null;
  const method = selected?.slug ? methodBySlug(selected.slug) : null;

  /* Estado del código postal: qué sabemos del destino que cargó el cliente. */
  const [cp, setCp] = useState({ status: 'idle', cities: [], asked: '' });
  const [suggestions, setSuggestions] = useState([]);
  const [branches, setBranches] = useState({ loading: false, list: [] });
  const [busy, setBusy] = useState(false);

  /* La dirección se manda una sola vez por destino: sin esto, cada tecla dispararía un cálculo. */
  const lastSent = useRef('');

  const addressKey = () => [form.postcode, form.state, form.city, form.address].join('|');

  /** Manda el destino al servidor y trae las tarifas que correspondan. */
  const pushAddress = async () => {
    if (!IS_WP) return;
    const key = addressKey();
    if (!cpLooksValid(form.postcode) || key === lastSent.current) return;
    lastSent.current = key;
    setBusy(true);
    await syncAddress({
      postcode: form.postcode,
      state: form.state || '',
      city: form.city || '',
      address_1: form.address || '',
      country: WP?.baseCountry || SHIPPING?.country || 'AR',
    });
    setBusy(false);
  };

  /**
   * Qué hay en ese código postal.
   *
   * Tres respuestas posibles: no tiene forma de CP (se avisa), lo conocemos
   * (se completa provincia y localidad) o es válido pero todavía no lo
   * conocemos, y entonces se le pide la localidad al cliente. Lo que elija
   * queda aprendido en el servidor para la próxima vez.
   */
  const lookup = async (value) => {
    if (!IS_WP) return;
    if (!cpLooksValid(value)) {
      setCp({ status: String(value || '').trim() === '' ? 'idle' : 'invalid', cities: [], asked: value });
      return;
    }
    setCp((c) => ({ ...c, status: 'loading', asked: value }));
    try {
      const data = await wc.getPostcode(value);
      if (!data?.valido) {
        setCp({ status: 'invalid', cities: [], asked: value });
        return;
      }
      const cities = data.localidades ?? [];
      if (data.encontrado && cities.length) {
        setForm((f) => ({
          ...f,
          state: data.provinciaId || cities[0].provinciaId || f.state,
          // Con una sola localidad se completa; con varias, que elija.
          city: cities.length === 1 ? cities[0].nombre : f.city,
        }));
        setCp({ status: 'ok', cities, asked: value });
      } else {
        setCp({ status: 'unknown', cities: [], asked: value });
      }
    } catch {
      /* Sin el plugin (o sin internet) el paso sigue: se cargan provincia y localidad a mano. */
      setCp({ status: 'unknown', cities: [], asked: value });
    }
  };

  const lookupDebounced = useRef(debounce(lookup, 400)).current;

  /* Al entrar con datos guardados (compra rápida), se resuelve el destino solo. */
  useEffect(() => {
    if (!IS_WP) return;
    if (cpLooksValid(form.postcode) && cp.status === 'idle') {
      lookup(form.postcode).then(pushAddress);
    }
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  /* Con provincia y localidad resueltas, se piden las tarifas. */
  useEffect(() => {
    if (cp.status === 'ok' || cp.status === 'unknown') pushAddress();
  }, [cp.status, form.state, form.city]); // eslint-disable-line react-hooks/exhaustive-deps

  /* Sucursales del método elegido, para el código postal cargado. */
  useEffect(() => {
    if (!IS_WP || !selected?.slug || !method?.needsBranch) {
      setBranches({ loading: false, list: [] });
      return;
    }
    let alive = true;
    setBranches({ loading: true, list: [] });
    wc.getBranches(selected.slug, form.postcode)
      .then((list) => alive && setBranches({ loading: false, list: Array.isArray(list) ? list : [] }))
      .catch(() => alive && setBranches({ loading: false, list: [] }));
    return () => {
      alive = false;
    };
  }, [selected?.slug, method?.needsBranch, form.postcode]); // eslint-disable-line react-hooks/exhaustive-deps

  /*
   * Lo que el cliente escribe viaja al servidor con calma. Siempre con el contacto y la dirección:
   * MJ Envíos valida todo junto, y cada envío reemplaza lo anterior.
   */
  const pushFields = useRef(debounce((fields) => syncShipping({ fields }), 600)).current;
  const allFields = (extraValues = extra) => ({ ...formFields(form), ...extraValues });

  const setExtraValue = (key, value) => {
    const next = { ...extra, [key]: value };
    setExtra(next);
    /* La sucursal cambia el precio: esa va sin esperar. */
    if (key === 'branch') {
      syncShipping({ branch: value, fields: allFields(next) });
      return;
    }
    pushFields(allFields(next));
  };

  /* Escribir el nombre, el teléfono o la dirección también le avisa al servidor. */
  useEffect(() => {
    if (!IS_WP) return;
    pushFields(allFields());
  }, [form.firstName, form.lastName, form.email, form.phone, form.postcode, form.state, form.city, form.address, form.address2]); // eslint-disable-line react-hooks/exhaustive-deps

  /** Elegir una forma de envío. */
  const chooseRate = async (rate) => {
    setBusy(true);
    await selectRate(rate.rate_id);
    if (rate.slug) await syncShipping({ method: rate.slug, fields: allFields() });
    setBusy(false);
  };

  /* Autocompletado de localidad cuando el CP no está en la tabla. */
  const searchCities = useRef(
    debounce(async (value, state) => {
      if (!IS_WP || String(value || '').trim().length < 2) {
        setSuggestions([]);
        return;
      }
      try {
        const list = await wc.searchCities(value, state);
        setSuggestions(Array.isArray(list) ? list.slice(0, 6) : []);
      } catch {
        setSuggestions([]);
      }
    }, 350)
  ).current;

  const field = (key) => ({
    value: form[key] ?? '',
    onChange: (e) => setForm((f) => ({ ...f, [key]: e.target.value })),
  });

  const required = (key, fallback = true) => {
    const cfg = addressField(method, key);
    return cfg ? cfg.required : fallback;
  };
  const labelOf = (key, fallback) => addressField(method, key)?.label ?? fallback;
  const askAddress = !method || method.destination === 'domicilio';

  return (
    <div className="mj-panel rounded-xl3 border border-line glass p-7">
      <h2 className="font-display text-lg font-semibold tracking-tight">{title}</h2>
      {texts.stepIntro && <p className="mt-1 text-sm text-ink-soft">{texts.stepIntro}</p>}

      {/* 1 · Destino */}
      <div className="mt-5 grid gap-4 sm:grid-cols-2">
        <div>
          <Input
            label={cpCfg.label || 'Código postal'}
            required={cpCfg.required !== false}
            inputMode="numeric"
            autoComplete="postal-code"
            placeholder="1425"
            value={form.postcode ?? ''}
            onChange={(e) => {
              const value = e.target.value;
              setForm((f) => ({ ...f, postcode: value }));
              lookupDebounced(value);
            }}
            onBlur={() => lookup(form.postcode).then(pushAddress)}
          />
          <p className="mt-1.5 flex items-center gap-1.5 text-xs text-ink-faint">
            {cp.status === 'loading' && <Loader2 size={12} className="animate-spin" />}
            {cp.status === 'invalid'
              ? <span className="text-warning">{cpCfg.invalid}</span>
              : cp.status === 'ok'
                ? <span className="text-success">{form.state ? `${form.city || ''}${form.city ? ', ' : ''}${states.find((s) => s.code === form.state)?.name ?? ''}` : cpCfg.help}</span>
                : cpCfg.help}
          </p>
        </div>

        {/* Varias localidades comparten el mismo CP: que elija la suya. */}
        {cp.status === 'ok' && cp.cities.length > 1 && (
          <Select label={labelOf('city', 'Localidad')} required={required('city')} {...field('city')}>
            <option value="">Elegí tu localidad</option>
            {cp.cities.map((c) => (
              <option key={`${c.nombre}-${c.provincia}`} value={c.nombre}>{c.nombre}</option>
            ))}
          </Select>
        )}

        {/* CP válido que todavía no conocemos: provincia y localidad a mano. */}
        {cp.status !== 'ok' && (
          <>
            <Select label={labelOf('state', 'Provincia')} required={required('state')} {...field('state')}>
              <option value="">Elegí tu provincia</option>
              {states.map((s) => (
                <option key={s.code} value={s.code}>{s.name}</option>
              ))}
            </Select>
            <div className="relative">
              <Input
                label={labelOf('city', 'Localidad')}
                required={required('city')}
                autoComplete="address-level2"
                placeholder="Tu localidad"
                value={form.city ?? ''}
                onChange={(e) => {
                  const value = e.target.value;
                  setForm((f) => ({ ...f, city: value }));
                  searchCities(value, form.state);
                }}
                onBlur={() => setTimeout(() => setSuggestions([]), 150)}
              />
              {suggestions.length > 0 && (
                <ul className="absolute z-20 mt-1 w-full overflow-hidden rounded-xl2 border border-line bg-surface shadow-lg">
                  {suggestions.map((s) => (
                    <li key={`${s.nombre}-${s.provincia}`}>
                      <button
                        type="button"
                        className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-surface-2"
                        onMouseDown={(e) => e.preventDefault()}
                        onClick={() => {
                          setForm((f) => ({ ...f, city: s.nombre, state: s.provinciaId || f.state }));
                          setSuggestions([]);
                        }}
                      >
                        <MapPin size={13} className="shrink-0 text-ink-faint" />
                        <span>{s.nombre}</span>
                        <span className="ml-auto text-xs text-ink-faint">{s.provincia}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </>
        )}

        {askAddress && (
          <>
            <Input
              label={labelOf('address_1', 'Calle y número')}
              required={required('address_1')}
              placeholder="Av. Siempre Viva 742"
              className="sm:col-span-2"
              autoComplete="street-address"
              {...field('address')}
              onBlur={pushAddress}
            />
            {(!method || addressField(method, 'address_2')) && (
              <Input
                label={labelOf('address_2', 'Piso, departamento o referencia')}
                required={required('address_2', false)}
                placeholder="3° B"
                className="sm:col-span-2"
                autoComplete="address-line2"
                {...field('address2')}
              />
            )}
          </>
        )}
      </div>

      {/* 2 · Formas de envío */}
      <div className="mt-7 border-t border-line pt-6">
        <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">
          Cómo lo querés recibir
        </p>

        {!cpLooksValid(form.postcode) ? (
          <p className="mt-3 text-sm text-ink-soft">{cpCfg.ask}</p>
        ) : busy && rates.length === 0 ? (
          <p className="mt-3 flex items-center gap-2 text-sm text-ink-soft">
            <Loader2 size={14} className="animate-spin" /> Calculando el envío…
          </p>
        ) : rates.length === 0 ? (
          <div className="mt-3 flex items-start gap-3 rounded-xl2 border border-warning/30 bg-warning/10 p-4 text-sm text-warning">
            <AlertCircle size={17} className="mt-0.5 shrink-0" />
            <p>{texts.none}</p>
          </div>
        ) : (
          <div className="mt-3 space-y-2">
            {rates.map((rate) => (
              <button
                type="button"
                key={rate.rate_id}
                onClick={() => chooseRate(rate)}
                className={cn(
                  'mj-choice flex w-full items-center justify-between gap-3 rounded-xl2 border p-4 text-left transition-all duration-200',
                  rate.selected ? 'border-accent bg-accent-soft' : 'border-line hover:border-line-strong'
                )}
              >
                <span className="flex items-start gap-3">
                  <span
                    className={cn(
                      'mt-0.5 grid size-5 shrink-0 place-items-center rounded-full border-2 transition-colors',
                      rate.selected ? 'border-accent bg-accent' : 'border-line-strong'
                    )}
                  >
                    {rate.selected && <Check size={11} className="text-white" strokeWidth={3} />}
                  </span>
                  <span>
                    <span className="block text-sm font-medium">{rate.name}</span>
                    {rate.description && <span className="block text-xs text-ink-faint">{rate.description}</span>}
                    {rate.eta && <span className="block text-xs text-ink-faint">{rate.eta}</span>}
                    {rate.note && <span className="block text-xs text-ink-faint">{rate.note}</span>}
                  </span>
                </span>
                <span className="shrink-0 text-sm font-medium">
                  {rate.coordinate
                    ? <span className="text-ink-soft">A coordinar</span>
                    : rate.cost > 0
                      ? formatPrice(rate.cost)
                      : <span className="text-success">{texts.free || 'GRATIS'}</span>}
                </span>
              </button>
            ))}
          </div>
        )}
      </div>

      {/* 3 · Lo que pide el método elegido */}
      {method?.needsBranch && (
        <div className="mt-7 border-t border-line pt-6">
          <Select
            label={branchField(method)?.label || texts.branch || 'Sucursal'}
            required={branchField(method)?.required !== false}
            value={extra.branch ?? ''}
            onChange={(e) => setExtraValue('branch', e.target.value)}
            disabled={branches.loading}
          >
            <option value="">{branches.loading ? 'Buscando sucursales…' : 'Elegí la sucursal'}</option>
            {branches.list.map((b) => (
              <option key={b.codigo} value={b.codigo}>
                {[b.nombre, b.direccion, b.localidad].filter(Boolean).join(' · ')}
              </option>
            ))}
          </Select>
          <p className="mt-1.5 text-xs text-ink-faint">
            {branches.list.length === 0 && !branches.loading
              ? 'No encontramos sucursales para ese código postal. Escribinos y la coordinamos.'
              : (branchField(method)?.help || texts.branchHelp)}
          </p>
        </div>
      )}

      {method && extraFields(method).length > 0 && (
        <div className="mt-7 grid gap-4 border-t border-line pt-6 sm:grid-cols-2">
          {extraFields(method).map((f) => {
            const value = extra[f.key] ?? '';
            const common = {
              label: f.label,
              required: f.required,
              placeholder: f.placeholder || undefined,
            };
            if (f.type === 'select') {
              return (
                <Select
                  key={f.key}
                  {...common}
                  value={value}
                  onChange={(e) => setExtraValue(f.key, e.target.value)}
                >
                  <option value="">Elegí una opción</option>
                  {f.options.map((option) => (
                    <option key={option} value={option}>{option}</option>
                  ))}
                </Select>
              );
            }
            if (f.type === 'textarea') {
              return (
                <div key={f.key} className="sm:col-span-2">
                  <Textarea
                    {...common}
                    className="min-h-24"
                    value={value}
                    onChange={(e) => setExtraValue(f.key, e.target.value)}
                  />
                  {f.help && <p className="mt-1.5 text-xs text-ink-faint">{f.help}</p>}
                </div>
              );
            }
            if (f.type === 'checkbox') {
              return (
                <label key={f.key} className="flex items-center gap-2.5 text-sm sm:col-span-2">
                  <input
                    type="checkbox"
                    className="size-4 rounded border-line-strong"
                    checked={value === '1'}
                    onChange={(e) => setExtraValue(f.key, e.target.checked ? '1' : '')}
                  />
                  <span>{f.label}</span>
                </label>
              );
            }
            return (
              <div key={f.key}>
                <Input
                  {...common}
                  type={f.type === 'number' ? 'text' : f.type}
                  inputMode={f.type === 'number' || f.key === 'doc' ? 'numeric' : undefined}
                  autoComplete={f.auto || undefined}
                  value={value}
                  onChange={(e) => setExtraValue(f.key, e.target.value)}
                />
                {f.help && <p className="mt-1.5 text-xs text-ink-faint">{f.help}</p>}
              </div>
            );
          })}
        </div>
      )}

      {method?.instructions && (
        <p className="mt-5 whitespace-pre-line rounded-xl2 border border-line bg-surface-2 p-4 text-sm text-ink-soft">
          {method.instructions}
        </p>
      )}

      {/* Lo que el servidor dice que falta, por si algo quedó fuera de la vista. */}
      {serverShipping?.problems?.length > 0 && (
        <ul className="mt-5 space-y-1 text-sm text-warning" aria-live="polite">
          {serverShipping.problems.map((problem) => (
            <li key={problem} className="flex items-start gap-2">
              <AlertCircle size={15} className="mt-0.5 shrink-0" />
              {problem}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
