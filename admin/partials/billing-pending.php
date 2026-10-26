<?php
/**
 * Partial: Por facturar — cobros ya pagados que aún no tienen factura SRI.
 *
 * Flujo principal del hub: el administrador registra el pago en Cobranza y
 * aquí emite la factura en 1 clic (o por lote). Los datos se cargan por AJAX.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

$pending_cfg   = Arriendo_Facil_SRI_Config::get();
$pending_ready = ! empty( $pending_cfg['ruc'] ) && ! empty( $pending_cfg['cert_filename'] );
$pending_nonce = wp_create_nonce( 'af_billing_nonce' );
?>

<?php if ( ! $pending_ready ) : ?>
	<div class="af-pending-setup">
		<span class="af-pending-setup__icon" aria-hidden="true"><?php echo af_lucide( 'triangle-alert', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
		<div>
			<h3><?php esc_html_e( 'Antes de facturar, conecta tu firma electrónica', 'arriendo-facil' ); ?></h3>
			<p><?php esc_html_e( 'Cada propietario factura con su propio RUC y certificado (.p12). Es una configuración de una sola vez.', 'arriendo-facil' ); ?></p>
		</div>
		<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=af-billing&tab=sri' ) ); ?>"><?php esc_html_e( 'Configurar ahora', 'arriendo-facil' ); ?></a>
	</div>
<?php endif; ?>

<section class="af-pending" id="af-pending" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( $pending_nonce ); ?>" data-ready="<?php echo $pending_ready ? '1' : '0'; ?>">

	<header class="af-pending__bar">
		<div class="af-pending__summary" aria-live="polite">
			<strong id="af-pending-count">—</strong>
			<span id="af-pending-summary-text"><?php esc_html_e( 'Cargando cobros…', 'arriendo-facil' ); ?></span>
		</div>
		<input type="search" id="af-pending-filter" class="af-pending__search" placeholder="<?php esc_attr_e( 'Buscar inquilino, cédula o inmueble…', 'arriendo-facil' ); ?>" />
		<label class="af-pending__all">
			<input type="checkbox" id="af-pending-select-all" />
			<?php esc_html_e( 'Seleccionar todos', 'arriendo-facil' ); ?>
		</label>
		<button type="button" class="button button-primary" id="af-pending-batch" disabled>
			<?php esc_html_e( 'Facturar seleccionados', 'arriendo-facil' ); ?>
			<span class="af-pending__batch-count" id="af-pending-batch-count" hidden>0</span>
		</button>
	</header>

	<div id="af-pending-progress" class="af-pending__progress" hidden>
		<div class="af-pending__progress-track"><div class="af-pending__progress-fill" id="af-pending-progress-fill"></div></div>
		<p id="af-pending-progress-text"></p>
	</div>

	<div id="af-pending-results" class="af-pending__results" hidden></div>
	<div id="af-pending-list" class="af-pending__list" aria-busy="true"></div>

	<div id="af-pending-empty" class="af-empty" hidden>
		<span class="af-empty__icon" aria-hidden="true"><?php echo af_lucide( 'circle-check', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
		<h3 class="af-empty__title"><?php esc_html_e( 'Todo al día', 'arriendo-facil' ); ?></h3>
		<p class="af-empty__text"><?php esc_html_e( 'Cuando registres un pago en Cobranza, aparecerá aquí listo para facturar.', 'arriendo-facil' ); ?></p>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=af-collections' ) ); ?>"><?php esc_html_e( 'Ir a Cobranza', 'arriendo-facil' ); ?></a>
	</div>
</section>

<div id="af-pending-modal" class="af-modal" hidden>
	<div class="af-modal__backdrop" data-af-pending-close></div>
	<div class="af-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="af-pending-modal-title">
		<div class="af-modal__header">
			<h2 id="af-pending-modal-title"><?php esc_html_e( 'Revisar factura', 'arriendo-facil' ); ?></h2>
			<button type="button" class="af-modal__close" data-af-pending-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		</div>
		<div class="af-modal__body">
			<div id="af-pending-warnings" class="af-pending-notes" hidden></div>
			<dl class="af-pending-buyer">
				<div><dt><?php esc_html_e( 'Cliente', 'arriendo-facil' ); ?></dt><dd id="af-pm-buyer">—</dd></div>
				<div><dt><?php esc_html_e( 'Identificación', 'arriendo-facil' ); ?></dt><dd id="af-pm-id">—</dd></div>
				<div><dt><?php esc_html_e( 'Forma de pago', 'arriendo-facil' ); ?></dt><dd id="af-pm-pay">—</dd></div>
				<div><dt><?php esc_html_e( 'Fecha de emisión', 'arriendo-facil' ); ?></dt><dd id="af-pm-date">—</dd></div>
			</dl>
			<table class="af-pending-items">
				<thead><tr><th><?php esc_html_e( 'Concepto', 'arriendo-facil' ); ?></th><th><?php esc_html_e( 'Valor', 'arriendo-facil' ); ?></th></tr></thead>
				<tbody id="af-pm-items"></tbody>
				<tfoot>
					<tr><td><?php esc_html_e( 'Subtotal', 'arriendo-facil' ); ?></td><td id="af-pm-subtotal">$0.00</td></tr>
					<tr><td id="af-pm-iva-label"><?php esc_html_e( 'IVA', 'arriendo-facil' ); ?></td><td id="af-pm-iva">$0.00</td></tr>
					<tr class="is-total"><td><?php esc_html_e( 'Total', 'arriendo-facil' ); ?></td><td id="af-pm-total">$0.00</td></tr>
				</tfoot>
			</table>
			<label class="af-pending-email">
				<span><?php esc_html_e( 'Enviar RIDE y XML a', 'arriendo-facil' ); ?></span>
				<input type="email" id="af-pm-email" placeholder="correo@inquilino.com" />
			</label>
			<p id="af-pm-feedback" class="af-pending-feedback" aria-live="polite"></p>
			<div class="af-pending-actions">
				<button type="button" class="button" data-af-pending-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
				<button type="button" class="button button-primary" id="af-pm-issue"><?php esc_html_e( 'Emitir factura', 'arriendo-facil' ); ?></button>
			</div>
		</div>
	</div>
</div>

<script>
(function () {
	'use strict';

	var root = document.getElementById( 'af-pending' );
	if ( ! root ) { return; }

	var ajaxUrl = root.dataset.ajax, nonce = root.dataset.nonce;
	var list = document.getElementById( 'af-pending-list' );
	var emptyBox = document.getElementById( 'af-pending-empty' );
	var filter = document.getElementById( 'af-pending-filter' );
	var selectAll = document.getElementById( 'af-pending-select-all' );
	var batchBtn = document.getElementById( 'af-pending-batch' );
	var batchCount = document.getElementById( 'af-pending-batch-count' );
	var modal = document.getElementById( 'af-pending-modal' );
	var groups = [];
	var current = null;

	var T = {
		method: { efectivo: '<?php echo esc_js( __( 'Efectivo', 'arriendo-facil' ) ); ?>', transferencia: '<?php echo esc_js( __( 'Transferencia', 'arriendo-facil' ) ); ?>', deposito: '<?php echo esc_js( __( 'Depósito', 'arriendo-facil' ) ); ?>', cheque: '<?php echo esc_js( __( 'Cheque', 'arriendo-facil' ) ); ?>', otro: '<?php echo esc_js( __( 'Otro', 'arriendo-facil' ) ); ?>' },
		rejected: '<?php echo esc_js( __( 'Rechazada por el SRI: corrige y reemite', 'arriendo-facil' ) ); ?>',
		noEmail: '<?php echo esc_js( __( 'Sin correo', 'arriendo-facil' ) ); ?>',
		review: '<?php echo esc_js( __( 'Revisar y facturar', 'arriendo-facil' ) ); ?>',
		tenants: '<?php echo esc_js( __( 'inquilinos con cobros por facturar', 'arriendo-facil' ) ); ?>',
		total: '<?php echo esc_js( __( 'por facturar', 'arriendo-facil' ) ); ?>',
		notReady: '<?php echo esc_js( __( 'Configura tu firma electrónica antes de facturar.', 'arriendo-facil' ) ); ?>',
		confirmBatch: '<?php echo esc_js( __( 'Se emitirán %d facturas ante el SRI. ¿Continuar?', 'arriendo-facil' ) ); ?>',
		issuing: '<?php echo esc_js( __( 'Emitiendo facturas…', 'arriendo-facil' ) ); ?>',
		done: '<?php echo esc_js( __( 'Facturas emitidas', 'arriendo-facil' ) ); ?>',
		failed: '<?php echo esc_js( __( 'con error', 'arriendo-facil' ) ); ?>',
		issued: '<?php echo esc_js( __( 'Factura emitida. Puedes verla en Comprobantes.', 'arriendo-facil' ) ); ?>',
		loadErr: '<?php echo esc_js( __( 'No se pudo cargar. Intenta de nuevo.', 'arriendo-facil' ) ); ?>',
		netErr: '<?php echo esc_js( __( 'Error de conexión.', 'arriendo-facil' ) ); ?>',
		issue: '<?php echo esc_js( __( 'Emitir factura', 'arriendo-facil' ) ); ?>'
	};

	function money( n ) { return '$' + ( Number( n ) || 0 ).toFixed( 2 ); }
	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}
	function post( data ) {
		var fd = new FormData();
		Object.keys( data ).forEach( function ( k ) {
			if ( Array.isArray( data[ k ] ) ) {
				data[ k ].forEach( function ( v ) { fd.append( k + '[]', v ); } );
			} else { fd.append( k, data[ k ] ); }
		} );
		fd.append( 'nonce', nonce );
		return fetch( ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } );
	}
	function selectedIds( g ) { return g.charges.filter( function ( c ) { return c.on; } ).map( function ( c ) { return c.id; } ); }
	function selectedTotal( g ) { return g.charges.reduce( function ( s, c ) { return s + ( c.on ? c.amount : 0 ); }, 0 ); }

	function updateBatch() {
		var n = groups.filter( function ( g ) { return g.picked && selectedIds( g ).length; } ).length;
		batchBtn.disabled = n === 0 || root.dataset.ready !== '1';
		batchCount.hidden = n === 0;
		batchCount.textContent = n;
	}

	function render() {
		var q = filter.value.trim().toLowerCase();
		list.innerHTML = '';
		var visible = 0, grand = 0;
		groups.forEach( function ( g ) {
			grand += g.total;
			var hay = ( g.guest + ' ' + g.guest_id + ' ' + g.accommodation ).toLowerCase();
			if ( q && hay.indexOf( q ) === -1 ) { return; }
			visible++;

			var card = el( 'article', 'af-pcard' + ( g.has_rejected ? ' is-rejected' : '' ) );
			var pick = el( 'input' ); pick.type = 'checkbox'; pick.checked = !! g.picked; pick.className = 'af-pcard__pick';
			pick.setAttribute( 'aria-label', g.guest );
			pick.addEventListener( 'change', function () { g.picked = pick.checked; updateBatch(); } );

			var head = el( 'div', 'af-pcard__head' );
			var who = el( 'div', 'af-pcard__who' );
			who.appendChild( el( 'strong', '', g.guest || '—' ) );
			who.appendChild( el( 'span', '', ( g.guest_id || '—' ) + ' · ' + ( g.accommodation || '' ) ) );
			head.appendChild( pick ); head.appendChild( who );

			var tags = el( 'div', 'af-pcard__tags' );
			tags.appendChild( el( 'span', 'af-tag af-tag--' + ( g.iva_rate ? 'info' : 'muted' ), 'IVA ' + g.iva_rate + '%' ) );
			if ( ! g.email ) { tags.appendChild( el( 'span', 'af-tag af-tag--warn', T.noEmail ) ); }
			if ( g.has_rejected ) { tags.appendChild( el( 'span', 'af-tag af-tag--danger', T.rejected ) ); }
			head.appendChild( tags );

			var chips = el( 'ul', 'af-pcard__charges' );
			g.charges.forEach( function ( c ) {
				var li = el( 'li' );
				var lab = el( 'label' );
				var cb = el( 'input' ); cb.type = 'checkbox'; cb.checked = !! c.on;
				cb.addEventListener( 'change', function () { c.on = cb.checked; render(); } );
				lab.appendChild( cb );
				var txt = el( 'span', 'af-pcard__charge-label', c.label + ' · ' + c.period );
				var meta = el( 'small', '', ( T.method[ c.method ] || c.method || '' ) + ( c.paid_on ? ' · ' + c.paid_on : '' ) );
				var wrap = el( 'span', 'af-pcard__charge-text' ); wrap.appendChild( txt ); wrap.appendChild( meta );
				lab.appendChild( wrap );
				li.appendChild( lab );
				li.appendChild( el( 'span', 'af-pcard__amount', money( c.amount ) ) );
				chips.appendChild( li );
			} );

			var foot = el( 'div', 'af-pcard__foot' );
			foot.appendChild( el( 'span', 'af-pcard__total', money( selectedTotal( g ) ) ) );
			var btn = el( 'button', 'button button-primary', T.review ); btn.type = 'button';
			btn.disabled = ! selectedIds( g ).length || root.dataset.ready !== '1';
			btn.addEventListener( 'click', function () { openModal( g ); } );
			foot.appendChild( btn );

			card.appendChild( head ); card.appendChild( chips ); card.appendChild( foot );
			list.appendChild( card );
		} );

		document.getElementById( 'af-pending-count' ).textContent = groups.length;
		document.getElementById( 'af-pending-summary-text' ).textContent = T.tenants + ' · ' + money( grand ) + ' ' + T.total;
		list.setAttribute( 'aria-busy', 'false' );
		emptyBox.hidden = groups.length !== 0;
		updateBatch();
		updateTabBadge( groups.length );
	}

	function updateTabBadge( n ) {
		var b = document.getElementById( 'af-pending-tab-count' );
		if ( b ) { b.textContent = n; b.hidden = n === 0; }
	}

	function load() {
		list.setAttribute( 'aria-busy', 'true' );
		return post( { action: 'af_billing_pending' } ).then( function ( resp ) {
			if ( ! resp.success ) { throw new Error( ( resp.data && resp.data.message ) || T.loadErr ); }
			groups = resp.data.groups.map( function ( g ) {
				g.picked = false;
				g.charges.forEach( function ( c ) { c.on = true; } );
				return g;
			} );
			selectAll.checked = false;
			render();
		} ).catch( function ( e ) {
			list.innerHTML = '';
			list.appendChild( el( 'p', 'af-pending-feedback is-error', e.message || T.loadErr ) );
		} );
	}

	function openModal( g ) {
		current = g;
		var fb = document.getElementById( 'af-pm-feedback' );
		fb.textContent = ''; fb.className = 'af-pending-feedback';
		document.getElementById( 'af-pm-items' ).innerHTML = '';
		document.getElementById( 'af-pending-warnings' ).hidden = true;
		document.getElementById( 'af-pm-email' ).value = g.email || '';
		document.getElementById( 'af-pm-issue' ).disabled = true;
		modal.hidden = false;
		loadPreview();
	}

	function loadPreview() {
		var g = current, fb = document.getElementById( 'af-pm-feedback' );
		fb.textContent = '…';
		post( { action: 'af_preview_charges_invoice', lease_id: g.lease_id, charge_ids: selectedIds( g ), email: document.getElementById( 'af-pm-email' ).value } ).then( function ( resp ) {
			if ( ! resp.success ) { fb.textContent = ( resp.data && resp.data.message ) || T.loadErr; fb.className = 'af-pending-feedback is-error'; return; }
			var d = resp.data;
			fb.textContent = '';
			document.getElementById( 'af-pm-buyer' ).textContent = d.buyer_name;
			document.getElementById( 'af-pm-id' ).textContent = d.buyer_id;
			document.getElementById( 'af-pm-pay' ).textContent = d.forma_pago;
			document.getElementById( 'af-pm-date' ).textContent = d.fecha;
			var body = document.getElementById( 'af-pm-items' ); body.innerHTML = '';
			d.items.forEach( function ( it ) {
				var tr = el( 'tr' ); tr.appendChild( el( 'td', '', it.descripcion ) ); tr.appendChild( el( 'td', '', money( it.total ) ) ); body.appendChild( tr );
			} );
			document.getElementById( 'af-pm-subtotal' ).textContent = money( d.subtotal );
			document.getElementById( 'af-pm-iva-label' ).textContent = 'IVA ' + d.iva_rate + '%';
			document.getElementById( 'af-pm-iva' ).textContent = money( d.iva );
			document.getElementById( 'af-pm-total' ).textContent = money( d.total );
			var notes = document.getElementById( 'af-pending-warnings' ); notes.innerHTML = '';
			var all = ( d.blocking ? [ d.blocking ] : [] ).concat( d.warnings || [] );
			all.forEach( function ( w ) { notes.appendChild( el( 'p', d.blocking && w === d.blocking ? 'is-error' : '', w ) ); } );
			notes.hidden = ! all.length;
			document.getElementById( 'af-pm-issue' ).disabled = !! d.blocking;
		} ).catch( function () { fb.textContent = T.netErr; fb.className = 'af-pending-feedback is-error'; } );
	}

	function issue( g, email ) {
		return post( { action: 'af_issue_charges_invoice', lease_id: g.lease_id, charge_ids: selectedIds( g ), email: email || '' } );
	}

	document.getElementById( 'af-pm-email' ).addEventListener( 'change', function () { if ( current ) { loadPreview(); } } );

	document.getElementById( 'af-pm-issue' ).addEventListener( 'click', function () {
		var btn = this, fb = document.getElementById( 'af-pm-feedback' );
		btn.disabled = true; fb.className = 'af-pending-feedback'; fb.textContent = T.issuing;
		issue( current, document.getElementById( 'af-pm-email' ).value ).then( function ( resp ) {
			if ( resp.success ) {
				fb.className = 'af-pending-feedback is-ok'; fb.textContent = T.issued;
				setTimeout( function () { modal.hidden = true; load(); }, 1200 );
			} else {
				fb.className = 'af-pending-feedback is-error'; fb.textContent = ( resp.data && resp.data.message ) || T.netErr; btn.disabled = false;
			}
		} ).catch( function () { fb.className = 'af-pending-feedback is-error'; fb.textContent = T.netErr; btn.disabled = false; } );
	} );

	modal.querySelectorAll( '[data-af-pending-close]' ).forEach( function ( n ) { n.addEventListener( 'click', function () { modal.hidden = true; if ( document.getElementById( 'af-pm-feedback' ).className.indexOf( 'is-ok' ) === -1 ) { return; } load(); } ); } );
	document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' ) { modal.hidden = true; } } );

	filter.addEventListener( 'input', render );
	selectAll.addEventListener( 'change', function () { groups.forEach( function ( g ) { g.picked = selectAll.checked; } ); render(); } );

	batchBtn.addEventListener( 'click', function () {
		var todo = groups.filter( function ( g ) { return g.picked && selectedIds( g ).length; } );
		if ( ! todo.length ) { return; }
		if ( root.dataset.ready !== '1' ) { window.alert( T.notReady ); return; }
		if ( ! window.confirm( T.confirmBatch.replace( '%d', todo.length ) ) ) { return; }

		var prog = document.getElementById( 'af-pending-progress' ), fill = document.getElementById( 'af-pending-progress-fill' ), txt = document.getElementById( 'af-pending-progress-text' );
		var results = document.getElementById( 'af-pending-results' );
		var ok = 0, errors = [], i = 0;
		batchBtn.disabled = true; prog.hidden = false; results.hidden = true; results.innerHTML = '';

		function next() {
			if ( i >= todo.length ) {
				prog.hidden = true;
				results.hidden = false;
				results.appendChild( el( 'p', errors.length ? 'is-error' : 'is-ok', T.done + ': ' + ok + ( errors.length ? ' · ' + errors.length + ' ' + T.failed : '' ) ) );
				errors.forEach( function ( m ) { results.appendChild( el( 'p', 'is-error', m ) ); } );
				load();
				return;
			}
			var g = todo[ i ];
			fill.style.width = Math.round( ( i / todo.length ) * 100 ) + '%';
			txt.textContent = T.issuing + ' ' + ( i + 1 ) + '/' + todo.length + ' · ' + g.guest;
			issue( g, g.email ).then( function ( resp ) {
				if ( resp.success ) { ok++; } else { errors.push( g.guest + ': ' + ( ( resp.data && resp.data.message ) || T.netErr ) ); }
			} ).catch( function () { errors.push( g.guest + ': ' + T.netErr ); } ).then( function () { i++; next(); } );
		}
		next();
	} );

	load();
}());
</script>
