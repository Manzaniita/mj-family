import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { Lock, Check, ChevronLeft, Loader2, AlertCircle, Zap, Pencil, Wallet } from 'lucide-react';
import { Section } from '@/components/layout/Layout';
import { Input, Select } from '@/components/ui/Input';
import { Button } from '@/components/ui/Button';
import { ProductImage } from '@/components/ui/ProductImage';
import { ReceiptUploader } from '@/components/ui/ReceiptUploader';
import { PaymentDetails } from '@/components/ui/PaymentDetails';
import { Reveal } from '@/components/motion/Reveal';
import ShippingStep from '@/components/checkout/ShippingStep';
import { useCart } from '@/store';
import { effectivePrice } from '@/lib/api';
import { IS_WP, WP, EXCHANGE_RATE, EXPRESS } from '@/lib/config';
import * as wc from '@/lib/wc';
import { formatPrice, formatUsd, FREE_SHIPPING_THRESHOLD, isValidCuit, cn } from '@/lib/format';
import { PAY, usePaymentMethod, selectedMethodId, findMethod, resolveAdjustment, usdTotalFor } from '@/lib/payments';
import { formFields, hasShipping, methodBySlug, missingFor, shippingTexts } from '@/lib/envios';

/* Cómo se llama el paso de envío. Lo decide el plugin; sin plugin, "Envío". */
const SHIPPING_TITLE = shippingTexts().stepTitle || 'Envío';

// Medios de pago: dentro de WordPress SOLO los gateways reales activos en
// WooCommerce (inyectados por PHP). En modo demo (standalone) se muestran
// opciones de ejemplo.
const demoMethods = [
  { id: 'card', title: 'Tarjeta de crédito/débito', description: 'Hasta 12 cuotas sin interés' },
  { id: 'mp', title: 'Mercado Pago', description: 'Saldo, tarjeta o QR' },
  { id: 'transfer', title: 'Transferencia', description: '10% de descuento extra' },
];
const paymentMethods = IS_WP
  ? (Array.isArray(WP?.paymentMethods) ? WP.paymentMethods : [])
  : demoMethods;

/** Radios con el mismo estilo de tarjeta que los medios de pago. */
function ChoiceGroup({ options, value, onChange }) {
  return (
    <div className="mt-3 space-y-2">
      {options.map((o) => (
        <button
          type="button"
          key={o.value}
          onClick={() => onChange(o.value)}
          className={cn(
            'mj-choice flex w-full items-center justify-between rounded-xl2 border p-4 text-left transition-all duration-200',
            value === o.value ? 'border-accent bg-accent-soft' : 'border-line hover:border-line-strong'
          )}
        >
          <div>
            <p className="text-sm font-medium">{o.title}</p>
            {o.description && <p className="text-xs text-ink-faint">{o.description}</p>}
          </div>
          <span
            className={cn(
              'grid size-5 shrink-0 place-items-center rounded-full border-2 transition-colors',
              value === o.value ? 'border-accent bg-accent' : 'border-line-strong'
            )}
          >
            {value === o.value && <Check size={11} className="text-white" strokeWidth={3} />}
          </span>
        </button>
      ))}
    </div>
  );
}

/**
 * Datos mínimos para poder cerrar un pedido sin preguntar nada más.
 *
 * Es la única condición de la compra rápida: si falta algo de esto, el atajo no aparece — mostrar
 * un botón que después va a fallar sería peor que pedir los datos de entrada.
 */
function isComplete(form) {
  const keys = ['firstName', 'lastName', 'email', 'phone', 'address', 'city', 'postcode'];
  /* Con envíos propios la provincia no es opcional: sin ella ningún correo cotiza. */
  if (hasShipping()) keys.push('state');
  return keys.every((key) => String(form[key] || '').trim() !== '');
}

/** Resumen compacto de a dónde va el pedido, con un botón para volver al formulario. */
function ExpressSummary({ form, onEdit }) {
  return (
    <div className="mj-panel rounded-xl3 border border-line glass p-7">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h2 className="font-display text-lg font-semibold tracking-tight">Tus datos</h2>
          <p className="mt-3 text-sm text-ink">{form.firstName} {form.lastName}</p>
          <p className="text-sm text-ink-soft">
            {[form.address, form.address2].filter(Boolean).join(', ')}
            {form.city ? `, ${form.city}` : ''}
            {form.postcode ? ` (${form.postcode})` : ''}
          </p>
          <p className="text-sm text-ink-soft">{form.email} · {form.phone}</p>
        </div>
        <button
          type="button"
          onClick={onEdit}
          className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-line px-3 py-2 text-xs font-medium text-ink-soft transition-colors hover:border-line-strong hover:text-ink"
        >
          <Pencil size={13} /> Cambiar
        </button>
      </div>
    </div>
  );
}

