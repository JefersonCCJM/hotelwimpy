@push('scripts')
<script>
const reservationPaymentRouteTemplate = '{{ route("reservations.register-payment", ":id") }}';
const reservationCheckInRouteTemplate = '{{ route("reservations.check-in", ":id") }}';
let reservationPaymentRequestInFlight = false;
let reservationCancelPaymentRequestInFlight = false;
const noShowAutoCancellationInProgress = new Set();

document.addEventListener('DOMContentLoaded', function () {
    let tooltip = null;
    const cells = document.querySelectorAll('[data-tooltip]');

    cells.forEach((cell) => {
        cell.addEventListener('mouseenter', function () {
            const tooltipAttr = this.getAttribute('data-tooltip');
            if (!tooltipAttr || tooltipAttr.trim() === '') {
                return;
            }

            let tooltipData;
            try {
                tooltipData = JSON.parse(tooltipAttr);
            } catch (error) {
                return;
            }

            if (tooltip) {
                tooltip.remove();
            }

            tooltip = document.createElement('div');
            tooltip.className = 'fixed z-50 px-3 py-2 bg-gray-900 text-white text-xs rounded-lg shadow-xl pointer-events-none';
            tooltip.style.opacity = '0';
            tooltip.style.transition = 'opacity 0.2s';

            let content = '<div class="space-y-1">';
            content += `<div class="font-bold text-emerald-400">Hab. ${tooltipData.room} - ${tooltipData.beds}</div>`;
            content += `<div class="text-gray-300">${tooltipData.date}</div>`;
            content += `<div class="text-gray-300">Estado: <span class="font-semibold">${tooltipData.status}</span></div>`;

            if (tooltipData.customer) {
                content += '<div class="pt-1 border-t border-gray-700">';
                content += `<div class="text-gray-300">Cliente: <span class="font-semibold text-white">${tooltipData.customer}</span></div>`;
                if (tooltipData.check_in) {
                    content += `<div class="text-gray-300">Check-in: ${tooltipData.check_in}</div>`;
                }
                if (tooltipData.check_out) {
                    content += `<div class="text-gray-300">Check-out: ${tooltipData.check_out}</div>`;
                }
                content += '</div>';
            }
            content += '</div>';

            tooltip.innerHTML = content;
            document.body.appendChild(tooltip);

            const rect = this.getBoundingClientRect();
            const tooltipRect = tooltip.getBoundingClientRect();
            const scrollX = window.pageXOffset || document.documentElement.scrollLeft;
            const scrollY = window.pageYOffset || document.documentElement.scrollTop;

            let left = rect.left + scrollX + (rect.width / 2) - (tooltipRect.width / 2);
            let top = rect.top + scrollY - tooltipRect.height - 8;

            if (left < 10) {
                left = 10;
            }
            if (left + tooltipRect.width > window.innerWidth - 10) {
                left = window.innerWidth - tooltipRect.width - 10;
            }
            if (top < 10) {
                top = rect.bottom + scrollY + 8;
            }

            tooltip.style.left = left + 'px';
            tooltip.style.top = top + 'px';

            setTimeout(() => {
                if (tooltip) {
                    tooltip.style.opacity = '1';
                }
            }, 10);
        });

        cell.addEventListener('mouseleave', function () {
            if (!tooltip) {
                return;
            }

            tooltip.style.opacity = '0';
            setTimeout(() => {
                if (tooltip) {
                    tooltip.remove();
                    tooltip = null;
                }
            }, 200);
        });
    });

    setTimeout(testModal, 800);

    window.addEventListener('register-payment-event', function (event) {
        submitReservationPaymentFromModal(event?.detail || {});
    });
});

