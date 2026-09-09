/**
 * Catálogo mock con la misma forma que la REST API de WooCommerce
 * (id, name, slug, price, regular_price, sale_price, categories, images, attributes…)
 * para que el swap a producción sea 1:1 en lib/api.js.
 */

export const categories = [
  { id: 1, name: 'iPhone', slug: 'iphone', description: 'El próximo salto.', hue: 250 },
  { id: 2, name: 'MacBook', slug: 'macbook', description: 'Poder sin esfuerzo.', hue: 210 },
  { id: 3, name: 'iPad', slug: 'ipad', description: 'Tu lienzo infinito.', hue: 180 },
  { id: 4, name: 'Accesorios', slug: 'accesorios', description: 'Los detalles importan.', hue: 300 },
  { id: 5, name: 'Audio', slug: 'audio', description: 'Sonido que se siente.', hue: 340 },
  { id: 6, name: 'Wearables', slug: 'wearables', description: 'Tecnología que te acompaña.', hue: 150 },
];

export const brands = [
  { id: 1, name: 'Apple', slug: 'apple' },
  { id: 2, name: 'Samsung', slug: 'samsung' },
  { id: 3, name: 'Sony', slug: 'sony' },
  { id: 4, name: 'JBL', slug: 'jbl' },
  { id: 5, name: 'Logitech', slug: 'logitech' },
  { id: 6, name: 'Xiaomi', slug: 'xiaomi' },
  { id: 7, name: 'DJI', slug: 'dji' },
  { id: 8, name: 'GoPro', slug: 'gopro' },
];

const img = (id) => `https://images.unsplash.com/${id}?auto=format&fit=crop&w=1200&q=80`;

let _id = 0;
const P = (data) => ({
  id: ++_id,
  regular_price: data.price,
  sale_price: null,
  stock_status: 'instock',
  featured: false,
  is_new: false,
  rating: 4.6,
  rating_count: 128,
  description:
    'Diseñado con obsesión por el detalle. Materiales premium, rendimiento de última generación y una experiencia pensada para durar.',
  specs: [
    ['Garantía', '12 meses oficial'],
    ['Origen', 'Importado — distribuidor oficial'],
    ['Condición', 'Nuevo, sellado en caja'],
  ],
  ...data,
});

