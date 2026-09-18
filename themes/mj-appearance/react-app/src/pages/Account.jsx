import { useCallback, useEffect, useState } from 'react';
import { Link, Navigate, NavLink, Outlet, useNavigate, useOutletContext, useParams } from 'react-router-dom';
import { LayoutDashboard, Package, MapPin, Heart, Download, Settings, LogOut, Loader2, AlertCircle, CheckCircle2, ArrowLeft, RotateCcw, MessageCircle, XCircle, Plus, ChevronRight, ExternalLink, CalendarDays, Wallet, Check, Gift } from 'lucide-react';
import { Section } from '@/components/layout/Layout';
import { Button } from '@/components/ui/Button';
import { Input, Select } from '@/components/ui/Input';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { ReceiptUploader } from '@/components/ui/ReceiptUploader';
import { PaymentDetails } from '@/components/ui/PaymentDetails';
import { Reveal } from '@/components/motion/Reveal';
import { useAuth, useWishlist, useCatalog, useCart, useUI } from '@/store';
import { ProductGrid } from '@/components/product/ProductGrid';
import * as wc from '@/lib/wc';
import { whatsappUrl } from '@/lib/consult';
import { IS_WP, SITE, CONTACT, WHATSAPP_NUMBERS, ACCOUNT_LINKS } from '@/lib/config';
import { formatPrice, formatUsd, isValidCuit, cn } from '@/lib/format';
import { fieldLabel } from '@/lib/envios';
import { usePageMeta } from '@/lib/seo';
import { t as tCopy, tf, tn, fill } from '@/lib/copy';
import {
  TURNOS, tcopy as turnosCopy, accountsEnabled, bookingPath, statusPath, statusLabel as turnoStatusLabel,
  formatDateLong, getMyBookings,
} from '@/lib/turnos';

const nav = [
  { to: '/cuenta', end: true, icon: LayoutDashboard, label: tCopy('account_nav_summary') },
  { to: '/cuenta/pedidos', icon: Package, label: tCopy('account_nav_orders') },
  /* "Mis turnos" (plugin MJ Turnos Online): solo con las cuentas de clientas prendidas. */
  ...(accountsEnabled() ? [{ to: '/cuenta/turnos', icon: CalendarDays, label: turnosCopy('my_bookings_title') }] : []),
  { to: '/cuenta/direcciones', icon: MapPin, label: tCopy('account_nav_addresses') },
  { to: '/cuenta/favoritos', icon: Heart, label: tCopy('account_nav_favorites') },
  { to: '/cuenta/descargas', icon: Download, label: tCopy('account_nav_downloads') },
  { to: '/cuenta/configuracion', icon: Settings, label: tCopy('account_nav_settings') },
];

/** Cuenta vacía para standalone (dev sin WordPress) — sin datos personales ficticios. */
const EMPTY_ACCOUNT = {
  user: { name: tCopy('account_guest') },
  orders: [],
  addresses: { billing: {}, shipping: {} },
  downloads: [],
};

const dateFmt = new Intl.DateTimeFormat(SITE?.locale || 'es-AR', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
});

const formatDate = (iso) => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '' : dateFmt.format(d);
};

const statusTone = (status) =>
  ({
    completed: 'success',
    processing: 'solid',
    'on-hold': 'neutral',
    pending: 'neutral',
    cancelled: 'danger',
    failed: 'danger',
    refunded: 'danger',
  })[status] ?? 'neutral';

function Spinner() {
  return (
    <div className="grid min-h-[40vh] place-items-center">
      <Loader2 size={30} className="animate-spin text-accent" />
    </div>
  );
}

function FormMessage({ msg }) {
  if (!msg) return null;
  return (
    <p className={cn('flex items-center gap-2 text-sm', msg.ok ? 'text-success' : 'text-danger')}>
      {msg.ok ? <CheckCircle2 size={15} className="shrink-0" /> : <AlertCircle size={15} className="shrink-0" />}
      {msg.text}
    </p>
  );
}

export function AccountLayout() {
  usePageMeta(tCopy('seo_title_account'));
  const { user, loading, logout } = useAuth();
  const navigate = useNavigate();
  const [account, setAccount] = useState(IS_WP ? null : EMPTY_ACCOUNT);
  const [fetching, setFetching] = useState(IS_WP);

  const refresh = useCallback(async () => {
    if (!IS_WP) return;
    try {
      setAccount(await wc.getAccount());
    } catch (e) {
      if (e?.status === 401) {
        navigate('/login', { replace: true });
        return;
      }
      setAccount((prev) => prev ?? EMPTY_ACCOUNT);
    } finally {
      setFetching(false);
    }
  }, [navigate]);

  useEffect(() => {
    if (IS_WP && user) refresh();
  }, [user, refresh]);

  if (loading) {
    return (
      <Section className="grid min-h-[70vh] place-items-center">
        <Loader2 size={32} className="animate-spin text-accent" />
      </Section>
    );
  }
  if (IS_WP && !user) return <Navigate to="/login" replace />;

  const handleLogout = async () => {
    await logout();
    navigate('/');
  };

  const name = user?.name || account?.user?.name || tCopy('account_guest');

  return (
    <Section className="md:py-16">
      <Reveal>
        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-accent">{tCopy('account_eyebrow')}</p>
        <h1 className="display mt-3 text-4xl md:text-5xl">{tf('account_greeting', { nombre: name })}</h1>
      </Reveal>
      <div className="mt-10 grid gap-8 lg:grid-cols-[260px_1fr]">
        <aside>
          <nav className="sticky top-44 rounded-xl3 border border-line glass p-3">
            {nav.map(({ to, end, icon: Icon, label }) => (
              <NavLink
                key={to}
                to={to}
                end={end}
                className={({ isActive }) =>
                  cn(
                    'flex items-center gap-3 rounded-xl2 px-4 py-3 text-sm font-medium transition-colors',
                    isActive ? 'bg-accent-soft text-accent' : 'text-ink-soft hover:bg-surface hover:text-ink'
                  )
                }
              >
                <Icon size={17} strokeWidth={1.8} /> {label}
              </NavLink>
            ))}
            {ACCOUNT_LINKS.map((link) => (
              <a
                key={link.key}
                href={link.href}
                title={link.description || undefined}
                className="flex items-center gap-3 rounded-xl2 px-4 py-3 text-sm font-medium text-ink-soft transition-colors hover:bg-surface hover:text-ink"
              >
                <ExternalLink size={17} strokeWidth={1.8} /> {link.label}
              </a>
            ))}
            <button
              onClick={handleLogout}
              className="flex w-full items-center gap-3 rounded-xl2 px-4 py-3 text-sm font-medium text-danger/80 transition-colors hover:bg-danger/10 hover:text-danger"
            >
              <LogOut size={17} strokeWidth={1.8} /> {tCopy('account_logout')}
            </button>
          </nav>
        </aside>
        <div className="min-w-0">
          {fetching && !account ? (
            <Spinner />
          ) : (
            <Outlet context={{ account: account ?? EMPTY_ACCOUNT, refresh }} />
          )}
        </div>
      </div>
    </Section>
  );
}

