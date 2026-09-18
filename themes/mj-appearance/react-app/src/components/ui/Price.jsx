import { formatPrice, formatUsd, cn } from '@/lib/format';
import { EXCHANGE_RATE, WP } from '@/lib/config';
import { PAY, useSelectedMethod, priceFor } from '@/lib/payments';

/**
 * Precio del producto.
 *  - Plugin MJ Medios de Pago: muestra el precio con el método elegido por el
 *    visitante (o el que se pasa en `method`), el base tachado si hay descuento
 *    y una leyenda "con Transferencia · 10% OFF".
 *  - Plugin MJ Dólar: USD debajo según el ajuste (solo ficha, en todos lados u
 *    oculto). `showUsd` fuerza el comportamiento.
 * Sin plugins, es el precio de siempre.
 */
export function Price({ product, size = 'md', className, showUsd, method }) {
  const selected = useSelectedMethod();
  const m = method === undefined ? selected : method;
  const sale = product.sale_price != null;
  const { price, base, factor, applied } = priceFor(product, m);

  // Tachado: el precio regular si hay oferta; si no, el base cuando el método descuenta.
  const strike = sale
    ? product.regular_price
    : applied && price < base && PAY?.showListPrice !== false ? base : null;

  const usdMode = EXCHANGE_RATE?.showUsdPrice || 'off';
  const usdBase = sale ? (product.usd_sale ?? product.usd) : product.usd;
  const usd = usdBase != null ? usdBase * factor : null;
  const withUsd = usd != null && (showUsd ?? (usdMode === 'all' || (usdMode === 'product' && size === 'lg')));

  const caption = applied && m
    ? `con ${m.short || m.title}${m.kind === 'discount' ? ` · ${m.value}% OFF` : ` · +${m.value}%`}`
    : '';

  const sizes = {
    sm: 'text-sm',
    md: 'text-lg',
    lg: 'text-3xl',
  };
  return (
    <div className={cn('mj-price flex flex-wrap items-baseline gap-x-2', className)}>
      <span className={cn('mj-price__value font-display font-semibold tracking-tight', sizes[size])}>
        {formatPrice(price)}
      </span>
      {strike != null && strike > price && (
        <span className="mj-price__old text-sm text-ink-faint line-through">
          {formatPrice(strike)}
        </span>
      )}
      {caption && (
        <span className={cn('mj-price__method basis-full font-medium text-ink-soft', size === 'lg' ? 'mt-0.5 text-xs' : 'text-[11px]')}>
          {caption}
        </span>
      )}
      {withUsd && (
        <span
          className={cn(
            'mj-price__usd basis-full font-medium tabular-nums text-ink-faint',
            size === 'lg' ? 'mt-1 text-sm' : 'text-[11px]'
          )}
        >
          {formatUsd(usd)}
        </span>
      )}
      {/* Plugin MJ ERP: un revendedor con sesión ve el precio de su lista, y se le avisa (window.MJ.resellerPriceNotice). */}
      {size === 'lg' && WP?.resellerPriceNotice && (
        <span className="mj-price__notice basis-full mt-1 text-xs font-medium text-accent">
          {WP.resellerPriceNotice}
        </span>
      )}
    </div>
  );
}
