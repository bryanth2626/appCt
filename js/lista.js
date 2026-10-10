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

    // 🔹 NUEVO: reserva
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


        function cerrarDoc() {
            document.getElementById('modal-doc').classList.remove('visible');
            document.getElementById('doc-hoja').innerHTML = '';
        }

        document.getElementById('modal-doc').addEventListener('click', function(e) {
            if (e.target === this) cerrarDoc();
        });