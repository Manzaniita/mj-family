import assert from 'node:assert/strict';
import { test } from 'node:test';
import { build } from 'esbuild';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import React from 'react';
import { act, create } from 'react-test-renderer';

// Renderizamos el componente real; sólo reemplazamos la red, el store y los controles visuales.
const mocks = {
  '@/store': 'export const useCart = selector => selector(globalThis.enviosPrueba.store);',
  '@/lib/wc': `export const getPostcode = value => globalThis.enviosPrueba.lookup(value);
    export const getBranches = async () => []; export const searchCities = async () => [];`,
  '@/lib/config': `export const IS_WP = true; export const WP = {baseCountry:'AR', shipping:{enabled:true, methods:[]}};`,
  '@/lib/copy': 'export const t = key => key;',
  '@/components/ui/Input': `import React from 'react';
    export const Input = props => React.createElement('input', props);
    export const Select = props => React.createElement('select', props);
    export const Textarea = props => React.createElement('textarea', props);`,
  '@/components/checkout/ShippingOptions': `import React from 'react';
    export default function Options(props) { return React.createElement('opciones', props); }`,
};
const bundle = await build({
  entryPoints: [resolve('src/components/checkout/ShippingStep.jsx')], bundle: true,
  write: false, format: 'cjs', platform: 'node', jsx: 'automatic', packages: 'external',
  alias: { '@': resolve('src') },
  plugins: [{ name: 'dobles-de-prueba', setup(api) {
    api.onResolve({ filter: /^@\// }, args => mocks[args.path]
      ? { path: args.path, namespace: 'prueba' } : undefined);
    api.onResolve({ filter: /^\.\/config$/ }, () => ({ path: '@/lib/config', namespace: 'prueba' }));
    api.onLoad({ filter: /.*/, namespace: 'prueba' }, args => ({ contents: mocks[args.path], loader: 'js', resolveDir: resolve('.') }));
  } }],
});
const module = { exports: {} };
new Function('require', 'module', 'exports', bundle.outputFiles[0].text)(createRequire(import.meta.url), module, module.exports);
const ShippingStep = module.exports.default;
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };
const geo = city => ({ valido: true, encontrado: true, provinciaId: 'B', localidades: [{ nombre: city }] });

function mount({ postcode = '1000', lookup, quote = null, address } = {}) {
  const calls = { address: [], shipping: [], select: [] };
  const storage = new Map(quote ? [['mj_envio', JSON.stringify(quote)]] : []);
  globalThis.window = { localStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) } };
  globalThis.enviosPrueba = { lookup, store: {
    shipping: { rates: [{ rate_id: 'mje:a', slug: 'a', selected: true }, { rate_id: 'mje:b', slug: 'b', selected: false }] },
    server: {}, syncing: false,
    syncAddress: async data => { calls.address.push(data); return address ? address(data) : {}; },
    syncShipping: async data => { calls.shipping.push(data); return {}; },
    selectRate: async id => { calls.select.push(id); return {}; },
  } };
  let update;
  function Harness() {
    const [form, setForm] = React.useState({ postcode, state: '', city: '', address: '' });
    const [extra, setExtra] = React.useState({});
    update = setForm;
    return React.createElement(ShippingStep, { form, setForm, extra, setExtra });
  }
  let tree;
  act(() => { tree = create(React.createElement(Harness)); });
  return { calls, storage, tree, update: fn => act(() => update(fn)), close: () => act(() => tree.unmount()) };
}

test('CP + blur: una consulta y una dirección resuelta, sin envío prematuro', async () => {
  let lookups = 0;
  const h = mount({ lookup: async () => { lookups++; return geo('Localidad'); } });
  try {
    act(() => h.tree.root.findByProps({ name: 'postcode' }).props.onBlur());
    await act(async () => { await pause(460); });
    assert.equal(lookups, 1);
    assert.equal(h.calls.address.length, 1);
    assert.equal(h.calls.address[0].city, 'Localidad');
    act(() => h.tree.root.findByProps({ name: 'postcode' }).props.onBlur());
    assert.equal(h.calls.address.length, 1);
  } finally { h.close(); }
});

test('una respuesta atrasada no pisa el nuevo CP ni manda su localidad', async () => {
  const first = deferred();
  const h = mount({ lookup: value => value === '1000' ? first.promise : Promise.resolve(geo('Nueva')) });
  try {
    await act(async () => { await pause(430); });
    h.update(f => ({ ...f, postcode: '2000' }));
    await act(async () => { await pause(430); });
    await act(async () => { first.resolve(geo('Vieja')); });
    assert.equal(h.calls.address.length, 1);
    assert.equal(h.calls.address[0].postcode, '2000');
    assert.equal(h.calls.address[0].city, 'Nueva');
  } finally { h.close(); }
});

test('la preferencia espera al destino y la elección manual hace una sola mutación', async () => {
  const pending = deferred();
  const h = mount({ lookup: async () => geo('Localidad'), quote: { cp: '1000', slug: 'b' }, address: () => pending.promise });
  try {
    await act(async () => { await pause(430); });
    assert.equal(h.calls.shipping.length, 0);
    await act(async () => { pending.resolve({}); });
    assert.equal(h.calls.shipping.filter(data => data.method === 'b').length, 1);
    await act(async () => {
      const options = h.tree.root.findByType('opciones');
      await options.props.onChoose(options.props.rates[0]);
    });
    assert.equal(h.calls.shipping.filter(data => data.method === 'a').length, 1);
    assert.equal(h.calls.select.length, 0);
    assert.equal(JSON.parse(h.storage.get('mj_envio')).slug, 'a');
  } finally { h.close(); }
});

test('un CP cargado después del montaje también se resuelve', async () => {
  const h = mount({ postcode: '', lookup: async () => geo('Cuenta') });
  try {
    h.update(f => ({ ...f, postcode: '3000' }));
    await act(async () => { await pause(430); });
    assert.equal(h.calls.address.length, 1);
    assert.equal(h.calls.address[0].city, 'Cuenta');
  } finally { h.close(); }
});