function openReservationDetail(data) {
    const modal = document.getElementById('reservation-detail-modal');
    const card = document.getElementById('reservation-detail-card');
    if (!modal || !data || !data.id) {
        return;
    }

    const setText = (id, value, fallback = '-') => {
        const element = document.getElementById(id);
        if (!element) {
            return;
        }

        if (value === null || value === undefined || value === '') {
            element.innerText = fallback;
            return;
        }

        element.innerText = value;
    };

    const customerName = data.customer_name || 'Cliente no disponible';
    const reservationLabel = data.reservation_code
        ? ('#' + data.reservation_code)
        : ('Reserva #' + data.id);

    setText('modal-customer-name', customerName, 'Cliente no disponible');
    setText('modal-customer-name-header', customerName, 'Cliente no disponible');
    setText('modal-reservation-id', reservationLabel, 'Reserva #' + data.id);
    setText('modal-room-info', data.rooms || 'Sin habitaciones', 'Sin habitaciones');
    setText('modal-checkin-date', data.check_in || 'N/A', 'N/A');
    setText('modal-checkout-date', data.check_out || 'N/A', 'N/A');
    setText('modal-checkin-time', data.check_in_time || 'N/A', 'N/A');
    setText('modal-guests-count', data.guests_count || '0', '0');
    setText('modal-total', '$' + (data.total || '0'), '$0');
    setText('modal-deposit', '$' + (data.deposit || '0'), '$0');
    setText('modal-balance', '$' + (data.balance || '0'), '$0');
    let notesLabel = data.notes || 'Sin notas adicionales';
    if (data.cancelled_at) {
        notesLabel += ` | Cancelada: ${data.cancelled_at}`;
    }
    setText('modal-notes', notesLabel, 'Sin notas adicionales');
    setText('modal-customer-id', data.customer_identification || '-', '-');
    setText('modal-customer-phone', data.customer_phone || '-', '-');
    setText('modal-status', data.status || 'Activa', 'Activa');

    const modalStatus = document.getElementById('modal-status');
    if (modalStatus) {
        const statusText = (data.status || 'Activa').toLowerCase();
        let statusClass = 'bg-emerald-100 text-emerald-700';

        if (statusText.includes('cancel')) {
            statusClass = 'bg-slate-200 text-slate-700';
        } else if (statusText.includes('lleg')) {
            statusClass = 'bg-emerald-100 text-emerald-700';
        } else if (statusText.includes('reserv')) {
            statusClass = 'bg-indigo-100 text-indigo-700';
        }

        modalStatus.className = `px-2 py-0.5 ${statusClass} text-[10px] font-bold uppercase tracking-wider rounded-md`;
    }

    const initialsEl = document.getElementById('modal-initials');
    if (initialsEl) {
        const initials = customerName
            .trim()
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((word) => word.charAt(0).toUpperCase())
            .join('');
        initialsEl.innerText = initials || 'NN';
    }

    let nightsLabel = '-';
    if (data.check_in && data.check_out) {
        try {
            const checkIn = new Date(data.check_in.split('/').reverse().join('-'));
            const checkOut = new Date(data.check_out.split('/').reverse().join('-'));
            const nights = Math.ceil((checkOut - checkIn) / (1000 * 60 * 60 * 24));
            if (!Number.isNaN(nights) && Number.isFinite(nights)) {
                nightsLabel = nights + ' noche' + (nights !== 1 ? 's' : '');
            }
        } catch (error) {
            nightsLabel = '-';
        }
    }
    setText('modal-nights', nightsLabel, '-');

    const editBtn = document.getElementById('modal-edit-btn');
    const checkInBtn = document.getElementById('modal-checkin-btn');
    const paymentBtn = document.getElementById('modal-payment-btn');
    const cancelPaymentBtn = document.getElementById('modal-cancel-payment-btn');
    const pdfBtn = document.getElementById('modal-pdf-btn');
    const viewDocumentBtn = document.getElementById('modal-view-document-btn');
    const downloadDocumentBtn = document.getElementById('modal-download-document-btn');
    const deleteBtn = document.getElementById('modal-delete-btn');

    const checkInUrl = data.check_in_url || (data.id ? reservationCheckInRouteTemplate.replace(':id', String(data.id)) : null);
    const canCheckIn = data.can_checkin !== false && data.has_operational_stay !== true;
    if (checkInBtn && checkInUrl && canCheckIn) {
        checkInBtn.onclick = () => openCheckInConfirmModal(
            checkInUrl,
            reservationLabel,
            customerName,
            checkInBtn
        );
        checkInBtn.classList.remove('hidden');
        checkInBtn.style.display = 'flex';
    } else if (checkInBtn) {
        checkInBtn.classList.add('hidden');
        checkInBtn.style.display = 'none';
    }

    const canPay = data.can_pay === true;
    if (paymentBtn && data.payment_url && canPay) {
        paymentBtn.onclick = () => openReservationPaymentModal(data);
        paymentBtn.classList.remove('hidden');
        paymentBtn.style.display = 'flex';
    } else if (paymentBtn) {
        paymentBtn.classList.add('hidden');
        paymentBtn.style.display = 'none';
    }

    const canCancelPayment = data.can_cancel_payment === true && canPay;
    if (cancelPaymentBtn && data.cancel_payment_url && canCancelPayment) {
        const cancelAmount = Number(data.last_payment_amount ?? 0) || 0;
        cancelPaymentBtn.onclick = () => openCancelPaymentConfirmModal(
            data.cancel_payment_url,
            cancelAmount,
            reservationLabel,
            customerName,
            cancelPaymentBtn
        );
        cancelPaymentBtn.classList.remove('hidden');
        cancelPaymentBtn.style.display = 'flex';
    } else if (cancelPaymentBtn) {
        cancelPaymentBtn.classList.add('hidden');
        cancelPaymentBtn.style.display = 'none';
    }

    if (editBtn && data.edit_url) {
        editBtn.setAttribute('href', data.edit_url);
        editBtn.style.display = 'flex';
    } else if (editBtn) {
        editBtn.setAttribute('href', '#');
        editBtn.style.display = 'none';
    }

    if (pdfBtn && data.pdf_url) {
        pdfBtn.setAttribute('href', data.pdf_url);
        pdfBtn.style.display = 'flex';
    } else if (pdfBtn) {
        pdfBtn.setAttribute('href', '#');
        pdfBtn.style.display = 'none';
    }

    if (viewDocumentBtn && data.guests_document_view_url) {
        viewDocumentBtn.setAttribute('href', data.guests_document_view_url);
        viewDocumentBtn.style.display = 'flex';
    } else if (viewDocumentBtn) {
        viewDocumentBtn.setAttribute('href', '#');
        viewDocumentBtn.style.display = 'none';
    }

    if (downloadDocumentBtn && data.guests_document_download_url) {
        downloadDocumentBtn.setAttribute('href', data.guests_document_download_url);
        downloadDocumentBtn.style.display = 'flex';
    } else if (downloadDocumentBtn) {
        downloadDocumentBtn.setAttribute('href', '#');
        downloadDocumentBtn.style.display = 'none';
    }

    const hasOperationalStay = data.has_operational_stay === true;
    const canCancel = data.can_cancel !== false && !hasOperationalStay;
    if (deleteBtn && data.id && canCancel) {
        deleteBtn.onclick = () => {
            closeReservationDetail();
            openDeleteModal(data.id);
        };
        deleteBtn.classList.remove('hidden');
        deleteBtn.style.display = 'flex';
    } else if (deleteBtn) {
        deleteBtn.classList.add('hidden');
        deleteBtn.style.display = 'none';
    }

    const shouldAutoCancelNoShow = data.auto_cancel_no_show === true
        && !!data.delete_url
        && !hasOperationalStay;
    if (shouldAutoCancelNoShow) {
        autoCancelNoShowReservation(data, reservationLabel, customerName);
        return;
    }

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    if (card) {
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    }
}

