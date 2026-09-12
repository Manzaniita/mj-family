import { useCallback, useEffect, useState } from 'react';
import { Link, Navigate, NavLink, Outlet, useNavigate, useOutletContext, useParams } from 'react-router-dom';
import { LayoutDashboard, Package, MapPin, Heart, Download, Settings, LogOut, Loader2, AlertCircle, CheckCircle2, ArrowLeft, RotateCcw, MessageCircle, XCircle, Plus, ChevronRight, ExternalLink } from 'lucide-react';
import { Section } from '@/components/layout/Layout';
import { Button } from '@/components/ui/Button';
import { Input, Select } from '@/components/ui/Input';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { ReceiptUploader } from '@/components/ui/ReceiptUploader';
import { Reveal } from '@/components/motion/Reveal';
import { useAuth, useWishlist, useCatalog, useCart, useUI } from '@/store';
import { ProductGrid } from '@/components/product/ProductGrid';
import * as wc from '@/lib/wc';
import { whatsappUrl } from '@/lib/consult';
import { IS_WP, SITE, CONTACT, WHATSAPP_NUMBERS, ACCOUNT_LINKS } from '@/lib/config';
import { formatPrice, isValidCuit, cn } from '@/lib/format';

const nav = [
  { to: '/cuenta', end: true, icon: LayoutDashboard, label: 'Resumen' },
  { to: '/cuenta/pedidos', icon: Package, label: 'Pedidos' },
  { to: '/cuenta/direcciones', icon: MapPin, label: 'Direcciones' },
  { to: '/cuenta/favoritos', icon: Heart, label: 'Favoritos' },
  { to: '/cuenta/descargas', icon: Download, label: 'Descargas' },
  { to: '/cuenta/configuracion', icon: Settings, label: 'Configuración' },
];

/** Cuenta vacía para standalone (dev sin WordPress) — sin datos personales ficticios. */
const EMPTY_ACCOUNT = {
  user: { name: 'Invitado' },
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

  const name = user?.name || account?.user?.name || 'Invitado';

  return (
    <Section className="md:py-16">
      <Reveal>
        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-accent">Mi cuenta</p>
        <h1 className="display mt-3 text-4xl md:text-5xl">Hola, {name} 👋</h1>
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
              <LogOut size={17} strokeWidth={1.8} /> Cerrar sesión
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
          [String(orders.length), 'Pedidos'],
          [String(favCount), 'En favoritos'],
          [formatPrice(totalSpent), 'Total comprado'],
        ].map(([n, l]) => (
          <div key={l} className="mj-panel rounded-xl3 border border-line glass p-6">
            <p className="font-display text-3xl font-semibold tracking-tight">{n}</p>
            <p className="mt-1 text-sm text-ink-soft">{l}</p>
          </div>
        ))}
      </div>
      <div className="mj-panel rounded-xl3 border border-line glass p-6">
        <h2 className="font-display text-lg font-semibold">Actividad reciente</h2>
        {recent.length === 0 ? (
          <p className="mt-4 text-sm text-ink-soft">Todavía no hiciste ningún pedido.</p>
        ) : (
          recent.map((o) => <OrderRow key={o.id} order={o} />)
        )}
      </div>
    </Reveal>
  );
}

