import { useEffect, useState } from 'react';
import { Loader2, Truck } from 'lucide-react';
import * as wc from '@/lib/wc';
import { formatPrice, cn } from '@/lib/format';
import { IS_WP } from '@/lib/config';
import { quoteConfig, shippingTexts, cpConfig, cpLooksValid } from '@/lib/envios';
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
 * Cuánto sale el envío de este producto.
 *
 * Pregunta solo el código postal y muestra las mismas opciones que el checkout,
 * con el mismo precio: sale todo de MJ Envíos (/mje/v1/cotizar), incluidos los
 * correos conectados por su propio plugin. Acá no hay ni un correo ni un precio
 * escrito, y los textos salen del panel.
 */
export function ShippingPanel({ product }) {
  const config = quoteConfig();
  const texts = shippingTexts();
  const cpCfg = cpConfig();

  const [cp, setCp] = useState(rememberedCp);
  const [state, setState] = useState({ status: 'idle', options: [], error: '' });

  /* Con un código postal ya guardado, la primera respuesta llega sin pedir nada. */
  useEffect(() => {
    if (IS_WP && config.enabled && cpLooksValid(cp)) ask(cp);
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  if (!IS_WP || !config.enabled || !product) return null;

  async function ask(value) {
    if (!cpLooksValid(value)) {
      setState({ status: 'error', options: [], error: cpCfg.invalid || '' });
      return;
    }
    setState((s) => ({ ...s, status: 'loading', error: '' }));
    try {
      const data = await wc.quoteShipping({
        cp: value,
        producto: product.id,
        cantidad: 1,
      });
      const options = Array.isArray(data?.opciones) ? data.opciones : [];
      rememberCp(value);
      setState({
        status: 'done',
        options,
        error: options.length ? '' : data?.mensaje || texts.none || '',
      });
    } catch {
      setState({ status: 'error', options: [], error: texts.error || '' });
    }
  }

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

      {state.options.length > 0 && (
        <ul className="mt-3 space-y-1.5" role="list">
          {state.options.map((option) => (
            <li key={option.metodo} className="flex items-baseline justify-between gap-3 text-sm">
              <span className="min-w-0">
                <span className="block truncate">{option.titulo}</span>
                {option.plazo && <span className="block text-xs text-ink-faint">{option.plazo}</span>}
              </span>
              <span className={cn('shrink-0 font-medium', option.gratis && 'text-success')}>
                {option.aCoordinar ? <span className="text-ink-soft">{option.formateado}</span> : null}
                {!option.aCoordinar && (option.gratis ? texts.free || t('cart_free') : formatPrice(option.costo))}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