/** Un importe en su moneda: la de la tienda como siempre, dólares como dólares. */
function moneyIn(amount, currency, shopCurrency) {
  if (!currency || currency === shopCurrency) return formatPrice(amount);
  if (currency === 'USD') return formatUsd(amount);
  return `${currency} ${amount}`;
}

/**
 * Saldo a favor (lo manda el plugin MJ ERP): cada moneda por su lado, sin convertir, y los
 * últimos movimientos. Sin plugin o sin saldo, no aparece.
 */
function CreditPanel({ credit }) {
  if (!credit) return null;
  const balances = (credit.balances || []).filter((b) => Number(b.amount) > 0.004);
  const movements = credit.movements || [];
  if (!balances.length && !movements.length) return null;
  const money = (amount, currency) => moneyIn(Number(amount) || 0, currency, credit.currency);

  return (
    <div className="mj-panel rounded-xl3 border border-line glass p-6">
      <div className="flex flex-wrap items-baseline justify-between gap-3">
        <h2 className="font-display text-lg font-semibold">{credit.label || tCopy('account_credit_title')}</h2>
        {balances.length > 0 && <p className="text-xs text-ink-faint">{tCopy('account_credit_hint')}</p>}
      </div>
      <div className="mt-4 flex flex-wrap gap-x-8 gap-y-2">
        {balances.length ? (
          balances.map((b) => (
            <p key={b.currency} className="font-display text-3xl font-semibold tracking-tight">{money(b.amount, b.currency)}</p>
          ))
        ) : (
          <p className="text-sm text-ink-soft">{tCopy('account_credit_empty')}</p>
        )}
      </div>
      {movements.length > 0 && (
        <ul className="mt-5 divide-y divide-line text-sm">
          {movements.map((m) => (
            <li key={m.id} className="flex items-center justify-between gap-4 py-3">
              <div className="min-w-0">
                <p className="truncate">{m.description}</p>
                <p className="text-xs text-ink-faint">{formatDate(m.date)}</p>
              </div>
              <p className={cn('shrink-0 font-medium', m.amount < 0 ? 'text-ink-soft' : 'text-success')}>
                {m.amount < 0 ? '- ' : '+ '}{money(Math.abs(m.amount), m.currency)}
              </p>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function AccountDashboard() {
  const { account } = useOutletContext();
  const orders = account?.orders ?? [];
  const favCount = useWishlist((s) => s.ids.length);
  const totalSpent = orders
    .filter((o) => o.status === 'completed' || o.status === 'processing')
    .reduce((n, o) => n + (Number(o.total) || 0), 0);
  const recent = orders.slice(0, 3);

  return (
    <Reveal className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-3">
        {[
          [String(orders.length), tCopy('account_nav_orders')],
          [String(favCount), tCopy('account_stat_favorites')],
          [formatPrice(totalSpent), tCopy('account_stat_spent')],
        ].map(([n, l]) => (
          <div key={l} className="mj-panel rounded-xl3 border border-line glass p-6">
            <p className="font-display text-3xl font-semibold tracking-tight">{n}</p>
            <p className="mt-1 text-sm text-ink-soft">{l}</p>
          </div>
        ))}
      </div>
      <LoyaltyCard card={account?.fidelidad} />
      <CreditPanel credit={account?.credit} />
      <div className="mj-panel rounded-xl3 border border-line glass p-6">
        <h2 className="font-display text-lg font-semibold">{tCopy('account_recent_title')}</h2>
        {recent.length === 0 ? (
          <p className="mt-4 text-sm text-ink-soft">{tCopy('account_orders_empty')}</p>
        ) : (
          recent.map((o) => <OrderRow key={o.id} order={o} />)
        )}
      </div>
    </Reveal>
  );
}

/* Estado del pago (MJ Medios de Pago, pedidos que piden comprobante) → tono de la etiqueta. */
const paymentTone = (state) =>
  ({ aprobado: 'success', parcial: 'solid', rechazado: 'danger' })[state] ?? 'neutral';

/**
 * Estado del pedido y, si el pedido pide comprobante (plugin MJ Medios de
 * Pago), el del pago al lado: "Estado: En espera · Pago: A revisar". Sin
 * `payment`, la etiqueta de siempre sola.
 */
function OrderStatus({ order }) {
  const status = <Badge tone={statusTone(order.status)}>{order.statusLabel ?? order.status}</Badge>;
  const payment = order.payment && order.payment.label ? order.payment : null;
  if (!payment) return status;
  return (
    <div className="mj-order-status flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-ink-faint">
      <span className="inline-flex items-center gap-1.5">
        {tCopy('account_status_label')}: {status}
      </span>
      <span className="mj-order-status__payment inline-flex items-center gap-1.5">
        {tCopy('account_payment_label')}: <Badge tone={paymentTone(payment.state)}>{payment.label}</Badge>
      </span>
    </div>
  );
}

/* Premio de fidelidad → tono de su estado (pendiente de canje, canjeado, pasado a saldo). */
const rewardTone = (status) => ({ pending: 'solid', credited: 'success' })[status] ?? 'neutral';

/**
 * Tarjeta de fidelidad (plugin MJ Fidelidad → `fidelidad` en GET /mj/v1/account):
 * los sellos como círculos, el progreso, el premio y los premios ganados con
 * su estado. Todos los textos vienen del plugin. Sin plugin, no aparece.
 */
function LoyaltyCard({ card }) {
  if (!card || typeof card !== 'object' || !card.enabled) return null;
  const texts = card.texts && typeof card.texts === 'object' ? card.texts : {};
  const per = Math.max(1, Math.min(50, Math.round(Number(card.perCard) || 0)));
  const current = Math.max(0, Math.min(per, Math.round(Number(card.current) || 0)));
  const total = Math.max(0, Math.round(Number(card.total) || 0));
  const rewards = (Array.isArray(card.rewards) ? card.rewards : []).filter((r) => r && (r.text || Number(r.credit) > 0));
  const movements = (Array.isArray(card.movements) ? card.movements : []).filter((m) => m && Number(m.stamps));

  return (
    <div className="mj-panel mj-loyalty rounded-xl3 border border-line glass p-6">
      <div className="flex flex-wrap items-baseline justify-between gap-3">
        {texts.title && <h2 className="mj-loyalty__title font-display text-lg font-semibold">{texts.title}</h2>}
        {card.progressText && <p className="mj-loyalty__progress text-sm font-medium text-accent">{card.progressText}</p>}
      </div>
      <ol className="mj-loyalty__stamps mt-5 flex flex-wrap gap-2.5" aria-label={card.progressText || undefined}>
        {Array.from({ length: per }).map((_, i) => {
          const filled = i < current;
          return (
            <li
              key={i}
              className={cn(
                'mj-loyalty__stamp grid size-9 place-items-center rounded-full border-2',
                filled ? 'mj-loyalty__stamp--filled border-accent bg-accent text-white' : 'border-line text-ink-faint'
              )}
            >
              {filled ? (
                <Check size={16} strokeWidth={2.5} aria-hidden="true" />
              ) : (
                <span className="text-[11px] font-medium" aria-hidden="true">{i + 1}</span>
              )}
            </li>
          );
        })}
      </ol>
      {total === 0 && texts.empty && <p className="mj-loyalty__empty mt-4 text-sm text-ink-soft">{texts.empty}</p>}
      {card.rewardText && (
        <p className="mj-loyalty__reward mt-5 flex items-start gap-2 text-sm">
          <Gift size={16} className="mt-0.5 shrink-0 text-accent" aria-hidden="true" />
          <span>
            {texts.rewardLabel && <span className="font-medium">{texts.rewardLabel} </span>}
            <span className="text-ink-soft">{card.rewardText}</span>
          </span>
        </p>
      )}
      {rewards.length > 0 && (
        <div className="mj-loyalty__rewards mt-6 border-t border-line pt-5">
          {texts.rewardsTitle && <h3 className="text-sm font-semibold">{texts.rewardsTitle}</h3>}
          <ul className="mt-3 divide-y divide-line text-sm">
            {rewards.map((r) => (
              <li key={r.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                <span className="min-w-0">
                  {r.text || formatPrice(Number(r.credit) || 0)}
                  {r.text && Number(r.credit) > 0 && <span className="text-ink-faint"> · {formatPrice(Number(r.credit))}</span>}
                </span>
                {r.statusLabel && <Badge tone={rewardTone(r.status)}>{r.statusLabel}</Badge>}
              </li>
            ))}
          </ul>
        </div>
      )}
      {movements.length > 0 && texts.historyTitle && (
        <details className="mj-loyalty__history mt-5 border-t border-line pt-4 text-sm">
          <summary className="cursor-pointer list-none font-semibold [&::-webkit-details-marker]:hidden">{texts.historyTitle}</summary>
          <ul className="mt-2 divide-y divide-line">
            {movements.slice(0, 10).map((m) => {
              const n = Number(m.stamps);
              return (
                <li key={m.id} className="flex items-center justify-between gap-4 py-2">
                  <span className="min-w-0 truncate text-ink-soft">{m.note || formatDate(m.createdAt)}</span>
                  <span className={cn('shrink-0 font-medium', n < 0 ? 'text-ink-soft' : 'text-success')}>
                    {n > 0 ? `+${n}` : String(n)}
                  </span>
                </li>
              );
            })}
          </ul>
        </details>
      )}
    </div>
  );
}

function OrderRow({ order }) {
  const meta = [
    formatDate(order.date),
    tn('account_order_items', order.itemsCount ?? 0),
  ]
    .filter(Boolean)
    .join(' · ');
  return (
    <Link
      to={`/cuenta/pedidos/${order.id}`}
      className="mt-4 flex flex-wrap items-center justify-between gap-4 rounded-xl2 border border-line p-4 transition-colors hover:border-accent/40"
    >
      <div>
        <p className="font-medium">#{order.number ?? order.id}</p>
        <p className="text-xs text-ink-faint">{meta}</p>
      </div>
      <OrderStatus order={order} />
      <p className="font-display font-semibold">{formatPrice(Number(order.total) || 0)}</p>
      <ChevronRight size={16} className="text-ink-faint" />
    </Link>
  );
}

export function AccountOrders() {
  const { account } = useOutletContext();
  const orders = account?.orders ?? [];
  return (
    <Reveal>
      <h2 className="font-display text-xl font-semibold">{tCopy('account_orders_title')}</h2>
      {orders.length === 0 ? (
        <div className="mt-6 rounded-xl3 border border-line glass p-12 text-center">
          <Package size={28} className="mx-auto text-ink-faint" />
          <p className="mt-4 text-ink-soft">{tCopy('account_orders_empty')}</p>
          <Button className="mt-5" size="sm" to="/tienda">{tCopy('account_orders_cta')}</Button>
        </div>
      ) : (
        <div className="mt-2">
          {orders.map((o) => <OrderRow key={o.id} order={o} />)}
        </div>
      )}
    </Reveal>
  );
}

/* --------------------------- Detalle de pedido ---------------------------- */

/** Producto mínimo para re-agregar una línea del pedido al carrito. */
const lineToProduct = (i) => ({
  id: i.productId,
  name: i.name,
  price: i.price,
  regular_price: i.price,
  sale_price: null,
  image: i.image,
  stock_status: 'instock',
});

export function AccountOrderDetail() {
  const { orderId } = useParams();
  const { refresh } = useOutletContext();
  const add = useCart((s) => s.add);
  const setCartError = useCart((s) => s.setError);
  const setCartOpen = useUI((s) => s.setCartOpen);
  const [order, setOrder] = useState(null);
  const [loading, setLoading] = useState(IS_WP);
  const [error, setError] = useState('');
  const [confirmCancel, setConfirmCancel] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [cancelError, setCancelError] = useState('');

  useEffect(() => {
    if (!IS_WP) return;
    let live = true;
    setLoading(true);
    setError('');
    wc.getOrder(orderId)
      .then((o) => { if (live) setOrder(o); })
      .catch((e) => { if (live) setError(e?.data?.message || tCopy('account_order_load_error')); })
      .finally(() => { if (live) setLoading(false); });
    return () => { live = false; };
  }, [orderId]);

  /** Vuelve a agregar líneas del pedido al carrito y abre el drawer. */
  const addToCart = (items) => {
    const available = items.filter((i) => i.productId);
    for (const i of available) {
      add(lineToProduct(i), i.qty, i.variationId ? { id: i.variationId, price: i.price } : null);
    }
    const skipped = items.length - available.length;
    if (skipped > 0) {
      setCartError(tn('account_reorder_skipped', skipped));
    }
    setCartOpen(true);
  };

  const cancel = async () => {
    setCancelling(true);
    setCancelError('');
    try {
      const res = await wc.cancelOrder(order.id);
      setOrder((o) => ({ ...o, status: res.status, statusLabel: res.statusLabel, canCancel: false }));
      setConfirmCancel(false);
      refresh?.();
    } catch (e) {
      setCancelError(e?.data?.message || tCopy('account_cancel_error'));
    } finally {
      setCancelling(false);
    }
  };

  if (!IS_WP) {
    return (
      <Reveal className="mj-empty rounded-xl3 border border-line glass p-12 text-center">
        <Package size={28} className="mx-auto text-ink-faint" />
        <p className="mt-4 text-ink-soft">{tCopy('account_order_standalone')}</p>
        <Button className="mt-5" size="sm" to="/cuenta/pedidos">{tCopy('account_orders_back')}</Button>
      </Reveal>
    );
  }
  if (loading) return <Spinner />;
  if (error) {
    return (
      <Reveal className="mj-empty rounded-xl3 border border-line glass p-12 text-center">
        <AlertCircle size={28} className="mx-auto text-danger" />
        <p className="mt-4 text-ink-soft">{error}</p>
        <Button className="mt-5" size="sm" to="/cuenta/pedidos">{tCopy('account_orders_back')}</Button>
      </Reveal>
    );
  }
  if (!order) return null;

  /* Asistencia: primer vendedor de WhatsApp configurado, o el WhatsApp de contacto, o el formulario. */
  const assistNumber = String(WHATSAPP_NUMBERS[0]?.number || CONTACT.whatsapp || '').replace(/\D+/g, '');
  /* Sin nombre del sitio, la parte " de {tienda}" se saca del texto. */
  const assistTpl = SITE?.name ? tCopy('account_assist_message') : tCopy('account_assist_message').replace(/ de \{tienda\}/, '');
  const assistMsg = fill(assistTpl, { numero: order.number ?? order.id, tienda: SITE?.name || '' });

  const t = order.totals ?? {};
  const totalRows = [
    [tCopy('account_totals_subtotal'), formatPrice(t.subtotal || 0)],
    t.discount > 0 && [tCopy('account_totals_discount'), `- ${formatPrice(t.discount)}`],
    [tCopy('account_totals_shipping'), t.shipping > 0 ? formatPrice(t.shipping) : tCopy('account_totals_free')],
    // Tipo de cambio con el que se cobró (plugin MJ Dólar; solo pedidos con productos en USD).
    order.exchangeRate > 0 && [tCopy('account_totals_rate'), order.exchangeRateFormatted || formatPrice(order.exchangeRate)],
    // Líneas de ajuste (descuento/recargo por medio de pago u otras fees) tal como quedaron en el pedido.
    ...(order.fees || []).map((f) => [f.name, `${f.amount < 0 ? '- ' : '+ '}${formatPrice(Math.abs(f.amount))}`]),
    order.usdTotal > 0 && [tCopy('account_totals_usd'), order.usdTotalFormatted],
  ].filter(Boolean);

  return (
    <Reveal>
      <Link to="/cuenta/pedidos" className="inline-flex items-center gap-1.5 text-sm text-ink-faint transition-colors hover:text-accent">
        <ArrowLeft size={15} /> {tCopy('account_orders_back')}
      </Link>

      <div className="mt-4 flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 className="font-display text-2xl font-semibold">{tCopy('account_order_title')} #{order.number ?? order.id}</h2>
          <p className="mt-1 text-sm text-ink-faint">{formatDate(order.date)}</p>
        </div>
        <OrderStatus order={order} />
      </div>

      {/* Acciones sobre el pedido */}
      <div className="mt-6 flex flex-wrap gap-3">
        <Button size="sm" onClick={() => addToCart(order.items)} disabled={!order.items.some((i) => i.productId)}>
          <RotateCcw size={15} /> {tCopy('account_reorder')}
        </Button>
        {assistNumber ? (
          <Button size="sm" variant="secondary" href={whatsappUrl(assistNumber, assistMsg)} target="_blank" rel="noreferrer">
            <MessageCircle size={15} /> {tCopy('account_assist')}
          </Button>
        ) : (
          <Button size="sm" variant="secondary" to="/contacto">
            <MessageCircle size={15} /> {tCopy('account_assist')}
          </Button>
        )}
        {order.canCancel && (
          <button
            onClick={() => setConfirmCancel(true)}
            className="inline-flex h-9 items-center gap-1.5 rounded-full px-4 text-sm font-medium text-danger/80 transition-colors hover:bg-danger/10 hover:text-danger"
          >
            <XCircle size={15} /> {tCopy('account_cancel')}
          </button>
        )}
      </div>

      {/* Productos del pedido */}
      <div className="mt-8 rounded-xl3 border border-line glass p-6">
        <h3 className="font-display text-lg font-semibold">{tCopy('account_products_title')}</h3>
        <div className="mt-4 divide-y divide-line">
          {order.items.map((i, idx) => (
            <div key={`${i.productId}-${i.variationId}-${idx}`} className="flex items-center gap-4 py-4 first:pt-0 last:pb-0">
              {i.image ? (
                <img src={i.image} alt="" className="size-14 shrink-0 rounded-xl2 object-cover" />
              ) : (
                <div className="size-14 shrink-0 rounded-xl2 bg-surface" />
              )}
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{i.name}</p>
                <p className="mt-0.5 text-xs text-ink-faint">
                  {i.variant ? `${i.variant} · ` : ''}{i.qty} × {formatPrice(i.price)}
                </p>
              </div>
              <p className="shrink-0 font-display text-sm font-semibold">{formatPrice(i.total)}</p>
              {i.productId ? (
                <button
                  onClick={() => addToCart([i])}
                  className="grid size-9 shrink-0 place-items-center rounded-full bg-accent-soft text-accent transition-all hover:bg-accent hover:text-white"
                  aria-label={tf('account_line_add_aria', { nombre: i.name })}
                  title={tCopy('account_line_add')}
                >
                  <Plus size={15} />
                </button>
              ) : (
                <span className="shrink-0 text-xs text-ink-faint">{tCopy('account_line_unavailable')}</span>
              )}
            </div>
          ))}
        </div>
      </div>

      {(order.paymentInstructions || order.paymentDetails?.length > 0) && (
        <div className="mj-panel mt-6 rounded-xl3 border border-line glass p-6">
          <h3 className="font-display text-lg font-semibold">{order.paymentTexts?.howTo || tCopy('account_payment_howto')}{order.paymentMethodTitle ? ` · ${order.paymentMethodTitle}` : ''}</h3>
          {order.paymentInstructions && (
            <p className="mt-3 whitespace-pre-line text-sm leading-relaxed text-ink-soft">{order.paymentInstructions}</p>
          )}
          <PaymentDetails
            title={order.paymentDetailsTitle}
            rows={order.paymentDetails}
            texts={order.paymentTexts}
            className={order.paymentInstructions ? 'mt-5 border-t border-line pt-5' : 'mt-4'}
          />
        </div>
      )}

      {/* Comprobante de pago: lo maneja el plugin MJ Medios de Pago. */}
      {order.receipt && (
        <div className="mj-panel mt-6 rounded-xl3 border border-line glass p-6">
          <ReceiptUploader
            objectId={order.receipt.objectId}
            context={order.receipt.context}
            title={tf('account_receipt_title', { comprobante: order.receipt.nounTitle })}
          />
        </div>
      )}

      <div className="mt-6 grid gap-6 md:grid-cols-2">
        {/* Totales */}
        <div className="mj-panel rounded-xl3 border border-line glass p-6">
          <h3 className="font-display text-lg font-semibold">{tCopy('account_totals_title')}</h3>
          <dl className="mt-4 space-y-2.5 text-sm">
            {totalRows.map(([label, value]) => (
              <div key={label} className="flex items-center justify-between">
                <dt className="text-ink-soft">{label}</dt>
                <dd className="font-medium">{value}</dd>
              </div>
            ))}
            <div className="flex items-center justify-between hairline-t pt-2.5">
              <dt className="font-medium">{tCopy('account_totals_total')}</dt>
              <dd className="font-display text-lg font-semibold">{formatPrice(t.total || 0)}</dd>
            </div>
            {/* Pagos parciales (plugin MJ Medios de Pago): lo que ya se acreditó y lo que falta. */}
            {order.paymentBalance && (
              <>
                <div className="flex items-center justify-between">
                  <dt className="text-ink-soft">{order.paymentBalance.labels?.paid || tCopy('account_balance_paid')}</dt>
                  <dd className="font-medium text-success">{order.paymentBalance.paidText}</dd>
                </div>
                <div className="flex items-center justify-between">
                  <dt className={order.paymentBalance.due > 0 ? 'font-medium text-danger' : 'text-ink-soft'}>
                    {order.paymentBalance.due > 0
                      ? order.paymentBalance.labels?.due || tCopy('account_balance_due')
                      : order.paymentBalance.labels?.paidInFull || tCopy('account_balance_full')}
                  </dt>
                  {order.paymentBalance.due > 0 && (
                    <dd className="font-semibold text-danger">{order.paymentBalance.dueText}</dd>
                  )}
                </div>
              </>
            )}
          </dl>
        </div>

        {/* Envío, pago y contacto */}
        <div className="mj-panel rounded-xl3 border border-line glass p-6">
          <h3 className="font-display text-lg font-semibold">{tCopy('account_shipping_title')}</h3>
          <div className="mt-4 space-y-4 text-sm">
            {(order.envio?.metodo || order.shippingMethod) && (
              <div>
                <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">{tCopy('account_shipping_method')}</p>
                <p className="mt-1 text-ink-soft">{order.envio?.metodo || order.shippingMethod}</p>
                {/* Todo lo de abajo lo aporta el plugin MJ Envíos: sin plugin, no hay nada que mostrar. */}
                {order.envio?.plazo && <p className="text-ink-faint">{order.envio.plazo}</p>}
                {order.envio?.aCoordinar && <p className="text-ink-faint">{tCopy('account_shipping_to_agree')}</p>}
                {order.envio?.sucursal && <p className="mt-1 text-ink-soft">{order.envio.sucursal}</p>}
                {order.envio?.retiro?.address && (
                  <p className="mt-1 text-ink-soft">
                    {tf('account_pickup_at', { lugar: [order.envio.retiro.address, order.envio.retiro.city].filter(Boolean).join(', ') })}
                  </p>
                )}
                {order.envio?.datos?.length > 0 && (
                  <ul className="mt-1.5 space-y-0.5 text-ink-faint">
                    {order.envio.datos.map((d) => (
                      <li key={d.etiqueta}>{d.etiqueta}: {d.valor}</li>
                    ))}
                  </ul>
                )}
                {order.envio?.instrucciones && (
                  <p className="mt-1.5 whitespace-pre-line text-ink-soft">{order.envio.instrucciones}</p>
                )}
              </div>
            )}
            {order.envio?.seguimiento?.codigo && (
              <div>
                <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">{tCopy('account_tracking')}</p>
                <p className="mt-1 font-medium text-ink">{order.envio.seguimiento.codigo}</p>
                {order.envio.seguimiento.link && (
                  <a
                    href={order.envio.seguimiento.link}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-accent hover:underline"
                  >
                    {tCopy('account_tracking_link')}
                  </a>
                )}
              </div>
            )}
            {order.paymentMethod && (
              <div>
                <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">{tCopy('account_payment_method')}</p>
                <p className="mt-1 text-ink-soft">{order.paymentMethod}</p>
              </div>
            )}
            {order.shippingAddr?.address && (
              <div>
                <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">{tCopy('account_shipping_address')}</p>
                <p className="mt-1 text-ink-soft">
                  {order.shippingAddr.name && <>{order.shippingAddr.name}<br /></>}
                  {order.shippingAddr.address}
                </p>
              </div>
            )}
            {(order.billing?.email || order.billing?.phone) && (
              <div>
                <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">{tCopy('account_contact')}</p>
                <p className="mt-1 text-ink-soft">{[order.billing?.email, order.billing?.phone].filter(Boolean).join(' · ')}</p>
              </div>
            )}
          </div>
        </div>

        {/* Factura ARCA (plugin MJ Facturador) */}
        {order.factura && (
          <div className="rounded-xl3 border border-line glass p-6 md:col-span-2">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h3 className="font-display text-lg font-semibold">{tCopy('account_invoice_title')}</h3>
              <Badge tone={order.factura.estado === 'emitida' ? 'success' : order.factura.estado === 'error' ? 'danger' : 'neutral'}>
                {order.factura.estado === 'emitida' ? tCopy('account_invoice_issued') : order.factura.estado === 'error' ? tCopy('account_invoice_failed') : tCopy('account_invoice_requested')}
              </Badge>
            </div>
            <div className="mt-4 grid gap-4 text-sm sm:grid-cols-2">
              <div>
                <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">{tCopy('account_invoice_receiver')}</p>
                <p className="mt-1 text-ink-soft">{order.factura.nombre}</p>
                <p className="text-ink-faint">{order.factura.documento} · {order.factura.condIva}</p>
              </div>
              {order.factura.estado === 'emitida' && (
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">{tCopy('account_invoice_voucher')}</p>
                  <p className="mt-1 text-ink-soft">{order.factura.tipo} {order.factura.numero}</p>
                  <p className="text-ink-faint">{tf('account_invoice_cae', { cae: order.factura.cae })}</p>
                </div>
              )}
            </div>
            {order.factura.estado === 'emitida' && (
              <div className="mt-5 flex flex-wrap items-center gap-3">
                {order.factura.pdfUrl && (
                  <Button size="sm" href={order.factura.pdfUrl} target="_blank" rel="noreferrer">
                    {tCopy('account_invoice_download')}
                  </Button>
                )}
                {order.factura.url && (
                  <Button size="sm" variant="secondary" href={order.factura.url} target="_blank" rel="noreferrer">
                    {tCopy('account_invoice_online')}
                  </Button>
                )}
              </div>
            )}
            {order.factura.estado === 'pendiente' && (
              <p className="mt-4 text-xs text-ink-faint">{tCopy('account_invoice_pending')}</p>
            )}
            {order.factura.estado === 'error' && (
              <p className="mt-4 text-xs text-ink-faint">{tCopy('account_invoice_error_text')}</p>
            )}
          </div>
        )}
      </div>

      {/* Confirmación de cancelación */}
      <Modal open={confirmCancel} onClose={() => !cancelling && setConfirmCancel(false)}>
        <div className="p-8">
          <h3 className="font-display text-xl font-semibold">{tf('account_cancel_title', { numero: order.number ?? order.id })}</h3>
          <p className="mt-3 text-sm leading-relaxed text-ink-soft">
            {tCopy('account_cancel_text')}
          </p>
          {cancelError && (
            <p className="mt-4 flex items-center gap-2 rounded-xl2 border border-danger/30 bg-danger/10 p-3 text-sm text-danger">
              <AlertCircle size={15} className="shrink-0" /> {cancelError}
            </p>
          )}
          <div className="mt-6 flex flex-wrap gap-3">
            <Button size="sm" variant="secondary" onClick={() => setConfirmCancel(false)} disabled={cancelling}>
              {tCopy('account_cancel_back')}
            </Button>
            <button
              onClick={cancel}
              disabled={cancelling}
              className="inline-flex h-9 items-center gap-1.5 rounded-full bg-danger px-4 text-sm font-medium text-white transition-all hover:bg-danger/85 disabled:opacity-40"
            >
              {cancelling && <Loader2 size={15} className="animate-spin" />}
              {tCopy('account_cancel_confirm')}
            </button>
          </div>
        </div>
      </Modal>
    </Reveal>
  );
}

/*
 * Los mismos datos que pide el checkout: lo que se guarda acá lo completa en la próxima compra. Los
 * nombres salen de MJ Envíos (Datos del cliente) cuando está; estos son los de respaldo.
 */
const ADDRESS_FIELDS = [
  ['first_name', 'field_first_name'],
  ['last_name', 'field_last_name'],
  ['address_1', 'field_address_1', true],
  ['address_2', 'field_address_2', true],
  ['city', 'field_city'],
  ['state', 'field_state'],
  ['postcode', 'field_postcode'],
  ['country', 'field_country'],
];

function AddressForm({ title, initial, withContact = false, onSave }) {
  const [form, setForm] = useState(() => ({ ...initial }));
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState(null);
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const submit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setMsg(null);
    try {
      await onSave(form);
      setMsg({ ok: true, text: tCopy('account_addresses_saved') });
    } catch (err) {
      setMsg({ ok: false, text: err?.data?.message || tCopy('account_addresses_error') });
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={submit} className="mj-panel rounded-xl3 border border-line glass p-6">
      <p className="font-medium">{title}</p>
      <div className="mt-4 grid gap-4 sm:grid-cols-2">
        {ADDRESS_FIELDS.map(([k, labelKey, wide]) => (
          <div key={k} className={wide ? 'sm:col-span-2' : undefined}>
            <Input label={fieldLabel(k, tCopy(labelKey))} value={form[k] ?? ''} onChange={set(k)} />
          </div>
        ))}
        {withContact && (
          <>
            <Input label={tCopy('field_email')} type="email" value={form.email ?? ''} onChange={set('email')} />
            <Input label={tCopy('field_phone')} type="tel" value={form.phone ?? ''} onChange={set('phone')} />
          </>
        )}
      </div>
      <div className="mt-5 flex items-center gap-4">
        <Button type="submit" disabled={saving}>
          {saving && <Loader2 size={15} className="animate-spin" />}
          {tCopy('account_save')}
        </Button>
        <FormMessage msg={msg} />
      </div>
    </form>
  );
}

const EMPTY_FISCAL = { fact_nombre: '', doc_tipo: 'cf', doc_nro: '', cond_iva: 'cf' };

/** Perfil fiscal del usuario: el checkout lo usa para autocompletar el paso 4. */
function FiscalForm() {
  const [form, setForm] = useState(null); // null = cargando
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState(null);
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  useEffect(() => {
    if (!IS_WP) {
      setForm(EMPTY_FISCAL);
      return;
    }
    wc.getFiscal()
      .then((d) => setForm(d?.receptor ?? EMPTY_FISCAL))
      .catch(() => setForm(EMPTY_FISCAL));
  }, []);

  const submit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setMsg(null);

    // Misma validación que hace el checkout y el servidor (dígito verificador).
    const nro = (form.doc_nro || '').replace(/\D+/g, '');
    if (form.doc_tipo === 'cuit' && !isValidCuit(nro)) {
      setSaving(false);
      setMsg({ ok: false, text: tCopy('fiscal_cuit_invalid') });
      return;
    }
    if (form.doc_tipo === 'dni' && (nro.length < 7 || nro.length > 8)) {
      setSaving(false);
      setMsg({ ok: false, text: tCopy('fiscal_dni_invalid') });
      return;
    }

    try {
      if (IS_WP) await wc.saveFiscal({ ...form, doc_nro: nro });
      setMsg({ ok: true, text: tCopy('account_fiscal_saved') });
    } catch (err) {
      setMsg({ ok: false, text: err?.data?.message || tCopy('account_data_error') });
    } finally {
      setSaving(false);
    }
  };

  if (!form) {
    return (
      <div className="grid place-items-center rounded-xl3 border border-line glass p-10">
        <Loader2 size={20} className="animate-spin text-accent" />
      </div>
    );
  }

  return (
    <form onSubmit={submit} className="mj-panel rounded-xl3 border border-line glass p-6">
      <p className="font-medium">{tCopy('account_fiscal_title')}</p>
      <p className="mt-1 text-xs text-ink-faint">
        {tCopy('account_fiscal_hint')}
      </p>
      <div className="mt-4 grid gap-4 sm:grid-cols-2">
        <Input
          label={tCopy('fiscal_name')}
          className="sm:col-span-2"
          maxLength={80}
          value={form.fact_nombre}
          onChange={set('fact_nombre')}
        />
        <Select label={tCopy('fiscal_doc_type')} value={form.doc_tipo} onChange={set('doc_tipo')}>
          <option value="cf">{tCopy('fiscal_doc_cf')}</option>
          <option value="cuit">{tCopy('fiscal_doc_cuit')}</option>
          <option value="dni">{tCopy('fiscal_doc_dni')}</option>
        </Select>
        <Input
          label={tCopy('fiscal_doc_number')}
          inputMode="numeric"
          disabled={form.doc_tipo === 'cf'}
          value={form.doc_tipo === 'cf' ? '' : form.doc_nro}
          onChange={set('doc_nro')}
        />
        <Select
          label={tCopy('fiscal_iva')}
          className="sm:col-span-2"
          value={form.cond_iva}
          onChange={set('cond_iva')}
        >
          <option value="cf">{tCopy('fiscal_iva_cf')}</option>
          <option value="ri">{tCopy('fiscal_iva_ri')}</option>
          <option value="monotributo">{tCopy('fiscal_iva_mono')}</option>
          <option value="exento">{tCopy('fiscal_iva_exento')}</option>
        </Select>
      </div>
      <div className="mt-5 flex items-center gap-4">
        <Button type="submit" disabled={saving}>
          {saving && <Loader2 size={15} className="animate-spin" />}
          {tCopy('account_save')}
        </Button>
        <FormMessage msg={msg} />
      </div>
    </form>
  );
}

export function AccountAddresses() {
  const { account, refresh } = useOutletContext();
  const addresses = account?.addresses ?? {};
  const billing = addresses.billing ?? {};
  const shipping = addresses.shipping ?? {};

  const save = (key) => async (values) => {
    if (!IS_WP) return; // standalone: demo sin persistencia
    await wc.updateAddresses({ billing, shipping, [key]: values });
    await refresh();
  };

  return (
    <Reveal className="grid gap-6">
      <AddressForm title={tCopy('account_billing_address_title')} initial={billing} withContact onSave={save('billing')} />
      <AddressForm title={tCopy('account_shipping_address_title')} initial={shipping} onSave={save('shipping')} />
      <FiscalForm />
    </Reveal>
  );
}

export function AccountFavorites() {
  const ids = useWishlist((s) => s.ids);
  const products = useCatalog((s) => s.products);
  const favs = products.filter((p) => ids.includes(p.id));
  return (
    <Reveal>
      <h2 className="font-display text-xl font-semibold">{tCopy('account_nav_favorites')}</h2>
      {favs.length === 0 ? (
        <div className="mt-6 rounded-xl3 border border-line glass p-12 text-center">
          <p className="text-ink-soft">{tCopy('account_favorites_empty')}</p>
          <Button className="mt-5" size="sm" to="/tienda">{tCopy('account_favorites_cta')}</Button>
        </div>
      ) : (
        <div className="mt-6"><ProductGrid products={favs} cols={3} /></div>
      )}
    </Reveal>
  );
}

export function AccountDownloads() {
  const { account } = useOutletContext();
  const downloads = account?.downloads ?? [];
  if (downloads.length === 0) {
    return (
      <Reveal className="mj-empty rounded-xl3 border border-line glass p-12 text-center">
        <Download size={28} className="mx-auto text-ink-faint" />
        <p className="mt-4 text-ink-soft">{tCopy('account_downloads_empty')}</p>
        <p className="mt-1 text-xs text-ink-faint">{tCopy('account_downloads_hint')}</p>
      </Reveal>
    );
  }
  return (
    <Reveal>
      <h2 className="font-display text-xl font-semibold">{tCopy('account_nav_downloads')}</h2>
      <div className="mt-4 space-y-3">
        {downloads.map((d, i) => (
          <div key={`${d.url}-${i}`} className="flex flex-wrap items-center justify-between gap-4 rounded-xl2 border border-line glass p-4">
            <p className="font-medium">{d.name}</p>
            <Button size="sm" variant="secondary" href={d.url}>
              <Download size={15} /> {tCopy('account_download')}
            </Button>
          </div>
        ))}
      </div>
    </Reveal>
  );
}

export function AccountSettings() {
  const { account, refresh } = useOutletContext();
  const authUser = useAuth((s) => s.user);
  const u = account?.user ?? authUser ?? {};
  const [form, setForm] = useState({
    first_name: u.first_name ?? '',
    last_name: u.last_name ?? '',
    display_name: u.display_name ?? u.name ?? '',
    email: u.email ?? '',
    current_password: '',
    new_password: '',
  });
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState(null);
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const submit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setMsg(null);
    try {
      if (IS_WP) {
        const payload = {
          first_name: form.first_name.trim(),
          last_name: form.last_name.trim(),
          display_name: form.display_name.trim(),
          email: form.email.trim(),
        };
        // Solo enviamos contraseñas si se pidió un cambio.
        if (form.new_password) {
          payload.current_password = form.current_password;
          payload.new_password = form.new_password;
        }
        const res = await wc.updateProfile(payload);
        if (res?.user) useAuth.setState({ user: res.user });
        refresh?.();
      }
      setForm((f) => ({ ...f, current_password: '', new_password: '' }));
      setMsg({ ok: true, text: tCopy('account_settings_saved') });
    } catch (err) {
      setMsg({ ok: false, text: err?.data?.message || tCopy('account_data_error') });
    } finally {
      setSaving(false);
    }
  };

  return (
    <Reveal className="max-w-lg">
      <h2 className="font-display text-xl font-semibold">{tCopy('account_nav_settings')}</h2>
      <form onSubmit={submit} className="mt-5 space-y-5">
        <div className="grid gap-5 sm:grid-cols-2">
          <Input label={tCopy('field_first_name')} autoComplete="given-name" value={form.first_name} onChange={set('first_name')} />
          <Input label={tCopy('field_last_name')} autoComplete="family-name" value={form.last_name} onChange={set('last_name')} />
        </div>
        <Input label={tCopy('account_display_name')} value={form.display_name} onChange={set('display_name')} />
        <Input label={tCopy('field_email')} type="email" autoComplete="email" value={form.email} onChange={set('email')} required />
        <div className="space-y-5 rounded-xl2 border border-line p-5">
          <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">{tCopy('account_password_title')}</p>
          <Input
            label={tCopy('account_password_current')}
            type="password"
            autoComplete="current-password"
            placeholder="••••••••"
            value={form.current_password}
            onChange={set('current_password')}
          />
          <Input
            label={tCopy('account_password_new')}
            type="password"
            autoComplete="new-password"
            placeholder={tCopy('field_password_hint')}
            minLength={6}
            value={form.new_password}
            onChange={set('new_password')}
          />
        </div>
        <div className="flex items-center gap-4">
          <Button type="submit" disabled={saving}>
            {saving && <Loader2 size={15} className="animate-spin" />}
            {tCopy('account_save_changes')}
          </Button>
          <FormMessage msg={msg} />
        </div>
      </form>
    </Reveal>
  );
}

/* ------------------------------ Mis turnos -------------------------------- */

/** Tono del badge según el estado del turno (mismo criterio que la página del turno). */
const turnoTone = (status) =>
  ({
    confirmed: 'success',
    completed: 'success',
    cancelled: 'danger',
    no_show: 'danger',
    pending: 'accent',
    awaiting_deposit: 'accent',
  })[status] ?? 'neutral';

/** Un turno de la lista: fecha, hora, servicio y estado; abre su página (ahí están las acciones). */
function TurnoRow({ item }) {
  const when = [item.date ? formatDateLong(item.date) : item.dateLabel, item.time && `${item.time}${item.endTime ? ` – ${item.endTime}` : ''}`]
    .filter(Boolean)
    .join(' · ');
  return (
    <Link
      to={statusPath(item.token)}
      className={cn(
        'mt-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl2 border p-4 transition-colors',
        item.needsAction ? 'border-accent/40 bg-accent-soft hover:border-accent' : 'border-line hover:border-accent/40'
      )}
    >
      <div className="min-w-0 flex-1">
        <p className="flex items-center gap-2 font-medium">
          {item.needsAction && <AlertCircle size={15} className="shrink-0 text-accent" aria-hidden="true" />}
          <span className="truncate">{item.serviceName}</span>
        </p>
        <p className="mt-0.5 text-xs capitalize text-ink-faint tabular-nums">{when}</p>
      </div>
      <Badge tone={turnoTone(item.status)}>{item.statusLabel || turnoStatusLabel(item.status)}</Badge>
      <span className="inline-flex items-center gap-1 text-xs font-medium text-accent">
        {turnosCopy('my_bookings_view')}
        <ChevronRight size={14} aria-hidden="true" />
      </span>
    </Link>
  );
}

/**
 * Mi cuenta → Mis turnos (plugin MJ Turnos Online, cuentas prendidas): los
 * próximos y los anteriores (GET /me/bookings), el saldo a favor con sus
 * movimientos y el botón para reservar. Los textos salen del plugin.
 */
export function AccountBookings() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(IS_WP);
  const [error, setError] = useState('');
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (!IS_WP || !TURNOS) return undefined;
    let live = true;
    setLoading(true);
    setError('');
    getMyBookings()
      .then((res) => { if (live) setData(res); })
      .catch((e) => {
        if (!live) return;
        setError(e?.status === 401 ? turnosCopy('my_bookings_login') : e?.message || turnosCopy('my_bookings_login'));
      })
      .finally(() => { if (live) setLoading(false); });
    return () => { live = false; };
  }, [tick]);

  if (!TURNOS) return null;
  if (loading) return <Spinner />;

  const upcoming = Array.isArray(data?.upcoming) ? data.upcoming : [];
  const past = Array.isArray(data?.past) ? data.past : [];
  const credit = Number(data?.credit) || 0;
  const movements = Array.isArray(data?.movements) ? data.movements : [];

  return (
    <Reveal className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <h2 className="font-display text-xl font-semibold">{turnosCopy('my_bookings_title')}</h2>
        <Button size="sm" to={bookingPath()}>
          <CalendarDays size={15} /> {turnosCopy('my_bookings_book')}
        </Button>
      </div>

      {error ? (
        <div className="mj-empty rounded-xl3 border border-line glass p-10 text-center">
          <AlertCircle size={26} className="mx-auto text-ink-faint" />
          <p className="mt-4 text-ink-soft">{error}</p>
          <Button className="mt-5" size="sm" variant="secondary" onClick={() => setTick((n) => n + 1)}>
            <RotateCcw size={15} /> {turnosCopy('booking_retry')}
          </Button>
        </div>
      ) : (
        <>
          {credit > 0 && (
            <div className="mj-panel rounded-xl3 border border-line glass p-6">
              <p className="flex items-center gap-2 text-sm font-medium text-ink-soft">
                <Wallet size={16} className="text-accent" aria-hidden="true" /> {turnosCopy('label_credit')}
              </p>
              <p className="mt-2 font-display text-3xl font-semibold tracking-tight">{formatPrice(credit)}</p>
              {movements.length > 0 && (
                <ul className="mt-5 divide-y divide-line text-sm">
                  {movements.map((m) => (
                    <li key={m.id} className="flex items-center justify-between gap-4 py-3">
                      <div className="min-w-0">
                        <p className="truncate">{m.reason}</p>
                        <p className="text-xs text-ink-faint">{m.dateLabel}</p>
                      </div>
                      <p className={cn('shrink-0 font-medium', Number(m.amount) < 0 ? 'text-ink-soft' : 'text-success')}>
                        {Number(m.amount) < 0 ? '- ' : '+ '}{formatPrice(Math.abs(Number(m.amount) || 0))}
                      </p>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}

          {upcoming.length === 0 && past.length === 0 ? (
            <div className="mj-empty rounded-xl3 border border-line glass p-12 text-center">
              <CalendarDays size={28} className="mx-auto text-ink-faint" />
              <p className="mt-4 text-ink-soft">{turnosCopy('my_bookings_empty')}</p>
            </div>
          ) : (
            <>
              {upcoming.length > 0 && (
                <div className="mj-panel rounded-xl3 border border-line glass p-6">
                  <h3 className="font-display text-lg font-semibold">{turnosCopy('my_bookings_upcoming')}</h3>
                  {upcoming.map((item) => <TurnoRow key={item.token} item={item} />)}
                </div>
              )}
              {past.length > 0 && (
                <div className="mj-panel rounded-xl3 border border-line glass p-6">
                  <h3 className="font-display text-lg font-semibold">{turnosCopy('my_bookings_past')}</h3>
                  {past.map((item) => <TurnoRow key={item.token} item={item} />)}
                </div>
              )}
            </>
          )}
        </>
      )}
    </Reveal>
  );
}