function OrderRow({ order }) {
  const meta = [
    formatDate(order.date),
    `${order.itemsCount ?? 0} producto${order.itemsCount === 1 ? '' : 's'}`,
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
      <Badge tone={statusTone(order.status)}>{order.statusLabel ?? order.status}</Badge>
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
      <h2 className="font-display text-xl font-semibold">Mis pedidos</h2>
      {orders.length === 0 ? (
        <div className="mt-6 rounded-xl3 border border-line glass p-12 text-center">
          <Package size={28} className="mx-auto text-ink-faint" />
          <p className="mt-4 text-ink-soft">Todavía no hiciste ningún pedido.</p>
          <Button className="mt-5" size="sm" to="/tienda">Explorar la tienda</Button>
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
      .catch((e) => { if (live) setError(e?.data?.message || 'No se pudo cargar el pedido.'); })
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
      setCartError(
        `${skipped} producto${skipped > 1 ? 's' : ''} del pedido ya no ${skipped > 1 ? 'están' : 'está'} disponible${skipped > 1 ? 's' : ''}.`
      );
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
      setCancelError(e?.data?.message || 'No se pudo cancelar el pedido.');
    } finally {
      setCancelling(false);
    }
  };

  if (!IS_WP) {
    return (
      <Reveal className="mj-empty rounded-xl3 border border-line glass p-12 text-center">
        <Package size={28} className="mx-auto text-ink-faint" />
        <p className="mt-4 text-ink-soft">El detalle de pedidos está disponible con la tienda conectada.</p>
        <Button className="mt-5" size="sm" to="/cuenta/pedidos">Volver a pedidos</Button>
      </Reveal>
    );
  }
  if (loading) return <Spinner />;
  if (error) {
    return (
      <Reveal className="mj-empty rounded-xl3 border border-line glass p-12 text-center">
        <AlertCircle size={28} className="mx-auto text-danger" />
        <p className="mt-4 text-ink-soft">{error}</p>
        <Button className="mt-5" size="sm" to="/cuenta/pedidos">Volver a pedidos</Button>
      </Reveal>
    );
  }
  if (!order) return null;

  /* Asistencia: primer vendedor de WhatsApp configurado, o el WhatsApp de contacto, o el formulario. */
  const assistNumber = String(WHATSAPP_NUMBERS[0]?.number || CONTACT.whatsapp || '').replace(/\D+/g, '');
  const assistMsg = `Hola! Necesito ayuda con mi pedido #${order.number ?? order.id}${SITE?.name ? ` de ${SITE.name}` : ''}.`;

  const t = order.totals ?? {};
  const totalRows = [
    ['Subtotal', formatPrice(t.subtotal || 0)],
    t.discount > 0 && ['Descuento', `- ${formatPrice(t.discount)}`],
    ['Envío', t.shipping > 0 ? formatPrice(t.shipping) : 'Gratis'],
    // Tipo de cambio con el que se cobró (plugin MJ Dólar; solo pedidos con productos en USD).
    order.exchangeRate > 0 && ['Dólar del pedido', order.exchangeRateFormatted || formatPrice(order.exchangeRate)],
    // Líneas de ajuste (descuento/recargo por medio de pago u otras fees) tal como quedaron en el pedido.
    ...(order.fees || []).map((f) => [f.name, `${f.amount < 0 ? '- ' : '+ '}${formatPrice(Math.abs(f.amount))}`]),
    order.usdTotal > 0 && ['Total en dólares', order.usdTotalFormatted],
  ].filter(Boolean);

  return (
    <Reveal>
      <Link to="/cuenta/pedidos" className="inline-flex items-center gap-1.5 text-sm text-ink-faint transition-colors hover:text-accent">
        <ArrowLeft size={15} /> Volver a pedidos
      </Link>

      <div className="mt-4 flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 className="font-display text-2xl font-semibold">Pedido #{order.number ?? order.id}</h2>
          <p className="mt-1 text-sm text-ink-faint">{formatDate(order.date)}</p>
        </div>
        <Badge tone={statusTone(order.status)}>{order.statusLabel ?? order.status}</Badge>
      </div>

      {/* Acciones sobre el pedido */}
      <div className="mt-6 flex flex-wrap gap-3">
        <Button size="sm" onClick={() => addToCart(order.items)} disabled={!order.items.some((i) => i.productId)}>
          <RotateCcw size={15} /> Repetir pedido
        </Button>
        {assistNumber ? (
          <Button size="sm" variant="secondary" href={whatsappUrl(assistNumber, assistMsg)} target="_blank" rel="noreferrer">
            <MessageCircle size={15} /> Solicitar asistencia
          </Button>
        ) : (
          <Button size="sm" variant="secondary" to="/contacto">
            <MessageCircle size={15} /> Solicitar asistencia
          </Button>
        )}
        {order.canCancel && (
          <button
            onClick={() => setConfirmCancel(true)}
            className="inline-flex h-9 items-center gap-1.5 rounded-full px-4 text-sm font-medium text-danger/80 transition-colors hover:bg-danger/10 hover:text-danger"
          >
            <XCircle size={15} /> Cancelar pedido
          </button>
        )}
      </div>

      {/* Productos del pedido */}
      <div className="mt-8 rounded-xl3 border border-line glass p-6">
        <h3 className="font-display text-lg font-semibold">Productos</h3>
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
                  aria-label={`Agregar ${i.name} al carrito`}
                  title="Agregar al carrito"
                >
                  <Plus size={15} />
                </button>
              ) : (
                <span className="shrink-0 text-xs text-ink-faint">No disponible</span>
              )}
            </div>
          ))}
        </div>
      </div>

      {order.paymentInstructions && (
        <div className="mj-panel mt-6 rounded-xl3 border border-line glass p-6">
          <h3 className="font-display text-lg font-semibold">Cómo pagar{order.paymentMethodTitle ? ` · ${order.paymentMethodTitle}` : ''}</h3>
          <p className="mt-3 whitespace-pre-line text-sm leading-relaxed text-ink-soft">{order.paymentInstructions}</p>
        </div>
      )}

      {/* Comprobante de pago: lo maneja el plugin MJ Medios de Pago. */}
      {order.receipt && (
        <div className="mj-panel mt-6 rounded-xl3 border border-line glass p-6">
          <ReceiptUploader
            objectId={order.receipt.objectId}
            context={order.receipt.context}
            title={`${order.receipt.nounTitle} de pago`}
          />
        </div>
      )}

      <div className="mt-6 grid gap-6 md:grid-cols-2">
        {/* Totales */}
        <div className="mj-panel rounded-xl3 border border-line glass p-6">
          <h3 className="font-display text-lg font-semibold">Totales</h3>
          <dl className="mt-4 space-y-2.5 text-sm">
            {totalRows.map(([label, value]) => (
              <div key={label} className="flex items-center justify-between">
                <dt className="text-ink-soft">{label}</dt>
                <dd className="font-medium">{value}</dd>
              </div>
            ))}
            <div className="flex items-center justify-between hairline-t pt-2.5">
              <dt className="font-medium">Total</dt>
              <dd className="font-display text-lg font-semibold">{formatPrice(t.total || 0)}</dd>
            </div>
          </dl>
        </div>

        {/* Envío, pago y contacto */}
        <div className="mj-panel rounded-xl3 border border-line glass p-6">
          <h3 className="font-display text-lg font-semibold">Envío y pago</h3>
          <div className="mt-4 space-y-4 text-sm">
            {(order.envio?.metodo || order.shippingMethod) && (
              <div>
                <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">Método de envío</p>
                <p className="mt-1 text-ink-soft">{order.envio?.metodo || order.shippingMethod}</p>
                {/* Todo lo de abajo lo aporta el plugin MJ Envíos: sin plugin, no hay nada que mostrar. */}
                {order.envio?.plazo && <p className="text-ink-faint">{order.envio.plazo}</p>}
                {order.envio?.aCoordinar && <p className="text-ink-faint">El costo se acuerda con vos.</p>}
                {order.envio?.sucursal && <p className="mt-1 text-ink-soft">{order.envio.sucursal}</p>}
                {order.envio?.retiro?.address && (
                  <p className="mt-1 text-ink-soft">
                    Se retira en {[order.envio.retiro.address, order.envio.retiro.city].filter(Boolean).join(', ')}
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
                <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">Seguimiento</p>
                <p className="mt-1 font-medium text-ink">{order.envio.seguimiento.codigo}</p>
                {order.envio.seguimiento.link && (
                  <a
                    href={order.envio.seguimiento.link}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-accent hover:underline"
                  >
                    Ver dónde está mi paquete
                  </a>
                )}
              </div>
            )}
            {order.paymentMethod && (
              <div>
                <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">Medio de pago</p>
                <p className="mt-1 text-ink-soft">{order.paymentMethod}</p>
              </div>
            )}
            {order.shippingAddr?.address && (
              <div>
                <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">Dirección de envío</p>
                <p className="mt-1 text-ink-soft">
                  {order.shippingAddr.name && <>{order.shippingAddr.name}<br /></>}
                  {order.shippingAddr.address}
                </p>
              </div>
            )}
            {(order.billing?.email || order.billing?.phone) && (
              <div>
                <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">Contacto</p>
                <p className="mt-1 text-ink-soft">{[order.billing?.email, order.billing?.phone].filter(Boolean).join(' · ')}</p>
              </div>
            )}
          </div>
        </div>

        {/* Factura ARCA (plugin MJ Facturador) */}
        {order.factura && (
          <div className="rounded-xl3 border border-line glass p-6 md:col-span-2">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h3 className="font-display text-lg font-semibold">Factura</h3>
              <Badge tone={order.factura.estado === 'emitida' ? 'success' : order.factura.estado === 'error' ? 'danger' : 'neutral'}>
                {order.factura.estado === 'emitida' ? 'Emitida' : order.factura.estado === 'error' ? 'Error al emitir' : 'Solicitada'}
              </Badge>
            </div>
            <div className="mt-4 grid gap-4 text-sm sm:grid-cols-2">
              <div>
                <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">Receptor</p>
                <p className="mt-1 text-ink-soft">{order.factura.nombre}</p>
                <p className="text-ink-faint">{order.factura.documento} · {order.factura.condIva}</p>
              </div>
              {order.factura.estado === 'emitida' && (
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">Comprobante</p>
                  <p className="mt-1 text-ink-soft">{order.factura.tipo} {order.factura.numero}</p>
                  <p className="text-ink-faint">CAE {order.factura.cae}</p>
                </div>
              )}
            </div>
            {order.factura.estado === 'emitida' && (
              <div className="mt-5 flex flex-wrap items-center gap-3">
                {order.factura.pdfUrl && (
                  <Button size="sm" href={order.factura.pdfUrl} target="_blank" rel="noreferrer">
                    Descargar factura (PDF)
                  </Button>
                )}
                {order.factura.url && (
                  <Button size="sm" variant="secondary" href={order.factura.url} target="_blank" rel="noreferrer">
                    Ver comprobante online
                  </Button>
                )}
              </div>
            )}
            {order.factura.estado === 'pendiente' && (
              <p className="mt-4 text-xs text-ink-faint">La factura se emite cuando el pedido se completa. Te llega por email.</p>
            )}
            {order.factura.estado === 'error' && (
              <p className="mt-4 text-xs text-ink-faint">Hubo un problema al emitirla; ya estamos al tanto. Si necesitás la factura urgente, escribinos.</p>
            )}
          </div>
        )}
      </div>

      {/* Confirmación de cancelación */}
      <Modal open={confirmCancel} onClose={() => !cancelling && setConfirmCancel(false)}>
        <div className="p-8">
          <h3 className="font-display text-xl font-semibold">Cancelar pedido #{order.number ?? order.id}</h3>
          <p className="mt-3 text-sm leading-relaxed text-ink-soft">
            ¿Seguro que querés cancelar este pedido? El stock se repone automáticamente y la operación no se puede deshacer.
          </p>
          {cancelError && (
            <p className="mt-4 flex items-center gap-2 rounded-xl2 border border-danger/30 bg-danger/10 p-3 text-sm text-danger">
              <AlertCircle size={15} className="shrink-0" /> {cancelError}
            </p>
          )}
          <div className="mt-6 flex flex-wrap gap-3">
            <Button size="sm" variant="secondary" onClick={() => setConfirmCancel(false)} disabled={cancelling}>
              Volver
            </Button>
            <button
              onClick={cancel}
              disabled={cancelling}
              className="inline-flex h-9 items-center gap-1.5 rounded-full bg-danger px-4 text-sm font-medium text-white transition-all hover:brightness-110 disabled:opacity-40"
            >
              {cancelling && <Loader2 size={15} className="animate-spin" />}
              Sí, cancelar pedido
            </button>
          </div>
        </div>
      </Modal>
    </Reveal>
  );
}

