/**
 * Script de administración para el Constructor de Cabecera y Pie de Página.
 *
 * Utiliza wp.element (React) nativo de WordPress para brindar una interfaz
 * reactiva y visual sin requerir compilación pesada para el admin.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.element || !window.MJ_BUILDER_DATA) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var render = wp.element.render || (wp.element.createRoot ? function (elem, container) {
		wp.element.createRoot(container).render(elem);
	} : null);

	var data = window.MJ_BUILDER_DATA;

	function BuilderApp() {
		var _scope = useState('header');
		var scope = _scope[0];
		var setScope = _scope[1];

		var _device = useState('desktop');
		var device = _device[0];
		var setDevice = _device[1];

		var _header = useState(data.headerLayout || { version: 2, desktop: { rows: [] }, mobile: { rows: [] } });
		var headerLayout = _header[0];
		var setHeaderLayout = _header[1];

		var _footer = useState(data.footerLayout || { version: 2, desktop: { rows: [] }, mobile: { rows: [] } });
		var footerLayout = _footer[0];
		var setFooterLayout = _footer[1];

		var _modal = useState(null);
		var modal = _modal[0];
		var setModal = _modal[1];

		var _toast = useState(null);
		var toast = _toast[0];
		var setToast = _toast[1];

		var _saving = useState(false);
		var isSaving = _saving[0];
		var setIsSaving = _saving[1];

		var currentLayout = scope === 'header' ? headerLayout : footerLayout;
		var setCurrentLayout = scope === 'header' ? setHeaderLayout : setFooterLayout;
		var availableBlocks = scope === 'header' ? data.headerBlocks : data.footerBlocks;

		var rows = (currentLayout[device] && currentLayout[device].rows) || [];

		// Mostrar toast por 3 segundos
		function showToast(message, type) {
			setToast({ message: message, type: type || 'success' });
			setTimeout(function () {
				setToast(null);
			}, 3500);
		}

		// Guardar cambios vía REST API
		function handleSave() {
			setIsSaving(true);
			var payload = {
				header: headerLayout,
				footer: footerLayout,
			};

			fetch(data.restUrl + '/layout', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': data.nonce,
				},
				body: JSON.stringify(payload),
			})
				.then(function (res) {
					return res.json();
				})
				.then(function (json) {
					setIsSaving(false);
					if (json && json.success) {
						showToast('¡Diseño guardado correctamente!');
					} else {
						showToast('Error al guardar los cambios.', 'error');
					}
				})
				.catch(function () {
					setIsSaving(false);
					showToast('Error de conexión al guardar.', 'error');
				});
		}

		// Restablecer valores
		function handleReset() {
			if (!window.confirm('¿Seguro que querés restablecer a los valores por defecto?')) {
				return;
			}
			setIsSaving(true);
			fetch(data.restUrl + '/reset?scope=' + scope, {
				method: 'POST',
				headers: { 'X-WP-Nonce': data.nonce },
			})
				.then(function (res) {
					return res.json();
				})
				.then(function (json) {
					setIsSaving(false);
					if (json && json.success) {
						if (scope === 'header') {
							setHeaderLayout(json.header);
						} else {
							setFooterLayout(json.footer);
						}
						showToast('Diseño restablecido.');
					}
				})
				.catch(function () {
					setIsSaving(false);
					showToast('Error al restablecer.', 'error');
				});
		}

		// Modificar filas del layout actual
		function updateRows(newRows) {
			var updated = JSON.parse(JSON.stringify(currentLayout));
			if (!updated[device]) updated[device] = { rows: [] };
			updated[device].rows = newRows;
			setCurrentLayout(updated);
		}

		// Agregar fila
		function addRow() {
			var newRow = {
				id: 'row-' + Date.now(),
				name: 'Nueva Fila (' + (rows.length + 1) + ')',
				enabled: true,
				sticky: false,
				style: {
					minHeight: '56px',
					bgColor: '',
					textColor: '',
					borderBottom: '1px solid var(--mj-line-subtle)',
					paddingX: '1.5rem',
					paddingY: '0',
				},
				zones: { left: [], center: [], right: [] },
			};
			updateRows(rows.concat([newRow]));
		}

		// Eliminar fila
		function removeRow(rowId) {
			if (!window.confirm('¿Eliminar esta fila y todos sus bloques?')) return;
			updateRows(rows.filter(function (r) { return r.id !== rowId; }));
		}

		// Mover fila
		function moveRow(index, dir) {
			var target = index + dir;
			if (target < 0 || target >= rows.length) return;
			var copy = rows.slice();
			var item = copy.splice(index, 1)[0];
			copy.splice(target, 0, item);
			updateRows(copy);
		}

		// Agregar bloque a una zona
		function addBlockToZone(rowId, zoneKey, blockDef) {
			var updated = rows.map(function (row) {
				if (row.id !== rowId) return row;
				var zones = Object.assign({}, row.zones);
				var list = (zones[zoneKey] || []).slice();
				list.push({
					id: 'blk-' + blockDef.id.replace('mj-', '') + '-' + Date.now(),
					type: blockDef.id,
					settings: Object.assign({}, blockDef.defaultSettings || {}),
					dimensions: {},
				});
				zones[zoneKey] = list;
				return Object.assign({}, row, { zones: zones });
			});
			updateRows(updated);
			setModal(null);
		}

		// Eliminar bloque
		function removeBlock(rowId, zoneKey, blockIndex) {
			var updated = rows.map(function (row) {
				if (row.id !== rowId) return row;
				var zones = Object.assign({}, row.zones);
				var list = (zones[zoneKey] || []).slice();
				list.splice(blockIndex, 1);
				zones[zoneKey] = list;
				return Object.assign({}, row, { zones: zones });
			});
			updateRows(updated);
		}

		// Mover bloque dentro de su zona
		function moveBlock(rowId, zoneKey, blockIndex, dir) {
			var updated = rows.map(function (row) {
				if (row.id !== rowId) return row;
				var zones = Object.assign({}, row.zones);
				var list = (zones[zoneKey] || []).slice();
				var target = blockIndex + dir;
				if (target < 0 || target >= list.length) return row;
				var item = list.splice(blockIndex, 1)[0];
				list.splice(target, 0, item);
				zones[zoneKey] = list;
				return Object.assign({}, row, { zones: zones });
			});
			updateRows(updated);
		}

		// Mover bloque a otra zona (left -> center -> right)
		function transferBlock(rowId, fromZone, toZone, blockIndex) {
			var updated = rows.map(function (row) {
				if (row.id !== rowId) return row;
				var zones = Object.assign({}, row.zones);
				var fromList = (zones[fromZone] || []).slice();
				var toList = (zones[toZone] || []).slice();
				var item = fromList.splice(blockIndex, 1)[0];
				toList.push(item);
				zones[fromZone] = fromList;
				zones[toZone] = toList;
				return Object.assign({}, row, { zones: zones });
			});
			updateRows(updated);
		}

		// Actualizar settings de un bloque
		function saveBlockSettings(rowId, zoneKey, blockIndex, newSettings, newDims) {
			var updated = rows.map(function (row) {
				if (row.id !== rowId) return row;
				var zones = Object.assign({}, row.zones);
				var list = (zones[zoneKey] || []).slice();
				var target = Object.assign({}, list[blockIndex], {
					settings: newSettings,
					dimensions: newDims,
				});
				list[blockIndex] = target;
				zones[zoneKey] = list;
				return Object.assign({}, row, { zones: zones });
			});
			updateRows(updated);
			setModal(null);
		}

		// Actualizar settings de una fila
		function saveRowSettings(rowId, newRowData) {
			var updated = rows.map(function (row) {
				if (row.id !== rowId) return row;
				return Object.assign({}, row, newRowData);
			});
			updateRows(updated);
			setModal(null);
		}

		// Render del Bloque en la Grilla
		function renderBlockCard(rowId, zoneKey, block, index, total) {
			var blockDef = availableBlocks.find(function (b) { return b.id === block.type; }) || {
				name: block.type,
				icon: 'admin-generic',
			};

			return el('div', { key: block.id, className: 'mj-builder-block-card' },
				el('div', { className: 'mj-builder-block-info' },
					el('span', { className: 'dashicons dashicons-' + (blockDef.icon || 'admin-generic') }),
					el('span', null, blockDef.name)
				),
				el('div', { className: 'mj-builder-block-controls' },
					index > 0 && el('button', {
						type: 'button',
						className: 'button button-small',
						title: 'Mover arriba',
						onClick: function () { moveBlock(rowId, zoneKey, index, -1); },
					}, '▲'),
					index < total - 1 && el('button', {
						type: 'button',
						className: 'button button-small',
						title: 'Mover abajo',
						onClick: function () { moveBlock(rowId, zoneKey, index, 1); },
					}, '▼'),
					zoneKey !== 'left' && el('button', {
						type: 'button',
						className: 'button button-small',
						title: 'Mover a zona izquierda',
						onClick: function () {
							transferBlock(rowId, zoneKey, zoneKey === 'right' ? 'center' : 'left', index);
						},
					}, '◀'),
					zoneKey !== 'right' && el('button', {
						type: 'button',
						className: 'button button-small',
						title: 'Mover a zona derecha',
						onClick: function () {
							transferBlock(rowId, zoneKey, zoneKey === 'left' ? 'center' : 'right', index);
						},
					}, '▶'),
					el('button', {
						type: 'button',
						className: 'button button-small',
						title: 'Configurar bloque',
						onClick: function () {
							setModal({
								type: 'edit_block',
								rowId: rowId,
								zoneKey: zoneKey,
								blockIndex: index,
								block: block,
								def: blockDef,
							});
						},
					}, '⚙'),
					el('button', {
						type: 'button',
						className: 'button button-small button-link-delete',
						title: 'Eliminar bloque',
						onClick: function () { removeBlock(rowId, zoneKey, index); },
					}, '✕')
				)
			);
		}

		// Render de una Fila
		function renderRow(row, rowIndex) {
			var zones = row.zones || { left: [], center: [], right: [] };
			return el('div', { key: row.id, className: 'mj-builder-row' + (!row.enabled ? ' is-disabled' : '') },
				el('div', { className: 'mj-builder-row-header' },
					el('div', { className: 'mj-builder-row-title' },
						el('span', { className: 'dashicons dashicons-menu' }),
						row.name,
						row.sticky && el('span', { className: 'mj-builder-row-badge' }, 'Sticky'),
						!row.enabled && el('span', { style: { color: '#888', fontSize: '11px' } }, '(Deshabilitada)')
					),
					el('div', { className: 'mj-builder-row-controls' },
						rowIndex > 0 && el('button', {
							type: 'button',
							className: 'button button-small',
							title: 'Subir fila',
							onClick: function () { moveRow(rowIndex, -1); },
						}, '▲ Subir'),
						rowIndex < rows.length - 1 && el('button', {
							type: 'button',
							className: 'button button-small',
							title: 'Bajar fila',
							onClick: function () { moveRow(rowIndex, 1); },
						}, '▼ Bajar'),
						el('button', {
							type: 'button',
							className: 'button button-small',
							onClick: function () {
								setModal({ type: 'edit_row', rowId: row.id, row: row });
							},
						}, '⚙ Ajustes'),
						el('button', {
							type: 'button',
							className: 'button button-small button-link-delete',
							onClick: function () { removeRow(row.id); },
						}, 'Eliminar')
					)
				),
				el('div', { className: 'mj-builder-zones' },
					['left', 'center', 'right'].map(function (zKey) {
						var zLabel = { left: 'Zona Izquierda', center: 'Zona Central', right: 'Zona Derecha' }[zKey];
						var list = zones[zKey] || [];
						return el('div', { key: zKey, className: 'mj-builder-zone' },
							el('div', { className: 'mj-builder-zone-header' },
								el('span', null, zLabel),
								el('span', null, list.length + ' bloques')
							),
							el('div', { className: 'mj-builder-zone-items' },
								list.map(function (blk, bIdx) {
									return renderBlockCard(row.id, zKey, blk, bIdx, list.length);
								})
							),
							el('button', {
								type: 'button',
								className: 'mj-builder-add-btn',
								onClick: function () {
									setModal({ type: 'add_block', rowId: row.id, zoneKey: zKey });
								},
							}, '+ Agregar Bloque')
						);
					})
				)
			);
		}

		// Render del Modal de Catálogo de Bloques
		function renderAddBlockModal() {
			if (!modal || modal.type !== 'add_block') return null;
			return el('div', { className: 'mj-builder-modal-overlay' },
				el('div', { className: 'mj-builder-modal' },
					el('div', { className: 'mj-builder-modal-header' },
						el('h3', null, 'Seleccionar bloque para agregar'),
						el('button', {
							type: 'button',
							className: 'button-link',
							onClick: function () { setModal(null); },
						}, '✕')
					),
					el('div', { className: 'mj-builder-modal-body' },
						el('div', { className: 'mj-builder-block-catalog' },
							availableBlocks.map(function (b) {
								return el('div', {
									key: b.id,
									className: 'mj-catalog-item',
									onClick: function () {
										addBlockToZone(modal.rowId, modal.zoneKey, b);
									},
								},
									el('div', { className: 'mj-catalog-item-title' },
										el('span', { className: 'dashicons dashicons-' + (b.icon || 'admin-generic') }),
										b.name
									),
									el('div', { className: 'mj-catalog-item-desc' }, b.description)
								);
							})
						)
					),
					el('div', { className: 'mj-builder-modal-footer' },
						el('button', {
							type: 'button',
							className: 'button',
							onClick: function () { setModal(null); },
						}, 'Cancelar')
					)
				)
			);
		}

		// Render del Modal de Ajustes de Bloque
		function renderEditBlockModal() {
			if (!modal || modal.type !== 'edit_block') return null;
			var block = modal.block;
			var settings = Object.assign({}, block.settings || {});
			var dims = Object.assign({}, block.dimensions || {});

			return el(EditBlockForm, {
				block: block,
				def: modal.def,
				onSave: function (newSettings, newDims) {
					saveBlockSettings(modal.rowId, modal.zoneKey, modal.blockIndex, newSettings, newDims);
				},
				onCancel: function () { setModal(null); },
			});
		}

		// Render del Modal de Ajustes de Fila
		function renderEditRowModal() {
			if (!modal || modal.type !== 'edit_row') return null;
			var row = modal.row;

			return el(EditRowForm, {
				row: row,
				onSave: function (newRowData) {
					saveRowSettings(modal.rowId, newRowData);
				},
				onCancel: function () { setModal(null); },
			});
		}

		return el('div', { className: 'mj-builder-root' },
			el('div', { className: 'mj-builder-header-bar' },
				el('div', { style: { display: 'flex', gap: '15px', alignItems: 'center' } },
					el('div', { className: 'mj-builder-tabs' },
						el('button', {
							type: 'button',
							className: 'mj-builder-tab-btn' + (scope === 'header' ? ' is-active' : ''),
							onClick: function () { setScope('header'); },
						}, el('span', { className: 'dashicons dashicons-arrow-up-alt' }), 'Cabecera (Header)'),
						el('button', {
							type: 'button',
							className: 'mj-builder-tab-btn' + (scope === 'footer' ? ' is-active' : ''),
							onClick: function () { setScope('footer'); },
						}, el('span', { className: 'dashicons dashicons-arrow-down-alt' }), 'Pie de página (Footer)')
					),
					el('div', { className: 'mj-builder-device-tabs' },
						el('button', {
							type: 'button',
							className: 'mj-builder-tab-btn' + (device === 'desktop' ? ' is-active' : ''),
							onClick: function () { setDevice('desktop'); },
						}, el('span', { className: 'dashicons dashicons-desktop' }), 'Computadora'),
						el('button', {
							type: 'button',
							className: 'mj-builder-tab-btn' + (device === 'mobile' ? ' is-active' : ''),
							onClick: function () { setDevice('mobile'); },
						}, el('span', { className: 'dashicons dashicons-smartphone' }), 'Celular')
					)
				),
				el('div', { className: 'mj-builder-actions' },
					el('button', {
						type: 'button',
						className: 'button button-secondary',
						onClick: addRow,
					}, '+ Nueva Fila'),
					el('button', {
						type: 'button',
						className: 'button button-secondary',
						onClick: handleReset,
						disabled: isSaving,
					}, 'Restablecer'),
					el('button', {
						type: 'button',
						className: 'button button-primary',
						onClick: handleSave,
						disabled: isSaving,
						style: { background: '#6e6bff', borderColor: '#5956e6' },
					}, isSaving ? 'Guardando...' : 'Guardar Diseño')
				)
			),

			el('div', { className: 'mj-builder-canvas' },
				rows.length === 0 ? el('div', {
					style: { padding: '40px', textAlign: 'center', background: '#f9f9fb', borderRadius: '8px', border: '1px dashed #c3c4c7', color: '#666' },
				},
					el('p', { style: { margin: '0 0 10px', fontSize: '15px' } }, 'No hay filas configuradas en esta vista.'),
					el('button', { type: 'button', className: 'button button-primary', onClick: addRow }, '+ Agregar primera fila')
				) : rows.map(renderRow)
			),

			renderAddBlockModal(),
			renderEditBlockModal(),
			renderEditRowModal(),

			toast && el('div', { className: 'mj-builder-toast is-' + toast.type },
				el('span', { className: 'dashicons dashicons-' + (toast.type === 'success' ? 'yes-alt' : 'warning') }),
				toast.message
			)
		);
	}

	// Formulario de edición de bloque
	function EditBlockForm(props) {
		var block = props.block;
		var def = props.def;
		var _settings = useState(Object.assign({}, block.settings || {}));
		var settings = _settings[0];
		var setSettings = _settings[1];

		var _dims = useState(Object.assign({}, block.dimensions || {}));
		var dims = _dims[0];
		var setDims = _dims[1];

		function setS(key, val) {
			var copy = Object.assign({}, settings);
			copy[key] = val;
			setSettings(copy);
		}

		function setD(key, val) {
			var copy = Object.assign({}, dims);
			copy[key] = val;
			setDims(copy);
		}

		return el('div', { className: 'mj-builder-modal-overlay' },
			el('div', { className: 'mj-builder-modal' },
				el('div', { className: 'mj-builder-modal-header' },
					el('h3', null, 'Ajustes del bloque: ' + def.name),
					el('button', { type: 'button', className: 'button-link', onClick: props.onCancel }, '✕')
				),
				el('div', { className: 'mj-builder-modal-body' },
					// Campos dinámicos según el tipo de bloque
					block.type === 'mj-logo' && el(wp.element.Fragment, null,
						el('div', { className: 'mj-builder-field' },
							el('label', null, 'Ancho máximo (ej: 180px o 100%)'),
							el('input', {
								type: 'text',
								value: settings.maxWidth || '',
								onChange: function (e) { setS('maxWidth', e.target.value); },
							})
						),
						el('div', { className: 'mj-builder-field' },
							el('label', null,
								el('input', {
									type: 'checkbox',
									checked: settings.showSiteName !== false,
									onChange: function (e) { setS('showSiteName', e.target.checked); },
								}),
								' Mostrar nombre del sitio junto al logo'
							)
						)
					),

					block.type === 'mj-cta-button' && el(wp.element.Fragment, null,
						el('div', { className: 'mj-builder-field' },
							el('label', null, 'Texto del botón'),
							el('input', {
								type: 'text',
								value: settings.label || '',
								onChange: function (e) { setS('label', e.target.value); },
							})
						),
						el('div', { className: 'mj-builder-field' },
							el('label', null, 'Enlace / URL de destino'),
							el('input', {
								type: 'text',
								value: settings.url || '',
								onChange: function (e) { setS('url', e.target.value); },
							})
						),
						el('div', { className: 'mj-builder-field' },
							el('label', null, 'Estilo visual'),
							el('select', {
								value: settings.variant || 'secondary',
								onChange: function (e) { setS('variant', e.target.value); },
							},
								el('option', { value: 'primary' }, 'Principal / Acento'),
								el('option', { value: 'secondary' }, 'Secundario / Superficie'),
								el('option', { value: 'outline' }, 'Contorno'),
								el('option', { value: 'ghost' }, 'Texto plano / Fantasma')
							)
						),
						el('div', { className: 'mj-builder-field' },
							el('label', null, 'Ícono Lucide (opcional, ej. phone, calendar, shopping-bag)'),
							el('input', {
								type: 'text',
								value: settings.icon || '',
								onChange: function (e) { setS('icon', e.target.value); },
							})
						)
					),

					block.type === 'mj-html' && el('div', { className: 'mj-builder-field' },
						el('label', null, 'Contenido HTML o Texto'),
						el('textarea', {
							rows: 4,
							value: settings.content || '',
							onChange: function (e) { setS('content', e.target.value); },
						})
					),

					// Dimensiones y márgenes granulares para TODOS los bloques
					el('h4', { style: { margin: '20px 0 10px', borderTop: '1px solid #eee', paddingTop: '15px' } }, 'Dimensiones y Espaciados'),
					el('div', { style: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' } },
						el('div', { className: 'mj-builder-field' },
							el('label', null, 'Margen izquierdo (ej: 8px)'),
							el('input', {
								type: 'text',
								value: dims.marginLeft || '',
								onChange: function (e) { setD('marginLeft', e.target.value); },
							})
						),
						el('div', { className: 'mj-builder-field' },
							el('label', null, 'Margen derecho (ej: 8px)'),
							el('input', {
								type: 'text',
								value: dims.marginRight || '',
								onChange: function (e) { setD('marginRight', e.target.value); },
							})
						)
					),
					el('div', { className: 'mj-builder-field' },
						el('label', null, 'Ancho personalizado (vacío = automático flex)'),
						el('input', {
							type: 'text',
							placeholder: 'auto, 200px, 100%',
							value: dims.width || '',
							onChange: function (e) { setD('width', e.target.value); },
						})
					)
				),
				el('div', { className: 'mj-builder-modal-footer' },
					el('button', { type: 'button', className: 'button', onClick: props.onCancel }, 'Cancelar'),
					el('button', {
						type: 'button',
						className: 'button button-primary',
						onClick: function () { props.onSave(settings, dims); },
					}, 'Guardar Bloque')
				)
			)
		);
	}

	// Formulario de edición de fila
	function EditRowForm(props) {
		var row = props.row;
		var _name = useState(row.name || '');
		var name = _name[0];
		var setName = _name[1];

		var _enabled = useState(row.enabled !== false);
		var enabled = _enabled[0];
		var setEnabled = _enabled[1];

		var _sticky = useState(!!row.sticky);
		var sticky = _sticky[0];
		var setSticky = _sticky[1];

		var _style = useState(Object.assign({}, row.style || {}));
		var style = _style[0];
		var setStyle = _style[1];

		function setSt(k, v) {
			var copy = Object.assign({}, style);
			copy[k] = v;
			setStyle(copy);
		}

		return el('div', { className: 'mj-builder-modal-overlay' },
			el('div', { className: 'mj-builder-modal' },
				el('div', { className: 'mj-builder-modal-header' },
					el('h3', null, 'Ajustes de la fila: ' + name),
					el('button', { type: 'button', className: 'button-link', onClick: props.onCancel }, '✕')
				),
				el('div', { className: 'mj-builder-modal-body' },
					el('div', { className: 'mj-builder-field' },
						el('label', null, 'Nombre descriptivo de la fila'),
						el('input', {
							type: 'text',
							value: name,
							onChange: function (e) { setName(e.target.value); },
						})
					),
					el('div', { className: 'mj-builder-field' },
						el('label', null,
							el('input', {
								type: 'checkbox',
								checked: enabled,
								onChange: function (e) { setEnabled(e.target.checked); },
							}),
							' Fila habilitada y visible en la tienda'
						)
					),
					el('div', { className: 'mj-builder-field' },
						el('label', null,
							el('input', {
								type: 'checkbox',
								checked: sticky,
								onChange: function (e) { setSticky(e.target.checked); },
							}),
							' Fila fija arriba al scrollear (Sticky)'
						)
					),
					el('div', { className: 'mj-builder-field' },
						el('label', null, 'Altura mínima de la fila (ej: 64px, 48px, auto)'),
						el('input', {
							type: 'text',
							value: style.minHeight || '',
							onChange: function (e) { setSt('minHeight', e.target.value); },
						})
					),
					el('div', { className: 'mj-builder-field' },
						el('label', null, 'Color de fondo personalizado (vacío = tema)'),
						el('input', {
							type: 'text',
							placeholder: '#ffffff o rgba(...)',
							value: style.bgColor || '',
							onChange: function (e) { setSt('bgColor', e.target.value); },
						})
					),
					el('div', { className: 'mj-builder-field' },
						el('label', null, 'Color de texto personalizado (vacío = tema)'),
						el('input', {
							type: 'text',
							placeholder: '#111111',
							value: style.textColor || '',
							onChange: function (e) { setSt('textColor', e.target.value); },
						})
					),
					el('div', { className: 'mj-builder-field' },
						el('label', null, 'Borde inferior'),
						el('select', {
							value: style.borderBottom || '',
							onChange: function (e) { setSt('borderBottom', e.target.value); },
						},
							el('option', { value: '' }, 'Por defecto del tema'),
							el('option', { value: '1px solid var(--mj-line-subtle)' }, 'Línea sutil'),
							el('option', { value: 'none' }, 'Sin borde'),
							el('option', { value: '2px solid var(--mj-accent)' }, 'Línea con color de acento')
						)
					)
				),
				el('div', { className: 'mj-builder-modal-footer' },
					el('button', { type: 'button', className: 'button', onClick: props.onCancel }, 'Cancelar'),
					el('button', {
						type: 'button',
						className: 'button button-primary',
						onClick: function () {
							props.onSave({
								name: name,
								enabled: enabled,
								sticky: sticky,
								style: style,
							});
						},
					}, 'Guardar Fila')
				)
			)
		);
	}

	// Montaje de la aplicación
	document.addEventListener('DOMContentLoaded', function () {
		var container = document.getElementById('mj-builder-app');
		if (container) {
			render(el(BuilderApp), container);
		}
	});
})(window.wp);
