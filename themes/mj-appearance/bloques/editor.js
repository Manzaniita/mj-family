/**
 * Bloques MJ en el editor de WordPress.
 *
 * No hay build: se usa wp.element.createElement directo. El esquema de cada
 * bloque (campos, tipos, defaults, textos) llega de PHP en window.MJBloques
 * (inc/bloques.php), así que agregar un campo es tocar un solo lugar.
 *
 * Cada bloque se dibuja en el lienzo como una tarjeta con una vista previa
 * aproximada (con la paleta y las fuentes de la tienda) y sus ajustes van en
 * la barra lateral. El render real lo hace React en la tienda.
 */
(function (wp) {
	'use strict';

	var cfg = window.MJBloques;
	if (!wp || !cfg || !cfg.blocks) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var registerBlockType = wp.blocks.registerBlockType;
	var be = wp.blockEditor || wp.editor;
	var InspectorControls = be.InspectorControls;
	var MediaUpload = be.MediaUpload;
	var MediaUploadCheck = be.MediaUploadCheck;
	var useBlockProps = be.useBlockProps;
	var c = wp.components;
	var apiFetch = wp.apiFetch;
	var copy = cfg.copy || {};
	var defaults = cfg.defaults || {};

	/* ---------------------------------------------------------------------
	 * Utilidades
	 * ------------------------------------------------------------------ */
	function decode(s) {
		if (!s || s.indexOf('&') === -1) {
			return s || '';
		}
		var ta = document.createElement('textarea');
		ta.innerHTML = s;
		return ta.value;
	}

	function t(key, fallback) {
		var v = copy[key];
		return typeof v === 'string' && v.trim() !== '' ? v : (fallback || '');
	}

	/* ¿El campo se muestra con estos atributos? (showIf: { campo: valor | [valores] }) */
	function visible(field, attrs) {
		if (!field.showIf) {
			return true;
		}
		return Object.keys(field.showIf).every(function (key) {
			var want = field.showIf[key];
			var have = attrs[key];
			return Array.isArray(want) ? want.indexOf(have) !== -1 : want === have;
		});
	}

	function optionList(options, emptyLabel) {
		var list = emptyLabel ? [{ label: emptyLabel, value: '' }] : [];
		Object.keys(options || {}).forEach(function (value) {
			list.push({ label: options[value], value: value });
		});
		return list;
	}

	function pick(items, fromAttr, toAttr) {
		return items.map(function (i) { return i[fromAttr] !== undefined ? i[fromAttr] : i[toAttr]; });
	}

	/* ---------------------------------------------------------------------
	 * Campos de la barra lateral
	 * ------------------------------------------------------------------ */
	function ImagesField(props) {
		var field = props.field;
		var multiple = field.type === 'images';
		var raw = props.value;
		var items = Array.isArray(raw) ? raw : (raw && raw.id ? [raw] : []);

		function commit(list) {
			props.onChange(multiple ? list : (list[0] || null));
		}

		return el(c.BaseControl, { label: field.label, help: field.help, __nextHasNoMarginBottom: true },
			el('div', { className: 'mj-field-media' },
				items.map(function (img, i) {
					return el('span', { key: img.id + '-' + i, className: 'mj-field-media__item' },
						el('img', { src: img.url, alt: '' }),
						el(c.Button, {
							icon: 'no-alt', label: 'Quitar', size: 'small',
							onClick: function () {
								commit(items.filter(function (_, j) { return j !== i; }));
							}
						})
					);
				}),
				el(MediaUploadCheck, null,
					el(MediaUpload, {
						allowedTypes: ['image'],
						multiple: multiple,
						gallery: multiple,
						value: pick(items, 'id'),
						onSelect: function (sel) {
							var list = (Array.isArray(sel) ? sel : [sel]).map(function (m) {
								var thumb = m.sizes && (m.sizes.medium || m.sizes.thumbnail);
								return { id: m.id, url: thumb ? thumb.url : m.url };
							});
							commit(list);
						},
						render: function (r) {
							return el(c.Button, { variant: 'secondary', onClick: r.open },
								items.length ? (multiple ? 'Cambiar imágenes' : 'Cambiar foto') : (multiple ? 'Elegir imágenes' : 'Elegir foto'));
						}
					})
				)
			)
		);
	}

	/*
	 * Fotos con leyenda (bloque Galería de trabajos): se eligen de la
	 * biblioteca y cada una lleva un texto propio. Vacío = la leyenda (o el
	 * texto alternativo) de la foto en la biblioteca, que se muestra de guía.
	 */
	function GalleryField(props) {
		var field = props.field;
		var items = Array.isArray(props.value) ? props.value : [];

		function update(i, patch) {
			props.onChange(items.map(function (it, j) { return j === i ? Object.assign({}, it, patch) : it; }));
		}
		function move(i, dir) {
			var j = i + dir;
			if (j < 0 || j >= items.length) { return; }
			var next = items.slice();
			var tmp = next[i]; next[i] = next[j]; next[j] = tmp;
			props.onChange(next);
		}

		return el(c.BaseControl, { label: field.label, help: field.help, __nextHasNoMarginBottom: true },
			el('ul', { className: 'mj-field-gallery' },
				items.map(function (img, i) {
					return el('li', { key: img.id + '-' + i, className: 'mj-field-gallery__item' },
						el('img', { src: img.url, alt: '' }),
						el('div', { className: 'mj-field-gallery__body' },
							el(c.TextControl, {
								label: 'Leyenda',
								hideLabelFromVision: true,
								value: img.caption || '',
								placeholder: img.libraryCaption || 'Leyenda (ej. Lash Lifting Tradicional)',
								onChange: function (v) { update(i, { caption: v }); },
								__nextHasNoMarginBottom: true,
								__next40pxDefaultSize: true
							}),
							el('div', { className: 'mj-field-gallery__actions' },
								el(c.Button, { icon: 'arrow-up-alt2', label: 'Subir', size: 'small', disabled: i === 0, onClick: function () { move(i, -1); } }),
								el(c.Button, { icon: 'arrow-down-alt2', label: 'Bajar', size: 'small', disabled: i === items.length - 1, onClick: function () { move(i, 1); } }),
								el(c.Button, { icon: 'no-alt', label: 'Quitar', size: 'small', isDestructive: true, onClick: function () {
									props.onChange(items.filter(function (_, j) { return j !== i; }));
								} })
							)
						)
					);
				})
			),
			el(MediaUploadCheck, null,
				el(MediaUpload, {
					allowedTypes: ['image'],
					multiple: true,
					gallery: true,
					value: pick(items, 'id'),
					onSelect: function (sel) {
						var prev = {};
						items.forEach(function (it) { prev[it.id] = it; });
						var list = (Array.isArray(sel) ? sel : [sel]).map(function (m) {
							var thumb = m.sizes && (m.sizes.medium || m.sizes.thumbnail);
							var library = decode(m.caption || m.alt || '');
							return {
								id: m.id,
								url: thumb ? thumb.url : m.url,
								caption: prev[m.id] ? prev[m.id].caption || '' : '',
								libraryCaption: library
							};
						});
						props.onChange(list);
					},
					render: function (r) {
						return el(c.Button, { variant: 'secondary', onClick: r.open }, items.length ? 'Agregar o quitar fotos' : 'Elegir fotos');
					}
				})
			)
		);
	}

	function ProductsField(props) {
		var q = useState('');
		var results = useState([]);
		var busy = useState(false);
		var query = q[0];
		var chosen = Array.isArray(props.value) ? props.value : [];

		useEffect(function () {
			if (query.trim().length < 2) {
				results[1]([]);
				return undefined;
			}
			var alive = true;
			busy[1](true);
			var timer = setTimeout(function () {
				apiFetch({ path: '/wc/store/v1/products?per_page=8&search=' + encodeURIComponent(query.trim()) })
					.then(function (rows) {
						if (!alive) { return; }
						results[1]((rows || []).map(function (p) { return { id: p.id, name: decode(p.name) }; }));
					})
					.catch(function () { if (alive) { results[1]([]); } })
					.then(function () { if (alive) { busy[1](false); } });
			}, 300);
			return function () { alive = false; clearTimeout(timer); };
		}, [query]);

		function move(i, dir) {
			var next = chosen.slice();
			var j = i + dir;
			if (j < 0 || j >= next.length) { return; }
			var tmp = next[i]; next[i] = next[j]; next[j] = tmp;
			props.onChange(next);
		}

		return el(c.BaseControl, { label: props.field.label, help: props.field.help, __nextHasNoMarginBottom: true },
			chosen.length ? el('ul', { className: 'mj-field-list' }, chosen.map(function (p, i) {
				return el('li', { key: p.id },
					el('span', null, p.name || ('#' + p.id)),
					el(c.Button, { icon: 'arrow-up-alt2', label: 'Subir', size: 'small', onClick: function () { move(i, -1); } }),
					el(c.Button, { icon: 'arrow-down-alt2', label: 'Bajar', size: 'small', onClick: function () { move(i, 1); } }),
					el(c.Button, { icon: 'no-alt', label: 'Quitar', size: 'small', isDestructive: true, onClick: function () {
						props.onChange(chosen.filter(function (x) { return x.id !== p.id; }));
					} })
				);
			})) : el('p', { className: 'mj-field-help' }, 'Todavía no elegiste ningún producto.'),
			el(c.TextControl, { label: 'Buscar producto', value: query, placeholder: 'Nombre o SKU…', onChange: q[1], __nextHasNoMarginBottom: true }),
			busy[0] ? el(c.Spinner) : null,
			results[0].length ? el('ul', { className: 'mj-field-results' }, results[0].map(function (p) {
				var already = chosen.some(function (x) { return x.id === p.id; });
				return el('li', { key: p.id }, el('button', {
					type: 'button', disabled: already,
					onClick: function () { props.onChange(chosen.concat([p])); q[1](''); }
				}, (already ? '✓ ' : '+ ') + p.name));
			})) : null
		);
	}

	function CategoriesField(props) {
		var chosen = Array.isArray(props.value) ? props.value : [];
		var cats = cfg.categories || [];
		if (!cats.length) {
			return el('p', { className: 'mj-field-help' }, 'No hay categorías de producto todavía.');
		}
		return el(c.BaseControl, { label: props.field.label, help: props.field.help, __nextHasNoMarginBottom: true },
			cats.map(function (cat) {
				return el(c.CheckboxControl, {
					key: cat.slug,
					label: cat.name + (cat.count ? ' (' + cat.count + ')' : ''),
					checked: chosen.indexOf(cat.slug) !== -1,
					__nextHasNoMarginBottom: true,
					onChange: function (on) {
						props.onChange(on ? chosen.concat([cat.slug]) : chosen.filter(function (s) { return s !== cat.slug; }));
					}
				});
			})
		);
	}

	function RepeaterField(props) {
		var rows = Array.isArray(props.value) ? props.value : [];
		var sub = props.field.fields || {};
		function update(i, key, v) {
			var next = rows.map(function (r, j) {
				if (j !== i) { return r; }
				var copyRow = Object.assign({}, r);
				copyRow[key] = v;
				return copyRow;
			});
			props.onChange(next);
		}
		function blank() {
			var r = {};
			Object.keys(sub).forEach(function (k) { r[k] = sub[k].default !== undefined ? sub[k].default : ''; });
			return r;
		}
		return el(c.BaseControl, { label: props.field.label, help: props.field.help, __nextHasNoMarginBottom: true },
			rows.map(function (row, i) {
				return el('div', { key: i, className: 'mj-field-repeater__row' },
					Object.keys(sub).map(function (k) {
						return el(Field, { key: k, field: sub[k], value: row[k], onChange: function (v) { update(i, k, v); } });
					}),
					el('div', { className: 'mj-field-repeater__actions' },
						el(c.Button, { icon: 'arrow-up-alt2', label: 'Subir', size: 'small', disabled: i === 0, onClick: function () {
							var next = rows.slice(); var tmp = next[i]; next[i] = next[i - 1]; next[i - 1] = tmp; props.onChange(next);
						} }),
						el(c.Button, { icon: 'arrow-down-alt2', label: 'Bajar', size: 'small', disabled: i === rows.length - 1, onClick: function () {
							var next = rows.slice(); var tmp = next[i]; next[i] = next[i + 1]; next[i + 1] = tmp; props.onChange(next);
						} }),
						el(c.Button, { icon: 'trash', label: 'Quitar', size: 'small', isDestructive: true, onClick: function () {
							props.onChange(rows.filter(function (_, j) { return j !== i; }));
						} })
					)
				);
			}),
			el(c.Button, { variant: 'secondary', onClick: function () { props.onChange(rows.concat([blank()])); } }, 'Agregar')
		);
	}

	function Field(props) {
		var field = props.field;
		var value = props.value;
		var onChange = props.onChange;
		var common = { label: field.label, help: field.help || undefined, __nextHasNoMarginBottom: true };
		switch (field.type) {
			case 'textarea':
				return el(c.TextareaControl, Object.assign({}, common, { value: value || '', placeholder: field.placeholder || '', rows: 3, onChange: onChange }));
			case 'url':
				return el(c.TextControl, Object.assign({}, common, { value: value || '', placeholder: field.placeholder || '', type: 'text', onChange: onChange }));
			case 'number':
				return el(c.RangeControl, Object.assign({}, common, {
					value: typeof value === 'number' ? value : Number(field.default) || 0,
					min: field.min !== undefined ? field.min : 0,
					max: field.max !== undefined ? field.max : 100,
					step: field.step || 1,
					onChange: function (v) { onChange(Number(v) || 0); },
					__next40pxDefaultSize: true
				}));
			case 'toggle':
				return el(c.ToggleControl, Object.assign({}, common, { checked: !!value, onChange: function (v) { onChange(!!v); } }));
			case 'select':
				return el(c.SelectControl, Object.assign({}, common, { value: value || field.default || '', options: optionList(field.options), onChange: onChange, __next40pxDefaultSize: true }));
			case 'category': {
				var opts = [{ label: '— Elegí una categoría —', value: '' }].concat((cfg.categories || []).map(function (x) {
					return { label: x.name, value: x.slug };
				}));
				return el(c.SelectControl, Object.assign({}, common, { value: value || '', options: opts, onChange: onChange, __next40pxDefaultSize: true }));
			}
			case 'brand': {
				var brands = cfg.brands || [];
				if (!brands.length) {
					return el('p', { className: 'mj-field-help' }, 'No hay marcas: la tienda las toma del atributo de producto "Marca".');
				}
				var bopts = [{ label: '— Elegí una marca —', value: '' }].concat(brands.map(function (x) { return { label: x.name, value: x.slug }; }));
				return el(c.SelectControl, Object.assign({}, common, { value: value || '', options: bopts, onChange: onChange, __next40pxDefaultSize: true }));
			}
			case 'image':
			case 'images':
				return el(ImagesField, { field: field, value: value, onChange: onChange });
			case 'products':
				return el(ProductsField, { field: field, value: value, onChange: onChange });
			case 'categories':
				return el(CategoriesField, { field: field, value: value, onChange: onChange });
			case 'repeater':
				return el(RepeaterField, { field: field, value: value, onChange: onChange });
			case 'gallery':
				return el(GalleryField, { field: field, value: value, onChange: onChange });
			case 'color':
				return el(c.BaseControl, common,
					el('div', { className: 'mj-field-color', style: { display: 'flex', alignItems: 'center', gap: '8px', marginTop: '4px' } },
						el('input', {
							type: 'color',
							value: value && /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(value) ? value : '#000000',
							onChange: function (e) { onChange(e.target.value); },
							style: { width: '36px', height: '36px', padding: '2px', border: '1px solid #ccc', borderRadius: '4px', cursor: 'pointer' }
						}),
						el(c.TextControl, {
							value: value || '',
							placeholder: field.placeholder || '#hex o transparente',
							onChange: onChange,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true
						})
					)
				);
			default:
				return el(c.TextControl, Object.assign({}, common, { value: value || '', placeholder: field.placeholder || '', onChange: onChange, __next40pxDefaultSize: true }));
		}
	}

	/* ---------------------------------------------------------------------
	 * Vistas previas en el lienzo
	 * ------------------------------------------------------------------ */
	function textOr(value, fallback, className, tag) {
		var v = (value || '').trim();
		var text = v || fallback || '';
		if (!text) { return null; }
		return el(tag || 'p', { className: className + (v ? '' : ' is-placeholder') }, text);
	}

	function cards(n, cols, tall, layout, names) {
		var count = Math.max(1, Math.min(n || 4, 12));
		var items = [];
		for (var i = 0; i < count; i++) {
			items.push(el('div', { key: i, className: 'mj-prev-card' + (tall ? ' mj-prev-card--tall' : '') },
				el('div', { className: 'mj-prev-card__img' }),
				el('div', { className: 'mj-prev-card__body' },
					names && names[i] ? el('p', { className: 'mj-prev-card__name' }, names[i]) : el('div', { className: 'mj-prev-line mj-prev-line--short' }),
					el('div', { className: 'mj-prev-line' }),
					tall ? null : el('div', { className: 'mj-prev-line mj-prev-line--btn' })
				)
			));
		}
		return el('div', {
			className: 'mj-prev-grid' + (layout === 'carousel' ? ' mj-prev-grid--carousel' : ''),
			style: { '--mj-prev-cols': String(cols || 4) }
		}, items);
	}

	function header(eyebrow, title, subtitle) {
		return el(Fragment, null,
			textOr(eyebrow.value, eyebrow.fallback, 'mj-prev-eyebrow'),
			textOr(title.value, title.fallback, 'mj-prev-title', 'h2'),
			subtitle ? textOr(subtitle.value, subtitle.fallback, 'mj-prev-subtitle') : null
		);
	}

	function chips(list) {
		var items = list.filter(Boolean);
		return items.length ? el('div', { className: 'mj-prev-chips' }, items.map(function (x, i) {
			return el('span', { key: i, className: 'mj-prev-chip' }, x);
		})) : null;
	}

	function sourceLabel(def, attrs) {
		var f = def.fields.source;
		return f && f.options ? f.options[attrs.source] || attrs.source : attrs.source;
	}

	function preview(key, def, attrs) {
		var hero = defaults.hero || {};
		switch (key) {
			case 'hero': {
				var imgs = Array.isArray(attrs.images) ? attrs.images : [];
				var bg = imgs.length ? imgs[0].url : (hero.image || '');
				var style = bg ? { backgroundImage: 'linear-gradient(rgb(0 0 0 / 0.55), rgb(0 0 0 / 0.55)), url(' + bg + ')' } : {};
				var badge = attrs.badge || hero.badge;
				var p1 = attrs.ctaPrimaryText || hero.ctaPrimary;
				var p2 = attrs.ctaSecondaryText || hero.ctaSecondary;
				return el('div', { className: 'mj-prev-hero' + (bg ? ' mj-prev-hero--media' : ''), style: style },
					badge ? el('span', { className: 'mj-prev-hero__badge' }, '✦ ' + badge) : null,
					el('h2', { className: 'mj-prev-title' },
						attrs.title || hero.title || '',
						(attrs.titleHighlight || hero.titleHighlight) ? el('span', { className: 'mj-prev-hero__highlight' }, attrs.titleHighlight || hero.titleHighlight) : null
					),
					textOr(attrs.subtitle, hero.subtitle || hero.tagline, 'mj-prev-subtitle'),
					(p1 || p2) ? el('div', { className: 'mj-prev-hero__actions' },
						p1 ? el('span', { className: 'mj-prev-btn' }, p1 + ' →') : null,
						p2 ? el('span', { className: 'mj-prev-btn mj-prev-btn--secondary' }, p2) : null
					) : null
				);
			}
			case 'encabezado':
				return header({ value: attrs.eyebrow }, { value: attrs.title, fallback: 'El título de la página' }, { value: attrs.subtitle });
			case 'banners': {
				var list = Array.isArray(attrs.images) ? attrs.images : [];
				return el(Fragment, null,
					el('div', { className: 'mj-prev-banner', style: list.length ? { backgroundImage: 'url(' + list[0].url + ')' } : {} },
						list.length ? null : 'Los banners de Apariencia → MJ Appearance'),
					el('div', { className: 'mj-prev-banner__dots' }, (list.length ? list : [1, 2, 3]).map(function (_, i) { return el('span', { key: i }); }))
				);
			}
			case 'productos': {
				var fb = { featured: 'home_featured', new: 'home_latest', rating: 'home_recommended' }[attrs.source] || 'home_featured';
				var names = attrs.source === 'manual' && Array.isArray(attrs.products) ? attrs.products.map(function (p) { return p.name; }) : null;
				var detail = attrs.source === 'category' && attrs.category ? attrs.category : (attrs.source === 'brand' && attrs.brand ? attrs.brand : '');
				return el(Fragment, null,
					header({ value: attrs.eyebrow, fallback: t(fb + '_eyebrow') }, { value: attrs.title, fallback: t(fb + '_title') }, { value: attrs.subtitle, fallback: attrs.source === 'new' ? '' : t(fb + '_subtitle') }),
					chips([sourceLabel(def, attrs), detail, attrs.limit + ' productos', attrs.layout === 'carousel' ? 'Carrusel' : 'Grilla de ' + attrs.cols]),
					cards(Math.min(attrs.limit || 4, Number(attrs.cols || 4) * 2), Number(attrs.cols || 4), false, attrs.layout, names)
				);
			}
			case 'categorias': {
				var picked = Array.isArray(attrs.categories) && attrs.categories.length ? attrs.categories : null;
				var catNames = (picked || (cfg.categories || []).slice(0, attrs.limit || 4).map(function (x) { return x.slug; })).map(function (slug) {
					var found = (cfg.categories || []).filter(function (x) { return x.slug === slug; })[0];
					return found ? found.name : slug;
				});
				return el(Fragment, null,
					header({ value: attrs.eyebrow, fallback: t('home_categories_eyebrow') }, { value: attrs.title, fallback: t('home_categories_title') }),
					cards(catNames.length || 4, Math.min(4, catNames.length || 4), true, attrs.layout, catNames)
				);
			}
			case 'marcas':
				return el(Fragment, null,
					textOr(attrs.label, t('home_brands_label'), 'mj-prev-eyebrow'),
					el('div', { className: 'mj-prev-marquee' }, ['Marca', 'Marca', 'Marca', 'Marca', 'Marca'].map(function (x, i) { return el('span', { key: i }, x); }))
				);
			case 'opiniones':
				return el(Fragment, null,
					header({ value: attrs.eyebrow, fallback: t('home_testimonials_eyebrow') }, { value: attrs.title, fallback: t('home_testimonials_title') }),
					chips([attrs.limit + ' opiniones', sourceLabel(def, attrs)]),
					cards(Math.min(attrs.limit || 4, 4), Math.min(attrs.limit || 4, 4), false)
				);
			case 'galeria': {
				var photos = Array.isArray(attrs.images) ? attrs.images : [];
				var gcols = Math.max(1, Number(attrs.cols) || 3);
				var shown = photos.length ? photos.slice(0, gcols * 2) : [1, 2, 3].slice(0, gcols);
				var labels = [];
				photos.forEach(function (p) {
					var cap = (p.caption || p.libraryCaption || '').trim();
					if (cap && labels.indexOf(cap) === -1) { labels.push(cap); }
				});
				return el(Fragment, null,
					(attrs.eyebrow || attrs.title) ? header({ value: attrs.eyebrow }, { value: attrs.title }) : null,
					attrs.filter && labels.length > 1 ? chips([t('gallery_filter_all', 'Todos')].concat(labels.slice(0, 5))) : null,
					el('div', { className: 'mj-prev-gallery', style: { '--mj-prev-cols': String(gcols) } },
						shown.map(function (p, i) {
							var cap = p && p.id ? (p.caption || p.libraryCaption || '') : '';
							return el('figure', { key: i, className: 'mj-prev-gallery__item' },
								el('div', { className: 'mj-prev-gallery__img mj-prev-gallery__img--' + (attrs.ratio || 'square'), style: p && p.url ? { backgroundImage: 'url(' + p.url + ')' } : {} }),
								attrs.showCaptions !== false ? el('figcaption', { className: cap ? '' : 'is-placeholder' }, cap || (p && p.id ? 'Sin leyenda' : 'Leyenda de la foto')) : null
							);
						})
					),
					chips([photos.length ? photos.length + ' fotos' : 'Elegí las fotos en la barra lateral', attrs.colsMobile + ' columnas en el celular'])
				);
			}
			case 'instagram':
				return el(Fragment, null,
					header({ value: '@instagram' }, { value: attrs.title, fallback: t('home_instagram_title') }),
					cards(Number(attrs.limit) || 6, Math.min(6, Number(attrs.limit) || 6), false)
				);
			case 'nosotros': {
				var img = attrs.image && attrs.image.url ? attrs.image.url : (defaults.aboutImage || '');
				return el(Fragment, null,
					el('div', { className: 'mj-prev-split' },
						el('div', { className: 'mj-prev-photo', style: img ? { backgroundImage: 'url(' + img + ')' } : {} }),
						el('div', null,
							header({ value: attrs.eyebrow, fallback: attrs.layout === 'full' ? t('about_eyebrow') : t('home_about_eyebrow') }, { value: attrs.title, fallback: attrs.layout === 'full' ? t('about_title') : t('home_about_title') }, { value: attrs.text, fallback: defaults.aboutText || 'El texto de Contenido → Nosotros.' })
						)
					),
					chips([attrs.layout === 'full' ? 'Página completa: texto, puntos destacados, cifras y botón' : 'Adelanto con enlace a Nosotros'])
				);
			}
			case 'contacto':
				return el('div', { className: 'mj-prev-split' },
					attrs.showForm ? el('div', { className: 'mj-prev-form' },
						el('div', { className: 'mj-prev-input' }), el('div', { className: 'mj-prev-input' }), el('div', { className: 'mj-prev-input mj-prev-input--tall' }),
						el('span', { className: 'mj-prev-btn' }, 'Enviar mensaje')) : null,
					el('div', null, chips([attrs.showChannels ? 'Dirección, teléfono, WhatsApp y mail' : '', attrs.showMap ? 'Mapa' : '']),
						el('p', { className: 'mj-prev-note' }, 'Los datos salen de Apariencia → MJ Appearance → Contacto.'))
				);
			case 'faq': {
				var items = Array.isArray(attrs.items) && attrs.items.length ? attrs.items : null;
				return el(Fragment, null,
					el('div', { className: 'mj-prev-faq' }, (items || [{ q: 'Las preguntas de Contenido → Preguntas frecuentes' }, { q: '…' }]).map(function (it, i) {
						return el('div', { key: i, className: items ? '' : 'is-placeholder' }, el('span', null, it.q || 'Pregunta ' + (i + 1)), el('span', null, '+'));
					})),
					attrs.showContactCard ? chips(['Con la tarjeta "¿No encontraste tu respuesta?"']) : null
				);
			}
			case 'servicios':
				return el(Fragment, null,
					header({ value: attrs.eyebrow, fallback: 'Servicios' }, { value: attrs.title, fallback: 'Los servicios de Turnos → Ajustes' }, { value: attrs.subtitle }),
					cards(attrs.limit || 3, Math.min(4, attrs.limit || 3), false)
				);
			case 'reserva': {
				var rimg = attrs.image && attrs.image.url ? attrs.image.url : '';
				return el('div', { className: 'mj-prev-split' },
					el('div', null,
						header({ value: attrs.eyebrow, fallback: 'Turnos' }, { value: attrs.title, fallback: 'Reservá tu turno online' }, { value: attrs.subtitle }),
						el('p', null, el('span', { className: 'mj-prev-btn' }, '📅 ' + (attrs.button || 'Reservar') + ' →'))),
					el('div', { className: 'mj-prev-photo', style: rimg ? { backgroundImage: 'url(' + rimg + ')' } : {} })
				);
			}
			case 'colegas':
				return el(Fragment, null,
					header({ value: attrs.eyebrow, fallback: 'Colegas' }, { value: attrs.title, fallback: 'El proyecto de Colegas → Ajustes' }, { value: attrs.subtitle }),
					cards(attrs.limit || 4, Math.min(4, attrs.limit || 4), true)
				);
			case 'header': {
				var hBg = attrs.bgColor || '';
				var hInk = attrs.inkColor || '';
				var hStyle = {};
				if (hBg) { hStyle.backgroundColor = hBg; }
				if (hInk) { hStyle.color = hInk; }
				return el('div', { className: 'mj-prev-header' + (attrs.border && attrs.border !== 'none' ? ' has-border' : ''), style: hStyle },
					el('div', { className: 'mj-prev-header__bar' },
						el('div', { className: 'mj-prev-header__logo' },
							el('span', { className: 'dashicons dashicons-store' }),
							el('strong', null, 'Logo')
						),
						attrs.searchPosition !== 'hidden' ? el('div', { className: 'mj-prev-header__search' },
							el('span', { className: 'dashicons dashicons-search' }),
							el('span', null, 'Buscar productos…')
						) : null,
						el('div', { className: 'mj-prev-header__actions' },
							attrs.showTurnosCta ? el('span', { className: 'mj-prev-header__btn' }, '📅 ' + (attrs.turnosCtaText || 'Reservar')) : null,
							attrs.customButtonText ? el('span', { className: 'mj-prev-header__btn mj-prev-header__btn--custom' }, (attrs.customButtonIcon ? attrs.customButtonIcon + ' ' : '') + attrs.customButtonText) : null,
							attrs.showDollarRate ? el('span', { className: 'mj-prev-header__icon' }, '💵 USD') : null,
							attrs.showWishlist ? el('span', { className: 'dashicons dashicons-heart' }) : null,
							attrs.showAccount ? el('span', { className: 'dashicons dashicons-admin-users' }) : null,
							attrs.showCart ? el('span', { className: 'dashicons dashicons-cart' }) : null
						)
					),
					attrs.menuPosition === 'below' ? el('div', { className: 'mj-prev-header__nav' },
						el('span', null, 'Inicio'),
						el('span', null, 'Catálogo'),
						el('span', null, 'Colecciones'),
						el('span', null, 'Contacto')
					) : null,
					chips([
						'Sticky: ' + (attrs.sticky || 'sticky'),
						'Estilo: ' + (attrs.headerStyle || 'glass'),
						'Logo: ' + (attrs.logoPosition || 'left'),
						'Buscador: ' + (attrs.searchPosition || 'center'),
						'Menú: ' + (attrs.menuPosition || 'below'),
						attrs.showTurnosCta ? 'Turnos CTA' : '',
						attrs.customButtonText ? 'Botón: ' + attrs.customButtonText : ''
					])
				);
			}
			case 'footer': {
				var fBg = attrs.bgColor || '';
				var fInk = attrs.inkColor || '';
				var fStyle = {};
				if (fBg) { fStyle.backgroundColor = fBg; }
				if (fInk) { fStyle.color = fInk; }
				var fCols = Number(attrs.columnsCount) || 4;
				var customCols = Array.isArray(attrs.columns) && attrs.columns.length ? attrs.columns : null;
				return el('div', { className: 'mj-prev-footer' + (attrs.border && attrs.border !== 'none' ? ' has-border' : ''), style: fStyle },
					attrs.showNewsletter ? el('div', { className: 'mj-prev-footer__news' },
						el('strong', null, 'Suscribite a novedades'),
						el('div', { className: 'mj-prev-footer__news-form' },
							el('span', { className: 'mj-prev-footer__input' }, 'Tu correo electrónico'),
							el('span', { className: 'mj-prev-btn' }, 'Suscribirme')
						)
					) : null,
					el('div', { className: 'mj-prev-footer__grid', style: { '--mj-footer-cols': String(fCols) } },
						attrs.showLogo !== false ? el('div', { className: 'mj-prev-footer__col' },
							el('strong', null, '✦ Tienda'),
							el('p', { className: 'mj-prev-note' }, attrs.tagline || 'Eslogan o descripción.'),
							attrs.showSocials !== false ? el('div', { className: 'mj-prev-footer__socials' },
								el('span', { className: 'dashicons dashicons-instagram' }),
								el('span', { className: 'dashicons dashicons-facebook' }),
								el('span', { className: 'dashicons dashicons-whatsapp' })
							) : null
						) : null,
						customCols ? customCols.map(function (col, i) {
							return el('div', { key: i, className: 'mj-prev-footer__col' },
								el('strong', null, col.title || ('Columna ' + (i + 1))),
								el('div', { className: 'mj-prev-line mj-prev-line--short' }),
								el('div', { className: 'mj-prev-line' })
							);
						}) : [1, 2].map(function (i) {
							return el('div', { key: i, className: 'mj-prev-footer__col' },
								el('strong', null, i === 1 ? 'Enlaces' : 'Atención'),
								el('div', { className: 'mj-prev-line mj-prev-line--short' }),
								el('div', { className: 'mj-prev-line' })
							);
						}),
						attrs.showContact !== false ? el('div', { className: 'mj-prev-footer__col' },
							el('strong', null, 'Contacto'),
							el('p', { className: 'mj-prev-note' }, 'WhatsApp, email y local')
						) : null
					),
					attrs.showLegalBar !== false ? el('div', { className: 'mj-prev-footer__legal' },
						el('span', null, attrs.copyright || '© 2026 Todos los derechos reservados.'),
						attrs.showPayments !== false ? el('span', null, '💳 Medios de pago') : null
					) : null,
					chips([
						fCols + ' columnas',
						attrs.showNewsletter ? 'Newsletter activo' : '',
						attrs.showPayments ? 'Medios de pago' : ''
					])
				);
			}
			case 'logo':
				return el('div', { className: 'mj-prev-inline-logo' },
					el('span', { className: 'dashicons dashicons-store' }),
					el('strong', null, '✦ ' + (cfg.siteName || 'Logo'))
				);
			case 'nav':
				return el('div', { className: 'mj-prev-inline-nav' },
					el('span', null, 'Inicio'),
					el('span', null, 'Tienda'),
					el('span', null, 'Categorías'),
					el('span', null, 'Contacto')
				);
			case 'search':
				return el('div', { className: 'mj-prev-inline-search' },
					el('span', { className: 'dashicons dashicons-search' }),
					el('span', null, 'Buscar productos…')
				);
			case 'carrito':
				return el('div', { className: 'mj-prev-inline-action' },
					el('span', { className: 'dashicons dashicons-cart' }),
					attrs.showCount !== false ? el('span', { className: 'mj-prev-badge' }, '0') : null
				);
			case 'favoritos':
				return el('div', { className: 'mj-prev-inline-action' },
					el('span', { className: 'dashicons dashicons-heart' }),
					attrs.showCount !== false ? el('span', { className: 'mj-prev-badge' }, '0') : null
				);
			case 'comparador':
				return el('div', { className: 'mj-prev-inline-action' },
					el('span', { className: 'dashicons dashicons-randomize' })
				);
			case 'cuenta':
				return el('div', { className: 'mj-prev-inline-action' },
					el('span', { className: 'dashicons dashicons-admin-users' }),
					attrs.showLabel ? el('span', { style: { marginLeft: '4px', fontSize: '13px' } }, attrs.label || 'Mi cuenta') : null
				);
			case 'tema-toggle':
				return el('div', { className: 'mj-prev-inline-action' },
					el('span', { className: 'dashicons dashicons-lightbulb' }),
					attrs.showLabel ? el('span', { style: { marginLeft: '4px', fontSize: '13px' } }, 'Modo') : null
				);
			case 'dolar':
				return el('div', { className: 'mj-prev-dolar-pill' },
					el('span', null, '💵 USD $1.200')
				);
			case 'medios-pago':
				return el('div', { className: 'mj-prev-pay-pill' },
					el('span', null, attrs.variant === 'badges' ? '💳 Visa · Master · MP' : '💳 Medios de pago')
				);
			case 'anuncios':
				return el('div', { className: 'mj-prev-topbar' },
					el('span', null, '⚡ Envíos a todo el país · 3 y 6 cuotas sin interés')
				);
			case 'envio-gratis':
				return el('div', { className: 'mj-prev-shipping' },
					el('span', null, '🚚 Envío gratis superando el mínimo')
				);
			case 'acciones-header':
				return el('div', { className: 'mj-prev-header__actions' },
					attrs.showDollarRate !== false ? el('span', { className: 'mj-prev-header__icon' }, '💵 USD') : null,
					attrs.showPaymentSelect !== false ? el('span', { className: 'mj-prev-header__icon' }, '💳') : null,
					attrs.showThemeToggle !== false ? el('span', { className: 'dashicons dashicons-lightbulb' }) : null,
					attrs.showAccount !== false ? el('span', { className: 'dashicons dashicons-admin-users' }) : null,
					attrs.showWishlist !== false ? el('span', { className: 'dashicons dashicons-heart' }) : null,
					attrs.showCompare !== false ? el('span', { className: 'dashicons dashicons-randomize' }) : null,
					attrs.showCart !== false ? el('span', { className: 'dashicons dashicons-cart' }) : null
				);
			case 'cta-button':
				return el('div', { className: 'mj-prev-cta-btn' },
					attrs.label || 'Ver más',
					el('span', null, ' →')
				);
			case 'newsletter':
				return el('div', { className: 'mj-prev-footer__news' },
					el('strong', null, attrs.title || 'Suscribite a novedades'),
					el('div', { className: 'mj-prev-footer__news-form' },
						el('span', { className: 'mj-prev-footer__input' }, 'Tu correo electrónico'),
						el('span', { className: 'mj-prev-btn' }, attrs.buttonText || 'Suscribirme')
					)
				);
			case 'footer-brand':
				return el('div', { className: 'mj-prev-footer__col' },
					el('strong', null, '✦ ' + (cfg.siteName || 'Tienda')),
					el('p', { className: 'mj-prev-note' }, attrs.tagline || 'Eslogan o presentación de la tienda.'),
					attrs.showSocials !== false ? el('div', { className: 'mj-prev-footer__socials' },
						el('span', { className: 'dashicons dashicons-instagram' }),
						el('span', { className: 'dashicons dashicons-facebook' }),
						el('span', { className: 'dashicons dashicons-whatsapp' })
					) : null
				);
			case 'footer-links':
				return el('div', { className: 'mj-prev-footer__col' },
					el('strong', null, attrs.title || 'Enlaces'),
					el('div', { className: 'mj-prev-line mj-prev-line--short' }),
					el('div', { className: 'mj-prev-line' })
				);
			case 'redes':
				return el('div', { className: 'mj-prev-footer__socials' },
					el('span', { className: 'dashicons dashicons-instagram' }),
					el('span', { className: 'dashicons dashicons-facebook' }),
					el('span', { className: 'dashicons dashicons-whatsapp' })
				);
			case 'footer-legal':
				return el('div', { className: 'mj-prev-footer__legal' },
					el('span', null, attrs.copyright || '© 2026 Todos los derechos reservados.'),
					attrs.showPayments !== false ? el('span', null, '💳 Medios de pago') : null
				);
			default:
				return chips((def.summary || []).map(function (k) { return attrs[k] ? String(attrs[k]) : ''; }));
		}
	}

	/* ---------------------------------------------------------------------
	 * Registro de los bloques
	 * ------------------------------------------------------------------ */
	var INLINE_BLOCKS = [
		'logo', 'nav', 'search', 'carrito', 'favoritos', 'comparador', 'cuenta',
		'tema-toggle', 'dolar', 'medios-pago', 'anuncios', 'envio-gratis',
		'acciones-header', 'cta-button', 'redes', 'footer-links', 'footer-legal'
	];
	function isInline(key) {
		return INLINE_BLOCKS.indexOf(key) !== -1;
	}

	function makeEdit(key, def) {
		return function Edit(props) {
			var attrs = props.attributes;
			var setAttributes = props.setAttributes;
			var inline = isInline(key);
			var blockProps = useBlockProps({ className: (inline ? 'mj-block-inline' : 'mj-block-card') + ' mj-block-card--' + key + (props.isSelected ? ' is-selected' : '') });
			var controls = Object.keys(def.fields).map(function (fkey) {
				var field = def.fields[fkey];
				if (!visible(field, attrs)) {
					return null;
				}
				return el(Field, {
					key: fkey, field: field, value: attrs[fkey],
					onChange: function (v) { var patch = {}; patch[fkey] = v; setAttributes(patch); }
				});
			});
			return el(Fragment, null,
				el(InspectorControls, null,
					el(c.PanelBody, { title: def.title, initialOpen: true },
						def.description ? el('p', { className: 'mj-field-help' }, def.description) : null,
						controls)
				),
				el('div', blockProps,
					inline ? null : el('div', { className: 'mj-block-card__head' },
						el('span', { className: 'dashicons dashicons-' + (def.icon || 'block-default') }),
						el('span', null, def.title),
						el('span', null, 'Bloque MJ · se dibuja con el diseño de la tienda')
					),
					preview(key, def, attrs)
				)
			);
		};
	}

	Object.keys(cfg.blocks).forEach(function (key) {
		var def = cfg.blocks[key];
		var attributes = {};
		var types = { text: 'string', textarea: 'string', url: 'string', select: 'string', category: 'string', brand: 'string', color: 'string', number: 'number', toggle: 'boolean', image: 'object', images: 'array', products: 'array', categories: 'array', repeater: 'array', gallery: 'array' };
		Object.keys(def.fields).forEach(function (fkey) {
			var f = def.fields[fkey];
			attributes[fkey] = { type: types[f.type] || 'string', default: f.default };
		});
		var variations = (def.variations || []).map(function (v) {
			return {
				name: v.name,
				title: v.title,
				description: v.description || '',
				attributes: v.attributes || {},
				isDefault: !!v.isDefault,
				scope: ['inserter', 'transform'],
				isActive: function (blockAttrs, variationAttrs) {
					return Object.keys(variationAttrs).every(function (k) { return blockAttrs[k] === variationAttrs[k]; });
				}
			};
		});
		registerBlockType('mj/' + key, {
			apiVersion: 2,
			title: def.title,
			description: def.description || '',
			icon: def.icon || 'block-default',
			category: 'mj',
			keywords: def.keywords || [],
			attributes: attributes,
			supports: {
				html: false,
				align: ['left', 'center', 'right', 'wide', 'full'],
				customClassName: true
			},
			variations: variations,
			example: { attributes: {} },
			edit: makeEdit(key, def),
			save: function () { return null; }
		});
	});

	/* ---------------------------------------------------------------------
	 * Panel "Tienda" en los ajustes del documento (solo páginas)
	 * ------------------------------------------------------------------ */
	var registerPlugin = wp.plugins && wp.plugins.registerPlugin;
	var Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
	var useEntityProp = wp.coreData && wp.coreData.useEntityProp;

	function PagePanel() {
		var info = wp.data.useSelect(function (select) {
			var ed = select('core/editor');
			return {
				type: ed.getCurrentPostType(),
				id: ed.getCurrentPostId(),
				link: ed.getPermalink ? ed.getPermalink() : '',
				slug: ed.getEditedPostSlug ? ed.getEditedPostSlug() : ''
			};
		}, []);
		var metaPair = useEntityProp('postType', 'page', 'meta');
		if (info.type !== 'page') {
			return null;
		}
		var meta = metaPair[0] || {};
		var setMeta = metaPair[1];

		var path = '';
		try {
			var home = new URL(cfg.homeUrl).pathname.replace(/\/$/, '');
			path = info.link ? new URL(info.link).pathname : '';
			if (home && path.indexOf(home) === 0) { path = path.slice(home.length); }
			path = path.replace(/^\/+|\/+$/g, '');
		} catch (e) { path = info.slug || ''; }
		var root = path.split('/')[0];

		var status;
		if (!cfg.pagesOn) {
			status = el('p', { className: 'mj-panel-status' }, 'Las páginas no se muestran en la tienda: está desmarcado en ', el('a', { href: cfg.settingsUrl }, 'los ajustes del tema'), '.');
		} else if (cfg.homeId && cfg.homeId === info.id) {
			status = el('p', { className: 'mj-panel-status' }, el('strong', null, 'Es la portada de la tienda.'), ' Sus bloques reemplazan a las secciones del panel.');
		} else if (path && (cfg.reserved.indexOf(path) !== -1 || cfg.reserved.indexOf(root) !== -1)) {
			status = el(c.Notice, { status: 'warning', isDismissible: false }, 'La dirección /' + path + ' es una pantalla de la tienda: esta página no se va a mostrar. Cambiale el enlace permanente.');
		} else if (path && cfg.overridable.indexOf(path) !== -1) {
			status = el('p', { className: 'mj-panel-status' }, 'Reemplaza a la pantalla ', el('code', null, '/' + path), ' del tema.');
		} else if (path) {
			status = el('p', { className: 'mj-panel-status' }, 'Se muestra en la tienda en ', el('code', null, '/' + path), '.');
		} else {
			status = el('p', { className: 'mj-panel-status' }, 'Al publicarla se muestra en la tienda con su enlace permanente.');
		}

		return el(Panel, { name: 'mj-pagina', title: 'Tienda', initialOpen: true },
			status,
			el(c.ToggleControl, {
				label: 'Ocultar el título de la página',
				checked: !!meta.mj_hide_title,
				__nextHasNoMarginBottom: true,
				help: 'Si la página arranca con un Hero o un Encabezado, el título se oculta solo.',
				onChange: function (v) { setMeta(Object.assign({}, meta, { mj_hide_title: !!v })); }
			}),
			el(c.ToggleControl, {
				label: 'Ocultar cabecera global (Header)',
				checked: !!meta.mj_hide_header,
				__nextHasNoMarginBottom: true,
				help: 'Útil para landing pages o cuando colocás un bloque Cabecera personalizado en esta página.',
				onChange: function (v) { setMeta(Object.assign({}, meta, { mj_hide_header: !!v })); }
			}),
			el(c.ToggleControl, {
				label: 'Ocultar pie de página global (Footer)',
				checked: !!meta.mj_hide_footer,
				__nextHasNoMarginBottom: true,
				help: 'Útil para páginas de captura, promociones o cuando colocás un bloque Pie de página personalizado.',
				onChange: function (v) { setMeta(Object.assign({}, meta, { mj_hide_footer: !!v })); }
			})
		);
	}

	if (registerPlugin && Panel && useEntityProp) {
		registerPlugin('mj-pagina', { render: PagePanel, icon: null });
	}
})(window.wp);