const ADDRESS_FIELDS = [
  ['first_name', 'Nombre'],
  ['last_name', 'Apellido'],
  ['address_1', 'Dirección', true],
  ['city', 'Ciudad'],
  ['state', 'Provincia'],
  ['postcode', 'Código postal'],
  ['country', 'País'],
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
      setMsg({ ok: true, text: 'Direcciones guardadas' });
    } catch (err) {
      setMsg({ ok: false, text: err?.data?.message || 'No se pudieron guardar las direcciones.' });
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={submit} className="mj-panel rounded-xl3 border border-line glass p-6">
      <p className="font-medium">{title}</p>
      <div className="mt-4 grid gap-4 sm:grid-cols-2">
        {ADDRESS_FIELDS.map(([k, label, wide]) => (
          <div key={k} className={wide ? 'sm:col-span-2' : undefined}>
            <Input label={label} value={form[k] ?? ''} onChange={set(k)} />
          </div>
        ))}
        {withContact && (
          <>
            <Input label="Email" type="email" value={form.email ?? ''} onChange={set('email')} />
            <Input label="Teléfono" type="tel" value={form.phone ?? ''} onChange={set('phone')} />
          </>
        )}
      </div>
      <div className="mt-5 flex items-center gap-4">
        <Button type="submit" disabled={saving}>
          {saving && <Loader2 size={15} className="animate-spin" />}
          Guardar
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
      setMsg({ ok: false, text: 'El CUIT/CUIL no es válido: revisá los números.' });
      return;
    }
    if (form.doc_tipo === 'dni' && (nro.length < 7 || nro.length > 8)) {
      setSaving(false);
      setMsg({ ok: false, text: 'El DNI debe tener 7 u 8 dígitos.' });
      return;
    }

    try {
      if (IS_WP) await wc.saveFiscal({ ...form, doc_nro: nro });
      setMsg({ ok: true, text: 'Datos fiscales guardados' });
    } catch (err) {
      setMsg({ ok: false, text: err?.data?.message || 'No se pudieron guardar los datos.' });
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
      <p className="font-medium">Datos de facturación</p>
      <p className="mt-1 text-xs text-ink-faint">
        Se usan para autocompletar la factura cuando la pedís a tu nombre en el checkout.
      </p>
      <div className="mt-4 grid gap-4 sm:grid-cols-2">
        <Input
          label="Nombre o razón social"
          className="sm:col-span-2"
          maxLength={80}
          value={form.fact_nombre}
          onChange={set('fact_nombre')}
        />
        <Select label="Tipo de documento" value={form.doc_tipo} onChange={set('doc_tipo')}>
          <option value="cf">Consumidor final (sin identificar)</option>
          <option value="cuit">CUIT</option>
          <option value="dni">DNI</option>
        </Select>
        <Input
          label="Número de documento"
          inputMode="numeric"
          disabled={form.doc_tipo === 'cf'}
          value={form.doc_tipo === 'cf' ? '' : form.doc_nro}
          onChange={set('doc_nro')}
        />
        <Select
          label="Condición frente al IVA"
          className="sm:col-span-2"
          value={form.cond_iva}
          onChange={set('cond_iva')}
        >
          <option value="cf">Consumidor Final</option>
          <option value="ri">Responsable Inscripto</option>
          <option value="monotributo">Monotributo</option>
          <option value="exento">Exento</option>
        </Select>
      </div>
      <div className="mt-5 flex items-center gap-4">
        <Button type="submit" disabled={saving}>
          {saving && <Loader2 size={15} className="animate-spin" />}
          Guardar
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
      <AddressForm title="Dirección de facturación" initial={billing} withContact onSave={save('billing')} />
      <AddressForm title="Dirección de envío" initial={shipping} onSave={save('shipping')} />
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
      <h2 className="font-display text-xl font-semibold">Favoritos</h2>
      {favs.length === 0 ? (
        <div className="mt-6 rounded-xl3 border border-line glass p-12 text-center">
          <p className="text-ink-soft">Todavía no guardaste favoritos.</p>
          <Button className="mt-5" size="sm" to="/tienda">Explorar</Button>
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
        <p className="mt-4 text-ink-soft">No tenés descargas disponibles.</p>
        <p className="mt-1 text-xs text-ink-faint">Facturas y manuales aparecerán acá.</p>
      </Reveal>
    );
  }
  return (
    <Reveal>
      <h2 className="font-display text-xl font-semibold">Descargas</h2>
      <div className="mt-4 space-y-3">
        {downloads.map((d, i) => (
          <div key={`${d.url}-${i}`} className="flex flex-wrap items-center justify-between gap-4 rounded-xl2 border border-line glass p-4">
            <p className="font-medium">{d.name}</p>
            <Button size="sm" variant="secondary" href={d.url}>
              <Download size={15} /> Descargar
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
      setMsg({ ok: true, text: 'Datos guardados' });
    } catch (err) {
      setMsg({ ok: false, text: err?.data?.message || 'No se pudieron guardar los datos.' });
    } finally {
      setSaving(false);
    }
  };

  return (
    <Reveal className="max-w-lg">
      <h2 className="font-display text-xl font-semibold">Configuración</h2>
      <form onSubmit={submit} className="mt-5 space-y-5">
        <div className="grid gap-5 sm:grid-cols-2">
          <Input label="Nombre" autoComplete="given-name" value={form.first_name} onChange={set('first_name')} />
          <Input label="Apellido" autoComplete="family-name" value={form.last_name} onChange={set('last_name')} />
        </div>
        <Input label="Nombre visible" value={form.display_name} onChange={set('display_name')} />
        <Input label="Email" type="email" autoComplete="email" value={form.email} onChange={set('email')} required />
        <div className="space-y-5 rounded-xl2 border border-line p-5">
          <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">Cambiar contraseña</p>
          <Input
            label="Contraseña actual"
            type="password"
            autoComplete="current-password"
            placeholder="••••••••"
            value={form.current_password}
            onChange={set('current_password')}
          />
          <Input
            label="Nueva contraseña"
            type="password"
            autoComplete="new-password"
            placeholder="Mínimo 6 caracteres"
            minLength={6}
            value={form.new_password}
            onChange={set('new_password')}
          />
        </div>
        <div className="flex items-center gap-4">
          <Button type="submit" disabled={saving}>
            {saving && <Loader2 size={15} className="animate-spin" />}
            Guardar cambios
          </Button>
          <FormMessage msg={msg} />
        </div>
      </form>
    </Reveal>
  );
}
