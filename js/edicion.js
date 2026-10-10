function abrir(id) { document.getElementById(id).classList.add('visible'); }
        function cerrar(id) { document.getElementById(id).classList.remove('visible'); }
        function formatearHora(hora) {
            if (!hora) return '—';

            let partes = hora.split(':');
            let h = parseInt(partes[0]);
            let m = partes[1];

            let ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12;
            h = h ? h : 12;

            return h + ':' + m + ' ' + ampm;
        }

        document.querySelectorAll('.modal-g-overlay,.doc-view-overlay').forEach(o => {
            o.addEventListener('click', e => { if (e.target === o) { cerrar(o.id); if (o.id === 'modal-ver') document.getElementById('ver-hoja').innerHTML = ''; } });
        });

        // Tabs modal edición
        function mt(panelId, btn) {
            document.querySelectorAll('.mpanel').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.mtab').forEach(b => b.classList.remove('active'));
            document.getElementById(panelId).classList.add('active');
            btn.classList.add('active');
        }

        // Saldo edición
        function editSaldo() {

    const montoInput = document.getElementById('e-monto_total').value.trim();
    const adelantoInput = document.getElementById('e-adelanto').value.trim();

    const t = montoInput === '' ? null : parseFloat(montoInput);
    const a = adelantoInput === '' ? 0 : parseFloat(adelantoInput);

    const d = document.getElementById('e-saldo-d');
    const h = document.getElementById('e-saldo-h');
    const err = document.getElementById('e-saldo-err');

    // RESERVA → sin monto total definido
    if (t === null) {

        d.value = 'Reserva';
        h.value = '';

        d.style.color = '#555';
        err.style.display = 'none';

        return;
    }

    const s = t - a;

    d.value = s.toFixed(2);
    h.value = s.toFixed(2);

    d.style.color = s < 0 ? '#c00' : '#1a7a30';

    err.style.display = s < 0 ? 'block' : 'none';
}

        // Abrir edición
        function abrirEditar(c, condActuales) {
            function fechaOk(v) { return v && !v.startsWith('0000') && !v.startsWith('-') && parseInt(v.split('-')[0]) >= 1900; }
            document.getElementById('e-id').value           = c.id_contrato;
            document.getElementById('e-nombre').value       = c.cliente    || '';
            document.getElementById('e-dni').value          = c.dni        || '';
            document.getElementById('e-email').value        = c.email      || '';
            document.getElementById('e-telefono').value     = c.telefono   || '';
            document.getElementById('e-dir_cliente').value  = c.dir_cliente || '';
            document.getElementById('e-c2_nombre').value    = c.cliente2_nombre    || '';
            document.getElementById('e-c2_dni').value       = c.cliente2_dni       || '';
            document.getElementById('e-c2_telefono').value  = c.cliente2_telefono  || '';
            document.getElementById('e-c2_email').value     = c.cliente2_email     || '';
            document.getElementById('e-c2_direccion').value = c.cliente2_direccion || '';
            document.getElementById('e-proforma').value     = c.proforma   || '';
            document.getElementById('e-provincia').value    = c.provincia  || '';
            document.getElementById('e-direccion').value    = c.direccion  || '';
            const horaVal = c.ho_inicio || '';
            document.getElementById('e-ho_inicio').value    = (horaVal && horaVal !== '00:00:00') ? horaVal.substring(0,5) : '';
            document.getElementById('e-fecha_evento').value  = fechaOk(c.fecha_evento)  ? c.fecha_evento  : '';
            document.getElementById('e-monto_total').value =
    c.monto_total !== null && c.monto_total !== ''
        ? parseFloat(c.monto_total).toFixed(2)
        : '';
            document.getElementById('e-adelanto').value      = parseFloat(c.adelanto    || 0).toFixed(2);
            document.getElementById('e-fecha_limite').value  = fechaOk(c.fecha_limite)  ? c.fecha_limite  : '';
            document.getElementById('e-fecha_entrega').value = fechaOk(c.fecha_entrega) ? c.fecha_entrega : '';
            document.getElementById('e-fecha_formato').value = fechaOk(c.fecha_formato) ? c.fecha_formato : '';
            document.getElementById('e-unidades').value     = c.unidades || '';
            document.getElementById('e-material').value     = c.material  || '';
            selVal('e-servicio_id', c.servicio_id);
            selVal('e-forma_id',    c.forma_id);
            selVal('e-formato_id',  c.formato_id);
            const sdEl = document.getElementById('e-servicio_detalle');
            if (sdEl) sdEl.value = c.servicio_detalle || '';
            editSaldo();

            document.querySelectorAll('#e-cond-lista input[type="checkbox"]').forEach(cb => {
                const m = condActuales.includes(parseInt(cb.value));
                cb.checked = m;
                cb.closest('.econd-item').classList.toggle('sel', m);
            });

            document.getElementById('tit-editar').textContent =
                'Editar Contrato N° ' + String(c.id_contrato).padStart(3, '0');

            mt('mpDatos', document.querySelector('.mtab'));
            abrir('modal-editar');
        }

        function selVal(id, val) {
            const s = document.getElementById(id);
            if (!s || val == null) return;
            [...s.options].forEach(o => { o.selected = (o.value == val); });
        }

        function validarEd() {

    const monto = document.getElementById('e-monto_total').value.trim();
    const saldo = parseFloat(document.getElementById('e-saldo-h').value);

    // SOLO validar si existe monto total
    if (monto !== '' && saldo < 0) {

        alert('⚠ El adelanto no puede superar el monto total.');

        mt('mpPago', document.querySelectorAll('.mtab')[1]);

        return false;
    }

    return true;
}

        // Utilidades
        function fmtFecha(f) {
            if (!f || f === '' || f.startsWith('0000') || f.startsWith('-')) return '<em style="color:#aaa;font-style:italic">Por confirmar</em>';
            const p = f.split('-');
            if (!p[0] || parseInt(p[0]) < 1900) return '<em style="color:#aaa;font-style:italic">Por confirmar</em>';
            return p[2] + '/' + p[1] + '/' + p[0];
        }
        function fmtHora(h) {
            if (!h || h === '' || h === '00:00:00') return '<em style="color:#aaa;font-style:italic">Por confirmar</em>';
            const parts = h.split(':');
            let hh = parseInt(parts[0]);
            const mm = parts[1] || '00';
            const ampm = hh >= 12 ? 'PM' : 'AM';
            hh = hh % 12 || 12;
            return hh + ':' + mm + ' ' + ampm;
        }
        function fmtMoney(v) {
            return 'S/ ' + parseFloat(v || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
        function esc(s) {
            if (!s) return '';
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
        function calcEstado(monto, saldo, fl) {

    // 🔹 Reserva
    if (monto === null || monto === undefined || monto === '') {
        return {
            label: 'Reserva',
            bg: '#eee',
            color: '#555'
        };
    }

    if (saldo <= 0) {
        return {
            label: 'Pagado',
            bg: '#e6f4ea',
            color: '#1a7a30'
        };
    }

    if (fl && fl < HOY) {
        return {
            label: 'No pago',
            bg: '#fdecea',
            color: '#c0392b'
        };
    }

    return {
        label: 'Proceso',
        bg: '#fff3e0',
        color: '#e65100'
    };
}

        // Ver historial agrupado por sesión
        function verHistorial(id, sesiones, cliente) {
            document.getElementById('tit-hist').textContent =
                'Historial — Contrato N° ' + String(id).padStart(3, '0') + ' · ' + cliente;

            let html = '';

            if (!sesiones || sesiones.length === 0) {
                html = '<div class="hist-vacio">Sin cambios registrados en este contrato.</div>';
            } else {
                sesiones.forEach((ses, idx) => {
                    const fechaHora = fmtFechaHora(ses.fecha_hora);
                    const nCambios = ses.cambios.length;
                    html += `
                <div class="hist-sesion">
                    <div class="hist-sesion-header">
                        <span class="hist-sesion-fecha">
                            📝 Edición ${sesiones.length - idx} — ${fechaHora}
                            <span style="font-size:10px;color:#aaa;font-weight:400;margin-left:8px;">
                                ${nCambios} campo${nCambios !== 1 ? 's' : ''} modificado${nCambios !== 1 ? 's' : ''}
                            </span>
                        </span>
                    </div>
                    <div class="hist-sesion-body">`;

                    ses.cambios.forEach(cambio => {
                        html += `
                        <div class="hist-fila">
                            <div class="hf-campo">${esc(cambio.campo)}</div>
                            <div class="hf-ant">${esc(cambio.anterior) || '—'}</div>
                            <div class="hf-nvo">${esc(cambio.nuevo) || '—'}</div>
                        </div>`;
                    });

                    html += `    </div>
                </div>`;
                });
            }

            document.getElementById('hist-body').innerHTML = html;
            abrir('modal-hist');
        }

        function fmtFechaHora(dt) {
            if (!dt) return '—';
            const [fecha, hora] = dt.split(' ');
            const p = (fecha||'').split('-');
            const fechaStr = (p[2]&&p[1]&&p[0]) ? p[2]+'/'+p[1]+'/'+p[0] : '—';
            const parts = (hora||'').split(':');
            let hh = parseInt(parts[0]||0);
            const mm = parts[1] || '00';
            const ampm = hh >= 12 ? 'PM' : 'AM';
            hh = hh % 12 || 12;
            return fechaStr + ' a las ' + hh + ':' + mm + ' ' + ampm;
        }

        let deleteId = null;

        function confirmarEliminar(id, nombre) {
            deleteId = id;

            document.getElementById('confirm-text').innerHTML =
                `¿Eliminar contrato N° <b>${id}</b> de "<b>${nombre}</b>"?<br><br>
        <span style="color:#c0392b;">⚠ Esta acción no se puede deshacer.</span>`;

            abrir('modal-confirm');
        }

        function ejecutarDelete() {
            document.getElementById('del-id').value = deleteId;
            document.getElementById('form-delete').submit();
        }