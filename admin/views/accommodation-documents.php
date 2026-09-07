<?php
/**
 * Accommodation documents meta box view.
 *
 * @package Arriendo_Facil
 * @var array $documents      Saved documents.
 * @var array $document_types Allowed document types.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="af-documents-box">
	<p class="af-field__hint" style="margin-top:0;">
		<?php esc_html_e( 'Escrituras, pólizas, predial y demás respaldos legales del inmueble.', 'arriendo-facil' ); ?>
	</p>

	<ul id="af-documents-list" class="af-documents-list">
		<?php foreach ( $documents as $document ) : ?>
			<?php
			$attachment_id  = absint( $document['attachment_id'] ?? 0 );
			$attachment_url = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
			if ( ! $attachment_url ) {
				continue;
			}
			?>
			<li class="af-documents-item"
				data-type="<?php echo esc_attr( $document['type'] ?? 'otro' ); ?>"
				data-id="<?php echo esc_attr( $attachment_id ); ?>"
				data-label="<?php echo esc_attr( $document['label'] ?? '' ); ?>"
				data-expires="<?php echo esc_attr( $document['expires_at'] ?? '' ); ?>">
				<span class="af-documents-item__type"><?php echo esc_html( $document_types[ $document['type'] ?? 'otro' ] ?? '' ); ?></span>
				<a href="<?php echo esc_url( $attachment_url ); ?>" target="_blank" rel="noopener">
					<?php echo esc_html( $document['label'] ? $document['label'] : basename( $attachment_url ) ); ?>
				</a>
				<?php if ( ! empty( $document['expires_at'] ) ) : ?>
					<span class="af-documents-item__expires">
						<?php
						printf(
							/* translators: %s: expiry date */
							esc_html__( 'Vence: %s', 'arriendo-facil' ),
							esc_html( $document['expires_at'] )
						);
						?>
					</span>
				<?php endif; ?>
				<button type="button" class="af-documents-item__remove" aria-label="<?php esc_attr_e( 'Quitar documento', 'arriendo-facil' ); ?>">&times;</button>
			</li>
		<?php endforeach; ?>
	</ul>

	<div class="af-documents-add">
		<select id="af-document-type" class="af-input">
			<?php foreach ( $document_types as $type_key => $type_label ) : ?>
				<option value="<?php echo esc_attr( $type_key ); ?>"><?php echo esc_html( $type_label ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="date" id="af-document-expires" class="af-input" title="<?php esc_attr_e( 'Fecha de vencimiento (opcional)', 'arriendo-facil' ); ?>" />
		<button type="button" id="af-document-add" class="button button-secondary">
			<?php esc_html_e( 'Subir documento', 'arriendo-facil' ); ?>
		</button>
	</div>

	<input type="hidden" id="af_documents" name="af_documents" value="" />
</div>

<script>
(function () {
	const list = document.getElementById('af-documents-list');
	const hidden = document.getElementById('af_documents');
	const addBtn = document.getElementById('af-document-add');
	const typeSelect = document.getElementById('af-document-type');
	const expiresInput = document.getElementById('af-document-expires');
	if (!list || !hidden || !addBtn) { return; }

	let frame = null;

	function bindRemove(item) {
		item.querySelector('.af-documents-item__remove').addEventListener('click', function () {
			item.remove();
			sync();
		});
	}

	function sync() {
		const items = [];
		list.querySelectorAll('.af-documents-item').forEach(function (item) {
			items.push({
				type: item.getAttribute('data-type'),
				attachment_id: parseInt(item.getAttribute('data-id'), 10),
				label: item.getAttribute('data-label') || '',
				expires_at: item.getAttribute('data-expires') || ''
			});
		});
		hidden.value = JSON.stringify(items);
	}

	list.querySelectorAll('.af-documents-item').forEach(bindRemove);
	sync();

	addBtn.addEventListener('click', function () {
		if (frame) { frame.open(); return; }

		frame = wp.media({
			title: <?php echo wp_json_encode( __( 'Seleccionar documento', 'arriendo-facil' ) ); ?>,
			button: { text: <?php echo wp_json_encode( __( 'Usar documento', 'arriendo-facil' ) ); ?> },
			multiple: false
		});

		frame.on('select', function () {
			const attachment = frame.state().get('selection').first().toJSON();
			const type = typeSelect.value;
			const expires = expiresInput.value;

			const item = document.createElement('li');
			item.className = 'af-documents-item';
			item.setAttribute('data-type', type);
			item.setAttribute('data-id', attachment.id);
			item.setAttribute('data-label', attachment.filename || attachment.title || '');
			item.setAttribute('data-expires', expires);
			item.innerHTML =
				'<span class="af-documents-item__type">' + typeSelect.options[typeSelect.selectedIndex].text + '</span>' +
				'<a href="' + attachment.url + '" target="_blank" rel="noopener">' + (attachment.filename || attachment.title) + '</a>' +
				(expires ? '<span class="af-documents-item__expires"><?php echo esc_js( __( 'Vence:', 'arriendo-facil' ) ); ?> ' + expires + '</span>' : '') +
				'<button type="button" class="af-documents-item__remove">&times;</button>';

			list.appendChild(item);
			bindRemove(item);
			sync();
			expiresInput.value = '';
		});

		frame.open();
	});
}());
</script>
