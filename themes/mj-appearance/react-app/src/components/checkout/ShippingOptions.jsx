import { Check, CreditCard, Truck } from 'lucide-react';
import { cn, formatPrice } from '@/lib/format';
import { t, tn } from '@/lib/copy';

/**
 * La lista de formas de envío del checkout, en tres niveles.
 *
 *   1. Cómo querés recibir la compra: retiro en el local, envío a domicilio, y
 *      los grupos que arme el dueño de la tienda ("Envío a todo el país").
 *   2. Al elegir un grupo, una tarjeta por correo: Andreani, Correo Argentino,
 *      el que esté configurado.
 *   3. Adentro de cada tarjeta, los modos que ofrece ESE correo —a domicilio, a
 *      sucursal, rápido, lento— con su plazo y su precio.
 *
 * Así el cliente compara primero entre correos y después entre modos, que es
 * como lo piensa. Qué grupos hay, qué correos y qué modos lo decide MJ Envíos:
 * acá no hay ningún nombre escrito.
 */

/** El precio de una tarifa, como se lee. */
function Price({ rate, texts }) {
  if (rate.coordinate) return <span className="text-ink-soft">{t('cart_shipping_coordinate')}</span>;
  if (rate.cost > 0) return <>{formatPrice(rate.cost)}</>;
  return <span className="text-success">{texts.free || t('cart_free')}</span>;
}

/**
 * Con qué se puede pagar este envío, cuando no es con cualquier cosa.
 *
 * El envío se muestra igual aunque el cliente haya elegido otro medio: al
 * elegirlo, el paso de pago se queda solo con los que acepta. Decirlo acá es lo
 * que evita que parezca que la opción "no se deja marcar". El texto sale de MJ
 * Envíos, que es el que sabe qué medios son.
 */
export function PayTag({ rate }) {
  const tag = rate.payments?.tag;
  if (!tag) return null;
  return (
    <span className="mt-0.5 flex items-center gap-1 text-xs text-ink-soft">
      <CreditCard size={12} className="shrink-0" aria-hidden="true" />
      {tag}
    </span>
  );
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

export function Radio({ on }) {
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

/**
 * El cuerpo de una opción: nombre, carteles, descripción y plazo.
 *
 * `short` es el modo sin el nombre del correo ("A sucursal (clásico)"): se usa
 * adentro de la tarjeta del correo, donde repetirlo quedaría mal.
 */
function OptionBody({ rate, short }) {
  return (
    <span className="min-w-0">
      <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span className="text-sm font-medium">{(short && rate.short) || rate.name}</span>
        <Badges rate={rate} />
      </span>
      {rate.description && <span className="block text-xs text-ink-faint">{rate.description}</span>}
      {rate.eta && <span className="block text-xs text-ink-faint">{rate.eta}</span>}
      {rate.note && <span className="block text-xs text-ink-faint">{rate.note}</span>}
      <PayTag rate={rate} />
    </span>
  );
}

/** Una forma de envío suelta: la fila de siempre, al nivel de arriba. */
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
        <OptionBody rate={rate} />
      </span>
      <span className="shrink-0 text-sm font-medium">
        <Price rate={rate} texts={texts} />
      </span>
    </button>
  );
}

/** Un modo de entrega adentro de la tarjeta de un correo. */
function OptionRow({ rate, texts, onChoose }) {
  return (
    <button
      type="button"
      onClick={() => onChoose(rate)}
      className={cn(
        'mj-choice flex w-full items-start justify-between gap-3 rounded-xl2 border p-3 text-left transition-all duration-200',
        rate.selected ? 'border-accent bg-accent-soft' : 'border-transparent hover:border-line'
      )}
    >
      <span className="flex min-w-0 items-start gap-3">
        <Radio on={rate.selected} />
        <OptionBody rate={rate} short />
      </span>
      <span className="shrink-0 text-sm font-medium">
        <Price rate={rate} texts={texts} />
      </span>
    </button>
  );
}

