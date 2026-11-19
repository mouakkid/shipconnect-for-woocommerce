/**
 * ShipConnect : interactions du panneau, de la liste et de la fiche commande.
 * Pas de dépendance externe. Tout texte venant du serveur est inséré via textContent,
 * sauf les fragments HTML rendus et échappés côté serveur (encadré commande, cellule de liste).
 */
(function () {
	'use strict';

	var CFG = window.ShipC;
	if (!CFG) {
		return;
	}
	var T = CFG.i18n || {};

	/* ---------- Utilitaires ---------- */

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			var value = attrs[key];
			if (value === false || value === null || value === undefined) {
				return;
			}
			node.setAttribute(key, value === true ? '' : String(value));
		});
		(children || []).forEach(function (child) {
			if (child === null || child === undefined) {
				return;
			}
			node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		});
		return node;
	}

	function all(selector, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(selector));
	}

	function format(text) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(text).replace(/%(\d+\$)?s/g, function (match, pos) {
			var index = pos ? parseInt(pos, 10) - 1 : i++;
			return args[index] !== undefined ? String(args[index]) : '';
		});
	}

	function appendNested(fd, prefix, value) {
		if (value && typeof value === 'object') {
			Object.keys(value).forEach(function (key) {
				appendNested(fd, prefix + '[' + key + ']', value[key]);
			});
		} else {
			fd.append(prefix, value === null || value === undefined ? '' : String(value));
		}
	}

	/** Action AJAX WordPress (préfixe shipc_ ajouté automatiquement). */
	function post(action, data) {
		var body;
		if (data instanceof FormData) {
			body = data;
		} else {
			body = new FormData();
			Object.keys(data || {}).forEach(function (key) {
				appendNested(body, key, data[key]);
			});
		}
		body.append('action', 'shipc_' + action);
		body.append('nonce', CFG.nonce);

		return fetch(CFG.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (response) {
				return response.json().catch(function () {
					return { success: false, data: { message: T.error } };
				});
			})
			.then(function (json) {
				if (json && json.success) {
					return json.data || {};
				}
				throw new Error((json && json.data && json.data.message) || T.error);
			});
	}

	/* ---------- Notifications ---------- */

	var toastTimer = null;

	/** Les erreurs restent affichées jusqu'à fermeture ; les succès disparaissent seuls. */
	function toast(message, type) {
		var node = document.getElementById('shipc-toast');
		if (!node) {
			node = el('div', { id: 'shipc-toast', class: 'shipc-toast' });
			document.body.appendChild(node);
		}
		type = type || 'ok';
		node.innerHTML = '';
		node.setAttribute('role', type === 'error' ? 'alert' : 'status');
		node.className = 'shipc-toast is-' + type;
		node.appendChild(el('span', { class: 'shipc-toast__text' }, [message]));
		var close = el('button', { type: 'button', class: 'shipc-toast__close', 'aria-label': T.close }, ['×']);
		close.addEventListener('click', function () {
			node.hidden = true;
		});
		node.appendChild(close);
		node.hidden = false;
		clearTimeout(toastTimer);
		if (type !== 'error') {
			toastTimer = setTimeout(function () {
				node.hidden = true;
			}, 5000);
		}
	}

	function setBusy(button, busy) {
		if (!button) {
			return;
		}
		if (busy) {
			// Largeur figée : le bouton ne saute pas quand son libellé devient « Veuillez patienter… ».
			if (button.offsetWidth) {
				button.style.minWidth = button.offsetWidth + 'px';
			}
			button.setAttribute('data-label', button.textContent);
			button.textContent = T.working;
			button.disabled = true;
			button.setAttribute('aria-busy', 'true');
		} else {
			button.textContent = button.getAttribute('data-label') || button.textContent;
			button.disabled = false;
			button.removeAttribute('aria-busy');
			button.style.minWidth = '';
		}
	}

	/** Action sur un bouton : désactivé pendant l'appel ; résout avec la réponse, ou null en cas d'erreur. */
	function run(button, action, data, statusNode) {
		setBusy(button, true);
		if (statusNode) {
			statusNode.textContent = '';
			statusNode.classList.remove('is-error');
		}
		return post(action, data)
			.then(
				function (res) {
					if (res.message) {
						toast(res.message, res.warning ? 'warn' : 'ok');
					}
					return res;
				},
				function (err) {
					toast(err.message, 'error');
					if (statusNode) {
						statusNode.textContent = err.message;
						statusNode.classList.add('is-error');
					}
					return null;
				}
			)
			.then(function (res) {
				if (button && button.isConnected) {
					setBusy(button, false);
				}
				return res;
			});
	}

	/* ---------- Fenêtres de dialogue (élément <dialog> natif) ---------- */

	var dialogCount = 0;

	/**
	 * Ouvre un dialogue modal.
	 * opts : title, body (nœud), confirmLabel, danger, hideConfirm, onConfirm(form, status, confirmBtn) -> valeur | false | Promise.
	 * Résout avec la valeur de onConfirm, ou null si l'utilisateur annule. Le focus revient à l'élément d'origine.
	 */
	function openDialog(opts) {
		return new Promise(function (resolve) {
			dialogCount++;
			var titleId = 'shipc-dialog-title-' + dialogCount;
			var opener = document.activeElement;
			var dialog = el('dialog', { class: 'shipc-dialog', 'aria-labelledby': titleId });
			var form = el('form', { method: 'dialog', novalidate: true });
			var status = el('p', { class: 'shipc-status', role: 'status', 'aria-live': 'polite' });
			var cancel = el('button', { type: 'button', class: 'button' }, [opts.cancelLabel || T.cancel]);
			var confirm = el('button', { type: 'submit', class: 'button ' + (opts.danger ? 'button-primary shipc-danger' : 'button-primary') }, [opts.confirmLabel || T.confirm]);
			var closeX = el('button', { type: 'button', class: 'shipc-dialog__close', 'aria-label': T.close }, ['×']);
			var settled = false;

			form.appendChild(el('h2', { id: titleId, class: 'shipc-dialog__title' }, [opts.title || '']));
			if (opts.body) {
				form.appendChild(opts.body);
			}
			form.appendChild(status);
			form.appendChild(el('div', { class: 'shipc-dialog__actions' }, opts.hideConfirm ? [cancel] : [cancel, confirm]));
			dialog.appendChild(closeX);
			dialog.appendChild(form);
			document.body.appendChild(dialog);

			function finish(value) {
				if (settled) {
					return;
				}
				settled = true;
				if (dialog.open) {
					dialog.close();
				}
				dialog.remove();
				if (opener && typeof opener.focus === 'function' && opener.isConnected) {
					opener.focus();
				}
				resolve(value);
			}

			cancel.addEventListener('click', function () {
				finish(null);
			});
			closeX.addEventListener('click', function () {
				finish(null);
			});
			dialog.addEventListener('cancel', function (event) {
				event.preventDefault();
				finish(null);
			});
			dialog.addEventListener('click', function (event) {
				if (event.target === dialog) {
					finish(null);
				}
			});
			// Le message d'erreur s'efface dès que l'utilisateur corrige sa saisie ou son choix.
			var clearError = function () {
				if (status.classList.contains('is-error')) {
					status.textContent = '';
					status.classList.remove('is-error');
				}
			};
			form.addEventListener('input', clearError);
			form.addEventListener('change', clearError);
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				status.classList.remove('is-error');
				var result = opts.onConfirm ? opts.onConfirm(form, status, confirm) : true;
				Promise.resolve(result).then(function (value) {
					if (value !== false && value !== undefined) {
						finish(value);
					}
				});
			});

			if (typeof dialog.showModal === 'function') {
				dialog.showModal();
			} else {
				dialog.setAttribute('open', '');
			}
			var first = form.querySelector('input:not([type="hidden"]), select, textarea') || confirm;
			if (first) {
				first.focus();
			}
		});
	}

	function confirmDialog(title, message, confirmLabel, danger, cancelLabel) {
		return openDialog({
			title: title,
			body: el('p', {}, [message]),
			confirmLabel: confirmLabel,
			cancelLabel: cancelLabel,
			danger: !!danger
		});
	}

	/* ---------- Assistant de connexion ---------- */

	function findAdapter(key) {
		var found = null;
		(CFG.adapters || []).forEach(function (adapter) {
			if (adapter.adapter === key) {
				found = adapter;
			}
		});
		return found;
	}

	function openConnect(key, presetName) {
		var meta = findAdapter(key);
		if (!meta) {
			return;
		}
		var isManual = key === 'manual';
		var body = el('div', { class: 'shipc-form' }, [el('p', { class: 'shipc-muted' }, [meta.description])]);

		if (meta.docs_url) {
			body.appendChild(
				el('p', {}, [el('a', { class: 'shipc-link shipc-link--flush', href: meta.docs_url, target: '_blank', rel: 'noopener noreferrer' }, [T.carrierDocs])])
			);
		}

		(meta.fields || []).forEach(function (field) {
			var id = 'shipc-f-' + key + '-' + field.key;
			var input;
			if (field.type === 'select') {
				input = el('select', { id: id, name: field.key, required: !!field.required });
				Object.keys(field.options || {}).forEach(function (value) {
					input.appendChild(el('option', { value: value }, [field.options[value]]));
				});
				if (field.default !== undefined && field.default !== null && field.default !== '') {
					input.value = String(field.default);
				}
			} else {
				var type = { password: 'password', url: 'url', number: 'number' }[field.type] || 'text';
				input = el('input', {
					type: type,
					id: id,
					name: field.key,
					placeholder: field.placeholder || '',
					autocomplete: type === 'password' ? 'new-password' : 'off',
					required: !!field.required,
					step: type === 'number' ? 'any' : null
				});
				if (field.default !== undefined && field.default !== null && type !== 'password') {
					input.value = String(field.default);
				}
				if (isManual && field.key === 'name' && presetName) {
					input.value = presetName;
				}
			}
			var row = el('p', { class: 'shipc-field' }, [el('label', { for: id }, [field.label + (field.required ? ' *' : '')]), input]);
			if (field.help) {
				row.appendChild(el('span', { class: 'shipc-help' }, [field.help]));
			}
			body.appendChild(row);
		});

		openDialog({
			title: isManual ? T.manualTitle : T.connectTitle + ' ' + meta.name,
			body: body,
			confirmLabel: isManual ? T.addManual : T.connect,
			onConfirm: function (form, status, button) {
				var fields = {};
				all('input, select', form).forEach(function (input) {
					fields[input.name] = input.value.trim();
				});
				var missing = all('input[required], select[required]', form).filter(function (input) {
					return input.value.trim() === '';
				});
				if (missing.length) {
					var label = form.querySelector('label[for="' + missing[0].id + '"]');
					status.textContent = T.required + ' : ' + (label ? label.textContent.replace(' *', '') : missing[0].name);
					status.classList.add('is-error');
					missing[0].focus();
					return false;
				}
				setBusy(button, true);
				status.textContent = T.working;
				return post('connect', { adapter: key, fields: fields }).then(
					function (res) {
						toast(res.message + (res.warning ? ' ' + res.warning : ''), res.warning ? 'warn' : 'ok');
						setTimeout(function () {
							window.location.reload();
						}, 1000);
						return true;
					},
					function (err) {
						setBusy(button, false);
						status.textContent = err.message;
						status.classList.add('is-error');
						return false;
					}
				);
			}
		});
	}

	/* ---------- Filtres du catalogue ---------- */

	function applyFilters() {
		var cards = all('[data-shipc-card]');
		if (!cards.length) {
			return;
		}
		var search = document.querySelector('[data-shipc-filter="search"]');
		var region = document.querySelector('[data-shipc-filter="region"]');
		var api = document.querySelector('[data-shipc-filter="api"]');
		var allCountries = document.querySelector('[data-shipc-filter="all"]');
		var counter = document.querySelector('[data-shipc-count]');

		var q = search ? search.value.toLowerCase().trim() : '';
		var r = region ? region.value : '';
		var a = api ? api.value : '';
		var everywhere = !allCountries || allCountries.checked || q !== '';
		var shown = 0;

		cards.forEach(function (card) {
			var match =
				(everywhere || card.getAttribute('data-local') === '1') &&
				(!q || (card.getAttribute('data-search') || '').indexOf(q) !== -1) &&
				(!r || card.getAttribute('data-region') === r) &&
				(!a || card.getAttribute('data-api') === a);
			card.hidden = !match;
			if (match) {
				shown++;
			}
		});

		if (counter) {
			counter.textContent = format(T.results, shown, cards.length);
		}
	}

	/* ---------- Envoi d'une commande : devis, confirmation, création ---------- */

	function daysLabel(days) {
		if (days === null || days === undefined || days === '') {
			return '';
		}
		return Number(days) === 1 ? T.oneDay : format(T.manyDays, days);
	}

	/** Dialogue de choix du tarif : résout avec le code de service choisi, ou null. */
	function chooseRate(quote, orderLabel) {
		var list = el('fieldset', { class: 'shipc-rates' }, [el('legend', { class: 'screen-reader-text' }, [T.chooseRate])]);
		quote.rates.forEach(function (rate, index) {
			var id = 'shipc-rate-' + index;
			var radio = el('input', { type: 'radio', name: 'service_code', id: id, value: rate.service_code, checked: !!rate.selected });
			var meta = daysLabel(rate.days);
			list.appendChild(
				el('label', { class: 'shipc-rate', for: id }, [
					radio,
					el('span', { class: 'shipc-rate__name' }, [rate.label || rate.service_code]),
					meta ? el('span', { class: 'shipc-rate__days' }, [meta]) : null,
					el('strong', { class: 'shipc-rate__price' }, [rate.price])
				])
			);
		});
		var body = el('div', {}, [el('p', { class: 'shipc-muted' }, [T.chooseRate]), list]);

		return openDialog({
			title: format(T.sendTitle, orderLabel, quote.carrier),
			body: body,
			confirmLabel: T.confirmBuy,
			onConfirm: function (form, status) {
				var checked = form.querySelector('input[name="service_code"]:checked');
				if (!checked) {
					// Aucun tarif coché : on le dit, au lieu d'un bouton qui ne réagit pas.
					status.textContent = T.chooseRateRequired || 'Choose a service to continue.';
					status.classList.add('is-error');
					var firstRate = form.querySelector('input[name="service_code"]');
					if (firstRate) {
						firstRate.focus();
					}
					return false;
				}
				return checked.value;
			}
		});
	}

	/** Dialogue de saisie du numéro de suivi (transporteur sans API). */
	function askTracking(carrier, orderLabel) {
		var input = el('input', { type: 'text', id: 'shipc-ask-tracking', name: 'tracking', autocomplete: 'off', required: true, class: 'widefat' });
		var body = el('p', { class: 'shipc-field' }, [el('label', { for: 'shipc-ask-tracking' }, [T.trackingPrompt]), input]);
		return openDialog({
			title: format(T.sendTitle, orderLabel, carrier),
			body: body,
			confirmLabel: T.send,
			onConfirm: function (form, status) {
				var value = input.value.trim();
				if (!value) {
					status.textContent = T.trackingRequired;
					status.classList.add('is-error');
					input.focus();
					return false;
				}
				return value;
			}
		});
	}

	/**
	 * Envoi depuis l'encadré de commande, le tableau de bord ou la liste des commandes.
	 * Transporteur API : devis puis confirmation du tarif. Transporteur manuel : numéro de suivi.
	 */
	function pushOrder(button) {
		var orderId = button.getAttribute('data-order-id');
		var box = button.closest('.shipc-box');
		var cell = button.closest('[data-shipc-cell], td.column-shipc, td.shipc');
		var statusNode = box ? box.querySelector('.shipc-box__status') : null;
		var select = box ? box.querySelector('[data-shipc-field="connection_id"]') : null;
		var trackingInput = box ? box.querySelector('[data-shipc-field="tracking_number"]') : null;
		var orderLabel = button.getAttribute('data-order-label') || '#' + orderId;
		var data = { order_id: orderId, connection_id: select ? select.value : '' };

		var manualSelected = select && select.selectedOptions[0] && select.selectedOptions[0].getAttribute('data-manual') === '1';
		if (manualSelected && trackingInput && !trackingInput.value.trim()) {
			if (statusNode) {
				statusNode.textContent = T.trackingRequired;
				statusNode.classList.add('is-error');
			}
			trackingInput.focus();
			return;
		}

		// Un ancien message d'erreur ne reste pas affiché pendant la nouvelle tentative.
		if (statusNode) {
			statusNode.textContent = '';
			statusNode.classList.remove('is-error');
		}
		setBusy(button, true);
		post('quote_order', data)
			.then(function (quote) {
				setBusy(button, false);
				data.connection_id = quote.connection_id;
				if (quote.manual) {
					if (trackingInput && trackingInput.value.trim()) {
						return { tracking_number: trackingInput.value.trim() };
					}
					return askTracking(quote.carrier, orderLabel).then(function (tracking) {
						return tracking ? { tracking_number: tracking } : null;
					});
				}
				return chooseRate(quote, orderLabel).then(function (code) {
					return code ? { service_code: code } : null;
				});
			})
			.then(function (extra) {
				if (!extra) {
					return null;
				}
				Object.keys(extra).forEach(function (key) {
					data[key] = extra[key];
				});
				return run(button, 'push_order', data, statusNode);
			})
			.then(function (res) {
				if (!res) {
					return;
				}
				if (box && res.box_html) {
					replaceBox(box, res.box_html);
				} else if (cell && res.cell_html !== undefined) {
					cell.innerHTML = res.cell_html;
				}
			})
			.catch(function (err) {
				setBusy(button, false);
				toast(err.message, 'error');
				if (statusNode) {
					statusNode.textContent = err.message;
					statusNode.classList.add('is-error');
				}
			});
	}

	/** Remplace l'encadré de commande par la version rendue par le serveur. */
	function replaceBox(box, html) {
		var wrap = document.createElement('div');
		wrap.innerHTML = html;
		var fresh = wrap.querySelector('.shipc-box');
		if (!fresh) {
			return;
		}
		box.replaceWith(fresh);
		syncBoxFields(fresh);
		var focusTarget = fresh.querySelector('button, a, select');
		if (focusTarget) {
			focusTarget.focus();
		}
	}

	/** Encadré : champ de suivi pour un transporteur manuel, alerte si le transporteur n'encaisse pas le contre-remboursement. */
	function syncBoxFields(box) {
		var select = box.querySelector('[data-shipc-field="connection_id"]');
		if (!select) {
			return;
		}
		var option = select.selectedOptions[0];
		var manual = option && option.getAttribute('data-manual') === '1';
		var cod = option && option.getAttribute('data-cod') === '1';
		var trackingRow = box.querySelector('[data-shipc-tracking-row]');
		var codWarning = box.querySelector('[data-shipc-cod-warning]');
		if (trackingRow) {
			trackingRow.hidden = !manual;
		}
		if (codWarning) {
			codWarning.hidden = manual || cod;
		}
	}

	/* ---------- Infobulles des graphiques (souris et clavier) ---------- */

	var tip = null;

	function showTip(target) {
		if (!tip) {
			tip = el('div', { class: 'shipc-tip', role: 'tooltip', hidden: true });
			document.body.appendChild(tip);
		}
		tip.innerHTML = '';
		tip.appendChild(el('strong', { class: 'shipc-tip__value' }, [target.getAttribute('data-tip-value') || '']));
		tip.appendChild(el('span', { class: 'shipc-tip__label' }, [target.getAttribute('data-tip-label') || '']));
		tip.hidden = false;

		var rect = target.getBoundingClientRect();
		var tipRect = tip.getBoundingClientRect();
		var left = rect.left + rect.width / 2 - tipRect.width / 2 + window.scrollX;
		var top = rect.top - tipRect.height - 8 + window.scrollY;
		left = Math.max(8 + window.scrollX, Math.min(left, window.scrollX + document.documentElement.clientWidth - tipRect.width - 8));
		if (top < window.scrollY + 8) {
			top = rect.bottom + 8 + window.scrollY;
		}
		tip.style.left = left + 'px';
		tip.style.top = top + 'px';
	}

	function hideTip() {
		if (tip) {
			tip.hidden = true;
		}
	}

	document.addEventListener('mouseover', function (event) {
		var target = event.target.closest ? event.target.closest('[data-shipc-tip]') : null;
		if (target) {
			showTip(target);
		}
	});
	document.addEventListener('mouseout', function (event) {
		if (event.target.closest && event.target.closest('[data-shipc-tip]')) {
			hideTip();
		}
	});
	document.addEventListener('focusin', function (event) {
		if (event.target.matches && event.target.matches('[data-shipc-tip]')) {
			showTip(event.target);
		}
	});
	document.addEventListener('focusout', function (event) {
		if (event.target.matches && event.target.matches('[data-shipc-tip]')) {
			hideTip();
		}
	});

	/* ---------- Clics ---------- */

	document.addEventListener('click', function (event) {
		var button = event.target.closest ? event.target.closest('[data-shipc-action]') : null;
		if (!button) {
			return;
		}
		var action = button.getAttribute('data-shipc-action');
		var box = button.closest('.shipc-box');
		var statusNode = box ? box.querySelector('.shipc-box__status') : null;

		switch (action) {
			case 'open-connect':
				openConnect(button.getAttribute('data-adapter'), button.getAttribute('data-name') || '');
				break;

			case 'test-connection':
				run(button, 'connection_test', { id: button.getAttribute('data-id') });
				break;

			case 'delete-connection':
				confirmDialog(T.deleteTitle, T.confirmDelete, T.delete, true).then(function (ok) {
					if (ok) {
						run(button, 'connection_delete', { id: button.getAttribute('data-id') }).then(function (res) {
							if (res) {
								window.location.reload();
							}
						});
					}
				});
				break;

			case 'push-order':
				event.preventDefault();
				pushOrder(button);
				break;

			case 'sync-shipment':
				run(button, 'sync_shipment', { shipment_id: button.getAttribute('data-shipment-id') }, statusNode).then(function (res) {
					if (!res) {
						return;
					}
					if (box && res.box_html) {
						replaceBox(box, res.box_html);
					} else {
						window.location.reload();
					}
				});
				break;

			case 'update-shipment':
				run(
					button,
					'shipment_update',
					{
						shipment_id: button.getAttribute('data-shipment-id'),
						status: box.querySelector('[data-shipc-field="status"]').value,
						tracking_number: box.querySelector('[data-shipc-field="new_tracking"]').value.trim()
					},
					statusNode
				).then(function (res) {
					if (res && res.box_html) {
						replaceBox(box, res.box_html);
					}
				});
				break;

			case 'cancel-shipment':
				confirmDialog(T.cancelShipment, T.confirmCancel, T.cancelShipment, true, T.keepShipment).then(function (ok) {
					if (!ok) {
						return;
					}
					run(button, 'shipment_cancel', { shipment_id: button.getAttribute('data-shipment-id') }, statusNode).then(function (res) {
						if (res && res.box_html) {
							replaceBox(box, res.box_html);
						}
					});
				});
				break;

			case 'dismiss-announcement':
				post('dismiss_announcement', { id: button.getAttribute('data-id') }).then(
					function () {
						var item = button.closest('[data-announcement]');
						var list = item ? item.parentNode : null;
						if (item) {
							item.remove();
						}
						if (list && !list.children.length) {
							var card = list.closest('.shipc-news');
							if (card) {
								card.remove();
							}
						}
					},
					function (err) {
						toast(err.message, 'error');
					}
				);
				break;

			case 'regenerate-webhook':
				confirmDialog(T.regenerateTitle, T.confirmRegenerate, T.regenerate, true).then(function (ok) {
					if (ok) {
						run(button, 'regenerate_webhook', {}).then(function (res) {
							if (res) {
								setTimeout(function () {
									window.location.reload();
								}, 800);
							}
						});
					}
				});
				break;

			case 'copy':
				var target = document.getElementById(button.getAttribute('data-copy-target'));
				if (target) {
					target.select();
					var done = function () {
						toast(T.copied, 'ok');
					};
					if (navigator.clipboard && window.isSecureContext) {
						navigator.clipboard.writeText(target.value).then(done, function () {
							document.execCommand('copy');
							done();
						});
					} else {
						document.execCommand('copy');
						done();
					}
				}
				break;
		}
	});

	/* ---------- Changements de champs ---------- */

	document.addEventListener('change', function (event) {
		var target = event.target;
		if (!target || !target.matches) {
			return;
		}

		if (target.matches('[data-shipc-toggle]')) {
			var row = target.closest('[data-connection-id]');
			post('connection_update', {
				id: row ? row.getAttribute('data-connection-id') : '',
				field: target.getAttribute('data-shipc-toggle'),
				value: target.checked ? '1' : '0'
			}).then(
				function () {
					toast(T.saved, 'ok');
				},
				function (err) {
					target.checked = !target.checked;
					toast(err.message, 'error');
				}
			);
		} else if (target.matches('[data-shipc-filter]')) {
			applyFilters();
		} else if (target.matches('[data-shipc-field="connection_id"]')) {
			var box = target.closest('.shipc-box');
			if (box) {
				syncBoxFields(box);
			}
		}
	});

	document.addEventListener('input', function (event) {
		if (event.target && event.target.matches && event.target.matches('[data-shipc-filter="search"]')) {
			applyFilters();
		}
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') {
			hideTip();
		}
	});

	/* ---------- Réglages : enregistrement et modifications non enregistrées ---------- */

	var settingsForm = document.querySelector('[data-shipc-form="settings"]');
	if (settingsForm) {
		var dirty = false;
		var dirtyFlag = settingsForm.querySelector('[data-shipc-dirty]');
		var markDirty = function (value) {
			dirty = value;
			if (dirtyFlag) {
				dirtyFlag.hidden = !value;
			}
		};

		settingsForm.addEventListener('input', function () {
			markDirty(true);
		});
		settingsForm.addEventListener('change', function () {
			markDirty(true);
		});
		settingsForm.addEventListener('submit', function (event) {
			event.preventDefault();
			var button = settingsForm.querySelector('button[type="submit"]');
			run(button, 'save_settings', new FormData(settingsForm)).then(function (res) {
				if (res) {
					markDirty(false);
				}
			});
		});
		window.addEventListener('beforeunload', function (event) {
			if (dirty) {
				event.preventDefault();
				event.returnValue = T.unsaved;
				return T.unsaved;
			}
		});
	}

	/* ---------- Textes repliés (« Lire la suite ») ---------- */

	// Le bouton [data-shipc-expand="id"] déplie ou replie le texte [data-shipc-clamp] portant cet id.
	document.addEventListener('click', function (event) {
		var toggle = event.target.closest ? event.target.closest('[data-shipc-expand]') : null;
		if (!toggle) {
			return;
		}
		var target = document.getElementById(toggle.getAttribute('data-shipc-expand'));
		if (!target) {
			return;
		}
		var open = toggle.getAttribute('aria-expanded') !== 'true';
		toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		target.classList.toggle('is-expanded', open);
	});

	/** Masque le bouton quand le texte tient déjà dans la hauteur repliée (mesuré sur les éléments visibles). */
	function syncClamps() {
		all('[data-shipc-expand]').forEach(function (toggle) {
			var target = document.getElementById(toggle.getAttribute('data-shipc-expand'));
			if (!target || !target.offsetParent || toggle.getAttribute('aria-expanded') === 'true') {
				return;
			}
			toggle.hidden = target.scrollHeight <= target.clientHeight + 1;
		});
	}

	if (document.querySelector('[data-shipc-expand]')) {
		var clampTimer = null;
		var queueClamps = function () {
			clearTimeout(clampTimer);
			clampTimer = setTimeout(syncClamps, 60);
		};
		// Après un filtrage du catalogue, d'autres fiches deviennent visibles : on remesure.
		document.addEventListener('input', queueClamps);
		document.addEventListener('change', queueClamps);
		document.addEventListener('click', function (event) {
			if (event.target.closest && event.target.closest('[data-shipc-catalog-reset]')) {
				queueClamps();
			}
		});
		window.addEventListener('resize', queueClamps);
		window.addEventListener('load', queueClamps);
		queueClamps();
	}

	/* ---------- Démarrage ---------- */

	all('.shipc-box').forEach(syncBoxFields);
	applyFilters();

	// Petits écrans : la barre d'onglets défile, l'onglet courant est amené dans la zone visible.
	var tabs = document.querySelector('.shipc-tabs');
	var activeTab = tabs ? tabs.querySelector('.shipc-tab.is-active') : null;
	if (activeTab && tabs.scrollWidth > tabs.clientWidth) {
		var tabRect = activeTab.getBoundingClientRect();
		var navRect = tabs.getBoundingClientRect();
		tabs.scrollLeft += tabRect.left - navRect.left - (navRect.width - tabRect.width) / 2;
	}

	// Le graphique par jour défile dans sa carte : on montre d'abord les jours les plus récents (à droite).
	if (document.documentElement.getAttribute('dir') !== 'rtl') {
		all('.shipc-chart-wrap').forEach(function (wrap) {
			if (wrap.scrollWidth > wrap.clientWidth) {
				wrap.scrollLeft = wrap.scrollWidth;
			}
		});
	}
})();
