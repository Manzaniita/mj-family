import { useEffect, useMemo, useRef, useState } from 'react';
import { Loader2, AlertCircle, MapPin } from 'lucide-react';
import { Input, Select, Textarea } from '@/components/ui/Input';
import { useCart } from '@/store';
import * as wc from '@/lib/wc';
import { IS_WP, WP } from '@/lib/config';
import { t } from '@/lib/copy';
import ShippingOptions from '@/components/checkout/ShippingOptions';
import {
  SHIPPING,
  shippingTexts,
  shippingGroups,
  cpConfig,
  provinces,
  methodBySlug,
  addressField,
  extraFields,
  branchField,
  cpLooksValid,
  debounce,
  formFields,
  rememberedQuote,
  rememberQuote,
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
/*
 * `errors` ({ campo: mensaje }) y `onTouch(campo)` vienen del checkout, que
 * valida el formulario entero: acá solo se muestran al lado de cada campo.
 */
export default function ShippingStep({ title, form, setForm, extra, setExtra, errors = {}, onTouch, attempted = false }) {
  const texts = shippingTexts();
  const cpCfg = cpConfig();
  const states = useMemo(provinces, []);
  const groups = useMemo(shippingGroups, []);

  const cartShipping = useCart((s) => s.shipping);
  const serverShipping = useCart((s) => s.server?.shipping);
  const syncAddress = useCart((s) => s.syncAddress);
  const selectRate = useCart((s) => s.selectRate);
  const syncShipping = useCart((s) => s.syncShipping);
  const syncing = useCart((s) => s.syncing);

  const rates = cartShipping?.rates ?? [];
  const selected = rates.find((r) => r.selected) ?? null;
  const method = selected?.slug ? methodBySlug(selected.slug) : null;

  /* Estado del código postal: qué sabemos del destino que cargó el cliente. */
  const [cp, setCp] = useState({ status: 'idle', cities: [], asked: '' });
  /*
   * Para qué código postal se completaron provincia y localidad. Va en una
   * referencia y no en el estado porque lo lee `lookup`, que corre con retraso:
   * leyéndolo del estado siempre vería el valor del primer render.
   */
  const filledFor = useRef('');
  const [suggestions, setSuggestions] = useState([]);
  const [branches, setBranches] = useState({ loading: false, list: [] });
  const [busy, setBusy] = useState(false);
  /* El servidor no pudo calcular este destino: hay que decirlo, no seguir mostrando lo viejo. */
  const [failed, setFailed] = useState(false);

  /* La dirección se manda una sola vez por destino: sin esto, cada tecla dispararía un cálculo. */
  const lastSent = useRef('');
  const pendingAddress = useRef('');
  const choosing = useRef(false);
  const latest = useRef({ form, setForm });
  latest.current = { form, setForm };
  const lookupSequence = useRef(0);

  const addressKey = () => [form.postcode, form.state, form.city, form.address].join('|');

  /**
   * Manda el destino al servidor y trae las tarifas que correspondan.
   *
   * El destino solo se da por enviado cuando el servidor contestó bien: si
   * falla, `lastSent` queda como estaba para que el próximo intento se mande de
   * nuevo. Marcarlo antes hacía que un destino que nunca llegó no se
   * reintentara nunca, y la tienda seguía mostrando las tarifas del anterior.
   */
  const pushAddress = async () => {
    if (!IS_WP) return;
    const key = addressKey();
    if (!cpLooksValid(form.postcode) || cp.asked !== form.postcode
      || !['ok', 'unknown'].includes(cp.status)
      || key === lastSent.current || key === pendingAddress.current) return;
    pendingAddress.current = key;
    setBusy(true);
    const cart = await syncAddress({
      postcode: form.postcode,
      state: form.state || '',
      city: form.city || '',
      address_1: form.address || '',
      country: WP?.baseCountry || SHIPPING?.country || 'AR',
    });
    if (pendingAddress.current === key) {
      pendingAddress.current = '';
      lastSent.current = cart ? key : '';
      setFailed(!cart);
      setBusy(false);
    }
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
    const sequence = ++lookupSequence.current;
    const current = () => sequence === lookupSequence.current && value === latest.current.form.postcode;
    if (!cpLooksValid(value)) {
      setCp((c) => ({ ...c, status: String(value || '').trim() === '' ? 'idle' : 'invalid', cities: [], asked: value }));
      return;
    }
    setCp((c) => ({ ...c, status: 'loading', asked: value }));
    try {
      const data = await wc.getPostcode(value);
      if (!current()) return;
      if (!data?.valido) {
        setCp((c) => ({ ...c, status: 'invalid', cities: [], asked: value }));
        return;
      }
      const cities = data.localidades ?? [];
      if (data.encontrado && cities.length) {
        latest.current.setForm((f) => ({
          ...f,
          state: data.provinciaId || cities[0].provinciaId || f.state,
          // Con una sola localidad se completa; con varias, que elija.
          city: cities.length === 1 ? cities[0].nombre : forget(f.city, value),
        }));
        filledFor.current = value;
        setCp({ status: 'ok', cities, asked: value });
      } else {
        /*
         * No sabemos qué hay en ese código postal. Lo que quedó de OTRO código
         * postal se borra: dejar "Buenos Aires · Mar del Plata" cuando el cliente
         * puso un CP de Santa Cruz es peor que no decir nada, porque se compra
         * con un destino que nadie eligió.
         */
        latest.current.setForm((f) => ({ ...f, state: forget(f.state, value), city: forget(f.city, value) }));
        filledFor.current = '';
        setCp((c) => ({ ...c, status: 'unknown', cities: [], asked: value }));
      }
    } catch {
      if (!current()) return;
      /* Sin el plugin (o sin internet) el paso sigue: se cargan provincia y localidad a mano. */
      setCp((c) => ({ ...c, status: 'unknown', cities: [], asked: value }));
    }
  };

  /* Lo que había quedado de otro código postal no vale; lo que escribió el cliente, sí. */
  function forget(value, asked) {
    return filledFor.current && filledFor.current !== asked ? '' : value;
  }

  /* Una consulta por CP, también si llega después desde la cuenta del cliente. */
  useEffect(() => {
    if (!IS_WP) return;
    const timer = setTimeout(() => lookup(form.postcode), 400);
    return () => {
      clearTimeout(timer);
      lookupSequence.current += 1;
    };
  }, [form.postcode]); // eslint-disable-line react-hooks/exhaustive-deps

  /* Con provincia y localidad resueltas, se piden las tarifas. */
  useEffect(() => {
    if (cp.status === 'ok' || cp.status === 'unknown') pushAddress();
  }, [cp.status, cp.asked, form.postcode, form.state, form.city]); // eslint-disable-line react-hooks/exhaustive-deps

  /* Sucursales del método elegido, para el código postal cargado. */
  useEffect(() => {
    if (!IS_WP || !selected?.slug || !method?.needsBranch) {
      setBranches({ loading: false, list: [] });
      return;
    }
    let alive = true;
    setBranches({ loading: true, list: [] });
    wc.getBranches(selected.slug, form.postcode, form.city, form.state)
      .then((list) => alive && setBranches({ loading: false, list: Array.isArray(list) ? list : [] }))
      .catch(() => alive && setBranches({ loading: false, list: [] }));
    return () => {
      alive = false;
    };
  }, [selected?.slug, method?.needsBranch, form.postcode, form.city, form.state]); // eslint-disable-line react-hooks/exhaustive-deps

  /*
   * Lo que el cliente escribe viaja al servidor con calma. Siempre con el contacto y la dirección:
   * MJ Envíos valida todo junto, y cada envío reemplaza lo anterior.
   */
  const pushFields = useRef(debounce((fields) => syncShipping({ fields }), 600)).current;
  useEffect(() => () => pushFields.cancel(), [pushFields]);
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
    if (!IS_WP || cp.asked !== form.postcode || !['ok', 'unknown'].includes(cp.status)) return;
    pushFields(allFields());
    return () => pushFields.cancel();
  }, [form.firstName, form.lastName, form.email, form.phone, form.postcode, form.state, form.city, form.address, form.address2]); // eslint-disable-line react-hooks/exhaustive-deps

  /** Elegir una forma de envío. */
  const chooseRate = async (rate) => {
    if (choosing.current || busy || syncing || lastSent.current !== addressKey()) return;
    choosing.current = true;
    applied.current = true;
    pushFields.cancel();
    setBusy(true);
    const cart = rate.slug
      ? await syncShipping({ method: rate.slug, fields: allFields() })
      : await selectRate(rate.rate_id);
    if (cart && rate.slug) rememberQuote({ cp: form.postcode, slug: rate.slug });
    setFailed(!cart);
    choosing.current = false;
    setBusy(false);
  };

  /*
   * El envío que el cliente dejó elegido en la ficha del producto.
   *
   * Se aplica una sola vez, cuando llegan las tarifas de su destino: ya comparó
   * allá, no tiene por qué volver a hacerlo. Si ese envío no está en la lista
   * (cambió el carrito, o el destino), queda el que eligió WooCommerce y no
   * pasa nada. Una sola vez también quiere decir que si después elige otro, no
   * se lo volvemos a cambiar.
   */
  const applied = useRef(false);
  useEffect(() => {
    if (applied.current || rates.length === 0 || busy || syncing
      || lastSent.current !== addressKey() || cp.asked !== form.postcode) return;
    applied.current = true;
    const remembered = rememberedQuote();
    const slug = remembered?.cp === form.postcode ? remembered.slug : null;
    const wanted = slug ? rates.find((r) => r.slug === slug) : null;
    if (wanted && !wanted.selected) chooseRate(wanted);
  }, [rates, busy, syncing, form.postcode, cp.asked]); // eslint-disable-line react-hooks/exhaustive-deps

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
    name: key,
    value: form[key] ?? '',
    onChange: (e) => setForm((f) => ({ ...f, [key]: e.target.value })),
    onBlur: () => onTouch?.(key),
    error: errors[key] || '',
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
            name="postcode"
            label={cpCfg.label || t('field_postcode')}
            required={cpCfg.required !== false}
            inputMode="numeric"
            autoComplete="postal-code"
            placeholder="1425"
            error={errors.postcode || (cp.status === 'invalid' ? cpCfg.invalid : '')}
            value={form.postcode ?? ''}
            onChange={(e) => {
              const value = e.target.value;
              setForm((f) => ({ ...f, postcode: value }));
            }}
            onBlur={() => onTouch?.('postcode')}
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
          <Select label={labelOf('city', t('field_locality'))} required={required('city')} {...field('city')}>
            <option value="">{t('shipping_choose_city')}</option>
            {cp.cities.map((c) => (
              <option key={`${c.nombre}-${c.provincia}`} value={c.nombre}>{c.nombre}</option>
            ))}
          </Select>
        )}

        {/* CP válido que todavía no conocemos: provincia y localidad a mano. */}
        {cp.status !== 'ok' && (
          <>
            <Select label={labelOf('state', t('field_state'))} required={required('state')} {...field('state')}>
              <option value="">{t('shipping_choose_state')}</option>
              {states.map((s) => (
                <option key={s.code} value={s.code}>{s.name}</option>
              ))}
            </Select>
            <div className="relative">
              <Input
                name="city"
                label={labelOf('city', t('field_locality'))}
                required={required('city')}
                autoComplete="address-level2"
                placeholder={t('shipping_city_placeholder')}
                error={errors.city || ''}
                value={form.city ?? ''}
                onChange={(e) => {
                  const value = e.target.value;
                  setForm((f) => ({ ...f, city: value }));
                  searchCities(value, form.state);
                }}
                onBlur={() => { onTouch?.('city'); setTimeout(() => setSuggestions([]), 150); }}
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
              label={labelOf('address_1', t('field_address_1'))}
              required={required('address_1')}
              placeholder={t('shipping_address_placeholder')}
              className="sm:col-span-2"
              autoComplete="street-address"
              {...field('address')}
              onBlur={() => { onTouch?.('address'); pushAddress(); }}
            />
            {(!method || addressField(method, 'address_2')) && (
              <Input
                label={labelOf('address_2', t('field_address_2'))}
                required={required('address_2', false)}
                placeholder={t('shipping_address_2_placeholder')}
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
          {t('shipping_methods_title')}
        </p>

        {!cpLooksValid(form.postcode) ? (
          <p className="mt-3 text-sm text-ink-soft">{cpCfg.ask}</p>
        ) : busy && rates.length === 0 ? (
          <p className="mt-3 flex items-center gap-2 text-sm text-ink-soft">
            <Loader2 size={14} className="animate-spin" /> {t('shipping_calculating')}
          </p>
        ) : rates.length === 0 ? (
          <div className="mt-3 flex items-start gap-3 rounded-xl2 border border-warning/30 bg-warning/10 p-4 text-sm text-warning">
            <AlertCircle size={17} className="mt-0.5 shrink-0" />
            <p>{texts.none}</p>
          </div>
        ) : (
          <fieldset disabled={busy || syncing || cp.asked !== form.postcode || cp.status === 'loading' || lastSent.current !== addressKey()} aria-busy={busy || syncing} className="min-w-0 border-0 p-0 disabled:opacity-60">
            <ShippingOptions rates={rates} groups={groups} texts={texts} onChoose={chooseRate} />
          </fieldset>
        )}
      </div>

      {/* 3 · Lo que pide el método elegido */}
      {method?.needsBranch && (
        <div className="mt-7 border-t border-line pt-6">
          <Select
            label={branchField(method)?.label || texts.branch || t('shipping_branch')}
            required={branchField(method)?.required !== false}
            value={extra.branch ?? ''}
            onChange={(e) => setExtraValue('branch', e.target.value)}
            disabled={branches.loading}
          >
            <option value="">{branches.loading ? t('shipping_branches_loading') : t('shipping_choose_branch')}</option>
            {branches.list.map((b) => (
              <option key={b.codigo} value={b.codigo}>
                {[
                  [b.nombre, b.direccion, b.localidad].filter(Boolean).join(' · '),
                  b.distancia ? `(a ${b.distancia} km)` : '',
                ]
                  .filter(Boolean)
                  .join(' ')}
              </option>
            ))}
          </Select>
          <p className="mt-1.5 text-xs text-ink-faint">
            {branches.list.length === 0 && !branches.loading
              ? t('shipping_no_branches')
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
                  <option value="">{t('shipping_choose_option')}</option>
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

      {failed && (
        <div className="mt-3 flex items-start gap-3 rounded-xl2 border border-warning/30 bg-warning/10 p-4 text-sm text-warning">
          <AlertCircle size={17} className="mt-0.5 shrink-0" />
          <p>{texts.error}</p>
        </div>
      )}

      {/* Por qué un correo no pudo cotizar: mejor decirlo que hacerlo desaparecer. */}
      {serverShipping?.notices?.length > 0 && (
        <ul className="mt-5 space-y-1 text-xs text-ink-faint" role="list">
          {serverShipping.notices.map((notice) => (
            <li key={notice}>{notice}</li>
          ))}
        </ul>
      )}

      {/*
        * Lo que el servidor dice que falta, por si algo quedó fuera de la vista.
        * Recién cuando el cliente intentó cerrar la compra: un formulario que
        * grita "completá esto" antes de empezar a escribir molesta.
        */}
      {attempted && serverShipping?.problems?.length > 0 && (
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