/**
 * La tarjeta de un correo, con todos sus modos adentro.
 *
 * Sin correo (un método propio que el dueño metió en el grupo) la tarjeta va sin
 * encabezado: no hay a quién nombrar.
 */
function CarrierCard({ carrier, rates, texts, onChoose }) {
  return (
    <div
      className={cn(
        'mj-carrier rounded-xl2 border bg-surface p-2',
        rates.some((r) => r.selected) ? 'border-accent/60' : 'border-line'
      )}
    >
      {carrier && (
        <p className="flex items-center gap-1.5 px-2 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-[0.12em] text-ink-soft">
          <Truck size={13} aria-hidden="true" />
          {carrier}
        </p>
      )}
      <div className="space-y-1">
        {rates.map((rate) => (
          <OptionRow key={rate.rate_id} rate={rate} texts={texts} onChoose={onChoose} />
        ))}
      </div>
    </div>
  );
}

/** El precio más bajo de un grupo, para anunciarlo antes de abrirlo. */
function cheapestOf(rates) {
  const payable = rates.filter((r) => !r.coordinate);
  if (!payable.length) return null;
  return payable.reduce((best, r) => (r.cost < best.cost ? r : best), payable[0]);
}

/** Cada correo con sus modos, en el orden en que llegaron las tarifas. */
export function byCarrier(rates) {
  const buckets = [];
  const index = new Map();
  rates.forEach((rate) => {
    const key = rate.carrierKey || '';
    if (!index.has(key)) {
      index.set(key, { key, carrier: rate.carrier || '', rates: [] });
      buckets.push(index.get(key));
    }
    index.get(key).rates.push(rate);
  });
  return buckets;
}

/**
 * Un grupo cerrado se lee como una opción más; abierto, muestra sus correos.
 *
 * Dos atajos, para que un grupo chico no cueste tres clics:
 *   · un solo correo con un solo modo → el grupo ES la opción, se elige directo;
 *   · un solo correo con varios modos → los modos cuelgan del grupo, sin repetir
 *     el encabezado del correo (su nombre ya se lee en el renglón de arriba).
 */
function GroupRow({ group, rates, texts, onChoose }) {
  const open = rates.some((r) => r.selected);
  const cheapest = cheapestOf(rates);
  const carriers = byCarrier(rates);
  const named = carriers.filter((c) => c.carrier);
  const soloCorreo = carriers.length === 1;

  /* Una sola opción adentro: no hay nada que comparar, se elige el grupo. */
  if (soloCorreo && rates.length === 1) {
    const rate = rates[0];
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
          <span className="min-w-0">
            <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
              <span className="text-sm font-medium">
                {group.icon ? `${group.icon} ` : ''}
                {group.title}
              </span>
              <Badges rate={rate} />
            </span>
            <span className="block text-xs text-ink-faint">
              {[rate.carrier, rate.short || rate.name].filter(Boolean).join(' · ')}
            </span>
            {rate.eta && <span className="block text-xs text-ink-faint">{rate.eta}</span>}
            {rate.note && <span className="block text-xs text-ink-faint">{rate.note}</span>}
            <PayTag rate={rate} />
          </span>
        </span>
        <span className="shrink-0 text-sm font-medium">
          <Price rate={rate} texts={texts} />
        </span>
      </button>
    );
  }

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
            {/* Con quién se puede mandar dice más que cuántas opciones hay. */}
            <span className="block text-xs text-ink-faint">
              {named.length === carriers.length && named.length > 0
                ? named.map((c) => c.carrier).join(' · ')
                : tn('shipping_group', rates.length)}
            </span>
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
        <div className="space-y-2 border-t border-line bg-bg-soft p-3">
          {carriers.map((bucket) => (
            <CarrierCard
              key={bucket.key || 'propio'}
              carrier={soloCorreo ? '' : bucket.carrier}
              rates={bucket.rates}
              texts={texts}
              onChoose={onChoose}
            />
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