function closeReservationDetail() {
    const modal = document.getElementById('reservation-detail-modal');
    const card = document.getElementById('reservation-detail-card');
    if (!modal) {
        return;
    }

    if (card) {
        card.classList.remove('scale-100', 'opacity-100');
        card.classList.add('scale-95', 'opacity-0');
    }

    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }, 150);
}

function testModal() {
    const modal = document.getElementById('reservation-detail-modal');
    if (!modal) {
        return;
    }
}

function openDeleteModal(id) {
    const modal = document.getElementById('delete-modal');
    const form = document.getElementById('delete-form');
    if (!modal || !form || !id) {
        return;
    }

    form.action = '{{ route("reservations.destroy", ":id") }}'.replace(':id', id);
    modal.classList.remove('hidden');
}

function closeDeleteModal() {
    const modal = document.getElementById('delete-modal');
    if (modal) {
        modal.classList.add('hidden');
    }
}

function confirmDeleteWithPin(form) {
    form.submit();
}

async function autoCancelNoShowReservation(data, reservationLabel = 'Reserva', customerName = 'Cliente') {
    const reservationId = Number(data?.id ?? 0);
    const deleteUrl = data?.delete_url || null;
    if (!reservationId || !deleteUrl) {
        return;
    }

    if (noShowAutoCancellationInProgress.has(reservationId)) {
        return;
    }

    noShowAutoCancellationInProgress.add(reservationId);

    window.dispatchEvent(new CustomEvent('notify', {
        detail: {
            type: 'warning',
            message: `${reservationLabel} (${customerName}) vencio sin check-in. Se cancela automaticamente.`,
        },
    }));

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    try {
        const response = await fetch(deleteUrl, {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
        });

        let payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }

        if (!response.ok || (payload && payload.ok === false)) {
            throw new Error(payload?.message || 'No fue posible cancelar automaticamente la reserva vencida.');
        }

        window.dispatchEvent(new CustomEvent('notify', {
            detail: {
                type: 'success',
                message: payload?.message || 'Reserva cancelada automaticamente por no presentarse en la fecha de check-in.',
            },
        }));

        closeReservationDetail();
        setTimeout(() => {
            window.location.reload();
        }, 250);
    } catch (error) {
        window.dispatchEvent(new CustomEvent('notify', {
            detail: {
                type: 'error',
                message: error?.message || 'No fue posible cancelar automaticamente la reserva vencida.',
            },
        }));
    } finally {
        noShowAutoCancellationInProgress.delete(reservationId);
    }
}

