import { Check } from 'lucide-react';
import { cn, formatPrice } from '@/lib/format';
import { t, tn } from '@/lib/copy';

/**
 * La lista de formas de envío del checkout, en dos niveles.
 *
 * Arriba, cómo querés recibir la compra: retiro en el local, envío a domicilio,
 * y los grupos que arme el dueño de la tienda ("Envío a todo el país"). Cuando
 * el cliente elige un grupo, se abren adentro las tarjetas de quién lo lleva
 * —Andreani, Correo Argentino, el que esté configurado— con su modo de entrega,
 * el plazo y el precio, para que elija la que más le convenga.
 *
 * Qué grupos hay, cómo se llaman y qué cae adentro lo decide MJ Envíos: acá no
 * hay ningún correo ni ningún nombre escrito.
 */

/** El precio de una tarifa, como se lee. */
function Price({ rate, texts }) {
  if (rate.coordinate) return <span className="text-ink-soft">{t('cart_shipping_coordinate')}</span>;
  if (rate.cost > 0) return <>{formatPrice(rate.cost)}</>;
  return <span className="text-success">{texts.free || t('cart_free')}</span>;
}

function Badges({ rate }) {
  if (!rate.badges?.length) return null;
  return (
    <>
      {rate.badges.map((badge) => (
        <span
          key={badge}
          className="rounded-full bg-success/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-success"
        >
          {badge}
        </span>
      ))}
    </>
  );
}

function Radio({ on }) {
  return (
    <span
      className={cn(
        'mt-0.5 grid size-5 shrink-0 place-items-center rounded-full border-2 transition-colors',
        on ? 'border-accent bg-accent' : 'border-line-strong'
      )}
    >
      {on && <Check size={11} className="text-white" strokeWidth={3} />}
    </span>
  );
}

/** Una forma de envío suelta: la fila de siempre. */
function RateRow({ rate, texts, onChoose }) {
  return (
    <button
      type="button"
      onClick={() => onChoose(rate)}
      className={cn(
        'mj-choice flex w-full items-center justify-between gap-3 rounded-xl2 border p-4 text-left transition-all duration-200',
        rate.selected ? 'border-accent bg-accent-soft' : 'border-line hover:border-line-strong'
      )}
    >
      <span className="flex items-start gap-3">
        <Radio on={rate.selected} />
        <span>
          <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span className="text-sm font-medium">{rate.name}</span>
            <Badges rate={rate} />
          </span>
          {rate.description && <span className="block text-xs text-ink-faint">{rate.description}</span>}
          {rate.eta && <span className="block text-xs text-ink-faint">{rate.eta}</span>}
          {rate.note && <span className="block text-xs text-ink-faint">{rate.note}</span>}
        </span>
      </span>
      <span className="shrink-0 text-sm font-medium">
        <Price rate={rate} texts={texts} />
      </span>
    </button>
  );
}

/**
 * Una tarjeta de adentro de un grupo: quién lo lleva y con qué modo.
 *
 * El correo va arriba y en chico, y el modo de entrega es el título: es lo que
 * el cliente compara cuando ya decidió que el paquete viaja.
 */
function CarrierCard({ rate, texts, onChoose }) {
  return (
    <button
      type="button"
      onClick={() => onChoose(rate)}
      className={cn(
        'mj-choice flex w-full items-start justify-between gap-3 rounded-xl2 border p-3 text-left transition-all duration-200',
        rate.selected ? 'border-accent bg-accent-soft' : 'border-line bg-surface hover:border-line-strong'
      )}
    >
      <span className="flex min-w-0 items-start gap-3">
        <Radio on={rate.selected} />
        <span className="min-w-0">
          {rate.carrier && (
            <span className="block text-[11px] font-semibold uppercase tracking-[0.1em] text-ink-faint">
              {rate.carrier}
            </span>
          )}
          <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span className="text-sm font-medium">{rate.name}</span>
            <Badges rate={rate} />
          </span>
          {rate.description && <span className="block text-xs text-ink-faint">{rate.description}</span>}
          {rate.eta && <span className="block text-xs text-ink-faint">{rate.eta}</span>}
          {rate.note && <span className="block text-xs text-ink-faint">{rate.note}</span>}
        </span>
      </span>
      <span className="shrink-0 text-sm font-medium">
        <Price rate={rate} texts={texts} />
      </span>
    </button>
  );
}