export const products = [
  P({
    name: 'iPhone 16 Pro Max', slug: 'iphone-16-pro-max', sku: 'APL-IP16PM-256', price: 2199000,
    category: 'iphone', brand: 'apple', hue: 250, featured: true, is_new: true,
    tagline: 'Titanio. Tan pro como suena.',
    image: img('photo-1695048133142-1a20484d2569'),
    gallery: [img('photo-1695048133142-1a20484d2569'), img('photo-1591337676887-a217a6970a8a'), img('photo-1512499617640-c74ae3a79d37')],
    rating: 4.9, rating_count: 342,
  }),
  P({
    name: 'iPhone 16', slug: 'iphone-16', sku: 'APL-IP16-128', price: 1450000, sale_price: 1299000,
    category: 'iphone', brand: 'apple', hue: 255, featured: true,
    tagline: 'El color es el nuevo pro.',
    image: img('photo-1592750475338-74b7b21085ab'),
    gallery: [img('photo-1592750475338-74b7b21085ab'), img('photo-1510557880182-3d4d3cba35a5')],
    rating: 4.8, rating_count: 519,
  }),
  P({
    name: 'iPhone 15', slug: 'iphone-15', price: 1150000,
    category: 'iphone', brand: 'apple', hue: 245,
    tagline: 'Sigue siendo increíble.',
    image: img('photo-1510557880182-3d4d3cba35a5'),
    gallery: [img('photo-1510557880182-3d4d3cba35a5')],
    rating: 4.7, rating_count: 861,
  }),
  P({
    name: 'MacBook Pro 14" M4', slug: 'macbook-pro-14-m4', price: 3450000,
    category: 'macbook', brand: 'apple', hue: 210, featured: true, is_new: true,
    tagline: 'Una bestia en silencio.',
    image: img('photo-1517336714731-489689fd1ca8'),
    gallery: [img('photo-1517336714731-489689fd1ca8'), img('photo-1611186871348-b1ce696e52c9'), img('photo-1541807084-5c52b6b3adef')],
    rating: 4.9, rating_count: 214,
  }),
  P({
    name: 'MacBook Air 13" M4', slug: 'macbook-air-13-m4', price: 2150000, sale_price: 1990000,
    category: 'macbook', brand: 'apple', hue: 205, featured: true,
    tagline: 'Liviana. Letal.',
    image: img('photo-1611186871348-b1ce696e52c9'),
    gallery: [img('photo-1611186871348-b1ce696e52c9'), img('photo-1541807084-5c52b6b3adef')],
    rating: 4.8, rating_count: 445,
  }),
  P({
    name: 'iPad Pro 13" M4', slug: 'ipad-pro-13-m4', price: 1990000,
    category: 'ipad', brand: 'apple', hue: 180, is_new: true,
    tagline: 'Imposiblemente delgada.',
    image: img('photo-1544244015-0df4b3ffc6b0'),
    gallery: [img('photo-1544244015-0df4b3ffc6b0'), img('photo-1561154464-82e9adf32764')],
    rating: 4.8, rating_count: 187,
  }),
  P({
    name: 'iPad Air 11"', slug: 'ipad-air-11', price: 1190000,
    category: 'ipad', brand: 'apple', hue: 185,
    tagline: 'Ligera por fuera. Potente por dentro.',
    image: img('photo-1561154464-82e9adf32764'),
    gallery: [img('photo-1561154464-82e9adf32764')],
    rating: 4.7, rating_count: 293,
  }),
  P({
    name: 'AirPods Pro 2', slug: 'airpods-pro-2', price: 420000, sale_price: 379000,
    category: 'audio', brand: 'apple', hue: 340, featured: true,
    tagline: 'Silencio, por favor.',
    image: img('photo-1600294037681-c80b4cb5b434'),
    gallery: [img('photo-1600294037681-c80b4cb5b434'), img('photo-1572569511254-d8f925fe2cbb')],
    rating: 4.8, rating_count: 1024,
  }),
  P({
    name: 'Sony WH-1000XM5', slug: 'sony-wh-1000xm5', price: 620000,
    category: 'audio', brand: 'sony', hue: 335, featured: true,
    tagline: 'El estándar del silencio.',
    image: img('photo-1618366712010-f4ae9c647dcb'),
    gallery: [img('photo-1618366712010-f4ae9c647dcb'), img('photo-1583394838336-acd977736f90')],
    rating: 4.9, rating_count: 756,
  }),
  P({
    name: 'JBL Charge 5', slug: 'jbl-charge-5', price: 280000, sale_price: 239000,
    category: 'audio', brand: 'jbl', hue: 345,
    tagline: 'La fiesta viaja con vos.',
    image: img('photo-1608043152269-423dbba4e7e1'),
    gallery: [img('photo-1608043152269-423dbba4e7e1')],
    rating: 4.6, rating_count: 634,
  }),
  P({
    name: 'Apple Watch Ultra 2', slug: 'apple-watch-ultra-2', price: 1290000,
    category: 'wearables', brand: 'apple', hue: 150, is_new: true,
    tagline: 'Aventura de serie.',
    image: img('photo-1434493789847-2f02dc6ca35d'),
    gallery: [img('photo-1434493789847-2f02dc6ca35d'), img('photo-1579586337278-3befd40fd17a')],
    rating: 4.8, rating_count: 168,
  }),
  P({
    name: 'Samsung Galaxy Watch 7', slug: 'galaxy-watch-7', price: 520000,
    category: 'wearables', brand: 'samsung', hue: 155,
    tagline: 'Tu salud, en tu muñeca.',
    image: img('photo-1579586337278-3befd40fd17a'),
    gallery: [img('photo-1579586337278-3befd40fd17a')],
    rating: 4.5, rating_count: 289, stock_status: 'outofstock',
  }),
  P({
    name: 'Magic Keyboard', slug: 'magic-keyboard', price: 290000,
    category: 'accesorios', brand: 'apple', hue: 300,
    tagline: 'Escribe como piensas.',
    image: img('photo-1587829741301-dc798b83add3'),
    gallery: [img('photo-1587829741301-dc798b83add3')],
    rating: 4.6, rating_count: 412,
  }),
  P({
    name: 'Logitech MX Master 3S', slug: 'mx-master-3s', price: 180000, sale_price: 159000,
    category: 'accesorios', brand: 'logitech', hue: 305, featured: true,
    tagline: 'Precisión de otro planeta.',
    image: img('photo-1527864550417-7fd91fc51a46'),
    gallery: [img('photo-1527864550417-7fd91fc51a46')],
    rating: 4.9, rating_count: 921,
  }),
  P({
    name: 'DJI Mini 4 Pro', slug: 'dji-mini-4-pro', price: 1450000,
    category: 'accesorios', brand: 'dji', hue: 295, is_new: true,
    tagline: 'El cielo, sin permiso.',
    image: img('photo-1473968512647-3e447244af8f'),
    gallery: [img('photo-1473968512647-3e447244af8f')],
    rating: 4.7, rating_count: 143,
  }),
  P({
    name: 'Xiaomi Smart Band 9', slug: 'xiaomi-band-9', price: 95000,
    category: 'wearables', brand: 'xiaomi', hue: 145,
    tagline: 'Todo lo esencial. Nada más.',
    image: img('photo-1575311373937-040b8e1fd5b6'),
    gallery: [img('photo-1575311373937-040b8e1fd5b6')],
    rating: 4.4, rating_count: 1834,
  }),
];