function openReservationPaymentModal(data) {
    if (!data || !data.id) {
        return;
    }

    const totalAmount = Number(data.total_amount_raw ?? 0) || 0;
    const paymentsTotal = Number(data.payments_total_raw ?? 0) || 0;
    const balanceDue = Number(data.balance_raw ?? Math.max(0, totalAmount - paymentsTotal)) || 0;

    window.dispatchEvent(new CustomEvent('open-payment-modal', {
        detail: {
            title: 'Registrar Pago',
            reservationId: Number(data.id),
            nightPrice: 0,
            nightDate: null,
            financialContext: {
                totalAmount,
                paymentsTotal,
                balanceDue,
            },
        },
    }));
}

async function submitReservationPaymentFromModal(paymentData) {
    if (!paymentData || !paymentData.reservationId) {
        window.dispatchEvent(new CustomEvent('reset-payment-modal-loading'));
        window.dispatchEvent(new CustomEvent('notify', {
            detail: { type: 'error', message: 'No se recibieron datos de la reserva para registrar el pago.' },
        }));
        return;
    }

    if (reservationPaymentRequestInFlight) {
        return;
    }

    const amount = Number(paymentData.amount ?? 0);
    if (!Number.isFinite(amount) || amount <= 0) {
        window.dispatchEvent(new CustomEvent('reset-payment-modal-loading'));
        window.dispatchEvent(new CustomEvent('notify', {
            detail: { type: 'error', message: 'El monto debe ser mayor a 0.' },
        }));
        return;
    }

    const reservationId = Number(paymentData.reservationId);
    const paymentUrl = reservationPaymentRouteTemplate.replace(':id', String(reservationId));
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    reservationPaymentRequestInFlight = true;

    try {
        const response = await fetch(paymentUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({
                amount: amount,
                payment_method: paymentData.paymentMethod,
                bank_name: paymentData.bankName || null,
                reference: paymentData.reference || null,
                night_date: paymentData.nightDate || null,
            }),
        });

        let payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }

        if (!response.ok || !payload || payload.ok !== true) {
            throw new Error(payload?.message || 'No fue posible registrar el pago.');
        }

        window.dispatchEvent(new CustomEvent('close-payment-modal'));
        window.dispatchEvent(new CustomEvent('notify', {
            detail: { type: 'success', message: payload.message || 'Pago registrado correctamente.' },
        }));

        closeReservationDetail();
        setTimeout(() => {
            window.location.reload();
        }, 250);
    } catch (error) {
        window.dispatchEvent(new CustomEvent('reset-payment-modal-loading'));
        window.dispatchEvent(new CustomEvent('notify', {
            detail: { type: 'error', message: error?.message || 'No fue posible registrar el pago.' },
        }));
    } finally {
        reservationPaymentRequestInFlight = false;
    }
}