/** El precio más bajo de un grupo, para anunciarlo antes de abrirlo. */
function cheapestOf(rates) {
  const payable = rates.filter((r) => !r.coordinate);
  if (!payable.length) return null;
  return payable.reduce((best, r) => (r.cost < best.cost ? r : best), payable[0]);
}

/** Un grupo cerrado se lee como una opción más; abierto, muestra sus tarjetas. */
function GroupRow({ group, rates, texts, onChoose }) {
  const open = rates.some((r) => r.selected);
  const cheapest = cheapestOf(rates);

  return (
    <div
      className={cn(
        'mj-choice-group rounded-xl2 border transition-all duration-200',
        open ? 'border-accent' : 'border-line'
      )}
    >
      <button
        type="button"
        onClick={() => onChoose(cheapest ?? rates[0])}
        className={cn(
          'flex w-full items-center justify-between gap-3 p-4 text-left',
          open ? 'rounded-t-xl2 bg-accent-soft' : 'rounded-xl2 hover:bg-surface-2'
        )}
      >
        <span className="flex items-start gap-3">
          <Radio on={open} />
          <span>
            <span className="block text-sm font-medium">
              {group.icon ? `${group.icon} ` : ''}
              {group.title}
            </span>
            {group.description && <span className="block text-xs text-ink-faint">{group.description}</span>}
            <span className="block text-xs text-ink-faint">{tn('shipping_group', rates.length)}</span>
          </span>
        </span>
        {!open && cheapest && (
          <span className="shrink-0 text-sm font-medium">
            {cheapest.cost > 0 ? (
              <>
                <span className="text-xs font-normal text-ink-faint">{t('shipping_group_from')} </span>
                {formatPrice(cheapest.cost)}
              </>
            ) : (
              <span className="text-success">{texts.free || t('cart_free')}</span>
            )}
          </span>
        )}
      </button>

      {open && (
        <div className="space-y-2 border-t border-line p-3">
          {rates.map((rate) => (
            <CarrierCard key={rate.rate_id} rate={rate} texts={texts} onChoose={onChoose} />
          ))}
        </div>
      )}
    </div>
  );
}

/**
 * Arma el árbol: lo suelto queda como está, lo agrupado cae en su grupo.
 *
 * El orden lo marcan las tarifas, que ya vienen ordenadas por el servidor: un
 * grupo aparece donde aparece su primera tarifa. Un grupo sin tarifas que
 * mostrar no se dibuja, y una tarifa cuyo grupo no existe (lo borraron) vuelve
 * a verse suelta en vez de desaparecer.
 */
export function buildEntries(rates, groups) {
  const bySlug = new Map((groups ?? []).map((g) => [g.slug, g]));
  const entries = [];
  const seen = new Map();

  rates.forEach((rate) => {
    const group = rate.group ? bySlug.get(rate.group) : null;
    if (!group) {
      entries.push({ kind: 'rate', key: rate.rate_id, rate });
      return;
    }
    if (!seen.has(group.slug)) {
      const entry = { kind: 'group', key: `g:${group.slug}`, group, rates: [] };
      seen.set(group.slug, entry);
      entries.push(entry);
    }
    seen.get(group.slug).rates.push(rate);
  });

  return entries;
}

export default function ShippingOptions({ rates, groups, texts, onChoose }) {
  const entries = buildEntries(rates, groups);

  return (
    <div className="mt-3 space-y-2">
      {entries.map((entry) =>
        entry.kind === 'rate' ? (
          <RateRow key={entry.key} rate={entry.rate} texts={texts} onChoose={onChoose} />
        ) : (
          <GroupRow key={entry.key} group={entry.group} rates={entry.rates} texts={texts} onChoose={onChoose} />
        )
      )}
    </div>
  );
}