/** "1.500,50" o "1500.5" → 1500.5 */
function parseAmount(value) {
  const s = String(value || '').trim().replace(/[^\d.,]/g, '');
  if (!s) return 0;
  if (s.includes(',')) return Number(s.replace(/\./g, '').replace(',', '.')) || 0;
  if (/^\d{1,3}(\.\d{3})+$/.test(s)) return Number(s.replace(/\./g, '')) || 0;
  return Number(s) || 0;
}

/** Un importe en su moneda: la de la tienda como siempre, dólares como dólares. */
function moneyIn(amount, currency, shopCurrency) {
  if (!currency || currency === shopCurrency) return formatPrice(amount);
  if (currency === 'USD') return formatUsd(amount);
  return `${currency} ${amount}`;
}

/**
 * Saldo a favor del cliente (plugin MJ ERP → extensions.mjerp_credit): usarlo todo o una parte.
 * La cuenta la hace el servidor; acá solo se elige y se muestra lo que vuelve.
 */
function CreditBox({ credit }) {
  const syncCredit = useCart((s) => s.syncCredit);
  const syncing = useCart((s) => s.syncing);
  const [amount, setAmount] = useState('');
  if (!credit || !(credit.available > 0)) return null;

  const label = credit.label || 'Saldo a favor';
  const others = credit.balances.filter((b) => b.currency !== credit.currency);
  const apply = () => {
    const n = parseAmount(amount);
    syncCredit({ use: true, amount: n > 0 ? n : null });
  };

  return (
    <div className="mt-6 rounded-xl2 border border-line p-4">
      <button
        type="button"
        onClick={() => syncCredit({ use: !credit.use, amount: null })}
        disabled={syncing}
        className="flex w-full items-center justify-between gap-3 text-left"
      >
        <span className="flex items-center gap-3">
          <Wallet size={17} className="shrink-0 text-accent" />
          <span>
            <span className="block text-sm font-medium">Usar mi {label.toLowerCase()}</span>
            <span className="block text-xs text-ink-faint">
              Tenés {formatPrice(credit.available)}
              {others.length > 0 && ` (incluye ${others.map((b) => moneyIn(b.amount, b.currency, credit.currency)).join(' y ')} al dólar de hoy)`}
            </span>
          </span>
        </span>
        <span
          className={cn(
            'grid size-5 shrink-0 place-items-center rounded-md border-2 transition-colors',
            credit.use ? 'border-accent bg-accent' : 'border-line-strong'
          )}
        >
          {credit.use && <Check size={11} className="text-white" strokeWidth={3} />}
        </span>
      </button>
      {credit.use && (
        <div className="mt-4 border-t border-line pt-4">
          <p className="text-sm font-medium text-success" aria-live="polite">
            Se descuentan {formatPrice(credit.applied)} del total.
          </p>
          <div className="mt-3 flex items-end gap-2">
            <div className="min-w-0 flex-1">
              <Input
                label="¿Querés usar menos?"
                inputMode="decimal"
                placeholder={formatPrice(credit.max)}
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                onKeyDown={(e) => {
                  // Enter aplica el importe: no tiene que confirmar el pedido.
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    apply();
                  }
                }}
              />
            </div>
            <Button type="button" size="sm" variant="secondary" onClick={apply} disabled={syncing}>
              Aplicar
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

/** Checkout de una sola página, minimalista — crea la orden real en WooCommerce. */
export default function Checkout() {
  const { items, clear, syncing, server } = useCart();
  const cartShipping = useCart((s) => s.shipping);
  // Medio de pago: arranca con el que el visitante eligió para ver precios (plugin MJ Medios de Pago) si está disponible.
  const [payment, setPayment] = useState(() => {
    const preferred = selectedMethodId();
    return preferred && paymentMethods.some((m) => m.id === preferred) ? preferred : paymentMethods[0]?.id;
  });
  const [placedMethod, setPlacedMethod] = useState(null);
  const [placedUsd, setPlacedUsd] = useState(null);
  /* Cómo pagar el pedido hecho: instrucciones y datos (alias, CBU…) que manda el servidor. */
  const [payInfo, setPayInfo] = useState(null);
  const choosePayment = (id) => {
    setPayment(id);
    usePaymentMethod.getState().set(id); // Sincroniza los precios de la tienda y la línea de ajuste del carrito.
  };
  const [placed, setPlaced] = useState(false);
  /* Comprobante de pago: el token solo hace falta si compró sin cuenta. */
  const [receipt, setReceipt] = useState(null);
  const [placing, setPlacing] = useState(false);
  const [error, setError] = useState(null);
  const [orderId, setOrderId] = useState(null);
  const [form, setForm] = useState({
    firstName: '', lastName: '', email: '', phone: '',
    address: '', address2: '', city: '', state: '', postcode: '',
  });
  /*
   * Datos del envío que pide el método elegido (DNI de quien recibe, sucursal,
   * horario, lo que sea que esté configurado). Viven acá porque el pedido se
   * cierra desde este formulario, y se sincronizan con el servidor a medida que
   * se escriben.
   */
  const [extra, setExtra] = useState({});
  // Paso 4 · Facturación. fiscalCfg = config del emisor según el plugin
  // (null = plugin inactivo o todavía no respondió → el paso no se muestra).
  const [fiscalCfg, setFiscalCfg] = useState(null);
  /*
   * Compra rápida. `expressReady` se enciende cuando llegan datos guardados completos; `express`
   * es lo que se está mostrando ahora, y el cliente lo apaga apenas toca "Cambiar".
   */
  const [expressReady, setExpressReady] = useState(false);
  const [express, setExpress] = useState(false);
  const [factura, setFactura] = useState({
    solicita: false,
    tipo: 'B', // A | B — la C se impone sola según la condición del emisor
    receptor: 'mismo', // mismo | otro
    factNombre: '',
    docTipo: 'cf', // cf | cuit | dni
    docNro: '',
    condIva: 'cf', // cf | ri | monotributo | exento
  });

  // Autocompletar con los datos de la sesión: contacto/envío desde las
  // direcciones guardadas en Mi cuenta y perfil fiscal desde el plugin
  // (mjf/v1/fiscal es público → también trae la config del emisor).
  useEffect(() => {
    if (!IS_WP) return;
    wc.getFiscal()
      .then((data) => {
        setFiscalCfg(data?.emisor ?? null);
        const r = data?.receptor;
        if (r && (r.doc_nro || r.fact_nombre)) {
          setFactura((f) => ({
            ...f,
            factNombre: r.fact_nombre || '',
            docTipo: r.doc_tipo || 'cf',
            docNro: r.doc_nro || '',
            condIva: r.cond_iva || 'cf',
            tipo: r.doc_tipo === 'cuit' && r.cond_iva === 'ri' ? 'A' : f.tipo,
          }));
        }
      })
      .catch(() => {});
    if (!WP?.user) return;
    wc.getAccount()
      .then((acc) => {
        const b = acc?.addresses?.billing ?? {};
        /* La dirección de envío manda si está cargada; si no, la de facturación. */
        const s = acc?.addresses?.shipping ?? {};
        const pick = (key) => s[key] || b[key] || '';
        const filled = {
          firstName: pick('first_name'),
          lastName: pick('last_name'),
          email: b.email || WP.user.email || '',
          phone: b.phone || '',
          address: pick('address_1'),
          address2: pick('address_2'),
          city: pick('city'),
          state: pick('state'),
          postcode: pick('postcode'),
        };

        setForm((f) => {
          const merged = { ...f };
          Object.keys(filled).forEach((key) => {
            if (!String(merged[key] || '').trim()) merged[key] = filled[key];
          });
          return merged;
        });

        if (EXPRESS.enabled && isComplete(filled)) {
          setExpressReady(true);
          setExpress(EXPRESS.auto);
          // El envío se calcula ya mismo: en modo rápido no hay un blur del CP que lo dispare, y
          // el total tiene que estar bien antes de que alguien apriete el botón.
          prefetchShipping(filled);
        }
      })
      .catch(() => {});
  }, []);

  const subtotal = items.reduce((n, i) => n + effectivePrice(i.product) * i.qty, 0);
  // Envío: gratis sobre el umbral; si no, costo real del servidor (null = a calcular).
  const freeShip = FREE_SHIPPING_THRESHOLD > 0 && subtotal >= FREE_SHIPPING_THRESHOLD;
  const shippingCost = freeShip ? 0 : cartShipping?.cost ?? null;
  // Ajuste por medio de pago (plugin MJ Medios de Pago) + otras fees del servidor.
  const payMethod = findMethod(payment);
  const adjustment = resolveAdjustment({ items, method: payMethod, server, syncing, live: IS_WP });
  const otherFees = IS_WP ? (server?.fees || []).filter((f) => !adjustment || f.name !== adjustment.label) : [];
  const total = subtotal + (shippingCost ?? 0) + (adjustment?.amount ?? 0) + otherFees.reduce((n, f) => n + f.amount, 0);
  const usdTotal = usdTotalFor(total, payMethod);
  const shippingRates = cartShipping?.rates ?? [];
  const chosenRate = shippingRates.find((r) => r.selected) ?? null;
  const noGateways = IS_WP && paymentMethods.length === 0;

  // Tipo de comprobante resultante (misma lógica que el plugin): emisor RI
  // deja elegir A/B salvo config forzada; monotributo/exento siempre emite C.
  const cbteForzado = fiscalCfg && fiscalCfg.tipo_cbte_default !== 'auto' ? Number(fiscalCfg.tipo_cbte_default) : null;
  const esEmisorRI = fiscalCfg?.condicion_iva === 'ri';
  const puedeElegirTipo = esEmisorRI && !cbteForzado;
  const cbteTipo = cbteForzado ?? (esEmisorRI ? (factura.tipo === 'A' ? 1 : 6) : 11);
  const cbteLetra = { 1: 'A', 6: 'B', 11: 'C' }[cbteTipo];
  const esA = cbteTipo === 1;

  const field = (key) => ({
    value: form[key],
    onChange: (e) => setForm((f) => ({ ...f, [key]: e.target.value })),
  });

  /**
   * Al completar el CP pedimos al servidor las tarifas de envío reales
   * para la dirección cargada; si ninguna queda seleccionada, elegimos
   * la primera disponible.
   */
  /**
   * Pide las tarifas de envío para una dirección.
   *
   * Pasa por el store (y no suelto a la Store API) porque la respuesta trae las
   * tarifas nuevas: sin adoptarlas, el resumen seguiría mostrando las del
   * destino anterior. Si ninguna quedó elegida, se toma la primera.
   */
  const syncShippingRates = async (data = form) => {
    if (!IS_WP || String(data.postcode || '').trim().length < 4) return;
    const { syncAddress, selectRate } = useCart.getState();
    await syncAddress({
      postcode: data.postcode,
      state: data.state || '',
      city: data.city || '',
      address_1: data.address || '',
      country: WP?.baseCountry,
    });
    const rates = useCart.getState().shipping?.rates ?? [];
    if (rates.length && !rates.some((r) => r.selected)) {
      await selectRate(rates[0].rate_id);
    }
  };

  /** Compra rápida: el envío se calcula ya, con los datos que ya teníamos. */
  const prefetchShipping = (data) => syncShippingRates(data);

  if (placed) {
    /* Mientras llega la respuesta del servidor, lo que ya se sabía del medio elegido. */
    const howTo = payInfo ?? {
      instructions: placedMethod?.instructions || '',
      detailsTitle: placedMethod?.detailsTitle || '',
      details: placedMethod?.details || [],
    };
    const hasHowTo = !!howTo.instructions || howTo.details?.length > 0;
    /* Cómo se llaman el bloque y el botón de copiar: lo configura MJ Medios de Pago. */
    const howToTexts = { ...(PAY?.texts || {}), ...(howTo.texts || {}) };
    return (
      <Section className="grid min-h-[60vh] place-items-center">
        <motion.div
          initial={{ opacity: 0, scale: 0.9 }}
          animate={{ opacity: 1, scale: 1 }}
          className="max-w-md text-center"
        >
          <motion.span
            initial={{ scale: 0 }}
            animate={{ scale: 1 }}
            transition={{ type: 'spring', stiffness: 300, damping: 18, delay: 0.15 }}
            className="mx-auto grid size-20 place-items-center rounded-full bg-success/15 text-success"
          >
            <Check size={34} strokeWidth={2.5} />
          </motion.span>
          <h1 className="display mt-8 text-4xl">¡Pedido confirmado!</h1>
          <p className="mt-4 text-ink-soft">
            {orderId ? <>Tu orden <span className="font-semibold text-ink">#{orderId}</span> fue creada. </> : null}
            Te enviamos el detalle por email.
          </p>
          {placedUsd != null && EXCHANGE_RATE && (
            <p className="mt-4 text-ink-soft">
              Total a pagar: <span className="font-semibold text-ink">{formatUsd(placedUsd)}</span> al dólar {EXCHANGE_RATE.formatted}
            </p>
          )}
          {hasHowTo && (
            <div className="mj-panel mt-6 rounded-xl2 border border-line glass p-5 text-left">
              <p className="text-xs font-semibold uppercase tracking-[0.15em] text-ink-faint">
                {howToTexts.howTo || 'Cómo pagar'}{placedMethod || howTo.title ? ` · ${placedMethod?.short || placedMethod?.title || howTo.title}` : ''}
              </p>
              {howTo.instructions && (
                <p className="mt-2 whitespace-pre-line text-sm leading-relaxed text-ink-soft">{howTo.instructions}</p>
              )}
              <PaymentDetails
                title={howTo.detailsTitle}
                rows={howTo.details}
                texts={howToTexts}
                className={howTo.instructions ? 'mt-4 border-t border-line pt-4' : 'mt-4'}
              />
            </div>
          )}
          {receipt && (
            <div className="mj-panel mt-6 rounded-xl2 border border-line glass p-5 text-left">
              <ReceiptUploader objectId={receipt.objectId} token={receipt.token} />
            </div>
          )}
          <div className="mt-8 flex justify-center gap-3">
            <Button to="/cuenta/pedidos" variant="secondary">Ver mis pedidos</Button>
            <Button to="/tienda">Seguir comprando</Button>
          </div>
        </motion.div>
      </Section>
    );
  }

  if (items.length === 0) {
    return (
      <Section className="grid min-h-[50vh] place-items-center text-center">
        <div>
          <h1 className="display text-3xl">No hay nada para pagar</h1>
          <Button className="mt-6" to="/tienda">Ir a la tienda</Button>
        </div>
      </Section>
    );
  }

  const submit = async (e) => {
    e.preventDefault();
    setError(null);

    if (!IS_WP) {
      setPlaced(true);
      clear();
      window.scrollTo({ top: 0 });
      return;
    }

    // Validación del paso 4 (el servidor la repite al guardar la orden).
    let additionalFields = null;
    if (fiscalCfg && factura.solicita) {
      const docTipo = esA ? 'cuit' : factura.docTipo;
      const docNro = factura.docNro.replace(/\D+/g, '').slice(0, 11);
      if (factura.receptor === 'otro' && !factura.factNombre.trim()) {
        setError('Cargá el nombre o razón social del receptor de la factura.');
        return;
      }
      if (docTipo === 'cuit' && !isValidCuit(docNro)) {
        setError(
          docNro.length === 11
            ? 'El CUIT/CUIL del receptor no es válido: revisá los números, hay algún dígito incorrecto.'
            : 'El CUIT/CUIL del receptor debe tener 11 dígitos.'
        );
        return;
      }
      if (docTipo === 'dni' && (docNro.length < 7 || docNro.length > 8)) {
        setError('El DNI del receptor debe tener 7 u 8 dígitos.');
        return;
      }
      additionalFields = {
        'mjf/solicita': true,
        'mjf/a_nombre': factura.receptor,
        // Receptor "mismo": el nombre sale de los datos de facturación del pedido.
        'mjf/fact_nombre': factura.receptor === 'otro' ? factura.factNombre.trim().slice(0, 80) : '',
        'mjf/doc_tipo': docTipo,
        'mjf/doc_nro': docTipo === 'cf' ? '' : docNro,
        'mjf/cond_iva': esA ? 'ri' : factura.condIva,
      };
    }

    /*
     * Envío: que esté elegido y con todos sus datos. El servidor lo valida de
     * nuevo, pero avisar acá es mucho mejor que un error después de apretar.
     */
    if (hasShipping()) {
      const rate = (cartShipping?.rates ?? []).find((r) => r.selected);
      if (!rate) {
        setError('Elegí cómo querés recibir tu compra.');
        return;
      }
      const missing = missingFor(rate.slug ? methodBySlug(rate.slug) : null, {
        ...extra,
        postcode: form.postcode,
        state: form.state,
        city: form.city,
        address_1: form.address,
        address_2: form.address2,
      });
      if (missing.length) {
        setError(`Para poder enviarte la compra falta: ${missing.join(', ')}.`);
        return;
      }
      /* Lo último que escribió puede estar esperando su turno: se manda ahora. */
      await useCart.getState().syncShipping({
        fields: { ...formFields(form), ...extra },
        branch: extra.branch || '',
        city: form.city || '',
      });
    }

    setPlacing(true);
    try {
      const order = await wc.placeOrder({ billing: form, paymentMethod: payment, additionalFields });
      const redirect = order?.payment_result?.redirect_url;
      if (redirect && !redirect.includes('order-received')) {
        // Pasarelas externas (p. ej. Mercado Pago) requieren redirección
        window.location.assign(redirect);
        return;
      }
      setOrderId(order?.order_id ?? null);
      setPlacedMethod(payMethod);
      setPlacedUsd(usdTotal);
      setPlaced(true);

      /*
       * El comprobante se sube DESPUÉS de cerrar el pedido: el archivo necesita
       * un pedido al que pegarse. El servidor decide si este medio de pago lo
       * pide; acá solo se pregunta, y el token autoriza a quien compró sin cuenta.
       */
      if (order?.order_id && order?.order_key) {
        wc.getPaymentInfo(order.order_id, order.order_key)
          .then((info) => { if (info) setPayInfo(info); })
          .catch(() => { /* Sin el plugin: quedan las instrucciones que ya se conocían. */ });
        wc.getReceiptToken(order.order_id, order.order_key)
          .then((res) => {
            if (res?.asks) setReceipt({ objectId: res.objectId, token: res.token, noun: res.noun });
          })
          .catch(() => { /* Sin comprobante: el pedido ya está hecho igual. */ });
      }
      clear();
      window.scrollTo({ top: 0 });
    } catch (err) {
      setError(err.message || 'No pudimos procesar el pedido. Revisá los datos e intentá de nuevo.');
    } finally {
      setPlacing(false);
    }
  };

  return (
    <Section className="md:py-16">
      <Reveal>
        <Link to="/carrito" className="inline-flex items-center gap-1.5 text-sm text-ink-soft hover:text-ink">
          <ChevronLeft size={15} /> Volver al carrito
        </Link>
        <h1 className="display mt-4 text-4xl md:text-5xl">Checkout</h1>
        <p className="mt-2 flex items-center gap-1.5 text-sm text-ink-soft">
          <Lock size={13} className="text-success" /> Pago 100% seguro y encriptado
        </p>
      </Reveal>

      {expressReady && !express && (
        <Reveal className="mt-8">
          <button
            type="button"
            onClick={() => setExpress(true)}
            className="mj-panel flex w-full items-center justify-between gap-4 rounded-xl3 border border-accent/40 bg-accent-soft p-5 text-left transition-colors hover:border-accent"
          >
            <span className="flex items-center gap-3">
              <Zap size={18} className="shrink-0 text-accent" />
              <span>
                <span className="block text-sm font-semibold">Comprar rápido</span>
                <span className="block text-xs text-ink-soft">Usamos los datos que ya tenemos y salteás los pasos.</span>
              </span>
            </span>
            <span className="shrink-0 text-sm font-medium text-accent">Usar</span>
          </button>
        </Reveal>
      )}

      <form onSubmit={submit} className="mt-10 grid gap-10 lg:grid-cols-[1fr_400px]">
        <div className="space-y-8">
          {express ? (
            <Reveal>
              <ExpressSummary form={form} onEdit={() => setExpress(false)} />
            </Reveal>
          ) : (
          <>
          <Reveal className="mj-panel rounded-xl3 border border-line glass p-7">
            <h2 className="font-display text-lg font-semibold tracking-tight">1 · Contacto</h2>
            <div className="mt-5 grid gap-4 sm:grid-cols-2">
              <Input label="Nombre" required placeholder="María" autoComplete="given-name" {...field('firstName')} />
              <Input label="Apellido" required placeholder="González" autoComplete="family-name" {...field('lastName')} />
              <Input label="Email" type="email" required placeholder="tu@email.com" className="sm:col-span-2" autoComplete="email" {...field('email')} />
              <Input label="Teléfono" type="tel" required placeholder="+54 11 ..." className="sm:col-span-2" autoComplete="tel" {...field('phone')} />
            </div>
          </Reveal>

          {hasShipping() ? (
            <Reveal>
              <ShippingStep
                title={`2 · ${SHIPPING_TITLE}`}
                form={form}
                setForm={setForm}
                extra={extra}
                setExtra={setExtra}
              />
            </Reveal>
          ) : (
            <Reveal className="mj-panel rounded-xl3 border border-line glass p-7">
              <h2 className="font-display text-lg font-semibold tracking-tight">2 · Envío</h2>
              <div className="mt-5 grid gap-4 sm:grid-cols-2">
                <Input label="Dirección" required placeholder="Calle y número" className="sm:col-span-2" autoComplete="street-address" {...field('address')} />
                <Input label="Ciudad" required placeholder="Ciudad" autoComplete="address-level2" {...field('city')} />
                <Input label="Código postal" required placeholder="CP" autoComplete="postal-code" {...field('postcode')} onBlur={() => syncShippingRates()} />
              </div>
            </Reveal>
          )}
          </>
          )}

          {/*
            * Compra rápida con envíos propios: los datos de contacto y la
            * dirección quedan resumidos arriba, pero la forma de envío hay que
            * elegirla igual — y puede pedir una sucursal o un DNI.
            */}
          {express && hasShipping() && (
            <Reveal>
              <ShippingStep
                title={SHIPPING_TITLE}
                form={form}
                setForm={setForm}
                extra={extra}
                setExtra={setExtra}
              />
            </Reveal>
          )}

          <Reveal className="mj-panel rounded-xl3 border border-line glass p-7">
            <h2 className="font-display text-lg font-semibold tracking-tight">{express ? 'Pago' : '3 · Pago'}</h2>
            {noGateways ? (
              <div className="mt-5 flex items-start gap-3 rounded-xl2 border border-warning/30 bg-warning/10 p-4 text-sm text-warning">
                <AlertCircle size={17} className="mt-0.5 shrink-0" />
                <p>No hay métodos de pago configurados. Contactanos para finalizar la compra.</p>
              </div>
            ) : (
            <div className="mt-5 space-y-3">
              {paymentMethods.map((m) => (
                <button
                  type="button"
                  key={m.id}
                  onClick={() => choosePayment(m.id)}
                  className={cn(
                    'mj-choice flex w-full items-center justify-between rounded-xl2 border p-4 text-left transition-all duration-200',
                    payment === m.id
                      ? 'border-accent bg-accent-soft'
                      : 'border-line hover:border-line-strong'
                  )}
                >
                  <div>
                    <p className="text-sm font-medium">{m.title}</p>
                    {m.description && <p className="text-xs text-ink-faint">{m.description}</p>}
                  </div>
                  <span className={cn(
                    'grid size-5 shrink-0 place-items-center rounded-full border-2 transition-colors',
                    payment === m.id ? 'border-accent bg-accent' : 'border-line-strong'
                  )}>
                    {payment === m.id && <Check size={11} className="text-white" strokeWidth={3} />}
                  </span>
                </button>
              ))}
            </div>
            )}
            {/* Datos para pagar del medio elegido: solo vienen si el admin los muestra en el checkout. */}
            {payMethod?.details?.length > 0 && (
              <PaymentDetails
                title={payMethod.detailsTitle}
                rows={payMethod.details}
                texts={PAY?.texts}
                className="mt-4 rounded-xl2 border border-line p-4"
              />
            )}
            {adjustment && (
              <p className={cn('mt-4 text-sm font-medium', adjustment.amount < 0 ? 'text-success' : 'text-ink-soft')} aria-live="polite">
                {adjustment.amount < 0
                  ? `Ahorrás ${formatPrice(-adjustment.amount)} pagando con ${payMethod?.short || payMethod?.title}.`
                  : `Recargo de ${formatPrice(adjustment.amount)} por pagar con ${payMethod?.short || payMethod?.title}.`}
              </p>
            )}
            {IS_WP && <CreditBox credit={server?.credit} />}
          </Reveal>

          {fiscalCfg && (
            <Reveal className="mj-panel rounded-xl3 border border-line glass p-7">
              <h2 className="font-display text-lg font-semibold tracking-tight">{express ? 'Facturación' : '4 · Facturación'}</h2>
              <ChoiceGroup
                options={[
                  { value: 'no', title: 'No necesito factura' },
                  { value: 'si', title: 'Sí, necesito factura', description: 'Se emite al completarse el pedido' },
                ]}
                value={factura.solicita ? 'si' : 'no'}
                onChange={(v) => {
                  setFactura((f) => ({ ...f, solicita: v === 'si' }));
                  // Pedir factura vuelve a abrir el formulario: hay datos fiscales que confirmar.
                  if (v === 'si' && express) setExpress(false);
                }}
              />

              {factura.solicita && (
                <div className="mt-6 space-y-6 border-t border-line pt-6">
                  {puedeElegirTipo ? (
                    <div>
                      <p className="text-xs font-medium uppercase tracking-wider text-ink-faint">Tipo de factura</p>
                      <ChoiceGroup
                        options={[
                          { value: 'A', title: 'Factura A', description: 'Responsable Inscripto — requiere CUIT' },
                          { value: 'B', title: 'Factura B', description: 'Consumidor final, monotributo o exento' },
                        ]}
                        value={factura.tipo}
                        onChange={(v) => setFactura((f) => ({ ...f, tipo: v }))}
                      />
                    </div>
                  ) : (
                    <p className="rounded-xl2 border border-line bg-surface-2 p-3.5 text-sm text-ink-soft">
                      Se emitirá <span className="font-semibold text-ink">Factura {cbteLetra}</span> con los datos que cargues acá.
                    </p>
                  )}

                  <div>
                    <p className="text-xs font-medium uppercase tracking-wider text-ink-faint">Receptor de la factura</p>
                    <ChoiceGroup
                      options={[
                        {
                          value: 'mismo',
                          title: 'Los mismos datos de la compra',
                          description: `${form.firstName} ${form.lastName}`.trim() || undefined,
                        },
                        { value: 'otro', title: 'Otra persona o empresa' },
                      ]}
                      value={factura.receptor}
                      onChange={(v) => setFactura((f) => ({ ...f, receptor: v }))}
                    />
                  </div>

                  <div className="grid gap-4 sm:grid-cols-2">
                    {factura.receptor === 'otro' && (
                      <Input
                        label={esA ? 'Razón social' : 'Nombre o razón social'}
                        placeholder={esA ? 'Empresa S.A.' : 'Nombre completo o razón social'}
                        className="sm:col-span-2"
                        maxLength={80}
                        value={factura.factNombre}
                        onChange={(e) => setFactura((f) => ({ ...f, factNombre: e.target.value }))}
                      />
                    )}
                    {esA ? (
                      <Input
                        label="CUIT del receptor"
                        inputMode="numeric"
                        placeholder="30-12345678-9"
                        className="sm:col-span-2"
                        value={factura.docNro}
                        onChange={(e) => setFactura((f) => ({ ...f, docNro: e.target.value }))}
                      />
                    ) : (
                      <>
                        <Select
                          label="Tipo de documento"
                          value={factura.docTipo}
                          onChange={(e) => setFactura((f) => ({ ...f, docTipo: e.target.value }))}
                        >
                          <option value="cf">Consumidor final (sin identificar)</option>
                          <option value="dni">DNI</option>
                          <option value="cuit">CUIT</option>
                        </Select>
                        <Input
                          label="Número de documento"
                          inputMode="numeric"
                          placeholder={factura.docTipo === 'cuit' ? '30-12345678-9' : '12345678'}
                          disabled={factura.docTipo === 'cf'}
                          value={factura.docTipo === 'cf' ? '' : factura.docNro}
                          onChange={(e) => setFactura((f) => ({ ...f, docNro: e.target.value }))}
                        />
                        <Select
                          label="Condición frente al IVA"
                          className="sm:col-span-2"
                          value={factura.condIva}
                          onChange={(e) => setFactura((f) => ({ ...f, condIva: e.target.value }))}
                        >
                          <option value="cf">Consumidor Final</option>
                          <option value="ri">Responsable Inscripto</option>
                          <option value="monotributo">Monotributo</option>
                          <option value="exento">Exento</option>
                        </Select>
                      </>
                    )}
                  </div>
                </div>
              )}
            </Reveal>
          )}

          {error && (
            <Reveal className="flex items-start gap-3 rounded-xl2 border border-danger/30 bg-danger/10 p-4 text-sm text-danger">
              <AlertCircle size={17} className="mt-0.5 shrink-0" />
              <p>{error}</p>
            </Reveal>
          )}
        </div>

        {/* Resumen */}
        <Reveal delay={0.15} className="lg:sticky lg:top-44 lg:self-start">
          <div className="mj-panel rounded-xl3 border border-line glass p-7">
            <h2 className="font-display text-lg font-semibold tracking-tight">Tu pedido</h2>
            <ul className="mt-5 space-y-3">
              {items.map(({ product, qty }) => (
                <li key={product.id} className="flex items-center gap-3">
                  <div className="relative">
                    <ProductImage product={product} className="size-14 rounded-xl" />
                    <span className="absolute -right-1.5 -top-1.5 grid size-5 place-items-center rounded-full bg-surface-2 border border-line text-[10px] font-bold">
                      {qty}
                    </span>
                  </div>
                  <p className="min-w-0 flex-1 truncate text-sm font-medium">{product.name}</p>
                  <p className="text-sm font-medium">{formatPrice(effectivePrice(product) * qty)}</p>
                </li>
              ))}
            </ul>
            {/* Con MJ Envíos, la forma de envío se elige en su paso: acá no se repite. */}
            {!hasShipping() && shippingRates.length > 0 && (
              <div className="mt-5 space-y-2 border-t border-line pt-5">
                <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint">Método de envío</p>
                {shippingRates.map((r) => (
                  <button
                    type="button"
                    key={r.rate_id}
                    onClick={() => useCart.getState().selectRate(r.rate_id)}
                    className={cn(
                      'flex w-full items-center justify-between gap-3 rounded-xl2 border p-3 text-left text-sm transition-all duration-200',
                      r.selected ? 'border-accent bg-accent-soft' : 'border-line hover:border-line-strong'
                    )}
                  >
                    <span className="flex items-center gap-2.5">
                      <span className={cn(
                        'grid size-4 shrink-0 place-items-center rounded-full border-2 transition-colors',
                        r.selected ? 'border-accent bg-accent' : 'border-line-strong'
                      )}>
                        {r.selected && <Check size={9} className="text-white" strokeWidth={3} />}
                      </span>
                      {r.name}
                    </span>
                    <span className="font-medium">{r.cost > 0 ? formatPrice(r.cost) : 'GRATIS'}</span>
                  </button>
                ))}
              </div>
            )}
            <dl className="mt-6 space-y-2.5 border-t border-line pt-5 text-sm">
              <div className="flex justify-between"><dt className="text-ink-soft">Subtotal</dt><dd>{formatPrice(subtotal)}</dd></div>
              <div className="flex justify-between gap-3">
                <dt className="text-ink-soft">
                  Envío
                  {chosenRate && <span className="block text-xs text-ink-faint">{chosenRate.name}</span>}
                </dt>
                <dd className={shippingCost === 0 && !chosenRate?.coordinate ? 'font-semibold text-success' : ''}>
                  {chosenRate?.coordinate
                    ? 'A coordinar'
                    : shippingCost == null
                      ? 'Se calcula al finalizar'
                      : shippingCost === 0
                        ? 'GRATIS'
                        : formatPrice(shippingCost)}
                </dd>
              </div>
              {adjustment && (
                <div className="flex justify-between gap-3">
                  <dt className="text-ink-soft">{adjustment.label}</dt>
                  <dd className={adjustment.amount < 0 ? 'font-semibold text-success' : ''}>
                    {adjustment.amount < 0 ? '- ' : '+ '}{formatPrice(Math.abs(adjustment.amount))}
                  </dd>
                </div>
              )}
              {otherFees.map((f) => (
                <div key={f.id || f.name} className="flex justify-between gap-3">
                  <dt className="text-ink-soft">{f.name}</dt>
                  <dd>{f.amount < 0 ? '- ' : '+ '}{formatPrice(Math.abs(f.amount))}</dd>
                </div>
              ))}
              <div className="flex items-baseline justify-between border-t border-line pt-4">
                <dt className="font-medium">Total</dt>
                <dd className="font-display text-2xl font-semibold">{formatPrice(total)}</dd>
              </div>
              {usdTotal != null && EXCHANGE_RATE && (
                <div className="flex items-baseline justify-between">
                  <dt className="text-ink-soft">En dólares</dt>
                  <dd className="font-semibold">{formatUsd(usdTotal)} <span className="text-xs font-normal text-ink-faint">al dólar {EXCHANGE_RATE.formatted}</span></dd>
                </div>
              )}
            </dl>
            <Button type="submit" size="lg" className="mt-6 w-full" disabled={placing || noGateways}>
              {placing ? <Loader2 size={15} className="animate-spin" /> : (express ? <Zap size={15} /> : <Lock size={15} />)}
              {placing ? 'Procesando…' : `${express ? EXPRESS.label : 'Pagar'} ${formatPrice(total)}`}
            </Button>
            {express && (
              <p className="mt-3 text-center text-xs text-ink-faint">
                Se envía a {form.address}, {form.city}.
              </p>
            )}
          </div>
        </Reveal>
      </form>
    </Section>
  );
}