async function openCancelPaymentConfirmModal(url, amount = 0, reservationLabel = '', customerName = '', sourceButton = null) {
    if (!url) return;

    const formattedAmount = Math.round(amount).toLocaleString('es-CO');
    const result = await Swal.fire({
        title: '¿Confirmar anulación?',
        html: `Revertirás el pago de <b>$${formattedAmount}</b> para ${reservationLabel} (${customerName}).<br><br>Esta acción ajustará las noches pagadas de la reserva.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: '<i class="fas fa-undo mr-2"></i> Sí, anular',
        cancelButtonText: 'Volver',
        customClass: { popup: 'rounded-3xl' }
    });

    if (!result.isConfirmed) return;

    if (reservationCancelPaymentRequestInFlight) return;
    reservationCancelPaymentRequestInFlight = true;

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        });

        let payload = null;
        try { payload = await response.json(); } catch (e) {}

        if (!response.ok || !payload || payload.ok !== true) {
            throw new Error(payload?.message || 'No fue posible anular el pago.');
        }

        await Swal.fire({
            title: '¡Pago anulado!',
            text: payload.message || 'Pago anulado correctamente.',
            icon: 'success',
            confirmButtonColor: '#10b981',
            customClass: { popup: 'rounded-3xl' }
        });

        closeReservationDetail();
        window.location.reload();
    } catch (error) {
        Swal.fire({
            title: 'No se pudo anular',
            text: error?.message || 'No fue posible anular el pago.',
            icon: 'error',
            confirmButtonColor: '#e11d48',
            customClass: { popup: 'rounded-3xl' }
        });
    } finally {
        reservationCancelPaymentRequestInFlight = false;
    }
}

async function openCheckInConfirmModal(url, reservationLabel = '', customerName = '', sourceButton = null) {
    if (!url) return;

    const result = await Swal.fire({
        title: '¿Confirmar llegada?',
        html: `Registrar check-in para <b>${reservationLabel}</b> (${customerName}).<br><br>El estado cambiará a "Llegó" en el calendario.`,
        icon: 'info',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#64748b',
        confirmButtonText: '<i class="fas fa-door-open mr-2"></i> Confirmar check-in',
        cancelButtonText: 'Volver',
        customClass: { popup: 'rounded-3xl' }
    });

    if (!result.isConfirmed) return;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    if (!csrfToken) {
        await Swal.fire({
            title: 'Error al registrar',
            text: 'No se encontró token de seguridad para registrar el check-in.',
            icon: 'error',
            confirmButtonColor: '#059669',
            customClass: { popup: 'rounded-3xl' }
        });
        return;
    }

    closeReservationDetail();

    // Enviar POST tradicional para evitar bloqueos silenciosos en fetch
    // (cookies/sesión/redirecciones intermedias de middleware).
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = url;
    form.style.display = 'none';

    const tokenInput = document.createElement('input');
    tokenInput.type = 'hidden';
    tokenInput.name = '_token';
    tokenInput.value = csrfToken;
    form.appendChild(tokenInput);

    document.body.appendChild(form);
    form.submit();
}
</script>
@endpush
