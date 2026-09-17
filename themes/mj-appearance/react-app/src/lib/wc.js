/**
 * Cliente de la WooCommerce Store API (wp-json/wc/store/v1).
 * Productos, categorías, carrito con sesión del servidor y checkout real.
 */
import { WP } from './config';

/**
 * Nonces vivos.
 *
 * Un nonce de WordPress depende del usuario Y del token de sesión, así que los
 * que vinieron en window.MJ dejan de valer apenas alguien entra o sale de su
 * cuenta. Por eso NO se leen fijo de WP: se guardan acá y se actualizan solos
 * (con la respuesta del login/logout, con la cabecera Nonce del carrito, o
 * pidiendo mj/v1/session cuando el server rechaza uno vencido).
 */
let nonce = WP?.nonce ?? null;     // Store API (carrito, checkout).
let wpNonce = WP?.wpNonce ?? null; // Endpoints propios (mj/v1 y otros plugins MJ).

/** Guarda los nonces que devuelve cualquier endpoint que los informe. */
export function setNonces(payload) {
  if (payload?.nonce) nonce = payload.nonce;
  if (payload?.wpNonce) wpNonce = payload.wpNonce;
  return payload;
}

/** Pide nonces nuevos a mj/v1/session. Devuelve null si no se pudo. */
async function refreshNonces() {
  try {
    const res = await fetch(`${WP.restUrl}mj/v1/session`, { credentials: 'same-origin' });
    if (!res.ok) return null;
    return setNonces(await res.json());
  } catch {
    return null;
  }
}

/** Un 401/403 por nonce vencido se puede reintentar una vez con uno nuevo. */
const isStaleNonce = (status, data) =>
  (status === 401 || status === 403) &&
  /nonce|cookie/i.test(`${data?.code || ''} ${data?.message || ''}`);