// Mismo shape que inyecta PHP: { text, color } (color null → color por defecto).
export const announcements = [
  { text: 'Envíos a todo el país', color: null },
  { text: '12 cuotas sin interés', color: null },
  { text: 'Nuevos ingresos cada semana', color: null },
  { text: 'Atención personalizada 24/7', color: null },
];

export const reviews = [
  { name: 'Martina R.', role: 'Diseñadora', text: 'No parece una tienda, parece el sitio de una startup de Silicon Valley. Compré en 3 minutos.', rating: 5 },
  { name: 'Julián P.', role: 'Developer', text: 'La experiencia de compra más fluida que vi en Argentina. El drawer del carrito es una joya.', rating: 5 },
  { name: 'Sofía L.', role: 'Fotógrafa', text: 'Llegó en 24hs, embalaje impecable. La web te da confianza desde el primer scroll.', rating: 5 },
  { name: 'Nicolás T.', role: 'Product Manager', text: 'Comparé precios en 5 tiendas. Me quedé acá por la experiencia. Volvería solo por la web.', rating: 4 },
];

export const faqs = [
  { q: '¿Los productos son originales?', a: 'Sí. Todos nuestros productos son 100% originales, nuevos y sellados, con garantía oficial de 12 meses.' },
  { q: '¿Cuánto tarda el envío?', a: 'CABA y GBA: 24hs. Interior del país: 2 a 5 días hábiles. Todos los envíos incluyen seguimiento en tiempo real.' },
  { q: '¿Puedo pagar en cuotas?', a: 'Ofrecemos hasta 12 cuotas sin interés con tarjetas seleccionadas, y todos los medios de pago de Mercado Pago.' },
  { q: '¿Tienen local físico?', a: 'Trabajamos 100% online para ofrecerte mejores precios, con showroom con cita previa en Buenos Aires.' },
  { q: '¿Cómo funciona la garantía?', a: 'Cada producto incluye garantía oficial. Ante cualquier falla, gestionamos el reemplazo directamente con el fabricante.' },
];

