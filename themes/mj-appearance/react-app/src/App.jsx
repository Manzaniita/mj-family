import { lazy } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Layout } from '@/components/layout/Layout';
import Home from '@/pages/Home';
import Shop from '@/pages/Shop';
import Product from '@/pages/Product';
import CartPage from '@/pages/CartPage';
import { COLEGAS } from '@/lib/colegas';
import { TURNOS } from '@/lib/turnos';

/*
 * Qué viaja con la primera carga y qué se baja cuando hace falta.
 *
 * Portada, tienda, producto y carrito son el camino de compra: van en app.js.
 * Todo lo demás (checkout, cuenta, acceso, turnos, colegas, páginas de texto)
 * se compila en archivos aparte que el navegador pide recién al entrar. En el
 * celular eso es la diferencia entre bajar el bundle entero para ver la
 * portada y bajar la mitad.
 *
 * Si un archivo viejo quedó cacheado después de una actualización del tema,
 * el import falla: se recarga la página una sola vez para traer el nuevo.
 */
function lazyPage(loader, name) {
  return lazy(() =>
    loader()
      .then((m) => ({ default: name ? m[name] : m.default }))
      .catch((err) => {
        const key = 'mj-chunk-reload';
        let already = false;
        try {
          already = sessionStorage.getItem(key) === '1';
          if (!already) sessionStorage.setItem(key, '1');
        } catch {
          /* sin storage: se intenta igual una vez */
        }
        if (!already) {
          window.location.reload();
          return new Promise(() => {}); // la recarga corta acá
        }
        throw err;
      })
  );
}

const Checkout = lazyPage(() => import('@/pages/Checkout'));
const account = (name) => lazyPage(() => import('@/pages/Account'), name);
const AccountLayout = account('AccountLayout');
const AccountDashboard = account('AccountDashboard');
const AccountOrders = account('AccountOrders');
const AccountOrderDetail = account('AccountOrderDetail');
const AccountAddresses = account('AccountAddresses');
const AccountFavorites = account('AccountFavorites');
const AccountDownloads = account('AccountDownloads');
const AccountSettings = account('AccountSettings');
const auth = (name) => lazyPage(() => import('@/pages/Auth'), name);
const Login = auth('Login');
const Register = auth('Register');
const Recover = auth('Recover');
const misc = (name) => lazyPage(() => import('@/pages/Misc'), name);
const Wishlist = misc('Wishlist');
const Compare = misc('Compare');
const RecentlyViewed = misc('RecentlyViewed');
const Brands = misc('Brands');
const Contact = misc('Contact');
const About = misc('About');
const FAQ = misc('FAQ');
const Privacy = misc('Privacy');
const Terms = misc('Terms');
const Sitemap = misc('Sitemap');
const NotFound = misc('NotFound');
const ProjectPage = lazyPage(() => import('@/pages/Colegas'), 'ProjectPage');
const ColegaPage = lazyPage(() => import('@/pages/Colegas'), 'ColegaPage');
const BookingPage = lazyPage(() => import('@/pages/Turnos'), 'BookingPage');
const BookingStatusPage = lazyPage(() => import('@/pages/Turnos'), 'BookingStatusPage');
const ServicesPage = lazyPage(() => import('@/pages/Servicios'), 'ServicesPage');

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route element={<Layout />}>
          <Route index element={<Home />} />
          <Route path="/tienda" element={<Shop />} />
          <Route path="/categoria/:slug" element={<Shop mode="category" />} />
          <Route path="/marca/:slug" element={<Shop mode="brand" />} />
          <Route path="/buscar" element={<Shop mode="search" />} />
          <Route path="/producto/:slug" element={<Product />} />
          <Route path="/carrito" element={<CartPage />} />
          <Route path="/checkout" element={<Checkout />} />
          <Route path="/cuenta" element={<AccountLayout />}>
            <Route index element={<AccountDashboard />} />
            <Route path="pedidos" element={<AccountOrders />} />
            <Route path="pedidos/:orderId" element={<AccountOrderDetail />} />
            <Route path="direcciones" element={<AccountAddresses />} />
            <Route path="favoritos" element={<AccountFavorites />} />
            <Route path="descargas" element={<AccountDownloads />} />
            <Route path="configuracion" element={<AccountSettings />} />
          </Route>
          <Route path="/mi-cuenta" element={<Navigate to="/cuenta" replace />} />
          <Route path="/login" element={<Login />} />
          <Route path="/registro" element={<Register />} />
          <Route path="/recuperar" element={<Recover />} />
          <Route path="/favoritos" element={<Wishlist />} />
          <Route path="/wishlist" element={<Wishlist />} />
          <Route path="/comparar" element={<Compare />} />
          <Route path="/vistos-recientemente" element={<RecentlyViewed />} />
          <Route path="/marcas" element={<Brands />} />
          <Route path="/contacto" element={<Contact />} />
          <Route path="/nosotros" element={<About />} />
          <Route path="/faq" element={<FAQ />} />
          <Route path="/privacidad" element={<Privacy />} />
          <Route path="/terminos" element={<Terms />} />
          <Route path="/mapa-del-sitio" element={<Sitemap />} />
          {/* Proyecto de colegas (plugin MJ Colegas): slug configurable en el plugin. */}
          {COLEGAS && <Route path={`/${COLEGAS.project.slug}`} element={<ProjectPage />} />}
          {COLEGAS && <Route path={`/${COLEGAS.project.slug}/:colega`} element={<ColegaPage />} />}
          {/* Turnos online (plugin MJ Turnos Online): reserva, estado de una reserva y página de servicios. Slugs configurables en el plugin. */}
          {TURNOS && <Route path={`/${TURNOS.slug}`} element={<BookingPage />} />}
          {TURNOS && <Route path={`/${TURNOS.slug}/t/:token`} element={<BookingStatusPage />} />}
          {TURNOS?.menu.servicesPageEnabled && <Route path={`/${TURNOS.servicesSlug}`} element={<ServicesPage />} />}
          <Route path="*" element={<NotFound />} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}
