import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Layout } from '@/components/layout/Layout';
import Home from '@/pages/Home';
import Shop from '@/pages/Shop';
import Product from '@/pages/Product';
import CartPage from '@/pages/CartPage';
import Checkout from '@/pages/Checkout';
import {
  AccountLayout, AccountDashboard, AccountOrders, AccountOrderDetail, AccountAddresses,
  AccountFavorites, AccountDownloads, AccountSettings,
} from '@/pages/Account';
import { Login, Register, Recover } from '@/pages/Auth';
import {
  Wishlist, Compare, RecentlyViewed, Brands, Contact, About,
  FAQ, Privacy, Terms, Sitemap, NotFound,
} from '@/pages/Misc';
import { ProjectPage, ColegaPage } from '@/pages/Colegas';
import { BookingPage, BookingStatusPage } from '@/pages/Turnos';
import { ServicesPage } from '@/pages/Servicios';
import { COLEGAS } from '@/lib/colegas';
import { TURNOS } from '@/lib/turnos';

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
