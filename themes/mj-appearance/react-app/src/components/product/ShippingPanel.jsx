import { useEffect, useState } from 'react';
import { Loader2, Truck } from 'lucide-react';
import * as wc from '@/lib/wc';
import { formatPrice, cn } from '@/lib/format';
import { IS_WP } from '@/lib/config';
import { quoteConfig, shippingTexts, cpConfig, cpLooksValid } from '@/lib/envios';
import { buildEntries, byCarrier } from '@/components/checkout/ShippingOptions';
import { t } from '@/lib/copy';

/* El último código postal consultado, para no volver a escribirlo en cada producto. */
const CP_KEY = 'mj_cp';

const rememberCp = (value) => {
  try {
    window.localStorage.setItem(CP_KEY, value);
  } catch {
    /* Navegación privada o almacenamiento bloqueado: el cotizador anda igual. */
  }
};

const rememberedCp = () => {
  try {
    return window.localStorage.getItem(CP_KEY) || '';
  } catch {
    return '';
  }
};

/**
 * Las opciones que devuelve /mje/v1/cotizar, con la forma que usa el checkout.
 *
 * Así el cotizador de la ficha y el paso de envío arman el mismo árbol (grupo →
 * correo → modo) con el mismo código, y no se pueden ir desincronizando.
 */
function asRates(opciones) {
  return (opciones ?? []).map((o) => ({
    rate_id: o.metodo,
    name: o.titulo,
    cost: o.costo,
    coordinate: !!o.aCoordinar,
    eta: o.plazo || '',
    note: o.aCoordinar ? o.formateado : '',
    group: o.grupo || '',
    carrier: o.correo || '',
    carrierKey: o.correoClave || '',
    badges: [],
    selected: false,
  }));
}

/** El precio de una opción, como se lee. */
function Price({ rate, texts }) {
  if (rate.coordinate) return <span className="text-ink-soft">{t('cart_shipping_coordinate')}</span>;
  if (rate.cost > 0) return <span className="font-medium">{formatPrice(rate.cost)}</span>;
  return <span className="font-medium text-success">{texts.free || t('cart_free')}</span>;
}

/** Una opción: el modo, su plazo y su precio. */
function Option({ rate, texts, inset }) {
  return (
    <li className={cn('flex items-baseline justify-between gap-3 text-sm', inset && 'pl-3')}>
      <span className="min-w-0">
        <span className="block">{rate.name}</span>
        {rate.eta && <span className="block text-xs text-ink-faint">{rate.eta}</span>}
      </span>
      <span className="shrink-0 text-right">
        <Price rate={rate} texts={texts} />
      </span>
    </li>
  );
}

/**
 * Cuánto sale el envío de este producto.
 *
 * Pregunta solo el código postal y muestra lo mismo que el checkout, con los
 * mismos precios y la misma estructura: cómo puede recibirlo, y adentro de cada
 * grupo, qué correo lo lleva y con qué modo. Sale todo de MJ Envíos: acá no hay
 * ni un correo ni un precio escrito, y los textos salen del panel.
 */
export function ShippingPanel({ product }) {
  const config = quoteConfig();
  const texts = shippingTexts();
  const cpCfg = cpConfig();

  const [cp, setCp] = useState(rememberedCp);
  const [state, setState] = useState({ status: 'idle', rates: [], groups: [], notices: [], error: '' });

  /* Con un código postal ya guardado, la primera respuesta llega sin pedir nada. */
  useEffect(() => {
    if (IS_WP && config.enabled && cpLooksValid(cp)) ask(cp);
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  if (!IS_WP || !config.enabled || !product) return null;

  async function ask(value) {
    if (!cpLooksValid(value)) {
      setState({ status: 'error', rates: [], groups: [], notices: [], error: cpCfg.invalid || '' });
      return;
    }
    setState((s) => ({ ...s, status: 'loading', error: '' }));
    try {
      const data = await wc.quoteShipping({ cp: value, producto: product.id, cantidad: 1 });
      const rates = asRates(data?.opciones);
      rememberCp(value);
      setState({
        status: 'done',
        rates,
        groups: Array.isArray(data?.grupos) ? data.grupos : [],
        notices: Array.isArray(data?.avisos) ? data.avisos : [],
        error: rates.length ? '' : data?.mensaje || texts.none || '',
      });
    } catch {
      setState({ status: 'error', rates: [], groups: [], notices: [], error: texts.error || '' });
    }
  }

  const entries = buildEntries(state.rates, state.groups);

  return (
    <div className="mj-shippanel mt-5 border-t border-line pt-4">
      <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-[0.15em] text-ink-faint">
        <Truck size={13} aria-hidden="true" />
        {config.title}
      </p>

      <form
        className="flex gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          ask(cp);
        }}
      >
        <input
          type="text"
          inputMode="numeric"
          value={cp}
          onChange={(e) => setCp(e.target.value)}
          placeholder={cpCfg.label || ''}
          aria-label={cpCfg.label || ''}
          className="w-32 rounded-xl2 border border-line bg-surface px-3 py-2 text-sm outline-none focus:border-accent"
        />
        <button
          type="submit"
          className="rounded-xl2 border border-line px-3 py-2 text-sm font-medium transition-colors hover:border-line-strong"
          disabled={state.status === 'loading'}
        >
          {state.status === 'loading' ? <Loader2 size={15} className="animate-spin" /> : config.button}
        </button>
      </form>

      {config.help && state.status === 'idle' && <p className="mt-1.5 text-xs text-ink-faint">{config.help}</p>}
      {state.error && <p className="mt-2 text-xs text-warning">{state.error}</p>}

      {entries.length > 0 && (
        <div className="mt-3 space-y-3">
          {entries.map((entry) =>
            entry.kind === 'rate' ? (
              <ul key={entry.key} className="space-y-1.5" role="list">
                <Option rate={entry.rate} texts={texts} />
              </ul>
            ) : (
              <div key={entry.key}>
                <p className="mb-1 text-sm font-medium">
                  {entry.group.icon ? `${entry.group.icon} ` : ''}
                  {entry.group.title}
                </p>
                <div className="space-y-2">
                  {byCarrier(entry.rates).map((bucket) => (
                    <div key={bucket.key || 'propio'}>
                      {bucket.carrier && (
                        <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-ink-soft">
                          {bucket.carrier}
                        </p>
                      )}
                      <ul className="space-y-1.5" role="list">
                        {bucket.rates.map((rate) => (
                          <Option key={rate.rate_id} rate={rate} texts={texts} inset={!!bucket.carrier} />
                        ))}
                      </ul>
                    </div>
                  ))}
                </div>
              </div>
            )
          )}
        </div>
      )}

      {/* Por qué un correo no pudo cotizar: mejor decirlo que hacerlo desaparecer. */}
      {state.notices.length > 0 && (
        <ul className="mt-2 space-y-1 text-xs text-ink-faint" role="list">
          {state.notices.map((notice) => (
            <li key={notice}>{notice}</li>
          ))}
        </ul>
      )}
    </div>
  );
}