async function req(path, { method = 'GET', body } = {}, retry = true) {
  const res = await fetch(`${WP.restUrl}wc/store/v1${path}`, {
    method,
    credentials: 'same-origin',
    headers: {
      ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...(nonce ? { Nonce: nonce } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const fresh = res.headers.get('Nonce');
  if (fresh) nonce = fresh;
  const data = await res.json().catch(() => null);
  if (!res.ok) {
    if (retry && isStaleNonce(res.status, data) && (await refreshNonces())) {
      return req(path, { method, body }, false);
    }
    throw Object.assign(new Error(data?.message || `Store API ${res.status}`), { data, status: res.status });
  }
  return data;
}

/* ------------------------------ Mapeos ----------------------------------- */

// La Store API devuelve textos con entidades HTML (&#8211;, &amp;, …):
// hay que decodificarlas además de quitar las etiquetas.
const decodeEntities = (s) => {
  if (!s || !s.includes('&')) return s || '';
  const el = document.createElement('textarea');
  el.innerHTML = s;
  return el.value;
};

const stripHtml = (html) => decodeEntities((html || '').replace(/<[^>]*>/g, ' ')).replace(/\s+/g, ' ').trim();

const hueFromSlug = (slug) => {
  let h = 0;
  for (const ch of slug || '') h = (h * 31 + ch.charCodeAt(0)) % 360;
  return h;
};

const toAmount = (value, minorUnit) => Number(value || 0) / 10 ** (minorUnit ?? 2);

/*
 * Una imagen de la Store API con lo que hace falta para que el navegador
 * elija el tamaño justo: la grilla del celular no tiene por qué bajar la foto
 * de 2000 px. `srcset` y `sizes` los arma WooCommerce con sus tamaños.
 */
const mapImage = (i) => ({
  src: i?.src ?? null,
  srcset: i?.srcset || '',
  sizes: i?.sizes || '',
  alt: i?.alt || '',
});

function findBrand(p) {
  const attr = (p.attributes || []).find((a) => /marca|brand/i.test(a.name || ''));
  const term = attr?.terms?.[0];
  return term ? { name: term.name, slug: term.slug } : null;
}

export function mapProduct(p) {
  const u = p.prices?.currency_minor_unit ?? 2;
  const regular = toAmount(p.prices?.regular_price, u);
  const current = toAmount(p.prices?.price, u);
  const onSale = p.on_sale && current < regular;
  const brand = findBrand(p);
  const catSlugs = (p.categories || []).map((c) => c.slug);
  const tags = (p.tags || []).map((t) => t.slug);

  return {
    id: p.id,
    name: stripHtml(p.name),
    slug: p.slug,
    sku: p.sku || '',
    stock_quantity: null, // se completa con /catalog-stock (la Store API no lo expone)
    price: regular || current,
    regular_price: regular || current,
    sale_price: onSale ? current : null,
    stock_status: p.is_in_stock ? 'instock' : 'outofstock',
    featured: tags.includes('destacado') || tags.includes('featured'),
    is_new: tags.includes('nuevo') || tags.includes('new'),
    rating: Number(p.average_rating) || 0,
    rating_count: p.review_count || 0,
    tagline: stripHtml(p.short_description).slice(0, 90),
    shortDesc: stripHtml(p.short_description),
    description: stripHtml(p.description) || stripHtml(p.short_description),
    specs: (p.attributes || []).map((a) => [a.name, (a.terms || []).map((t) => t.name).join(', ')]),
    category: catSlugs[0] ?? 'general',
    categories: catSlugs,
    brand: brand?.slug ?? null,
    brandName: brand?.name ?? null,
    // Tiempo de entrega por SKU (plugin MJ Tiempos de Entrega → extensions.mjdt).
    delivery: p.extensions?.mjdt?.delivery || '',
    variationDelivery: Object.fromEntries(
      (p.extensions?.mjdt?.variations || []).map((v) => [v.id, v.delivery])
    ),
    // Precio en dólares (plugin MJ Dólar → extensions.mjd). null = se vende a precio fijo en pesos.
    // En variables, usd es el mínimo entre variaciones; el detalle por variación va en variationUsd.
    usd: p.extensions?.mjd?.usd ?? null,
    usd_sale: p.extensions?.mjd?.usd_sale ?? null,
    variationUsd: Object.fromEntries(
      (p.extensions?.mjd?.variations || []).map((v) => [v.id, { usd: v.usd ?? null, usd_sale: v.usd_sale ?? null }])
    ),
    // ¿Participa de descuentos/recargos por medio de pago? (plugin MJ Medios de Pago → extensions.mjpm)
    paymentEligible: p.extensions?.mjpm?.eligible !== false,
    // Quién lo hizo (plugin MJ Colegas → extensions.mjc). null = producto propio de la tienda.
    colega: p.extensions?.mjc?.slug || null,
    colegaName: p.extensions?.mjc?.name || null,
    hue: hueFromSlug(p.slug),
    image: p.images?.[0]?.src ?? null,
    imageSet: mapImage(p.images?.[0]),
    gallery: (p.images || []).map((i) => i.src),
    galleryImages: (p.images || []).map(mapImage),
    permalink: p.permalink,
    type: p.type,
    // Variaciones (productos variables): opciones + combinaciones válidas
    variations: (p.variations || []).map((v) => ({
      id: v.id,
      attributes: (v.attributes || []).map((a) => ({ name: a.name, value: a.value })),
    })),
    variation_attributes: (p.attributes || [])
      .filter((a) => a.has_variations)
      .map((a) => ({
        name: a.name,
        terms: (a.terms || []).map((t) => ({ name: t.name, slug: t.slug })),
      })),
  };
}

/** Producto mínimo construido desde un item del carrito (p. ej. variaciones). */
export function mapCartItemProduct(it) {
  const u = it.prices?.currency_minor_unit ?? 2;
  const regular = toAmount(it.prices?.regular_price, u);
  const current = toAmount(it.prices?.price, u);
  return {
    id: it.id,
    name: stripHtml(it.name),
    slug: it.permalink ? new URL(it.permalink).pathname.split('/').filter(Boolean).pop() : String(it.id),
    price: regular || current,
    regular_price: regular || current,
    sale_price: current < regular ? current : null,
    stock_status: 'instock',
    hue: hueFromSlug(it.name),
    image: it.images?.[0]?.src ?? null,
    imageSet: mapImage(it.images?.[0]),
    gallery: (it.images || []).map((i) => i.src),
    galleryImages: (it.images || []).map(mapImage),
    rating: 0,
    rating_count: 0,
    specs: [],
    category: 'general',
    categories: [],
    brand: null,
    paymentEligible: it.extensions?.mjpm?.eligible !== false,
    colega: it.extensions?.mjc?.slug || null,
    colegaName: it.extensions?.mjc?.name || null,
  };
}

/* ------------------------------ Catálogo --------------------------------- */

export async function fetchStoreCatalog() {
  const products = [];
  for (let page = 1; page <= 10; page++) {
    const batch = await req(`/products?per_page=100&page=${page}&catalog_visibility=catalog`);
    products.push(...batch.map(mapProduct));
    if (batch.length < 100) break;
  }

  // SKU y stock por producto (la Store API no publica stock_quantity).
  try {
    const extras = await apiReq('/catalog-stock');
    const byId = new Map((extras || []).map((e) => [e.id, e]));
    for (const p of products) {
      const e = byId.get(p.id);
      if (!e) continue;
      if (!p.sku && e.sku) p.sku = e.sku;
      p.stock_quantity = e.stock_quantity ?? null;
    }
  } catch {
    /* sin extras: la búsqueda muestra solo "En stock" / "Sin stock" */
  }

  const rawCats = await req('/products/categories?per_page=100').catch(() => []);
  const categories = rawCats
    .filter((c) => c.slug !== 'uncategorized' && c.slug !== 'sin-categorizar')
    .map((c) => ({
      id: c.id,
      name: stripHtml(c.name),
      slug: c.slug,
      description: stripHtml(c.description) || 'Explorá la colección.',
      hue: hueFromSlug(c.slug),
      count: c.count,
      image: c.image?.src ?? null,
    }))
    .sort((a, b) => (b.count || 0) - (a.count || 0));

  const brandMap = new Map();
  for (const p of products) {
    if (p.brand && !brandMap.has(p.brand)) brandMap.set(p.brand, { id: brandMap.size + 1, name: p.brandName, slug: p.brand });
  }

  return { products, categories, brands: [...brandMap.values()] };
}

/* ------------------------------- Carrito --------------------------------- */

export const getCart = () => req('/cart');
export const addCartItem = (id, quantity) => req('/cart/add-item', { method: 'POST', body: { id, quantity } });
export const updateCartItem = (key, quantity) => req('/cart/update-item', { method: 'POST', body: { key, quantity } });
export const removeCartItem = (key) => req('/cart/remove-item', { method: 'POST', body: { key } });

/** Guarda la dirección de envío en la sesión del carrito (recalcula rates). */
export const updateCustomerAddress = (shipping_address) =>
  req('/cart/customer/update-address', { method: 'POST', body: { shipping_address } });

/** Marca el método de envío elegido en la sesión del carrito. */
export const selectShippingRate = (rateId) =>
  req('/cart/select-shipping-rate', { method: 'POST', body: { rate_id: rateId } });

/**
 * Manda datos a una extensión del carrito registrada por un plugin y devuelve
 * el carrito recalculado. MJ Medios de Pago: namespace 'mjpm', { payment_method }.
 */
export const updateCartExtension = (namespace, data) =>
  req('/cart/extensions', { method: 'POST', body: { namespace, data } });

/* ------------------------------- Reseñas --------------------------------- */

/** Últimas reseñas aprobadas, mapeadas al formato de testimonios de la home. */
export async function fetchReviews(limit = 4) {
  try {
    const rows = await req(`/products/reviews?per_page=${limit}&status=approved`);
    return (rows || []).map((r) => ({
      name: stripHtml(r.reviewer),
      role: 'Compra verificada',
      text: stripHtml(r.review),
      rating: Number(r.rating) || 0,
    }));
  } catch {
    return [];
  }
}

/* --------------------------- API propia mj/v1 -------------------------
 * Auth, cuenta y contacto (lo implementa PHP en paralelo).
 * Mismo manejo de errores que `req`: throw Error con { data, status }.
 * ------------------------------------------------------------------------- */

async function apiReq(path, { method = 'GET', body } = {}, ns = 'mj/v1', retry = true) {
  const res = await fetch(`${WP.restUrl}${ns}${path}`, {
    method,
    credentials: 'same-origin',
    headers: {
      ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...(wpNonce ? { 'X-WP-Nonce': wpNonce } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => null);
  if (!res.ok) {
    if (retry && isStaleNonce(res.status, data) && (await refreshNonces())) {
      return apiReq(path, { method, body }, ns, false);
    }
    throw Object.assign(new Error(data?.message || `${ns} ${res.status}`), { data, status: res.status });
  }
  // Login, registro y logout devuelven nonces nuevos: adoptarlos en el acto.
  return setNonces(data);
}

/* --------------------- Facturación (plugin MJ Facturador) -----------------
 * Config fiscal del emisor (pública) y perfil fiscal del usuario logueado,
 * en wp-json/mjf/v1. Si el plugin está inactivo, getFiscal rechaza (404).
 * ------------------------------------------------------------------------- */

/** → { emisor: { condicion_iva, tipo_cbte_default }, receptor: {...} | null } */
export const getFiscal = () => apiReq('/fiscal', {}, 'mjf/v1');

/** Guarda el perfil fiscal del usuario (razón social, doc, cond. IVA). */
export const saveFiscal = (data) => apiReq('/fiscal', { method: 'POST', body: data }, 'mjf/v1');

/** → { user, nonce, wpNonce, adminBar } — los nonces se adoptan solos. */
export const login = (username, password, remember = false) =>
  apiReq('/auth/login', { method: 'POST', body: { username, password, remember } });
export const register = (data) => apiReq('/auth/register', { method: 'POST', body: data });
export const logout = () => apiReq('/auth/logout', { method: 'POST' });

/** Estado de sesión actual del servidor (usuario + nonces vigentes). */
export const getSession = () => apiReq('/session');
export const recover = (loginOrEmail) => apiReq('/auth/recover', { method: 'POST', body: { login: loginOrEmail } });

/**
 * Stock y precios por variación de un producto (los expone PHP porque la
 * Store API no publica stock_quantity ni precios de variaciones).
 * → { manage_stock, stock_status, stock_quantity, backorders, variations: [...] }
 */
export const getProductStock = (id) => apiReq(`/product-stock/${id}`);

/** Tipo de cambio vigente (plugin MJ Dólar, mjd/v1). Rechaza si el plugin está inactivo. */
export const getExchangeRate = () => apiReq('/rate', {}, 'mjd/v1');

/* ----------------------- Envíos (plugin MJ Envíos) ------------------------
 * mje/v1. Todo público: el paso de envío del checkout se usa sin cuenta.
 * Si el plugin está inactivo, estas llamadas rechazan con 404 y el checkout
 * sigue funcionando con las tarifas que haya configurado WooCommerce.
 * ------------------------------------------------------------------------- */

/** Provincia y localidades de un código postal. */
export const getPostcode = (cp) => apiReq(`/geo/cp?cp=${encodeURIComponent(cp)}`, {}, 'mje/v1');

/** Localidades que coinciden con un texto (autocompletado). */
export const searchCities = (query, state = '') =>
  apiReq(`/geo/localidades?q=${encodeURIComponent(query)}&prov=${encodeURIComponent(state)}`, {}, 'mje/v1');

/** Sucursales del correo de un método, para el CP cargado. */
export const getBranches = (method, cp = '') =>
  apiReq(`/sucursales?metodo=${encodeURIComponent(method)}&cp=${encodeURIComponent(cp)}`, {}, 'mje/v1');

/** Cuánto sale enviar a un destino (sirve sin carrito armado). */
export const quoteShipping = (data) => apiReq('/cotizar', { method: 'POST', body: data }, 'mje/v1');

/** Seguimiento de un pedido (con sesión, o con la clave del pedido). */
export const getTracking = (orderId, orderKey = '') =>
  apiReq(`/seguimiento?pedido=${encodeURIComponent(orderId)}&clave=${encodeURIComponent(orderKey)}`, {}, 'mje/v1');

export const getAccount = () => apiReq('/account');
export const updateProfile = (data) => apiReq('/account/profile', { method: 'POST', body: data });
export const updateAddresses = (billing, shipping) =>
  apiReq('/account/addresses', { method: 'POST', body: { billing, shipping } });

/** Detalle completo de un pedido del usuario (productos, totales, direcciones). */
export const getOrder = (id) => apiReq(`/account/orders/${id}`);

/** Cancela un pedido propio (solo si su estado lo permite). */
export const cancelOrder = (id) => apiReq(`/account/orders/${id}/cancel`, { method: 'POST' });

/**
 * Token de subida del comprobante de un pedido, a cambio de su clave.
 *
 * Solo hace falta para quien compró SIN cuenta: con sesión, el widget autoriza
 * por el pedido mismo. La clave la devuelve la Store API al cerrar el checkout,
 * y es la misma prueba de propiedad que usa la página de gracias de WooCommerce.
 */
export const getReceiptToken = (orderId, orderKey) =>
  apiReq(`/comprobantes/token?pedido=${encodeURIComponent(orderId)}&clave=${encodeURIComponent(orderKey)}`, {}, 'mjpm/v1');

/**
 * Cómo pagar un pedido: instrucciones y datos para pagar (alias, CBU, titular…)
 * del medio con que se compró. Mismo intercambio que el token del comprobante:
 * el id y la clave que devuelve la Store API al cerrar el checkout.
 */
export const getPaymentInfo = (orderId, orderKey) =>
  apiReq(`/como-pagar?pedido=${encodeURIComponent(orderId)}&clave=${encodeURIComponent(orderKey)}`, {}, 'mjpm/v1');

/** kind: 'contact' | 'newsletter'. */
export const sendContact = (data) => apiReq('/contact', { method: 'POST', body: data });

/* ------------------------------- Checkout -------------------------------- */

export async function placeOrder({ billing, paymentMethod, additionalFields }) {
  const address = {
    first_name: billing.firstName,
    last_name: billing.lastName,
    address_1: billing.address,
    // Piso/depto y provincia: los correos no pueden despachar sin esto.
    address_2: billing.address2 || '',
    city: billing.city,
    state: billing.state || '',
    postcode: billing.postcode,
    country: WP?.baseCountry || 'AR',
    email: billing.email,
    phone: billing.phone,
  };
  const { email, phone, ...shipping } = address;
  return req('/checkout', {
    method: 'POST',
    body: {
      billing_address: address,
      shipping_address: shipping,
      payment_method: paymentMethod,
      // Campos fiscales del paso 4 (los registra y valida el plugin MJ Facturador).
      ...(additionalFields ? { additional_fields: additionalFields } : {}),
    },
  });
}
